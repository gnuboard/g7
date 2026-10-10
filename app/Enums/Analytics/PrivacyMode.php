<?php

namespace App\Enums\Analytics;

/**
 * 개인정보 수집 모드 Enum
 *
 * 방문자를 어디까지 식별해 저장할지 정하는 세 모드입니다. 운영자가 설정 화면에서
 * 고르며 수집 파이프라인 전체가 이 값을 따릅니다.
 */
enum PrivacyMode: string
{
    /**
     * IP 저장 — 쿠키로 방문자를 구분하고 설정한 만큼 마스킹한 IP 를 남긴다
     */
    case Full = 'full';

    /**
     * 동의 연동 — 동의한 방문자만 수집한다. 동의 출처가 없으면 익명 모드로 내려간다
     */
    case Consent = 'consent';

    /**
     * 익명 — 쿠키도 IP 도 남기지 않고 날마다 바뀌는 해시로만 구분한다
     */
    case Anonymous = 'anonymous';

    /**
     * 모든 값을 문자열 배열로 반환합니다.
     *
     * @return array<string> 값 목록
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 유효한 값인지 확인합니다.
     *
     * @param  string  $value  검증할 값
     * @return bool 유효 여부
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string 번역된 라벨
     */
    public function label(): string
    {
        return __('analytics.privacy_mode.'.$this->value);
    }

    /**
     * 방문자 식별에 쿠키를 쓰는 모드인지 반환합니다.
     *
     * 익명 모드는 쿠키를 심지 않으므로 재방문 여부를 판정할 수 없습니다.
     *
     * @return bool 쿠키 사용 여부
     */
    public function usesCookie(): bool
    {
        return $this !== self::Anonymous;
    }
}
