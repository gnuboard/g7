<?php

namespace App\Exceptions;

/**
 * 같은 대상에 다른 작업(설치·업데이트·활성화·비활성화·제거)이 이미 진행 중이라 새 요청을 거절할 때 던진다(409).
 *
 * 업데이트 버튼을 두 번 누르거나 CLI 와 관리자 화면이 같은 확장을 동시에 다루면 두 요청이 상태 가드(status=updating)를
 * 세우기 전에 함께 통과해 작업이 두 번 수행됐다(#138 실측 ②). 7.1.5 부터 업데이트뿐 아니라 생명주기 전체와 언어팩·코어
 * 업데이트가 같은 잠금을 쓴다. 응답 `errors` 에는 `operation_in_progress: true` 를 싣는다.
 */
class ExtensionOperationInProgressException extends ExtensionOperationLockException
{
    /** 업데이트 진행 중 응답 메시지 키 (하위 호환 — 7.1.4 이전부터 쓰던 키) */
    public const MESSAGE_KEY = 'extensions.errors.update_already_running';

    /** 업데이트 외 작업이 진행 중일 때의 메시지 키 */
    public const OPERATION_MESSAGE_KEY = 'extensions.errors.operation_already_running';

    /** 코어 업데이트가 진행 중일 때의 메시지 키 */
    public const CORE_MESSAGE_KEY = 'settings.core_update.console.already_running';

    /**
     * 같은 대상의 작업이 이미 진행 중이라는 예외를 만듭니다.
     *
     * @param  string  $type  대상 종류 (module|plugin|template|language_pack|core)
     * @param  string  $identifier  대상 식별자
     * @param  string  $operation  거절된 작업 (install|activate|deactivate|uninstall|update)
     */
    public function __construct(string $type, string $identifier, string $operation = 'update')
    {
        parent::__construct(409, $type, $identifier, $operation);
    }

    /**
     * {@inheritDoc}
     */
    public function getMessageKey(): string
    {
        if ($this->type === 'core') {
            return self::CORE_MESSAGE_KEY;
        }

        return $this->operation === 'update' ? self::MESSAGE_KEY : self::OPERATION_MESSAGE_KEY;
    }

    /**
     * {@inheritDoc}
     */
    public function getMessageParams(): array
    {
        if ($this->type === 'core') {
            return ['minutes' => intdiv(\App\Extension\Helpers\ExtensionOperationLock::CORE_LOCK_SECONDS, 60)];
        }

        return parent::getMessageParams();
    }

    /**
     * {@inheritDoc}
     */
    public function getErrors(): array
    {
        return ['operation_in_progress' => true];
    }
}
