<?php

namespace App\Enums\Analytics;

/**
 * 액션 종류 Enum
 *
 * 방문 안에서 일어난 행동의 종류입니다.
 */
enum ActionType: string
{
    /**
     * 페이지 열람
     */
    case Pageview = 'pageview';

    /**
     * 파일 내려받기
     */
    case Download = 'download';

    /**
     * 외부 링크 이동
     */
    case Outlink = 'outlink';

    /**
     * 사이트 내부 검색
     */
    case SiteSearch = 'site_search';

    /**
     * 확장이 보낸 사용자 정의 이벤트
     */
    case Event = 'event';

    /**
     * 목표 달성(전환)
     */
    case Goal = 'goal';

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
        return __('analytics.action_type.'.$this->value);
    }
}
