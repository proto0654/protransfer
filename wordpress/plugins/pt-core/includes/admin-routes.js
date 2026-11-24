(() => {
  'use strict'

  const GROUPS = '.cf-complex__groups'
  const ROW = '.cf-complex__group'
  const TITLE = '.cf-complex__group-title'
  const NOT_SELECTED = '— не выбрано —'
  const PRICE_UNIT = ' ₽/км'

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

  // Pure helpers are exposed for the Node test; the browser entry point below
  // exits early when document is unavailable.
  if (typeof window !== 'undefined') {
    window.ptRoutesAdminHelpers = { formatTitle, fieldSlug }
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

  function updateRow(row) {
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

  function init() {
    if (!document.querySelector(GROUPS)) return

    document.addEventListener('change', onFieldEvent)
    document.addEventListener('input', onFieldEvent)

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
