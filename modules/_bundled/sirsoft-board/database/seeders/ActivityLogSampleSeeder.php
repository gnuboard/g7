<?php

namespace Modules\Sirsoft\Board\Database\Seeders;

use App\Enums\ActivityLogType;
use App\Models\ActivityLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Database\Seeders\Sample\Concerns\BoardSampleMarker;
use Modules\Sirsoft\Board\Database\Seeders\Sample\Concerns\SampleRunRegistry;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Models\ReactionType;
use Modules\Sirsoft\Board\Models\Report;
use Modules\Sirsoft\Board\Models\ReportLog;

/**
 * 게시판 활동 로그 샘플 시더
 *
 * 이번 샘플 실행에서 시더가 만든 데이터(게시판·글·댓글·첨부·반응·스크랩)와 샘플 신고를 따라, 리스너
 * (BoardActivityLogListener)가 남기는 것과 같은 키·속성 모양의 활동 로그를 만듭니다.
 *
 * 불변식:
 * - 시더가 만들지 않은 리소스(운영 중인 게시판의 기존 글 등)에는 로그를 만들지 않는다 — 실제 회원·운영자
 *   명의로 하지 않은 행동의 감사 기록이 생기기 때문이다. 범위는 SampleRunRegistry(이번 실행의 id 범위).
 *   그래서 이 시더만 따로 돌리면 샘플 신고 로그만 만든다.
 * - 행위자(user_id)는 실재 회원만 — 작성자가 탈퇴해 행이 없으면 비운다(activity_logs.user_id FK).
 * - 이전 실행이 남긴 샘플 로그(properties.seeded_by='sirsoft-board:sample')는 그 실행이 만든 리소스의 기록이라
 *   남긴다. 다시 만드는 샘플 신고의 로그만 지우고 새로 만든다. 운영 로그는 지우지 않는다(B-5).
 * - 리스너가 남기는 액션만 만든다 — 조회(show·index) 로그는 없다(B-9). 게시판 유형은 설치 시더가 만드는
 *   시스템 데이터라 「관리자가 만들었다」는 로그를 만들지 않는다. 1.2.0 반응·스크랩·신고 취소·
 *   첨부 다운로드·메뉴 제거·일괄 적용 중단 키를 포함한다(SB-2).
 * - 게시판 이름은 다국어 배열 그대로 넣어 보는 사람의 언어로 표시되게 한다(B-8). 첨부 로그의
 *   게시물 번호는 post_id 다(B-7).
 * - 리소스마다 수명주기 순서(생성 → 수정 → 조치)로 최대 5건, 시각은 리소스 생성 뒤 ~ 지금(B-9·B-10).
 * - 500건씩 일괄 insert 한다(B-10). properties 는 리스너 모양 + extension_origin(B-27).
 */
class ActivityLogSampleSeeder extends Seeder
{
    /** @var string 다국어 키 접두사 */
    private const PREFIX = 'sirsoft-board::activity_log.description.';

    /** @var string 활동 로그 출처 확장 식별자 (리스너의 extension_origin 과 같다) */
    private const ORIGIN = 'sirsoft-board';

    /** @var int 리소스당 최대 로그 수 */
    private const MAX_PER_RESOURCE = 5;

    /** @var int 일괄 insert 크기 */
    private const CHUNK = 500;

    /** @var array<string> 샘플 IP 목록 */
    private const IPS = ['192.168.1.10', '10.0.0.5', '172.16.0.1', '192.168.0.100', '10.10.10.1'];

