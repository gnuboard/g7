<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 같은 확장의 업데이트가 이미 진행 중이라 새 요청을 거절할 때 던진다.
 *
 * 업데이트 버튼을 두 번 누르거나 CLI 와 관리자 화면이 같은 확장을 동시에 업데이트하면 두 요청이 상태 가드
 * (status=updating)를 세우기 전에 함께 통과해 업데이트가 두 번 수행됐다(#138 실측 ②). 응답에는
 * {@see MESSAGE_KEY} 를 넘긴다(409).
 */
class ExtensionOperationInProgressException extends RuntimeException
{
    /** 응답 메시지 키 */
    public const MESSAGE_KEY = 'extensions.errors.update_already_running';

    /**
     * 같은 확장의 업데이트가 이미 진행 중이라는 예외를 만듭니다.
     *
     * @param  string  $type  확장 종류 (module|plugin|template)
     * @param  string  $identifier  확장 식별자
     */
    public function __construct(
        public readonly string $type,
        public readonly string $identifier,
    ) {
        parent::__construct(__(self::MESSAGE_KEY, ['name' => $identifier]));
    }

    /**
     * 응답 메시지 키를 반환합니다(예외는 번역문이 아니라 키를 들고 다닌다 — docs/backend/exceptions.md).
     *
     * @return string 다국어 메시지 키
     */
    public function getMessageKey(): string
    {
        return self::MESSAGE_KEY;
    }

    /**
     * 응답 메시지 치환 파라미터를 반환합니다.
     *
     * @return array<string, string> 메시지 파라미터
     */
    public function getMessageParams(): array
    {
        return ['name' => $this->identifier];
    }
}
