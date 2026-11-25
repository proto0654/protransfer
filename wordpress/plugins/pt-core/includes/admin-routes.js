(() => {
  'use strict'

  const GROUPS = '.cf-complex__groups'
  const ROW = '.cf-complex__group'
  const TITLE = '.cf-complex__group-title'
  const NOT_SELECTED = '— не выбрано —'
  const PRICE_UNIT = ' ₽/км'
  // Own prices are optional: the collapsed panel keeps a row compact until the
  // editor decides to override the tariff rate for this route.
  const TOGGLE_CLASS = 'pt-route-prices-toggle'
  const HIDDEN_CLASS = 'pt-route-price-field--hidden'
  const STYLE_ID = 'pt-route-prices-style'

  /** Field key from a Carbon compact input name, or '' for unrelated fields. */
  function fieldSlug(name) {
    // Carbon prefixes the child field names of compact rows with an underscore
    // (`...[_pt_route_overrides][3][_from_slug]`), so `_?` is required to read
    // the real DOM; the canonical key returned stays unprefixed. The hidden
    // row marker `[value]` never matches the allowed slugs.
    const match = /^carbon_fields_compact_input\[[^\]]*\]\[[^\]]*\]\[_?(from_slug|to_slug|km|price_[A-Za-z0-9_]+)\]$/.exec(String(name || ''))
    return match ? match[1] : ''
  }

  /** Tariff titles for the own-price fields, localised by PHP. */
  function priceLabels() {
    if (typeof window === 'undefined') return {}
    const list = window.ptAdminRoutes && window.ptAdminRoutes.prices
    return list && typeof list === 'object' ? list : {}
  }

  /** Readable row heading built from the selected point labels. */
  function formatTitle({ from, to, km, prices } = {}) {
    const fromLabel = String(from ?? '').trim() || NOT_SELECTED
    const toLabel = String(to ?? '').trim() || NOT_SELECTED
    const distance = String(km ?? '').trim()
    const base = fromLabel + ' → ' + toLabel + (distance === '' ? '' : ': ' + distance + ' км')

    // Own route prices are the point of this screen, so a saved one is visible
    // in the collapsed header without opening the row.
    const own = (Array.isArray(prices) ? prices : [])
      .map(({ label, value }) => {
        const price = String(value ?? '').trim()
        return price === '' ? '' : String(label ?? '').trim() + ' ' + price
      })
      .filter(Boolean)

    return own.length === 0 ? base : base + ' · свои цены: ' + own.join(', ') + PRICE_UNIT
  }

  function selectedLabel(select) {
    if (!select) return ''
    const option = select.selectedOptions && select.selectedOptions[0]
    return option ? String(option.textContent || '').trim() : ''
  }

  function controlValue(control) {
    if (!control) return ''
    if (control.tagName === 'SELECT') return selectedLabel(control)
    return 'value' in control ? String(control.value ?? '') : ''
  }

  /** The price panel starts open only when the row already overrides a tariff. */
  function initialPricesState(values) {
    const filled = (Array.isArray(values) ? values : []).some((value) => String(value ?? '').trim() !== '')
    return filled ? 'open' : 'closed'
  }

  // Pure helpers are exposed for the Node test; the browser entry point below
  // exits early when document is unavailable.
  if (typeof window !== 'undefined') {
    window.ptRoutesAdminHelpers = { formatTitle, fieldSlug, initialPricesState }
  }
  if (typeof document === 'undefined') return

  let scheduled = false

  function collectPrices(byKey) {
    const labels = priceLabels()
    const seen = {}
    const prices = []
    Object.keys(labels).forEach((key) => {
      if (!byKey[key]) return
      seen[key] = true
      prices.push({ label: labels[key], value: controlValue(byKey[key]) })
    })
    // A field the tariff list no longer describes (tariff unpublished) still
    // shows its saved price instead of disappearing from the header.
    Object.keys(byKey).forEach((key) => {
      if (seen[key] || key.indexOf('price_') !== 0) return
      prices.push({ label: key.slice('price_'.length).replace(/_/g, ' '), value: controlValue(byKey[key]) })
    })
    return prices
  }

  /** Carbon wraps each field in `.cf-field`; fall back to the row-owned wrapper. */
  function fieldBox(control) {
    const box = control.closest('.cf-field')
    if (box) return box
    const row = control.closest(ROW)
    const body = row && row.querySelector('.cf-complex__group-body')
    let node = control
    while (node.parentElement && node.parentElement !== body && node.parentElement !== row) {
      node = node.parentElement
    }
    return node
  }

  function priceControls(row) {
    return Array.from(row.querySelectorAll('select, input'))
      .filter((control) => fieldSlug(control.name).indexOf('price_') === 0)
  }

  function injectStyles() {
    if (document.getElementById(STYLE_ID)) return
    const style = document.createElement('style')
    style.id = STYLE_ID
    style.textContent = [
      '.pt-route-prices-toggle{display:flex;align-items:center;justify-content:space-between;gap:8px;',
      'box-sizing:border-box;clear:both;width:100%;margin:4px 0 8px;padding:8px 10px;',
      'border:1px solid #dcdcde;border-radius:4px;background:#f6f7f7;font-size:12px;line-height:1.45;color:#50575e}',
      '.pt-route-prices-toggle strong{color:#1d2327;font-weight:600}',
      '.pt-route-prices-toggle .button{flex:none}',
      '.pt-route-price-field--hidden{display:none !important}',
    ].join('')
    document.head.appendChild(style)
  }

  /** Apply one row's open/closed state to the toggle and every price field. */
  function applyPricesState(row, state) {
    const open = state === 'open'
    row.dataset.ptPrices = open ? 'open' : 'closed'
    priceControls(row).forEach((control) => {
      fieldBox(control).classList.toggle(HIDDEN_CLASS, !open)
    })
    const toggle = row.querySelector('.' + TOGGLE_CLASS)
    if (!toggle) return
    const button = toggle.querySelector('button')
    const label = toggle.querySelector('.pt-route-prices-toggle__state')
    if (button) button.setAttribute('aria-expanded', open ? 'true' : 'false')
    if (label) {
      const next = open ? 'Скрыть' : 'Показать'
      if (label.textContent !== next) label.textContent = next
    }
  }

  /** Insert the price-panel note and toggle once per row, before the prices. */
  function ensurePricesToggle(row) {
    const controls = priceControls(row)
    if (controls.length === 0) return
    const firstBox = fieldBox(controls[0])
    const parent = firstBox.parentElement
    if (!parent) return

    if (!row.querySelector('.' + TOGGLE_CLASS)) {
      const toggle = document.createElement('div')
      toggle.className = TOGGLE_CLASS
      const text = document.createElement('span')
      text.className = 'pt-route-prices-toggle__text'
      text.innerHTML = '<strong>Свои цены за км</strong> — переопределяют ставку тарифа. Пустое поле — цена из тарифа.'
      const button = document.createElement('button')
      button.type = 'button'
      button.className = 'button button-small'
      button.setAttribute('aria-expanded', 'false')
      const label = document.createElement('span')
      label.className = 'pt-route-prices-toggle__state'
      label.textContent = 'Показать'
      button.appendChild(label)
      toggle.append(text, button)
      parent.insertBefore(toggle, firstBox)
      row.dataset.ptPrices = initialPricesState(controls.map(controlValue))
    }

    applyPricesState(row, row.dataset.ptPrices === 'open' ? 'open' : 'closed')
  }

  function updateRow(row) {
    ensurePricesToggle(row)
    const controls = Array.from(row.querySelectorAll('select, input'))
    const byKey = {}
    controls.forEach((control) => {
      const key = fieldSlug(control.name)
      if (key && !byKey[key]) byKey[key] = control
    })
    if (!byKey.from_slug && !byKey.to_slug) return

    const title = row.querySelector(TITLE)
    if (!title) return
    const next = formatTitle({
      from: selectedLabel(byKey.from_slug),
      to: selectedLabel(byKey.to_slug),
      km: controlValue(byKey.km),
      prices: collectPrices(byKey),
    })
    // Carbon re-renders the template header itself, so only replace real changes.
    if (title.textContent !== next) title.textContent = next
  }

  function updateAll() {
    scheduled = false
    document.querySelectorAll(GROUPS + ' ' + ROW).forEach(updateRow)
  }

  function schedule() {
    if (scheduled) return
    scheduled = true
    if (typeof window.requestAnimationFrame === 'function') window.requestAnimationFrame(updateAll)
    else updateAll()
  }

  function onFieldEvent(event) {
    const target = event.target
    if (!target || !target.closest || !target.closest(GROUPS)) return
    if (!target.matches || !target.matches('select, input')) return
    schedule()
  }

  function onToggleClick(event) {
    const toggle = event.target && event.target.closest ? event.target.closest('.' + TOGGLE_CLASS) : null
    if (!toggle) return
    const row = toggle.closest(ROW)
    if (!row) return
    event.preventDefault()
    applyPricesState(row, row.dataset.ptPrices === 'open' ? 'closed' : 'open')
  }

  function init() {
    if (!document.querySelector(GROUPS)) return

    injectStyles()
    document.addEventListener('change', onFieldEvent)
    document.addEventListener('input', onFieldEvent)
    document.addEventListener('click', onToggleClick)

    // Adding, copying, removing or dragging a row changes the container children.
    if (typeof MutationObserver === 'function') {
      const observer = new MutationObserver(schedule)
      document.querySelectorAll(GROUPS).forEach((group) => {
        observer.observe(group, { childList: true, subtree: true })
      })
    }

    updateAll()
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init)
  else init()
})()
