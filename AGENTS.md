# Agent guide — Organizr (fork)

This file is the repo's primary, tool-agnostic agent guide (read by Cursor,
Claude Code, Copilot, Codex, etc.). Read it first; it orients you to what the
repo is, the toolchain, and the **fork discipline** that keeps it rebase-friendly
against upstream.

**There are no nested `AGENTS.md` files** — the repo's packaging surface is
small, so this single root file is the whole guide. Add a nested file only if a
subtree (e.g. `api/v2/`) grows its own distinct conventions.

## What this is

**Organizr** is a self-hosted **homelab services dashboard** ("front page"): you
configure "Tabs" pointing at your services (Sonarr, Plex, etc.) and load them all
in one web UI, with users/groups, guest access, SSO/auth-proxy, and themes.

It is the well-known open-source PHP app **`causefx/Organizr`** (this repo is the
`v2-master` line). Stack:

- **Backend:** PHP (server-rendered pages + a JSON API). The image targets
  **PHP 8.2** (`Dockerfile`); upstream's stated floor is PHP 7.2+ (`README.md`).
- **API:** legacy endpoints under `api/` plus a **Slim 4** REST API under
  **`api/v2/`** (PSR-7, routes in `api/v2/routes/`). `api/index.php` forwards
  everything to `api/v2/`.
- **Frontend:** classic **jQuery + Bootstrap 3** assets (no SPA build for the app
  itself) — `js/`, `css/`, `less/`, and a large vendored `plugins/bower_components/`
  tree. Bootstrap itself lives pre-built under `bootstrap/` (Grunt project,
  rarely rebuilt).
- **Database:** **SQLite** (`pdo_sqlite`), plus `dibi` as the DB layer. The DB +
  config live in a writable `data/` directory (see "Config & data" below).
- **PHP deps:** Composer, declared in **`api/composer.json`** (Slim, dibi,
  lcobucci/jwt, phpmailer, google2fa, adldap2, swagger-php, stripe, symfony/yaml,
  …), installed into `api/vendor/`. `symfony/yaml` is what the fork's declarative
  config uses to parse `/config/config.yaml`.

### This is a FORK — fork discipline matters

This repo is `nantomarioni/Organizr` (remote `origin`), branch **`v2-master`**,
a fork of upstream `causefx/Organizr`. It is **not** a clean mirror — it carries
a stack of local feature commits on top of upstream v2 (`git log`):

