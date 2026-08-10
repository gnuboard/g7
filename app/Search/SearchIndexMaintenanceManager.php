<?php

namespace App\Search;

use App\Extension\HookManager;
use App\Search\Contracts\SearchIndexMaintainer;
use App\Search\DTO\SearchIndexHealth;
use App\Search\DTO\SearchIndexRepairReport;
use App\Search\Engines\Maintenance\FulltextIndexMaintainer;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 검색 인덱스 점검·재생성 진입점
 *
 * 활성 Scout 드라이버에 해당하는 `SearchIndexMaintainer` 를 찾아 위임합니다.
 * 코어는 어떤 엔진이 무엇을 어떻게 점검하는지 알지 못하며, 등급(`SearchIndexStatus`)만
 * 보고 재생성 대상을 고릅니다.
 *
 * ## 등록
 *
 * 기본 제공은 `mysql-fulltext` 하나이고, 확장은 필터 훅으로 자기 엔진의 유지보수기를
 * 추가합니다.
 *
 * ```php
 * HookManager::addFilter('core.search.index_maintainers', function (array $maintainers) {
 *     $maintainers['meilisearch'] = MeilisearchIndexMaintainer::class;
 *
 *     return $maintainers;
 * });
 * ```
 *
 * 유지보수기를 등록하지 않은 엔진은 점검 대상에서 빠질 뿐 검색은 그대로 동작합니다.
 * 그 경우 `hasMaintainer()` 가 false 이고, 화면·커맨드는 "이 엔진은 점검을 제공하지
 * 않는다" 를 명시합니다 — 점검 결과 0건과 구분되어야 하기 때문입니다.
 *
 * ## 재생성은 언제나 선택 사항이다
 *
 * 재생성 비용은 엔진에 따라 테이블 잠금(FULLTEXT)이나 전체 재색인(외부 엔진)입니다.
 * 운영 중인 사이트에서 확장 업데이트만으로 그 비용이 발생하면 안 되므로,
 * 이 클래스는 어떤 자동 트리거에도 연결하지 않고 호출자의 명시적 요청만 수행합니다.
 */
class SearchIndexMaintenanceManager
{
    /** 확장이 유지보수기를 등록하는 필터 훅 */
    public const MAINTAINERS_FILTER = 'core.search.index_maintainers';

    /** 해석된 유지보수기 캐시 (요청 수명) */
    private ?SearchIndexMaintainer $resolved = null;

    /** 해석을 시도했는지 여부 (없음도 캐시하기 위함) */
    private bool $resolvedAttempted = false;

    /**
     * 현재 활성 Scout 드라이버명을 반환합니다.
     *
     * @return string 드라이버명
     */
    public function driver(): string
    {
        return (string) config('scout.driver', 'mysql-fulltext');
    }

    /**
     * 등록된 유지보수기 맵을 반환합니다.
     *
     * @return array<string, class-string<SearchIndexMaintainer>> 드라이버명 => 구현 클래스
     */
    public function registered(): array
    {
        $maintainers = [
            'mysql-fulltext' => FulltextIndexMaintainer::class,
        ];

        $maintainers = HookManager::applyFilters(self::MAINTAINERS_FILTER, $maintainers);

        return is_array($maintainers) ? $maintainers : [];
    }

    /**
     * 활성 드라이버의 유지보수기를 반환합니다.
     *
     * @return SearchIndexMaintainer|null 등록되지 않았으면 null
     */
    public function maintainer(): ?SearchIndexMaintainer
    {
        if ($this->resolvedAttempted) {
            return $this->resolved;
        }

        $this->resolvedAttempted = true;
        $class = $this->registered()[$this->driver()] ?? null;

        if ($class === null) {
            return $this->resolved = null;
        }

        try {
            $instance = app($class);
        } catch (Throwable $e) {
            Log::warning('검색 인덱스 유지보수기 생성 실패', [
                'driver' => $this->driver(),
                'class' => $class,
                'error' => $e->getMessage(),
            ]);

            return $this->resolved = null;
        }

        if (! $instance instanceof SearchIndexMaintainer) {
            Log::warning('검색 인덱스 유지보수기가 계약을 구현하지 않음', [
                'driver' => $this->driver(),
                'class' => $class,
            ]);

            return $this->resolved = null;
        }

        return $this->resolved = $instance;
    }

    /**
     * 활성 엔진이 점검을 제공하는지 여부.
     *
     * @return bool
     */
    public function hasMaintainer(): bool
    {
        return $this->maintainer() !== null;
    }

    /**
     * 활성 엔진의 인덱스를 점검합니다.
     *
     * @param  array<string, mixed>  $filters  엔진별 필터
     * @return array<int, SearchIndexHealth>
     */
    public function inspect(array $filters = []): array
    {
        $maintainer = $this->maintainer();

        if ($maintainer === null || ! $maintainer->isAvailable()) {
            return [];
        }

        return $maintainer->inspect($filters);
    }

    /**
     * 점검 불가 사유를 반환합니다.
     *
     * @return string|null 점검 가능하면 null
     */
    public function unavailableReason(): ?string
    {
        $maintainer = $this->maintainer();

        if ($maintainer === null) {
            return __('search.index.no_maintainer', ['driver' => $this->driver()]);
        }

        return $maintainer->isAvailable() ? null : $maintainer->unavailableReason();
    }

    /**
     * 색인이 누락된 인덱스를 재생성합니다.
     *
     * 재생성 후 **다시 점검해서** 잔존 여부를 담습니다 — 재생성했다는 사실만으로
     * 복구됐다고 단정하지 않습니다.
     *
     * @param  array<string, mixed>  $filters  엔진별 필터
     * @return SearchIndexRepairReport 실행 보고
     */
    public function repairStale(array $filters = []): SearchIndexRepairReport
    {
        $maintainer = $this->maintainer();

        if ($maintainer === null) {
            return SearchIndexRepairReport::unavailable($this->driver(), $this->unavailableReason());
        }

        if (! $maintainer->isAvailable()) {
            return SearchIndexRepairReport::unavailable($this->driver(), $maintainer->unavailableReason());
        }

        $before = $maintainer->inspect($filters);
        $targets = array_values(array_filter($before, fn (SearchIndexHealth $h) => $h->needsRebuild()));

        if ($targets === []) {
            return SearchIndexRepairReport::nothingToDo($this->driver(), count($before));
        }

        $repaired = [];
        $failed = [];

        foreach ($targets as $target) {
            try {
                $maintainer->rebuild($target);
                $repaired[] = $target->identifier;
            } catch (Throwable $e) {
                $failed[$target->identifier] = $e->getMessage();
                Log::error('검색 인덱스 재생성 실패', [
                    'driver' => $this->driver(),
                    'index' => $target->identifier,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $after = $maintainer->inspect($filters);
        $remaining = array_values(array_map(
            fn (SearchIndexHealth $h) => $h->identifier,
            array_filter($after, fn (SearchIndexHealth $h) => $h->needsRebuild())
        ));

        Log::info('검색 인덱스 재생성 수행', [
            'driver' => $this->driver(),
            'inspected' => count($after),
            'repaired' => $repaired,
            'failed' => array_keys($failed),
            'remaining' => $remaining,
        ]);

        return new SearchIndexRepairReport(
            driver: $this->driver(),
            inspected: count($after),
            repaired: $repaired,
            failed: $failed,
            remaining: $remaining,
        );
    }
}
