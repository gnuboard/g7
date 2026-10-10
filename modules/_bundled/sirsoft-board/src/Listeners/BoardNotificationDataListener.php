<?php

namespace Modules\Sirsoft\Board\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Extension\ModuleManager;
use App\Services\NotificationRecipientCapability;
use BackedEnum;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Board\Enums\PostStatus;
use Modules\Sirsoft\Board\Enums\TriggerType;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Models\Report;
use Modules\Sirsoft\Board\Module;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\CommentRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\PostRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\ReportRepositoryInterface;
use Modules\Sirsoft\Board\Services\UserNotificationSettingService;

/**
 * 게시판 알림 데이터 필터 리스너
 *
 * notification_definitions의 extract_data 필터를 처리하여
 * 알림 발송에 필요한 데이터와 컨텍스트를 제공합니다.
 * 수신자 결정은 notification_templates.recipients 설정에 위임합니다.
 */
class BoardNotificationDataListener implements HookListenerInterface
{
    /**
     * 게시글 링크를 관리자 주소로 올려도 되는지 가르는 권한.
     *
     * 역할 이름이 아니라 권한으로 판정한다 — 게시판마다 관리자 역할이 동적으로 생기므로
     * 역할 열거는 곧 낡고, 그 화면을 못 여는 사람에게 관리자 주소를 주지 않는다.
     */
    private const ADMIN_BOARD_PERMISSION = 'sirsoft-board.boards.read';

    /**
     * @param  BoardRepositoryInterface  $boardRepository  게시판 조회
     * @param  PostRepositoryInterface  $postRepository  게시글 조회
     * @param  CommentRepositoryInterface  $commentRepository  댓글 조회
     * @param  UserNotificationSettingService  $userNotificationSettingService  사용자 알림 설정
     * @param  RoleRepositoryInterface  $roleRepository  역할 조회
     * @param  UserRepositoryInterface  $userRepository  사용자 조회 (관리자 부재 시 superAdmin 폴백)
     * @param  ReportRepositoryInterface  $reportRepository  신고 케이스·로그 조회
     */
    public function __construct(
        private readonly BoardRepositoryInterface $boardRepository,
        private readonly PostRepositoryInterface $postRepository,
        private readonly CommentRepositoryInterface $commentRepository,
        private readonly UserNotificationSettingService $userNotificationSettingService,
        private readonly RoleRepositoryInterface $roleRepository,
        private readonly UserRepositoryInterface $userRepository,
        private readonly ReportRepositoryInterface $reportRepository,
    ) {}

    /**
     * 구독할 훅 목록을 반환합니다.
     *
     * @return array
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-board.notification.extract_data' => [
                'method' => 'extractData',
                'priority' => 20,
                'type' => 'filter',
            ],
            'sirsoft-board.notification.personalize_data' => [
                'method' => 'personalizeBoardData',
                'priority' => 20,
                'type' => 'filter',
            ],
            'core.notification.filter_default_definitions' => [
                'method' => 'contributeDefaultDefinitions',
                'priority' => 20,
                'type' => 'filter',
            ],
        ];
    }

    /**
     * 훅 이벤트를 처리합니다.
     *
     * @param  mixed  ...$args  훅에서 전달된 인수들
     * @return void
     */
    public function handle(...$args): void {}

    /**
     * 게시판 모듈의 기본 알림 정의를 코어 리셋 로직에 제공합니다.
     *
     * @param  array  $definitions  현재까지 수집된 기본 정의 목록
     * @param  array  $context  type/channel 필터 컨텍스트
     * @return array 게시판 시더 정의를 병합한 목록
     */
    public function contributeDefaultDefinitions(array $definitions, array $context = []): array
    {
        // module.php 의 getNotificationDefinitions() 가 SSoT — declarative getter 패턴
        /** @var Module $module */
        $module = app(ModuleManager::class)->getModule('sirsoft-board');
        if (! $module) {
            return $definitions;
        }

        $contributed = [];
        foreach ($module->getNotificationDefinitions() as $data) {
            $contributed[] = array_merge($data, [
                'extension_type' => 'module',
                'extension_identifier' => $module->getIdentifier(),
            ]);
        }

        return array_merge($definitions, $contributed);
    }

