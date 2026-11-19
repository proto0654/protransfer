/**
 * Loads calculator/content data.
 * Dev: Vite proxies /wp-json → https://protransfer.weblaba.ru (see vite.config.js).
 * Falls back to bundled JSON if the API is unreachable.
 */
import siteJson from '../data/site.json'
import pointsJson from '../data/points.json'
import tariffsJson from '../data/tariffs.json'
import routesJson from '../data/routes.json'
import reviewsJson from '../data/reviews.json'
import faqJson from '../data/faq.json'

import { normalizeSite } from './content.js'

const API_BOOTSTRAP = '/wp-json/pt/v1/bootstrap'

function normalizeTariffs(list) {
  return (list || []).map((t) => ({
    ...t,
    perKm: t.perKm ?? t.per_km,
    minPrice: t.minPrice ?? t.min_price,
    onRequest: t.onRequest ?? t.on_request,
  }))
}

export async function loadSiteData() {
  const fallback = {
    site: normalizeSite(siteJson),
    points: pointsJson,
    tariffs: tariffsJson,
    routes: routesJson,
    reviews: reviewsJson,
    faq: faqJson,
    source: 'json',
  }

  if (import.meta.env.VITE_DATA_SOURCE === 'json') {
    return fallback
  }

  try {
    const embedded = document.getElementById('pt-bootstrap')
    let data
    if (embedded) {
      data = JSON.parse(embedded.textContent)
    } else {
      const res = await fetch(API_BOOTSTRAP, { headers: { Accept: 'application/json' }, signal: AbortSignal.timeout(8000) })
      if (!res.ok) throw new Error(`HTTP ${res.status}`)
      data = await res.json()
    }
    if (!data?.points?.length || !data?.tariffs?.length) {
      throw new Error('empty bootstrap')
    }
    return {
      site: normalizeSite(data.site || siteJson),
      points: data.points,
      tariffs: normalizeTariffs(data.tariffs),
      routes: data.routes || routesJson,
      reviews: Array.isArray(data.reviews) ? data.reviews : reviewsJson,
      faq: Array.isArray(data.faq) ? data.faq : faqJson,
      calc: data.calc,
      source: 'wp',
    }
  } catch (err) {
    console.warn('[protransfer] WP bootstrap unavailable, using local JSON', err)
    return fallback
  }
}
