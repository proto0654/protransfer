import test from 'node:test'
import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import app from '../src/js/app.js'
import { buildOverrides, buildRoutePrices, calculatorSettings, estimateKm, priceBreakdown } from '../src/js/calc.js'

const points = [{ id: 'a', name: 'A', lat: 45, lon: 34 }, { id: 'b', name: 'B', lat: 45.5, lon: 34.5 }]
const tariff = { id: 'test', name: 'Test', base: 200, perKm: 20, minPrice: 1000, seats: 4 }
const secondTariff = { id: 'plain', name: 'Plain', base: 200, perKm: 20, minPrice: 1000, seats: 4 }
// Own route price: 70 км по 35 ₽/км для тарифа «test».
const overrides = [{ from: 'a', to: 'b', km: 70, prices: { test: 35 } }]

function phpQuote(routeRows) {
  const command = process.platform === 'win32' ? 'php.bat' : 'php'
  const input = JSON.stringify({ pt_route_overrides: routeRows })
  return JSON.parse(execFileSync(command, ['scripts/calculator-fixture.php'], { encoding: 'utf8', input, shell: process.platform === 'win32' }))
}

function instance(routes = overrides) {
  const data = { site: {}, points, tariffs: [tariff, secondTariff], reviews: [], faq: [], routes: { overrides: routes, popular: [] } }
  const view = app(data)
  view.fromName = 'A'
  view.toName = 'B'
  view.roundTrip = true
  view.extraStop = true
  view.childSeat = true
  return view
}

test('a route price beats the tariff rate and replaces the formula rate', () => {
  const view = instance()
  assert.equal(view.routePerKm(tariff), 35)
  assert.equal(view.breakdownFor(tariff).perKm, 35)
  assert.equal(view.breakdownFor(tariff).perKmSource, 'route')
  assert.equal(view.breakdownFor(tariff).formula, 200 + 35 * 70)
  // Another tariff on the same route keeps its own rate.
  assert.equal(view.routePerKm(secondTariff), undefined)
  assert.equal(view.breakdownFor(secondTariff).perKmSource, 'tariff')
  // The price is symmetric: B → A costs the same as A → B.
  view.fromName = 'B'
  view.toName = 'A'
  assert.equal(view.priceFor(tariff), view.breakdownFor(tariff).total)
  assert.equal(view.routePerKm(tariff), 35)
})

test('the site calculator and the server quote agree with a route price', () => {
  const view = instance()
  const php = phpQuote([{ from_slug: 'a', to_slug: 'b', km: '70', price_test: '35' }])
  assert.equal(php.per_km, 35)
  assert.equal(php.per_km_source, 'route')
  assert.equal(view.routeInfo.km, php.km)
  assert.equal(view.priceFor(tariff), php.total)
  assert.equal(view.breakdownFor(tariff).formula, php.formula_price)
})

test('without an own price both engines fall back to the tariff rate', () => {
  const view = instance([{ from: 'a', to: 'b', km: 70 }])
  const php = phpQuote([{ from_slug: 'a', to_slug: 'b', km: '70' }])
  assert.equal(view.breakdownFor(tariff).perKmSource, 'tariff')
  assert.equal(php.per_km_source, 'tariff')
  assert.equal(view.priceFor(tariff), php.total)
})

test('a price row without kilometres does not fake a distance', () => {
  const rows = [{ from: 'a', to: 'b', prices: { test: 35 } }]
  const map = buildOverrides(rows)
  assert.equal(Object.keys(map).length, 0)
  const settings = calculatorSettings({})
  const km = estimateKm(points[0], points[1], map, settings)
  assert.ok(Number.isFinite(km) && km > 0)
  assert.notEqual(km, 70)
  const prices = buildRoutePrices(rows)
  assert.equal(prices['a|b|test'], 35)
  assert.equal(prices['b|a|test'], 35)
  // Invalid prices stay out of the map.
  assert.deepEqual(buildRoutePrices([{ from: 'a', to: 'b', prices: { test: 0, plain: 'abc' } }]), {})
})

test('popular route cards use the route price of the card tariff', () => {
  const view = app({ site: {}, points, tariffs: [tariff], reviews: [], faq: [],
    routes: { overrides, popular: [{ from: 'a', to: 'b' }], tariff: 'test' } })
  const card = view.popularRoutes[0]
  assert.equal(card.price, Math.round((200 + 35 * card.km) / view.calc.price_round) * view.calc.price_round)
})

test('priceBreakdown accepts a per-route rate without mutating the tariff', () => {
  const settings = calculatorSettings({})
  const plain = priceBreakdown(tariff, 10, {}, settings)
  const routed = priceBreakdown(tariff, 10, { perKm: 50 }, settings)
  assert.equal(plain.perKm, 20)
  assert.equal(routed.perKm, 50)
  assert.equal(routed.formula, 200 + 50 * 10)
  assert.equal(tariff.perKm, 20)
})
