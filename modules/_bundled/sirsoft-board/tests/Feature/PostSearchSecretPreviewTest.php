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
 * 게시판 목록/검색 응답의 비밀글·블라인드 본문 요약(content_preview) 차단 테스트 (이슈 #413-37)
 *
 * 게시판 내부 검색은 별도 엔드포인트가 아니라 목록 조회(index)에 search 필터를 얹은 것이다.
 * 비밀글(is_secret)·블라인드(status=blinded) 게시글은 목록/검색 응답에서 본문 요약이
 * 권한과 무관하게 빈 문자열로 차단되어야 한다 (확정 정책: 목록 미리보기는 권한자도 숨김).
 *
 * 제목은 현행 유지(노출), 차단 대상은 content_preview 뿐.
 * 상세 본문(content) 권한자 노출은 기존 정책(PostBlindedAccessControlTest)이 보장 — 본 테스트 범위 밖.
 *
 * @scenario board-search-secret-content-preview
 *
 * @effects published_preview_visible_in_list,
 *          secret_preview_empty_for_guest,
 *          secret_preview_empty_for_other_member,
 *          secret_preview_empty_for_author,
 *          secret_preview_empty_for_manager,
 *          secret_preview_empty_via_search_filter,
 *          blinded_preview_empty_in_list,
 *          secret_title_visible_in_list
 */
class PostSearchSecretPreviewTest extends BoardTestCase
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
        return 'search-secret-preview-test';
    }

    /**
     * 기본 게시판 속성 (비밀글 허용)
     *
     * @param  string  $slug  게시판 슬러그
     * @return array<string, mixed> 게시판 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '비밀글 미리보기 테스트', 'en' => 'Secret Preview Test'],
            'is_active' => true,
            'secret_mode' => 'enabled',
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

        // 비로그인/일반 회원이 목록을 조회하려면 posts.read 권한 필요
        $this->grantDefaultGuestPermissions();
        $this->grantUserRolePermissions(['posts.read', 'posts.write', 'comments.read', 'comments.write']);

        // factory 사용자는 코어 'user' role 을 자동 보유하지 않으므로 명시적 부여
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
     * (목록 조회용 posts.read 포함)
     */
    private function setupManagerRole(): void
    {
        $slug = $this->board->slug;

        $managerPermIds = [];
        foreach (['manager', 'posts.read', 'posts.read-secret'] as $action) {
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
     * 게시판 목록 API 에서 특정 게시글의 content_preview 를 조회합니다.
     *
     * @param  int  $postId  게시글 ID
     * @param  User|null  $user  요청 사용자 (null 이면 비로그인)
     * @param  string|null  $search  검색어 (null 이면 일반 목록)
     * @param  string|null  $searchField  검색 필드 (author 등)
     * @return mixed content_preview (없으면 null)
     */
    private function fetchListPreview(int $postId, ?User $user = null, ?string $search = null, ?string $searchField = null): mixed
    {
        $this->resetPermissionMiddlewareCache();

        $request = $user
            ? $this->actingAs($user, 'sanctum')
            : $this;

        $url = "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts";
        $params = [];
        if ($search !== null) {
            $params['search'] = $search;
        }
        if ($searchField !== null) {
            $params['search_field'] = $searchField;
        }
        if ($params !== []) {
            $url .= '?'.http_build_query($params);
        }

        $response = $request->getJson($url);
        $response->assertStatus(200);

        $item = collect($response->json('data.data') ?? $response->json('data'))
            ->firstWhere('id', $postId);

        return $item['content_preview'] ?? null;
    }

    // =========================================================================
    // 비밀글 미리보기 차단 매트릭스 (계획서 7절 #1~6)
    // =========================================================================

    /**
     * 일반(공개) 게시글은 목록 미리보기에 본문 요약이 노출된다. (회귀 방지)
     *
     * @scenario viewer=guest
     *
     * @effects published_preview_visible_in_list
     */
    #[Test]
    public function published_post_preview_is_visible_in_list(): void
    {
        $postId = $this->createTestPost([
            'user_id' => $this->postAuthor->id,
            'title' => '공개 글',
            'content' => '공개 미리보기 노출 본문',
            'is_secret' => false,
            'status' => 'published',
        ]);

        $preview = $this->fetchListPreview($postId);

        $this->assertNotSame('', $preview, '일반 글은 목록 미리보기가 노출되어야 한다.');
        $this->assertStringContainsString('공개 미리보기', (string) $preview);
    }

    /**
     * 비밀글 미리보기는 비로그인에게 차단된다(빈 문자열).
     *
     * @scenario viewer=guest
     *
     * @effects secret_preview_empty_for_guest
     */
    #[Test]
    public function secret_post_preview_is_empty_for_guest(): void
    {
        $postId = $this->createTestPost([
            'user_id' => $this->postAuthor->id,
            'title' => '비밀 글',
            'content' => '비밀글 미리보기 새면 안 되는 본문',
            'is_secret' => true,
            'status' => 'published',
        ]);

        $preview = $this->fetchListPreview($postId);

        $this->assertSame('', $preview, '비밀글 미리보기는 비로그인에게 차단되어야 한다.');
    }

    /**
     * 비밀글 미리보기는 제3자(일반 회원)에게 차단된다(빈 문자열).
     *
     * @scenario viewer=other_member
     *
     * @effects secret_preview_empty_for_other_member
     */
    #[Test]
    public function secret_post_preview_is_empty_for_other_member(): void
    {
        $postId = $this->createTestPost([
            'user_id' => $this->postAuthor->id,
            'title' => '비밀 글',
            'content' => '비밀글 미리보기 새면 안 되는 본문',
            'is_secret' => true,
            'status' => 'published',
        ]);

        $preview = $this->fetchListPreview($postId, $this->regularUser);

        $this->assertSame('', $preview, '비밀글 미리보기는 제3자에게 차단되어야 한다.');
    }

    /**
     * 비밀글 미리보기는 작성자 본인에게도 목록에서는 차단된다(빈 문자열).
     * (확정 정책: 목록 미리보기는 권한자도 숨김 — 본문은 상세에서만 노출)
     *
     * @scenario viewer=author
     *
     * @effects secret_preview_empty_for_author
     */
    #[Test]
    public function secret_post_preview_is_empty_for_author_in_list(): void
    {
        $postId = $this->createTestPost([
            'user_id' => $this->postAuthor->id,
            'title' => '비밀 글',
            'content' => '비밀글 미리보기 새면 안 되는 본문',
            'is_secret' => true,
            'status' => 'published',
        ]);

        $preview = $this->fetchListPreview($postId, $this->postAuthor);

        $this->assertSame('', $preview, '목록 미리보기는 작성자 본인에게도 숨겨야 한다.');
    }

    /**
     * 비밀글 미리보기는 게시판 관리자(manager)에게도 목록에서는 차단된다(빈 문자열).
     *
     * @scenario viewer=manager
     *
     * @effects secret_preview_empty_for_manager
     */
    #[Test]
    public function secret_post_preview_is_empty_for_manager_in_list(): void
    {
        $postId = $this->createTestPost([
            'user_id' => $this->postAuthor->id,
            'title' => '비밀 글',
            'content' => '비밀글 미리보기 새면 안 되는 본문',
            'is_secret' => true,
            'status' => 'published',
        ]);

        $preview = $this->fetchListPreview($postId, $this->managerUser);

        $this->assertSame('', $preview, '목록 미리보기는 관리자에게도 숨겨야 한다.');
    }

    /**
     * 검색 필터(search=) 경유 시에도 비밀글 미리보기가 차단된다.
     *
     * @scenario viewer=guest channel=search
     *
     * @effects secret_preview_empty_via_search_filter
     */
    #[Test]
    public function secret_post_preview_is_empty_via_search_filter(): void
    {
        // 작성자명 검색(LIKE 기반)으로 비밀글을 검색 결과에 포함시킨다.
        // (FULLTEXT 인덱스는 트랜잭션 격리 테스트에서 신규 행 매칭이 불안정하므로
        //  검색 매칭 자체가 아니라 "검색 경유 응답의 마스킹"을 안정적으로 검증)
        $authorKeyword = 'secretwriter37';
        $postId = $this->createTestPost([
            'user_id' => $this->postAuthor->id,
            'author_name' => $authorKeyword,
            'title' => '검색 경유 비밀 글',
            'content' => '비밀글 본문 미리보기 새면 안 됨',
            'is_secret' => true,
            'status' => 'published',
        ]);

        $preview = $this->fetchListPreview($postId, null, $authorKeyword, 'author');

        $this->assertSame('', $preview, '검색 결과에서도 비밀글 미리보기는 차단되어야 한다.');
    }

    // =========================================================================
    // 블라인드 회귀 + 제목 노출 (계획서 7절 #7~8)
    // =========================================================================

    /**
     * 블라인드 게시글은 목록 미리보기가 차단된다(34번 회귀 방지).
     *
     * @scenario viewer=guest
     *
     * @effects blinded_preview_empty_in_list
     */
    #[Test]
    public function blinded_post_preview_is_empty_in_list(): void
    {
        $postId = $this->createTestPost([
            'user_id' => $this->postAuthor->id,
            'title' => '블라인드 글',
            'content' => '블라인드 미리보기 새면 안 되는 본문',
            'is_secret' => false,
            'status' => 'blinded',
        ]);

        $preview = $this->fetchListPreview($postId);

        $this->assertSame('', $preview, '블라인드 게시글 미리보기는 차단되어야 한다.');
    }

    /**
     * 비밀글 제목은 목록에서 그대로 노출된다(현행 유지 — 차단 대상은 미리보기 뿐).
     *
     * @scenario viewer=guest
     *
     * @effects secret_title_visible_in_list
     */
    #[Test]
    public function secret_post_title_is_visible_in_list(): void
    {
        $this->resetPermissionMiddlewareCache();

        $postId = $this->createTestPost([
            'user_id' => $this->postAuthor->id,
            'title' => '비밀 제목 노출 확인',
            'content' => '비밀글 본문',
            'is_secret' => true,
            'status' => 'published',
        ]);

        $response = $this->getJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts"
        );
        $response->assertStatus(200);

        $item = collect($response->json('data.data') ?? $response->json('data'))
            ->firstWhere('id', $postId);

        $this->assertNotNull($item, '비밀글은 목록에 노출되어야 한다(식별 허용).');
        $this->assertSame('비밀 제목 노출 확인', $item['title'] ?? null);
        $this->assertSame('', $item['content_preview'] ?? null);
    }
}
