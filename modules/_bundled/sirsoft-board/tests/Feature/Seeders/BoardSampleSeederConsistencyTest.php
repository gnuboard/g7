<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Seeders;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Sirsoft\Board\Database\Seeders\ActivityLogSampleSeeder;
use Modules\Sirsoft\Board\Database\Seeders\BoardReactionTypeSeeder;
use Modules\Sirsoft\Board\Database\Seeders\BoardTypeSeeder;
use Modules\Sirsoft\Board\Database\Seeders\Sample\BoardSampleSeeder;
use Modules\Sirsoft\Board\Database\Seeders\Sample\PostSampleSeeder;
use Modules\Sirsoft\Board\Database\Seeders\Sample\ReactionScrapSampleSeeder;
use Modules\Sirsoft\Board\Database\Seeders\Sample\ReportSampleSeeder;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Models\Report;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * 게시판 샘플 시더 정합 시험 (7.1.0 출시 점검 리포트 2.2)
 *
 * 샘플 시더를 실제로 돌린 뒤 데이터가 운영 경로와 같은 불변식을 지키는지, 그리고 재시드가
 * 운영 데이터(운영자 게시판·신고·활동 로그)를 지우지 않는지 고정한다.
 */
class BoardSampleSeederConsistencyTest extends ModuleTestCase
{
    /**
     * 시더가 찾는 관리자·활성 회원·차단 회원을 만든다.
     *
     * @return array{admin: User, blocked: User} 만든 회원
     */
    private function prepareUsers(): array
    {
        Storage::fake('modules');
        $this->clearLeftoverSampleData();

        $adminRole = Role::where('identifier', 'admin')->first();
        $userRole = Role::where('identifier', 'user')->first();

        $admin = User::factory()->create(['is_super' => true, 'status' => 'active']);
        $admin->roles()->attach($adminRole->id);

        for ($i = 0; $i < 8; $i++) {
            User::factory()->create(['status' => 'active'])->roles()->attach($userRole->id);
        }

        $blocked = User::factory()->create(['status' => 'blocked']);
        $blocked->roles()->attach($userRole->id);

        return ['admin' => $admin, 'blocked' => $blocked];
    }

    /**
     * 같은 프로세스의 앞선 시험이 남긴 샘플 데이터를 이 시험의 트랜잭션 안에서 비운다.
     *
     * 기반 클래스는 프로세스의 첫 시험 setUp 에서 트랜잭션을 연 뒤 migrate:fresh(DDL)를 실행하므로
     * 그 트랜잭션이 암묵 커밋돼 첫 시험의 데이터가 남는다. 남은 샘플 게시판이 있으면 시더가 「이미 있음」으로
     * 건너뛰어 이 시험의 전제가 깨지므로, 시작 전에 지운다(트랜잭션 안이라 시험 뒤 되돌아간다).
     *
     * @return void 반환값 없음
     */
    private function clearLeftoverSampleData(): void
    {
        DB::table('board_reactions')->delete();
        DB::table('board_post_scraps')->delete();
        DB::table('boards_report_logs')->delete();
        DB::table('boards_reports')->delete();
        DB::table('board_attachments')->delete();
        DB::table('board_comments')->delete();
        DB::table('board_posts')->delete();
        DB::table('board_stats')->delete();
        DB::table('activity_logs')->where('description_key', 'like', 'sirsoft-board::%')->delete();
        DB::table('boards')->delete();
    }

