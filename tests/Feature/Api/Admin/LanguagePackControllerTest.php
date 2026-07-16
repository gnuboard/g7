<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackStatus;
use App\Models\LanguagePack;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * LanguagePackController Feature 테스트.
 *
 * 8개 엔드포인트의 골든 패스 + 권한 경계 + 유효성 실패를 검증합니다.
 */
class LanguagePackControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * read 권한 보유 사용자.
     */
    private User $reader;

    /**
     * manage 권한 보유 사용자.
     */
    private User $manager;

    /**
     * 권한 없는 사용자.
     */
    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->reader = $this->makeUserWithPermissions(['core.language_packs.read']);
        $this->manager = $this->makeUserWithPermissions([
            'core.language_packs.read',
            'core.language_packs.install',
            'core.language_packs.manage',
            'core.language_packs.update',
        ]);
        $this->stranger = User::factory()->create();
    }

    /**
     * 지정된 권한을 가진 사용자를 생성합니다.
     *
     * @param  array<int, string>  $permissionIdentifiers  권한 식별자 목록
     * @return User 생성된 사용자
     */
    private function makeUserWithPermissions(array $permissionIdentifiers): User
    {
        $user = User::factory()->create();
        $role = Role::query()->create([
            'identifier' => 'lp_test_role_'.uniqid(),
            'name' => ['ko' => '테스트', 'en' => 'Test'],
            'description' => ['ko' => '', 'en' => ''],
            'is_active' => true,
        ]);
        $permissions = Permission::query()
            ->whereIn('identifier', $permissionIdentifiers)
            ->get();
        $role->permissions()->attach($permissions->pluck('id')->all());
        $user->roles()->attach($role->id);

        return $user;
    }

    /**
     * 활성 코어 ja 언어팩을 생성합니다.
     *
     * @return LanguagePack 생성된 언어팩
     */
    private function makeActiveCorePack(): LanguagePack
    {
        return LanguagePack::query()->create([
            'identifier' => 'sirsoft-core-ja',
            'vendor' => 'sirsoft',
            'scope' => LanguagePackScope::Core->value,
            'target_identifier' => null,
            'locale' => 'ja',
            'locale_name' => 'Japanese',
            'locale_native_name' => '日本語',
            'text_direction' => 'ltr',
            'version' => '1.0.0',
            'status' => LanguagePackStatus::Active->value,
            'is_protected' => false,
            'manifest' => [],
            'source_type' => 'zip',
        ]);
    }

    public function test_index_returns_paginated_list(): void
    {
        $this->makeActiveCorePack();
        Sanctum::actingAs($this->reader);

        // exclude_protected=1 로 가상 보호 행(built_in) 제외 — DB 활성 코어 1건만 검증.
        $response = $this->getJson('/api/admin/language-packs?status=active&exclude_protected=1');

        $response->assertOk()
            ->assertJsonPath('data.data.0.identifier', 'sirsoft-core-ja')
            ->assertJsonPath('data.meta.total', 1);
    }

    public function test_index_filter_by_scope(): void
    {
        $this->makeActiveCorePack();
        LanguagePack::query()->create([
            'identifier' => 'acme-template-foo-ja',
            'vendor' => 'acme',
            'scope' => LanguagePackScope::Template->value,
            'target_identifier' => 'foo',
            'locale' => 'ja',
            'locale_name' => 'Japanese',
            'locale_native_name' => '日本語',
            'text_direction' => 'ltr',
            'version' => '1.0.0',
            'status' => LanguagePackStatus::Active->value,
            'is_protected' => false,
            'manifest' => [],
            'source_type' => 'zip',
        ]);
        Sanctum::actingAs($this->reader);

        // scope=core + status=active + exclude_protected → DB 활성 코어 1건만 (built_in 가상 행 제외).
        $response = $this->getJson('/api/admin/language-packs?scope=core&status=active&exclude_protected=1');

        $response->assertOk()
            ->assertJsonCount(1, 'data.data');
    }

    public function test_index_includes_uninstalled_bundled_rows_with_install_ability(): void
    {
        // 임시 번들 디렉토리 — 본 테스트의 슬롯 충돌만 회피.
        $bundledIdentifier = 'test-feature-acme-zz-'.uniqid();
        $bundledPath = base_path('lang-packs/_bundled/'.$bundledIdentifier);
        \Illuminate\Support\Facades\File::ensureDirectoryExists($bundledPath);
        \Illuminate\Support\Facades\File::put(
            $bundledPath.'/language-pack.json',
            json_encode([
                'identifier' => $bundledIdentifier,
                'vendor' => 'acme',
                'scope' => LanguagePackScope::Core->value,
                'target_identifier' => null,
                'locale' => 'zz',
                'locale_name' => 'Zzland',
                'locale_native_name' => 'Zzland',
                'text_direction' => 'ltr',
                'version' => '0.1.0',
            ], JSON_UNESCAPED_UNICODE)
        );

        try {
            Sanctum::actingAs($this->manager);

            $response = $this->getJson('/api/admin/language-packs?status=uninstalled&search='.$bundledIdentifier);
            $response->assertOk();

            $rows = $response->json('data.data');
            $this->assertNotEmpty($rows, '미설치 번들 가상 행이 응답에 포함되어야 함');

            $first = $rows[0];
            $this->assertSame($bundledIdentifier, $first['identifier']);
            $this->assertSame('uninstalled', $first['status']);
            $this->assertSame($bundledIdentifier, $first['bundled_identifier']);
            $this->assertNull($first['id']);
            $this->assertTrue($first['abilities']['can_install'] ?? false);
        } finally {
            \Illuminate\Support\Facades\File::deleteDirectory($bundledPath);
        }
    }

    public function test_install_from_bundled_promotes_uninstalled_row(): void
    {
        // 검증기는 core 스코프에서 identifier 가 `{vendor}-core-{locale}` 형식이어야 한다고 요구.
        // 테스트 격리를 위해 vendor 에 uniqid 를 포함해 재실행 안전성을 확보한다.
        $vendor = 'acme'.substr(uniqid(), -6);
        $locale = 'yy';
        $bundledIdentifier = $vendor.'-core-'.$locale;
        $bundledPath = base_path('lang-packs/_bundled/'.$bundledIdentifier);
        \Illuminate\Support\Facades\File::ensureDirectoryExists($bundledPath);
        \Illuminate\Support\Facades\File::put(
            $bundledPath.'/language-pack.json',
            json_encode([
                'identifier' => $bundledIdentifier,
                'namespace' => $vendor,
                'vendor' => $vendor,
                'name' => ['ko' => 'Test Language Pack', 'en' => 'Test Language Pack'],
                'scope' => LanguagePackScope::Core->value,
                'target_identifier' => null,
                'locale' => $locale,
                'locale_name' => 'Yyland',
                'locale_native_name' => 'Yyland',
                'text_direction' => 'ltr',
                'version' => '0.1.0',
                'g7_version' => '>=1.0.0',
            ], JSON_UNESCAPED_UNICODE)
        );

        $installedDir = base_path('lang-packs/'.$bundledIdentifier);

        try {
            Sanctum::actingAs($this->manager);

            $response = $this->postJson('/api/admin/language-packs/install-from-bundled', [
                'identifier' => $bundledIdentifier,
                'auto_activate' => true,
            ]);

            $response->assertStatus(201)
                ->assertJsonPath('data.identifier', $bundledIdentifier)
                ->assertJsonPath('data.status', 'active')
                ->assertJsonPath('data.source_type', 'bundled');

            $this->assertDatabaseHas('language_packs', [
                'identifier' => $bundledIdentifier,
                'status' => LanguagePackStatus::Active->value,
            ]);
        } finally {
            \Illuminate\Support\Facades\File::deleteDirectory($bundledPath);
            \Illuminate\Support\Facades\File::deleteDirectory($installedDir);
        }
    }

    public function test_show_returns_pack_details(): void
    {
        $pack = $this->makeActiveCorePack();
        Sanctum::actingAs($this->reader);

        $response = $this->getJson('/api/admin/language-packs/'.$pack->id);

        $response->assertOk()
            ->assertJsonPath('data.identifier', 'sirsoft-core-ja')
            ->assertJsonStructure(['data' => ['manifest']]);
    }

    public function test_show_404_for_missing_pack(): void
    {
        Sanctum::actingAs($this->reader);

        $response = $this->getJson('/api/admin/language-packs/999999');

        $response->assertStatus(404);
    }

    public function test_install_from_file_rejects_oversize(): void
    {
        Sanctum::actingAs($this->manager);

        $bigContent = str_repeat('x', 11 * 1024 * 1024);
        $response = $this->postJson('/api/admin/language-packs/install-from-file', [
            'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('big.zip', $bigContent),
        ]);

        $response->assertStatus(422);
    }

    public function test_install_from_github_rejects_invalid_url(): void
    {
        Sanctum::actingAs($this->manager);

        $response = $this->postJson('/api/admin/language-packs/install-from-github', [
            'github_url' => 'https://gitlab.com/foo/bar',
        ]);

        $response->assertStatus(422);
    }

    public function test_install_from_url_rejects_invalid_checksum(): void
    {
        Sanctum::actingAs($this->manager);

        $response = $this->postJson('/api/admin/language-packs/install-from-url', [
            'url' => 'https://example.com/pack.zip',
            'checksum' => 'invalid',
        ]);

        $response->assertStatus(422);
    }

    public function test_activate_promotes_inactive_pack(): void
    {
        $pack = LanguagePack::query()->create([
            'identifier' => 'sirsoft-core-ja',
            'vendor' => 'sirsoft',
            'scope' => LanguagePackScope::Core->value,
            'target_identifier' => null,
            'locale' => 'ja',
            'locale_name' => 'Japanese',
            'locale_native_name' => '日本語',
            'text_direction' => 'ltr',
            'version' => '1.0.0',
            'status' => LanguagePackStatus::Inactive->value,
            'is_protected' => false,
            'manifest' => [],
            'source_type' => 'zip',
        ]);
        Sanctum::actingAs($this->manager);

        $response = $this->postJson('/api/admin/language-packs/'.$pack->id.'/activate');

        $response->assertOk();
        $this->assertSame('active', $pack->fresh()->status);
    }

    public function test_activate_returns_409_when_slot_already_active(): void
    {
        // 슬롯에 이미 active 인 코어/ja 팩 + 같은 슬롯 후보 (inactive)
        $current = $this->makeActiveCorePack();
        $candidate = LanguagePack::query()->create([
            'identifier' => 'gnuboard-core-ja',
            'vendor' => 'gnuboard',
            'scope' => LanguagePackScope::Core->value,
            'target_identifier' => null,
            'locale' => 'ja',
            'locale_name' => 'Japanese',
            'locale_native_name' => '日本語',
            'text_direction' => 'ltr',
            'version' => '1.0.0',
            'status' => LanguagePackStatus::Inactive->value,
            'is_protected' => false,
            'manifest' => [],
            'source_type' => 'zip',
        ]);
        Sanctum::actingAs($this->manager);

        $response = $this->postJson('/api/admin/language-packs/'.$candidate->id.'/activate');

        $response->assertStatus(409)
            ->assertJsonPath('errors.current.identifier', $current->identifier)
            ->assertJsonPath('errors.target.identifier', $candidate->identifier);

        // 양쪽 상태가 보존되었는지 확인 (force 미전달이면 변경 없어야 함)
        $this->assertSame('active', $current->fresh()->status);
        $this->assertSame('inactive', $candidate->fresh()->status);
    }

    public function test_activate_with_force_replaces_active_pack(): void
    {
        $current = $this->makeActiveCorePack();
        $candidate = LanguagePack::query()->create([
            'identifier' => 'gnuboard-core-ja',
            'vendor' => 'gnuboard',
            'scope' => LanguagePackScope::Core->value,
            'target_identifier' => null,
            'locale' => 'ja',
            'locale_name' => 'Japanese',
            'locale_native_name' => '日本語',
            'text_direction' => 'ltr',
            'version' => '1.0.0',
            'status' => LanguagePackStatus::Inactive->value,
            'is_protected' => false,
            'manifest' => [],
            'source_type' => 'zip',
        ]);
        Sanctum::actingAs($this->manager);

        $response = $this->postJson('/api/admin/language-packs/'.$candidate->id.'/activate', [
            'force' => true,
        ]);

        $response->assertOk();
        $this->assertSame('inactive', $current->fresh()->status);
        $this->assertSame('active', $candidate->fresh()->status);
    }

    public function test_deactivate_changes_status(): void
    {
        $pack = $this->makeActiveCorePack();
        Sanctum::actingAs($this->manager);

        $response = $this->postJson('/api/admin/language-packs/'.$pack->id.'/deactivate');

        $response->assertOk();
        $this->assertSame('inactive', $pack->fresh()->status);
    }

    public function test_uninstall_removes_pack(): void
    {
        $pack = LanguagePack::query()->create([
            'identifier' => 'sirsoft-core-ja',
            'vendor' => 'sirsoft',
            'scope' => LanguagePackScope::Core->value,
            'target_identifier' => null,
            'locale' => 'ja',
            'locale_name' => 'Japanese',
            'locale_native_name' => '日本語',
            'text_direction' => 'ltr',
            'version' => '1.0.0',
            'status' => LanguagePackStatus::Inactive->value,
            'is_protected' => false,
            'manifest' => [],
            'source_type' => 'zip',
        ]);
        Sanctum::actingAs($this->manager);

        $response = $this->deleteJson('/api/admin/language-packs/'.$pack->id);

        $response->assertOk();
        $this->assertNull(LanguagePack::query()->find($pack->id));
    }

    public function test_uninstall_protected_pack_returns_500(): void
    {
        $pack = LanguagePack::query()->create([
            'identifier' => 'g7-core-ko',
            'vendor' => 'g7',
            'scope' => LanguagePackScope::Core->value,
            'target_identifier' => null,
            'locale' => 'ko',
            'locale_name' => 'Korean',
            'locale_native_name' => '한국어',
            'text_direction' => 'ltr',
            'version' => '1.0.0',
            'status' => LanguagePackStatus::Active->value,
            'is_protected' => true,
            'manifest' => [],
        ]);
        Sanctum::actingAs($this->manager);

        $response = $this->deleteJson('/api/admin/language-packs/'.$pack->id);

        $response->assertStatus(500);
    }

    public function test_unauthorized_user_cannot_access(): void
    {
        Sanctum::actingAs($this->stranger);

        $response = $this->getJson('/api/admin/language-packs');

        $response->assertStatus(403);
    }

    public function test_reader_cannot_install(): void
    {
        Sanctum::actingAs($this->reader);

        $response = $this->postJson('/api/admin/language-packs/install-from-github', [
            'github_url' => 'https://github.com/foo/bar',
        ]);

        $response->assertStatus(403);
    }

    public function test_reader_cannot_activate(): void
    {
        $pack = LanguagePack::query()->create([
            'identifier' => 'sirsoft-core-ja',
            'vendor' => 'sirsoft',
            'scope' => LanguagePackScope::Core->value,
            'target_identifier' => null,
            'locale' => 'ja',
            'locale_name' => 'Japanese',
            'locale_native_name' => '日本語',
            'text_direction' => 'ltr',
            'version' => '1.0.0',
            'status' => LanguagePackStatus::Inactive->value,
            'is_protected' => false,
            'manifest' => [],
        ]);
        Sanctum::actingAs($this->reader);

        $response = $this->postJson('/api/admin/language-packs/'.$pack->id.'/activate');

        $response->assertStatus(403);
    }

    public function test_check_updates_returns_summary(): void
    {
        Sanctum::actingAs($this->manager);

        $response = $this->postJson('/api/admin/language-packs/check-updates');

        $response->assertOk()
            ->assertJsonPath('data.checked', 0)
            ->assertJsonPath('data.updates', 0);
    }

    public function test_check_updates_requires_update_permission(): void
    {
        Sanctum::actingAs($this->reader);

        $response = $this->postJson('/api/admin/language-packs/check-updates');

        $response->assertStatus(403);
    }

    public function test_perform_update_rejects_non_github_source(): void
    {
        $pack = $this->makeActiveCorePack();
        Sanctum::actingAs($this->manager);

        $response = $this->postJson('/api/admin/language-packs/'.$pack->id.'/update');

        $response->assertStatus(500);
    }

    public function test_perform_update_404_for_missing_pack(): void
    {
        Sanctum::actingAs($this->manager);

        $response = $this->postJson('/api/admin/language-packs/9999999/update');

        $response->assertStatus(404);
    }

    public function test_refresh_cache_returns_status_map(): void
    {
        Sanctum::actingAs($this->manager);

        $response = $this->postJson('/api/admin/language-packs/refresh-cache');

        $response->assertOk()
            ->assertJsonStructure(['data' => ['registry', 'translator', 'template']]);
    }

    public function test_changelog_returns_empty_when_missing(): void
    {
        $pack = $this->makeActiveCorePack();
        Sanctum::actingAs($this->manager);

        $response = $this->getJson('/api/admin/language-packs/'.$pack->id.'/changelog');

        $response->assertOk()
            ->assertJsonPath('data.has_changelog', false);
    }

    public function test_index_search_filter(): void
    {
        $this->makeActiveCorePack();
        LanguagePack::query()->create([
            'identifier' => 'foobar-core-fr',
            'vendor' => 'foobar',
            'scope' => LanguagePackScope::Core->value,
            'target_identifier' => null,
            'locale' => 'fr',
            'locale_name' => 'French',
            'locale_native_name' => 'Français',
            'text_direction' => 'ltr',
            'version' => '1.0.0',
            'status' => LanguagePackStatus::Active->value,
            'is_protected' => false,
            'manifest' => [],
            'source_type' => 'zip',
        ]);
        Sanctum::actingAs($this->manager);

        $response = $this->getJson('/api/admin/language-packs?search=foobar');

        $response->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.vendor', 'foobar');
    }
}
