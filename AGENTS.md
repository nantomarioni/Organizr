# Agent guide — Organizr (fork)

Primary agent guide for this repo. **No nested `AGENTS.md` files** and no
`docs/` knowledge files of our own (`docs/` is the upstream swagger UI); add a
nested guide only if a subtree (e.g. `api/v2/`) grows distinct conventions.

## What this is

**Organizr** is a self-hosted **homelab services dashboard**: "Tabs" pointing at
your services, loaded in one web UI, with users/groups, guest access,
SSO/auth-proxy and themes. This is `nantomarioni/Organizr`, branch
**`v2-master`**, a **fork** of `causefx/Organizr` v2 carrying local commits:

- **Self-contained Docker image** — root `Dockerfile` + `root/` runtime tree
  (PHP 8.2-fpm-alpine + nginx + supervisor); the biggest local addition.
- **Declarative setup** — `root/declarative-config.php` seeds admin user,
  settings, groups and tabs from `/config/config.yaml` on container start.
- **Auth-proxy group mapping** for header/SSO login; **Jellystat** widget;
  editing existing tabs declaratively.

Stack: PHP (server-rendered pages + JSON API); legacy `api/` plus a **Slim 4**
REST API under `api/v2/` (`api/index.php` forwards there); jQuery + Bootstrap 3
frontend (`js/`, `css/`, `less/`, vendored `plugins/bower_components/`,
pre-built `bootstrap/`); **SQLite** via `dibi`; Composer deps in
`api/composer.json` → `api/vendor/` (`symfony/yaml` parses the declarative
config).

**Fork discipline:** keep the upstream diff small and rebase-friendly. Follow
upstream PHP conventions and the `api/` layout; concentrate fork-only runtime /
packaging code in `root/` + `Dockerfile`; no drive-by reformatting of upstream
files. Upstreamable fixes base on `v2-develop` (`CONTRIBUTING.md`).

> `README.md` is upstream's and refers to upstream's docker image, not this fork.

## How to run things

```bash
# A. The fork's image (what CI publishes and what deploys)
docker build -t organizr-local .
docker run -d --name organizr -p 8080:80 -e PUID=1000 -e PGID=1000 -v "$PWD/config:/config" organizr-local

# B. Quick PHP poke (no nginx → the /api/v2 rewrite is NOT exercised; verify API changes under A)
cd api && composer install && cd .. && php -S localhost:8080
```

- `root/entrypoint.sh`: chowns `www-data` to `PUID/PGID`, symlinks
  `/config/data` → `/var/www/html/data`, runs `declarative-config.php`, `exec`s
  `supervisord` (php-fpm + nginx). nginx: `root/etc/nginx/http.d/default.conf`
  (`/api/v2` → `api/v2/index.php`, else `index.php`); `root/etc/supervisord.conf`.
- CI `.github/workflows/build.yml`: push to `v2-master` → `ghcr.io/nantomarioni/organizr`
  tagged `latest` + `sha-<short>`. **No automatic deploy bump** — the tag in
  `../homelab-manifests/apps/organizr/values.yaml` is edited by hand.
- **No app-level asset build** (no root `package.json`/gulp): `js/`/`css/`/`less/`
  are edited and served directly. `bootstrap/` is a vendored Grunt project you
  almost never rebuild. Don't invent a pipeline.

## Quality gate

No tests, no lint, no CI gate beyond the Docker build (`build.yml`; `lock.yml`
/ `stale.yml` are issue bots). The realistic bar:

1. The page/endpoint you touched loads and runs with no new PHP
   warnings/notices (built-in server or the image).
2. `composer install` still resolves if you touched `api/composer.json`
   (commit `api/composer.lock`).
3. `docker build .` still succeeds if you touched `Dockerfile`, `root/`,
   `api/composer.*` or moved files the image copies.
4. Runtime layout (paths, PHP version, entrypoint, nginx routing) still matches
   both packagings — see Ripple awareness.
5. Upstream diff stays minimal.

## Conventions

- **Front controller** `index.php`; new API endpoints are Slim routes under
  `api/v2/routes/`, never bolted onto the legacy `api/` switch.
- **Backend layout (`api/`)**: `functions.php` autoloads `vendor/` then globs
  `functions/`, `homepage/`, `classes/`, `pages/` and every
  `plugins/**/{plugin.php,*page.php,cron.php}`. Core class
  `api/classes/organizr.class.php` (`Organizr`). Domain logic goes in
  `api/functions/<area>-functions.php` or extends the class — match the
  surrounding feature.
- **Plugins / homepage widgets** under `plugins/` + `api/homepage/`, auto-included
  by the glob above; user-supplied ones load from writable `data/plugins`,
  `data/pages` (not committed).
