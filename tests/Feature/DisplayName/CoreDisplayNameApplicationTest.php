<?php

namespace Tests\Feature\DisplayName;

use App\Contracts\Repositories\FieldDefinitionRepositoryInterface;
use App\DisplayName\MemberNamePresenter;
use App\Enums\ActivityLogType;
use App\Enums\ExtensionOwnerType;
use App\Enums\PrivacyManualRunStatus;
use App\Fields\FieldContext;
use App\Fields\FieldSet;
use App\Fields\FieldValueService;
use App\Helpers\PermissionHelper;
use App\Http\Controllers\Api\Admin\ActivityLogController;
use App\Http\Controllers\Api\Public\PublicProfileController;
use App\Http\Resources\AttachmentResource;
use App\Http\Resources\LayoutVersionResource;
use App\Http\Resources\MenuResource;
use App\Http\Resources\PrivacyManualRunResource;
use App\Models\ActivityLog;
use App\Models\Attachment;
use App\Models\FieldDefinition;
use App\Models\Menu;
use App\Models\NotificationDefinition;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\Permission;
use App\Models\PrivacyManualRun;
use App\Models\Role;
use App\Models\TemplateLayoutVersion;
use App\Models\User;
use App\Services\UserService;
use Database\Seeders\CoreFieldDefinitionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 코어 화면의 회원 이름 출력 지점에 표시 이름이 적용되는지 잰다.
 *
 * 관리자 화면(활동 로그, 알림 발송 이력, 알림 템플릿의 지정 수신자)의 이름은 관리자 영역 표시 이름이고,
 * 실명은 회원 조회 권한이 있는 관리자에게만 `real_name` 으로 더해진다. 공개 프로필은 사이트 기본 영역 표시 이름이다.
 * 설정을 바꾸지 않은 사이트에서는 값이 종전과 같다.
 */
class CoreDisplayNameApplicationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $member;

    /**
     * 역할과 코어 회원 항목을 시딩하고 최고관리자와 일반 회원을 준비한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->clearPermissionCache();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(CoreFieldDefinitionSeeder::class);

        $this->admin = User::factory()->create(['is_super' => true, 'name' => '관리자', 'nickname' => '운영팀', 'email' => 'boss@example.com']);
        $adminRole = Role::where('identifier', 'admin')->first();
        if ($adminRole) {
            $this->admin->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        }
        $this->admin = $this->admin->fresh();

        $this->member = User::factory()->create(['name' => '홍길동', 'nickname' => '길동이', 'email' => 'gildong@example.com']);
    }

    /**
     * 권한 캐시를 비운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearPermissionCache();
        parent::tearDown();
    }

    /**
     * PermissionHelper 의 정적 캐시를 비웁니다.
     *
     * @return void 반환값 없음
     */
    private function clearPermissionCache(): void
    {
        $prop = (new \ReflectionClass(PermissionHelper::class))->getProperty('permissionCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    /**
     * 표시 이름 영역 설정을 바꿉니다.
     *
     * @param  array<string, array<string, string>>  $areas  영역 키 => 설정
     * @return void 반환값 없음
     */
    private function areas(array $areas): void
    {
        config(['g7_settings.core.member.display_name_areas' => $areas]);
    }

    /**
     * 인증 헤더를 만듭니다 (한 테스트 안에서 토큰을 바꿔 쓰므로 가드를 비운다).
     *
     * @param  User|null  $user  요청할 사람 (null 이면 최고관리자)
     * @return array<string, string> 헤더
     */
    private function as(?User $user = null): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.($user ?? $this->admin)->createToken('test')->plainTextToken, 'Accept' => 'application/json'];
    }

    /**
     * 지정한 관리자 권한만 가진 관리자를 만듭니다.
     *
     * @param  array<string, string|null>  $permissions  권한 식별자 => 범위(null 전체, self, role)
     * @param  array<string, mixed>  $attributes  회원 속성
     * @return User 만든 관리자
     */
    private function limitedAdmin(array $permissions, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['name' => '부관리자', 'nickname' => '부운영'], $attributes));

        $sync = [];
        foreach ($permissions as $identifier => $scope) {
            $permission = Permission::firstOrCreate(['identifier' => $identifier], [
                'name' => ['ko' => $identifier, 'en' => $identifier],
                'extension_type' => ExtensionOwnerType::Core, 'extension_identifier' => 'core', 'type' => 'admin',
            ]);
            $sync[$permission->id] = ['scope_type' => $scope];
        }

        $role = Role::create(['identifier' => 'limited_'.uniqid(), 'name' => ['ko' => '제한 관리자', 'en' => 'Limited'], 'is_active' => true]);
        $role->permissions()->sync($sync);
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }

    /**
     * 활동 로그 한 건을 만듭니다.
     *
     * @param  User  $actor  행위자
     * @return ActivityLog 만든 로그
     */
    private function activityLog(User $actor): ActivityLog
    {
        return ActivityLog::create([
            'log_type' => ActivityLogType::Admin, 'action' => 'user.update',
            'description_key' => 'activity_log.description.user_update', 'description_params' => ['user_id' => 'abc-123'],
            'loggable_type' => User::class, 'loggable_id' => $actor->id, 'user_id' => $actor->id, 'ip_address' => '127.0.0.1',
        ]);
    }

    /**
     * 활동 로그 목록 응답에서 행위자가 주어진 회원인 행의 `user` 묶음을 찾습니다.
     *
     * @param  array<int, array<string, mixed>>  $rows  목록 행
     * @param  User  $actor  행위자
     * @return array<string, mixed> `user` 묶음
     */
    private function actorBlock(array $rows, User $actor): array
    {
        foreach ($rows as $row) {
            if (($row['user']['uuid'] ?? null) === $actor->uuid) {
                return $row['user'];
            }
        }

        $this->fail("행위자 {$actor->uuid} 의 활동 로그 행이 목록에 없다");
    }

    /**
     * 표시 이름으로 쓸 수 있는 추가 항목을 만들고 회원의 값을 넣습니다.
     *
     * @param  array<int, User>  $users  값을 넣을 회원
     * @return void 반환값 없음
     */
    private function aliasField(array $users): void
    {
        FieldDefinition::create([
            'field_set' => FieldSet::CORE_USER, 'key' => 'alias', 'type' => 'text', 'source_type' => 'custom', 'source_identifier' => 'custom',
            'storage' => 'value', 'name' => ['ko' => '활동명', 'en' => 'Alias'], 'enabled' => true, 'display_name_usable' => true, 'sort_order' => 900,
        ]);
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();

        foreach ($users as $index => $user) {
            app(FieldValueService::class)->write($user, FieldSet::CORE_USER, ['alias' => '활동명'.$index], FieldContext::for(FieldContext::SURFACE_ADMIN_UPDATE, $user));
        }
    }

    /**
     * 설정을 바꾸지 않은 사이트에서는 관리자 화면과 공개 프로필의 이름이 종전과 같고 `real_name` 이 실리지 않는다.
     *
     * @scenario case=default_settings_unchanged
     *
     * @effects default_admin_names_equal_real_name, default_admin_lists_carry_no_real_name, default_public_profile_shows_name
     *
     * @return void 반환값 없음
     */
    public function test_default_settings_keep_names_and_add_no_real_name(): void
    {
        $this->activityLog($this->member);

        $rows = $this->getJson('/api/admin/activity-logs', $this->as())->assertOk()->json('data.data');
        $block = $this->actorBlock($rows, $this->member);
        $this->assertSame('홍길동', $block['name']);
        $this->assertArrayNotHasKey('real_name', $block, '표시 이름이 실명과 같은데 real_name 이 실렸다');
        $this->assertSame('홍길동', collect($rows)->firstWhere('user.uuid', $this->member->uuid)['actor_name']);

        $profile = $this->getJson("/api/users/{$this->member->uuid}/profile")->assertOk()->json('data');
        $this->assertSame('홍길동', $profile['name']);
        $this->assertArrayNotHasKey('real_name', $profile);

        $this->assertSame(['name' => '홍길동'], app(MemberNamePresenter::class)->fields($this->member, $this->adminRequest($this->admin)));
    }

    /**
     * 관리자 영역을 닉네임으로 바꾸면 관리자 목록의 이름이 닉네임이 되고, 회원 조회 권한이 있는 관리자에게 실명이 보조로 실린다.
     *
     * @scenario case=admin_list_display_name
     *
     * @effects admin_list_name_follows_admin_area, admin_list_carries_real_name_for_user_readers, actor_name_follows_admin_area
     *
     * @return void 반환값 없음
     */
    public function test_admin_lists_show_admin_area_name_with_real_name(): void
    {
        $this->areas(['core:site' => ['mode' => 'fixed', 'kind' => 'name'], 'core:admin' => ['mode' => 'fixed', 'kind' => 'nickname']]);
        $this->activityLog($this->member);

        $rows = $this->getJson('/api/admin/activity-logs', $this->as())->assertOk()->json('data.data');
        $block = $this->actorBlock($rows, $this->member);

        $this->assertSame('길동이', $block['name']);
        $this->assertSame('홍길동', $block['real_name']);
        $this->assertSame('길동이', collect($rows)->firstWhere('user.uuid', $this->member->uuid)['actor_name']);

        // 사이트 기본 영역은 그대로라 공개 프로필은 이름이다(관리자 영역 설정이 사용자 화면에 번지지 않는다).
        $this->assertSame('홍길동', $this->getJson("/api/users/{$this->member->uuid}/profile")->json('data.name'));
    }

    /**
     * 회원 조회 권한이 없는 관리자에게는 `real_name` 이 실리지 않는다. 조회 범위가 본인으로 좁혀진 관리자는 자기 실명만 본다.
     *
     * @scenario case=real_name_gate
     *
     * @effects real_name_hidden_without_user_read_permission, real_name_limited_to_self_for_scoped_reader, real_name_hidden_outside_admin_requests
     *
     * @return void 반환값 없음
     */
    public function test_real_name_requires_user_read_permission(): void
    {
        $this->areas(['core:site' => ['mode' => 'fixed', 'kind' => 'nickname'], 'core:admin' => ['mode' => 'fixed', 'kind' => 'nickname']]);

        $noUserRead = $this->limitedAdmin(['core.activities.read' => null], ['email' => 'log-only@example.com']);
        $this->activityLog($this->member);

        $rows = $this->getJson('/api/admin/activity-logs', $this->as($noUserRead))->assertOk()->json('data.data');
        $block = $this->actorBlock($rows, $this->member);
        $this->assertSame('길동이', $block['name']);
        $this->assertArrayNotHasKey('real_name', $block, '회원 조회 권한이 없는 관리자에게 실명이 실렸다');
        $this->assertStringNotContainsString('홍길동', json_encode($rows, JSON_UNESCAPED_UNICODE));

        // 조회 범위가 「본인」인 관리자: 다른 회원의 실명은 없고 자기 실명만 실린다.
        $selfScoped = $this->limitedAdmin(['core.activities.read' => null, 'core.users.read' => 'self'], ['email' => 'self-scope@example.com']);
        $this->activityLog($selfScoped);
        $this->clearPermissionCache();

        $rows = $this->getJson('/api/admin/activity-logs', $this->as($selfScoped))->assertOk()->json('data.data');
        $this->assertArrayNotHasKey('real_name', $this->actorBlock($rows, $this->member));
        $this->assertSame('부관리자', $this->actorBlock($rows, $selfScoped)['real_name']);

        // 관리자 컨트롤러에 도달하지 않은 요청은 권한이 있어도 실명을 싣지 않는다.
        $presenter = app(MemberNamePresenter::class);
        $this->assertSame(['name' => '길동이'], $presenter->fields($this->member, $this->publicRequest($this->admin)));
        $this->assertSame(['name' => '길동이', 'real_name' => '홍길동'], $presenter->fields($this->member, $this->adminRequest($this->admin)));
    }

    /**
     * 실명이 비었거나 표시 이름과 같으면, 그리고 탈퇴한 회원이면 `real_name` 을 싣지 않는다.
     *
     * @scenario case=real_name_omitted
     *
     * @effects real_name_omitted_when_empty, real_name_omitted_when_same_as_display_name, real_name_omitted_for_withdrawn_member
     *
     * @return void 반환값 없음
     */
    public function test_real_name_is_omitted_when_empty_same_or_withdrawn(): void
    {
        $this->areas(['core:site' => ['mode' => 'fixed', 'kind' => 'name'], 'core:admin' => ['mode' => 'fixed', 'kind' => 'nickname']]);
        $presenter = app(MemberNamePresenter::class);
        $request = $this->adminRequest($this->admin);

        $noName = User::factory()->create(['name' => null, 'nickname' => '무명씨', 'email' => 'noname@example.com']);
        $this->assertSame(['name' => '무명씨'], $presenter->fields($noName, $request));

        $same = User::factory()->create(['name' => '한결', 'nickname' => '한결', 'email' => 'same@example.com']);
        $this->assertSame(['name' => '한결'], $presenter->fields($same, $request));

        $withdrawn = User::factory()->create(['name' => '김탈퇴', 'nickname' => '떠난이', 'email' => 'gone@example.com', 'status' => 'withdrawn']);
        $this->assertSame(['name' => __('user.withdrawn_user')], $presenter->fields($withdrawn, $request));

        $this->assertSame(['name' => null], $presenter->fields(null, $request));
    }

    /**
     * 공개 프로필은 사이트 기본 영역의 표시 이름만 내보낸다. 닉네임으로 보이는 사이트에서 실명이 응답에 없다.
     *
     * @scenario case=public_profile
     *
     * @effects public_profile_shows_site_display_name, public_profile_never_exposes_real_name, public_profile_nickname_never_falls_back_to_name
     *
     * @return void 반환값 없음
     */
    public function test_public_profile_shows_display_name_only(): void
    {
        $this->areas(['core:site' => ['mode' => 'fixed', 'kind' => 'nickname'], 'core:admin' => ['mode' => 'fixed', 'kind' => 'name']]);

        // 로그인한 관리자가 봐도 공개 프로필에는 실명이 없다.
        $response = $this->getJson("/api/users/{$this->member->uuid}/profile", $this->as())->assertOk();
        $this->assertSame('길동이', $response->json('data.name'));
        $this->assertStringNotContainsString('홍길동', $response->getContent());

        // 닉네임이 빈 회원은 이름이 아니라 아이디 가림으로 보인다.
        $noNickname = User::factory()->create(['name' => '이실명', 'nickname' => null, 'email' => 'realname@example.com']);
        $response = $this->getJson("/api/users/{$noNickname->uuid}/profile")->assertOk();
        $this->assertSame('rea***', $response->json('data.name'));
        $this->assertStringNotContainsString('이실명', $response->getContent());

        $this->assertSame('길동이', app(UserService::class)->getPublicProfile($this->member->id)['name']);
    }

    /**
     * 추가 항목 값으로 보이는 설정에서도 목록은 항목 값을 한 번만 읽는다(행마다 읽지 않는다).
     *
     * @scenario case=list_query_count
     *
     * @effects admin_list_reads_field_values_once, admin_list_shows_field_kind_value
     *
     * @return void 반환값 없음
     */
    public function test_admin_list_reads_field_values_once(): void
    {
        $actors = [];
        foreach (range(1, 6) as $i) {
            $actors[] = User::factory()->create(['name' => "회원{$i}", 'nickname' => "닉{$i}", 'email' => "actor{$i}@example.com"]);
        }
        $this->aliasField($actors);
        foreach ($actors as $actor) {
            $this->activityLog($actor);
        }
        $this->areas(['core:site' => ['mode' => 'fixed', 'kind' => 'name'], 'core:admin' => ['mode' => 'fixed', 'kind' => 'field:alias']]);

        $headers = $this->as();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $rows = $this->getJson('/api/admin/activity-logs', $headers)->assertOk()->json('data.data');
        $valueQueries = array_filter(DB::getQueryLog(), fn (array $query) => str_contains($query['query'], 'field_values'));
        DB::disableQueryLog();

        $this->assertCount(1, $valueQueries, '활동 로그 목록이 추가 항목 값을 행마다 읽었다');
        $block = $this->actorBlock($rows, $actors[2]);
        $this->assertSame('활동명2', $block['name']);
        $this->assertSame('회원3', $block['real_name']);
    }

    /**
     * 회원 관계를 싣는 관리자 리소스(메뉴 등록자, 첨부 업로더, 레이아웃 버전 저장자, 개인정보 수동 실행 접수자)가 같은 규칙을 쓴다.
     *
     * @scenario case=relation_resources
     *
     * @effects relation_resources_show_admin_area_name, relation_resources_carry_suffixed_real_name_keys, relation_resources_hide_real_name_on_public_requests
     *
     * @return void 반환값 없음
     */
    public function test_relation_resources_use_the_same_rule(): void
    {
        $this->areas(['core:site' => ['mode' => 'fixed', 'kind' => 'masked_name'], 'core:admin' => ['mode' => 'fixed', 'kind' => 'nickname']]);

        $menu = (new Menu(['name' => ['ko' => '메뉴'], 'slug' => 'issue490-menu', 'url' => '/x']))->setRelation('creator', $this->member);
        $attachment = (new Attachment(['hash' => 'issue490hash0', 'original_filename' => 'a.txt', 'mime_type' => 'text/plain', 'size' => 1]))->setRelation('creator', $this->member);
        $version = (new TemplateLayoutVersion(['version' => 3, 'changes_summary' => []]))->setRelation('creator', $this->member);
        $run = (new PrivacyManualRun(['target' => 'dormant', 'status' => PrivacyManualRunStatus::cases()[0]]))->setRelation('requester', $this->member);

        // 관리자 요청: 관리자 영역 표시 이름과 실명 보조
        $request = $this->bind($this->adminRequest($this->admin), $this->admin);
        $this->assertSame(
            ['uuid' => $this->member->uuid, 'name' => '길동이', 'real_name' => '홍길동', 'email' => $this->member->email],
            (new MenuResource($menu))->toArray($request)['creator'],
        );
        $creator = (new AttachmentResource($attachment))->toArray($request)['creator'];
        $this->assertSame(['uuid' => $this->member->uuid, 'name' => '길동이', 'real_name' => '홍길동'], $creator instanceof \Closure ? $creator() : $creator);

        $row = (new LayoutVersionResource($version))->toListArray($request);
        $this->assertSame(['길동이', '홍길동'], [$row['created_by_name'], $row['created_by_real_name']]);

        $manual = (new PrivacyManualRunResource($run))->toArray($request);
        $this->assertSame(['길동이', '홍길동'], [$manual['requester_name'], $manual['requester_real_name']]);

        // 공개 요청: 사이트 기본 영역 표시 이름, 실명 키 없음
        $request = $this->bind($this->publicRequest($this->admin), $this->admin);

        $creator = (new AttachmentResource($attachment))->toArray($request)['creator'];
        $this->assertSame(['uuid' => $this->member->uuid, 'name' => '홍*동'], $creator instanceof \Closure ? $creator() : $creator);
        $row = (new LayoutVersionResource($version))->toListArray($request);
        $this->assertSame('홍*동', $row['created_by_name']);
        $this->assertArrayNotHasKey('created_by_real_name', $row);
    }

    /**
     * 관리자 화면의 그 밖의 회원 이름(알림 발송 이력의 수신자, 알림 템플릿의 지정 수신자, 대시보드 최근 알림)도 관리자 영역 표시 이름이다.
     *
     * @scenario case=other_admin_surfaces
     *
     * @effects notification_log_recipient_follows_admin_area, notification_template_specific_users_follow_admin_area, notification_log_snapshot_is_kept
     *
     * @return void 반환값 없음
     */
    public function test_other_admin_surfaces_follow_admin_area(): void
    {
        $this->areas(['core:site' => ['mode' => 'fixed', 'kind' => 'name'], 'core:admin' => ['mode' => 'fixed', 'kind' => 'nickname']]);

        NotificationLog::create([
            'channel' => 'mail', 'notification_type' => 'welcome', 'extension_type' => 'core', 'extension_identifier' => 'core',
            'recipient_user_id' => $this->member->id, 'recipient_identifier' => $this->member->email, 'recipient_name' => '기록 당시 이름',
            'subject' => '제목', 'body' => '본문', 'status' => 'sent', 'source' => 'notification', 'sent_at' => now(),
        ]);

        $row = $this->getJson('/api/admin/notification-logs', $this->as())->assertOk()->json('data.data.0');
        $this->assertSame('기록 당시 이름', $row['recipient_name'], '발송 당시 기록한 이름이 바뀌었다');
        $this->assertSame('길동이', $row['recipient']['name']);
        $this->assertSame('홍길동', $row['recipient']['real_name']);

        $definition = NotificationDefinition::create([
            'type' => 'issue490_specific', 'hook_prefix' => 'core.test', 'extension_type' => 'core', 'extension_identifier' => 'core',
            'name' => ['ko' => '지정 수신자'], 'variables' => [], 'channels' => ['database'], 'hooks' => [], 'is_active' => true, 'is_default' => true,
        ]);
        $template = NotificationTemplate::create([
            'definition_id' => $definition->id, 'channel' => 'database', 'subject' => ['ko' => '제목'], 'body' => ['ko' => '본문'],
            'recipients' => [['type' => 'specific_users', 'value' => [$this->member->uuid, 'missing-uuid']]], 'is_active' => true, 'is_default' => true,
        ]);

        $response = $this->putJson("/api/admin/notification-templates/{$template->id}", ['body' => ['ko' => '본문']], $this->as())->assertOk();
        $this->assertSame(['길동이', 'missing-uuid'], $response->json('data.recipients.0.display_names'));
    }

    /**
     * 관리자 화면의 회원 검색은 이름과 닉네임을 모두 찾는다.
     *
     * @scenario case=admin_search
     *
     * @effects admin_member_search_matches_name_and_nickname
     *
     * @return void 반환값 없음
     */
    public function test_admin_member_search_matches_name_and_nickname(): void
    {
        $headers = $this->as();

        $byNickname = $this->getJson('/api/admin/users/search?keyword='.urlencode('길동이'), $headers)->assertOk()->json('data');
        $this->assertContains($this->member->uuid, array_column($byNickname, 'uuid'));

        $byName = $this->getJson('/api/admin/users/search?keyword='.urlencode('홍길'), $headers)->assertOk()->json('data');
        $this->assertContains($this->member->uuid, array_column($byName, 'uuid'));

        // 와일드카드 문자는 글자 그대로 찾는다.
        $this->assertSame([], $this->getJson('/api/admin/users/search?keyword='.urlencode('%'), $headers)->assertOk()->json('data'));
    }

    /**
     * 요청을 현재 요청으로 묶습니다 (요청 인자 없이 `request()` 를 읽는 리소스 경로용).
     *
     * 컨테이너에 요청을 다시 묶으면 인증 관리자가 사용자 해석기를 덮어쓰므로 묶은 뒤에 다시 건다.
     *
     * @param  Request  $request  요청
     * @param  User  $viewer  보는 사람
     * @return Request 현재 요청으로 묶인 요청
     */
    private function bind(Request $request, User $viewer): Request
    {
        $this->app->instance('request', $request);
        $request->setUserResolver(fn () => $viewer);

        return $request;
    }

    /**
     * 관리자 컨트롤러에 도달한 요청을 흉내 냅니다.
     *
     * @param  User  $viewer  보는 사람
     * @return Request 요청
     */
    private function adminRequest(User $viewer): Request
    {
        return $this->requestFor($viewer, [ActivityLogController::class, 'index']);
    }

    /**
     * 공개 컨트롤러에 도달한 요청을 흉내 냅니다.
     *
     * @param  User  $viewer  보는 사람
     * @return Request 요청
     */
    private function publicRequest(User $viewer): Request
    {
        return $this->requestFor($viewer, [PublicProfileController::class, 'show']);
    }

    /**
     * 주어진 컨트롤러로 라우팅된 요청을 만듭니다.
     *
     * @param  User  $viewer  보는 사람
     * @param  array{0: class-string, 1: string}  $action  컨트롤러와 메서드
     * @return Request 요청
     */
    private function requestFor(User $viewer, array $action): Request
    {
        $request = Request::create('/issue490-probe', 'GET');
        $route = (new Route('GET', '/issue490-probe', $action))->setContainer($this->app);
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => $viewer);

        return $request;
    }
}
