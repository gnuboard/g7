<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Admin;

use App\DisplayName\DisplayNameAreaRegistry;
use App\DisplayName\DisplayNameResolver;
use App\DisplayName\NicknameRequirement;
use App\Extension\HookManager;
use App\Models\ActivityLog;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\PolicyFixtures;

/**
 * SSO 플러그인이 회원 이름을 싣는 자리의 표시 이름 (#490)
 *
 * - 관리자 「회원 연결」 목록의 회원 이름은 관리자 영역(`core:admin`) 표시 이름이다. 실명은 회원 조회 권한(`core.users.read`)이
 *   있는 관리자에게, 실명이 표시 이름과 다를 때만 `user.real_name` 으로 실린다.
 * - 목록 검색은 관리자 화면 검색 규칙대로 이름, 닉네임, 이메일을 찾는다.
 * - 새로 남기는 활동 로그의 회원 이름은 그 시점의 관리자 영역 표시 이름이다.
 * - 알림 본문의 사건 회원 이름(`user_name`)은 알림 영역(`core:notification`) 표시 이름이고, 회원 본인에게 가는 알림의
 *   받는 사람 이름(`name`)은 코어가 받는 사람과 채널마다 푸는 자리표시(`{recipient_name}`)다.
 */
class MemberDisplayNameTest extends PluginTestCase
{
    use PolicyFixtures;

    /** API 접두 */
    private const BASE = '/api/plugins/sirsoft-sso/admin/accounts';

    /**
     * 관리자 영역을 닉네임으로 고정하면 목록의 회원 이름이 닉네임이 되고, 회원 조회 권한이 있는 관리자에게만 실명 키가 실린다.
     *
     * @scenario case=list_nickname_admin_area_with_member_read
     *
     * @effects account_user_name_follows_admin_area, account_user_real_name_added_for_member_readers
     *
     * @return void 반환값 없음
     */
    public function test_list_shows_admin_area_name_and_real_name_for_member_readers(): void
    {
        $this->areas(['core:admin' => ['mode' => 'fixed', 'kind' => 'nickname']]);
        $this->linkAccount($this->makeMember(['name' => '홍길동', 'nickname' => '길동이']), $this->makeIdp('corp'));
        Sanctum::actingAs($this->makeAdmin(['sirsoft-sso.accounts.view', 'core.users.read']));

        $user = $this->getJson(self::BASE)->assertOk()->json('data.data.0.user');

        $this->assertSame('길동이', $user['name']);
        $this->assertSame('홍길동', $user['real_name']);
    }

    /**
     * 회원 조회 권한이 없는 관리자에게는 표시 이름만 가고 실명 키 자체가 없다.
     *
     * @scenario case=list_nickname_admin_area_without_member_read
     *
     * @effects account_user_name_follows_admin_area, account_user_real_name_hidden_without_member_read
     *
     * @return void 반환값 없음
     */
    public function test_list_omits_real_name_without_member_read_permission(): void
    {
        $this->areas(['core:admin' => ['mode' => 'fixed', 'kind' => 'nickname']]);
        $this->linkAccount($this->makeMember(['name' => '홍길동', 'nickname' => '길동이']), $this->makeIdp('corp'));
        Sanctum::actingAs($this->makeAdmin(['sirsoft-sso.accounts.view']));

        $user = $this->getJson(self::BASE)->assertOk()->json('data.data.0.user');

        $this->assertSame('길동이', $user['name']);
        $this->assertArrayNotHasKey('real_name', $user);
    }

