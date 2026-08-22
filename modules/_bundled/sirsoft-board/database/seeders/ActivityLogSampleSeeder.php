<?php

namespace Modules\Sirsoft\Board\Database\Seeders;

use App\Enums\ActivityLogType;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Modules\Sirsoft\Board\Enums\PostStatus;
use Modules\Sirsoft\Board\Enums\ReportStatus;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\BoardType;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Models\Report;

/**
 * 게시판 활동 로그 샘플 시더
 *
 * 리소스 중심 rand(1,50) 방식으로 ActivityLog를 생성합니다.
 * 각 리소스마다 rand(1,50)개의 랜덤 액션 로그를 생성합니다.
 */
class ActivityLogSampleSeeder extends Seeder
{
    /** @var string 다국어 키 접두사 */
    private const PREFIX = 'sirsoft-board::activity_log.description.';

    /** @var array<string> 샘플 IP 목록 */
    private const IPS = ['192.168.1.10', '10.0.0.5', '172.16.0.1', '192.168.0.100', '10.10.10.1'];

    /** @var array<string> 샘플 User-Agent 목록 */
    private const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/125.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
        'Mozilla/5.0 (X11; Linux x86_64) Firefox/126.0',
    ];

    /**
     * 시더를 실행합니다.
     */
    public function run(): void
    {
        $admins = User::whereHas('roles', fn ($q) => $q->where('identifier', 'admin'))->get();
        if ($admins->isEmpty()) {
            $this->command->warn('관리자 사용자가 없어 게시판 활동 로그 시더를 건너뜁니다.');

            return;
        }

        $users = User::whereDoesntHave('roles', fn ($q) => $q->where('identifier', 'admin'))->get();
        if ($users->isEmpty()) {
            $users = $admins;
        }

        // 기존 게시판 활동 로그 삭제
        $deleted = ActivityLog::where('description_key', 'like', 'sirsoft-board::%')->delete();
        if ($deleted > 0) {
            $this->command->info("기존 게시판 활동 로그 {$deleted}건 삭제.");
        }

        $count = 0;

        $count += $this->seedBoardLogs($admins);
        $count += $this->seedBoardTypeLogs($admins);
        $count += $this->seedPostLogs($admins, $users);
        $count += $this->seedCommentLogs($admins, $users);
        $count += $this->seedAttachmentLogs($admins, $users);
        $count += $this->seedReportLogs($admins);

        $this->command->info("게시판 활동 로그 {$count}건 생성 완료.");
    }

    /**
     * 게시판 활동 로그 (리소스별 rand(1,50)건)
     *
     * @param  Collection  $admins  관리자 컬렉션
     * @return int 생성된 로그 수
     */
    private function seedBoardLogs(Collection $admins): int
    {
        $boards = Board::get();
        if ($boards->isEmpty()) {
            $this->command->warn('게시판 데이터가 없어 게시판 활동 로그를 건너뜁니다.');

            return 0;
        }

        $morphType = (new Board)->getMorphClass();

        $actions = [
            [
                'action' => 'board.create',
                'key' => 'board_create',
                'loggable' => true,
                'params' => fn (Board $board) => ['board_name' => $this->getLocalizedName($board->name)],
                'changes' => null,
                'properties' => null,
            ],
            [
                'action' => 'board.update',
                'key' => 'board_update',
                'loggable' => true,
                'params' => fn (Board $board) => ['board_name' => $this->getLocalizedName($board->name)],
                'changes' => fn (Board $board) => [
                    ['field' => 'name', 'label_key' => 'sirsoft-board::activity_log.fields.name', 'old' => $this->getLocalizedName($board->name).' (수정 전)', 'new' => $this->getLocalizedName($board->name), 'type' => 'text'],
                ],
                'properties' => null,
            ],
            [
                'action' => 'board.show',
                'key' => 'board_show',
                'loggable' => true,
                'params' => fn (Board $board) => ['board_name' => $this->getLocalizedName($board->name)],
                'changes' => null,
                'properties' => null,
            ],
            [
                'action' => 'board.delete',
                'key' => 'board_delete',
                'loggable' => false,
                'params' => fn (Board $board) => ['board_name' => $this->getLocalizedName($board->name)],
                'changes' => null,
                'properties' => fn (Board $board) => ['deleted_id' => $board->id, 'slug' => $board->slug, 'name' => $this->getLocalizedName($board->name)],
            ],
            [
                'action' => 'board.add_to_menu',
                'key' => 'board_add_to_menu',
                'loggable' => true,
                'params' => fn (Board $board) => ['board_name' => $this->getLocalizedName($board->name)],
                'changes' => null,
                'properties' => null,
            ],
            [
                'action' => 'board_settings.bulk_apply',
                'key' => 'board_settings_bulk_apply',
                'loggable' => true,
                'params' => fn (Board $board) => ['board_name' => $this->getLocalizedName($board->name)],
                'changes' => fn (Board $board) => [
                    ['field' => 'per_page', 'label_key' => 'sirsoft-board::activity_log.fields.per_page', 'old' => 10, 'new' => 20, 'type' => 'number'],
                    ['field' => 'use_comment', 'label_key' => 'sirsoft-board::activity_log.fields.use_comment', 'old' => false, 'new' => true, 'type' => 'boolean'],
                ],
                'properties' => null,
            ],
        ];

        return $this->generateResourceLogs($boards, $admins, ActivityLogType::Admin, $morphType, $actions);
    }

    /**
     * 게시판 유형 활동 로그 (리소스별 rand(1,50)건)
     *
     * @param  Collection  $admins  관리자 컬렉션
     * @return int 생성된 로그 수
     */
    private function seedBoardTypeLogs(Collection $admins): int
    {
        $types = BoardType::get();
        if ($types->isEmpty()) {
            $this->command->warn('게시판 유형 데이터가 없어 게시판 유형 활동 로그를 건너뜁니다.');

            return 0;
        }

        $morphType = (new BoardType)->getMorphClass();

        $actions = [
            [
                'action' => 'board_type.create',
                'key' => 'board_type_create',
                'loggable' => true,
                'params' => fn (BoardType $type) => ['type_name' => $this->getLocalizedName($type->name)],
                'changes' => null,
                'properties' => null,
            ],
            [
                'action' => 'board_type.update',
                'key' => 'board_type_update',
                'loggable' => true,
                'params' => fn (BoardType $type) => ['type_name' => $this->getLocalizedName($type->name)],
                'changes' => fn (BoardType $type) => [
                    ['field' => 'name', 'label_key' => 'sirsoft-board::activity_log.fields.name', 'old' => $this->getLocalizedName($type->name).' (수정 전)', 'new' => $this->getLocalizedName($type->name), 'type' => 'text'],
                ],
                'properties' => null,
            ],
            [
                'action' => 'board_type.show',
                'key' => 'board_type_show',
                'loggable' => true,
                'params' => fn (BoardType $type) => ['type_name' => $this->getLocalizedName($type->name)],
                'changes' => null,
                'properties' => null,
            ],
            [
                'action' => 'board_type.delete',
                'key' => 'board_type_delete',
                'loggable' => false,
                'params' => fn (BoardType $type) => ['type_name' => $this->getLocalizedName($type->name)],
                'changes' => null,
                'properties' => fn (BoardType $type) => ['deleted_id' => $type->id, 'slug' => $type->slug],
            ],
        ];

        return $this->generateResourceLogs($types, $admins, ActivityLogType::Admin, $morphType, $actions);
    }

    /**
     * 게시글 활동 로그 (리소스별 rand(1,50)건)
     *
     * @param  Collection  $admins  관리자 컬렉션
     * @param  Collection  $users  일반 사용자 컬렉션
     * @return int 생성된 로그 수
     */
    private function seedPostLogs(Collection $admins, Collection $users): int
    {
        $posts = Post::with('board')->get();
        if ($posts->isEmpty()) {
            $this->command->warn('게시글 데이터가 없어 게시글 활동 로그를 건너뜁니다.');

            return 0;
        }

        $morphType = (new Post)->getMorphClass();

        $adminActions = [
            [
                'action' => 'post.create',
                'key' => 'post_create',
                'logType' => ActivityLogType::Admin,
                'loggable' => true,
                'params' => fn (Post $post) => ['title' => $post->title, 'board_name' => $post->board ? $this->getLocalizedName($post->board->name) : '알 수 없음'],
                'changes' => null,
                'properties' => null,
            ],
            [
                'action' => 'post.update',
                'key' => 'post_update',
                'logType' => ActivityLogType::Admin,
                'loggable' => true,
                'params' => fn (Post $post) => ['title' => $post->title, 'board_name' => $post->board ? $this->getLocalizedName($post->board->name) : '알 수 없음'],
                'changes' => fn (Post $post) => [
                    ['field' => 'title', 'label_key' => 'sirsoft-board::activity_log.fields.title', 'old' => $post->title.' (수정 전)', 'new' => $post->title, 'type' => 'text'],
                    ['field' => 'content', 'label_key' => 'sirsoft-board::activity_log.fields.content', 'old' => '이전 내용...', 'new' => $post->content ?? '수정된 내용', 'type' => 'text'],
                ],
                'properties' => null,
            ],
            [
                'action' => 'post.delete',
                'key' => 'post_delete',
                'logType' => ActivityLogType::Admin,
                'loggable' => false,
                'params' => fn (Post $post) => ['board_name' => $post->board ? $this->getLocalizedName($post->board->name) : '알 수 없음', 'post_id' => $post->id],
                'changes' => null,
                'properties' => fn (Post $post) => ['deleted_id' => $post->id, 'board_id' => $post->board_id, 'title' => $post->title, 'user_id' => $post->user_id],
            ],
            [
                'action' => 'post.blind',
                'key' => 'post_blind',
                'logType' => ActivityLogType::Admin,
                'loggable' => true,
                'params' => fn (Post $post) => ['board_name' => $post->board ? $this->getLocalizedName($post->board->name) : '알 수 없음', 'post_id' => $post->id],
                'changes' => fn (Post $post) => [
                    ['field' => 'status', 'label_key' => 'sirsoft-board::activity_log.fields.status', 'old' => $post->status?->value ?? PostStatus::Published->value, 'new' => PostStatus::Blinded->value, 'type' => 'enum',
                     'old_label_key' => PostStatus::Published->labelKey(), 'new_label_key' => PostStatus::Blinded->labelKey()],
                ],
                'properties' => null,
            ],
            [
                'action' => 'post.restore',
                'key' => 'post_restore',
                'logType' => ActivityLogType::Admin,
                'loggable' => true,
                'params' => fn (Post $post) => ['board_name' => $post->board ? $this->getLocalizedName($post->board->name) : '알 수 없음', 'post_id' => $post->id],
                'changes' => fn (Post $post) => [
                    ['field' => 'status', 'label_key' => 'sirsoft-board::activity_log.fields.status', 'old' => PostStatus::Blinded->value, 'new' => PostStatus::Published->value, 'type' => 'enum',
                     'old_label_key' => PostStatus::Blinded->labelKey(), 'new_label_key' => PostStatus::Published->labelKey()],
                ],
                'properties' => null,
            ],
        ];

        $userActions = [
            [
                'action' => 'post.create',
                'key' => 'post_create',
                'logType' => ActivityLogType::User,
                'loggable' => true,
                'params' => fn (Post $post) => ['title' => $post->title, 'board_name' => $post->board ? $this->getLocalizedName($post->board->name) : '알 수 없음'],
                'changes' => null,
                'properties' => null,
                'userIdResolver' => fn (Post $post, Collection $actors) => $post->user_id ?? $actors->random()->id,
            ],
            [
                'action' => 'post.update',
                'key' => 'post_update',
                'logType' => ActivityLogType::User,
                'loggable' => true,
                'params' => fn (Post $post) => ['title' => $post->title, 'board_name' => $post->board ? $this->getLocalizedName($post->board->name) : '알 수 없음'],
                'changes' => fn (Post $post) => [
                    ['field' => 'title', 'label_key' => 'sirsoft-board::activity_log.fields.title', 'old' => $post->title.' (수정 전)', 'new' => $post->title, 'type' => 'text'],
                ],
                'properties' => null,
                'userIdResolver' => fn (Post $post, Collection $actors) => $post->user_id ?? $actors->random()->id,
            ],
        ];

        $allActions = array_merge($adminActions, $userActions);

        $count = $this->generateResourceLogsWithMixedTypes($posts, $admins, $users, $morphType, $allActions);

        return $count;
    }

    /**
     * 댓글 활동 로그 (리소스별 rand(1,50)건)
     *
     * @param  Collection  $admins  관리자 컬렉션
     * @param  Collection  $users  일반 사용자 컬렉션
     * @return int 생성된 로그 수
     */
    private function seedCommentLogs(Collection $admins, Collection $users): int
    {
        $comments = Comment::with('post.board')->get();
        if ($comments->isEmpty()) {
            $this->command->warn('댓글 데이터가 없어 댓글 활동 로그를 건너뜁니다.');

            return 0;
        }

        $morphType = (new Comment)->getMorphClass();

        $adminActions = [
            [
                'action' => 'comment.create',
                'key' => 'comment_create',
                'logType' => ActivityLogType::Admin,
                'loggable' => true,
                'params' => fn (Comment $comment) => ['board_name' => $comment->post?->board ? $this->getLocalizedName($comment->post->board->name) : '알 수 없음', 'post_id' => $comment->post_id],
                'changes' => null,
                'properties' => null,
            ],
            [
                'action' => 'comment.delete',
                'key' => 'comment_delete',
                'logType' => ActivityLogType::Admin,
                'loggable' => false,
                'params' => fn (Comment $comment) => ['comment_id' => $comment->id],
                'changes' => null,
                'properties' => fn (Comment $comment) => ['deleted_id' => $comment->id, 'post_id' => $comment->post_id, 'user_id' => $comment->user_id],
            ],
            [
                'action' => 'comment.blind',
                'key' => 'comment_blind',
                'logType' => ActivityLogType::Admin,
                'loggable' => true,
                'params' => fn (Comment $comment) => ['comment_id' => $comment->id],
                'changes' => fn (Comment $comment) => [
                    ['field' => 'status', 'label_key' => 'sirsoft-board::activity_log.fields.status', 'old' => $comment->status?->value ?? PostStatus::Published->value, 'new' => PostStatus::Blinded->value, 'type' => 'enum',
                     'old_label_key' => PostStatus::Published->labelKey(), 'new_label_key' => PostStatus::Blinded->labelKey()],
                ],
                'properties' => null,
            ],
            [
                'action' => 'comment.restore',
                'key' => 'comment_restore',
                'logType' => ActivityLogType::Admin,
                'loggable' => true,
                'params' => fn (Comment $comment) => ['comment_id' => $comment->id],
                'changes' => fn (Comment $comment) => [
                    ['field' => 'status', 'label_key' => 'sirsoft-board::activity_log.fields.status', 'old' => PostStatus::Blinded->value, 'new' => PostStatus::Published->value, 'type' => 'enum',
                     'old_label_key' => PostStatus::Blinded->labelKey(), 'new_label_key' => PostStatus::Published->labelKey()],
                ],
                'properties' => null,
            ],
        ];

        $userActions = [
            [
                'action' => 'comment.create',
                'key' => 'comment_create',
                'logType' => ActivityLogType::User,
                'loggable' => true,
                'params' => fn (Comment $comment) => ['board_name' => $comment->post?->board ? $this->getLocalizedName($comment->post->board->name) : '알 수 없음', 'post_id' => $comment->post_id],
                'changes' => null,
                'properties' => null,
                'userIdResolver' => fn (Comment $comment, Collection $actors) => $comment->user_id ?? $actors->random()->id,
            ],
            [
                'action' => 'comment.update',
                'key' => 'comment_update',
                'logType' => ActivityLogType::User,
                'loggable' => true,
                'params' => fn (Comment $comment) => ['comment_id' => $comment->id],
                'changes' => fn (Comment $comment) => [
                    ['field' => 'content', 'label_key' => 'sirsoft-board::activity_log.fields.content', 'old' => ($comment->content ?? '이전 댓글').' (수정 전)', 'new' => $comment->content ?? '수정된 댓글', 'type' => 'text'],
                ],
                'properties' => null,
                'userIdResolver' => fn (Comment $comment, Collection $actors) => $comment->user_id ?? $actors->random()->id,
            ],
        ];

        $allActions = array_merge($adminActions, $userActions);

        return $this->generateResourceLogsWithMixedTypes($comments, $admins, $users, $morphType, $allActions);
    }

    /**
     * 첨부파일 활동 로그 (리소스별 rand(1,50)건)
     *
     * @param  Collection  $admins  관리자 컬렉션
     * @param  Collection  $users  일반 사용자 컬렉션
     * @return int 생성된 로그 수
     */
    private function seedAttachmentLogs(Collection $admins, Collection $users): int
    {
        $attachments = Attachment::get();
        if ($attachments->isEmpty()) {
            $this->command->warn('첨부파일 데이터가 없어 첨부파일 활동 로그를 건너뜁니다.');

            return 0;
        }

        $morphType = (new Attachment)->getMorphClass();

        $allActions = [
            [
                'action' => 'attachment.upload',
                'key' => 'board_attachment_upload',
                'logType' => null,
                'loggable' => true,
                'params' => fn (Attachment $attachment) => ['post_id' => $attachment->attachable_id],
                'changes' => null,
                'properties' => null,
                'userIdResolver' => fn (Attachment $attachment, Collection $actors) => $attachment->created_by ?? $actors->random()->id,
            ],
            [
                'action' => 'attachment.delete',
                'key' => 'board_attachment_delete',
                'logType' => ActivityLogType::Admin,
                'loggable' => false,
                'params' => fn (Attachment $attachment) => ['post_id' => $attachment->attachable_id],
                'changes' => null,
                'properties' => fn (Attachment $attachment) => ['deleted_id' => $attachment->id, 'original_filename' => $attachment->original_filename, 'mime_type' => $attachment->mime_type, 'size' => $attachment->size],
            ],
        ];

        return $this->generateResourceLogsWithMixedTypes($attachments, $admins, $users, $morphType, $allActions);
    }

    /**
     * 신고 활동 로그 (리소스별 rand(1,50)건)
     *
     * @param  Collection  $admins  관리자 컬렉션
     * @return int 생성된 로그 수
     */
    private function seedReportLogs(Collection $admins): int
    {
        $reports = Report::get();
        if ($reports->isEmpty()) {
            $this->command->warn('신고 데이터가 없어 신고 활동 로그를 건너뜁니다.');

            return 0;
        }

        $morphType = (new Report)->getMorphClass();

        $actions = [
            [
                'action' => 'report.create',
                'key' => 'report_create',
                'loggable' => true,
                'params' => fn (Report $report) => ['report_id' => $report->id],
                'changes' => null,
                'properties' => null,
            ],
            [
                'action' => 'report.update_status',
                'key' => 'report_update_status',
                'loggable' => true,
                'params' => fn (Report $report) => ['report_id' => $report->id],
                'changes' => fn (Report $report) => [
                    ['field' => 'status', 'label_key' => 'sirsoft-board::activity_log.fields.status', 'old' => $this->pickDifferentEnum(ReportStatus::class, $report->status), 'new' => $report->status?->value ?? ReportStatus::Pending->value, 'type' => 'enum'],
                ],
                'properties' => null,
            ],
            [
                'action' => 'report.delete',
                'key' => 'report_delete',
                'loggable' => false,
                'params' => fn (Report $report) => ['report_id' => $report->id],
                'changes' => null,
                'properties' => fn (Report $report) => ['deleted_id' => $report->id, 'board_id' => $report->board_id],
            ],
            [
                'action' => 'report.blind_content',
                'key' => 'report_blind_content',
                'loggable' => true,
                'params' => fn (Report $report) => ['report_id' => $report->id],
                'changes' => fn (Report $report) => [
                    ['field' => 'status', 'label_key' => 'sirsoft-board::activity_log.fields.status', 'old' => ReportStatus::Pending->value, 'new' => ReportStatus::Suspended->value, 'type' => 'enum',
                     'old_label_key' => ReportStatus::Pending->labelKey(), 'new_label_key' => ReportStatus::Suspended->labelKey()],
                ],
                'properties' => null,
            ],
            [
                'action' => 'report.restore_content',
                'key' => 'report_restore_content',
                'loggable' => true,
                'params' => fn (Report $report) => ['report_id' => $report->id],
                'changes' => fn (Report $report) => [
                    ['field' => 'status', 'label_key' => 'sirsoft-board::activity_log.fields.status', 'old' => ReportStatus::Suspended->value, 'new' => ReportStatus::Rejected->value, 'type' => 'enum',
                     'old_label_key' => ReportStatus::Suspended->labelKey(), 'new_label_key' => ReportStatus::Rejected->labelKey()],
                ],
                'properties' => null,
            ],
            [
                'action' => 'report.delete_content',
                'key' => 'report_delete_content',
                'loggable' => true,
                'params' => fn (Report $report) => ['report_id' => $report->id],
                'changes' => fn (Report $report) => [
                    ['field' => 'status', 'label_key' => 'sirsoft-board::activity_log.fields.status', 'old' => ReportStatus::Pending->value, 'new' => ReportStatus::Deleted->value, 'type' => 'enum',
                     'old_label_key' => ReportStatus::Pending->labelKey(), 'new_label_key' => ReportStatus::Deleted->labelKey()],
                ],
                'properties' => null,
            ],
            [
                'action' => 'report.bulk_update_status',
                'key' => 'report_bulk_update_status',
                'loggable' => true,
                'params' => fn (Report $report) => ['count' => rand(2, 8)],
                'changes' => fn (Report $report) => [
                    ['field' => 'status', 'label_key' => 'sirsoft-board::activity_log.fields.status', 'old' => $this->pickDifferentEnum(ReportStatus::class, $report->status), 'new' => $report->status?->value ?? ReportStatus::Pending->value, 'type' => 'enum'],
                ],
                'properties' => null,
            ],
        ];

        return $this->generateResourceLogs($reports, $admins, ActivityLogType::Admin, $morphType, $actions);
    }

    /**
     * 리소스 중심 랜덤 로그를 생성합니다.
     *
     * 각 리소스마다 rand(1,50)개의 랜덤 액션을 선택(중복 허용)하여 로그를 생성합니다.
     *
     * @param  Collection  $resources  리소스 컬렉션
     * @param  Collection  $actors  액터(사용자) 컬렉션
     * @param  ActivityLogType  $logType  로그 유형
     * @param  string  $morphType  모델 morph 타입
     * @param  array  $actions  액션 템플릿 배열
     * @return int 생성된 로그 수
     */
    private function generateResourceLogs(
        Collection $resources,
        Collection $actors,
        ActivityLogType $logType,
        string $morphType,
        array $actions,
    ): int {
        $count = 0;

        foreach ($resources as $resource) {
            $logCount = rand(1, 50);

            for ($i = 0; $i < $logCount; $i++) {
                $action = $actions[array_rand($actions)];

                $loggableType = $action['loggable'] ? $morphType : null;
                $loggableId = $action['loggable'] ? $resource->id : null;

                $params = ($action['params'])($resource);
                $changes = $action['changes'] ? ($action['changes'])($resource) : null;
                $properties = $action['properties'] ? ($action['properties'])($resource) : null;

                $this->createLog(
                    $logType,
                    $loggableType,
                    $loggableId,
                    $actors->random()->id,
                    $action['action'],
                    self::PREFIX.$action['key'],
                    $params,
                    $changes,
                    $properties,
                );

                $count++;
            }
        }

        return $count;
    }

    /**
     * 혼합 로그 유형(Admin/User)을 가진 리소스 중심 랜덤 로그를 생성합니다.
     *
     * 각 리소스마다 rand(1,50)개의 랜덤 액션을 선택(중복 허용)하여 로그를 생성합니다.
     * 각 액션 템플릿에 logType 필드가 있으면 해당 유형을 사용합니다.
     *
     * @param  Collection  $resources  리소스 컬렉션
     * @param  Collection  $admins  관리자 컬렉션
     * @param  Collection  $users  일반 사용자 컬렉션
     * @param  string  $morphType  모델 morph 타입
     * @param  array  $actions  액션 템플릿 배열 (logType, userIdResolver 포함 가능)
     * @return int 생성된 로그 수
     */
    private function generateResourceLogsWithMixedTypes(
        Collection $resources,
        Collection $admins,
        Collection $users,
        string $morphType,
        array $actions,
    ): int {
        $count = 0;

        foreach ($resources as $resource) {
            $logCount = rand(1, 50);

            for ($i = 0; $i < $logCount; $i++) {
                $action = $actions[array_rand($actions)];

                // logType이 null이면 랜덤으로 Admin/User 결정 (attachment.upload 패턴)
                $logType = $action['logType'] ?? (rand(0, 1) ? ActivityLogType::Admin : ActivityLogType::User);

                $loggableType = $action['loggable'] ? $morphType : null;
                $loggableId = $action['loggable'] ? $resource->id : null;

                // userIdResolver가 있으면 사용, 없으면 logType에 따라 결정
                $actorPool = $logType === ActivityLogType::Admin ? $admins : $users;

                if (isset($action['userIdResolver'])) {
                    $userId = ($action['userIdResolver'])($resource, $actorPool);
                } else {
                    $userId = $actorPool->random()->id;
                }

                $params = ($action['params'])($resource);
                $changes = $action['changes'] ? ($action['changes'])($resource) : null;
                $properties = $action['properties'] ? ($action['properties'])($resource) : null;

                $this->createLog(
                    $logType,
                    $loggableType,
                    $loggableId,
                    $userId,
                    $action['action'],
                    self::PREFIX.$action['key'],
                    $params,
                    $changes,
                    $properties,
                );

                $count++;
            }
        }

        return $count;
    }

    /**
     * ActivityLog 레코드를 생성합니다.
     *
     * @param  ActivityLogType  $logType  로그 유형
     * @param  string|null  $loggableType  모델 morph 타입
     * @param  int|null  $loggableId  모델 ID
     * @param  int  $userId  사용자 ID
     * @param  string  $action  액션명
     * @param  string  $descriptionKey  다국어 키
     * @param  array  $descriptionParams  다국어 파라미터
     * @param  array|null  $changes  변경 이력
     * @param  array|null  $properties  추가 속성
     */
    private function createLog(
        ActivityLogType $logType,
        ?string $loggableType,
        ?int $loggableId,
        int $userId,
        string $action,
        string $descriptionKey,
        array $descriptionParams,
        ?array $changes = null,
        ?array $properties = null,
    ): ActivityLog {
        return ActivityLog::create([
            'log_type' => $logType,
            'loggable_type' => $loggableType,
            'loggable_id' => $loggableId,
            'user_id' => $userId,
            'action' => $action,
            'description_key' => $descriptionKey,
            'description_params' => $descriptionParams,
            'changes' => $changes,
            'properties' => $properties,
            'ip_address' => self::IPS[array_rand(self::IPS)],
            'user_agent' => self::USER_AGENTS[array_rand(self::USER_AGENTS)],
            'created_at' => Carbon::now()->subDays(rand(1, 30))->subHours(rand(0, 23))->subMinutes(rand(0, 59)),
        ]);
    }

    /**
     * 다국어 name 배열에서 현재 로케일 값을 가져옵니다.
     *
     * @param  mixed  $name  name 속성 (배열 또는 문자열)
     */
    private function getLocalizedName(mixed $name): string
    {
        if (is_string($name)) {
            return $name;
        }

        if (is_array($name)) {
            $locale = app()->getLocale();

            return $name[$locale] ?? $name['ko'] ?? $name[array_key_first($name)] ?? '';
        }

        return '';
    }

    /**
     * Enum에서 현재 값과 다른 값을 반환합니다.
     *
     * @param  class-string  $enumClass  Enum 클래스
     * @param  mixed  $currentValue  현재 값
     */
    private function pickDifferentEnum(string $enumClass, mixed $currentValue): string
    {
        $cases = $enumClass::cases();
        $currentStr = $currentValue instanceof \BackedEnum ? $currentValue->value : (string) $currentValue;

        $others = array_filter($cases, fn ($c) => $c->value !== $currentStr);

        if (empty($others)) {
            return $cases[0]->value;
        }

        return $others[array_rand($others)]->value;
    }

    /**
     * 바이트 수를 사람이 읽기 쉬운 형식으로 변환합니다.
     *
     * @param  int  $bytes  바이트 수
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1).' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return $bytes.' B';
    }
}
