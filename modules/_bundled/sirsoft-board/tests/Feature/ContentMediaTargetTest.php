<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

require_once __DIR__.'/../ModuleTestCase.php';

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 게시판 편집기 업로드 계약 표준 엔드포인트 `…/content-media/target` (#887)
 *
 * 편집 화면이 「무엇을 올릴 수 있나」를 받는 길을 확장마다 같은 모양(`GET …/content-media/target`)으로 맞췄다. 게시판은 사용자
 * `boards/{slug}/content-media/target`, 관리자 `admin/board/{slug}/content-media/target` 이고 권한은 업로드와 같다
 * (`attachments.upload` / `admin.attachments.upload`). 값은 그 게시판 본문 미디어 정책이다. 옛 길인 글쓰기 폼 메타
 * `content_media` 는 같은 값을 계속 싣는다(호환).
 *
 * @scenario-manifest modules/_bundled/sirsoft-board/tests/scenarios/content-media-target.yaml
 */
class ContentMediaTargetTest extends BoardTestCase
{
    /**
     * 이 시험이 쓰는 게시판 슬러그를 돌려줍니다.
     *
     * @return string 게시판 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'content-media-target';
    }

    /**
     * 이 시험 게시판의 기본 속성을 돌려줍니다 — 본문 미디어 정책 없음(첨부 정책 대체).
     *
     * @param  string  $slug  게시판 슬러그
     * @return array<string, mixed> 게시판 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '본문 미디어 계약', 'en' => 'Content Media Target'],
            'is_active' => true,
            'use_file_upload' => false,
            'content_media_policy' => null,
        ];
    }

    /**
     * 업로드 권한이 있는 회원은 계약을 받는다 — 업로드 주소·서빙 주소 정규식·정책이 실리고, 글쓰기 폼 메타 `content_media` 와 같다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=user, permission=granted
     *
     * @effects user_target_contract_shape, form_meta_alias_same_value
     */
    public function test_user_target_returns_contract_identical_to_form_meta(): void
    {
        $this->grantUserRolePermissions(['posts.read', 'posts.write', 'attachments.upload']);
        $member = $this->member('target-member@test.com');

        $target = $this->actingAs($member, 'sanctum')->getJson($this->userTargetUrl())->assertStatus(200)->json('data');

        $this->assertIsArray($target);
        $this->assertSame("/api/modules/sirsoft-board/boards/{$this->getTestBoardSlug()}/content-media", $target['endpoint']);
        $this->assertSame('file', $target['fileField']);
        $this->assertSame(1, preg_match('#'.$target['mediaUrlPattern'].'#', "/api/modules/sirsoft-board/boards/{$this->getTestBoardSlug()}/content-media/AbCdEf123456"), '서빙 주소 정규식은 이 게시판의 실제 서빙 주소에 맞는다');
        $this->assertArrayHasKey('policy', $target);
        $this->assertArrayHasKey('version', $target);

        $meta = $this->actingAs($member, 'sanctum')->getJson($this->postsUrl().'/form-meta')->assertStatus(200)->json('data.content_media');
        $this->assertSame($target, $meta, '옛 폼 메타 content_media 는 같은 값을 싣는다');
    }

    /**
     * 「파일 첨부 사용」을 꺼도 업로드 권한이 있으면 비회원도 계약을 받는다(#883 D26).
     *
     * @return void 반환값 없음
     *
     * @scenario surface=user, permission=granted
     *
     * @effects guest_target_with_upload_permission
     */
    public function test_guest_with_upload_permission_receives_contract(): void
    {
        $this->setGuestPermissions(['posts.read', 'posts.write', 'attachments.upload']);

        $this->getJson($this->userTargetUrl())
            ->assertStatus(200)
            ->assertJsonPath('data.endpoint', "/api/modules/sirsoft-board/boards/{$this->getTestBoardSlug()}/content-media");
    }

    /**
     * 업로드 권한이 없으면 계약을 주지 않는다 — 회원 403, 비회원 401. 폼 메타 `content_media` 는 null 이다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=user, permission=denied
     *
     * @effects target_requires_upload_permission
     */
    public function test_user_target_requires_upload_permission(): void
    {
        $this->setGuestPermissions(['posts.read', 'posts.write']);
        $this->grantUserRolePermissions(['posts.read', 'posts.write']);
        $member = $this->member('target-denied@test.com');

        $this->getJson($this->userTargetUrl())->assertStatus(401);
        $this->actingAs($member, 'sanctum')->getJson($this->userTargetUrl())->assertStatus(403);
        $this->actingAs($member, 'sanctum')->getJson($this->postsUrl().'/form-meta')
            ->assertStatus(200)
            ->assertJsonPath('data.content_media', null);
    }

    /**
     * 정책이 받는 종류가 하나도 없으면 계약은 null 이다(폼 메타도 null).
     *
     * @return void 반환값 없음
     *
     * @scenario surface=user, permission=granted
     *
     * @effects target_null_when_nothing_accepted
     */
    public function test_target_is_null_when_policy_accepts_nothing(): void
    {
        $this->updateBoardSettings(['content_media_policy' => [
            'image' => ['enabled' => false],
            'video' => ['enabled' => false],
            'audio' => ['enabled' => false],
        ]]);
        $this->grantUserRolePermissions(['posts.read', 'posts.write', 'attachments.upload']);
        $member = $this->member('target-nothing@test.com');

        $this->actingAs($member, 'sanctum')->getJson($this->userTargetUrl())
            ->assertStatus(200)
            ->assertJsonPath('data', null);
        $this->actingAs($member, 'sanctum')->getJson($this->postsUrl().'/form-meta')
            ->assertJsonPath('data.content_media', null);
    }

    /**
     * 관리자 계약은 관리자 업로드 주소를 싣고, 관리자 글쓰기 폼 메타 `content_media` 와 같다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=admin, permission=granted
     *
     * @effects admin_target_contract_shape, form_meta_alias_same_value
     */
    public function test_admin_target_returns_admin_endpoint_identical_to_form_meta(): void
    {
        $admin = $this->boardAdminWith(['admin.posts.read', 'admin.posts.write', 'admin.attachments.upload']);

        $target = $this->actingAs($admin)->getJson($this->adminTargetUrl())->assertStatus(200)->json('data');

        $this->assertIsArray($target);
        $this->assertSame("/api/modules/sirsoft-board/admin/board/{$this->getTestBoardSlug()}/content-media", $target['endpoint']);

        $meta = $this->actingAs($admin)
            ->getJson("/api/modules/sirsoft-board/admin/board/{$this->getTestBoardSlug()}/posts/form-meta")
            ->assertStatus(200)
            ->json('data.content_media');
        $this->assertSame($target, $meta, '옛 관리자 폼 메타 content_media 는 같은 값을 싣는다');
    }

    /**
     * 관리자 업로드 권한이 없으면 관리자 계약은 403 이다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=admin, permission=denied
     *
     * @effects target_requires_upload_permission
     */
    public function test_admin_target_requires_admin_upload_permission(): void
    {
        $admin = $this->boardAdminWith(['admin.posts.read', 'admin.posts.write']);

        $this->actingAs($admin)->getJson($this->adminTargetUrl())->assertStatus(403);
    }

    /**
     * 주어진 게시판 권한만 가진 관리자를 만듭니다(공유 admin 역할을 쓰지 않는다).
     *
     * @param  array<int, string>  $actions  게시판 권한 액션(예: admin.attachments.upload)
     * @return User 관리자
     */
    private function boardAdminWith(array $actions): User
    {
        $role = Role::create([
            'identifier' => 'board_target_'.uniqid(),
            'name' => ['ko' => '계약 역할', 'en' => 'Target Role'],
            'is_active' => true,
        ]);

        foreach ($actions as $action) {
            $identifier = "sirsoft-board.{$this->board->slug}.{$action}";
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                ['name' => ['ko' => $identifier, 'en' => $identifier], 'type' => 'admin']
            );
            $role->permissions()->attach($permission->id, ['scope_type' => null, 'granted_at' => now()]);
        }

        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach($role->id);
        $this->resetPermissionMiddlewareCache();

        return $user->fresh();
    }

    /**
     * 사용자 역할을 가진 회원을 만듭니다.
     *
     * @param  string  $email  이메일
     * @return User 사용자 역할 회원
     */
    private function member(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $role = Role::where('identifier', 'user')->first();
        if ($role) {
            $user->roles()->attach($role->id);
        }

        return $user;
    }

    /**
     * 사용자 계약 주소를 돌려줍니다.
     *
     * @return string 계약 주소
     */
    private function userTargetUrl(): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->getTestBoardSlug()}/content-media/target";
    }

    /**
     * 관리자 계약 주소를 돌려줍니다.
     *
     * @return string 계약 주소
     */
    private function adminTargetUrl(): string
    {
        return "/api/modules/sirsoft-board/admin/board/{$this->getTestBoardSlug()}/content-media/target";
    }

    /**
     * 사용자 글 주소를 돌려줍니다.
     *
     * @return string 글 주소
     */
    private function postsUrl(): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->getTestBoardSlug()}/posts";
    }
}
