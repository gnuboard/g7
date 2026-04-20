<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\NotificationTemplate\PreviewNotificationTemplateRequest;
use App\Http\Requests\NotificationTemplate\UpdateNotificationTemplateRequest;
use App\Http\Resources\NotificationTemplateResource;
use App\Models\NotificationTemplate;
use App\Services\NotificationTemplateService;
use Illuminate\Support\Facades\Log;

/**
 * 알림 템플릿 관리 컨트롤러
 */
class NotificationTemplateController extends AdminBaseController
{
    /**
     * @param NotificationTemplateService $templateService
     */
    public function __construct(
        private readonly NotificationTemplateService $templateService,
    ) {
        parent::__construct();
    }

    /**
     * 알림 템플릿을 수정합니다.
     *
     * @param UpdateNotificationTemplateRequest $request
     * @param NotificationTemplate $template
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateNotificationTemplateRequest $request, NotificationTemplate $template)
    {
        try {
            $updated = $this->templateService->updateTemplate(
                $template,
                $request->validated(),
                $this->getCurrentAdmin()?->id
            );

            return $this->success(
                __('notification.template_updated'),
                new NotificationTemplateResource($updated)
            );
        } catch (\Exception $e) {
            Log::error('알림 템플릿 수정 실패', [
                'template_id' => $template->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error(__('notification.template_update_failed'), 500);
        }
    }

    /**
     * 알림 템플릿 활성/비활성을 토글합니다.
     *
     * @param NotificationTemplate $template
     * @return \Illuminate\Http\JsonResponse
     */
    public function toggleActive(NotificationTemplate $template)
    {
        try {
            $updated = $this->templateService->toggleActive($template);

            return $this->success(
                __('notification.template_toggled'),
                new NotificationTemplateResource($updated)
            );
        } catch (\Exception $e) {
            Log::error('알림 템플릿 활성 토글 실패', [
                'template_id' => $template->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error(__('notification.template_toggle_failed'), 500);
        }
    }

    /**
     * 알림 템플릿 미리보기를 반환합니다.
     *
     * @param PreviewNotificationTemplateRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function preview(PreviewNotificationTemplateRequest $request)
    {
        try {
            $result = $this->templateService->getPreview($request->validated());

            return $this->success(__('notification.preview_success'), $result);
        } catch (\Exception $e) {
            Log::error('알림 템플릿 미리보기 실패', ['error' => $e->getMessage()]);

            return $this->error(__('notification.preview_failed'), 500);
        }
    }

    /**
     * 알림 템플릿을 기본값으로 복원합니다.
     *
     * @param NotificationTemplate $template
     * @return \Illuminate\Http\JsonResponse
     */
    public function reset(NotificationTemplate $template)
    {
        try {
            $definition = $template->definition;
            if (! $definition) {
                return $this->error(__('notification.definition_not_found'), 404);
            }

            $defaultData = $this->templateService->getDefaultTemplateData($definition->type, $template->channel);
            if (empty($defaultData)) {
                return $this->error(__('notification.default_data_not_found'), 404);
            }

            $updated = $this->templateService->resetToDefault($template, $defaultData);

            return $this->success(
                __('notification.template_reset'),
                new NotificationTemplateResource($updated)
            );
        } catch (\Exception $e) {
            Log::error('알림 템플릿 초기화 실패', [
                'template_id' => $template->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error(__('notification.template_reset_failed'), 500);
        }
    }

}
