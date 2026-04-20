<?php

namespace Modules\Sirsoft\Board\Repositories\Contracts;

use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Post;

/**
 * 게시글 Repository 인터페이스
 */
interface PostRepositoryInterface
{
    /**
     * 게시판의 게시글 목록을 페이지네이션하여 조회합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  array  $filters  필터 조건
     * @param  int  $perPage  페이지당 항목 수
     * @param  bool  $withTrashed  삭제된 게시글 포함 여부
     * @param  Board|null  $board  게시판 모델 (이미 조회된 경우 전달하여 중복 쿼리 방지)
     */
    public function paginate(string $slug, array $filters = [], int $perPage = 15, bool $withTrashed = false, ?Board $board = null): Paginator;

    /**
     * 게시글을 생성합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  array  $data  게시글 생성 데이터
     * @return Post 생성된 게시글 모델
     */
    public function create(string $slug, array $data): Post;

    /**
     * ID로 게시글을 조회합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  게시글 ID
     * @return Post|null 게시글 모델 또는 null
     */
    public function find(string $slug, int $id): ?Post;

    /**
     * ID로 게시글을 조회하며, 없으면 예외를 발생시킵니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  게시글 ID
     * @return Post 게시글 모델
     *
     * @throws ModelNotFoundException
     */
    public function findOrFail(string $slug, int $id): Post;

    /**
     * 게시글을 수정합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  게시글 ID
     * @param  array  $data  수정할 데이터
     * @return Post 수정된 게시글 모델
     *
     * @throws ModelNotFoundException
     */
    public function update(string $slug, int $id, array $data): Post;

    /**
     * 게시글을 삭제합니다 (소프트 삭제).
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  게시글 ID
     * @return bool 삭제 성공 여부
     *
     * @throws ModelNotFoundException
     */
    public function delete(string $slug, int $id): bool;

    /**
     * 게시글을 영구 삭제합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  게시글 ID
     * @return bool 삭제 성공 여부
     *
     * @throws ModelNotFoundException
     */
    public function forceDelete(string $slug, int $id): bool;

    /**
     * 게시글 상태를 변경합니다 (블라인드/삭제).
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  게시글 ID
     * @param  string  $status  변경할 상태 (blinded/deleted)
     * @param  array  $actionLog  작업 이력 데이터
     * @param  string|null  $triggerType  트리거 유형 (admin, report 등)
     *
     * @throws ModelNotFoundException
     */
    public function updateStatus(string $slug, int $id, string $status, array $actionLog, ?string $triggerType = null): Post;

    /**
     * 조회수를 증가시킵니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  게시글 ID
     * @return int 증가된 조회수
     */
    public function incrementViewCount(string $slug, int $id): int;

    /**
     * 신고 처리를 위한 게시글 상태를 일괄 업데이트합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  게시글 ID
     * @param  array  $updates  업데이트할 데이터 (status, trigger_type, deleted_at, action_log)
     * @return Post 수정된 게시글
     *
     * @throws ModelNotFoundException
     */
    public function updateStatusBulk(string $slug, int $id, array $updates): Post;

    /**
     * ID로 게시글을 조회하며 댓글/첨부파일 카운트를 포함합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  게시글 ID
     * @param  int|null  $boardId  게시판 ID (전달 시 Board 재조회 생략)
     * @return Post|null 게시글 모델 (카운트 포함)
     */
    public function findWithCounts(string $slug, int $id, ?int $boardId = null): ?Post;

    /**
     * 전체 일반 게시글(원글) 수를 조회합니다.
     * 필터가 적용된 경우 필터 조건을 만족하는 일반 게시글 수를 반환합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  array  $filters  필터 조건
     * @param  bool  $withTrashed  삭제된 게시글 포함 여부
     * @return int 일반 게시글 수 (답글, 공지 제외)
     */
    public function countNormalPosts(string $slug, array $filters = [], bool $withTrashed = false): int;

    /**
     * 이전/다음 게시글을 조회합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  현재 게시글 ID
     * @param  array  $filters  정렬 파라미터 (order_by, order_direction)
     * @param  bool  $withTrashed  삭제된 게시글 포함 여부
     * @param  int|null  $boardId  게시판 ID (전달 시 Board 재조회 생략)
     * @return array{prev: Post|null, next: Post|null} 이전/다음 게시글
     */
    public function getAdjacentPosts(string $slug, int $id, array $filters = [], bool $withTrashed = false, ?int $boardId = null): array;

    /**
     * 사용자의 게시글 활동 목록을 조회합니다.
     *
     * @param  int  $userId  사용자 ID
     * @param  array  $filters  필터 조건 (board_slug, search)
     * @param  int  $perPage  페이지당 항목 수
     * @return LengthAwarePaginator 게시글 활동 목록
     */
    public function getUserActivities(int $userId, array $filters = [], int $perPage = 20): LengthAwarePaginator;

