<?php

namespace Tests\Feature\Privacy;

use App\Enums\ExtensionOwnerType;
use App\Enums\UserStatus;
use App\Extension\HookManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Privacy\Contracts\PrivacyRetentionHandlerInterface;
use App\Privacy\DormantAccountService;
use App\Privacy\DTO\PrivacyRetentionTarget;
use App\Privacy\Handlers\WithdrawnUserRetentionHandler;
use App\Privacy\PrivacyRetentionManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * 개인정보 조치 대상 목록 API 회귀 테스트 (이슈 #636 — 조치 대상 미리보기 설계 결함)
 *
 * 관리자 화면의 「대상 N건」 배지와 「더보기」 모달이 배치와 **같은 명단**을 보여 주는지,
 * 꺼져 있어도 보이는지, 명단을 볼 권한(설정 읽기 + 도메인 읽기)을 요청 시점에 검사하는지,
 * 확장 핸들러가 등록만으로 코어 API 에 붙는지를 고정합니다.
 *
 * @scenario target=privacy_withdrawn_users, row_age=beyond_retention
 *
 * @effects disabled_handler_is_reported_separately_from_zero_targets, target_list_matches_the_preview_population, target_list_requires_domain_read_permission, extension_handler_targets_are_served_by_the_core_api, target_list_pages_until_has_more_pages_is_false
 */
class PrivacyTargetApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 개인정보 설정을 기본값(전부 꺼짐)으로 고정합니다.
     *
     * 개발 환경에서 관리자가 저장한 `privacy.json` 이 부팅 시 config 로 올라와 테스트에 새므로
     * 테스트가 읽는 값을 여기서 못박는다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('g7_settings.core.privacy.withdrawn_retention_enabled', false);
        Config::set('g7_settings.core.privacy.withdrawn_retention_days', 365);
        Config::set('g7_settings.core.privacy.dormant_enabled', false);
        Config::set('g7_settings.core.privacy.dormant_days', 730);
        Config::set('g7_settings.core.privacy.dormant_notice_days', 30);
        Config::set('g7_settings.core.privacy.dormant_withdraw_enabled', false);
        Config::set('g7_settings.core.privacy.dormant_withdraw_days', 365);
    }

    /**
     * 꺼진 상태에서도 탈퇴 잔여정보 대상 목록이 건수·배치 모집단과 같은 명단이다.
     *
     * @return void 반환값 없음
     *
     * @scenario toggle=off, row_age=beyond_retention, run_mode=dry_run
     *
     * @effects target_list_matches_the_preview_population, disabled_handler_is_reported_separately_from_zero_targets
     */
    public function test_withdrawn_target_list_matches_the_preview_population_even_when_disabled(): void
    {
        $expected = collect([
            $this->createWithdrawnUser(400),
            $this->createWithdrawnUser(500),
        ])->pluck('id')->sort()->values()->all();
        $this->createWithdrawnUser(10); // 보유기간 이내 — 대상 아님

        $admin = $this->createAdmin(['core.settings.read', 'core.users.read']);

        $preview = $this->actingAs($admin)->getJson('/api/admin/settings/privacy-preview')->assertOk();
        $row = $preview->json('data.retention.user-withdrawn');
        $this->assertFalse($row['enabled'], '기본값은 꺼짐이다');
        $this->assertSame(2, $row['count'], '꺼져 있어도 건수를 센다');
        $this->assertTrue($row['can_view_targets']);

        $list = $this->actingAs($admin)->getJson('/api/admin/privacy/retention-targets/user-withdrawn?per_page=50')->assertOk();
        $ids = collect($list->json('data.data'))->pluck('id')->sort()->values()->all();

        $this->assertSame($expected, $ids, '모달 명단 = 건수 모집단');
        $this->assertSame(app(WithdrawnUserRetentionHandler::class)->targetQuery()->pluck('id')->sort()->values()->all(), $ids, '모달 명단 = 배치 쿼리');
        $this->assertSame(2, $list->json('data.pagination.total'));
        $this->assertArrayHasKey('title', $list->json('data.columns'));
        $this->assertStringStartsWith('/admin/users/', $list->json('data.data.0.url'));
    }

    /**
     * 목록은 페이지 단위로 이어지고 마지막 페이지에서 has_more_pages 가 false 다.
     *
     * @return void 반환값 없음
     *
     * @scenario toggle=off, row_age=beyond_retention, run_mode=dry_run
     *
     * @effects target_list_pages_until_has_more_pages_is_false
     */
    public function test_target_list_pages_until_has_more_pages_is_false(): void
    {
        foreach ([400, 450, 500] as $days) {
            $this->createWithdrawnUser($days);
        }

        $admin = $this->createAdmin(['core.settings.read', 'core.users.read']);

        $first = $this->actingAs($admin)->getJson('/api/admin/privacy/retention-targets/user-withdrawn?per_page=2&page=1')->assertOk();
        $this->assertCount(2, $first->json('data.data'));
        $this->assertTrue($first->json('data.pagination.has_more_pages'));

        $second = $this->actingAs($admin)->getJson('/api/admin/privacy/retention-targets/user-withdrawn?per_page=2&page=2')->assertOk();
        $this->assertCount(1, $second->json('data.data'));
        $this->assertFalse($second->json('data.pagination.has_more_pages'));

        $all = array_merge(
            collect($first->json('data.data'))->pluck('id')->all(),
            collect($second->json('data.data'))->pluck('id')->all(),
        );
        $this->assertCount(3, array_unique($all), '두 페이지가 겹치거나 빠지지 않는다');

        // 가장 오래된 탈퇴부터 — 가장 먼저 대상이 된 회원이 위에 온다(Y-m-d 문자열 비교).
        $dates = array_merge(
            collect($first->json('data.data'))->pluck('date')->all(),
            collect($second->json('data.data'))->pluck('date')->all(),
        );
        $sorted = $dates;
        sort($sorted);
        $this->assertSame($sorted, $dates, '기준일 오름차순');

        $this->actingAs($admin)->getJson('/api/admin/privacy/retention-targets/user-withdrawn?per_page=101')->assertStatus(422);
    }

    /**
     * 설정 읽기만 있고 회원 읽기가 없으면 명단을 볼 수 없다(건수는 보인다).
     *
     * @return void 반환값 없음
     *
     * @scenario toggle=off, row_age=beyond_retention, run_mode=dry_run
     *
     * @effects target_list_requires_domain_read_permission
     */
    public function test_target_list_requires_domain_read_permission(): void
    {
        $this->createWithdrawnUser(400);
        $settingsOnly = $this->createAdmin(['core.settings.read']);

        $preview = $this->actingAs($settingsOnly)->getJson('/api/admin/settings/privacy-preview')->assertOk();
        $this->assertSame(1, $preview->json('data.retention.user-withdrawn.count'), '건수는 설정 읽기로 보인다');
        $this->assertFalse($preview->json('data.retention.user-withdrawn.can_view_targets'));
        $this->assertFalse($preview->json('data.dormant.can_view_targets'));

        $this->actingAs($settingsOnly)->getJson('/api/admin/privacy/retention-targets/user-withdrawn')->assertForbidden();
        $this->actingAs($settingsOnly)->getJson('/api/admin/privacy/dormant-targets/convert')->assertForbidden();
    }

    /**
     * 등록되지 않은 핸들러·단계는 404 다.
     *
     * @return void 반환값 없음
     *
     * @scenario toggle=off, row_age=beyond_retention, run_mode=dry_run
     *
     * @effects target_list_requires_domain_read_permission
     */
    public function test_unknown_handler_and_stage_are_not_found(): void
    {
        $admin = $this->createAdmin(['core.settings.read', 'core.users.read']);

        $this->actingAs($admin)->getJson('/api/admin/privacy/retention-targets/no-such-handler')->assertNotFound();
        $this->actingAs($admin)->getJson('/api/admin/privacy/dormant-targets/no-such-stage')->assertNotFound();
    }

    /**
     * 확장이 필터 훅으로 등록한 핸들러는 코어 코드 수정 없이 코어 목록 API 에 붙고,
     * 그 핸들러가 선언한 권한으로 검사된다.
     *
     * @return void 반환값 없음
     *
     * @scenario toggle=off, row_age=beyond_retention, run_mode=dry_run
     *
     * @effects extension_handler_targets_are_served_by_the_core_api, target_list_requires_domain_read_permission
     */
    public function test_extension_handler_targets_are_served_by_the_core_api(): void
    {
        HookManager::addFilter(PrivacyRetentionManager::HANDLERS_FILTER, function (array $handlers) {
            $handlers[] = ListingFakeRetentionHandler::class;

            return $handlers;
        });

        $withDomain = $this->createAdmin(['fake-ext.settings.read', 'fake-ext.orders.read']);
        $settingsOnly = $this->createAdmin(['fake-ext.settings.read']);

        $list = $this->actingAs($withDomain)->getJson('/api/admin/privacy/retention-targets/fake-ext.order?per_page=2')->assertOk();
        $this->assertSame(['A-1', 'A-2'], collect($list->json('data.data'))->pluck('title')->all());
        $this->assertSame(3, $list->json('data.pagination.total'));
        $this->assertSame(['title' => '주문번호', 'date' => '종결일'], $list->json('data.columns'));

        $this->actingAs($settingsOnly)->getJson('/api/admin/privacy/retention-targets/fake-ext.order')->assertForbidden();

        // 코어 개인정보 탭의 건수 API 는 코어 설정 읽기로 본다 — 확장 핸들러 행도 함께 실린다.
        $coreViewer = $this->createAdmin(['core.settings.read', 'fake-ext.settings.read', 'fake-ext.orders.read']);
        $preview = $this->actingAs($coreViewer)->getJson('/api/admin/settings/privacy-preview')->assertOk();
        $this->assertSame(
            ['enabled' => false, 'count' => 3, 'can_view_targets' => true],
            $preview->json('data.retention')['fake-ext.order'],
        );
    }

    /**
     * 휴면 네 단계는 꺼져 있어도 건수를 세고, 단계 목록이 같은 쿼리의 명단이다.
     *
     * @return void 반환값 없음
     *
     * @scenario toggle=off, row_age=beyond_retention, run_mode=dry_run
     *
     * @effects target_list_matches_the_preview_population, disabled_handler_is_reported_separately_from_zero_targets
     */
    public function test_dormant_stage_counts_and_lists_are_available_while_disabled(): void
    {
        $dormant = User::factory()->create([
            'status' => UserStatus::Dormant->value,
            'dormant_at' => now()->subDays(1000),
            'dormant_notified_at' => now()->subDays(60),
        ]);

        $admin = $this->createAdmin(['core.settings.read', 'core.users.read']);

        $preview = $this->actingAs($admin)->getJson('/api/admin/settings/privacy-preview')->assertOk();
        $this->assertFalse($preview->json('data.dormant.enabled'));
        $this->assertFalse($preview->json('data.dormant.withdraw_enabled'));
        $this->assertTrue($preview->json('data.dormant.can_view_targets'));

        $service = app(DormantAccountService::class);

        foreach (DormantAccountService::STAGES as $stage) {
            $this->assertSame($service->stageQuery($stage)->count(), $preview->json("data.dormant.{$stage}"), "{$stage} 건수 = 쿼리");

            $list = $this->actingAs($admin)->getJson("/api/admin/privacy/dormant-targets/{$stage}?per_page=100")->assertOk();
            $this->assertSame(
                $service->stageQuery($stage)->pluck('id')->sort()->values()->all(),
                collect($list->json('data.data'))->pluck('id')->sort()->values()->all(),
                "{$stage} 명단 = 쿼리",
            );
        }

        $this->assertGreaterThan(0, $preview->json('data.dormant.withdraw'), '자동 탈퇴가 꺼져 있어도 대상 규모를 보여 준다');
        $withdrawList = $this->actingAs($admin)->getJson('/api/admin/privacy/dormant-targets/withdraw')->assertOk();
        $this->assertContains($dormant->id, collect($withdrawList->json('data.data'))->pluck('id')->all());
    }

    /**
     * 도메인 읽기의 적용 범위가 본인(self)으로 좁은 관리자는 명단을 볼 수 없다(건수는 보인다).
     *
     * 회원 목록은 범위를 걸어 본인만 보여 주는데 명단은 전체를 모은 것이라, 열어 주면 설정 화면을
     * 거쳐 목록 화면보다 넓은 명단을 보게 된다(2026-09-23 확정 「전체 범위만 명단 허용」).
     *
     * @return void 반환값 없음
     *
     * @scenario toggle=off, row_age=beyond_retention, run_mode=dry_run
     *
     * @effects target_list_requires_domain_read_permission
     */
    public function test_scoped_domain_read_cannot_view_target_lists(): void
    {
        $this->createWithdrawnUser(400);
        User::factory()->create([
            'status' => UserStatus::Dormant->value,
            'dormant_at' => now()->subDays(1000),
            'dormant_notified_at' => now()->subDays(60),
        ]);

        $scoped = $this->createAdmin(['core.settings.read', 'core.users.read'], ['core.users.read' => 'self']);

        $preview = $this->actingAs($scoped)->getJson('/api/admin/settings/privacy-preview')->assertOk();
        $this->assertSame(1, $preview->json('data.retention.user-withdrawn.count'), '건수는 범위와 무관하게 보인다');
        $this->assertFalse($preview->json('data.retention.user-withdrawn.can_view_targets'));
        $this->assertFalse($preview->json('data.dormant.can_view_targets'));

        $this->actingAs($scoped)->getJson('/api/admin/privacy/retention-targets/user-withdrawn')->assertForbidden();
        $this->actingAs($scoped)->getJson('/api/admin/privacy/dormant-targets/withdraw')->assertForbidden();

        // 같은 권한을 전체 범위로 가진 관리자는 그대로 본다.
        $full = $this->createAdmin(['core.settings.read', 'core.users.read']);
        $this->actingAs($full)->getJson('/api/admin/privacy/retention-targets/user-withdrawn')->assertOk();
        $this->actingAs($full)->getJson('/api/admin/privacy/dormant-targets/withdraw')->assertOk();
    }

    /**
     * 휴면 목록의 기준일은 판정과 같은 「마지막 활동」 — 토큰 마지막 사용이 로그인보다 늦으면 그 날이다.
     *
     * 대상 판정은 max(로그인, 토큰 마지막 사용)으로 하는데(§1-D 14) 목록이 로그인만 보이면 토큰으로
     * 쓰던 회원이 실제보다 오래 쉰 것처럼 보이고 정렬도 판정 순서와 어긋난다.
     *
     * @return void 반환값 없음
     *
     * @scenario toggle=off, row_age=beyond_retention, run_mode=dry_run
     *
     * @effects target_list_matches_the_preview_population
     */
    public function test_dormant_list_date_is_the_last_activity_including_tokens(): void
    {
        $tokenUser = User::factory()->create([
            'status' => UserStatus::Active->value,
            'last_login_at' => now()->subDays(1000),
            'dormant_notified_at' => now()->subDays(60),
        ]);
        $tokenUser->createToken('probe');
        $tokenUser->tokens()->update(['last_used_at' => now()->subDays(800)]);

        $loginUser = User::factory()->create([
            'status' => UserStatus::Active->value,
            'last_login_at' => now()->subDays(900),
            'dormant_notified_at' => now()->subDays(60),
        ]);

        $admin = $this->createAdmin(['core.settings.read', 'core.users.read']);
        $rows = $this->actingAs($admin)->getJson('/api/admin/privacy/dormant-targets/convert?per_page=100')->assertOk()->json('data.data');

        $this->assertSame([$loginUser->id, $tokenUser->id], array_column($rows, 'id'), '마지막 활동이 더 오래된 회원이 먼저 — 토큰 사용까지 본 순서');
        $this->assertGreaterThan($rows[0]['date'], $rows[1]['date'], '토큰으로 800일 전까지 쓴 회원의 기준일은 로그인 1000일 전이 아니라 800일 전');
    }

    /**
     * 보유기간이 지난 탈퇴 회원을 만듭니다.
     *
     * @param  int  $withdrawnDaysAgo  며칠 전에 탈퇴했는지
     * @return User 생성된 회원
     */
    private function createWithdrawnUser(int $withdrawnDaysAgo): User
    {
        return User::factory()->create([
            'status' => UserStatus::Withdrawn->value,
            'withdrawn_at' => now()->subDays($withdrawnDaysAgo),
        ]);
    }

    /**
     * 지정한 관리자 권한만 가진 관리자를 만듭니다.
     *
     * @param  array<int, string>  $permissions  권한 식별자 목록
     * @param  array<string, string>  $scopes  권한 식별자 => 적용 범위(`self`·`role`) — 없으면 전체
     * @return User 관리자
     */
    private function createAdmin(array $permissions, array $scopes = []): User
    {
        $user = User::factory()->create();

        $permissionIds = collect($permissions)->map(fn (string $identifier) => Permission::firstOrCreate(
            ['identifier' => $identifier],
            [
                'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'type' => 'admin',
            ],
        )->id)->all();

        $role = Role::create([
            'identifier' => 'privacy_target_test_'.uniqid(),
            'name' => json_encode(['ko' => '시험 관리자', 'en' => 'Test admin']),
            'description' => json_encode(['ko' => '시험 관리자', 'en' => 'Test admin']),
            'is_active' => true,
        ]);
        $role->permissions()->sync($permissionIds);

        foreach ($scopes as $identifier => $scope) {
            $role->permissions()->updateExistingPivot(Permission::where('identifier', $identifier)->value('id'), ['scope_type' => $scope]);
        }

        $adminBase = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'description' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ],
        );

        $user->roles()->attach($adminBase->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }
}

