<?php

namespace App\Exceptions;

use App\Privacy\Contracts\PrivacyManualRunRequiresEnabledInterface;
use RuntimeException;

/**
 * 자동 실행 토글이 꺼져 있어 직권 실행을 접수할 수 없을 때 발생합니다.
 *
 * {@see PrivacyManualRunRequiresEnabledInterface} 를 단 대상만 해당한다.
 * 컨트롤러가 409 로 응답한다. 메시지 자리에는 번역문이 아니라 다국어 **키**를 보관한다.
 */
class PrivacyManualRunDisabledException extends RuntimeException
{
    /** 응답 메시지 키 */
    public const MESSAGE_KEY = 'exceptions.privacy_manual_run_disabled';

    /**
     * @param  string  $target  대상 키
     */
    public function __construct(public readonly string $target)
    {
        parent::__construct(self::MESSAGE_KEY);
    }
}
