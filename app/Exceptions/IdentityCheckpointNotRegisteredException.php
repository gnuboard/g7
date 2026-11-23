<?php

namespace App\Exceptions;

/**
 * 확장이 선언하지 않은 본인인증 확장 지점 키로 강제를 요청했음 (개발자 오류).
 *
 * `IdentityPolicyService::enforceCheckpoint()` 를 오타 난 키로 부르면 그 지점에 연결된 정책이 영영 강제되지 않는데
 * 아무도 알아차리지 못한다. 그래서 조용히 통과시키지 않고 이 예외로 바로 드러낸다. 잘못된 인자이므로
 * `\InvalidArgumentException` 을 상속한다. 메시지 키는 `identity.errors.checkpoint_not_registered` 다.
 *
 * @since 7.2.1
 */
class IdentityCheckpointNotRegisteredException extends \InvalidArgumentException
{
    /**
     * 메시지 키
     */
    public const MESSAGE_KEY = 'identity.errors.checkpoint_not_registered';

    /**
     * @param  string  $checkpointKey  등록되지 않은 확장 지점 키
     */
    public function __construct(public readonly string $checkpointKey)
    {
        parent::__construct(__(self::MESSAGE_KEY, ['key' => $checkpointKey]));
    }
}
