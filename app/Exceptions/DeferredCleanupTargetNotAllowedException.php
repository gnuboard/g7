<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 미뤄 둔 정리 대장 행이 실행할 수 없는 대상을 가리킬 때 던집니다 — 그 행은 실행하지 않고 대장에 남깁니다.
 *
 * 대장의 범위·종류·시더 클래스는 DB 에서 읽는 값이다. 시더는 그 확장이 선언한 시더(`getSeeders()`)일 때만 실행하고,
 * 그 밖의 값(변조된 행, 이름이 바뀐 옛 시더, 알 수 없는 범위)은 실행하지 않는다.
 *
 * @since 7.2.0
 */
class DeferredCleanupTargetNotAllowedException extends RuntimeException
{
    /** 메시지 키 */
    public const MESSAGE_KEY = 'exceptions.deferred_cleanup_target_not_allowed';

    /**
     * @param  string  $target  대장에 적힌 대상 (범위·종류·시더)
     */
    public function __construct(public readonly string $target)
    {
        parent::__construct((string) __(self::MESSAGE_KEY, $this->getMessageParams()));
    }

    /**
     * 메시지 키를 반환합니다.
     *
     * @return string 다국어 메시지 키
     */
    public function getMessageKey(): string
    {
        return self::MESSAGE_KEY;
    }

    /**
     * 메시지 치환 파라미터를 반환합니다.
     *
     * @return array{target: string} 치환 파라미터
     */
    public function getMessageParams(): array
    {
        return ['target' => $this->target];
    }
}
