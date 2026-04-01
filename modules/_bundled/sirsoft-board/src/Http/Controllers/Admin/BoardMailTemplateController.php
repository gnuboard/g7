<?php

namespace Modules\Sirsoft\Board\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\MailTemplate\MailTemplateIndexRequest;
use App\Http\Requests\MailTemplate\MailTemplatePreviewRequest;
use App\Http\Requests\MailTemplate\UpdateMailTemplateRequest;
use App\Http\Resources\MailTemplateCollection;
use App\Http\Resources\MailTemplateResource;
use Modules\Sirsoft\Board\Models\BoardMailTemplate;
use Modules\Sirsoft\Board\Services\BoardMailTemplateService;
use Illuminate\Http\JsonResponse;

/**
 * 게시판 메일 템플릿 관리 컨트롤러
 */
class BoardMailTemplateController extends AdminBaseController
{
    /**
     * BoardMailTemplateController 생성자.
     *
     * @param BoardMailTemplateService $service 메일 템플릿 서비스
     */
    public function __construct(
        private BoardMailTemplateService $service
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
                'sirsoft-board',
                'messages.mail_template_fetch_success',
                $collection->toArray($request)
            );
        } catch (\Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-board',
                'messages.mail_template_fetch_failed',
                500
            );
        }
    }

    /**
     * 메일 템플릿을 수정합니다.
     *
     * @param UpdateMailTemplateRequest $request 수정 요청
     * @param BoardMailTemplate $boardMailTemplate 수정 대상
     * @return JsonResponse 수정 결과
     */
    public function update(UpdateMailTemplateRequest $request, BoardMailTemplate $boardMailTemplate): JsonResponse
    {
        try {
            $userId = $request->user()?->id;
            $template = $this->service->updateTemplate(
                $boardMailTemplate,
                $request->validated(),
                $userId
            );

            return ResponseHelper::moduleSuccess(
                'sirsoft-board',
                'messages.mail_template_save_success',
                new MailTemplateResource($template)
            );
        } catch (\Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-board',
                'messages.mail_template_save_error',
                500
            );
        }
    }

    /**
     * 메일 템플릿의 활성 상태를 토글합니다.
     *
     * @param BoardMailTemplate $boardMailTemplate 토글 대상
     * @return JsonResponse 토글 결과
     */
    public function toggleActive(BoardMailTemplate $boardMailTemplate): JsonResponse
    {
        try {
            $template = $this->service->toggleActive($boardMailTemplate);

            return ResponseHelper::moduleSuccess(
                'sirsoft-board',
                'messages.mail_template_toggle_success',
                new MailTemplateResource($template)
            );
        } catch (\Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-board',
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
                'sirsoft-board',
                'messages.mail_template_preview_success',
                $result
            );
        } catch (\Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-board',
                'messages.mail_template_preview_failed',
                500
            );
        }
    }

    /**
     * 메일 템플릿을 시더 기본값으로 복원합니다.
     *
     * @param BoardMailTemplate $boardMailTemplate 복원 대상
     * @return JsonResponse 복원 결과
     */
    public function reset(BoardMailTemplate $boardMailTemplate): JsonResponse
    {
        try {
            $defaultData = $this->service->getDefaultTemplateData($boardMailTemplate->type);

            if (! $defaultData) {
                return ResponseHelper::moduleError(
                    'sirsoft-board',
                    'messages.mail_template_reset_no_default',
                    404
                );
            }

            $template = $this->service->resetToDefault($boardMailTemplate, $defaultData);

            return ResponseHelper::moduleSuccess(
                'sirsoft-board',
                'messages.mail_template_reset_success',
                new MailTemplateResource($template)
            );
        } catch (\Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-board',
                'messages.mail_template_reset_failed',
                500
            );
        }
    }
}
