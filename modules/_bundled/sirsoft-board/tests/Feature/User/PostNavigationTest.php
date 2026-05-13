<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Enums\PostStatus;
use Modules\Sirsoft\Board\Enums\SecretMode;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 게시글 이전/다음 네비게이션 API 회귀 테스트 (이슈 #269)
 *
 * PostController::navigation() 이 어떤 상황에서도 500 응답을 내지 않고
 * graceful degradation 으로 `{prev, next}` 또는 404 만 반환하도록 보장합니다.
 *
 * @group board
 * @group board-navigation
 */
class PostNavigationTest extends ModuleTestCase
{
    private string $boardSlug = 'nav-test';

    private Board $board;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        DB::table('boards')->where('slug', $this->boardSlug)->delete();
        DB::table('boards')->where('slug', 'nav-test-inactive')->delete();

        $this->board = Board::factory()->create([
            'slug' => $this->boardSlug,
            'is_active' => true,
            'order_by' => 'created_at',
            'order_direction' => 'DESC',
            'secret_mode' => SecretMode::Disabled->value,
        ]);

        $this->ensureGuestCanReadPosts();
    }

    /**
     * navigation 라우트는 permission:user,posts.read 로 보호됩니다.
     * guest 역할에 해당 권한을 부여해 401 차단을 우회합니다.
     */
    private function ensureGuestCanReadPosts(): void
    {
        $identifier = 'sirsoft-board.'.$this->boardSlug.'.posts.read';
        $permId = DB::table('permissions')->where('identifier', $identifier)->value('id');

        if (! $permId) {
            $permId = DB::table('permissions')->insertGetId([
                'identifier' => $identifier,
                'name' => json_encode(['ko' => 'navigation 테스트 read', 'en' => 'nav-test read']),
                'type' => 'user',
                'extension_type' => 'module',
                'extension_identifier' => 'sirsoft-board',
                'resource_route_key' => 'post',
                'owner_key' => 'user_id',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $guestRoleId = DB::table('roles')->where('identifier', 'guest')->value('id');
        if (! $guestRoleId) {
            return;
        }

        DB::table('role_permissions')->updateOrInsert(
            ['role_id' => $guestRoleId, 'permission_id' => $permId],
            ['scope_type' => null, 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now()]
        );
    }

    protected function tearDown(): void
    {
        DB::table('board_posts')->where('board_id', $this->board->id)->delete();
        DB::table('boards')->whereIn('slug', [$this->boardSlug, 'nav-test-inactive'])->delete();
        parent::tearDown();
    }

    /**
     * 존재하지 않는 글 ID → 200 + 빈 값 (500 금지)
     */
    public function test_returns_empty_when_post_not_found(): void
    {
        $response = $this->getJson("/api/modules/sirsoft-board/boards/{$this->boardSlug}/posts/999999/navigation");

        $response->assertStatus(200);
        $response->assertJson([
            'data' => ['prev' => null, 'next' => null],
        ]);
    }

    /**
     * 공지글 → 200 + 빈 값 (네비게이션 미제공)
     */
    public function test_returns_empty_for_notice_post(): void
    {
        $noticeId = $this->insertPost([
            'is_notice' => true,
            'title' => '공지',
        ]);

        $response = $this->getJson("/api/modules/sirsoft-board/boards/{$this->boardSlug}/posts/{$noticeId}/navigation");

        $response->assertStatus(200);
        $response->assertJson([
            'data' => ['prev' => null, 'next' => null],
        ]);
    }

    /**
     * 일반글 양옆 글 없음 → 200 + 빈 값
     */
    public function test_returns_empty_when_no_adjacent_posts(): void
    {
        $postId = $this->insertPost(['title' => '혼자']);

        $response = $this->getJson("/api/modules/sirsoft-board/boards/{$this->boardSlug}/posts/{$postId}/navigation");

        $response->assertStatus(200);
        $response->assertJson([
            'data' => ['prev' => null, 'next' => null],
        ]);
    }

    /**
     * 일반글 + 이전/다음 글 존재 → 정상 반환 (created_at DESC 정렬)
     *
     * created_at DESC 정렬에서 prev는 created_at이 더 나중인 글(newer),
     * next는 created_at이 더 이전인 글(older).
     */
    public function test_returns_adjacent_posts_when_available(): void
    {
        $olderId = $this->insertPost(['title' => '이전글', 'created_at' => now()->subMinutes(30)]);
        $currentId = $this->insertPost(['title' => '현재글', 'created_at' => now()->subMinutes(20)]);
        $newerId = $this->insertPost(['title' => '최신글', 'created_at' => now()->subMinutes(10)]);

        $response = $this->getJson("/api/modules/sirsoft-board/boards/{$this->boardSlug}/posts/{$currentId}/navigation");

        $response->assertStatus(200);
        $response->assertJson([
            'data' => [
                'prev' => ['id' => $newerId],  // DESC 에서 prev = created_at 더 나중
                'next' => ['id' => $olderId],  // DESC 에서 next = created_at 더 이전
            ],
        ]);
    }

    /**
     * 비밀글(secret_mode=always)에서도 navigation 은 is_secret 필터를 적용하지 않고
     * 목록과 동일 범위로 prev/next 를 실제로 반환해야 합니다.
     *
     * getAdjacentPosts 쿼리에 is_secret 필터가 없음을 실증적으로 검증합니다.
     */
    public function test_secret_always_board_returns_real_prev_next(): void
    {
        DB::table('boards')->where('id', $this->board->id)->update([
            'secret_mode' => SecretMode::Always->value,
        ]);

        $olderId = $this->insertPost([
            'is_secret' => true,
            'title' => '비밀 이전글',
            'created_at' => now()->subMinutes(30),
        ]);
        $currentId = $this->insertPost([
            'is_secret' => true,
            'title' => '비밀 현재글',
            'created_at' => now()->subMinutes(20),
        ]);
        $newerId = $this->insertPost([
            'is_secret' => true,
            'title' => '비밀 최신글',
            'created_at' => now()->subMinutes(10),
        ]);

        $response = $this->getJson("/api/modules/sirsoft-board/boards/{$this->boardSlug}/posts/{$currentId}/navigation");

        $response->assertStatus(200);
        $response->assertJson([
            'data' => [
                'prev' => ['id' => $newerId],  // DESC
                'next' => ['id' => $olderId],
            ],
        ]);
    }

    /**
     * 핵심 회귀 방지: Controller 가 PostService::getPost 를 호출하지 않음을 실증합니다.
     *
     * beta.2 의 500 경로는 getPost 내부의 PermissionHelper::checkScopeAccess 가 false 를
     * 반환하면서 AccessDeniedHttpException 을 throw 할 때 발생했습니다. 수정 코드가
     * getPost 호출을 실제로 제거했는지 spy 로 검증합니다.
     */
    public function test_controller_does_not_invoke_getpost(): void
    {
        $postId = $this->insertPost(['title' => 'getPost 미호출 검증']);

        $postService = $this->spy(\Modules\Sirsoft\Board\Services\PostService::class);

        $response = $this->getJson("/api/modules/sirsoft-board/boards/{$this->boardSlug}/posts/{$postId}/navigation");

        $this->assertNotSame(500, $response->status());
        $postService->shouldNotHaveReceived('getPost');
    }

    /**
     * 핵심 회귀 방지: getAdjacentPosts 가 예외를 던져도 Controller 는 500 이 아닌
     * 200 + 빈 값 을 반환하고 Log::warning 을 한 번 호출해야 합니다.
     */
    public function test_controller_degrades_gracefully_when_getadjacent_throws(): void
    {
        $postId = $this->insertPost(['title' => '예외 유도']);

        \Illuminate\Support\Facades\Log::spy();

        $postService = $this->mock(\Modules\Sirsoft\Board\Services\PostService::class);
        $postService->shouldReceive('isPostNotice')->once()->andReturn(false);
        $postService->shouldReceive('getAdjacentPosts')->once()->andThrow(
            new \RuntimeException('boom')
        );

        $response = $this->getJson("/api/modules/sirsoft-board/boards/{$this->boardSlug}/posts/{$postId}/navigation");

        $response->assertStatus(200);
        $response->assertJson([
            'data' => ['prev' => null, 'next' => null],
        ]);

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function ($message, $context) use ($postId) {
                return str_contains($message, 'Post navigation')
                    && ($context['post_id'] ?? null) === $postId
                    && ($context['slug'] ?? null) === $this->boardSlug;
            });
    }

    /**
     * 정상 경로(예외 없음) 에서는 Log::warning 이 호출되지 않아야 합니다.
     */
    public function test_controller_does_not_log_warning_on_success(): void
    {
        $this->insertPost(['title' => '이전', 'created_at' => now()->subMinutes(20)]);
        $currentId = $this->insertPost(['title' => '현재', 'created_at' => now()->subMinutes(15)]);
        $this->insertPost(['title' => '최신', 'created_at' => now()->subMinutes(10)]);

        \Illuminate\Support\Facades\Log::spy();

        $response = $this->getJson("/api/modules/sirsoft-board/boards/{$this->boardSlug}/posts/{$currentId}/navigation");

        $response->assertStatus(200);
        \Illuminate\Support\Facades\Log::shouldNotHaveReceived('warning');
    }

    /**
     * [A-1] 존재하지 않는 게시판은 404 또는 permission 단계의 401 을 반환해야 합니다 (500 금지).
     */
    public function test_returns_error_for_nonexistent_board(): void
    {
        $response = $this->getJson('/api/modules/sirsoft-board/boards/non-existent-board/posts/1/navigation');

        $this->assertNotSame(500, $response->status(), '존재하지 않는 게시판에서 500 이 아니어야 합니다.');
        $this->assertContains($response->status(), [401, 403, 404], 'permission 또는 board-not-found 경로로 처리되어야 합니다.');
    }

    /**
     * [A-2] 비활성 게시판은 404 또는 permission 단계의 401 을 반환해야 합니다 (500 금지).
     */
    public function test_returns_error_for_inactive_board(): void
    {
        Board::factory()->create([
            'slug' => 'nav-test-inactive',
            'is_active' => false,
            'order_by' => 'created_at',
            'order_direction' => 'DESC',
            'secret_mode' => SecretMode::Disabled->value,
        ]);

        $response = $this->getJson('/api/modules/sirsoft-board/boards/nav-test-inactive/posts/1/navigation');

        $this->assertNotSame(500, $response->status(), '비활성 게시판에서 500 이 아니어야 합니다.');
        $this->assertContains($response->status(), [401, 403, 404]);
    }

    /**
     * [B-1] order_direction=ASC 에서 prev 는 created_at 더 이전 글, next 는 더 나중 글이어야 합니다.
     */
    public function test_asc_order_flips_prev_next_direction(): void
    {
        DB::table('boards')->where('id', $this->board->id)->update(['order_direction' => 'ASC']);

        $olderId = $this->insertPost(['title' => '이전글', 'created_at' => now()->subMinutes(30)]);
        $currentId = $this->insertPost(['title' => '현재글', 'created_at' => now()->subMinutes(20)]);
        $newerId = $this->insertPost(['title' => '최신글', 'created_at' => now()->subMinutes(10)]);

        $response = $this->getJson("/api/modules/sirsoft-board/boards/{$this->boardSlug}/posts/{$currentId}/navigation");

        $response->assertStatus(200);
        $response->assertJson([
            'data' => [
                'prev' => ['id' => $olderId],
                'next' => ['id' => $newerId],
            ],
        ]);
    }

    /**
     * [D] 일반글 사이에 공지글이 끼어 있어도 navigation 은 공지를 건너뛰어야 합니다.
     */
    public function test_notice_posts_excluded_from_navigation(): void
    {
        $olderId = $this->insertPost(['title' => '이전글', 'created_at' => now()->subMinutes(30)]);
        $this->insertPost(['title' => '공지', 'is_notice' => true, 'created_at' => now()->subMinutes(25)]);
        $currentId = $this->insertPost(['title' => '현재글', 'created_at' => now()->subMinutes(20)]);
        $this->insertPost(['title' => '공지2', 'is_notice' => true, 'created_at' => now()->subMinutes(15)]);
        $newerId = $this->insertPost(['title' => '최신글', 'created_at' => now()->subMinutes(10)]);

        $response = $this->getJson("/api/modules/sirsoft-board/boards/{$this->boardSlug}/posts/{$currentId}/navigation");

        $response->assertStatus(200);
        $response->assertJson([
            'data' => [
                'prev' => ['id' => $newerId],  // created_at DESC
                'next' => ['id' => $olderId],
            ],
        ]);
        $response->assertJsonMissingPath('data.prev.is_notice');
    }

    /**
     * [G] published 가 아닌(블라인드 등) 글은 navigation 에 포함되면 안 됩니다.
     */
    public function test_non_published_posts_excluded_from_navigation(): void
    {
        $olderId = $this->insertPost(['title' => '이전글', 'created_at' => now()->subMinutes(30)]);
        $this->insertPost([
            'title' => '블라인드글',
            'status' => PostStatus::Blinded->value,
            'created_at' => now()->subMinutes(25),
        ]);
        $currentId = $this->insertPost(['title' => '현재글', 'created_at' => now()->subMinutes(20)]);
        $this->insertPost([
            'title' => '삭제상태글',
            'status' => PostStatus::Deleted->value,
            'created_at' => now()->subMinutes(15),
        ]);
        $newerId = $this->insertPost(['title' => '최신글', 'created_at' => now()->subMinutes(10)]);

        $response = $this->getJson("/api/modules/sirsoft-board/boards/{$this->boardSlug}/posts/{$currentId}/navigation");

        $response->assertStatus(200);
        $response->assertJson([
            'data' => [
                'prev' => ['id' => $newerId],
                'next' => ['id' => $olderId],
            ],
        ]);
    }

    /**
     * [J-1] 가장 오래된 글(DESC 정렬에서 next 방향 끝)은 next 가 null 이어야 합니다.
     */
    public function test_oldest_post_has_no_next(): void
    {
        $oldestId = $this->insertPost(['title' => '가장 오래된 글', 'created_at' => now()->subMinutes(30)]);
        $this->insertPost(['title' => '중간글', 'created_at' => now()->subMinutes(20)]);
        $this->insertPost(['title' => '최신글', 'created_at' => now()->subMinutes(10)]);

        $response = $this->getJson("/api/modules/sirsoft-board/boards/{$this->boardSlug}/posts/{$oldestId}/navigation");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertNotNull($data['prev'], 'DESC 정렬 첫글 기준 prev 는 더 이전(older) 이 없으므로 next 가 null 이어야 합니다.');
        $this->assertNull($data['next'], 'DESC 정렬에서 가장 오래된 글의 next 는 null 이어야 합니다.');
    }

    /**
     * [J-2] 가장 최신 글(DESC 정렬에서 prev 방향 끝)은 prev 가 null 이어야 합니다.
     */
    public function test_newest_post_has_no_prev(): void
    {
        $this->insertPost(['title' => '이전글', 'created_at' => now()->subMinutes(30)]);
        $this->insertPost(['title' => '중간글', 'created_at' => now()->subMinutes(20)]);
        $newestId = $this->insertPost(['title' => '최신글', 'created_at' => now()->subMinutes(10)]);

        $response = $this->getJson("/api/modules/sirsoft-board/boards/{$this->boardSlug}/posts/{$newestId}/navigation");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertNull($data['prev'], 'DESC 정렬에서 가장 최신 글의 prev 는 null 이어야 합니다.');
        $this->assertNotNull($data['next']);
    }

    /**
     * board_posts 레코드를 직접 INSERT 합니다.
     *
     * @param  array  $overrides  오버라이드할 속성
     * @return int 생성된 post id
     */
    private function insertPost(array $overrides = []): int
    {
        $defaults = [
            'board_id' => $this->board->id,
            'title' => '테스트글',
            'content' => '본문',
            'content_mode' => 'text',
            'author_name' => '작성자',
            'ip_address' => '127.0.0.1',
            'is_notice' => false,
            'is_secret' => false,
            'status' => PostStatus::Published->value,
            'view_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        return DB::table('board_posts')->insertGetId(array_merge($defaults, $overrides));
    }
}
