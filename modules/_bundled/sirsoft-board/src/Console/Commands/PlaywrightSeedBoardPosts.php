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
use Illuminate\Support\Str;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Services\BoardPermissionService;

/**
 * Playwright E2E · 브라우저 실측용 「쪽이 여럿인 게시판」 시드 커맨드 (#757).
 *
 * 게시판 목록의 페이지 번호·게시글 행·글쓰기 링크를 재려면 **두 쪽 이상인 공개 게시판**이 있어야 한다.
 * 설치본마다 글 수가 달라 시험이 건너뛰어지고, 화면·API 로 글을 여러 건 만들면 게시판 글 쿨다운
 * (기본 60초)에 막힌다. 이 커맨드가 쪽당 글 수를 작게 둔 임시 게시판과 글을 직접 만든다.
 *
 * 만드는 것 (게시판 `e2e-nav-{tag}` 하나 — 태그로 워커·실행을 가른다):
 *   - 게시판: 쪽당 글 수 `--per-page`(PC·모바일 같게, 기본 2). 권한은 게시판 기본 권한을 붙인 뒤 글 열람을
 *     비로그인·회원·관리자, 글쓰기를 회원·관리자로 **명시**한다 — 운영자가 바꾼 기본 권한 설정에 시험 결과가 흔들리지 않게.
 *   - 게시글 `--posts`건(기본 3 — 쪽당 2 면 2쪽), 제목 「[e2e:{tag}] 시드 글 n」
 *   - 회원 `e2e-nav-{tag}@example.test` — 실제 가입처럼 기본 `user` 역할을 붙이고 토큰을 낸다. 역할 없는 토큰 계정은
 *     「회원」이 아니라서(게시판 읽기부터 403) 회원 갈래를 잴 수 없다.
 *
 * 시드에는 `--tag` 가 반드시 있어야 한다(워커끼리 지우지 않게). 재실행하면 같은 태그의 게시판을 먼저 지우고 다시
 * 만든다(멱등). `--teardown --tag` 는 그 태그만, `--tag` 없는 `--teardown` 은 이 커맨드가 만든 `e2e-nav-*` 전부를 지운다.
 *
 * 보안 가드 (코어 PlaywrightIssueToken 과 동일 3중 패턴):
 *   ① CLI 한정 — `php_sapi_name() === 'cli'`
 *   ② G7_PLAYWRIGHT_BYPASS=1 환경변수 옵트인
 *   ③ APP_DEBUG=true inline override
 *
 * 호출 예시:
 *   G7_PLAYWRIGHT_BYPASS=1 php artisan playwright:seed-board-posts --tag=w0 --json
 *   G7_PLAYWRIGHT_BYPASS=1 php artisan playwright:seed-board-posts --tag=w0 --teardown
 */
class PlaywrightSeedBoardPosts extends Command
{
    /** 게시판 slug 접두 — 뒤에 태그가 붙는다 */
    public const SLUG_PREFIX = 'e2e-nav-';

    /** 명시할 게시판 권한 — 권한 키 → 역할 identifier (목록에 없는 기본 역할은 뗀다) */
    private const ROLE_GRANTS = [
        'posts.read' => ['admin', 'user', 'guest'],
        'posts.write' => ['admin', 'user'],
    ];

    /** 명시 대상인 기본 역할 */
    private const BASE_ROLES = ['admin', 'user', 'guest'];

    /** 시드 회원 이메일 도메인 — 앞에 slug 가 붙는다 */
    private const MEMBER_DOMAIN = '@example.test';

    /**
     * 커맨드 이름 및 시그니처
     *
     * @var string
     */
    protected $signature = 'playwright:seed-board-posts
        {--tag= : 워커·실행 구분 태그(영문 소문자·숫자·하이픈) — 시드에 필수, 정리에서 빼면 전량}
        {--posts=3 : 만들 게시글 수}
        {--per-page=2 : 게시판 쪽당 글 수(PC·모바일)}
        {--teardown : 시드 데이터를 삭제한다}
        {--json : 결과를 JSON 으로 출력}';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = 'Playwright E2E · 쪽이 여럿인 임시 게시판 시드 (CLI + G7_PLAYWRIGHT_BYPASS 가드)';

