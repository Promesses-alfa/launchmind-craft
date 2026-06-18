<?php

namespace launchmind\blog\services;

use Craft;
use GuzzleHttp\Client;
use launchmind\blog\Plugin;
use Throwable;
use yii\base\Component;

/**
 * Server-side client for the Launchmind customer blog API.
 *
 * All requests are server-side only; the API key is never exposed to the
 * browser. Reads go through the Cache service (stale-while-revalidate + ETag)
 * so a degraded API can't break or slow the customer's site.
 *
 * Endpoints (see apps/web/app/api/blog/customer/route.ts):
 *   GET  /blog/customer?api_key&limit&offset&lang        → { posts, total, subscription }
 *   GET  /blog/customer?api_key&slug&lang                → { post, subscription }
 *   POST /tracking/plugin-view                           → page-view beacon
 */
class Api extends Component
{
    private const SUPPORTED_LANGS = ['en', 'nl', 'de', 'fr', 'es', 'it', 'hi', 'pl'];

    /**
     * Fetch a page of posts. Returns an array of normalized post arrays
     * (possibly empty). Never throws — on failure it serves stale cache or [].
     */
    public function getPosts(array $args = []): array
    {
        $params = array_merge([
            'limit' => 12,
            'offset' => 0,
            'lang' => $this->resolveLang(),
        ], $args);

        $cache = Plugin::getInstance()->cache;
        $cacheKey = $cache->key('posts', $params);
        $entry = $cache->getWithFreshness($cacheKey);
        if ($entry['state'] === 'fresh') {
            return $entry['value'];
        }

        $response = $this->request('/blog/customer', $params, $cacheKey);

        if ($response === 'not_modified' || $response === null) {
            if ($entry['state'] !== 'miss') {
                // Re-confirm the cached value as fresh and keep serving it.
                $cache->setWithStale($cacheKey, $entry['value'], $this->freshTtl());
                return $entry['value'];
            }
            return [];
        }

        $rawPosts = $response['posts'] ?? (array_is_list($response) ? $response : []);
        $posts = array_map([$this, 'normalizePost'], $rawPosts);

        $this->rememberSubscriptionId($response['subscription']['id'] ?? null);
        $cache->setWithStale($cacheKey, $posts, $this->freshTtl());

        return $posts;
    }

    /**
     * Fetch a single post by slug. Returns the normalized post array, or null
     * when not found / unconfigured.
     */
    public function getPost(string $slug, ?string $lang = null): ?array
    {
        if ($slug === '') {
            return null;
        }
        $lang = $lang ?: $this->resolveLang();

        $cache = Plugin::getInstance()->cache;
        $cacheKey = $cache->key('post_' . $slug, ['lang' => $lang]);
        $entry = $cache->getWithFreshness($cacheKey);
        if ($entry['state'] === 'fresh') {
            return $entry['value'];
        }

        $response = $this->request('/blog/customer', ['slug' => $slug, 'lang' => $lang], $cacheKey);

        if ($response === 'not_modified' || $response === null) {
            if ($entry['state'] !== 'miss') {
                $cache->setWithStale($cacheKey, $entry['value'], $this->freshTtl());
                return $entry['value'];
            }
            return null;
        }

        $rawPost = $response['post'] ?? $response;
        if (!is_array($rawPost) || empty($rawPost['slug'])) {
            return null;
        }
        $post = $this->normalizePost($rawPost);

        $this->rememberSubscriptionId($response['subscription']['id'] ?? null);
        $cache->setWithStale($cacheKey, $post, $this->freshTtl());

        return $post;
    }

    /**
     * Validate the configured credentials. Used by the settings page test
     * button. Returns [bool success, string message].
     *
     * @return array{0:bool,1:string}
     */
    public function testConnection(): array
    {
        $response = $this->request('/blog/customer', ['limit' => 1]);
        if ($response === null) {
            return [false, Craft::t('launchmind-blog', 'Could not reach the Launchmind API. Check the API key and base URL.')];
        }
        return [true, Craft::t('launchmind-blog', 'Connection successful.')];
    }

