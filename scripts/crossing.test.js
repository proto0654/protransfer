import test from 'node:test'
import assert from 'node:assert/strict'
import app from '../src/js/app.js'
import { calculatorSettings, isCrossRegionRoute, priceBreakdownLegs } from '../src/js/calc.js'

const points = [
  { id: 'sip-air', name: 'Аэропорт Симферополь', lat: 45.0522, lon: 33.9751 },
  { id: 'yalta', name: 'Ялта', lat: 44.4952, lon: 34.1663 },
  { id: 'anapa', name: 'Анапа', lat: 44.8949, lon: 37.3169 },
  { id: 'kerch-yuzhnaya', name: 'Керчь-Южная', lat: 45.318079, lon: 36.429475 },
  { id: 'taman', name: 'Тамань-Пассажирская', lat: 45.2167, lon: 36.7167 },
  { id: 'armyansk', name: 'Армянск', lat: 46.108, lon: 33.692 },
]

const crossing = {
  enabled: true,
  railFrom: 'kerch-yuzhnaya',
  railTo: 'taman',
  via: 'armyansk',
  corridor: [
    { id: 'armyansk', name: 'Армянск', lat: 46.108, lon: 33.692 },
    { id: 'rostov', name: 'Ростов-на-Дону', lat: 47.2357, lon: 39.7015 },
  ],
  railMinutes: 45,
  transferMinutes: 30,
}

const routes = {
  popular: [],
  crossing,
  overrides: [
    { from: 'sip-air', to: 'kerch-yuzhnaya', km: 200 },
    { from: 'taman', to: 'anapa', km: 88 },
    { from: 'sip-air', to: 'armyansk', km: 250 },
    { from: 'armyansk', to: 'rostov', km: 500 },
    { from: 'rostov', to: 'anapa', km: 300 },
  ],
}

const tariff = { id: 'comfort', name: 'Комфорт', base: 200, perKm: 20, minPrice: 1000, seats: 4 }

function instance() {
  return app({ site: {}, points, tariffs: [tariff], reviews: [], faq: [], routes, calc: calculatorSettings({}) })
}

test('crossing geography and two-car prices are shared by both engines', () => {
  const sip = points[0]
  const anapa = points[2]
  assert.equal(isCrossRegionRoute(sip, anapa), true)
  assert.equal(isCrossRegionRoute(points[0], points[1]), false)

  const legs = [
    { from: 'sip-air', to: 'kerch-yuzhnaya', km: 200 },
    { from: 'taman', to: 'anapa', km: 88 },
  ]
  const plain = priceBreakdownLegs(tariff, legs, {}, calculatorSettings({}))
  assert.equal(plain.km, 288)
  assert.equal(plain.parts.length, 2)
  assert.equal(plain.parts[0].basePrice, 4200)
  assert.equal(plain.parts[1].basePrice, 1960)
  assert.equal(plain.total, 6160)

  const round = priceBreakdownLegs(tariff, legs, { roundTrip: true, extraStop: true }, calculatorSettings({}))
  assert.equal(round.beforeRounding, 12204)
  assert.equal(round.total, 12200)
})

test('a Crimea-mainland route starts on the bypass and switches to two cars plus the train', () => {
  const trip = instance()
  trip.fromName = 'Аэропорт Симферополь'
  trip.toName = 'Анапа'

  assert.equal(trip.isCrossRegion, true)
  assert.equal(trip.activeCrossingMode, 'bypass')
  assert.equal(trip.routeInfo.mode, 'bypass')
  assert.equal(trip.routeInfo.km, 1050)
  assert.equal(trip.priceFor(tariff), 21200)
  assert.match(trip.crossingModeLabel, /Перекоп/)

  trip.crossingMode = 'train'
  assert.equal(trip.activeCrossingMode, 'train')
  assert.equal(trip.routeInfo.mode, 'train')
  assert.equal(trip.routeInfo.km, 288)
  assert.equal(trip.routeInfo.minutes, 320)
  assert.equal(trip.routeInfo.legs[0].to, 'kerch-yuzhnaya')
  assert.equal(trip.routeInfo.legs[1].type, 'rail')
  assert.equal(trip.routeInfo.legs[2].from, 'taman')
  assert.equal(trip.trainPriceParts.length, 2)
  assert.equal(trip.priceFor(tariff), 6160)

  trip.roundTrip = true
  assert.equal(trip.priceFor(tariff), 11700)
})

test('the reverse direction swaps the stations and local routes ignore the switch', () => {
  const trip = instance()
  trip.fromName = 'Анапа'
  trip.toName = 'Аэропорт Симферополь'
  trip.crossingMode = 'train'

  assert.equal(trip.isCrossRegion, true)
  assert.equal(trip.routeInfo.legs[0].to, 'taman')
  assert.equal(trip.routeInfo.legs[2].from, 'kerch-yuzhnaya')
  assert.equal(trip.priceFor(tariff), 6160)

  trip.fromName = 'Аэропорт Симферополь'
  trip.toName = 'Ялта'
  assert.equal(trip.isCrossRegion, false)
  assert.equal(trip.activeCrossingMode, 'direct')
  assert.equal(trip.routeInfo.mode, 'direct')
})

test('without the mainland station the bypass stays available and the train switch falls back to it', () => {
  const trip = app({
    site: {},
    points: points.filter((point) => point.id !== 'taman'),
    tariffs: [tariff],
    reviews: [],
    faq: [],
    routes,
    calc: calculatorSettings({}),
  })
  trip.fromName = 'Аэропорт Симферополь'
  trip.toName = 'Анапа'
  trip.crossingMode = 'train'

  assert.equal(trip.isCrossRegion, true)
  assert.equal(trip.trainAvailable, false)
  assert.equal(trip.activeCrossingMode, 'bypass')
  assert.equal(trip.routeInfo.mode, 'bypass')
  assert.equal(trip.priceFor(tariff), 21200)
})
