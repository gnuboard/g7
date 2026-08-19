<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Board;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Http\Middleware\PermissionMiddleware;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Board\Enums\PostStatus;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 공개 최근글/인기글의 열람 권한 필터 + 비밀글 가시성 테스트 (N-3 + N-4)
 *
 * - N-3: 공개 최근글(GET /boards/posts/recent)은 읽기 권한 없는 게시판의 글을
 *   익명/저권한 사용자에게 노출하면 안 된다. 비밀글은 제목 공개 정책(2026-02-04)에
 *   따라 제목을 노출한다(본문만 보호 — 목록/검색/홈과 동일).
 * - N-4: 공개 인기글(GET /boards/popular)도 읽기 권한 없는 게시판의 글을 제외한다.
 *   (인기글은 별도 컨벤션으로 비밀글 자체를 제외한다 — 2026-01-16.)
 */
class PublicRecentPostsVisibilityTest extends ModuleTestCase
{
    private Board $readableBoard;

    private Board $hiddenBoard;

    protected function setUp(): void
    {
        parent::setUp();

        Board::where('is_active', true)->update(['is_active' => false]);
        Cache::flush();

        $this->readableBoard = Board::factory()->create(['is_active' => true, 'name' => ['ko' => '공개', 'en' => 'Readable']]);
        $this->hiddenBoard = Board::factory()->create(['is_active' => true, 'name' => ['ko' => '비공개', 'en' => 'Hidden']]);

        // 읽기 가능한 게시판: 공개글 + 비밀글
        $this->insertPost($this->readableBoard->id, '공개글읽기가능', 100, false, PostStatus::Published->value);
        $this->insertPost($this->readableBoard->id, '비밀글읽기가능', 90, true, PostStatus::Published->value);
        // 읽기 불가능한 게시판: 공개글
        $this->insertPost($this->hiddenBoard->id, '숨김게시판글', 200, false, PostStatus::Published->value);

        // 비회원 읽기 권한은 readableBoard 에만 부여
        $this->grantRead('guest', $this->readableBoard->slug);
        PermissionMiddleware::clearGuestRoleCache();
    }

    // ========== N-3 최근글 ==========

    /**
     * @scenario case=recent_readable_filter
     *
     * @effects unreadable_board_titles_absent_for_caller
     */
    #[Test]
    public function guest_recent_posts_shows_readable_titles_including_secret_but_excludes_unreadable_board(): void
    {
        $titles = $this->titles($this->getJson('/api/modules/sirsoft-board/boards/posts/recent?limit=20'));

        $this->assertContains('공개글읽기가능', $titles);
        // 비밀글도 제목은 공개된다(본문만 보호) — 2026-02-04 확정 정책.
        $this->assertContains('비밀글읽기가능', $titles, '비밀글도 제목은 최근글에 노출된다(본문만 보호).');
        $this->assertNotContains('숨김게시판글', $titles, '읽기 권한 없는 게시판 글은 노출되면 안 된다.');
    }

    /**
     * @scenario case=recent_readable_filter
     *
     * @effects unreadable_board_titles_absent_for_caller
     */
    #[Test]
    public function low_permission_member_recent_posts_excludes_unreadable_board_titles(): void
    {
        $member = $this->memberWithReadOn($this->readableBoard->slug);

        $titles = $this->titles(
            $this->actingAs($member)->getJson('/api/modules/sirsoft-board/boards/posts/recent?limit=20')
        );

        $this->assertContains('공개글읽기가능', $titles);
        $this->assertContains('비밀글읽기가능', $titles, '비밀글도 제목은 노출된다(본문만 보호).');
        $this->assertNotContains('숨김게시판글', $titles, '읽기 권한 없는 게시판 글은 저권한 회원에게도 노출되면 안 된다.');
    }

    // ========== N-4 인기글 ==========

    /**
     * @scenario case=popular_readable_filter
     *
     * @effects unreadable_board_titles_absent_for_caller
     */
    #[Test]
    public function guest_popular_posts_excludes_unreadable_board_titles(): void
    {
        $titles = $this->titles($this->getJson('/api/modules/sirsoft-board/boards/popular?limit=20'));

        $this->assertContains('공개글읽기가능', $titles);
        $this->assertNotContains('숨김게시판글', $titles, '인기글도 읽기 권한 없는 게시판 글을 제외해야 한다.');
        // 인기글은 비밀글도 원래 제외한다(2026-01-16 컨벤션).
        $this->assertNotContains('비밀글읽기가능', $titles);
    }

