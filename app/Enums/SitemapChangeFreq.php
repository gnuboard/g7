<?php

namespace App\Enums;

/**
 * 사이트맵 <changefreq> 값 Enum (sitemaps.org 폐쇄 어휘)
 *
 * https://www.sitemaps.org/protocol.html 의 <changefreq> 는 크롤러에게 페이지 갱신
 * 빈도를 알려주는 힌트로, 허용 값이 7개로 고정된 폐쇄 어휘다. 이 Enum 이 그 SSoT 이며,
 * 잘못된 문자열이 사이트맵 XML 에 유입되는 것을 저장(SitemapIndexer)과 렌더
 * (SitemapXmlRenderer) 두 경계에서 차단한다.
 *
 * 기여자(getUrlsLazy)·리스너·정적 URL 은 리터럴 문자열 대신 이 Enum 의 case 를 사용해
 * 오타를 작성 시점에 잡는다.
 */
enum SitemapChangeFreq: string
{
    /**
     * 접근할 때마다 변경 (예: 실시간 시세)
     */
    case Always = 'always';

    /**
     * 시간 단위 변경
     */
    case Hourly = 'hourly';

    /**
     * 일 단위 변경
     */
    case Daily = 'daily';

    /**
     * 주 단위 변경
     */
    case Weekly = 'weekly';

    /**
     * 월 단위 변경
     */
    case Monthly = 'monthly';

    /**
     * 연 단위 변경
     */
    case Yearly = 'yearly';

    /**
     * 사실상 변경 없음 (예: 보관 URL)
     */
    case Never = 'never';

    /**
     * 임의 문자열을 유효한 changefreq 값으로 정규화합니다.
     *
     * 대소문자·앞뒤 공백을 흡수하고, 폐쇄 어휘에 없는 값은 null 로 떨어뜨려
     * 사이트맵 XML 에 비표준 값이 출력되는 것을 방지합니다.
     *
     * @param  string|null  $value  검증할 원본 값
     * @return string|null 유효하면 정규화된 값, 아니면 null
     */
    public static function normalize(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return self::tryFrom(strtolower(trim($value)))?->value;
    }
}
