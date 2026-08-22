<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

// 테스트 베이스 클래스 수동 require (autoload 전에 로드 필요)
require_once __DIR__.'/../ModuleTestCase.php';

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 블라인드 게시글/댓글 원문 접근 차단 테스트 (이슈 #413-34)
 *
 * 블라인드 처리된 게시글·댓글의 원문(content)은 게시판 관리자(manager/admin.manage)
 * 또는 작성자 본인만 열람할 수 있어야 한다. 그 외(비로그인·제3자)에게는
 * content 가 null 로 응답되어 '원글 보기' 버튼이 노출되지 않는다.
 *
 * 목록 제목·블라인드 안내·사유 문구는 그대로 노출(투명성) — 차단 대상은 원문뿐.
 *
 * @scenario board-blinded-original-access
 *
 * @effects published_content_visible_to_all,
 *          blinded_content_null_for_guest,
 *          blinded_content_null_for_other_member,
 *          blinded_content_visible_to_author,
 *          blinded_content_visible_to_manager,
 *          blinded_guest_authored_content_null_for_guest,
 *          blinded_post_preview_empty_in_list,
 *          blinded_comment_content_matrix
 */
class PostBlindedAccessControlTest extends BoardTestCase
{
    private User $managerUser;

    private User $regularUser;

    private User $postAuthor;

    /**
     * 테스트 게시판 slug
     *
     * @return string 게시판 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'blind-access-test';
    }

    /**
     * 기본 게시판 속성
     *
     * @param  string  $slug  게시판 슬러그
     * @return array<string, mixed> 게시판 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '블라인드 접근 테스트', 'en' => 'Blind Access Test'],
            'is_active' => true,
            'secret_mode' => 'disabled',
            'blocked_keywords' => [],
        ];
    }

    /**
     * 테스트 사전 준비를 수행합니다.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->managerUser = User::factory()->create();
        $this->regularUser = User::factory()->create();
        $this->postAuthor = User::factory()->create();

        // 비로그인/일반 회원이 상세를 조회하려면 posts.read 권한 필요
        $this->grantDefaultGuestPermissions();
        $this->grantUserRolePermissions(['posts.read', 'posts.write', 'comments.read', 'comments.write']);

        // factory 로 만든 사용자는 코어 'user' role 을 자동 보유하지 않으므로 명시적 부여
        // (regularUser·postAuthor 는 로그인 회원으로서 posts.read 권한이 필요)
        $userRole = Role::where('identifier', 'user')->first();
        if ($userRole) {
            $this->regularUser->roles()->syncWithoutDetaching([$userRole->id]);
            $this->postAuthor->roles()->syncWithoutDetaching([$userRole->id]);
            $this->managerUser->roles()->syncWithoutDetaching([$userRole->id]);
        }

        $this->setupManagerRole();
        $this->resetPermissionMiddlewareCache();
    }

    /**
     * 게시판 관리자(manager) 역할을 생성하고 managerUser 에 부여합니다.
     */
    private function setupManagerRole(): void
    {
        $slug = $this->board->slug;

        // manager 권한 + 상세 조회용 posts.read 부여
        $managerPermIds = [];
        foreach (['manager', 'posts.read'] as $action) {
            $perm = Permission::firstOrCreate(
                ['identifier' => "sirsoft-board.{$slug}.{$action}"],
                [
                    'name' => ['ko' => $action, 'en' => $action],
                    'slug' => "sirsoft-board.{$slug}.{$action}",
                    'type' => 'user',
                ]
            );
            $managerPermIds[] = $perm->id;
        }

        $managerRole = Role::firstOrCreate(
            ['identifier' => "{$slug}-manager"],
            ['name' => ['ko' => '게시판 관리(사용자)', 'en' => 'Board Manager']]
        );
        $managerRole->permissions()->syncWithoutDetaching($managerPermIds);
        $this->managerUser->roles()->attach($managerRole->id);
    }

    /**
     * User 상세 API 로 게시글 content 를 조회합니다.
     *
     * @param  int  $postId  게시글 ID
     * @param  User|null  $user  요청 사용자 (null 이면 비로그인)
     * @return array{status:int, content:mixed} 응답 상태와 content
     */
    private function fetchPostContent(int $postId, ?User $user = null): array
    {
        $this->resetPermissionMiddlewareCache();

        $request = $user
            ? $this->actingAs($user, 'sanctum')
            : $this;

        $response = $request->getJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}"
        );

        return [
            'status' => $response->status(),
            'content' => $response->json('data.content'),
        ];
    }

    // =========================================================================
    // 게시글 원문 차단 매트릭스 (계획서 7절 #1~9)
    // =========================================================================

    /**
     * 일반(published) 게시글은 비로그인에게도 원문이 노출된다. (회귀 방지)
     *
     * @scenario viewer=guest
     *
     * @effects published_content_visible_to_all
     */
    #[Test]
    public function published_post_content_is_visible_to_guest(): void
    {
        $postId = $this->createTestPost([
            'user_id' => $this->postAuthor->id,
            'content' => '공개 원문입니다.',
            'status' => 'published',
        ]);

        $result = $this->fetchPostContent($postId);

        $this->assertSame(200, $result['status']);
        $this->assertSame('공개 원문입니다.', $result['content']);
    }

    /**
     * 블라인드 게시글 원문은 비로그인에게 차단된다(null).
     *
     * @scenario viewer=guest
     *
     * @effects blinded_content_null_for_guest
     */
    #[Test]
    public function blinded_post_content_is_hidden_from_guest(): void
    {
        $postId = $this->createTestPost([
            'user_id' => $this->postAuthor->id,
            'content' => '블라인드 원문입니다.',
            'status' => 'blinded',
        ]);

        $result = $this->fetchPostContent($postId);

        $this->assertSame(200, $result['status']);
        $this->assertNull($result['content']);
    }

    /**
     * 블라인드 게시글 원문은 제3자(일반 회원)에게 차단된다(null).
     *
     * @scenario viewer=other_member
     *
     * @effects blinded_content_null_for_other_member
     */
    #[Test]
    public function blinded_post_content_is_hidden_from_other_member(): void
    {
        $postId = $this->createTestPost([
            'user_id' => $this->postAuthor->id,
            'content' => '블라인드 원문입니다.',
            'status' => 'blinded',
        ]);

        $result = $this->fetchPostContent($postId, $this->regularUser);

        $this->assertSame(200, $result['status']);
        $this->assertNull($result['content']);
    }

    /**
     * 블라인드 게시글 원문은 작성자 본인에게 노출된다.
     *
     * @scenario viewer=author
     *
     * @effects blinded_content_visible_to_author
     */
    #[Test]
    public function blinded_post_content_is_visible_to_author(): void
    {
        $postId = $this->createTestPost([
            'user_id' => $this->postAuthor->id,
            'content' => '블라인드 원문입니다.',
            'status' => 'blinded',
        ]);

        $result = $this->fetchPostContent($postId, $this->postAuthor);

        $this->assertSame(200, $result['status']);
        $this->assertSame('블라인드 원문입니다.', $result['content']);
    }

    /**
     * 블라인드 게시글 원문은 게시판 관리자(manager)에게 노출된다.
     *
     * @scenario viewer=manager
     *
     * @effects blinded_content_visible_to_manager
     */
    #[Test]
    public function blinded_post_content_is_visible_to_manager(): void
    {
        $postId = $this->createTestPost([
            'user_id' => $this->postAuthor->id,
            'content' => '블라인드 원문입니다.',
            'status' => 'blinded',
        ]);

        $result = $this->fetchPostContent($postId, $this->managerUser);

        $this->assertSame(200, $result['status']);
        $this->assertSame('블라인드 원문입니다.', $result['content']);
    }

    /**
     * 비회원 작성 블라인드 게시글은 비로그인에게 차단된다(본인 판정 불가).
     *
     * @scenario viewer=guest
     *
     * @effects blinded_guest_authored_content_null_for_guest
     */
    #[Test]
    public function blinded_guest_post_content_is_hidden_from_guest(): void
    {
        $postId = $this->createTestPost([
            'user_id' => null,
            'author_name' => '비회원',
            'content' => '비회원 블라인드 원문',
            'status' => 'blinded',
        ]);

        $result = $this->fetchPostContent($postId);

        $this->assertSame(200, $result['status']);
        $this->assertNull($result['content']);
    }

    /**
     * 블라인드 게시글은 목록 미리보기(content_preview)에 원문이 새지 않는다.
     *
     * @scenario viewer=guest
     *
     * @effects blinded_post_preview_empty_in_list
     */
    #[Test]
    public function blinded_post_preview_is_empty_in_list(): void
    {
        $this->createTestPost([
            'user_id' => $this->postAuthor->id,
            'title' => '블라인드 글',
            'content' => '목록에서 새면 안 되는 원문',
            'status' => 'blinded',
        ]);

        $this->resetPermissionMiddlewareCache();
        $response = $this->getJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts"
        );

        $response->assertStatus(200);

        $items = collect($response->json('data.data') ?? $response->json('data'))
            ->filter(fn ($item) => is_array($item) && ($item['status'] ?? null) === 'blinded');

        $this->assertNotEmpty($items, '블라인드 게시글이 목록에 존재해야 한다(제목 노출).');
        foreach ($items as $item) {
            $this->assertSame('', $item['content_preview'] ?? null);
        }
    }

    // =========================================================================
    // 댓글 원문 차단 매트릭스 (계획서 7절 #10)
    // =========================================================================

    /**
     * 블라인드 댓글 원문은 비로그인에게 차단되고, 작성자/관리자에게 노출된다.
     *
     * @scenario viewer=guest viewer=other_member viewer=author viewer=manager
     *
     * @effects blinded_comment_content_matrix
     */
    #[Test]
    public function blinded_comment_content_access_matrix(): void
    {
        $postId = $this->createTestPost([
            'user_id' => $this->postAuthor->id,
            'content' => '본문',
            'status' => 'published',
        ]);

        $commentId = $this->createTestComment($postId, [
            'user_id' => $this->postAuthor->id,
            'content' => '블라인드 댓글 원문',
            'status' => 'blinded',
        ]);

        // 비로그인 → 차단
        $guest = $this->fetchCommentContent($postId, $commentId);
        $this->assertNull($guest, '비로그인에게 블라인드 댓글 원문은 차단되어야 한다.');

        // 제3자 → 차단
        $other = $this->fetchCommentContent($postId, $commentId, $this->regularUser);
        $this->assertNull($other, '제3자에게 블라인드 댓글 원문은 차단되어야 한다.');

        // 작성자 본인 → 노출
        $author = $this->fetchCommentContent($postId, $commentId, $this->postAuthor);
        $this->assertSame('블라인드 댓글 원문', $author);

        // 관리자 → 노출
        $manager = $this->fetchCommentContent($postId, $commentId, $this->managerUser);
        $this->assertSame('블라인드 댓글 원문', $manager);
    }

    /**
     * User 상세 API 응답에서 특정 댓글의 content 를 추출합니다.
     *
     * @param  int  $postId  게시글 ID
     * @param  int  $commentId  댓글 ID
     * @param  User|null  $user  요청 사용자 (null 이면 비로그인)
     * @return mixed 댓글 content (없으면 null)
     */
    private function fetchCommentContent(int $postId, int $commentId, ?User $user = null): mixed
    {
        $this->resetPermissionMiddlewareCache();

        $request = $user
            ? $this->actingAs($user, 'sanctum')
            : $this;

        $response = $request->getJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}"
        );

        $response->assertStatus(200);

        $comment = collect($response->json('data.comments') ?? [])
            ->firstWhere('id', $commentId);

        return $comment['content'] ?? null;
    }
}
