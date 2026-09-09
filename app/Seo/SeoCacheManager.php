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

    /**
     * 저장 상한에서 인덱스를 정리한 시각을 남기는 표식 키
     */
    private const PRUNE_MARK_KEY = 'seo.index_pruned_at';

    /**
     * 저장 상한에서 인덱스 정리를 다시 시도하기까지의 최소 간격 (초)
     */
    private const PRUNE_INTERVAL_SECONDS = 60;

    public function __construct(private readonly CacheInterface $cache) {}

    /**
     * {@inheritdoc}
     */
    public function get(string $url, string $locale): ?string
    {
        return $this->getEntry($url, $locale)['html'] ?? null;
    }

    /**
     * 캐시 항목(HTML + 레이아웃명)을 조회합니다.
     *
     * 페이지는 레이아웃명과 함께 저장된다 — 캐시 적중 경로는 렌더러를 거치지 않아 요청
     * 속성에 레이아웃명이 없고, 통계를 화면별로 귀속하려면 항목이 그것을 알아야 한다.
     * 이전 버전이 문자열로만 저장한 항목은 레이아웃명 없이 그대로 읽힌다 — 배포 직후
     * 살아 있는 캐시를 버리지 않는다.
     *
     * @param  string  $url  URL
     * @param  string  $locale  로케일
     * @return array{html: string, layout: string|null}|null 캐시 항목 (없으면 null)
     */
    public function getEntry(string $url, string $locale): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $value = $this->cache->get($this->buildKey($url, $locale));

        if (is_string($value)) {
            return ['html' => $value, 'layout' => null];
        }

        if (is_array($value) && is_string($value['html'] ?? null)) {
            $layout = $value['layout'] ?? null;

            return ['html' => $value['html'], 'layout' => is_string($layout) ? $layout : null];
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function put(string $url, string $locale, string $html): void
    {
        $this->storePage($url, $locale, $html, null);
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
     * 캐시 활성화 여부 확인 — 고급 탭 값을 쓰되 SEO 탭에 별도 지정이 있으면 그것이 우선 (D19).
     */
    private function isEnabled(): bool
    {
        return SeoCacheSettings::pageCacheEnabled();
    }

    /**
     * 캐시 TTL (초) — 고급 탭 값을 쓰되 SEO 탭에 별도 지정이 있으면 그것이 우선 (D19).
     */
    private function getCacheTtl(): int
    {
        return SeoCacheSettings::pageCacheTtl();
    }

    /**
     * 캐시 인덱스를 조회합니다.
     */
    private function getIndex(): array
    {
        return $this->cache->get(self::INDEX_KEY, []);
    }

    /**
     * 저장 상한에서 인덱스 정리를 시도해도 되는지 판정하고, 시도한다면 표식을 남깁니다.
     *
     * 정리는 인덱스 전체를 훑는다(항목마다 캐시 조회). 살아 있는 항목만으로 상한에 닿은
     * 경로는 저장 시도마다 그 스캔을 되풀이하게 되고, 그 빈도는 봇 미스 렌더 예산만큼이다
     * — 정리해도 자리가 나지 않는 상태에서 비용만 곱해진다. 그래서 간격으로 묶는다.
     *
     * @return bool 정리를 수행해도 되면 true
     */
    private function shouldAttemptPrune(): bool
    {
        if ($this->cache->has(self::PRUNE_MARK_KEY)) {
            return false;
        }

        $this->cache->put(self::PRUNE_MARK_KEY, true, self::PRUNE_INTERVAL_SECONDS);

        return true;
    }

    /**
     * 유효한 캐시만 남겨 인덱스를 재구성하고 그 결과를 반환합니다.
     *
     * 페이지는 TTL 로 사라지지만 인덱스 항목은 남는다 — 이 메서드가 그 차이를 메우는
     * 유일한 지점이므로, 인덱스를 근거로 판정하는 쪽(저장 상한)은 판정 전에 여기를 거친다.
     *
     * @return array<string, array<string, mixed>> 정리된 인덱스
     */
    private function rebuildIndex(): array
    {
        $index = $this->getIndex();
        $validIndex = [];

        foreach ($index as $key => $entry) {
            if ($this->cache->has($entry['key'])) {
                $validIndex[$key] = $entry;
            }
        }

        $this->cache->put(self::INDEX_KEY, $validIndex, 86400 * 30);

        return $validIndex;
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
     * 인덱스는 단일 캐시 항목에 전체 변종 배열을 담고 저장마다 통째로 다시 쓴다. 항목 수에
     * 상한이 없으면 쿼리만 바꾼 반복 요청이 그 배열을 무한히 키운다 — 그래서 **새 URL** 은
     * 경로당 변종 수와 전체 항목 수 상한 안에서만 저장한다. 이미 인덱스에 있는 키의 갱신은
     * 저장 규모를 늘리지 않으므로 상한과 무관하게 쓴다.
     *
     * @param  string  $url  URL
     * @param  string  $locale  로케일
     * @param  string  $html  HTML
     * @param  string  $layoutName  레이아웃명
     */
    public function putWithLayout(string $url, string $locale, string $html, string $layoutName): void
    {
        $this->storePage($url, $locale, $html, $layoutName);
    }

    /**
     * 페이지와 인덱스 항목을 저장합니다 (`put`/`putWithLayout` 공통 경로).
     *
     * 두 공개 메서드는 같은 자원(페이지 캐시 + 인덱스)을 쓰므로 저장 규모 상한도 같아야
     * 한다 — 한쪽에만 두면 다른 쪽이 우회로가 되고, 인터페이스는 확장에 열려 있어
     * "지금 호출부가 없다" 는 방어가 되지 않는다.
     *
     * @param  string  $url  URL (경로 + 정규화 쿼리)
     * @param  string  $locale  로케일
     * @param  string  $html  저장할 HTML
     * @param  string|null  $layoutName  레이아웃명 (없으면 인덱스에 기록하지 않음)
     */
    private function storePage(string $url, string $locale, string $html, ?string $layoutName): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $key = $this->buildKey($url, $locale);
        $index = $this->getIndex();

        if (! isset($index[$key])) {
            // 상한이 세는 인덱스에는 **페이지가 이미 만료된** 항목이 섞인다 — 인덱스는
            // 페이지보다 훨씬 오래 살고(30일 vs 기본 2시간) 저장마다 수명이 갱신되며
            // 스스로 줄지 않는다. 여기서 한 번 정리하지 않으면 상한이 "지금 저장된 양"이
            // 아니라 "과거에 저장한 적이 있는 양"을 재게 되어, 한 번 닿은 경로는 실제
            // 캐시가 비어도 영영 저장이 막힌다(상한이 아니라 일방향 래치가 된다).
            if (! SeoCacheBounds::canStore($index, $url, $locale) && $this->shouldAttemptPrune()) {
                $index = $this->rebuildIndex();
            }

            if (! SeoCacheBounds::canStore($index, $url, $locale)) {
                Log::debug('[SEO] 캐시 저장 상한에 도달해 저장하지 않습니다', [
                    'url' => $url,
                    'locale' => $locale,
                    'entries' => count($index),
                ]);

                return;
            }
        }

        // 레이아웃명을 페이지와 함께 둔다 — 적중 경로가 통계를 화면별로 귀속할 유일한 출처다.
        $this->cache->put($key, ['html' => $html, 'layout' => $layoutName], $this->getCacheTtl());

        $entry = [
            'url' => $url,
            'locale' => $locale,
            'key' => $key,
        ];

        if ($layoutName !== null) {
            $entry['layout'] = $layoutName;
        }

        $entry['cached_at'] = now()->toIso8601String();

        $index[$key] = $entry;

        $this->cache->put(self::INDEX_KEY, $index, 86400 * 30);
    }
}
