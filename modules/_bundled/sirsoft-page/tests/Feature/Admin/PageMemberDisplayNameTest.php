<?php

namespace Modules\Sirsoft\Page\Tests\Feature\Admin;

// FeatureTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../FeatureTestCase.php';

use App\DisplayName\DisplayNameAreaRegistry;
use App\DisplayName\NicknameRequirement;
use App\Enums\PermissionType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageVersion;
use Modules\Sirsoft\Page\Tests\FeatureTestCase;

/**
 * 페이지 관리 화면의 작성자, 수정자 이름 (#490)
 *
 * 페이지 목록, 상세, 버전 이력, 버전 상세의 `creator.name`, `updater.name` 은 회원 이름 컬럼을 직접 싣지 않고 관리자 영역
 * (`core:admin`) 표시 이름으로 계산한다. 실명은 회원 조회 권한(`core.users.read`)이 있는 관리자에게, 실명이 표시 이름과 다를 때만
 * 같은 묶음의 `real_name` 으로 실린다. 설정을 바꾸지 않은 사이트의 응답은 수정 전과 같다.
 *
 * @scenario-manifest tests/scenarios/page-member-display-name.yaml
 */
class PageMemberDisplayNameTest extends FeatureTestCase
{
    private const BASE_URL = '/api/modules/sirsoft-page/admin/pages';

    /**
     * 시험이 만든 페이지를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Page::where('slug', 'like', 'test-member-dn-%')->forceDelete();

        parent::tearDown();
    }

    /**
     * 관리자 영역을 닉네임으로 고정하면 페이지 상세의 작성자와 수정자 이름이 닉네임이 되고, 회원 조회 권한이 있으면 실명 키가 붙는다.
     *
     * @scenario case=detail_nickname_with_member_read
     *
     * @effects page_creator_updater_name_follows_admin_area, page_real_name_added_for_member_readers
     *
     * @return void 반환값 없음
     */
    public function test_detail_creator_and_updater_follow_admin_area_with_real_name(): void
    {
        $this->nicknameAdminArea();
        $creator = $this->member('홍길동', '길동이');
        $updater = $this->member('김철수', '철수닉');
        $page = $this->makePage('test-member-dn-detail', $creator, $updater);

        $data = $this->actingAs($this->viewer(withMemberRead: true))
            ->getJson(self::BASE_URL.'/'.$page->id)
            ->assertOk()
            ->json('data');

        $this->assertSame(['uuid' => $creator->uuid, 'name' => '길동이', 'real_name' => '홍길동'], $data['creator']);
        $this->assertSame(['uuid' => $updater->uuid, 'name' => '철수닉', 'real_name' => '김철수'], $data['updater']);
    }

    /**
     * 회원 조회 권한이 없는 관리자에게는 표시 이름만 가고 실명 키 자체가 없다.
     *
     * @scenario case=detail_nickname_without_member_read
     *
     * @effects page_creator_updater_name_follows_admin_area, page_real_name_hidden_without_member_read
     *
     * @return void 반환값 없음
     */
    public function test_detail_real_name_key_is_absent_without_member_read(): void
    {
        $this->nicknameAdminArea();
        $creator = $this->member('홍길동', '길동이');
        $page = $this->makePage('test-member-dn-noread', $creator, $creator);

        $data = $this->actingAs($this->viewer(withMemberRead: false))
            ->getJson(self::BASE_URL.'/'.$page->id)
            ->assertOk()
            ->json('data');

        $this->assertSame(['uuid' => $creator->uuid, 'name' => '길동이'], $data['creator']);
        $this->assertSame(['uuid' => $creator->uuid, 'name' => '길동이'], $data['updater']);
    }

    /**
     * 페이지 목록의 작성자 이름도 같은 규칙이다.
     *
     * @scenario case=list_nickname_with_member_read
     *
     * @effects page_list_creator_name_follows_admin_area
     *
     * @return void 반환값 없음
     */
    public function test_list_creator_follows_admin_area(): void
    {
        $this->nicknameAdminArea();
        $creator = $this->member('홍길동', '길동이');
        $page = $this->makePage('test-member-dn-list', $creator, $creator);

        $row = collect($this->actingAs($this->viewer(withMemberRead: true))
            ->getJson(self::BASE_URL.'?search_field=slug&search='.$page->slug.'&per_page=100')
            ->assertOk()
            ->json('data.data'))
            ->firstWhere('id', $page->id);

        $this->assertNotNull($row);
        $this->assertSame(['uuid' => $creator->uuid, 'name' => '길동이', 'real_name' => '홍길동'], $row['creator']);
    }

