<?php

namespace App\Seo;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * SEO 봇 캐시의 상한 판정 단일 출처
 *
 * 봇 판정은 User-Agent 문자열뿐이라 누구나 위장할 수 있다. 그런데 캐시 키가 경로 + 전체
 * 쿼리였으므로 물음표 뒤 값만 바꾸면 매 요청이 미스가 되고, 미스마다 레이아웃 병합 ·
 * 표현식 평가 · 자기 API 루프백 HTTP 호출이 일어나고 그 결과가 무제한으로 저장됐다.
 * 요청 하나가 워커 여러 개를 묶고 캐시 저장소를 계속 키우는 통로였다.
 *
 * 상한은 셋으로 나뉜다:
 *
 *   - **키 형태** — 정규화할 수 없을 만큼 큰 쿼리는 애초에 색인 대상이 아니다(캐시하지 않고 SPA).
 *   - **렌더 예산** — IP 당 분당 미스 렌더 수. 초과분은 SPA 로 돌려보낸다(429 가 아니다 — 봇에게
 *     오류를 주면 색인에서 그 URL 이 사라진다).
 *   - **동시 렌더** — 사이트 전체에서 동시에 진행하는 미스 렌더 수. IP 당 예산은 요청이 끝나야
 *     보이므로 한꺼번에 몰아친 요청(동시 15건 전량 렌더 실측)을 막지 못한다. 초과분도 SPA 다.
 *   - **저장 규모** — 경로당 변종 수와 전체 항목 수.
 *
 * 값은 `config/core.php` 의 `seo_cache_limits` 가 SSoT 이고 env 로 덮을 수 있다.
 */
final class SeoCacheBounds
{
    /**
     * 캐시 키에서 제외하는 시스템 파라미터.
     *
     * `locale` 은 캐시 키의 별도 축이다. `_escaped_fragment_` 는 더 이상 봇 판정 신호가 아니지만
     * 그것을 붙여 오는 옛 크롤러(봇 UA)가 있을 수 있고 내용에 영향이 없다 — 키에 남기면 같은
     * 페이지가 두 벌 저장된다.
     */
    private const SYSTEM_QUERY_PARAMS = ['locale', '_escaped_fragment_'];

    /**
     * 동시 렌더 슬롯 잠금 이름 접두사 (슬롯 번호가 뒤에 붙는다).
     */
    private const SLOT_LOCK_PREFIX = 'seo-render-slot:';

    /**
     * 슬롯 잠금 수명 (초). 워커가 죽어 반환하지 못한 슬롯이 이 시간 뒤에 풀린다.
     * 렌더 1건의 상한(데이터소스 수 × 호출 제한 시간 + 조립)보다 넉넉해야 한다.
     */
    private const SLOT_LOCK_SECONDS = 60;

    /**
     * IP 별 렌더 예산 판정 잠금 이름 접두사.
     */
    private const BUDGET_LOCK_PREFIX = 'seo-render-budget:';

    /**
     * 상한값을 반환합니다.
     *
     * @param  string  $key  `seo_cache_limits` 하위 키
     * @param  int  $fallback  설정이 없을 때의 기본값
     * @return int 상한값
     */
    private static function limit(string $key, int $fallback): int
    {
        return (int) config('core.seo_cache_limits.'.$key, $fallback);
    }

    /**
     * 캐시 키에 쓸 쿼리 파라미터를 정규화합니다.
     *
     * 시스템 파라미터를 제거하고 키 순서로 정렬한다. 개수·길이 상한을 넘으면 **null** 을
     * 돌려주며, 그것은 "이 URL 은 캐시하지도 렌더하지도 않는다" 를 뜻한다.
     *
     * @param  array<string, mixed>  $query  요청 쿼리 파라미터
     * @return array<string, mixed>|null 정규화된 파라미터 (상한 초과 시 null)
     */
    public static function normalizeQuery(array $query): ?array
    {
        foreach (self::SYSTEM_QUERY_PARAMS as $param) {
            unset($query[$param]);
        }

        if (count($query) > self::limit('max_query_params', 10)) {
            return null;
        }

        ksort($query);

        if (strlen(http_build_query($query)) > self::limit('max_query_length', 512)) {
            return null;
        }

        return $query;
    }