    /**
     * 알림 유형에 따라 데이터와 컨텍스트를 추출합니다.
     *
     * @param  array  $default  기본 extract_data 구조
     * @param  string  $type  알림 정의 유형
     * @param  array  $args  훅에서 전달된 원본 인수
     * @return array{notifiable: null, notifiables: null, data: array, context: array}
     */
    public function extractData(array $default, string $type, array $args): array
    {
        return match ($type) {
            'new_comment' => $this->extractNewComment($args),
            'reply_comment' => $this->extractReplyComment($args),
            'post_reply' => $this->extractPostReply($args),
            'post_action' => $this->extractPostAction($args),
            'report_action' => $this->extractReportAction($args),
            'new_post_admin' => $this->extractNewPostAdmin($args),
            'report_received_admin' => $this->extractReportReceivedAdmin($args),
            default => $default,
        };
    }

    // ──────────────────────────────────────────────
    // 댓글/대댓글 알림 (2종)
    // ──────────────────────────────────────────────

    /**
     * 새 댓글 알림 데이터를 추출합니다.
     *
     * @param  array  $args  훅 인수 [$comment, $slug]
     * @return array
     */
    private function extractNewComment(array $args): array
    {
        $comment = $args[0] ?? null;
        $slug = $args[1] ?? '';

        if (! $comment instanceof Comment || ! empty($comment->parent_id)) {
            return $this->emptyResult();
        }

        $board = $this->boardRepository->findBySlug($slug);
        if (! $board || ! $board->notify_author) {
            return $this->emptyResult();
        }

        $post = $this->postRepository->find($slug, $comment->post_id);
        if (! $post || ! $post->user_id) {
            return $this->emptyResult();
        }

        // 사용자 개인 알림 설정 체크
        if (! $this->isUserNotificationEnabled($post->user_id, 'notify_comment')) {
            return $this->emptyResult();
        }

        $commentContent = $this->truncateContent($comment->content);

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => [
                'name' => '{recipient_name}',
                'app_name' => config('app.name'),
                'board_name' => $board->localizedName ?? $board->name,
                'post_title' => $post->title ?? '',
                'comment_author' => $comment->user?->name ?? $comment->author_name ?? '',
                'comment_content' => $commentContent,
                'post_url' => $this->buildPostUrl($board, $post),
                'site_url' => config('app.url'),
            ],
            'context' => [
                'trigger_user_id' => $comment->user_id,
                'trigger_user' => $comment->user,
                'related_users' => [
                    'post_author' => $post->user,
                ],
            ],
        ];
    }

    /**
     * 대댓글 알림 데이터를 추출합니다.
     *
     * @param  array  $args  훅 인수 [$comment, $slug]
     * @return array
     */
    private function extractReplyComment(array $args): array
    {
        $comment = $args[0] ?? null;
        $slug = $args[1] ?? '';

        if (! $comment instanceof Comment || empty($comment->parent_id)) {
            return $this->emptyResult();
        }

        $board = $this->boardRepository->findBySlug($slug);
        if (! $board || ! $board->notify_author) {
            return $this->emptyResult();
        }

        $post = $this->postRepository->find($slug, $comment->post_id);
        if (! $post) {
            return $this->emptyResult();
        }

        $parentComment = $this->commentRepository->find($slug, $comment->parent_id);
        if (! $parentComment || ! $parentComment->user_id) {
            return $this->emptyResult();
        }

        // 사용자 개인 알림 설정 체크
        if (! $this->isUserNotificationEnabled($parentComment->user_id, 'notify_reply_comment')) {
            return $this->emptyResult();
        }

        $commentContent = $this->truncateContent($comment->content);

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => [
                'name' => '{recipient_name}',
                'app_name' => config('app.name'),
                'board_name' => $board->localizedName ?? $board->name,
                'post_title' => $post->title ?? '',
                'comment_author' => $comment->user?->name ?? $comment->author_name ?? '',
                'comment_content' => $commentContent,
                'post_url' => $this->buildPostUrl($board, $post),
                'site_url' => config('app.url'),
            ],
            'context' => [
                'trigger_user_id' => $comment->user_id,
                'trigger_user' => $comment->user,
                'related_users' => [
                    'parent_comment_author' => $parentComment->user,
                ],
            ],
        ];
    }

    // ──────────────────────────────────────────────
    // 게시글 알림 (3종)
    // ──────────────────────────────────────────────

    /**
     * 답변글 알림 데이터를 추출합니다.
     *
     * @param  array  $args  훅 인수 [$post, $slug, $options?]
     * @return array
     */
    private function extractPostReply(array $args): array
    {
        $post = $args[0] ?? null;
        $slug = $args[1] ?? '';

        if (! $post instanceof Post || empty($post->parent_id)) {
            return $this->emptyResult();
        }

        $board = $this->boardRepository->findBySlug($slug);
        if (! $board || ! $board->notify_author) {
            return $this->emptyResult();
        }

        $parentPost = $this->postRepository->find($slug, $post->parent_id);
        if (! $parentPost || ! $parentPost->user_id) {
            return $this->emptyResult();
        }

        // 사용자 개인 알림 설정 체크
        if (! $this->isUserNotificationEnabled($parentPost->user_id, 'notify_post_reply')) {
            return $this->emptyResult();
        }

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => [
                'name' => '{recipient_name}',
                'app_name' => config('app.name'),
                'board_name' => $board->localizedName ?? $board->name,
                'post_title' => $parentPost->title ?? '',
                'post_url' => $this->buildPostUrl($board, $parentPost),
                'site_url' => config('app.url'),
            ],
            'context' => [
                'trigger_user_id' => $post->user_id,
                'trigger_user' => $post->user,
                'related_users' => [
                    'original_post_author' => $parentPost->user,
                ],
            ],
        ];
    }

    /**
     * 관리자 처리(직권) 알림 데이터를 추출합니다.
     *
     * @param  array  $args  훅 인수 [$post, $slug] 또는 [$comment, $slug]
     * @return array
     */
    private function extractPostAction(array $args): array
    {
        $target = $args[0] ?? null;
        $slug = $args[1] ?? '';

        $board = $this->boardRepository->findBySlug($slug);
        if (! $board || ! $board->notify_author) {
            return $this->emptyResult();
        }

        if ($target instanceof Post) {
            if (! $target->user_id) {
                return $this->emptyResult();
            }

            // 신고 기반 처리(수동/자동)는 report_action에서 처리 (admin 직권만 여기서 처리)
            if (in_array($target->trigger_type, [TriggerType::Report, TriggerType::AutoHide], true)) {
                return $this->emptyResult();
            }

            // 수신자(작성자) 개인 알림 설정 체크
            if (! $this->isUserNotificationEnabled($target->user_id, 'notify_post_complete')) {
                return $this->emptyResult();
            }

            $actionType = $this->resolveActionType($args);
            $targetTypeLabel = __('sirsoft-board::notification.report_action.target_types.post');

            return [
                'notifiable' => null,
                'notifiables' => null,
                'data' => [
                    'name' => '{recipient_name}',
                    'app_name' => config('app.name'),
                    'board_name' => $board->localizedName ?? $board->name,
                    'post_title' => $target->title ?? '',
                    'action_type' => $actionType,
                    'target_type' => $targetTypeLabel,
                    'post_url' => $this->buildPostUrl($board, $target),
                    'site_url' => config('app.url'),
                ],
                'context' => [
                    'trigger_user_id' => Auth::id(),
                    'trigger_user' => Auth::user(),
                    'related_users' => [
                        'post_author' => $target->user,
                    ],
                ],
            ];
        }

        if ($target instanceof Comment) {
            if (! $target->user_id) {
                return $this->emptyResult();
            }

            // 신고 기반 처리(수동/자동)는 report_action에서 처리 (admin 직권만 여기서 처리)
            if (in_array($target->trigger_type, [TriggerType::Report, TriggerType::AutoHide], true)) {
                return $this->emptyResult();
            }

            // 수신자(댓글 작성자) 개인 알림 설정 체크
            if (! $this->isUserNotificationEnabled($target->user_id, 'notify_post_complete')) {
                return $this->emptyResult();
            }

            $post = $this->postRepository->find($slug, $target->post_id);
            $actionType = $this->resolveActionType($args);
            $targetTypeLabel = __('sirsoft-board::notification.report_action.target_types.comment');

            return [
                'notifiable' => null,
                'notifiables' => null,
                'data' => [
                    'name' => '{recipient_name}',
                    'app_name' => config('app.name'),
                    'board_name' => $board->localizedName ?? $board->name,
                    'post_title' => $post?->title ?? '',
                    'action_type' => $actionType,
                    'target_type' => $targetTypeLabel,
                    'post_url' => $post ? $this->buildPostUrl($board, $post) : config('app.url'),
                    'site_url' => config('app.url'),
                ],
                'context' => [
                    'trigger_user_id' => Auth::id(),
                    'trigger_user' => Auth::user(),
                    'related_users' => [
                        'post_author' => $target->user,
                    ],
                ],
            ];
        }

        return $this->emptyResult();
    }

    /**
     * 신고 처리 결과 알림 데이터를 추출합니다.
     *
     * @param  array  $args  훅 인수 [$post, $slug] 또는 [$comment, $slug]
     * @return array
     */
    private function extractReportAction(array $args): array
    {
        $target = $args[0] ?? null;
        $slug = $args[1] ?? '';

        $reportPolicy = g7_module_settings('sirsoft-board', 'report_policy', []);
        if (empty($reportPolicy['notify_author_on_report_action'])) {
            return $this->emptyResult();
        }

        $board = $this->boardRepository->findBySlug($slug);
        if (! $board) {
            return $this->emptyResult();
        }

        if ($target instanceof Post) {
            // 신고 기반 처리(수동/자동)만 처리 (admin 직권은 post_action에서 처리)
            if (! $target->user_id || ! in_array($target->trigger_type, [TriggerType::Report, TriggerType::AutoHide], true)) {
                return $this->emptyResult();
            }

            $actionType = $this->resolveActionType($args);
            $targetTypeLabel = __('sirsoft-board::notification.report_action.target_types.post');

            return [
                'notifiable' => null,
                'notifiables' => null,
                'data' => [
                    'name' => '{recipient_name}',
                    'app_name' => config('app.name'),
                    'board_name' => $board->localizedName ?? $board->name,
                    'post_title' => $target->title ?? '',
                    'action_type' => $actionType,
                    'target_type' => $targetTypeLabel,
                    'post_url' => $this->buildPostUrl($board, $target),
                    'site_url' => config('app.url'),
                ],
                'context' => [
                    'trigger_user_id' => Auth::id(),
                    'trigger_user' => Auth::user(),
                    'related_users' => [
                        'post_author' => $target->user,
                    ],
                ],
            ];
        }

        if ($target instanceof Comment) {
            // 신고 기반 처리(수동/자동)만 처리 (admin 직권은 post_action에서 처리)
            if (! $target->user_id || ! in_array($target->trigger_type, [TriggerType::Report, TriggerType::AutoHide], true)) {
                return $this->emptyResult();
            }

            $post = $this->postRepository->find($slug, $target->post_id);
            $actionType = $this->resolveActionType($args);
            $targetTypeLabel = __('sirsoft-board::notification.report_action.target_types.comment');

            return [
                'notifiable' => null,
                'notifiables' => null,
                'data' => [
                    'name' => '{recipient_name}',
                    'app_name' => config('app.name'),
                    'board_name' => $board->localizedName ?? $board->name,
                    'post_title' => $post?->title ?? '',
                    'action_type' => $actionType,
                    'target_type' => $targetTypeLabel,
                    'post_url' => $post ? $this->buildPostUrl($board, $post) : config('app.url'),
                    'site_url' => config('app.url'),
                ],
                'context' => [
                    'trigger_user_id' => Auth::id(),
                    'trigger_user' => Auth::user(),
                    'related_users' => [
                        'post_author' => $target->user,
                    ],
                ],
            ];
        }

        return $this->emptyResult();
    }

    // ──────────────────────────────────────────────
    // 관리자 알림 (2종)
    // ──────────────────────────────────────────────

    /**
     * 신규 게시글 관리자 알림 데이터를 추출합니다.
     *
     * @param  array  $args  훅 인수 [$post, $slug, $options?]
     * @return array
     */
    private function extractNewPostAdmin(array $args): array
    {
        $post = $args[0] ?? null;
        $slug = $args[1] ?? '';
        $options = $args[2] ?? [];

        if (! $post instanceof Post) {
            return $this->emptyResult();
        }

        // skip_notification 옵션 확인
        if (! empty($options['skip_notification'])) {
            return $this->emptyResult();
        }

        $board = $this->boardRepository->findBySlug($slug);
        if (! $board || ! $board->notify_admin_on_post) {
            return $this->emptyResult();
        }

        // 답변글은 제외
        if (! empty($post->parent_id)) {
            return $this->emptyResult();
        }

        $managers = $this->resolveBoardManagers($slug);

        if ($managers->isEmpty()) {
            return $this->emptyResult();
        }

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => [
                'name' => '{recipient_name}',
                'app_name' => config('app.name'),
                'board_name' => $board->localizedName ?? $board->name,
                'post_title' => $post->title ?? '',
                'post_author' => $post->author_name ?? $post->user?->name ?? '',
                'post_url' => $this->buildPostUrl($board, $post),
                'site_url' => config('app.url'),
            ],
            'context' => [
                'trigger_user_id' => $post->user_id,
                'trigger_user' => $post->user,
                'related_users' => [
                    'board_managers' => $managers,
                ],
                // personalize 가 읽는 **스칼라**다 — 큐에서 모델을 다시 읽으면 과거 사건의
                // 알림에 "지금" 상태가 실린다.
                'board_slug' => (string) $board->slug,
                'post_id' => (int) $post->id,
            ],
        ];
    }

    /**
     * 수신자가 게시판 운영자면 게시글 링크를 관리자 화면 주소로 올립니다.
     *
     * 공개 게시글 주소는 **블라인드 글에서 아무것도 보여 주지 못한다** — 새 글 통보를 받고
     * 들어간 운영자가 할 수 있는 일이 없다. 그렇다고 유형 이름으로 관리자 여부를 단정하면,
     * 운영자가 그 알림의 수신자를 바꾸는 순간 일반 회원에게 관리자 주소가 간다.
     *
     * @param  array  $data  앞선 필터까지의 알림 데이터
     * @param  object  $notifiable  수신자 (User 또는 GuestNotifiable)
     * @param  string  $type  알림 정의 유형
     * @param  array  $context  extract_data 가 실은 컨텍스트
     * @param  array  $args  원래 훅 인자 (읽지 않는다)
     * @return array 수신자별로 치환된 알림 데이터
     */
    public function personalizeBoardData(
        array $data,
        object $notifiable,
        string $type,
        array $context,
        array $args = []
    ): array {
        // 새 글 관리자 통보에만 적용한다 — 답글·댓글 알림의 수신자는 글쓴이 본인이라
        // 관리자 주소가 맞지 않는다.
        if ($type !== 'new_post_admin') {
            return $data;
        }

        $slug = (string) ($context['board_slug'] ?? '');
        $postId = (int) ($context['post_id'] ?? 0);

        if ($slug === '' || $postId <= 0) {
            return $data;
        }

        if (! app(NotificationRecipientCapability::class)->can($notifiable, self::ADMIN_BOARD_PERMISSION)) {
            return $data;
        }

        $data['post_url'] = $this->buildAdminPostUrl($slug, $postId);

        return $data;
    }

    /**
     * 관리자 게시글 상세 주소를 만듭니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $postId  게시글 ID
     * @return string 절대 URL
     */
    private function buildAdminPostUrl(string $slug, int $postId): string
    {
        return config('app.url')."/admin/board/{$slug}/post/{$postId}";
    }

    /**
     * 신고 접수 관리자 알림 데이터를 추출합니다.
     *
     * @param  array  $args  훅 인수 [$report]
     * @return array
     */
    private function extractReportReceivedAdmin(array $args): array
    {
        $report = $args[0] ?? null;

        if (! $report instanceof Report) {
            return $this->emptyResult();
        }

        $reportPolicy = g7_module_settings('sirsoft-board', 'report_policy', []);
        if (empty($reportPolicy['notify_admin_on_report'])) {
            return $this->emptyResult();
        }

        // 발송 범위 조건: per_case = 케이스당 1회, per_report = 신고 건마다
        $scope = $reportPolicy['notify_admin_on_report_scope'] ?? 'per_case';
        if ($scope === 'per_case') {
            $activeCycleLogCount = $this->reportRepository->countLogsByReportSince(
                (int) $report->getKey(),
                $report->last_activated_at
            );

            if ($activeCycleLogCount > 1) {
                return $this->emptyResult();
            }
        }

        $board = $report->board;
        if (! $board) {
            return $this->emptyResult();
        }

        $log = $this->reportRepository->findLatestLogByReport((int) $report->getKey());
        $postTitle = $log?->snapshot['title'] ?? '';
        $boardName = $log?->snapshot['board_name'] ?? ($board->localizedName ?? $board->name);
        $targetType = $report->target_type instanceof BackedEnum
            ? $report->target_type->value
            : (string) $report->target_type;
        $reasonType = $log?->reason_type instanceof BackedEnum
            ? $log->reason_type->value
            : ($log?->reason_type ? (string) $log->reason_type : 'etc');

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => [
                'name' => '{recipient_name}',
                'app_name' => config('app.name'),
                'board_name' => $boardName,
                'post_title' => $postTitle,
                'target_type' => __('sirsoft-board::notification.report_action.target_types.'.$targetType),
                'reason_type' => __('sirsoft-board::notification.report_received_admin.reason_types.'.$reasonType),
                'report_url' => config('app.url').'/admin/boards/reports',
                'site_url' => config('app.url'),
            ],
            'context' => [
                'trigger_user_id' => $report->reporter_id ?? null,
                'trigger_user' => $report->reporter ?? null,
                // 신고 알림 수신자는 **그 게시판의 관리자**다. 종전 `permission` 수신자 타입은
                // 알림 인프라의 인정 어휘 밖이라 운영자가 이 템플릿을 화면에서 고칠 수 없었고
                // (아무것도 안 바꾸고 저장만 눌러도 422), 목록 칩에도 뜨지 않았다.
                'related_users' => [
                    'board_managers' => $this->resolveBoardManagers((string) $board->slug),
                ],
            ],
        ];
    }

    // ──────────────────────────────────────────────
    // 헬퍼 메서드
    // ──────────────────────────────────────────────

    /**
     * 게시판별 관리자 사용자를 반환합니다 (없으면 superAdmin 폴백).
     *
     * 게시판마다 관리자 역할이 동적으로 생기므로(`sirsoft-board.{slug}.manager`) 역할 이름을
     * 알림 정의에 열거할 수 없다 — 관계(`board_managers`)로 넘긴다.
     *
     * @param  string  $slug  게시판 슬러그
     * @return Collection 관리자 사용자 목록
     */
    private function resolveBoardManagers(string $slug): Collection
    {
        $managers = $this->roleRepository->getUsersByIdentifier("sirsoft-board.{$slug}.manager");

        if ($managers->isEmpty()) {
            $superAdmin = $this->userRepository->findSuperAdmin();
            $managers = $superAdmin ? collect([$superAdmin]) : collect();
        }

        return $managers;
    }

    /**
     * 게시글 URL을 생성합니다.
     *
     * @param  Board  $board  게시판
     * @param  Post  $post  게시글
     * @return string
     */
    private function buildPostUrl(Board $board, Post $post): string
    {
        return config('app.url')."/board/{$board->slug}/{$post->id}";
    }

    /**
     * 컨텐츠를 200자로 자릅니다.
     *
     * @param  string|null  $content  원본 컨텐츠
     * @return string
     */
    private function truncateContent(?string $content): string
    {
        $text = strip_tags($content ?? '');

        return mb_strlen($text) > 200 ? mb_substr($text, 0, 200).'...' : $text;
    }

    /**
     * 모델 상태에서 처리 유형을 추론합니다.
     *
     * after_blind/after_delete/after_restore 훅에서 호출되므로
     * 모델의 현재 상태로 어떤 처리가 수행되었는지 판단합니다.
     *
     * @param  array  $args  훅 인수 [$target, $slug, ...]
     * @return string 처리 유형 (번역된 라벨)
     */
    private function resolveActionType(array $args): string
    {
        $target = $args[0] ?? null;

        if ($target instanceof Post) {
            if ($target->trashed()) {
                return __('sirsoft-board::notification.post_action.action_types.deleted');
            }
            if ($target->status === PostStatus::Blinded) {
                return __('sirsoft-board::notification.post_action.action_types.blind');
            }

            return __('sirsoft-board::notification.post_action.action_types.restored');
        }

        if ($target instanceof Comment) {
            if ($target->trashed()) {
                return __('sirsoft-board::notification.post_action.action_types.deleted');
            }
            if ($target->status === PostStatus::Blinded) {
                return __('sirsoft-board::notification.post_action.action_types.blind');
            }

            return __('sirsoft-board::notification.post_action.action_types.restored');
        }

        return '';
    }

    /**
     * 사용자의 개인 알림 설정이 활성화되어 있는지 확인합니다.
     *
     * 설정 레코드가 없으면 기본적으로 알림을 받지 않는 것으로 간주합니다.
     *
     * @param  int  $userId  사용자 ID
     * @param  string  $field  알림 설정 필드명 (notify_comment, notify_reply_comment, notify_post_reply, notify_post_complete)
     * @return bool 알림 활성화 여부
     */
    private function isUserNotificationEnabled(int $userId, string $field): bool
    {
        $settings = $this->userNotificationSettingService->getByUserId($userId);

        // 설정 레코드 없음 → 기본값: 알림 미수신
        if (! $settings) {
            return false;
        }

        return (bool) ($settings->{$field} ?? false);
    }

    /**
     * 빈 결과를 반환합니다.
     *
     * @return array
     */
    private function emptyResult(): array
    {
        // context.skip=true 로 NotificationHookListener 가 발송 자체를 스킵하도록 지시.
        // (template recipients 기반 해석이 Listener 의 정책 gate 를 우회하는 문제 대응)
        return ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => ['skip' => true]];
    }
}
