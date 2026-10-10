<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Services\BoardPermissionService;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 사용자 글쓰기 경로의 특권 필드 권한 검사 테스트
 *
 * 검증 목적:
 * - 게시판 관리 권한도 공지 등록 권한(posts.write-notice)도 없는 회원이 is_notice 를 보내면
 *   작성·수정 모두 403 으로 거부되고 DB 가 바뀌지 않는다 (커뮤니티 제보 본건)
 * - 수정 경로는 값이 바뀔 때만 판정한다 — 작성자가 기존 공지 값을 그대로 되돌려 보내는 것은 통과
 * - 공지 등록 권한 보유자, 게시판 관리자·스텝 역할(자동 주입)은 공지를 등록할 수 있다
 * - 사용자가 status 로 blinded/deleted 를 지정할 수 없다 (게시글·댓글)
 * - 비밀글이 꺼진 게시판에서 수정으로 비밀글 전환을 할 수 없고, 필수 게시판에서는 해제할 수 없다
 * - 회원이 보낸 author_name 은 저장되지 않는다
 * - 글쓰기 폼 메타가 공지 등록 권한을 노출해 화면과 서버 판정이 맞는다
 */
#[Group('board')]
#[Group('security')]
class PostNoticePermissionTest extends BoardTestCase
{
    /** 게시판 권한이 기본(user 역할)뿐인 회원 */
    private User $member;

    /**
     * 테스트 게시판 슬러그를 반환합니다.
     *
     * @return string 게시판 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'post-notice-permission';
    }

    /**
     * 테스트 게시판 기본 속성을 반환합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @return array<string, mixed> 게시판 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '공지 권한 게시판', 'en' => 'Notice Permission Board'],
            'is_active' => true,
            'use_comment' => true,
            'secret_mode' => 'enabled',
            'blocked_keywords' => [],
        ];
    }

    /**
     * 회원 역할에 글·댓글 권한을 주고 기본 회원을 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->grantUserRolePermissions(['posts.read', 'posts.write', 'comments.read', 'comments.write']);
        $this->member = $this->createMember('notice-member@test.com');
    }

    /**
     * 권한 없는 회원이 공지로 작성하면 403 이고 글 행이 남지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=member_store_notice_denied
     *
     * @effects notice_request_forbidden_without_permission, no_post_row_created_on_denied_store
     */
    public function test_member_without_permission_cannot_store_notice(): void
    {
        $this->actingAs($this->member)
            ->postJson($this->postsUrl(), $this->postPayload(['title' => '공지 위장 글', 'is_notice' => true]))
            ->assertStatus(403)
            ->assertJsonPath('message', __('sirsoft-board::messages.permission.notice_denied'));

        $this->assertDatabaseMissing('board_posts', ['board_id' => $this->board->id, 'title' => '공지 위장 글']);
    }

    /**
     * 권한 없는 회원이 자기 일반글을 공지로 바꾸면 403 이고 값이 그대로다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=member_update_notice_denied
     *
     * @effects notice_request_forbidden_without_permission, post_notice_flag_unchanged_on_denied_update
     */
    public function test_member_without_permission_cannot_turn_own_post_into_notice(): void
    {
        $postId = $this->createTestPost(['user_id' => $this->member->id, 'author_name' => null]);

        $this->actingAs($this->member)
            ->putJson($this->postsUrl($postId), ['is_notice' => true])
            ->assertStatus(403)
            ->assertJsonPath('message', __('sirsoft-board::messages.permission.notice_denied'));

        $this->assertFalse((bool) DB::table('board_posts')->where('id', $postId)->value('is_notice'));
    }

    /**
     * 권한 없는 작성자는 권한자가 지정한 공지를 해제할 수도 없다 (등록·해제 모두 권한 대상).
     *
     * @return void 반환값 없음
     *
     * @scenario case=member_unset_notice_denied
     *
     * @effects notice_request_forbidden_without_permission, post_notice_flag_unchanged_on_denied_update
     */
    public function test_member_without_permission_cannot_unset_notice(): void
    {
        $postId = $this->createTestPost(['user_id' => $this->member->id, 'author_name' => null, 'is_notice' => true]);

        $this->actingAs($this->member)
            ->putJson($this->postsUrl($postId), ['is_notice' => false])
            ->assertStatus(403);

        $this->assertTrue((bool) DB::table('board_posts')->where('id', $postId)->value('is_notice'));
    }