    /** @var array<string> 샘플 User-Agent 목록 */
    private const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/125.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
        'Mozilla/5.0 (X11; Linux x86_64) Firefox/126.0',
    ];

    /** @var array<int, array<string, mixed>> insert 대기 행 */
    private array $buffer = [];

    /** @var int 만든 로그 수 */
    private int $created = 0;

    /** @var array<int, bool> 회원 실재 여부 캐시 */
    private array $userExists = [];

    /** @var array<int, int> 관리자 ID 목록 */
    private array $adminIds = [];

    /** @var array<int, int> 활성 일반 회원 ID 목록 */
    private array $userIds = [];

    /**
     * 시더를 실행합니다.
     *
     * @return void 반환값 없음
     */
    public function run(): void
    {
        $this->adminIds = User::whereHas('roles', fn ($q) => $q->where('identifier', 'admin'))->pluck('id')->all();
        if ($this->adminIds === []) {
            $this->command->warn('관리자 사용자가 없어 게시판 활동 로그 시더를 건너뜁니다.');

            return;
        }

        $this->userIds = User::where('status', 'active')
            ->whereNotIn('id', $this->adminIds)
            ->orderBy('id')
            ->limit(50)
            ->pluck('id')
            ->all() ?: $this->adminIds;

        $this->purgeSampleReportLogs();

        $boards = Board::whereIn('slug', BoardSampleMarker::SAMPLE_BOARD_SLUGS)->get();
        if ($boards->isEmpty()) {
            $this->command->warn('샘플 게시판이 없어 게시판 활동 로그를 건너뜁니다.');

            return;
        }

        $this->seedBoardLogs($boards);
        $this->seedPostLogs($boards);
        $this->seedCommentLogs($boards);
        $this->seedAttachmentLogs($boards);
        $this->seedReportLogs($boards);
        $this->seedReactionLogs($boards);
        $this->seedScrapLogs($boards);
        $this->flush();

        $this->command->info("게시판 활동 로그 {$this->created}건 생성 완료.");
    }

    /**
     * 샘플 신고 시더가 다시 만드는 신고의 샘플 로그(표식 있는 신고 로그)만 지웁니다.
     *
     * 이전 샘플 신고는 신고 시더가 지우고 다시 만들므로 그 로그도 새로 만든다. 다른 샘플 로그는 이전 실행이
     * 만든 리소스의 기록이라 남긴다(이번 실행의 로그는 새 리소스에만 생기므로 겹치지 않는다).
     *
     * @return void 반환값 없음
     */
    private function purgeSampleReportLogs(): void
    {
        $deleted = ActivityLog::where('description_key', 'like', 'sirsoft-board::activity_log.description.report_%')
            ->where('properties', 'like', '%"seeded_by":"'.BoardSampleMarker::BOARD_ACTIVITY_SEEDED_BY.'"%')
            ->delete();

        if ($deleted > 0) {
            $this->command->info("이전 샘플 신고 활동 로그 {$deleted}건 정리 (표식 있는 행만).");
        }

        $unmarked = ActivityLog::where('description_key', 'like', 'sirsoft-board::%')
            ->where(fn ($q) => $q->whereNull('properties')
                ->orWhere('properties', 'not like', '%"seeded_by":"'.BoardSampleMarker::BOARD_ACTIVITY_SEEDED_BY.'"%'))
            ->count();
        if ($unmarked > 0) {
            $this->command->warn("  ⚠ 표식 없는 게시판 활동 로그 {$unmarked}건은 운영 로그로 보고 남깁니다 (이전 버전 시더가 만든 샘플도 표식이 없어 정리되지 않습니다).");
        }
    }

    /**
     * 게시판 생성·수정 로그, 메뉴 추가·제거 1쌍, 설정 일괄 적용·중단 각 1건.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Board>  $boards  샘플 게시판
     * @return void 반환값 없음
     */
    private function seedBoardLogs($boards): void
    {
        // 이번 실행에서 만든 샘플 게시판만 — 이미 있던 게시판의 생성·설정 이력은 지어내지 않는다
        $boards = $boards->whereIn('id', SampleRunRegistry::boardIds())->values();
        if ($boards->isEmpty()) {
            return;
        }

        foreach ($boards as $board) {
            $createdAt = Carbon::parse($board->created_at);
            $admin = $this->randomAdmin();

            // 생성 로그는 만들지 않는다 — 게시판 샘플 시더는 BoardService::createBoard 를 거쳐 board.after_create
            // 리스너가 실제 생성 로그를 이미 남긴다(여기서 또 만들면 중복)

            if (rand(0, 1)) {
                $this->push(ActivityLogType::Admin, Board::class, $board->id, $admin, 'board.update', 'board_update',
                    ['board_name' => $board->name], null, [[
                        'field' => 'per_page',
                        'label_key' => 'sirsoft-board::activity_log.fields.per_page',
                        'old' => 15,
                        'new' => $board->per_page,
                        'type' => 'number',
                    ]], $this->timeAfter($createdAt));
            }
        }

        // 메뉴 추가 후 제거 1쌍 (현재 메뉴에는 없는 상태와 일치)
        $menuBoard = $boards->first();
        $menuName = ['ko' => '커뮤니티', 'en' => 'Community'];
        $addedAt = $this->timeAfter(Carbon::parse($menuBoard->created_at));
        $this->push(ActivityLogType::Admin, Board::class, $menuBoard->id, $this->randomAdmin(), 'board.add_to_menu', 'board_add_to_menu',
            ['board_name' => $menuBoard->name, 'menu_name' => $menuName], ['menu_id' => null, 'board_id' => $menuBoard->id], null, $addedAt);
        $this->push(ActivityLogType::Admin, Board::class, $menuBoard->id, $this->randomAdmin(), 'board.remove_from_menu', 'board_remove_from_menu',
            ['board_name' => $menuBoard->name, 'menu_name' => $menuName], ['menu_id' => null, 'board_id' => $menuBoard->id], null, $this->timeAfter($addedAt));

        // 설정 일괄 적용 성공 1건 + 중단(전체 롤백) 1건
        $firstAt = $this->timeAfter(Carbon::parse($boards->min('created_at')));
        $this->push(ActivityLogType::Admin, null, null, $this->randomAdmin(), 'board_settings.bulk_apply', 'board_settings_bulk_apply',
            ['updated_count' => $boards->count()], ['fields' => ['per_page', 'new_display_hours'], 'updated_count' => $boards->count()], null, $firstAt);
        $failed = $boards->last();
        $this->push(ActivityLogType::Admin, null, null, $this->randomAdmin(), 'board_settings.bulk_apply_aborted', 'board_settings_bulk_apply_aborted',
            ['failed_board_name' => $failed->name, 'failed_at' => 3, 'total' => $boards->count()],
            ['fields' => ['max_file_size'], 'failed_board' => ['id' => $failed->id, 'slug' => $failed->slug, 'name' => $failed->name], 'failed_at' => 3, 'total' => $boards->count(), 'failure_reason' => 'validation'],
            null, $this->timeAfter($firstAt));
    }

    /**
     * 게시글 생성 → (수정) → 블라인드/삭제 로그.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Board>  $boards  샘플 게시판
     * @return void 반환값 없음
     */
    private function seedPostLogs($boards): void
    {
        $boardsById = $boards->keyBy('id');

        $range = SampleRunRegistry::range('board_posts');
        if ($range === null) {
            return;
        }

        Post::withTrashed()
            ->whereIn('board_id', $boards->pluck('id'))
            ->where('id', '>', $range[0])
            ->where('id', '<=', $range[1])
            ->select(['id', 'board_id', 'user_id', 'title', 'status', 'action_logs', 'created_at', 'deleted_at'])
            ->orderBy('id')
            ->chunkById(500, function ($posts) use ($boardsById) {
                foreach ($posts as $post) {
                    $board = $boardsById[$post->board_id];
                    $createdAt = Carbon::parse($post->created_at);
                    $isAdminAuthor = $post->user_id && in_array($post->user_id, $this->adminIds, true);
                    $props = ['title' => $post->title, 'slug' => $board->slug, 'board_name' => $board->name];
                    $count = 0;

                    $this->push($isAdminAuthor ? ActivityLogType::Admin : ActivityLogType::User, Post::class, $post->id, $post->user_id,
                        'post.create', 'post_create', ['title' => $post->title, 'board_name' => $board->name], $props, null, $createdAt);
                    $count++;

                    if ($post->user_id && rand(1, 100) <= 20) {
                        $this->push(ActivityLogType::User, Post::class, $post->id, $post->user_id,
                            'post.update', 'post_update', ['title' => $post->title, 'board_name' => $board->name], null, [[
                                'field' => 'title',
                                'label_key' => 'sirsoft-board::activity_log.fields.title',
                                'old' => $post->title.' (수정 전)',
                                'new' => $post->title,
                                'type' => 'text',
                            ]], $this->timeAfter($createdAt, 24));
                        $count++;
                    }

                    // 조치 로그 — 조치 이력의 시각·처리자를 그대로 쓴다
                    foreach ($this->actionLogsOf($post->action_logs) as $log) {
                        if ($count >= self::MAX_PER_RESOURCE) {
                            break;
                        }
                        $key = match ($log['action'] ?? null) {
                            'blind' => 'post_blind',
                            'delete' => 'post_delete',
                            default => null,
                        };
                        if ($key === null) {
                            continue;
                        }
                        $this->push(ActivityLogType::Admin, Post::class, $post->id, $log['admin_id'] ?? $this->randomAdmin(),
                            str_replace('_', '.', $key), $key, ['post_id' => $post->id, 'board_name' => $board->name], $props, null,
                            Carbon::parse($log['created_at']));
                        $count++;
                    }
                }
            });
    }

    /**
     * 댓글 생성 → 블라인드/삭제 로그.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Board>  $boards  샘플 게시판
     * @return void 반환값 없음
     */
    private function seedCommentLogs($boards): void
    {
        $boardsById = $boards->keyBy('id');

        $range = SampleRunRegistry::range('board_comments');
        if ($range === null) {
            return;
        }

        Comment::withTrashed()
            ->whereIn('board_id', $boards->pluck('id'))
            ->where('id', '>', $range[0])
            ->where('id', '<=', $range[1])
            ->select(['id', 'board_id', 'post_id', 'user_id', 'status', 'trigger_type', 'action_logs', 'created_at'])
            ->orderBy('id')
            ->chunkById(500, function ($comments) use ($boardsById) {
                foreach ($comments as $comment) {
                    $board = $boardsById[$comment->board_id];
                    $createdAt = Carbon::parse($comment->created_at);

                    $this->push(ActivityLogType::User, Comment::class, $comment->id, $comment->user_id,
                        'comment.create', 'comment_create', ['board_name' => $board->name, 'post_id' => $comment->post_id],
                        ['slug' => $board->slug, 'post_id' => $comment->post_id, 'board_name' => $board->name], null, $createdAt);

                    foreach ($this->actionLogsOf($comment->action_logs) as $log) {
                        $key = match ($log['action'] ?? null) {
                            'blind' => 'comment_blind',
                            'delete' => 'comment_delete',
                            default => null,
                        };
                        if ($key === null) {
                            continue;
                        }
                        // 작성자 본인 삭제는 사용자 로그, 관리자 조치는 관리자 로그
                        $byAuthor = $key === 'comment_delete' && $comment->trigger_type?->value === 'user';
                        $this->push($byAuthor ? ActivityLogType::User : ActivityLogType::Admin, Comment::class, $comment->id,
                            $log['admin_id'] ?? null, str_replace('_', '.', $key), $key, ['comment_id' => $comment->id],
                            ['slug' => $board->slug, 'post_id' => $comment->post_id], null, Carbon::parse($log['created_at']));
                    }
                }
            });
    }

    /**
     * 첨부 업로드 로그 + 일부 다운로드 로그 (게시물 번호는 post_id).
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Board>  $boards  샘플 게시판
     * @return void 반환값 없음
     */
    private function seedAttachmentLogs($boards): void
    {
        $range = SampleRunRegistry::range('board_attachments');
        if ($range === null) {
            return;
        }

        $authors = Post::withTrashed()->whereIn('board_id', $boards->pluck('id'))->pluck('user_id', 'id');

        Attachment::withTrashed()
            ->whereIn('board_id', $boards->pluck('id'))
            ->where('id', '>', $range[0])
            ->where('id', '<=', $range[1])
            ->select(['id', 'post_id', 'original_filename', 'size', 'created_at'])
            ->orderBy('id')
            ->chunkById(500, function ($attachments) use ($authors) {
                foreach ($attachments as $attachment) {
                    $createdAt = Carbon::parse($attachment->created_at);
                    $props = [
                        'original_filename' => $attachment->original_filename,
                        'size' => $attachment->size,
                        'post_id' => $attachment->post_id,
                    ];

                    $this->push(ActivityLogType::User, Attachment::class, $attachment->id, $authors[$attachment->post_id] ?? null,
                        'attachment.upload', 'board_attachment_upload', ['post_id' => $attachment->post_id], $props, null, $createdAt);

                    if (rand(1, 100) <= 30) {
                        $this->push(ActivityLogType::User, Attachment::class, $attachment->id, $this->randomUser(),
                            'attachment.download', 'board_attachment_download', ['post_id' => $attachment->post_id],
                            ['original_filename' => $attachment->original_filename, 'post_id' => $attachment->post_id, 'context' => 'user'],
                            null, $this->timeAfter($createdAt));
                    }
                }
            });
    }

    /**
     * 신고 접수(신고자별) → 처리 상태 변경 → 콘텐츠 조치 로그, 신고 후 취소 1쌍.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Board>  $boards  샘플 게시판
     * @return void 반환값 없음
     */
    private function seedReportLogs($boards): void
    {
        // 샘플 신고(표식 있는 케이스)만 — 운영 신고의 처리 이력을 지어내지 않는다
        $reports = Report::whereIn('board_id', $boards->pluck('id'))
            ->where('metadata', 'like', '%"'.BoardSampleMarker::REPORT_METADATA_KEY.'":true%')
            ->with('logs')
            ->get();

        foreach ($reports as $report) {
            foreach ($report->logs->take(self::MAX_PER_RESOURCE - 2) as $log) {
                /** @var ReportLog $log */
                $this->push(ActivityLogType::User, Report::class, $report->id, $log->reporter_id, 'report.create', 'report_create',
                    ['report_id' => $report->id], [
                        'reason' => $log->reason_type?->value ?? '',
                        'reportable_type' => $report->target_type?->value ?? '',
                    ], null, Carbon::parse($log->created_at));
            }

            if ($report->processed_at) {
                $at = Carbon::parse($report->processed_at);
                $status = $report->status?->value;
                $this->push(ActivityLogType::Admin, Report::class, $report->id, $report->processed_by ?? $this->randomAdmin(),
                    'report.update_status', 'report_update_status', ['report_id' => $report->id], ['status' => $status], null, $at);

                $contentKey = match ($status) {
                    'suspended' => 'report_blind_content',
                    'deleted' => 'report_delete_content',
                    default => null,
                };
                if ($contentKey) {
                    $this->push(ActivityLogType::Admin, Report::class, $report->id, $report->processed_by ?? $this->randomAdmin(),
                        str_replace('report_', 'report.', $contentKey), $contentKey, ['report_id' => $report->id], null, null, $at);
                }
            }
        }

        // 신고 후 취소 1쌍 — 취소한 신고자는 그 케이스의 신고자 목록에 없다(데이터와 일치)
        $pending = $reports->first(fn (Report $r) => $r->status?->value === 'pending' && $r->board_id !== null);
        if ($pending) {
            $reporterIds = $pending->logs->pluck('reporter_id')->all();
            $canceller = collect($this->userIds)->first(fn ($id) => ! in_array($id, $reporterIds, true) && $id !== $pending->author_id);
            if ($canceller) {
                $reportedAt = $this->timeAfter(Carbon::parse($pending->created_at), 24);
                $this->push(ActivityLogType::User, Report::class, $pending->id, $canceller, 'report.create', 'report_create',
                    ['report_id' => $pending->id], ['reason' => 'spam', 'reportable_type' => $pending->target_type?->value ?? ''], null, $reportedAt);
                $this->push(ActivityLogType::User, Report::class, $pending->id, $canceller, 'report.cancel', 'report_cancel',
                    ['report_id' => $pending->id], ['reason' => 'spam', 'case_deleted' => false], null, $this->timeAfter($reportedAt, 2));
            }
        }
    }

    /**
     * 반응 등록 로그(현재 반응 행 기준) + 변경·취소 몇 건.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Board>  $boards  샘플 게시판
     * @return void 반환값 없음
     */
    private function seedReactionLogs($boards): void
    {
        $boardsById = $boards->keyBy('id');
        $types = ReactionType::get()->keyBy('id');
        $range = SampleRunRegistry::range('board_reactions');
        if ($range === null) {
            return;
        }

        $reactions = DB::table('board_reactions')->whereIn('board_id', $boards->pluck('id'))
            ->where('id', '>', $range[0])->where('id', '<=', $range[1])
            ->orderBy('id')->limit(300)->get();

        if ($reactions->isEmpty()) {
            return;
        }

        $postIds = $reactions->where('target_type', 'post')->pluck('target_id')->all();
        $commentIds = $reactions->where('target_type', 'comment')->pluck('target_id')->all();
        $titles = Post::withTrashed()->whereIn('id', $postIds)->pluck('title', 'id');
        $commentPosts = Comment::withTrashed()->whereIn('id', $commentIds)->pluck('post_id', 'id');
        $likeId = $types->first(fn ($t) => $t->code === 'like')?->id;

        foreach ($reactions as $i => $reaction) {
            $board = $boardsById[$reaction->board_id] ?? null;
            if (! $board) {
                continue;
            }
            $isComment = $reaction->target_type === 'comment';
            $typeName = $types[$reaction->reaction_type_id]?->getLocalizedName() ?? '';
            $at = Carbon::parse($reaction->created_at);

            [$params, $props] = $this->reactionShape($isComment, $reaction, $board, $typeName, $titles, $commentPosts);
            $prefix = $isComment ? 'comment_reaction' : 'reaction';
            $model = $isComment ? Comment::class : Post::class;

            // 일부는 추천으로 눌렀다가 지금 유형으로 바꾼 이력 (현재 행과 일치)
            if ($likeId && $reaction->reaction_type_id !== $likeId && $i % 3 === 0) {
                $likeName = $types[$likeId]->getLocalizedName();
                [$likeParams, $likeProps] = $this->reactionShape($isComment, (object) array_merge((array) $reaction, ['reaction_type_id' => $likeId]), $board, $likeName, $titles, $commentPosts);
                $firstAt = $at->copy()->subMinutes(rand(5, 120));
                $this->push(ActivityLogType::User, $model, $reaction->target_id, $reaction->user_id, $prefix.'.add', $prefix.'_add', $likeParams, $likeProps, null, $firstAt);
                $this->push(ActivityLogType::User, $model, $reaction->target_id, $reaction->user_id, $prefix.'.change', $prefix.'_change', $params, $props, null, $at);

                continue;
            }

            $this->push(ActivityLogType::User, $model, $reaction->target_id, $reaction->user_id, $prefix.'.add', $prefix.'_add', $params, $props, null, $at);
        }

        // 눌렀다가 취소한 이력 2건 — 반응 행이 없는 회원·글로 만든다
        $sample = $reactions->where('target_type', 'post')->take(2);
        foreach ($sample as $reaction) {
            $reactedUsers = DB::table('board_reactions')->where('target_type', 'post')->where('target_id', $reaction->target_id)->pluck('user_id')->all();
            $userId = collect($this->userIds)->first(fn ($id) => ! in_array($id, $reactedUsers, true));
            $board = $boardsById[$reaction->board_id] ?? null;
            if (! $userId || ! $board || ! $likeId) {
                continue;
            }
            $likeName = $types[$likeId]->getLocalizedName();
            [$params, $props] = $this->reactionShape(false, (object) array_merge((array) $reaction, ['reaction_type_id' => $likeId]), $board, $likeName, $titles, $commentPosts);
            $at = $this->timeAfter(Carbon::parse($reaction->created_at), 24);
            $this->push(ActivityLogType::User, Post::class, $reaction->target_id, $userId, 'reaction.add', 'reaction_add', $params, $props, null, $at);
            $this->push(ActivityLogType::User, Post::class, $reaction->target_id, $userId, 'reaction.remove', 'reaction_remove', $params, $props, null, $this->timeAfter($at, 2));
        }
    }

    /**
     * 반응 로그의 description_params·properties 를 리스너 모양으로 만듭니다.
     *
     * @param  bool  $isComment  댓글 반응 여부
     * @param  object  $reaction  반응 행
     * @param  Board  $board  게시판
     * @param  string  $typeName  반응 유형 이름 (로케일 문자열)
     * @param  Collection  $titles  글 ID → 제목
     * @param  Collection  $commentPosts  댓글 ID → 글 ID
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [params, properties]
     */
    private function reactionShape(bool $isComment, object $reaction, Board $board, string $typeName, $titles, $commentPosts): array
    {
        if ($isComment) {
            $postId = $commentPosts[$reaction->target_id] ?? null;

            return [
                ['post_id' => $postId ?? '', 'comment_id' => $reaction->target_id, 'reaction_type' => $typeName, 'board_name' => $board->name],
                ['post_id' => $postId, 'comment_id' => $reaction->target_id, 'reaction_type_id' => $reaction->reaction_type_id, 'reaction_type' => $typeName, 'board_name' => $board->name],
            ];
        }

        $title = $titles[$reaction->target_id] ?? '';

        return [
            ['title' => $title, 'reaction_type' => $typeName, 'board_name' => $board->name],
            ['title' => $title, 'reaction_type_id' => $reaction->reaction_type_id, 'reaction_type' => $typeName, 'board_name' => $board->name],
        ];
    }

    /**
     * 스크랩 등록 로그(현재 스크랩 행 기준) + 등록 후 해제 1쌍.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Board>  $boards  샘플 게시판
     * @return void 반환값 없음
     */
    private function seedScrapLogs($boards): void
    {
        $range = SampleRunRegistry::range('board_post_scraps');
        if ($range === null) {
            return;
        }

        $scraps = DB::table('board_post_scraps')->whereIn('board_id', $boards->pluck('id'))
            ->where('id', '>', $range[0])->where('id', '<=', $range[1])
            ->orderBy('id')->limit(200)->get();

        foreach ($scraps as $scrap) {
            $this->push(ActivityLogType::User, Post::class, $scrap->post_id, $scrap->user_id, 'scrap.scrap', 'scrap_add',
                ['post_id' => $scrap->post_id], null, null, Carbon::parse($scrap->created_at));
        }

        $first = $scraps->first();
        if ($first) {
            $scrapped = DB::table('board_post_scraps')->where('post_id', $first->post_id)->pluck('user_id')->all();
            $userId = collect($this->userIds)->first(fn ($id) => ! in_array($id, $scrapped, true));
            if ($userId) {
                $at = $this->timeAfter(Carbon::parse($first->created_at), 24);
                $this->push(ActivityLogType::User, Post::class, $first->post_id, $userId, 'scrap.scrap', 'scrap_add', ['post_id' => $first->post_id], null, null, $at);
                $this->push(ActivityLogType::User, Post::class, $first->post_id, $userId, 'scrap.unscrap', 'scrap_remove', ['post_id' => $first->post_id], null, null, $this->timeAfter($at, 2));
            }
        }
    }

    /**
     * 활동 로그 1행을 버퍼에 넣고, 일괄 insert 크기에 닿으면 씁니다.
     *
     * @param  ActivityLogType  $logType  로그 유형
     * @param  string|null  $loggableType  대상 모델 클래스
     * @param  int|null  $loggableId  대상 ID
     * @param  int|null  $userId  행위자 ID (비회원이면 null)
     * @param  string  $action  액션명 (리스너와 같은 값)
     * @param  string  $key  description 키 꼬리
     * @param  array<string, mixed>  $params  다국어 파라미터
     * @param  array<string, mixed>|null  $properties  추가 속성 (리스너 모양)
     * @param  array<int, array<string, mixed>>|null  $changes  변경 이력
     * @param  Carbon  $at  기록 시각
     * @return void 반환값 없음
     */
    private function push(ActivityLogType $logType, ?string $loggableType, ?int $loggableId, ?int $userId, string $action, string $key, array $params, ?array $properties, ?array $changes, Carbon $at): void
    {
        $userId = $this->existingUserId($userId);

        $properties = array_merge($properties ?? [], [
            'extension_origin' => self::ORIGIN,
            'seeded_by' => BoardSampleMarker::BOARD_ACTIVITY_SEEDED_BY,
        ]);

        $now = now();
        $this->buffer[] = [
            'log_type' => $logType->value,
            'loggable_type' => $loggableType,
            'loggable_id' => $loggableId,
            'user_id' => $userId,
            'action' => $action,
            'description_key' => self::PREFIX.$key,
            'description_params' => json_encode($params, JSON_UNESCAPED_UNICODE),
            'properties' => json_encode($properties, JSON_UNESCAPED_UNICODE),
            'changes' => $changes !== null ? json_encode($changes, JSON_UNESCAPED_UNICODE) : null,
            'ip_address' => self::IPS[array_rand(self::IPS)],
            'user_agent' => self::USER_AGENTS[array_rand(self::USER_AGENTS)],
            'created_at' => ($at->greaterThan($now) ? $now : $at)->toDateTimeString(),
        ];

        if (count($this->buffer) >= self::CHUNK) {
            $this->flush();
        }
    }

    /**
     * 실재 회원 ID 만 돌려줍니다 (탈퇴로 행이 없으면 null).
     *
     * 게시글 작성자 칸은 FK 가 없어 지워진 회원 id 가 남을 수 있지만 activity_logs.user_id 는 FK 다.
     *
     * @param  int|null  $userId  회원 ID
     * @return int|null 실재하면 그 ID, 아니면 null
     */
    private function existingUserId(?int $userId): ?int
    {
        if ($userId === null) {
            return null;
        }

        if (! array_key_exists($userId, $this->userExists)) {
            $this->userExists[$userId] = User::whereKey($userId)->exists();
        }

        return $this->userExists[$userId] ? $userId : null;
    }

    /**
     * 버퍼의 행을 일괄 insert 합니다.
     *
     * @return void 반환값 없음
     */
    private function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        DB::table('activity_logs')->insert($this->buffer);
        $this->created += count($this->buffer);
        $this->buffer = [];
    }

    /**
     * 저장된 action_logs 를 배열로 읽습니다.
     *
     * @param  mixed  $value  모델 캐스트 값 또는 JSON 문자열
     * @return array<int, array<string, mixed>> 조치 이력 (시각 있는 것만)
     */
    private function actionLogsOf(mixed $value): array
    {
        $logs = is_string($value) ? json_decode($value, true) : $value;

        return array_values(array_filter(is_array($logs) ? $logs : [], fn ($log) => is_array($log) && ! empty($log['created_at'])));
    }

    /**
     * 기준 시각 뒤 ~ 최대 N시간 사이 시각 (지금을 넘지 않음).
     *
     * @param  Carbon  $base  기준 시각
     * @param  int  $maxHours  최대 지연 시간
     * @return Carbon 시각
     */
    private function timeAfter(Carbon $base, int $maxHours = 72): Carbon
    {
        $at = $base->copy()->addMinutes(rand(1, max(1, $maxHours * 60)));

        return $at->greaterThan(now()) ? now() : $at;
    }

    /**
     * 임의 관리자 ID.
     *
     * @return int 관리자 ID
     */
    private function randomAdmin(): int
    {
        return $this->adminIds[array_rand($this->adminIds)];
    }

    /**
     * 임의 활성 회원 ID.
     *
     * @return int 회원 ID
     */
    private function randomUser(): int
    {
        return $this->userIds[array_rand($this->userIds)];
    }
}
