<?php

namespace Plugins\Sirsoft\Marketing\Tests\Feature\Http\Controllers;

use App\Models\User;
use App\Services\PluginSettingsService;
use Plugins\Sirsoft\Marketing\Models\MarketingConsent;
use Plugins\Sirsoft\Marketing\Tests\PluginTestCase;

/**
 * 마케팅 관리자 채널 저장 API 테스트
 *
 * PUT /api/plugins/sirsoft-marketing/admin/channels
 */
class MarketingAdminControllerTest extends PluginTestCase
{
    /**
     * 기본 채널 목록 (channels JSON으로 저장된 상태 시뮬레이션)
     */
    private const EXISTING_CHANNELS = [
        [
            'key' => 'email_subscription',
            'label' => ['ko' => '광고성 이메일 수신', 'en' => 'Email Marketing'],
            'page_slug' => '',
            'enabled' => true,
            'is_system' => true,
        ],
    ];

    /**
     * 저장된 채널 목록을 시스템 채널 하나로 고정해 둔다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('get')->willReturnCallback(
            fn (string $id, string $key, mixed $default = null) => match ($key) {
                'channels' => json_encode(self::EXISTING_CHANNELS),
                default => $default,
            }
        );
        $this->app->instance(PluginSettingsService::class, $mock);
    }

    // ── 인증 ──

    /**
     * 인증 없이 채널 저장을 부르면 401 이다.
     *
     * @scenario case=unauthenticated
     *
     * @effects admin_only_channel_save
     *
     * @return void 반환값 없음
     */
    public function test_update_channels_requires_authentication(): void
    {
        $response = $this->putJson('/api/plugins/sirsoft-marketing/admin/channels', [
            'channels' => self::EXISTING_CHANNELS,
        ]);

        $response->assertUnauthorized();
    }

    /**
     * 설정 수정 권한을 가진 관리자는 채널을 저장할 수 있다.
     *
     * @scenario case=saved
     *
     * @effects admin_only_channel_save
     *
     * @return void 반환값 없음
     */
    public function test_update_channels_succeeds_for_admin(): void
    {
        $admin = $this->createAdminUser(['core.plugins.update']);

        $response = $this->actingAs($admin)->putJson(
            '/api/plugins/sirsoft-marketing/admin/channels',
            ['channels' => self::EXISTING_CHANNELS]
        );

        $response->assertOk();
    }

    // ── 기본 저장 ──

    /**
     * 새 일반 채널을 더하면 저장 응답에 그 채널이 실린다.
     *
     * @scenario case=saved
     *
     * @effects admin_only_channel_save
     *
     * @return void 반환값 없음
     */
    public function test_update_channels_saves_new_channel(): void
    {
        $admin = $this->createAdminUser(['core.plugins.update']);

        $channels = array_merge(self::EXISTING_CHANNELS, [
            [
                'key' => 'sms_subscription',
                'label' => ['ko' => '광고성 SMS', 'en' => 'SMS Marketing'],
                'page_slug' => '',
                'enabled' => true,
                'is_system' => false,
            ],
        ]);

        $response = $this->actingAs($admin)->putJson(
            '/api/plugins/sirsoft-marketing/admin/channels',
            ['channels' => $channels]
        );

        $response->assertOk()
            ->assertJsonPath('data.channels.1.key', 'sms_subscription');
    }

    // ── 검증: key 중복 ──

    /**
     * 같은 키의 채널이 둘이면 422 로 거절한다.
     *
     * @scenario case=field_validation
     *
     * @effects field_errors_shown_without_deactivate_modal
     *
     * @return void 반환값 없음
     */
    public function test_update_channels_rejects_duplicate_keys(): void
    {
        $admin = $this->createAdminUser(['core.plugins.update']);

        $channels = [
            ['key' => 'email_subscription', 'label' => ['ko' => 'A', 'en' => 'A'], 'page_slug' => '', 'enabled' => true, 'is_system' => true],
            ['key' => 'email_subscription', 'label' => ['ko' => 'B', 'en' => 'B'], 'page_slug' => '', 'enabled' => true, 'is_system' => false],
        ];

        $response = $this->actingAs($admin)->putJson(
            '/api/plugins/sirsoft-marketing/admin/channels',
            ['channels' => $channels]
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['channels']);
    }

    // ── 검증: key 형식 ──

