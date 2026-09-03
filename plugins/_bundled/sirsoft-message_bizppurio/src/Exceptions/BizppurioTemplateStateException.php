<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Exceptions;

use RuntimeException;

/**
 * 비즈뿌리오 알림 템플릿 상태 전이 위반 예외 (#597).
 *
 * 허용되지 않은 상태에서의 수정·삭제·검수 신청 등 라이프사이클 규칙 위반을 담는다.
 * 컨트롤러는 이 예외를 도메인 실패로 구분해 422 로 응답하고, 그 외 예외는 서버
 * 결함으로 보아 500 을 반환한다. 메시지는 번역문이 아닌 키+치환 파라미터로 보관해
 * 응답 시점에 해석한다(예외 → 응답 매핑 규정).
 */
class BizppurioTemplateStateException extends RuntimeException
{
    /**
     * @param  string  $messageKey  다국어 메시지 키 (sirsoft-message_bizppurio::messages.*)
     * @param  array<string, mixed>  $replace  메시지 치환 파라미터
     */
    public function __construct(
        private readonly string $messageKey,
        private readonly array $replace = [],
    ) {
        parent::__construct(__($messageKey, $replace));
    }

    /**
     * 다국어 메시지 키를 반환합니다.
     *
     * @return string 다국어 메시지 키
     */
    public function getMessageKey(): string
    {
        return $this->messageKey;
    }

    /**
     * 메시지 치환 파라미터를 반환합니다.
     *
     * @return array<string, mixed> 치환 파라미터
     */
    public function getMessageParams(): array
    {
        return $this->replace;
    }
}
