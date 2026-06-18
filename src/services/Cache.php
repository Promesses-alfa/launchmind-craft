<?php

namespace launchmind\blog\services;

use Craft;
use yii\base\Component;
use yii\caching\TagDependency;

/**
 * Thin wrapper over Craft's cache component implementing stale-while-revalidate
 * so a Launchmind API outage never breaks a customer blog: the last-good
 * payload keeps serving for up to STALE_TTL while the API recovers.
 *
 * Mirrors the WordPress connector's cache semantics:
 *   - getWithFreshness(): returns 'miss' | 'fresh' | 'stale'
 *   - setWithStale(): fresh window + 24h envelope lifetime
 *   - ETags persisted per key so the API client can send If-None-Match
 *   - all entries tagged so clearAll() can invalidate them at once
 */
class Cache extends Component
{
    private const PREFIX = 'launchmind_blog_';
    private const TAG = 'launchmind-blog';

    /** How long the envelope survives past the fresh window (24h). */
    public const STALE_TTL = 86400;

    /**
     * @return array{state:string,value?:mixed}
     */
    public function getWithFreshness(string $key): array
    {
        $envelope = Craft::$app->getCache()->get(self::PREFIX . $key);
        if (!is_array($envelope) || !array_key_exists('value', $envelope)) {
            return ['state' => 'miss'];
        }

        $freshUntil = (int) ($envelope['fresh_until'] ?? 0);
        if ($freshUntil >= time()) {
            return ['state' => 'fresh', 'value' => $envelope['value']];
        }

        return ['state' => 'stale', 'value' => $envelope['value']];
    }

    /**
     * Store a value with a fresh window. The envelope itself lives for
     * STALE_TTL so it can act as the outage fallback after the fresh window
     * elapses.
     */
    public function setWithStale(string $key, mixed $value, int $freshTtl): void
    {
        $envelope = [
            'value' => $value,
            'fresh_until' => time() + max(60, $freshTtl),
            'stored_at' => time(),
        ];

        Craft::$app->getCache()->set(
            self::PREFIX . $key,
            $envelope,
            self::STALE_TTL,
            new TagDependency(['tags' => self::TAG])
        );
    }

    public function getEtag(string $key): ?string
    {
        $etag = Craft::$app->getCache()->get(self::PREFIX . 'etag_' . $key);
        return is_string($etag) && $etag !== '' ? $etag : null;
    }

    public function setEtag(string $key, string $etag): void
    {
        if ($etag === '') {
            return;
        }
        Craft::$app->getCache()->set(
            self::PREFIX . 'etag_' . $key,
            $etag,
            self::STALE_TTL,
            new TagDependency(['tags' => self::TAG])
        );
    }

    /**
     * Build a stable cache key from a base name and its parameters.
     */
    public function key(string $base, array $params = []): string
    {
        if ($params === []) {
            return $base;
        }
        ksort($params);
        return $base . '_' . md5(serialize($params));
    }

    /**
     * Jittered TTL: spreads cache-expiry refreshes across a 2-minute window so
     * a fleet of Craft sites never stampedes the upstream API in lockstep.
     */
    public function ttlJittered(int $ttl): int
    {
        return max(60, $ttl + random_int(-60, 60));
    }

    /**
     * Invalidate every cache entry this plugin has written.
     */
    public function clearAll(): void
    {
        TagDependency::invalidate(Craft::$app->getCache(), self::TAG);
    }
}
