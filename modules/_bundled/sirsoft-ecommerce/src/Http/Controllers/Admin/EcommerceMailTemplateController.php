<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\MailTemplate\MailTemplateIndexRequest;
use App\Http\Requests\MailTemplate\MailTemplatePreviewRequest;
use App\Http\Requests\MailTemplate\UpdateMailTemplateRequest;
use App\Http\Resources\MailTemplateCollection;
use App\Http\Resources\MailTemplateResource;
use Modules\Sirsoft\Ecommerce\Models\EcommerceMailTemplate;
use Modules\Sirsoft\Ecommerce\Services\EcommerceMailTemplateService;
use Illuminate\Http\JsonResponse;

/**
 * 이커머스 메일 템플릿 관리 컨트롤러
 */
class EcommerceMailTemplateController extends AdminBaseController
{
    /**
     * EcommerceMailTemplateController 생성자.
     *
     * @param EcommerceMailTemplateService $service 메일 템플릿 서비스
     */
    public function __construct(
        private EcommerceMailTemplateService $service
    ) {
        parent::__construct();
    }

    /**
     * 메일 템플릿 목록을 페이지네이션하여 조회합니다.
     *
     * @param MailTemplateIndexRequest $request 검증된 목록 조회 요청
     * @return JsonResponse 페이지네이션된 템플릿 목록
     */
    public function index(MailTemplateIndexRequest $request): JsonResponse
    {
        try {
            $filters = array_filter($request->validated(), fn ($value) => $value !== null);
            $perPage = (int) ($request->validated('per_page') ?? 20);
            $templates = $this->service->getTemplates($filters, $perPage);

            $collection = new MailTemplateCollection($templates);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.mail_template_fetch_success',
                $collection->toArray($request)
            );
        } catch (\Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.mail_template_fetch_failed',
                500
            );
        }
    }

    /**
     * 메일 템플릿을 수정합니다.
     *
     * @param UpdateMailTemplateRequest $request 수정 요청
     * @param EcommerceMailTemplate $ecommerceMailTemplate 수정 대상
     * @return JsonResponse 수정 결과
     */
    public function update(UpdateMailTemplateRequest $request, EcommerceMailTemplate $ecommerceMailTemplate): JsonResponse
    {
        try {
            $userId = $request->user()?->id;
            $template = $this->service->updateTemplate(
                $ecommerceMailTemplate,
                $request->validated(),
                $userId
            );

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.mail_template_save_success',
                new MailTemplateResource($template)
            );
        } catch (\Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.mail_template_save_error',
                500
            );
        }
    }

    /**
     * 메일 템플릿의 활성 상태를 토글합니다.
     *
     * @param EcommerceMailTemplate $ecommerceMailTemplate 토글 대상
     * @return JsonResponse 토글 결과
     */
    public function toggleActive(EcommerceMailTemplate $ecommerceMailTemplate): JsonResponse
    {
        try {
            $template = $this->service->toggleActive($ecommerceMailTemplate);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.mail_template_toggle_success',
                new MailTemplateResource($template)
            );
        } catch (\Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.mail_template_toggle_failed',
                500
            );
        }
    }

    /**
     * 메일 템플릿 미리보기를 반환합니다.
     *
     * @param MailTemplatePreviewRequest $request 검증된 미리보기 요청
     * @return JsonResponse 미리보기 결과
     */
    public function preview(MailTemplatePreviewRequest $request): JsonResponse
    {
        try {
            $result = $this->service->getPreview($request->validated());

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.mail_template_preview_success',
                $result
            );
        } catch (\Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.mail_template_preview_failed',
                500
            );
        }
    }

    /**
     * 메일 템플릿을 시더 기본값으로 복원합니다.
     *
     * @param EcommerceMailTemplate $ecommerceMailTemplate 복원 대상
     * @return JsonResponse 복원 결과
     */
    public function reset(EcommerceMailTemplate $ecommerceMailTemplate): JsonResponse
    {
        try {
            $defaultData = $this->service->getDefaultTemplateData($ecommerceMailTemplate->type);

            if (! $defaultData) {
                return ResponseHelper::moduleError(
                    'sirsoft-ecommerce',
                    'messages.mail_template_reset_no_default',
                    404
                );
            }

            $template = $this->service->resetToDefault($ecommerceMailTemplate, $defaultData);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.mail_template_reset_success',
                new MailTemplateResource($template)
            );
        } catch (\Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.mail_template_reset_failed',
                500
            );
        }
    }
}
