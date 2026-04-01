<?php

namespace Plugins\Sirsoft\Marketing\Tests\Unit\Services;

use App\Models\User;
use App\Services\PluginSettingsService;
use Plugins\Sirsoft\Marketing\Contracts\MarketingConsentRepositoryInterface;
use Plugins\Sirsoft\Marketing\Models\MarketingConsent;
use Plugins\Sirsoft\Marketing\Models\MarketingConsentHistory;
use Plugins\Sirsoft\Marketing\Repositories\MarketingConsentRepository;
use Plugins\Sirsoft\Marketing\Services\MarketingConsentService;
use Plugins\Sirsoft\Marketing\Tests\PluginTestCase;

class MarketingConsentServiceTest extends PluginTestCase
{
    private MarketingConsentService $service;

    /** @var PluginSettingsService&\PHPUnit\Framework\MockObject\MockObject */
    private PluginSettingsService $pluginSettings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pluginSettings = $this->createMock(PluginSettingsService::class);
        // 기본적으로 channels JSON 반환 + 모든 법적 항목 활성화 (*_enabled = true)
        $this->pluginSettings->method('get')->willReturnCallback(
            function (string $id, string $key, mixed $default = null) {
                if ($key === 'channels') {
                    return json_encode([
                        [
                            'key'       => 'email_subscription',
                            'label'     => ['ko' => '광고성 이메일 수신', 'en' => 'Email Marketing'],
                            'page_slug' => '',
                            'enabled'   => true,
                            'is_system' => true,
                        ],
                    ]);
                }

                return $default ?? true;
            }
        );

