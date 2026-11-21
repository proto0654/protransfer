import test from 'node:test'
import assert from 'node:assert/strict'
import app from '../src/js/app.js'

const tomorrow = new Date(Date.now() + 24 * 60 * 60 * 1000)
const tomorrowISO = `${tomorrow.getFullYear()}-${String(tomorrow.getMonth() + 1).padStart(2, '0')}-${String(tomorrow.getDate()).padStart(2, '0')}`

const points = [
  { id: 'airport', name: 'Аэропорт', city: '', type: 'airport', lat: 45, lon: 34, alias: [] },
  { id: 'yalta', name: 'Ялта', city: 'Ялта', type: 'city', lat: 45.5, lon: 34.5, alias: [] },
]
const tariff = { id: 'comfort', name: 'Комфорт', cars: 'Kia Carnival', base: 1000, perKm: 30, minPrice: 1000, seats: 4, onRequest: false }

function instance() {
  const state = app({
    site: { brand: 'Про Трансфер', whatsapp: '79781234567', phoneHref: '+79781234567', email: 'info@example.test' },
    points,
    tariffs: [tariff],
    reviews: [],
    faq: [],
    routes: { overrides: [], popular: [] },
    calc: {},
  })
  state.fromName = 'Аэропорт'
  state.toName = 'Ялта'
  state.name = 'Иван'
  state.phone = '+7 (978) 123-45-67'
  state.comment = 'Рейс SU 123'
  state.consent = true
  state.date = tomorrowISO
  state.time = '10:00'
  state.passengers = 2
  return state
}

function response(status, body) {
  return {
    status,
    ok: status >= 200 && status < 300,
    async json() {
      if (body === undefined) throw new Error('not json')
      return body
    },
  }
}

function installStorage() {
  const store = new Map()
  globalThis.localStorage = {
    getItem: (key) => (store.has(key) ? store.get(key) : null),
    setItem: (key, value) => store.set(key, String(value)),
    removeItem: (key) => store.delete(key),
  }
  return store
}

function installWindow(value = {}) {
  const previous = globalThis.window
  globalThis.window = value
  return () => {
    if (previous === undefined) delete globalThis.window
    else globalThis.window = previous
  }
}

test('submitBooking posts the calculator state and accepts only a 201', async () => {
  installStorage()
  const restoreWindow = installWindow()
  const calls = []
  globalThis.fetch = async (url, options) => {
    calls.push({ url, options })
    return response(201, { id: 7, number: 'PT-7', total_label: '4 000 ₽' })
  }

  try {
    const state = instance()
    await state.submitBooking()

    assert.equal(calls.length, 1)
    assert.equal(calls[0].url, '/wp-json/pt/v1/order')
    assert.equal(calls[0].options.method, 'POST')
    assert.equal(calls[0].options.headers['Content-Type'], 'application/json')
    const body = JSON.parse(calls[0].options.body)
    assert.equal(body.name, 'Иван')
    assert.equal(body.phone, '+7 (978) 123-45-67')
    assert.equal(body.comment, 'Рейс SU 123')
    assert.equal(body.from, 'airport')
    assert.equal(body.to, 'yalta')
    assert.equal(body.tariff, 'comfort')
    assert.equal(body.date, tomorrowISO)
    assert.equal(body.time, '10:00')
    assert.equal(body.passengers, 2)
    assert.equal(body.round_trip, false)
    assert.equal(body.extra_stop, false)
    assert.equal(body.child_seat, false)
    assert.ok(body.distance_km > 0)
    assert.equal(body.total, state.total)
    assert.equal(body.consent, true)
    assert.equal(body.company, '')

    assert.equal(state.successOpen, true)
    assert.equal(state.bookingOpen, false)
    assert.equal(state.order.delivered, true)
    assert.equal(state.order.id, 'PT-7')
    assert.equal(state.order.price, '4 000 ₽')
    assert.equal(state.submitting, false)
    assert.equal(JSON.parse(localStorage.getItem('protransfer_orders')).length, 1)
  } finally {
    restoreWindow()
  }
})