    /**
     * 이 IP 가 미스 렌더를 더 수행할 수 있는지 판정합니다.
     *
     * @param  string  $ip  요청 IP
     * @return bool 렌더 허용 여부
     */
    public static function renderAllowed(string $ip): bool
    {
        return ! RateLimiter::tooManyAttempts(
            'seo-render:'.$ip,
            self::limit('render_misses_per_minute', 60)
        );
    }

    /**
     * 미스 렌더 1건을 예산에서 차감합니다.
     *
     * @param  string  $ip  요청 IP
     * @return void
     */
    public static function recordRender(string $ip): void
    {
        RateLimiter::hit('seo-render:'.$ip, 60);
    }

    /**
     * 예산 안이면 미스 렌더 1건을 차감하고 true 를 돌려줍니다 (판정 + 차감을 한 번에).
     *
     * `renderAllowed()` → `recordRender()` 를 따로 부르면 동시에 들어온 요청이 모두 차감 전의
     * 값을 보고 통과한다. 파일 캐시의 카운터 증가는 읽고-쓰기라 동시 요청끼리 서로의 증가를
     * 덮어쓰기도 한다(동시 15건 뒤 카운터 6 실측). 그래서 IP 별 잠금 안에서 판정과 차감을 묶는다.
     *
     * 잠금을 2초 안에 얻지 못하면 같은 IP 가 줄을 설 만큼 몰아치는 중이라 렌더하지 않는다.
     * 저장소가 잠금을 제공하지 못하면(드라이버 미지원 등) 원자성 없이 종전 방식으로 판정한다.
     *
     * @param  string  $ip  요청 IP
     * @return bool 차감했으면(렌더해도 되면) true
     */
    public static function claimRender(string $ip): bool
    {
        $claim = static function () use ($ip): bool {
            if (! self::renderAllowed($ip)) {
                return false;
            }

            self::recordRender($ip);

            return true;
        };

        try {
            return (bool) Cache::lock(self::BUDGET_LOCK_PREFIX.$ip, 5)
                ->betweenBlockedAttemptsSleepFor(20)
                ->block(2, $claim);
        } catch (LockTimeoutException) {
            return false;
        } catch (\Throwable $e) {
            Log::warning('[SEO] 렌더 예산 잠금을 얻을 수 없어 잠금 없이 판정합니다', [
                'store' => config('cache.default'),
                'error' => $e->getMessage(),
            ]);

            return $claim();
        }
    }

    /**
     * 동시 렌더 슬롯 하나를 차지합니다.
     *
     * 슬롯은 `max_concurrent_renders` 개의 잠금이다. 빈 잠금 하나를 얻으면 그것을 돌려줄
     * 콜백을 반환하고, 모두 차 있으면 null 을 반환한다(호출측은 SPA 로 돌려보낸다). 상한이
     * 0 이하이거나 저장소가 잠금을 제공하지 못하면 제한 없이 통과시킨다 — 봇 화면 전체를
     * 막는 것보다 종전 동작이 낫다.
     *
     * @return \Closure|null 슬롯 반환 콜백 (슬롯이 모두 찼으면 null)
     */
    public static function acquireRenderSlot(): ?\Closure
    {
        $max = self::limit('max_concurrent_renders', 4);

        if ($max <= 0) {
            return static function (): void {};
        }

        try {
            // 시작 번호를 흩어 모든 요청이 0번 슬롯부터 두드리지 않게 한다.
            $start = random_int(0, $max - 1);

            for ($i = 0; $i < $max; $i++) {
                $lock = Cache::lock(self::SLOT_LOCK_PREFIX.(($start + $i) % $max), self::SLOT_LOCK_SECONDS);

                if ($lock->get()) {
                    return static function () use ($lock): void {
                        try {
                            $lock->release();
                        } catch (\Throwable) {
                            // 반환 실패는 슬롯 수명이 지나면 저절로 풀린다.
                        }
                    };
                }
            }

            return null;
        } catch (\Throwable $e) {
            Log::warning('[SEO] 동시 렌더 슬롯 잠금을 얻을 수 없어 제한 없이 렌더합니다', [
                'store' => config('cache.default'),
                'error' => $e->getMessage(),
            ]);

            return static function (): void {};
        }
    }

