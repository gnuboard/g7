<?php

namespace App\Services\Analytics;

use App\Analytics\AnalyticsClock;
use App\Analytics\AnalyticsConfig;
use App\Analytics\GeoLocator;
use App\Analytics\ReferrerClassifier;
use App\Analytics\TrackingRequest;
use App\Analytics\UrlNormalizer;
use App\Analytics\UserAgentClassifier;
use App\Enums\Analytics\VisitorType;
use App\Models\Analytics\AnalyticsVisit;

/**
 * 방문 속성 채우기
 *
 * 새 방문 행에 들어갈 값을 한곳에서 만듭니다. 환경·유입·지역·개인정보 처리가 모두
 * 여기를 거치므로, 같은 방문이 경로(비콘·서버 폴백·검색 훅)에 따라 다르게 적히는
 * 일이 생기지 않습니다.
 */
class VisitEnricher
{
    /**
     * 방문 속성 조립기를 생성합니다.
     *
     * @param  GeoLocator  $geo  지역 판정기
     * @param  PrivacyService  $privacy  개인정보 처리기
     */
    public function __construct(
        private readonly GeoLocator $geo,
        private readonly PrivacyService $privacy,
    ) {}

    /**
     * 새 방문 행의 속성을 만듭니다.
     *
     * @param  TrackingRequest  $request  수집 요청
     * @param  AnalyticsConfig  $config  설정
     * @param  array{key: string, key_type: string, new_cookie: string|null, is_returning: bool|null}  $identity  방문자 식별 결과
     * @param  bool  $anonymous  익명 모드 여부
     * @return array<string, mixed> 방문 행 속성
     */
    public function build(
        TrackingRequest $request,
        AnalyticsConfig $config,
        array $identity,
        bool $anonymous,
    ): array {
        $environment = UserAgentClassifier::classify($request->userAgent, $request->clientHints);
        $ip = $this->privacy->resolveIp($request->ip, $config, $anonymous);
        $user = $this->privacy->resolveUser($request->userId, $config);

        $location = $this->geo->locate(
            $request->ip,
            $ip['masked'],
            $request->browserLanguage,
            $config->geoBeforeMask,
        );

        $normalized = UrlNormalizer::normalize($request->url);
        $referrer = ReferrerClassifier::classify(
            $request->referrer,
            $normalized['query'],
            $config->anonymizeReferrer,
        );

        $now = AnalyticsClock::now();

        return [
            'visitor_key' => $identity['key'],
            'visitor_key_type' => $identity['key_type'],
            'visitor_type' => $this->visitorType($request)->value,
            'user_id' => $user['user_id'],
            'user_hash' => $user['user_hash'],
            'visit_date' => AnalyticsClock::dateOf($now),
            'first_action_at' => $now,
            'last_action_at' => $now,
            'actions_count' => 0,
            'pageviews_count' => 0,
            'total_time_seconds' => 0,
            'is_bounce' => true,
            'source' => $request->source,
            'ip' => $ip['ip'],
            'ip_hash' => $ip['ip_hash'],
            'country_code' => $location['country_code'],
            'region_code' => $location['region_code'],
            'city' => $location['city'],
            'browser_name' => $environment['browser_name'],
            'browser_version' => $environment['browser_version'],
            'os_name' => $environment['os_name'],
            'os_version' => $environment['os_version'],
            'device_type' => $environment['device_type']->value,
            'screen_resolution' => $request->resolution,
            'browser_language' => $request->browserLanguage,
            'locale' => $request->locale,
            'referrer_type' => $referrer['type']->value,
            'referrer_name' => $referrer['name'],
            'referrer_url' => $referrer['url'],
            'referrer_keyword' => $referrer['keyword'],
            'campaign_name' => $referrer['campaign_name'],
            'campaign_source' => $referrer['campaign_source'],
            'campaign_medium' => $referrer['campaign_medium'],
            'is_returning' => $identity['is_returning'],
            'custom_dimensions' => $request->dimensions === [] ? null : $request->dimensions,
        ];
    }

