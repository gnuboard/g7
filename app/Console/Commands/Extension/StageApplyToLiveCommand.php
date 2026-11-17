<?php

namespace App\Console\Commands\Extension;

use App\Console\Commands\Traits\HasUnifiedConfirm;
use App\Console\Commands\Traits\LocalizesDescription;
use App\Console\Helpers\ArtisanCommandRunner;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Support\RuntimeRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * 운영 서버에서, DB·캐시를 함께 쓰는 검수 서버가 먼저 반영한 확장 판을 이 서버에 마무리합니다 (`stage:apply-to-live`).
 *
 * 검수 서버가 새 코드로 확장을 업데이트하면 공유 DB 의 판 번호가 먼저 새 판이 되어, 운영 서버의 `*:update`·`language-pack:update` 는
 * 「업데이트 없음/이미 최신」으로 끝난다. 그래서 운영 서버는 업데이트가 하던 나머지 일을 따로 해야 한다. 이 명령이 그 순서다.
 *
 * 1. 파일 맞추기 — `extension:reset-files-from-bundled`(모듈·플러그인·템플릿·언어팩 전부). 이 서버의 번들 폴더 → 활성 폴더.
 * 2. 레이아웃 갱신(keep) — 켜진 템플릿(사용자 → 관리자 순) 의 `template:refresh-layout`, 레이아웃을 가진 켜진 모듈·플러그인의
 *    `module|plugin:refresh-layout`. 이 서버 역할의 레이아웃 표에 쓴다. 관리자 템플릿 갱신이 설치된 템플릿의 설정 화면도 다시 등록한다.
 * 3. 미뤄 둔 정리 실행 — `extension:apply-deferred-cleanup`.
 *
 * 방향은 늘 「이 서버의 번들 파일 → 이 서버의 활성 폴더·레이아웃 표」 다. 검수 서버의 데이터(검수 서버에서 고친 레이아웃 등)를 운영으로
 * 옮기는 명령이 아니며, 그런 기능은 없다. 실행 전에(`--yes` 여도) 방향과 단계를 출력한다. 한 단계가 실패하면 그 단계에서 멈추고 1 로
 * 끝난다(단계 안의 다른 확장은 끝까지 처리해 결과를 보인다). 코어가 바뀌었으면 이 명령 전에 운영 서버에서 `core:update` 를 먼저 한다.
 * 검수 서버에서는 실행하지 않는다(종료 1).
 *
 * @since 7.2.0
 */
class StageApplyToLiveCommand extends Command
{
    use HasUnifiedConfirm;
    use LocalizesDescription;

    /**
     * @var string 명령 시그니처
     */
    protected $signature = 'stage:apply-to-live
        {--dry-run : 각 단계를 미리보기로만 실행 (파일·DB 를 바꾸지 않음)}
        {--json : 결과를 JSON 으로 출력}
        {--yes : 확인 질문에 「예」로 답하고 진행 (번들 파일 → 이 서버 활성 폴더·레이아웃 표. 검수 서버 데이터는 옮기지 않음)}';

    /**
     * @var string 명령 설명 (LocalizesDescription 이 다국어로 바꾼다)
     */
    protected $description = 'common.console.stage_apply.description';

    /**
     * 단계별 결과 (출력용).
     *
     * @var array<int, array{step: string, status: string, exit_code: int|null, items: array<int, array<string, mixed>>}>
     */
    private array $steps = [];

