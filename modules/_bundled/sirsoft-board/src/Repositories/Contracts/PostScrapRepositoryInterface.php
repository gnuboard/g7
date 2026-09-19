<?php

namespace Modules\Sirsoft\Board\Repositories\Contracts;

use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Sirsoft\Board\Models\PostScrap;

/**
 * 게시글 스크랩 Repository 인터페이스
 */
interface PostScrapRepositoryInterface
{
    /**
     * 검색 대상 기본값 (제목+내용)
     */
    public const SEARCH_FIELD_DEFAULT = 'title_content';

    /**
     * 스크랩을 토글합니다 (있으면 삭제, 없으면 생성).
     *
     * @param  int  $userId  회원 ID
     * @param  int  $postId  게시글 ID
     * @param  int|null  $boardId  게시판 ID (생성 시 기록)
     * @return array{added: bool, scrap: PostScrap|null} added=true 면 생성, false 면 삭제
     */
    public function toggle(int $userId, int $postId, ?int $boardId = null): array;

    /**
     * 스크랩 레코드 ID 로 삭제합니다 (본인 소유 레코드만).
     *
     * @param  int  $id  스크랩 레코드 ID
     * @param  int  $userId  회원 ID (소유권 확인)
     * @return PostScrap|null 삭제된 레코드 (없으면 null)
     */
    public function deleteByIdAndUser(int $id, int $userId): ?PostScrap;

    /**
     * 회원이 특정 게시글을 스크랩했는지 확인합니다.
     *
     * @param  int  $userId  회원 ID
     * @param  int  $postId  게시글 ID
     * @return bool 스크랩 여부
     */
    public function isScraped(int $userId, int $postId): bool;

    /**
     * 회원의 스크랩 목록을 최근 스크랩순으로 페이지네이션합니다.
     *
     * 원글이 삭제된 스크랩도, 스크랩이 중지된 게시판(use_scrap=false·is_active=false)의
     * 스크랩도 목록에서 숨기지 않는다 — 화면이 배지로 구분하고 해제만 허용한다.
     * search 지정 시 searchField 가 가리키는 컬럼(제목/내용/둘 다)에 매칭되는 스크랩만
     * 반환하며, 원글이 삭제된 스크랩과 스크랩 중지 게시판의 스크랩은 검색 결과에서 제외된다.
     *
     * @param  int  $userId  회원 ID
     * @param  int  $perPage  페이지당 개수
     * @param  string|null  $search  검색어
     * @param  string  $searchField  검색 대상 (title_content|title|content)
     * @return LengthAwarePaginator 페이지네이션된 스크랩 목록
     */
    public function paginateByUser(
        int $userId,
        int $perPage = 20,
        ?string $search = null,
        string $searchField = self::SEARCH_FIELD_DEFAULT
    ): LengthAwarePaginator;

    /**
     * 회원 소유 스크랩을 ID 목록으로 일괄 삭제합니다.
     *
     * 소유권 필터(user_id)를 쿼리에 함께 걸어, 남의 ID·없는 ID 는 조용히 빠진다.
     * 반환 컬렉션은 scrap_count 감소·훅 발화를 행 단위로 수행하기 위한 근거다.
     *
     * @param  array<int, int>  $ids  스크랩 레코드 ID 목록
     * @param  int  $userId  회원 ID (소유권 확인)
     * @return Collection<int, PostScrap> 실제로 삭제된 레코드 (id, user_id, post_id, board_id)
     */
    public function deleteManyByIdsAndUser(array $ids, int $userId): Collection;

    /**
     * 관리자용 전 회원 스크랩 목록을 페이지네이션합니다.
     *
     * @param  array<string, mixed>  $filters  필터 (search_field, search, board_id, user_id, 기간, 정렬)
     * @param  int  $perPage  페이지당 개수
     * @return Paginator 페이지네이션된 스크랩 목록
     */
    public function paginateForAdmin(array $filters, int $perPage = 20): Paginator;

    /**
     * 스크랩 레코드를 ID 로 조회합니다 (소유자 무관).
     *
     * @param  int  $id  스크랩 레코드 ID
     * @return PostScrap|null 스크랩 레코드
     */
    public function findById(int $id): ?PostScrap;

    /**
     * 스크랩 레코드를 ID 로 삭제합니다 (소유자 무관 — 관리자 전용).
     *
     * @param  int  $id  스크랩 레코드 ID
     * @return PostScrap|null 삭제된 레코드 (없으면 null)
     */
    public function deleteById(int $id): ?PostScrap;

    /**
     * 스크랩 레코드를 ID 목록으로 일괄 삭제합니다 (소유자 무관 — 관리자 전용).
     *
     * @param  array<int, int>  $ids  스크랩 레코드 ID 목록
     * @return Collection<int, PostScrap> 실제로 삭제된 레코드
     */
    public function deleteManyByIds(array $ids): Collection;

    /**
     * 회원의 스크랩 요약(건수·최근 스크랩 시각)을 조회합니다.
     *
     * @param  int  $userId  회원 ID
     * @return array{count: int, latest_scrapped_at: Carbon|null} 요약
     */
    public function summaryByUser(int $userId): array;
}
