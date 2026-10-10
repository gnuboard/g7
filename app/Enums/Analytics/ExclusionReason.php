<?php

namespace App\Enums\Analytics;

/**
 * 수집 제외 사유 Enum
 *
 * 방문을 세지 않기로 한 이유입니다. 제외는 조용히 일어나므로 사유별 카운터를 남겨
 * 두어야 운영자가 「왜 안 세는지」를 진단 화면에서 볼 수 있습니다. 이 Enum 이
 * 카운터 키의 단일 출처입니다.
 */
enum ExclusionReason: string
{
    /**
     * 수집 기능이 꺼져 있음
     */
    case Disabled = 'disabled';

    /**
     * 브라우저가 미리 가져온 요청
     */
    case Prefetch = 'prefetch';

    /**
     * 추적 거부 신호
     */
    case Dnt = 'dnt';

    /**
     * 방문자가 직접 제외를 선택함
     */
    case OptOut = 'opt_out';

    /**
     * 제외 경로 규칙에 걸림
     */
    case UrlPattern = 'url_pattern';

    /**
     * 제외 IP 규칙에 걸림
     */
    case IpRule = 'ip_rule';

    /**
     * 제외 User-Agent 규칙에 걸림
     */
    case UaRule = 'ua_rule';

    /**
     * 자동화 브라우저로 판정
     */
    case Headless = 'headless';

    /**
     * 클라우드 사업자 IP 대역
     */
    case CloudIp = 'cloud_ip';

    /**
     * 국가 필터에 걸림
     */
    case Country = 'country';

    /**
     * 봇으로 판정
     */
    case Bot = 'bot';

    /**
     * 동의 모드에서 동의를 받지 못함
     */
    case Consent = 'consent';

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
        return __('analytics.exclusion_reason.'.$this->value);
    }
}
