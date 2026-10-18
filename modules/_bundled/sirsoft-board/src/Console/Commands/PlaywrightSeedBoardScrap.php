<?php

namespace Modules\Sirsoft\Board\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Models\PostScrap;
use Modules\Sirsoft\Board\Services\BoardPermissionService;

/**
 * Playwright E2E · 브라우저 실측용 스크랩 도메인 시드 커맨드 (#524).
 *
 * 스크랩 화면은 **세 상태의 행**(정상 · 스크랩 중지 · 삭제글)과 **게시판이 지워진 행**을 함께
 * 보여 준다. 그 넷을 한 화면에 세우려면 게시판 설정을 바꾸고 글과 게시판을 지운 «뒤» 의 상태가
 * 필요한데, 손으로 만들면 매번 다른 상태가 되어 실측 결과를 비교할 수 없다. 이 커맨드가 그
 * 상태를 고정한다.
 *
 * 만드는 것:
 *   - 회원 A(scrap-a) · 회원 B(scrap-b)
 *   - 게시판 free(use_scrap on) · notice(만든 뒤 use_scrap off) · tmpboard(만든 뒤 삭제)
 *   - 게시판마다 글 2건 — 제목에 「알파 제목」, 본문에 「베타 본문 키워드」를 갈라 넣는다.
 *     제목 검색과 내용 검색이 서로 다른 결과를 내는지 보려면 두 낱말이 겹치면 안 된다.
 *   - A 의 스크랩 4건(free 2 · notice 1 · tmpboard 1) · B 의 스크랩 1건(free)
 *   - free 글 하나를 소프트 삭제 → 「삭제된 게시글」 행
 *
 * 재실행하면 같은 slug·계정을 먼저 지우고 다시 만든다(멱등).
 *
 * 보안 가드 (코어 PlaywrightIssueToken 과 동일 3중 패턴):
 *   ① CLI 한정 — `php_sapi_name() === 'cli'`
 *   ② G7_PLAYWRIGHT_BYPASS=1 환경변수 옵트인
 *   ③ APP_DEBUG=true inline override
 *
 * 호출 예시:
 *   G7_PLAYWRIGHT_BYPASS=1 php artisan playwright:seed-board-scrap --json
 *   G7_PLAYWRIGHT_BYPASS=1 php artisan playwright:seed-board-scrap --teardown
 */
class PlaywrightSeedBoardScrap extends Command
{
    /**
     * 고정 게시판 slug — spec 이 URL 로 쓴다.
     */
    private const SLUG_FREE = 'e2e-scrap-free';

    private const SLUG_NOTICE = 'e2e-scrap-notice';

    private const SLUG_TEMP = 'e2e-scrap-temp';

    /**
     * 고정 회원 이메일 — 재실행 시 같은 계정을 지우고 다시 만든다.
     */
    private const EMAIL_A = 'e2e-scrap-a@example.test';

    private const EMAIL_B = 'e2e-scrap-b@example.test';

    /**
     * 검색 구분 검증용 낱말 — 제목과 본문에 겹치지 않게 갈라 넣는다.
     */
    private const TITLE_WORD = '알파 제목';

    private const BODY_WORD = '베타 본문 키워드';

    /**
     * 커맨드 이름 및 시그니처
     *
     * @var string
     */
    protected $signature = 'playwright:seed-board-scrap
        {--teardown : 시드한 데이터를 전량 삭제한다}
        {--json : 결과를 JSON 으로 출력}';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = 'Playwright E2E · 실측용 스크랩 도메인 시드 (CLI + G7_PLAYWRIGHT_BYPASS 가드)';

