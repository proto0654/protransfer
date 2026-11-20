import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import vm from 'node:vm'

test('changing the comparison route clears distance and keeps it independent until the iframe route changes', () => {
  function field(value = '', type = 'text') {
    const listeners = new Map()
    return {
      value, type, checked: false,
      addEventListener: (event, listener) => listeners.set(event, listener),
      fire: (event) => listeners.get(event)(),
    }
  }
  const fields = { from: field('a'), to: field('b'), tariff: field('standard'), distance_km: field(),
    round_trip: field('', 'checkbox'), child_seat: field('', 'checkbox'), extra_stop: field('', 'checkbox') }
  const frame = { ...field(), contentWindow: {} }
  const form = { ...field(), dataset: {}, elements: { namedItem: (name) => fields[name] } }
  const elements = { 'pt-debug-form': form, 'pt-live-result': {}, 'pt-calc-frontend': frame,
    'pt-save-settings': field(), 'pt-save-status': {} }
  let receiveMessage
  const origin = 'https://example.test'

  vm.runInNewContext(readFileSync(new URL('../wordpress/plugins/pt-core/includes/admin-debug.js', import.meta.url), 'utf8'), {
    AbortController,
    FormData: class { set() {} },
    clearTimeout() {},
    setTimeout() {},
    fetch: () => new Promise(() => {}),
    document: { getElementById: (id) => elements[id], addEventListener() {} },
    window: { location: { origin }, addEventListener: (_, listener) => { receiveMessage = listener } },
  })
  const report = (to, km) => receiveMessage({ origin, source: frame.contentWindow,
    data: { type: 'pt-calculator-state', from: 'a', to, km, tariff: 'standard' } })

  report('b', 50)
  assert.equal(fields.distance_km.value, '50')
  fields.distance_km.value = '55'
  fields.distance_km.fire('input')
  report('b', 60)
  assert.equal(fields.distance_km.value, '55')
  fields.to.value = 'c'
  fields.to.fire('change')
  assert.equal(fields.distance_km.value, '')
  report('b', 70)
  assert.equal(fields.to.value, 'c')
  assert.equal(fields.distance_km.value, '')
  report('d', 90)
  assert.equal(fields.to.value, 'd')
  assert.equal(fields.distance_km.value, '90')
})
