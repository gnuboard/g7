<?php

namespace Modules\Sirsoft\Board\Repositories\Contracts;

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
     * @return Collection 정렬된 댓글 컬렉션
     */
    public function getByPostId(string $slug, int $postId, bool $withTrashed = false, string $orderDirection = 'DESC', ?string $scopePermission = null, ?int $boardId = null): Collection;

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
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  댓글 ID
     * @return Comment|null 댓글 모델 또는 null
     */
    public function find(string $slug, int $id): ?Comment;

    /**
     * ID로 댓글을 조회하며, 없으면 예외를 발생시킵니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  댓글 ID
     * @return Comment 댓글 모델
     *
     * @throws ModelNotFoundException
     */
    public function findOrFail(string $slug, int $id): Comment;

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
     * 게시글 ID 기준으로 댓글을 일괄 소프트 삭제합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $postId  게시글 ID
     * @return int 삭제된 댓글 수
     */
    public function softDeleteByPostId(string $slug, int $postId): int;

    /**
     * 사용자가 작성한 댓글 목록을 페이지네이션하여 조회합니다.
     *
     * @param  int  $userId  사용자 ID
     * @param  array  $filters  필터 조건 (board_slug, search, sort)
     * @param  int  $perPage  페이지당 항목 수
     * @return LengthAwarePaginator 댓글 목록
     */
    public function getUserComments(int $userId, array $filters = [], int $perPage = 20): LengthAwarePaginator;
}
