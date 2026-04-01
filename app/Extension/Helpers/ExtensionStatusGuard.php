<?php

namespace App\Extension\Helpers;

use App\Enums\ExtensionStatus;

/**
 * 확장 상태 가드
 *
 * 설치/활성화/비활성화/삭제/업데이트 등의 작업 실행 전,
 * 현재 상태가 진행 중(installing, uninstalling, updating)이면 예외를 발생시킵니다.
 */
class ExtensionStatusGuard
{
    /**
     * 진행 중인 작업이 없는지 확인합니다.
     *
     * @param  ExtensionStatus  $status  현재 상태
     * @param  string  $identifier  확장 식별자 (에러 메시지용)
     *
     * @throws \RuntimeException 진행 중인 작업이 있을 때
     */
    public static function assertNotInProgress(ExtensionStatus $status, string $identifier): void
    {
        $inProgressStatuses = [
            ExtensionStatus::Installing,
            ExtensionStatus::Uninstalling,
            ExtensionStatus::Updating,
        ];

        if (in_array($status, $inProgressStatuses, true)) {
            throw new \RuntimeException(
                __('extensions.errors.operation_in_progress', [
                    'name' => $identifier,
                    'status' => $status->value,
                ])
            );
        }
    }
}
