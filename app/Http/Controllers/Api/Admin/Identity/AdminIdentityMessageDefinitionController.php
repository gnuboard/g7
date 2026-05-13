<?php

namespace App\Http\Controllers\Api\Admin\Identity;

use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Admin\Identity\AdminIdentityMessageDefinitionIndexRequest;
use App\Http\Requests\Admin\Identity\StoreIdentityMessageDefinitionRequest;
use App\Http\Requests\Admin\Identity\UpdateIdentityMessageDefinitionRequest;
use App\Http\Resources\Admin\Identity\IdentityMessageDefinitionCollection;
use App\Http\Resources\Admin\Identity\IdentityMessageDefinitionResource;
use App\Models\IdentityMessageDefinition;
use App\Services\IdentityMessageDefinitionService;
use App\Services\IdentityMessageTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * IDV 메시지 정의 관리 컨트롤러.
 */
class AdminIdentityMessageDefinitionController extends AdminBaseController
{
    /**
     * @param  IdentityMessageDefinitionService  $definitionService
     * @param  IdentityMessageTemplateService  $templateService
     */
    public function __construct(
        private readonly IdentityMessageDefinitionService $definitionService,
        private readonly IdentityMessageTemplateService $templateService,
    ) {
        parent::__construct();
    }

    /**
     * 메시지 정의 목록 조회.
     *
     * @param  AdminIdentityMessageDefinitionIndexRequest  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(AdminIdentityMessageDefinitionIndexRequest $request)
    {
        try {
            $filters = $request->validated();
            $perPage = (int) ($filters['per_page'] ?? 20);

            $definitions = $this->definitionService->getDefinitions($filters, $perPage);
            $collection = new IdentityMessageDefinitionCollection($definitions);

            return $this->success(
                __('identity_message.definition_list_success'),
                $collection->toArray($request)
            );
        } catch (\Exception $e) {
            Log::error('IDV 메시지 정의 목록 조회 실패', ['error' => $e->getMessage()]);

            return $this->error(__('identity_message.definition_list_failed'), 500);
        }
    }

    /**
     * 메시지 정의 신규 생성 (정책 매핑 — admin 운영자 전용).
     *
     * scope_type='policy' + scope_value=admin policy.key 매칭만 허용.
     * FormRequest 가 검증 처리.
     *
     * @param  StoreIdentityMessageDefinitionRequest  $request
     * @return JsonResponse
     */
    public function store(StoreIdentityMessageDefinitionRequest $request): JsonResponse
    {
        try {
            $definition = $this->definitionService->createAdminDefinition($request->validated());

            return $this->success(
                __('identity_message.definition_created'),
                new IdentityMessageDefinitionResource($definition->load('templates')),
                201,
            );
        } catch (\Exception $e) {
            Log::error('IDV 메시지 정의 생성 실패', ['error' => $e->getMessage()]);

            return $this->error(__('identity_message.definition_create_failed'), 500);
        }
    }

    /**
     * 메시지 정의 상세 조회.
     *
     * @param  IdentityMessageDefinition  $definition
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(IdentityMessageDefinition $definition)
    {
        try {
            $definition->load('templates');

            return $this->success(
                __('identity_message.definition_show_success'),
                new IdentityMessageDefinitionResource($definition)
            );
        } catch (\Exception $e) {
            Log::error('IDV 메시지 정의 상세 조회 실패', [
                'definition_id' => $definition->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error(__('identity_message.definition_show_failed'), 500);
        }
    }

    /**
     * 메시지 정의 수정 (name, description, channels, is_active).
     *
     * @param  UpdateIdentityMessageDefinitionRequest  $request
     * @param  IdentityMessageDefinition  $definition
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateIdentityMessageDefinitionRequest $request, IdentityMessageDefinition $definition)
    {
        try {
            $updated = $this->definitionService->updateDefinition($definition, $request->validated());

            return $this->success(
                __('identity_message.definition_updated'),
                new IdentityMessageDefinitionResource($updated->load('templates'))
            );
        } catch (\Exception $e) {
            Log::error('IDV 메시지 정의 수정 실패', [
                'definition_id' => $definition->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error(__('identity_message.definition_update_failed'), 500);
        }
    }

    /**
     * 활성/비활성 토글.
     *
     * @param  IdentityMessageDefinition  $definition
     * @return \Illuminate\Http\JsonResponse
     */
    public function toggleActive(IdentityMessageDefinition $definition)
    {
        try {
            $updated = $this->definitionService->toggleActive($definition);

            return $this->success(
                __('identity_message.definition_toggled'),
                new IdentityMessageDefinitionResource($updated)
            );
        } catch (\Exception $e) {
            Log::error('IDV 메시지 정의 활성 토글 실패', [
                'definition_id' => $definition->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error(__('identity_message.definition_toggle_failed'), 500);
        }
    }

    /**
     * 정의의 모든 채널 템플릿을 기본값으로 일괄 복원합니다.
     *
     * @param  IdentityMessageDefinition  $definition
     * @return \Illuminate\Http\JsonResponse
     */
    public function reset(IdentityMessageDefinition $definition)
    {
        try {
            $definition->load('templates');

            foreach ($definition->templates as $template) {
                $this->templateService->resetToDefault($template);
            }

            $this->definitionService->markAsDefault($definition);
            $definition->load('templates');

            return $this->success(
                __('identity_message.definition_reset'),
                new IdentityMessageDefinitionResource($definition)
            );
        } catch (\Exception $e) {
            Log::error('IDV 메시지 정의 초기화 실패', [
                'definition_id' => $definition->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error(__('identity_message.definition_reset_failed'), 500);
        }
    }

    /**
     * 운영자 추가 메시지 정의 삭제.
     *
     * is_default=true 인 시드 정의는 삭제 거부 (선언형 보호).
     * FK cascadeOnDelete 로 자식 templates 자동 삭제.
     *
     * @param  IdentityMessageDefinition  $definition
     * @return JsonResponse
     */
    public function destroy(IdentityMessageDefinition $definition): JsonResponse
    {
        if ($definition->is_default) {
            return $this->forbidden(__('identity_message.definition_delete_forbidden'));
        }

        try {
            $this->definitionService->deleteAdminDefinition($definition);

            return $this->success(__('identity_message.definition_deleted'));
        } catch (\Exception $e) {
            Log::error('IDV 메시지 정의 삭제 실패', [
                'definition_id' => $definition->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error(__('identity_message.definition_delete_failed'), 500);
        }
    }
}
