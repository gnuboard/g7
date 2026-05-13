<?php

namespace App\Http\Controllers\Api\Admin\Identity;

use App\Enums\IdentityPolicySourceType;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Identity\AdminIdentityPolicyIndexRequest;
use App\Http\Requests\Identity\AdminIdentityPolicyResetFieldRequest;
use App\Http\Requests\Identity\AdminIdentityPolicyStoreRequest;
use App\Http\Requests\Identity\AdminIdentityPolicyUpdateRequest;
use App\Http\Resources\Identity\PolicyCollection;
use App\Http\Resources\Identity\PolicyResource;
use App\Services\IdentityPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 관리자 — IDV 정책 CRUD 컨트롤러.
 *
 * S1d 서브섹션(DataGrid + 편집 모달)을 위한 API.
 * 선언형 정책(source_type != 'admin')은 enabled/grace_minutes/provider_id/fail_mode/conditions 5개 필드만
 * 편집 가능하며, 편집 시 user_overrides JSON 에 필드명이 append 되어 Seeder 재실행 시 보존됩니다.
 */
class AdminIdentityPolicyController extends AdminBaseController
{
    /**
     * source_type = core/module/plugin 정책이 수정 가능한 필드 화이트리스트.
     *
     * @var list<string>
     */
    protected const LIMITED_EDITABLE_FIELDS = ['enabled', 'grace_minutes', 'provider_id', 'fail_mode', 'conditions'];

    /**
     * @param  IdentityPolicyService  $policyService  정책 유스케이스 Service
     */
    public function __construct(
        protected IdentityPolicyService $policyService,
    ) {}

    /**
     * 정책 목록을 조회합니다.
     *
     * @param  AdminIdentityPolicyIndexRequest  $request  검증된 요청 (필터: scope/purpose/source_type/enabled/search)
     * @return JsonResponse 정책 컬렉션 (페이지네이션 포함)
     */
    public function index(AdminIdentityPolicyIndexRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $filters = array_filter(
            array_intersect_key($validated, array_flip(['scope', 'purpose', 'source_type', 'source_identifier', 'applies_to', 'fail_mode', 'enabled', 'search'])),
            fn ($v) => $v !== null,
        );
        $perPage = (int) ($validated['per_page'] ?? 20);

        $paginated = $this->policyService->search($filters, $perPage);
        $collection = (new PolicyCollection($paginated))->toArray($request);

        return $this->success('messages.success', [
            'data' => $collection['data'],
            'abilities' => $collection['abilities'] ?? [],
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
        ]);
    }

    /**
     * 정책을 신규 생성합니다 (source_type='admin' 고정).
     *
     * @param  AdminIdentityPolicyStoreRequest  $request  검증된 요청
     * @return JsonResponse 생성된 정책 리소스
     */
    public function store(AdminIdentityPolicyStoreRequest $request): JsonResponse
    {
        $policy = $this->policyService->createAdminPolicy($request->validated());

        return $this->success(
            'messages.created',
            (new PolicyResource($policy))->toArray($request),
            201,
        );
    }

    /**
     * 정책을 수정합니다. source_type != 'admin' 일 경우 제한 필드만 허용됩니다.
     *
     * @param  AdminIdentityPolicyUpdateRequest  $request  검증된 요청
     * @param  int  $id  정책 ID
     * @return JsonResponse 수정된 정책 리소스
     */
    public function update(AdminIdentityPolicyUpdateRequest $request, int $id): JsonResponse
    {
        $policy = $this->policyService->findById($id);

        if (! $policy) {
            return $this->error('messages.not_found', 404);
        }

        $validated = $request->validated();

        // source_type != 'admin' 이면 제한 필드만 허용
        if ($policy->source_type !== IdentityPolicySourceType::Admin) {
            $validated = array_intersect_key(
                $validated,
                array_flip(self::LIMITED_EDITABLE_FIELDS),
            );
        }

        if (empty($validated)) {
            return $this->error('validation.nothing_to_update', 422);
        }

        if (! $this->policyService->updatePolicy($policy, $validated)) {
            return $this->error('messages.failed', 500);
        }

        $policy->refresh();

        return $this->success(
            'messages.updated',
            (new PolicyResource($policy))->toArray($request),
        );
    }

    /**
     * 정책을 삭제합니다 (source_type='admin' 정책만 가능, 선언형 정책은 비활성화로 대체).
     *
     * @param  Request  $request  HTTP 요청
     * @param  int  $id  정책 ID
     * @return JsonResponse
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $policy = $this->policyService->findById($id);

        if (! $policy) {
            return $this->error('messages.not_found', 404);
        }

        if ($policy->source_type !== IdentityPolicySourceType::Admin) {
            return $this->forbidden('messages.cannot_delete_system_resource');
        }

        return $this->policyService->deleteAdminPolicy($policy)
            ? $this->success('messages.deleted')
            : $this->error('messages.failed', 500);
    }

    /**
     * 특정 필드의 user_overrides 를 해제하고 선언 기본값으로 즉시 복원합니다.
     *
     * S1d "↺ 기본값으로 되돌리기" 버튼이 호출하는 엔드포인트입니다.
     * `source_type='admin'` 정책은 선언 기본값이 없어 false 를 반환하며,
     * 선언형 정책(core/module/plugin) 에 대해서만 의미가 있습니다.
     *
     * @param  AdminIdentityPolicyResetFieldRequest  $request  검증된 요청 (field 필수)
     * @param  int  $id  정책 ID
     * @return JsonResponse 복원된 최신 정책 리소스 또는 오류
     */
    public function resetField(AdminIdentityPolicyResetFieldRequest $request, int $id): JsonResponse
    {
        $policy = $this->policyService->findById($id);

        if (! $policy) {
            return $this->error('messages.not_found', 404);
        }

        if ($policy->source_type === IdentityPolicySourceType::Admin) {
            return $this->forbidden('identity.errors.admin_policy_has_no_default');
        }

        $field = (string) $request->validated()['field'];
        $ok = $this->policyService->resetFieldOverride($policy, $field);

        if (! $ok) {
            return $this->error('identity.errors.reset_field_failed', 422);
        }

        return $this->successWithResource('messages.success', new PolicyResource($policy->fresh()));
    }
}
