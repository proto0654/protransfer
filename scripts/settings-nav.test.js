import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import vm from 'node:vm'

function helpers() {
  const window = {}
  vm.runInNewContext(readFileSync(new URL('../wordpress/plugins/pt-core/includes/admin-settings-nav.js', import.meta.url), 'utf8'), { window })
  return window.ptSettingsNavHelpers
}

test('tab labels drop the shared landing prefix and normalize spacing', () => {
  const { tabLabel } = helpers()
  assert.equal(tabLabel('Лендинг: Первый экран'), 'Первый экран')
  assert.equal(tabLabel('Лендинг: Контакты и связь'), 'Контакты и связь')
  assert.equal(tabLabel('Контакты и контент'), 'Контакты и контент')
  assert.equal(tabLabel('Поисковые системы и обложка'), 'Поисковые системы и обложка')
  assert.equal(tabLabel('  Лендинг:   Отзывы  '), 'Отзывы')
  assert.equal(tabLabel(''), '')
})

test('slugs are stable ASCII identifiers safe for URL hashes', () => {
  const { slugify } = helpers()
  assert.equal(slugify('Первый экран'), 'pervyy-ekran')
  assert.equal(slugify('Контакты и контент'), 'kontakty-i-kontent')
  assert.equal(slugify('Поисковые системы и обложка'), 'poiskovye-sistemy-i-oblozhka')
  assert.equal(slugify('FAQ и документы'), 'faq-i-dokumenty')
  assert.equal(slugify('---'), 'section')
})

test('dirty comparison handles text, checkboxes and multi-selects', () => {
  const { fieldValue, initialFieldValue, hasChanged } = helpers()
  assert.equal(fieldValue({ type: 'text', value: 'Новый адрес' }), 'Новый адрес')
  assert.equal(hasChanged({ type: 'text', value: 'Новый адрес' }, 'Старый адрес'), true)
  assert.equal(hasChanged({ type: 'text', value: 'Новый адрес' }, 'Новый адрес'), false)
  assert.equal(fieldValue({ type: 'checkbox', checked: true }), '1')
  assert.equal(fieldValue({ type: 'checkbox', checked: false }), '0')
  assert.equal(initialFieldValue({ type: 'checkbox', defaultChecked: true }), '1')
  const select = {
    tagName: 'SELECT', multiple: true, type: 'select-multiple',
    options: [{ value: 'a', selected: false }, { value: 'b', selected: true }],
  }
  assert.equal(fieldValue(select), 'b')
  assert.equal(hasChanged(select, 'a'), true)
})
