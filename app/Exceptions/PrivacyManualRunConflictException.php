<?php

namespace App\Exceptions;

use App\Models\PrivacyManualRun;
use RuntimeException;

/**
 * 같은 대상에 처리 대기·처리 중인 직권 실행이 이미 있어 새 접수를 거절할 때 던진다.
 *
 * 대상당 작업은 하나만 건다(2026-09-25 확정) — 같은 행을 두 작업이 동시에 처리하거나,
 * 운영자가 결과를 기다리지 못하고 여러 번 눌러 작업이 쌓이는 것을 막는다. 응답에는
 * {@see MESSAGE_KEY} 를 넘긴다(409).
 */
class PrivacyManualRunConflictException extends RuntimeException
{
    /** 응답 메시지 키 */
    public const MESSAGE_KEY = 'exceptions.privacy_manual_run_conflict';

    /**
     * @param  PrivacyManualRun  $activeRun  진행 중인 기록
     */
    public function __construct(public readonly PrivacyManualRun $activeRun)
    {
        parent::__construct(__(self::MESSAGE_KEY));
    }
}
