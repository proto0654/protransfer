import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import vm from 'node:vm'

function setup({ unavailable = false, initial = ['45', '34'] } = {}) {
  const source = readFileSync('wordpress/plugins/pt-core/includes/admin-point.js', 'utf8')
  class Input { constructor(value) { this.value = value; this.events = [] } dispatchEvent(event) { this.events.push(event.type) } }
  Object.defineProperty(Input.prototype, 'value', { get() { return this._value }, set(value) { this._value = value } })
  const lat = new Input(initial[0]); const lon = new Input(initial[1]); const status = {}; const container = {}
  let clicked; let dragged; let marker
  const ymaps = {
    ready: (fn) => fn(),
    Map: class { constructor() { this.events = { add: (_, fn) => { clicked = fn } }; this.geoObjects = { add: () => {} } } },
    Placemark: class { constructor(coords) { let current = coords; marker = this; this.geometry = { setCoordinates: (v) => { current = v }, getCoordinates: () => current }; this.events = { add: (_, fn) => { dragged = fn } } } },
  }
  const document = { body: {}, head: { appendChild: (script) => { if (unavailable) script.onerror(); else script.onload() } },
    getElementById: (id) => id === 'pt-point-map' ? container : status,
    querySelector: (selector) => selector.includes('_pt_lat') ? lat : selector.includes('_pt_lon') ? lon : null,
    createElement: () => ({}), }
  const window = { ymaps, ptPointMapConfig: {} }
  const sandbox = { window, document, ymaps, URL, HTMLInputElement: Input, Event: class { constructor(type) { this.type = type } }, MutationObserver: class { observe() {} disconnect() {} }, setTimeout: () => 1, clearTimeout() {}, console }
  vm.runInNewContext(source, sandbox)
  return { lat, lon, status, click: (coords) => clicked({ get: () => coords }), drag: (coords) => { marker.geometry.setCoordinates(coords); dragged() } }
}

test('clicking and dragging a marker update both saved form fields', () => {
  const ui = setup(); ui.click([44.12345678, 34.12345678])
  assert.equal(ui.lat.value, '44.123457'); assert.equal(ui.lon.value, '34.123457')
  assert.deepEqual(ui.lat.events, ['input', 'change'])
  ui.drag([45, 35]); assert.equal(ui.lat.value, '45'); assert.equal(ui.lon.value, '35')
})

test('map load failure preserves existing coordinates and offers manual entry', () => {
  const ui = setup({ unavailable: true }); assert.equal(ui.lat.value, '45'); assert.equal(ui.lon.value, '34')
  assert.match(ui.status.textContent, /вручную/)
})
