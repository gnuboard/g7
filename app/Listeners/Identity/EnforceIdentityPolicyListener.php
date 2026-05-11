<?php

namespace App\Listeners\Identity;

use App\Contracts\Extension\HookListenerInterface;
use App\Contracts\Repositories\IdentityPolicyRepositoryInterface;
use App\Enums\IdentityOriginType;
use App\Models\User;
use App\Services\IdentityPolicyService;
use Illuminate\Support\Facades\Auth;

/**
 * 훅 기반 IDV 정책 강제 Listener.
 *
 * before_* 훅에 자동 구독되어, 훅 이름이 scope=hook 정책의 target 과 일치하면 enforce() 호출.
 *
 * 라우트 미들웨어로 커버 안 되는 내부 호출 경로 (Service/잡/Artisan) 까지 일괄 보호.
 *
 * @since 7.0.0-beta.4
 */
class EnforceIdentityPolicyListener implements HookListenerInterface
{
    /**
     * @param  IdentityPolicyService  $policyService  정책 유스케이스 Service
     * @param  IdentityPolicyRepositoryInterface  $policyRepository  정책 Repository
     */
    public function __construct(
        protected IdentityPolicyService $policyService,
        protected IdentityPolicyRepositoryInterface $policyRepository,
    ) {}

    /**
     * 구독 훅 메타데이터 (scope=hook 정책 대상 before_* 훅 일괄 등록).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        // 코어가 보장하는 before_* 훅 (마이그레이션 전 부팅에도 안전).
        $coreHooks = [
            'core.auth.before_reset_password',
            'core.user.before_update',
            'core.user.before_delete',
            'core.user.before_withdraw',
            'core.attachment.before_delete',
            'core.activity_log.before_delete',
            'core.activity_log.before_delete_many',
            'core.menu.before_update_order',
            'core.dashboard.before_stats',
            'core.dashboard.before_resources',
            'core.layout_preview.before_generate',
            'core.attachment.before_download_action',
        ];

        // 모듈/플러그인이 declarative getter 로 등록한 hook scope 정책의 target 을 동적 구독.
        // 부팅 시점에 identity_policies 테이블이 이미 sync 되어 있으므로 자동 작동.
        $dynamicHooks = static::loadDynamicHookTargets();

        $hookNames = array_values(array_unique(array_merge($coreHooks, $dynamicHooks)));

        return array_fill_keys($hookNames, [
            'method' => 'handle',
            'priority' => 15, // 먼저 실행되는 가드보다 뒤, Notification 등 부작용보다 앞
            'sync' => true,
        ]);
    }

    /**
     * identity_policies 테이블에서 scope='hook' 정책의 target 목록을 추출합니다.
     *
     * boot context (static getSubscribedHooks 호출 시점) 에서 동작해야 하므로 컨테이너에서
     * Repository 를 즉석 해석합니다. 마이그레이션 전이거나 DB 미연결 환경에서 Repository 가
     * 빈 배열을 반환하도록 보장합니다 (IdentityPolicyRepository::listHookTargets).
     *
     * @return list<string> 동적 hook target 목록
     */
    protected static function loadDynamicHookTargets(): array
    {
        try {
            return app(\App\Contracts\Repositories\IdentityPolicyRepositoryInterface::class)->listHookTargets();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * before_* 훅 핸들러. 현재 실행 중인 훅 이름과 매칭되는 hook scope 정책을 enforce 합니다.
     *
     * @param  mixed  ...$args  훅별로 다양한 인자 (첫 인자는 보통 모델/payload)
     * @return void
     */
    public function handle(...$args): void
    {
        $hookName = $this->resolveCurrentHook();
        if ($hookName === null) {
            return;
        }

        $policies = $this->policyRepository->resolveByScopeTarget('hook', $hookName);
        if ($policies->isEmpty()) {
            return;
        }

        $context = [
            'origin_type' => IdentityOriginType::Hook->value,
            'origin_identifier' => $hookName,
            'changed_fields' => $this->extractChangedFields($args),
            // verify 직후 retry 흐름: IdentityGuardInterceptor 가 원 요청 body 에 부착한
            // verification_token 을 enforce() 의 우회 검사로 전달 (grace_minutes=0 정책 무한 루프 차단).
            'verification_token' => $this->resolveVerificationToken(),
            // 428 응답에 원 요청 정보 포함 — IdentityGuardInterceptor 가 verify 성공 시
            // return_request.url 에 token 을 부착해 재실행한다. 누락 시 인터셉터가 재시도를
            // 시작하지 못해 사용자가 인증을 마쳐도 본인확인 토스트가 반복되는 회귀 발생.
            'return_request' => $this->resolveReturnRequest(),
        ];

        foreach ($policies as $policy) {
            $context['origin_policy_key'] = $policy->key;
            $this->policyService->enforce($policy, $this->resolveUser($args), $context);
        }
    }

    /**
     * 현재 실행 중인 훅 이름을 HookManager 의 runtime stack 에서 조회합니다.
     *
     * @return string|null 훅 이름 또는 null
     */
    protected function resolveCurrentHook(): ?string
    {
        return \App\Extension\HookManager::getRunningHook();
    }

    /**
     * 현재 행위자(actor) 를 추출합니다. IDV 의 "verify 해야 할 주체" 는 행위자이므로
     * 인증된 Auth::user() 를 우선합니다. 게스트 흐름(예: 비로그인 비밀번호 재설정 요청)
     * 에서만 훅 인자에 담긴 대상 User 로 폴백합니다.
     *
     * 회귀 차단: 관리자가 다른 사용자를 삭제하는 흐름에서 args[0] 의 target 사용자
     * (일반 유저)를 반환하면 applies_to=admin 정책이 isAdminContext(target)=false 로
     * 평가돼 우회되던 회귀.
     *
     * @param  array<int, mixed>  $args  훅 호출 시 전달된 가변 인자
     * @return User|null 추출된 행위자 또는 null
     */
    protected function resolveUser(array $args): ?User
    {
        $authUser = Auth::user();
        if ($authUser instanceof User) {
            return $authUser;
        }

        foreach ($args as $arg) {
            if ($arg instanceof User) {
                return $arg;
            }
        }

        return null;
    }

    /**
     * 현재 HTTP 요청의 verification_token 을 조회합니다 (없거나 비-HTTP 컨텍스트면 빈 문자열).
     *
     * IdentityGuardInterceptor 가 IDV verify 직후 원 요청을 재실행할 때 body/query 에 부착하는
     * 토큰을 enforce() 의 우회 검사 키로 전달하기 위함. CLI/큐 흐름에서는 request() 바인딩이 없을
     * 수 있으므로 안전하게 캐치한다.
     *
     * @return string verification_token 또는 빈 문자열
     */
    protected function resolveVerificationToken(): string
    {
        try {
            $request = app('request');
            if ($request instanceof \Illuminate\Http\Request) {
                return (string) $request->input('verification_token', '');
            }
        } catch (\Throwable) {
            // CLI/큐 컨텍스트 — request 바인딩 부재
        }

        return '';
    }

    /**
     * 현재 HTTP 요청의 method/url 을 return_request 형태로 반환합니다.
     *
     * 428 응답에 포함되어 IdentityGuardInterceptor 가 verify 성공 후 원 요청을 재실행할 때
     * 사용. CLI/큐 컨텍스트에서는 null.
     *
     * @return array{method: string, url: string}|null
     */
    protected function resolveReturnRequest(): ?array
    {
        try {
            $request = app('request');
            if ($request instanceof \Illuminate\Http\Request) {
                return [
                    'method' => $request->getMethod(),
                    'url' => $request->fullUrl(),
                ];
            }
        } catch (\Throwable) {
            // CLI/큐 컨텍스트 — request 바인딩 부재
        }

        return null;
    }

    /**
     * 훅 인자에서 changed_fields 를 추출합니다 (정책 conditions.changed_fields 매칭용).
     *
     * @param  array<int, mixed>  $args  훅 인자
     * @return array<int, string> 변경 필드명 배열
     */
    protected function extractChangedFields(array $args): array
    {
        foreach ($args as $arg) {
            if (is_array($arg) && isset($arg['changed_fields'])) {
                return (array) $arg['changed_fields'];
            }
            if (is_object($arg) && method_exists($arg, 'getDirty')) {
                return array_keys($arg->getDirty());
            }
        }

        return [];
    }
}
