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

test('route row highlights its own price and tariff labels', () => {
  const { formatTitle } = helpers()
  const economy = { label: 'Эконом', value: '24' }
  const comfort = { label: 'Комфорт', value: ' 40 ' }
  const blank = { label: 'Бизнес', value: '' }
  assert.equal(
    formatTitle({ from: 'Ялта', to: 'Симферополь', km: '50', prices: [economy, blank] }),
    'Ялта → Симферополь: 50 км · свои цены: Эконом 24 ₽/км'
  )
  assert.equal(
    formatTitle({ from: 'Ялта', to: 'Симферополь', prices: [economy, comfort] }),
    'Ялта → Симферополь · свои цены: Эконом 24, Комфорт 40 ₽/км'
  )
  assert.equal(formatTitle({ from: 'Ялта', to: 'Симферополь', km: '50', prices: [] }), 'Ялта → Симферополь: 50 км')
  assert.equal(formatTitle({ from: 'Ялта', to: 'Симферополь', km: '50', prices: [blank] }), 'Ялта → Симферополь: 50 км')
  assert.equal(formatTitle({ from: 'Ялта', to: 'Симферополь', km: '50' }), 'Ялта → Симферополь: 50 км')
})

test('route field keys come only from Carbon compact row names', () => {
  const { fieldSlug } = helpers()
  // Real names from the live DOM: Carbon prefixes compact row children with `_`.
  assert.equal(fieldSlug('carbon_fields_compact_input[_pt_route_overrides][3][_from_slug]'), 'from_slug')
  assert.equal(fieldSlug('carbon_fields_compact_input[_pt_route_overrides][3][_to_slug]'), 'to_slug')
  assert.equal(fieldSlug('carbon_fields_compact_input[_pt_route_overrides][3][_km]'), 'km')
  assert.equal(fieldSlug('carbon_fields_compact_input[_pt_route_overrides][3][_price_comfort]'), 'price_comfort')
  assert.equal(fieldSlug('carbon_fields_compact_input[_pt_route_overrides][3][_price_comfort_pl]'), 'price_comfort_pl')
  assert.equal(fieldSlug('carbon_fields_compact_input[_pt_route_overrides][3][price_standard]'), 'price_standard')
  assert.equal(fieldSlug('carbon_fields_compact_input[_pt_route_overrides][3][_price_]'), '')
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
