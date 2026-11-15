<?php

namespace App\Extension\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 확장 업데이트가 실패했을 때 상태(활성·비활성)를 업데이트 전 값으로 되돌리는 쓰기를 안전하게 실행합니다.
 *
 * 업데이트 실패 처리의 마지막은 「업데이트 중」 상태를 이전 상태로 되돌리는 DB 쓰기다. 실패 원인이 DB 연결 고갈
 * (동시 연결 수 초과)이면 이 쓰기도 같은 이유로 실패하고, 그 예외가 원래 실패를 가린 채 확장이 「업데이트 중」에
 * 남았다(화면은 비활성처럼 보이고, 10분이 지나야 정리 대상이 된다).
 *
 * 한 번 실패하면 연결을 다시 맺고 한 번 더 시도한다. 그래도 안 되면 기록만 남기고 넘어간다 — 호출한 쪽이 던지는
 * 원래 예외가 진짜 원인이다.
 */
final class StatusRestore
{
    /**
     * 상태 복원 쓰기를 실행합니다.
     *
     * @param  callable(): mixed  $write  상태를 되돌리는 쓰기
     * @param  string  $type  확장 유형 (module · plugin · template)
     * @param  string  $identifier  확장 식별자
     * @return bool 되돌렸으면 true (두 번 모두 실패하면 false — 예외는 던지지 않는다)
     */
    public static function run(callable $write, string $type, string $identifier): bool
    {
        try {
            $write();

            return true;
        } catch (\Throwable $first) {
            try {
                // 끊긴·고갈된 연결을 버리고 새로 맺는다
                DB::reconnect();
                $write();

                return true;
            } catch (\Throwable $second) {
                try {
                    Log::error('확장 상태 복원 실패 — 「업데이트 중」 상태로 남습니다. 10분 뒤 활성화 또는 --force 업데이트가 정리합니다', [
                        'type' => $type,
                        'identifier' => $identifier,
                        'error' => $second->getMessage(),
                        'first_error' => $first->getMessage(),
                    ]);
                } catch (\Throwable) {
                    // 기록도 못 남기는 상황이면 조용히 넘어간다 — 원래 예외를 가리지 않는다
                }

                return false;
            }
        }
    }
}
