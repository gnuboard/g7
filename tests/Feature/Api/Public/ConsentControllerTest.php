<?php

namespace Tests\Feature\Api\Public;

use App\Extension\Consent\ConsentSubject;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\FakesConsentProviders;
use Tests\TestCase;

/**
 * 공개 동의 스냅샷 API `GET /api/consent` (#639, 결정 D4·D5)
 *
 * 브라우저의 코어 동의 런타임이 이 응답으로 `_global.consent` 를 채운다. 다른 확장이 읽는 공개
 * 계약이므로 응답 키는 정확히 5개이고, 회원 번호·게스트 식별자·제공자 내부 값은 싣지 않는다.
 * 같은 브라우저라도 로그인 전(게스트 식별자)과 로그인 후(회원 번호)의 답이 갈려야 한다.
 */
class ConsentControllerTest extends TestCase
{
    use DatabaseTransactions;
    use FakesConsentProviders;

    /**
     * 등록·선택값과 스로틀 카운터를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->resetConsentProviders();
        RateLimiter::clear('consent');
    }

    /**
     * 게스트는 「analytics 허용」, 회원 1번은 「marketing 허용」으로 답하는 제공자를 등록합니다.
     *
     * @return void 반환값 없음
     */
    private function providerAnsweringBySubject(): void
    {
        $this->fakeConsentProvider(
            'fake-consent',
            fn (ConsentSubject $subject): array => $subject->isMember()
                ? ['marketing' => true]
                : ['analytics' => $subject->subjectKey === 'guest-cookie-key'],
            true,
            fn ($request): ?string => $request->cookie('fake_consent_guest'),
        );
    }

    /**
     * 게스트는 제공자가 쿠키에서 구한 식별자 기준으로 답한다.
     *
     * @scenario selection=auto, visitor=guest, status=ready
     *
     * @effects public_api_answers_guest_by_provider_key
     *
     * @return void 반환값 없음
     */
    public function test_guest_is_answered_by_provider_subject_key(): void
    {
        $this->providerAnsweringBySubject();

        $this->withCredentials()->withUnencryptedCookie('fake_consent_guest', 'guest-cookie-key')
            ->getJson('/api/consent')
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.categories.analytics', true)
            ->assertJsonPath('data.categories.necessary', true);
    }

    /**
     * 회원(Bearer)은 같은 브라우저의 게스트 쿠키와 무관하게 회원 기준으로 답한다.
     *
     * @scenario selection=auto, visitor=member, status=ready
     *
     * @effects public_api_answers_member_by_user
     *
     * @return void 반환값 없음
     */
    public function test_member_is_answered_by_user_not_guest_cookie(): void
    {
        $this->providerAnsweringBySubject();
        $token = User::factory()->create()->createToken('t')->plainTextToken;

        $this->withCredentials()->withUnencryptedCookie('fake_consent_guest', 'guest-cookie-key')
            ->withToken($token)
            ->getJson('/api/consent')
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.categories.analytics', false)
            ->assertJsonPath('data.categories.marketing', true);
    }

    /**
     * 제공자가 답하지 못하면 게스트에게 unknown 으로 답한다 (fail-closed).
     *
     * @scenario selection=auto, visitor=guest, status=unknown
     *
     * @effects provider_exception_fails_closed
     *
     * @return void 반환값 없음
     */
    public function test_provider_failure_is_unknown_for_guest(): void
    {
        $this->fakeConsentProvider('fake-consent', function (): array {
            throw new \RuntimeException('provider down');
        });

        $this->getJson('/api/consent')
            ->assertOk()
            ->assertJsonPath('data.status', 'unknown')
            ->assertJsonPath('data.categories.analytics', false);
    }

    /**
     * 동의 관리 제공자가 없으면 none 이다.
     *
     * @scenario selection=none, visitor=guest, status=none
     *
     * @effects no_provider_yields_none_snapshot
     *
     * @return void 반환값 없음
     */
    public function test_no_provider_is_none(): void
    {
        $this->getJson('/api/consent')
            ->assertOk()
            ->assertJsonPath('data.status', 'none')
            ->assertJsonPath('data.contract_version', 1);
    }

    /**
     * 응답은 공개 계약 5키뿐이다 — 식별자가 새지 않는다.
     *
     * @scenario selection=auto, visitor=member, status=ready
     *
     * @effects public_api_exposes_exactly_five_keys
     *
     * @return void 반환값 없음
     */
    public function test_response_has_exactly_the_public_keys(): void
    {
        $this->providerAnsweringBySubject();
        $token = User::factory()->create()->createToken('t')->plainTextToken;

        $data = $this->withToken($token)->getJson('/api/consent')->assertOk()->json('data');

        $this->assertSame(['contract_version', 'status', 'categories', 'needs_renewal', 'policy_version'], array_keys($data));
        $this->assertStringNotContainsString('fake-consent', json_encode($data), '제공자 id 는 내보내지 않는다');
    }

    /**
     * 분당 60회를 넘으면 429 로 막는다.
     *
     * @scenario selection=none, visitor=guest, status=none
     *
     * @effects public_api_is_throttled
     *
     * @return void 반환값 없음
     */
    public function test_61st_request_in_a_minute_is_throttled(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/consent')->assertOk();
        }

        $this->getJson('/api/consent')->assertStatus(429);
    }

    /**
     * 옛 분석 전용 동의 조회 경로는 더는 없다.
     *
     * @scenario selection=none, visitor=guest, status=none
     *
     * @effects legacy_analytics_consent_route_is_removed
     *
     * @return void 반환값 없음
     */
    public function test_legacy_analytics_consent_route_is_gone(): void
    {
        $this->getJson('/api/analytics/consent')->assertNotFound();
    }
}