- **Auth / SSO** in `api/functions/` (`auth-functions.php`, `2fa-functions.php`).
  The auth-proxy group mapping is fork code wired through the declarative
  config — keep its setting keys (`authProxyHeaderNameGroup`,
  `authProxyGroupMapping`, …) in sync between the class and `$settingsMap`.
- **Config & data**: SQLite + config live in `data/` (`data/config/config.php`,
  `databaseLocation.ini.php`, `*.db`) — **gitignored; never commit a populated
  `data/` or a real DB**. In the image `data/` is the persisted `/config/data`.
- **Declarative config (fork)**: `root/declarative-config.php` idempotently
  seeds from `/config/config.yaml` via `Organizr` API methods. A new settable
  field needs a `$settingsMap` entry.
- **Frontend**: match the existing jQuery style. `*.css` is tagged as PHP in
  `.gitattributes` (linguist) — intentional, don't "fix".

## Where things live

```
index.php cron.php        # dashboard front controller; scheduled tasks
Dockerfile                # FORK: PHP 8.2-fpm + nginx + supervisor image
api/                      # composer.json/.lock, functions.php (autoload+glob), index.php (→ v2),
│                         #   classes/ functions/ homepage/ pages/ plugins/ v2/ (Slim routes/) config/ demo_data/
root/                     # FORK: entrypoint.sh, declarative-config.php, etc/ (nginx http.d, supervisord.conf)
js/ css/ less/ plugins/   # frontend assets (no build step); plugins/bower_components/ vendored
bootstrap/                # vendored Bootstrap 3 Grunt project
docs/                     # api.json / swagger UI for the v2 API
scripts/                  # linux-update.sh / windows-update.bat self-update helpers
.github/workflows/        # build.yml (image), lock.yml, stale.yml
```

## Making a change — walkthrough

- **API endpoint** → Slim route in `api/v2/routes/` + logic in the class or an
  `api/functions/<area>-functions.php`; verify under the image; update
  `docs/api.json` if you maintain the spec.
- **Settings field** → class + settings page under `api/pages/` **and**
  `$settingsMap` in `root/declarative-config.php`.
- **Widget/plugin** → copy an existing one under `plugins/` / `api/homepage/`.
- **Frontend tweak** → edit `js/`/`css/`/`less/` directly.
- **Runtime layout change** (path, PHP version, entrypoint, nginx routing) →
  update `Dockerfile` + `root/` here **and** reconcile `../docker-organizr`.

## Ripple awareness

Open the sibling before declaring done. Siblings are `../<repo>` checkouts
(`github.com/nantomarioni/<repo>`).

- **`docker-organizr`** — upstream's packaging model: the app is **not** baked
  in; `root/etc/cont-init.d/40-install` `git clone`s Organizr into
  `/config/www/organizr` at start and self-updates via git. It expects the
  front controller at the repo root, the v2 API at `api/v2/` (it patches nginx
  for the `/api/v2` location), config at `data/config/config.php`, branch wiring
  in `api/config/default.php`.
  > **Known divergence:** it clones **`causefx/Organizr`** (upstream), not this
  > fork, so it does not ship the fork features; the fork ships via its own
  > `Dockerfile`. Keep the path/PHP/entrypoint contract consistent regardless.
- **`homelab-manifests`** (`apps/organizr/`) — deploys the fork image
  (`image.repository: ghcr.io/nantomarioni/organizr`, hand-bumped `sha-` tag);
  its `configmap.yaml` renders the **`/config/config.yaml` consumed by
  `root/declarative-config.php`** (keys `admin`, `settings.organizrHash`,
  `settings.authProxy.{enabled,headerName,headerNameEmail,headerNameGroups,groupMapping,whitelist,overrideLogout,logoutURL,register}`,
  `groups`, `tabs`), mounts the icons Secret at `/config/icons` (symlinked to
  `data/userTabs` by an init container) and persists `/config/data`. Renaming a
  `$settingsMap` key or the `/config` paths breaks that chart. Auth headers
  come from its `authentik-auth` Middleware (`X-Authentik-*`).
- **`homelab`** — `terraform/3-core-config/authentik-organizr.tf` is the
  Authentik proxy provider that emits those headers; `groupMapping` assumes
  Authentik group names.

## When stuck

- Upstream behaviour / config / features → <https://github.com/causefx/Organizr>,
  <https://docs.organizr.app/>.
- v2 API shape → `api/v2/routes/` + `docs/` swagger.
- "Where does this setting live?" → `api/classes/organizr.class.php`, the
  gitignored `data/config/`, seeding in `root/declarative-config.php`.
- Packaging / runtime layout → `Dockerfile` + `root/`, `../docker-organizr`,
  `../homelab-manifests/apps/organizr`.
- Upstreaming → `CONTRIBUTING.md` (PRs target `v2-develop`).
