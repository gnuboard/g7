<?php

namespace Modules\Sirsoft\Board\Console\Commands;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Services\BoardPermissionService;

/**
 * Playwright E2E · 브라우저 실측용 게시판 개인정보 보유기간 시드 커맨드 (이슈 #636 S5).
 *
 * 보유기간 처리는 「작성 후 N일이 지난 행」만 건드리므로, 실측하려면 **오래전에 쓴 것처럼 보이는
 * 글**이 필요하다. 화면·API 로는 작성 시각을 과거로 만들 수 없어 이 커맨드가 그 상태를 고정한다.
 *
 * 만드는 것 (게시판 `e2e-retention` 하나):
 *   - 오래된 비회원 글 2건(작성자 「보유기간비회원」, IP 203.0.113.x) — 비회원 축의 대상
 *   - 오래된 회원 글 1건 — IP 축의 대상, 비회원 축의 대상 아님
 *   - 최근 비회원 글 1건 — 어느 축의 대상도 아님(기간 안)
 *   - 오래된 비회원 댓글 1건(회원 글 아래)
 * 작성 시각은 `--days`(기본 4000일) 전으로 둔다 — 기본 보유기간(IP 365일)보다 충분히 멀다.
 *
 * 재실행하면 같은 slug·계정을 먼저 지우고 다시 만든다(멱등). `--teardown` 은 전량 삭제한다.
 *
 * 보안 가드 (코어 PlaywrightIssueToken 과 동일 3중 패턴):
 *   ① CLI 한정 — `php_sapi_name() === 'cli'`
 *   ② G7_PLAYWRIGHT_BYPASS=1 환경변수 옵트인
 *   ③ APP_DEBUG=true inline override
 *
 * 호출 예시:
 *   G7_PLAYWRIGHT_BYPASS=1 php artisan playwright:seed-board-retention --json
 *   G7_PLAYWRIGHT_BYPASS=1 php artisan playwright:seed-board-retention --teardown
 */
class PlaywrightSeedBoardRetention extends Command
{
    /** 고정 게시판 slug */
    private const SLUG = 'e2e-retention';

    /** 고정 회원 이메일 */
    private const MEMBER_EMAIL = 'e2e-retention-member@example.test';

    /** 비회원 작성자명 — 익명화 전후 대조용 */
    private const GUEST_NAME = '보유기간비회원';

    /**
     * 커맨드 이름 및 시그니처
     *
     * @var string
     */
    protected $signature = 'playwright:seed-board-retention
        {--days=4000 : 오래된 행의 작성 시각(며칠 전)}
        {--teardown : 시드한 데이터를 전량 삭제한다}
        {--json : 결과를 JSON 으로 출력}';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = 'Playwright E2E · 실측용 게시판 개인정보 보유기간 시드 (CLI + G7_PLAYWRIGHT_BYPASS 가드)';

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

        $result = $this->option('teardown') ? DB::transaction(fn () => $this->purge()) : $this->seed();

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

            $old = now()->subDays(max(1, (int) $this->option('days')));

            $member = User::create([
                'name' => '보유기간회원',
                'email' => self::MEMBER_EMAIL,
                'password' => Hash::make('e2e-retention-password'),
                'email_verified_at' => now(),
            ]);

            $boardId = (int) DB::table((new Board)->getTable())->insertGetId([
                'slug' => self::SLUG,
                'name' => json_encode(['ko' => '보유기간 실측(E2E)', 'en' => self::SLUG], JSON_UNESCAPED_UNICODE),
                'is_active' => true,
                'use_comment' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 관리 화면을 거치지 않고 만든 게시판에는 게시판별 권한이 없다 — 실제 생성 경로와 같은
            // 서비스로 기본 권한을 붙여야 관리자·회원 화면이 403 이 아니다.
            $board = Board::find($boardId);
            if ($board !== null) {
                app(BoardPermissionService::class)->ensureBoardPermissions($board);
            }

            $guestOld1 = $this->post($boardId, null, self::GUEST_NAME, '203.0.113.21', $old, '오래된 비회원 글 1');
            $guestOld2 = $this->post($boardId, null, self::GUEST_NAME, '203.0.113.22', $old, '오래된 비회원 글 2');
            $memberOld = $this->post($boardId, $member->id, null, '203.0.113.23', $old, '오래된 회원 글');
            $guestRecent = $this->post($boardId, null, self::GUEST_NAME, '203.0.113.24', now(), '최근 비회원 글');

            $guestComment = (int) DB::table((new Comment)->getTable())->insertGetId([
                'board_id' => $boardId,
                'post_id' => $memberOld,
                'user_id' => null,
                'author_name' => self::GUEST_NAME,
                'content' => '오래된 비회원 댓글',
                'ip_address' => '203.0.113.25',
                'status' => 'published',
                'created_at' => $old,
                'updated_at' => $old,
            ]);

            return [
                'board_slug' => self::SLUG,
                'guest_post_ids' => [$guestOld1, $guestOld2],
                'member_post_id' => $memberOld,
                'recent_guest_post_id' => $guestRecent,
                'guest_comment_id' => $guestComment,
                'guest_author_name' => self::GUEST_NAME,
                'created_at' => $old->toDateTimeString(),
            ];
        });
    }

    /**
     * 게시글 한 건을 만듭니다.
     *
     * @param  int  $boardId  게시판 ID
     * @param  int|null  $userId  회원 ID (비회원이면 null)
     * @param  string|null  $authorName  비회원 작성자명
     * @param  string  $ip  작성 IP
     * @param  \DateTimeInterface  $at  작성 시각
     * @param  string  $title  제목
     * @return int 게시글 ID
     */
    private function post(int $boardId, ?int $userId, ?string $authorName, string $ip, \DateTimeInterface $at, string $title): int
    {
        return (int) DB::table((new Post)->getTable())->insertGetId([
            'board_id' => $boardId,
            'user_id' => $userId,
            'title' => $title,
            'content' => $title.' 본문입니다.',
            'author_name' => $authorName,
            'ip_address' => $ip,
            'status' => 'published',
            'trigger_type' => 'admin',
            'view_count' => 0,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    /**
     * 고정 slug·계정에 딸린 데이터를 지웁니다 (재실행 멱등의 핵심).
     *
     * @return array<string, int> 삭제 건수
     */
    private function purge(): array
    {
        $boardIds = DB::table((new Board)->getTable())->where('slug', self::SLUG)->pluck('id')->all();
        $comments = $boardIds === [] ? 0 : DB::table((new Comment)->getTable())->whereIn('board_id', $boardIds)->delete();
        $posts = $boardIds === [] ? 0 : DB::table((new Post)->getTable())->whereIn('board_id', $boardIds)->delete();
        $boards = $boardIds === [] ? 0 : DB::table((new Board)->getTable())->whereIn('id', $boardIds)->delete();

        // 게시판별 권한은 identifier 접두로 전량 지운다(스크랩 시드와 같은 규약 — parent 사슬에 기대지 않는다).
        $permissions = 0;
        $prefix = 'sirsoft-board.'.self::SLUG;
        foreach (Permission::where('identifier', $prefix)->orWhere('identifier', 'like', $prefix.'.%')->get() as $row) {
            $row->roles()->detach();
            $row->delete();
            $permissions++;
        }

        $users = DB::table((new User)->getTable())->where('email', self::MEMBER_EMAIL)->delete();

        return [
            'deleted_comments' => $comments,
            'deleted_posts' => $posts,
            'deleted_boards' => $boards,
            'deleted_board_permissions' => $permissions,
            'deleted_users' => $users,
        ];
    }
}
