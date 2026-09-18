<?php

namespace Tests\Feature\Analytics;

use App\Analytics\AnalyticsConfig;
use App\Analytics\ExclusionCounter;
use App\Analytics\TrackingRequest;
use App\Enums\Analytics\ReferrerType;
use App\Extension\HookManager;
use App\Extension\PluginManager;
use App\Models\Analytics\AnalyticsCollectFailure;
use App\Models\Analytics\AnalyticsVisit;
use App\Services\Analytics\PrivacyService;
use App\Services\Analytics\VisitService;
use App\Services\GeoIpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * 개인정보 모드 — 저장 값과 익명 폴백.
 *
 * 이 판정이 틀리면 운영자가 「익명으로 모으는 중」이라고 믿는 동안 IP 가 쌓이거나,
 * 반대로 동의를 받았는데 아무것도 모이지 않는다. 둘 다 화면에는 아무 증상이 없다 —
 * 저장된 값을 직접 단언해야 드러난다.
 */
class PrivacyModeTest extends TestCase
{
    use RefreshDatabase;

    private PrivacyService $privacy;

    /**
     * 테스트 준비 — 서비스 해석.
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->privacy = app(PrivacyService::class);
    }

    /**
     * 수집 요청 하나를 만듭니다.
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 값
     * @return TrackingRequest 수집 요청
     */
    private function request(array $overrides = []): TrackingRequest
    {
        return new TrackingRequest(...array_merge([
            'type' => 'pageview',
            'url' => 'https://example.test/',
            'ip' => '198.51.100.42',
            'receivedAt' => now()->format('Y-m-d H:i:s'),
        ], $overrides));
    }

    /**
     * IP 저장 모드는 마스킹한 주소를 남기고 해시는 두지 않는다.
     *
     * @scenario mode=full, mask_octets=0, ip_family=ipv4
     *
     * @effects full_mode_stores_masked_ip_and_issues_cookie
     *
     * @return void 없음
     */
    public function test_full_mode_stores_ip(): void
    {
        $result = $this->privacy->resolveIp('198.51.100.42', AnalyticsConfig::fromSettings(), false);

        $this->assertSame('198.51.100.42', $result['ip']);
        $this->assertNull($result['ip_hash']);
    }

    /**
     * 마스킹 옥텟 수만큼 주소 하위를 지운다.
     *
     * @scenario mode=full, mask_octets=1|2|3, ip_family=ipv4
     *
     * @effects ip_masking_clears_requested_octets_for_ipv4
     *
     * @return void 없음
     */
    public function test_masking_applies_to_stored_ip(): void
    {
        foreach ([1 => '198.51.100.0', 2 => '198.51.0.0', 3 => '198.0.0.0'] as $octets => $expected) {
            config(['g7_settings.core.analytics.ip_mask_octets' => $octets]);

            $result = $this->privacy->resolveIp('198.51.100.42', AnalyticsConfig::fromSettings(), false);

            $this->assertSame($expected, $result['ip'], "옥텟 {$octets}");
        }
    }

    /**
     * 익명 모드는 IP 를 남기지 않고 해시만 둔다.
     *
     * @scenario mode=anonymous, ip_family=ipv4
     *
     * @effects anonymous_mode_stores_no_ip, anonymous_mode_stores_salted_ip_hash_instead
     *
     * @return void 없음
     */
    public function test_anonymous_mode_stores_hash_not_ip(): void
    {
        $result = $this->privacy->resolveIp('198.51.100.42', AnalyticsConfig::fromSettings(), true);

        $this->assertNull($result['ip'], '익명 모드에 IP 가 남으면 모드의 의미가 없다');
        $this->assertSame(64, strlen((string) $result['ip_hash']));
    }

    /**
     * IPv6 도 같은 규칙으로 다룬다.
     *
     * @scenario mode=full, mask_octets=1, ip_family=ipv6
     *
     * @effects ip_masking_clears_requested_blocks_for_ipv6
     *
     * @return void 없음
     */
    public function test_masking_applies_to_ipv6(): void
    {
        config(['g7_settings.core.analytics.ip_mask_octets' => 1]);

        $result = $this->privacy->resolveIp('2001:db8:85a3::8a2e:370:7334', AnalyticsConfig::fromSettings(), false);

        $this->assertNotSame('2001:db8:85a3::8a2e:370:7334', $result['ip']);
        $this->assertNotNull($result['ip']);
    }

