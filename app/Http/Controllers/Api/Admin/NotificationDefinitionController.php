<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\NotificationDefinition\NotificationDefinitionIndexRequest;
use App\Http\Requests\NotificationDefinition\UpdateNotificationDefinitionRequest;
use App\Http\Resources\NotificationDefinitionCollection;
use App\Http\Resources\NotificationDefinitionResource;
use App\Models\NotificationDefinition;
use App\Services\NotificationDefinitionService;
use App\Services\NotificationTemplateService;
use Illuminate\Support\Facades\Log;

/**
 * 알림 정의 관리 컨트롤러
 */
class NotificationDefinitionController extends AdminBaseController
{
    /**
     * @param NotificationDefinitionService $definitionService
     */
    public function __construct(
        private readonly NotificationDefinitionService $definitionService,
        private readonly NotificationTemplateService $templateService,
    ) {
        parent::__construct();
    }

    /**
     * 알림 정의 목록을 조회합니다.
     *
     * @param NotificationDefinitionIndexRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(NotificationDefinitionIndexRequest $request)
    {
        try {
            $filters = $request->validated();
            $perPage = $filters['per_page'] ?? 20;

            $definitions = $this->definitionService->getDefinitions($filters, $perPage);
            $collection = new NotificationDefinitionCollection($definitions);

            return $this->success(
                __('notification.definition_list_success'),
                $collection->toArray($request)
            );
        } catch (\Exception $e) {
            Log::error('알림 정의 목록 조회 실패', ['error' => $e->getMessage()]);

            return $this->error(__('notification.definition_list_failed'), 500);
        }
    }

    /**
     * 알림 정의 상세를 조회합니다.
     *
     * @param NotificationDefinition $definition
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(NotificationDefinition $definition)
    {
        try {
            $definition->load('templates');

            return $this->success(
                __('notification.definition_show_success'),
                new NotificationDefinitionResource($definition)
            );
        } catch (\Exception $e) {
            Log::error('알림 정의 상세 조회 실패', [
                'definition_id' => $definition->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error(__('notification.definition_show_failed'), 500);
        }
    }

    /**
     * 알림 정의를 수정합니다.
     *
     * @param UpdateNotificationDefinitionRequest $request
     * @param NotificationDefinition $definition
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateNotificationDefinitionRequest $request, NotificationDefinition $definition)
    {
        try {
            $updated = $this->definitionService->updateDefinition(
                $definition,
                $request->validated(),
                $this->getCurrentAdmin()?->id
            );

            return $this->success(
                __('notification.definition_updated'),
                new NotificationDefinitionResource($updated->load('templates'))
            );
        } catch (\Exception $e) {
            Log::error('알림 정의 수정 실패', [
                'definition_id' => $definition->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error(__('notification.definition_update_failed'), 500);
        }
    }

    /**
     * 알림 정의 활성/비활성을 토글합니다.
     *
     * @param NotificationDefinition $definition
     * @return \Illuminate\Http\JsonResponse
     */
    public function toggleActive(NotificationDefinition $definition)
    {
        try {
            $updated = $this->definitionService->toggleActive($definition);

            return $this->success(
                __('notification.definition_toggled'),
                new NotificationDefinitionResource($updated)
            );
        } catch (\Exception $e) {
            Log::error('알림 정의 활성 토글 실패', [
                'definition_id' => $definition->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error(__('notification.definition_toggle_failed'), 500);
        }
    }

    /**
     * 알림 정의의 모든 템플릿을 기본값으로 일괄 복원합니다.
     *
     * @param NotificationDefinition $definition
     * @return \Illuminate\Http\JsonResponse
     */
    public function reset(NotificationDefinition $definition)
    {
        try {
            $definition->load('templates');
            $resetCount = 0;

            foreach ($definition->templates as $template) {
                $defaultData = $this->templateService->getDefaultTemplateData(
                    $definition->type,
                    $template->channel
                );

                if (! empty($defaultData)) {
                    $this->templateService->resetToDefault($template, $defaultData);
                    $resetCount++;
                }
            }

            $this->definitionService->markAsDefault($definition);
            $definition->load('templates');

            return $this->success(
                __('notification.definition_reset'),
                new NotificationDefinitionResource($definition)
            );
        } catch (\Exception $e) {
            Log::error('알림 정의 초기화 실패', [
                'definition_id' => $definition->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error(__('notification.definition_reset_failed'), 500);
        }
    }
}
