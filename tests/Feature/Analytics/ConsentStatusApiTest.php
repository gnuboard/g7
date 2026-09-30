<?php

namespace Tests\Feature\Analytics;

use App\Extension\HookManager;
use App\Models\User;
use App\Services\Analytics\PrivacyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 방문자 동의 상태 조회 API — 코어 필터를 거쳐 답한다.
 *
 * 브라우저 트래커는 동의 연동 모드에서 이 API 로 「이 방문자가 동의했는가」를 미리 안다. 종전에는
 * 트래커가 특정 동의 관리 확장의 경로를 직접 불렀다 — 그 확장이 없거나 다른 확장을 쓰는 사이트에서는
 * 트래커가 틀린 경로를 부르고, 코어가 그 확장에 묶였다. 이제 코어 API 가 코어 필터에 묻고 답한다.
 *
 * @scenario mode=consent, consent_provider=active_consented
 *
 * @effects consent_status_api_answers_through_the_core_filter
 */
class ConsentStatusApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 동의 필터에 답하는 구독자를 겁니다.
     *
     * @param  callable  $answer  필터 콜백
     * @return void 없음
     */
    private function answerConsent(callable $answer): void
    {
        HookManager::clearFilter(PrivacyService::CONSENT_FILTER);
        HookManager::addFilter(PrivacyService::CONSENT_FILTER, $answer);
    }

    /**
     * 테스트가 건 필터를 정리합니다.
     *
     * @return void 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearFilter(PrivacyService::CONSENT_FILTER);

        parent::tearDown();
    }

    /**
     * 동의 모드에서 구독자가 동의했다고 답하면 has_consent=true.
     *
     * @scenario mode=consent, consent_provider=active_consented
     *
     * @effects consent_status_api_answers_through_the_core_filter
     *
     * @return void 없음
     */
    public function test_answers_granted_through_the_core_filter(): void
    {
        config(['g7_settings.core.analytics.privacy_mode' => 'consent']);
        $this->answerConsent(fn ($default) => true);

        $this->getJson('/api/analytics/consent')
            ->assertOk()
            ->assertJsonPath('data.consent_source', PrivacyService::SOURCE_FILTER)
            ->assertJsonPath('data.has_consent', true);
    }

    /**
     * 동의 모드에서 구독자가 거절이라고 답하면 has_consent=false.
     *
     * @scenario mode=consent, consent_provider=active_declined
     *
     * @effects consent_status_api_answers_through_the_core_filter
     *
     * @return void 없음
     */
    public function test_answers_declined_through_the_core_filter(): void
    {
        config(['g7_settings.core.analytics.privacy_mode' => 'consent']);
        $this->answerConsent(fn ($default) => false);

        $this->getJson('/api/analytics/consent')
            ->assertOk()
            ->assertJsonPath('data.has_consent', false);
    }

    /**
     * 답하는 구독자가 없으면 판정 대상이 아니다 — has_consent=null, 출처 none.
     *
     * @scenario mode=consent, consent_provider=absent
     *
     * @effects consent_status_api_answers_through_the_core_filter
     *
     * @return void 없음
     */
    public function test_without_subscribers_there_is_nothing_to_answer(): void
    {
        config(['g7_settings.core.analytics.privacy_mode' => 'consent']);
        HookManager::clearFilter(PrivacyService::CONSENT_FILTER);
        HookManager::clearFilter(PrivacyService::TRACKING_PERMISSION_FILTER);

        $this->getJson('/api/analytics/consent')
            ->assertOk()
            ->assertJsonPath('data.consent_source', PrivacyService::SOURCE_NONE)
            ->assertJsonPath('data.has_consent', null);
    }

    /**
     * 동의 연동 모드가 아니면 동의를 게이트로 쓰지 않으므로 묻지 않는다.
     *
     * @scenario mode=full, consent_provider=active_declined
     *
     * @effects consent_status_api_answers_through_the_core_filter
     *
     * @return void 없음
     */
    public function test_other_modes_do_not_ask(): void
    {
        config(['g7_settings.core.analytics.privacy_mode' => 'full']);
        $asked = false;
        $this->answerConsent(function ($default) use (&$asked) {
            $asked = true;

            return false;
        });

        $this->getJson('/api/analytics/consent')
            ->assertOk()
            ->assertJsonPath('data.has_consent', null);
        $this->assertFalse($asked);
    }

    /**
     * 로그인 회원은 회원 번호로 묻는다.
     *
     * @scenario mode=consent, consent_provider=active_consented, visitor=member
     *
     * @effects consent_status_api_answers_through_the_core_filter
     *
     * @return void 없음
     */
    public function test_member_is_asked_by_user_id(): void
    {
        config(['g7_settings.core.analytics.privacy_mode' => 'consent']);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $seenUserId = null;
        $this->answerConsent(function ($default, $userId = null) use (&$seenUserId) {
            $seenUserId = $userId;

            return true;
        });

        $this->getJson('/api/analytics/consent')->assertOk()->assertJsonPath('data.has_consent', true);
        $this->assertSame($user->id, $seenUserId);
    }
}
