<?php

namespace Tests\Feature\Settings;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 환경설정 개인정보 탭 — 「최근 검색어」 카드 (코어 7.1.5)
 *
 * 범위별 보관 개수·보관 기한은 목록(`privacy.search_history_scopes = [{scope, max_count, retention_days}]`)으로 저장한다 —
 * 범위 id 에 점이 있어 맵은 점 표기 검증을 깨뜨린다. 응답은 선언 범위마다 한 행으로 다듬고 `_meta.search_scopes` 로
 * 범위 이름·소유 확장·선언 기본값을 함께 준다.
 *
 * DB 는 관리자 계정·역할만 쓰므로 트랜잭션으로 격리한다.
 *
 * @scenario-manifest tests/scenarios/search-history.yaml
 */
class SearchHistorySettingsTest extends TestCase
{
    use DatabaseTransactions;

    /** 관리자 토큰 */
    private string $token;

    /**
     * 설정 권한 관리자 토큰을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->token = $this->createAdminUser()->createToken('search-history-settings-test')->plainTextToken;
    }

    /**
     * 설정 읽기·저장 권한을 가진 관리자를 만듭니다.
     *
     * @return User 생성된 관리자
     */
    private function createAdminUser(): User
    {
        $user = User::factory()->create(['email' => 'search-history-'.Str::lower(Str::random(10)).'@example.com']);

        $permissionIds = [];
        foreach (['core.settings.read', 'core.settings.update'] as $identifier) {
            $permissionIds[] = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            )->id;
        }

        $role = Role::create([
            'identifier' => 'search_history_settings_test_'.Str::lower(Str::random(8)),
            'name' => json_encode(['ko' => '최근 검색어 설정 테스트', 'en' => 'Search history settings test']),
            'description' => json_encode(['ko' => '테스트 전용', 'en' => 'Test only']),
            'is_active' => true,
        ]);
        $role->permissions()->sync($permissionIds);

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'description' => json_encode(['ko' => '시스템 관리자', 'en' => 'System Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        $user->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }

    /**
     * 개인정보 탭 저장 요청을 보냅니다 (필수 기간 칸은 기본값으로 채운다).
     *
     * @param  array<string, mixed>  $searchHistory  최근 검색어 칸 (search_history_enabled·search_history_scopes)
     * @return TestResponse 응답
     */
    private function savePrivacy(array $searchHistory): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/admin/settings', [
                '_tab' => 'privacy',
                'privacy' => [
                    'consent_provider' => '',
                    'withdrawn_retention_enabled' => false,
                    'withdrawn_retention_days' => 365,
                    'dormant_enabled' => false,
                    'dormant_days' => 730,
                    'dormant_notice_days' => 30,
                    'dormant_withdraw_enabled' => false,
                    'dormant_withdraw_days' => 365,
                    ...$searchHistory,
                ],
            ]);
    }

    /**
     * 목록 모양으로 저장하고(숫자 문자열은 정수로), 응답은 선언 범위 행과 `_meta.search_scopes` 를 싣는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=settings_save_list_shape
     *
     * @effects settings_saved_as_list_shape, settings_meta_lists_declared_scopes
     */
    public function test_saves_list_shape_and_returns_meta(): void
    {
        $response = $this->savePrivacy([
            'search_history_enabled' => false,
            'search_history_scopes' => [
                ['scope' => 'core.site', 'max_count' => '25', 'retention_days' => '90'],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.settings.privacy.search_history_enabled', false);
        $response->assertJsonPath('data.settings.privacy.search_history_scopes.0', ['scope' => 'core.site', 'max_count' => 25, 'retention_days' => 90]);
        $response->assertJsonPath('data.settings._meta.search_scopes.0.scope', 'core.site');
        $response->assertJsonPath('data.settings._meta.search_scopes.0.owner', 'core');
        $response->assertJsonPath('data.settings._meta.search_scopes.0.label', __('search.history.scopes.core_site'));
        $response->assertJsonPath('data.settings._meta.search_scopes.0.default_max_count', 10);
        $response->assertJsonPath('data.settings._meta.search_scopes.0.default_retention_days', 0);

        $stored = app(ConfigRepositoryInterface::class)->getCategory('privacy');
        $this->assertSame([['scope' => 'core.site', 'max_count' => 25, 'retention_days' => 90]], $stored['search_history_scopes']);
        $this->assertFalse($stored['search_history_enabled']);
    }

    /**
     * 선언되지 않은 범위·중복 범위·범위 밖 숫자는 행 위치를 담은 키로 422 를 낸다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=settings_rejects_invalid_rows
     *
     * @effects settings_unknown_scope_rejected, settings_range_rejected
     */
    public function test_rejects_unknown_scope_duplicates_and_out_of_range(): void
    {
        $this->savePrivacy(['search_history_scopes' => [['scope' => 'acme-none.items', 'max_count' => 10, 'retention_days' => 0]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('privacy.search_history_scopes.0.scope');

        $this->savePrivacy(['search_history_scopes' => [
            ['scope' => 'core.site', 'max_count' => 10, 'retention_days' => 0],
            ['scope' => 'core.site', 'max_count' => 10, 'retention_days' => 0],
        ]])->assertStatus(422)->assertJsonValidationErrors('privacy.search_history_scopes.1.scope');

        $this->savePrivacy(['search_history_scopes' => [['scope' => 'core.site', 'max_count' => 51, 'retention_days' => -1]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['privacy.search_history_scopes.0.max_count', 'privacy.search_history_scopes.0.retention_days']);
    }
}
