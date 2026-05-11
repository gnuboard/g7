<?php

namespace App\Http\Controllers\Api\Admin\Identity;

use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Admin\Identity\PreviewIdentityMessageTemplateRequest;
use App\Http\Requests\Admin\Identity\UpdateIdentityMessageTemplateRequest;
use App\Http\Resources\Admin\Identity\IdentityMessageTemplateResource;
use App\Models\IdentityMessageTemplate;
use App\Services\IdentityMessageTemplateService;
use Illuminate\Support\Facades\Log;

/**
 * IDV 메시지 템플릿 관리 컨트롤러.
 */
class AdminIdentityMessageTemplateController extends AdminBaseController
{
    /**
     * @param  IdentityMessageTemplateService  $templateService
     */
    public function __construct(
        private readonly IdentityMessageTemplateService $templateService,
    ) {
        parent::__construct();
    }

    /**
     * 메시지 템플릿 수정 (subject, body, is_active).
     *
     * @param  UpdateIdentityMessageTemplateRequest  $request
     * @param  IdentityMessageTemplate  $template
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateIdentityMessageTemplateRequest $request, IdentityMessageTemplate $template)
    {
        try {
            $updated = $this->templateService->updateTemplate($template, $request->validated());

            return $this->success(
                __('identity_message.template_updated'),
                new IdentityMessageTemplateResource($updated)
            );
        } catch (\Exception $e) {
            Log::error('IDV 메시지 템플릿 수정 실패', [
                'template_id' => $template->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error(__('identity_message.template_update_failed'), 500);
        }
    }

    /**
     * 활성/비활성 토글.
     *
     * @param  IdentityMessageTemplate  $template
     * @return \Illuminate\Http\JsonResponse
     */
    public function toggleActive(IdentityMessageTemplate $template)
    {
        try {
            $updated = $this->templateService->toggleActive($template);

            return $this->success(
                __('identity_message.template_toggled'),
                new IdentityMessageTemplateResource($updated)
            );
        } catch (\Exception $e) {
            Log::error('IDV 메시지 템플릿 활성 토글 실패', [
                'template_id' => $template->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error(__('identity_message.template_toggle_failed'), 500);
        }
    }

    /**
     * 변수 치환 미리보기.
     *
     * @param  PreviewIdentityMessageTemplateRequest  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function preview(PreviewIdentityMessageTemplateRequest $request)
    {
        try {
            $payload = $request->validated();
            $template = IdentityMessageTemplate::findOrFail($payload['template_id']);
            $rendered = $this->templateService->getPreview(
                $template,
                $payload['data'] ?? [],
                $payload['locale'] ?? null,
            );

            return $this->success(__('identity_message.preview_success'), $rendered);
        } catch (\Exception $e) {
            Log::error('IDV 메시지 템플릿 미리보기 실패', ['error' => $e->getMessage()]);

            return $this->error(__('identity_message.preview_failed'), 500);
        }
    }

    /**
     * 템플릿을 시더 기본값으로 복원.
     *
     * @param  IdentityMessageTemplate  $template
     * @return \Illuminate\Http\JsonResponse
     */
    public function reset(IdentityMessageTemplate $template)
    {
        try {
            $updated = $this->templateService->resetToDefault($template);

            return $this->success(
                __('identity_message.template_reset'),
                new IdentityMessageTemplateResource($updated)
            );
        } catch (\Exception $e) {
            Log::error('IDV 메시지 템플릿 초기화 실패', [
                'template_id' => $template->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error(__('identity_message.template_reset_failed'), 500);
        }
    }
}
