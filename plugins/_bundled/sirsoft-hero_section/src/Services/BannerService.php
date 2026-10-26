<?php

namespace Plugins\Sirsoft\HeroSection\Services;

use App\Extension\HookManager;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Plugins\Sirsoft\HeroSection\Models\Banner;
use Plugins\Sirsoft\HeroSection\Repositories\Contracts\BannerImageRepositoryInterface;
use Plugins\Sirsoft\HeroSection\Repositories\Contracts\BannerRepositoryInterface;

/**
 * 배너 서비스 — 훅 발행·트랜잭션·순서·영구 삭제를 맡는다. 검증은 FormRequest, 쿼리는 Repository 가 한다.
 *
 * 발행 훅(접두 `sirsoft-hero_section.banner.`): before/after_create · update · delete · bulk_status ·
 * bulk_delete · reorder, filter_create_data · filter_update_data · filter_list_params.
 * 활동 로그·공개 캐시 비우기는 after 훅을 구독하는 리스너가 한다.
 */
class BannerService
{
    /**
     * BannerService 생성자
     *
     * @param  BannerRepositoryInterface  $banners  배너 Repository
     * @param  BannerImageRepositoryInterface  $images  이미지 Repository
     * @param  BannerImageService  $imageService  이미지 서비스(파일 삭제)
     */
    public function __construct(
        private BannerRepositoryInterface $banners,
        private BannerImageRepositoryInterface $images,
        private BannerImageService $imageService,
    ) {}

    /**
     * 관리자 목록을 조회합니다.
     *
     * @param  array<string, mixed>  $filters  검증된 목록 필터
     * @return LengthAwarePaginator 배너 목록
     */
    public function getList(array $filters): LengthAwarePaginator
    {
        $filters = HookManager::applyFilters('sirsoft-hero_section.banner.filter_list_params', $filters);

        return $this->banners->paginateForAdmin($filters, now());
    }

    /**
     * 위치별·상태별 건수를 반환합니다.
     *
     * @param  string  $position  상태별 건수를 낼 위치
     * @return array{positions: array<string, int>, statuses: array<string, int>} 통계
     */
    public function getStatistics(string $position): array
    {
        return $this->banners->statistics($position, now());
    }

    /**
     * 지금 노출 중인 배너를 순서대로 반환합니다(관리자 미리보기).
     *
     * @param  string  $position  위치 키
     * @return Collection<int, Banner> 노출 배너
     */
    public function getPreview(string $position): Collection
    {
        return $this->banners->exposedForPosition($position, now());
    }

    /**
     * 위치의 배너 전체를 순서대로 반환합니다(순서 변경 창).
     *
     * @param  string  $position  위치 키
     * @return Collection<int, Banner> 배너 목록
     */
    public function getOrderList(string $position): Collection
    {
        return $this->banners->listForPosition($position);
    }

    /**
     * 배너를 찾습니다.
     *
     * @param  int  $id  배너 ID
     * @return Banner 배너
     */
    public function find(int $id): Banner
    {
        return $this->banners->findOrFail($id);
    }

