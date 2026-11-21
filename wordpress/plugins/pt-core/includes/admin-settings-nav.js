(() => {
  'use strict'

  const LANDING_PREFIX = 'Лендинг: '
  const FIELD_SELECTOR = 'input, textarea, select'
  const TRANSLITERATION = {
    а: 'a', б: 'b', в: 'v', г: 'g', д: 'd', е: 'e', ё: 'e', ж: 'zh', з: 'z',
    и: 'i', й: 'y', к: 'k', л: 'l', м: 'm', н: 'n', о: 'o', п: 'p', р: 'r',
    с: 's', т: 't', у: 'u', ф: 'f', х: 'h', ц: 'ts', ч: 'ch', ш: 'sh', щ: 'sch',
    ъ: '', ы: 'y', ь: '', э: 'e', ю: 'yu', я: 'ya',
  }

  /** Box heading without the shared landing prefix, e.g. «Первый экран». */
  function tabLabel(heading) {
    const text = String(heading || '').replace(/\s+/g, ' ').trim()
    return text.startsWith(LANDING_PREFIX) ? text.slice(LANDING_PREFIX.length).trim() : text
  }

  /** Stable ASCII identifier used in the URL hash and localStorage. */
  function slugify(label) {
    let result = ''
    for (const character of String(label || '').toLowerCase()) {
      result += Object.prototype.hasOwnProperty.call(TRANSLITERATION, character) ? TRANSLITERATION[character] : character
    }
    result = result.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '')
    return result || 'section'
  }

  /** Comparable form of a form control, including checkboxes and multi-selects. */
  function fieldValue(field) {
    if (!field) return ''
    if (field.type === 'checkbox' || field.type === 'radio') return field.checked ? '1' : '0'
    if (field.tagName === 'SELECT' && field.multiple) {
      return Array.from(field.options || [])
        .filter((option) => option.selected)
        .map((option) => option.value)
        .join('\u0000')
    }
    return 'value' in field ? String(field.value ?? '') : ''
  }

  /** Value loaded with the page, used as the dirty-tracking baseline. */
  function initialFieldValue(field) {
    if (field.type === 'checkbox' || field.type === 'radio') return field.defaultChecked ? '1' : '0'
    if (field.tagName === 'SELECT' && field.multiple && field.options) {
      return Array.from(field.options)
        .filter((option) => option.defaultSelected)
        .map((option) => option.value)
        .join('\u0000')
    }
    return typeof field.defaultValue === 'string' ? field.defaultValue : fieldValue(field)
  }

  function hasChanged(field, initial) {
    return fieldValue(field) !== initial
  }

  // Pure helpers are exposed for the Node test; the browser entry point below
  // exits early when the admin screen has no Carbon boxes.
  if (typeof window !== 'undefined') {
    window.ptSettingsNavHelpers = { tabLabel, slugify, fieldValue, initialFieldValue, hasChanged }
  }
  if (typeof document === 'undefined') return

  const config = window.ptSettingsNavConfig || {}
  const storageKey = config.storageKey || 'pt-settings-nav-active'
  const initialValues = new WeakMap()
  const dirtyGroups = new Set()
  const structuralDirty = new Set()
  let submitting = false
  let activeItem = null
  let items = []

  function textOfHeading(box) {
    const heading = box.querySelector('.postbox-header .hndle, .postbox-header h2, .hndle, h2')
    return heading ? heading.textContent || '' : ''
  }

  function rememberField(field) {
    if (!initialValues.has(field)) initialValues.set(field, fieldValue(field))
  }

  function rememberFields(root) {
    if (root.nodeType === 1 && root.matches && root.matches(FIELD_SELECTOR)) rememberField(root)
    if (!root.querySelectorAll) return
    root.querySelectorAll(FIELD_SELECTOR).forEach(rememberField)
  }

  function fieldChanged(field) {
    const initial = initialValues.has(field) ? initialValues.get(field) : initialFieldValue(field)
    return hasChanged(field, initial)
  }

  function readStoredId() {
    try {
      return window.localStorage.getItem(storageKey) || ''
    } catch (error) {
      return ''
    }
  }

  function storeId(id) {
    try {
      window.localStorage.setItem(storageKey, id)
    } catch (error) {
      // Storage can be unavailable in private or hardened browser modes.
    }
  }

  function readHashId() {
    const raw = window.location && window.location.hash ? window.location.hash.replace(/^#/, '') : ''
    try {
      return decodeURIComponent(raw)
    } catch (error) {
      return raw
    }
  }

  function writeHashId(id) {
    if (window.history && typeof window.history.replaceState === 'function') {
      try {
        window.history.replaceState(null, '', '#' + id)
        return
      } catch (error) {
        // Fall through to the location hash when history is unavailable.
      }
    }
    if (window.location) window.location.hash = id
  }

  function setDirty(item, dirty) {
    if (dirty) dirtyGroups.add(item.id)
    else dirtyGroups.delete(item.id)
    item.tab.dataset.dirty = dirty ? 'true' : 'false'
    if (dirty) {
      item.tab.setAttribute('aria-label', item.label + ', есть несохранённые изменения')
      item.tab.title = 'Есть несохранённые изменения'
    } else {
      item.tab.removeAttribute('aria-label')
      item.tab.removeAttribute('title')
    }
  }

  function updateDirty(item) {
    const dirty = structuralDirty.has(item.id)
      || Array.from(item.box.querySelectorAll(FIELD_SELECTOR)).some(fieldChanged)
    setDirty(item, dirty)
  }

  function refreshDirty() {
    items.forEach(updateDirty)
  }

  function markStructuralDirty(item) {
    structuralDirty.add(item.id)
    setDirty(item, true)
  }

  function select(item, options) {
    if (!item) return
    activeItem = item
    refreshDirty()
    items.forEach((entry) => {
      const selected = entry === item
      entry.tab.setAttribute('aria-selected', selected ? 'true' : 'false')
      entry.tab.tabIndex = selected ? 0 : -1
      entry.box.classList.toggle('pt-settings-box-hidden', !selected)
      if (selected) entry.box.removeAttribute('aria-hidden')
      else entry.box.setAttribute('aria-hidden', 'true')
    })
    storeId(item.id)
    writeHashId(item.id)
    if (options && options.scroll) {
      try {
        item.box.scrollIntoView({ block: 'start', behavior: 'auto' })
      } catch (error) {
        item.box.scrollIntoView()
      }
    }
  }

  function init() {
    const boxes = Array.from(document.querySelectorAll('#poststuff .postbox.carbon-box'))
    if (!boxes.length) return

    const reservedIds = new Set(boxes.map((box) => box.id).filter(Boolean))
    function allocateId(base) {
      let candidate = base
      let suffix = 2
      while (reservedIds.has(candidate) || document.getElementById(candidate)) {
        candidate = base + '-' + suffix
        suffix += 1
      }
      reservedIds.add(candidate)
      return candidate
    }

    const itemsInOrder = boxes.map((box, index) => {
      const label = tabLabel(textOfHeading(box)) || 'Раздел ' + (index + 1)
      const id = allocateId('pt-settings-' + slugify(label))
      const tabId = allocateId(id + '-tab')
      const panelId = box.id || allocateId(id + '-panel')
      if (!box.id) box.id = panelId
      box.setAttribute('role', 'tabpanel')
      box.setAttribute('aria-labelledby', tabId)

      const tab = document.createElement('button')
      tab.type = 'button'
      tab.className = 'pt-settings-nav__tab'
      tab.id = tabId
      tab.setAttribute('role', 'tab')
      tab.setAttribute('aria-controls', panelId)
      tab.setAttribute('aria-selected', 'false')
      tab.tabIndex = -1
      tab.textContent = label

      return { id, label, box, tab }
    })
    items = itemsInOrder

    const itemById = new Map(items.map((item) => [item.id, item]))
    const itemByBox = new Map(items.map((item) => [item.box, item]))

    const nav = document.createElement('nav')
    nav.className = 'pt-settings-nav'
    nav.setAttribute('aria-label', 'Разделы настроек сайта')
    const tablist = document.createElement('div')
    tablist.className = 'pt-settings-nav__tabs'
    tablist.setAttribute('role', 'tablist')
    tablist.setAttribute('aria-label', 'Разделы настроек сайта')
    items.forEach((item) => {
      item.tab.addEventListener('click', () => select(item, { scroll: true }))
      tablist.appendChild(item.tab)
    })
    nav.appendChild(tablist)

    const sortable = items[0].box.closest('.meta-box-sortables')
    const mount = sortable ? sortable.parentNode : items[0].box.parentNode
    mount.insertBefore(nav, sortable || items[0].box)

    tablist.addEventListener('keydown', (event) => {
      const current = items.findIndex((item) => item.tab === event.target)
      if (current < 0) return
      let next = -1
      switch (event.key) {
        case 'ArrowLeft':
        case 'ArrowUp':
          next = (current - 1 + items.length) % items.length
          break
        case 'ArrowRight':
        case 'ArrowDown':
          next = (current + 1) % items.length
          break
        case 'Home':
          next = 0
          break
        case 'End':
          next = items.length - 1
          break
        case 'Enter':
        case ' ':
          event.preventDefault()
          select(items[current], { scroll: true })
          return
        default:
          return
      }
      event.preventDefault()
      items[next].tab.focus()
    })

    function onFieldEvent(event) {
      const target = event.target
      if (!target || !target.matches || !target.matches(FIELD_SELECTOR)) return
      const box = target.closest('.postbox.carbon-box')
      const item = box ? itemByBox.get(box) : null
      if (!item) return
      if (!initialValues.has(target)) initialValues.set(target, initialFieldValue(target))
      updateDirty(item)
    }

    function onComplexAction(event) {
      const button = event.target && event.target.closest
        ? event.target.closest('.cf-complex__group-action')
        : null
      if (!button) return
      const icon = button.querySelector('.dashicons-trash, .dashicons-admin-page')
      const item = icon ? itemByBox.get(button.closest('.postbox.carbon-box')) : null
      if (item) markStructuralDirty(item)
    }

    // Capture phase keeps tracking reliable when a field component stops the
    // event before it reaches the document bubble listeners.
    document.addEventListener('input', onFieldEvent, true)
    document.addEventListener('change', onFieldEvent, true)
    document.addEventListener('click', onComplexAction, true)

    items.forEach((item) => {
      rememberFields(item.box)
      setDirty(item, false)
    })

    // Carbon mounts its fields asynchronously. Remember their initial values
    // without replacing or re-rendering any of its DOM.
    if (typeof MutationObserver === 'function') {
      const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
          mutation.addedNodes.forEach((node) => {
            if (node.nodeType === 1) rememberFields(node)
          })
        })
      })
      items.forEach((item) => observer.observe(item.box, { childList: true, subtree: true }))
    }

    const form = document.getElementById('post')
    if (form) {
      form.addEventListener('submit', () => {
        submitting = true
        structuralDirty.clear()
        items.forEach((item) => setDirty(item, false))
      })
    }

    window.addEventListener('beforeunload', (event) => {
      if (submitting) return
      refreshDirty()
      if (dirtyGroups.size === 0) return
      const message = 'Есть несохранённые изменения'
      event.preventDefault()
      event.returnValue = message
      return message
    })

    window.addEventListener('hashchange', () => {
      const item = itemById.get(readHashId())
      if (item && (!activeItem || activeItem.id !== item.id)) select(item, { scroll: true })
    })

    const requested = itemById.get(readHashId()) || itemById.get(readStoredId()) || items[0]
    select(requested, { scroll: false })
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init)
  else init()
})()
