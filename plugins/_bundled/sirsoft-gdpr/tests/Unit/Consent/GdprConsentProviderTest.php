<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Unit\Consent;

use App\Extension\Consent\ConsentManager;
use App\Extension\Consent\ConsentSelection;
use App\Extension\Consent\ConsentSnapshot;
use App\Extension\Consent\ConsentSubject;
use App\Extension\HookManager;
use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Mockery\MockInterface;
use Plugins\Sirsoft\Gdpr\Concerns\IssuesGuestSessionCookie;
use Plugins\Sirsoft\Gdpr\Consent\GdprConsentProvider;
use Plugins\Sirsoft\Gdpr\Listeners\RegisterGdprConsentProviderListener;
use Plugins\Sirsoft\Gdpr\Services\GdprConsentService;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * 코어 동의 제공자 계약 구현 테스트 (#639) [case:analytics-consent-guest-session-id]
 *
 * 이 플러그인은 코어 동의 계약의 제공자로 등록되어, 코어·다른 확장의 「이 방문자가 무엇에 동의했나」에 답한다.
 * 게스트 동의는 서명된 `gdpr_session` 쿠키의 UUID 로 저장되므로, 같은 UUID 로 찾아야 배너에서 동의한 게스트가
 * 「동의 없음」이 되지 않는다(#682 §8 M1-T9). 코어는 이 플러그인을 모른다 — 필터로 등록되고 계약으로만 묻는다.
 */
class GdprConsentProviderTest extends PluginTestCase
{
    /**
     * 코어 매니저의 등록·선택값을 비우고 이 플러그인의 등록 리스너만 붙입니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        HookManager::clearFilter(ConsentManager::REGISTERED_PROVIDERS_FILTER);
        config(['g7_settings.core.'.ConsentManager::SETTING_KEY => '']);
        HookManager::addFilter(
            ConsentManager::REGISTERED_PROVIDERS_FILTER,
            [new RegisterGdprConsentProviderListener, 'register'],
            20,
        );
    }

    /**
     * 서명된 게스트 쿠키 값을 만듭니다.
     *
     * @param  string  $sessionId  게스트 세션 UUID
     * @return string 쿠키 값
     */
    private function signed(string $sessionId): string
    {
        $signer = new class
        {
            use IssuesGuestSessionCookie;

            /**
             * 서명한 값을 돌려줍니다.
             *
             * @param  string  $sessionId  게스트 세션 UUID
             * @return string 쿠키 값
             */
            public function sign(string $sessionId): string
            {
                return $this->signGuestSessionId($sessionId);
            }
        };

        return $signer->sign($sessionId);
    }

    /**
     * 게스트 쿠키가 달린 요청을 만듭니다.
     *
     * @param  string|null  $cookieValue  `gdpr_session` 쿠키 값
     * @return Request HTTP 요청
     */
    private function guestRequest(?string $cookieValue): Request
    {
        return Request::create('/api/consent', 'GET', [], $cookieValue === null ? [] : ['gdpr_session' => $cookieValue]);
    }

    /**
     * 쿠키 동의 조회 결과를 흉내 냅니다.
     *
     * @param  array<string, bool>  $consents  항목 → 동의 여부
     * @param  bool  $current  현재 정책 버전 동의가 있는지
     * @param  bool  $history  동의 이력이 있는지
     * @return void 반환값 없음
     */
    private function consentsAre(array $consents, bool $current = true, bool $history = true): void
    {
        $this->mock(GdprConsentService::class, function (MockInterface $mock) use ($consents, $current, $history): void {
            $mock->shouldReceive('getCurrentPolicyVersion')->andReturn('v2');
            $mock->shouldReceive('getCurrentCookieConsents')->andReturn($consents);
            $mock->shouldReceive('hasCurrentCookieConsent')->andReturn($current);
            $mock->shouldReceive('hasAnyConsentHistory')->andReturn($history);
        });
    }

    /**
     * 플러그인 설정 조회를 흉내 냅니다 (지정하지 않은 키는 기본값).
     *
     * @param  array<string, mixed>  $values  설정 키 → 값
     * @return void 반환값 없음
     */
    private function pluginSettingsAre(array $values): void
    {
        $this->mock(PluginSettingsService::class, function (MockInterface $mock) use ($values): void {
            $mock->shouldReceive('get')->andReturnUsing(
                fn (string $identifier, ?string $key = null, mixed $default = null) => array_key_exists((string) $key, $values) ? $values[$key] : $default
            );
        });
    }

    /**
     * 등록 리스너가 필터로 제공자를 더하고, 하나뿐이면 코어가 자동 선택한다.
     *
     * @scenario case=registration
     *
     * @effects gdpr_registers_as_a_core_consent_provider
     *
     * @return void 반환값 없음
     */
    public function test_registers_through_the_core_filter_and_is_auto_selected(): void
    {
        $hooks = RegisterGdprConsentProviderListener::getSubscribedHooks();
        $this->assertSame('filter', $hooks[ConsentManager::REGISTERED_PROVIDERS_FILTER]['type']);
        $this->assertTrue($hooks[ConsentManager::REGISTERED_PROVIDERS_FILTER]['sync']);

        $selection = app(ConsentManager::class)->selection();

        $this->assertSame(ConsentSelection::STATE_AUTO, $selection->state);
        $this->assertSame('sirsoft-gdpr', $selection->provider?->getId());
        $this->assertNotSame('', $selection->provider?->getLabel());
    }

    /**
     * 배너를 끄면 동의를 모으지 않으므로 수집 중이 아니고, 코어는 제공자 없음으로 판정한다.
     *
     * @scenario case=banner_disabled
     *
     * @effects banner_disabled_provider_is_not_available
     *
     * @return void 반환값 없음
     */
    public function test_banner_disabled_makes_the_provider_unavailable(): void
    {
        $this->pluginSettingsAre(['banner_enabled' => false]);

        $this->assertFalse(app(GdprConsentProvider::class)->isAvailable());
        $this->assertSame(ConsentSelection::STATE_NONE, app(ConsentManager::class)->selection()->state);
        $this->assertSame(ConsentSnapshot::STATUS_NONE, app(ConsentManager::class)->snapshotFor(ConsentSubject::guest(null))->status);
    }

    /**
     * 카테고리는 운영자 설정을 현재 로케일 이름으로 계약 모양에 담는다.
     *
     * @scenario case=categories
     *
     * @effects provider_categories_follow_operator_settings
     *
     * @return void 반환값 없음
     */
    public function test_categories_follow_operator_settings_in_the_current_locale(): void
    {
        $this->pluginSettingsAre(['cookie_categories' => json_encode([
            ['key' => 'necessary', 'required' => true, 'label' => ['ko' => '필수', 'en' => 'Necessary']],
            ['key' => 'analytics', 'required' => false, 'label' => ['ko' => '분석', 'en' => 'Analytics']],
        ])]);
        app()->setLocale('en');

        $this->assertSame([
            ['key' => 'necessary', 'label' => 'Necessary', 'required' => true],
            ['key' => 'analytics', 'label' => 'Analytics', 'required' => false],
        ], app(GdprConsentProvider::class)->getCategories());
    }

    /**
     * 게스트 요청은 서명을 검증한 `gdpr_session` UUID 로 동의를 찾는다.
     *
     * @scenario case=subject_key
     *
     * @effects provider_answers_consent_session_id_with_the_verified_guest_uuid
     *
     * @return void 반환값 없음
     */
    public function test_guest_subject_key_is_the_verified_gdpr_session_uuid(): void
    {
        $uuid = '11111111-2222-3333-4444-555555555555';

        $this->assertSame($uuid, app(GdprConsentProvider::class)->resolveSubjectKey($this->guestRequest($this->signed($uuid))));
        $this->assertSame($uuid, app(ConsentManager::class)->subjectKey($this->guestRequest($this->signed($uuid))));
    }

    /**
     * 위조 쿠키는 믿지 않고, 회원은 회원 번호로 찾으므로 게스트 식별자가 없다.
     *
     * @scenario case=subject_key
     *
     * @effects provider_answers_consent_session_id_with_the_verified_guest_uuid
     *
     * @return void 반환값 없음
     */
    public function test_forged_cookie_and_member_have_no_guest_subject_key(): void
    {
        $provider = app(GdprConsentProvider::class);

        $this->assertNull($provider->resolveSubjectKey($this->guestRequest('forged|1|abc')));

        $member = $this->guestRequest($this->signed('11111111-2222-3333-4444-555555555555'));
        $member->setUserResolver(fn () => new User);
        $this->assertNull($provider->resolveSubjectKey($member));
    }

    /**
     * 화면 요청(web 그룹)의 쿠키 암호화를 거친 뒤에도 게스트 동의 식별값이 살아 UUID 로 풀린다.
     *
     * 이 쿠키는 API 응답이 서명값 그대로 심는다. 암호화 대상이면 화면 요청에서 복호화에 실패해 null 이 되고,
     * 동의한 게스트의 새로고침이 「미동의」로 제외됐다(#682 S3 라운드 13 브라우저 실측).
     *
     * @scenario case=subject_key
     *
     * @effects guest_consent_cookie_survives_web_cookie_encryption
     *
     * @return void 반환값 없음
     */
    public function test_guest_session_cookie_survives_web_cookie_encryption(): void
    {
        $uuid = '66666666-7777-8888-9999-000000000000';
        $request = Request::create('/board/free', 'GET', [], ['gdpr_session' => $this->signed($uuid)]);

        $seen = null;
        app(EncryptCookies::class)->handle($request, function (Request $passed) use (&$seen) {
            $seen = $passed;

            return response('ok');
        });

        $this->assertNotNull($seen);
        $this->assertSame($uuid, app(GdprConsentProvider::class)->resolveSubjectKey($seen), '서명값이 암호화 미들웨어를 지나도 그대로여야 동의를 찾는다');
    }

    /**
     * 아직 식별되지 않은 게스트는 「모름」이 아니라 「필수만 동의」다 (결정 D19).
     *
     * @scenario case=snapshot_unidentified
     *
     * @effects unidentified_guest_is_necessary_only
     *
     * @return void 반환값 없음
     */
    public function test_unidentified_guest_is_ready_with_necessary_only(): void
    {
        $this->consentsAre([]);

        $snapshot = app(ConsentManager::class)->snapshotFor(ConsentSubject::guest(null));

        $this->assertSame(ConsentSnapshot::STATUS_READY, $snapshot->status);
        $this->assertSame('sirsoft-gdpr', $snapshot->providerId);
        $this->assertSame('v2', $snapshot->policyVersion);
        $this->assertTrue($snapshot->allows('necessary'));
        $this->assertFalse($snapshot->allows('analytics'));
        $this->assertFalse($snapshot->allows('functional'));
    }

    /**
     * 분석 허용은 분석 동의, 식별자 추적 허용은 분석·기능 둘 다 동의해야 참이다.
     *
     * @scenario case=snapshot_consents
     *
     * @effects provider_answers_core_consent_filter_with_analytics_consent, provider_permits_tracking_only_with_both_categories
     *
     * @return void 반환값 없음
     */
    public function test_tracking_requires_both_analytics_and_functional(): void
    {
        $cases = [
            'analytics+functional' => [['analytics' => true, 'functional' => true], true, true],
            'analytics only' => [['analytics' => true, 'functional' => false], true, false],
            'functional only' => [['analytics' => false, 'functional' => true], false, false],
            'none' => [[], false, false],
        ];

        foreach ($cases as $label => [$consents, $analytics, $tracking]) {
            $this->consentsAre($consents);
            $snapshot = app(ConsentManager::class)->snapshotFor(ConsentSubject::guest('guest-uuid'));

            $this->assertSame($analytics, $snapshot->permitsAnalytics(), $label);
            $this->assertSame($tracking, $snapshot->permitsTracking(), $label);
        }

        $this->consentsAre(['analytics' => true, 'functional' => true]);
        $this->assertTrue(app(ConsentManager::class)->snapshotFor(ConsentSubject::member(42))->permitsTracking());
    }

    /**
     * 정책 버전이 바뀌어 현재 동의가 없고 이력만 있으면 재동의가 필요하고, 분석은 허용하지 않는다.
     *
     * @scenario case=snapshot_renewal
     *
     * @effects stale_policy_consent_needs_renewal
     *
     * @return void 반환값 없음
     */
    public function test_stale_policy_version_needs_renewal(): void
    {
        $this->consentsAre([], current: false, history: true);
        $snapshot = app(ConsentManager::class)->snapshotFor(ConsentSubject::member(42));
        $this->assertTrue($snapshot->needsRenewal);
        $this->assertFalse($snapshot->permitsAnalytics());

        $this->consentsAre([], current: false, history: false);
        $this->assertFalse(app(ConsentManager::class)->snapshotFor(ConsentSubject::member(42))->needsRenewal);
    }

    /**
     * 조회가 실패하면 코어가 「모름」으로 닫는다 — 허용으로 새지 않는다.
     *
     * @scenario case=snapshot_failure
     *
     * @effects lookup_failure_fails_closed
     *
     * @return void 반환값 없음
     */
    public function test_lookup_failure_fails_closed(): void
    {
        $this->mock(GdprConsentService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getCurrentPolicyVersion')->andReturn('v2');
            $mock->shouldReceive('getCurrentCookieConsents')->andThrow(new \RuntimeException('db down'));
        });

        $snapshot = app(ConsentManager::class)->snapshotFor(ConsentSubject::member(42));

        $this->assertSame(ConsentSnapshot::STATUS_UNKNOWN, $snapshot->status);
        $this->assertFalse($snapshot->permitsTracking());
    }
}