    /**
     * 게시판 샘플 시더 7단계 중 데이터 시더를 순서대로 돌린다.
     *
     * 글 수는 기본(160건)의 30% 로 줄여 시험 부하를 낮춘다 — 불변식은 건수와 무관하다.
     *
     * @return void 반환값 없음
     */
    private function runSampleSeeders(): void
    {
        $command = new class extends Command
        {
            /** @var string 명령 이름 */
            protected $name = 'test:board-sample-seed';
        };
        $command->setLaravel($this->app);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));

        foreach ([
            BoardTypeSeeder::class,
            BoardReactionTypeSeeder::class,
            BoardSampleSeeder::class,
            PostSampleSeeder::class,
            ReactionScrapSampleSeeder::class,
            ReportSampleSeeder::class,
            ActivityLogSampleSeeder::class,
        ] as $class) {
            $seeder = $this->app->make($class);
            $seeder->setContainer($this->app)->setCommand($command);
            if (method_exists($seeder, 'setSeederCounts')) {
                $seeder->setSeederCounts(['posts' => 48]);
            }
            $seeder->__invoke();
        }
    }

    /**
     * 샘플 시드 한 번으로 게시판 설정·게시글·반응·신고 불변식을 모두 확인한다 (시드 1회로 부하 최소화).
     *
     * @scenario case=fresh_seed
     *
     * @effects sample_boards_merge_module_defaults_and_vary_demo_settings, sample_posts_keep_cascade_author_time_and_count_invariants, sample_reactions_respect_board_settings_and_self_rule, sample_reports_apply_outcomes_like_report_service
     *
     * @return void 반환값 없음
     */
    public function test_fresh_sample_seed_keeps_operational_invariants(): void
    {
        ['blocked' => $blocked] = $this->prepareUsers();
        $this->runSampleSeeders();

        $this->assertBoardSettings();
        $this->assertPostInvariants($blocked);
        $this->assertReactionInvariants();
        $this->assertReportInvariants();
    }

    /**
     * 샘플 게시판 설정: 모듈 기본값 병합(반응 켜짐), 시연 다양화, 상품 문의 게시판.
     *
     * @return void 반환값 없음
     */
    private function assertBoardSettings(): void
    {
        $boards = DB::table('boards')->get()->keyBy('slug');

        foreach (['notice', 'free', 'gallery', 'event', 'qna', 'members', 'inquiry', 'archive', 'product-inquiry'] as $slug) {
            $this->assertTrue($boards->has($slug), "{$slug} 샘플 게시판이 있어야 합니다.");
            $this->assertNotNull($boards[$slug]->active_reaction_types, "{$slug}: active_reaction_types 가 NULL 이면 반응이 꺼진다(B-1).");
        }

        $this->assertSame(['like', 'dislike'], json_decode($boards['free']->active_reaction_types, true));
        $this->assertSame(['like'], json_decode($boards['gallery']->active_reaction_types, true));
        $this->assertSame(0, (int) $boards['notice']->use_reaction);
        $this->assertSame('block', $boards['qna']->reply_delete_policy);
        $this->assertSame(0, (int) $boards['qna']->use_comment_reaction);

        // B-23: 지정 안 한 입력 제한은 DB 기본값(10)이 아니라 모듈 기본값(2)
        $this->assertSame(2, (int) $boards['gallery']->min_content_length);

        // B-20: archive 는 모듈 기본 금지어 목록을 명시
        $defaults = (array) g7_module_settings('sirsoft-board', 'basic_defaults.blocked_keywords', []);
        $this->assertSame(array_values($defaults), json_decode($boards['archive']->blocked_keywords, true));

        // B-16: 상품 문의 게시판 — 비밀글 필수, 답글 1단, 댓글·반응·스크랩 끔
        $pi = $boards['product-inquiry'];
        $this->assertSame('always', $pi->secret_mode);
        $this->assertSame(1, (int) $pi->max_reply_depth);
        $this->assertSame(0, (int) $pi->use_comment);
        $this->assertSame(0, (int) $pi->use_reaction);
        $this->assertSame(0, (int) $pi->use_scrap);
        $this->assertSame(0, DB::table('board_posts')->where('board_id', $pi->id)->count(), '상품 문의 글은 이커머스 시더가 만든다.');

        // SB-4: 사이트 기본 언어 이름이 비지 않는다
        $siteLocale = config('app.site_locale');
        foreach ($boards as $slug => $board) {
            $name = json_decode($board->name, true);
            $this->assertNotEmpty($name[$siteLocale] ?? null, "{$slug}: 사이트 기본 언어 이름이 비어 있다.");
        }
    }

    /**
     * 게시글·댓글·첨부 불변식: 삭제 원글 연쇄, 공지 작성자, 시각 순서, 카운트 일치.
     *
     * @param  User  $blocked  차단 회원 (작성자가 되면 안 된다)
     * @return void 반환값 없음
     */
    private function assertPostInvariants(User $blocked): void
    {
        $adminIds = DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->where('r.identifier', 'admin')->pluck('ur.user_id')->all();

        // B-2: 삭제 원글 아래 살아 있는 답글·댓글·첨부 0
        $this->assertSame(0, DB::table('board_posts as c')->join('board_posts as p', 'p.id', '=', 'c.parent_id')
            ->whereNotNull('p.deleted_at')->whereNull('c.deleted_at')->count());
        $this->assertSame(0, DB::table('board_comments as c')->join('board_posts as p', 'p.id', '=', 'c.post_id')
            ->whereNotNull('p.deleted_at')->whereNull('c.deleted_at')->count());
        $this->assertSame(0, DB::table('board_attachments as a')->join('board_posts as p', 'p.id', '=', 'a.post_id')
            ->whereNotNull('p.deleted_at')->whereNull('a.deleted_at')->count());

        // block 정책 게시판: 살아 있는 답글이 달린 삭제 원글 없음
        $qnaId = DB::table('boards')->where('slug', 'qna')->value('id');
        $this->assertSame(0, DB::table('board_posts as p')->where('p.board_id', $qnaId)->whereNotNull('p.deleted_at')
            ->whereExists(fn ($q) => $q->from('board_posts as r')->whereColumn('r.parent_id', 'p.id')->where('r.trigger_type', '!=', 'cascade'))
            ->count());

        // B-11: 공지는 관리자만, 관리자 전용 게시판(notice·event)도 관리자만
        $this->assertSame(0, DB::table('board_posts')->where('is_notice', true)->whereNotIn('user_id', $adminIds)->count());
        $adminOnly = DB::table('boards')->whereIn('slug', ['notice', 'event'])->pluck('id');
        $this->assertSame(0, DB::table('board_posts')->whereIn('board_id', $adminOnly)
            ->where(fn ($q) => $q->whereNull('user_id')->orWhereNotIn('user_id', $adminIds))->count());

        // B-12: 차단 회원은 작성자가 아니다
        $this->assertSame(0, DB::table('board_posts')->where('user_id', $blocked->id)->count());
        $this->assertSame(0, DB::table('board_comments')->where('user_id', $blocked->id)->count());

        // B-24: 비회원 글의 첨부 소유자는 비어 있다
        $this->assertSame(0, DB::table('board_attachments as a')->join('board_posts as p', 'p.id', '=', 'a.post_id')
            ->whereNull('p.user_id')->whereNotNull('a.created_by')->count());

        // 시각 순서: 자식 ≥ 부모, 삭제 ≥ 작성, 미래 없음
        $this->assertSame(0, DB::table('board_posts as c')->join('board_posts as p', 'p.id', '=', 'c.parent_id')
            ->whereColumn('c.created_at', '<', 'p.created_at')->count());
        $this->assertSame(0, DB::table('board_comments as c')->join('board_posts as p', 'p.id', '=', 'c.post_id')
            ->whereColumn('c.created_at', '<', 'p.created_at')->count());
        $this->assertSame(0, DB::table('board_posts')->whereColumn('deleted_at', '<', 'created_at')->count());
        $this->assertSame(0, DB::table('board_posts')->where('created_at', '>', now()->addMinute())->count());

        // B-22: 조치 이력 시각은 Y-m-d H:i:s
        foreach (DB::table('board_posts')->whereNotNull('action_logs')->pluck('action_logs') as $raw) {
            foreach (json_decode($raw, true) as $log) {
                $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $log['created_at']);
            }
        }

        // 댓글 상태 분포 시연(블라인드·작성자 삭제·비밀 댓글)이 생긴다
        $this->assertGreaterThan(0, DB::table('board_comments')->where('status', 'blinded')->count());

        // 카운트 일치: posts_count·comments_count·scrap_count·reaction_counts
        $alivePosts = DB::table('board_posts')->whereNull('deleted_at')->selectRaw('board_id, COUNT(*) AS n')->groupBy('board_id')->pluck('n', 'board_id');
        $aliveComments = DB::table('board_comments')->whereNull('deleted_at')->selectRaw('board_id, COUNT(*) AS n')->groupBy('board_id')->pluck('n', 'board_id');
        foreach (DB::table('boards')->get(['id', 'slug', 'posts_count', 'comments_count']) as $board) {
            $this->assertSame((int) ($alivePosts[$board->id] ?? 0), (int) $board->posts_count, "{$board->slug}: posts_count 불일치");
            $this->assertSame((int) ($aliveComments[$board->id] ?? 0), (int) $board->comments_count, "{$board->slug}: comments_count 불일치");
        }
        $scraps = DB::table('board_post_scraps')->selectRaw('post_id, COUNT(*) AS n')->groupBy('post_id')->pluck('n', 'post_id');
        foreach (DB::table('board_posts')->get(['id', 'scrap_count']) as $post) {
            $this->assertSame((int) ($scraps[$post->id] ?? 0), (int) $post->scrap_count, "글 {$post->id}: scrap_count 불일치");
        }

        $actual = DB::table('board_reactions')->where('target_type', 'post')
            ->selectRaw('target_id, reaction_type_id, COUNT(*) AS n')->groupBy('target_id', 'reaction_type_id')->get();
        $this->assertGreaterThan(0, $actual->count(), '반응 샘플이 있어야 한다(B-14).');
        foreach ($actual as $row) {
            $counts = json_decode((string) DB::table('board_posts')->where('id', $row->target_id)->value('reaction_counts'), true);
            $this->assertSame((int) $row->n, (int) ($counts[(string) $row->reaction_type_id] ?? -1));
        }

        // B-15: 대시보드 집계 행
        $this->assertGreaterThan(0, DB::table('board_stats')->count());

        // B-25: 쿼리 이벤트 디스패처가 되돌아왔다
        $this->assertNotNull(DB::connection()->getEventDispatcher());
    }

    /**
     * 반응·스크랩 불변식: 본인 반응 없음, 켠 유형만, 첫 일반 회원 보장.
     *
     * @return void 반환값 없음
     */
    private function assertReactionInvariants(): void
    {
        // 본인 글 반응 0
        $this->assertSame(0, DB::table('board_reactions as r')->join('board_posts as p', 'p.id', '=', 'r.target_id')
            ->where('r.target_type', 'post')->whereColumn('p.user_id', 'r.user_id')->count());
        // 반응이 꺼진 게시판·댓글 반응이 꺼진 게시판에는 반응 없음
        $off = DB::table('boards')->where('use_reaction', false)->pluck('id');
        $this->assertSame(0, DB::table('board_reactions')->whereIn('board_id', $off)->count());
        $commentOff = DB::table('boards')->where('use_comment_reaction', false)->pluck('id');
        $this->assertSame(0, DB::table('board_reactions')->where('target_type', 'comment')->whereIn('board_id', $commentOff)->count());
        // 게시판에서 켜지 않은 유형(비추천)은 쓰지 않는다
        $dislikeId = DB::table('board_reaction_types')->where('code', 'dislike')->value('id');
        $likeOnly = DB::table('boards')->whereRaw("JSON_CONTAINS(active_reaction_types, '\"dislike\"') = 0")->pluck('id');
        $this->assertSame(0, DB::table('board_reactions')->whereIn('board_id', $likeOnly)->where('reaction_type_id', $dislikeId)->count());
        // 비밀글에는 반응 없음 (반응 뒤 신고 조치로 블라인드·삭제된 글의 반응은 운영에서도 남으므로 상태는 보지 않는다)
        $this->assertSame(0, DB::table('board_reactions as r')->join('board_posts as p', 'p.id', '=', 'r.target_id')
            ->where('r.target_type', 'post')->where('p.is_secret', true)->count());

        // 첫 일반 회원은 반응·스크랩을 1건 이상 갖는다
        $member = DB::table('users')->where('status', 'active')->where('is_super', false)->orderBy('id')->value('id');
        $this->assertGreaterThan(0, DB::table('board_reactions')->where('user_id', $member)->count());
        $this->assertGreaterThan(0, DB::table('board_post_scraps')->where('user_id', $member)->count());
    }

    /**
     * 신고 불변식: 표식, 본인 신고 없음, 조치 반영, 시각 순서.
     *
     * @return void 반환값 없음
     */
    private function assertReportInvariants(): void
    {
        $reports = Report::with('logs')->get();
        $this->assertGreaterThan(0, $reports->count());

        foreach ($reports as $report) {
            $this->assertTrue((bool) ($report->metadata['sample'] ?? false), '샘플 신고는 표식을 단다(B-4).');

            foreach ($report->logs as $log) {
                if ($report->author_id !== null) {
                    $this->assertNotSame($report->author_id, $log->reporter_id, '본인 글은 신고하지 않는다(B-13).');
                }
                $this->assertTrue($log->created_at->greaterThanOrEqualTo($report->created_at));
            }

            if ($report->processed_at) {
                $this->assertTrue($report->processed_at->greaterThanOrEqualTo($report->created_at), '처리는 접수 뒤(B-13).');
            }

            // 신고 항목마다 reported 이력이 붙는다 (재접수 케이스 제외)
            if ($report->last_activated_at === null && $report->board_id !== null) {
                $reported = collect($report->process_histories)->where('type', 'reported')->count();
                $this->assertSame($report->logs->count(), $reported);
                $this->assertSame('reported', collect($report->process_histories)->first()['action_label']);
            }

            if ($report->board_id === null) {
                continue;
            }

            $table = $report->target_type->value === 'post' ? 'board_posts' : 'board_comments';
            $target = DB::table($table)->where('id', $report->target_id)->first();

            // B-3: 게시 중단 → 블라인드, 삭제 → 삭제 (block 정책 대상 제외는 시더가 고르지 않는다)
            if ($report->status->value === 'suspended') {
                $this->assertSame('blinded', $target->status);
            }
            if ($report->status->value === 'deleted') {
                $this->assertNotNull($target->deleted_at);
            }
        }

        // 자동 블라인드 기준 이상 신고된 활성 케이스는 대상이 auto_hide 블라인드
        $threshold = (int) g7_module_settings('sirsoft-board', 'report_policy.auto_hide_threshold', 0);
        if ($threshold > 0) {
            $many = $reports->first(fn ($r) => $r->status->value === 'pending' && $r->logs->count() >= $threshold);
            $this->assertNotNull($many, '대량 신고 케이스가 있어야 한다.');
            $table = $many->target_type->value === 'post' ? 'board_posts' : 'board_comments';
            $target = DB::table($table)->where('id', $many->target_id)->first();
            $this->assertSame('blinded', $target->status);
            $this->assertSame('auto_hide', $target->trigger_type);
        }
    }

    /**
     * 사이트 기본 언어가 ko·en 이 아니고 그 언어팩 시드가 없어도 샘플 게시판 이름·설명은 그 언어 값을 갖는다(SB-4).
     *
     * 관리자 게시판 수정 화면은 사이트 기본 언어 이름을 필수로 요구한다. 값이 비면 샘플 게시판을 수정할 수 없다.
     *
     * @scenario case=site_locale_not_ko_en
     *
     * @effects sample_board_names_fill_site_locale_fallback
     *
     * @return void 반환값 없음
     */
    public function test_sample_board_names_fill_site_locale_fallback(): void
    {
        $this->prepareUsers();

        $command = new class extends Command
        {
            /** @var string 명령 이름 */
            protected $name = 'test:board-sample-seed-locale';
        };
        $command->setLaravel($this->app);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));

        foreach ([BoardTypeSeeder::class, BoardReactionTypeSeeder::class] as $class) {
            $this->app->make($class)->setContainer($this->app)->setCommand($command)->__invoke();
        }

        // 설치 시더가 설정을 다시 읽을 수 있으므로 게시판 샘플 시더 직전에 사이트 기본 언어를 바꾼다
        config(['app.site_locale' => 'ja']);
        $this->app->make(BoardSampleSeeder::class)->setContainer($this->app)->setCommand($command)->__invoke();
        $this->assertSame('ja', config('app.site_locale'), '시드 중 사이트 기본 언어 설정이 바뀌면 안 된다.');

        // 다국어 fallback chain — app.fallback_locale 값으로 채운다
        $fallback = config('app.fallback_locale', 'ko');
        foreach (DB::table('boards')->get() as $board) {
            $name = json_decode($board->name, true);
            $description = json_decode((string) $board->description, true) ?? [];
            $this->assertSame($name[$fallback], $name['ja'] ?? null, "{$board->slug}: 사이트 기본 언어(ja) 이름은 fallback 언어 값으로 채운다.");
            if (isset($description[$fallback])) {
                $this->assertSame($description[$fallback], $description['ja'] ?? null);
            }
        }
    }

    /**
     * 운영 중인 사이트(샘플 slug 게시판에 이미 글이 있고, 그 글 작성자가 탈퇴로 지워진 상태)에서 --sample 을 돌려도
     * 활동 로그 시더가 실패하지 않고, 시더가 만들지 않은 글에 「생성」 등 감사 로그를 지어내지 않는다.
     *
     * 실측(개발 DB 사본): 기존 글 713건이 없는 회원을 가리켜 activity_logs.user_id FK 로 중단됐고,
     * 그 전에 기존 글 478건에 실제 회원 명의의 가짜 로그가 만들어졌다.
     *
     * @scenario case=operational_site_existing_posts
     *
     * @effects sample_logs_only_for_resources_created_in_this_run
     *
     * @return void 반환값 없음
     */
    public function test_sample_logs_skip_existing_posts_and_missing_members(): void
    {
        $this->prepareUsers();

        // 운영 중인 free 게시판 + 탈퇴(행 없음) 회원이 쓴 글 + 실제 회원이 쓴 글
        $boardId = DB::table('boards')->insertGetId([
            'name' => json_encode(['ko' => '자유게시판', 'en' => 'Free Board']),
            'slug' => 'free',
            'type' => 'basic',
            'created_at' => now()->subYear(),
            'updated_at' => now()->subYear(),
        ]);
        $memberId = (int) DB::table('users')->where('status', 'active')->where('is_super', false)->value('id');
        $orphanPostId = $this->insertOperatorPost($boardId, 9_999_999);
        $memberPostId = $this->insertOperatorPost($boardId, $memberId);

        $this->runSampleSeeders();

        $this->assertSame(0, DB::table('activity_logs')->whereIn('loggable_id', [$orphanPostId, $memberPostId])
            ->where('loggable_type', Post::class)->count(),
            '시더가 만들지 않은 글에 활동 로그를 만들면 안 된다.');
        $this->assertSame(0, DB::table('activity_logs as l')->leftJoin('users as u', 'u.id', '=', 'l.user_id')
            ->whereNotNull('l.user_id')->whereNull('u.id')->count(), '활동 로그 행위자는 실재 회원이어야 한다.');
        $this->assertGreaterThan(0, DB::table('activity_logs')->where('properties', 'like', '%"seeded_by":"sirsoft-board:sample"%')->count(),
            '새로 만든 샘플 게시판·글의 로그는 만들어져야 한다.');

        // 운영 글에 회원 명의 반응·스크랩·신고도 지어내지 않는다
        $operatorPosts = [$orphanPostId, $memberPostId];
        $this->assertSame(0, DB::table('board_reactions')->where('target_type', 'post')->whereIn('target_id', $operatorPosts)->count());
        $this->assertSame(0, DB::table('board_post_scraps')->whereIn('post_id', $operatorPosts)->count());
        $this->assertSame(0, DB::table('boards_reports')->where('target_type', 'post')->whereIn('target_id', $operatorPosts)->count());
    }

    /**
     * 운영자 글 1건을 직접 넣는다 (시더 밖에서 만든 글).
     *
     * @param  int  $boardId  게시판 ID
     * @param  int  $userId  작성자 ID (없는 회원일 수 있다)
     * @return int 글 ID
     */
    private function insertOperatorPost(int $boardId, int $userId): int
    {
        return (int) DB::table('board_posts')->insertGetId([
            'board_id' => $boardId,
            'title' => '운영 글',
            'content' => '운영 중 작성된 글',
            'content_mode' => 'text',
            'user_id' => $userId,
            'ip_address' => '10.0.0.1',
            'status' => 'published',
            'trigger_type' => 'admin',
            'created_at' => now()->subMonths(2),
            'updated_at' => now()->subMonths(2),
        ]);
    }

    /**
     * 재시드는 운영 데이터(운영자 게시판·신고·활동 로그)를 지우지 않고, 샘플 로그를 중복하지 않는다.
     *
     * @scenario case=reseed_preserves_operator_data
     *
     * @effects reseed_keeps_operator_reports_logs_and_boards
     *
     * @return void 반환값 없음
     */
    public function test_reseed_preserves_operator_data(): void
    {
        ['admin' => $admin] = $this->prepareUsers();

        // 운영자 게시판(샘플 slug 아님) + 운영 신고 + 운영 활동 로그
        $operatorBoardId = DB::table('boards')->insertGetId([
            'name' => json_encode(['ko' => '운영 게시판', 'en' => 'Operator']),
            'slug' => 'operator-board',
            'type' => 'basic',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $operatorReport = Report::create([
            'board_id' => $operatorBoardId,
            'target_type' => 'post',
            'target_id' => 1,
            'status' => 'rejected',
            'metadata' => ['ip' => '127.0.0.1'],
            'process_histories' => [],
        ]);
        $operatorLogId = DB::table('activity_logs')->insertGetId([
            'log_type' => 'admin',
            'user_id' => $admin->id,
            'action' => 'board.update',
            'description_key' => 'sirsoft-board::activity_log.description.board_update',
            'description_params' => json_encode(['board_name' => ['ko' => '운영 게시판']]),
            'properties' => json_encode(['extension_origin' => 'sirsoft-board']),
            'created_at' => now()->subYear(),
        ]);

        $this->runSampleSeeders();
        $this->runSampleSeeders();

        $this->assertDatabaseHas('boards_reports', ['id' => $operatorReport->id]);
        $this->assertDatabaseHas('activity_logs', ['id' => $operatorLogId]);
        $this->assertSame(0, DB::table('board_posts')->where('board_id', $operatorBoardId)->count(), '운영자 게시판에 샘플 글을 넣지 않는다(B-21).');

        // 샘플 로그는 재시드 때 지우고 다시 만든다 — 글마다 생성 로그는 1건
        $dup = DB::table('activity_logs')
            ->where('action', 'post.create')
            ->where('properties', 'like', '%"seeded_by":"sirsoft-board:sample"%')
            ->selectRaw('loggable_id, COUNT(*) AS n')->groupBy('loggable_id')->having('n', '>', 1)->count();
        $this->assertSame(0, $dup);

        // 조회 로그(show·index)는 만들지 않는다(B-9)
        $this->assertSame(0, ActivityLog::where('description_key', 'like', 'sirsoft-board::activity_log.description.%_show')
            ->orWhere('description_key', 'like', 'sirsoft-board::activity_log.description.%_index')->count());
    }
}
