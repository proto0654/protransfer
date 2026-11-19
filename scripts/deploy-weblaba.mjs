#!/usr/bin/env node
/**
 * Deploy WordPress plugin + child theme + MU plugin to Weblaba staging.
 * Uses SSH key (~/.ssh/weblaba_deploy by default). Never uploads wp-config or uploads.
 */
import { spawnSync } from 'node:child_process'
import { existsSync } from 'node:fs'
import { homedir } from 'node:os'
import { join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = resolve(fileURLToPath(new URL('..', import.meta.url)))
const host = process.env.PT_SSH_HOST || 'server199.hosting.reg.ru'
const user = process.env.PT_SSH_USER || 'u1396397'
const key =
  process.env.PT_SSH_KEY ||
  join(homedir(), '.ssh', 'weblaba_deploy')
const remote = `${user}@${host}`
const sshBase = ['-i', key, '-o', 'IdentitiesOnly=yes', '-o', 'StrictHostKeyChecking=accept-new']

function run(cmd, args, opts = {}) {
  console.log('>', cmd, args.join(' '))
  const res = spawnSync(cmd, args, { stdio: 'inherit', shell: false, ...opts })
  if (res.status !== 0) {
    process.exit(res.status ?? 1)
  }
  return res
}

function sshCapture(remoteCmd) {
  const res = spawnSync(
    'ssh',
    [...sshBase, remote, `bash -lc ${JSON.stringify(remoteCmd)}`],
    { encoding: 'utf8' }
  )
  if (res.status !== 0) {
    process.stderr.write(res.stderr || '')
    process.exit(res.status ?? 1)
  }
  return (res.stdout || '').trim()
}

function ssh(remoteCmd) {
  run('ssh', [...sshBase, remote, `bash -lc ${JSON.stringify(remoteCmd)}`])
}

if (!existsSync(key)) {
  console.error(`SSH key not found: ${key}`)
  process.exit(1)
}

const localPlugin = join(root, 'wordpress/plugins/pt-core')
const localTheme = join(root, 'wordpress/themes/protransfer')
const localMu = join(root, 'wordpress/mu-plugins/pt-staging.php')

for (const p of [localPlugin, localTheme, localMu]) {
  if (!existsSync(p)) {
    console.error(`Missing ${p}`)
    process.exit(1)
  }
}

const docroot =
  process.env.PT_DOCROOT ||
  sshCapture('printf %s "$HOME/www/protransfer.weblaba.ru"')
const share = sshCapture('printf %s "$HOME/.local/share/protransfer"')

console.log('Docroot:', docroot)

console.log('Ensuring remote paths…')
ssh(
  `mkdir -p "${docroot}/wp-content/plugins" "${docroot}/wp-content/themes" "${docroot}/wp-content/mu-plugins"`
)

const scpBase = [...sshBase]

console.log('Uploading pt-core…')
run('scp', [...scpBase, '-r', localPlugin, `${remote}:${docroot}/wp-content/plugins/`])

console.log('Uploading child theme…')
run('scp', [...scpBase, '-r', localTheme, `${remote}:${docroot}/wp-content/themes/`])

console.log('Uploading MU plugin…')
run('scp', [...scpBase, localMu, `${remote}:${docroot}/wp-content/mu-plugins/pt-staging.php`])

const wp = `php "${share}/tools/wp-cli.phar" --path="${docroot}"`
const composer = `php "${share}/tools/composer.phar"`

console.log('Ensuring .htaccess rewrite…')
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
    `${wp} rewrite structure '/%postname%/' --hard || true`,
    `${wp} pt seed || true`,
  ].join(' && ')
)

console.log('Deploy OK → https://protransfer.weblaba.ru/')