    /**
     * 배너를 만듭니다 — 위치 맨 뒤에 놓고 임시 업로드 이미지를 연결합니다.
     *
     * @param  array<string, mixed>  $data  검증된 값 (temp_key 포함)
     * @param  int|null  $actorId  등록한 회원 ID
     * @return Banner 만든 배너
     */
    public function create(array $data, ?int $actorId): Banner
    {
        HookManager::doAction('sirsoft-hero_section.banner.before_create', $data);
        $data = HookManager::applyFilters('sirsoft-hero_section.banner.filter_create_data', $data);

        $tempKey = (string) ($data['temp_key'] ?? '');
        unset($data['temp_key']);

        $banner = DB::transaction(function () use ($data, $tempKey, $actorId) {
            $banner = $this->banners->create($data + [
                'sort_order' => $this->banners->nextSortOrder($data['position']),
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);

            if ($tempKey !== '') {
                $this->images->linkTempImages($tempKey, $banner->id);
            }

            return $this->banners->findOrFail($banner->id);
        });

        HookManager::doAction('sirsoft-hero_section.banner.after_create', $banner);

        return $banner;
    }

    /**
     * 배너를 고칩니다 — 위치가 바뀌면 새 위치 맨 뒤로 옮깁니다.
     *
     * @param  Banner  $banner  대상 배너
     * @param  array<string, mixed>  $data  검증된 값
     * @param  int|null  $actorId  수정한 회원 ID
     * @return Banner 고친 배너
     */
    public function update(Banner $banner, array $data, ?int $actorId): Banner
    {
        HookManager::doAction('sirsoft-hero_section.banner.before_update', $banner, $data);
        $data = HookManager::applyFilters('sirsoft-hero_section.banner.filter_update_data', $data, $banner);
        unset($data['temp_key']);

        $snapshot = $banner->toArray();
        $previousPosition = $banner->position;

        $updated = DB::transaction(function () use ($banner, $data, $actorId, $previousPosition) {
            if (isset($data['position']) && $data['position'] !== $previousPosition) {
                $data['sort_order'] = $this->banners->nextSortOrder($data['position']);
            }

            return $this->banners->update($banner, $data + ['updated_by' => $actorId]);
        });

        HookManager::doAction('sirsoft-hero_section.banner.after_update', $updated, $snapshot, $previousPosition);

        return $updated;
    }

    /**
     * 배너를 영구 삭제합니다 — PC·모바일 이미지 파일을 먼저 지운 뒤 행을 지웁니다.
     *
     * @param  Banner  $banner  대상 배너
     * @return void 반환값 없음
     */
    public function delete(Banner $banner): void
    {
        HookManager::doAction('sirsoft-hero_section.banner.before_delete', $banner);

        $snapshot = $banner->toArray();
        $id = $banner->id;

        $this->imageService->deleteFilesForBanners([$id]);
        DB::transaction(fn () => $this->banners->delete($banner));

        HookManager::doAction('sirsoft-hero_section.banner.after_delete', $id, $snapshot);
    }

    /**
     * 여러 배너의 사용 여부(노출·비노출)를 바꿉니다.
     *
     * @param  array<int, int>  $ids  배너 ID 목록
     * @param  bool  $isActive  사용 여부
     * @param  int|null  $actorId  바꾼 회원 ID
     * @return int 바뀐 건수
     */
    public function bulkSetActive(array $ids, bool $isActive, ?int $actorId): int
    {
        HookManager::doAction('sirsoft-hero_section.banner.before_bulk_status', $ids, $isActive);

        $snapshots = $this->banners->findMany($ids)->map(fn (Banner $b) => $b->toArray())->keyBy('id')->all();
        $count = DB::transaction(fn () => $this->banners->bulkSetActive($ids, $isActive, $actorId));

        HookManager::doAction('sirsoft-hero_section.banner.after_bulk_status', $ids, $isActive, $snapshots);

        return $count;
    }

    /**
     * 여러 배너를 영구 삭제합니다 — 각 배너의 이미지 파일을 먼저 지웁니다.
     *
     * @param  array<int, int>  $ids  배너 ID 목록
     * @return int 지운 건수
     */
    public function bulkDelete(array $ids): int
    {
        HookManager::doAction('sirsoft-hero_section.banner.before_bulk_delete', $ids);

        $banners = $this->banners->findMany($ids);
        $snapshots = $banners->map(fn (Banner $b) => $b->toArray())->keyBy('id')->all();

        $this->imageService->deleteFilesForBanners($banners->pluck('id')->all());
        $count = DB::transaction(function () use ($banners) {
            $deleted = 0;
            foreach ($banners as $banner) {
                $deleted += $this->banners->delete($banner) ? 1 : 0;
            }

            return $deleted;
        });

        HookManager::doAction('sirsoft-hero_section.banner.after_bulk_delete', $ids, $snapshots);

        return $count;
    }

    /**
     * 위치의 배너 순서를 바꿉니다(FormRequest 가 그 위치 전체 id 인지 확인했다).
     *
     * @param  string  $position  위치 키
     * @param  array<int, int>  $ids  새 순서의 배너 ID 목록
     * @return void 반환값 없음
     */
    public function reorder(string $position, array $ids): void
    {
        HookManager::doAction('sirsoft-hero_section.banner.before_reorder', $position, $ids);

        DB::transaction(fn () => $this->banners->reorder($ids));

        HookManager::doAction('sirsoft-hero_section.banner.after_reorder', $position, $ids);
    }
}
