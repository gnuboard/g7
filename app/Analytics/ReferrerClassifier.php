<?php

namespace App\Analytics;

use App\Enums\Analytics\ReferrerAnonymization;
use App\Enums\Analytics\ReferrerType;

/**
 * 유입 경로 분류기
 *
 * 방문자가 어디서 왔는지를 직접·검색·다른 사이트·캠페인으로 가릅니다.
 *
 * 검색 키워드는 대부분 비어 옵니다 — 검색엔진이 HTTPS 로 옮겨 가면서 검색어를
 * 유입 URL 에 싣지 않게 됐기 때문입니다. 값이 없다고 판정이 틀린 것이 아니라는
 * 사실을 화면이 힌트로 알립니다.
 */
class ReferrerClassifier
{
    /**
     * 유입 정보를 분류합니다.
     *
     * @param  string|null  $referrer  유입 URL
     * @param  array<string, string>  $landingQuery  방문한 페이지의 쿼리 (캠페인 추출용)
     * @param  ReferrerAnonymization  $anonymization  유입 URL 익명화 방식
     * @return array{type: ReferrerType, name: string|null, url: string|null, keyword: string|null, campaign_name: string|null, campaign_source: string|null, campaign_medium: string|null}
     *                                                                                                                                                                                      분류 결과
     */
    public static function classify(
        ?string $referrer,
        array $landingQuery = [],
        ReferrerAnonymization $anonymization = ReferrerAnonymization::None,
    ): array {
        $campaign = self::campaignFrom($landingQuery);

        // 캠페인 표식이 붙어 있으면 어디서 왔든 캠페인 유입이다 —
        // 운영자가 링크를 그렇게 표시해 둔 것이 가장 정확한 출처다.
        if ($campaign['name'] !== null || $campaign['source'] !== null) {
            return [
                'type' => ReferrerType::Campaign,
                'name' => $campaign['source'] ?? $campaign['name'],
                'url' => self::presentUrl($referrer, $anonymization),
                'keyword' => null,
                'campaign_name' => $campaign['name'],
                'campaign_source' => $campaign['source'],
                'campaign_medium' => $campaign['medium'],
            ];
        }

        $host = self::hostOf($referrer);

        // 매체(utm_medium)만 붙은 주소는 캠페인으로 보지 않는다 — 이름·출처가 없으면 어느 캠페인인지
        // 말할 수 없다. 다만 운영자가 붙인 매체 값은 유입 종류와 따로 남겨 「캠페인 매체」 리포트가 센다.
        $medium = $campaign['medium'];

        if ($host === null) {
            return self::emptyResult(ReferrerType::Direct, $medium);
        }

        // 사이트 안에서의 이동은 유입이 아니다.
        if ($host === UrlNormalizer::siteHost()) {
            return self::emptyResult(ReferrerType::Direct, $medium);
        }

        $engine = self::searchEngineFor($host);

        if ($engine !== null) {
            return [
                'type' => ReferrerType::Search,
                'name' => $engine['name'],
                'url' => self::presentUrl($referrer, $anonymization),
                'keyword' => self::keywordFrom($referrer, $engine['params']),
                'campaign_name' => null,
                'campaign_source' => null,
                'campaign_medium' => $medium,
            ];
        }

        return [
            'type' => ReferrerType::Website,
            'name' => $host,
            'url' => self::presentUrl($referrer, $anonymization),
            'keyword' => null,
            'campaign_name' => null,
            'campaign_source' => null,
            'campaign_medium' => $medium,
        ];
    }

    /**
     * 빈 분류 결과를 만듭니다.
     *
     * @param  ReferrerType  $type  유입 종류
     * @param  string|null  $medium  캠페인 매체 (utm_medium 만 붙은 경우)
     * @return array{type: ReferrerType, name: null, url: null, keyword: null, campaign_name: null, campaign_source: null, campaign_medium: string|null}
     *                                                                                                                                                   분류 결과
     */
    private static function emptyResult(ReferrerType $type, ?string $medium = null): array
    {
        return [
            'type' => $type,
            'name' => null,
            'url' => null,
            'keyword' => null,
            'campaign_name' => null,
            'campaign_source' => null,
            'campaign_medium' => $medium,
        ];
    }

    /**
     * 방문 URL 의 쿼리에서 캠페인 정보를 뽑습니다.
     *
     * @param  array<string, string>  $query  쿼리 파라미터
     * @return array{name: string|null, source: string|null, medium: string|null} 캠페인 정보
     */
    private static function campaignFrom(array $query): array
    {
        $keys = AnalyticsTables::campaignParams();

        $pick = static function (?string $key) use ($query): ?string {
            if ($key === null || ! isset($query[$key])) {
                return null;
            }

            $value = trim($query[$key]);

            return $value === '' ? null : mb_substr($value, 0, 100);
        };

        return [
            'name' => $pick($keys['name'] ?? null),
            'source' => $pick($keys['source'] ?? null),
            'medium' => $pick($keys['medium'] ?? null),
        ];
    }