    /**
     * 차감한 미스 렌더 1건을 예산에 되돌립니다.
     *
     * 렌더러가 "그릴 게 없음"(null)으로 돌아온 요청 — 미라우트 404, SEO 비활성 화면 — 은
     * 캐시에 남지 않아 올 때마다 다시 예산을 쓴다. 봇은 예전에 있던 죽은 주소를 오래 다시
     * 긁으므로, 그 요청까지 세면 정상 페이지의 예산이 죽은 주소에 소진된다. 렌더 도중 예외는
     * 비용을 이미 치른 것이라 되돌리지 않는다.
     *
     * @param  string  $ip  요청 IP
     * @return void
     */
    public static function refundRender(string $ip): void
    {
        $refund = static fn () => RateLimiter::decrement('seo-render:'.$ip, 60);

        // 차감과 같은 잠금 안에서 되돌린다 — 밖에서 하면 동시 차감과 서로 덮어쓴다.
        try {
            Cache::lock(self::BUDGET_LOCK_PREFIX.$ip, 5)
                ->betweenBlockedAttemptsSleepFor(20)
                ->block(2, $refund);
        } catch (\Throwable) {
            $refund();
        }
    }

    /**
     * 이 IP 의 요청을 통계로 기록할 수 있는지 판정합니다.
     *
     * 통계 테이블이 새로운 증식 축이 되지 않도록 기록 자체에도 상한을 둔다.
     *
     * @param  string  $ip  요청 IP
     * @return bool 기록 허용 여부
     */
    public static function statsAllowed(string $ip): bool
    {
        return ! RateLimiter::tooManyAttempts(
            'seo-stats:'.$ip,
            self::limit('stats_records_per_minute', 300)
        );
    }

    /**
     * 통계 기록 1건을 예산에서 차감합니다.
     *
     * @param  string  $ip  요청 IP
     * @return void
     */
    public static function recordStat(string $ip): void
    {
        RateLimiter::hit('seo-stats:'.$ip, 60);
    }

    /**
     * 새 URL 을 캐시에 저장할 수 있는지 판정합니다.
     *
     * 이미 인덱스에 있는 키의 **갱신**은 이 판정을 거치지 않는다(호출측 책임) — 저장 규모가
     * 늘지 않기 때문이다.
     *
     * 경로당 변종은 **언어별로** 센다. 인덱스 항목은 url|locale 별이라 경로만 보고 합산하면
     * 언어 수만큼 실효 상한이 줄어, 다국어 사이트의 목록 뒤쪽 페이지가 언어마다 캐시에서 빠진다.
     *
     * @param  array<string, array<string, mixed>>  $index  현재 캐시 인덱스
     * @param  string  $url  저장하려는 URL (경로 + 정규화 쿼리)
     * @param  string  $locale  저장하려는 로케일
     * @return bool 저장 허용 여부
     */
    public static function canStore(array $index, string $url, string $locale): bool
    {
        if (count($index) >= self::limit('max_entries', 20000)) {
            return false;
        }

        $path = self::pathOf($url);
        $variants = 0;

        foreach ($index as $entry) {
            if (($entry['locale'] ?? null) !== $locale) {
                continue;
            }

            if (self::pathOf((string) ($entry['url'] ?? '')) === $path) {
                $variants++;
            }
        }

        return $variants < self::limit('max_variants_per_path', 50);
    }

    /**
     * URL 에서 경로 부분만 잘라냅니다.
     *
     * @param  string  $url  캐시 URL (`/path?a=1` 형태)
     * @return string 경로
     */
    private static function pathOf(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) ? $path : $url;
    }
}
