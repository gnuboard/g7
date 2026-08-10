<?php

namespace Tests\Unit\Search;

use App\Enums\SearchIndexStatus;
use App\Extension\HookManager;
use App\Search\Contracts\SearchIndexMaintainer;
use App\Search\DTO\SearchIndexHealth;
use App\Search\Engines\Maintenance\FulltextIndexMaintainer;
use App\Search\SearchIndexMaintenanceManager;
use Tests\TestCase;

/**
 * 검색 인덱스 유지보수 진입점 테스트 (엔진 중립성 고정)
 *
 * 이 클래스가 지켜야 하는 것은 **FULLTEXT 를 잘 다루는 것이 아니라**, 활성 엔진이
 * 무엇이든 같은 방식으로 다루는 것입니다. FULLTEXT 전용 가정이 들어오면 확장이
 * 등록한 엔진에서 조용히 오동작하므로 여기서 고정합니다.
 */
class SearchIndexMaintenanceManagerTest extends TestCase
{
    /**
     * 훅으로 등록한 유지보수기가 드라이버명으로 해석되는지 검증합니다.
     *
     * @return void
     */
    public function test_확장이_훅으로_등록한_유지보수기가_해석된다(): void
    {
        config(['scout.driver' => 'acme-search']);

        HookManager::addFilter(
            SearchIndexMaintenanceManager::MAINTAINERS_FILTER,
            fn (array $maintainers) => $maintainers + ['acme-search' => FakeSearchIndexMaintainer::class]
        );

        $manager = new SearchIndexMaintenanceManager;

        $this->assertArrayHasKey('acme-search', $manager->registered());
        $this->assertTrue($manager->hasMaintainer());
        $this->assertInstanceOf(FakeSearchIndexMaintainer::class, $manager->maintainer());
    }

    /**
     * 유지보수기를 등록하지 않은 엔진에서 "점검 0건" 과 "점검 불가" 가 구분되는지 검증합니다.
     *
     * 둘을 뭉뚱그리면 운영자가 "인덱스가 다 정상" 으로 읽습니다.
     *
     * @return void
     */
    public function test_미등록_엔진은_점검_0건이_아니라_점검_불가로_보고된다(): void
    {
        config(['scout.driver' => 'no-such-engine']);

        $manager = new SearchIndexMaintenanceManager;

        $this->assertFalse($manager->hasMaintainer());
        $this->assertNotNull($manager->unavailableReason());
        $this->assertSame([], $manager->inspect());

        $report = $manager->repairStale();

        $this->assertFalse($report->available);
        $this->assertSame('no-such-engine', $report->driver);
        $this->assertSame([], $report->repaired);
        $this->assertNotNull($report->reason);
    }

    /**
     * 색인 누락 판정만 재생성 대상이 되는지 검증합니다.
     *
     * `degraded` 는 엔진 토크나이저 특성일 수 있어 자동 재생성 대상이 아닙니다.
     *
     * @return void
     */
    public function test_stale_만_재생성하고_degraded_는_건드리지_않는다(): void
    {
        config(['scout.driver' => 'acme-search']);

        HookManager::addFilter(
            SearchIndexMaintenanceManager::MAINTAINERS_FILTER,
            fn (array $maintainers) => $maintainers + ['acme-search' => FakeSearchIndexMaintainer::class]
        );

        FakeSearchIndexMaintainer::$rebuilt = [];
        FakeSearchIndexMaintainer::$statuses = [
            'idx-healthy' => SearchIndexStatus::Healthy,
            'idx-degraded' => SearchIndexStatus::Degraded,
            'idx-stale' => SearchIndexStatus::Stale,
            'idx-skipped' => SearchIndexStatus::Skipped,
        ];

        $report = (new SearchIndexMaintenanceManager)->repairStale();

        $this->assertSame(['idx-stale'], FakeSearchIndexMaintainer::$rebuilt);
        $this->assertSame(['idx-stale'], $report->repaired);
        $this->assertSame([], $report->failed);
    }

    /**
     * 재생성 후 다시 점검해 잔존 여부를 담는지 검증합니다.
     *
     * "재생성했다" 와 "복구됐다" 는 다른 사실입니다.
     *
     * @return void
     */
    public function test_재생성_후_다시_점검해_잔존을_보고한다(): void
    {
        config(['scout.driver' => 'acme-search']);

        HookManager::addFilter(
            SearchIndexMaintenanceManager::MAINTAINERS_FILTER,
            fn (array $maintainers) => $maintainers + ['acme-search' => FakeSearchIndexMaintainer::class]
        );

        FakeSearchIndexMaintainer::$rebuilt = [];
        FakeSearchIndexMaintainer::$healAfterRebuild = false;   // 재생성해도 낫지 않는 엔진
        FakeSearchIndexMaintainer::$statuses = ['idx-stale' => SearchIndexStatus::Stale];

        $report = (new SearchIndexMaintenanceManager)->repairStale();

        $this->assertSame(['idx-stale'], $report->repaired);
        $this->assertSame(['idx-stale'], $report->remaining, '재생성 후 재점검 결과가 보고에 반영되어야 한다');
        $this->assertFalse($report->isClean());

        FakeSearchIndexMaintainer::$healAfterRebuild = true;
    }

    /**
     * 기본 드라이버에 FULLTEXT 유지보수기가 등록되어 있는지 검증합니다.
     *
     * @return void
     */
    public function test_기본_드라이버에_fulltext_유지보수기가_등록되어_있다(): void
    {
        config(['scout.driver' => 'mysql-fulltext']);

        $manager = new SearchIndexMaintenanceManager;

        $this->assertSame(
            FulltextIndexMaintainer::class,
            $manager->registered()['mysql-fulltext'] ?? null
        );
    }
}

/**
 * 테스트용 가짜 유지보수기 — 확장이 자체 엔진을 등록하는 경로를 대신한다.
 */
class FakeSearchIndexMaintainer implements SearchIndexMaintainer
{
    /** @var array<string, SearchIndexStatus> 식별자 => 판정 */
    public static array $statuses = [];

    /** @var array<int, string> rebuild() 가 호출된 식별자 */
    public static array $rebuilt = [];

    /** 재생성 후 상태가 healthy 로 바뀌는지 여부 */
    public static bool $healAfterRebuild = true;

    /** {@inheritDoc} */
    public function driver(): string
    {
        return 'acme-search';
    }

    /** {@inheritDoc} */
    public function isAvailable(): bool
    {
        return true;
    }

    /** {@inheritDoc} */
    public function unavailableReason(): ?string
    {
        return null;
    }

    /** {@inheritDoc} */
    public function inspect(array $filters = []): array
    {
        $results = [];

        foreach (self::$statuses as $identifier => $status) {
            if (self::$healAfterRebuild && in_array($identifier, self::$rebuilt, true)) {
                $status = SearchIndexStatus::Healthy;
            }

            $results[] = new SearchIndexHealth(
                driver: 'acme-search',
                identifier: $identifier,
                status: $status,
                measurement: 'fake',
                context: ['identifier' => $identifier],
            );
        }

        return $results;
    }

    /** {@inheritDoc} */
    public function rebuild(SearchIndexHealth $health): void
    {
        self::$rebuilt[] = $health->identifier;
    }
}
