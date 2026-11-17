<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 미뤄 둔 정리 대장 행의 정리 메서드가 허용 목록에 없을 때 던집니다 — 그 행은 실행하지 않습니다.
 *
 * 대장의 `helper` 문자열(`클래스::메서드`)은 DB 에서 읽는 값이다. 허용 목록에 있는 선언형 정리 메서드만 부르고,
 * 그 밖의 값(변조된 행, 이름이 바뀐 옛 메서드)은 부르지 않고 실패로 남긴다.
 *
 * @since 7.2.0
 */
class DeferredCleanupHelperNotAllowedException extends RuntimeException
{
    /** 메시지 키 */
    public const MESSAGE_KEY = 'exceptions.deferred_cleanup_helper_not_allowed';

    /**
     * @param  string  $helper  대장에 적힌 정리 메서드 (`클래스::메서드`)
     */
    public function __construct(public readonly string $helper)
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
     * @return array{helper: string} 치환 파라미터
     */
    public function getMessageParams(): array
    {
        return ['helper' => $this->helper];
    }
}