    /**
     * 가명화를 켜면 회원 ID 대신 해시를 남긴다.
     *
     * @scenario mode=full, pseudonymize=true
     *
     * @effects pseudonymized_user_id_is_replaced_by_a_hash
     *
     * @return void 없음
     */
    public function test_pseudonymized_user_id(): void
    {
        config(['g7_settings.core.analytics.pseudonymize_user_id' => true]);

        $result = $this->privacy->resolveUser(42, AnalyticsConfig::fromSettings());

        $this->assertNull($result['user_id'], '가명화를 켜면 원본 ID 가 남으면 안 된다');
        $this->assertSame(64, strlen((string) $result['user_hash']));
    }

    /**
     * 가명화를 끄면 회원 ID 를 그대로 둔다.
     *
     * @scenario mode=full, pseudonymize=false
     *
     * @effects pseudonymized_user_id_is_replaced_by_a_hash
     *
     * @return void 없음
     */
    public function test_plain_user_id_when_not_pseudonymized(): void
    {
        $result = $this->privacy->resolveUser(42, AnalyticsConfig::fromSettings());

        $this->assertSame(42, $result['user_id']);
        $this->assertNull($result['user_hash']);
    }

    /**
     * 동의 출처가 없으면 동의 모드가 익명으로 내려간다.
     *
     * 아무것도 모으지 않으면 운영자가 이유도 모른 채 데이터를 잃는다. 익명 수집은
     * 개인정보를 남기지 않아 법적으로 가장 안전한 쪽이다.
     *
     * @scenario mode=consent, gdpr=absent
     *
     * @effects consent_mode_without_consent_source_falls_back_to_anonymous
     *
     * @return void 없음
     */
    public function test_consent_mode_without_source_falls_back_to_anonymous(): void
    {
        config(['g7_settings.core.analytics.privacy_mode' => 'consent']);

        $this->assertSame('none', $this->privacy->consentSource());
        $this->assertTrue($this->privacy->isAnonymous(AnalyticsConfig::fromSettings()));
    }

    /**
     * 동의 관리 플러그인이 없으면 IP 저장 모드는 그대로 IP 저장 모드다.
     *
     * @scenario mode=full, gdpr=absent
     *
     * @effects full_mode_stores_masked_ip_and_issues_cookie
     *
     * @return void 없음
     */
    public function test_full_mode_without_a_consent_plugin_stays_full(): void
    {
        $config = AnalyticsConfig::fromSettings();

        $this->assertFalse($this->privacy->isAnonymous($config));
        $this->assertNull($this->privacy->anonymousFallbackReason($config));
    }

    /**
     * 동의 여부는 확장이 답하는 필터로 판정한다.
     *
     * 코어가 동의 관리 플러그인의 클래스를 직접 부르면 그 플러그인 없이는 코어가
     * 서지 못한다. 필터를 열어 두고 답할 수 있는 확장이 답하게 한다.
     *
     * @scenario mode=consent, gdpr=active_consented
     *
     * @effects consent_mode_with_granted_consent_behaves_like_full
     *
     * @return void 없음
     */
    public function test_consent_is_resolved_through_a_filter(): void
    {
        config(['g7_settings.core.analytics.privacy_mode' => 'consent']);

        HookManager::addFilter('core.analytics.visitor_has_consent', fn () => true);

        $this->assertTrue(
            $this->privacy->hasAnalyticsConsent($this->request(), AnalyticsConfig::fromSettings()),
        );
    }

    /**
     * 동의하지 않았으면 거짓을 돌려준다.
     *
     * @scenario mode=consent, gdpr=active_declined
     *
     * @effects consent_mode_with_declined_consent_records_nothing
     *
     * @return void 없음
     */
    public function test_declined_consent_is_reported(): void
    {
        config(['g7_settings.core.analytics.privacy_mode' => 'consent']);

        HookManager::addFilter('core.analytics.visitor_has_consent', fn () => false);

        $this->assertFalse(
            $this->privacy->hasAnalyticsConsent($this->request(), AnalyticsConfig::fromSettings()),
        );
    }