    /**
     * 커맨드를 실행합니다.
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        // ① CLI 한정
        if (php_sapi_name() !== 'cli') {
            $this->error('CLI 전용 커맨드입니다.');

            return self::FAILURE;
        }

        // ② 명시 옵트인
        if (env('G7_PLAYWRIGHT_BYPASS') !== '1') {
            $this->error('G7_PLAYWRIGHT_BYPASS=1 환경변수가 필요합니다.');

            return self::FAILURE;
        }

        // ③ APP_DEBUG 강제
        Config::set('app.debug', true);

        $result = $this->option('teardown') ? $this->teardown() : $this->seed();

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($result as $key => $value) {
                $this->line($key.': '.(is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE)));
            }
        }

        return self::SUCCESS;
    }

    /**
     * 시드 데이터를 만듭니다.
     *
     * @return array<string, mixed> 만들어진 식별자 모음
     */
    private function seed(): array
    {
        return DB::transaction(function () {
            $this->purge();

            $memberA = $this->createMember(self::EMAIL_A, '스크랩회원A');
            $memberB = $this->createMember(self::EMAIL_B, '스크랩회원B');

            $free = $this->createBoard(self::SLUG_FREE, '자유게시판(E2E)', true);
            $notice = $this->createBoard(self::SLUG_NOTICE, '공지사항(E2E)', true);
            $temp = $this->createBoard(self::SLUG_TEMP, '임시게시판(E2E)', true);

            $freePosts = $this->createPosts($free, $memberA->id, '자유');
            $noticePosts = $this->createPosts($notice, $memberA->id, '공지');
            $tempPosts = $this->createPosts($temp, $memberB->id, '임시');

            // 지울 글은 따로 만든다 — 본문 낱말이 든 글을 지워 버리면 「내용」 검색이 잴 대상이 없어진다
            // (삭제글은 검색에서 빠지는 것이 설계다).
            $doomed = $this->createPosts($free, $memberA->id, '삭제예정')[0];

            // 회원 A 는 세 게시판에 걸쳐 스크랩한다 — 게시판 상태가 달라진 뒤에도 행이 남아야 한다.
            $scrapIds = [
                $this->scrap($memberA->id, $freePosts[0], $free),
                $this->scrap($memberA->id, $freePosts[1], $free),
                $this->scrap($memberA->id, $doomed, $free),
                $this->scrap($memberA->id, $noticePosts[0], $notice),
                $this->scrap($memberA->id, $tempPosts[0], $temp),
            ];
            // 회원 B 의 스크랩 — 「내 것만 지워진다」 를 확인할 대조군이다.
            $otherScrapId = $this->scrap($memberB->id, $freePosts[0], $free);

            // 상태 전환 — 스크랩을 «건 뒤» 에 바꿔야 세 상태의 행이 한 화면에 선다.
            DB::table((new Board)->getTable())->where('id', $notice)->update(['use_scrap' => false]);
            DB::table((new Board)->getTable())->where('id', $temp)->delete();
            DB::table((new Post)->getTable())->where('id', $doomed)->update(['deleted_at' => now()]);

            return [
                // 시드한 회원으로 «실제로 로그인» 해야 그 회원의 스크랩 목록이 보인다.
                // `playwright:issue-token` 은 자기 테스트 계정을 새로 만들어 주므로 여기서 직접 발급한다.
                'member_a_token' => $memberA->createToken('playwright-scrap-a')->plainTextToken,
                'member_b_token' => $memberB->createToken('playwright-scrap-b')->plainTextToken,
                'member_a_uuid' => $memberA->uuid,
                'member_b_uuid' => $memberB->uuid,
                'member_a_email' => $memberA->email,
                'member_b_email' => $memberB->email,
                'board_free_slug' => self::SLUG_FREE,
                'board_notice_slug' => self::SLUG_NOTICE,
                'post_alive_id' => $freePosts[0],
                'post_body_match_id' => $freePosts[1],
                'post_deleted_id' => $doomed,
                'scrap_ids' => $scrapIds,
                'other_member_scrap_id' => $otherScrapId,
                'title_keyword' => self::TITLE_WORD,
                'body_keyword' => self::BODY_WORD,
            ];
        });
    }

    /**
     * 시드 데이터를 전량 삭제합니다.
     *
     * @return array<string, int> 삭제 건수
     */
    private function teardown(): array
    {
        return DB::transaction(fn () => $this->purge());
    }

