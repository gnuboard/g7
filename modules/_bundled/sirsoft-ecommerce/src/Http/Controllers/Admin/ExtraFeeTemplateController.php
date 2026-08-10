<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ExtraFeeTemplateBulkCreateRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ExtraFeeTemplateBulkDeleteRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ExtraFeeTemplateBulkToggleActiveRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ExtraFeeTemplateListRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ExtraFeeTemplateStoreRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ExtraFeeTemplateUpdateRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\ExtraFeeTemplateCollection;
use Modules\Sirsoft\Ecommerce\Http\Resources\ExtraFeeTemplateResource;
use Modules\Sirsoft\Ecommerce\Services\ExtraFeeTemplateService;

/**
 * 추가배송비 템플릿 관리 컨트롤러
 */
class ExtraFeeTemplateController extends AdminBaseController
{
    public function __construct(
        private ExtraFeeTemplateService $extraFeeTemplateService
    ) {}

    /**
     * 추가배송비 템플릿 목록 조회
     *
     * @param ExtraFeeTemplateListRequest $request
     * @return JsonResponse
     */
    public function index(ExtraFeeTemplateListRequest $request): JsonResponse
    {
        $templates = $this->extraFeeTemplateService->getList($request->validated());
        $statistics = $this->extraFeeTemplateService->getStatistics();

        $collection = new ExtraFeeTemplateCollection($templates);

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.extra_fee_template.list_retrieved',
            $collection->withStatistics($statistics)
        );
    }

    /**
     * 추가배송비 템플릿 상세 조회
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        $template = $this->extraFeeTemplateService->getDetail($id);

        if (! $template) {
            return ResponseHelper::notFound(
                'messages.extra_fee_template.not_found',
                [],
                'sirsoft-ecommerce'
            );
        }

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.extra_fee_template.retrieved',
            new ExtraFeeTemplateResource($template)
        );
    }

    /**
     * 추가배송비 템플릿 생성
     *
     * @param ExtraFeeTemplateStoreRequest $request
     * @return JsonResponse
     */
    public function store(ExtraFeeTemplateStoreRequest $request): JsonResponse
    {
        try {
            $template = $this->extraFeeTemplateService->create($request->validated());

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.extra_fee_template.created',
                new ExtraFeeTemplateResource($template),
                201
            );
        } catch (\Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                400
            );
        }
    }

    /**
     * 추가배송비 템플릿 수정
     *
     * @param ExtraFeeTemplateUpdateRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(ExtraFeeTemplateUpdateRequest $request, int $id): JsonResponse
    {
        $template = $this->extraFeeTemplateService->getDetail($id);

        if (! $template) {
            return ResponseHelper::notFound(
                'messages.extra_fee_template.not_found',
                [],
                'sirsoft-ecommerce'
            );
        }

        try {
            $updatedTemplate = $this->extraFeeTemplateService->update($template, $request->validated());

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.extra_fee_template.updated',
                new ExtraFeeTemplateResource($updatedTemplate)
            );
        } catch (\Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                400
            );
        }
    }

    /**
     * 추가배송비 템플릿 삭제
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        $template = $this->extraFeeTemplateService->getDetail($id);

        if (! $template) {
            return ResponseHelper::notFound(
                'messages.extra_fee_template.not_found',
                [],
                'sirsoft-ecommerce'
            );
        }

        try {
            $this->extraFeeTemplateService->delete($template);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.extra_fee_template.deleted'
            );
        } catch (\Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                400
            );
        }
    }

    /**
     * 추가배송비 템플릿 사용여부 토글
     *
     * @param int $id
     * @return JsonResponse
     */
    public function toggleActive(int $id): JsonResponse
    {
        $template = $this->extraFeeTemplateService->getDetail($id);

        if (! $template) {
            return ResponseHelper::notFound(
                'messages.extra_fee_template.not_found',
                [],
                'sirsoft-ecommerce'
            );
        }

        try {
            $updatedTemplate = $this->extraFeeTemplateService->toggleActive($template);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.extra_fee_template.toggled',
                new ExtraFeeTemplateResource($updatedTemplate)
            );
        } catch (\Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                400
            );
        }
    }

    /**
     * 추가배송비 템플릿 일괄 삭제
     *
     * @param ExtraFeeTemplateBulkDeleteRequest $request
     * @return JsonResponse
     */
    public function bulkDestroy(ExtraFeeTemplateBulkDeleteRequest $request): JsonResponse
    {
        try {
            $count = $this->extraFeeTemplateService->bulkDelete($request->validated()['ids']);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.extra_fee_template.bulk_deleted',
                ['deleted_count' => $count]
            );
        } catch (\Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                400
            );
        }
    }

    /**
     * 추가배송비 템플릿 일괄 사용여부 변경
     *
     * @param ExtraFeeTemplateBulkToggleActiveRequest $request
     * @return JsonResponse
     */
    public function bulkToggleActive(ExtraFeeTemplateBulkToggleActiveRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $count = $this->extraFeeTemplateService->bulkToggleActive(
                $validated['ids'],
                $validated['is_active']
            );

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.extra_fee_template.bulk_toggled',
                ['updated_count' => $count]
            );
        } catch (\Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                400
            );
        }
    }

    /**
     * 일괄 등록 (CSV/엑셀 업로드용)
     *
     * @param ExtraFeeTemplateBulkCreateRequest $request
     * @return JsonResponse
     */
    public function bulkStore(ExtraFeeTemplateBulkCreateRequest $request): JsonResponse
    {
        try {
            $count = $this->extraFeeTemplateService->bulkCreate($request->validated()['items']);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.extra_fee_template.bulk_created',
                ['created_count' => $count],
                201
            );
        } catch (\Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                400
            );
        }
    }

    /**
     * 활성화된 템플릿을 배송정책용 JSON 배열로 반환
     *
     * @return JsonResponse
     */
    public function activeSettings(): JsonResponse
    {
        $settings = $this->extraFeeTemplateService->getAllAsExtraFeeSettings();

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.extra_fee_template.settings_retrieved',
            $settings
        );
    }
}
