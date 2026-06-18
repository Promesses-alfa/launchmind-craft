# Release Notes for Launchmind Blog

## 1.0.0

- Initial release.
- Pulls Launchmind articles live via the customer blog API, cached with
  stale-while-revalidate and ETag conditional requests.
- Bundled, overridable front-end templates for the article list and single
  article, with canonical, Open Graph, hreflang and `BlogPosting` JSON-LD.
- `craft.launchmind.posts()` / `craft.launchmind.post()` template variables for
  fully custom rendering.
- Settings page with test-connection and clear-cache actions; environment
  variable support for the API key and base URL.
- Server-side, bot-filtered page-view analytics beacon (toggleable).