    /**
     * 버전 이력과 버전 상세의 작성자 이름도 같은 규칙이다.
     *
     * @scenario case=versions_nickname_with_member_read
     *
     * @effects page_version_creator_name_follows_admin_area
     *
     * @return void 반환값 없음
     */
    public function test_version_creator_follows_admin_area_on_list_and_detail(): void
    {
        $this->nicknameAdminArea();
        $creator = $this->member('홍길동', '길동이');
        $page = $this->makePage('test-member-dn-version', $creator, $creator);
        $version = $this->makeVersion($page, $creator);
        $viewer = $this->viewer(withMemberRead: true);

        $listed = collect($this->actingAs($viewer)->getJson(self::BASE_URL.'/'.$page->id.'/versions')->assertOk()->json('data'))
            ->firstWhere('id', $version->id);
        $detail = $this->actingAs($viewer)->getJson(self::BASE_URL.'/'.$page->id.'/versions/'.$version->id)->assertOk()->json('data');

        $expected = ['uuid' => $creator->uuid, 'name' => '길동이', 'real_name' => '홍길동'];
        $this->assertSame($expected, $listed['creator']);
        $this->assertSame($expected, $detail['creator']);
    }

    /**
     * 설정을 바꾸지 않은 사이트는 작성자와 수정자 이름이 수정 전과 같고 실명 키가 없다.
     *
     * @scenario case=default_site_unchanged
     *
     * @effects page_creator_name_unchanged_on_default_site, page_real_name_omitted_when_same
     *
     * @return void 반환값 없음
     */
    public function test_default_site_keeps_member_name_without_real_name_key(): void
    {
        $creator = $this->member('홍길동', '길동이');
        $page = $this->makePage('test-member-dn-default', $creator, $creator);
        $version = $this->makeVersion($page, $creator);
        $viewer = $this->viewer(withMemberRead: true);

        $data = $this->actingAs($viewer)->getJson(self::BASE_URL.'/'.$page->id)->assertOk()->json('data');
        $detail = $this->actingAs($viewer)->getJson(self::BASE_URL.'/'.$page->id.'/versions/'.$version->id)->assertOk()->json('data');

        $expected = ['uuid' => $creator->uuid, 'name' => '홍길동'];
        $this->assertSame($expected, $data['creator']);
        $this->assertSame($expected, $data['updater']);
        $this->assertSame($expected, $detail['creator']);
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
     * 작성자와 수정자를 정한 페이지를 만듭니다.
     *
     * @param  string  $slug  슬러그
     * @param  User  $creator  작성자
     * @param  User  $updater  수정자
     * @return Page 페이지
     */
    private function makePage(string $slug, User $creator, User $updater): Page
    {
        return Page::factory()->create([
            'slug' => $slug,
            'current_version' => 1,
            'created_by' => $creator->id,
            'updated_by' => $updater->id,
        ]);
    }

    /**
     * 작성자를 정한 페이지 버전을 만듭니다.
     *
     * @param  Page  $page  소속 페이지
     * @param  User  $creator  작성자
     * @return PageVersion 버전
     */
    private function makeVersion(Page $page, User $creator): PageVersion
    {
        return PageVersion::create([
            'page_id' => $page->id,
            'version' => 1,
            'title' => $page->title,
            'content' => $page->content,
            'content_mode' => $page->content_mode,
            'created_by' => $creator->id,
        ]);
    }

    /**
     * 페이지 관리 화면을 볼 수 있는 관리자를 만듭니다.
     *
     * 공유 `admin` 역할을 쓰지 않는다 — 기준 역할이 회원 조회 권한을 이미 가질 수 있어 「회원 조회가 없는 관리자」를 만들 수 없다.
     *
     * @param  bool  $withMemberRead  회원 조회 권한(`core.users.read`)을 줄지
     * @return User 관리자
     */
    private function viewer(bool $withMemberRead): User
    {
        $role = Role::query()->create([
            'identifier' => 'page-dn-'.Str::lower(Str::random(8)),
            'name' => ['ko' => '시험 역할', 'en' => 'Test Role'],
            'description' => ['ko' => '', 'en' => ''],
        ]);

        $identifiers = ['admin.access', 'sirsoft-page.pages.read'];
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