    /**
     * 세 단계를 차례로 실행합니다.
     *
     * @param  ArtisanCommandRunner  $runner  안쪽 명령 실행기
     * @param  TemplateRepositoryInterface  $templates  템플릿 저장소
     * @param  ModuleRepositoryInterface  $modules  모듈 저장소
     * @param  PluginRepositoryInterface  $plugins  플러그인 저장소
     * @return int 종료 코드 — 검수 서버·확인 불가·단계 실패면 1
     */
    public function handle(ArtisanCommandRunner $runner, TemplateRepositoryInterface $templates, ModuleRepositoryInterface $modules, PluginRepositoryInterface $plugins): int
    {
        $json = (bool) $this->option('json');
        $dryRun = (bool) $this->option('dry-run');
        $this->steps = [];

        if (RuntimeRole::isStage()) {
            return $this->refuse(__('common.console.stage_apply.stage_refused'), 'stage_role');
        }

        // 방향·단계는 확인 질문보다 먼저, `--yes` 여도 출력한다 — 확인을 건너뛰는 실행에서 방향을 알려 줄 문구가 이것뿐이다
        if (! $json) {
            $this->warn(__('common.console.stage_apply.direction'));
            $this->line(__('common.console.stage_apply.not_copy'));
            $this->line(__('common.console.stage_apply.core_first'));
            $this->line(__('common.console.stage_apply.steps_header'));
            foreach (['files', 'layouts', 'cleanup'] as $i => $step) {
                $this->line('  '.($i + 1).'. '.__('common.console.stage_apply.step.'.$step));
            }
            if ($dryRun) {
                $this->line(__('common.console.stage_apply.dry_run_notice'));
            }
        }

        if (! $dryRun) {
            if (! $this->canAnswerConfirmation()) {
                if ($json) {
                    return $this->refuse(__('common.console.stage_apply.confirm_unavailable'), 'confirm_unavailable');
                }

                return $this->refuseUnanswerableConfirmation(
                    __('common.console.stage_apply.confirm_unavailable'),
                    __('common.console.stage_apply.confirm_hint'),
                );
            }
            if (! $this->unifiedConfirm(__('common.console.stage_apply.confirm_question'), false)) {
                $this->info(__('common.console.stage_apply.aborted'));

                return self::SUCCESS;
            }
        }

        $dryOption = $dryRun ? ['--dry-run' => true] : [];

        // 1. 파일 맞추기 — 전 종류(언어팩 포함). 결과는 이 저장소의 명령 JSON 이다
        $files = $runner->run('extension:reset-files-from-bundled', ['--json' => true] + $dryOption);
        $decoded = $this->decodeJson($files['output']);
        $fileItems = array_map(fn (array $row) => [
            'type' => $row['type'] ?? null,
            'identifier' => $row['identifier'] ?? null,
            'status' => $row['status'] ?? null,
        ], array_values(array_filter((array) ($decoded['items'] ?? []), fn ($row) => is_array($row) && ($row['status'] ?? '') !== 'in_sync')));
        if (! $this->record('files', $files['exit_code'], $fileItems, $files['output'])) {
            return $this->finish($dryRun, 'files');
        }

        // 2. 레이아웃 갱신(keep) — 사용자 템플릿 → 관리자 템플릿(설치된 템플릿 설정 화면 재등록) → 모듈 → 플러그인
        $layoutItems = [];
        $layoutFailed = false;
        foreach ($this->layoutTargets($templates, $modules, $plugins) as [$type, $identifier]) {
            // 1단계가 활성 폴더를 바꿨다 — 이 프로세스가 불러 둔 옛 확장 코드가 아니라 새 파일로 갱신하도록 새 프로세스로 돌린다
            $result = $runner->runFresh("{$type}:refresh-layout", ['identifier' => $identifier, '--layout-strategy' => 'keep', '--json' => true] + $dryOption);
            $ok = $result['exit_code'] === 0;
            $layoutFailed = $layoutFailed || ! $ok;
            $layoutItems[] = ['type' => $type, 'identifier' => $identifier, 'status' => $ok ? 'ok' : 'failed', 'exit_code' => $result['exit_code']]
                + ($ok ? [] : ['output' => trim($result['output'])]);
        }
        if (! $this->record('layouts', $layoutFailed ? self::FAILURE : self::SUCCESS, $layoutItems, '')) {
            return $this->finish($dryRun, 'layouts');
        }

        // 3. 미뤄 둔 정리 실행 — 종료 코드만 본다(0 성공, 1 실패·대기)
        // 지울 대상을 새 파일의 선언으로 다시 계산해야 한다 — 같은 프로세스의 옛 선언으로 계산하면 새 판이 추가한 항목을 지운다
        $cleanup = $runner->runFresh('extension:apply-deferred-cleanup', ['--json' => true] + $dryOption);
        if (! $this->record('cleanup', $cleanup['exit_code'], [], $cleanup['output'])) {
            return $this->finish($dryRun, 'cleanup');
        }

        return $this->finish($dryRun, null);
    }