test('window.ptApiBase prefixes the endpoint', async () => {
  installStorage()
  const restoreWindow = installWindow({ ptApiBase: 'https://wp.example.test/' })
  const calls = []
  globalThis.fetch = async (url, options) => {
    calls.push({ url, options })
    return response(201, { id: 8, number: 'PT-8', total_label: '4 000 ₽' })
  }

  try {
    await instance().submitBooking()
    assert.equal(calls[0].url, 'https://wp.example.test/wp-json/pt/v1/order')
  } finally {
    restoreWindow()
  }
})

test('window.ptApiBase may already include the wp-json prefix', async () => {
  installStorage()
  const restoreWindow = installWindow({ ptApiBase: 'https://wp.example.test/wp-json/' })
  const calls = []
  globalThis.fetch = async (url) => {
    calls.push(url)
    return response(201, { id: 10, number: 'PT-10', total_label: '4 000 ₽' })
  }

  try {
    await instance().submitBooking()
    assert.equal(calls[0], 'https://wp.example.test/wp-json/pt/v1/order')
  } finally {
    restoreWindow()
  }
})

test('the submit button is disabled while the request is in flight', async () => {  installStorage()
  const restoreWindow = installWindow()
  let release
  globalThis.fetch = () => new Promise((resolve) => {
    release = resolve
  })

  try {
    const state = instance()
    const pending = state.submitBooking()
    assert.equal(state.submitting, true)
    release(response(201, { id: 9, number: 'PT-9', total_label: '4 000 ₽' }))
    await pending
    assert.equal(state.submitting, false)
    assert.equal(state.successOpen, true)
  } finally {
    restoreWindow()
  }
})

test('a non-201 response shows the server message and keeps the entered data', async () => {
  installStorage()
  const restoreWindow = installWindow()
  globalThis.fetch = async () => response(422, {
    code: 'pt_order_invalid',
    message: 'Укажите корректный телефон в формате +7 (XXX) XXX-XX-XX',
    data: { status: 422, errors: { phone: 'Укажите корректный телефон в формате +7 (XXX) XXX-XX-XX' } },
  })

  try {
    const state = instance()
    await state.submitBooking()

    assert.equal(state.successOpen, false)
    assert.equal(state.bookingOpen, false)
    assert.equal(state.submitting, false)
    assert.equal(state.submitError, 'Укажите корректный телефон в формате +7 (XXX) XXX-XX-XX')
    assert.equal(state.errors.phone, 'Укажите корректный телефон в формате +7 (XXX) XXX-XX-XX')
    assert.equal(state.name, 'Иван')
    assert.equal(state.phone, '+7 (978) 123-45-67')
    assert.equal(state.comment, 'Рейс SU 123')
    assert.equal(state.date, tomorrowISO)
    assert.equal(localStorage.getItem('protransfer_orders'), null)
  } finally {
    restoreWindow()
  }
})

test('a 200 response without the documented body is not treated as success', async () => {
  installStorage()
  const restoreWindow = installWindow()
  globalThis.fetch = async () => response(200, { ok: true })

  try {
    const state = instance()
    await state.submitBooking()
    assert.equal(state.successOpen, false)
    assert.ok(state.submitError)
  } finally {
    restoreWindow()
  }
})

test('an unavailable backend falls back to a local draft and the messenger dialog', async () => {
  const store = installStorage()
  const restoreWindow = installWindow()
  globalThis.fetch = async () => {
    throw new TypeError('Failed to fetch')
  }

  try {
    const state = instance()
    await state.submitBooking()

    assert.equal(state.successOpen, true)
    assert.equal(state.bookingOpen, false)
    assert.equal(state.submitError, '')
    assert.equal(state.order.delivered, false)
    assert.match(state.order.id, /^PT-/)
    assert.equal(JSON.parse(store.get('protransfer_orders')).length, 1)
  } finally {
    restoreWindow()
  }
})

test('a static host answering 404 also falls back to the local draft', async () => {
  installStorage()
  const restoreWindow = installWindow()
  globalThis.fetch = async () => response(404, undefined)

  try {
    const state = instance()
    await state.submitBooking()
    assert.equal(state.successOpen, true)
    assert.equal(state.order.delivered, false)
  } finally {
    restoreWindow()
  }
})