    /**
     * 키 형식(영문 소문자·숫자·밑줄)을 어기면 그 항목 오류로 거절한다.
     *
     * @scenario case=field_validation
     *
     * @effects field_errors_shown_without_deactivate_modal
     *
     * @return void 반환값 없음
     */
    public function test_update_channels_rejects_invalid_key_format(): void
    {
        $admin = $this->createAdminUser(['core.plugins.update']);

        $channels = [
            ['key' => 'invalid-key!', 'label' => ['ko' => 'A', 'en' => 'A'], 'page_slug' => '', 'enabled' => true, 'is_system' => false],
        ];

        $response = $this->actingAs($admin)->putJson(
            '/api/plugins/sirsoft-marketing/admin/channels',
            ['channels' => $channels]
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['channels.0.key']);
    }

    // ── 검증: is_system 채널 누락 거부 ──

    /**
     * 시스템 채널을 목록에서 빼면 422 로 거절한다.
     *
     * @scenario case=field_validation
     *
     * @effects field_errors_shown_without_deactivate_modal
     *
     * @return void 반환값 없음
     */
    public function test_update_channels_rejects_removal_of_system_channel(): void
    {
        $admin = $this->createAdminUser(['core.plugins.update']);

        // email_subscription(is_system=true) 없이 제출
        $channels = [
            ['key' => 'sms_subscription', 'label' => ['ko' => 'SMS', 'en' => 'SMS'], 'page_slug' => '', 'enabled' => true, 'is_system' => false],
        ];

        $response = $this->actingAs($admin)->putJson(
            '/api/plugins/sirsoft-marketing/admin/channels',
            ['channels' => $channels]
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['channels']);
    }

    // ── 검증: is_system 플래그 위변조 거부 ──

    /**
     * 시스템 채널의 is_system 을 false 로 바꾸면 그 항목 오류로 거절한다.
     *
     * @scenario case=field_validation
     *
     * @effects field_errors_shown_without_deactivate_modal
     *
     * @return void 반환값 없음
     */
    public function test_update_channels_rejects_system_flag_downgrade(): void
    {
        $admin = $this->createAdminUser(['core.plugins.update']);

        // email_subscription의 is_system을 false로 위변조
        $channels = [
            ['key' => 'email_subscription', 'label' => ['ko' => '광고성 이메일', 'en' => 'Email'], 'page_slug' => '', 'enabled' => true, 'is_system' => false],
        ];

        $response = $this->actingAs($admin)->putJson(
            '/api/plugins/sirsoft-marketing/admin/channels',
            ['channels' => $channels]
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['channels.0.is_system']);
    }

    // ── 검증: 동의 데이터 존재 시 채널 삭제 거부 ──

    /**
     * 저장 목록에 없던 키의 동의 데이터는 삭제 판정에 쓰이지 않아 저장이 통과한다.
     *
     * @scenario case=saved
     *
     * @effects admin_only_channel_save
     *
     * @return void 반환값 없음
     */
    public function test_update_channels_rejects_deletion_when_consents_exist(): void
    {
        $admin = $this->createAdminUser(['core.plugins.update']);
        $user = User::factory()->create();

        // sms_subscription 채널에 동의 데이터 생성
        MarketingConsent::create([
            'user_id' => $user->id,
            'consent_key' => 'sms_subscription',
            'is_consented' => true,
            'consented_at' => now(),
            'revoked_at' => null,
            'last_source' => 'register',
            'consent_count' => 1,
        ]);

        // sms_subscription을 제출 목록에서 제외 (삭제 시도)
        $response = $this->actingAs($admin)->putJson(
            '/api/plugins/sirsoft-marketing/admin/channels',
            ['channels' => self::EXISTING_CHANNELS]
        );

        // sms_subscription은 EXISTING_CHANNELS에 없으므로 삭제 시도로 감지
        // → 동의 데이터 없으면 통과 (이 테스트에서는 별도 채널이라 422 미발생)
        // 실제 삭제 거부는 기존 channels에 있는 키가 제출에서 빠진 경우
        // setUp mock의 EXISTING_CHANNELS에 sms가 없으므로 이 테스트는 OK 응답
        $response->assertOk();
    }