- **Self-contained Docker support** — its own `Dockerfile` + `root/` runtime tree
  (this is the biggest local addition; see "How to run things" and "Ripple
  awareness").
- **Declarative setup** — `root/declarative-config.php` seeds admin user / settings
  / tabs from a `/config/config.yaml` on container start.
- **Auth-proxy groups** — group mapping for header/SSO auth-proxy login.
- **Jellystat integration + tab editing** — Jellystat homepage widget, plus
  `editing existing tabs` in the declarative config.

**Keep the upstream diff small and rebase-friendly.** Follow upstream conventions
(PHP style, the `api/` layout, the function/class organization). Concentrate
local-only runtime/packaging code in `root/` and the root `Dockerfile`; avoid
scattering bespoke logic across the upstream `api/` and `plugins/` trees unless
you're genuinely extending an upstream feature. Upstream PRs go to `v2-develop`
(`CONTRIBUTING.md`) — base would-be-upstreamable fixes there.

## How to run things

There is **no PHP package-manager-driven local dev setup committed** beyond
Composer for `api/`. Three realistic ways to run it:

### A. This fork's self-contained Docker image (the local addition)

The root `Dockerfile` bakes the app into a single image (PHP 8.2-fpm-alpine +
nginx + supervisor) and is what CI publishes:

```bash
# Build (multi-stage: composer vendor stage, then runtime)
docker build -t organizr-local .

# Run — persists SQLite DB + config under ./data on the host's /config
docker run -d --name organizr \
  -p 8080:80 \
  -e PUID=1000 -e PGID=1000 \
  -v "$PWD/config:/config" \
  organizr-local
```

- `root/entrypoint.sh` fixes `www-data` to `PUID/PGID`, symlinks
  `/config/data` → `/var/www/html/data`, runs `declarative-config.php`, then
  `exec`s `supervisord` (which runs `php-fpm` + `nginx`).
- nginx config: `root/etc/nginx/http.d/default.conf` (routes `/api/v2` →
  `api/v2/index.php`, everything else → `index.php`).
- supervisor: `root/etc/supervisord.conf`.
- CI: `.github/workflows/build.yml` builds on push to `v2-master` and pushes
  `ghcr.io/nantomarioni/organizr:latest` (+ a `sha-` tag).

### B. PHP built-in server (quick, no nginx)

Composer-install the API deps, then serve the repo root (Organizr expects to be
served from its own root with `index.php` as the front controller):

```bash
cd api && composer install        # populates api/vendor/
cd ..  && php -S localhost:8080    # uses index.php / api/v2/index.php
```

This works for poking at PHP, but routing nuances (the `/api/v2` rewrite) are
handled by nginx in the real deploy — verify API changes under the Docker image.

### C. The upstream container (`infra/docker-organizr`)

Upstream's model keeps the app **out** of the image and `git clone`s it at
runtime. See "Ripple awareness" — note that sibling currently clones
`causefx/Organizr`, **not** this fork.

### Asset "build"

There is **no committed app-level asset build** (no root `package.json` /
gulpfile / npm step for `js/` + `css/`). Those are edited/served directly.
`bootstrap/` is a vendored Bootstrap 3 Grunt project (`bootstrap/package.json`);
you almost never rebuild it. Don't invent an asset pipeline that isn't here.

## Quality gate

There is **no formal test suite or lint/CI gate for the PHP app** — the only CI
is `.github/workflows/build.yml` (Docker build) plus `lock.yml` / `stale.yml`
(issue bots). So the bar before declaring a change done is:

1. **It loads + runs without PHP errors** — exercise the page/endpoint you
   touched (built-in server or the Docker image). No new warnings/notices in the
   PHP-FPM logs.
2. **`composer install` still resolves** if you touched `api/composer.json`
   (commit the matching `api/composer.lock`).
3. **The Docker image still builds** (`docker build .`) if you touched anything
   in the build path — `Dockerfile`, `root/`, `api/composer.*`, or moved files
   the image copies.
4. **Runtime layout still matches the packaging** — if you changed paths, the
   PHP version, or entrypoint behavior, reconcile both `Dockerfile`/`root/` here
   AND the sibling `docker-organizr` (see Ripple awareness).
5. **Upstream diff stays minimal** — no drive-by reformatting of upstream files.

## Conventions

- **Front controller:** `index.php` (root) renders the dashboard; `api/index.php`
  forwards to the **v2 API** (`api/v2/`, Slim 4). Add new API endpoints as Slim
  routes under `api/v2/routes/`, not by bolting onto the legacy `api/` switch.
- **Backend layout (`api/`):** `functions.php` autoloads `vendor/`, then globs in
  everything under `functions/`, `homepage/`, `classes/`, `pages/`, and every
  `plugins/**/{plugin.php,*page.php,cron.php}`. The core class is
  `api/classes/organizr.class.php` (`Organizr`). Add domain logic as a
  function file under `api/functions/<area>-functions.php` or extend the existing
  class — match how the surrounding feature is organized.
- **Plugins / homepage widgets:** service integrations live under `plugins/` and
  `api/homepage/`. A plugin is auto-included if it ships a `plugin.php`,
  `*page.php`, or `cron.php`. **User-supplied** plugins/pages are loaded from the
  writable `data/plugins` and `data/pages` (not committed).
- **Auth / SSO:** auth + 2FA + auth-proxy logic is in `api/functions/`
  (`auth-functions.php`, `2fa-functions.php`, …). The auth-proxy **group mapping**
  is a local fork feature wired through the declarative config
  (`root/declarative-config.php`) — keep its setting keys
  (`authProxyHeaderNameGroup`, `authProxyGroupMapping`, …) in sync with the class.
- **Config & data:** Organizr is wizard-configured; runtime state is **SQLite +
  config under `data/`** — `data/config/config.php`, `databaseLocation.ini.php`,
  and the `*.db` files. **These are gitignored** (`.gitignore`: `data/*`,
  `users*.db`, `config/config.php`, `databaseLocation.ini.php`, …) — never commit
  a populated `data/` or a real DB. In the Docker image, `data/` is symlinked to
  the persisted `/config/data` (default DB `organizr.db`, see
  `declarative-config.php`).
- **Declarative config (fork):** `root/declarative-config.php` reads
  `/config/config.yaml` and idempotently seeds admin user, settings, groups,
  and tabs via the `Organizr` API methods. When you add a settable field, add
  its mapping there too (the `$settingsMap` table).
- **Frontend:** jQuery + Bootstrap 3 + the vendored `plugins/bower_components/`
  libs. Match the existing jQuery style; `*.css` is tagged as PHP in
  `.gitattributes` (linguist) — that's intentional, don't "fix" it.
- **Fork discipline:** follow upstream PHP conventions; keep edits to upstream
  files minimal; put fork-only packaging in `root/` + `Dockerfile`.

## Where things live

```
.
├── index.php                # dashboard front controller (renders the page)
├── cron.php                 # scheduled tasks entry
├── Dockerfile               # FORK: self-contained PHP 8.2-fpm + nginx + supervisor image
├── README.md                # upstream product overview (refers to upstream docker image)
├── CONTRIBUTING.md          # upstream contribution flow (PRs → v2-develop)
├── api/
│   ├── composer.json/.lock  # PHP deps → api/vendor/
│   ├── functions.php        # autoloader + glob-includes of the app
│   ├── index.php            # forwards to api/v2/ (Slim)
│   ├── classes/             # core classes (organizr.class.php, logger, ping, …)
│   ├── functions/           # the bulk of backend logic, *-functions.php files
│   ├── homepage/            # homepage/service widgets
│   ├── pages/               # server-rendered settings/admin pages
│   ├── plugins/             # bundled plugin code + committed plugin configs
│   ├── v2/                  # Slim 4 REST API (routes/ holds the endpoints)
│   ├── config/              # default config templates (real config is gitignored)
│   └── demo_data/           # demo fixtures
├── root/                    # FORK: container runtime tree
│   ├── entrypoint.sh        #   PUID/PGID, data symlink, declarative config, exec supervisord
│   ├── declarative-config.php  # seeds admin/settings/tabs from /config/config.yaml
│   └── etc/                 #   nginx (http.d/default.conf) + supervisord.conf
├── js/ css/ less/           # app frontend assets (edited directly, no build step)
├── plugins/                 # frontend plugin assets + vendored bower_components/
├── bootstrap/               # vendored Bootstrap 3 (Grunt project, rarely rebuilt)
├── docs/                    # api.json / swagger UI for the v2 API
├── scripts/                 # linux-update.sh / windows-update.bat (self-update helpers)
└── .github/workflows/       # build.yml (docker image), lock.yml, stale.yml
```

> Stray scratch files at the root (`test_debug.php`, `test_jellystat_api.html`,
> `server.log`, `poster_updates.js`, `debug_jellystat_metadata.php`) are
> local debugging leftovers, not part of the app — don't treat them as canonical.

## Making a change — walkthrough

- **Add/extend a JSON API endpoint** → add a Slim route under
  `api/v2/routes/`; back it with logic in `api/classes/organizr.class.php` or an
  `api/functions/<area>-functions.php` file. Verify via the Docker image (nginx
  does the `/api/v2` rewrite). Update `docs/api.json` if you maintain the spec.
- **Add a settings field** → wire it through the class + settings page under
  `api/pages/`, AND add it to the `$settingsMap` in
  `root/declarative-config.php` so declarative installs can set it.
- **Add a service widget/plugin** → follow an existing one under `plugins/` /
  `api/homepage/`; it auto-loads if it exposes `plugin.php`/`*page.php`/`cron.php`.
- **Frontend tweak** → edit `js/`, `css/`, `less/` directly (no build step).
- **Change runtime layout** (a path, the PHP version, an entrypoint behavior,
  nginx routing) → this **ripples to packaging**. Update the root `Dockerfile`
  and `root/` here, AND reconcile the sibling `docker-organizr` (next section).
  Bump nothing in upstream files you don't have to.

## Ripple awareness — packaging is paired

Organizr the app is **packaged by two different containers**; a runtime-layout
change can break either:

- **`infra/docker-organizr`** — upstream's packaging model: the app is **not**
  baked into the image; `root/etc/cont-init.d/40-install` `git clone`s Organizr
  into `/config/www/organizr` at container start and self-updates via git. It
  expects the front controller at the repo root, the v2 API at `api/v2/`
  (it patches nginx to add the `/api/v2` location), config at
  `data/config/config.php`, and branch wiring in `api/config/default.php`.
  > **Known divergence:** that script clones **`causefx/Organizr`** (upstream),
  > **not** this fork. So `docker-organizr` as-is does not ship this fork's
  > local features — the fork ships via its own `Dockerfile` (option A above).
  > If you intend `docker-organizr` to package this fork, change the clone URL
  > there. Either way, keep the path/PHP/entrypoint contract consistent.
- **This repo's own `Dockerfile` + `root/`** — the fork's self-contained image
  (`ghcr.io/nantomarioni/organizr`). If you change where `data/` lives, the PHP
  version, the nginx routing, or the entrypoint, update `Dockerfile`,
  `root/entrypoint.sh`, `root/etc/nginx/http.d/default.conf`, and
  `root/etc/supervisord.conf` together.

The cross-repo write-up for this pairing also lives in `infra/AGENTS.md`
("Organizr pairing").

## When stuck

- Upstream behavior / config syntax / feature docs → upstream project
  `https://github.com/causefx/Organizr` and the wiki `https://docs.organizr.app/`.
- v2 API shape → the Slim routes in `api/v2/routes/` and `docs/` (swagger).
- "Where does this setting/DB value live?" → `api/classes/organizr.class.php` +
  the gitignored `data/config/`; declarative seeding in
  `root/declarative-config.php`.
- Packaging / runtime layout → the root `Dockerfile` + `root/`, and the sibling
  `infra/docker-organizr` (plus `infra/AGENTS.md`).
- Contribution flow (if upstreaming) → `CONTRIBUTING.md` (PRs target `v2-develop`).
