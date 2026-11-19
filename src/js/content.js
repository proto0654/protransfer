import schema from '../../wordpress/plugins/pt-core/data/content.json' with { type: 'json' }

export function normalizeSite(site) {
  const defaults = Object.fromEntries(Object.entries(schema).map(([key, field]) => [key, field.default]))
  return { ...site, copy: { ...defaults, ...(site.copy || {}) } }
}
