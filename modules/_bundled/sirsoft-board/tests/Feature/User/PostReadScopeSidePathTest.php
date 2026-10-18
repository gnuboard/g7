<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Enums\ScopeType;
use App\Helpers\PermissionHelper;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Scout\EngineManager;
use Modules\Sirsoft\Board\Database\Seeders\BoardReactionTypeSeeder;
use Modules\Sirsoft\Board\Models\ReactionType;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 게시글 읽기 스코프의 부가 경로 재적용 테스트 (#789 §6.3 B10~B14)
 *
 * `posts.read`·`comments.read` 는 스코프 메타(`resource_route_key = post|comment`, `owner_key = user_id`)를
 * 갖지만 게시판 라우트는 `{postId}`·`{commentId}` 를 써서 미들웨어 스코프 검사가 돌지 않는다.
 * 상세·목록은 서비스·저장소가 스코프를 다시 걸지만, 그 밖의 경로(신고·스크랩·반응, 최근글·인기글,
 * 공개 프로필, 이전/다음 글)는 보유만 보고 범위 밖 글을 다뤘다.
 *
 * 열람자: 「게시글 조회 = 본인만」 회원(reader). 대상: 다른 회원의 글·댓글. 대조군: 본인 글.
 * 시나리오 축·효과는 매니페스트 tests/scenarios/board-scoped-permission-reapply.yaml 참조.
 */
#[Group('board')]
#[Group('scope')]
class PostReadScopeSidePathTest extends BoardTestCase
{
    private User $reader;

    private User $other;

    private int $ownPostId;

    private int $otherPostId;

    private int $otherCommentId;

    private int $likeId;

    /**
     * 이 테스트가 쓰는 게시판 slug 를 돌려줍니다.
     *
     * @return string 게시판 slug
     */
    protected function getTestBoardSlug(): string
    {
        return 'read-scope-side';
    }

    /**
     * 이 테스트에 맞춘 게시판 속성을 반환합니다.
     *
     * @param  string  $slug  게시판 slug
     * @return array 게시판 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '읽기 스코프 부가 경로', 'en' => 'Read Scope Side Paths'],
            'is_active' => true,
            'use_comment' => true,
            'use_report' => true,
            'use_scrap' => true,
            'secret_mode' => 'disabled',
            'blocked_keywords' => [],
        ];
    }

    /**
     * 열람자(본인만)·다른 회원·글·댓글·반응 유형을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        PermissionHelper::clearPermissionScopeCache();
        Cache::flush();

        // 비회원은 이 게시판을 읽지 못한다 — 회원 판정만 본다
        $this->setGuestPermissions([]);

        DB::table('board_reactions')->delete();
        ReactionType::query()->delete();
        $this->seed(BoardReactionTypeSeeder::class);
        $this->likeId = (int) ReactionType::query()->where('code', 'like')->value('id');
        $this->updateBoardSettings([
            'use_reaction' => true,
            'use_comment_reaction' => true,
            'active_reaction_types' => ['like', 'dislike'],
        ]);

        // 다른 회원 — user 역할(게시글·댓글 조회 전체)
        $this->grantScoped(Role::where('identifier', 'user')->first(), null);
        $this->other = $this->createUser();

        // 열람자 — 게시글·댓글 조회 = 본인만
        $readerRole = Role::create([
            'identifier' => 'read-scope-side-reader',
            'name' => ['ko' => '본인 글만', 'en' => 'Own posts only'],
            'is_active' => true,
        ]);
        $this->grantScoped($readerRole, ScopeType::Self);
        $this->reader = User::factory()->create();
        $this->reader->roles()->attach($readerRole->id);

        $this->resetPermissionMiddlewareCache();

        $this->otherPostId = $this->createTestPost([
            'title' => 'OTHER-POST-TITLE',
            'content' => 'OTHER-POST-BODY',
            'user_id' => $this->other->id,
            'author_name' => 'other',
            'created_at' => now()->subMinutes(10),
            'view_count' => 100,
        ]);
        $this->ownPostId = $this->createTestPost([
            'title' => 'OWN-POST-TITLE',
            'content' => 'OWN-POST-BODY',
            'user_id' => $this->reader->id,
            'author_name' => 'reader',
            'created_at' => now()->subMinutes(5),
            'view_count' => 50,
        ]);
        $this->otherCommentId = $this->createTestComment($this->otherPostId, [
            'user_id' => $this->other->id,
            'author_name' => 'other',
        ]);
    }

    /**
     * 역할에 이 게시판의 읽기 권한을 스코프 메타와 함께 줍니다.
     *
     * @param  Role  $role  대상 역할
     * @param  ScopeType|null  $scope  스코프 (null = 전체)
     * @return void 반환값 없음
     */
    private function grantScoped(Role $role, ?ScopeType $scope): void
    {
        $meta = [
            'posts.read' => 'post',
            'comments.read' => 'comment',
        ];

        foreach ($meta as $key => $routeKey) {
            $permission = Permission::updateOrCreate(
                ['identifier' => "sirsoft-board.{$this->board->slug}.{$key}"],
                [
                    'name' => ['ko' => $key, 'en' => $key],
                    'type' => 'user',
                    'resource_route_key' => $routeKey,
                    'owner_key' => 'user_id',
                ]
            );
            $role->permissions()->syncWithoutDetaching([$permission->id => ['scope_type' => $scope?->value]]);
        }
    }

    /**
     * 게시판 하위 API 주소.
     *
     * @param  string  $path  하위 경로
     * @return string 주소
     */
    private function url(string $path): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->board->slug}{$path}";
    }

    // ==========================================
    // 전제 — 상세는 이미 스코프를 건다 (오탐 무회귀)
    // ==========================================

    /**
     * 상세는 남의 글을 403 으로 막고 본인 글은 연다 (기존 동작 고정).
     *
     * @scenario case=detail_scope_baseline_kept
     *
     * @effects detail_scope_baseline_kept
     *
     * @return void 반환값 없음
     */
    public function test_detail_scope_baseline(): void
    {
        $this->actingAs($this->reader, 'sanctum')->getJson($this->url("/posts/{$this->otherPostId}"))->assertStatus(403);
        $this->actingAs($this->reader, 'sanctum')->getJson($this->url("/posts/{$this->ownPostId}"))->assertOk();
    }

    // ==========================================
    // B10 — 글 대상 신고·스크랩·반응
    // ==========================================

    /**
     * 범위 밖 글 신고는 403 이고 신고가 남지 않는다.
     *
     * @scenario case=out_of_scope_post_report_forbidden
     *
     * @effects out_of_scope_post_report_forbidden
     *
     * @return void 반환값 없음
     */
    public function test_cannot_report_out_of_scope_post(): void
    {
        $this->actingAs($this->reader, 'sanctum')
            ->postJson($this->url("/posts/{$this->otherPostId}/reports"), ['reason_type' => 'spam', 'reason_detail' => '범위 밖 신고'])
            ->assertStatus(403);

        $this->assertSame(0, DB::table('boards_reports')->where('board_id', $this->board->id)->count());
    }

    /**
     * 범위 밖 글 스크랩은 403 이고 스크랩이 남지 않는다. 본인 글 스크랩은 된다(대조군).
     *
     * @scenario case=out_of_scope_post_scrap_forbidden
     *
     * @effects out_of_scope_post_scrap_forbidden, own_post_scrap_allowed
     *
     * @return void 반환값 없음
     */
    public function test_cannot_scrap_out_of_scope_post_but_can_scrap_own(): void
    {
        $this->actingAs($this->reader, 'sanctum')
            ->postJson($this->url("/posts/{$this->otherPostId}/scrap/toggle"))
            ->assertStatus(403);
        $this->assertDatabaseMissing('board_post_scraps', ['user_id' => $this->reader->id, 'post_id' => $this->otherPostId]);

        $this->actingAs($this->reader, 'sanctum')
            ->postJson($this->url("/posts/{$this->ownPostId}/scrap/toggle"))
            ->assertOk();
    }

    /**
     * 범위 밖 글 반응은 403 이고 반응이 남지 않는다.
     *
     * @scenario case=out_of_scope_post_react_forbidden
     *
     * @effects out_of_scope_post_react_forbidden
     *
     * @return void 반환값 없음
     */
    public function test_cannot_react_to_out_of_scope_post(): void
    {
        $this->actingAs($this->reader, 'sanctum')
            ->postJson($this->url("/posts/{$this->otherPostId}/react"), ['reaction_type_id' => $this->likeId])
            ->assertStatus(403);

        $this->assertDatabaseMissing('board_reactions', ['user_id' => $this->reader->id]);
    }

    // ==========================================
    // B11 — 댓글 대상 신고·반응
    // ==========================================

    /**
     * 범위 밖 댓글 신고·반응은 403 이다.
     *
     * @scenario case=out_of_scope_comment_report_react_forbidden
     *
     * @effects out_of_scope_comment_report_react_forbidden
     *
     * @return void 반환값 없음
     */
    public function test_cannot_report_or_react_to_out_of_scope_comment(): void
    {
        $this->actingAs($this->reader, 'sanctum')
            ->postJson($this->url("/comments/{$this->otherCommentId}/reports"), ['reason_type' => 'spam', 'reason_detail' => '범위 밖 댓글'])
            ->assertStatus(403);
        $this->actingAs($this->reader, 'sanctum')
            ->postJson($this->url("/comments/{$this->otherCommentId}/react"), ['reaction_type_id' => $this->likeId])
            ->assertStatus(403);

        $this->assertSame(0, DB::table('boards_reports')->where('board_id', $this->board->id)->count());
        $this->assertDatabaseMissing('board_reactions', ['user_id' => $this->reader->id]);
    }

    // ==========================================
    // B12 — 최근글·인기글
    // ==========================================

    /**
     * 최근 게시글에서 범위 밖 글이 빠지고 본인 글은 남는다. 소유자 표시 키는 응답에 나가지 않는다.
     *
     * @scenario case=recent_posts_filtered_by_scope
     *
     * @effects recent_posts_filtered_by_scope
     *
     * @return void 반환값 없음
     */
    public function test_recent_posts_exclude_out_of_scope_posts(): void
    {
        $response = $this->actingAs($this->reader, 'sanctum')
            ->getJson('/api/modules/sirsoft-board/boards/posts/recent?limit=10');

        $response->assertOk();
        $titles = array_column($response->json('data'), 'title');
        $this->assertContains('OWN-POST-TITLE', $titles);
        $this->assertNotContains('OTHER-POST-TITLE', $titles);
        foreach ($response->json('data') as $item) {
            $this->assertArrayNotHasKey('_owner_id', $item);
        }
    }

    /**
     * 인기 게시글에서 범위 밖 글이 빠진다.
     *
     * @scenario case=popular_posts_filtered_by_scope
     *
     * @effects popular_posts_filtered_by_scope
     *
     * @return void 반환값 없음
     */
    public function test_popular_posts_exclude_out_of_scope_posts(): void
    {
        $response = $this->actingAs($this->reader, 'sanctum')
            ->getJson('/api/modules/sirsoft-board/boards/popular?limit=10');

        $response->assertOk();
        $titles = array_column($response->json('data'), 'title');
        $this->assertContains('OWN-POST-TITLE', $titles);
        $this->assertNotContains('OTHER-POST-TITLE', $titles);
    }

    // ==========================================
    // B13 — 게시판 디렉터리 내장 최근글
    // ==========================================

    /**
     * 게시판 디렉터리(목록 + 게시판별 최근글)의 내장 최근글에서 범위 밖 글이 빠지고 본인 글은 남는다.
     *
     * @scenario case=directory_recent_posts_filtered_by_scope
     *
     * @effects directory_recent_posts_filtered_by_scope
     *
     * @return void 반환값 없음
     */
    public function test_directory_recent_posts_exclude_out_of_scope_posts(): void
    {
        $response = $this->actingAs($this->reader, 'sanctum')
            ->getJson('/api/modules/sirsoft-board/boards?limit=10');

        $response->assertOk();
        $entry = collect($response->json('data'))->firstWhere('slug', $this->board->slug);
        $this->assertNotNull($entry, '열람자가 읽을 수 있는 게시판은 디렉터리에 나와야 한다');
        $titles = array_column($entry['recent_posts'] ?? [], 'title');
        $this->assertContains('OWN-POST-TITLE', $titles);
        $this->assertNotContains('OTHER-POST-TITLE', $titles);
    }

    // ==========================================
    // B13 — 공개 프로필·통합검색
    // ==========================================

    /**
     * 다른 회원의 공개 프로필 작성글·통계에서 범위 밖 게시판 글이 빠진다.
     *
     * @scenario case=public_profile_filtered_by_scope
     *
     * @effects public_profile_filtered_by_scope
     *
     * @return void 반환값 없음
     */
    public function test_public_profile_excludes_out_of_scope_posts(): void
    {
        $posts = $this->actingAs($this->reader, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/users/{$this->other->uuid}/posts");
        $posts->assertOk();
        $this->assertNotContains('OTHER-POST-TITLE', array_column($posts->json('data.data') ?? [], 'title'));

        $stats = $this->actingAs($this->reader, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/users/{$this->other->uuid}/posts/stats");
        $stats->assertOk();
        $this->assertSame(0, (int) $stats->json('data.posts_count'));
    }

    /**
     * 통합검색 결과와 건수에서 범위 밖 글이 빠진다.
     *
     * FULLTEXT 매칭 건수는 InnoDB 전문검색 캐시 상태에 좌우되어 이 계층에서 단언할 수 없다
     * (SearchCursorPaginationTest 와 같은 이유) — 검색 엔진 해석을 실패시켜 LIKE 폴백으로 돌리고
     * 범위 판정만 본다. 범위가 없으면 두 글(OWN·OTHER)이 모두 맞는 검색어다.
     *
     * @scenario case=search_filtered_by_scope
     *
     * @effects search_filtered_by_scope
     *
     * @return void 반환값 없음
     */
    public function test_search_excludes_out_of_scope_posts(): void
    {
        $this->mock(EngineManager::class, fn ($mock) => $mock->shouldReceive('engine')->andThrow(new \RuntimeException('LIKE 폴백 강제')));
        $token = $this->reader->createToken('read-scope-side')->plainTextToken;

        $response = $this->getJson('/api/search?q=TITLE&type=posts', ['Authorization' => 'Bearer '.$token]);

        $response->assertOk();
        $titles = array_column($response->json('data.posts.items') ?? [], 'title');
        $this->assertNotContains('OTHER-POST-TITLE', array_map('strip_tags', $titles));
        $this->assertSame(1, (int) $response->json('data.posts.total'));
    }

    // ==========================================
    // B14 — 이전/다음 글
    // ==========================================

    /**
     * 본인 글의 이전/다음 글에 범위 밖 글이 나오지 않는다.
     *
     * @scenario case=navigation_filtered_by_scope
     *
     * @effects navigation_filtered_by_scope
     *
     * @return void 반환값 없음
     */
    public function test_navigation_skips_out_of_scope_neighbors(): void
    {
        $response = $this->actingAs($this->reader, 'sanctum')
            ->getJson($this->url("/posts/{$this->ownPostId}/navigation"));

        $response->assertOk();
        $this->assertNull($response->json('data.prev'));
        $this->assertNull($response->json('data.next'));
    }
}
