<?php

namespace App\Console\Commands\Extension;

use App\Console\Commands\Traits\LocalizesDescription;
use App\Contracts\Repositories\ExtensionDeferredCleanupRepositoryInterface;
use App\Extension\Helpers\DeferredDeclarativeCleanup;
use App\Models\ExtensionDeferredCleanup;
use App\Support\RuntimeRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 검수 서버가 미뤄 둔 선언형 정리를 운영 서버에서 실행합니다 (`extension:apply-deferred-cleanup`).
 *
 * 지울 대상은 검수 서버가 적을 때의 목록이 아니라 지금 이 서버의 확장 파일 선언과 공유 DB 로 다시 정한다 — 설치·업데이트의
 * 선언 동기화(시드 정리는 그 시더)를 그대로 부르므로 운영자 데이터가 있으면 지우기 직전 활동 로그에 남기고 자식은 명시적으로 지운다.
 * 이 서버의 확장 파일 판이 공유 DB 판과 다르면 그 행은 기다림으로 남긴다(종료 1). 실행한 행은 대장에서 지운다.
 * 검수 서버에서는 실행하지 않는다(종료 1).
 *
 * @since 7.2.0
 */
class ApplyDeferredCleanupCommand extends Command
{
    use LocalizesDescription;

    /**
     * @var string 명령 시그니처
     */
    protected $signature = 'extension:apply-deferred-cleanup
        {--dry-run : 실행하지 않고 대장만 출력}
        {--json : 결과를 JSON 으로 출력}';

    /**
     * @var string 명령 설명 (LocalizesDescription 이 다국어로 바꾼다)
     */
    protected $description = 'extensions.commands.apply_deferred_cleanup.description';

    /**
     * 대장을 실행합니다.
     *
     * @param  ExtensionDeferredCleanupRepositoryInterface  $repository  대장 저장소
     * @return int 종료 코드 — 실패가 있으면 1
     */
    public function handle(ExtensionDeferredCleanupRepositoryInterface $repository): int
    {
        if (RuntimeRole::isStage()) {
            $this->error(__('extensions.commands.apply_deferred_cleanup.stage_refused'));

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $items = [];
        $failed = 0;
        $waiting = 0;
        $rows = $repository->all();

        // 지우기 전에 무엇을 정리하는지 알린다 — 「정리」는 새 판 선언에서 빠진 메뉴·권한·역할 등을 실제로 지운다. 확인 질문은 두지 않는다(배포 자동화)
        if (! $dryRun && ! $this->option('json') && count($rows) > 0) {
            $this->warn(__('extensions.commands.apply_deferred_cleanup.about_to_apply', ['count' => count($rows)]));
            foreach ($rows as $row) {
                $this->line('  #'.$row->id.' '.$this->describe($row));
            }
        }

        foreach ($rows as $row) {
            $item = [
                'id' => $row->id,
                'scope' => $row->scope,
                'identifier' => $row->identifier,
                'kind' => $row->kindValue(),
                'target' => $row->target,
                'status' => 'pending',
            ];
            if (! $dryRun) {
                try {
                    $reason = DeferredDeclarativeCleanup::apply($row);
                    if ($reason === null) {
                        // 실행 도중 검수 서버가 같은 정리를 다시 적었으면 행을 남긴다 — 다음 실행이 새 기록으로 다시 계산한다
                        $item['status'] = 'applied';
                        $item['kept_rerecorded'] = ! $repository->delete($row);
                    } else {
                        // 이 서버의 파일(코어) 판이 맞지 않는다 — 파일을 맞춘 뒤 다시 실행한다. 행은 남긴다
                        $waiting++;
                        $item['status'] = 'waiting';
                        $item['reason'] = $reason;
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $item['status'] = 'failed';
                    $item['error'] = $e->getMessage();
                    Log::warning('미뤄 둔 선언 정리 실행 실패', ['id' => $row->id, 'scope' => $row->scope, 'identifier' => $row->identifier, 'error' => $e->getMessage()]);
                }
            }
            $items[] = $item;
        }
        if ($this->option('json')) {
            $this->line((string) json_encode(['dry_run' => $dryRun, 'items' => $items, 'failed' => $failed, 'waiting' => $waiting], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } elseif ($items === []) {
            $this->info(__('extensions.commands.apply_deferred_cleanup.empty'));
        } else {
            // 사람이 읽는 표는 머리글·상태를 다국어로 — JSON 출력은 원래 값을 그대로 쓴다
            $this->table(
                __('extensions.commands.apply_deferred_cleanup.columns'),
                array_map(fn (array $i) => [
                    $i['id'],
                    $i['scope'],
                    $i['identifier'],
                    __('extensions.commands.apply_deferred_cleanup.kind_labels.'.$i['kind']).($i['target'] !== '' ? ' '.$i['target'] : ''),
                    __('extensions.commands.apply_deferred_cleanup.status_labels.'.$i['status']),
                ], $items),
            );
            foreach ($items as $i) {
                if (isset($i['error'])) {
                    $this->warn("  #{$i['id']} {$i['error']}");
                }
                if (isset($i['reason'])) {
                    $this->warn('  #'.$i['id'].' '.__('extensions.commands.apply_deferred_cleanup.waiting_reasons.'.$i['reason']));
                }
            }
            $this->info($dryRun
                ? trans_choice('extensions.commands.apply_deferred_cleanup.dry_run_summary', count($items), ['count' => count($items)])
                : __('extensions.commands.apply_deferred_cleanup.summary', ['applied' => count($items) - $failed - $waiting, 'waiting' => $waiting, 'failed' => $failed]));
            if ($waiting > 0) {
                $this->warn(__('extensions.commands.apply_deferred_cleanup.waiting_hint'));
            }
        }

        return $failed > 0 || $waiting > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * 대장 행을 안내 한 줄로 바꿉니다.
     *
     * @param  ExtensionDeferredCleanup  $row  대장 행
     * @return string 범위 식별자 — 정리 종류 (시더)
     */
    private function describe(ExtensionDeferredCleanup $row): string
    {
        $label = __('extensions.commands.apply_deferred_cleanup.kind_labels.'.$row->kindValue());

        return trim("{$row->scope} {$row->identifier}").' — '.$label.($row->target !== '' ? " ({$row->target})" : '');
    }
}
