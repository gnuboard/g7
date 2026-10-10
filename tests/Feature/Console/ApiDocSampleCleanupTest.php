<?php

namespace Tests\Feature\Console;

use App\Models\ActivityLog;
use App\Models\LanguagePack;
use App\Models\Menu;
use App\Models\NotificationDefinition;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\User;
use App\Support\ApiDoc\ApiDocSampleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * API 문서 실측 샘플 정리 테스트
 *
 * 2026-10-08 실측(O-E-4): `api:docgen --seed` 가 상세 GET 실측용으로 만든 샘플(메뉴 「API 문서 샘플 메뉴」·하위 메뉴,
 * 역할 `apidoc-sample-role`, 사용자 `apidoc-sample-user@example.com`, 알림 정의·템플릿, 스케줄, 활동 로그, 언어팩, 알림 로그)이
 * 개발 DB 에 남아 관리자 메뉴·역할·사용자 목록에 실제 데이터처럼 보였다. 실측이 끝나면 샘플과 시드 중 만들어진 행을
 * 모두 걷고, 앞선 실행이 남긴 샘플도 함께 걷으며, 샘플이 아닌 행은 건드리지 않는지 고정한다.
 *
 * @scenario-manifest tests/scenarios/apidoc-sample-cleanup.yaml
 */
class ApiDocSampleCleanupTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 시드·정리 전후로 비교할 테이블(접두 제외).
     *
     * @var array<int, string>
     */
    private const TABLES = [
        'users', 'roles', 'permissions', 'role_permissions', 'user_roles', 'menus',
        'notification_definitions', 'notification_templates', 'schedules', 'activity_logs',
        'language_packs', 'notification_logs',
    ];

    /**
     * api:docgen --seed 실행이 끝나면 코어 샘플도, 시드 중 만들어진 다른 행도 남지 않는다(확장 시더 포함).
     *
     * @scenario state=clean_db
     *
     * @effects apidoc_samples_removed_after_docgen_run
     *
     * @return void 반환값 없음
     */
    public function test_docgen_seed_run_leaves_no_samples(): void
    {
        // 읽기 실측은 외부 HTTP 로 나간다 — 개발 사이트로 요청하지 않게 막는다
        Http::fake();
        $before = $this->tableCounts();

        $this->artisan('api:docgen', ['--scope' => 'plugin:sirsoft-ckeditor5', '--check' => true, '--seed' => true])
            ->assertSuccessful();

        $this->assertSame([], array_filter($this->sampleCounts()), '샘플 행이 남았다');
        $this->assertSame($before, $this->tableCounts(), '시드 중 만들어진 행이 남았다');
    }

    /**
     * 앞선 실행(정리 도입 전)이 남긴 샘플도 다음 실행이 끝나면 걷힌다.
     *
     * @scenario state=leftover_samples
     *
     * @effects apidoc_samples_removed_after_docgen_run
     *
     * @return void 반환값 없음
     */
    public function test_docgen_seed_run_removes_samples_left_by_earlier_runs(): void
    {
        Http::fake();
        $before = $this->tableCounts();

        // 정리 없이 시드만 한 종전 실행이 남긴 상태
        (new ApiDocSampleService)->seed();
        $this->assertNotEmpty(array_filter($this->sampleCounts()), '전제: 샘플이 시드되지 않았다');

        $this->artisan('api:docgen', ['--scope' => 'plugin:sirsoft-ckeditor5', '--check' => true, '--seed' => true])
            ->assertSuccessful();

        $this->assertSame([], array_filter($this->sampleCounts()), '앞선 실행이 남긴 샘플이 그대로다');
        $this->assertSame($before, $this->tableCounts(), '앞선 실행이 남긴 행이 그대로다');
    }

    /**
     * 정리는 샘플만 지운다 — 샘플이 아닌 사용자·역할·메뉴·권한과 그 연결은 그대로 둔다.
     *
     * @scenario state=real_rows_present
     *
     * @effects apidoc_sample_cleanup_keeps_real_rows
     *
     * @return void 반환값 없음
     */
    public function test_cleanup_keeps_rows_that_are_not_samples(): void
    {
        $permission = Permission::factory()->create(['identifier' => 'qa.real.permission']);
        $role = Role::factory()->create(['identifier' => 'qa_real_role']);
        $role->permissions()->attach($permission->id);
        $user = User::factory()->create(['email' => 'qa-real-user@example.com']);
        $user->roles()->attach($role->id);
        $menu = Menu::factory()->create(['slug' => 'qa-real-menu']);

        $service = new ApiDocSampleService;
        $service->seed();
        $removed = $service->cleanup();

        $this->assertGreaterThan(0, $removed);
        $this->assertSame([], array_filter($this->sampleCounts()));
        $this->assertNotNull(Permission::find($permission->id));
        $this->assertNotNull(Role::find($role->id));
        $this->assertNotNull(User::find($user->id));
        $this->assertNotNull(Menu::find($menu->id));
        $this->assertSame([$permission->id], $role->permissions()->pluck('permissions.id')->all(), '실제 역할의 권한 연결이 바뀌었다');
        $this->assertSame([$role->id], $user->roles()->pluck('roles.id')->all(), '실제 사용자의 역할 연결이 바뀌었다');
    }

    /**
     * 확장 시더처럼 정리 대상 밖 코드가 시드 중 만든 행도 기록했다가 걷는다.
     *
     * @scenario state=extension_seeder
     *
     * @effects apidoc_samples_removed_after_docgen_run
     *
     * @return void 반환값 없음
     */
    public function test_cleanup_removes_rows_created_while_tracking(): void
    {
        $before = $this->tableCounts();
        $service = new ApiDocSampleService;
        // 메뉴 팩토리는 연관 행(작성자 등)도 함께 만든다 — 그 행까지 기록돼야 한다
        $menu = $service->track(fn () => Menu::factory()->create(['slug' => 'qa-extension-sample']));

        $this->assertGreaterThanOrEqual(1, $service->cleanup());
        $this->assertNull(Menu::find($menu->id));
        $this->assertSame($before, $this->tableCounts(), '기록 중 함께 만들어진 행이 남았다');
    }

    /**
     * 샘플 식별자별 남은 행 수.
     *
     * @return array<string, int> 식별자 => 행 수
     */
    private function sampleCounts(): array
    {
        $definitionIds = NotificationDefinition::query()->where('type', 'apidoc-sample.event')->pluck('id');

        return [
            'menus' => Menu::query()->where('slug', 'like', 'apidoc-sample-menu%')->count(),
            'role' => Role::query()->where('identifier', 'like', 'apidoc-sample-role%')->count(),
            'user' => User::query()->where('email', 'apidoc-sample-user@example.com')->count(),
            'permissions' => Permission::query()->where('identifier', 'like', 'apidoc-sample.%')->count(),
            'notification_definition' => $definitionIds->count(),
            'notification_templates' => NotificationTemplate::query()->whereIn('definition_id', $definitionIds)->count(),
            'schedule' => Schedule::query()->where('name', 'API 문서 샘플 스케줄')->count(),
            'activity_log' => ActivityLog::query()->where('description_key', 'apidoc.sample.action')->count(),
            'language_pack' => LanguagePack::query()->where('identifier', 'apidoc-sample-lang')->count(),
            'notification_log' => NotificationLog::query()->where('notification_type', 'apidoc.sample.event')->count(),
        ];
    }

    /**
     * 비교 대상 테이블의 행 수.
     *
     * @return array<string, int> 테이블 => 행 수
     */
    private function tableCounts(): array
    {
        $counts = [];
        foreach (self::TABLES as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}
