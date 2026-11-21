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
  assert.ok(site.copy.calc_title)
})

test('every content marker has a field and both landing entries stay identical', () => {
  const schema = JSON.parse(readFileSync('wordpress/plugins/pt-core/data/content.json', 'utf8'))
  for (const key of Object.keys(schema)) assert.match(key, /^[a-z][a-z0-9_]*$/, `Invalid Carbon field key: ${key}`)
  const landing = readFileSync('index.html', 'utf8')
  assert.equal(landing, readFileSync('preview.html', 'utf8'))
  for (const file of ['index.html', 'legal.html']) {
    for (const match of readFileSync(file, 'utf8').matchAll(/data-pt-(?:copy|legal|placeholder)="([^"]+)"/g)) {
      assert.ok(schema[match[1]], `Missing field: ${match[1]}`)
    }
  }
})

test('marked elements contain text only, never child elements', () => {
  for (const file of ['index.html', 'preview.html']) {
    const html = readFileSync(file, 'utf8')
    for (const match of html.matchAll(/<([a-z][a-z0-9]*)\b[^>]*data-pt-(?:copy|site)="([^"]+)"[^>]*>([\s\S]*?)<\/\1>/gi)) {
      assert.ok(
        !match[3].includes('<'),
        `${file}: marked ${match[1]} "${match[2]}" contains child markup; mark the innermost text node instead`,
      )
    }
  }
})
