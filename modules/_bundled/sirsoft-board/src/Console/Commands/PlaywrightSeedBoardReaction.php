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
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Models\Reaction;
use Modules\Sirsoft\Board\Models\ReactionType;
use Modules\Sirsoft\Board\Models\Report;
use Modules\Sirsoft\Board\Models\ReportLog;
use Modules\Sirsoft\Board\Services\BoardPermissionService;

/**
 * Playwright E2E · 브라우저 실측용 반응 도메인 시드 커맨드 (#525).
 *
 * 반응 화면은 **세 상태의 행**(정상 · 반응 꺼짐 · 대상 삭제됨)과 **게시판이 지워진 행**을 함께
 * 보여 준다. 여기에 글 반응과 댓글 반응이 섞이고, 유형 하나는 비활성이어야 「비활성 유형」
 * 표시와 필터 옵션을 잴 수 있다. 그 조합을 손으로 만들면 회차마다 다른 상태가 되어 실측
 * 결과를 비교할 수 없다. 이 커맨드가 그 상태를 고정한다.
 *
 * 만드는 것:
 *   - 회원 A(reaction-a, 반응 7건) · 회원 B(reaction-b, 대조군) · 회원 C(탈퇴 상태)
 *   - 게시판 free(반응·댓글 반응 on) · notice(만든 뒤 use_reaction off) · tmpboard(만든 뒤 삭제)
 *   - 게시판마다 글 2건 — 제목에 「알파 제목」, 본문에 「베타 본문 키워드」를 갈라 넣는다.
 *     제목 검색과 내용 검색이 서로 다른 결과를 내야 「구분이 동작한다」 를 말할 수 있다.
 *   - free 글에 댓글 2건(회원 A 의 댓글 1 · 회원 B 의 댓글 1)
 *   - 비활성 반응 유형 1종 — 「비활성 유형」 배지와 필터 옵션 「(비활성)」을 잴 대상
 *   - free 글 하나를 소프트 삭제 → 「삭제된 게시글」 행, 댓글 하나도 소프트 삭제
 *   - 회원 A 의 신고 2건 — 대기 중(취소 가능) 1 · 반려(취소 불가) 1.
 *     두 상태가 함께 있어야 「취소 버튼이 대기 중인 건에만 뜬다」 를 한 화면에서 잰다.
 *
 * 재실행하면 같은 slug·계정·유형을 먼저 지우고 다시 만든다(멱등).
 *
 * 보안 가드 (코어 PlaywrightIssueToken 과 동일 3중 패턴):
 *   ① CLI 한정 — `php_sapi_name() === 'cli'`
 *   ② G7_PLAYWRIGHT_BYPASS=1 환경변수 옵트인
 *   ③ APP_DEBUG=true inline override
 *
 * 호출 예시:
 *   G7_PLAYWRIGHT_BYPASS=1 php artisan playwright:seed-board-reaction --json
 *   G7_PLAYWRIGHT_BYPASS=1 php artisan playwright:seed-board-reaction --teardown
 */
class PlaywrightSeedBoardReaction extends Command
{
    /**
     * 고정 게시판 slug — spec 이 URL 로 쓴다.
     */
    private const SLUG_FREE = 'e2e-react-free';

    private const SLUG_NOTICE = 'e2e-react-notice';

    private const SLUG_TEMP = 'e2e-react-temp';

    /**
     * 고정 회원 이메일 — 재실행 시 같은 계정을 지우고 다시 만든다.
     */
    private const EMAIL_A = 'e2e-react-a@example.test';

    private const EMAIL_B = 'e2e-react-b@example.test';

    private const EMAIL_C = 'e2e-react-c@example.test';

    /**
     * 시드가 만드는 비활성 반응 유형 코드 — 기본 유형(like/dislike)과 겹치지 않게 둔다.
     */
    private const INACTIVE_TYPE_CODE = 'e2e_retired';

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
    protected $signature = 'playwright:seed-board-reaction
        {--teardown : 시드한 데이터를 전량 삭제한다}
        {--json : 결과를 JSON 으로 출력}';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = 'Playwright E2E · 실측용 반응 도메인 시드 (CLI + G7_PLAYWRIGHT_BYPASS 가드)';

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

