<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Admin;

use App\Enums\IdentityPolicyScope;
use App\Enums\IdentityPolicySourceType;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Identity\AdminIdentityPolicyDestroyRequest;
use App\Http\Requests\Identity\AdminIdentityPolicyIndexRequest;
use App\Http\Requests\Identity\AdminIdentityPolicyStoreRequest;
use App\Http\Requests\Identity\AdminIdentityPolicyUpdateRequest;
use App\Models\IdentityPolicy;
use App\Services\IdentityPolicyService;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Ecommerce\Http\Resources\IdentityPolicyCollection;
use Modules\Sirsoft\Ecommerce\Http\Resources\IdentityPolicyResource;

/**
 * 이커머스 환경설정 「본인인증 정책」 탭의 정책 조회·추가·수정·삭제 컨트롤러
 *
 * 정책 표는 코어 것(identity_policies)이지만, 이 탭은 이커머스 몫(`source_identifier = sirsoft-ecommerce`)만 다루고
 * 이커머스 권한(`sirsoft-ecommerce.identity.policies.read`/`.update`)으로 동작한다 — 라우트 미들웨어가 그 권한을
 * 요구하고, 응답 abilities 도 같은 권한으로 판정한다. 검증 규칙과 정책 저장은 코어 관리자 정책 API 와 같은
 * FormRequest·서비스를 그대로 쓴다.
 *
 * 이커머스 몫이 아닌 정책은 이 경로에서 찾을 수 없음(404)으로 답한다. 코어·다른 확장 정책은 각자의 화면과
 * 권한으로만 다룬다.
 *
 * 정책은 출처가 아니라 범위·대상으로 적용되므로, 운영자 정책의 대상도 이커머스 지점으로 묶는다 — 훅·사용자 지정
 * 범위는 `sirsoft-ecommerce.` 로, 경로 범위는 `api.modules.sirsoft-ecommerce.` 로 시작해야 한다. 그렇지 않으면 이커머스 권한만으로
 * 코어 로그인 같은 다른 지점에 본인인증을 걸 수 있다.
 */
class IdentityPolicyController extends AdminBaseController
{
    /** 이 탭이 다루는 정책의 출처 식별자 */
    private const SOURCE_IDENTIFIER = 'sirsoft-ecommerce';

    /**
     * 선언형 정책(source_type != admin)에서 운영자가 고칠 수 있는 칸 — 코어 관리자 정책 API 와 같은 목록.
     * 키·범위·대상은 확장이 발행하는 지점 식별자라 바꾸면 정책이 실제 지점과 어긋난다.
     *
     * @var list<string>
     */
    private const LIMITED_EDITABLE_FIELDS = ['enabled', 'grace_minutes', 'provider_id', 'fail_mode', 'conditions', 'purpose', 'applies_to', 'priority'];

    /**
     * 범위별로 운영자 정책이 겨눌 수 있는 대상 접두어 — 이커머스가 발행하는 훅·경로 이름만 허용한다.
     *
     * @var array<string, string>
     */
    private const OWNED_TARGET_PREFIXES = [
        'hook' => 'sirsoft-ecommerce.',
        'custom' => 'sirsoft-ecommerce.',
        'route' => 'api.modules.sirsoft-ecommerce.',
    ];

    /**
     * @param  IdentityPolicyService  $policyService  코어 본인인증 정책 서비스
     */
    public function __construct(
        protected IdentityPolicyService $policyService,
    ) {}

    /**
     * 이커머스 몫 정책 목록을 조회합니다. 요청이 다른 출처를 적어 보내도 이커머스 몫으로 좁힙니다.
     *
     * @param  AdminIdentityPolicyIndexRequest  $request  검증된 요청 (필터: scope/purpose/source_type/enabled/search)
     * @return JsonResponse 정책 목록 + abilities + 쪽 정보
     */
    public function index(AdminIdentityPolicyIndexRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $filters = array_filter(
            array_intersect_key($validated, array_flip(['scope', 'purpose', 'source_type', 'applies_to', 'fail_mode', 'enabled', 'search'])),
            fn ($v) => $v !== null,
        );
        $filters['source_identifier'] = self::SOURCE_IDENTIFIER;
        $perPage = (int) ($validated['per_page'] ?? 20);

        $paginated = $this->policyService->search($filters, $perPage);
        $collection = (new IdentityPolicyCollection($paginated))->toArray($request);

        return $this->success('common.success', [
            'data' => $collection['data'],
            'abilities' => $collection['abilities'],
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
        ]);
    }