    /**
     * 설정을 바꾸지 않은 사이트는 수정 전과 같이 회원 이름을 싣고 실명 키를 더하지 않는다. 연결 추가 응답(같은 행 모양)도 같다.
     *
     * @scenario case=default_site_unchanged
     *
     * @effects account_user_name_unchanged_on_default_site, account_user_real_name_omitted_when_same, link_response_row_uses_same_rule
     *
     * @return void 반환값 없음
     */
    public function test_default_site_keeps_member_name(): void
    {
        $idp = $this->makeIdp('corp');
        $member = $this->makeMember(['name' => '홍길동', 'nickname' => '길동이']);
        Sanctum::actingAs($this->makeAdmin(['sirsoft-sso.accounts.view', 'sirsoft-sso.accounts.provision', 'core.users.read']));

        $created = $this->postJson(self::BASE, ['idp_id' => $idp->id, 'subject' => 'DN-1', 'user_id' => $member->id])->assertStatus(201)->json('data.user');
        $this->assertSame('홍길동', $created['name']);
        $this->assertArrayNotHasKey('real_name', $created);

        $listed = $this->getJson(self::BASE)->assertOk()->json('data.data.0.user');
        $this->assertSame('홍길동', $listed['name']);
        $this->assertArrayNotHasKey('real_name', $listed);
        // 연결 해제 경고와 회원 상세 이동에 쓰는 나머지 키는 그대로다
        $this->assertSame($member->uuid, $listed['uuid']);
        $this->assertTrue($listed['has_password']);
        $this->assertFalse($listed['is_privileged']);
    }

    /**
     * 목록 검색은 이름, 닉네임, 이메일을 찾는다. 검색어의 LIKE 와일드카드는 글자 그대로 찾는다.
     *
     * @scenario case=search_matches_name_nickname_email
     *
     * @effects account_search_matches_name, account_search_matches_nickname, account_search_keeps_email, account_search_escapes_wildcards
     *
     * @return void 반환값 없음
     */
    public function test_search_matches_name_nickname_and_email(): void
    {
        $idp = $this->makeIdp('corp');
        $member = $this->makeMember(['name' => '홍길동', 'nickname' => '길동이', 'email' => 'gildong.dn@corp798.test']);
        $this->linkAccount($member, $idp);
        $this->linkAccount($this->makeMember(['name' => '김철수', 'nickname' => '철수씨']), $idp);
        Sanctum::actingAs($this->makeAdmin(['sirsoft-sso.accounts.view']));

        foreach (['홍길', '길동이', 'GILDONG.DN'] as $keyword) {
            $ids = array_column(array_column($this->getJson(self::BASE.'?q='.urlencode($keyword))->assertOk()->json('data.data'), 'user'), 'id');
            $this->assertSame([$member->id], $ids, $keyword);
        }

        $this->assertSame([], $this->getJson(self::BASE.'?q='.urlencode('%'))->assertOk()->json('data.data'));
    }

    /**
     * 새로 남기는 활동 로그의 회원 이름은 그 시점의 관리자 영역 표시 이름이다(연결 추가, 사전 등록).
     *
     * @scenario case=activity_log_member_name
     *
     * @effects activity_log_user_name_is_admin_area_name
     *
     * @return void 반환값 없음
     */
    public function test_new_activity_logs_record_admin_area_name(): void
    {
        $this->areas(['core:admin' => ['mode' => 'fixed', 'kind' => 'nickname']]);
        $idp = $this->makeIdp('corp');
        $member = $this->makeMember(['name' => '홍길동', 'nickname' => '길동이']);
        Sanctum::actingAs($this->makeAdmin(['sirsoft-sso.accounts.view', 'sirsoft-sso.accounts.provision']));

        $this->postJson(self::BASE, ['idp_id' => $idp->id, 'subject' => 'DN-2', 'user_id' => $member->id])->assertStatus(201);
        $linked = ActivityLog::query()->where('action', 'sso.linked')->where('loggable_id', $member->id)->firstOrFail();
        $this->assertSame('길동이', $linked->description_params['user_name']);

        $createdId = $this->postJson(self::BASE, ['idp_id' => $idp->id, 'subject' => 'DN-3', 'new_user' => ['name' => '새직원', 'email' => 'new.dn@corp798.test']])
            ->assertStatus(201)->json('data.user.id');
        $created = User::query()->findOrFail($createdId);
        $preregistered = ActivityLog::query()->where('action', 'sso.preregistered')->where('loggable_id', $createdId)->firstOrFail();
        // 닉네임이 없는 회원은 닉네임 종류의 대체 순서(아이디 가림)로 보인다 — 실명이 기록에 새지 않는다
        $expected = app(DisplayNameResolver::class)->resolve($created, DisplayNameAreaRegistry::AREA_ADMIN);
        $this->assertSame($expected, $preregistered->description_params['user_name']);
        $this->assertNotSame('새직원', $preregistered->description_params['user_name']);
    }

