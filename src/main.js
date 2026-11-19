import Alpine from 'alpinejs'
import app from './js/app.js'
import { loadSiteData } from './js/load-data.js'
import './style.css'

const data = await loadSiteData()
Alpine.data('app', () => app(data))
window.Alpine = Alpine
Alpine.start()
