<?php

namespace Modules\Sirsoft\Board\Privacy;

use App\Extension\HookManager;
use App\Privacy\Contracts\PrivacyRetentionHandlerInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Board\Enums\ReportType;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Privacy\Concerns\PaginatesBoardTargets;
use Modules\Sirsoft\Board\Repositories\Contracts\ReportRepositoryInterface;
use Modules\Sirsoft\Board\Services\AttachmentService;
use Modules\Sirsoft\Board\Services\CommentService;
use Modules\Sirsoft\Board\Services\PostService;
use Throwable;

/**
 * 게시판 비회원 작성자 정보 보유기간 핸들러 (이슈 #636)
 *
 * 비회원(`user_id` NULL)이 쓴 게시글·댓글을 작성 후 N일이 지나면 운영자가 고른 방식으로
 * 처리합니다. 회원 글은 대상이 아닙니다 — 회원의 개인정보는 회원 탈퇴·휴면 생명주기가
 * 다루고, 탈퇴 회원 글은 표시 계층이 이미 「탈퇴한 사용자」로 가립니다.
 *
 * ## 두 방식
 *
 * - **익명화(anonymize, 기본)**: 작성자명을 고정 치환값으로 바꿉니다. 글은 그대로 남아 게시판
 *   흐름(답글·댓글 맥락)이 깨지지 않습니다.
 * - **삭제(delete)**: 게시판의 삭제 경로({@see PostService::deleteForRetention()})로 지운 뒤
 *   그 행에 남은 작성자명·비밀번호·IP·제목·본문을 파기하고 첨부는 파일째 없앱니다.
 *   행을 물리적으로 지우지 않고 **빈 삭제 행**으로 남기는 이유: 비회원 글 아래의 답글·
 *   댓글 트리와 게시판 건수가 삭제 경로의 리스너로 맞춰지고, 되살려도 복원될 개인정보가
 *   없습니다. 개인정보가 남지 않으므로 파기(개인정보보호법 §21)에 해당합니다.
 *
 * 두 방식 모두 신고 로그가 복사해 둔 스냅샷(작성자명·제목·본문)도 함께 덮습니다 — 원본만
 * 지우면 그 사본이 남아 「지웠다」가 거짓이 됩니다. 삭제된(휴지통) 행도 대상입니다.
 *
 * ## 멱등
 *
 * 익명화는 「작성자명이 치환값이 아닌 행」, 삭제는 「삭제됐고 작성자명이 치환값이며 본문이
 * 빈 행이 아닌 행」이 대상이라 두 번째 실행은 0건입니다. 익명화했던 글을 나중에 삭제 모드로
 * 바꾸면 그 글은 아직 본문이 남아 있으므로 다시 대상이 됩니다.
 */
class BoardGuestRetentionHandler implements PrivacyRetentionHandlerInterface
{
    use PaginatesBoardTargets;

    /** 비회원 작성자명 치환값 — 멱등 판정의 표지이기도 하다 */
    public const ANONYMIZED_AUTHOR = '***';

    /** 익명화 모드 */
    public const MODE_ANONYMIZE = 'anonymize';

    /** 삭제 모드 */
    public const MODE_DELETE = 'delete';

    /** 한 번에 읽어 처리할 행 수 */
    private const CHUNK_SIZE = 200;

