<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\SearchPresetListRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreSearchPresetRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateSearchPresetRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\SearchPresetResource;
use Modules\Sirsoft\Ecommerce\Models\SearchPreset;
use Modules\Sirsoft\Ecommerce\Services\SearchPresetService;

/**
 * 검색 프리셋 관리 컨트롤러
 */
class SearchPresetController extends AdminBaseController
{
    public function __construct(
        protected SearchPresetService $presetService
    ) {}

    /**
     * 프리셋 목록 조회
     *
     * @param SearchPresetListRequest $request 요청
     * @return JsonResponse
     */
    public function index(SearchPresetListRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $targetScreen = $validated['target_screen'] ?? 'products';

        $presets = $this->presetService->getPresets($targetScreen);

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.presets.fetch_success',
            SearchPresetResource::collection($presets)
        );
    }

    /**
     * 프리셋 저장
     *
     * @param StoreSearchPresetRequest $request 요청
     * @return JsonResponse
     */
    public function store(StoreSearchPresetRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $preset = $this->presetService->create(
            $validated['target_screen'] ?? 'products',
            $validated['name'],
            $validated['conditions']
        );

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.presets.created',
            new SearchPresetResource($preset),
            201
        );
    }

    /**
     * 프리셋 수정
     *
     * @param UpdateSearchPresetRequest $request 요청
     * @param SearchPreset $preset 프리셋 모델
     * @return JsonResponse
     */
    public function update(UpdateSearchPresetRequest $request, SearchPreset $preset): JsonResponse
    {
        $preset = $this->presetService->update($preset, $request->validated());

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.presets.updated',
            new SearchPresetResource($preset)
        );
    }

    /**
     * 프리셋 삭제
     *
     * @param SearchPreset $preset 프리셋 모델
     * @return JsonResponse
     */
    public function destroy(SearchPreset $preset): JsonResponse
    {
        $this->presetService->delete($preset);

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.presets.deleted',
            ['deleted' => true]
        );
    }
}
