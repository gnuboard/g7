<?php

namespace Plugins\Sirsoft\Gdpr\Enums;

/**
 * GDPR 동의 이력(history) 행위 종류
 *
 * gdpr_user_consent_histories.action 컬럼에 기록되는 append-only 행위 값.
 *
 * - granted: 동의 부여 (필수 항목 저장 / 선택 항목 동의)
 * - revoked: 동의 철회 (기존 동의 항목을 마이페이지 등에서 취소)
 * - rejected: 명시적 거부 (배너에서 "동의하지 않고 계속하기" 로 선택형 카테고리 거부)
 *
 * revoked 와 rejected 는 모두 is_consented=false 로 수렴하지만, 사용자의
 * 의사표시 맥락이 다르다 (철회 = 이전에 동의했던 것을 되돌림 / 거부 = 처음부터 거부).
 * GDPR Art.7(1) 입증 책임상 이 구분을 이력에 보존한다.
 */
enum ConsentAction: string
{
    case Granted = 'granted';
    case Revoked = 'revoked';
    case Rejected = 'rejected';

    /**
     * 저장 값(value/rejection 신호)으로부터 행위를 판정합니다.
     *
     * @param bool $value 동의 여부 (true=동의, false=미동의)
     * @param bool $isRejection 명시적 거부 신호 여부
     * @return self value=true 시 Granted, false+거부 시 Rejected, 그 외 Revoked
     */
    public static function fromDecision(bool $value, bool $isRejection = false): self
    {
        if ($value) {
            return self::Granted;
        }

        return $isRejection ? self::Rejected : self::Revoked;
    }

    /**
     * 모든 케이스의 string 값 목록.
     *
     * @return array<int, string>
     */
    public static function allValues(): array
    {
        return array_column(self::cases(), 'value');
    }
}
