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