    /**
     * 호스트에 해당하는 검색엔진을 찾습니다.
     *
     * @param  string  $host  유입 호스트 (소문자, www 제거)
     * @return array{name: string, params: array<int, string>}|null 검색엔진 (아니면 null)
     */
    private static function searchEngineFor(string $host): ?array
    {
        foreach (AnalyticsTables::searchEngines() as $engine) {
            foreach ($engine['hosts'] as $candidate) {
                if (! is_string($candidate)) {
                    continue;
                }

                $candidate = strtolower($candidate);

                if ($host === $candidate || str_ends_with($host, '.'.$candidate)) {
                    return [
                        'name' => (string) ($engine['name'] ?? $candidate),
                        'params' => array_values(array_filter((array) ($engine['params'] ?? []), 'is_string')),
                    ];
                }
            }
        }

        return null;
    }

    /**
     * 유입 URL 에서 검색 키워드를 뽑습니다.
     *
     * @param  string  $referrer  유입 URL
     * @param  array<int, string>  $params  키워드가 담기는 파라미터 후보
     * @return string|null 검색 키워드 (없으면 null)
     */
    private static function keywordFrom(string $referrer, array $params): ?string
    {
        $queryString = parse_url($referrer, PHP_URL_QUERY);

        if (! is_string($queryString) || $queryString === '') {
            return null;
        }

        parse_str($queryString, $parsed);

        foreach ($params as $param) {
            $value = $parsed[$param] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return mb_substr(trim($value), 0, 255);
            }
        }

        return null;
    }

    /**
     * 유입 주소에서 조각(#…)과 추적 파라미터를 뗍니다.
     *
     * 조각은 브라우저가 서버에 보내지 않는 자리라 그 사이트도 남기려던 값이 아니고, 인증 토큰 같은 값이
     * 실릴 수 있습니다. 추적 파라미터는 페이지 주소 정규화와 같은 목록을 씁니다. 나머지 쿼리는 순서와
     * 인코딩을 건드리지 않습니다 — 유입 주소는 다시 열어 볼 수 있어야 하는 원문입니다.
     *
     * @param  string  $referrer  유입 URL
     * @return string 정리한 URL
     */
    private static function withoutFragmentAndTracking(string $referrer): string
    {
        $clean = explode('#', $referrer, 2)[0];
        $pieces = explode('?', $clean, 2);

        if (! isset($pieces[1]) || $pieces[1] === '') {
            return $pieces[0];
        }

        $stripped = array_flip(AnalyticsTables::strippedQueryParams());
        $kept = array_filter(
            explode('&', $pieces[1]),
            static fn (string $pair): bool => $pair !== '' && ! isset($stripped[urldecode(explode('=', $pair, 2)[0])]),
        );

        return $pieces[0].($kept !== [] ? '?'.implode('&', $kept) : '');
    }

    /**
     * 저장할 유입 URL 을 만듭니다.
     *
     * 익명화를 켜면 경로와 쿼리를 버리고 도메인까지만 남깁니다 — 유입처가 어디인지는
     * 알 수 있지만 방문자가 그 사이트에서 무엇을 보고 있었는지는 남지 않습니다.
     *
     * @param  string|null  $referrer  유입 URL
     * @param  ReferrerAnonymization  $anonymization  익명화 방식
     * @return string|null 저장할 URL (없으면 null)
     */
    private static function presentUrl(?string $referrer, ReferrerAnonymization $anonymization): ?string
    {
        if ($referrer === null || trim($referrer) === '') {
            return null;
        }

        if ($anonymization !== ReferrerAnonymization::DomainOnly) {
            return mb_substr(self::withoutFragmentAndTracking($referrer), 0, 1024);
        }

        $parts = parse_url($referrer);

        if ($parts === false || ! isset($parts['host'])) {
            return null;
        }

        $scheme = $parts['scheme'] ?? 'https';

        return $scheme.'://'.$parts['host'];
    }

    /**
     * 유입 URL 의 호스트를 소문자·www 제거 형태로 반환합니다.
     *
     * @param  string|null  $referrer  유입 URL
     * @return string|null 호스트 (없으면 null)
     */
    private static function hostOf(?string $referrer): ?string
    {
        if ($referrer === null || trim($referrer) === '') {
            return null;
        }

        $host = parse_url(trim($referrer), PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower($host);

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