    /**
     * 작성자가 기존 공지 값을 그대로 되돌려 보내는 수정은 통과한다.
     *
     * 수정 폼은 서버가 준 기존 값을 통째로 되돌려 보내므로, 이것까지 막으면 권한자가 공지로
     * 지정한 글을 작성자가 고칠 수 없게 된다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=author_resends_existing_notice
     *
     * @effects unchanged_notice_value_passes_for_author
     */
    public function test_author_can_edit_notice_post_when_value_is_unchanged(): void
    {
        $postId = $this->createTestPost(['user_id' => $this->member->id, 'author_name' => null, 'is_notice' => true]);

        $this->actingAs($this->member)
            ->putJson($this->postsUrl($postId), ['title' => '제목만 고친 공지', 'is_notice' => true])
            ->assertStatus(200);

        $row = DB::table('board_posts')->where('id', $postId)->first();
        $this->assertSame('제목만 고친 공지', $row->title);
        $this->assertTrue((bool) $row->is_notice);
    }

    /**
     * 공지 등록 권한만 받은 회원은 공지로 작성할 수 있다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=notice_holder_store_notice
     *
     * @effects notice_permission_holder_can_set_notice
     */
    public function test_notice_permission_holder_can_store_notice(): void
    {
        $holder = $this->createMember('notice-holder@test.com');
        $holderRole = Role::create(['identifier' => 'notice-holder', 'name' => ['ko' => '공지 담당', 'en' => 'Notice Holder']]);
        $permission = Permission::firstOrCreate(
            ['identifier' => "sirsoft-board.{$this->board->slug}.posts.write-notice"],
            ['name' => ['ko' => '공지 등록', 'en' => 'Create Notice Posts'], 'type' => 'user']
        );
        $holderRole->permissions()->attach($permission->id);
        $holder->roles()->attach($holderRole->id);

        $response = $this->actingAs($holder)
            ->postJson($this->postsUrl(), $this->postPayload(['title' => '담당자 공지', 'is_notice' => true]))
            ->assertStatus(201);

        $this->assertTrue((bool) DB::table('board_posts')->where('id', $response->json('data.id'))->value('is_notice'));
    }

    /**
     * 게시판 관리자 역할은 게시판 권한 생성 경로의 자동 주입으로 공지를 등록할 수 있다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=manager_store_notice
     *
     * @effects board_manager_role_injected_with_notice_permission, notice_permission_holder_can_set_notice
     */
    public function test_board_manager_role_can_store_notice(): void
    {
        $manager = $this->createBoardRoleMember('manager', 'notice-manager@test.com');

        $this->assertTrue(
            Role::where('identifier', "sirsoft-board.{$this->board->slug}.manager")->first()
                ->permissions()->where('identifier', "sirsoft-board.{$this->board->slug}.posts.write-notice")->exists(),
            '게시판 관리자 역할에 공지 등록 권한이 자동 주입되어야 합니다.'
        );

        $this->actingAs($manager)
            ->postJson($this->postsUrl(), $this->postPayload(['title' => '관리자 공지', 'is_notice' => true]))
            ->assertStatus(201);

        $this->assertDatabaseHas('board_posts', ['board_id' => $this->board->id, 'title' => '관리자 공지', 'is_notice' => true]);
    }

    /**
     * 게시판 스텝 역할은 관리 권한이 없어도 공지 등록 권한 자동 주입으로 공지를 등록할 수 있다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=step_store_notice
     *
     * @effects board_step_role_injected_with_notice_permission, notice_permission_holder_can_set_notice
     */
    public function test_board_step_role_can_store_notice(): void
    {
        $step = $this->createBoardRoleMember('step', 'notice-step@test.com');

        $stepRole = Role::where('identifier', "sirsoft-board.{$this->board->slug}.step")->first();
        $this->assertFalse(
            $stepRole->permissions()->where('identifier', "sirsoft-board.{$this->board->slug}.manager")->exists(),
            '스텝 역할은 게시판 관리 권한이 없어야 이 테스트가 공지 등록 권한 경로를 검증합니다.'
        );

        $this->actingAs($step)
            ->postJson($this->postsUrl(), $this->postPayload(['title' => '스텝 공지', 'is_notice' => true]))
            ->assertStatus(201);

        $this->assertDatabaseHas('board_posts', ['board_id' => $this->board->id, 'title' => '스텝 공지', 'is_notice' => true]);
    }