    /**
     * @param  PostService  $postService  게시글 서비스 (삭제 경로)
     * @param  CommentService  $commentService  댓글 서비스 (삭제 경로)
     * @param  AttachmentService  $attachmentService  첨부 서비스 (파일 파기)
     * @param  ReportRepositoryInterface  $reportRepository  신고 저장소 (스냅샷 파기)
     */
    public function __construct(
        private PostService $postService,
        private CommentService $commentService,
        private AttachmentService $attachmentService,
        private ReportRepositoryInterface $reportRepository,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function id(): string
    {
        return 'sirsoft-board.guest';
    }

    /**
     * {@inheritDoc}
     */
    public function label(): string
    {
        return __('sirsoft-board::messages.privacy_retention.handlers.guest');
    }

    /**
     * 보유기간 기능이 켜져 있고 비회원 보존기간이 1일 이상일 때만 켜집니다.
     *
     * 비회원 보존기간 0 은 「이 축을 쓰지 않음」이다 — 켜 두고 0 이면 꺼진 것으로 보고한다
     * (대상 0건과 구분).
     *
     * @return bool 활성 여부
     */
    public function isEnabled(): bool
    {
        return (bool) module_setting('sirsoft-board', 'privacy_retention.enabled', false)
            && $this->retentionDays() > 0;
    }

    /**
     * 비회원 보존기간(일)을 설정에서 읽습니다 (0 = 미적용).
     *
     * @return int 보존기간(일)
     */
    public function retentionDays(): int
    {
        return max(0, (int) module_setting('sirsoft-board', 'privacy_retention.guest_retention_days', 0));
    }

    /**
     * 처리 방식을 읽습니다. 알 수 없는 값은 되돌릴 수 있는 쪽(익명화)으로 떨어집니다.
     *
     * @return string anonymize|delete
     */
    public function mode(): string
    {
        $mode = module_setting('sirsoft-board', 'privacy_retention.guest_mode', self::MODE_ANONYMIZE);

        return $mode === self::MODE_DELETE ? self::MODE_DELETE : self::MODE_ANONYMIZE;
    }

    /**
     * {@inheritDoc}
     */
    public function preview(): int
    {
        return $this->postQuery()->count() + $this->commentQuery()->count();
    }

    /**
     * {@inheritDoc}
     */
    public function targets(int $page, int $perPage): LengthAwarePaginator
    {
        return $this->paginateBoardTargets($this->postQuery(), $this->commentQuery(), $page, $perPage);
    }

    /**
     * {@inheritDoc}
     */
    public function targetColumns(): array
    {
        return $this->boardTargetColumns();
    }

    /**
     * {@inheritDoc}
     */
    public function targetPermissions(): array
    {
        return $this->boardTargetPermissions();
    }

    /**
     * {@inheritDoc}
     */
    public function runPermissions(): array
    {
        return $this->boardRunPermissions();
    }

    /**
     * {@inheritDoc}
     */
    public function process(bool $dryRun, bool $force = false): array
    {
        // 관리자 직권 실행(`$force`)만 토글 가드를 넘는다 — 권한은 코어 직권 실행 API 가 검사했다.
        if (! $force && ! $this->isEnabled()) {
            return ['count' => 0, 'mode' => 'disabled'];
        }

        $mode = $this->mode();

        if ($dryRun) {
            return ['count' => $this->preview(), 'mode' => $mode];
        }

        $days = $this->retentionDays();

        // 훅 이름을 리터럴로 적는다 — 조립하면 문서 생성기와 이름 검색이 찾지 못한다.
        HookManager::doAction('sirsoft-board.guest_author.before_prune', $days);

        // 게시글을 먼저 — 삭제 모드에서 게시글 삭제가 그 아래 댓글을 연쇄 삭제하므로
        // 댓글 쿼리는 그 뒤에 만들어야 이미 지워진 댓글의 파기(스크럽)까지 한 번에 잡는다.
        $processed = $this->processPosts($mode) + $this->processComments($mode);

        HookManager::doAction('sirsoft-board.guest_author.after_prune', $days, $processed);

        if ($processed > 0) {
            Log::info('게시판 비회원 작성자 정보 보유기간 처리 완료', [
                'days' => $days,
                'mode' => $mode,
                'count' => $processed,
            ]);
        }

        return ['count' => $processed, 'mode' => $mode];
    }

    /**
     * 보존기간이 지난 미처리 비회원 게시글 쿼리 — 미리보기와 실행이 같은 이 쿼리를 쓴다.
     *
     * @return Builder<Post> 대상 쿼리
     */
    public function postQuery(): Builder
    {
        return $this->scopeUnprocessed($this->scopeRetentionApplies(
            Post::withTrashed()
                ->whereNull('user_id')
                ->where('created_at', '<=', now()->subDays($this->retentionDays()))
        ));
    }

    /**
     * 보존기간이 지난 미처리 비회원 댓글 쿼리 — 미리보기와 실행이 같은 이 쿼리를 쓴다.
     *
     * @return Builder<Comment> 대상 쿼리
     */
    public function commentQuery(): Builder
    {
        return $this->scopeUnprocessed($this->scopeRetentionApplies(
            Comment::withTrashed()
                ->whereNull('user_id')
                ->where('created_at', '<=', now()->subDays($this->retentionDays()))
        ));
    }

    /**
     * 비회원 보존기간이 0(미적용)이면 대상을 비웁니다.
     *
     * 0 은 「이 축을 쓰지 않음」이다. 기간 0 을 그대로 쓰면 임계가 `now()` 가 되어 **모든 비회원
     * 글이 대상**으로 세어진다 — 실행은 `isEnabled()` 가 막지만, 건수·명단은 기능이 꺼져 있어도
     * 보여 주므로(2026-09-23 확정) 쿼리 자체가 비어야 운영자가 「전부 지워진다」로 오독하지 않는다.
     *
     * @param  Builder  $query  기본 쿼리
     * @return Builder 적용 여부가 반영된 쿼리
     */
    private function scopeRetentionApplies(Builder $query): Builder
    {
        return $this->retentionDays() > 0 ? $query : $query->whereRaw('1 = 0');
    }

    /**
     * 현재 방식 기준으로 이미 처리된 행을 뺍니다 (멱등 조건).
     *
     * @param  Builder  $query  기본 쿼리
     * @return Builder 미처리 행 쿼리
     */
    private function scopeUnprocessed(Builder $query): Builder
    {
        if ($this->mode() === self::MODE_DELETE) {
            // 삭제 완료 표지 = 삭제됨 + 작성자명 치환값 + 본문 비움. 셋 중 하나라도 아니면 남은 것이 있다.
            return $query->where(function (Builder $q) {
                $q->whereNull('deleted_at')
                    ->orWhereNull('author_name')
                    ->orWhere('author_name', '!=', self::ANONYMIZED_AUTHOR)
                    ->orWhere('content', '!=', '');
            });
        }

        return $query->where(function (Builder $q) {
            $q->whereNull('author_name')->orWhere('author_name', '!=', self::ANONYMIZED_AUTHOR);
        });
    }

    /**
     * 비회원 게시글을 처리합니다.
     *
     * @param  string  $mode  처리 방식
     * @return int 처리 건수
     */
    private function processPosts(string $mode): int
    {
        $processed = 0;

        $this->postQuery()
            ->with('board:id,slug')
            ->select(['id', 'board_id', 'deleted_at'])
            ->chunkById(self::CHUNK_SIZE, function ($posts) use ($mode, &$processed) {
                $done = [];

                foreach ($posts as $post) {
                    try {
                        if ($mode === self::MODE_DELETE) {
                            $this->deletePost($post);
                        } else {
                            $this->scrubSnapshots(ReportType::Post, $post->id, $mode);
                            $this->scrubRow(Post::class, $post->id, ['author_name' => self::ANONYMIZED_AUTHOR]);
                        }

                        $done[] = $post->id;
                    } catch (Throwable $e) {
                        // 건별 격리 — 한 글의 실패가 나머지 파기를 멈추지 않는다. 표지(행 덮어쓰기)는
                        // 신고 스냅샷까지 지운 뒤 마지막에 찍으므로 다음 실행이 다시 대상으로 잡는다.
                        Log::warning('게시판 비회원 게시글 보유기간 처리 실패 — 건너뜀', [
                            'post_id' => $post->id,
                            'mode' => $mode,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                $this->resyncSearchIndex($done);

                $processed += count($done);
            });

        return $processed;
    }

    /**
     * 비회원 댓글을 처리합니다.
     *
     * @param  string  $mode  처리 방식
     * @return int 처리 건수
     */
    private function processComments(string $mode): int
    {
        $processed = 0;

        $this->commentQuery()
            ->with('board:id,slug')
            ->select(['id', 'board_id', 'deleted_at'])
            ->chunkById(self::CHUNK_SIZE, function ($comments) use ($mode, &$processed) {
                $done = [];

                foreach ($comments as $comment) {
                    try {
                        if ($mode === self::MODE_DELETE) {
                            $alive = $comment->deleted_at === null;

                            if ($alive && $comment->board !== null) {
                                $this->commentService->deleteForRetention($comment->board->slug, $comment->id);
                            }

                            $this->scrubSnapshots(ReportType::Comment, $comment->id, $mode);
                            $this->scrubRow(Comment::class, $comment->id, $this->deletedRowValues(false, $alive && $comment->board === null));
                        } else {
                            $this->scrubSnapshots(ReportType::Comment, $comment->id, $mode);
                            $this->scrubRow(Comment::class, $comment->id, ['author_name' => self::ANONYMIZED_AUTHOR]);
                        }

                        $done[] = $comment->id;
                    } catch (Throwable $e) {
                        Log::warning('게시판 비회원 댓글 보유기간 처리 실패 — 건너뜀', [
                            'comment_id' => $comment->id,
                            'mode' => $mode,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                $processed += count($done);
            });

        return $processed;
    }

    /**
     * 비회원 게시글 한 건을 삭제 모드로 처리합니다.
     *
     * 살아 있는 글은 게시판 삭제 경로로 지워 답글·댓글 연쇄와 건수·연동 리스너를 태우고,
     * 그 뒤 행에 남은 개인정보와 첨부 파일을 파기한다. 이미 휴지통에 있던 글은 파기만 한다.
     * 게시판 행이 사라진 고아 글은 삭제 경로를 탈 수 없으므로 파기하면서 삭제 시각을 직접 찍는다.
     *
     * @param  Post  $post  대상 게시글 (id·board_id·deleted_at·board 만 로드됨)
     * @return void 반환값 없음
     */
    private function deletePost(Post $post): void
    {
        $alive = $post->deleted_at === null;

        if ($alive && $post->board !== null) {
            $this->postService->deleteForRetention($post->board->slug, $post->id);
        }

        $this->attachmentService->purgeByPostIds([$post->id]);
        $this->scrubSnapshots(ReportType::Post, $post->id, self::MODE_DELETE);
        $this->scrubRow(Post::class, $post->id, $this->deletedRowValues(true, $alive && $post->board === null));
    }

    /**
     * 삭제 모드에서 행에 남기는 값을 반환합니다.
     *
     * @param  bool  $isPost  게시글 여부 (제목 칸이 있음)
     * @param  bool  $stampDeletedAt  삭제 시각을 직접 찍을지 (삭제 경로를 못 탄 고아 행 — 찍지 않으면
     *                                멱등 표지가 서지 않아 매 실행 다시 대상이 된다)
     * @return array<string, mixed> 컬럼 => 값
     */
    private function deletedRowValues(bool $isPost, bool $stampDeletedAt = false): array
    {
        $values = [
            'author_name' => self::ANONYMIZED_AUTHOR,
            'password' => null,
            'ip_address' => null,
            'content' => '',
        ];

        // 제목은 빈 문자열이 아니라 치환값 — 관리자 목록은 휴지통 글도 보여 주므로 빈 제목 행이
        // 무엇인지 알아볼 수 없다(삭제 표시와 함께 `***` 로 보이게 한다).
        if ($isPost) {
            $values['title'] = self::ANONYMIZED_AUTHOR;
        }

        if ($stampDeletedAt) {
            $values['deleted_at'] = now();
        }

        return $values;
    }

    /**
     * 한 행을 가리키는 신고 로그 스냅샷(작성자명·제목·본문 사본)을 덮습니다.
     *
     * 행의 처리 표지보다 **먼저** 부른다 — 표지를 먼저 찍으면 이 호출의 실패가 그 행을 다음
     * 실행의 대상에서 영구히 빼 사본의 개인정보가 남는다.
     *
     * @param  ReportType  $type  신고 대상 종류
     * @param  int  $id  대상 행 ID
     * @param  string  $mode  처리 방식
     * @return void 반환값 없음
     */
    private function scrubSnapshots(ReportType $type, int $id, string $mode): void
    {
        $this->reportRepository->scrubSnapshotsForTargets($type->value, [$id], $this->snapshotReplacements($mode));
    }

    /**
     * 신고 스냅샷에 덮어쓸 값을 반환합니다.
     *
     * @param  string  $mode  처리 방식
     * @return array<string, string> 스냅샷 키 => 치환값
     */
    private function snapshotReplacements(string $mode): array
    {
        if ($mode === self::MODE_DELETE) {
            return ['author_name' => self::ANONYMIZED_AUTHOR, 'title' => self::ANONYMIZED_AUTHOR, 'content' => ''];
        }

        return ['author_name' => self::ANONYMIZED_AUTHOR];
    }

    /**
     * 외부 검색엔진 색인을 파기 결과에 맞춥니다.
     *
     * 파기는 모델 이벤트를 타지 않으므로(수정 시각 보존) Scout 가 알아서 동기화하지 않는다.
     * 기본 엔진(MySQL FULLTEXT)은 테이블 자체라 할 일이 없고({@see Post::shouldBeSearchable()}),
     * 외부 엔진을 쓰는 사이트에서만 색인에 남은 작성자명·본문을 갱신·제거한다. 색인 실패는
     * DB 파기를 되돌릴 이유가 아니므로 기록만 한다.
     *
     * @param  array<int, int>  $postIds  처리한 게시글 ID 목록
     * @return void 반환값 없음
     */
    private function resyncSearchIndex(array $postIds): void
    {
        if ($postIds === [] || ! (new Post)->shouldBeSearchable()) {
            return;
        }

        try {
            Post::withTrashed()->whereKey($postIds)->get()->each(function (Post $post) {
                $post->trashed() ? $post->unsearchable() : $post->searchable();
            });
        } catch (Throwable $e) {
            Log::warning('게시판 비회원 게시글 검색 색인 동기화 실패', [
                'post_ids' => $postIds,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 행 하나를 수정 시각을 바꾸지 않고 덮어씁니다 (휴지통 행 포함).
     *
     * @param  class-string  $model  모델 클래스
     * @param  int  $id  행 ID
     * @param  array<string, mixed>  $values  덮어쓸 값
     * @return void 반환값 없음
     */
    private function scrubRow(string $model, int $id, array $values): void
    {
        // toBase(): Eloquent update 는 updated_at 을 바꾸고 모델 이벤트 연쇄(썸네일 재계산 등)를
        // 부를 수 있다 — 파기는 글 수정이 아니다.
        $model::withTrashed()->whereKey($id)->toBase()->update($values);
    }
}
