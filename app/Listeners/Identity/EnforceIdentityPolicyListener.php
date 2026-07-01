<?php

namespace App\Listeners\Identity;

use App\Contracts\Extension\HookListenerInterface;
use App\Contracts\Repositories\IdentityPolicyRepositoryInterface;
use App\Enums\IdentityOriginType;
use App\Extension\HookManager;
use App\Models\User;
use App\Services\IdentityPolicyService;
use Illuminate\Http\Request;
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
        $coreHooks = static::coreHookTargets();

        // 코어 hook 만 자동발견 시점에 정적 등록한다. 모듈/플러그인 hook scope 정책 target 은
        // 부팅 후반(모듈 로드 완료)·정책 sync·토글 시점에 syncDynamicHookSubscriptions() 가
        // 멱등 (재)바인딩을 전담한다. 자동발견(getSubscribedHooks) 은 모듈 로드보다 먼저 1회만
        // 호출되므로 모듈 target 을 여기서 반환하면 그 시점에 누락된 채 잠겨 결제 직전 가드가
        // 무력화되던 결함이 있었다. 책임을 분리해 코어/동적 hook 의 등록 소유권을 겹치지 않게 한다.
        return array_fill_keys($coreHooks, [
            'method' => 'handle',
            'priority' => 15, // 먼저 실행되는 가드보다 뒤, Notification 등 부작용보다 앞
            'sync' => true,
        ]);
    }

    /**
     * 동적 hook target → 현재 HookManager 에 등록된 enforce 콜백 맵 (멱등 재구독용).
     *
     * @var array<string, callable>
     */
    private static array $dynamicCallbacks = [];

    /**
     * 모듈/플러그인 hook scope 정책의 target 에 enforce 구독을 멱등적으로 (재)바인딩합니다.
     *
     * 배경 (구독 시점 경합 결함):
     *   getSubscribedHooks() 는 코어 리스너 자동발견(CoreServiceProvider::boot 전반부)에서
     *   "한 번" 호출되며, 그 시점은 모듈 로드(boot 후반부)보다 앞선다. 모듈/플러그인 IDV 정책은
     *   모듈 로드 또는 설치/활성화 시점에 비로소 identity_policies 에 적재되므로, 모듈 hook
     *   target(예: sirsoft-ecommerce.checkout.before_payment) 을 자동발견 단계에서 등록하면
     *   그 시점에 누락된 채 잠겨 결제 직전 가드가 영구히 무력화되던 결함이 있었다.
     *
     *   그래서 코어 hook 은 getSubscribedHooks() 가 정적 등록을 전담하고, 동적(모듈/플러그인)
     *   hook target 은 이 메서드가 단독 소유한다. 설정이 바뀌는 시점(모듈/플러그인 로드 완료 후
     *   boot, 정책 sync, 토글, 확장 설치)에만 호출되어 현재 target 목록으로 구독을 재동기화한다.
     *   평상시 훅 발화 경로에는 추가 비용이 없다(런타임 매회 조회 아님).
     *
     * 멱등성: 이전에 이 메서드가 등록한 콜백을 removeAction 으로 먼저 제거한 뒤 현재 target 으로
     * 다시 등록한다. 정적 추적($dynamicCallbacks)이 HookManager 실제 상태와 항상 일치하므로,
     * 반복 호출/테스트 RefreshDatabase 환경에서도 이중 등록(이중 428)이 발생하지 않는다.
     * 코어 hook 과의 중복은 코어 hook 집합을 제외해 회피한다.
     */
    public static function syncDynamicHookSubscriptions(): void
    {
        // 1) 이전에 등록한 동적 콜백 전부 해제 (멱등 재동기화)
        foreach (self::$dynamicCallbacks as $hookName => $callback) {
            HookManager::removeAction($hookName, $callback);
        }
        self::$dynamicCallbacks = [];

        // 2) 코어 hook 은 getSubscribedHooks() 가 소유하므로 동적 등록 대상에서 제외
        $coreHooks = array_fill_keys(static::coreHookTargets(), true);

        // 3) 현재 hook scope 정책 target 으로 재구독
        foreach (static::loadDynamicHookTargets() as $hookName) {
            if (! is_string($hookName) || $hookName === '' || isset($coreHooks[$hookName])) {
                continue;
            }
            if (isset(self::$dynamicCallbacks[$hookName])) {
                continue; // 동일 target 중복 방지
            }

            // getSubscribedHooks() 의 동기(sync) 등록과 동일한 형태 — priority 15 동일.
            $callback = static function (...$args) {
                app(static::class)->handle(...$args);
            };
            self::$dynamicCallbacks[$hookName] = $callback;
            HookManager::addAction($hookName, $callback, 15);
        }
    }

    /**
     * 동적 구독 추적 상태를 초기화하고 등록된 콜백을 해제합니다 (테스트 격리용).
     */
    public static function resetDynamicSubscriptions(): void
    {
        foreach (self::$dynamicCallbacks as $hookName => $callback) {
            HookManager::removeAction($hookName, $callback);
        }
        self::$dynamicCallbacks = [];
    }

    /**
     * 코어가 보장하는 before_* 훅 target 목록 (마이그레이션 전 부팅에도 안전).
     *
     * getSubscribedHooks() 의 정적 등록 대상이자, syncDynamicHookSubscriptions() 가
     * 동적 등록에서 제외할 집합. 두 곳의 SSoT 이므로 한 곳에서만 정의한다.
     *
     * @return list<string> 코어 hook target 목록
     */
    protected static function coreHookTargets(): array
    {
        return [
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
            return app(IdentityPolicyRepositoryInterface::class)->listHookTargets();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * before_* 훅 핸들러. 현재 실행 중인 훅 이름과 매칭되는 hook scope 정책을 enforce 합니다.
     *
     * @param  mixed  ...$args  훅별로 다양한 인자 (첫 인자는 보통 모델/payload)
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
        return HookManager::getRunningHook();
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
            if ($request instanceof Request) {
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
            if ($request instanceof Request) {
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
