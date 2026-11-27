import {
  buildOverrides,
  buildRoutePrices,
  calculatorSettings,
  priceBreakdown,
  priceBreakdownLegs,
  estimateKm,
  estimateMinutes,
  isCrossRegionRoute,
  isMainlandPoint,
  formatDuration,
  money,
  roundPrice,
  formatDate,
  plural,
  todayISO,
  defaultPickupTime,
} from './calc.js'

const ORDERS_KEY = 'protransfer_orders'

// карта Яндекса: ключ задаётся в site.map.apiKey (JSON или WP)
const MAP = {
  center: [45.15, 34.4],
  zoom: 8,
}

// объекты карты вне реактивного состояния Alpine
const mapStore = { ymaps: null, map: null, layer: null, promise: null }

export default function app(data) {
  const routeCache = new Map()
  let routeRevision = 0
  const site = data.site
  const points = data.points
  const tariffs = data.tariffs
  const reviews = data.reviews
  const faq = data.faq
  const routesData = data.routes
  const overrides = buildOverrides(routesData.overrides || [])
  const routePrices = buildRoutePrices(routesData.overrides || [])
  const calc = calculatorSettings(data.calc)
  // Пересечение Керченского пролива: станции и объезд задаются в «Маршрутах».
  const crossingSettings = routesData.crossing || {}
  const crossing = {
    enabled: crossingSettings.enabled !== false,
    railFrom: crossingSettings.railFrom || '',
    railTo: crossingSettings.railTo || '',
    via: crossingSettings.via || '',
    corridor: Array.isArray(crossingSettings.corridor)
      ? crossingSettings.corridor
        .filter((point) => Number.isFinite(Number(point?.lat)) && Number.isFinite(Number(point?.lon)))
        .map((point, index) => ({ id: String(point.id || `corridor-${index}`), name: String(point.name || ''), lat: Number(point.lat), lon: Number(point.lon) }))
      : [],
    railMinutes: Number(crossingSettings.railMinutes) || 45,
    transferMinutes: Number.isFinite(Number(crossingSettings.transferMinutes)) ? Number(crossingSettings.transferMinutes) : 30,
  }
  const initialRoute = routesData.popular?.[0]
  const initialFrom = points.find((point) => point.id === initialRoute?.from) || points[0]
  const initialTo = points.find((point) => point.id === initialRoute?.to) || points.find((point) => point.id !== initialFrom?.id)

  return {
    site,
    calc,
    points,
    tariffs,
    reviews,
    faq,
    routes: routesData,
    dataSource: data.source || 'json',
    labMode: false,

    fromName: initialFrom?.name || '',
    toName: initialTo?.name || '',
    date: todayISO(),
    time: defaultPickupTime(),
    passengers: 2,
    roundTrip: false,
    childSeat: false,
    extraStop: false,
    tariffId: tariffs[0]?.id || '',
    crossing,
    crossingMode: 'bypass',

    name: '',
    phone: '',
    comment: '',
    consent: false,
    company: '',
    submitting: false,
    submitError: '',

    mobileOpen: false,
    scrolled: false,
    showBar: false,
    bookingOpen: false,
    successOpen: false,
    order: null,
    errors: {},
    copied: false,

    listState: {
      from: { open: false, index: -1, query: '' },
      to: { open: false, index: -1, query: '' },
    },

    geo: null,
    routeLoading: false,
    mapFailed: false,

    init() {
      this.onScroll = () => {
        this.scrolled = window.scrollY > 12
        this.showBar = window.scrollY > 720
      }
      window.addEventListener('scroll', this.onScroll, { passive: true })
      if (window.ptCalculatorLab) this.initCalculatorLab()
      this.onScroll()

      this.$watch('fromName', () => this.loadRoute())
      this.$watch('toName', () => this.loadRoute())
      this.$watch('crossingMode', () => this.loadRoute())

      this.$nextTick(() => {
        this.setupMapLazy()
        this.loadRoute()
      })
    },

    initCalculatorLab() {
      this.labMode = true
      let lastReport
      const report = () => {
        const info = this.routeInfo
        const state = { type: 'pt-calculator-state', from: this.fromPoint?.id || '',
          to: this.toPoint?.id || '', tariff: this.tariffId, roundTrip: this.roundTrip,
          childSeat: this.childSeat, extraStop: this.extraStop, crossing: this.activeCrossingMode, km: info?.km ?? null }
        const snapshot = JSON.stringify(state)
        if (lastReport === snapshot) return
        lastReport = snapshot
        window.parent.postMessage(state, window.location.origin)
      }
      for (const field of ['fromName', 'toName', 'tariffId', 'roundTrip', 'childSeat', 'extraStop', 'crossingMode', 'geo']) this.$watch(field, report)
      this.$nextTick(report)
      window.addEventListener('message', (event) => {
        if (event.origin !== window.location.origin || event.source !== window.parent || event.data?.type !== 'pt-calculator-preview') return
        if (!event.data.settings || typeof event.data.settings !== 'object') return
        Object.assign(this.calc, calculatorSettings(event.data.settings))
        report()
      })
    },

    /* ---------- map ---------- */

    setupMapLazy() {
      const element = this.$refs.map
      if (!element) return

      if (typeof IntersectionObserver === 'undefined') {
        this.initMap()
        return
      }

      const observer = new IntersectionObserver(
        (entries) => {
          if (entries.some((entry) => entry.isIntersecting)) {
            observer.disconnect()
            this.initMap()
          }
        },
        { rootMargin: '200px' },
      )
      observer.observe(element)
    },

    async initMap() {
      if (mapStore.map || !this.$refs.map) return

      let ymaps
      try {
        ymaps = await this.loadYmaps()
      } catch (error) {
        this.mapFailed = true
        return
      }

      mapStore.ymaps = ymaps

      // объекты карты держим вне реактивного состояния Alpine: прокси ломает внутренности Яндекса
      mapStore.map = new ymaps.Map(
        this.$refs.map,
        { center: MAP.center, zoom: MAP.zoom, controls: ['zoomControl'], type: 'yandex#map' },
        { suppressObsoleteBrowserNotifier: true },
      )

      // колесо мыши не должно «съедать» прокрутку страницы — включаем зум по клику
      mapStore.map.behaviors.disable('scrollZoom')
      mapStore.map.events.add('click', () => mapStore.map.behaviors.enable('scrollZoom'))

      mapStore.layer = new ymaps.GeoObjectCollection()
      mapStore.map.geoObjects.add(mapStore.layer)

      this.refreshMap()
    },

    loadYmaps() {
      if (mapStore.promise) return mapStore.promise

      mapStore.promise = new Promise((resolve, reject) => {
        if (window.ymaps && typeof window.ymaps.Map === 'function') {
          window.ymaps.ready(() => resolve(window.ymaps))
          return
        }

        const script = document.createElement('script')
        const key = (this.site.map && this.site.map.apiKey) || ''
        script.src = `https://api-maps.yandex.ru/2.1/?lang=ru_RU${key ? `&apikey=${key}` : ''}`
        script.async = true
        script.onload = () => {
          if (!window.ymaps) {
            reject(new Error('ymaps: api not available'))
            return
          }
          window.ymaps.ready(() => resolve(window.ymaps))
        }
        script.onerror = () => reject(new Error('ymaps: script failed'))
        document.head.appendChild(script)
      })

      return mapStore.promise
    },

    pin(label, color) {
      // label уходит в iconContent (текст на кружке), цвет — в опции пресета
      return {
        properties: { iconContent: label },
        options: { preset: 'islands#circleIcon', iconColor: color },
      }
    },

    refreshMap() {
      if (!mapStore.map || !mapStore.layer) return

      const ymaps = mapStore.ymaps
      const from = this.fromPoint
      const to = this.toPoint
      mapStore.layer.removeAll()

      if (!from || !to || from.id === to.id) {
        mapStore.map.setCenter(MAP.center, MAP.zoom)
        return
      }

      const key = `${from.id}|${to.id}`
      const geo = this.geo && this.geo.key === key ? this.geo : null
      const allCoords = []

      const addAuto = (coords) => {
        if (!Array.isArray(coords) || coords.length < 2) return
        mapStore.layer.add(new ymaps.Polyline(coords, {}, { strokeColor: '#D93B31', strokeWidth: 9, strokeOpacity: 0.45 }))
        mapStore.layer.add(new ymaps.Polyline(coords, {}, { strokeColor: '#0B1422', strokeWidth: 4, strokeOpacity: 0.9 }))
        allCoords.push(...coords)
      }

      const addRail = (coords) => {
        if (!Array.isArray(coords) || coords.length < 2) return
        mapStore.layer.add(new ymaps.Polyline(coords, {}, { strokeColor: '#2F6FED', strokeWidth: 10, strokeOpacity: 0.14 }))
        mapStore.layer.add(new ymaps.Polyline(coords, {}, { strokeColor: '#2F6FED', strokeWidth: 4, strokeOpacity: 0.95, strokeStyle: 'dash' }))
        allCoords.push(...coords)
      }

      if (geo?.mode === 'train' && Array.isArray(geo.legs)) {
        for (const leg of geo.legs) {
          if (leg.type === 'rail') addRail(leg.coords)
          else addAuto(leg.coords)
        }
        const stationFrom = this.railFromPoint
        const stationTo = this.railToPoint
        for (const station of [stationFrom, stationTo]) {
          if (!station) continue
          mapStore.layer.add(new ymaps.Placemark(
            [station.lat, station.lon],
            { iconContent: 'П', hintContent: station.name },
            { preset: 'islands#circleIcon', iconColor: '#2F6FED' },
          ))
        }
      } else {
        addAuto(geo?.coords || [[from.lat, from.lon], [to.lat, to.lon]])
        const via = this.crossingViaPoint
        if (geo?.mode === 'bypass' && via) {
          mapStore.layer.add(new ymaps.Placemark(
            [via.lat, via.lon],
            { hintContent: via.name },
            { preset: 'islands#circleDotIcon', iconColor: '#D97706' },
          ))
        }
      }

      const pinFrom = this.pin('А', '#0B1422')
      const pinTo = this.pin('Б', '#D93B31')
      mapStore.layer.add(
        new ymaps.Placemark([from.lat, from.lon], { ...pinFrom.properties, hintContent: from.name }, pinFrom.options),
      )
      mapStore.layer.add(
        new ymaps.Placemark([to.lat, to.lon], { ...pinTo.properties, hintContent: to.name }, pinTo.options),
      )

      const bounds = allCoords.reduce(
        (acc, coord) => [
          [Math.min(acc[0][0], coord[0]), Math.min(acc[0][1], coord[1])],
          [Math.max(acc[1][0], coord[0]), Math.max(acc[1][1], coord[1])],
        ],
        [
          [90, 180],
          [-90, -180],
        ],
      )

      // checkZoomRange здесь нельзя: он сбрасывает масштаб на обзорный (8) и маршрут «тонет» в центре карты
      mapStore.map.setBounds(bounds, { zoomMargin: 28, duration: 220 })
    },

    /* маршрут: сначала Яндекс (нужен ключ), потом публичный OSRM, иначе расчёт по координатам */

    async routeFromYandex(from, to, via = null) {
      const apiKey = (this.site.map && this.site.map.apiKey) || ''
      if (!mapStore.ymaps || !apiKey) return null

      try {
        const vias = Array.isArray(via) ? via : (via ? [via] : [])
        const waypoints = [[from.lat, from.lon], ...vias.map((point) => [point.lat, point.lon]), [to.lat, to.lon]]
        const route = await mapStore.ymaps.route(waypoints, { routingMode: 'auto', mapStateAutoApply: false })
        const active = route.getActiveRoute()
        if (!active) return null

        const coords = []
        const paths = route.getPaths()
        for (let p = 0; p < paths.getLength(); p += 1) {
          const segments = paths.get(p).getSegments()
          for (let i = 0; i < segments.getLength(); i += 1) {
            const piece = segments.get(i).getCoordinates()
            for (let j = 0; j < piece.length; j += 1) coords.push([piece[j][0], piece[j][1]])
          }
        }
        if (coords.length < 2) return null

        return {
          km: Math.max(1, Math.round(active.getLength() / 1000)),
          rawMinutes: active.getTime() / 60,
          coords,
          source: 'yandex',
        }
      } catch (error) {
        return null
      }
    },

    async routeFromOsrm(from, to, via = null) {
      const controller = new AbortController()
      const timer = setTimeout(() => controller.abort(), 8000)
      try {
        const vias = Array.isArray(via) ? via : (via ? [via] : [])
        const waypoints = [from, ...vias, to]
        const url =
          'https://router.project-osrm.org/route/v1/driving/' +
          waypoints.map((point) => `${point.lon},${point.lat}`).join(';') +
          '?overview=simplified&geometries=geojson'
        const response = await fetch(url, { signal: controller.signal })
        if (!response.ok) throw new Error(`osrm ${response.status}`)
        const data = await response.json()
        const route = data.routes && data.routes[0]
        if (!route) throw new Error('no route')

        return {
          km: Math.max(1, Math.round(route.distance / 1000)),
          rawMinutes: route.duration / 60,
          coords: route.geometry.coordinates.map((coord) => [coord[1], coord[0]]),
          source: 'osrm',
        }
      } catch (error) {
        return null
      } finally {
        clearTimeout(timer)
      }
    },

    estimateRoute(from, to, via = null) {
      if (!from || !to) return null
      const vias = Array.isArray(via) ? via : (via ? [via] : [])
      if (vias.length) {
        const points = [from, ...vias, to]
        let km = 0
        for (let i = 0; i < points.length - 1; i += 1) {
          const legKm = estimateKm(points[i], points[i + 1], overrides, this.calc)
          if (!legKm) return null
          km += legKm
        }
        return {
          km,
          minutes: estimateMinutes(km, this.calc),
          coords: points.map((point) => [point.lat, point.lon]),
          source: 'bypass',
        }
      }
      const km = estimateKm(from, to, overrides, this.calc)
      if (!km) return null
      return {
        km,
        minutes: estimateMinutes(km, this.calc),
        coords: [
          [from.lat, from.lon],
          [to.lat, to.lon],
        ],
        source: overrides[`${from.id}|${to.id}`] != null ? 'override' : 'haversine',
      }
    },

    estimateRouteOrZero(from, to) {
      if (!from || !to) return null
      if (from.id === to.id) {
        return { km: 0, minutes: 0, coords: [[from.lat, from.lon], [from.lat, from.lon]], source: 'zero' }
      }
      return this.estimateRoute(from, to)
    },

    normalizeLeg(route, point) {
      if (!route) {
        return { km: 0, minutes: 0, coords: [[point.lat, point.lon], [point.lat, point.lon]], source: 'zero' }
      }
      const km = Number(route.km) || 0
      const minutes = Number.isFinite(route.rawMinutes)
        ? route.rawMinutes
        : (Number.isFinite(route.minutes) ? route.minutes : estimateMinutes(km, this.calc))
      return { km, minutes, coords: route.coords, source: route.source }
    },

    trainResult(from, to, first, second, exact = false) {
      const stationFrom = this.trainFromPoint
      const stationTo = this.trainToPoint
      const railMinutes = this.crossing.railMinutes
      const transferMinutes = this.crossing.transferMinutes
      const rawMinutes = first.minutes + second.minutes + railMinutes + transferMinutes
      const km = first.km + second.km
      const step = this.calc.time_round
      return {
        km,
        rawMinutes,
        exact,
        minutes: Math.max(step, Math.round(rawMinutes / step) * step),
        coords: [...first.coords, ...second.coords],
        legs: [
          { type: 'auto', from: from.id, to: stationFrom.id, km: first.km, minutes: first.minutes, coords: first.coords },
          { type: 'rail', from: stationFrom.id, to: stationTo.id, km: 0, minutes: railMinutes, coords: [[stationFrom.lat, stationFrom.lon], [stationTo.lat, stationTo.lon]] },
          { type: 'auto', from: stationTo.id, to: to.id, km: second.km, minutes: second.minutes, coords: second.coords },
        ],
        source: 'train',
      }
    },

    estimateTrainRoute(from, to) {
      if (!this.trainFromPoint || !this.trainToPoint) return null
      const first = this.normalizeLeg(this.estimateRouteOrZero(from, this.trainFromPoint), from)
      const second = this.normalizeLeg(this.estimateRouteOrZero(this.trainToPoint, to), this.trainToPoint)
      return this.trainResult(from, to, first, second)
    },

    estimateRouteForMode(from, to, mode) {
      if (mode === 'train') return this.estimateTrainRoute(from, to)
      if (mode === 'bypass') return this.estimateRoute(from, to, this.crossingCorridorPoints)
      return this.estimateRoute(from, to)
    },

    async loadAutoRoute(from, to, via = null, mode = 'direct') {
      if (!from || !to) return null
      if (from.id === to.id) {
        return { km: 0, rawMinutes: 0, coords: [[from.lat, from.lon], [from.lat, from.lon]], source: 'zero', mode }
      }
      // Яндекс (нужен apiKey) → публичный OSRM (5с) → null, вызывающий оставит fallback
      let result = await this.routeFromYandex(from, to, via)
      if (!result) result = await this.routeFromOsrm(from, to, via)
      if (result) result.mode = mode
      return result
    },

    async loadTrainRoute(from, to) {
      const stationFrom = this.trainFromPoint
      const stationTo = this.trainToPoint
      if (!stationFrom || !stationTo) return null
      const [firstResult, secondResult] = await Promise.all([
        this.loadAutoRoute(from, stationFrom, null, 'train'),
        this.loadAutoRoute(stationTo, to, null, 'train'),
      ])
      const first = this.normalizeLeg(firstResult || this.estimateRouteOrZero(from, stationFrom), from)
      const second = this.normalizeLeg(secondResult || this.estimateRouteOrZero(stationTo, to), stationTo)
      return this.trainResult(from, to, first, second, Boolean(firstResult && secondResult))
    },

    async loadRoute() {
      const revision = ++routeRevision
      const from = this.fromPoint
      const to = this.toPoint

      if (!from || !to || from.id === to.id) {
        this.geo = null
        this.routeLoading = false
        this.refreshMap()
        return
      }

      const mode = this.activeCrossingMode
      const key = `${from.id}|${to.id}`
      const cacheKey = `${key}|${mode}`

      if (routeCache.has(cacheKey)) {
        this.geo = routeCache.get(cacheKey)
        this.routeLoading = false
        this.refreshMap()
        return
      }

      if (this.geo && this.geo.key === key && (this.geo.mode || 'direct') === mode) {
        const resolved = mode === 'train'
          ? Boolean(this.geo.exact)
          : Boolean(this.geo.source) && !['override', 'haversine', 'bypass', 'train'].includes(this.geo.source)
        if (resolved) {
          this.refreshMap()
          return
        }
      }

      // Сразу показываем км из override/оценки, линию А→Б; геометрию дорог дотягиваем фоном.
      const fallback = this.estimateRouteForMode(from, to, mode)
      if (fallback) {
        fallback.key = key
        fallback.mode = mode
        this.geo = fallback
        this.refreshMap()
      }

      this.routeLoading = true
      try {
        let result = null
        if (mode === 'train') result = await this.loadTrainRoute(from, to)
        else if (mode === 'bypass') result = await this.loadAutoRoute(from, to, this.crossingCorridorPoints, 'bypass')
        else result = await this.loadAutoRoute(from, to, null, 'direct')
        if (revision !== routeRevision) return

        if (result) {
          result.key = key
          result.mode = mode
          routeCache.set(cacheKey, result)
          this.geo = result
        } else if (fallback) {
          routeCache.set(cacheKey, fallback)
          this.geo = fallback
        } else {
          this.geo = null
        }
      } finally {
        if (revision === routeRevision) {
          this.routeLoading = false
          this.refreshMap()
        }
      }
    },

    /* ---------- data helpers ---------- */

    findPoint(value) {
      const needle = String(value || '').trim().toLowerCase()
      if (!needle) return null
      return (
        this.points.find((p) => p.name.toLowerCase() === needle) ||
        this.points.find((p) => (p.alias || []).some((a) => a.toLowerCase() === needle)) ||
        null
      )
    },

    normalize(value) {
      return String(value || '')
        .toLowerCase()
        .replace(/ё/g, 'е')
        .trim()
    },

    get defaultPointList() {
      const seen = new Set()
      const out = []
      const push = (point) => {
        if (point && !seen.has(point.id)) {
          seen.add(point.id)
          out.push(point)
        }
      }
      this.routes.popular.forEach((route) => {
        push(this.points.find((p) => p.id === route.from))
        push(this.points.find((p) => p.id === route.to))
      })
      this.points.forEach(push)
      return out.slice(0, 8)
    },

    searchPoints(query) {
      const q = this.normalize(query)
      if (!q) return this.defaultPointList

      const scored = []
      for (const point of this.points) {
        const name = this.normalize(point.name)
        const city = this.normalize(point.city)
        const aliases = (point.alias || []).map((a) => this.normalize(a))

        let score = -1
        if (name.startsWith(q)) score = 0
        else if (city.startsWith(q)) score = 1
        else if (name.includes(q)) score = 2
        else if (city.includes(q)) score = 3
        else if (aliases.some((a) => a.includes(q))) score = 4

        if (score >= 0) scored.push({ point, score })
      }

      return scored
        .sort((a, b) => a.score - b.score || a.point.name.localeCompare(b.point.name))
        .slice(0, 8)
        .map((item) => item.point)
    },

    listFor(which) {
      return this.searchPoints(this.listState[which].query)
    },

    openList(which) {
      const other = which === 'from' ? 'to' : 'from'
      this.listState[other].open = false
      this.listState[other].index = -1
      const state = this.listState[which]
      state.query = ''
      state.index = -1
      state.open = true
    },

    closeList(which) {
      const state = this.listState[which]
      state.open = false
      state.index = -1
    },

    onFieldFocus(which, event) {
      this.openList(which)
      // выделяем текст: чтобы набирать новое название, не стирая старое руками
      const input = event.target
      if (input.value) {
        requestAnimationFrame(() => input.select())
      }
    },

    onFieldClick(which) {
      // после Esc поле остаётся в фокусе, поэтому focus уже не сработает — открываем по клику
      if (this.listState[which].open) return
      this.openList(which)
    },

    onFieldInput(which, event) {
      const value = event.target.value
      if (which === 'from') this.fromName = value
      else this.toName = value
      const state = this.listState[which]
      state.query = value
      state.index = -1
      state.open = true
    },

    onFieldKeydown(which, event) {
      const state = this.listState[which]
      const items = this.listFor(which)

      if (event.key === 'ArrowDown') {
        event.preventDefault()
        state.open = true
        state.index = Math.min(state.index + 1, items.length - 1)
      } else if (event.key === 'ArrowUp') {
        event.preventDefault()
        state.index = Math.max(state.index - 1, 0)
      } else if (event.key === 'Enter') {
        if (state.open && state.index >= 0 && items[state.index]) {
          event.preventDefault()
          this.pickPoint(which, items[state.index])
        }
      } else if (event.key === 'Escape') {
        this.closeList(which)
      } else if (event.key === 'Tab') {
        this.closeList(which)
      }
    },

    pickPoint(which, point) {
      if (which === 'from') this.fromName = point.name
      else this.toName = point.name
      this.closeList(which)
    },

    isPicked(which, point) {
      const value = which === 'from' ? this.fromPoint : this.toPoint
      return value ? value.id === point.id : false
    },

    pointLabel(point) {
      if (!point) return ''
      const city = String(point.city || '').trim()
      return city && city !== point.name ? city : ''
    },

    seatsLabel(n) {
      return `${n} ${plural(n, ['место', 'места', 'мест'])}`
    },

    bagsLabel(n) {
      return `${n} ${plural(n, ['чемодан', 'чемодана', 'чемоданов'])}`
    },

    money,

    kmBetween(fromId, toId) {
      const a = this.points.find((p) => p.id === fromId)
      const b = this.points.find((p) => p.id === toId)
      return estimateKm(a, b, overrides, this.calc)
    },

    get railFromPoint() {
      return this.points.find((p) => p.id === this.crossing.railFrom) || null
    },

    get railToPoint() {
      return this.points.find((p) => p.id === this.crossing.railTo) || null
    },

    get crossingViaPoint() {
      const point = this.points.find((p) => p.id === this.crossing.via)
      if (point) return point
      if (this.crossing.corridor.length) {
        const first = this.crossing.corridor[0]
        return { id: 'crossing-via', name: first.name, lat: first.lat, lon: first.lon }
      }
      return null
    },

    get crossingCorridorPoints() {
      return this.corridorFor(this.fromPoint)
    },

    corridorFor(point) {
      const list = this.crossing.corridor.length
        ? this.crossing.corridor
        : (this.crossingViaPoint ? [{ name: this.crossingViaPoint.name, lat: this.crossingViaPoint.lat, lon: this.crossingViaPoint.lon }] : [])
      return isMainlandPoint(point) ? [...list].reverse() : list
    },

    get trainFromPoint() {
      if (!this.isCrossRegion) return null
      return isMainlandPoint(this.fromPoint) ? this.railToPoint : this.railFromPoint
    },

    get trainToPoint() {
      if (!this.isCrossRegion) return null
      return isMainlandPoint(this.fromPoint) ? this.railFromPoint : this.railToPoint
    },

    get crossingAvailable() {
      return Boolean(this.crossing.enabled && this.crossingCorridorPoints.length)
    },

    get trainAvailable() {
      return Boolean(this.crossingAvailable && this.railFromPoint && this.railToPoint)
    },

    get isCrossRegion() {
      return this.crossingAvailable && isCrossRegionRoute(this.fromPoint, this.toPoint)
    },

    get activeCrossingMode() {
      if (!this.isCrossRegion) return 'direct'
      return this.crossingMode === 'train' && this.trainAvailable ? 'train' : 'bypass'
    },

    get crossingModeLabel() {
      return this.activeCrossingMode === 'train' ? 'авто + поезд через мост' : 'объезд через Перекоп'
    },

    get crossingModeShort() {
      return this.activeCrossingMode === 'train' ? 'Поезд через мост' : 'Объезд через Перекоп'
    },

    pointName(id) {
      const point = this.points.find((p) => p.id === id)
      return point ? point.name : id
    },

    get fromPoint() {
      return this.findPoint(this.fromName)
    },

    get toPoint() {
      return this.findPoint(this.toName)
    },

    get routeInfo() {
      const from = this.fromPoint
      const to = this.toPoint
      if (!from || !to || from.id === to.id) return null

      const key = `${from.id}|${to.id}`
      const mode = this.activeCrossingMode
      const geo = this.geo && this.geo.key === key && (this.geo.mode || 'direct') === mode ? this.geo : null
      const step = this.calc.time_round
      const roundMinutes = (raw) => Math.max(step, Math.round(raw / step) * step)

      if (geo && Number.isFinite(geo.km)) {
        if (mode === 'train' && Array.isArray(geo.legs)) {
          const raw = Number.isFinite(geo.rawMinutes)
            ? geo.rawMinutes
            : estimateMinutes(geo.km, this.calc) + this.crossing.railMinutes + this.crossing.transferMinutes
          const minutes = roundMinutes(raw)
          return { km: geo.km, minutes, duration: formatDuration(minutes), exact: Boolean(geo.exact), source: 'train', mode: 'train', legs: geo.legs }
        }
        if (mode === 'bypass') {
          const raw = Number.isFinite(geo.rawMinutes) ? geo.rawMinutes : estimateMinutes(geo.km, this.calc)
          const minutes = roundMinutes(raw)
          return { km: geo.km, minutes, duration: formatDuration(minutes), exact: Boolean(geo.rawMinutes), source: geo.source || 'bypass', mode: 'bypass' }
        }
        if (Number.isFinite(geo.rawMinutes)) {
          const minutes = roundMinutes(geo.rawMinutes)
          return { km: geo.km, minutes, duration: formatDuration(minutes), exact: true, source: geo.source, mode: 'direct' }
        }
      }

      if (mode === 'train') {
        const fallback = this.estimateTrainRoute(from, to)
        if (fallback) {
          return { km: fallback.km, minutes: fallback.minutes, duration: formatDuration(fallback.minutes), exact: false, source: 'train', mode: 'train', legs: fallback.legs }
        }
      }
      if (mode === 'bypass') {
        const fallback = this.estimateRoute(from, to, this.crossingCorridorPoints)
        if (fallback) {
          return { km: fallback.km, minutes: fallback.minutes, duration: formatDuration(fallback.minutes), exact: false, source: 'bypass', mode: 'bypass' }
        }
      }

      const km = estimateKm(from, to, overrides, this.calc)
      const minutes = estimateMinutes(km, this.calc)
      return { km, minutes, duration: formatDuration(minutes), exact: false, source: overrides[`${from.id}|${to.id}`] != null ? 'override' : 'haversine', mode: 'direct' }
    },

    get routeKmLabel() {
      const km = this.routeInfo?.km
      if (!Number.isFinite(km)) return ''
      return this.activeCrossingMode === 'train' ? `${km} км на авто` : `${km} км`
    },

    get durationLabel() {
      return this.routeInfo ? this.routeInfo.duration : ''
    },

    get today() {
      return todayISO()
    },

    get dateLabel() {
      return formatDate(this.date)
    },

    get selectedTariff() {
      return this.tariffs.find((t) => t.id === this.tariffId) || this.tariffs[0]
    },

    priceFor(tariff) {
      return this.breakdownFor(tariff)?.total ?? null
    },

    /** Own ₽/км saved for this route and tariff in «Маршруты», if any. */
    routePerKmFor(tariff, fromId, toId) {
      if (!tariff || !fromId || !toId) return undefined
      return routePrices[`${fromId}|${toId}|${tariff.id}`]
    },

    routePerKm(tariff) {
      const from = this.fromPoint
      const to = this.toPoint
      if (!tariff || !from || !to) return undefined
      return this.routePerKmFor(tariff, from.id, to.id)
    },

    breakdownFor(tariff) {
      const info = this.routeInfo
      if (!info) return null
      const options = { roundTrip: this.roundTrip, extraStop: this.extraStop, childSeat: this.childSeat }
      if (info.mode === 'train' && Array.isArray(info.legs)) {
        const autoLegs = info.legs
          .filter((leg) => leg.type === 'auto' && leg.km > 0)
          .map((leg) => ({ from: leg.from, to: leg.to, km: leg.km, perKm: this.routePerKmFor(tariff, leg.from, leg.to) }))
        return priceBreakdownLegs(tariff, autoLegs, options, this.calc)
      }
      return priceBreakdown(tariff, info.km, {
        ...options,
        perKm: this.routePerKm(tariff),
      }, this.calc)
    },

    get trainPriceParts() {
      if (this.activeCrossingMode !== 'train') return []
      return this.breakdownFor(this.selectedTariff)?.parts || []
    },

    get labBreakdown() {
      return this.breakdownFor(this.selectedTariff)
    },

    get labDistanceSource() {
      const labels = { yandex: 'Яндекс', osrm: 'OSRM', override: 'Ручной маршрут', haversine: 'Оценка по координатам', bypass: 'Объезд через Перекоп', train: 'Авто + поезд' }
      return labels[this.routeInfo?.source] || 'Маршрут не выбран'
    },

    get tariffOptions() {
      return this.tariffs.map((t) => ({
        ...t,
        price: this.priceFor(t),
        fits: this.passengers <= t.seats,
      }))
    },

    get total() {
      return this.priceFor(this.selectedTariff)
    },

    get totalLabel() {
      if (!this.routeInfo) return '—'
      if (this.selectedTariff && this.selectedTariff.onRequest) return 'По запросу'
      return this.total ? money(this.total) : '—'
    },

    get canBook() {
      if (this.labMode || !this.routeInfo || !this.selectedTariff) return false
      if (this.selectedTariff.onRequest) return true
      return Boolean(this.total) && this.passengers <= this.selectedTariff.seats
    },

    get popularRoutes() {
      const economy = this.tariffs.find((t) => t.id === this.routes.tariff) || this.tariffs[0]
      if (!economy) return []
      return this.routes.popular
        .map((r) => {
          const from = this.points.find((p) => p.id === r.from)
          const to = this.points.find((p) => p.id === r.to)
          if (!from || !to) return null
          const cross = this.crossingAvailable && isCrossRegionRoute(from, to)
          const route = cross ? this.estimateRoute(from, to, this.corridorFor(from)) : this.estimateRoute(from, to)
          const km = route?.km
          if (!km) return null
          const minutes = route.minutes ?? estimateMinutes(km, this.calc)
          const perKm = routePrices[`${from.id}|${to.id}|${economy.id}`] ?? economy.perKm
          const price = roundPrice(Math.max(economy.minPrice, economy.base + perKm * km), this.calc)
          return { from, to, km, duration: formatDuration(minutes), price: economy.onRequest ? null : price, tariffName: economy.name }
        })
        .filter(Boolean)
    },

    /* ---------- calculator actions ---------- */

    setRoute(fromId, toId) {
      const from = this.points.find((p) => p.id === fromId)
      const to = this.points.find((p) => p.id === toId)
      if (from) this.fromName = from.name
      if (to) this.toName = to.name
      this.scrollToCalculator()
    },

    swap() {
      const from = this.fromName
      this.fromName = this.toName
      this.toName = from
    },

    get maxPassengers() {
      return Math.max(1, ...this.tariffs.map((tariff) => Number(tariff.seats) || 0))
    },

    stepPax(delta) {
      const next = Math.min(this.maxPassengers, Math.max(1, this.passengers + delta))
      this.passengers = next
      const current = this.selectedTariff
      if (current && current.seats < next) {
        const fit =
          this.tariffs
            .filter((t) => t.seats >= next && !t.onRequest)
            .sort((a, b) => a.minPrice - b.minPrice)[0] ||
          this.tariffs.filter((t) => t.seats >= next).sort((a, b) => a.seats - b.seats)[0]
        if (fit) this.tariffId = fit.id
      }
    },

    selectTariff(tariff) {
      if (!tariff.fits) return
      this.tariffId = tariff.id
    },

    chooseTariff(id) {
      const tariff = this.tariffs.find((t) => t.id === id)
      if (!tariff) return
      this.tariffId = tariff.id
      this.scrollToCalculator()
    },

    scrollToCalculator() {
      const target = document.getElementById('calculator')
      if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' })
    },

    /* ---------- booking ---------- */

    openBooking() {
      if (!this.canBook) return
      this.errors = {}
      this.submitError = ''
      this.bookingOpen = true
      this.successOpen = false
      document.body.style.overflow = 'hidden'
      this.$nextTick(() => this.$refs.nameInput && this.$refs.nameInput.focus())
    },

    closeBooking() {
      this.bookingOpen = false
      if (!this.successOpen) document.body.style.overflow = ''
    },

    closeSuccess() {
      this.successOpen = false
      document.body.style.overflow = ''
    },

    closeAll() {
      if (this.bookingOpen) {
        this.closeBooking()
      } else if (this.successOpen) {
        this.closeSuccess()
      } else {
        this.mobileOpen = false
      }
    },

    get errorsList() {
      return Object.values(this.errors)
    },

    validateBooking() {
      const errors = {}
      if (this.name.trim().length < 2) errors.name = 'Укажите, как к вам обращаться'
      if (this.phone.replace(/\D/g, '').length !== 11) errors.phone = 'Введите номер полностью'
      if (!this.consent) errors.consent = 'Нужно согласие на обработку данных'
      if (!this.date) errors.date = 'Укажите дату'
      if (!this.time) {
        errors.time = 'Укажите время'
      } else if (this.date === todayISO()) {
        const [h, m] = this.time.split(':').map(Number)
        const now = new Date()
        if (h * 60 + m <= now.getHours() * 60 + now.getMinutes() + 30) {
          errors.time = 'Выберите время не раньше чем через 30 минут'
        }
      }
      this.errors = errors
      return Object.keys(errors).length === 0
    },

    async submitBooking() {
      if (this.labMode || this.submitting) return
      if (!this.validateBooking() || !this.selectedTariff || !this.fromPoint || !this.toPoint) return

      const order = this.buildOrder(this.selectedTariff)
      this.submitting = true
      this.submitError = ''
      try {
        const result = await this.sendOrder(order)
        if (result.delivered) {
          order.delivered = true
          if (result.number) order.id = result.number
          if (result.totalLabel) order.price = result.totalLabel
        }
        this.saveOrder(order)
        this.order = order
        this.bookingOpen = false
        this.successOpen = true
        this.copied = false
      } catch (error) {
        this.submitError = error?.message || 'Не удалось отправить заявку. Попробуйте ещё раз или позвоните нам.'
        if (error?.fieldErrors && typeof error.fieldErrors === 'object') {
          this.errors = { ...this.errors, ...error.fieldErrors }
        }
      } finally {
        this.submitting = false
      }
    },

    buildOrder(tariff) {
      const isRequest = Boolean(tariff.onRequest)
      const info = this.routeInfo
      const autoLegs = info?.mode === 'train' && Array.isArray(info.legs)
        ? info.legs.filter((leg) => leg.type === 'auto').map((leg) => ({ from: leg.from, to: leg.to, km: leg.km }))
        : []
      return {
        id: `PT-${Math.random().toString(36).slice(2, 7).toUpperCase()}`,
        delivered: false,
        from: this.fromPoint.name,
        to: this.toPoint.name,
        fromSlug: this.fromPoint.id,
        toSlug: this.toPoint.id,
        date: this.date,
        time: this.time,
        passengers: this.passengers,
        roundTrip: this.roundTrip,
        childSeat: this.childSeat,
        extraStop: this.extraStop,
        tariff: tariff.name,
        tariffSlug: tariff.id,
        cars: tariff.cars,
        km: info ? info.km : null,
        minutes: info ? info.minutes : null,
        crossing: this.isCrossRegion ? this.activeCrossingMode : '',
        crossingLabel: this.isCrossRegion ? this.crossingModeLabel : '',
        legs: autoLegs,
        clientTotal: isRequest ? null : this.total,
        price: isRequest ? 'по запросу' : money(this.total),
        name: this.name.trim(),
        phone: this.phone,
        comment: this.comment.trim(),
      }
    },

    orderPayload(order) {
      return {
        name: order.name,
        phone: order.phone,
        comment: order.comment,
        from: order.fromSlug,
        to: order.toSlug,
        tariff: order.tariffSlug,
        date: order.date,
        time: order.time,
        passengers: order.passengers,
        round_trip: order.roundTrip,
        extra_stop: order.extraStop,
        child_seat: order.childSeat,
        distance_km: order.km,
        duration_minutes: order.minutes,
        total: order.clientTotal,
        crossing_mode: order.crossing,
        legs: order.legs,
        consent: this.consent,
        company: this.company,
      }
    },

    /**
     * Sends the order to WordPress. A missing backend (static build, unavailable
     * API) returns a non-delivered result so the dialog falls back to the
     * messenger flow; a server error with a Russian message is rethrown.
     */
    async sendOrder(order) {
      const base = typeof window !== 'undefined' && typeof window.ptApiBase === 'string'
        ? window.ptApiBase.replace(/\/+$/, '')
        : ''
      const endpoint = /\/wp-json$/.test(base) ? `${base}/pt/v1/order` : `${base}/wp-json/pt/v1/order`

      let response
      try {
        response = await fetch(endpoint, {
          method: 'POST',
          headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
          body: JSON.stringify(this.orderPayload(order)),
        })
      } catch (error) {
        return { delivered: false }
      }

      if (response.status === 404 || response.status === 405) return { delivered: false }

      let data = null
      try {
        data = await response.json()
      } catch (error) {
        data = null
      }

      // Static hosting may answer with an HTML page and a success status.
      if (!data && response.ok) return { delivered: false }

      if (response.status === 201) {
        return { delivered: true, number: data?.number, totalLabel: data?.total_label }
      }

      const message = typeof data?.message === 'string' && data.message.trim()
        ? data.message
        : 'Не удалось отправить заявку. Попробуйте ещё раз или позвоните нам.'
      const error = new Error(message)
      error.fieldErrors = data?.data?.errors && typeof data.data.errors === 'object' ? data.data.errors : {}
      throw error
    },

    saveOrder(order) {
      try {
        const raw = localStorage.getItem(ORDERS_KEY)
        const list = raw ? JSON.parse(raw) : []
        list.unshift({ ...order, createdAt: new Date().toISOString() })
        localStorage.setItem(ORDERS_KEY, JSON.stringify(list.slice(0, 100)))
      } catch (error) {
        // приватный режим или переполнение хранилища — заявка остаётся в памяти
      }
    },

    startNewOrder() {
      this.closeSuccess()
      this.order = null
      this.name = ''
      this.phone = ''
      this.comment = ''
      this.consent = false
      this.company = ''
      this.submitError = ''
      this.errors = {}
    },

    get orderSummary() {
      if (!this.order) return ''
      return [
        `Маршрут: ${this.order.from} → ${this.order.to}`,
        this.order.crossingLabel ? `Как добраться: ${this.order.crossingLabel}` : '',
        `Дата и время: ${formatDate(this.order.date)}, ${this.order.time}`,
        `Пассажиров: ${this.order.passengers}`,
        `Тариф: ${this.order.tariff}`,
        `Стоимость: ${this.order.price}`,
        this.order.roundTrip ? 'Туда и обратно: да' : '',
        this.order.childSeat ? 'Детское кресло: да' : '',
        this.order.extraStop ? 'Дополнительная остановка: да' : '',
      ]
        .filter(Boolean)
        .join('\n')
    },

    get orderText() {
      if (!this.order) return ''
      return [
        `Заявка ${this.order.id} — ${this.site.brand}`,
        this.orderSummary,
        `Имя: ${this.order.name}`,
        `Телефон: ${this.order.phone}`,
        this.order.comment ? `Комментарий: ${this.order.comment}` : '',
        '',
        'Оплата водителю после поездки.',
      ]
        .filter(Boolean)
        .join('\n')
    },

    get maxHref() {
      return this.site.max || 'https://max.ru/'
    },

    get tgHref() {
      return `https://t.me/share/url?url=${encodeURIComponent('https://protransfer.ru')}&text=${encodeURIComponent(this.orderText)}`
    },

    get mailHref() {
      const subject = this.order ? `Заявка ${this.order.id} — трансфер` : 'Заявка на трансфер'
      return `mailto:${this.site.email}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(this.orderText)}`
    },

    async copyOrder() {
      const text = this.orderText
      try {
        await navigator.clipboard.writeText(text)
      } catch (error) {
        const area = document.createElement('textarea')
        area.value = text
        area.style.position = 'fixed'
        area.style.opacity = '0'
        document.body.appendChild(area)
        area.select()
        document.execCommand('copy')
        area.remove()
      }
      this.copied = true
      window.setTimeout(() => {
        this.copied = false
      }, 2500)
    },

    /* ---------- inputs ---------- */

    onPhoneInput(event) {
      let digits = event.target.value.replace(/\D/g, '')
      if (!digits) {
        this.phone = ''
        return
      }
      if (digits[0] === '8') digits = `7${digits.slice(1)}`
      if (digits[0] !== '7') digits = `7${digits}`
      digits = digits.slice(0, 11)

      let out = '+7'
      if (digits.length > 1) out += ` (${digits.slice(1, 4)}`
      if (digits.length >= 4) out += `) ${digits.slice(4, 7)}`
      if (digits.length >= 7) out += `-${digits.slice(7, 9)}`
      if (digits.length >= 9) out += `-${digits.slice(9, 11)}`

      this.phone = out
      event.target.value = out
    },
  }
}
