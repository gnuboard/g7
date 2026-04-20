<?php

namespace App\Seo;

use App\Contracts\Extension\CacheInterface;
use App\Seo\Contracts\SeoCacheManagerInterface;
use Illuminate\Support\Facades\Log;

class SeoCacheManager implements SeoCacheManagerInterface
{
    /**
     * 캐시 키 프리픽스 (드라이버 접두사 `g7:core:` 다음에 붙음)
     */
    private const CACHE_PREFIX = 'seo.page.';

    /**
     * 캐시된 URL 인덱스 키
     */
    private const INDEX_KEY = 'seo.cached_urls';

    public function __construct(private readonly CacheInterface $cache) {}

    /**
     * {@inheritdoc}
     */
    public function get(string $url, string $locale): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $key = $this->buildKey($url, $locale);

        return $this->cache->get($key);
    }

    /**
     * {@inheritdoc}
     */
    public function put(string $url, string $locale, string $html): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $key = $this->buildKey($url, $locale);
        $ttl = $this->getCacheTtl();

        $this->cache->put($key, $html, $ttl);

        // URL 인덱스 업데이트
        $this->addToIndex($url, $locale, $key);
    }

    /**
     * {@inheritdoc}
     */
    public function invalidateByUrl(string $urlPattern): int
    {
        $count = 0;
        $index = $this->getIndex();

        foreach ($index as $entry) {
            if ($this->matchesPattern($entry['url'], $urlPattern)) {
                $this->cache->forget($entry['key']);
                $count++;
            }
        }

        if ($count > 0) {
            $this->rebuildIndex();
        }

        return $count;
    }

    /**
     * {@inheritdoc}
     */
    public function invalidateByLayout(string $layoutName): int
    {
        $count = 0;
        $index = $this->getIndex();

        foreach ($index as $entry) {
            if (($entry['layout'] ?? '') === $layoutName) {
                $this->cache->forget($entry['key']);
                $count++;
            }
        }

        if ($count > 0) {
            $this->rebuildIndex();
        }

        return $count;
    }

    /**
     * {@inheritdoc}
     */
    public function clearAll(): void
    {
        $index = $this->getIndex();

        foreach ($index as $entry) {
            $this->cache->forget($entry['key']);
        }

        $this->cache->forget(self::INDEX_KEY);

        Log::info('[SEO] All cache cleared', ['count' => count($index)]);
    }

    /**
     * {@inheritdoc}
     */
    public function getCachedUrls(): array
    {
        return array_map(fn ($entry) => $entry['url'], $this->getIndex());
    }

    /**
     * 캐시 키 빌드 (URL + 로케일)
     *
     * @param  string  $url  URL
     * @param  string  $locale  로케일
     * @return string 캐시 키
     */
    private function buildKey(string $url, string $locale): string
    {
        return self::CACHE_PREFIX.md5($url.'|'.$locale);
    }

    /**
     * 캐시 활성화 여부 확인
     */
    private function isEnabled(): bool
    {
        return (bool) g7_core_settings('cache.seo_enabled', g7_core_settings('seo.cache_enabled', true));
    }

    /**
     * 캐시 TTL (초) — g7_core_settings('cache.seo_ttl') 우선.
     */
    private function getCacheTtl(): int
    {
        return (int) g7_core_settings('cache.seo_ttl', g7_core_settings('seo.cache_ttl', 7200));
    }

    /**
     * URL 인덱스에 항목을 추가합니다.
     *
     * @param  string  $url  URL
     * @param  string  $locale  로케일
     * @param  string  $key  캐시 키
     */
    private function addToIndex(string $url, string $locale, string $key): void
    {
        $index = $this->getIndex();
        $index[$key] = [
            'url' => $url,
            'locale' => $locale,
            'key' => $key,
            'cached_at' => now()->toIso8601String(),
        ];

        $this->cache->put(self::INDEX_KEY, $index, 86400 * 30); // 30일
    }

    /**
     * 캐시 인덱스를 조회합니다.
     */
    private function getIndex(): array
    {
        return $this->cache->get(self::INDEX_KEY, []);
    }

    /**
     * 유효한 캐시만 남겨 인덱스를 재구성합니다.
     */
    private function rebuildIndex(): void
    {
        $index = $this->getIndex();
        $validIndex = [];

        foreach ($index as $key => $entry) {
            if ($this->cache->has($entry['key'])) {
                $validIndex[$key] = $entry;
            }
        }

        $this->cache->put(self::INDEX_KEY, $validIndex, 86400 * 30);
    }

    /**
     * URL이 패턴에 매칭되는지 확인합니다.
     *
     * @param  string  $url  URL
     * @param  string  $pattern  패턴 (와일드카드 * 지원)
     */
    private function matchesPattern(string $url, string $pattern): bool
    {
        // 와일드카드를 정규식으로 변환
        $regex = str_replace(['*', '/'], ['.*', '\/'], $pattern);

        return (bool) preg_match('/^'.$regex.'$/', $url);
    }

    /**
     * 캐시 저장 시 레이아웃 정보를 함께 저장합니다.
     *
     * @param  string  $url  URL
     * @param  string  $locale  로케일
     * @param  string  $html  HTML
     * @param  string  $layoutName  레이아웃명
     */
    public function putWithLayout(string $url, string $locale, string $html, string $layoutName): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $key = $this->buildKey($url, $locale);
        $ttl = $this->getCacheTtl();

        $this->cache->put($key, $html, $ttl);

        // 레이아웃 정보 포함하여 인덱스 업데이트
        $index = $this->getIndex();
        $index[$key] = [
            'url' => $url,
            'locale' => $locale,
            'key' => $key,
            'layout' => $layoutName,
            'cached_at' => now()->toIso8601String(),
        ];

        $this->cache->put(self::INDEX_KEY, $index, 86400 * 30);
    }
}
