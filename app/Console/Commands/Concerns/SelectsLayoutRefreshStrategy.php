<?php

namespace App\Console\Commands\Concerns;

use App\Enums\LayoutSyncMode;
use App\Extension\Layout\LayoutSyncOptions;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * `{template|module|plugin}:refresh-layout` 의 레이아웃 갱신 전략·화면별 결정·미리보기 옵션 공통 트레이트.
 *
 * - `--layout-strategy=keep|overwrite`: 기본 keep(운영자가 고친 화면을 지킨다). 잘못된 값이면 실패.
 * - `--decisions=<json 파일>`: 충돌 화면마다 `take-theirs`(새 판)·`keep-ours`(운영자 판).
 * - `--dry-run --json`: 판정만 출력하고 DB 는 그대로 둔다.
 *
 * @mixin Command
 */
trait SelectsLayoutRefreshStrategy
{
    /**
     * `--layout-strategy` 옵션을 읽습니다 — 잘못된 값이면 오류를 출력하고 null.
     *
     * @return LayoutSyncMode|null 전략 (잘못된 값이면 null)
     */
    protected function layoutRefreshStrategy(): ?LayoutSyncMode
    {
        $raw = strtolower(trim((string) ($this->option('layout-strategy') ?? 'keep')));

        if ($raw === '') {
            return LayoutSyncMode::Keep;
        }

        if (! LayoutSyncMode::isValid($raw)) {
            $this->error(__('extensions.layout_sync.errors.invalid_strategy', ['value' => $raw, 'allowed' => implode(', ', LayoutSyncMode::values())]));

            return null;
        }

        return LayoutSyncMode::from($raw);
    }

    /**
     * `--decisions` 옵션의 결정 파일을 읽습니다 — 잘못되면 오류를 출력하고 null.
     *
     * @return array<string, string>|null 결정 (옵션이 없으면 빈 배열, 잘못되면 null)
     */
    protected function layoutRefreshDecisions(): ?array
    {
        if (! $this->hasOption('decisions')) {
            return [];
        }

        $path = $this->option('decisions');

        if ($path === null || trim((string) $path) === '') {
            return [];
        }

        try {
            return LayoutSyncOptions::decisionsFromFile((string) $path);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return null;
        }
    }

    /**
     * 미리보기(dry-run) 결과를 출력합니다 — `--json` 이면 JSON 한 줄, 아니면 판정 표.
     *
     * DB 를 바꾸지 않았으므로 충돌이 있어도 종료 0 이다(충돌 여부는 `has_conflicts` 로 낸다).
     *
     * @param  array<string, mixed>  $preview  `LayoutSyncReport::toPreviewArray()` 모양 (+ 선택 `declarative_deletions`)
     * @return int 종료 코드 (늘 0)
     */
    protected function renderLayoutRefreshPreview(array $preview): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(['dry_run' => true] + $preview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $this->info(__('extensions.layout_sync.cli.dry_run_header'));

        $entries = array_values(array_filter((array) ($preview['entries'] ?? []), fn ($entry) => ($entry['verdict'] ?? '') !== 'unchanged'));

        if ($entries !== []) {
            $this->table(
                __('extensions.layout_sync.cli.preview_columns'),
                array_map(fn (array $entry) => [
                    ($entry['template'] ?? '').' · '.($entry['name'] ?? ''),
                    __('extensions.layout_sync.cli.kind.'.($entry['kind'] ?? 'layout')),
                    __('extensions.layout_sync.verdict.'.($entry['verdict'] ?? '')),
                    count($entry['conflicts'] ?? []),
                    $entry['key'] ?? '',
                ], $entries)
            );
        }

        $this->renderLayoutRefreshCounts($preview);

        return Command::SUCCESS;
    }

    /**
     * 갱신 결과(또는 미리보기)의 판정별 개수와 충돌 화면 목록을 출력합니다.
     *
     * @param  array<string, mixed>|null  $preview  `LayoutSyncReport::toPreviewArray()` 모양
     * @return void 반환값 없음
     */
    protected function renderLayoutRefreshCounts(?array $preview): void
    {
        if (! is_array($preview)) {
            return;
        }

        $counts = array_filter((array) ($preview['summary'] ?? []), fn ($count) => (int) $count > 0);

        if ($counts !== []) {
            $this->table(
                __('extensions.layout_sync.cli.counts_columns'),
                array_map(fn (string $verdict, int $count) => [__('extensions.layout_sync.verdict.'.$verdict), $count], array_keys($counts), array_values($counts))
            );
        }

        $conflicts = (array) ($preview['conflict_screens'] ?? []);

        if ($conflicts !== []) {
            $this->warn(trans_choice('extensions.layout_sync.cli.conflict_screens', count($conflicts)));

            foreach ($conflicts as $screen) {
                $this->warn('  - '.$screen);
            }
        }
    }
}
