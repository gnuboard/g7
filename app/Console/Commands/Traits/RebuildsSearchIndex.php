<?php

namespace App\Console\Commands\Traits;

use App\Search\DTO\SearchIndexHealth;
use App\Search\DTO\SearchIndexRepairReport;
use App\Search\SearchIndexMaintenanceManager;

/**
 * 설치·업데이트 커맨드에 "검색 인덱스 재생성" 선택 옵션을 제공하는 트레이트.
 *
 * 재생성 비용은 엔진마다 다르지만(테이블 잠금 / 전체 재색인) 어느 쪽이든 운영 중인
 * 사이트에 영향을 줍니다. 그래서 **기본값은 재생성하지 않음**이고, 운영자가
 * `--rebuild-search-index` 로 명시했을 때만 수행합니다.
 *
 * 옵션을 주지 않아도 점검 결과는 안내합니다 — 색인이 누락되면 검색이 오류 없이 0건을
 * 돌려주므로, 알려주지 않으면 운영자가 알 방법이 없습니다.
 */
trait RebuildsSearchIndex
{
    /**
     * 커맨드 `$signature` 에 붙일 옵션 정의.
     *
     * @return string 옵션 시그니처 조각
     */
    public static function rebuildSearchIndexOption(): string
    {
        return '{--rebuild-search-index : 완료 후 색인이 누락된 검색 인덱스를 재생성 (인덱스가 잠기거나 재색인됩니다 — 운영 중에는 점검 결과만 확인하고 유지보수 시간에 수행하세요)}';
    }

    /**
     * 작업 완료 후 검색 인덱스를 점검하고, 요청이 있었으면 재생성합니다.
     *
     * @param  bool|null  $requested  재생성 요청 여부 (null 이면 커맨드 옵션에서 읽음)
     * @return SearchIndexRepairReport|null 재생성 보고 (재생성을 하지 않았으면 null)
     */
    protected function handleSearchIndexRebuild(?bool $requested = null): ?SearchIndexRepairReport
    {
        $manager = app(SearchIndexMaintenanceManager::class);

        // 점검을 제공하지 않는 엔진에서는 조용히 넘어간다 (검색 자체는 정상 동작)
        if (! $manager->hasMaintainer() || $manager->unavailableReason() !== null) {
            return null;
        }

        $requested ??= (bool) $this->option('rebuild-search-index');

        if ($requested) {
            $report = $manager->repairStale();
            $this->reportRebuild($report);

            return $report;
        }

        $this->warnStale($manager->inspect());

        return null;
    }

    /**
     * 재생성하지 않은 경우, 색인 누락 사실만 안내합니다.
     *
     * @param  array<int, SearchIndexHealth>  $results  점검 결과
     * @return void
     */
    private function warnStale(array $results): void
    {
        $stale = array_values(array_filter($results, fn (SearchIndexHealth $h) => $h->needsRebuild()));

        if ($stale === []) {
            return;
        }

        $this->newLine();
        $this->warn('⚠️  '.__('search.index.stale_after_update', ['count' => count($stale)]));

        foreach ($stale as $health) {
            $this->line('   - '.$health->identifier);
        }

        $this->line('   '.__('search.index.stale_hint'));
    }

    /**
     * 재생성 보고를 콘솔에 출력합니다.
     *
     * @param  SearchIndexRepairReport  $report  재생성 보고
     * @return void
     */
    private function reportRebuild(SearchIndexRepairReport $report): void
    {
        $this->newLine();

        if (! $report->didRebuild()) {
            $this->info('✅ '.$report->summary());

            return;
        }

        $this->info('✅ '.$report->summary());

        foreach ($report->repaired as $identifier) {
            $this->line('   '.__('search.index.rebuilt_item', ['index' => $identifier]));
        }

        foreach ($report->failed as $identifier => $message) {
            $this->error('   '.__('search.index.rebuild_failed_item', ['index' => $identifier, 'error' => $message]));
        }

        foreach ($report->remaining as $identifier) {
            $this->warn('   '.__('search.index.still_stale_item', ['index' => $identifier]));
        }
    }
}
