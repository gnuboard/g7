<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Console;

use App\Http\Middleware\PermissionMiddleware;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * playwright:seed-board-posts — 사용자 화면 이동 링크 E2E 가 잴 「두 쪽인 공개 게시판」과 그 회원을 워커별로 세우고 지운다 (#757).
 *
 * `user-navigation-links.spec.ts` 의 게시판 시험 셋(게시글 행 · 2쪽 번호 · 글쓰기)은 설치본의 기존 게시판에 기대 건너뛰었다.
 * 이 커맨드가 쪽당 2건 · 글 3건 게시판과 기본 `user` 역할 회원을 만든다. 브라우저가 보는 판정은 **글 목록 응답의
 * 쓰기 권한**이므로 여기서도 그 응답으로 잰다 — 역할 없는 토큰 계정은 「회원」이 아니라 쓰기 갈래를 잴 수 없었다(M1 실측).
 *
 * 시나리오 매니페스트: `modules/_bundled/sirsoft-board/tests/scenarios/playwright-board-posts-seed.yaml`
 */
class PlaywrightSeedBoardPostsCommandTest extends ModuleTestCase
{
    /**
     * 옵트인 플래그를 켭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['G7_PLAYWRIGHT_BYPASS'] = '1';
        $_ENV['G7_PLAYWRIGHT_BYPASS'] = '1';
        putenv('G7_PLAYWRIGHT_BYPASS=1');
    }

    /**
     * 옵트인 플래그를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        unset($_SERVER['G7_PLAYWRIGHT_BYPASS'], $_ENV['G7_PLAYWRIGHT_BYPASS']);
        putenv('G7_PLAYWRIGHT_BYPASS');

        parent::tearDown();
    }

    /**
     * 커맨드를 JSON 모드로 실행하고 결과를 돌려줍니다.
     *
     * @param  array<string, mixed>  $options  커맨드 옵션
     * @return array{0: int, 1: array<string, mixed>} [종료 코드, JSON 결과]
     */
    private function runSeed(array $options): array
    {
        $code = Artisan::call('playwright:seed-board-posts', $options + ['--json' => true]);
        $lines = array_values(array_filter(
            preg_split('/\R/', trim(Artisan::output())) ?: [],
            fn (string $l) => str_starts_with(trim($l), '{')
        ));

        return [$code, $lines === [] ? [] : (json_decode(end($lines), true) ?? [])];
    }

    /**
     * 글 목록 응답을 그 요청 주체로 읽습니다.
     *
     * @param  string  $slug  게시판 slug
     * @param  string|null  $token  회원 토큰(없으면 비로그인)
     * @return TestResponse 응답
     */
    private function listPosts(string $slug, ?string $token = null)
    {
        // 앞 요청이 해석한 사용자가 다음 요청에 남지 않게 한다
        $this->app['auth']->forgetGuards();
        PermissionMiddleware::clearGuestRoleCache();

        return $this->getJson(
            "/api/modules/sirsoft-board/boards/{$slug}/posts",
            $token === null ? [] : ['Authorization' => 'Bearer '.$token]
        );
    }

    /**
     * 한 태그의 시드 행 수를 셉니다. (게시판·글·게시판별 권한·회원)
     *
     * @param  string  $tag  워커별 꼬리표
     * @return array<string, int> 행 수
     */
    private function countsFor(string $tag): array
    {
        $slug = 'e2e-nav-'.$tag;
        $boardIds = Board::query()->where('slug', $slug)->pluck('id');

        return [
            'boards' => $boardIds->count(),
            'posts' => Post::query()->whereIn('board_id', $boardIds)->count(),
            'permissions' => Permission::query()->where('identifier', 'like', "sirsoft-board.{$slug}.%")->count(),
            'members' => User::query()->where('email', $slug.'@example.test')->count(),
        ];
    }

    /**
     * 시드 게시판은 쪽당 2건 · 글 3건(두 쪽)이고, 시드 회원은 쓰기 링크 갈래 · 비로그인은 읽기만 하는 단추 갈래다.
     *
     * @scenario op=seed, tag=valid
     *
     * @effects seeds_two_page_public_board, seeds_member_who_can_write, guest_reads_but_cannot_write
     *
     * @return void 반환값 없음
     */
    public function test_seed_builds_a_two_page_board_the_member_can_write_to(): void
    {
        [$code, $seed] = $this->runSeed(['--tag' => 'w0t1']);

        $this->assertSame(0, $code, Artisan::output());
        $this->assertSame('e2e-nav-w0t1', $seed['board_slug']);
        $this->assertSame(2, $seed['per_page']);
        $this->assertSame(2, $seed['last_page']);
        $this->assertCount(3, $seed['post_ids']);

        $board = Board::query()->where('slug', 'e2e-nav-w0t1')->firstOrFail();
        $this->assertSame(2, (int) $board->per_page);
        $this->assertSame(2, (int) $board->per_page_mobile);

        // 회원은 실제 가입처럼 기본 user 역할 하나를 가진다 — 역할 없는 계정은 게시판 읽기부터 막힌다
        $member = User::query()->where('email', $seed['member_email'])->firstOrFail();
        $this->assertSame(['user'], $member->roles()->pluck('identifier')->all());
        $this->assertNotSame('', (string) $seed['member_token']);

        $guest = $this->listPosts($seed['board_slug']);
        $guest->assertOk();
        $this->assertFalse((bool) $guest->json('data.abilities.can_write'), '비로그인은 글쓰기 단추 갈래여야 한다');

        $asMember = $this->listPosts($seed['board_slug'], $seed['member_token']);
        $asMember->assertOk();
        $this->assertTrue((bool) $asMember->json('data.abilities.can_write'), '시드 회원은 글쓰기 링크 갈래여야 한다');
    }

    /**
     * 같은 태그로 다시 시드하면 앞선 게시판·글·회원을 지우고 한 벌만 남긴다.
     *
     * @scenario op=reseed, tag=valid
     *
     * @effects reseed_same_tag_replaces_previous_set
     *
     * @return void 반환값 없음
     */
    public function test_reseed_with_the_same_tag_leaves_one_set(): void
    {
        [, $first] = $this->runSeed(['--tag' => 'w0t2']);
        [$code, $second] = $this->runSeed(['--tag' => 'w0t2']);

        $this->assertSame(0, $code, Artisan::output());
        $counts = $this->countsFor('w0t2');
        $this->assertSame([1, 3, 1], [$counts['boards'], $counts['posts'], $counts['members']]);
        $this->assertGreaterThan(0, $counts['permissions'], '게시판별 권한이 다시 붙어야 한다');
        $this->assertSame(0, Post::query()->whereIn('id', $first['post_ids'])->count(), '앞선 시드의 글이 남았다');
        $this->assertNotSame($first['member_token'], $second['member_token']);
    }

    /**
     * 태그를 준 정리는 그 태그의 게시판·글·권한·회원만 지우고 다른 워커의 시드는 남긴다.
     *
     * @scenario op=teardown, tag=valid
     *
     * @effects teardown_by_tag_keeps_other_tags
     *
     * @return void 반환값 없음
     */
    public function test_teardown_by_tag_keeps_other_tags(): void
    {
        $this->runSeed(['--tag' => 'w1t3']);
        $this->runSeed(['--tag' => 'w2t3']);
        $other = $this->countsFor('w2t3');

        [$code, $result] = $this->runSeed(['--tag' => 'w1t3', '--teardown' => true]);

        $this->assertSame(0, $code, Artisan::output());
        $this->assertSame(['boards' => 0, 'posts' => 0, 'permissions' => 0, 'members' => 0], $this->countsFor('w1t3'));
        $this->assertSame($other, $this->countsFor('w2t3'));
        $this->assertSame(1, $result['deleted_users']);
        $this->assertSame(3, $result['deleted_posts']);
    }

    /**
     * 태그 없는 정리는 이 커맨드가 만든 `e2e-nav-*` 게시판·회원만 전부 지우고 다른 게시판·회원은 건드리지 않는다.
     *
     * @scenario op=teardown, tag=omitted
     *
     * @effects teardown_without_tag_sweeps_every_seed_only
     *
     * @return void 반환값 없음
     */
    public function test_teardown_without_tag_sweeps_every_seed_only(): void
    {
        $this->runSeed(['--tag' => 'w1t4']);
        $this->runSeed(['--tag' => 'w2t4']);
        $keptBoard = DB::table((new Board)->getTable())->insertGetId([
            'slug' => 'e2e-keep-t4',
            'name' => json_encode(['ko' => '남는 게시판', 'en' => 'kept'], JSON_UNESCAPED_UNICODE),
            'is_active' => true,
            'per_page' => 20,
            'per_page_mobile' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $keptUser = $this->createUser();

        [$code] = $this->runSeed(['--teardown' => true]);

        $this->assertSame(0, $code, Artisan::output());
        $this->assertSame(0, Board::query()->where('slug', 'like', 'e2e-nav-%')->count());
        $this->assertSame(0, User::query()->where('email', 'like', 'e2e-nav-%@example.test')->count());
        $this->assertSame(1, Board::query()->whereKey($keptBoard)->count());
        $this->assertSame(1, User::query()->whereKey($keptUser->id)->count());
    }

    /**
     * 시드에 태그가 없거나 형식이 틀리면 아무것도 만들지 않고 실패한다(워커끼리 지우지 않게).
     *
     * @scenario op=seed, tag=invalid
     *
     * @effects seed_without_valid_tag_fails_without_side_effects
     *
     * @return void 반환값 없음
     */
    public function test_seed_without_a_valid_tag_fails_without_side_effects(): void
    {
        foreach ([[], ['--tag' => 'W0 bad'], ['--tag' => 'w0_bad']] as $options) {
            $code = Artisan::call('playwright:seed-board-posts', $options);

            $this->assertSame(1, $code, json_encode($options));
        }

        $this->assertSame(0, Board::query()->where('slug', 'like', 'e2e-nav-%')->count());
        $this->assertSame(0, User::query()->where('email', 'like', 'e2e-nav-%@example.test')->count());
    }
}
