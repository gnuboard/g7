<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Search;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Http\Middleware\PermissionMiddleware;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 검색 필터/인기 게시판 목록의 게시판별 읽기 권한 필터 테스트 (⑦ + N-2)
 *
 * - ⑦: 통합 검색의 available_boards(필터 드롭다운)는 읽기 권한(posts.read)을 통과한
 *   게시판만 담아야 한다 — getActiveBoardsListForFilter($user) 가 검색 결과 필터와
 *   동일한 게이트를 목록 구성에도 적용한다.
 * - N-2: 공개 인기 게시판 목록(GET /boards/popular-boards)도 호출자 읽기 권한을
 *   통과한 게시판만 노출한다.
 */
class AvailableBoardsPermissionTest extends ModuleTestCase
{
    private Board $readableBoard;

    private Board $hiddenBoard;

    protected function setUp(): void
    {
        parent::setUp();

        // 다른 테스트가 남긴 활성 게시판 잡음 제거 + 캐시 초기화
        Board::where('is_active', true)->update(['is_active' => false]);
        Cache::flush();

        $this->readableBoard = Board::factory()->create([
            'is_active' => true,
            'name' => ['ko' => '공개 게시판', 'en' => 'Readable Board'],
        ]);
        $this->hiddenBoard = Board::factory()->create([
            'is_active' => true,
            'name' => ['ko' => '비공개 게시판', 'en' => 'Hidden Board'],
        ]);

        // 비회원 읽기 권한은 readableBoard 에만 부여한다.
        $this->grantGuestRead($this->readableBoard);

        PermissionMiddleware::clearGuestRoleCache();
    }

    /**
     * ⑦ getActiveBoardsListForFilter 는 비회원에게 읽기 권한 없는 게시판을 제외한다.
     *
     * @scenario case=available_boards_guest
     *
     * @effects available_boards_match_read_permission
     */
    #[Test]
    public function available_boards_excludes_boards_guest_cannot_read(): void
    {
        $list = app(BoardService::class)->getActiveBoardsListForFilter(null);

        $slugs = array_column($list, 'slug');

        $this->assertContains($this->readableBoard->slug, $slugs, '읽기 권한 있는 게시판은 필터 목록에 포함되어야 한다.');
        $this->assertNotContains($this->hiddenBoard->slug, $slugs, '읽기 권한 없는 게시판은 필터 목록에서 제외되어야 한다.');
    }

    /**
     * ⑦ 회원 기준으로도 읽기 권한 있는 게시판만 필터 목록에 포함된다.
     *
     * @scenario case=available_boards_member
     *
     * @effects available_boards_match_read_permission
     */
    #[Test]
    public function available_boards_filters_by_member_read_permission(): void
    {
        $member = User::factory()->create();
        $userRole = Role::where('identifier', 'user')->first();
        $member->roles()->attach($userRole->id);

        // 회원 역할에 readableBoard 읽기 권한만 부여
        $perm = Permission::firstOrCreate(
            ['identifier' => "sirsoft-board.{$this->readableBoard->slug}.posts.read"],
            ['name' => ['ko' => 'read', 'en' => 'read'], 'type' => 'user']
        );
        $userRole->permissions()->syncWithoutDetaching([$perm->id]);
        PermissionMiddleware::clearGuestRoleCache();

        $list = app(BoardService::class)->getActiveBoardsListForFilter($member);

        $slugs = array_column($list, 'slug');

        $this->assertContains($this->readableBoard->slug, $slugs);
        $this->assertNotContains($this->hiddenBoard->slug, $slugs);
    }

    /**
     * N-2 공개 인기 게시판 목록은 비회원 읽기 권한을 통과한 게시판만 노출한다.
     *
     * @scenario case=popular_boards_readable
     *
     * @effects popular_boards_exclude_unreadable
     */
    #[Test]
    public function popular_boards_endpoint_excludes_boards_guest_cannot_read(): void
    {
        $response = $this->getJson('/api/modules/sirsoft-board/boards/popular-boards?limit=20');
        $response->assertStatus(200);

        $slugs = array_column($response->json('data'), 'slug');

        $this->assertContains($this->readableBoard->slug, $slugs, '읽기 권한 있는 게시판은 인기 게시판 목록에 포함되어야 한다.');
        $this->assertNotContains($this->hiddenBoard->slug, $slugs, '읽기 권한 없는 게시판은 인기 게시판 목록에서 제외되어야 한다.');
    }

    /**
     * ⑦ HTTP 검색 엔드포인트가 Bearer 회원을 해석해 available_boards 에 반영한다.
     *
     * 라우트에 optional.sanctum 이 없으면 Bearer 토큰이 있어도 $request->user() 가
     * null 이라 모든 호출자가 guest 로 판정된다 — 서비스 직접 호출 테스트로는 이
     * 사각이 보이지 않으므로 실제 HTTP 경로로 검증한다 (actingAs 금지: actingAs 는
     * 미들웨어 없이도 유저를 심어 라이브와 어긋난다).
     *
     * @scenario case=available_boards_bearer_http
     *
     * @effects search_route_resolves_bearer_user, available_boards_match_read_permission
     */
    #[Test]
    public function search_endpoint_resolves_bearer_user_for_available_boards(): void
    {
        $member = User::factory()->create();
        $userRole = Role::where('identifier', 'user')->first();
        $member->roles()->attach($userRole->id);

        // 회원 역할에 두 게시판 읽기 권한 부여 — hiddenBoard 는 회원 역할에만 있으므로
        // (guest 미보유) Bearer 유저가 해석될 때만 목록에 나타난다.
        foreach ([$this->hiddenBoard, $this->readableBoard] as $board) {
            $perm = Permission::firstOrCreate(
                ['identifier' => "sirsoft-board.{$board->slug}.posts.read"],
                ['name' => ['ko' => 'read', 'en' => 'read'], 'type' => 'user']
            );
            $userRole->permissions()->syncWithoutDetaching([$perm->id]);
        }
        PermissionMiddleware::clearGuestRoleCache();

        $token = $member->createToken('search-bearer-test')->plainTextToken;

        $response = $this->getJson('/api/search?q=테스트', ['Authorization' => 'Bearer '.$token]);
        $response->assertStatus(200);

        $slugs = array_column($response->json('data.posts.available_boards') ?? [], 'slug');

        $this->assertContains(
            $this->hiddenBoard->slug,
            $slugs,
            'Bearer 인증 회원의 읽기 권한이 available_boards 에 반영되어야 한다 (검색 라우트 optional.sanctum).'
        );
        $this->assertContains($this->readableBoard->slug, $slugs);
    }

    /**
     * 게시판에 비회원(guest) 읽기 권한(posts.read)을 부여합니다.
     *
     * @param  Board  $board  대상 게시판
     */
    private function grantGuestRead(Board $board): void
    {
        $guestRole = Role::where('identifier', 'guest')->first();
        if (! $guestRole) {
            return;
        }

        $perm = Permission::firstOrCreate(
            ['identifier' => "sirsoft-board.{$board->slug}.posts.read"],
            ['name' => ['ko' => 'read', 'en' => 'read'], 'type' => 'user']
        );
        $guestRole->permissions()->syncWithoutDetaching([$perm->id]);
    }
}
