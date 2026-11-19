#!/usr/bin/env node
/**
 * Deploy to protransfer.weblaba.ru staging:
 * 1) Vite landing → child theme /landing (preview.html as homepage)
 * 2) pt-core plugin + child theme + MU plugin
 *
 * SSH key: ~/.ssh/weblaba_deploy (or PT_SSH_KEY). Never uploads wp-config/uploads.
 */
import { spawnSync } from 'node:child_process'
import { cpSync, existsSync, mkdirSync, rmSync, writeFileSync } from 'node:fs'
import { homedir } from 'node:os'
import { join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = resolve(fileURLToPath(new URL('..', import.meta.url)))
const host = process.env.PT_SSH_HOST || 'server199.hosting.reg.ru'
const user = process.env.PT_SSH_USER || 'u1396397'
const key = process.env.PT_SSH_KEY || join(homedir(), '.ssh', 'weblaba_deploy')
const remote = `${user}@${host}`
const sshBase = ['-i', key, '-o', 'IdentitiesOnly=yes', '-o', 'StrictHostKeyChecking=accept-new']

function run(cmd, args, opts = {}) {
  console.log('>', cmd, args.join(' '))
  const res = spawnSync(cmd, args, { stdio: 'inherit', shell: false, ...opts })
  if (res.status !== 0) process.exit(res.status ?? 1)
  return res
}

function shellQuote(value) {
  return "'" + String(value).replaceAll("'", "'\\''") + "'"
}

function sshCapture(remoteCmd) {
  const res = spawnSync('ssh', [...sshBase, remote, 'bash -s'], {
    input: `set -euo pipefail\n${remoteCmd}\n`, encoding: 'utf8',
  })
  if (res.status !== 0) {
    process.stderr.write(res.stderr || '')
    process.exit(res.status ?? 1)
  }
  return (res.stdout || '').trim()
}

function ssh(remoteCmd) {
  run('ssh', [...sshBase, remote, 'bash -s'], {
    input: `set -euo pipefail\n${remoteCmd}\n`, stdio: ['pipe', 'inherit', 'inherit'],
  })
}

if (!existsSync(key)) {
  console.error(`SSH key not found: ${key}`)
  process.exit(1)
}

const dist = join(root, 'dist')
const landing = join(root, 'wordpress/themes/protransfer/landing')
const localPlugin = join(root, 'wordpress/plugins/pt-core')
const localTheme = join(root, 'wordpress/themes/protransfer')
const localMu = join(root, 'wordpress/mu-plugins/pt-staging.php')
const favicon = join(root, 'public/favicon.svg')

if (!existsSync(join(dist, 'preview.html'))) {
  console.error('Missing dist/preview.html — run npm run build:weblaba first (or npm run deploy:weblaba).')
  process.exit(1)
}

console.log('Preparing theme landing from dist…')
rmSync(landing, { recursive: true, force: true })
mkdirSync(landing, { recursive: true })
cpSync(dist, landing, { recursive: true })
writeFileSync(
  join(landing, '.htaccess'),
  'Options -Indexes\n<IfModule mod_headers.c>\nHeader set Cache-Control "public, max-age=3600"\n</IfModule>\n'
)

// Fleet photos for WP Media seed (featured image on pt_tariff)
const fleetSrc = join(root, 'public/fleet')
const fleetDst = join(root, 'wordpress/plugins/pt-core/data/fleet')
if (existsSync(fleetSrc)) {
  rmSync(fleetDst, { recursive: true, force: true })
  mkdirSync(fleetDst, { recursive: true })
  cpSync(fleetSrc, fleetDst, { recursive: true })
  console.log('Copied fleet photos into pt-core/data/fleet')
}

const docroot =
  process.env.PT_DOCROOT || sshCapture('printf %s "$HOME/www/protransfer.weblaba.ru"')
const share = sshCapture('printf %s "$HOME/.local/share/protransfer"')
console.log('Docroot:', docroot)
const expectedDocroot = sshCapture('printf %s "$HOME/www/protransfer.weblaba.ru"')
if (docroot !== expectedDocroot) {
  throw new Error('Deployment target must be the documented Protransfer staging docroot')
}
const commit = run('git', ['rev-parse', 'HEAD'], { encoding: 'utf8', stdio: 'pipe' }).stdout.trim()
const releaseId = new Date().toISOString().replace(/[^0-9]/g, '').slice(0, 14) + '-' + commit.slice(0, 7)
const backup = `${share}/backups/${releaseId}`
const wp = `php ${shellQuote(share + '/tools/wp-cli.phar')} --path=${shellQuote(docroot)}`
const composer = `php ${shellQuote(share + '/tools/composer.phar')}`
console.log('Creating verified rollback backup:', backup)
ssh(`umask 077
mkdir -p ${shellQuote(backup)}
${wp} core is-installed
${wp} db export ${shellQuote(backup + '/database.sql')} --single-transaction --skip-lock-tables --no-tablespaces --column-statistics=0
 gzip ${shellQuote(backup + '/database.sql')}
gzip -t ${shellQuote(backup + '/database.sql.gz')}
cd ${shellQuote(docroot)}
paths=(wp-content wp-config.php)
if [ -f .htaccess ]; then paths+=(.htaccess); fi
tar -czf ${shellQuote(backup + '/site.tgz')} "\${paths[@]}"
tar -tzf ${shellQuote(backup + '/site.tgz')} > /dev/null
printf '%s\n' ${shellQuote(commit)} > ${shellQuote(backup + '/target-commit')}`)


ssh(
  `mkdir -p "${docroot}/wp-content/plugins" "${docroot}/wp-content/themes" "${docroot}/wp-content/mu-plugins"`
)

const scpBase = [...sshBase]

console.log('Uploading pt-core…')
run('scp', [...scpBase, '-r', localPlugin, `${remote}:${docroot}/wp-content/plugins/`])

console.log('Uploading child theme (+ landing)…')
run('scp', [...scpBase, '-r', localTheme, `${remote}:${docroot}/wp-content/themes/`])

console.log('Uploading MU plugin…')
run('scp', [...scpBase, localMu, `${remote}:${docroot}/wp-content/mu-plugins/pt-staging.php`])

if (existsSync(favicon)) {
  console.log('Uploading favicon…')
  run('scp', [...scpBase, favicon, `${remote}:${docroot}/favicon.svg`])
}


ssh(
  `test -f "${docroot}/.htaccess" || cat > "${docroot}/.htaccess" <<'EOF'
Options -Indexes
DirectoryIndex index.php
<IfModule mod_headers.c>
Header always set X-Robots-Tag "noindex, nofollow, noarchive"
Header always set Cache-Control "private, no-store, no-cache, must-revalidate"
</IfModule>
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /
RewriteRule ^index\\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
EOF`
)

console.log('Composer install + activate…')
ssh(
  [
    `cd "${docroot}/wp-content/plugins/pt-core"`,
    `${composer} install --no-dev --prefer-dist --no-interaction`,
    `${wp} plugin activate pt-core`,
    `${wp} theme activate protransfer`,
    `${wp} core is-installed`,
    `printf '%s\\n' ${shellQuote(commit)} > ${shellQuote(share + '/deployed-commit')}`,
  ].join(' && ')
)

console.log('Deploy OK → https://protransfer.weblaba.ru/', commit, 'Rollback:', backup)