    /**
     * Fire the server-side page-view beacon. Best-effort and non-blocking — a
     * failed beacon never affects the visitor's page render. Crawler traffic
     * is filtered out so server-side counts stay clean.
     */
    public function trackView(string $slug): void
    {
        $settings = Plugin::getInstance()->getSettings();
        if (!$settings->enableTracking || $slug === '') {
            return;
        }

        $subscriptionId = $this->resolveSubscriptionId();
        if ($subscriptionId === '') {
            return;
        }

        $request = Craft::$app->getRequest();
        $ua = $request->getUserAgent() ?? '';
        if ($this->isBot($ua)) {
            return;
        }

        $body = [
            'subscription_id' => $subscriptionId,
            'article_slug' => $slug,
            'platform' => Plugin::PLATFORM,
            'event_type' => 'page_view',
            'source' => 'server',
            'referrer' => $request->getReferrer() ?? '',
            'plugin_version' => Plugin::getInstance()->getVersion(),
        ];

        try {
            $this->client()->post($settings->getApiBase() . '/tracking/plugin-view', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'X-Launchmind-Source' => 'server',
                ],
                'json' => $body,
                'timeout' => 3,
                'http_errors' => false,
            ]);
        } catch (Throwable) {
            // Beacons are fire-and-forget.
        }
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /**
     * Perform a GET against the API with ETag conditional support.
     *
     * @return array|string|null Decoded body on 200, the string 'not_modified'
     *                           on 304, or null on any failure.
     */
    private function request(string $endpoint, array $params, string $etagKey = ''): array|string|null
    {
        $settings = Plugin::getInstance()->getSettings();
        $apiKey = $settings->getApiKey();
        if ($apiKey === '') {
            return null;
        }

        // Send the key as query param too — some hosts strip Authorization.
        $params['api_key'] = $apiKey;

        $headers = [
            'Authorization' => 'Bearer ' . $apiKey,
            'Accept' => 'application/json',
            'X-Launchmind-Platform' => Plugin::PLATFORM,
            'X-Launchmind-Version' => Plugin::getInstance()->getVersion(),
        ];

        $cache = Plugin::getInstance()->cache;
        if ($etagKey !== '' && ($etag = $cache->getEtag($etagKey)) !== null) {
            $headers['If-None-Match'] = $etag;
        }

        try {
            $response = $this->client()->get($settings->getApiBase() . $endpoint, [
                'query' => $params,
                'headers' => $headers,
                'timeout' => 8,
                'http_errors' => false,
            ]);
        } catch (Throwable $e) {
            Craft::warning('Launchmind API request failed: ' . $e->getMessage(), 'launchmind-blog');
            return null;
        }

        $status = $response->getStatusCode();

        if ($status === 304) {
            return 'not_modified';
        }
        if ($status !== 200) {
            return null;
        }

        if ($etagKey !== '' && ($etagHeader = $response->getHeaderLine('ETag')) !== '') {
            $cache->setEtag($etagKey, $etagHeader);
        }

        $data = json_decode((string) $response->getBody(), true);
        return is_array($data) ? $data : null;
    }

    private function client(): Client
    {
        return Craft::createGuzzleClient();
    }

    /**
     * Normalize a raw API post into the shape the bundled templates expect.
     * The API already returns HTML content and sanitized fields; we map field
     * names and guarantee every key exists so templates never hit undefined.
     */
    private function normalizePost(array $post): array
    {
        return [
            'id' => $post['id'] ?? '',
            'slug' => $post['slugLocalized'] ?? $post['slug'] ?? '',
            'masterSlug' => $post['slug'] ?? '',
            'title' => $post['title'] ?? '',
            'excerpt' => $post['excerpt'] ?? '',
            'html' => $post['html'] ?? $post['content'] ?? '',
            'coverImage' => $post['coverImage'] ?? $post['image'] ?? '',
            'tags' => array_values(array_filter((array) ($post['tags'] ?? $post['keywords'] ?? []))),
            'author' => $post['author'] ?? 'Launchmind',
            'publishedAt' => $post['publishedAt'] ?? $post['date'] ?? '',
            'language' => $post['language'] ?? '',
            'availableLanguages' => array_values((array) ($post['availableLanguages'] ?? [])),
            'isVoiceArticle' => (bool) ($post['is_voice_article'] ?? false),
            'wordCount' => (int) ($post['wordCount'] ?? 0),
            'seo' => [
                'title' => $post['seo']['title'] ?? $post['title'] ?? '',
                'description' => $post['seo']['description'] ?? $post['excerpt'] ?? '',
            ],
        ];
    }

    private function resolveLang(): string
    {
        $configured = trim(Plugin::getInstance()->getSettings()->language);
        if ($configured !== '' && in_array($configured, self::SUPPORTED_LANGS, true)) {
            return $configured;
        }
        $siteLang = substr(Craft::$app->language, 0, 2);
        return in_array($siteLang, self::SUPPORTED_LANGS, true) ? $siteLang : 'en';
    }

    private function freshTtl(): int
    {
        $cache = Plugin::getInstance()->cache;
        return $cache->ttlJittered(Plugin::getInstance()->getSettings()->getCacheTtl());
    }

    /**
     * Persist the subscription id returned by the API so tracking beacons have
     * a stable identifier without a separate lookup.
     */
    private function rememberSubscriptionId(?string $id): void
    {
        if (!is_string($id) || $id === '') {
            return;
        }
        $cache = Plugin::getInstance()->cache;
        $cache->setWithStale('subscription_id', $id, Cache::STALE_TTL);
    }

    /**
     * Resolve the tracking subscription id: explicit setting first, then the
     * value remembered from an API response, then the UUID embedded in a
     * `lm_<uuid>_<random>` key.
     */
    private function resolveSubscriptionId(): string
    {
        $explicit = trim(Plugin::getInstance()->getSettings()->subscriptionId);
        if ($explicit !== '') {
            return $explicit;
        }

        $remembered = Plugin::getInstance()->cache->getWithFreshness('subscription_id');
        if ($remembered['state'] !== 'miss' && is_string($remembered['value'])) {
            return $remembered['value'];
        }

        $apiKey = Plugin::getInstance()->getSettings()->getApiKey();
        if (preg_match('/^lm_([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})_/i', $apiKey, $m)) {
            return strtolower($m[1]);
        }

        return '';
    }

    /**
     * Cheap UA-based bot filter — mirrors the WordPress connector so
     * server-side counts aren't dominated by crawlers.
     */
    private function isBot(string $ua): bool
    {
        if ($ua === '') {
            return true;
        }
        return (bool) preg_match(
            '/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|ia_archiver|wayback|ahrefs|semrush|mj12|dotbot|screaming|applebot|linkedinbot|twitterbot|pinterestbot|whatsapp|telegram|gptbot|claude|anthropic|ccbot|perplexity|oai-searchbot|chatgpt-user/i',
            $ua
        );
    }
}
