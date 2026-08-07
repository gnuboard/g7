<?php

namespace Modules\Sirsoft\Board\Repositories\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Sirsoft\Board\Models\PostScrap;

/**
 * 게시글 스크랩 Repository 인터페이스
 */
interface PostScrapRepositoryInterface
{
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
     * 원글이 삭제된 스크랩도 포함한다 (마이페이지에서 "삭제된 게시글" 로 표시).
     * search 지정 시 원글 제목에 매칭되는 스크랩만 반환하며, 원글이 삭제된 스크랩은
     * 응답에서 제목이 노출되지 않으므로 검색 대상에서도 제외된다.
     *
     * @param  int  $userId  회원 ID
     * @param  int  $perPage  페이지당 개수
     * @param  string|null  $search  게시글 제목 검색어
     * @return LengthAwarePaginator 페이지네이션된 스크랩 목록
     */
    public function paginateByUser(int $userId, int $perPage = 20, ?string $search = null): LengthAwarePaginator;
}