/**
 * 시험용 확장 핸들러 — 대상 3건을 목록으로 내놓고 확장 권한을 선언한다.
 */
class ListingFakeRetentionHandler implements PrivacyRetentionHandlerInterface
{
    /**
     * {@inheritDoc}
     */
    public function id(): string
    {
        return 'fake-ext.order';
    }

    /**
     * {@inheritDoc}
     */
    public function label(): string
    {
        return '시험 주문';
    }

    /**
     * {@inheritDoc}
     */
    public function isEnabled(): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function preview(): int
    {
        return 3;
    }

    /**
     * {@inheritDoc}
     */
    public function targets(int $page, int $perPage): LengthAwarePaginator
    {
        $rows = collect(['A-1', 'A-2', 'A-3'])->map(fn (string $number, int $i) => new PrivacyRetentionTarget(id: $i + 1, title: $number));

        return new Paginator($rows->forPage($page, $perPage)->values(), 3, $perPage, $page);
    }

    /**
     * {@inheritDoc}
     */
    public function targetColumns(): array
    {
        return ['title' => '주문번호', 'date' => '종결일'];
    }

    /**
     * {@inheritDoc}
     */
    public function targetPermissions(): array
    {
        return ['fake-ext.settings.read', 'fake-ext.orders.read'];
    }

    /**
     * {@inheritDoc}
     */
    public function runPermissions(): array
    {
        return ['core.settings.update', 'core.users.update'];
    }

    /**
     * {@inheritDoc}
     */
    public function process(bool $dryRun, bool $force = false): array
    {
        return ['count' => 0, 'mode' => 'disabled'];
    }
}
