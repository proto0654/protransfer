(() => {
  const form = document.getElementById('pt-debug-form')
  const output = document.getElementById('pt-live-result')
  const frame = document.getElementById('pt-calc-frontend')
  if (!form || !output) return
  let request
  let timer
  let manualDistance = false
  let lastFrameRoute
  const from = form.elements.namedItem('from')
  const to = form.elements.namedItem('to')
  const distance = form.elements.namedItem('distance_km')
  async function calculate() {
    clearTimeout(timer)
    request?.abort()
    const controller = new AbortController()
    request = controller
    const body = new FormData(form)
    body.set('action', 'pt_calc_debug')
    body.set('_ajax_nonce', form.dataset.nonce)
    output.textContent = 'Рассчитываем…'
    try {
      const response = await fetch(form.dataset.ajaxUrl, { method: 'POST', body, signal: controller.signal, credentials: 'same-origin' })
      const data = await response.json()
      if (controller.signal.aborted) return
      const r = data.data
      if (r?.settings) frame?.contentWindow?.postMessage({ type: 'pt-calculator-preview', settings: r.settings }, window.location.origin)
      if (!response.ok || !data.success) throw new Error(r?.error || r || 'Ошибка расчёта')
      const source = { manual: 'Введено вручную', override: 'Ручной маршрут', haversine: 'Оценка по координатам' }[r.km_source] || r.km_source
      const rows = [
        ['Источник расстояния', source],
        ['Расстояние и время', `${r.km} км · ${r.minutes} мин (оценка)`],
        ['Формула тарифа', `${r.base} + ${r.per_km} × ${r.km} = ${r.formula_price} ₽`],
        ['Минимальная цена', `${r.min_price} ₽`],
        ['После применения минимума', `${r.after_base_or_min} ₽`],
        ['Туда-обратно', r.round_trip ? `× ${r.round_trip_factor}` : 'Нет'],
        ['Дополнительная остановка', r.extra_stop ? `+ ${r.extra_stop_price} ₽` : 'Нет'],
        ['Детское кресло', r.child_seat ? `+ ${r.child_seat_price} ₽` : 'Нет'],
        ['Округление', `до ${r.settings.price_round} ₽`],
        ['Итого', r.total_label],
      ]
      output.replaceChildren()
      const title = document.createElement('h2'); title.textContent = 'Расчёт'; output.append(title)
      const table = document.createElement('table'); table.className = 'widefat striped'
      const tbody = document.createElement('tbody'); table.append(tbody)
      for (const [label, value] of rows) {
        const tr = document.createElement('tr')
        const th = document.createElement('th'); th.scope = 'row'; th.textContent = label
        const td = document.createElement('td'); td.textContent = value
        tr.append(th, td); tbody.append(tr)
      }
      output.append(table)
    } catch (error) {
      if (!controller.signal.aborted) output.textContent = `Не удалось рассчитать: ${error.message}`
    }
  }
  function schedule() {
    request?.abort()
    clearTimeout(timer)
    timer = setTimeout(calculate, 250)
  }
  document.addEventListener('input', (event) => { if (event.target.form === form) schedule() })
  document.addEventListener('change', (event) => { if (event.target.form === form) schedule() })
  form.addEventListener('submit', (event) => { event.preventDefault(); calculate() })
  frame?.addEventListener('load', calculate)
  window.addEventListener('message', (event) => {
    if (!frame || event.origin !== window.location.origin || event.source !== frame.contentWindow || event.data?.type !== 'pt-calculator-state') return
    if (!event.data.from || !event.data.to || event.data.from === event.data.to) return
    const iframeRoute = `${event.data.from}|${event.data.to}`
    const comparisonRoute = `${from.value}|${to.value}`
    if (iframeRoute === lastFrameRoute && iframeRoute !== comparisonRoute) return
    lastFrameRoute = iframeRoute
    if (iframeRoute !== comparisonRoute) manualDistance = false
    const values = { from: event.data.from, to: event.data.to, tariff: event.data.tariff,
      round_trip: Boolean(event.data.roundTrip), child_seat: Boolean(event.data.childSeat),
      extra_stop: Boolean(event.data.extraStop) }
    if (!manualDistance) values.distance_km = event.data.km == null ? '' : String(event.data.km)
    let changed = false
    for (const [name, value] of Object.entries(values)) {
      const field = form.elements.namedItem(name)
      if (!field) continue
      if (field.type === 'checkbox') { if (field.checked !== value) { field.checked = value; changed = true } }
      else if (field.value !== value) { field.value = value; changed = true }
    }
    if (changed) schedule()
  })
  for (const field of [from, to]) {
    field.addEventListener('change', () => {
      distance.value = ''
      manualDistance = false
    })
  }
  distance.addEventListener('input', () => { manualDistance = true })
  const save = document.getElementById('pt-save-settings')
  const status = document.getElementById('pt-save-status')
  save.addEventListener('click', async () => {
    const body = new FormData(form)
    body.set('action', 'pt_save_calc_settings')
    body.set('_ajax_nonce', form.dataset.nonce)
    save.disabled = true
    status.textContent = 'Сохраняем…'
    try {
      const response = await fetch(form.dataset.ajaxUrl, { method: 'POST', body, credentials: 'same-origin' })
      const data = await response.json()
      if (!response.ok || !data.success) throw new Error(data.data || 'Ошибка сохранения')
      status.textContent = 'Сохранено'
      calculate()
    } catch (error) {
      status.textContent = `Не удалось сохранить: ${error.message}`
    } finally {
      save.disabled = false
    }
  })
  document.addEventListener('input', (event) => { if (event.target.form === form && event.target.name.startsWith('calc[')) status.textContent = 'Есть несохранённые изменения' })
  calculate()
})()
