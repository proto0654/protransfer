import test from 'node:test'
import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import app from '../src/js/app.js'
import { calculatorSettings, estimateKm, estimateMinutes } from '../src/js/calc.js'

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
