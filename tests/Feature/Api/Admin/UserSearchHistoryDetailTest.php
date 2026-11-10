<?php

namespace Tests\Feature\Api\Admin;

use App\Extension\ModuleManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserSearchHistory;
use App\Support\SearchScopes;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * 관리자 회원 상세의 최근 검색어 (`search_history`, 코어 7.1.5)
 *
 * 최근 검색어는 회원 정보라 관리자 회원 조회에서 볼 수 있어야 한다. 상세 응답에만 범위 id 를 키로 싣고(코어 구획은
 * `core.site`, 확장 탭은 자기 범위), 지금 선언된 범위만 낸다 — 확장을 끄면 그 범위는 빠지고 행은 남는다.
 * 사이트가 기능을 껐거나 회원이 자동 저장을 꺼도 저장된 검색어는 보인다(끄기는 기록만 멈춘다).
 *
 * @scenario-manifest tests/scenarios/search-history.yaml
 */
#[Group('core')]
#[Group('user')]
class UserSearchHistoryDetailTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 회원 검색어 한 행을 만듭니다.
     *
     * @param  User  $user  회원
     * @param  string  $scope  범위
     * @param  string  $keyword  검색어
     * @param  string  $at  검색 일시
     * @return void 반환값 없음
     */
    private function history(User $user, string $scope, string $keyword, string $at): void
    {
        UserSearchHistory::query()->create(['user_id' => $user->id, 'scope' => $scope, 'keyword' => $keyword, 'searched_at' => Carbon::parse($at)]);
    }

    /**
     * 회원 읽기 권한만 가진 운영자를 만듭니다.
     *
     * @return User 운영자
     */
    private function operator(): User
    {
        $user = User::factory()->create(['email' => Str::random(8).'@sh.test']);
        $role = Role::create(['name' => ['ko' => 'sh', 'en' => 'sh'], 'identifier' => 'sh-'.$user->id, 'is_active' => true]);
        $permission = Permission::firstOrCreate(
            ['identifier' => 'core.users.read'],
            ['name' => ['ko' => 'core.users.read', 'en' => 'core.users.read'], 'type' => 'admin', 'order' => 1]
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    /**
     * 범위 하나를 선언한 활성 모듈을 끼웁니다.
     *
     * @return void 반환값 없음
     */
    private function activateFakeShop(): void
    {
        $fakeModule = new class
        {
            /**
             * 식별자를 반환합니다.
             *
             * @return string 식별자
             */
            public function getIdentifier(): string
            {
                return 'acme-shop';
            }

            /**
             * 범위 선언을 반환합니다.
             *
             * @return array<string, mixed> 범위 id → 선언
             */
            public function getSearchScopes(): array
            {
                return SearchScopes::normalize(['products' => ['label' => ['ko' => '상품 검색', 'en' => 'Products'], 'max_count' => 5]], 'acme-shop');
            }
        };
        $manager = Mockery::mock(app(ModuleManager::class))->makePartial();
        $manager->shouldReceive('getActiveModules')->andReturn([$fakeModule]);
        $this->app->instance(ModuleManager::class, $manager);
    }

    /**
     * 상세 응답이 범위별 검색어를 최신순으로 싣고, 자동 저장 상태를 함께 낸다. 목록 응답에는 싣지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=admin_user_detail
     *
     * @effects admin_detail_lists_keywords_per_scope_newest_first, admin_list_omits_search_history
     */
    public function test_detail_lists_keywords_per_scope_newest_first(): void
    {
        app()->setLocale('ko');
        $member = User::factory()->create(['email' => Str::random(8).'@sh.test']);
        $this->history($member, 'core.site', '등산화', '2026-11-04 10:00:00');
        $this->history($member, 'core.site', '텐트 폴대', '2026-11-04 11:00:00');
        $operator = $this->operator();

        $data = $this->actingAs($operator)->getJson('/api/admin/users/'.$member->uuid)->assertOk()->json('data.search_history');

        $this->assertTrue($data['enabled']);
        $this->assertTrue($data['auto_save']);
        $this->assertSame(['텐트 폴대', '등산화'], array_column($data['scopes']['core.site']['items'], 'keyword'));
        $this->assertSame('core', $data['scopes']['core.site']['owner']);
        $this->assertNotSame('', $data['scopes']['core.site']['label']);
        $this->assertNotNull($data['scopes']['core.site']['items'][0]['searched_at']);

        $rows = $this->actingAs($operator)->getJson('/api/admin/users')->assertOk()->json('data.data');
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('search_history', $row, '목록은 행마다 범위 조회가 붙지 않게 싣지 않는다');
        }
    }

    /**
     * 회원이 자동 저장을 끄거나 사이트가 기능을 꺼도 저장된 검색어는 그대로 보인다 — 상태 값만 바뀐다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=admin_user_detail
     *
     * @effects admin_detail_shows_stored_rows_when_saving_is_off
     */
    public function test_stored_keywords_stay_visible_when_saving_is_off(): void
    {
        $member = User::factory()->create(['email' => Str::random(8).'@sh.test', 'search_history_auto_save' => false]);
        $this->history($member, 'core.site', '남은 검색어', '2026-11-04 10:00:00');
        Config::set('g7_settings.core.privacy.search_history_enabled', false);

        $data = $this->actingAs($this->operator())->getJson('/api/admin/users/'.$member->uuid)->assertOk()->json('data.search_history');

        $this->assertFalse($data['enabled']);
        $this->assertFalse($data['auto_save']);
        $this->assertSame(['남은 검색어'], array_column($data['scopes']['core.site']['items'], 'keyword'));
    }

    /**
     * 활성 확장이 선언한 범위는 자기 키로 실리고, 선언이 없는(꺼진) 확장의 범위는 빠지되 행은 지우지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=admin_user_detail
     *
     * @effects admin_detail_includes_active_extension_scope, admin_detail_omits_inactive_extension_scope_keeps_rows
     */
    public function test_extension_scope_follows_activation(): void
    {
        app()->setLocale('ko');
        $member = User::factory()->create(['email' => Str::random(8).'@sh.test']);
        $this->history($member, 'acme-shop.products', '캠핑 의자', '2026-11-04 10:00:00');
        $operator = $this->operator();

        $inactive = $this->actingAs($operator)->getJson('/api/admin/users/'.$member->uuid)->assertOk()->json('data.search_history.scopes');
        $this->assertArrayNotHasKey('acme-shop.products', $inactive);
        $this->assertSame(1, UserSearchHistory::query()->where('user_id', $member->id)->count());

        $this->activateFakeShop();
        $active = $this->actingAs($operator)->getJson('/api/admin/users/'.$member->uuid)->assertOk()->json('data.search_history.scopes');

        $this->assertSame('module:acme-shop', $active['acme-shop.products']['owner']);
        $this->assertSame('상품 검색', $active['acme-shop.products']['label']);
        $this->assertSame(['캠핑 의자'], array_column($active['acme-shop.products']['items'], 'keyword'));
        $this->assertSame([], $active['core.site']['items']);
    }
}
