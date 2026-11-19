<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Feature\Api\Admin;

use App\DisplayName\DisplayNameAreaRegistry;
use App\DisplayName\NicknameRequirement;
use App\Enums\PermissionType;
use App\Enums\UserStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;
use Plugins\Sirsoft\Gdpr\Enums\GdprPolicyChangeType;
use Plugins\Sirsoft\Gdpr\Models\GdprPolicyVersion;
use Plugins\Sirsoft\Gdpr\Models\GdprUserConsentHistory;
use Plugins\Sirsoft\Gdpr\Repositories\Contracts\GdprPrivacyNoticeRepositoryInterface;
use Plugins\Sirsoft\Gdpr\Repositories\GdprPrivacyNoticeRepository;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * GDPR 관리자 화면의 회원 이름 (#490)
 *
 * 동의 로그의 회원(`user.name`), 정책 버전의 발행자(`publisher.name`), 연간 안내 대상 명단의 회원 칸(`title`)은 회원 이름 컬럼을
 * 직접 싣지 않고 관리자 영역(`core:admin`) 표시 이름으로 계산한다. 실명은 회원 조회 권한(`core.users.read`)이 있는 관리자에게,
 * 실명이 표시 이름과 다를 때만 같은 묶음의 `real_name` 으로 실린다(대상 명단은 코어 개인정보 명단과 같이 싣지 않는다).
 * 설정을 바꾸지 않은 사이트의 응답은 수정 전과 같다.
 *
 * @scenario-manifest tests/scenarios/admin-member-display-name.yaml
 */
class AdminMemberDisplayNameTest extends PluginTestCase
{
    private const CONSENT_LOG_URL = '/api/plugins/sirsoft-gdpr/admin/consent-log';

    private const POLICY_VERSIONS_URL = '/api/plugins/sirsoft-gdpr/admin/policy-versions';

    private const TARGETS_URL = '/api/plugins/sirsoft-gdpr/admin/annual-notice/targets?per_page=100';

    /**
     * 연간 안내 저장소를 묶습니다 (명단 경로가 쓴다).
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(GdprPrivacyNoticeRepositoryInterface::class, GdprPrivacyNoticeRepository::class);
    }

    /**
     * 관리자 영역을 닉네임으로 고정하면 동의 로그의 회원 이름이 닉네임이 되고, 회원 조회 권한이 있는 관리자에게만 실명 키가 실린다.
     *
     * @scenario case=consent_log_nickname_with_member_read
     *
     * @effects consent_log_member_name_follows_admin_area, consent_log_real_name_added_for_member_readers
     *
     * @return void 반환값 없음
     */
    public function test_consent_log_member_name_follows_admin_area_with_real_name_for_member_readers(): void
    {
        $this->nicknameAdminArea();
        $member = $this->member('홍길동', '길동이');
        $this->consentLog($member);

        $user = $this->actingAs($this->viewer(withMemberRead: true))
            ->getJson(self::CONSENT_LOG_URL)
            ->assertOk()
            ->json('data.data.0.user');

        $this->assertSame($member->uuid, $user['uuid']);
        $this->assertSame('길동이', $user['name']);
        $this->assertSame('홍길동', $user['real_name']);
    }

    /**
     * 회원 조회 권한이 없는 관리자에게는 동의 로그의 회원 표시 이름만 가고 실명 키 자체가 없다.
     *
     * @scenario case=consent_log_nickname_without_member_read
     *
     * @effects consent_log_member_name_follows_admin_area, consent_log_real_name_hidden_without_member_read
     *
     * @return void 반환값 없음
     */
    public function test_consent_log_real_name_key_is_absent_without_member_read(): void
    {
        $this->nicknameAdminArea();
        $this->consentLog($this->member('홍길동', '길동이'));

        $user = $this->actingAs($this->viewer(withMemberRead: false))
            ->getJson(self::CONSENT_LOG_URL)
            ->assertOk()
            ->json('data.data.0.user');

        $this->assertSame('길동이', $user['name']);
        $this->assertArrayNotHasKey('real_name', $user);
    }

    /**
     * 설정을 바꾸지 않은 사이트는 동의 로그에 수정 전과 같이 회원 이름을 싣고, 실명 키를 더하지 않는다. 비회원 행은 회원 묶음이 null 이다.
     *
     * @scenario case=consent_log_default_site_unchanged
     *
     * @effects consent_log_member_name_unchanged_on_default_site, consent_log_guest_row_has_no_member
     *
     * @return void 반환값 없음
     */
    public function test_consent_log_default_site_keeps_member_name(): void
    {
        $member = $this->member('홍길동', '길동이');
        $this->consentLog($member);
        $this->consentLog(null);

        $rows = collect($this->actingAs($this->viewer(withMemberRead: true))
            ->getJson(self::CONSENT_LOG_URL)
            ->assertOk()
            ->json('data.data'));

        $memberRow = $rows->firstWhere('user_id', $member->id);
        $this->assertSame('홍길동', $memberRow['user']['name']);
        $this->assertArrayNotHasKey('real_name', $memberRow['user']);
        $this->assertSame($member->email, $memberRow['user']['email']);

        $guestRow = $rows->firstWhere('user_id', null);
        $this->assertNull($guestRow['user']);
    }

    /**
     * 정책 버전의 발행자 이름은 목록, 현재 버전, 버전 상세 세 경로 모두 관리자 영역 표시 이름이고 회원 조회 권한이 있으면 실명 키가 붙는다.
     *
     * @scenario case=policy_publisher_nickname_with_member_read
     *
     * @effects policy_publisher_name_follows_admin_area, policy_publisher_real_name_added_for_member_readers
     *
     * @return void 반환값 없음
     */
    public function test_policy_publisher_name_follows_admin_area_on_every_path(): void
    {
        $this->nicknameAdminArea();
        $publisher = $this->member('홍길동', '길동이');
        $this->policyVersion(5, $publisher);
        $viewer = $this->viewer(withMemberRead: true);

        $listed = collect($this->actingAs($viewer)->getJson(self::POLICY_VERSIONS_URL)->assertOk()->json('data.data'))
            ->firstWhere('version', 5)['publisher'];
        $current = $this->actingAs($viewer)->getJson(self::POLICY_VERSIONS_URL.'/current')->assertOk()->json('data.data.publisher');
        $detail = $this->actingAs($viewer)->getJson(self::POLICY_VERSIONS_URL.'/5')->assertOk()->json('data.data.publisher');

        foreach (['목록' => $listed, '현재 버전' => $current, '버전 상세' => $detail] as $path => $shown) {
            $this->assertSame($publisher->uuid, $shown['uuid'], $path);
            $this->assertSame('길동이', $shown['name'], $path);
            $this->assertSame('홍길동', $shown['real_name'], $path);
        }
    }

    /**
     * 회원 조회 권한이 없는 관리자에게는 발행자 표시 이름만 가고 실명 키가 없다.
     *
     * @scenario case=policy_publisher_nickname_without_member_read
     *
     * @effects policy_publisher_name_follows_admin_area, policy_publisher_real_name_hidden_without_member_read
     *
     * @return void 반환값 없음
     */
    public function test_policy_publisher_real_name_key_is_absent_without_member_read(): void
    {
        $this->nicknameAdminArea();
        $this->policyVersion(5, $this->member('홍길동', '길동이'));
        $viewer = $this->viewer(withMemberRead: false);

        $current = $this->actingAs($viewer)->getJson(self::POLICY_VERSIONS_URL.'/current')->assertOk()->json('data.data.publisher');
        $detail = $this->actingAs($viewer)->getJson(self::POLICY_VERSIONS_URL.'/5')->assertOk()->json('data.data.publisher');

        foreach ([$current, $detail] as $shown) {
            $this->assertSame('길동이', $shown['name']);
            $this->assertArrayNotHasKey('real_name', $shown);
        }
    }

    /**
     * 설정을 바꾸지 않은 사이트는 발행자 이름이 수정 전과 같고 실명 키가 없다. 발행자가 없는 버전(설치 시드)은 발행자 묶음이 null 이다.
     *
     * @scenario case=policy_publisher_default_site_unchanged
     *
     * @effects policy_publisher_name_unchanged_on_default_site, policy_publisher_null_without_publisher
     *
     * @return void 반환값 없음
     */
    public function test_policy_publisher_default_site_keeps_member_name(): void
    {
        $this->policyVersion(5, $this->member('홍길동', '길동이'));

        $rows = collect($this->actingAs($this->viewer(withMemberRead: true))
            ->getJson(self::POLICY_VERSIONS_URL)
            ->assertOk()
            ->json('data.data'));

        $published = $rows->firstWhere('version', 5)['publisher'];
        $this->assertSame('홍길동', $published['name']);
        $this->assertArrayNotHasKey('real_name', $published);
        $this->assertNull($rows->firstWhere('version', 1)['publisher']);
    }

    /**
     * 연간 안내 대상 명단의 회원 칸은 관리자 영역 표시 이름이고, 코어 개인정보 대상 명단처럼 실명 키를 싣지 않는다.
     *
     * @scenario case=annual_targets_nickname_admin_area
     *
     * @effects annual_target_title_follows_admin_area, annual_target_has_no_real_name_key
     *
     * @return void 반환값 없음
     */
    public function test_annual_notice_target_title_follows_admin_area(): void
    {
        $this->nicknameAdminArea();
        $member = $this->dueMember('홍길동', '길동이');

        $row = collect($this->actingAs($this->viewer(withMemberRead: true))
            ->getJson(self::TARGETS_URL)
            ->assertOk()
            ->json('data.data'))
            ->firstWhere('id', $member->id);

        $this->assertNotNull($row);
        $this->assertSame('길동이', $row['title']);
        $this->assertSame($member->email, $row['subtitle']);
        $this->assertArrayNotHasKey('real_name', $row);
        $this->assertArrayNotHasKey('title_real_name', $row);
    }

    /**
     * 설정을 바꾸지 않은 사이트는 연간 안내 대상 명단의 회원 칸이 수정 전과 같은 회원 이름이다.
     *
     * @scenario case=annual_targets_default_site_unchanged
     *
     * @effects annual_target_title_unchanged_on_default_site
     *
     * @return void 반환값 없음
     */
    public function test_annual_notice_target_title_is_unchanged_on_default_site(): void
    {
        $member = $this->dueMember('홍길동', '길동이');

        $row = collect($this->actingAs($this->viewer(withMemberRead: true))
            ->getJson(self::TARGETS_URL)
            ->assertOk()
            ->json('data.data'))
            ->firstWhere('id', $member->id);

        $this->assertNotNull($row);
        $this->assertSame('홍길동', $row['title']);
    }

    /**
     * 관리자 영역을 닉네임으로 고정합니다.
     *
     * @return void 반환값 없음
     */
    private function nicknameAdminArea(): void
    {
        config(['g7_settings.core.member.display_name_areas' => [
            'core:admin' => ['mode' => 'fixed', 'kind' => 'nickname'],
            'core:site' => ['mode' => 'fixed', 'kind' => 'name'],
        ]]);
        app(DisplayNameAreaRegistry::class)->flush();
        app(NicknameRequirement::class)->flush();
    }

    /**
     * 이름과 닉네임을 정한 회원을 만듭니다.
     *
     * @param  string  $name  이름
     * @param  string  $nickname  닉네임
     * @return User 회원
     */
    private function member(string $name, string $nickname): User
    {
        return User::factory()->create(['name' => $name, 'nickname' => $nickname]);
    }

    /**
     * 연간 안내 대상이 되는 회원(가입 1년 경과, 활성)을 만듭니다. 시험 DB 에 남은 다른 회원은 가입일을 지금으로 맞춰 대상에서 뺀다.
     *
     * @param  string  $name  이름
     * @param  string  $nickname  닉네임
     * @return User 회원
     */
    private function dueMember(string $name, string $nickname): User
    {
        User::query()->update(['created_at' => now()]);

        return User::factory()->create([
            'name' => $name,
            'nickname' => $nickname,
            'status' => UserStatus::Active,
            'created_at' => now()->subYears(2),
        ]);
    }

    /**
     * 동의 이력 한 줄을 만듭니다.
     *
     * @param  User|null  $member  회원 (null 이면 비회원)
     * @return GdprUserConsentHistory 이력
     */
    private function consentLog(?User $member): GdprUserConsentHistory
    {
        return GdprUserConsentHistory::create([
            'user_id' => $member?->id,
            'session_id' => $member === null ? Str::random(32) : null,
            'consent_key' => 'cookie_analytics',
            'action' => 'granted',
            'source' => 'banner',
            'policy_version' => '1.0',
        ]);
    }

    /**
     * 발행자를 정한 정책 버전을 만듭니다.
     *
     * @param  int  $version  버전 번호
     * @param  User  $publisher  발행자
     * @return GdprPolicyVersion 정책 버전
     */
    private function policyVersion(int $version, User $publisher): GdprPolicyVersion
    {
        return GdprPolicyVersion::create([
            'version' => $version,
            'change_type' => GdprPolicyChangeType::Material->value,
            'memo' => '발행자 이름 시험',
            'snapshot' => ['cookie_categories' => [['key' => 'analytics']]],
            'created_by' => $publisher->id,
        ]);
    }

    /**
     * GDPR 관리자 화면을 볼 수 있는 관리자를 만듭니다.
     *
     * 공유 `admin` 역할을 쓰지 않는다 — 기준 역할이 회원 조회 권한을 이미 가질 수 있어 「회원 조회가 없는 관리자」를 만들 수 없다.
     *
     * @param  bool  $withMemberRead  회원 조회 권한(`core.users.read`)을 줄지
     * @return User 관리자
     */
    private function viewer(bool $withMemberRead): User
    {
        $role = Role::query()->create([
            'identifier' => 'gdpr-dn-'.Str::lower(Str::random(8)),
            'name' => ['ko' => '시험 역할', 'en' => 'Test Role'],
            'description' => ['ko' => '', 'en' => ''],
            'is_active' => true,
        ]);

        $identifiers = ['admin.access', 'sirsoft-gdpr.privacy.view'];
        if ($withMemberRead) {
            $identifiers[] = 'core.users.read';
        }

        foreach ($identifiers as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                ['name' => ['ko' => $identifier, 'en' => $identifier], 'type' => PermissionType::Admin]
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user = User::factory()->create(['name' => '보는관리자', 'nickname' => '보는이']);
        $user->roles()->attach($role->id);
        $user->flushPermissionCaches();

        return $user;
    }
}