    /**
     * 동의 모드가 아니면 동의를 판정하지 않는다.
     *
     * @scenario mode=full
     *
     * @effects consent_mode_with_granted_consent_behaves_like_full
     *
     * @return void 없음
     */
    public function test_non_consent_mode_skips_consent_check(): void
    {
        $this->assertNull(
            $this->privacy->hasAnalyticsConsent($this->request(), AnalyticsConfig::fromSettings()),
        );
    }

    /**
     * 설정이 더 강한 쪽으로 바뀌면 소급을 제안한다.
     *
     * @scenario mode=full
     *
     * @effects tightening_privacy_settings_offers_retroactive_anonymization
     *
     * @return void 없음
     */
    public function test_tightening_offers_retroactive_anonymization(): void
    {
        $this->assertTrue(PrivacyService::tightensPrivacy(
            ['privacy_mode' => 'full', 'ip_mask_octets' => 0],
            ['privacy_mode' => 'anonymous', 'ip_mask_octets' => 0],
        ));

        $this->assertTrue(PrivacyService::tightensPrivacy(
            ['privacy_mode' => 'full', 'ip_mask_octets' => 0],
            ['privacy_mode' => 'full', 'ip_mask_octets' => 2],
        ));
    }

    /**
     * 약한 쪽으로 바꿀 때는 소급할 것이 없다.
     *
     * 과거 데이터는 이미 그보다 적은 정보만 담고 있다.
     *
     * @scenario mode=full
     *
     * @effects loosening_privacy_settings_offers_nothing
     *
     * @return void 없음
     */
    public function test_loosening_offers_nothing(): void
    {
        $this->assertFalse(PrivacyService::tightensPrivacy(
            ['privacy_mode' => 'anonymous', 'ip_mask_octets' => 2],
            ['privacy_mode' => 'full', 'ip_mask_octets' => 0],
        ));
    }

    /**
     * IP 가 없으면 아무 값도 만들지 않는다.
     *
     * @scenario mode=full
     *
     * @effects anonymous_mode_stores_no_ip
     *
     * @return void 없음
     */
    public function test_missing_ip_yields_nulls(): void
    {
        $result = $this->privacy->resolveIp(null, AnalyticsConfig::fromSettings(), false);

        $this->assertNull($result['ip']);
        $this->assertNull($result['ip_hash']);
    }

    /**
     * 방문 하나를 실제로 적재하고 그 행을 돌려줍니다.
     *
     * @param  array<string, mixed>  $overrides  수집 요청 덮어쓰기
     * @return array{visit: AnalyticsVisit|null, result: array<string, mixed>} 방문 행과 적재 결과
     */
    private function trackOne(array $overrides = []): array
    {
        $result = app(VisitService::class)->track($this->request(array_merge([
            'userAgent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
            'browserLanguage' => 'ko-KR',
            'resolution' => '1920x1080',
            'userId' => 42,
        ], $overrides)));

        return [
            'visit' => $result['visit_id'] === null ? null : AnalyticsVisit::find($result['visit_id']),
            'result' => $result,
        ];
    }

    /**
     * IP 저장 모드의 원장 행 — ip 저장·해시 없음·쿠키 발급·회원 ID 그대로·재방문 판정.
     *
     * 모드별 「무엇이 남는가」를 저장된 행으로 단언한다. 서비스 메서드의 반환값만 보면
     * 조립 단계에서 값이 뒤바뀌어도 잡히지 않는다.
     *
     * @scenario mode=full, gdpr=absent, ip_family=ipv4
     *
     * @effects full_mode_stores_masked_ip_and_issues_cookie, full_mode_reports_returning_visitors
     *
     * @return void 없음
     */
    public function test_full_mode_ledger_row(): void
    {
        ['visit' => $visit, 'result' => $result] = $this->trackOne();

        $this->assertSame('198.51.100.42', $visit->ip);
        $this->assertNull($visit->ip_hash);
        $this->assertNotNull($result['new_cookie'], '쿠키를 발급한다');
        $this->assertSame('cookie', $visit->visitor_key_type);
        $this->assertSame(42, $visit->user_id);
        $this->assertNull($visit->user_hash);
        $this->assertFalse((bool) $visit->is_returning);

        ['visit' => $again] = $this->trackOne(['cookies' => ['g7_vid' => $visit->visitor_key], 'url' => 'https://example.test/2']);
        $this->assertSame($visit->id, $again->id, '같은 쿠키는 같은 방문');
    }

