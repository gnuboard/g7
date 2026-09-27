<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Unit\Listeners;

use App\Models\User;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Plugins\Sirsoft\Gdpr\Concerns\IssuesGuestSessionCookie;
use Plugins\Sirsoft\Gdpr\Listeners\GdprAnalyticsConsentListener;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * 코어 방문자 통계의 동의 조회 식별자 필터 리스너 테스트 [case:analytics-consent-guest-session-id]
 *
 * 게스트 동의는 서명된 `gdpr_session` 쿠키의 UUID 로 저장된다. 코어가 라라벨 세션 ID 로 물으면
 * 배너에서 동의한 게스트도 「동의 없음」이 되어 동의 연동 모드에서 게스트가 한 명도 세어지지
 * 않는다(브라우저 실측 이슈 #682 §8 M1-T9). 이 리스너가 그 UUID 를 코어에 알려 준다.
 */
class GdprAnalyticsConsentListenerTest extends PluginTestCase
{
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
     * 게스트 쿠키가 달린 수집 요청을 만듭니다.
     *
     * @param  string|null  $cookieValue  `gdpr_session` 쿠키 값
     * @return Request HTTP 요청
     */
    private function collectRequest(?string $cookieValue): Request
    {
        return Request::create('/api/analytics/collect', 'POST', [], $cookieValue === null ? [] : ['gdpr_session' => $cookieValue]);
    }

    /**
     * 게스트 요청은 검증된 `gdpr_session` UUID 로 동의를 묻게 한다.
     *
     * @scenario mode=consent, gdpr=active_consented
     *
     * @effects consent_lookup_uses_the_consent_providers_guest_session_id
     *
     * @return void 없음
     */
    public function test_guest_request_resolves_to_the_verified_gdpr_session_id(): void
    {
        $uuid = '11111111-2222-3333-4444-555555555555';

        $resolved = app(GdprAnalyticsConsentListener::class)
            ->resolveConsentSessionId('laravel-session-id', $this->collectRequest($this->signed($uuid)));

        $this->assertSame($uuid, $resolved);
    }

    /**
     * 위조·형식 오류 쿠키는 믿지 않고 코어가 준 기본값을 그대로 돌려준다.
     *
     * @scenario mode=consent, gdpr=active_declined
     *
     * @effects consent_lookup_uses_the_consent_providers_guest_session_id
     *
     * @return void 없음
     */
    public function test_forged_or_missing_cookie_keeps_the_default(): void
    {
        $listener = app(GdprAnalyticsConsentListener::class);

        $this->assertSame('laravel-session-id', $listener->resolveConsentSessionId('laravel-session-id', $this->collectRequest('forged|1|abc')));
        $this->assertNull($listener->resolveConsentSessionId(null, $this->collectRequest(null)));
    }

    /**
     * 회원은 회원 번호로 동의를 찾으므로 게스트 식별자를 싣지 않는다.
     *
     * @scenario mode=consent, gdpr=active_consented
     *
     * @effects consent_lookup_uses_the_consent_providers_guest_session_id
     *
     * @return void 없음
     */
    public function test_member_request_keeps_the_default(): void
    {
        $request = $this->collectRequest($this->signed('11111111-2222-3333-4444-555555555555'));
        $request->setUserResolver(fn () => new User);

        $this->assertNull(app(GdprAnalyticsConsentListener::class)->resolveConsentSessionId(null, $request));
    }

    /**
     * 필터로 구독하고 요청 문맥에서 동기로 돈다(큐로 넘기면 요청이 없다).
     *
     * @scenario mode=consent, gdpr=active_consented
     *
     * @effects consent_lookup_uses_the_consent_providers_guest_session_id
     *
     * @return void 없음
     */
    public function test_subscribes_to_the_consent_session_filter(): void
    {
        $hooks = GdprAnalyticsConsentListener::getSubscribedHooks();

        $this->assertArrayHasKey('core.analytics.consent_session_id', $hooks);
        $this->assertSame('filter', $hooks['core.analytics.consent_session_id']['type']);
        $this->assertSame('resolveConsentSessionId', $hooks['core.analytics.consent_session_id']['method']);
        $this->assertTrue($hooks['core.analytics.consent_session_id']['sync']);
    }

    /**
     * 화면 요청(web 그룹)의 쿠키 암호화를 거친 뒤에도 게스트 동의 식별값이 살아 UUID 로 풀린다.
     *
     * 이 쿠키는 API 응답이 서명값 그대로 심는다. 암호화 대상이면 화면 요청에서 복호화에 실패해
     * null 이 되고, 동의한 게스트의 새로고침이 「미동의」로 제외됐다(#682 S3 라운드 13 브라우저 실측 —
     * 진단 「동의 없음」 13건이 전부 동의자의 새로고침).
     *
     * @scenario mode=consent, gdpr=active_consented
     *
     * @effects guest_consent_cookie_survives_web_cookie_encryption
     *
     * @return void 없음
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
        $this->assertSame(
            $uuid,
            app(GdprAnalyticsConsentListener::class)->resolveConsentSessionId('laravel-session-id', $seen),
            '서명값이 암호화 미들웨어를 지나도 그대로여야 동의를 찾는다',
        );
    }
}
