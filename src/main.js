import Alpine from 'alpinejs'
import app from './js/app.js'
import './style.css'

Alpine.data('app', app)
window.Alpine = Alpine
Alpine.start()
