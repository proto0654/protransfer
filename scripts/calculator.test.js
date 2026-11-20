import test from 'node:test'
import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import app from '../src/js/app.js'
import { calculatorSettings, estimateKm, estimateMinutes, priceBreakdown } from '../src/js/calc.js'

const points = [{ id: 'a', name: 'A', lat: 45, lon: 34 }, { id: 'b', name: 'B', lat: 45.5, lon: 34.5 }]
const tariff = { id: 'test', name: 'Test', base: 200, perKm: 20, minPrice: 1000, seats: 4 }
const overrides = { road_factor: 1.7, km_round: 10, speed_short: 30, speed_mid: 40, speed_long: 70, speed_mid_threshold: 30, speed_long_threshold: 200, time_buffer: 20, time_round: 10, price_round: 50, round_trip_factor: 1.5, extra_stop_price: 350, child_seat_price: 200 }

test('saved settings affect distance, duration and both price calculations', () => {
  const settings = calculatorSettings(overrides)
  const instance = app({ site: {}, points, tariffs: [tariff], reviews: [], faq: [], routes: { overrides: [], popular: [] }, calc: settings })
  instance.fromName = 'A'; instance.toName = 'B'; instance.roundTrip = true; instance.extraStop = true; instance.childSeat = true
  const phpOptions = Object.fromEntries(Object.entries(overrides).map(([k, v]) => [`pt_${k}`, v]))
  const command = process.platform === 'win32' ? 'php.bat' : 'php'
  const php = JSON.parse(execFileSync(command, ['scripts/calculator-fixture.php'], { encoding: 'utf8', input: JSON.stringify(phpOptions), shell: process.platform === 'win32' }))
  assert.equal(instance.routeInfo.km, php.km)
  assert.equal(instance.routeInfo.minutes, php.minutes)
  assert.equal(instance.priceFor(tariff), php.total)
  assert.equal(estimateKm(points[0], points[1], { 'a|b': 77 }, settings), 77)
  assert.equal(estimateMinutes(30, settings), 80)
})

test('invalid settings fall back and zero surcharges remain valid', () => {
  const settings = calculatorSettings({ speed_short: 0, km_round: -2, extra_stop_price: 0, child_seat_price: 0 })
  assert.equal(settings.speed_short, 50)
  assert.equal(settings.km_round, 5)
  assert.equal(settings.extra_stop_price, 0)
  assert.equal(settings.child_seat_price, 0)
})


test('breakdown uses the same minimum, options and rounding as the public price', () => {
  const settings = calculatorSettings({ round_trip_factor: 1.9, extra_stop_price: 500, child_seat_price: 150, price_round: 10 })
  const d = priceBreakdown(tariff, 10, { roundTrip: true, extraStop: true, childSeat: true }, settings)
  assert.equal(d.formula, 400); assert.equal(d.basePrice, 1000)
  assert.equal(d.beforeRounding, 2550); assert.equal(d.total, 2550)
  assert.equal(priceBreakdown({ ...tariff, onRequest: true }, 10, {}, settings).total, null)
  assert.equal(priceBreakdown(tariff, null, {}, settings), null)
})

test('lab preview accepts settings only from the same-origin parent', () => {
  const oldWindow = globalThis.window
  let listener; const parent = { postMessage() {} }
  globalThis.window = { ptCalculatorLab: true, parent, location: { origin: 'https://example.test' }, addEventListener: (_, fn) => { listener = fn } }
  try {
    const instance = app({ site: {}, points, tariffs: [tariff], reviews: [], faq: [], routes: { overrides: [], popular: [] } })
    instance.fromName = 'A'; instance.toName = 'B'; instance.$watch = () => {}; instance.$nextTick = (fn) => fn()
    instance.initCalculatorLab()
    const before = instance.priceFor(tariff)
    listener({ origin: 'https://other.test', source: parent, data: { type: 'pt-calculator-preview', settings: { extra_stop_price: 999 } } })
    assert.equal(instance.calc.extra_stop_price, 500)
    listener({ origin: 'https://example.test', source: {}, data: { type: 'pt-calculator-preview', settings: { extra_stop_price: 999 } } })
    assert.equal(instance.calc.extra_stop_price, 500)
    listener({ origin: 'https://example.test', source: parent, data: { type: 'pt-calculator-preview', settings: { extra_stop_price: 999 } } })
    instance.extraStop = true
    assert.equal(instance.priceFor(tariff), before + 1000)
  } finally { globalThis.window = oldWindow }
})


test('provider duration is rerounded when preview settings change', () => {
  const instance = app({ site: {}, points, tariffs: [tariff], reviews: [], faq: [], routes: { overrides: [], popular: [] } })
  instance.fromName = 'A'; instance.toName = 'B'
  instance.geo = { key: 'a|b', km: 70, rawMinutes: 23, minutes: 25, source: 'osrm' }
  assert.equal(instance.routeInfo.minutes, 25)
  instance.calc.time_round = 10
  assert.equal(instance.routeInfo.minutes, 20)
  assert.equal(instance.routeInfo.source, 'osrm')
})

test('a slow result cannot replace the distance for a more recent route', async () => {
  const morePoints = [...points, { id: 'c', name: 'C', lat: 46, lon: 35 }]
  const instance = app({ site: {}, points: morePoints, tariffs: [tariff], reviews: [], faq: [], routes: { overrides: [], popular: [] } })
  let completeOlderRoute
  instance.routeFromYandex = (_from, to) => to.id === 'b'
    ? new Promise((resolve) => { completeOlderRoute = resolve })
    : Promise.resolve({ km: 100, rawMinutes: 70, source: 'yandex' })
  const olderRoute = instance.loadRoute()
  instance.toName = 'C'
  await instance.loadRoute()
  completeOlderRoute({ km: 40, rawMinutes: 35, source: 'yandex' })
  await olderRoute
  assert.equal(instance.routeInfo.km, 100)
  assert.equal(instance.routeLoading, false)
})