    /**
     * 고정 slug·계정에 딸린 데이터를 지웁니다 (재실행 멱등의 핵심).
     *
     * 게시판은 이미 지워져 있을 수 있어(시드가 tmpboard 를 삭제한다) slug 로만 찾지 않고
     * 회원이 남긴 스크랩까지 함께 훑는다.
     *
     * @return array<string, int> 삭제 건수
     */
    private function purge(): array
    {
        $userIds = DB::table((new User)->getTable())
            ->whereIn('email', [self::EMAIL_A, self::EMAIL_B])
            ->pluck('id')
            ->all();

        $boardIds = DB::table((new Board)->getTable())
            ->whereIn('slug', [self::SLUG_FREE, self::SLUG_NOTICE, self::SLUG_TEMP])
            ->pluck('id')
            ->all();

        $postIds = $boardIds === []
            ? []
            : DB::table((new Post)->getTable())->whereIn('board_id', $boardIds)->pluck('id')->all();

        $scraps = 0;
        if ($userIds !== []) {
            $scraps += DB::table((new PostScrap)->getTable())->whereIn('user_id', $userIds)->delete();
        }
        if ($postIds !== []) {
            $scraps += DB::table((new PostScrap)->getTable())->whereIn('post_id', $postIds)->delete();
        }

        $posts = $postIds === [] ? 0 : DB::table((new Post)->getTable())->whereIn('id', $postIds)->delete();
        $boards = $boardIds === [] ? 0 : DB::table((new Board)->getTable())->whereIn('id', $boardIds)->delete();

        // 게시판마다 딸린 권한도 함께 지운다. 시드가 만든 것을 지우지 않으면 개발 DB 의 권한 목록에
        // `sirsoft-board.e2e-*` 가 회차마다 쌓인다. 게시판 행으로 찾지 않고 **고정 slug 로** 찾는 것은
        // 시드가 tmpboard 를 스스로 지워 행이 이미 없을 수 있기 때문이다.
        // 카테고리 권한을 지우면 자식 권한은 CASCADE 로 함께 사라진다(BoardPermissionService 와 같은 규약).
        // 카테고리를 지우면 자식은 CASCADE 로 따라 사라지지만, `parent_id` 가 비어 있는 행은 그 사슬에
        // 걸리지 않는다(등록 경로가 둘이라 실제로 그런 행이 남는다). 그래서 사슬에 기대지 않고
        // **identifier 접두**로 모집단을 직접 훑어 전량을 지운다.
        $permissions = 0;
        foreach ([self::SLUG_FREE, self::SLUG_NOTICE, self::SLUG_TEMP] as $slug) {
            $prefix = 'sirsoft-board.'.$slug;
            $rows = Permission::where('identifier', $prefix)
                ->orWhere('identifier', 'like', $prefix.'.%')
                ->get();
            foreach ($rows as $row) {
                $row->roles()->detach();
                $row->delete();
                $permissions++;
            }
        }

        $activities = 0;
        if ($userIds !== []) {
            $activities = DB::table((new ActivityLog)->getTable())->whereIn('user_id', $userIds)->delete();
        }

        $users = $userIds === [] ? 0 : DB::table((new User)->getTable())->whereIn('id', $userIds)->delete();

        return [
            'deleted_scraps' => $scraps,
            'deleted_posts' => $posts,
            'deleted_boards' => $boards,
            'deleted_board_permissions' => $permissions,
            'deleted_activity_logs' => $activities,
            'deleted_users' => $users,
        ];
    }

