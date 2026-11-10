<?php

namespace App\Exceptions;

/**
 * 작업 잠금 저장소(캐시)가 오류를 내 잠금을 잡을 수 없어 작업을 시작하지 않을 때 던진다(503).
 *
 * 7.1.4 까지는 이 경우 잠금 없이 진행해(fail-open) 같은 확장의 업데이트가 동시에 여러 번 돌 수 있었다(#161). 잠금이 없으면
 * 상호 배제를 보장할 수 없으므로 시작하지 않는다. 원본 오류(파일 경로 포함)는 `Log::error` 에만 남기고 응답·화면에는 번역문만
 * 싣는다. 응답 `errors` 에는 `operation_lock_unavailable: true` 를 싣는다.
 */
class ExtensionOperationLockUnavailableException extends ExtensionOperationLockException
{
    /** 확장·언어팩 작업 메시지 키 */
    public const MESSAGE_KEY = 'extensions.errors.operation_lock_unavailable';

    /** 코어 업데이트 메시지 키 */
    public const CORE_MESSAGE_KEY = 'settings.core_update.console.lock_unavailable';

    /**
     * 잠금 저장소를 쓸 수 없다는 예외를 만듭니다.
     *
     * @param  string  $type  대상 종류 (module|plugin|template|language_pack|core)
     * @param  string  $identifier  대상 식별자
     * @param  string  $operation  거절된 작업 (install|activate|deactivate|uninstall|update)
     */
    public function __construct(string $type, string $identifier, string $operation = 'update')
    {
        parent::__construct(503, $type, $identifier, $operation);
    }

    /**
     * {@inheritDoc}
     */
    public function getMessageKey(): string
    {
        return $this->type === 'core' ? self::CORE_MESSAGE_KEY : self::MESSAGE_KEY;
    }

    /**
     * {@inheritDoc}
     */
    public function getErrors(): array
    {
        return ['operation_lock_unavailable' => true];
    }
}
