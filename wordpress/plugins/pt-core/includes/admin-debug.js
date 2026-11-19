(() => {
  const form = document.getElementById('pt-debug-form')
  const output = document.getElementById('pt-live-result')
  if (!form || !output) return
  let request
  let timer
  async function calculate() {
    request?.abort()
    request = new AbortController()
    const body = new FormData(form)
    body.set('action', 'pt_calc_debug')
    body.set('_ajax_nonce', form.dataset.nonce)
    output.textContent = 'Рассчитываем…'
    try {
      const response = await fetch(form.dataset.ajaxUrl, { method: 'POST', body, signal: request.signal, credentials: 'same-origin' })
      const data = await response.json()
      if (!response.ok || !data.success) throw new Error(data.data || 'Ошибка расчёта')
      const r = data.data
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
      if (error.name !== 'AbortError') output.textContent = `Не удалось рассчитать: ${error.message}`
    }
  }
  function schedule() { clearTimeout(timer); timer = setTimeout(calculate, 250) }
  document.addEventListener('input', (event) => { if (event.target.form === form) schedule() })
  document.addEventListener('change', (event) => { if (event.target.form === form) schedule() })
  form.addEventListener('submit', (event) => { event.preventDefault(); calculate() })
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
