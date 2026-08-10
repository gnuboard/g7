<?php

namespace App\Console\Commands\Search;

use App\Console\Commands\Traits\HasUnifiedConfirm;
use App\Enums\SearchIndexStatus;
use App\Search\DTO\SearchIndexHealth;
use App\Search\SearchIndexMaintenanceManager;
use Illuminate\Console\Command;

/**
 * 검색 인덱스 점검·재생성 커맨드
 *
 * 인덱스는 있는데 내용이 색인되지 않아 검색이 조용히 0건을 돌려주는 상태를 검출하고,
 * 운영자가 선택하면 재생성합니다. 이 상태는 예외도 로그도 남기지 않으므로
 * "원래 검색이 안 되는 줄" 알고 지나가기 쉽습니다.
 *
 * 점검 방법은 **활성 Scout 엔진의 유지보수기**가 정합니다. 확장이 자체 검색 엔진을
 * 등록하면서 유지보수기를 함께 등록하면 이 커맨드가 그대로 그 엔진을 다룹니다
 * (`core.search.index_maintainers` 필터 훅).
 *
 * 사용 예시:
 *   php artisan search:index                       # 활성 엔진의 인덱스 점검 (읽기 전용)
 *   php artisan search:index --repair              # 색인 누락 인덱스 재생성
 *   php artisan search:index --filter=table=pages  # 엔진별 필터 전달
 *   php artisan search:index --json                # 기계 판독용 출력
 */
class SearchIndexCommand extends Command
{
    use HasUnifiedConfirm;

    protected $signature = 'search:index
        {--repair : 색인이 누락된 인덱스를 재생성 (미지정 시 점검만)}
        {--filter=* : 엔진별 필터 (key=value 형태, 다중 지정 가능. FULLTEXT: table, index, samples)}
        {--json : 기계 판독용 JSON 출력}';

    protected $description = '활성 검색 엔진의 인덱스가 실제로 내용을 색인하고 있는지 점검하고, 누락 시 재생성합니다';

    /**
     * 커맨드를 실행합니다.
     *
     * @param  SearchIndexMaintenanceManager  $manager  검색 인덱스 유지보수 진입점
     * @return int 종료 코드 (색인 누락 잔존 시 1)
     */
    public function handle(SearchIndexMaintenanceManager $manager): int
    {
        $driver = $manager->driver();
        $filters = $this->parseFilters();

        if (! $manager->hasMaintainer()) {
            $this->components->warn(__('search.index.no_maintainer', ['driver' => $driver]));

            return self::SUCCESS;
        }

        if (($reason = $manager->unavailableReason()) !== null) {
            $this->components->warn($reason);

            return self::SUCCESS;
        }

        $results = $manager->inspect($filters);

        if ($results === []) {
            $this->components->warn(__('search.index.no_targets', ['driver' => $driver]));

            return self::SUCCESS;
        }

        $report = null;

        if ($this->option('repair')) {
            $targets = array_values(array_filter($results, fn (SearchIndexHealth $h) => $h->needsRebuild()));

            if ($targets !== [] && $this->confirmRebuild($targets)) {
                $report = $manager->repairStale($filters);
                $results = $manager->inspect($filters);
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'driver' => $driver,
                'results' => array_map(fn (SearchIndexHealth $h) => $h->toArray(), $results),
                'repair' => $report?->toArray(),
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            $this->render($driver, $results, $report?->summary());
        }

        $remaining = array_filter($results, fn (SearchIndexHealth $h) => $h->needsRebuild());

        return ($remaining === [] && ($report === null || $report->failed === [])) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * `--filter=key=value` 옵션을 연관 배열로 해석합니다.
     *
     * @return array<string, string>
     */
    private function parseFilters(): array
    {
        $filters = [];

        foreach ((array) $this->option('filter') as $raw) {
            if (! is_string($raw) || ! str_contains($raw, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $raw, 2);
            $key = trim($key);

            if ($key !== '') {
                $filters[$key] = trim($value);
            }
        }

        return $filters;
    }

    /**
     * 재생성 대상을 보여 주고 진행 여부를 확인합니다.
     *
     * @param  array<int, SearchIndexHealth>  $targets  재생성 대상
     * @return bool 진행 여부
     */
    private function confirmRebuild(array $targets): bool
    {
        $this->newLine();
        $this->components->info(__('search.index.rebuild_targets', ['count' => count($targets)]));

        foreach ($targets as $target) {
            $this->line('  - '.$target->identifier.' ('.$target->measurement.')');
        }

        $this->components->warn(__('search.index.rebuild_cost_warning'));

        if ($this->unifiedConfirm(__('search.index.rebuild_confirm'), true)) {
            return true;
        }

        $this->components->info(__('search.index.rebuild_skipped'));

        return false;
    }

    /**
     * 판정 결과를 표로 출력합니다.
     *
     * @param  string  $driver  드라이버명
     * @param  array<int, SearchIndexHealth>  $results  판정 결과
     * @param  string|null  $repairSummary  재생성 요약 (수행했을 때만)
     * @return void
     */
    private function render(string $driver, array $results, ?string $repairSummary): void
    {
        $rows = array_map(fn (SearchIndexHealth $health) => [
            $health->identifier,
            '<fg='.$health->status->consoleColor().'>'.$health->status->label().'</>',
            $health->measurement,
        ], $results);

        $this->newLine();
        $this->line('  '.__('search.index.driver_label', ['driver' => $driver]));
        $this->table([__('search.index.col.index'), __('search.index.col.status'), __('search.index.col.measurement')], $rows);

        $counts = [];
        foreach (SearchIndexStatus::cases() as $case) {
            $counts[$case->value] = count(array_filter($results, fn (SearchIndexHealth $h) => $h->status === $case));
        }

        $this->line('  '.__('search.index.counts', [
            'healthy' => $counts[SearchIndexStatus::Healthy->value],
            'degraded' => $counts[SearchIndexStatus::Degraded->value],
            'stale' => $counts[SearchIndexStatus::Stale->value],
            'skipped' => $counts[SearchIndexStatus::Skipped->value],
            'total' => count($results),
        ]));

        if ($repairSummary !== null) {
            $this->newLine();
            $this->components->info($repairSummary);
        }

        if ($counts[SearchIndexStatus::Stale->value] > 0 && ! $this->option('repair')) {
            $this->newLine();
            $this->components->warn(__('search.index.stale_hint'));
        }

        if ($counts[SearchIndexStatus::Degraded->value] > 0) {
            $this->line('  <comment>'.__('search.index.degraded_hint').'</comment>');
        }
    }
}
