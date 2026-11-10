<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * 확장·언어팩·코어 작업 잠금({@see \App\Extension\Helpers\ExtensionOperationLock})이 작업을 시작하지 못하게 했을 때의 공통 부모.
 *
 * 두 갈래가 있다 — 같은 대상에 다른 작업이 진행 중이면 409({@see ExtensionOperationInProgressException}), 잠금 저장소(캐시)가
 * 오류를 내면 503({@see ExtensionOperationLockUnavailableException}). 어느 쪽이든 작업 본문은 한 줄도 실행하지 않았다.
 *
 * HttpException 이라 Laravel 이 보고(report)하지 않는다 — 원본 오류 기록은 잠금 쪽의 `Log::error` 가 맡는다. 응답에는
 * 번역 키·치환값·`errors` 표지만 싣는다(서버 경로 같은 원문은 싣지 않는다 — docs/backend/exceptions.md).
 */
abstract class ExtensionOperationLockException extends HttpException
{
    /**
     * 잠금 거절 예외를 만듭니다.
     *
     * @param  int  $statusCode  HTTP 상태 코드 (409|503)
     * @param  string  $type  대상 종류 (module|plugin|template|language_pack|core)
     * @param  string  $identifier  대상 식별자
     * @param  string  $operation  거절된 작업 (install|activate|deactivate|uninstall|update)
     */
    public function __construct(
        int $statusCode,
        public readonly string $type,
        public readonly string $identifier,
        public readonly string $operation = 'update',
    ) {
        parent::__construct($statusCode, __($this->getMessageKey(), $this->getMessageParams()));
    }

    /**
     * 응답 메시지 키를 반환합니다(예외는 번역문이 아니라 키를 들고 다닌다 — docs/backend/exceptions.md).
     *
     * @return string 다국어 메시지 키
     */
    abstract public function getMessageKey(): string;

    /**
     * 응답 메시지 치환 파라미터를 반환합니다.
     *
     * @return array<string, string|int> 메시지 파라미터
     */
    public function getMessageParams(): array
    {
        return ['name' => $this->identifier];
    }

    /**
     * 응답 `errors` 에 실을 표지를 반환합니다 — 화면이 같은 409 의 다른 계약(의존 경고·칸 충돌)과 이 거절을 가른다.
     *
     * @return array<string, bool> 오류 표지
     */
    abstract public function getErrors(): array;
}
