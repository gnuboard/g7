<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackStatus;
use App\Models\LanguagePack;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\LanguagePackService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

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
        $this->seed(RolePermissionSeeder::class);

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
        File::ensureDirectoryExists($bundledPath);
        File::put(
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
            File::deleteDirectory($bundledPath);
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
        File::ensureDirectoryExists($bundledPath);
        File::put(
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
            File::deleteDirectory($bundledPath);
            File::deleteDirectory($installedDir);
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
            'file' => UploadedFile::fake()->createWithContent('big.zip', $bigContent),
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

    // ========================================================================
    // 활성화 권한 경계 (KVE-2026-1678)
    // ========================================================================

    /**
     * install 권한만 가진 계정은 `auto_activate` 로 활성화까지 수행할 수 없다.
     *
     * 설치(install)와 활성화(manage)는 원래 별도 권한인데, 설치 요청의 `auto_activate`
     * 플래그가 그 경계를 우회하고 있었다. 활성 팩만 fallback 경로에 배선되어 `require`
     * 되므로 이 경계가 임의 PHP 실행의 마지막 관문이다.
     *
     * 외부 소스 3종(파일·URL·GitHub) 모두 같은 경계를 가져야 한다 — 한 곳만 막으면
     * 나머지가 우회로가 된다.
     *
     * @param  string  $endpoint  설치 엔드포인트
     * @param  array<string, mixed>  $payload  `auto_activate` 를 제외한 요청 본문
     *
     * @scenario vector=install_from_untrusted_source, actor_permission=install_only
     *
     * @effects install_only_user_cannot_auto_activate
     */
    #[DataProvider('externalInstallEndpointProvider')]
    public function test_install_only_user_cannot_auto_activate(string $endpoint, array $payload): void
    {
        Sanctum::actingAs($this->makeUserWithPermissions(['core.language_packs.install']));

        $response = $this->postJson($endpoint, $payload + ['auto_activate' => true]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('auto_activate');
    }

    /**
     * `auto_activate` 를 보내지 않으면 install 권한만으로도 설치 요청이 통과한다.
     *
     * 게이트가 설치 자체를 막으면 안 된다 — 거부되는 것은 그 항목 하나뿐이어야 한다.
     * (설치 자체는 뒤이어 소스 검증에서 실패하므로 `auto_activate` 검증 오류가
     * 없다는 것만 단언한다.)
     *
     * @param  string  $endpoint  설치 엔드포인트
     * @param  array<string, mixed>  $payload  요청 본문
     *
     * @scenario vector=install_from_untrusted_source, actor_permission=install_only
     *
     * @effects install_without_auto_activate_is_not_blocked
     */
    #[DataProvider('externalInstallEndpointProvider')]
    public function test_install_without_auto_activate_is_not_blocked_for_install_only_user(string $endpoint, array $payload): void
    {
        Sanctum::actingAs($this->makeUserWithPermissions(['core.language_packs.install']));

        $response = $this->postJson($endpoint, $payload);

        $this->assertArrayNotHasKey(
            'auto_activate',
            (array) ($response->json('errors') ?? []),
            "auto_activate 를 보내지 않았는데 활성화 권한 게이트가 걸렸다: {$endpoint}"
        );
    }

    /**
     * manage 권한을 가진 계정의 `auto_activate` 는 권한 게이트에 걸리지 않는다.
     *
     * @param  string  $endpoint  설치 엔드포인트
     * @param  array<string, mixed>  $payload  요청 본문
     *
     * @scenario vector=install_from_untrusted_source, actor_permission=install_and_manage
     *
     * @effects manage_user_can_auto_activate
     */
    #[DataProvider('externalInstallEndpointProvider')]
    public function test_manage_user_is_not_blocked_by_the_activation_gate(string $endpoint, array $payload): void
    {
        Sanctum::actingAs($this->manager);

        $response = $this->postJson($endpoint, $payload + ['auto_activate' => true]);

        $this->assertArrayNotHasKey(
            'auto_activate',
            (array) ($response->json('errors') ?? []),
            "활성화 권한 보유자가 게이트에 걸렸다: {$endpoint}"
        );
    }

    /**
     * 활성화 권한만 가진 계정은 설치 자체를 할 수 없다.
     *
     * 활성화 게이트를 `auto_activate` 필드에 건 결과로 "manage 만 있으면 설치가 된다" 는
     * 반대 방향의 구멍이 생기지 않았음을 고정한다. 설치 권한은 라우트 미들웨어가 소유하므로
     * 필드 단위 Rule 에 도달하기 전에 403 으로 끊겨야 한다 — 여기서 422(검증 단계 도달)가
     * 나오면 설치 권한 경계가 무너진 것이다.
     *
     * @param  string  $endpoint  설치 엔드포인트
     * @param  array<string, mixed>  $payload  요청 본문
     *
     * @scenario vector=install_from_untrusted_source, actor_permission=manage_only
     *
     * @effects manage_only_user_cannot_install_at_all
     */
    #[DataProvider('externalInstallEndpointProvider')]
    public function test_manage_only_user_cannot_install(string $endpoint, array $payload): void
    {
        Sanctum::actingAs($this->makeUserWithPermissions(['core.language_packs.manage']));

        $response = $this->postJson($endpoint, $payload + ['auto_activate' => true]);

        $response->assertStatus(403);
    }

    /**
     * ZIP 업로드 경로에도 동일한 활성화 권한 경계가 적용된다.
     *
     * 보고서의 주 공격 벡터가 이 경로다 — 업로드한 PHP 를 곧바로 활성화해
     * `require` 경로에 배선하는 것이 체인의 마지막 단계였다.
     *
     * @scenario vector=install_from_untrusted_source, actor_permission=install_only
     *
     * @effects install_only_user_cannot_auto_activate
     */
    public function test_install_from_file_blocks_auto_activate_for_install_only_user(): void
    {
        Sanctum::actingAs($this->makeUserWithPermissions(['core.language_packs.install']));

        $response = $this->postJson('/api/admin/language-packs/install-from-file', [
            'file' => UploadedFile::fake()->create('pack.zip', 8, 'application/zip'),
            'auto_activate' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('auto_activate');
    }

    /**
     * 번들 설치도 외부 소스와 동일하게 활성화 권한 게이트를 적용한다 (프로젝트 결정, 2026-08-04).
     *
     * 설치(install)와 활성화(manage)를 별도 권한으로 두는 정책을 네 경로 전부에 같은 강도로
     * 적용한다 — 어느 경로로 들어오느냐가 권한 경계를 바꾸면 그 경로가 우회로가 된다.
     *
     * 검토 단계에서는 "번들은 저장소 동봉 신뢰 자산이라 임의 PHP 반입 경로가 아니다" 를 근거로
     * 면제했으나, 네 경로에 일관 적용하는 것으로 결정했다. 그 결과 설치 권한만 가진 운영자가 활성
     * 팩을 재설치하면 그 팩이 `installed` 로 내려가고, 다시 켜려면 관리 권한이 필요하다.
     *
     * @scenario vector=install_from_bundled, actor_permission=install_only
     *
     * @effects install_only_user_cannot_auto_activate
     */
    public function test_bundled_install_blocks_auto_activate_for_install_only_user(): void
    {
        Sanctum::actingAs($this->makeUserWithPermissions(['core.language_packs.install']));

        $response = $this->postJson('/api/admin/language-packs/install-from-bundled', [
            'identifier' => 'g7-core-nonexistent',
            'auto_activate' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('auto_activate');
    }

    /**
     * 번들 설치에서 `auto_activate` 를 보내지 않으면 게이트가 발동하지 않는다.
     *
     * 게이트를 `authorize()` 가 아니라 필드 단위 Rule 로 건 이유를 고정한다 — 설치 권한만
     * 가진 운영자의 평범한 번들 설치까지 403 이 되면 안 된다.
     *
     * 존재하지 않는 identifier 라 설치 자체는 실패하지만, 그 실패가 `auto_activate` 검증
     * 오류가 아니어야 한다는 것이 이 테스트의 단언 대상이다.
     *
     * @scenario vector=install_from_bundled, actor_permission=install_only
     *
     * @effects install_without_auto_activate_is_not_blocked
     */
    public function test_bundled_install_without_auto_activate_is_not_blocked(): void
    {
        Sanctum::actingAs($this->makeUserWithPermissions(['core.language_packs.install']));

        $response = $this->postJson('/api/admin/language-packs/install-from-bundled', [
            'identifier' => 'g7-core-nonexistent',
        ]);

        $this->assertArrayNotHasKey(
            'auto_activate',
            (array) ($response->json('errors') ?? []),
            'auto_activate 를 보내지도 않은 요청에 활성화 권한 게이트가 발동했다'
        );
    }

    /**
     * 관리 권한을 함께 가진 계정은 번들 재설치에서 `auto_activate` 로 막히지 않는다.
     *
     * 게이트가 권한 유무로 갈리는지(무조건 거부가 아닌지) 확인한다.
     *
     * @scenario vector=install_from_bundled, actor_permission=install_and_manage
     *
     * @effects manage_user_can_auto_activate
     */
    public function test_bundled_install_allows_auto_activate_for_manage_user(): void
    {
        Sanctum::actingAs($this->makeUserWithPermissions([
            'core.language_packs.install',
            'core.language_packs.manage',
        ]));

        $response = $this->postJson('/api/admin/language-packs/install-from-bundled', [
            'identifier' => 'g7-core-nonexistent',
            'auto_activate' => true,
        ]);

        $this->assertArrayNotHasKey(
            'auto_activate',
            (array) ($response->json('errors') ?? []),
            '관리 권한 보유자에게도 활성화 게이트가 걸렸다'
        );
    }

    /**
     * install 권한만 가진 계정은 활성 팩을 덮어쓰는 방식으로도 활성화 게이트를 우회할 수 없다.
     *
     * `auto_activate` 에 게이트를 걸면 남는 우회로는 "이미 활성인 팩과 같은 identifier 로
     * 덮어쓰기" 다. 덮어쓴 내용이 활성 상태를 그대로 물려받는다면 게이트를 우회해
     * 임의 PHP 가 `require` 경로에 배선된다.
     *
     * 설치 트랜잭션이 항상 `installed` 로만 기록하므로, 덮어쓴 팩은 active → installed 로
     * 강등된다. 겉보기엔 재설치 부작용이지만 이 체인의 마지막 관문을 지키는 안전장치다.
     *
     * 이 강등 자체는 이번 수정 이전에도 성립하던 동작이다(예전 코드도 `auto_activate` 가
     * 없으면 `installed` 로 기록했다). 그런데 활성화 게이트가 이 성질에 의존하게 됐으므로,
     * "재설치하면 활성 상태가 풀린다" 를 불편으로 보고 되돌리는 순간 게이트가 통째로
     * 무력화된다. 그래서 테스트로 고정한다.
     *
     * @scenario vector=install_from_untrusted_source, actor_permission=install_only
     *
     * @effects overwriting_an_active_pack_does_not_inherit_active_status
     */
    public function test_install_only_user_cannot_reactivate_by_overwriting_active_pack(): void
    {
        $identifier = 'ovrwrite-core-ja';

        try {
            // 1) 활성화 권한 보유자가 정상 설치 + 활성화
            Sanctum::actingAs($this->manager);
            $this->postJson('/api/admin/language-packs/install-from-file', [
                'file' => new UploadedFile(
                    $this->makeLanguagePackZip($identifier, '1.0.0', "<?php\nreturn ['greeting' => 'こんにちは'];\n"),
                    'pack.zip', 'application/zip', null, true
                ),
                'auto_activate' => true,
            ])->assertSuccessful();

            $pack = LanguagePack::query()->where('identifier', $identifier)->firstOrFail();
            $this->assertSame(LanguagePackStatus::Active->value, $pack->status, '전제 조건 — 덮어쓰기 대상은 활성 팩이어야 한다');

            // 2) install 권한만 가진 계정이 같은 identifier 로 덮어쓰기 (auto_activate 미전송)
            Sanctum::actingAs($this->makeUserWithPermissions(['core.language_packs.install']));
            $this->postJson('/api/admin/language-packs/install-from-file', [
                'file' => new UploadedFile(
                    $this->makeLanguagePackZip($identifier, '1.0.1', "<?php\nreturn ['greeting' => 'overwritten'];\n"),
                    'pack.zip', 'application/zip', null, true
                ),
            ])->assertSuccessful();

            $pack->refresh();
            $this->assertSame('1.0.1', $pack->version, '전제 조건 — 덮어쓰기가 실제로 수행되어야 한다');
            $this->assertSame(
                LanguagePackStatus::Installed->value,
                $pack->status,
                '덮어쓴 팩이 활성 상태를 물려받았다 — install 권한만으로 활성화 게이트가 우회된다'
            );
        } finally {
            File::deleteDirectory(base_path('lang-packs/'.$identifier));
        }
    }

    /**
     * 코어 ja 언어팩 ZIP 을 임시로 만듭니다.
     *
     * @param  string  $identifier  언어팩 식별자
     * @param  string  $version  버전
     * @param  string  $translationPhp  `backend/ja/messages.php` 로 담을 내용
     * @return string 생성된 ZIP 절대 경로
     */
    private function makeLanguagePackZip(string $identifier, string $version, string $translationPhp): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'lp_ctrl_').'.zip';

        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('language-pack.json', json_encode([
            'identifier' => $identifier,
            'namespace' => 'ovrwrite',
            'vendor' => 'ovrwrite',
            'name' => ['ko' => $identifier, 'en' => $identifier],
            'version' => $version,
            'scope' => LanguagePackScope::Core->value,
            'target_identifier' => null,
            'locale' => 'ja',
            'locale_native_name' => '日本語',
            'g7_version' => '>=7.0.0-beta.4',
        ], JSON_UNESCAPED_UNICODE));
        $zip->addFromString('backend/ja/messages.php', $translationPhp);
        $zip->close();

        return $zipPath;
    }

    /**
     * 외부 소스 설치 엔드포인트 목록 (유효성만 통과하는 최소 본문).
     *
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function externalInstallEndpointProvider(): array
    {
        return [
            'GitHub' => ['/api/admin/language-packs/install-from-github', [
                'github_url' => 'https://github.com/gnuboard/g7-lang-nonexistent',
            ]],
            'URL' => ['/api/admin/language-packs/install-from-url', [
                'url' => 'https://example.com/pack.zip',
            ]],
        ];
    }

    /**
     * 실패 응답에 예외 원문이 실리지 않는다.
     *
     * 종전에는 `$e->getMessage()` 를 응답 errors/messageParams 로 그대로 넘겨,
     * 검증 실패 메시지에 내부 예외 문자열(경로·드라이버 오류 등)이 노출됐다.
     * 디버그 노출은 ResponseHelper 가 app.debug 에서만 Throwable 을 펼치는
     * 기존 메커니즘에 위임한다.
     *
     * @scenario endpoint=manifest_preview
     *
     * @effects language_pack_manifest_preview_failure_omits_exception_text
     */
    public function test_manifest_preview_failure_does_not_leak_exception_message(): void
    {
        config(['app.debug' => false]);

        Sanctum::actingAs($this->manager);

        // ZIP 이 아닌 파일 → previewManifest 내부에서 예외 발생
        $response = $this->postJson('/api/admin/language-packs/manifest-preview', [
            'file' => UploadedFile::fake()->createWithContent('broken.zip', 'not-a-zip-archive'),
        ]);

        $response->assertStatus(422);

        $body = $response->getContent();
        foreach (['ZipArchive', 'SQLSTATE', '.php', 'vendor/', 'Exception'] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $body,
                "실패 응답에 내부 예외 흔적('{$needle}')이 포함되어 있습니다: {$body}"
            );
        }
    }

    /**
     * 캐시 갱신 실패 응답에도 예외 원문이 실리지 않는다 (500 축).
     *
     * @scenario endpoint=refresh_cache
     *
     * @effects language_pack_refresh_cache_failure_omits_exception_text
     */
    public function test_refresh_cache_failure_does_not_leak_exception_message(): void
    {
        config(['app.debug' => false]);

        $this->mock(LanguagePackService::class, function ($mock) {
            $mock->shouldReceive('refreshCache')
                ->andThrow(new \RuntimeException('SQLSTATE[HY000]: internal detail'));
        });

        Sanctum::actingAs($this->manager);

        $response = $this->postJson('/api/admin/language-packs/refresh-cache');

        $response->assertStatus(500);
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }
}
