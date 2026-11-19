<?php

namespace Modules\Sirsoft\Board\Contracts;

use Modules\Sirsoft\Board\Contracts\Data\BoardSummary;

/**
 * 게시판 조회 공개 계약
 *
 * 다른 확장(배너 위치 등)이 게시판을 고르거나 이름·주소를 보일 때 쓴다. 게시판 Model·Repository 는 게시판 모듈 내부라
 * 다른 확장이 직접 쓰지 않는다. 서비스 컨테이너로 주입받는다(`app(BoardDirectory::class)`).
 *
 * @since 1.3.0
 */
interface BoardDirectory
{
    /** 계약 판 — 메서드 추가는 판을 올리지 않는다(구현은 게시판 모듈만) */
    public const VERSION = 1;

    /**
     * slug 로 게시판을 찾습니다.
     *
     * @param  string  $slug  게시판 slug
     * @return BoardSummary|null 없으면 null
     */
    public function findBySlug(string $slug): ?BoardSummary;

    /**
     * id 로 게시판을 찾습니다.
     *
     * @param  int  $id  게시판 id
     * @return BoardSummary|null 없으면 null
     */
    public function findById(int $id): ?BoardSummary;

    /**
     * id 묶음으로 게시판을 찾습니다. 없는 id 는 결과에서 빠집니다.
     *
     * @param  array<int, int>  $ids  게시판 id 목록
     * @return array<int, BoardSummary> id => 요약
     */
    public function findByIds(array $ids): array;

    /**
     * slug 묶음의 현재 언어 게시판 이름을 돌려줍니다.
     *
     * @param  array<int, string>  $slugs  게시판 slug 목록
     * @return array<string, string> slug => 게시판 이름
     */
    public function labelsBySlugs(array $slugs): array;

    /**
     * 게시판을 검색합니다 — slug 와 다국어 이름 부분 일치.
     *
     * @param  string  $keyword  검색어(빈 값이면 앞에서부터)
     * @param  int  $limit  최대 건수
     * @return array<int, BoardSummary> 요약 목록
     */
    public function search(string $keyword, int $limit): array;
}
