<?php

namespace Modules\Sirsoft\Board\Repositories\Contracts;

use App\Support\Query\BoundedCount;
use App\Support\Query\BoundedPage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Sirsoft\Board\Models\Comment;

/**
 * 댓글 Repository 인터페이스
 */
interface CommentRepositoryInterface
{
    /**
     * 특정 게시글의 댓글 목록을 조회합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $postId  게시글 ID
     * @param  bool  $withTrashed  삭제된 댓글 포함 여부 (기본값: false)
     * @param  string  $orderDirection  정렬 방향 (ASC 또는 DESC, 기본값: DESC)
     * @param  string|null  $scopePermission  스코프 권한 식별자 (null 이면 스코프 필터 미적용)
     * @param  int|null  $boardId  게시판 ID (전달 시 Board 재조회 생략)
     * @return Collection 정렬된 댓글 컬렉션
     */
    public function getByPostId(string $slug, int $postId, bool $withTrashed = false, string $orderDirection = 'DESC', ?string $scopePermission = null, ?int $boardId = null): Collection;

    /**
     * 게시글의 원댓글을 페이지네이션하고, 이번 페이지 원댓글의 답글까지 함께 조립합니다.
     *
     * 페이지 단위는 **원댓글**입니다. 답글까지 잘라 세면 트리가 페이지 경계에서 끊겨
     * 부모 없는 답글이 화면에 남습니다. 총 건수는 상한까지만 세며, 다음 페이지 이동은
     * 총 건수와 무관하게 실측으로 판정됩니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $postId  게시글 ID
     * @param  int  $perPage  페이지당 원댓글 수
     * @param  int  $page  현재 페이지
     * @param  bool  $withTrashed  삭제된 댓글 포함 여부
     * @param  string  $orderDirection  정렬 방향 (ASC 또는 DESC)
     * @param  string|null  $scopePermission  스코프 권한 식별자
     * @param  int|null  $boardId  게시판 ID (전달 시 재조회 생략)
     * @return BoundedPage 원댓글 기준 페이지 (컬렉션은 트리 정렬된 댓글 전체)
     */
    public function paginateRootsByPostId(
        string $slug,
        int $postId,
        int $perPage,
        int $page = 1,
        bool $withTrashed = false,
        string $orderDirection = 'DESC',
        ?string $scopePermission = null,
        ?int $boardId = null
    ): BoundedPage;

    /**
     * 게시글의 댓글 총 건수를 조회합니다.
     *
     * 목록 조회는 상한에 걸려 잘릴 수 있으므로, 화면이 "더 있다" 를 알 수 있도록
     * 총 건수를 따로 제공합니다. 집계에도 같은 상한이 걸려 대량 댓글에서 비용이 일정합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $postId  게시글 ID
     * @param  bool  $withTrashed  삭제 댓글 포함 여부
     * @param  int|null  $boardId  게시판 ID (전달 시 재조회 생략)
     * @return BoundedCount 댓글 총 건수 (상한 초과 시 상한값 + AtLeast 정확도)
     */
    public function countByPostId(string $slug, int $postId, bool $withTrashed = false, ?int $boardId = null): BoundedCount;

    /**
     * 댓글을 생성합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  array  $data  댓글 생성 데이터
     * @return Comment 생성된 댓글 모델
     */
    public function create(string $slug, array $data): Comment;

    /**
     * ID로 댓글을 조회합니다.
     *
     * $postId 를 전달하면 해당 게시글에 속한 댓글만 조회합니다 (교차 게시글 접근 차단).
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  댓글 ID
     * @param  int|null  $postId  상위 게시글 ID (null 이면 게시판 범위 전체)
     * @return Comment|null 댓글 모델 또는 null
     */
    public function find(string $slug, int $id, ?int $postId = null): ?Comment;

    /**
     * ID로 댓글을 조회하며, 없으면 예외를 발생시킵니다.
     *
     * $postId 를 전달하면 해당 게시글에 속한 댓글만 조회합니다 (교차 게시글 접근 차단).
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  댓글 ID
     * @param  int|null  $postId  상위 게시글 ID (null 이면 게시판 범위 전체)
     * @return Comment 댓글 모델
     *
     * @throws ModelNotFoundException
     */
    public function findOrFail(string $slug, int $id, ?int $postId = null): Comment;

    /**
     * 댓글을 수정합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  댓글 ID
     * @param  array  $data  수정할 데이터
     * @return Comment 수정된 댓글 모델
     *
     * @throws ModelNotFoundException
     */
    public function update(string $slug, int $id, array $data): Comment;

