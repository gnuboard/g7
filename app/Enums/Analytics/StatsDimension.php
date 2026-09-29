<?php

namespace App\Enums\Analytics;

/**
 * 집계 차원 Enum
 *
 * 차원별 집계 표에 쌓이는 항목입니다. 상위 목록 화면은 전부 이 차원 하나를 골라
 * 읽습니다. 확장이 등록한 차원은 ext:{식별자}:{키} 형태라 여기 없습니다.
 */
enum StatsDimension: string
{
    /**
     * 페이지 주소
     */
    case PageUrl = 'page_url';

    /**
     * 페이지 제목
     */
    case PageTitle = 'page_title';

    /**
     * 진입 페이지
     */
    case EntryPage = 'entry_page';

    /**
     * 이탈 페이지
     */
    case ExitPage = 'exit_page';

    /**
     * 유입 종류
     */
    case ReferrerType = 'referrer_type';

    /**
     * 유입처 이름
     */
    case ReferrerName = 'referrer_name';

    /**
     * 검색 키워드
     */
    case ReferrerKeyword = 'referrer_keyword';

    /**
     * 캠페인
     */
    case Campaign = 'campaign';

    /**
     * 캠페인 유입처
     */
    case CampaignSource = 'campaign_source';

    /**
     * 브라우저
     */
    case Browser = 'browser';

    /**
     * 브라우저 버전
     */
    case BrowserVersion = 'browser_version';

    /**
     * 운영체제
     */
    case Os = 'os';

    /**
     * 기기 종류
     */
    case DeviceType = 'device_type';

    /**
     * 화면 해상도
     */
    case Resolution = 'resolution';

    /**
     * 브라우저 언어
     */
    case BrowserLanguage = 'browser_language';

    /**
     * 사이트 로케일
     */
    case Locale = 'locale';

    /**
     * 국가
     */
    case Country = 'country';

    /**
     * 지역
     */
    case Region = 'region';

    /**
     * 도시
     */
    case City = 'city';

    /**
     * 사이트 내부 검색어
     */
    case SiteSearch = 'site_search';

    /**
     * 내려받은 파일
     */
    case Download = 'download';

    /**
     * 외부 링크
     */
    case Outlink = 'outlink';

    /**
     * 목표
     */
    case Goal = 'goal';

    /**
     * 봇 이름
     */
    case BotName = 'bot_name';

    /**
     * 봇이 가져간 페이지
     */
    case BotPage = 'bot_page';

    /**
     * 캠페인 매체 (utm_medium)
     */
    case CampaignMedium = 'campaign_medium';

    /**
     * 사이트 검색 탭 (전체·게시글·상품·페이지 등)
     */
    case SearchTab = 'search_tab';

    /**
     * 이벤트 이름 (확장이 `G7Core.analytics.trackEvent()` 로 보낸 이름)
     */
    case EventName = 'event_name';

    /**
     * 체류시간 구간
     */
    case VisitDuration = 'visit_duration';

    /**
     * 본 페이지 수 구간
     */
    case VisitPages = 'visit_pages';

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
        return __('analytics.dimension.'.$this->value);
    }
}
