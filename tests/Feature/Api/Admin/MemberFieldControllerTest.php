<?php

namespace Tests\Feature\Api\Admin;

use App\Contracts\Extension\CacheInterface;
use App\Jobs\RecomputeNicknameUniqueKeysJob;
use App\Models\Role;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Models\User;
use App\Support\NicknameNormalizer;
use Database\Seeders\CoreFieldDefinitionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 관리자 — 환경설정 회원 탭의 안내 값 API.
 *
 * 닉네임 중복 금지를 켜기 전의 겹침 수, 켠 뒤 도는 중복 검사 준비의 진행 상태, 가입 화면이 운영자 수정본일 때의 경고.
 */
class MemberFieldControllerTest extends TestCase
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

        $admin = User::factory()->create(['is_super' => true]);
        $adminRole = Role::where('identifier', 'admin')->first();
        if ($adminRole) {
            $admin->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        }
        $this->adminToken = $admin->fresh()->createToken('test')->plainTextToken;
    }

    /**
     * 인증 헤더를 만듭니다.
     *
     * @param  string|null  $token  토큰 (null 이면 최고관리자)
     * @return array<string, string> 헤더
     */
    private function auth(?string $token = null): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.($token ?? $this->adminToken)];
    }

    /**
     * 닉네임과 비교용 정규화 값을 가진 회원을 만듭니다.
     *
     * @param  string|null  $nickname  닉네임
     * @return User 만든 회원
     */
    private function member(?string $nickname): User
    {
        return User::factory()->create([
            'nickname' => $nickname,
            'nickname_normalized' => $nickname === null ? null : NicknameNormalizer::normalize($nickname),
        ]);
    }

    /**
     * 겹치는 닉네임이 없으면 0, 0 이다.
     *
     * @scenario info=nickname_duplicates
     *
     * @effects duplicates_zero_when_all_distinct
     *
     * @return void 반환값 없음
     */
    public function test_nickname_duplicates_is_zero_when_all_distinct(): void
    {
        $this->member('길동이');
        $this->member('철수');
        $this->member(null);

        $this->getJson('/api/admin/member-fields/nickname-duplicates', $this->auth())
            ->assertOk()
            ->assertJsonPath('data.duplicate_nicknames', 0)
            ->assertJsonPath('data.duplicate_members', 0);
    }

    /**
     * 공백과 대소문자만 다른 닉네임은 같은 것으로 센다 — 종 수와 그 닉네임을 쓰는 회원 수를 준다.
     *
     * @scenario info=nickname_duplicates
     *
     * @effects duplicates_counts_kinds_and_members_by_normalized_value
     *
     * @return void 반환값 없음
     */
    public function test_nickname_duplicates_counts_kinds_and_members(): void
    {
        $this->member('Gildong');
        $this->member('gil dong');
        $this->member('GILDONG');
        $this->member('철수');
        $this->member('철 수');
        $this->member('혼자');

        $this->getJson('/api/admin/member-fields/nickname-duplicates', $this->auth())
            ->assertOk()
            ->assertJsonPath('data.duplicate_nicknames', 2)
            ->assertJsonPath('data.duplicate_members', 5);
    }

    /**
     * 재계산 작업이 돈 적이 없으면 idle, 진행 중이면 캐시에 쓴 처리 수와 전체 수를 준다.
     *
     * @scenario info=nickname_recompute_status
     *
     * @effects recompute_status_reads_job_progress
     *
     * @return void 반환값 없음
     */
    public function test_nickname_recompute_status_reads_job_progress(): void
    {
        $this->getJson('/api/admin/member-fields/nickname-recompute-status', $this->auth())
            ->assertOk()
            ->assertJsonPath('data.status', 'idle')
            ->assertJsonPath('data.total', 0);

        app(CacheInterface::class)->put(RecomputeNicknameUniqueKeysJob::PROGRESS_CACHE_KEY, [
            'status' => 'running', 'processed' => 500, 'total' => 1200, 'unique' => true,
            'started_at' => now()->toIso8601String(), 'finished_at' => null,
        ], 60);

        $this->getJson('/api/admin/member-fields/nickname-recompute-status', $this->auth())
            ->assertOk()
            ->assertJsonPath('data.status', 'running')
            ->assertJsonPath('data.processed', 500)
            ->assertJsonPath('data.total', 1200);
    }

    /**
     * 환경설정 조회 권한이 없으면 두 API 모두 403, 비로그인은 401 이다.
     *
     * @scenario info=permission
     *
     * @effects info_requires_settings_read_permission
     *
     * @return void 반환값 없음
     */
    public function test_info_requires_settings_read_permission(): void
    {
        $this->getJson('/api/admin/member-fields/nickname-duplicates')->assertUnauthorized();

        $member = User::factory()->create();
        $token = $member->createToken('test')->plainTextToken;

        $this->getJson('/api/admin/member-fields/nickname-duplicates', $this->auth($token))->assertForbidden();
        $this->getJson('/api/admin/member-fields/nickname-recompute-status', $this->auth($token))->assertForbidden();
    }

    /**
     * 가입 화면이 원본 그대로면 경고가 없고, 운영자 수정본인데 항목 반복 구획이 없으면 경고한다. 수정본이라도 그 구획이 있으면 경고하지 않는다.
     *
     * @scenario info=signup_layout_notice
     *
     * @effects notice_only_when_modified_layout_lacks_member_fields
     *
     * @return void 반환값 없음
     */
    public function test_signup_layout_notice(): void
    {
        $template = Template::factory()->create(['identifier' => 'sirsoft-basic', 'type' => 'user', 'status' => 'active']);
        $original = ['layout_name' => 'auth/register', 'components' => [['id' => 'member_fields_root', 'type' => 'basic', 'name' => 'Div']]];
        $layout = TemplateLayout::factory()->create([
            'template_id' => $template->id, 'name' => 'auth/register',
            'content' => $original, 'original_content' => json_encode($original),
        ]);

        $notice = fn () => $this->getJson('/api/admin/field-sets/core.user/fields', $this->auth())
            ->assertOk()->json('data.notices.signup_layout_modified');

        $this->assertFalse($notice());

        // 운영자가 옛 판을 고쳐 유지 중 — 항목 반복 구획이 없다
        $layout->update(['content' => ['layout_name' => 'auth/register', 'components' => [['id' => 'old_name_input', 'type' => 'basic', 'name' => 'Input']]]]);
        $this->assertTrue($notice());

        // 수정본이지만 병합으로 항목 반복 구획이 들어갔다
        $layout->update(['content' => ['layout_name' => 'auth/register', 'components' => [['id' => 'member_fields_root', 'type' => 'basic', 'name' => 'Div'], ['id' => 'operator_banner', 'type' => 'basic', 'name' => 'Div']]]]);
        $this->assertFalse($notice());
    }
}
