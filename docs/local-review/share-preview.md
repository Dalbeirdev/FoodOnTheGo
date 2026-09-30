# Shareable dashboard preview (static build)

The Restaurant Dashboard is a frontend-only preview with development fixtures (no backend). A static
snapshot can be published as a private Claude Artifact page for review:

```bash
cd customer-web
VITE_ROUTER=hash VITE_SHARE_BUILD=1 npx vite build --base ./ --outDir dist-share --emptyOutDir
```

- `VITE_ROUTER=hash` switches the app to hash routing (`#/restaurant-dashboard/overview`) so every route survives a reload on a single-file host.
- `VITE_SHARE_BUILD=1` rewrites absolute `/brand/…` and `/images/…` references to relative paths.
- The bundle is wrapped into `dist-share/page.html` (title, fonts, stylesheet, root, hash bootstrap, module script) and published with `assets/`, `brand/`, `images/`, `favicon.svg`, `icons.svg`.
- Current preview: https://claude.ai/artifact/YUJz1HeHohVmuY62aUjVsY (private; share from the page's Share menu). Data lives only in the viewer's browser.

## Platform Admin preview (Module 18)

Same build with `--outDir dist-share-admin`, wrapped as `dist-share-admin/admin.html` with the hash bootstrap `#/admin/overview`.

- Current preview: https://claude.ai/artifact/XQyLJ52ZW1KQu1qWWnFzyy (private; share from the page's Share menu). Data lives only in the viewer's browser.

## Fixture scopes (Module 18A)

The build runs the India launch scope. To load the controlled global test fixtures in a browser (other currencies,
time zones, unit systems), set `localStorage.setItem('fotg.fixtures', 'global')` and reload; remove the key to return to
India. The Module 17 / 18 e2e suites set this flag; the Module 18A suite runs without it.
