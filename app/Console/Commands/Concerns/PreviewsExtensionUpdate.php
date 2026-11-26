<?php

namespace App\Console\Commands\Concerns;

use App\Console\Commands\Traits\ReportsUpdateCheckErrors;
use App\Enums\LayoutSyncMode;
use App\Extension\Layout\LayoutSyncOptions;
use App\Services\Extension\ExtensionUpdatePreviewService;
use Closure;
use Illuminate\Console\Command;

/**
 * `{module|plugin|template}:update` 의 미리보기(`--dry-run --json`)·화면별 결정(`--decisions`) 공통 트레이트.
 *
 * 미리보기는 관리자 [업데이트] 창과 같은 서비스({@see ExtensionUpdatePreviewService::preview()})를 부른다 — 레이아웃 판정과
 * 지워질 선언 항목(`declarative_deletions`)이 API 응답과 같다. 업데이트·DB 는 바꾸지 않는다.
 *
 * 쓰는 명령은 {@see ReportsUpdateCheckErrors} 도 함께 쓴다 — GitHub 조회 실패(`check_error`)를 같은 문구·종료 코드로 알린다.
 *
 * @mixin Command
 */
trait PreviewsExtensionUpdate
{
    use SelectsLayoutRefreshStrategy;

    /**
     * 업데이트를 미리 보고 결과를 출력합니다.
     *
     * @param  string  $type  확장 종류 (`module`·`plugin`·`template`)
     * @param  string  $identifier  확장 식별자
     * @param  string|null  $source  업데이트 소스 (`bundled`·`github`, null 이면 자동)
     * @param  string  $layoutStrategy  레이아웃 처리 방식 (`keep`·`overwrite`)
     * @return int 종료 코드 (미리보기는 바꾸지 않으므로 0, 결정 파일이 잘못되거나 `--source=github` 의 GitHub 조회가 실패하면 1)
     */
    protected function runUpdatePreview(string $type, string $identifier, ?string $source, string $layoutStrategy): int
    {
        $decisions = $this->layoutRefreshDecisions();

        if ($decisions === null) {
            return Command::FAILURE;
        }

        $preview = app(ExtensionUpdatePreviewService::class)->preview($type, $identifier, $source, LayoutSyncMode::from($layoutStrategy), $decisions);

        // GitHub 를 명시했는데 조회에 실패했으면 미리보기도 실패로 끝낸다 — 「업데이트 없음」으로 읽히지 않게
        $checkFailed = $source === 'github' && ($preview['check_error'] ?? null) !== null;

        if ($this->option('json')) {
            $this->line((string) json_encode(['dry_run' => true] + $preview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $checkFailed ? Command::FAILURE : Command::SUCCESS;
        }

        $this->info(__('extensions.commands.update.dry_run_header', [
            'identifier' => $identifier,
            'from' => (string) ($preview['from_version'] ?? '-'),
            'to' => (string) ($preview['to_version'] ?? '-'),
            'source' => (string) ($preview['update_source'] ?? '-'),
        ]));

        if ($this->reportUpdateCheckError($preview, $source, $identifier)) {
            return Command::FAILURE;
        }

        if (! $preview['update_available']) {
            $this->line(__('extensions.commands.update.dry_run_no_update'));

            return Command::SUCCESS;
        }

        if ($preview['layouts_skipped'] === 'inactive') {
            $this->line(__('extensions.commands.update.dry_run_layouts_skipped_inactive'));
        } elseif ($preview['layouts_skipped'] === 'files_missing') {
            $this->line(__('extensions.commands.update.dry_run_layouts_skipped_files_missing'));
        } else {
            $this->renderLayoutRefreshPreview($preview['layouts']);
        }

        $deletions = $preview['declarative_deletions'];

        if ($deletions !== []) {
            $this->newLine();
            $this->warn(__('extensions.commands.update.dry_run_deletions_header'));
            $this->table(
                __('extensions.commands.update.dry_run_deletions_columns'),
                array_map(fn (array $item) => [
                    __('extensions.declarative_deletion.type.'.($item['type'] ?? '')),
                    $item['key'] ?? '',
                    ($item['has_operator_overrides'] ?? false) ? __('extensions.commands.update.dry_run_operator_modified') : '',
                ], $deletions)
            );
        }

        if (! empty($preview['pending_token'])) {
            $this->line(__('extensions.commands.update.dry_run_pending_token', ['token' => $preview['pending_token']]));
        }

        return Command::SUCCESS;
    }

    /**
     * `--decisions` 파일의 화면별 결정을 걸고 업데이트를 실행합니다.
     *
     * @param  array<string, string>  $decisions  화면별 결정
     * @param  Closure  $update  업데이트 실행
     * @return mixed 업데이트 결과
     */
    protected function withLayoutDecisions(array $decisions, Closure $update): mixed
    {
        return $decisions === [] ? $update() : LayoutSyncOptions::run(new LayoutSyncOptions(false, $decisions), $update);
    }
}