    /**
     * 레이아웃 갱신 대상 — 켜진 템플릿(사용자 → 관리자), 레이아웃·조각 폴더가 있는 켜진 모듈·플러그인.
     *
     * 관리자 템플릿을 뒤에 둔다 — 관리자 템플릿 갱신이 설치된 템플릿 전부의 설정 화면을 다시 등록하므로 마지막 등록이 그 판정이 된다.
     *
     * @param  TemplateRepositoryInterface  $templates  템플릿 저장소
     * @param  ModuleRepositoryInterface  $modules  모듈 저장소
     * @param  PluginRepositoryInterface  $plugins  플러그인 저장소
     * @return array<int, array{0: string, 1: string}> [종류, 식별자] 목록
     */
    private function layoutTargets(TemplateRepositoryInterface $templates, ModuleRepositoryInterface $modules, PluginRepositoryInterface $plugins): array
    {
        $targets = [];
        $active = $templates->getActive()->sortBy('identifier');
        foreach ($active->where('type', '!=', 'admin') as $row) {
            $targets[] = ['template', (string) $row->identifier];
        }
        foreach ($active->where('type', 'admin') as $row) {
            $targets[] = ['template', (string) $row->identifier];
        }

        foreach (['module' => $modules, 'plugin' => $plugins] as $type => $repository) {
            foreach ($repository->getActive()->sortBy('identifier') as $row) {
                if ($this->hasLayouts($type, (string) $row->identifier)) {
                    $targets[] = [$type, (string) $row->identifier];
                }
            }
        }

        return $targets;
    }

    /**
     * 모듈·플러그인이 레이아웃이나 조각을 가졌는지 — 이 서버의 활성 폴더 기준(1단계가 맞춘 뒤의 파일, 미리보기는 지금 파일).
     *
     * @param  string  $type  종류 (module|plugin)
     * @param  string  $identifier  식별자
     * @return bool 가졌으면 true
     */
    private function hasLayouts(string $type, string $identifier): bool
    {
        $root = base_path("{$type}s/{$identifier}");

        return File::isDirectory($root.'/resources/layouts') || File::isDirectory($root.'/resources/extensions');
    }

    /**
     * 단계 결과를 남기고 계속할지 돌려줍니다.
     *
     * @param  string  $step  단계 (files|layouts|cleanup)
     * @param  int  $exitCode  종료 코드
     * @param  array<int, array<string, mixed>>  $items  항목
     * @param  string  $output  안쪽 명령 출력 (실패 때 보인다)
     * @return bool 성공이면 true
     */
    private function record(string $step, int $exitCode, array $items, string $output): bool
    {
        $ok = $exitCode === 0;
        $this->steps[] = ['step' => $step, 'status' => $ok ? 'ok' : 'failed', 'exit_code' => $exitCode, 'items' => $items]
            + ($ok || trim($output) === '' ? [] : ['output' => trim($output)]);

        return $ok;
    }