    /**
     * 댓글을 삭제합니다 (소프트 삭제).
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  댓글 ID
     * @return bool 삭제 성공 여부
     *
     * @throws ModelNotFoundException
     */
    public function delete(string $slug, int $id): bool;

    /**
     * 댓글을 영구 삭제합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  댓글 ID
     * @return bool 삭제 성공 여부
     *
     * @throws ModelNotFoundException
     */
    public function forceDelete(string $slug, int $id): bool;

    /**
     * 댓글 상태를 변경합니다 (블라인드/삭제).
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  댓글 ID
     * @param  string  $status  변경할 상태 (blinded/deleted)
     * @param  array  $actionLog  작업 이력 데이터
     * @param  string|null  $triggerType  트리거 유형 (report, admin, auto_hide 등)
     * @return Comment 상태가 변경된 댓글 모델
     *
     * @throws ModelNotFoundException
     */
    public function updateStatus(string $slug, int $id, string $status, array $actionLog, ?string $triggerType = null): Comment;

    /**
     * 신고 처리를 위한 댓글 상태를 일괄 업데이트합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  댓글 ID
     * @param  array  $updates  업데이트할 데이터 (status, trigger_type, deleted_at, action_log)
     * @return Comment 수정된 댓글
     *
     * @throws ModelNotFoundException
     */
    public function updateStatusBulk(string $slug, int $id, array $updates): Comment;

    /**
     * 게시판 ID와 댓글 ID로 댓글을 조회합니다 (삭제 포함).
     *
     * @param  int  $boardId  게시판 ID
     * @param  int  $id  댓글 ID
     * @return Comment|null 댓글 또는 null
     */
    public function findByBoardId(int $boardId, int $id): ?Comment;

    /**
     * 게시판 ID 기준으로 댓글을 일괄 소프트 삭제합니다.
     *
     * @param  int  $boardId  게시판 ID
     * @return int 삭제된 댓글 수
     */
    public function softDeleteByBoardId(int $boardId): int;

    /**
     * 게시판 ID 기준으로 댓글을 일괄 영구 삭제합니다.
     *
     * 게시판 영구 삭제(deleteBoard) 시 사용합니다. 소프트 삭제와 달리
     * deleted_at 마킹이 아니라 레코드를 물리적으로 제거합니다.
     *
     * @param  int  $boardId  게시판 ID
     * @return int 삭제된 댓글 수
     */
    public function forceDeleteByBoardId(int $boardId): int;

    /**
     * 게시글 ID 기준으로 살아있는 댓글을 cascade 로 일괄 소프트 삭제합니다.
     *
     * 게시글 삭제 연쇄로 지워졌음을 trigger_type='cascade' 로 마킹합니다.
     * 이미 삭제된 댓글(사용자 직접 삭제 등)은 영향을 받지 않습니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $postId  게시글 ID
     * @return int 삭제된 댓글 수
     */
    public function softDeleteByPostId(string $slug, int $postId): int;

    /**
     * 게시글 ID 기준으로 cascade 로 지워진 댓글만 복원합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $postId  게시글 ID
     * @return int 복원된 댓글 수
     */
    public function restoreCascadedByPostId(string $slug, int $postId): int;

    /**
     * 사용자가 작성한 댓글 목록을 페이지네이션하여 조회합니다.
     *
     * @param  int  $userId  사용자 ID
     * @param  array  $filters  필터 조건 (board_slug, search, sort)
     * @param  int  $perPage  페이지당 항목 수
     * @return LengthAwarePaginator 댓글 목록
     */
    public function getUserComments(int $userId, array $filters = [], int $perPage = 20): LengthAwarePaginator;

    /**
     * 부모 댓글의 replies_count 컬럼을 활성 대댓글 수로 재계산해 갱신합니다.
     *
     * Listener (CommentReplySyncListener) 가 호출하는 영속 단일 진입점.
     *
     * @param  int  $parentCommentId  부모 댓글 ID
     * @return int 갱신된 카운트 값
     */
    public function recalculateRepliesCount(int $parentCommentId): int;

    /**
     * 특정 날짜에 작성된 전체 게시판의 댓글 수를 조회합니다 (대시보드 집계용).
     *
     * 삭제되지 않은(deleted_at IS NULL) 댓글만 카운트합니다.
     *
     * @param  string  $date  집계 기준 날짜 (Y-m-d)
     * @return int 해당 날짜 작성 댓글 수
     */
    public function countCreatedOnDate(string $date): int;
}
