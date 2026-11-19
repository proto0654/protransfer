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
    map[`${item.from}|${item.to}`] = item.km
    map[`${item.to}|${item.from}`] = item.km
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
