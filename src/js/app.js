import {
  buildOverrides,
  calculatorSettings,
  priceBreakdown,
  estimateKm,
  estimateMinutes,
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
  const calc = calculatorSettings(data.calc)
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
          childSeat: this.childSeat, extraStop: this.extraStop, km: info?.km ?? null }
        const snapshot = JSON.stringify(state)
        if (lastReport === snapshot) return
        lastReport = snapshot
        window.parent.postMessage(state, window.location.origin)
      }
      for (const field of ['fromName', 'toName', 'tariffId', 'roundTrip', 'childSeat', 'extraStop', 'geo']) this.$watch(field, report)
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
      const coords =
        this.geo && this.geo.key === key && this.geo.coords
          ? this.geo.coords
          : [
              [from.lat, from.lon],
              [to.lat, to.lon],
            ]

      mapStore.layer.add(new ymaps.Polyline(coords, {}, { strokeColor: '#D93B31', strokeWidth: 9, strokeOpacity: 0.45 }))
      mapStore.layer.add(new ymaps.Polyline(coords, {}, { strokeColor: '#0B1422', strokeWidth: 4, strokeOpacity: 0.9 }))

      const pinFrom = this.pin('А', '#0B1422')
      const pinTo = this.pin('Б', '#D93B31')
      mapStore.layer.add(
        new ymaps.Placemark([from.lat, from.lon], { ...pinFrom.properties, hintContent: from.name }, pinFrom.options),
      )
      mapStore.layer.add(
        new ymaps.Placemark([to.lat, to.lon], { ...pinTo.properties, hintContent: to.name }, pinTo.options),
      )

      const bounds = coords.reduce(
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

    async routeFromYandex(from, to) {
      const apiKey = (this.site.map && this.site.map.apiKey) || ''
      if (!mapStore.ymaps || !apiKey) return null

      try {
        const route = await mapStore.ymaps.route(
          [
            [from.lat, from.lon],
            [to.lat, to.lon],
          ],
          { routingMode: 'auto', mapStateAutoApply: false },
        )
        const active = route.getActiveRoute()
        if (!active) return null

        const coords = []
        const segments = route.getPaths().get(0).getSegments()
        for (let i = 0; i < segments.getLength(); i += 1) {
          const piece = segments.get(i).getCoordinates()
          for (let j = 0; j < piece.length; j += 1) coords.push([piece[j][0], piece[j][1]])
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

    async routeFromOsrm(from, to) {
      const controller = new AbortController()
      const timer = setTimeout(() => controller.abort(), 5000)
      try {
        const url =
          'https://router.project-osrm.org/route/v1/driving/' +
          `${from.lon},${from.lat};${to.lon},${to.lat}` +
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

    estimateRoute(from, to) {
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

      const key = `${from.id}|${to.id}`

      if (routeCache.has(key)) {
        this.geo = routeCache.get(key)
        this.routeLoading = false
        this.refreshMap()
        return
      }

      if (this.geo && this.geo.key === key && this.geo.source && this.geo.source !== 'override' && this.geo.source !== 'haversine') {
        this.refreshMap()
        return
      }

      // Сразу показываем км из override/оценки, линию А→Б; геометрию дорог дотягиваем фоном.
      const fallback = this.estimateRoute(from, to)
      if (fallback) {
        fallback.key = key
        this.geo = fallback
        this.refreshMap()
      }

      this.routeLoading = true
      try {
        // Яндекс (нужен apiKey) → публичный OSRM (5с) → оставляем fallback
        let result = await this.routeFromYandex(from, to)
        if (revision !== routeRevision) return
        if (!result) result = await this.routeFromOsrm(from, to)
        if (revision !== routeRevision) return

        if (result) {
          result.key = key
          routeCache.set(key, result)
          this.geo = result
        } else if (fallback) {
          routeCache.set(key, fallback)
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
      if (this.geo?.key === key && Number.isFinite(this.geo.rawMinutes)) {
        const step = this.calc.time_round
        const minutes = Math.max(step, Math.round(this.geo.rawMinutes / step) * step)
        return {
          km: this.geo.km,
          minutes,
          duration: formatDuration(minutes),
          exact: true,
          source: this.geo.source,
        }
      }

      const km = estimateKm(from, to, overrides, this.calc)
      const minutes = estimateMinutes(km, this.calc)
      return { km, minutes, duration: formatDuration(minutes), exact: false, source: overrides[`${from.id}|${to.id}`] != null ? 'override' : 'haversine' }
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

    breakdownFor(tariff) {
      return priceBreakdown(tariff, this.routeInfo?.km, {
        roundTrip: this.roundTrip, extraStop: this.extraStop, childSeat: this.childSeat,
      }, this.calc)
    },

    get labBreakdown() {
      return this.breakdownFor(this.selectedTariff)
    },

    get labDistanceSource() {
      const labels = { yandex: 'Яндекс', osrm: 'OSRM', override: 'Ручной маршрут', haversine: 'Оценка по координатам' }
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
          const km = estimateKm(from, to, overrides, this.calc)
          const minutes = estimateMinutes(km, this.calc)
          const price = roundPrice(Math.max(economy.minPrice, economy.base + economy.perKm * km), this.calc)
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
        km: this.routeInfo ? this.routeInfo.km : null,
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
        total: order.clientTotal,
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