    /**
     * 커맨드를 실행합니다.
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        // ① CLI 한정
        if (php_sapi_name() !== 'cli') {
            $this->error(__('sirsoft-board::messages.console.playwright_seed.cli_only'));

            return self::FAILURE;
        }

        // ② 명시 옵트인
        if (env('G7_PLAYWRIGHT_BYPASS') !== '1') {
            $this->error(__('sirsoft-board::messages.console.playwright_seed.bypass_required'));

            return self::FAILURE;
        }

        // ③ APP_DEBUG 강제
        Config::set('app.debug', true);

        $tag = $this->option('tag');
        $tag = is_string($tag) && $tag !== '' ? $tag : null;
        $teardown = (bool) $this->option('teardown');

        // 시드는 태그가 필수다. 정리는 태그가 없으면 전량 — 태그가 있으면 형식부터 본다(부작용 전에 거절)
        if (($tag === null && ! $teardown) || ($tag !== null && ! preg_match('/^[a-z0-9-]{1,40}$/', $tag))) {
            $this->error(__('sirsoft-board::messages.console.seed_board_posts.invalid_tag'));

            return self::FAILURE;
        }

        $result = $teardown
            ? DB::transaction(fn () => $this->purge($tag === null ? null : self::SLUG_PREFIX.$tag))
            : $this->seed(self::SLUG_PREFIX.$tag, $tag);

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
     * @param  string  $slug  게시판 slug
     * @param  string  $tag  구분 태그
     * @return array<string, mixed> 만들어진 식별자 모음
     */
    private function seed(string $slug, string $tag): array
    {
        $perPage = max(1, (int) $this->option('per-page'));
        $count = max(1, (int) $this->option('posts'));

        return DB::transaction(function () use ($slug, $tag, $perPage, $count) {
            $this->purge($slug);

            $boardId = (int) DB::table((new Board)->getTable())->insertGetId([
                'slug' => $slug,
                'name' => json_encode(['ko' => '쪽 이동 실측(E2E) '.$tag, 'en' => $slug], JSON_UNESCAPED_UNICODE),
                'is_active' => true,
                'use_comment' => true,
                'per_page' => $perPage,
                'per_page_mobile' => $perPage,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 관리 화면을 거치지 않고 만든 게시판에는 게시판별 권한이 없다 — 실제 생성 경로와 같은
            // 서비스로 권한 행을 만든 뒤, 시험이 기대는 열람·글쓰기 역할을 명시한다.
            $board = Board::find($boardId);
            if ($board !== null) {
                app(BoardPermissionService::class)->ensureBoardPermissions($board);
            }
            $this->grantRoles($slug);

            // 작성 시각을 1초씩 벌려 최신순 정렬이 흔들리지 않게 한다(마지막 글이 1쪽 맨 위)
            $postIds = [];
            $base = now()->subMinutes($count);
            for ($i = 1; $i <= $count; $i++) {
                $at = $base->copy()->addSeconds($i);
                $postIds[] = (int) DB::table((new Post)->getTable())->insertGetId([
                    'board_id' => $boardId,
                    'user_id' => null,
                    'title' => "[e2e:{$tag}] 시드 글 {$i}",
                    'content' => "[e2e:{$tag}] 시드 글 {$i} 본문입니다.",
                    'author_name' => 'E2E',
                    'ip_address' => '203.0.113.30',
                    'status' => 'published',
                    'trigger_type' => 'admin',
                    'view_count' => 0,
                    'created_at' => $at,
                    'updated_at' => $at,
                ]);
            }

            $member = $this->createMember($slug.self::MEMBER_DOMAIN, '쪽 이동 실측 회원 '.$tag);

            return [
                'board_slug' => $slug,
                'post_ids' => $postIds,
                'per_page' => $perPage,
                'last_page' => (int) ceil($count / $perPage),
                'member_email' => $member->email,
                'member_token' => $member->createToken('playwright-board-posts')->plainTextToken,
            ];
        });
    }

    /**
     * 시드 회원을 만듭니다 — 실제 가입(AuthService::register)과 같이 기본 `user` 역할을 붙인다.
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
            'password' => Hash::make(Str::random(32)),
            'email_verified_at' => now(),
        ]);

        $role = Role::where('identifier', 'user')->first();
        if ($role !== null) {
            $user->roles()->sync([$role->id]);
        }

        return $user;
    }

    /**
     * 글 열람·글쓰기 권한의 기본 역할을 명시합니다(열람: 비로그인·회원·관리자, 글쓰기: 회원·관리자 — 비로그인은 뗀다).
     *
     * @param  string  $slug  게시판 slug
     * @return void 반환값 없음
     */
    private function grantRoles(string $slug): void
    {
        $roleIds = Role::query()->whereIn('identifier', self::BASE_ROLES)->pluck('id', 'identifier');

        foreach (self::ROLE_GRANTS as $key => $roles) {
            $permission = Permission::where('identifier', "sirsoft-board.{$slug}.{$key}")->first();
            if ($permission === null) {
                continue;
            }

            $grant = $roleIds->only($roles)->values()->all();
            if ($grant !== []) {
                $permission->roles()->syncWithoutDetaching($grant);
            }

            $revoke = $roleIds->except($roles)->values()->all();
            if ($revoke !== []) {
                $permission->roles()->detach($revoke);
            }
        }
    }

    /**
     * 시드 게시판과 딸린 글·권한·회원을 지웁니다 (재실행 멱등의 핵심).
     *
     * @param  string|null  $slug  게시판 slug (null 이면 이 커맨드가 만든 `e2e-nav-*` 전부)
     * @return array<string, int> 삭제 건수
     */
    private function purge(?string $slug): array
    {
        $boardQuery = DB::table((new Board)->getTable());
        if ($slug === null) {
            $boardQuery->where('slug', 'like', self::SLUG_PREFIX.'%');
        } else {
            $boardQuery->where('slug', $slug);
        }
        $slugs = $boardQuery->pluck('slug', 'id')->all();
        $boardIds = array_keys($slugs);

        // 회원은 게시판 행이 아니라 이메일로 찾는다 — 게시판만 지워진 회차의 회원도 남기지 않게.
        $userQuery = DB::table((new User)->getTable());
        if ($slug === null) {
            $userQuery->where('email', 'like', self::SLUG_PREFIX.'%'.self::MEMBER_DOMAIN);
        } else {
            $userQuery->where('email', $slug.self::MEMBER_DOMAIN);
        }
        $userIds = $userQuery->pluck('id')->all();

        $posts = $boardIds === [] ? 0 : DB::table((new Post)->getTable())->whereIn('board_id', $boardIds)->delete();
        $boards = $boardIds === [] ? 0 : DB::table((new Board)->getTable())->whereIn('id', $boardIds)->delete();

        // 게시판별 권한은 identifier 접두로 전량 지운다(보유기간·스크랩 시드와 같은 규약).
        $permissions = 0;
        foreach ($slugs as $boardSlug) {
            $prefix = 'sirsoft-board.'.$boardSlug;
            foreach (Permission::where('identifier', $prefix)->orWhere('identifier', 'like', $prefix.'.%')->get() as $row) {
                $row->roles()->detach();
                $row->delete();
                $permissions++;
            }
        }

        $activities = 0;
        $users = 0;
        if ($userIds !== []) {
            $activities = DB::table((new ActivityLog)->getTable())->whereIn('user_id', $userIds)->delete();
            foreach (User::whereIn('id', $userIds)->get() as $user) {
                $user->tokens()->delete();
                $user->roles()->detach();
            }
            $users = DB::table((new User)->getTable())->whereIn('id', $userIds)->delete();
        }

        return [
            'deleted_posts' => $posts,
            'deleted_boards' => $boards,
            'deleted_board_permissions' => $permissions,
            'deleted_activity_logs' => $activities,
            'deleted_users' => $users,
        ];
    }
}
