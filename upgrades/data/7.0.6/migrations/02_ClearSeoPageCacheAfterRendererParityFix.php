<?php

namespace App\Upgrades\Data\V7_0_6\Migrations;

use App\Extension\Cache\CoreCacheDriver;
use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\Log;

/**
 * 봇용 페이지 캐시를 1회 전량 무효화합니다.
 *
 * 배경:
 *   7.0.6 이전의 봇 렌더러는 레이아웃 확장이 교체한 본문 영역(게시글·댓글·페이지·상품
 *   설명)을 빈 요소로 내보냈고, 그 결과물이 페이지 캐시에 그대로 저장되어 있다.
 *   렌더러를 고쳐도 캐시가 유효한 동안에는 검색 봇이 계속 빈 본문을 받는다.
 *   캐시 수명(TTL)을 길게 잡아 둔 사이트일수록 오래 남으므로 업그레이드 시 한 번 비운다.
 *
 * 멱등: 인덱스가 없으면 아무 것도 하지 않는다. 재실행해도 부작용이 없다.
 *
 * 실패 정책: 캐시 삭제 실패가 업그레이드 전체를 막지 않는다(경고 후 계속).
 * 비우지 못해도 캐시 수명이 지나면 자연히 새 결과물로 교체된다.
 *
 * 격리 원칙 (docs/extension/upgrade-step-guide.md §13):
 *   `SeoCacheManager` 를 참조하지 않는다. 캐시 키 프리픽스와 인덱스 키를 본 클래스에
 *   로컬 상수로 동결해 V-1 안전을 확보한다 — 미래 버전에서 그 클래스의 상수가 바뀌어도
 *   본 스텝은 7.0.6 시점의 캐시를 대상으로 동작해야 한다.
 */
final class ClearSeoPageCacheAfterRendererParityFix implements DataMigration
{
    /**
     * 봇 페이지 캐시가 저장한 URL 인덱스 키 (7.0.6 시점 고정값).
     */
    private const INDEX_KEY = 'seo.cached_urls';

    /**
     * 봇 페이지 캐시 키 프리픽스 (7.0.6 시점 고정값).
     *
     * 인덱스가 손상되어 다른 네임스페이스의 키가 섞여 있어도 봇 페이지 캐시 밖의
     * 데이터를 지우지 않도록, 삭제 대상을 이 프리픽스로 한정합니다.
     */
    private const CACHE_PREFIX = 'seo.page.';

    /**
     * 마이그레이션 식별자 (로그용).
     *
     * @return string 식별자
     */
    public function name(): string
    {
        return 'ClearSeoPageCacheAfterRendererParityFix';
    }

    /**
     * 저장된 봇 페이지 캐시를 전량 삭제합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        try {
            $this->runInternal($context);
        } catch (\Throwable $e) {
            // 업그레이드 중단 금지 — 캐시 삭제 실패가 업그레이드 전체를 막지 않는다.
            $context->logger->warning(sprintf(
                '[7.0.6] 봇 페이지 캐시 무효화 실패 (캐시 수명 만료로 자연 해소, 계속 진행): %s',
                $e->getMessage(),
            ));
            Log::warning('7.0.6 ClearSeoPageCacheAfterRendererParityFix 실패', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * 실제 삭제 로직.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    private function runInternal(UpgradeContext $context): void
    {
        $cache = $this->resolveCache();
        if ($cache === null) {
            $context->logger->info('[7.0.6] 봇 페이지 캐시 드라이버 미가용 — skip');

            return;
        }

        $index = $cache->get(self::INDEX_KEY);
        if (! is_array($index) || $index === []) {
            $context->logger->info('[7.0.6] 봇 페이지 캐시 — 비울 대상 없음');

            return;
        }

        $count = 0;
        foreach ($index as $entry) {
            $key = is_array($entry) ? ($entry['key'] ?? null) : null;
            if (! is_string($key) || ! str_starts_with($key, self::CACHE_PREFIX)) {
                continue;
            }

            $cache->forget($key);
            $count++;
        }

        $cache->forget(self::INDEX_KEY);

        $context->logger->info(sprintf('[7.0.6] 봇 페이지 캐시 %d건 무효화 완료', $count));
    }

    /**
     * 코어 캐시 드라이버를 반환합니다.
     *
     * 컨테이너 바인딩에 의존하지 않고 직접 생성합니다 — 업그레이드 시점에는 바인딩이
     * 없을 수 있습니다. 생성 실패 시 null 을 반환해 스텝을 건너뜁니다.
     *
     * @return CoreCacheDriver|null 캐시 드라이버 (미가용 시 null)
     */
    private function resolveCache(): ?CoreCacheDriver
    {
        try {
            return new CoreCacheDriver(config('cache.default', 'array'));
        } catch (\Throwable) {
            return null;
        }
    }
}