    /**
     * 비회원의 공지 요청은 기존대로 검증 단계에서 422 로 거부된다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=guest_store_notice_rejected
     *
     * @effects guest_notice_rejected_by_validation
     */
    public function test_guest_notice_request_is_rejected_by_validation(): void
    {
        $this->postJson($this->postsUrl(), $this->postPayload([
            'title' => '비회원 공지 시도',
            'author_name' => '비회원',
            'password' => 'secret1234',
            'is_notice' => true,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['is_notice']);

        $this->assertDatabaseMissing('board_posts', ['board_id' => $this->board->id, 'title' => '비회원 공지 시도']);
    }

    /**
     * 공지 값을 보내지 않거나 false 로 보낸 일반 작성은 영향을 받지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=member_store_plain
     *
     * @effects plain_post_unaffected
     */
    public function test_plain_post_is_unaffected(): void
    {
        $this->actingAs($this->member)
            ->postJson($this->postsUrl(), $this->postPayload(['title' => '공지 값 없음']))
            ->assertStatus(201);

        $this->actingAs($this->member)
            ->postJson($this->postsUrl(), $this->postPayload(['title' => '공지 값 false', 'is_notice' => false]))
            ->assertStatus(201);

        $this->assertSame(2, DB::table('board_posts')->where('board_id', $this->board->id)->where('is_notice', false)->count());
    }

    /**
     * 회원이 status 로 blinded·deleted 를 지정해 작성하면 422 다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=member_store_blinded_rejected
     *
     * @effects privileged_status_rejected_by_validation
     */
    public function test_member_cannot_store_post_with_privileged_status(): void
    {
        foreach (['blinded', 'deleted'] as $status) {
            $this->actingAs($this->member)
                ->postJson($this->postsUrl(), $this->postPayload(['title' => "상태 {$status} 시도", 'status' => $status]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['status']);
        }

        $this->assertSame(0, DB::table('board_posts')->where('board_id', $this->board->id)->count());
    }

    /**
     * 회원이 댓글 작성·수정에서 status 로 blinded 를 지정하면 422 이고 댓글 상태가 그대로다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=member_comment_blinded_rejected
     *
     * @effects comment_privileged_status_rejected_by_validation
     */
    public function test_member_cannot_set_privileged_status_on_comment(): void
    {
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId, ['user_id' => $this->member->id, 'author_name' => null]);

        $this->actingAs($this->member)
            ->postJson($this->commentsUrl($postId), ['content' => '블라인드 상태로 쓰는 댓글', 'status' => 'blinded'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->actingAs($this->member)
            ->putJson($this->commentsUrl($postId, $commentId), ['content' => '스스로 블라인드하는 댓글', 'status' => 'blinded'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->assertSame('published', DB::table('board_comments')->where('id', $commentId)->value('status'));
        $this->assertSame(1, DB::table('board_comments')->where('post_id', $postId)->count());
    }

    /**
     * 비밀글이 꺼진 게시판에서 일반글을 수정으로 비밀글로 바꾸면 403 이다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=member_update_secret_on_disabled_board_denied
     *
     * @effects secret_toggle_forbidden_on_disabled_board
     */
    public function test_member_cannot_turn_post_secret_on_disabled_board(): void
    {
        $this->updateBoardSettings(['secret_mode' => 'disabled']);
        $postId = $this->createTestPost(['user_id' => $this->member->id, 'author_name' => null]);

        $this->actingAs($this->member)
            ->putJson($this->postsUrl($postId), ['is_secret' => true])
            ->assertStatus(403);

        $this->assertFalse((bool) DB::table('board_posts')->where('id', $postId)->value('is_secret'));
    }

    /**
     * 비밀글을 끄기 전에 쓴 비밀글은 같은 값이 다시 와도 수정할 수 있다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=existing_secret_resent_on_disabled_board
     *
     * @effects existing_secret_value_passes_on_disabled_board
     */
    public function test_existing_secret_post_can_be_edited_after_secret_mode_disabled(): void
    {
        $postId = $this->createTestPost(['user_id' => $this->member->id, 'author_name' => null, 'is_secret' => true]);
        $this->updateBoardSettings(['secret_mode' => 'disabled']);

        $this->actingAs($this->member)
            ->putJson($this->postsUrl($postId), ['title' => '예전 비밀글 수정', 'is_secret' => true])
            ->assertStatus(200);

        $this->assertSame('예전 비밀글 수정', DB::table('board_posts')->where('id', $postId)->value('title'));
    }

    /**
     * 비밀글 필수 게시판에서 수정으로 비밀글을 해제하려 해도 비밀글로 유지된다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=always_board_update_keeps_secret
     *
     * @effects secret_forced_on_always_board
     */
    public function test_secret_stays_on_always_board_when_update_unsets_it(): void
    {
        $this->updateBoardSettings(['secret_mode' => 'always']);
        $postId = $this->createTestPost(['user_id' => $this->member->id, 'author_name' => null, 'is_secret' => true]);

        $this->actingAs($this->member)
            ->putJson($this->postsUrl($postId), ['is_secret' => false])
            ->assertStatus(200);

        $this->assertTrue((bool) DB::table('board_posts')->where('id', $postId)->value('is_secret'));
    }

    /**
     * 회원이 author_name 을 보내도 저장되지 않는다 — 회원 글의 작성자명은 계정 이름이 SSoT.
     *
     * @return void 반환값 없음
     *
     * @scenario case=member_author_name_ignored
     *
     * @effects member_author_name_not_persisted
     */
    public function test_member_author_name_is_not_persisted(): void
    {
        $response = $this->actingAs($this->member)
            ->postJson($this->postsUrl(), $this->postPayload(['title' => '작성자명 위장', 'author_name' => '관리자']))
            ->assertStatus(201);

        $row = DB::table('board_posts')->where('id', $response->json('data.id'))->first();
        $this->assertNull($row->author_name, '회원 글에 요청의 author_name 이 저장되면 작성자 검색·정렬이 오염됩니다.');
        $this->assertSame($this->member->id, (int) $row->user_id);
    }

    /**
     * 글쓰기 폼 메타의 can_write_notice 가 공지 등록 권한 보유 여부를 따른다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=form_meta_exposes_notice_ability
     *
     * @effects notice_ability_follows_notice_permission
     */
    public function test_form_meta_exposes_notice_ability(): void
    {
        $step = $this->createBoardRoleMember('step', 'form-meta-step@test.com');

        $stepAbilities = $this->actingAs($step)
            ->getJson($this->postsUrl().'/form-meta')
            ->assertStatus(200)
            ->json('data.board.user_abilities');

        $this->assertTrue($stepAbilities['can_write_notice'] ?? null, '스텝은 공지 옵션을 볼 수 있어야 합니다.');
        $this->assertFalse($stepAbilities['can_manage'] ?? null, '스텝은 게시판 관리 권한이 없습니다.');

        $memberAbilities = $this->actingAs($this->member)
            ->getJson($this->postsUrl().'/form-meta')
            ->assertStatus(200)
            ->json('data.board.user_abilities');

        $this->assertFalse($memberAbilities['can_write_notice'] ?? null, '권한 없는 회원에게 공지 옵션이 보이면 안 됩니다.');
    }

    /**
     * user 역할을 가진 회원을 만듭니다.
     *
     * @param  string  $email  이메일
     * @return User 생성된 회원
     */
    private function createMember(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->roles()->attach(Role::where('identifier', 'user')->value('id'));

        return $user;
    }

    /**
     * 게시판 관리자·스텝 역할을 만들고 게시판 권한 생성 경로로 권한을 주입한 뒤, 지정 역할 회원을 만듭니다.
     *
     * 실제 게시판 생성과 같은 BoardPermissionService::ensureBoardPermissions() 를 거치므로
     * defaults.json 기본값 + 역할 자동 주입(injectBoardRoles)이 그대로 검증됩니다.
     *
     * @param  string  $kind  역할 종류 (manager | step)
     * @param  string  $email  이메일
     * @return User 해당 게시판 역할을 가진 회원
     */
    private function createBoardRoleMember(string $kind, string $email): User
    {
        foreach (['manager', 'step'] as $roleKind) {
            Role::firstOrCreate(
                ['identifier' => "sirsoft-board.{$this->board->slug}.{$roleKind}"],
                ['name' => ['ko' => "게시판 {$roleKind}", 'en' => "Board {$roleKind}"]]
            );
        }

        app(BoardPermissionService::class)->ensureBoardPermissions($this->board->fresh());

        $user = $this->createMember($email);
        $user->roles()->attach(Role::where('identifier', "sirsoft-board.{$this->board->slug}.{$kind}")->value('id'));

        return $user;
    }

    /**
     * 게시글 작성 요청 본문을 만듭니다.
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 필드
     * @return array<string, mixed> 요청 본문
     */
    private function postPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => '테스트 제목',
            'content' => '테스트 본문은 열 글자 이상이어야 합니다.',
        ], $overrides);
    }

    /**
     * 사용자 게시글 API URL 을 만듭니다.
     *
     * @param  int|null  $postId  게시글 ID (없으면 목록·작성 URL)
     * @return string 게시글 API URL
     */
    private function postsUrl(?int $postId = null): string
    {
        $base = "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts";

        return $postId === null ? $base : "{$base}/{$postId}";
    }

    /**
     * 사용자 댓글 API URL 을 만듭니다.
     *
     * @param  int  $postId  게시글 ID
     * @param  int|null  $commentId  댓글 ID (없으면 목록·작성 URL)
     * @return string 댓글 API URL
     */
    private function commentsUrl(int $postId, ?int $commentId = null): string
    {
        $base = "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/comments";

        return $commentId === null ? $base : "{$base}/{$commentId}";
    }
}
