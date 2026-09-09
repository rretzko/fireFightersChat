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

### Fix: set `COMPOSER_AUTH` as a build environment variable

Composer falls back to the `COMPOSER_AUTH` environment variable (a JSON string
in the same shape as `auth.json`) when no `auth.json` file exists. Add it in
**cloud.laravel.com → your app → Settings → Environment → Custom Environment
Variables**, available at build time (not just runtime, since `composer
install` runs during the build step):

- Key: `COMPOSER_AUTH`
- Value (single-line JSON):
  ```json
  {"http-basic":{"composer.fluxui.dev":{"username":"YOUR_FLUX_EMAIL","password":"YOUR_FLUX_LICENSE_KEY"}}}
  ```

Use the same Flux account email and license key as the local `auth.json`
(from https://fluxui.dev/settings). Once set, re-run the deploy.
