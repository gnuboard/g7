<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ShippingPolicyBulkDeleteRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ShippingPolicyBulkToggleActiveRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ShippingPolicyListRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreShippingPolicyRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\TestShippingApiRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateShippingPolicyClaimSettingsRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateShippingPolicyRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\ShippingPolicyClaimAddressResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\ShippingPolicyCollection;
use Modules\Sirsoft\Ecommerce\Http\Resources\ShippingPolicyCountrySettingResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\ShippingPolicyResource;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Services\OrderCalculationService;
use Modules\Sirsoft\Ecommerce\Services\ShippingPolicyService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * 배송정책 관리 컨트롤러
 */
class ShippingPolicyController extends AdminBaseController
{
    /**
     * 배송정책 서비스를 주입받습니다.
     *
     * @param  ShippingPolicyService  $shippingPolicyService  배송정책 CRUD·클레임 설정 서비스
     * @param  OrderCalculationService  $calculationService  배송비 계산 API 테스트 호출 서비스
     */
    public function __construct(
        private ShippingPolicyService $shippingPolicyService,
        private OrderCalculationService $calculationService
    ) {}

    /**
     * 배송정책 목록 조회
     *
     * @param  ShippingPolicyListRequest  $request
     * @return JsonResponse
     */
    public function index(ShippingPolicyListRequest $request): JsonResponse
    {
        $shippingPolicies = $this->shippingPolicyService->getList($request->validated());
        $statistics = $this->shippingPolicyService->getStatistics();

        $collection = new ShippingPolicyCollection($shippingPolicies);

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.shipping_policy.list_retrieved',
            $collection->withStatistics($statistics)
        );
    }

    /**
     * 배송정책 생성
     *
     * @param  StoreShippingPolicyRequest  $request
     * @return JsonResponse
     */
    public function store(StoreShippingPolicyRequest $request): JsonResponse
    {
        try {
            $shippingPolicy = $this->shippingPolicyService->create($request->validated());

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.shipping_policy.created',
                new ShippingPolicyResource($shippingPolicy),
                201
            );
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (\Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                500
            );
        }
    }

    /**
     * 계산 API 연동 테스트 호출
     *
     * 관리자가 폼에서 입력 중인 설정으로 외부 배송비 계산 API 를 1회 실호출하여
     * 요청 미리보기 + 응답 + 추출 배송비를 반환합니다. 타임아웃·응답 크기 제한 적용.
     *
     * @param  TestShippingApiRequest  $request  테스트 호출 요청
     * @return JsonResponse 테스트 결과
     */
    public function testApiCall(TestShippingApiRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->calculationService->testApiCall(
            endpoint: $validated['endpoint'],
            config: $validated['config'] ?? [],
            requestFields: $validated['request_fields'] ?? null,
            sample: $validated['sample'] ?? [],
        );

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.shipping_policy.api_test_done',
            $result
        );
    }

    /**
     * 배송정책 수정
     *
     * @param  UpdateShippingPolicyRequest  $request
     * @param  int  $id
     * @return JsonResponse
     */
    public function update(UpdateShippingPolicyRequest $request, int $id): JsonResponse
    {
        $shippingPolicy = $this->shippingPolicyService->getDetail($id);

        if (! $shippingPolicy) {
            return ResponseHelper::notFound(
                'messages.shipping_policy.not_found',
                [],
                'sirsoft-ecommerce'
            );
        }

        try {
            $updated = $this->shippingPolicyService->update($shippingPolicy, $request->validated());

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.shipping_policy.updated',
                new ShippingPolicyResource($updated)
            );
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (\Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                500
            );
        }
    }

    /**
     * 배송정책 클레임 설정(회수지 + 국가별 클레임 배송비)을 조회합니다.
     *
     * @param  int  $id  배송정책 ID
     * @return JsonResponse 클레임 설정 응답
     */
    public function claimSettings(int $id): JsonResponse
    {
        $shippingPolicy = $this->shippingPolicyService->getDetail($id);

        if (! $shippingPolicy) {
            return ResponseHelper::notFound(
                'messages.shipping_policy.not_found',
                [],
                'sirsoft-ecommerce'
            );
        }

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.shipping_policy.claim_settings_retrieved',
            $this->claimSettingsPayload($shippingPolicy)
        );
    }

    /**
     * 배송정책 클레임 설정을 수정합니다. (부분 수정 — 보낸 축만 반영)
     *
     * @param  UpdateShippingPolicyClaimSettingsRequest  $request  수정 요청
     * @param  int  $id  배송정책 ID
     * @return JsonResponse 수정된 클레임 설정 응답
     */
    public function updateClaimSettings(UpdateShippingPolicyClaimSettingsRequest $request, int $id): JsonResponse
    {
        $shippingPolicy = $this->shippingPolicyService->getDetail($id);

        if (! $shippingPolicy) {
            return ResponseHelper::notFound(
                'messages.shipping_policy.not_found',
                [],
                'sirsoft-ecommerce'
            );
        }

        try {
            $shippingPolicy = $this->shippingPolicyService->updateClaimSettings($shippingPolicy, $request->validated());

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.shipping_policy.claim_settings_updated',
                $this->claimSettingsPayload($shippingPolicy)
            );
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (\Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                500
            );
        }
    }

    /**
     * 클레임 설정 응답 페이로드를 조립합니다. (GET/PUT 공용 — 같은 형태를 보증)
     *
     * @param  ShippingPolicy  $shippingPolicy  배송정책
     * @return array<string, mixed> 클레임 설정 페이로드
     */
    private function claimSettingsPayload($shippingPolicy): array
    {
        $settings = $this->shippingPolicyService->getClaimSettings($shippingPolicy);

        return [
            'addresses' => collect($settings['addresses'])
                ->map(fn ($address) => $address ? (new ShippingPolicyClaimAddressResource($address))->resolve() : null)
                ->toArray(),
            'country_settings' => ShippingPolicyCountrySettingResource::collection($settings['country_settings'])->resolve(),
        ];
    }

    /**
     * 배송정책 상세 조회
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        $shippingPolicy = $this->shippingPolicyService->getDetail($id);

        if (! $shippingPolicy) {
            return ResponseHelper::notFound(
                'messages.shipping_policy.not_found',
                [],
                'sirsoft-ecommerce'
            );
        }

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.shipping_policy.retrieved',
            new ShippingPolicyResource($shippingPolicy)
        );
    }

    /**
     * 배송정책 삭제
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        $shippingPolicy = $this->shippingPolicyService->getDetail($id);

        if (! $shippingPolicy) {
            return ResponseHelper::notFound(
                'messages.shipping_policy.not_found',
                [],
                'sirsoft-ecommerce'
            );
        }

        try {
            $this->shippingPolicyService->delete($shippingPolicy);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.shipping_policy.deleted'
            );
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (\Exception $e) {
            // 서버 결함/인프라 장애 — 4xx 로 뭉개면 장애가 입력 오류로 위장된다
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                500
            );
        }
    }

    /**
     * 배송정책 사용여부 토글
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function toggleActive(int $id): JsonResponse
    {
        $shippingPolicy = $this->shippingPolicyService->getDetail($id);

        if (! $shippingPolicy) {
            return ResponseHelper::notFound(
                'messages.shipping_policy.not_found',
                [],
                'sirsoft-ecommerce'
            );
        }

        try {
            $updatedPolicy = $this->shippingPolicyService->toggleActive($shippingPolicy);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.shipping_policy.toggled',
                new ShippingPolicyResource($updatedPolicy)
            );
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (\Exception $e) {
            // 서버 결함/인프라 장애 — 4xx 로 뭉개면 장애가 입력 오류로 위장된다
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                500
            );
        }
    }

    /**
     * 배송정책 일괄 삭제
     *
     * @param  ShippingPolicyBulkDeleteRequest  $request
     * @return JsonResponse
     */
    public function bulkDestroy(ShippingPolicyBulkDeleteRequest $request): JsonResponse
    {
        try {
            $count = $this->shippingPolicyService->bulkDelete($request->validated()['ids']);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.shipping_policy.bulk_deleted',
                ['deleted_count' => $count],
                200,
                ['count' => $count]
            );
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (\Exception $e) {
            // 서버 결함/인프라 장애 — 4xx 로 뭉개면 장애가 입력 오류로 위장된다
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                500
            );
        }
    }

    /**
     * 배송정책 일괄 사용여부 변경
     *
     * @param  ShippingPolicyBulkToggleActiveRequest  $request
     * @return JsonResponse
     */
    public function bulkToggleActive(ShippingPolicyBulkToggleActiveRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $count = $this->shippingPolicyService->bulkToggleActive(
                $validated['ids'],
                $validated['is_active']
            );

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.shipping_policy.bulk_toggled',
                ['updated_count' => $count],
                200,
                ['count' => $count]
            );
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (\Exception $e) {
            // 서버 결함/인프라 장애 — 4xx 로 뭉개면 장애가 입력 오류로 위장된다
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                500
            );
        }
    }

    /**
     * 활성화된 배송정책 목록 조회 (Select 옵션용)
     *
     * @return JsonResponse
     */
    public function activeList(): JsonResponse
    {
        $shippingPolicies = $this->shippingPolicyService->getActiveList();

        $options = $shippingPolicies->map(fn ($policy) => [
            'value' => $policy->id,
            'label' => $policy->getLocalizedName(),
            'countries_display' => $policy->getCountriesWithFlags(),
            'fee_summary' => $policy->getFeeSummary(),
            'is_default' => $policy->is_default,
        ]);

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.shipping_policy.active_list_retrieved',
            $options
        );
    }

    /**
     * 기본 배송정책 설정
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function setDefault(int $id): JsonResponse
    {
        $shippingPolicy = $this->shippingPolicyService->getDetail($id);

        if (! $shippingPolicy) {
            return ResponseHelper::notFound(
                'messages.shipping_policy.not_found',
                [],
                'sirsoft-ecommerce'
            );
        }

        try {
            $updatedPolicy = $this->shippingPolicyService->setDefault($shippingPolicy);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.shipping_policy.set_default_success',
                new ShippingPolicyResource($updatedPolicy)
            );
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (\Exception $e) {
            // 서버 결함/인프라 장애 — 4xx 로 뭉개면 장애가 입력 오류로 위장된다
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                500
            );
        }
    }
}
