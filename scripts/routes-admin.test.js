import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import vm from 'node:vm'

function helpers() {
  const window = {}
  vm.runInNewContext(
    readFileSync(new URL('../wordpress/plugins/pt-core/includes/admin-routes.js', import.meta.url), 'utf8'),
    { window, document: undefined }
  )
  return window.ptRoutesAdminHelpers
}

test('route field keys come only from Carbon compact row names', () => {
  const { fieldSlug } = helpers()
  // Real names from the live DOM: Carbon prefixes compact row children with `_`.
  assert.equal(fieldSlug('carbon_fields_compact_input[_pt_route_overrides][3][_from_slug]'), 'from_slug')
  assert.equal(fieldSlug('carbon_fields_compact_input[_pt_route_overrides][3][_to_slug]'), 'to_slug')
  assert.equal(fieldSlug('carbon_fields_compact_input[_pt_route_overrides][3][_km]'), 'km')
  assert.equal(fieldSlug('carbon_fields_compact_input[_pt_popular_routes][0][_from_slug]'), 'from_slug')
  assert.equal(fieldSlug('carbon_fields_compact_input[_pt_popular_routes][0][_to_slug]'), 'to_slug')
  assert.equal(fieldSlug('carbon_fields_compact_input[_pt_popular_routes][0][value]'), '')
  // Unprefixed variants keep working.
  assert.equal(fieldSlug('carbon_fields_compact_input[_pt_route_overrides][3][from_slug]'), 'from_slug')
  assert.equal(fieldSlug('carbon_fields_compact_input[_pt_route_overrides][3][to_slug]'), 'to_slug')
  assert.equal(fieldSlug('carbon_fields_compact_input[_pt_route_overrides][3][km]'), 'km')
  assert.equal(fieldSlug('carbon_fields_compact_input[_pt_route_overrides][3][value]'), '')
  assert.equal(fieldSlug('carbon_fields_compact_input[_pt_popular_routes][0][alias]'), '')
  assert.equal(fieldSlug('pt_popular_tariff'), '')
  assert.equal(fieldSlug(''), '')
  assert.equal(fieldSlug(undefined), '')
})

test('row titles use selected point names and optional kilometres', () => {
  const { formatTitle } = helpers()
  assert.equal(formatTitle({ from: 'Ялта — курорт или посёлок', to: 'Симферополь — город' }), 'Ялта — курорт или посёлок → Симферополь — город')
  assert.equal(formatTitle({ from: 'Ялта', to: 'Симферополь', km: '50' }), 'Ялта → Симферополь: 50 км')
  assert.equal(formatTitle({ from: 'Ялта', to: 'Симферополь', km: '0' }), 'Ялта → Симферополь: 0 км')
  assert.equal(formatTitle({ from: 'Ялта', to: '' }), 'Ялта → — не выбрано —')
  assert.equal(formatTitle({ from: '', to: '' }), '— не выбрано — → — не выбрано —')
  assert.equal(formatTitle({ from: 'Ялта', to: 'Симферополь', km: '  50  ' }), 'Ялта → Симферополь: 50 км')
  assert.equal(formatTitle({ from: 'Ялта', to: 'Симферополь', km: '   ' }), 'Ялта → Симферополь')
})
