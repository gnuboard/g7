<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Feature\Settings;

use App\Extension\HookManager;
use App\Extension\PluginManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Testing\TestResponse;
use Plugins\Sirsoft\MessageBizppurio\Listeners\ValidateBizppurioSettingsListener;
use Plugins\Sirsoft\MessageBizppurio\Plugin;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * 회귀 테스트 — 운영 모드(검수 모드 off) 발송 자격증명 미입력 저장 차단.
 *
 * 코어 플러그인 설정 저장 경로(PUT /api/admin/plugins/{id}/settings)에서
 * is_test_mode=false 일 때 bizppurio_id / password / sender_number 를 required 로
 * 강제하는지 검증한다. api_key(카카오 관리)·sender_key(알림톡)는 문자 발송의 필수
 * 조건이 아니므로 required 대상이 아니다.
 *
 * @since 1.0.0
 */
class BizppurioLiveModeSettingsValidationTest extends PluginTestCase
{
    private const IDENTIFIER = 'sirsoft-message_bizppurio';

    private User $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        // 코어 PluginManager 가 테스트 환경에서 본 플러그인 인스턴스를 반환하도록 수동 등록.
        // (UpdatePluginSettingsRequest::rules() 가 getPlugin()->getSettingsSchema() 를 사용)
        $manager = app(PluginManager::class);
        $ref = new \ReflectionClass($manager);
        $prop = $ref->getProperty('plugins');
        $prop->setAccessible(true);
        $plugins = $prop->getValue($manager);
        $plugins[self::IDENTIFIER] = new Plugin;
        $prop->setValue($manager, $plugins);

        // 검증 규칙 필터 훅 등록 (실제 훅 체인 재현 — mock 없음).
        $listener = new ValidateBizppurioSettingsListener;
        HookManager::addFilter(
            'core.plugin_settings.update_rules',
            [$listener, 'addLiveModeRules'],
            10
        );

        $this->admin = $this->createAdminUser();
        $this->token = $this->admin->createToken('test-token')->plainTextToken;
    }

    /**
     * core.plugins.update 권한을 가진 admin 사용자 생성.
     */
    private function createAdminUser(): User
    {
        $user = User::factory()->create();

        $permission = Permission::firstOrCreate(
            ['identifier' => 'core.plugins.update'],
            ['name' => json_encode(['ko' => '플러그인 수정', 'en' => 'Update Plugins']), 'type' => 'admin']
        );

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            ['name' => json_encode(['ko' => '관리자', 'en' => 'Admin']), 'type' => 'admin']
        );

        $testRole = Role::create([
            'identifier' => 'bizppurio_settings_test_'.uniqid(),
            'name' => json_encode(['ko' => '테스트', 'en' => 'Test']),
            'type' => 'admin',
        ]);
        $testRole->permissions()->sync([$permission->id]);

        $user->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($testRole->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }

    /**
     * 인증 헤더가 적용된 PUT 요청 헬퍼.
     *
     * @param  array<string, mixed>  $body
     * @return TestResponse
     */
    private function putSettings(array $body)
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->putJson('/api/admin/plugins/'.self::IDENTIFIER.'/settings', $body);
    }

    /**
     * @scenario test_mode=off,credentials=empty
     *
     * @effects live_mode_with_empty_credentials_returns_422_with_bizppurio_id_password_sender_number_errors
     */
    public function test_live_mode_with_empty_credentials_is_rejected(): void
    {
        $response = $this->putSettings([
            'is_test_mode' => false,
            'bizppurio_id' => '',
            'password' => '',
            'sender_number' => '',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['bizppurio_id', 'password', 'sender_number']);
    }

    /**
     * @scenario test_mode=off,credentials=empty
     *
     * @effects api_key_and_sender_key_are_not_required_in_live_mode
     */
    public function test_live_mode_does_not_require_api_key_or_sender_key(): void
    {
        $response = $this->putSettings([
            'is_test_mode' => false,
            'bizppurio_id' => 'biz-account',
            'password' => 'secret',
            'sender_number' => '021234567',
            'api_key' => '',
            'sender_key' => '',
        ]);

        // api_key / sender_key 는 문자 발송 필수 조건이 아니므로 빈 값이어도 통과해야 한다.
        $response->assertStatus(200);
    }

    /**
     * @scenario test_mode=off,credentials=empty,locale=ko
     *
     * @effects validation_error_messages_use_korean_field_labels_not_english_keys
     */
    public function test_validation_error_messages_use_korean_field_labels(): void
    {
        $response = $this->putSettings([
            'is_test_mode' => false,
            'bizppurio_id' => '',
            'password' => '',
            'sender_number' => '',
        ]);

        $response->assertStatus(422);

        $errors = $response->json('errors');
        // 코어 검증기 수정 없이 리스너의 Lang::addLines 로 주입한 한국어 라벨이 노출되어야 한다.
        $this->assertStringContainsString('비즈뿌리오 아이디', $errors['bizppurio_id'][0]);
        $this->assertStringNotContainsString('bizppurio id', $errors['bizppurio_id'][0]);
        $this->assertStringContainsString('발신번호', $errors['sender_number'][0]);
    }

    /**
     * @scenario test_mode=off,credentials=filled,locale=ko
     *
     * @effects live_mode_with_filled_credentials_returns_200
     */
    public function test_live_mode_with_filled_credentials_is_accepted(): void
    {
        $response = $this->putSettings([
            'is_test_mode' => false,
            'bizppurio_id' => 'biz-account',
            'password' => 'secret',
            'sender_number' => '021234567',
        ]);

        $response->assertStatus(200);
    }

    /**
     * @scenario test_mode=on,credentials=empty,locale=ko
     *
     * @effects dev_mode_with_empty_credentials_returns_200
     */
    public function test_dev_mode_with_empty_credentials_is_accepted(): void
    {
        $response = $this->putSettings([
            'is_test_mode' => true,
            'bizppurio_id' => '',
            'password' => '',
            'sender_number' => '',
        ]);

        $response->assertStatus(200);
    }

    /**
     * @scenario test_mode=off,credentials=filled
     *
     * @effects password_is_not_stored_as_plaintext_in_settings_file, password_decrypts_back_to_original_via_settings_service_get
     */
    public function test_password_is_stored_encrypted(): void
    {
        $this->putSettings([
            'is_test_mode' => false,
            'bizppurio_id' => 'biz-account',
            'password' => 'super-secret',
            'sender_number' => '021234567',
        ])->assertStatus(200);

        // 저장 파일에 평문이 남지 않아야 한다 (sensitive 암호화).
        // PluginTestCase 가 'plugins' 디스크를 테스트 전용 경로로 격리(#458)하므로 실제
        // storage_path 가 아니라 격리된 디스크를 통해 파일을 조회한다.
        $relativePath = self::IDENTIFIER.'/settings/setting.json';
        $this->assertTrue(
            \Illuminate\Support\Facades\Storage::disk('plugins')->exists($relativePath),
            '설정 파일이 격리된 plugins 디스크에 저장되어야 한다.'
        );
        $raw = \Illuminate\Support\Facades\Storage::disk('plugins')->get($relativePath);
        $this->assertStringNotContainsString('super-secret', $raw);

        // 복호화 왕복은 원문과 일치해야 한다.
        $value = app(PluginSettingsService::class)->get(self::IDENTIFIER, 'password');
        $this->assertSame('super-secret', $value);
    }
}
