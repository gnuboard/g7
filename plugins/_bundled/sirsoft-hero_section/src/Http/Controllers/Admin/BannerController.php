<?php

namespace Plugins\Sirsoft\HeroSection\Http\Controllers\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\HeroSection\Http\Requests\Admin\BannerBulkDeleteRequest;
use Plugins\Sirsoft\HeroSection\Http\Requests\Admin\BannerBulkStatusRequest;
use Plugins\Sirsoft\HeroSection\Http\Requests\Admin\BannerListRequest;
use Plugins\Sirsoft\HeroSection\Http\Requests\Admin\BannerPositionRequest;
use Plugins\Sirsoft\HeroSection\Http\Requests\Admin\BannerReorderRequest;
use Plugins\Sirsoft\HeroSection\Http\Requests\Admin\StoreBannerRequest;
use Plugins\Sirsoft\HeroSection\Http\Requests\Admin\UpdateBannerRequest;
use Plugins\Sirsoft\HeroSection\Http\Resources\BannerCollection;
use Plugins\Sirsoft\HeroSection\Http\Resources\BannerResource;
use Plugins\Sirsoft\HeroSection\Models\Banner;
use Plugins\Sirsoft\HeroSection\Services\BannerService;

/**
 * 관리자 배너 컨트롤러 — 목록·미리보기·순서·일괄·등록·상세·수정·삭제.
 */
class BannerController extends AdminBaseController
{
    /**
     * BannerController 생성자
     *
     * @param  BannerService  $banners  배너 서비스
     */
    public function __construct(private BannerService $banners)
    {
        parent::__construct();
    }

    /**
     * 배너 목록(통계·abilities 포함)을 반환합니다.
     *
     * @param  BannerListRequest  $request  목록 요청
     * @return JsonResponse 목록 응답
     */
    public function index(BannerListRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $collection = (new BannerCollection($this->banners->getList($filters)))
            ->withStatistics($this->banners->getStatistics($filters['position']));

        return $this->success('sirsoft-hero_section::messages.banner.fetched', $collection->toArray($request));
    }

    /**
     * 지금 노출 중인 배너를 반환합니다(목록 상단 미리보기).
     *
     * @param  BannerPositionRequest  $request  위치 요청
     * @return JsonResponse 미리보기 응답
     */
    public function preview(BannerPositionRequest $request): JsonResponse
    {
        $rows = $this->banners->getPreview($request->validated()['position']);

        return $this->success('sirsoft-hero_section::messages.banner.fetched', [
            'data' => $rows->map(fn (Banner $b) => (new BannerResource($b))->toArray($request))->values()->all(),
        ]);
    }

    /**
     * 위치의 배너 전체를 순서대로 반환합니다(순서 변경 창).
     *
     * @param  BannerPositionRequest  $request  위치 요청
     * @return JsonResponse 순서 목록 응답
     */
    public function orderList(BannerPositionRequest $request): JsonResponse
    {
        $rows = $this->banners->getOrderList($request->validated()['position']);

        return $this->success('sirsoft-hero_section::messages.banner.fetched', [
            'data' => $rows->map(fn (Banner $b) => (new BannerResource($b))->toListArray($request))->values()->all(),
        ]);
    }

    /**
     * 위치의 배너 순서를 바꿉니다.
     *
     * @param  BannerReorderRequest  $request  순서 요청
     * @return JsonResponse 결과 응답
     */
    public function reorder(BannerReorderRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->banners->reorder($data['position'], array_map('intval', $data['ids']));

        return $this->success('sirsoft-hero_section::messages.banner.reordered');
    }

    /**
     * 여러 배너를 노출·비노출로 바꿉니다.
     *
     * @param  BannerBulkStatusRequest  $request  일괄 상태 요청
     * @return JsonResponse 처리 건수 응답
     */
    public function bulkStatus(BannerBulkStatusRequest $request): JsonResponse
    {
        $data = $request->validated();
        $count = $this->banners->bulkSetActive(array_map('intval', $data['ids']), (bool) $data['is_active'], $request->user()?->id);

        return $this->success('sirsoft-hero_section::messages.banner.bulk_status', ['count' => $count], 200, ['count' => $count]);
    }

    /**
     * 여러 배너를 영구 삭제합니다.
     *
     * @param  BannerBulkDeleteRequest  $request  일괄 삭제 요청
     * @return JsonResponse 처리 건수 응답
     */
    public function bulkDestroy(BannerBulkDeleteRequest $request): JsonResponse
    {
        $count = $this->banners->bulkDelete(array_map('intval', $request->validated()['ids']));

        return $this->success('sirsoft-hero_section::messages.banner.bulk_deleted', ['count' => $count], 200, ['count' => $count]);
    }

    /**
     * 배너를 등록합니다.
     *
     * @param  StoreBannerRequest  $request  등록 요청
     * @return JsonResponse 등록한 배너 응답
     */
    public function store(StoreBannerRequest $request): JsonResponse
    {
        $banner = $this->banners->create($request->validated(), $request->user()?->id);

        return $this->successWithResource('sirsoft-hero_section::messages.banner.created', new BannerResource($banner), 201);
    }

    /**
     * 배너 상세를 반환합니다.
     *
     * @param  Banner  $banner  대상 배너 (라우트 모델 바인딩)
     * @return JsonResponse 상세 응답
     */
    public function show(Banner $banner): JsonResponse
    {
        return $this->successWithResource('sirsoft-hero_section::messages.banner.fetched', new BannerResource($this->banners->find($banner->id)));
    }

    /**
     * 배너를 수정합니다.
     *
     * @param  UpdateBannerRequest  $request  수정 요청
     * @param  Banner  $banner  대상 배너
     * @return JsonResponse 수정한 배너 응답
     */
    public function update(UpdateBannerRequest $request, Banner $banner): JsonResponse
    {
        $updated = $this->banners->update($banner, $request->validated(), $request->user()?->id);

        return $this->successWithResource('sirsoft-hero_section::messages.banner.updated', new BannerResource($updated));
    }

    /**
     * 배너를 영구 삭제합니다(이미지 파일 포함).
     *
     * @param  Banner  $banner  대상 배너
     * @return JsonResponse 결과 응답
     */
    public function destroy(Banner $banner): JsonResponse
    {
        $this->banners->delete($banner);

        return $this->success('sirsoft-hero_section::messages.banner.deleted');
    }
}
