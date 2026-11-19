<?php

namespace Plugins\Sirsoft\HeroSection\Services;

use App\Extension\HookManager;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Plugins\Sirsoft\HeroSection\Models\Banner;
use Plugins\Sirsoft\HeroSection\Models\BannerImage;
use Plugins\Sirsoft\HeroSection\Repositories\Contracts\BannerRepositoryInterface;
use Plugins\Sirsoft\HeroSection\Support\Positions\Placement;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * 배너 서비스 — 훅 발행·트랜잭션·순서·영구 삭제를 맡는다. 검증은 FormRequest, 쿼리는 Repository 가 한다.
 *
 * 발행 훅(접두 `sirsoft-hero_section.banner.`): before/after_create · update · delete · bulk_status ·
 * bulk_delete · reorder, filter_create_data · filter_update_data · filter_list_params.
 * 수정 저장으로 기기별 이미지가 바뀌면 `sirsoft-hero_section.banner_image.after_change` 도 발행한다.
 * 활동 로그·공개 캐시 비우기는 after 훅을 구독하는 리스너가 한다.
 * 순서는 자리(위치 + 대상) 단위다 — 등록은 그 자리 맨 뒤, 위치나 대상을 바꾸면 새 자리 맨 뒤로 간다.
 */
class BannerService
{
    /**
     * BannerService 생성자
     *
     * @param  BannerRepositoryInterface  $banners  배너 Repository
     * @param  BannerImageService  $imageService  이미지 서비스(선택 적용·떼어 내기·파일 삭제)
     */
    public function __construct(
        private BannerRepositoryInterface $banners,
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
     * 위치별·대상별·상태별 건수를 반환합니다.
     *
     * @param  Placement  $placement  상태별 건수를 낼 자리
     * @return array{positions: array<string, int>, targets: array<string, int>, statuses: array<string, int>} 통계
     */
    public function getStatistics(Placement $placement): array
    {
        return $this->banners->statistics($placement, now());
    }

    /**
     * 지금 노출 중인 배너를 순서대로 반환합니다(관리자 미리보기).
     *
     * @param  Placement  $placement  자리
     * @return Collection<int, Banner> 노출 배너
     */
    public function getPreview(Placement $placement): Collection
    {
        return $this->banners->exposedForPlacement($placement, now());
    }

    /**
     * 자리의 배너 전체를 순서대로 반환합니다(순서 변경 창).
     *
     * @param  Placement  $placement  자리
     * @return Collection<int, Banner> 배너 목록
     */
    public function getOrderList(Placement $placement): Collection
    {
        return $this->banners->listForPlacement($placement);
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
     * 배너를 만듭니다 — 자리(위치 + 대상) 맨 뒤에 놓고 임시 묶음에서 기기마다 한 장씩 붙입니다.
     *
     * 이미지 ID(pc_image_id·mobile_image_id)를 보냈으면 그것을, 보내지 않은 기기는 묶음의 가장 최근 이미지를 붙인다.
     * 붙지 않은 임시 이미지는 그대로 두고 고아 임시 이미지 정리가 지운다.
     *
     * @param  array<string, mixed>  $data  검증된 값 (temp_key 포함)
     * @param  int|null  $actorId  등록한 회원 ID
     * @return Banner 만든 배너
     *
     * @throws ValidationException 검증 뒤 고른 이미지를 먼저 저장된 다른 요청이 가져갔을 때(등록 저장 연타)
     */
    public function create(array $data, ?int $actorId): Banner
    {
        HookManager::doAction('sirsoft-hero_section.banner.before_create', $data);
        $data = HookManager::applyFilters('sirsoft-hero_section.banner.filter_create_data', $data);

        $tempKey = (string) ($data['temp_key'] ?? '');
        $selected = $this->takeImageSelection($data);

        $banner = DB::transaction(function () use ($data, $tempKey, $selected, $actorId) {
            $banner = $this->banners->create($data + [
                'sort_order' => $this->banners->nextSortOrder(Placement::of($data['position'], $data['target_key'] ?? null)),
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);

            if ($tempKey !== '') {
                $selection = $this->imageService->resolveTempSelection($tempKey, $selected);
                // 검증 뒤 같은 묶음의 PC 이미지를 먼저 저장된 요청이 가져갔으면 이미지 없는 배너를 만들지 않는다
                if ($selection['pc'] === null) {
                    throw ValidationException::withMessages(['temp_key' => __('sirsoft-hero_section::validation.pc_image.required')]);
                }
                $this->imageService->applySelection($banner, $selection);
            }

            return $this->banners->findOrFail($banner->id);
        });

        HookManager::doAction('sirsoft-hero_section.banner.after_create', $banner);

        return $banner;
    }

    /**
     * 배너를 고칩니다 — 위치나 대상이 바뀌면 새 자리 맨 뒤로 옮기고, 수정 화면이 고른 이미지로 바꿉니다.
     *
     * 이미지는 저장할 때만 바뀐다(수정 화면의 업로드는 임시 묶음에 머문다). 떼어 낸 옛 이미지 파일은 커밋 뒤에 지운다.
     *
     * @param  Banner  $banner  대상 배너
     * @param  array<string, mixed>  $data  검증된 값 (pc_image_id·mobile_image_id 는 보낸 것만 반영)
     * @param  int|null  $actorId  수정한 회원 ID
     * @return Banner 고친 배너
     */
    public function update(Banner $banner, array $data, ?int $actorId): Banner
    {
        $expectedRevision = (int) ($data['revision'] ?? $banner->lock_version);
        unset($data['revision']);

        [$updated, $images, $snapshot, $previousPosition] = DB::transaction(function () use ($banner, $data, $actorId, $expectedRevision) {
            $banner = $this->banners->findForUpdate($banner->id);
            if ($banner->lock_version !== $expectedRevision) {
                throw new ConflictHttpException(__('sirsoft-hero_section::messages.banner.conflict'));
            }

            HookManager::doAction('sirsoft-hero_section.banner.before_update', $banner, $data);
            $data = HookManager::applyFilters('sirsoft-hero_section.banner.filter_update_data', $data, $banner);
            $selection = $this->takeImageSelection($data);
            $snapshot = $banner->toArray();
            $previousPosition = $banner->position;
            $next = Placement::of($data['position'] ?? $banner->position, array_key_exists('target_key', $data) ? $data['target_key'] : $banner->target_key);
            if (! $next->equals(Placement::of($banner->position, $banner->target_key))) {
                $data['sort_order'] = $this->banners->nextSortOrder($next);
            }
            $images = $this->imageService->applySelection($banner, $selection);

            return [$this->banners->update($banner, $data + ['updated_by' => $actorId]), $images, $snapshot, $previousPosition];
        });

        $this->deleteReleasedImages($images['released']);
        foreach ($images['changes'] as $device => $change) {
            HookManager::doAction('sirsoft-hero_section.banner_image.after_change', $updated, $device, $change);
        }

        HookManager::doAction('sirsoft-hero_section.banner.after_update', $updated, $snapshot, $previousPosition);

        return $updated;
    }

    /**
     * 배너를 영구 삭제합니다 — PC·모바일 이미지를 떼어 낸 뒤 배너를 지우고, 커밋 뒤 이미지 파일·행을 지웁니다.
     *
     * 파일 삭제가 실패한 이미지는 임시 이미지로 남아 고아 임시 이미지 정리가 다시 지운다(아무도 찾지 못하는 파일이 남지 않는다).
     *
     * @param  Banner  $banner  대상 배너
     * @return void 반환값 없음
     */
    public function delete(Banner $banner): void
    {
        HookManager::doAction('sirsoft-hero_section.banner.before_delete', $banner);

        $id = $banner->id;

        [$released, $snapshot] = DB::transaction(function () use ($id) {
            $banner = $this->banners->findForUpdate($id);
            $snapshot = $banner->toArray();
            $released = $this->imageService->releaseForBanners([$id]);
            $this->banners->delete($banner);

            return [$released, $snapshot];
        });
        $this->deleteReleasedImages($released);

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

        [$count, $snapshots] = DB::transaction(function () use ($ids, $isActive, $actorId) {
            $banners = $this->banners->findManyForUpdate($ids);
            $snapshots = $banners->map(fn (Banner $banner) => $banner->toArray())->keyBy('id')->all();

            return [$this->banners->bulkSetActive($ids, $isActive, $actorId), $snapshots];
        });

        HookManager::doAction('sirsoft-hero_section.banner.after_bulk_status', $ids, $isActive, $snapshots);

        return $count;
    }

    /**
     * 여러 배너를 영구 삭제합니다 — 단건 삭제와 같이 이미지를 떼어 낸 뒤 배너를 지우고, 커밋 뒤 이미지를 지웁니다.
     *
     * @param  array<int, int>  $ids  배너 ID 목록
     * @return int 지운 건수
     */
    public function bulkDelete(array $ids): int
    {
        HookManager::doAction('sirsoft-hero_section.banner.before_bulk_delete', $ids);

        [$count, $released, $snapshots] = DB::transaction(function () use ($ids) {
            $banners = $this->banners->findManyForUpdate($ids);
            $snapshots = $banners->map(fn (Banner $banner) => $banner->toArray())->keyBy('id')->all();
            $lockedIds = $banners->pluck('id')->all();
            $released = $this->imageService->releaseForBanners($lockedIds);

            return [$this->banners->deleteMany($lockedIds), $released, $snapshots];
        });
        $this->deleteReleasedImages($released);

        HookManager::doAction('sirsoft-hero_section.banner.after_bulk_delete', $ids, $snapshots);

        return $count;
    }

    /**
     * 자리의 배너 순서를 바꿉니다(FormRequest 가 그 자리 전체 id 인지 확인했다).
     *
     * 훅 인자 셋째에 대상 키를 더했다(정적 위치는 null) — 앞 두 인자는 그대로라 기존 구독자는 바꾸지 않아도 된다.
     *
     * @param  Placement  $placement  자리
     * @param  array<int, int>  $ids  새 순서의 배너 ID 목록
     * @return void 반환값 없음
     */
    public function reorder(Placement $placement, array $ids): void
    {
        HookManager::doAction('sirsoft-hero_section.banner.before_reorder', $placement->position, $ids, $placement->targetKey);

        DB::transaction(fn () => $this->banners->reorder($ids));

        HookManager::doAction('sirsoft-hero_section.banner.after_reorder', $placement->position, $ids, $placement->targetKey);
    }

    /**
     * 검증된 값에서 파일 선택(pc_image_id·mobile_image_id·pc_video_id·mobile_video_id)과 temp_key 를 빼냅니다 — 배너 행에 저장할 값이 아니다.
     *
     * @param  array<string, mixed>  $data  검증된 값 (참조로 받아 선택 키와 temp_key 를 지운다)
     * @return array<string, int|null> 보낸 선택 키(pc·mobile·pc_video·mobile_video) => 파일 ID (보내지 않은 키는 없다)
     */
    private function takeImageSelection(array &$data): array
    {
        $selection = [];
        foreach (['pc' => 'pc_image_id', 'mobile' => 'mobile_image_id', 'pc_video' => 'pc_video_id', 'mobile_video' => 'mobile_video_id'] as $slot => $field) {
            if (array_key_exists($field, $data)) {
                $selection[$slot] = $data[$field] !== null ? (int) $data[$field] : null;
            }
        }
        unset($data['temp_key'], $data['pc_image_id'], $data['mobile_image_id'], $data['pc_video_id'], $data['mobile_video_id']);

        return $selection;
    }

    /**
     * 배너에서 떼어 낸 이미지의 파일·행을 지웁니다 — 실패분은 임시 이미지로 남아 정리 명령이 다시 지운다.
     *
     * @param  array<int, BannerImage>  $released  떼어 낸 이미지
     * @return void 반환값 없음
     */
    private function deleteReleasedImages(array $released): void
    {
        foreach ($released as $image) {
            $this->imageService->deleteImage($image);
        }
    }
}