    /**
     * 서버 폴백이 먼저 연 방문에 첫 비콘이 합류할 때 채울 값을 만듭니다.
     *
     * 문서 요청에는 화면 해상도가 없고, 로케일은 서버가 요청 헤더로 짐작한 값이며, 유입
     * 주소가 빠져 있을 수 있습니다. 브라우저가 처음 알린 값으로 그 자리를 채우고 출처를
     * 비콘으로 바꿉니다 — 출처가 서버로 남으면 브라우저가 멀쩡히 보고한 방문까지
     * 「서버로만 잡힌 방문」으로 세어집니다.
     *
     * @param  TrackingRequest  $request  첫 비콘 요청
     * @param  AnalyticsConfig  $config  설정
     * @param  AnalyticsVisit  $visit  서버 폴백이 연 방문
     * @return array<string, mixed> 방문 행에 덮어쓸 속성
     */
    public function enrichFromFirstBeacon(TrackingRequest $request, AnalyticsConfig $config, AnalyticsVisit $visit): array
    {
        $user = $this->privacy->resolveUser($request->userId, $config);

        // 같은 화면 로드인데 서버는 회원을 못 볼 수 있다 — API 인증은 토큰이라 문서 요청에는
        // 실리지 않는다. 브라우저가 알린 쪽이 정확하다.
        $attributes = [
            'source' => $request->source,
            'visitor_type' => $this->visitorType($request)->value,
            'user_id' => $user['user_id'],
            'user_hash' => $user['user_hash'],
        ];

        if ($request->resolution !== null && $request->resolution !== '') {
            $attributes['screen_resolution'] = $request->resolution;
        }

        if ($request->locale !== null && $request->locale !== '') {
            $attributes['locale'] = $request->locale;
        }

        // 서버는 언어 헤더 첫 항목(`ko`)만 안다 — 브라우저가 알린 설정 언어(`ko-KR`)가 비콘으로만
        // 열린 방문과 같은 자세함이다. 두 값이 섞이면 브라우저 언어 리포트가 한 언어를 둘로 센다.
        if ($request->browserLanguage !== null && $request->browserLanguage !== '') {
            $attributes['browser_language'] = $request->browserLanguage;
        }

        if ($visit->referrer_url === null && $request->referrer !== null && $request->referrer !== '') {
            $referrer = ReferrerClassifier::classify(
                $request->referrer,
                UrlNormalizer::normalize($request->url)['query'],
                $config->anonymizeReferrer,
            );

            $attributes += [
                'referrer_type' => $referrer['type']->value,
                'referrer_name' => $referrer['name'],
                'referrer_url' => $referrer['url'],
                'referrer_keyword' => $referrer['keyword'],
                'campaign_name' => $referrer['campaign_name'],
                'campaign_source' => $referrer['campaign_source'],
                'campaign_medium' => $referrer['campaign_medium'],
            ];
        }

        return $attributes;
    }

    /**
     * 열린 방문이 지금 요청과 같은 사람의 것인지 판정합니다.
     *
     * 방문 도중 로그인·로그아웃하면 그때부터는 다른 사람의 방문입니다. 같은 방문에 이어
     * 붙이면 로그인한 관리자의 열람이 게스트 방문으로 남아 「관리자 제외」로 빠지지 않습니다.
     *
     * @param  TrackingRequest  $request  수집 요청
     * @param  AnalyticsConfig  $config  설정
     * @param  AnalyticsVisit  $visit  열린 방문
     * @return bool 같은 사람이면 true
     */
    public function identityMatches(TrackingRequest $request, AnalyticsConfig $config, AnalyticsVisit $visit): bool
    {
        $user = $this->privacy->resolveUser($request->userId, $config);

        return (string) ($visit->user_id ?? '') === (string) ($user['user_id'] ?? '')
            && (string) ($visit->user_hash ?? '') === (string) ($user['user_hash'] ?? '');
    }

    /**
     * 방문자 유형을 정합니다.
     *
     * 봇은 여기 오지 않습니다 — 제외 파이프라인이 앞에서 걸러 별도 원장으로 보냅니다.
     *
     * @param  TrackingRequest  $request  수집 요청
     * @return VisitorType 방문자 유형
     */
    private function visitorType(TrackingRequest $request): VisitorType
    {
        if ($request->userId === null) {
            return VisitorType::Guest;
        }

        return $request->isAdmin ? VisitorType::Admin : VisitorType::Member;
    }
}
