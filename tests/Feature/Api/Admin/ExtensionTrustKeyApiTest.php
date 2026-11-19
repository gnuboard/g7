<?php

namespace Tests\Feature\Api\Admin;

use App\Extension\Integrity\BuiltinTrustKeys;
use App\Models\ExtensionTrustKey;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\ActsAsSettingsAdmin;
use Tests\Concerns\PackageSignatureFixtures;
use Tests\TestCase;

/**
 * 확장 신뢰 키 API `GET/POST/DELETE /api/admin/extension-trust-keys` (#916 ④, D20·D31·D33)
 *
 * 추가·삭제는 같은 요청의 비밀번호 재확인을 거치고, 권한 없는 관리자는 403 이다. 「미서명 패키지 차단」은 탭 저장
 * (`security.extension_block_unsigned`)이고 목록 응답에 상태가 실린다.
 */
class ExtensionTrustKeyApiTest extends TestCase
{
    use ActsAsSettingsAdmin;
    use PackageSignatureFixtures;
    use RefreshDatabase;

    /**
     * 스키마를 다시 만들지 않고 트랜잭션으로 격리합니다.
     *
     * @return void 반환값 없음
     */
    public function refreshDatabase(): void
    {
        $this->beginDatabaseTransaction();
    }

    /**
     * 설정 관리자를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->makeSettingsAdmin();
    }

    /**
     * 시험 파일을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->cleanupPackageFixtures();

        parent::tearDown();
    }

    /**
     * 비밀번호를 다시 확인하고 키를 추가·조회·삭제합니다 — key_id 는 서버가 계산합니다.
     *
     * @scenario case=trust_key_api_crud
     *
     * @effects trust_key_api_requires_password_and_computes_key_id
     *
     * @return void 반환값 없음
     */
    public function test_store_index_destroy_with_password(): void
    {
        $key = $this->fixtureKey('glitter');

        $created = $this->asSettingsAdmin()->postJson('/api/admin/extension-trust-keys', [
            'public_key' => $key['public_key_b64'],
            'label' => 'glitter',
            'vendor_scope' => 'glitter, glitterlab',
            'password' => 'password123',
        ]);
        $created->assertStatus(201)
            ->assertJsonPath('data.key_id', $key['key_id'])
            ->assertJsonPath('data.vendor_scope', ['glitter', 'glitterlab'])
            // 등록한 관리자 ID 는 응답에 싣지 않는다 (api-resources 민감 정보 — created_by)
            ->assertJsonMissingPath('data.created_by');

        $index = $this->asSettingsAdmin()->getJson('/api/admin/extension-trust-keys')
            ->assertOk()
            ->assertJsonMissingPath('data.keys.0.created_by')
            ->assertJsonPath('data.keys.0.key_id', $key['key_id'])
            ->assertJsonPath('data.block_unsigned', false)
            ->assertJsonPath('data.sodium_available', true);

        // 내장 키는 코어 상수 목록 그대로 읽기 전용으로 실린다 (운영 키를 넣거나 빼도 이 단언이 따라간다)
        $this->assertSame(
            array_column(BuiltinTrustKeys::all(), 'key_id'),
            array_column($index->json('data.builtin'), 'key_id'),
        );

        $id = (int) $created->json('data.id');

        $this->asSettingsAdmin()->deleteJson('/api/admin/extension-trust-keys/'.$id, ['password' => 'wrong-password'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
        $this->assertDatabaseHas('extension_trust_keys', ['id' => $id]);

        $this->asSettingsAdmin()->deleteJson('/api/admin/extension-trust-keys/'.$id, ['password' => 'password123'])
            ->assertOk()
            ->assertJsonPath('data.key_id', $key['key_id']);
        $this->assertDatabaseMissing('extension_trust_keys', ['id' => $id]);

        $this->asSettingsAdmin()->deleteJson('/api/admin/extension-trust-keys/'.$id, ['password' => 'password123'])->assertNotFound();
    }

    /**
     * vendor 범위 없음·sirsoft·틀린 비밀번호·비밀번호 없음은 422 이고 행이 생기지 않습니다.
     *
     * @scenario case=trust_key_api_validation
     *
     * @effects trust_key_requires_vendor_scope_and_rejects_reserved_sirsoft
     *
     * @return void 반환값 없음
     */
    public function test_store_validation(): void
    {
        $key = $this->fixtureKey('glitter');
        $base = ['public_key' => $key['public_key_b64'], 'label' => 'glitter', 'vendor_scope' => ['glitter'], 'password' => 'password123'];

        $this->asSettingsAdmin()->postJson('/api/admin/extension-trust-keys', ['vendor_scope' => []] + $base)
            ->assertStatus(422)->assertJsonValidationErrors(['vendor_scope']);
        $this->asSettingsAdmin()->postJson('/api/admin/extension-trust-keys', ['vendor_scope' => ['sirsoft']] + $base)
            ->assertStatus(422)->assertJsonValidationErrors(['vendor_scope.0']);
        $this->asSettingsAdmin()->postJson('/api/admin/extension-trust-keys', ['label' => 'sirsoft'] + $base)
            ->assertStatus(422)->assertJsonValidationErrors(['label']);
        $this->asSettingsAdmin()->postJson('/api/admin/extension-trust-keys', ['public_key' => 'AAAA'] + $base)
            ->assertStatus(422)->assertJsonValidationErrors(['public_key']);
        $this->asSettingsAdmin()->postJson('/api/admin/extension-trust-keys', ['password' => 'nope'] + $base)
            ->assertStatus(422)->assertJsonValidationErrors(['password']);
        $without = $base;
        unset($without['password']);
        $this->asSettingsAdmin()->postJson('/api/admin/extension-trust-keys', $without)
            ->assertStatus(422)->assertJsonValidationErrors(['password']);

        $this->assertSame(0, ExtensionTrustKey::query()->count());
    }

    /**
     * 비밀번호가 없는 관리자(외부 인증 전용)는 비밀번호 칸 없이 보내면 본인인증(428)을 요구받고 키가 생기지 않습니다.
     *
     * @scenario case=trust_key_api_passwordless_admin
     *
     * @effects trust_key_api_passwordless_admin_gets_identity_challenge
     *
     * @return void 반환값 없음
     */
    public function test_passwordless_admin_gets_identity_challenge(): void
    {
        $key = $this->fixtureKey('glitter');
        $admin = $this->makeSettingsAdmin();
        $admin->forceFill(['password' => null])->save();

        $this->asSettingsAdmin()->postJson('/api/admin/extension-trust-keys', [
            'public_key' => $key['public_key_b64'],
            'label' => 'glitter',
            'vendor_scope' => ['glitter'],
        ])->assertStatus(428);

        $this->assertSame(0, ExtensionTrustKey::query()->count());
    }

    /**
     * 설정 권한이 없는 관리자는 목록·추가 모두 403 입니다.
     *
     * @scenario case=trust_key_api_forbidden
     *
     * @effects trust_key_api_requires_settings_permission
     *
     * @return void 반환값 없음
     */
    public function test_admin_without_settings_permission_is_forbidden(): void
    {
        $key = $this->fixtureKey('glitter');
        $plain = User::factory()->create(['password' => Hash::make('password123')]);
        $adminRole = Role::query()->where('identifier', 'admin')->firstOrFail();
        $plain->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $token = $plain->createToken('t')->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])
            ->getJson('/api/admin/extension-trust-keys')->assertForbidden();
        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])
            ->postJson('/api/admin/extension-trust-keys', ['public_key' => $key['public_key_b64'], 'label' => 'glitter', 'vendor_scope' => ['glitter'], 'password' => 'password123'])
            ->assertForbidden();
    }

    /**
     * 「미서명 패키지 차단」 스위치는 보안 탭 저장에 실립니다 (기본 false).
     *
     * @scenario case=block_unsigned_setting_saved
     *
     * @effects block_unsigned_switch_saved_with_security_tab
     *
     * @return void 반환값 없음
     */
    public function test_block_unsigned_is_saved_with_security_tab(): void
    {
        $this->assertFalse((bool) g7_core_settings('security.extension_block_unsigned', false));

        $this->asSettingsAdmin()->postJson('/api/admin/settings', [
            '_tab' => 'security',
            'security' => ['extension_block_unsigned' => 'not-bool'],
        ])->assertStatus(422)->assertJsonValidationErrors(['security.extension_block_unsigned']);
    }
}