            $memberA = $this->createMember(self::EMAIL_A, '반응회원A');
            $memberB = $this->createMember(self::EMAIL_B, '반응회원B');
            // 탈퇴 회원 — 관리자 목록의 「탈퇴」 배지와 이름 익명화를 잴 대상이다.
            $memberC = $this->createMember(self::EMAIL_C, '반응회원C');
            DB::table((new User)->getTable())->where('id', $memberC->id)->update(['status' => 'withdrawn']);

            $free = $this->createBoard(self::SLUG_FREE, '자유게시판(E2E반응)', true, true);
            $notice = $this->createBoard(self::SLUG_NOTICE, '공지사항(E2E반응)', true, true);
            $temp = $this->createBoard(self::SLUG_TEMP, '임시게시판(E2E반응)', true, true);

            $freePosts = $this->createPosts($free, $memberB->id, '자유');
            $noticePosts = $this->createPosts($notice, $memberB->id, '공지');
            $tempPosts = $this->createPosts($temp, $memberB->id, '임시');

            // 지울 글은 따로 만든다 — 본문 낱말이 든 글을 지우면 「내용」 검색이 잴 대상이 없어진다
            // (삭제글은 회원 목록 검색에서 빠지는 것이 설계다).
            $doomed = $this->createPosts($free, $memberB->id, '삭제예정')[0];

            // 댓글 — 글 반응과 댓글 반응이 한 목록에 섞여야 대상 배지·필터를 잴 수 있다.
            $commentAlive = $this->createComment($free, $freePosts[0], $memberB->id, '살아 있는 댓글입니다.');
            $commentDoomed = $this->createComment($free, $freePosts[0], $memberB->id, '지워질 댓글입니다.');

            $types = $this->resolveReactionTypes();
            $inactiveTypeId = $this->createInactiveType();

            // 회원 A 의 반응 7건 — 글 6 + 댓글 1. 게시판 상태가 달라진 뒤에도 행이 남아야 한다.
            $reactionIds = [
                $this->react($memberA->id, 'post', $freePosts[0], $types['like'], $free),
                $this->react($memberA->id, 'post', $freePosts[1], $types['dislike'], $free),
                $this->react($memberA->id, 'post', $doomed, $types['like'], $free),
                $this->react($memberA->id, 'post', $noticePosts[0], $types['like'], $notice),
                $this->react($memberA->id, 'post', $noticePosts[1], $types['like'], $notice),
                $this->react($memberA->id, 'post', $tempPosts[0], $types['like'], $temp),
                $this->react($memberA->id, 'comment', $commentAlive, $types['like'], $free),
            ];
            // 회원 B 의 반응 — 「내 것만 해제된다」 를 확인할 대조군.
            $otherReactionId = $this->react($memberB->id, 'post', $freePosts[1], $types['like'], $free);
            // 탈퇴 회원의 반응 — 관리자 목록의 익명화 표시를 잴 대상.
            $withdrawnReactionId = $this->react($memberC->id, 'post', $freePosts[0], $types['dislike'], $free);
            // 비활성 유형으로 남은 과거 반응 — 유형 필터가 비활성까지 낸다는 것을 잴 대상.
            $inactiveReactionId = $this->react($memberB->id, 'comment', $commentAlive, $inactiveTypeId, $free);

            // 신고 2건 — 대기 중 하나, 반려 하나. 「내 신고」 탭의 상태 배지와 취소 버튼 노출을
            // 이 둘로 가른다.
            $reportPending = $this->report($free, $freePosts[0], $memberA->id, 'pending', 'spam');
            $reportRejected = $this->report($free, $freePosts[1], $memberA->id, 'rejected', 'abuse');

            // 상태 전환 — 반응을 «건 뒤» 에 바꿔야 세 상태의 행이 한 화면에 선다.
            DB::table((new Board)->getTable())->where('id', $notice)->update(['use_reaction' => false]);
            DB::table((new Board)->getTable())->where('id', $temp)->delete();
            DB::table((new Post)->getTable())->where('id', $doomed)->update(['deleted_at' => now()]);
            DB::table((new Comment)->getTable())
                ->where('id', $commentDoomed)
                ->where('board_id', $free)
                ->update(['deleted_at' => now(), 'status' => 'deleted']);

