import defaults from '../../wordpress/plugins/pt-core/data/calc.json' with { type: 'json' }

export function calculatorSettings(settings = {}) {
  return Object.fromEntries(Object.entries(defaults).map(([key, fallback]) => {
    const value = Number(settings[key] ?? fallback)
    const positive = ['road_factor', 'km_round', 'speed_short', 'speed_mid', 'speed_long', 'time_round', 'price_round'].includes(key)
    const integerStep = ['km_round', 'time_round', 'price_round'].includes(key)
    return [key, Number.isFinite(value) && (positive ? value > 0 : value >= 0) && (!integerStep || Number.isInteger(value)) ? value : fallback]
  }))
}

export function routeKey(a, b) {
  return [a, b].sort().join('|')
}

export function buildOverrides(list) {
  const map = {}
  for (const item of list) {
    const km = Number(item.km)
    if (!Number.isFinite(km) || km <= 0) continue
    map[`${item.from}|${item.to}`] = km
    map[`${item.to}|${item.from}`] = km
  }
  return map
}

/**
 * Per-route prices from «Маршруты»: «from|to|tariff» => ₽/км, both directions.
 * The bootstrap payload keys prices by tariff slug.
 */
export function buildRoutePrices(list) {
  const map = {}
  for (const item of list) {
    const prices = item.prices || {}
    for (const [tariffId, raw] of Object.entries(prices)) {
      const price = Number(raw)
      if (!Number.isFinite(price) || price <= 0) continue
      map[`${item.from}|${item.to}|${tariffId}`] = price
      map[`${item.to}|${item.from}|${tariffId}`] = price
    }
  }
  return map
}

export function haversineKm(a, b) {
  const R = 6371
  const toRad = (deg) => (deg * Math.PI) / 180
  const dLat = toRad(b.lat - a.lat)
  const dLon = toRad(b.lon - a.lon)
  const lat1 = toRad(a.lat)
  const lat2 = toRad(b.lat)
  const h =
    Math.sin(dLat / 2) ** 2 +
    Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLon / 2) ** 2
  return 2 * R * Math.asin(Math.sqrt(h))
}

export function estimateKm(from, to, overrides = {}, settings = defaults) {
  if (!from || !to || from.id === to.id) return null
  const exact = overrides[`${from.id}|${to.id}`]
  if (exact) return exact
  const raw = haversineKm(from, to) * settings.road_factor
  return Math.max(settings.km_round, Math.round(raw / settings.km_round) * settings.km_round)
}

export function estimateMinutes(km, settings = defaults) {
  if (!km) return 0
  const speed = km > settings.speed_long_threshold ? settings.speed_long : km > settings.speed_mid_threshold ? settings.speed_mid : settings.speed_short
  const minutes = (km / speed) * 60 + settings.time_buffer
  return Math.round(minutes / settings.time_round) * settings.time_round
}

export function formatDuration(minutes) {
  if (!minutes) return ''
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  if (h === 0) return `${m} мин`
  if (m === 0) return `${h} ч`
  return `${h} ч ${m} мин`
}

export function roundPrice(value, settings = defaults) {
  return Math.round(value / settings.price_round) * settings.price_round
}

export function priceBreakdown(tariff, km, options, settings = defaults) {
  if (!tariff || !Number.isFinite(km) || km <= 0) return null
  // A route may carry its own ₽/км for this tariff; it replaces the tariff rate
  // while the base, minimum and surcharges stay as configured.
  const override = Number(options.perKm)
  const usesRoutePrice = Number.isFinite(override) && override > 0
  const perKm = usesRoutePrice ? override : Number(tariff.perKm)
  const formula = Number(tariff.base) + perKm * km
  const minimum = Number(tariff.minPrice)
  const basePrice = Math.max(minimum, formula)
  const multiplier = options.roundTrip ? settings.round_trip_factor : 1
  const extraStop = options.extraStop ? settings.extra_stop_price : 0
  const childSeat = options.childSeat ? settings.child_seat_price : 0
  const beforeRounding = basePrice * multiplier + extraStop + childSeat
  return { km, perKm, perKmSource: usesRoutePrice ? 'route' : 'tariff', formula, minimum, basePrice, multiplier, extraStop, childSeat, beforeRounding,
    roundStep: settings.price_round, onRequest: Boolean(tariff.onRequest),
    total: tariff.onRequest ? null : roundPrice(beforeRounding, settings) }
}

/**
 * A «train» crossing is two separate cars: one before the railway station and
 * one after it. Each leg pays its own minimum and its own route rate, while
 * round-trip and surcharges apply once to the sum.
 */
export function priceBreakdownLegs(tariff, legs, options = {}, settings = defaults) {
  if (!tariff || !Array.isArray(legs)) return null
  const parts = []
  for (const leg of legs) {
    const km = Number(leg.km)
    if (!Number.isFinite(km) || km <= 0) continue
    const override = Number(leg.perKm)
    const usesRoutePrice = Number.isFinite(override) && override > 0
    const perKm = usesRoutePrice ? override : Number(tariff.perKm)
    const formula = Number(tariff.base) + perKm * km
    const minimum = Number(tariff.minPrice)
    parts.push({
      from: leg.from || '', to: leg.to || '', km, perKm,
      perKmSource: usesRoutePrice ? 'route' : 'tariff',
      formula, minimum, basePrice: Math.max(minimum, formula),
    })
  }
  if (!parts.length) return null
  const basePrice = parts.reduce((sum, part) => sum + part.basePrice, 0)
  const multiplier = options.roundTrip ? settings.round_trip_factor : 1
  const extraStop = options.extraStop ? settings.extra_stop_price : 0
  const childSeat = options.childSeat ? settings.child_seat_price : 0
  const beforeRounding = basePrice * multiplier + extraStop + childSeat
  return {
    km: parts.reduce((sum, part) => sum + part.km, 0),
    parts,
    basePrice,
    minimum: parts.reduce((sum, part) => sum + part.minimum, 0),
    formula: parts.reduce((sum, part) => sum + part.formula, 0),
    multiplier, extraStop, childSeat, beforeRounding,
    roundStep: settings.price_round,
    onRequest: Boolean(tariff.onRequest),
    total: tariff.onRequest ? null : roundPrice(beforeRounding, settings),
  }
}

/** Crimea is everything west of the Kerch strait; the mainland starts east of it. */
export const MAINLAND_LON = 36.6

export function isMainlandPoint(point) {
  return Number(point?.lon) > MAINLAND_LON
}

export function isCrossRegionRoute(from, to) {
  if (!from || !to || from.id === to.id) return false
  return isMainlandPoint(from) !== isMainlandPoint(to)
}

export function money(value) {
  return `${new Intl.NumberFormat('ru-RU').format(value)} ₽`
}

export function formatDate(iso) {
  if (!iso) return ''
  const [year, month, day] = iso.split('-')
  return `${day}.${month}.${year}`
}

export function plural(n, forms) {
  const abs = Math.abs(n) % 100
  const last = abs % 10
  if (abs > 10 && abs < 20) return forms[2]
  if (last === 1) return forms[0]
  if (last >= 2 && last <= 4) return forms[1]
  return forms[2]
}

export function todayISO() {
  const d = new Date()
  const local = new Date(d.getTime() - d.getTimezoneOffset() * 60000)
  return local.toISOString().slice(0, 10)
}

export function defaultPickupTime() {
  const d = new Date(Date.now() + 2 * 60 * 60 * 1000)
  const minutes = d.getMinutes() < 30 ? '00' : '30'
  return `${String(d.getHours()).padStart(2, '0')}:${minutes}`
}