    /**
     * 알림의 사건 회원 이름은 알림 영역 표시 이름이고, 회원 본인에게 가는 알림의 받는 사람 이름은 자리표시다.
     *
     * @scenario case=notification_member_names
     *
     * @effects notification_user_name_follows_notification_area, notification_member_recipient_name_is_placeholder
     *
     * @return void 반환값 없음
     */
    public function test_notification_member_names(): void
    {
        $this->areas(['core:notification' => ['mode' => 'fixed', 'kind' => 'nickname']]);
        $idp = $this->makeIdp('corp');
        $this->makeSuper();
        $admin = $this->makeAdmin([], ['name' => '이관리', 'nickname' => '관리왕']);
        $account = $this->linkAccount($admin, $idp);
        $base = ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []];

        $skipped = HookManager::applyFilters('sirsoft-sso.notification.extract_data', $base, 'sso_admin_withdraw_skipped', [$admin, $account]);
        $this->assertSame('관리왕', $skipped['data']['user_name']);
        $this->assertSame('{recipient_name}', $skipped['data']['name']);

        $emergency = HookManager::applyFilters('sirsoft-sso.notification.extract_data', $base, 'sso_emergency_login', [$admin]);
        $this->assertSame('관리왕', $emergency['data']['user_name']);

        $member = $this->makeMember(['name' => '김직원', 'nickname' => '직원이']);
        $reset = HookManager::applyFilters('sirsoft-sso.notification.extract_data', $base, 'sso_password_reset_redirect', [$member, $idp]);
        $this->assertSame('{recipient_name}', $reset['data']['name']);
        $this->assertSame($member->id, $reset['context']['trigger_user_id']);
    }

    /**
     * 영역 설정을 바꿉니다 (사이트 기본과 관리자 화면은 지정하지 않으면 이름 고정).
     *
     * @param  array<string, array<string, mixed>>  $areas  영역 키 => 설정
     * @return void 반환값 없음
     */
    /**
     * 정책 화면의 비상 계정 목록도 관리자 영역 표시 이름이고, 회원 조회 권한이 있는 관리자에게만 실명이 따로 실린다
     * (종전에는 원본 이름 그대로였다).
     *
     * @scenario case=emergency_users_admin_area_name
     *
     * @effects emergency_user_name_follows_admin_area, emergency_user_real_name_only_for_member_readers
     *
     * @return void 반환값 없음
     */
    public function test_policy_emergency_users_use_admin_area_name(): void
    {
        $this->areas(['core:admin' => ['mode' => 'fixed', 'kind' => 'nickname']]);
        $emergency = $this->makeSuper(['name' => '비상관리', 'nickname' => '비상닉']);
        $this->setPolicy(['emergency_user_ids' => [$emergency->id]]);

        Sanctum::actingAs($this->makeAdmin(['sirsoft-sso.settings.view', 'core.users.read']));
        $row = $this->getJson('/api/plugins/sirsoft-sso/admin/policy')->assertOk()->json('data.emergency_users.0');
        $this->assertSame('비상닉', $row['name']);
        $this->assertSame('비상관리', $row['real_name'] ?? null);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->makeAdmin(['sirsoft-sso.settings.view']));
        $row = $this->getJson('/api/plugins/sirsoft-sso/admin/policy')->assertOk()->json('data.emergency_users.0');
        $this->assertSame('비상닉', $row['name']);
        $this->assertArrayNotHasKey('real_name', $row);
    }

    /**
     * 표시 이름 영역 설정을 바꾸고 등록소 캐시를 비웁니다 (사이트와 관리자 영역은 이름으로 고정한 기본값을 깐다).
     *
     * @param  array<string, array<string, string>>  $areas  영역 키 => 영역 설정
     * @return void 반환값 없음
     */
    private function areas(array $areas): void
    {
        config(['g7_settings.core.member.display_name_areas' => $areas + [
            'core:site' => ['mode' => 'fixed', 'kind' => 'name'],
            'core:admin' => ['mode' => 'fixed', 'kind' => 'name'],
        ]]);
        app(DisplayNameAreaRegistry::class)->flush();
        app(NicknameRequirement::class)->flush();
    }
}
