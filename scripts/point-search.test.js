import test from 'node:test'
import assert from 'node:assert/strict'
import app from '../src/js/app.js'

// Payload shaped like the current bootstrap response: a point is a name, a
// city and its search variants. There is no point type any more.
const points = [
  { id: 'kerch-yuzhnaya', name: 'Керчь-Южная', city: 'Керчь', lat: 45.31, lon: 36.42, alias: ['жд керчь южная', 'керчь южная'] },
  { id: 'kerch', name: 'Керчь', city: 'Керчь', lat: 45.35, lon: 36.47, alias: [] },
  { id: 'sip-air', name: 'Аэропорт Симферополь', city: 'Симферополь', lat: 45.05, lon: 33.97, alias: ['SIP'] },
]

function instance(data = points) {
  return app({
    site: { brand: 'Про Трансфер' },
    points: data,
    tariffs: [],
    reviews: [],
    faq: [],
    routes: { overrides: [], popular: [{ from: 'sip-air', to: 'kerch' }] },
    calc: {},
  })
}

test('a point is found by name, city and search variants without a type', () => {
  const state = instance()
  assert.equal(state.findPoint('Керчь-Южная')?.id, 'kerch-yuzhnaya')
  assert.equal(state.findPoint('жд керчь южная')?.id, 'kerch-yuzhnaya')
  assert.deepEqual(state.searchPoints('южная').map((p) => p.id), ['kerch-yuzhnaya'])
  assert.equal(state.searchPoints('симферополь')[0].id, 'sip-air')
  assert.deepEqual(state.searchPoints('керчь').map((p) => p.id).sort(), ['kerch', 'kerch-yuzhnaya'])
})

test('the suggestion list and the row subtitle no longer depend on a type', () => {
  const state = instance()
  assert.deepEqual(state.defaultPointList.map((p) => p.id), ['sip-air', 'kerch', 'kerch-yuzhnaya'])
  assert.equal(state.pointLabel(points[0]), 'Керчь')
  assert.equal(state.pointLabel(points[1]), '')
  assert.equal(state.pointLabel(points[2]), 'Симферополь')
})
