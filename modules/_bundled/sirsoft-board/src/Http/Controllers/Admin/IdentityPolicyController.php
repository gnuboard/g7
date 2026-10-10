<?php

namespace Modules\Sirsoft\Board\Http\Controllers\Admin;

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
use Modules\Sirsoft\Board\Http\Resources\IdentityPolicyCollection;
use Modules\Sirsoft\Board\Http\Resources\IdentityPolicyResource;

/**
 * 게시판 환경설정 「본인인증 정책」 탭의 정책 조회·추가·수정·삭제 컨트롤러
 *
 * 정책 표는 코어 것(identity_policies)이지만, 이 탭은 게시판 몫(`source_identifier = sirsoft-board`)만 다루고
 * 게시판 권한(`sirsoft-board.identity.policies.read`/`.update`)으로 동작한다 — 라우트 미들웨어가 그 권한을
 * 요구하고, 응답 abilities 도 같은 권한으로 판정한다. 검증 규칙과 정책 저장은 코어 관리자 정책 API 와 같은
 * FormRequest·서비스를 그대로 쓴다.
 *
 * 게시판 몫이 아닌 정책은 이 경로에서 찾을 수 없음(404)으로 답한다. 코어·다른 확장 정책은 각자의 화면과
 * 권한으로만 다룬다.
 *
 * 정책은 출처가 아니라 범위·대상으로 적용되므로, 운영자 정책의 대상도 게시판 지점으로 묶는다 — 훅·사용자 지정
 * 범위는 `sirsoft-board.` 로, 경로 범위는 `api.modules.sirsoft-board.` 로 시작해야 한다. 그렇지 않으면 게시판 권한만으로
 * 코어 로그인 같은 다른 지점에 본인인증을 걸 수 있다. 이 검사는 대상을 새로 지정·변경할 때만 한다 — 7.0.11 에서
 * 자유 입력으로 만든 게시판 밖 대상 정책도 대상을 그대로 둔 사용 토글·다른 칸 수정은 된다(옮기려면 게시판 지점으로만).
 */
class IdentityPolicyController extends AdminBaseController
{
    /** 이 탭이 다루는 정책의 출처 식별자 */
    private const SOURCE_IDENTIFIER = 'sirsoft-board';

    /**
     * 선언형 정책(source_type != admin)에서 운영자가 고칠 수 있는 칸 — 코어 관리자 정책 API 와 같은 목록.
     * 키·범위·대상은 확장이 발행하는 지점 식별자라 바꾸면 정책이 실제 지점과 어긋난다.
     *
     * @var list<string>
     */
    private const LIMITED_EDITABLE_FIELDS = ['enabled', 'grace_minutes', 'provider_id', 'fail_mode', 'conditions', 'purpose', 'applies_to', 'priority'];

    /**
     * 범위별로 운영자 정책이 겨눌 수 있는 대상 접두어 — 게시판이 발행하는 훅·경로 이름만 허용한다.
     *
     * @var array<string, string>
     */
    private const OWNED_TARGET_PREFIXES = [
        'hook' => 'sirsoft-board.',
        'custom' => 'sirsoft-board.',
        'route' => 'api.modules.sirsoft-board.',
    ];

    /**
     * @param  IdentityPolicyService  $policyService  코어 본인인증 정책 서비스
     */
    public function __construct(
        protected IdentityPolicyService $policyService,
    ) {}

    /**
     * 게시판 몫 정책 목록을 조회합니다. 요청이 다른 출처를 적어 보내도 게시판 몫으로 좁힙니다.
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
     * 운영자 정책을 게시판 몫으로 새로 만듭니다 (source_type=admin, source_identifier=sirsoft-board 고정).
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
     * 게시판 몫 정책을 수정합니다. 선언형 정책은 고칠 수 있는 칸만 반영합니다.
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
        } elseif ($this->changesScopeOrTarget($policy, $validated) && ! $this->isOwnedTarget(
            $validated['scope'] ?? $policy->scope,
            $validated['target'] ?? $policy->target,
        )) {
            // 대상을 새로 지정·변경할 때만 소유를 검사한다 — 7.0.11 에서 자유 입력으로 만든 게시판 밖 대상 정책도
            // 사용 토글·다른 칸 수정은 그대로 된다. 대상이 그대로면 게시판 밖 지점이 새로 생기지 않는다.
            return $this->targetNotOwnedError();
        }

        if (empty($validated)) {
            return $this->error('sirsoft-board::validation.identity_policy.nothing_to_update', 422);
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
     * 게시판 몫 운영자 정책을 삭제합니다. 선언형 정책은 지울 수 없고 비활성으로 대신합니다.
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
     * 범위·대상이 게시판 지점을 가리키는지 판정합니다.
     *
     * @param  IdentityPolicyScope|string|null  $scope  정책 범위
     * @param  string|null  $target  정책 대상
     * @return bool 게시판 지점이면 true
     */
    private function isOwnedTarget(IdentityPolicyScope|string|null $scope, ?string $target): bool
    {
        $scopeValue = $scope instanceof IdentityPolicyScope ? $scope->value : $scope;
        $prefix = self::OWNED_TARGET_PREFIXES[$scopeValue] ?? null;

        return $prefix !== null && is_string($target) && str_starts_with($target, $prefix);
    }

    /**
     * 수정 요청이 정책의 범위나 대상을 저장된 값과 다르게 바꾸는지 판정합니다.
     *
     * 편집 창은 범위·대상을 늘 다시 보내므로 키가 있는지가 아니라 값이 바뀌는지로 가른다.
     *
     * @param  IdentityPolicy  $policy  저장된 정책
     * @param  array<string, mixed>  $validated  검증된 수정 값
     * @return bool 범위나 대상이 바뀌면 true
     */
    private function changesScopeOrTarget(IdentityPolicy $policy, array $validated): bool
    {
        $storedScope = $policy->scope instanceof IdentityPolicyScope ? $policy->scope->value : $policy->scope;

        if (array_key_exists('scope', $validated) && (string) $validated['scope'] !== (string) $storedScope) {
            return true;
        }

        return array_key_exists('target', $validated) && (string) $validated['target'] !== (string) $policy->target;
    }

    /**
     * 대상이 게시판 지점이 아닐 때의 검증 오류 응답을 만듭니다.
     *
     * @return JsonResponse 422 검증 오류 (target)
     */
    private function targetNotOwnedError(): JsonResponse
    {
        return $this->validationError([
            'target' => [__('sirsoft-board::validation.identity_policy.target_not_owned')],
        ]);
    }

    /**
     * 게시판 몫 정책만 찾습니다. 다른 출처의 정책은 없는 것으로 봅니다.
     *
     * @param  int  $id  정책 ID
     * @return IdentityPolicy|null 게시판 몫 정책 또는 null
     */
    private function findOwnPolicy(int $id): ?IdentityPolicy
    {
        $policy = $this->policyService->findById($id);

        return $policy && $policy->source_identifier === self::SOURCE_IDENTIFIER ? $policy : null;
    }
}
