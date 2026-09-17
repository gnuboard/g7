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