    /**
     * 동의 모드에서 동의를 받으면 IP 저장 모드와 같은 행이 남는다.
     *
     * @scenario mode=consent, gdpr=active_consented
     *
     * @effects consent_mode_with_granted_consent_behaves_like_full
     *
     * @return void 없음
     */
    public function test_consent_mode_granted_ledger_row(): void
    {
        config(['g7_settings.core.analytics.privacy_mode' => 'consent']);
        HookManager::addFilter('core.analytics.visitor_has_consent', fn () => true);

        ['visit' => $visit, 'result' => $result] = $this->trackOne();

        $this->assertSame('198.51.100.42', $visit->ip);
        $this->assertNull($visit->ip_hash);
        $this->assertNotNull($result['new_cookie']);
        $this->assertSame('cookie', $visit->visitor_key_type);
        $this->assertSame(42, $visit->user_id);
        $this->assertFalse((bool) $visit->is_returning);
    }

    /**
     * 동의 모드에서 동의가 없으면 아무 행도 남지 않고 제외 사유가 세어진다.
     *
     * @scenario mode=consent, gdpr=active_declined, exclusion=consent
     *
     * @effects consent_mode_with_declined_consent_records_nothing, each_exclusion_reason_is_counted_separately
     *
     * @return void 없음
     */
    public function test_consent_mode_declined_records_nothing_and_counts(): void
    {
        config(['g7_settings.core.analytics.privacy_mode' => 'consent']);
        HookManager::addFilter('core.analytics.visitor_has_consent', fn () => false);
        $before = app(ExclusionCounter::class)->forDate()['consent'];

        ['visit' => $visit, 'result' => $result] = $this->trackOne();

        $this->assertNull($visit);
        $this->assertSame('consent', $result['excluded']);
        $this->assertNull($result['new_cookie'], '동의 없이는 쿠키도 없다');
        $this->assertSame(0, AnalyticsVisit::count());
        $this->assertSame($before + 1, app(ExclusionCounter::class)->forDate()['consent']);
    }

    /**
     * 동의 모드인데 동의 출처가 없으면 익명 모드의 행이 남는다.
     *
     * @scenario mode=consent, gdpr=inactive
     *
     * @effects consent_mode_without_consent_source_falls_back_to_anonymous
     *
     * @return void 없음
     */
    public function test_consent_mode_without_source_stores_anonymous_row(): void
    {
        config(['g7_settings.core.analytics.privacy_mode' => 'consent']);

        ['visit' => $visit, 'result' => $result] = $this->trackOne();

        $this->assertNotNull($visit, '아무것도 안 모으는 대신 익명으로 센다');
        $this->assertNull($visit->ip);
        $this->assertSame(64, strlen($visit->ip_hash));
        $this->assertNull($result['new_cookie']);
        $this->assertSame('config', $visit->visitor_key_type);
        $this->assertNull($visit->is_returning);
    }

    /**
     * 익명 모드의 원장 행 — ip 없음·솔트 해시·쿠키 없음·재방문 null·같은 날 같은 키.
     *
     * @scenario mode=anonymous, gdpr=absent, ip_family=ipv4
     *
     * @effects anonymous_mode_stores_no_ip, anonymous_mode_stores_salted_ip_hash_instead, anonymous_mode_issues_no_cookie, anonymous_mode_leaves_returning_flag_null
     *
     * @return void 없음
     */
    public function test_anonymous_mode_ledger_row(): void
    {
        config(['g7_settings.core.analytics.privacy_mode' => 'anonymous']);

        ['visit' => $visit, 'result' => $result] = $this->trackOne();

        $this->assertNull($visit->ip);
        $this->assertSame(64, strlen($visit->ip_hash));
        $this->assertNull($result['new_cookie'], '익명 모드는 쿠키를 심지 않는다');
        $this->assertSame('config', $visit->visitor_key_type);
        $this->assertSame(42, $visit->user_id, '가명화를 켜지 않았으면 회원 ID 는 남는다');
        $this->assertNull($visit->is_returning, '재방문은 판정하지 않는다');

        ['visit' => $again] = $this->trackOne(['url' => 'https://example.test/2']);
        $this->assertSame($visit->id, $again->id, '같은 날 같은 환경은 같은 방문');
    }

