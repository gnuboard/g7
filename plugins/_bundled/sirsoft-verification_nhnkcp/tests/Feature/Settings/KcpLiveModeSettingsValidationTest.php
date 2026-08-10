<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Tests\Feature\Settings;

use App\Extension\PluginManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\PluginSettingsService;
use App\Support\SensitiveSettingMask;
use Illuminate\Testing\TestResponse;
use Plugins\Sirsoft\VerificationNhnkcp\Tests\PluginTestCase;

/**
 * 운영 모드 전환 시 자격증명 필수 검증.
 *
 * 코어 설정 저장 경로(PUT /api/admin/plugins/{id}/settings)에서 운영 모드일 때만 사이트코드와
 * 암호화 키를 required 로 강제하는지, 그리고 에러 메시지의 항목 이름이 화면 언어로 노출되는지
 * 확인한다. 자격증명 없이 운영 모드가 켜져 인증이 조용히 실패하는 상황을 막는 게 목적이다.
 *
 * @since 1.0.0
 */
class KcpLiveModeSettingsValidationTest extends PluginTestCase
{
    private User $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminUser();
        $this->token = $this->admin->createToken('test-token')->plainTextToken;
    }

    /**
     * @scenario mode=live,live_credentials=missing_site_cd
     *
     * @effects live_mode_requires_site_cd_and_enc_key
     */
    public function test_live_mode_without_credentials_is_rejected(): void
    {
        $response = $this->putSettings([
            'is_test_mode' => false,
            'live_site_cd' => '',
            'live_enc_key' => '',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['live_site_cd', 'live_enc_key']);
    }

    /**
     * @scenario mode=live,live_credentials=missing_enc_key
     *
     * @effects live_mode_requires_site_cd_and_enc_key
     */
    public function test_validation_messages_use_korean_field_labels(): void
    {
        $response = $this->putSettings([
            'is_test_mode' => false,
            'live_site_cd' => '',
            'live_enc_key' => '',
        ]);

        $response->assertStatus(422);

        $errors = $response->json('errors');
        $this->assertStringContainsString('운영 사이트코드', $errors['live_site_cd'][0]);
        $this->assertStringNotContainsString('live site cd', $errors['live_site_cd'][0]);
        $this->assertStringContainsString('운영 암호화 키', $errors['live_enc_key'][0]);
    }

    /**
     * @scenario mode=live,live_credentials=complete
     *
     * @effects live_mode_requires_site_cd_and_enc_key
     */
    public function test_live_mode_with_credentials_is_accepted(): void
    {
        $response = $this->putSettings([
            'is_test_mode' => false,
            'live_site_cd' => 'A1B2C',
            'live_enc_key' => 'live-secret-key',
        ]);

        $response->assertStatus(200);
    }

    /**
     * @scenario mode=test,live_credentials=complete
     *
     * @effects test_mode_saves_without_live_credentials
     */
    public function test_test_mode_without_live_credentials_is_accepted(): void
    {
        $response = $this->putSettings([
            'is_test_mode' => true,
            'live_site_cd' => '',
            'live_enc_key' => '',
        ]);

        $response->assertStatus(200);
    }

    /**
     * @scenario mode=live,live_credentials=complete
     *
     * @effects sensitive_key_is_not_returned_in_plain_text
     */
    public function test_saved_encryption_key_is_not_stored_in_plain_text(): void
    {
        $this->putSettings([
            'is_test_mode' => false,
            'live_site_cd' => 'A1B2C',
            'live_enc_key' => 'super-secret-live-key',
        ])->assertStatus(200);

        // 저장 파일에는 암호문이 들어가고, 서비스 조회 시에만 복호화되어야 한다.
        $storage = app(PluginManager::class)
            ->getPlugin(self::PLUGIN_IDENTIFIER)
            ->getStorage();

        $stored = json_decode((string) $storage->get('settings', 'setting.json'), true);

        $this->assertIsArray($stored);
        $this->assertNotSame('super-secret-live-key', $stored['live_enc_key'] ?? null, '평문 저장 금지');

        $this->assertSame(
            'super-secret-live-key',
            app(PluginSettingsService::class)->get(self::PLUGIN_IDENTIFIER, 'live_enc_key'),
        );
    }

    /**
     * 관리자 설정 응답에 암호화 키 평문이 실리지 않는지 확인한다.
     *
     * 저장은 암호화되어 있어도 조회 응답이 복호화된 값을 그대로 내려보내면 브라우저·개발자 도구·
     * 프록시 로그에 비밀값이 남는다. 값이 있다는 사실만 마스크로 알려야 한다.
     *
     * @scenario mode=live,live_credentials=complete
     *
     * @effects sensitive_key_is_not_returned_in_plain_text
     */
    public function test_admin_response_masks_encryption_key(): void
    {
        $this->putSettings([
            'is_test_mode' => false,
            'live_site_cd' => 'A1B2C',
            'live_enc_key' => 'super-secret-live-key',
        ])->assertStatus(200)
            ->assertJsonPath('data.live_enc_key', SensitiveSettingMask::MASK);

        $show = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->getJson('/api/admin/plugins/'.self::PLUGIN_IDENTIFIER.'/settings');

        $show->assertStatus(200)->assertJsonPath('data.live_enc_key', SensitiveSettingMask::MASK);
        $this->assertStringNotContainsString('super-secret-live-key', $show->getContent());

        // 민감하지 않은 항목은 그대로 보여야 한다 (마스킹이 과하게 번지지 않는지).
        $show->assertJsonPath('data.live_site_cd', 'A1B2C');
    }

    /**
     * 마스크를 그대로 되돌려 보내면 저장된 값이 유지되는지 확인한다.
     *
     * 운영자가 암호화 키를 건드리지 않고 다른 항목만 바꿔 저장하면, 화면은 마스크를 그대로 보낸다.
     * 그것을 값으로 받아 저장하면 저장된 키가 마스크 문자열로 덮여 운영 인증이 통째로 멈춘다.
     *
     * @scenario mode=live,live_credentials=complete
     *
     * @effects sensitive_key_is_not_returned_in_plain_text
     */
    public function test_resubmitting_mask_preserves_stored_key(): void
    {
        $this->putSettings([
            'is_test_mode' => false,
            'live_site_cd' => 'A1B2C',
            'live_enc_key' => 'super-secret-live-key',
        ])->assertStatus(200);

        // 화면이 마스크를 그대로 되돌려 보내는 상황 재현
        $this->putSettings([
            'is_test_mode' => false,
            'live_site_cd' => 'Z9Y8X',
            'live_enc_key' => SensitiveSettingMask::MASK,
        ])->assertStatus(200);

        $this->assertSame(
            'super-secret-live-key',
            app(PluginSettingsService::class)->get(self::PLUGIN_IDENTIFIER, 'live_enc_key'),
            '마스크 재전송은 저장된 키를 덮어써서는 안 된다',
        );
        $this->assertSame(
            'Z9Y8X',
            app(PluginSettingsService::class)->get(self::PLUGIN_IDENTIFIER, 'live_site_cd'),
            '같은 요청의 다른 항목은 정상 저장되어야 한다',
        );
    }

    /**
     * 사이트코드에 SM 프리픽스를 포함해 입력하면 저장 시 프리픽스를 떼고 보관해야 한다.
     *
     * 설정 화면은 입력칸 왼쪽에 `SM` 배지를 따로 두고 "SM 을 제외한 값만" 받는다. 프리픽스가
     * 포함된 채로 저장되면 화면이 `SM` + `SMZ9Y8X` 로 그려져 운영자에게는 프리픽스가 두 번
     * 붙은 것처럼 보인다. 저장값을 프리픽스 미포함으로 정규화해 화면 표시와 보관 형태를
     * 일치시킨다 (런타임 부착은 buildLiveSiteCd 가 계속 담당).
     *
     * @scenario mode=live,live_credentials=complete
     *
     * @effects sm_prefix_is_added_once
     */
    public function test_site_cd_is_stored_without_sm_prefix(): void
    {
        foreach (['SMZ9Y8X' => 'Z9Y8X', 'smZ9Y8X' => 'Z9Y8X', 'Z9Y8X' => 'Z9Y8X'] as $input => $expected) {
            $this->putSettings([
                'is_test_mode' => false,
                'live_site_cd' => $input,
                'live_enc_key' => 'super-secret-live-key',
            ])->assertStatus(200);

            $this->assertSame(
                $expected,
                app(PluginSettingsService::class)->get(self::PLUGIN_IDENTIFIER, 'live_site_cd'),
                "입력 [{$input}] 은 프리픽스 없이 보관되어야 한다",
            );
        }
    }

    /**
     * 정규화 후에도 런타임 사이트코드는 프리픽스가 붙은 최종값이어야 한다.
     *
     * @scenario mode=live,live_credentials=complete
     *
     * @effects sm_prefix_is_added_once
     */
    public function test_normalized_site_cd_still_resolves_with_prefix(): void
    {
        $this->putSettings([
            'is_test_mode' => false,
            'live_site_cd' => 'SMZ9Y8X',
            'live_enc_key' => 'super-secret-live-key',
        ])->assertStatus(200);

        $provider = app()->makeWith(
            \Plugins\Sirsoft\VerificationNhnkcp\Identity\KcpIdentityProvider::class,
            ['config' => app(PluginSettingsService::class)->get(self::PLUGIN_IDENTIFIER)],
        );

        $this->assertSame('SMZ9Y8X', $provider->resolveSiteCd());
    }

    /**
     * core.plugins.update 권한을 가진 관리자 생성.
     */
    private function createAdminUser(): User
    {
        $user = User::factory()->create();

        $updatePermission = Permission::firstOrCreate(
            ['identifier' => 'core.plugins.update'],
            ['name' => json_encode(['ko' => '플러그인 수정', 'en' => 'Update Plugins']), 'type' => 'admin']
        );

        // 설정 조회(GET)는 별도 권한을 요구한다 — 마스킹 응답 검증에 필요.
        $readPermission = Permission::firstOrCreate(
            ['identifier' => 'core.plugins.read'],
            ['name' => json_encode(['ko' => '플러그인 조회', 'en' => 'Read Plugins']), 'type' => 'admin']
        );

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            ['name' => json_encode(['ko' => '관리자', 'en' => 'Admin']), 'type' => 'admin']
        );

        $testRole = Role::create([
            'identifier' => 'nhnkcp_settings_test_'.uniqid(),
            'name' => json_encode(['ko' => '테스트', 'en' => 'Test']),
            'type' => 'admin',
        ]);
        $testRole->permissions()->sync([$updatePermission->id, $readPermission->id]);

        $user->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($testRole->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }

    /**
     * 인증 헤더가 적용된 설정 저장 요청.
     *
     * @param  array<string, mixed>  $body
     * @return TestResponse
     */
    private function putSettings(array $body)
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->putJson('/api/admin/plugins/'.self::PLUGIN_IDENTIFIER.'/settings', $body);
    }
}