    /**
     * 운영자 정책을 이커머스 몫으로 새로 만듭니다 (source_type=admin, source_identifier=sirsoft-ecommerce 고정).
     *
     * @param  AdminIdentityPolicyStoreRequest  $request  검증된 요청
     * @return JsonResponse 생성된 정책
     */
    public function store(AdminIdentityPolicyStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();

        if (! $this->isOwnedTarget($validated['scope'] ?? null, $validated['target'] ?? null)) {
            return $this->targetNotOwnedError();
        }

        $policy = $this->policyService->createAdminPolicy(
            array_merge($validated, ['source_identifier' => self::SOURCE_IDENTIFIER]),
        );

        return $this->success(
            'common.created',
            (new IdentityPolicyResource($policy))->toArray($request),
            201,
        );
    }

    /**
     * 이커머스 몫 정책을 수정합니다. 선언형 정책은 고칠 수 있는 칸만 반영합니다.
     *
     * @param  AdminIdentityPolicyUpdateRequest  $request  검증된 요청
     * @param  int  $id  정책 ID
     * @return JsonResponse 수정된 정책 또는 오류
     */
    public function update(AdminIdentityPolicyUpdateRequest $request, int $id): JsonResponse
    {
        $policy = $this->findOwnPolicy($id);

        if (! $policy) {
            return $this->notFound('common.not_found');
        }

        $validated = $request->validated();

        if ($policy->source_type !== IdentityPolicySourceType::Admin) {
            $validated = array_intersect_key($validated, array_flip(self::LIMITED_EDITABLE_FIELDS));
        } elseif (! $this->isOwnedTarget(
            $validated['scope'] ?? $policy->scope,
            $validated['target'] ?? $policy->target,
        )) {
            return $this->targetNotOwnedError();
        }

        if (empty($validated)) {
            return $this->error('sirsoft-ecommerce::validation.identity_policy.nothing_to_update', 422);
        }

        if (! $this->policyService->updatePolicy($policy, $validated)) {
            return $this->error('common.failed', 500);
        }

        $policy->refresh();

        return $this->success(
            'common.updated',
            (new IdentityPolicyResource($policy))->toArray($request),
        );
    }

    /**
     * 이커머스 몫 운영자 정책을 삭제합니다. 선언형 정책은 지울 수 없고 비활성으로 대신합니다.
     *
     * @param  AdminIdentityPolicyDestroyRequest  $request  검증된 요청
     * @param  int  $id  정책 ID
     * @return JsonResponse 삭제 결과
     */
    public function destroy(AdminIdentityPolicyDestroyRequest $request, int $id): JsonResponse
    {
        $policy = $this->findOwnPolicy($id);

        if (! $policy) {
            return $this->notFound('common.not_found');
        }

        if ($policy->source_type !== IdentityPolicySourceType::Admin) {
            return $this->forbidden('identity.errors.cannot_delete_system_policy');
        }

        return $this->policyService->deleteAdminPolicy($policy)
            ? $this->success('common.deleted')
            : $this->error('common.failed', 500);
    }

    /**
     * 범위·대상이 이커머스 지점을 가리키는지 판정합니다.
     *
     * @param  IdentityPolicyScope|string|null  $scope  정책 범위
     * @param  string|null  $target  정책 대상
     * @return bool 이커머스 지점이면 true
     */
    private function isOwnedTarget(IdentityPolicyScope|string|null $scope, ?string $target): bool
    {
        $scopeValue = $scope instanceof IdentityPolicyScope ? $scope->value : $scope;
        $prefix = self::OWNED_TARGET_PREFIXES[$scopeValue] ?? null;

        return $prefix !== null && is_string($target) && str_starts_with($target, $prefix);
    }

    /**
     * 대상이 이커머스 지점이 아닐 때의 검증 오류 응답을 만듭니다.
     *
     * @return JsonResponse 422 검증 오류 (target)
     */
    private function targetNotOwnedError(): JsonResponse
    {
        return $this->validationError([
            'target' => [__('sirsoft-ecommerce::validation.identity_policy.target_not_owned')],
        ]);
    }

    /**
     * 이커머스 몫 정책만 찾습니다. 다른 출처의 정책은 없는 것으로 봅니다.
     *
     * @param  int  $id  정책 ID
     * @return IdentityPolicy|null 이커머스 몫 정책 또는 null
     */
    private function findOwnPolicy(int $id): ?IdentityPolicy
    {
        $policy = $this->policyService->findById($id);

        return $policy && $policy->source_identifier === self::SOURCE_IDENTIFIER ? $policy : null;
    }
}
