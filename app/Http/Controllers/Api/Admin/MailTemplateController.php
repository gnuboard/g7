<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\MailTemplate\MailTemplateIndexRequest;
use App\Http\Requests\MailTemplate\MailTemplatePreviewRequest;
use App\Http\Requests\MailTemplate\UpdateMailTemplateRequest;
use App\Http\Resources\MailTemplateCollection;
use App\Http\Resources\MailTemplateResource;
use App\Models\MailTemplate;
use App\Services\MailTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * 메일 템플릿 관리 컨트롤러
 *
 * 메일 템플릿의 조회, 수정, 미리보기, 초기화 기능을 제공합니다.
 */
class MailTemplateController extends AdminBaseController
{
    /**
     * MailTemplateController 생성자.
     *
     * @param MailTemplateService $mailTemplateService 메일 템플릿 서비스
     */
    public function __construct(
        private MailTemplateService $mailTemplateService
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
            $templates = $this->mailTemplateService->getTemplates($filters, $perPage);

            $collection = new MailTemplateCollection($templates);

            return $this->success('mail_template.fetch_success', $collection->toArray($request));
        } catch (\Exception $e) {
            Log::error('메일 템플릿 목록 조회 실패', ['error' => $e->getMessage()]);

            return $this->error('mail_template.fetch_failed', 500);
        }
    }

    /**
     * 메일 템플릿을 수정합니다.
     *
     * @param UpdateMailTemplateRequest $request 수정 요청
     * @param MailTemplate $mailTemplate 수정 대상
     * @return JsonResponse 수정 결과
     */
    public function update(UpdateMailTemplateRequest $request, MailTemplate $mailTemplate): JsonResponse
    {
        try {
            $userId = $request->user()?->id;
            $template = $this->mailTemplateService->updateTemplate(
                $mailTemplate,
                $request->validated(),
                $userId
            );

            return $this->success(
                'mail_template.save_success',
                new MailTemplateResource($template)
            );
        } catch (\Exception $e) {
            Log::error('메일 템플릿 수정 실패', ['error' => $e->getMessage()]);

            return $this->error('mail_template.save_error', 500);
        }
    }

    /**
     * 메일 템플릿의 활성 상태를 토글합니다.
     *
     * @param MailTemplate $mailTemplate 토글 대상
     * @return JsonResponse 토글 결과
     */
    public function toggleActive(MailTemplate $mailTemplate): JsonResponse
    {
        try {
            $template = $this->mailTemplateService->toggleActive($mailTemplate);

            return $this->success(
                'mail_template.toggle_success',
                new MailTemplateResource($template)
            );
        } catch (\Exception $e) {
            Log::error('메일 템플릿 활성 상태 토글 실패', ['error' => $e->getMessage()]);

            return $this->error('mail_template.toggle_failed', 500);
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
            $result = $this->mailTemplateService->getPreview($request->validated());

            return $this->success('mail_template.preview_success', $result);
        } catch (\Exception $e) {
            Log::error('메일 템플릿 미리보기 실패', ['error' => $e->getMessage()]);

            return $this->error('mail_template.preview_failed', 500);
        }
    }

    /**
     * 메일 템플릿을 시더 기본값으로 복원합니다.
     *
     * @param MailTemplate $mailTemplate 복원 대상
     * @return JsonResponse 복원 결과
     */
    public function reset(MailTemplate $mailTemplate): JsonResponse
    {
        try {
            $defaultData = $this->mailTemplateService->getDefaultTemplateData($mailTemplate->type);

            if (! $defaultData) {
                return $this->error('mail_template.reset_no_default', 404);
            }

            $template = $this->mailTemplateService->resetToDefault($mailTemplate, $defaultData);

            return $this->success(
                'mail_template.reset_success',
                new MailTemplateResource($template)
            );
        } catch (\Exception $e) {
            Log::error('메일 템플릿 기본값 복원 실패', ['error' => $e->getMessage()]);

            return $this->error('mail_template.reset_failed', 500);
        }
    }
}