    /**
     * 시드용 회원을 만듭니다.
     *
     * @param  string  $email  이메일
     * @param  string  $name  이름
     * @return User 만들어진 회원
     */
    private function createMember(string $email, string $name): User
    {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('e2e-scrap-password'),
            'email_verified_at' => now(),
        ]);

        // 실제 가입(AuthService::register)과 같이 기본 'user' 역할을 붙인다 —
        // 역할이 없으면 게시판 목록조차 403 이라 회원 화면을 잴 수 없다.
        $role = Role::where('identifier', 'user')->first();
        if ($role !== null) {
            $user->roles()->sync([$role->id]);
        }

        return $user;
    }

    /**
     * 시드용 게시판을 만듭니다.
     *
     * @param  string  $slug  게시판 slug
     * @param  string  $koName  한국어 게시판명
     * @param  bool  $useScrap  스크랩 사용 여부
     * @return int 게시판 ID
     */
    private function createBoard(string $slug, string $koName, bool $useScrap): int
    {
        $id = (int) DB::table((new Board)->getTable())->insertGetId([
            'slug' => $slug,
            'name' => json_encode(['ko' => $koName, 'en' => $slug], JSON_UNESCAPED_UNICODE),
            'is_active' => true,
            'use_scrap' => $useScrap,
            'use_comment' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 게시판 읽기 권한은 **게시판마다** 따로 있다(`sirsoft-board.{slug}.posts.read`). 관리 화면을
        // 거쳐 만든 게시판은 BoardService 가 이 권한을 만들고 기본 역할에 붙여 주지만, 여기서는
        // 행을 직접 넣으므로 그 단계가 통째로 빠진다 — 그러면 `user` 역할 회원에게 목록·상세가
        // 전부 403 이 되어 회원 화면(검색 구분·스크랩 토글)을 아예 잴 수 없다.
        // 실제 생성 경로와 같은 서비스를 불러 같은 기본 권한을 붙인다.
        $board = Board::find($id);
        if ($board !== null) {
            app(BoardPermissionService::class)->ensureBoardPermissions($board);
        }

        return $id;
    }

    /**
     * 게시판에 글 두 건을 만듭니다.
     *
     * 첫 글은 제목에만, 둘째 글은 본문에만 검색 낱말을 둔다 — 제목 검색과 내용 검색이 서로
     * 다른 건수를 내야 「구분이 실제로 동작한다」 를 말할 수 있다.
     *
     * @param  int  $boardId  게시판 ID
     * @param  int  $userId  작성자 ID
     * @param  string  $label  게시판 구분 라벨
     * @return array<int, int> 글 ID 두 개
     */
    private function createPosts(int $boardId, int $userId, string $label): array
    {
        $titleOnly = DB::table((new Post)->getTable())->insertGetId([
            'board_id' => $boardId,
            'user_id' => $userId,
            'title' => self::TITLE_WORD.' — '.$label,
            'content' => '제목에만 낱말이 있는 글입니다.',
            'author_name' => '스크랩작성자',
            'ip_address' => '127.0.0.1',
            'status' => 'published',
            'trigger_type' => 'admin',
            'view_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $bodyOnly = DB::table((new Post)->getTable())->insertGetId([
            'board_id' => $boardId,
            'user_id' => $userId,
            'title' => '감마 다른 제목 — '.$label,
            'content' => self::BODY_WORD.' 가 본문에만 있는 글입니다.',
            'author_name' => '스크랩작성자',
            'ip_address' => '127.0.0.1',
            'status' => 'published',
            'trigger_type' => 'admin',
            'view_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [(int) $titleOnly, (int) $bodyOnly];
    }

    /**
     * 스크랩 한 건을 만들고 글의 스크랩 수를 올립니다.
     *
     * 서비스를 부르지 않고 직접 넣는다 — 시드는 훅·활동 로그까지 재현할 필요가 없고,
     * 그쪽은 PHPUnit 이 이미 잰다.
     *
     * @param  int  $userId  회원 ID
     * @param  int  $postId  게시글 ID
     * @param  int  $boardId  게시판 ID
     * @return int 스크랩 레코드 ID
     */
    private function scrap(int $userId, int $postId, int $boardId): int
    {
        $id = (int) DB::table((new PostScrap)->getTable())->insertGetId([
            'user_id' => $userId,
            'post_id' => $postId,
            'board_id' => $boardId,
            'created_at' => now(),
        ]);

        DB::table((new Post)->getTable())->where('id', $postId)->increment('scrap_count');

        return $id;
    }
}
