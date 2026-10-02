<?php

namespace App\Console\Commands\Traits;

use App\Extension\Layout\LayoutSyncSummary;

/**
 * 확장 업데이트 커맨드가 레이아웃 처리 결과를 콘솔에 보여 주는 공통 부분 (#727 D2).
 *
 * 운영자가 고친 화면이 있으면 표(화면·종류·결과·자동 반영·겹침)와 요약 한 줄을, 덮어쓰기 전략이면 업데이트 전에
 * 덮어쓰일 수정본 목록(레이아웃 + 확장 조각)을 경고로 낸다.
 */
trait RendersLayoutSyncResult
{
    /**
     * 업데이트 결과의 레이아웃 처리 표와 요약을 출력합니다.
     *
     * @param  array<string, mixed>|null  $layoutSync  보고 (`LayoutSyncReport::toArray()`)
     * @return void 반환값 없음
     */
    protected function renderLayoutSyncResult(?array $layoutSync): void
    {
        $summary = LayoutSyncSummary::text($layoutSync);

        if ($summary === null) {
            return;
        }

        $entries = $layoutSync['entries'] ?? [];

        if ($entries !== []) {
            $this->newLine();
            $this->info(__('extensions.layout_sync.cli.header'));
            $this->table(
                __('extensions.layout_sync.cli.columns'),
                array_map(fn (array $entry) => [
                    ($entry['template'] ?? '').' · '.($entry['name'] ?? ''),
                    __('extensions.layout_sync.cli.kind.'.($entry['kind'] ?? 'layout')),
                    __('extensions.layout_sync.verdict.'.($entry['verdict'] ?? '')),
                    count($entry['applied'] ?? []),
                    count($entry['conflicts'] ?? []),
                ], $entries)
            );
        }

        $this->info($summary);
    }

    /**
     * 7.0 JSON 레이아웃의 깨진 조각 참조를 빈 자리로 등록한 경고를 건별로 출력합니다 (#712).
     *
     * 설치·활성화·업데이트·갱신은 그 조각 참조 때문에 멈추지 않는다 — 대신 어느 파일의 어느 조각이 빠졌는지 알린다.
     *
     * @param  mixed  $warnings  경고 목록 (`layout_warnings` — `{file, partial, reason, message}` 원소)
     * @return void 반환값 없음
     */
    protected function renderLayoutIncludeWarnings(mixed $warnings): void
    {
        if (! is_array($warnings) || $warnings === []) {
            return;
        }

        $this->newLine();

        foreach ($warnings as $warning) {
            $this->warn('⚠️  '.(is_array($warning) ? ($warning['message'] ?? '') : (string) $warning));
        }
    }

    /**
     * 덮어쓰기 전략일 때 덮어쓰일 운영자 수정본(레이아웃 + 확장 조각)을 경고합니다.
     *
     * @param  array{modified_count?: int, modified_layouts?: list<array{name: string, updated_at?: string|null}>}  $modified  `hasModifiedLayouts()` 응답
     * @param  string  $domain  번역 도메인 (`modules`·`plugins`·`templates`)
     * @return void 반환값 없음
     */
    protected function warnModifiedLayoutsBeforeOverwrite(array $modified, string $domain): void
    {
        if (($modified['modified_count'] ?? 0) === 0) {
            return;
        }

        $this->newLine();
        $this->warn('⚠️  '.__($domain.'.commands.update.modified_layouts_warning', ['count' => $modified['modified_count']]));

        foreach ($modified['modified_layouts'] ?? [] as $item) {
            $this->warn(__($domain.'.commands.update.modified_layout_item', ['name' => $item['name'], 'date' => $item['updated_at'] ?? '']));
        }
    }

    /**
     * 확장 하나의 레이아웃 처리 요약을 한 줄로 출력합니다 — core:update 는 확장마다 요약만, 표는 개별 update 커맨드가 낸다.
     *
     * @param  mixed  $result  확장 업데이트 결과
     * @return void 반환값 없음
     */
    protected function lineLayoutSyncSummary(mixed $result): void
    {
        $summary = is_array($result) ? LayoutSyncSummary::text($result['layout_sync'] ?? null) : null;

        if ($summary !== null) {
            $this->line('  '.$summary);
        }
    }
}