    /**
     * 가명화를 켜면 어느 모드든 회원 ID 대신 해시가 남는다.
     *
     * @scenario mode=full, pseudonymize=true
     *
     * @effects pseudonymized_user_id_is_replaced_by_a_hash
     *
     * @return void 없음
     */
    public function test_pseudonymized_ledger_row(): void
    {
        config(['g7_settings.core.analytics.pseudonymize_user_id' => true]);

        ['visit' => $visit] = $this->trackOne();

        $this->assertNull($visit->user_id);
        $this->assertSame(64, strlen($visit->user_hash));
    }

    /**
     * 지역 조회에 쓰는 IP 는 `geo_before_mask` 가 정한다.
     *
     * @scenario mode=full, mask_octets=1, geo_before_mask=true|false
     *
     * @effects geo_lookup_runs_before_masking_when_configured
     *
     * @return void 없음
     */
    public function test_geo_lookup_ip_follows_geo_before_mask(): void
    {
        config(['g7_settings.core.analytics.ip_mask_octets' => 1]);

        $seen = [];
        $this->mock(GeoIpService::class, function (MockInterface $mock) use (&$seen): void {
            $mock->shouldReceive('getLocationByIp')->andReturnUsing(function (string $ip) use (&$seen): ?array {
                $seen[] = $ip;

                return ['country' => 'KR', 'region_code' => '11', 'region' => 'Seoul', 'city' => 'Seoul', 'city_names' => []];
            });
        });

        config(['g7_settings.core.analytics.geo_before_mask' => true]);
        ['visit' => $before] = $this->trackOne();

        config(['g7_settings.core.analytics.geo_before_mask' => false]);
        ['visit' => $after] = $this->trackOne(['cookies' => [], 'ip' => '198.51.100.43']);

        $this->assertSame(['198.51.100.42', '198.51.100.0'], $seen, '켜면 원본, 끄면 마스킹된 IP 로 조회한다');
        $this->assertSame('198.51.100.0', $before->ip, '저장은 어느 쪽이든 마스킹 후');
        $this->assertSame('Seoul', $before->city);
        $this->assertSame('Seoul', $after->city);
    }

    /**
     * 추적 거부 헤더가 있으면 어느 모드든 세지 않고 사유를 남긴다.
     *
     * @scenario mode=anonymous, exclusion=dnt
     *
     * @effects excluded_requests_record_nothing, each_exclusion_reason_is_counted_separately
     *
     * @return void 없음
     */
    public function test_dnt_is_respected_in_every_mode(): void
    {
        $before = app(ExclusionCounter::class)->forDate()['dnt'];

        foreach (['full', 'consent', 'anonymous'] as $mode) {
            config(['g7_settings.core.analytics.privacy_mode' => $mode]);
            ['result' => $result] = $this->trackOne(['headers' => ['dnt' => '1']]);
            $this->assertSame('dnt', $result['excluded'], $mode);
        }

        $this->assertSame(0, AnalyticsVisit::count());
        $this->assertSame($before + 3, app(ExclusionCounter::class)->forDate()['dnt']);
    }

    /**
     * 실패 로그의 IP 도 같은 마스킹 규칙을 따른다.
     *
     * @scenario mode=full, mask_octets=2
     *
     * @effects failure_log_applies_the_same_ip_masking_rule
     *
     * @return void 없음
     */
    public function test_failure_log_ip_is_masked(): void
    {
        config(['g7_settings.core.analytics.ip_mask_octets' => 2]);
        config(['g7_settings.core.analytics.max_actions_per_visit' => 50]);

        // 상한을 넘겨 실패 로그를 만든다 — 상한 하한이 50 이라 50건을 넣는다.
        $cookie = str_repeat('d', 32);
        for ($i = 0; $i < 51; $i++) {
            $this->trackOne(['cookies' => ['g7_vid' => $cookie], 'url' => 'https://example.test/p'.$i]);
        }

        $failure = AnalyticsCollectFailure::first();

        $this->assertNotNull($failure);
        $this->assertSame('198.51.0.0', $failure->ip);
    }