    /**
     * 결과를 출력하고 종료 코드를 돌려줍니다.
     *
     * @param  bool  $dryRun  미리보기였는지
     * @param  string|null  $failedStep  실패한 단계 (없으면 null)
     * @return int 종료 코드
     */
    private function finish(bool $dryRun, ?string $failedStep): int
    {
        $exitCode = $failedStep === null ? self::SUCCESS : self::FAILURE;

        // 실행하지 않은 단계도 표에 남긴다 — 어디서 멈췄는지 보이게
        $done = array_column($this->steps, 'step');
        foreach (['files', 'layouts', 'cleanup'] as $step) {
            if (! in_array($step, $done, true)) {
                $this->steps[] = ['step' => $step, 'status' => 'skipped', 'exit_code' => null, 'items' => []];
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(['dry_run' => $dryRun, 'failed_step' => $failedStep, 'steps' => $this->steps], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $exitCode;
        }

        $this->table(
            [__('common.console.stage_apply.col_step'), __('common.console.stage_apply.col_status'), __('common.console.stage_apply.col_detail')],
            array_map(fn (array $row) => [
                __('common.console.stage_apply.step.'.$row['step']),
                __('common.console.stage_apply.status.'.$row['status']),
                $this->detail($row),
            ], $this->steps),
        );

        foreach ($this->steps as $row) {
            foreach ($row['items'] as $item) {
                if (($item['status'] ?? '') === 'version_differs') {
                    $this->line(__('common.console.stage_apply.version_differs_hint', ['type' => $item['type'], 'identifier' => $item['identifier']]));
                }
                if (($item['status'] ?? '') === 'failed' && isset($item['output'])) {
                    $this->error("  {$item['type']}:{$item['identifier']} — {$item['output']}");
                }
            }
            if ($row['status'] === 'failed' && isset($row['output'])) {
                $this->line($row['output']);
            }
        }

        if ($failedStep === null) {
            $this->info(__($dryRun ? 'common.console.stage_apply.dry_run_done' : 'common.console.stage_apply.done'));
        } else {
            $this->error(__('common.console.stage_apply.stopped', ['step' => __('common.console.stage_apply.step.'.$failedStep)]));
            if ($failedStep === 'cleanup') {
                $this->line(__('common.console.stage_apply.cleanup_failed_hint'));
            }
        }

        return $exitCode;
    }

    /**
     * 표의 상세 칸.
     *
     * @param  array{step: string, status: string, exit_code: int|null, items: array<int, array<string, mixed>>}  $row  단계 결과
     * @return string 상세
     */
    private function detail(array $row): string
    {
        if ($row['status'] === 'skipped') {
            return '';
        }

        return match ($row['step']) {
            'files' => __('common.console.stage_apply.detail.files', [
                'differs' => count(array_filter($row['items'], fn ($i) => in_array($i['status'] ?? '', ['differs', 'synced'], true))),
                'other' => count(array_filter($row['items'], fn ($i) => ! in_array($i['status'] ?? '', ['differs', 'synced'], true))),
            ]),
            'layouts' => __('common.console.stage_apply.detail.layouts', [
                'ok' => count(array_filter($row['items'], fn ($i) => ($i['status'] ?? '') === 'ok')),
                'failed' => count(array_filter($row['items'], fn ($i) => ($i['status'] ?? '') === 'failed')),
            ]),
            default => __('common.console.stage_apply.detail.exit_code', ['code' => (string) $row['exit_code']]),
        };
    }

    /**
     * 실행하지 않고 끝냅니다.
     *
     * @param  string  $message  사유
     * @param  string  $code  기계용 사유 (`--json`)
     * @return int Command::FAILURE
     */
    private function refuse(string $message, string $code): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(['error' => $code, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }

    /**
     * 안쪽 명령 출력의 JSON 을 읽습니다 (앞에 섞인 줄은 건너뜀).
     *
     * @param  string  $output  출력
     * @return array<string, mixed> 결과 (읽지 못하면 빈 배열)
     */
    private function decodeJson(string $output): array
    {
        $start = strpos($output, '{');
        if ($start === false) {
            return [];
        }
        $decoded = json_decode(substr($output, $start), true);

        return is_array($decoded) ? $decoded : [];
    }
}