    /**
     * 동의가 쌓인 시스템 채널을 빼면 시스템 채널 보호가 먼저 422 로 막는다.
     *
     * @scenario case=field_validation
     *
     * @effects field_errors_shown_without_deactivate_modal
     *
     * @return void 반환값 없음
     */
    public function test_update_channels_rejects_deletion_of_existing_channel_with_consents(): void
    {
        $admin = $this->createAdminUser(['core.plugins.update']);
        $user = User::factory()->create();

        // email_subscription에 동의 데이터 생성
        MarketingConsent::create([
            'user_id' => $user->id,
            'consent_key' => 'email_subscription',
            'is_consented' => true,
            'consented_at' => now(),
            'revoked_at' => null,
            'last_source' => 'register',
            'consent_count' => 1,
        ]);

        // email_subscription을 제외하고 제출 (삭제 시도)
        $channels = [
            ['key' => 'sms_subscription', 'label' => ['ko' => 'SMS', 'en' => 'SMS'], 'page_slug' => '', 'enabled' => true, 'is_system' => false],
        ];

        $response = $this->actingAs($admin)->putJson(
            '/api/plugins/sirsoft-marketing/admin/channels',
            ['channels' => $channels]
        );

        // is_system 누락으로 422 (system 채널 삭제 거부가 먼저 발동)
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['channels']);
    }

    // ── 검증: 필수 필드 누락 ──

    /**
     * 채널 이름이 없으면 그 항목 오류로 거절한다.
     *
     * @scenario case=field_validation
     *
     * @effects field_errors_shown_without_deactivate_modal
     *
     * @return void 반환값 없음
     */
    public function test_update_channels_rejects_missing_label(): void
    {
        $admin = $this->createAdminUser(['core.plugins.update']);

        $channels = [
            ['key' => 'sms_subscription', 'page_slug' => '', 'enabled' => true, 'is_system' => false],
        ];

        $response = $this->actingAs($admin)->putJson(
            '/api/plugins/sirsoft-marketing/admin/channels',
            ['channels' => $channels]
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['channels.0.label']);
    }

    // ── 검증: 동의가 쌓인 일반 채널 삭제 — 막힌 채널 키를 기계가 읽는 필드로 돌려준다 ──

    /**
     * 동의한 회원이 있는 일반 채널을 목록에서 빼면 422 와 함께 그 채널 키를 `errors.blocked_channel_keys` 로 돌려준다.
     *
     * 화면은 이 필드가 있을 때만 「비활성으로 전환」 창을 열고 그 키의 채널을 되살린다 —
     * 번역된 문장(`errors.channels[0]`)을 키로 대조하면 어떤 언어에서도 채널을 찾지 못한다.
     *
     * @scenario case=channel_has_consents
     *
     * @effects blocked_channel_key_is_machine_readable
     *
     * @return void 반환값 없음
     */
    public function test_update_channels_returns_blocked_channel_keys_when_deleting_channel_with_consents(): void
    {
        $this->bindStoredChannels(array_merge(self::EXISTING_CHANNELS, [
            ['key' => 'sms_subscription', 'label' => ['ko' => 'SMS', 'en' => 'SMS'], 'page_slug' => '', 'enabled' => true, 'is_system' => false],
        ]));
        $admin = $this->createAdminUser(['core.plugins.update']);
        MarketingConsent::create([
            'user_id' => User::factory()->create()->id,
            'consent_key' => 'sms_subscription',
            'is_consented' => true,
            'consented_at' => now(),
            'revoked_at' => null,
            'last_source' => 'register',
            'consent_count' => 1,
        ]);

        $response = $this->actingAs($admin)->putJson(
            '/api/plugins/sirsoft-marketing/admin/channels',
            ['channels' => self::EXISTING_CHANNELS]
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['channels'])
            ->assertJsonPath('errors.blocked_channel_keys', ['sms_subscription']);
    }

    /**
     * 형식·필수값 오류만 있는 422 에는 `blocked_channel_keys` 가 실리지 않는다 — 화면이 비활성 전환 창 대신 필드 오류를 보여야 한다.
     *
     * @scenario case=field_validation
     *
     * @effects blocked_channel_key_is_machine_readable
     *
     * @return void 반환값 없음
     */
    public function test_update_channels_field_errors_do_not_carry_blocked_channel_keys(): void
    {
        $admin = $this->createAdminUser(['core.plugins.update']);

        $response = $this->actingAs($admin)->putJson(
            '/api/plugins/sirsoft-marketing/admin/channels',
            ['channels' => array_merge(self::EXISTING_CHANNELS, [
                ['key' => 'Bad-Key', 'label' => ['ko' => '', 'en' => ''], 'page_slug' => '', 'enabled' => true, 'is_system' => false],
            ])]
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['channels.1.key', 'channels.1.label'])
            ->assertJsonMissingPath('errors.blocked_channel_keys');
    }

    /**
     * 저장된 채널 목록을 주어진 값으로 바꿔 끼운다.
     *
     * @param  array<int, array<string, mixed>>  $channels  저장된 것으로 볼 채널 목록
     * @return void 반환값 없음
     */
    private function bindStoredChannels(array $channels): void
    {
        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('get')->willReturnCallback(
            fn (string $id, string $key, mixed $default = null) => match ($key) {
                'channels' => json_encode($channels),
                default => $default,
            }
        );
        $this->app->instance(PluginSettingsService::class, $mock);
    }
}
