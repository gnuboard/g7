<?php

namespace App\Console\Commands\Extension;

use App\Console\Commands\Traits\LocalizesDescription;
use App\Exceptions\ExtensionOperationLockException;
use App\Services\Extension\ExtensionFileSyncService;
use Illuminate\Console\Command;

/**
 * 같은 판인데 파일이 다른 확장의 활성 폴더를 번들 폴더 내용으로 덮어씁니다 (`extension:reset-files-from-bundled`).
 *
 * 방향은 늘 번들 폴더 → 활성 폴더다(운영자 `custom/` 은 보존). 활성 폴더를 직접 고친 내용은 사라진다 — 이름에 방향(`from-bundled`)과
 * 파괴성(`reset`)을 넣고, 사람이 읽는 출력은 바꾸기 전에 방향과 교체 대상 수를 늘 먼저 알린다(`--json` 출력에는 섞지 않는다).
 * 판 번호·업그레이드 스텝·레이아웃 본문은 건드리지 않는다(비어 있는 레이아웃 병합 기준만 교체 전 파일로 채운다). 판이 다른 확장은
 * 대상이 아니다(업데이트 명령을 안내한다). 언어팩(`--type=language-pack`, `lang-packs/<id>`)도 대상이다.
 * 종전 이름 `extension:sync-files` 는 하위 호환으로 남긴다({@see SyncExtensionFilesCommand}).
 *
 * @since 7.2.0
 */
class ResetExtensionFilesFromBundledCommand extends Command
{
    use LocalizesDescription;

    /**
     * @var string 명령 시그니처
     */
    protected $signature = 'extension:reset-files-from-bundled
        {identifier? : 대상 확장 식별자 (생략하면 설치된 확장 전부를 판정)}
        {--type= : 종류 (module|plugin|template|language-pack)}
        {--migrate : 교체 뒤 그 확장의 미실행 마이그레이션만 실행}
        {--dry-run : 판정만 출력하고 파일을 바꾸지 않음}
        {--json : 결과를 JSON 으로 출력}';

    /**
     * @var string 명령 설명 (LocalizesDescription 이 다국어로 바꾼다)
     */
    protected $description = 'extensions.commands.sync_files.description';

    /**
     * 판정하고(필요하면) 교체합니다.
     *
     * @param  ExtensionFileSyncService  $service  파일 동기화 서비스
     * @return int 종료 코드 — 지정한 확장이 대상이 아니거나 교체에 실패하면 1
     */
    public function handle(ExtensionFileSyncService $service): int
    {
        $type = $this->option('type') !== null ? (string) $this->option('type') : null;
        if ($type !== null && ! in_array($type, ExtensionFileSyncService::TYPES, true)) {
            $this->error(__('extensions.commands.sync_files.invalid_type', ['type' => $type]));

            return self::INVALID;
        }

        $identifier = $this->argument('identifier') !== null ? (string) $this->argument('identifier') : null;
        $dryRun = (bool) $this->option('dry-run');
        $plan = $service->plan($type, $identifier);

        if ($identifier !== null && $plan === []) {
            return $this->finish(['dry_run' => $dryRun, 'items' => [], 'synced' => 0, 'failed' => 1], __('extensions.commands.sync_files.not_installed', ['identifier' => $identifier]), self::FAILURE);
        }

        // 바꾸기 전에 방향과 교체 대상 수를 알린다 — 확인 질문이 없는 명령이라 사람이 읽는 출력에서 방향을 알려 줄 곳이 이것뿐이다
        if (! $this->option('json')) {
            $this->warn(__('extensions.commands.sync_files.direction'));
            $this->warn(__('extensions.commands.sync_files.targets', ['count' => count(array_filter($plan, fn ($r) => $r['status'] === 'differs'))]));
        }

        $synced = 0;
        $failed = 0;
        foreach ($plan as $i => $row) {
            if ($row['status'] !== 'differs' || $dryRun) {
                continue;
            }
            try {
                $result = $service->sync($row['type'], $row['identifier'], (bool) $this->option('migrate'));
                $plan[$i]['status'] = 'synced';
                $plan[$i]['migrated'] = $result['migrated'];
                $plan[$i]['layout_bases_filled'] = $result['layout_bases_filled'];
                $synced++;
            } catch (ExtensionOperationLockException $e) {
                $plan[$i]['status'] = 'locked';
                $plan[$i]['error'] = $e->getMessage();
                $failed++;
            } catch (\Throwable $e) {
                $plan[$i]['status'] = 'failed';
                $plan[$i]['error'] = $e->getMessage();
                $failed++;
            }
        }

        // 확장 하나를 지정했는데 교체 대상이 아니면(판이 다름·번들 없음) 요청한 일을 하지 않은 것이다 — 성공으로 끝내지 않는다
        $notTarget = $identifier !== null && count($plan) === 1 && in_array($plan[0]['status'], ['version_differs', 'no_bundled'], true);

        return $this->finish(
            ['dry_run' => $dryRun, 'items' => $plan, 'synced' => $synced, 'failed' => $failed],
            null,
            ($failed > 0 || $notTarget) ? self::FAILURE : self::SUCCESS,
        );
    }

    /**
     * 결과를 출력하고 종료 코드를 돌려줍니다.
     *
     * @param  array{dry_run: bool, items: array<int, array<string, mixed>>, synced: int, failed: int}  $result  결과
     * @param  string|null  $error  오류 문장
     * @param  int  $exitCode  종료 코드
     * @return int 종료 코드
     */
    private function finish(array $result, ?string $error, int $exitCode): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($result + ($error !== null ? ['error' => $error] : []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $exitCode;
        }

        if ($error !== null) {
            $this->error('❌ '.$error);

            return $exitCode;
        }

        $this->table(
            [__('extensions.commands.sync_files.col_type'), __('extensions.commands.sync_files.col_identifier'), __('extensions.commands.sync_files.col_version'), __('extensions.commands.sync_files.col_status')],
            array_map(fn (array $row) => [
                $row['type'],
                $row['identifier'],
                $row['installed_version'].($row['bundled_version'] !== null && $row['bundled_version'] !== $row['installed_version'] ? ' → '.$row['bundled_version'] : ''),
                __('extensions.commands.sync_files.status.'.$row['status']),
            ], $result['items']),
        );

        foreach ($result['items'] as $row) {
            if ($row['status'] === 'version_differs') {
                $this->line(__('extensions.commands.sync_files.update_hint', ['command' => "php artisan {$row['type']}:update {$row['identifier']}"]));
            }
            if (isset($row['error'])) {
                $this->error("  {$row['identifier']}: {$row['error']}");
            }
        }

        $this->info($result['dry_run']
            ? __('extensions.commands.sync_files.dry_run_summary', ['count' => count(array_filter($result['items'], fn ($r) => $r['status'] === 'differs'))])
            : __('extensions.commands.sync_files.summary', ['synced' => $result['synced'], 'failed' => $result['failed']]));

        return $exitCode;
    }
}
