# Fire Fighters Chat

## Deployment (Laravel Cloud)

This project depends on `livewire/flux-pro`, a paid private package served from
`composer.fluxui.dev`. Composer authenticates against that host using the
`http-basic` credentials in `auth.json`, which is intentionally git-ignored
(it contains the Flux license key) and therefore is **not** present when
Laravel Cloud clones the repo for a build.

Without those credentials, the build fails during `composer install` with:

```
The 'https://composer.fluxui.dev/...' URL required authentication (HTTP 401).
```

### Fix: add a `composer config` build command

Laravel Cloud's build container does **not** apply custom environment
variables (e.g. `COMPOSER_AUTH`) before `composer install` runs, so setting
`COMPOSER_AUTH` in Settings → Environment has no effect on this failure.

Instead, add an explicit `composer config` command in **cloud.laravel.com →
your app → Settings → Deployments → Build Commands**, placed *before* the
existing `composer install` command:

```
composer config http-basic.composer.fluxui.dev YOUR_FLUX_EMAIL YOUR_FLUX_LICENSE_KEY
```

Use the same Flux account email and license key as the local `auth.json`
(from https://fluxui.dev/settings). Once added, re-run the deploy.

### Database: MySQL required, not SQLite

Locally this app defaults to SQLite (`DB_CONNECTION=sqlite`,
`database/database.sqlite`), but that file is git-ignored
(`database/.gitignore`) and Laravel Cloud application containers use an
**ephemeral filesystem** — each replica has its own disk, and it resets on
every deploy/reboot. SQLite is officially unsupported on Laravel Cloud for
this reason (see [SQLite Support - Laravel
Cloud](https://cloud.laravel.com/docs/knowledge-base/sqlite)); attempting to
use it in production surfaces as:

```
Database file at path [/var/www/html/database/database.sqlite] does not exist.
```

**Fix:** add a MySQL database resource to the environment via **cloud.laravel.com
→ your app → Add Resources → Database (MySQL)**. Laravel Cloud injects the
`DB_CONNECTION`, `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD`
environment variables for the attached database automatically — no code
changes are needed since `config/database.php` already reads `DB_CONNECTION`
from the environment. After attaching the database, run migrations against it
(either via a configured deployment migration command in Settings →
Deployments, or manually with `php artisan migrate --force` through the Cloud
console/CLI).

### Broadcasts stuck on "Sending" with no logs

`SendBroadcast` fans out one queued job (`SendBroadcastMessage`) per recipient
via `Bus::batch()`. The app's default queue connection is `database`
(`QUEUE_CONNECTION=database`, see `.env.example`), which pushes jobs onto the
`jobs` table — but Laravel Cloud does not run a queue worker by default. With
nothing consuming that table, jobs sit forever: the broadcast status hangs at
`sending`, and the log stream never shows the `Mock SMS sent` entries from
`LogSmsGateway::send()`, because the job that would log them never executes.

**Fix (used for this demo):** set `QUEUE_CONNECTION=sync` as an environment
variable in **cloud.laravel.com → your app → Settings → Environment**, then
redeploy. With the sync driver, `Bus::batch()->dispatch()` runs the batch
(including its recipients and the `finally()` callback that marks the
broadcast `sent`) inline within the request — no separate worker needed. This
is fine for a POC with the mock SMS gateway and small recipient counts;
`app/Actions/SendBroadcast.php` already accounts for this (see its comments on
why status is set to `Sending` *before* dispatch).

For a production deployment with real send volume, prefer a proper queue
worker instead of `sync`: either a `queue:work` background process on the App
cluster, or a Managed Queue (Settings → Add Compute → Managed Queue), which
also sets `QUEUE_CONNECTION=cloud` automatically. See [Queues - Laravel
Cloud](https://laravel.com/cloud/docs/queues).
