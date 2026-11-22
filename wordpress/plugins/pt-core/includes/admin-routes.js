(() => {
  'use strict'

  const GROUPS = '.cf-complex__groups'
  const ROW = '.cf-complex__group'
  const TITLE = '.cf-complex__group-title'
  const NOT_SELECTED = '— не выбрано —'

  /** Field key from a Carbon compact input name, or '' for unrelated fields. */
  function fieldSlug(name) {
    const match = /^carbon_fields_compact_input\[[^\]]*\]\[[^\]]*\]\[(from_slug|to_slug|km)\]$/.exec(String(name || ''))
    return match ? match[1] : ''
  }

  /** Readable row heading built from the selected point labels. */
  function formatTitle({ from, to, km } = {}) {
    const fromLabel = String(from ?? '').trim() || NOT_SELECTED
    const toLabel = String(to ?? '').trim() || NOT_SELECTED
    const distance = String(km ?? '').trim()
    return fromLabel + ' → ' + toLabel + (distance === '' ? '' : ': ' + distance + ' км')
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