        $repository = new MarketingConsentRepository();
        $this->service = new MarketingConsentService($repository, $this->pluginSettings);
    }

    /**
     * 특정 channels JSON을 반환하는 서비스 인스턴스를 생성합니다.
     *
     * @param array<int, array{key: string, enabled: bool}> $channels 채널 목록
     * @return MarketingConsentService
     */
    private function makeServiceWithChannels(array $channels): MarketingConsentService
    {
        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('get')->willReturnCallback(
            function (string $id, string $key, mixed $default = null) use ($channels) {
                if ($key === 'channels') {
                    return json_encode($channels);
                }

                return $default ?? true;
            }
        );

        return new MarketingConsentService(new MarketingConsentRepository(), $mock);
    }

    // ── getAllByUserId ──

    public function test_get_all_by_user_id_returns_empty_when_no_consent(): void
    {
        $user = User::factory()->create();

        $result = $this->service->getAllByUserId($user->id);

        $this->assertCount(0, $result);
    }

    public function test_get_all_by_user_id_returns_eav_records(): void
    {
        $user = User::factory()->create();

        $this->service->updateConsent($user->id, 'email_subscription', true, 'register');
        $this->service->updateConsent($user->id, 'marketing_consent', true, 'register');

        $result = $this->service->getAllByUserId($user->id);

        $this->assertCount(2, $result);

        $keys = $result->pluck('consent_key')->sort()->values()->toArray();
        $this->assertEquals(['email_subscription', 'marketing_consent'], $keys);
    }

    // ── updateConsent: 단일 항목 동의/철회 ──

    public function test_update_consent_creates_new_record_when_granted(): void
    {
        $user = User::factory()->create();

        $this->service->updateConsent($user->id, 'email_subscription', true, 'register');

        $record = MarketingConsent::where('user_id', $user->id)
            ->where('consent_key', 'email_subscription')
            ->first();

        $this->assertNotNull($record);
        $this->assertTrue($record->is_consented);
        $this->assertNotNull($record->consented_at);
        $this->assertNull($record->revoked_at);
        $this->assertEquals('register', $record->last_source);
        $this->assertEquals(1, $record->consent_count);
    }

    public function test_update_consent_sets_revoked_at_when_false(): void
    {
        $user = User::factory()->create();

        $this->service->updateConsent($user->id, 'email_subscription', true, 'register');
        $this->service->updateConsent($user->id, 'email_subscription', false, 'admin');

        $record = MarketingConsent::where('user_id', $user->id)
            ->where('consent_key', 'email_subscription')
            ->first();

        $this->assertFalse($record->is_consented);
        $this->assertNull($record->consented_at);
        $this->assertNotNull($record->revoked_at);
        $this->assertEquals('admin', $record->last_source);
    }

    public function test_update_consent_records_history(): void
    {
        $user = User::factory()->create();

        $this->service->updateConsent($user->id, 'email_subscription', true, 'register');

        $history = MarketingConsentHistory::where('user_id', $user->id)->first();

        $this->assertNotNull($history);
        $this->assertEquals('email_subscription', $history->channel_key);
        $this->assertEquals('granted', $history->action);
        $this->assertEquals('register', $history->source);
    }

    // ── 정합성 규칙: master 철회 시 채널 자동 철회 ──

    public function test_revoking_master_auto_revokes_all_channels(): void
    {
        $user = User::factory()->create();

        // 채널 먼저 동의
        $this->service->updateConsent($user->id, 'email_subscription', true, 'register');
        $this->service->updateConsent($user->id, MarketingConsent::MASTER_KEY, true, 'register');

        // master 철회
        $this->service->updateConsent($user->id, MarketingConsent::MASTER_KEY, false, 'admin');

        $emailRecord = MarketingConsent::where('user_id', $user->id)
            ->where('consent_key', 'email_subscription')
            ->first();

        $this->assertFalse($emailRecord->is_consented);
    }

    // ── 정합성 규칙: 채널 동의 시 master 자동 활성화 ──

    public function test_granting_channel_auto_activates_master(): void
    {
        $user = User::factory()->create();

        // master 미동의 상태에서 채널 동의
        $this->service->updateConsent($user->id, 'email_subscription', true, 'register');

        $masterRecord = MarketingConsent::where('user_id', $user->id)
            ->where('consent_key', MarketingConsent::MASTER_KEY)
            ->first();

        $this->assertNotNull($masterRecord);
        $this->assertTrue($masterRecord->is_consented);
    }

    // ── updateConsents: 일괄 업데이트 ──

    public function test_update_consents_updates_multiple_keys(): void
    {
        $user = User::factory()->create();

        $this->service->updateConsents($user->id, [
            'email_subscription' => true,
            'third_party_consent' => false,
        ], 'register');

        $consents = $this->service->getAllByUserId($user->id)->keyBy('consent_key');

        $emailRecord = $consents->get('email_subscription');
        $this->assertNotNull($emailRecord);
        $this->assertTrue($emailRecord->is_consented);

        $thirdRecord = $consents->get('third_party_consent');
        $this->assertNotNull($thirdRecord);
        $this->assertFalse($thirdRecord->is_consented);
    }

    // ── getHistories ──

    public function test_get_histories_returns_empty_when_no_history(): void
    {
        $user = User::factory()->create();

        $result = $this->service->getHistories($user->id);

        $this->assertCount(0, $result);
    }

    public function test_get_histories_ordered_by_desc(): void
    {
        $user = User::factory()->create();

        // email 동의 시 master(marketing_consent)도 자동 활성화 → 이력 2건
        // email 철회 → 이력 1건 추가 (총 3건)
        $this->service->updateConsent($user->id, 'email_subscription', true, 'register');
        $this->service->updateConsent($user->id, 'email_subscription', false, 'profile');

        $histories = $this->service->getHistories($user->id);

        // 최근 이력(revoked)이 먼저 나와야 함
        $this->assertEquals('revoked', $histories->first()->action);
        $this->assertEquals('email_subscription', $histories->first()->channel_key);
    }

    // ── deleteByUserId ──

    public function test_delete_by_user_id_removes_all_records(): void
    {
        $user = User::factory()->create();

        $this->service->updateConsent($user->id, 'email_subscription', true, 'register');
        $this->service->updateConsent($user->id, 'marketing_consent', true, 'register');

        $this->service->deleteByUserId($user->id);

        $this->assertCount(0, MarketingConsent::where('user_id', $user->id)->get());
        $this->assertCount(0, MarketingConsentHistory::where('user_id', $user->id)->get());
    }

    // ── *_enabled 기반 채널/법적 항목 필터링 ──

    public function test_get_registered_channels_excludes_channel_when_disabled(): void
    {
        $service = $this->makeServiceWithChannels([
            ['key' => 'email_subscription', 'label' => ['ko' => '광고성 이메일 수신', 'en' => 'Email Marketing'], 'page_slug' => '', 'enabled' => false, 'is_system' => true],
            ['key' => 'sms_subscription', 'label' => ['ko' => 'SMS', 'en' => 'SMS'], 'page_slug' => '', 'enabled' => true, 'is_system' => false],
        ]);

        $channels = $service->getRegisteredChannels();

        $keys = array_column($channels, 'key');
        $this->assertNotContains('email_subscription', $keys);
        $this->assertContains('sms_subscription', $keys);
    }

    public function test_get_registered_channels_includes_channel_when_enabled(): void
    {
        $service = $this->makeServiceWithChannels([
            ['key' => 'email_subscription', 'label' => ['ko' => '광고성 이메일 수신', 'en' => 'Email Marketing'], 'page_slug' => '', 'enabled' => true, 'is_system' => true],
        ]);

        $channels = $service->getRegisteredChannels();

        $keys = array_column($channels, 'key');
        $this->assertContains('email_subscription', $keys);
    }

    public function test_get_enabled_legal_keys_excludes_key_when_disabled(): void
    {
        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('get')->willReturnCallback(
            function (string $id, string $key, mixed $default = null) {
                if ($key === 'third_party_consent_enabled') {
                    return false;
                }

                return $default ?? true;
            }
        );
        $service = new MarketingConsentService(new MarketingConsentRepository(), $mock);

        $keys = $service->getEnabledLegalKeys();

        $this->assertNotContains('third_party_consent', $keys);
        $this->assertContains('info_disclosure', $keys);
    }

    public function test_get_enabled_legal_keys_returns_all_when_all_enabled(): void
    {
        $keys = $this->service->getEnabledLegalKeys();

        $this->assertContains('third_party_consent', $keys);
        $this->assertContains('info_disclosure', $keys);
    }

    // ── consent_count 증가 ──

    public function test_consent_count_increments_on_each_grant(): void
    {
        $user = User::factory()->create();

        $this->service->updateConsent($user->id, 'email_subscription', true, 'register');
        $this->service->updateConsent($user->id, 'email_subscription', false, 'admin');
        $this->service->updateConsent($user->id, 'email_subscription', true, 'profile');

        $record = MarketingConsent::where('user_id', $user->id)
            ->where('consent_key', 'email_subscription')
            ->first();

        $this->assertEquals(2, $record->consent_count);
    }
}
