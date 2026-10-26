<?php

namespace Plugins\Sirsoft\HeroSection\Repositories\Contracts;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Plugins\Sirsoft\HeroSection\Models\Banner;

/**
 * 배너 Repository 인터페이스 — 배너 쿼리는 모두 여기서 만든다.
 *
 * 노출 상태 필터·정렬·공개 노출·다음 전환 시각은 같은 기간 조건을 쓴다(상태는 저장하지 않는다).
 */
interface BannerRepositoryInterface
{
    /**
     * 관리자 목록을 조회합니다 — 위치·상태·제목 검색·정렬을 SQL 에서 처리합니다.
     *
     * @param  array<string, mixed>  $filters  검증된 목록 필터 (position, status, search, sort_by, sort_order, per_page)
     * @param  CarbonInterface  $now  상태 판정 기준 시각
     * @return LengthAwarePaginator 배너 목록 (PC 이미지 포함)
     */
    public function paginateForAdmin(array $filters, CarbonInterface $now): LengthAwarePaginator;

    /**
     * 위치별 건수와 한 위치의 상태별 건수를 반환합니다.
     *
     * @param  string  $position  상태별 건수를 낼 위치
     * @param  CarbonInterface  $now  상태 판정 기준 시각
     * @return array{positions: array<string, int>, statuses: array<string, int>} 통계
     */
    public function statistics(string $position, CarbonInterface $now): array;

    /**
     * id 로 배너를 찾습니다(이미지 포함).
     *
     * @param  int  $id  배너 ID
     * @return Banner 배너
     *
     * @throws ModelNotFoundException 없을 때
     */
    public function findOrFail(int $id): Banner;

    /**
     * 여러 id 의 배너를 찾습니다(이미지 포함).
     *
     * @param  array<int, int>  $ids  배너 ID 목록
     * @return Collection<int, Banner> 배너 목록
     */
    public function findMany(array $ids): Collection;

    /**
     * 배너 제목(다국어 원본)만 읽습니다 — 활동 로그 이름 해석용.
     *
     * @param  int  $id  배너 ID
     * @return array<string, string>|null 제목 (배너가 없으면 null)
     */
    public function findTitle(int $id): ?array;

    /**
     * 배너를 만듭니다.
     *
     * @param  array<string, mixed>  $data  저장할 값
     * @return Banner 만든 배너
     */
    public function create(array $data): Banner;

    /**
     * 배너를 고칩니다.
     *
     * @param  Banner  $banner  대상 배너
     * @param  array<string, mixed>  $data  바꿀 값
     * @return Banner 고친 배너
     */
    public function update(Banner $banner, array $data): Banner;

    /**
     * 배너 행을 지웁니다(이미지 파일은 서비스가 먼저 지운다).
     *
     * @param  Banner  $banner  대상 배너
     * @return bool 삭제 여부
     */
    public function delete(Banner $banner): bool;

    /**
     * 위치의 맨 뒤 순서 값을 반환합니다.
     *
     * @param  string  $position  위치 키
     * @return int 다음 순서 값
     */
    public function nextSortOrder(string $position): int;

    /**
     * 위치의 배너 전체를 순서대로 반환합니다(순서 변경 창용, PC 이미지 포함).
     *
     * @param  string  $position  위치 키
     * @return Collection<int, Banner> 배너 목록
     */
    public function listForPosition(string $position): Collection;

    /**
     * 위치에 속한 배너 id 목록을 반환합니다.
     *
     * @param  string  $position  위치 키
     * @return array<int, int> 배너 ID 목록
     */
    public function idsForPosition(string $position): array;

    /**
     * 주어진 id 순서대로 sort_order 를 0 부터 다시 매깁니다.
     *
     * @param  array<int, int>  $ids  새 순서의 배너 ID 목록
     * @return void 반환값 없음
     */
    public function reorder(array $ids): void;

    /**
     * 여러 배너의 사용 여부를 바꿉니다.
     *
     * @param  array<int, int>  $ids  배너 ID 목록
     * @param  bool  $isActive  사용 여부
     * @param  int|null  $actorId  바꾼 회원 ID
     * @return int 바뀐 건수
     */
    public function bulkSetActive(array $ids, bool $isActive, ?int $actorId): int;

    /**
     * 지금 노출 중인 배너를 순서대로 반환합니다(이미지 포함).
     *
     * @param  string  $position  위치 키
     * @param  CarbonInterface  $now  기준 시각
     * @return Collection<int, Banner> 노출 배너
     */
    public function exposedForPosition(string $position, CarbonInterface $now): Collection;

    /**
     * 캐시에 담을 노출 후보(사용 중이고 아직 끝나지 않은 배너)를 순서대로 반환합니다(이미지 포함).
     *
     * @param  string  $position  위치 키
     * @param  CarbonInterface  $now  기준 시각
     * @return Collection<int, Banner> 노출 후보
     */
    public function exposureCandidatesForPosition(string $position, CarbonInterface $now): Collection;

    /**
     * 위치에서 다음으로 노출 상태가 바뀌는 시각을 반환합니다(예약 시작 또는 종료).
     *
     * @param  string  $position  위치 키
     * @param  CarbonInterface  $now  기준 시각
     * @return CarbonInterface|null 다음 전환 시각 (없으면 null)
     */
    public function nextTransitionAt(string $position, CarbonInterface $now): ?CarbonInterface;
}
