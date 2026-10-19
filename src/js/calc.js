const roadFactor = 1.35

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

export function estimateKm(from, to, overrides = {}) {
  if (!from || !to || from.id === to.id) return null
  const exact = overrides[`${from.id}|${to.id}`]
  if (exact) return exact
  const raw = haversineKm(from, to) * roadFactor
  return Math.max(5, Math.round(raw / 5) * 5)
}

export function estimateMinutes(km) {
  if (!km) return 0
  const speed = km > 150 ? 82 : km > 50 ? 68 : 50
  const minutes = (km / speed) * 60 + 10
  return Math.round(minutes / 5) * 5
}

export function formatDuration(minutes) {
  if (!minutes) return ''
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  if (h === 0) return `${m} мин`
  if (m === 0) return `${h} ч`
  return `${h} ч ${m} мин`
}

export function roundPrice(value) {
  return Math.round(value / 10) * 10
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
