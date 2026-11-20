(() => {
  const container = document.getElementById('pt-point-map')
  const status = document.getElementById('pt-point-map-status')
  if (!container || !status) return
  const findField = (name) => document.querySelector(`input[name="carbon_fields_compact_input[_pt_${name}]"]`)
  let map
  let marker
  let observer
  let timeout
  let starting = false
  let timedOut = false
  const valid = (lat, lon) => Number.isFinite(lat) && Number.isFinite(lon) && Math.abs(lat) <= 90 && Math.abs(lon) <= 180
  function setValue(input, value) {
    const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set
    setter.call(input, String(Math.round(value * 1000000) / 1000000))
    input.dispatchEvent(new Event('input', { bubbles: true }))
    input.dispatchEvent(new Event('change', { bubbles: true }))
  }
  function selectPoint(coords) {
    const lat = findField('lat')
    const lon = findField('lon')
    if (!lat || !lon || !valid(coords[0], coords[1])) return
    setValue(lat, coords[0]); setValue(lon, coords[1])
    if (marker) marker.geometry.setCoordinates(coords)
    else {
      marker = new ymaps.Placemark(coords, {}, { draggable: true })
      marker.events.add('dragend', () => selectPoint(marker.geometry.getCoordinates()))
      map.geoObjects.add(marker)
    }
    status.textContent = 'Метка установлена. Сохраните точку кнопкой «Обновить» или «Опубликовать».'
  }
  function start() {
    const lat = findField('lat'); const lon = findField('lon')
    if (!lat || !lon || !window.ymaps || starting || timedOut) return false
    starting = true
    const hasCoords = lat.value !== '' && lon.value !== '' && valid(Number(lat.value), Number(lon.value))
    const coords = hasCoords ? [Number(lat.value), Number(lon.value)] : [45.15, 34.4]
    window.ymaps.ready(() => {
      if (timedOut) return
      clearTimeout(timeout)
      try {
        map = new ymaps.Map(container, { center: coords, zoom: hasCoords ? 11 : 7, controls: ['zoomControl', 'searchControl'] })
        if (hasCoords) {
          marker = new ymaps.Placemark(coords, {}, { draggable: true })
          marker.events.add('dragend', () => selectPoint(marker.geometry.getCoordinates()))
          map.geoObjects.add(marker)
        }
        map.events.add('click', (event) => selectPoint(event.get('coords')))
        status.textContent = 'Нажмите на карту, чтобы выбрать координаты.'
      } catch (error) {
        status.textContent = 'Не удалось открыть карту. Укажите координаты вручную.'
        console.error('[protransfer] Point map initialization failed', error)
      }
    })
    return true
  }
  function tryStart() {
    if (start()) observer?.disconnect()
  }
  const script = document.createElement('script')
  const url = new URL('https://api-maps.yandex.ru/2.1/')
  url.searchParams.set('lang', 'ru_RU')
  if (window.ptPointMapConfig?.key) url.searchParams.set('apikey', window.ptPointMapConfig.key)
  script.src = url.toString()
  script.async = true
  script.onload = tryStart
  script.onerror = () => { observer?.disconnect(); clearTimeout(timeout); status.textContent = 'Карта недоступна. Укажите координаты вручную.' }
  observer = new MutationObserver(tryStart)
  observer.observe(document.body, { childList: true, subtree: true })
  timeout = setTimeout(() => { timedOut = true; observer.disconnect(); status.textContent = 'Карта не загрузилась. Укажите координаты вручную.' }, 15000)
  document.head.appendChild(script)
})()
