import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { normalizeSite } from '../src/js/content.js'

test('older bootstrap receives new copy defaults while deliberate empty text is preserved', () => {
  const site = normalizeSite({ brand: 'Custom', copy: { hero_title: '', hero_description: 'Changed' } })
  assert.equal(site.brand, 'Custom')
  assert.equal(site.copy.hero_title, '')
  assert.equal(site.copy.hero_description, 'Changed')
  assert.ok(site.copy.hero_accent)
})

test('every content marker has a field and both landing entries stay identical', () => {
  const schema = JSON.parse(readFileSync('wordpress/plugins/pt-core/data/content.json', 'utf8'))
  for (const key of Object.keys(schema)) assert.match(key, /^[a-z][a-z0-9_]*$/, `Invalid Carbon field key: ${key}`)
  const landing = readFileSync('index.html', 'utf8')
  assert.equal(landing, readFileSync('preview.html', 'utf8'))
  for (const file of ['index.html', 'legal.html']) {
    for (const match of readFileSync(file, 'utf8').matchAll(/data-pt-(?:copy|legal)="([^"]+)"/g)) {
      assert.ok(schema[match[1]], `Missing field: ${match[1]}`)
    }
  }
})
