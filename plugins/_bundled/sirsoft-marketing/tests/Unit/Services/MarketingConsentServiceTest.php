<?php

namespace Plugins\Sirsoft\Marketing\Tests\Unit\Services;

use App\Extension\HookManager;
use App\Models\User;
use App\Services\PluginSettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use Plugins\Sirsoft\Marketing\Models\MarketingConsent;
use Plugins\Sirsoft\Marketing\Models\MarketingConsentHistory;
use Plugins\Sirsoft\Marketing\Repositories\MarketingConsentRepository;
use Plugins\Sirsoft\Marketing\Services\MarketingConsentService;
use Plugins\Sirsoft\Marketing\Tests\PluginTestCase;

class MarketingConsentServiceTest extends PluginTestCase
{
    private MarketingConsentService $service;

    /** @var PluginSettingsService&MockObject */
    private PluginSettingsService $pluginSettings;

    /**
     * 테스트용 설정 서비스·저장소로 동의 서비스를 준비합니다.
     *
     * @return void 반환값 없음
     */
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
                            'key' => 'email_subscription',
                            'label' => ['ko' => '광고성 이메일 수신', 'en' => 'Email Marketing'],
                            'page_slug' => '',
                            'enabled' => true,
                            'is_system' => true,
                        ],
                    ]);
                }

                return $default ?? true;
            }
        );

        $repository = new MarketingConsentRepository;
        $this->service = new MarketingConsentService($repository, $this->pluginSettings);
    }

    /**
     * 특정 channels JSON을 반환하는 서비스 인스턴스를 생성합니다.
     *
     * @param  array<int, array{key: string, enabled: bool}>  $channels  채널 목록
     * @return MarketingConsentService 채널 설정을 주입한 동의 서비스
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

        return new MarketingConsentService(new MarketingConsentRepository, $mock);
    }

    // ── getAllByUserId ──

    /**
     * 동의 기록이 없으면 빈 목록을 돌려준다.
     *
     * @scenario case=read
     *
     * @effects consent_records_read_per_user
     *
     * @return void 반환값 없음
     */
    public function test_get_all_by_user_id_returns_empty_when_no_consent(): void
    {
        $user = User::factory()->create();

        $result = $this->service->getAllByUserId($user->id);

        $this->assertCount(0, $result);
    }

    /**
     * 회원의 동의 항목을 항목별 행으로 돌려준다.
     *
     * @scenario case=read
     *
     * @effects consent_records_read_per_user
     *
     * @return void 반환값 없음
     */
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

    /**
     * 동의하면 새 동의 행을 만든다.
     *
     * @scenario case=update_one
     *
     * @effects consent_state_saved_with_history
     *
     * @return void 반환값 없음
     */
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

    /**
     * 철회하면 철회 시각을 남긴다.
     *
     * @scenario case=update_one
     *
     * @effects consent_state_saved_with_history
     *
     * @return void 반환값 없음
     */
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

    /**
     * 동의 변경마다 이력을 남긴다.
     *
     * @scenario case=update_one
     *
     * @effects consent_state_saved_with_history
     *
     * @return void 반환값 없음
     */
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

    /**
     * 전체 동의를 철회하면 채널 동의도 모두 철회된다.
     *
     * @scenario case=update_one
     *
     * @effects master_and_channel_consents_stay_consistent
     *
     * @return void 반환값 없음
     */
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

    /**
     * 채널에 동의하면 전체 동의가 자동으로 켜진다.
     *
     * @scenario case=update_one
     *
     * @effects master_and_channel_consents_stay_consistent
     *
     * @return void 반환값 없음
     */
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

    /**
     * 여러 동의 항목을 한 번에 저장한다.
     *
     * @scenario case=update_bulk
     *
     * @effects consent_state_saved_with_history
     *
     * @return void 반환값 없음
     */
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

    /**
     * 일괄 저장 전에 동의 데이터가 `sirsoft-marketing.filter_consent_data` 필터를 거친다.
     *
     * 선언만 있고 발화처가 없어 이 필터를 구독한 확장이 한 번도 불리지 않았다(#138 후속 — 문서에만 있는 훅은
     * 발화를 만든다). 인자는 (동의 데이터, 회원 ID, 변경 경로)다.
     *
     * @scenario case=update_bulk
     *
     * @effects bulk_consent_data_passes_filter_hook
     *
     * @return void 반환값 없음
     */
    public function test_update_consents_passes_data_through_filter(): void
    {
        $user = User::factory()->create();
        $seen = [];
        HookManager::addFilter('sirsoft-marketing.filter_consent_data', function (array $data, int $userId, string $source) use (&$seen) {
            $seen = [$userId, $source];
            unset($data['third_party_consent']);

            return $data;
        }, 5);

        $this->service->updateConsents($user->id, [
            'email_subscription' => true,
            'third_party_consent' => true,
        ], 'register');

        $consents = $this->service->getAllByUserId($user->id)->keyBy('consent_key');

        $this->assertSame([$user->id, 'register'], $seen);
        $this->assertTrue($consents->get('email_subscription')?->is_consented ?? false);
        $this->assertNull($consents->get('third_party_consent'), '필터가 뺀 항목은 저장되지 않는다');
    }

    // ── getHistories ──

    /**
     * 이력이 없으면 빈 목록을 돌려준다.
     *
     * @scenario case=history
     *
     * @effects consent_history_listed_newest_first
     *
     * @return void 반환값 없음
     */
    public function test_get_histories_returns_empty_when_no_history(): void
    {
        $user = User::factory()->create();

        $result = $this->service->getHistories($user->id);

        $this->assertCount(0, $result);
    }

    /**
     * 이력은 최신순이다.
     *
     * @scenario case=history
     *
     * @effects consent_history_listed_newest_first
     *
     * @return void 반환값 없음
     */
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

    /**
     * 회원 삭제 시 동의·이력을 모두 지운다.
     *
     * @scenario case=delete
     *
     * @effects consent_data_deleted_with_user
     *
     * @return void 반환값 없음
     */
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

    /**
     * 꺼진 채널은 등록 채널 목록에서 빠진다.
     *
     * @scenario case=channels
     *
     * @effects channel_list_follows_settings
     *
     * @return void 반환값 없음
     */
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

    /**
     * 켜진 채널은 등록 채널 목록에 들어간다.
     *
     * @scenario case=channels
     *
     * @effects channel_list_follows_settings
     *
     * @return void 반환값 없음
     */
    public function test_get_registered_channels_includes_channel_when_enabled(): void
    {
        $service = $this->makeServiceWithChannels([
            ['key' => 'email_subscription', 'label' => ['ko' => '광고성 이메일 수신', 'en' => 'Email Marketing'], 'page_slug' => '', 'enabled' => true, 'is_system' => true],
        ]);

        $channels = $service->getRegisteredChannels();

        $keys = array_column($channels, 'key');
        $this->assertContains('email_subscription', $keys);
    }

    /**
     * 꺼진 법정 항목은 활성 키 목록에서 빠진다.
     *
     * @scenario case=channels
     *
     * @effects channel_list_follows_settings
     *
     * @return void 반환값 없음
     */
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
        $service = new MarketingConsentService(new MarketingConsentRepository, $mock);

        $keys = $service->getEnabledLegalKeys();

        $this->assertNotContains('third_party_consent', $keys);
        $this->assertContains('info_disclosure', $keys);
    }

    /**
     * 모두 켜져 있으면 법정 항목을 모두 돌려준다.
     *
     * @scenario case=channels
     *
     * @effects channel_list_follows_settings
     *
     * @return void 반환값 없음
     */
    public function test_get_enabled_legal_keys_returns_all_when_all_enabled(): void
    {
        $keys = $this->service->getEnabledLegalKeys();

        $this->assertContains('third_party_consent', $keys);
        $this->assertContains('info_disclosure', $keys);
    }

    // ── consent_count 증가 ──

    /**
     * 동의할 때마다 동의 수가 늘어난다.
     *
     * @scenario case=read
     *
     * @effects consent_records_read_per_user
     *
     * @return void 반환값 없음
     */
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

    // ── getDefaultSystemChannels (registry payload name_key 계약, 7.0.0-beta.4+) ──

    /**
     * 기본 시스템 채널은 라벨 키를 가진다.
     *
     * @scenario case=channels
     *
     * @effects channel_labels_resolve_by_locale
     *
     * @return void 반환값 없음
     */
    public function test_default_system_channels_carry_label_key(): void
    {
        $channels = $this->service->getDefaultSystemChannels();

        $this->assertCount(1, $channels);
        $email = $channels[0];

        $this->assertSame('email_subscription', $email['key']);
        $this->assertSame('sirsoft-marketing::channels.email_subscription.label', $email['label_key']);
        $this->assertTrue($email['is_system']);
    }

    /**
     * 기본 시스템 채널 라벨은 언어팩으로 풀린다.
     *
     * @scenario case=channels
     *
     * @effects channel_labels_resolve_by_locale
     *
     * @return void 반환값 없음
     */
    public function test_default_system_channels_label_resolves_via_lang_pack(): void
    {
        // label 은 lang 파일에서 해석된 ko/en 값으로 자동 채워져 plugin_settings 저장 시 fallback 보장
        $channels = $this->service->getDefaultSystemChannels();
        $email = $channels[0];

        $this->assertIsArray($email['label']);
        $this->assertArrayHasKey('ko', $email['label']);
        $this->assertArrayHasKey('en', $email['label']);
        // 활성 lang 파일이 등록되어 있으면 실제 라벨, 미등록 환경이면 키 그대로 (둘 다 string)
        $this->assertIsString($email['label']['ko']);
        $this->assertIsString($email['label']['en']);
    }
}
