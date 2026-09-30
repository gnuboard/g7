<?php

namespace Tests\Feature\Analytics\Api;

use App\Enums\Analytics\ActionType;
use App\Enums\ExtensionOwnerType;
use App\Models\Analytics\AnalyticsAction;
use App\Models\Analytics\AnalyticsActionName;
use App\Models\Analytics\AnalyticsStatsDaily;
use App\Models\Analytics\AnalyticsVisit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 방문자 통계 관리자 API 테스트 공통 바탕
 *
 * 권한 셋(read·update·privacy)이 갈리는 표면이라, 테스트마다 계정을 다시 만드는 대신
 * 「이 권한만 가진 관리자」를 한 줄로 얻을 수 있게 둡니다.
 */
abstract class AnalyticsApiTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * 테스트 준비 — 표준시를 UTC 로 고정.
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'UTC', 'g7_settings.core.general.timezone' => 'UTC']);

        // 방문자 통계 설정 전체를 기본값(config/settings/defaults.json)으로 되돌린다 — 운영자가 관리자 화면에서 저장한
        // 값(storage/app/settings/analytics.json)이 부팅 시 config 로 실려 테스트에 스며든다
        // (mem:core-settings-boot-config-leaks-into-unit-tests). 모드를 바꾸는 테스트는 제 본문에서 다시 덮어쓴다.
        $defaults = json_decode((string) file_get_contents(base_path('config/settings/defaults.json')), true);
        config(['g7_settings.core.analytics' => array_replace(
            (array) config('g7_settings.core.analytics', []),
            $defaults['defaults']['analytics'] ?? []
        )]);

        // 화면 열 때 집계는 요청마다 오늘치를 원장에서 다시 계산한다 — 집계 행을 직접 심어 목록·추이를
        // 검산하는 테스트에서는 그 행이 빈 원장으로 덮여 사라진다. 기본은 끄고, 그 동작을 보는 테스트
        // (ArchiveOnViewMiddlewareTest)만 켠다.
        config(['g7_settings.core.analytics.archive_on_view_enabled' => false]);
    }

    /**
     * 지정한 권한만 가진 관리자를 만듭니다.
     *
     * @param  array<int, string>  $permissions  권한 식별자 목록
     * @return User 만들어진 관리자
     */
    protected function adminWith(array $permissions): User
    {
        $user = User::factory()->create();

        $permissionIds = [];

        foreach ($permissions as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ],
            );

            $permissionIds[$permission->id] = ['scope_type' => null];
        }

        $role = Role::create([
            'identifier' => 'analytics_test_'.uniqid(),
            'name' => json_encode(['ko' => '통계 테스트 역할', 'en' => 'Analytics Test Role']),
            'is_active' => true,
        ]);
        $role->permissions()->sync($permissionIds);

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ],
        );

        $user->roles()->attach($adminRole->id, ['assigned_at' => now()]);
        $user->roles()->attach($role->id, ['assigned_at' => now()]);

        return $user->fresh();
    }

    /**
     * 통계를 읽을 수 있는 관리자를 만듭니다.
     *
     * @return User 만들어진 관리자
     */
    protected function readAdmin(): User
    {
        return $this->adminWith(['core.analytics.read']);
    }

    /**
     * 통계 설정을 바꿀 수 있는 관리자를 만듭니다.
     *
     * @return User 만들어진 관리자
     */
    protected function updateAdmin(): User
    {
        return $this->adminWith(['core.analytics.update']);
    }

    /**
     * 개별 방문자를 볼 수 있는 관리자를 만듭니다.
     *
     * @return User 만들어진 관리자
     */
    protected function privacyAdmin(): User
    {
        return $this->adminWith(['core.analytics.read', 'core.analytics.privacy']);
    }

    /**
     * 이름 사전 행을 만들거나 찾습니다.
     *
     * @param  string  $type  이름 종류
     * @param  string  $name  이름
     * @return AnalyticsActionName 사전 행
     */
    protected function actionName(string $type, string $name): AnalyticsActionName
    {
        return AnalyticsActionName::query()->firstOrCreate(
            ['type' => $type, 'name_hash' => md5($type.'|'.$name)],
            ['name' => $name],
        );
    }

    /**
     * 일 집계 행을 하나 심습니다.
     *
     * @param  string  $date  일자 (Y-m-d)
     * @param  array<string, mixed>  $attributes  덮어쓸 값
     * @return AnalyticsStatsDaily 만들어진 행
     */
    protected function daily(string $date, array $attributes = []): AnalyticsStatsDaily
    {
        return AnalyticsStatsDaily::factory()->create(array_merge([
            'date' => $date,
            'visitor_type' => 'guest',
            'visits' => 10,
            'visitors' => 8,
            'pageviews' => 25,
            'bounces' => 3,
            'total_time_seconds' => 1200,
            'is_final' => true,
        ], $attributes));
    }

    /**
     * 방문 하나와 페이지뷰 하나를 심습니다.
     *
     * @param  array<string, mixed>  $attributes  덮어쓸 값
     * @return AnalyticsVisit 만들어진 방문
     */
    protected function visit(array $attributes = []): AnalyticsVisit
    {
        $moment = Carbon::now('UTC');
        $name = $this->actionName('url', 'example.test/');

        $visit = AnalyticsVisit::factory()->create(array_merge([
            'visit_date' => $moment->toDateString(),
            'first_action_at' => $moment,
            'last_action_at' => $moment,
            'entry_action_name_id' => $name->id,
            'exit_action_name_id' => $name->id,
        ], $attributes));

        AnalyticsAction::factory()->create([
            'visit_id' => $visit->id,
            'visitor_key' => $visit->visitor_key,
            'action_type' => ActionType::Pageview,
            'action_name_id' => $name->id,
            'occurred_at' => $moment,
        ]);

        return $visit;
    }
}
