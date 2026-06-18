# Launchmind Blog for Craft CMS

Publish your [Launchmind](https://launchmind.io) AI-generated SEO &amp; GEO blog
on your Craft CMS 5 site. Articles are pulled live from the Launchmind API and
cached — nothing is written to your Craft database, so your content always
reflects the latest from Launchmind.

## Requirements

- Craft CMS 5.0.0 or later
- PHP 8.2 or later
- A Launchmind account and API key

## Installation

From the Plugin Store: search for **Launchmind Blog** and click **Install**.

Or with Composer:

```bash
composer require launchmind/craft-launchmind-blog
php craft plugin/install launchmind-blog
```

## Setup

1. Go to **Settings → Plugins → Launchmind Blog**.
2. Paste your **API key** (find it in your Launchmind dashboard under
   *Settings → Connectors*). You can also reference an environment variable,
   e.g. `$LAUNCHMIND_API_KEY`.
3. Set the **Blog path** (default `blog`).
4. Click **Test connection**, then **Save**.

Your blog is now live at `/blog` (list) and `/blog/<slug>` (articles).

> Tip: use the API key `lm_test_demo1234567890` to preview the plugin with
> sample content before connecting a real account.

## Two ways to render

**Bundled templates (zero config).** The plugin ships starter templates and
registers the routes for you. Override them by copying any file from
`vendor/launchmind/craft-launchmind-blog/src/templates/launchmind-blog/` into
`templates/launchmind-blog/` in your own project.

**Your own templates (full control).** Pull the data wherever you like:

```twig
{% set posts = craft.launchmind.posts({ limit: 6 }) %}
{% for post in posts %}
  <a href="{{ url(craft.launchmind.blogPath ~ '/' ~ post.slug) }}">
    <h2>{{ post.title }}</h2>
    <p>{{ post.excerpt }}</p>
  </a>
{% endfor %}

{# A single article #}
{% set post = craft.launchmind.post('my-article-slug') %}
{{ post.html|raw }}
```

Each post exposes: `id`, `slug`, `title`, `excerpt`, `html`, `coverImage`,
`tags`, `author`, `publishedAt`, `language`, `availableLanguages`, `wordCount`,
`isVoiceArticle`, and `seo.title` / `seo.description`.

## Configuration file (optional)

Create `config/launchmind-blog.php` to manage settings in code / per environment:

```php
<?php
return [
    'apiKey' => '$LAUNCHMIND_API_KEY',
    'blogPath' => 'blog',
    'cacheTtl' => 600,
    'enableTracking' => true,
];
```

## Caching &amp; reliability

Responses are cached with stale-while-revalidate: if the Launchmind API is ever
briefly unreachable, your blog keeps serving the last-known-good content for up
to 24 hours. Conditional requests (ETag / `If-None-Match`) keep bandwidth low.
Clear the cache any time from the settings page.

## SEO

The bundled single-article template emits a canonical tag, Open Graph tags,
`hreflang` alternates for translated articles, and `BlogPosting` JSON-LD. If you
use SEOmatic, render the article inside your own layout and let SEOmatic own the
meta tags.

## Support

hello@launchmind.io