    /**
     * referrer 익명화 `domain_only` 는 원장 행의 `referrer_url` 을 도메인까지만 남긴다.
     *
     * 분류기 단위 테스트는 반환값만 본다 — 설정값이 조립 단계(`VisitEnricher`)를 거쳐
     * 저장된 행까지 닿는지는 여기서 단언한다. 유입 유형·검색어는 그대로 남아야 한다.
     *
     * @scenario mode=full, referrer_anon=domain_only
     *
     * @effects referrer_anonymization_keeps_domain_only
     *
     * @return void 없음
     */
    public function test_domain_only_referrer_ledger_row(): void
    {
        $referrer = 'https://www.google.com/search?q=%EA%B7%B8%EB%88%84%EB%B3%B4%EB%93%9C&hl=ko';

        config(['g7_settings.core.analytics.anonymize_referrer' => 'none']);
        ['visit' => $plain] = $this->trackOne(['referrer' => $referrer, 'cookies' => ['g7_vid' => str_repeat('e', 32)]]);

        $this->assertNotNull($plain);
        $this->assertSame($referrer, $plain->referrer_url, '익명화를 끄면 URL 을 그대로 둔다');

        config(['g7_settings.core.analytics.anonymize_referrer' => 'domain_only']);
        ['visit' => $anonymized] = $this->trackOne(['referrer' => $referrer, 'cookies' => ['g7_vid' => str_repeat('f', 32)]]);

        $this->assertNotNull($anonymized);
        $this->assertSame('https://www.google.com', $anonymized->referrer_url, '도메인까지만 남긴다');
        $this->assertSame(ReferrerType::Search, $anonymized->referrer_type, '유입 유형은 그대로');
        $this->assertSame('그누보드', $anonymized->referrer_keyword, '검색어 추출은 익명화와 무관');
    }

    /**
     * 동의 관리 플러그인을 활성으로 흉내 냅니다.
     *
     * 동의 출처 판정은 활성 플러그인 목록을 본다. 목록만 바꿔 「그 플러그인이 켜진 사이트」를
     * 만든다 — 플러그인 파일을 설치하지 않아도 판정 경로는 같다.
     *
     * @return void 없음
     */
    private function activateConsentPlugin(): void
    {
        $this->mock(PluginManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getActivePlugins')->andReturn(['sirsoft-gdpr' => ['identifier' => 'sirsoft-gdpr']]);
        });

        $this->privacy = app(PrivacyService::class);
    }

    /**
     * IP 저장 모드라도 동의 관리 플러그인이 켜져 있으면 익명으로 내려간다.
     *
     * 그 플러그인은 동의 전 응답에서 방문자 쿠키를 지운다. 쿠키가 남지 않으면 비콘마다
     * 새 방문자 키가 생겨 한 사람의 이동이 방문 여러 건이 되고, 방문·순방문자가 페이지뷰만큼
     * 부풀며 이탈률이 100% 가 된다. 처음부터 일자 회전 해시로 묶는 편이 정직하다.
     *
     * @scenario mode=full, gdpr=active_declined
     *
     * @effects full_mode_falls_back_to_anonymous_when_the_consent_plugin_blocks_cookies
     *
     * @return void 없음
     */
    public function test_full_mode_falls_back_to_anonymous_when_cookies_are_blocked(): void
    {
        $this->activateConsentPlugin();

        $config = AnalyticsConfig::fromSettings();

        $this->assertTrue($this->privacy->isAnonymous($config), 'IP 저장 모드인데 쿠키가 막히면 익명으로 센다');
        $this->assertSame(
            AnalyticsConfig::ANONYMOUS_REASON_CONSENT_PLUGIN_BLOCKS_COOKIES,
            $this->privacy->anonymousFallbackReason($config),
            '왜 익명으로 도는지 화면이 설명할 수 있어야 한다',
        );

        ['visit' => $visit, 'result' => $result] = $this->trackOne();

        $this->assertNotNull($visit);
        $this->assertNull($visit->ip, '익명 모드의 행이 남는다');
        $this->assertSame(64, strlen($visit->ip_hash));
        $this->assertSame('config', $visit->visitor_key_type);
        $this->assertNull($result['new_cookie'], '지워질 쿠키를 심지 않는다');
        $this->assertNull($visit->is_returning);
    }
}
