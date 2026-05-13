<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreProductNoticeTemplateRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateProductNoticeTemplateRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\ProductNoticeTemplateCollection;
use Modules\Sirsoft\Ecommerce\Http\Resources\ProductNoticeTemplateResource;
use Modules\Sirsoft\Ecommerce\Services\ProductNoticeTemplateService;

/**
 * 상품정보제공고시 템플릿 컨트롤러
 *
 * 관리자가 상품정보제공고시 템플릿을 관리할 수 있는 기능을 제공합니다.
 */
class ProductNoticeTemplateController extends AdminBaseController
{
    public function __construct(
        private ProductNoticeTemplateService $templateService
    ) {}

    /**
     * 템플릿 목록을 조회합니다.
     *
     * @param Request $request 요청 데이터
     * @return JsonResponse 템플릿 목록 JSON 응답
     */
    public function index(Request $request): JsonResponse
    {
        $filters = [
            'search' => $request->get('search'),
            'is_active' => $request->boolean('active_only', false) ? true : null,
        ];

        // null 값 제거
        $filters = array_filter($filters, fn ($v) => $v !== null);

        // 페이지네이션 파라미터
        $perPage = (int) $request->get('per_page', 20);

        // per_page가 0 이하이거나 all이면 전체 조회
        if ($perPage <= 0 || $request->get('per_page') === 'all') {
            $templates = $this->templateService->getAllTemplates($filters);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.notice_templates.fetch_success',
                new ProductNoticeTemplateCollection($templates)
            );
        }

        // 페이지네이션 조회
        $templates = $this->templateService->getPaginatedTemplates($filters, $perPage);

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.notice_templates.fetch_success',
            new ProductNoticeTemplateCollection($templates)
        );
    }

    /**
     * 템플릿 상세를 조회합니다.
     *
     * @param int $id 템플릿 ID
     * @return JsonResponse 템플릿 상세 JSON 응답
     */
    public function show(int $id): JsonResponse
    {
        $template = $this->templateService->getTemplate($id);

        if (! $template) {
            return ResponseHelper::notFound(
                'messages.notice_templates.not_found',
                [],
                'sirsoft-ecommerce'
            );
        }

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.notice_templates.fetch_success',
            new ProductNoticeTemplateResource($template)
        );
    }

    /**
     * 템플릿을 생성합니다.
     *
     * @param StoreProductNoticeTemplateRequest $request 생성 요청
     * @return JsonResponse 생성된 템플릿 JSON 응답
     */
    public function store(StoreProductNoticeTemplateRequest $request): JsonResponse
    {
        try {
            $template = $this->templateService->createTemplate($request->validated());

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.notice_templates.created',
                new ProductNoticeTemplateResource($template),
                201
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                400
            );
        }
    }

    /**
     * 템플릿을 수정합니다.
     *
     * @param UpdateProductNoticeTemplateRequest $request 수정 요청
     * @param int $id 템플릿 ID
     * @return JsonResponse 수정된 템플릿 JSON 응답
     */
    public function update(UpdateProductNoticeTemplateRequest $request, int $id): JsonResponse
    {
        try {
            $template = $this->templateService->updateTemplate($id, $request->validated());

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.notice_templates.updated',
                new ProductNoticeTemplateResource($template)
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                400
            );
        }
    }

    /**
     * 템플릿을 삭제합니다.
     *
     * @param int $id 템플릿 ID
     * @return JsonResponse 삭제 결과 JSON 응답
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $result = $this->templateService->deleteTemplate($id);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.notice_templates.deleted',
                $result
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                400
            );
        }
    }

    /**
     * 템플릿을 복사합니다.
     *
     * @param int $id 원본 템플릿 ID
     * @return JsonResponse 복사된 템플릿 JSON 응답
     */
    public function copy(int $id): JsonResponse
    {
        try {
            $template = $this->templateService->copyTemplate($id);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.notice_templates.copied',
                new ProductNoticeTemplateResource($template),
                201
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                400
            );
        }
    }
}
