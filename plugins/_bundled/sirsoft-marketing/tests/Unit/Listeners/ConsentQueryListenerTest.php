<?php

namespace Plugins\Sirsoft\Marketing\Tests\Unit\Listeners;

use App\Models\User;
use Mockery;
use Plugins\Sirsoft\Marketing\Listeners\ConsentQueryListener;
use Plugins\Sirsoft\Marketing\Services\MarketingConsentService;
use Plugins\Sirsoft\Marketing\Tests\PluginTestCase;

/**
 * 수신동의 조회 필터 (1.1.5)
 *
 * 다른 확장(커머스 광고성 쿠폰 알림)이 `sirsoft-marketing.consent.is_consented` 로 동의 여부를 묻는다.
 */
class ConsentQueryListenerTest extends PluginTestCase
{
    /**
     * Mockery 를 닫습니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 서비스 응답을 정한 리스너를 만듭니다.
     *
     * @param  bool|null  $consented  서비스가 돌려줄 값 (null 이면 호출되면 안 된다)
     * @return ConsentQueryListener 리스너
     */
    private function listener(?bool $consented): ConsentQueryListener
    {
        $service = Mockery::mock(MarketingConsentService::class);
        if ($consented === null) {
            $service->shouldNotReceive('isConsented');
        } else {
            $service->shouldReceive('isConsented')->with(7, 'marketing_consent')->once()->andReturn($consented);
        }

        return new ConsentQueryListener($service);
    }

    /**
     * 동의했으면 true, 철회했거나 기록이 없으면(서비스가 false) false 를 돌려준다.
     *
     * @scenario case=consent_is_consented_consented_or_not
     *
     * @effects consent_query_answers
     *
     * @return void 반환값 없음
     */
    public function test_answers_consent_state(): void
    {
        $this->assertTrue($this->listener(true)->isConsented(null, 7, 'marketing_consent'));
        $this->assertFalse($this->listener(false)->isConsented(null, 7, 'marketing_consent'));
    }

    /**
     * 회원 ID 가 숫자가 아니거나 0 이하, 키가 문자열이 아니거나 비었으면 묻지 않고 앞선 값(불리언일 때만)을 그대로 둔다.
     *
     * @scenario case=consent_is_consented_invalid_args
     *
     * @effects consent_query_ignores_invalid_args
     *
     * @return void 반환값 없음
     */
    public function test_invalid_arguments_pass_through(): void
    {
        $this->assertNull($this->listener(null)->isConsented(null, 'abc', 'marketing_consent'));
        $this->assertNull($this->listener(null)->isConsented(null, 0, 'marketing_consent'));
        $this->assertNull($this->listener(null)->isConsented(null, 7, ['marketing_consent']));
        $this->assertNull($this->listener(null)->isConsented(null, 7, ''));
        $this->assertTrue($this->listener(null)->isConsented(true, 'abc', 'marketing_consent'));
        $this->assertNull($this->listener(null)->isConsented('yes', 'abc', 'marketing_consent'));
    }

    /**
     * 실제 동의 기록으로 — 동의했다가 철회한 회원은 false, 기록이 아예 없는 회원도 false, 동의 중인 회원은 true.
     *
     * @scenario case=consent_is_consented_revoked_vs_no_record
     *
     * @effects consent_query_answers
     *
     * @return void 반환값 없음
     */
    public function test_revoked_and_missing_records_are_both_not_consented(): void
    {
        $service = app(MarketingConsentService::class);
        $listener = new ConsentQueryListener($service);
        $revoked = User::factory()->create();
        $never = User::factory()->create();
        $consenting = User::factory()->create();

        $service->updateConsent($revoked->id, 'marketing_consent', true, 'profile');
        $service->updateConsent($revoked->id, 'marketing_consent', false, 'profile');
        $service->updateConsent($consenting->id, 'marketing_consent', true, 'profile');

        $this->assertDatabaseHas('user_marketing_consents', ['user_id' => $revoked->id, 'consent_key' => 'marketing_consent', 'is_consented' => false]);
        $this->assertDatabaseMissing('user_marketing_consents', ['user_id' => $never->id]);

        $this->assertFalse($listener->isConsented(null, $revoked->id, 'marketing_consent'), '철회');
        $this->assertFalse($listener->isConsented(null, $never->id, 'marketing_consent'), '기록 없음');
        $this->assertTrue($listener->isConsented(null, $consenting->id, 'marketing_consent'));
    }

    /**
     * 필터로 동기 구독한다 (큐로 가면 응답을 돌려줄 수 없다).
     *
     * @scenario case=consent_is_consented_subscription
     *
     * @effects consent_query_answers
     *
     * @return void 반환값 없음
     */
    public function test_subscribes_as_sync_filter(): void
    {
        $hook = ConsentQueryListener::getSubscribedHooks()['sirsoft-marketing.consent.is_consented'];

        $this->assertSame('filter', $hook['type']);
        $this->assertTrue($hook['sync']);
        $this->assertSame('isConsented', $hook['method']);
    }
}
