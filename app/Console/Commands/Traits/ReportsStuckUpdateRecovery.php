<?php

namespace App\Console\Commands\Traits;

use App\Extension\Helpers\ExtensionOperationLock;
use App\Extension\Helpers\ExtensionUpdateJournal;
use App\Extension\Helpers\StuckUpdateRecovery;

/**
 * 업데이트·활성화 명령이 「멈춘 업데이트를 정리했습니다」 안내를 출력하는 트레이트 (7.1.1 출시 전).
 *
 * 정리는 Manager 진입점({@see StuckUpdateRecovery})이 하고, 명령은 그 결과를 운영자에게 보여 준다 — 이어지는 작업이
 * 실패해도 정리 사실은 남아야 하므로 성공·실패 두 갈래에서 부른다(두 번 불러도 한 번만 출력).
 */
trait ReportsStuckUpdateRecovery
{
    /**
     * 이번 실행에서 정리한 멈춘 업데이트를 경고로 출력합니다.
     *
     * 정리는 확장을 비활성으로 되돌리므로(직전 상태를 알 수 없다 — 결정), 업데이트 명령에서는 다시 켜는 명령을 함께
     * 안내한다 — 종전에는 업데이트가 끝나도 사이트가 그 확장 없이 503 으로 남았는데 다음 행동이 보이지 않았다.
     * 활성화 명령은 정리 뒤 바로 켜므로 안내하지 않는다. 대신 판 전환을 끝내지 못한 정리(파일은 새 판일 수 있는데 DB 판·스텝이 끝나지
     * 않음)면 업데이트를 다시 돌리라고 안내한다.
     *
     * @param  bool  $suggestActivate  다시 켜는 명령을 안내할지 (업데이트 명령 true)
     * @param  bool  $updateFailed  정리 뒤 이어 한 업데이트가 실패했는지 (업데이트 명령의 실패 갈래 true — 판 전환이 여전히 끝나지 않았다)
     * @return void 반환값 없음
     */
    protected function reportStuckUpdateRecovery(bool $suggestActivate = false, bool $updateFailed = false): void
    {
        foreach (StuckUpdateRecovery::takeRecovered() as $item) {
            $this->warn('⚠️  '.__('extensions.stuck_update_recovered', [
                'name' => $item['identifier'],
                'status' => $item['status'],
            ]));

            if ($suggestActivate) {
                $this->warn('💡 '.__('extensions.stuck_update_activate_hint', [
                    'command' => "php artisan {$item['type']}:activate {$item['identifier']}",
                ]));
            }

            // 판 전환을 끝내지 못한 채 멈췄다 — 정리가 DB 판을 시작 판으로 되돌렸으니 업데이트를 다시 돌려 마저 끝내게 한다.
            // 업데이트 명령은 정리 뒤 바로 그 업데이트를 이어 하므로, 활성화 명령과 이어 한 업데이트가 실패했을 때만 안내한다.
            $unfinished = $item['unfinished'] ?? null;
            if ((! $suggestActivate || $updateFailed) && is_array($unfinished)) {
                $this->warn('💡 '.__('extensions.stuck_update_unfinished_hint', [
                    'from' => $unfinished['from'],
                    'to' => $unfinished['to'],
                    'command' => "php artisan {$item['type']}:update {$item['identifier']}",
                ]));
            }
        }
    }

    /**
     * `--force` 없는 업데이트가 진행 중 상태(`updating` 등)의 확장을 만나면 안내하고 멈춥니다.
     *
     * 끊긴 업데이트는 DB 판을 이미 새 판으로 적어 두므로, 업데이트 확인을 먼저 하면 「업데이트 없음」으로 끝나 멈춘 업데이트
     * 안내가 운영자에게 닿지 않았다. 그래서 업데이트 확인보다 먼저 상태 가드를 본다.
     *
     * @param  object  $record  확장 행 (`status`, `updated_at`)
     * @param  string  $identifier  확장 식별자
     * @param  bool  $force  `--force` 지정 여부 (지정하면 Manager 가 정리하므로 막지 않는다)
     * @return bool 막았으면 true (호출한 쪽은 실패로 끝낸다)
     */
    protected function refuseInProgressUpdate(object $record, string $identifier, bool $force): bool
    {
        if ($force) {
            return false;
        }

        try {
            StuckUpdateRecovery::assertNotInProgress($identifier, $record);
        } catch (\RuntimeException $e) {
            $this->error('❌ '.$e->getMessage());

            return true;
        }

        return false;
    }

    /**
     * 「업데이트 없음」 판정인데 이 서버의 진행 기록이 남아 있으면 끝나지 않은 업데이트를 안내하고 멈춥니다.
     *
     * 업데이트는 DB 판을 새 판으로 적은 뒤 스텝·상태 복원·레이아웃 갱신을 한다. 그 사이에 프로세스가 죽으면 DB 판은 이미 새 판이라
     * `--force` 없는 업데이트가 「업데이트 없음」으로 끝나 남은 스텝·레이아웃 갱신이 영영 건너뛰어졌다. 검수 서버는 공유 상태 칸에
     * `updating` 을 쓰지 않아 멈춘 업데이트 판정({@see refuseInProgressUpdate()})이 잡지 못하고, 운영 서버도 상태를 되돌린 뒤 죽으면
     * 같다 — 그래서 역할과 무관하게 서버 로컬 진행 기록({@see ExtensionUpdateJournal})으로 판정한다.
     *
     * 「업데이트 있음」 판정일 때는 부르지 않는다 — 그 업데이트가 진행 기록을 이어받아 못 끝낸 스텝을 마저 돈다(멈춘 업데이트 정리 뒤
     * 안내하는 흐름). 이 서버에서 그 업데이트가 아직 돌고 있으면(작업 잠금) 진행 중이라고 알린다. 진행 기록·DB 판은 건드리지 않는다.
     *
     * @param  string  $type  확장 종류 (module|plugin|template)
     * @param  string  $identifier  확장 식별자
     * @return bool 막았으면 true (호출한 쪽은 실패로 끝낸다)
     */
    protected function refuseUnfinishedUpdate(string $type, string $identifier): bool
    {
        $journal = ExtensionUpdateJournal::find($type, $identifier);
        if ($journal === null) {
            return false;
        }

        if (ExtensionOperationLock::isFree($type, $identifier) === false) {
            $this->error('❌ '.__('extensions.errors.update_already_running', ['name' => $identifier]));

            return true;
        }

        $this->error('❌ '.__('extensions.commands.update.unfinished_update_needs_force', [
            'name' => $identifier,
            'from' => $journal->fromVersion(),
            'to' => $journal->toVersion(),
            'command' => "php artisan {$type}:update {$identifier} --force",
        ]));

        return true;
    }
}
