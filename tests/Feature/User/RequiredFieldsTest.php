<?php

namespace Tests\Feature\User;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\FieldDefinitionRepositoryInterface;
use App\Extension\HookManager;
use App\Fields\FieldContext;
use App\Fields\FieldRegistry;
use App\Fields\FieldValueService;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureRequiredFieldsCompleted;
use App\Models\FieldDefinition;
use App\Models\Role;
use App\Models\User;
use App\Services\RequiredFieldsChecker;
use App\Support\NicknameNormalizer;
use Database\Seeders\CoreFieldDefinitionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 비어 있는 필수 항목 판정과, 그 판정을 쓰는 관리자 회원 목록(행의 미입력 항목, 「필수 항목 미입력」 필터, 「닉네임 중복」 필터, 동적 열 정보).
 */
class RequiredFieldsTest extends TestCase
{
    use RefreshDatabase;

    private string $adminToken;

    /**
     * 권한과 코어 회원 항목을 시딩하고 최고관리자 토큰을 준비한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(CoreFieldDefinitionSeeder::class);

        $admin = User::factory()->create(['is_super' => true, 'nickname' => null]);
        $adminRole = Role::where('identifier', 'admin')->first();
        if ($adminRole) {
            $admin->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        }
        $this->adminToken = $admin->fresh()->createToken('test')->plainTextToken;
    }

    /**
     * 테스트가 건 잠금 구독자를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearFilter('core.user.locked_fields');
        HookManager::clearFilter('core.user.locked_field_reasons');
        parent::tearDown();
    }

    /**
     * 최고관리자 인증 헤더를 만듭니다.
     *
     * @return array<string, string> 헤더
     */
    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->adminToken, 'Accept' => 'application/json'];
    }

    /**
     * 운영자 추가 항목을 만듭니다.
     *
     * @param  string  $key  항목 키
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @return FieldDefinition 만든 항목
     */
    private function custom(string $key, array $attributes = []): FieldDefinition
    {
        $definition = FieldDefinition::create(array_merge([
            'field_set' => 'core.user', 'key' => $key, 'type' => 'text', 'source_type' => 'custom',
            'source_identifier' => 'custom', 'storage' => 'value', 'name' => ['ko' => $key.' 이름'], 'sort_order' => 500,
        ], $attributes));
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();

        return $definition->refresh();
    }

    /**
     * 코어 회원 항목의 속성을 바꿉니다.
     *
     * @param  string  $key  항목 키
     * @param  array<string, mixed>  $attributes  바꿀 속성
     * @return void 반환값 없음
     */
    private function core(string $key, array $attributes): void
    {
        FieldDefinition::where('field_set', 'core.user')->where('key', $key)->update($attributes);
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();
    }

    /**
     * 회원의 운영자 추가 항목 값을 저장합니다.
     *
     * @param  User  $user  회원
     * @param  array<string, mixed>  $values  항목 키 => 값
     * @return void 반환값 없음
     */
    private function fill(User $user, array $values): void
    {
        app(FieldValueService::class)->write($user, 'core.user', $values, FieldContext::for(FieldContext::SURFACE_ADMIN_UPDATE, $user));
    }

    /**
     * 보통 회원은 「기존 회원에게도 입력 요구」를 켠 필수 항목만, 건너뛰고 만들어진 회원은 필수 항목 전부가 판정 대상이다.
     *
     * @scenario case=member_kind
     *
     * @effects pending_judged_by_require_existing_flag, pending_covers_all_required_for_deferred_member
     *
     * @return void 반환값 없음
     */
    public function test_pending_keys_depend_on_member_kind(): void
    {
        $this->custom('rf_demanded', ['required' => true, 'require_existing_members' => true]);
        $this->custom('rf_signup_only', ['required' => true, 'require_existing_members' => false]);
        $this->custom('rf_optional', ['required' => false, 'require_existing_members' => true]);
        $this->custom('rf_admin', ['required' => true, 'require_existing_members' => true, 'edit_scope' => FieldDefinition::SCOPE_ADMIN_EDIT]);

        $checker = app(RequiredFieldsChecker::class);
        $normal = User::factory()->create();
        $deferred = User::factory()->create(['required_fields_deferred_at' => now()]);

        $this->assertSame(['rf_demanded'], $checker->pendingKeys($normal), '선택 항목이나 관리자만 수정 항목, 요구를 켜지 않은 항목이 섞였다');
        $this->assertSame(['rf_demanded', 'rf_signup_only'], $checker->pendingKeys($deferred));

        $this->fill($normal, ['rf_demanded' => '채움']);
        $this->fill($deferred, ['rf_demanded' => '채움']);
        $this->assertSame([], $checker->pendingKeys($normal->fresh()));
        $this->assertSame(['rf_signup_only'], $checker->pendingKeys($deferred->fresh()));
        $this->assertSame([['key' => 'rf_signup_only', 'label' => 'rf_signup_only 이름']], $checker->describe(['rf_signup_only']));
    }

    /**
     * 컬럼 항목(휴대폰)도 판정하고, 그 회원에게 잠긴 칸은 판정에서 뺀다. 여러 회원을 볼 때 잠금을 보지 않으면 잠긴 칸도 센다.
     *
     * @scenario case=locked
     *
     * @effects pending_excludes_fields_locked_for_member
     *
     * @return void 반환값 없음
     */
    public function test_locked_fields_are_excluded_for_single_member(): void
    {
        $this->core('mobile', ['required' => true, 'require_existing_members' => true]);
        $user = User::factory()->create(['mobile' => null]);
        $checker = app(RequiredFieldsChecker::class);

        $this->assertSame(['mobile'], $checker->pendingKeys($user));

        HookManager::addFilter('core.user.locked_fields', fn ($fields) => [...(array) $fields, 'mobile']);
        $this->assertSame([], $checker->pendingKeys($user), '잠긴 칸이 미입력으로 남았다');
        $this->assertSame([$user->id => ['mobile']], $checker->pendingFor(new Collection([$user]), excludeLocked: false));
    }

    /**
     * 회원이 늘어도 판정에 드는 쿼리 수는 그대로다(값 테이블을 회원마다 읽지 않는다).
     *
     * @scenario case=batch
     *
     * @effects pending_batch_uses_constant_queries
     *
     * @return void 반환값 없음
     */
    public function test_batch_judgement_uses_constant_queries(): void
    {
        $this->custom('rf_demanded', ['required' => true, 'require_existing_members' => true]);
        $checker = app(RequiredFieldsChecker::class);
        $count = function (Collection $users) use ($checker): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $checker->pendingFor($users, excludeLocked: false);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        // 첫 호출은 항목 정의를 읽는 쿼리가 한 번 더 든다 — 정의를 읽어 둔 뒤에 센다.
        $checker->candidates();
        $two = $count(User::factory()->count(2)->create());
        $eight = $count(User::factory()->count(8)->create());

        $this->assertSame($two, $eight, "회원 2명일 때 {$two}건, 8명일 때 {$eight}건");
        $this->assertLessThanOrEqual(2, $eight);
    }

    /**
     * 관리자 회원 목록 행에 비어 있는 필수 항목이 실리고, 「필수 항목 미입력」 필터는 그 회원만 돌려준다.
     *
     * @scenario case=list_required_missing
     *
     * @effects list_rows_carry_pending_required_fields, list_filters_members_missing_required_fields
     *
     * @return void 반환값 없음
     */
    public function test_admin_list_carries_pending_and_filters_missing(): void
    {
        $this->custom('rf_demanded', ['required' => true, 'require_existing_members' => true]);
        $this->custom('rf_signup_only', ['required' => true, 'require_existing_members' => false]);

        $filled = User::factory()->create(['name' => '채운회원']);
        $this->fill($filled, ['rf_demanded' => '값']);
        $empty = User::factory()->create(['name' => '빈회원']);
        $this->fill($empty, ['rf_signup_only' => '값']);
        $deferredEmpty = User::factory()->create(['name' => '건너뛴회원', 'required_fields_deferred_at' => now()]);
        $this->fill($deferredEmpty, ['rf_demanded' => '값']);

        $rows = collect($this->getJson('/api/admin/users?per_page=50', $this->auth())->assertOk()->json('data.data'))->keyBy('uuid');
        $this->assertSame([], $rows[$filled->uuid]['pending_required_fields']);
        $this->assertSame([['key' => 'rf_demanded', 'label' => 'rf_demanded 이름']], $rows[$empty->uuid]['pending_required_fields']);
        $this->assertSame(['rf_signup_only'], array_column($rows[$deferredEmpty->uuid]['pending_required_fields'], 'key'));

        $missing = collect($this->getJson('/api/admin/users?per_page=50&required_missing=1', $this->auth())->assertOk()->json('data.data'))->pluck('uuid');
        $this->assertTrue($missing->contains($empty->uuid));
        $this->assertTrue($missing->contains($deferredEmpty->uuid));
        $this->assertFalse($missing->contains($filled->uuid), '필수 항목을 채운 회원이 미입력 필터에 걸렸다');
    }

    /**
     * 요구하는 필수 항목이 하나도 없으면 「필수 항목 미입력」 필터의 결과는 0건이다.
     *
     * @scenario case=list_required_missing_none
     *
     * @effects list_missing_filter_empty_without_required_fields
     *
     * @return void 반환값 없음
     */
    public function test_missing_filter_returns_nothing_without_required_fields(): void
    {
        $this->core('name', ['required' => false]);
        User::factory()->count(2)->create();

        $response = $this->getJson('/api/admin/users?required_missing=1', $this->auth())->assertOk();

        $this->assertSame([], $response->json('data.data'));
        $this->getJson('/api/admin/users?required_missing=maybe', $this->auth())->assertStatus(422)->assertJsonValidationErrors(['required_missing']);
    }

    /**
     * 「닉네임 중복」 필터는 정규화 닉네임이 다른 회원과 겹치는 회원만 돌려준다.
     *
     * @scenario case=list_nickname_duplicate
     *
     * @effects list_filters_members_with_duplicated_nickname
     *
     * @return void 반환값 없음
     */
    public function test_nickname_duplicate_filter(): void
    {
        $normalized = NicknameNormalizer::normalize('겹침닉');
        $a = User::factory()->create(['nickname' => '겹침닉', 'nickname_normalized' => $normalized]);
        $b = User::factory()->create(['nickname' => '겹침 닉', 'nickname_normalized' => $normalized]);
        $solo = User::factory()->create(['nickname' => '혼자닉', 'nickname_normalized' => NicknameNormalizer::normalize('혼자닉')]);
        $none = User::factory()->create(['nickname' => null, 'nickname_normalized' => null]);

        $uuids = collect($this->getJson('/api/admin/users?per_page=50&nickname_duplicate=1', $this->auth())->assertOk()->json('data.data'))->pluck('uuid');

        $this->assertEqualsCanonicalizing([$a->uuid, $b->uuid], $uuids->all());
        $this->assertFalse($uuids->contains($solo->uuid));
        $this->assertFalse($uuids->contains($none->uuid));
    }

    /**
     * 목록 응답은 운영자가 목록 열, 회원 검색으로 켠 추가 항목을 열 정보와 검색 대상으로 알려 준다.
     *
     * @scenario case=list_member_fields_meta
     *
     * @effects list_exposes_dynamic_columns_and_search_fields
     *
     * @return void 반환값 없음
     */
    public function test_list_exposes_dynamic_columns_and_search_fields(): void
    {
        $this->custom('rf_col', ['admin_list_column' => true, 'admin_searchable' => true]);
        $this->custom('rf_search', ['admin_searchable' => true]);
        $this->custom('rf_plain');
        $user = User::factory()->create();
        $this->fill($user, ['rf_col' => '열 값']);

        $data = $this->getJson('/api/admin/users?per_page=50', $this->auth())->assertOk()->json('data');

        $this->assertSame([['key' => 'rf_col', 'field' => 'custom_fields.rf_col', 'header' => 'rf_col 이름']], $data['member_fields']['columns']);
        $this->assertSame(
            [['value' => 'custom:rf_col', 'label' => 'rf_col 이름'], ['value' => 'custom:rf_search', 'label' => 'rf_search 이름']],
            $data['member_fields']['search_fields'],
        );
        $row = collect($data['data'])->firstWhere('uuid', $user->uuid);
        $this->assertSame(['rf_col' => '열 값'], $row['custom_fields']);
    }

    /**
     * 회원 권한을 가진 회원과 그 인증 헤더를 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  회원 속성
     * @return array{0: User, 1: array<string, string>} 회원과 인증 헤더
     */
    private function member(array $attributes = []): array
    {
        $user = User::factory()->create($attributes);
        $role = Role::where('identifier', 'user')->first();
        if ($role && ! $user->roles()->where('roles.id', $role->id)->exists()) {
            $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);
        }
        $token = $user->fresh()->createToken('member')->plainTextToken;

        return [$user->fresh(), ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json']];
    }

    /**
     * 모듈 라우트가 등록되는 모양 그대로(`api/modules/{모듈}` 접두, `api` 그룹) 게시판 글 목록, 글쓰기, 비밀번호 확인 라우트를 세웁니다.
     *
     * 코어 테스트 환경에는 모듈이 올라오지 않는다 — 모듈 라우트 등록자(`ModuleRouteServiceProvider`)와 같은 접두, 이름, 그룹으로
     * 대역 라우트를 세워 「`api` 그룹을 받는 확장 라우트에 관문이 닿는다」를 잰다.
     *
     * @return void 반환값 없음
     */
    private function registerBoardRoutes(): void
    {
        Route::prefix('api/modules/sirsoft-board')
            ->name('api.modules.sirsoft-board.')
            ->middleware('api')
            ->group(function () {
                Route::get('boards/{slug}/posts', fn () => response()->json(['ok' => true]))
                    ->middleware('optional.sanctum')->name('boards.posts.index');
                Route::post('boards/{slug}/posts', fn () => response()->json(['ok' => true], 201))
                    ->middleware('optional.sanctum')->name('boards.posts.store');
                Route::post('boards/{slug}/posts/{id}/verify-password', fn () => response()->json(['ok' => true]))
                    ->middleware('optional.sanctum')->name('boards.posts.verify-password');
                Route::post('me/scraps', fn () => response()->json(['ok' => true], 201))
                    ->middleware('auth:sanctum')->name('me.scraps.store');
            });
        Route::getRoutes()->refreshNameLookups();
    }

    /**
     * 요청 사이에 인증 상태가 넘어가지 않게 가드를 비웁니다.
     *
     * @return void 반환값 없음
     */
    private function resetAuth(): void
    {
        $this->app['auth']->forgetGuards();
    }

    /**
     * 판정은 `fields:pending:{회원 ID}:{정의 버전}` 에 캐시되고, 운영자가 항목을 바꾸면 새 버전의 키로 다시 판정한다.
     *
     * @scenario case=cache_version
     *
     * @effects pending_cached_under_user_and_definition_version, pending_recomputed_when_definition_version_changes
     *
     * @return void 반환값 없음
     */
    public function test_pending_is_cached_per_definition_version(): void
    {
        $this->custom('rf_demanded', ['required' => true, 'require_existing_members' => true]);
        $checker = app(RequiredFieldsChecker::class);
        $cache = app(CacheInterface::class);
        $user = User::factory()->create();

        $firstKey = $checker->cacheKey($user);
        $this->assertSame('fields:pending:'.$user->id.':'.app(FieldRegistry::class)->version('core.user'), $firstKey);
        $this->assertFalse($cache->has($firstKey));

        $this->assertSame(['rf_demanded'], $checker->pendingKeys($user));
        $this->assertSame(['rf_demanded'], $cache->get($firstKey), '판정이 캐시에 들어가지 않았다');

        // 두 번째 판정은 값 테이블을 읽지 않는다.
        DB::flushQueryLog();
        DB::enableQueryLog();
        $checker->pendingKeys($user);
        $valueQueries = collect(DB::getQueryLog())->filter(fn (array $query) => str_contains($query['query'], 'field_values'))->count();
        DB::disableQueryLog();
        $this->assertSame(0, $valueQueries, '캐시가 있는데 값 테이블을 다시 읽었다');

        // 운영자가 항목을 바꾸면 정의 버전이 달라져 새 키로 다시 판정한다.
        $this->custom('rf_second', ['required' => true, 'require_existing_members' => true]);
        $secondKey = $checker->cacheKey($user);
        $this->assertNotSame($firstKey, $secondKey);
        $this->assertSame(['rf_demanded', 'rf_second'], $checker->pendingKeys($user));
    }

    /**
     * 회원 값이 저장되거나 건너뛴 표시가 바뀌면 판정 캐시가 지워진다.
     *
     * @scenario case=cache_invalidation
     *
     * @effects pending_cache_forgotten_on_member_value_save, pending_cache_forgotten_on_member_row_change
     *
     * @return void 반환값 없음
     */
    public function test_cache_is_forgotten_when_member_values_change(): void
    {
        $this->custom('rf_demanded', ['required' => true, 'require_existing_members' => true]);
        $this->custom('rf_signup_only', ['required' => true, 'require_existing_members' => false]);
        $this->core('mobile', ['required' => true, 'require_existing_members' => true]);
        $checker = app(RequiredFieldsChecker::class);
        $cache = app(CacheInterface::class);
        $user = User::factory()->create(['mobile' => null]);

        $this->assertSame(['mobile', 'rf_demanded'], $checker->pendingKeys($user));

        // 값 테이블 항목 저장
        $this->fill($user, ['rf_demanded' => '채움']);
        $this->assertFalse($cache->has($checker->cacheKey($user)), '값을 저장했는데 캐시가 남았다');
        $this->assertSame(['mobile'], $checker->pendingKeys($user));

        // 회원 행(컬럼 항목) 저장 — 회원 서비스를 거치지 않은 저장도 지운다
        $user->update(['mobile' => '010-1234-5678']);
        $this->assertFalse($cache->has($checker->cacheKey($user)));
        $this->assertSame([], $checker->pendingKeys($user));

        // 건너뛴 표시가 생기면 요구를 켜지 않은 필수 항목도 대상이 된다
        $user->update(['required_fields_deferred_at' => now()]);
        $this->assertSame(['rf_signup_only'], $checker->pendingKeys($user->fresh()));
    }

    /**
     * 비어 있는 필수 항목이 있는 회원의 쓰기 요청은 모듈 라우트에서도 403 `required_fields_pending` 으로 거부되고, 읽기는 통과한다.
     *
     * @scenario case=write_block
     *
     * @effects pending_member_write_rejected_with_error_code, pending_member_read_passes, module_route_write_rejected, guest_write_passes
     *
     * @return void 반환값 없음
     */
    public function test_pending_member_write_is_rejected_on_module_route(): void
    {
        $this->registerBoardRoutes();
        $this->custom('rf_demanded', ['required' => true, 'require_existing_members' => true]);
        [, $pendingAuth] = $this->member();
        [$complete, $completeAuth] = $this->member();
        $this->fill($complete, ['rf_demanded' => '채움']);

        $response = $this->postJson('/api/modules/sirsoft-board/boards/free/posts', ['title' => '글'], $pendingAuth);
        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'required_fields_pending')
            ->assertJsonPath('message', __('user.required_fields_pending'))
            ->assertJsonPath('data.pending_required_fields', ['rf_demanded'])
            ->assertJsonPath('data.fields', [['key' => 'rf_demanded', 'label' => 'rf_demanded 이름']]);

        $this->resetAuth();
        $this->getJson('/api/modules/sirsoft-board/boards/free/posts', $pendingAuth)->assertOk();

        // 필수 항목을 채운 회원과 비회원은 그대로 쓴다.
        $this->resetAuth();
        $this->postJson('/api/modules/sirsoft-board/boards/free/posts', ['title' => '글'], $completeAuth)->assertStatus(201);
        $this->resetAuth();
        $this->postJson('/api/modules/sirsoft-board/boards/free/posts', ['title' => '글'], ['Accept' => 'application/json'])->assertStatus(201);
    }

    /**
     * 미입력 회원에게도 열어 두는 길: 내 정보 수정 두 주소, 언어 변경, 알림 읽음, 재확인, 로그아웃, 그리고 확장이 필터로 보탠 라우트.
     *
     * @scenario case=allow_list
     *
     * @effects pending_member_can_update_profile_on_both_addresses, pending_member_can_logout, pending_member_passes_marked_core_routes, extension_filter_exempts_named_routes
     *
     * @return void 반환값 없음
     */
    public function test_allow_list_lets_pending_member_through(): void
    {
        $this->registerBoardRoutes();
        $this->custom('rf_demanded', ['required' => true, 'require_existing_members' => true]);
        [$user, $auth] = $this->member(['name' => '미입력회원']);

        $assertNotBlocked = function ($response, string $what): void {
            $this->assertNotSame('required_fields_pending', $response->json('error_code'), $what.' 요청이 추가 정보 요구로 막혔다');
        };

        // 같은 컨트롤러의 두 주소가 모두 열려 있다 — 관문이 아니라 내 정보 저장의 검증(비어 있는 필수 항목)에 닿는다.
        foreach (['/api/me', '/api/user/profile'] as $uri) {
            $this->resetAuth();
            $response = $this->putJson($uri, ['name' => '고친이름', 'email' => $user->email, 'fields_version' => app(FieldRegistry::class)->version('core.user')], $auth);
            $assertNotBlocked($response, 'PUT '.$uri);
            $response->assertStatus(422)->assertJsonValidationErrors(['custom_fields.rf_demanded']);
        }

        $this->resetAuth();
        $assertNotBlocked($this->postJson('/api/user/profile/update-language', ['language' => 'en'], $auth), '언어 변경');
        $this->resetAuth();
        $assertNotBlocked($this->postJson('/api/user/notifications/read-all', [], $auth), '알림 모두 읽음');
        $this->resetAuth();
        $assertNotBlocked($this->postJson('/api/me/verify-password', ['password' => 'wrong'], $auth), '재확인');

        // 확장이 필터로 보탠 라우트 이름은 통과하고, 보태지 않은 라우트는 그대로 막힌다.
        HookManager::addFilter('core.required_fields.filter_exempt_routes', fn ($routes) => [...(array) $routes, 'api.modules.sirsoft-board.boards.posts.verify-password']);
        try {
            $this->resetAuth();
            $this->postJson('/api/modules/sirsoft-board/boards/free/posts/1/verify-password', [], $auth)->assertOk();
            $this->resetAuth();
            $this->postJson('/api/modules/sirsoft-board/boards/free/posts', [], $auth)->assertStatus(403)->assertJsonPath('error_code', 'required_fields_pending');
        } finally {
            HookManager::clearFilter('core.required_fields.filter_exempt_routes');
        }

        // 두 주소 모두 비어 있던 필수 항목을 채워 저장할 수 있다.
        $this->resetAuth();
        $this->putJson('/api/user/profile', ['name' => '고친이름', 'email' => $user->email, 'custom_fields' => ['rf_demanded' => '채움']], $auth)->assertOk();
        $this->resetAuth();
        $this->putJson('/api/me', ['name' => '또고친이름', 'email' => $user->email, 'custom_fields' => ['rf_demanded' => '채움']], $auth)->assertOk();
        $this->assertSame('또고친이름', $user->fresh()->name);
        $this->assertSame([], app(RequiredFieldsChecker::class)->pendingKeys($user->fresh()));

        // 로그아웃은 미입력 회원에게도 열려 있다.
        [, $otherAuth] = $this->member();
        $this->resetAuth();
        $this->postJson('/api/auth/logout', [], $otherAuth)->assertOk();
    }

    /**
     * 코어 라우트의 통과 표식이 「회원이 빠져나갈 길과 기술 요청」 묶음에 빠짐없이 붙어 있다.
     *
     * @scenario case=core_markers
     *
     * @effects core_exit_routes_carry_allow_marker
     *
     * @return void 반환값 없음
     */
    public function test_core_exit_routes_carry_the_allow_marker(): void
    {
        $marked = [
            'api.auth.logout', 'api.user.auth.logout', 'api.user.auth.logout-all-devices', 'api.user.auth.refresh',
            'api.me.update', 'api.me.destroy', 'api.me.required-fields.update', 'api.me.avatar.upload', 'api.me.avatar.delete',
            'api.me.verify-password', 'api.me.password', 'api.me.fields.file.store', 'api.me.fields.file.destroy',
            'api.me.search-history.store', 'api.me.search-history.merge', 'api.me.search-history.settings',
            'api.me.search-history.destroy-all', 'api.me.search-history.destroy',
            'api.user.profile.update', 'api.user.profile.update-language',
            'api.user.notifications.read', 'api.user.notifications.read-batch', 'api.user.notifications.read-all',
            'api.user.notifications.destroy-all', 'api.user.notifications.destroy',
            'api.identity.challenges.request', 'api.identity.challenges.verify', 'api.identity.challenges.claim',
            'api.identity.challenges.cancel', 'api.identity.callback', 'api.identity.claims',
            'api.broadcasting.auth', 'api.public.analytics.collect', 'api.public.csp_report',
        ];

        $missing = [];
        foreach ($marked as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "라우트 {$name} 가 없다");
            if (! in_array(EnsureRequiredFieldsCompleted::ALLOW_PENDING_ALIAS, $route->gatherMiddleware(), true)) {
                $missing[] = $name;
            }
        }

        $this->assertSame([], $missing, '통과 표식이 빠진 라우트가 있다');
    }

    /**
     * 관리자 API 는 미입력 관리자에게도 막지 않는다. 같은 관리자가 사용자 쪽 쓰기 라우트를 부르면 막힌다.
     *
     * @scenario case=admin_exception
     *
     * @effects admin_api_write_passes_for_pending_admin, pending_admin_blocked_on_user_route
     *
     * @return void 반환값 없음
     */
    public function test_admin_api_is_not_blocked(): void
    {
        $this->registerBoardRoutes();
        $this->custom('rf_demanded', ['required' => true, 'require_existing_members' => true]);
        $target = User::factory()->create(['name' => '대상회원']);

        $this->putJson('/api/admin/users/'.$target->uuid, [
            'name' => '관리자가고침',
            'email' => $target->email,
            'role_ids' => [Role::where('identifier', 'user')->value('id')],
        ], $this->auth())->assertOk();
        $this->assertSame('관리자가고침', $target->fresh()->name);

        $this->resetAuth();
        $this->postJson('/api/modules/sirsoft-board/boards/free/posts', [], $this->auth())
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'required_fields_pending');
    }

    /**
     * 필수 항목을 건너뛰고 만들어진 회원은 요구를 켜지 않은 필수 항목도 채워야 하고, 추가 정보 저장으로 다 채우면 건너뛴 표시가 지워진다.
     *
     * @scenario case=deferred_complete
     *
     * @effects required_context_lists_only_pending_fields, required_fields_save_accepts_only_pending_fields, deferred_mark_cleared_after_all_filled, auth_response_carries_pending_required_fields
     *
     * @return void 반환값 없음
     */
    public function test_deferred_member_completes_required_fields(): void
    {
        $this->registerBoardRoutes();
        $this->custom('rf_signup_only', ['required' => true, 'require_existing_members' => false]);
        $optional = $this->custom('rf_optional', ['required' => false]);
        [$user, $auth] = $this->member(['name' => '건너뛴회원', 'required_fields_deferred_at' => now()]);

        // 본인 응답이 미입력 키를 싣는다.
        $this->getJson('/api/auth/user', $auth)->assertOk()->assertJsonPath('data.pending_required_fields', ['rf_signup_only']);

        // 추가 정보 화면의 항목은 비어 있는 필수 항목뿐이다.
        $this->resetAuth();
        $schema = $this->getJson('/api/me/fields?context=required', $auth)->assertOk()->json('data');
        $this->assertSame(['rf_signup_only'], array_column($schema['fields'], 'key'));
        $this->assertSame('custom_fields.rf_signup_only', $schema['fields'][0]['name']);
        $this->assertTrue($schema['fields'][0]['required']);
        $this->assertSame(['rf_signup_only'], $schema['pending_required_fields']);
        $this->assertNotEmpty($schema['fields_version']);

        // 쓰기는 막혀 있다.
        $this->resetAuth();
        $this->postJson('/api/modules/sirsoft-board/boards/free/posts', [], $auth)->assertStatus(403);

        // 비워 보내면 검증 오류, 이미 값이 있는 칸(이름)과 필수가 아닌 항목은 보내도 저장되지 않는다.
        $this->resetAuth();
        $this->putJson('/api/me/required-fields', ['custom_fields' => ['rf_signup_only' => '']], $auth)
            ->assertStatus(422)->assertJsonValidationErrors(['custom_fields.rf_signup_only']);

        $this->resetAuth();
        $saved = $this->putJson('/api/me/required-fields', [
            'name' => '바꾼이름',
            'custom_fields' => ['rf_signup_only' => '채움', 'rf_optional' => '끼워넣음'],
        ], $auth)->assertOk();
        $saved->assertJsonPath('data.pending_required_fields', [])
            ->assertJsonPath('data.user.pending_required_fields', []);

        $fresh = $user->fresh();
        $this->assertSame('건너뛴회원', $fresh->name, '추가 정보 저장이 이미 값이 있는 칸을 바꿨다');
        $this->assertNull($fresh->required_fields_deferred_at, '다 채웠는데 건너뛴 표시가 남았다');
        $values = app(FieldValueService::class)->read($fresh, 'core.user');
        $this->assertSame('채움', $values['rf_signup_only'] ?? null);
        $this->assertTrue(app(FieldValueService::class)->isEmpty($optional, $values['rf_optional'] ?? null), '필수가 아닌 항목이 추가 정보 저장으로 들어갔다');

        // 이제 쓰기가 통과한다.
        $this->resetAuth();
        $this->postJson('/api/modules/sirsoft-board/boards/free/posts', [], $auth)->assertStatus(201);

        // 미입력이 없는 회원의 추가 정보 항목은 비어 있다.
        $this->resetAuth();
        $this->getJson('/api/me/fields?context=required', $auth)->assertOk()->assertJsonPath('data.fields', []);
    }

    /**
     * 그 회원에게 잠긴 칸이 비어 있어도 쓰기를 막지 않는다 (채울 방법이 없는 칸 때문에 갇히지 않는다).
     *
     * @scenario case=locked_not_blocked
     *
     * @effects locked_empty_field_does_not_block_writes
     *
     * @return void 반환값 없음
     */
    public function test_locked_empty_field_does_not_block_writes(): void
    {
        $this->registerBoardRoutes();
        $this->core('mobile', ['required' => true, 'require_existing_members' => true]);
        [, $auth] = $this->member(['mobile' => null]);

        $this->postJson('/api/modules/sirsoft-board/boards/free/posts', [], $auth)
            ->assertStatus(403)->assertJsonPath('data.pending_required_fields', ['mobile']);

        HookManager::addFilter('core.user.locked_fields', fn ($fields) => [...(array) $fields, 'mobile']);

        $this->resetAuth();
        $this->postJson('/api/modules/sirsoft-board/boards/free/posts', [], $auth)->assertStatus(201);
        $this->resetAuth();
        $this->getJson('/api/auth/user', $auth)->assertOk()->assertJsonPath('data.pending_required_fields', []);
    }

    /**
     * 관문은 `api` 그룹에 붙고 우선순위 목록에서 회원 상태 관문 뒤다 — 차단된 회원은 상태 사유로 먼저 끊긴다.
     *
     * @scenario case=middleware_order
     *
     * @effects gate_registered_on_api_group_after_active_user_gate, inactive_member_rejected_by_status_first
     *
     * @return void 반환값 없음
     */
    public function test_gate_runs_after_active_user_gate(): void
    {
        $router = app('router');
        $this->assertContains(EnsureRequiredFieldsCompleted::class, $router->getMiddlewareGroups()['api']);

        $priority = $router->middlewarePriority;
        $activeAt = array_search(EnsureActiveUser::class, $priority, true);
        $requiredAt = array_search(EnsureRequiredFieldsCompleted::class, $priority, true);
        $this->assertNotFalse($activeAt);
        $this->assertNotFalse($requiredAt);
        $this->assertGreaterThan($activeAt, $requiredAt, '추가 정보 요구 관문이 회원 상태 관문보다 앞에 있다');

        $this->registerBoardRoutes();
        $this->custom('rf_demanded', ['required' => true, 'require_existing_members' => true]);
        [, $auth] = $this->member(['status' => 'blocked']);

        $response = $this->postJson('/api/modules/sirsoft-board/me/scraps', [], $auth)->assertStatus(403);
        $this->assertNull($response->json('error_code'), '차단된 회원이 상태 사유가 아니라 추가 정보 요구로 거부됐다');
        $response->assertJsonPath('message', __('auth.account_blocked'));
    }
}