    // ========== 게시판 디렉토리(boards index + 내장 최근글) ==========
    //
    // 최근글·인기글·인기게시판·대시보드에는 열람 권한 필터가 적용되었지만,
    // 게시판 디렉토리(GET /boards?limit=N)는 활성 게시판 전체 + 내장 최근글 제목을
    // 무필터로 직렬화해 우회로가 남아 있었다 (7.0.7 사전점검 SEO 봇 렌더 실측 발견 —
    // 게스트 홈 화면에 회원전용 게시판 글 제목이 노출).

    /**
     * @scenario case=board_directory_readable_filter
     *
     * @effects board_directory_excludes_unreadable_board_and_posts
     */
    #[Test]
    public function guest_board_directory_excludes_unreadable_board_and_its_post_titles(): void
    {
        $response = $this->getJson('/api/modules/sirsoft-board/boards?limit=3');
        $response->assertStatus(200);

        $slugs = array_column($response->json('data'), 'slug');
        $this->assertContains($this->readableBoard->slug, $slugs);
        $this->assertNotContains($this->hiddenBoard->slug, $slugs, '읽기 권한 없는 게시판은 디렉토리에 노출되면 안 된다.');

        // 내장 최근글 제목까지 전수 검사 — 게시판 행이 아닌 다른 경로로도 새면 안 된다
        $raw = json_encode($response->json('data'), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('공개글읽기가능', $raw);
        $this->assertStringNotContainsString('숨김게시판글', $raw, '읽기 권한 없는 게시판의 글 제목이 디렉토리 응답에 노출되면 안 된다.');
    }

    /**
     * @scenario case=board_directory_readable_filter
     *
     * @effects board_directory_includes_readable_board_for_permitted_member
     */
    #[Test]
    public function member_with_read_permission_sees_restricted_board_in_directory(): void
    {
        $member = $this->memberWithReadOn($this->hiddenBoard->slug);

        $response = $this->actingAs($member)->getJson('/api/modules/sirsoft-board/boards?limit=3');
        $response->assertStatus(200);

        $slugs = array_column($response->json('data'), 'slug');
        $this->assertContains($this->hiddenBoard->slug, $slugs, '열람 권한을 가진 회원에게는 해당 게시판이 디렉토리에 보여야 한다.');

        $raw = json_encode($response->json('data'), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('숨김게시판글', $raw, '열람 권한자는 그 게시판의 최근글 제목도 볼 수 있어야 한다.');
    }

    // ========== 헬퍼 ==========

    /**
     * @param  TestResponse  $response
     * @return array<int, string> 응답 데이터의 title 목록
     */
    private function titles($response): array
    {
        $response->assertStatus(200);

        return array_column($response->json('data'), 'title');
    }

    private function insertPost(int $boardId, string $title, int $viewCount, bool $isSecret, string $status): void
    {
        DB::table('board_posts')->insert([
            'board_id' => $boardId,
            'title' => $title,
            'content' => '내용',
            'author_name' => '작성자',
            'view_count' => $viewCount,
            'is_secret' => $isSecret,
            'status' => $status,
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function grantRead(string $roleIdentifier, string $slug): void
    {
        $role = Role::where('identifier', $roleIdentifier)->first();
        if (! $role) {
            return;
        }
        $perm = Permission::firstOrCreate(
            ['identifier' => "sirsoft-board.{$slug}.posts.read"],
            ['name' => ['ko' => 'read', 'en' => 'read'], 'type' => 'user']
        );
        $role->permissions()->syncWithoutDetaching([$perm->id]);
    }

    private function memberWithReadOn(string $slug): User
    {
        $member = User::factory()->create();
        $userRole = Role::where('identifier', 'user')->first();
        $member->roles()->attach($userRole->id);
        $this->grantRead('user', $slug);
        PermissionMiddleware::clearGuestRoleCache();

        return $member;
    }
}