            return [
                // 시드한 회원으로 «실제로 로그인» 해야 그 회원의 반응 목록이 보인다.
                // `playwright:issue-token` 은 자기 테스트 계정을 새로 만들어 주므로 여기서 직접 발급한다.
                'member_a_token' => $memberA->createToken('playwright-reaction-a')->plainTextToken,
                'member_b_token' => $memberB->createToken('playwright-reaction-b')->plainTextToken,
                'member_a_uuid' => $memberA->uuid,
                'member_b_uuid' => $memberB->uuid,
                'member_c_uuid' => $memberC->uuid,
                'member_a_email' => $memberA->email,
                'member_b_email' => $memberB->email,
                'member_a_name' => '반응회원A',
                'board_free_slug' => self::SLUG_FREE,
                'board_notice_slug' => self::SLUG_NOTICE,
                'board_free_id' => $free,
                'post_alive_id' => $freePosts[0],
                'post_body_match_id' => $freePosts[1],
                'post_deleted_id' => $doomed,
                'comment_alive_id' => $commentAlive,
                'comment_deleted_id' => $commentDoomed,
                'report_log_pending_id' => $reportPending,
                'report_log_rejected_id' => $reportRejected,
                'reaction_ids' => $reactionIds,
                'other_member_reaction_id' => $otherReactionId,
                'withdrawn_member_reaction_id' => $withdrawnReactionId,
                'inactive_type_reaction_id' => $inactiveReactionId,
                'reaction_type_like_id' => $types['like'],
                'reaction_type_dislike_id' => $types['dislike'],
                'reaction_type_inactive_id' => $inactiveTypeId,
                'reaction_type_inactive_code' => self::INACTIVE_TYPE_CODE,
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
     * 고정 slug·계정·유형에 딸린 데이터를 지웁니다 (재실행 멱등의 핵심).
     *
     * 게시판은 이미 지워져 있을 수 있어(시드가 tmpboard 를 삭제한다) slug 로만 찾지 않고
     * 회원이 남긴 반응까지 함께 훑는다.
     *
     * @return array<string, int> 삭제 건수
     */
    private function purge(): array
    {
        $userIds = DB::table((new User)->getTable())
            ->whereIn('email', [self::EMAIL_A, self::EMAIL_B, self::EMAIL_C])
            ->pluck('id')
            ->all();

        $boardIds = DB::table((new Board)->getTable())
            ->whereIn('slug', [self::SLUG_FREE, self::SLUG_NOTICE, self::SLUG_TEMP])
            ->pluck('id')
            ->all();

        $postIds = $boardIds === []
            ? []
            : DB::table((new Post)->getTable())->whereIn('board_id', $boardIds)->pluck('id')->all();

        $commentIds = $boardIds === []
            ? []
            : DB::table((new Comment)->getTable())->whereIn('board_id', $boardIds)->pluck('id')->all();

        // 반응은 회원·대상 양쪽에서 훑는다 — 게시판이 이미 지워진 행도 남아 있기 때문.
        $reactions = 0;
        if ($userIds !== []) {
            $reactions += DB::table((new Reaction)->getTable())->whereIn('user_id', $userIds)->delete();
        }
        if ($postIds !== []) {
            $reactions += DB::table((new Reaction)->getTable())
                ->where('target_type', 'post')->whereIn('target_id', $postIds)->delete();
        }
        if ($commentIds !== []) {
            $reactions += DB::table((new Reaction)->getTable())
                ->where('target_type', 'comment')->whereIn('target_id', $commentIds)->delete();
        }

        // 신고는 로그가 케이스를 가리키므로 로그를 먼저 지운다.
        $reportIds = $boardIds === []
            ? []
            : DB::table((new Report)->getTable())->whereIn('board_id', $boardIds)->pluck('id')->all();

        $reportLogs = 0;
        if ($userIds !== []) {
            $reportLogs += DB::table((new ReportLog)->getTable())->whereIn('reporter_id', $userIds)->delete();
        }
        if ($reportIds !== []) {
            $reportLogs += DB::table((new ReportLog)->getTable())->whereIn('report_id', $reportIds)->delete();
        }
        $reports = $reportIds === []
            ? 0
            : DB::table((new Report)->getTable())->whereIn('id', $reportIds)->delete();

        $comments = $commentIds === []
            ? 0
            : DB::table((new Comment)->getTable())->whereIn('id', $commentIds)->delete();
        $posts = $postIds === [] ? 0 : DB::table((new Post)->getTable())->whereIn('id', $postIds)->delete();
        $boards = $boardIds === [] ? 0 : DB::table((new Board)->getTable())->whereIn('id', $boardIds)->delete();

        // 시드가 만든 비활성 유형. 이 유형을 가리키는 반응은 위에서 이미 지웠으므로 FK 에 걸리지 않는다.
        $types = DB::table((new ReactionType)->getTable())
            ->where('code', self::INACTIVE_TYPE_CODE)
            ->delete();

        // 게시판마다 딸린 권한도 함께 지운다. 시드가 만든 것을 지우지 않으면 개발 DB 의 권한 목록에
        // `sirsoft-board.e2e-*` 가 회차마다 쌓인다. 게시판 행으로 찾지 않고 **고정 slug 로** 찾는 것은
        // 시드가 tmpboard 를 스스로 지워 행이 이미 없을 수 있기 때문이다.
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
            'deleted_reactions' => $reactions,
            'deleted_report_logs' => $reportLogs,
            'deleted_reports' => $reports,
            'deleted_comments' => $comments,
            'deleted_posts' => $posts,
            'deleted_boards' => $boards,
            'deleted_reaction_types' => $types,
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
            'password' => Hash::make('e2e-reaction-password'),
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
     * @param  bool  $useReaction  반응 사용 여부
     * @param  bool  $useCommentReaction  댓글 반응 사용 여부
     * @return int 게시판 ID
     */
    private function createBoard(string $slug, string $koName, bool $useReaction, bool $useCommentReaction): int
    {
        $id = (int) DB::table((new Board)->getTable())->insertGetId([
            'slug' => $slug,
            'name' => json_encode(['ko' => $koName, 'en' => $slug], JSON_UNESCAPED_UNICODE),
            'is_active' => true,
            'use_reaction' => $useReaction,
            'use_comment_reaction' => $useCommentReaction,
            'use_comment' => true,
            // 활성 유형을 비워 두면 글 상세에 반응 버튼이 아예 뜨지 않는다.
            'active_reaction_types' => json_encode(['like', 'dislike'], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 게시판 읽기 권한은 **게시판마다** 따로 있다(`sirsoft-board.{slug}.posts.read`). 관리 화면을
        // 거쳐 만든 게시판은 BoardService 가 이 권한을 만들고 기본 역할에 붙여 주지만, 여기서는
        // 행을 직접 넣으므로 그 단계가 통째로 빠진다 — 그러면 `user` 역할 회원에게 목록·상세가
        // 전부 403 이 되어 회원 화면(반응 버튼·댓글 반응)을 아예 잴 수 없다.
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
            'author_name' => '반응작성자',
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
            'author_name' => '반응작성자',
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
     * 댓글 한 건을 만듭니다.
     *
     * @param  int  $boardId  게시판 ID
     * @param  int  $postId  게시글 ID
     * @param  int  $userId  작성자 ID
     * @param  string  $content  본문
     * @return int 댓글 ID
     */
    private function createComment(int $boardId, int $postId, int $userId, string $content): int
    {
        $id = (int) DB::table((new Comment)->getTable())->insertGetId([
            'board_id' => $boardId,
            'post_id' => $postId,
            'user_id' => $userId,
            'content' => $content,
            'author_name' => '반응댓글작성자',
            'ip_address' => '127.0.0.1',
            'status' => 'published',
            'trigger_type' => 'admin',
            'depth' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table((new Post)->getTable())->where('id', $postId)->increment('comments_count');

        return $id;
    }

    /**
     * 기본 반응 유형(추천·비추천) ID 를 찾습니다.
     *
     * 시더가 이미 넣어 둔 것을 쓴다 — 시드가 기본 유형을 새로 만들면 운영 데이터와 겹친다.
     *
     * @return array<string, int> 코드별 유형 ID
     */
    private function resolveReactionTypes(): array
    {
        $rows = DB::table((new ReactionType)->getTable())
            ->whereIn('code', ['like', 'dislike'])
            ->pluck('id', 'code')
            ->all();

        return [
            'like' => (int) ($rows['like'] ?? 0),
            'dislike' => (int) ($rows['dislike'] ?? ($rows['like'] ?? 0)),
        ];
    }

    /**
     * 비활성 반응 유형을 만듭니다.
     *
     * 이미 비활성으로 돌린 유형으로 쌓인 과거 반응이 목록·필터에 어떻게 보이는지를 재려면
     * 그런 유형이 하나 있어야 한다. 기본 유형을 끄면 다른 화면 실측이 흔들리므로 전용 유형을 둔다.
     *
     * @return int 유형 ID
     */
    private function createInactiveType(): int
    {
        return (int) DB::table((new ReactionType)->getTable())->insertGetId([
            'code' => self::INACTIVE_TYPE_CODE,
            'name' => json_encode(['ko' => '지난 반응(E2E)', 'en' => 'Retired (E2E)'], JSON_UNESCAPED_UNICODE),
            'icon' => 'fa-star',
            'display_order' => 90,
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 반응 한 건을 만들고 대상의 반응 수를 올립니다.
     *
     * 서비스를 부르지 않고 직접 넣는다 — 시드는 훅·활동 로그까지 재현할 필요가 없고,
     * 그쪽은 PHPUnit 이 이미 잰다.
     *
     * @param  int  $userId  회원 ID
     * @param  string  $targetType  대상 구분 (post|comment)
     * @param  int  $targetId  대상 ID
     * @param  int  $reactionTypeId  반응 유형 ID
     * @param  int  $boardId  게시판 ID
     * @return int 반응 레코드 ID
     */
    private function react(int $userId, string $targetType, int $targetId, int $reactionTypeId, int $boardId): int
    {
        $id = (int) DB::table((new Reaction)->getTable())->insertGetId([
            'user_id' => $userId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'reaction_type_id' => $reactionTypeId,
            'board_id' => $boardId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->bumpCount($targetType, $targetId, $boardId, $reactionTypeId);

        return $id;
    }

    /**
     * 신고 한 건(케이스 + 로그)을 만듭니다.
     *
     * 화면이 읽는 것은 **로그**(`boards_report_logs`)이고, 취소 가능 여부는 케이스의 상태로
     * 갈린다. 그래서 둘을 함께 만든다.
     *
     * @param  int  $boardId  게시판 ID
     * @param  int  $postId  게시글 ID
     * @param  int  $reporterId  신고자 ID
     * @param  string  $status  케이스 상태 (pending|review|rejected|…)
     * @param  string  $reason  신고 사유
     * @return int 로그 ID — 취소 엔드포인트가 받는 값
     */
    private function report(int $boardId, int $postId, int $reporterId, string $status, string $reason): int
    {
        $reportId = (int) DB::table((new Report)->getTable())->insertGetId([
            'board_id' => $boardId,
            'target_type' => 'post',
            'target_id' => $postId,
            'status' => $status,
            'last_reported_at' => now(),
            'last_activated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table((new ReportLog)->getTable())->insertGetId([
            'report_id' => $reportId,
            'reporter_id' => $reporterId,
            // 스냅샷은 신고 «당시» 의 내용이다 — 원글이 고쳐지거나 지워져도 무엇을 신고했는지 남는다.
            'snapshot' => json_encode([
                'title' => self::TITLE_WORD.' — 자유',
                'content' => '신고 당시 본문 스냅샷입니다.',
            ], JSON_UNESCAPED_UNICODE),
            'reason_type' => $reason,
            'reason_detail' => 'E2E 시드가 만든 신고입니다.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 대상의 reaction_counts JSON 에서 그 유형의 수를 하나 올립니다.
     *
     * 화면이 이 값을 그대로 읽으므로 시드가 채워 두지 않으면 버튼 옆 숫자가 0 으로 뜬다.
     *
     * @param  string  $targetType  대상 구분 (post|comment)
     * @param  int  $targetId  대상 ID
     * @param  int  $boardId  게시판 ID
     * @param  int  $reactionTypeId  반응 유형 ID
     * @return void
     */
    private function bumpCount(string $targetType, int $targetId, int $boardId, int $reactionTypeId): void
    {
        $table = $targetType === 'comment' ? (new Comment)->getTable() : (new Post)->getTable();

        $query = DB::table($table)->where('id', $targetId);
        if ($targetType === 'comment') {
            // 댓글은 (id, board_id) 복합 기본키다 — 게시판을 빼면 파티션을 가로질러 훑는다.
            $query->where('board_id', $boardId);
        }

        $current = $query->value('reaction_counts');
        $counts = is_string($current) && $current !== '' ? (json_decode($current, true) ?: []) : [];
        $key = (string) $reactionTypeId;
        $counts[$key] = (int) ($counts[$key] ?? 0) + 1;

        $query->update(['reaction_counts' => json_encode($counts, JSON_UNESCAPED_UNICODE)]);
    }
}
