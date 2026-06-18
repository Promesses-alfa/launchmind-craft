<?php

namespace launchmind\blog\models;

use craft\base\Model;
use craft\helpers\App;

/**
 * Plugin settings. Editable in Settings → Plugins → Launchmind Blog, or
 * overridden in config/launchmind-blog.php (Craft merges that file over these
 * defaults). API key and base URL support environment variables, e.g.
 * apiKey => '$LAUNCHMIND_API_KEY'.
 */
class Settings extends Model
{
    /** Launchmind API base. Defaults to production. */
    public string $apiBase = 'https://launchmind.io/api';

    /** Customer API key (lm_...). Required for live content. */
    public string $apiKey = '';

    /**
     * Subscription UUID, used as the tracking identifier. Resolved
     * automatically from the API response on the first successful call, so
     * this normally stays empty in the settings UI.
     */
    public string $subscriptionId = '';

    /** Fresh-cache window in seconds (min 60). Default 10 minutes. */
    public int $cacheTtl = 600;

    /** Front-end path the blog renders at, e.g. "blog" → /blog and /blog/<slug>. */
    public string $blogPath = 'blog';

    /**
     * ISO-639-1 language to request (en, nl, de, fr, es, it, hi, pl).
     * Empty = derive from the current Craft site language.
     */
    public string $language = '';

    /** Whether to fire the server-side page-view beacon on article renders. */
    public bool $enableTracking = true;

    public function rules(): array
    {
        return [
            [['apiBase', 'blogPath'], 'required'],
            [['apiBase', 'apiKey', 'subscriptionId', 'blogPath', 'language'], 'string'],
            [['cacheTtl'], 'integer', 'min' => 60],
            [['enableTracking'], 'boolean'],
        ];
    }

    public function getApiBase(): string
    {
        return rtrim((string) App::parseEnv($this->apiBase), '/');
    }

    public function getApiKey(): string
    {
        return trim((string) App::parseEnv($this->apiKey));
    }

    public function getBlogPath(): string
    {
        return trim($this->blogPath, '/') ?: 'blog';
    }

    public function getCacheTtl(): int
    {
        return max(60, $this->cacheTtl);
    }
}