    /**
     * 게시판에서 키워드로 게시글을 검색합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  string  $keyword  검색 키워드
     * @param  string  $orderBy  정렬 컬럼
     * @param  string  $direction  정렬 방향 (asc, desc)
     * @param  int  $limit  조회할 최대 항목 수
     * @return array{total: int, items: Collection}
     */
    public function searchByKeyword(string $slug, string $keyword, string $orderBy = 'created_at', string $direction = 'desc', int $limit = 10): array;

    /**
     * 게시판에서 키워드와 일치하는 게시글 수를 조회합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  string  $keyword  검색 키워드
     * @return int 일치하는 게시글 수
     */
    public function countByKeyword(string $slug, string $keyword): int;

    /**
     * 여러 게시판에서 키워드로 게시글을 검색합니다 (단일 쿼리, DB 페이지네이션).
     *
     * @param  array  $boardIds  검색 대상 게시판 ID 목록
     * @param  string  $keyword  검색 키워드
     * @param  string  $orderBy  정렬 컬럼
     * @param  string  $direction  정렬 방향 (asc, desc)
     * @param  int  $perPage  페이지당 항목 수
     * @param  int  $page  페이지 번호
     * @return array{total: int, items: Collection}
     */
    public function searchAcrossBoards(array $boardIds, string $keyword, string $orderBy = 'created_at', string $direction = 'desc', int $perPage = 10, int $page = 1): array;

    /**
     * 여러 게시판에서 키워드와 일치하는 게시글 수를 조회합니다 (단일 쿼리).
     *
     * @param  array  $boardIds  검색 대상 게시판 ID 목록
     * @param  string  $keyword  검색 키워드
     */
    public function countAcrossBoards(array $boardIds, string $keyword): int;

    /**
     * 사용자의 게시글 활동 통계를 조회합니다.
     *
     * 작성한 게시글 수, 작성한 댓글 수, 총 조회수를 반환합니다.
     *
     * @param  int  $userId  사용자 ID
     * @return array{total_posts: int, total_comments: int, total_views: int} 활동 통계
     */
    public function getUserActivityStats(int $userId): array;

    /**
     * 사용자의 공개 게시글/댓글 통계를 조회합니다 (공개 프로필용).
     *
     * 기존 getUserActivityStats()와 다른 점:
     * - status='published' 조건 적용
     * - comments_count = 실제 작성한 댓글 수 (댓글 단 게시글 수가 아님)
     *
     * @param  int  $userId  사용자 ID
     * @return array{posts_count: int, comments_count: int} 공개 게시글/댓글 통계
     */
    public function getUserPublicStats(int $userId): array;

    /**
     * 게시판 ID와 게시글 ID로 게시글을 조회합니다 (삭제 포함).
     *
     * @param  int  $boardId  게시판 ID
     * @param  int  $id  게시글 ID
     * @return Post|null 게시글 또는 null
     */
    public function findByBoardId(int $boardId, int $id): ?Post;

    /**
     * 게시판 ID 기준으로 게시글을 일괄 소프트 삭제합니다.
     *
     * @param  int  $boardId  게시판 ID
     * @return int 삭제된 게시글 수
     */
    public function softDeleteByBoardId(int $boardId): int;

    /**
     * ID로 게시글을 조회합니다 (게시판 슬러그 불필요, board 관계 포함).
     *
     * 게시판 슬러그를 알 수 없는 상황(예: 이커머스 훅 리스너)에서
     * Post ID만으로 게시글과 소속 게시판을 함께 조회할 때 사용합니다.
     *
     * @param  int  $id  게시글 ID
     * @return Post|null 게시글 모델 (board 관계 포함) 또는 null
     */
    public function findWithBoard(int $id): ?Post;

    /**
     * ID 배열로 게시글 목록을 조회합니다 (board, user, attachments, replies 관계 포함).
     *
     * 이커머스 문의 목록 구성 시 게시글 데이터를 일괄 조회할 때 사용합니다.
     *
     * @param  array<int>  $ids  게시글 ID 배열
     * @return Collection<int, Post> 게시글 컬렉션
     */
    public function findByIdsWithRelations(array $ids): Collection;

    /**
     * 부모 게시글 ID로 첫 번째 자식(답변) 게시글을 조회합니다 (board 관계 포함).
     *
     * 이커머스 문의 답변 수정/삭제 시 답변 Post를 조회할 때 사용합니다.
     *
     * @param  int  $parentPostId  부모 게시글 ID
     * @return Post|null 첫 번째 자식 게시글 (board 관계 포함) 또는 null
     */
    public function findFirstReplyWithBoard(int $parentPostId): ?Post;
}
