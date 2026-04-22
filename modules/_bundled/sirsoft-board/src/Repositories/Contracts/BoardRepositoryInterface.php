<?php

namespace Modules\Sirsoft\Board\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Sirsoft\Board\Models\Board;

/**
 * 게시판 Repository 인터페이스
 */
interface BoardRepositoryInterface
{
    /**
     * 게시판을 생성합니다.
     *
     * @param array $data 게시판 생성 데이터
     * @return Board 생성된 게시판 모델
     */
    public function create(array $data): Board;

    /**
     * ID로 게시판을 조회합니다.
     *
     * @param int $id 게시판 ID
     * @return Board|null 게시판 모델 또는 null
     */
    public function find(int $id): ?Board;

    /**
     * ID로 게시판을 조회하며, 없으면 예외를 발생시킵니다.
     *
     * @param int $id 게시판 ID
     * @return Board 게시판 모델
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findOrFail(int $id): Board;

    /**
     * 슬러그로 게시판을 조회합니다.
     *
     * @param string $slug 게시판 슬러그
     * @return Board|null 게시판 모델 또는 null
     */
    public function findBySlug(string $slug): ?Board;

    /**
     * 게시판을 수정합니다.
     *
     * @param int $id 게시판 ID
     * @param array $data 수정할 데이터
     * @return Board 수정된 게시판 모델
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function update(int $id, array $data): Board;

    /**
     * 게시판을 삭제합니다.
     *
     * @param int $id 게시판 ID
     * @return bool 삭제 성공 여부
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function delete(int $id): bool;

    /**
     * 모든 게시판을 조회합니다.
     *
     * @return Collection 게시판 컬렉션
     */
    public function all(): Collection;

    /**
     * 게시판 목록을 페이지네이션하여 조회합니다.
     *
     * @param int $perPage 페이지당 개수
     * @return LengthAwarePaginator 페이지네이션된 게시판 목록
     */
    public function paginate(int $perPage = 15): LengthAwarePaginator;

    /**
     * 키워드로 게시판을 검색합니다.
     *
     * @param string $keyword 검색 키워드
     * @return Collection 검색 결과 컬렉션
     */
    public function search(string $keyword): Collection;

    /**
     * 타입으로 게시판을 필터링합니다.
     *
     * @param string $type 게시판 타입
     * @return Collection 필터링된 게시판 컬렉션
     */
    public function filterByType(string $type): Collection;

    /**
     * 쿼리 빌더를 반환합니다.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Board>
     */
    public function query();

    /**
     * 최근 게시글을 조회합니다. (여러 게시판 통합)
     *
     * @param  int  $limit  조회 개수
     * @return array<int, array<string, mixed>>
     */
    public function getRecentPosts(int $limit): array;

    /**
     * 특정 게시판의 최근 게시물을 조회합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $limit  조회 개수
     * @return array<int, array<string, mixed>>
     */
    public function getBoardRecentPosts(string $slug, int $limit): array;

    /**
     * 게시판 ID로 최근 게시물을 조회합니다 (slug 재조회 없음).
     *
     * @param  int  $boardId  게시판 ID
     * @param  int  $limit  조회 개수
     * @return array<int, array<string, mixed>>
     */
    public function getBoardRecentPostsById(int $boardId, int $limit): array;

    /**
     * 게시판의 게시글 개수를 조회합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @return int 게시글 개수
     */
    public function getBoardPostsCount(string $slug): int;

    /**
     * 전체 게시판의 게시글 개수를 집계합니다.
     *
     * @return int 전체 게시글 개수
     */
    public function getTotalPostsCount(): int;

    /**
     * 전체 게시판의 댓글 개수를 집계합니다.
     *
     * @return int 전체 댓글 개수
     */
    public function getTotalCommentsCount(): int;

    /**
     * 인기 게시글을 조회합니다. (조회수 기준, 기간별 필터링)
     *
     * @param  string  $period  기간 필터 (today, week, month, all)
     * @param  int  $limit  조회 개수
     * @return array<int, array<string, mixed>>
     */
    public function getPopularPosts(string $period, int $limit): array;

    /**
     * 활성화된 게시판 목록을 조회합니다.
     *
     * @param string|null $slug 특정 게시판 슬러그 (null이면 전체)
     * @return Collection 활성 게시판 컬렉션
     */
    public function getActiveBoards(?string $slug = null): Collection;

    /**
     * 필터용 전체 활성 게시판 목록을 반환합니다.
     *
     * @return Collection 활성 게시판 컬렉션
     */
    public function getActiveBoardsList(): Collection;

    /**
     * 게시판을 일괄 업데이트합니다.
     *
     * @param array<string, mixed> $data 업데이트할 데이터
     * @param bool $applyAll 전체 적용 여부
     * @param array<int> $boardIds 특정 게시판 ID 목록 (applyAll=false일 때 사용)
     * @return int 업데이트된 게시판 수
     */
    public function bulkUpdate(array $data, bool $applyAll = true, array $boardIds = []): int;

    /**
     * 게시판 ID 목록별 게시글 개수를 조회합니다.
     *
     * @param array<int> $boardIds 게시판 ID 목록
     * @return array<int, int> board_id => count 매핑
     */
    public function getPostsCountByBoardIds(array $boardIds): array;

    /**
     * 활성화된 게시판 목록을 정렬 옵션과 함께 조회합니다.
     *
     * @param string $orderBy 정렬 기준 컬럼
     * @param string $orderDirection 정렬 방향 (asc/desc)
     * @return Collection 활성 게시판 컬렉션
     */
    public function getActiveBoardsOrdered(string $orderBy = 'created_at', string $orderDirection = 'desc'): Collection;

    /**
     * 활성 게시판의 통계를 조회합니다 (게시판 수, 게시글 수, 댓글 수).
     *
     * @return object{boards_count: int, posts_total: int, comments_total: int}
     */
    public function getActiveBoardStats(): object;

    /**
     * 메뉴용 경량 게시판 목록을 조회합니다.
     *
     * id, name, slug 컬럼만 조회하여 메뉴 렌더링에 필요한 최소 데이터만 반환합니다.
     *
     * @return Collection 활성 게시판 컬렉션 (id, name, slug만 포함)
     */
    public function getActiveBoardsForMenu(): Collection;

    /**
     * 특정 유형의 게시판 개수를 반환합니다.
     *
     * @param string $type 게시판 유형 slug
     * @return int 해당 유형의 게시판 개수
     */
    public function countByType(string $type): int;
}