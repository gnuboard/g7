<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\Fields\UserPreferenceFieldHandler;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\EcommerceUserProfileRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\SignupCurrencyResolver;

/**
 * 가입 시 locale 기반 통화 부여 리스너 (A4 — D-SIGNUP)
 *
 * 회원가입(core.auth.after_register) 및 관리자 회원생성(core.user.after_create) 시
 * 유저 locale 을 환경설정의 통화별 언어 매핑과 대조해 결제 통화를 user-profile 에 저장한다.
 * locale 출처는 유저 모델의 language 컬럼(가입 시 결정값) — request() 직접 접근 회피(Listener 규율).
 *
 * 모듈 미설치 시 리스너 미등록 → 통화 부여 안 일어남(코어 가입 정상, A5).
 */
class AssignDefaultCurrencyOnRegisterListener implements HookListenerInterface
{
    /**
     * 의존성을 주입받습니다.
     *
     * @param  EcommerceUserProfileRepositoryInterface  $profileRepository  회원 이커머스 프로필 저장소 (결제 통화 저장)
     * @param  SignupCurrencyResolver  $resolver  가입 시 부여할 결제 통화 해석기
     */
    public function __construct(
        protected EcommerceUserProfileRepositoryInterface $profileRepository,
        protected SignupCurrencyResolver $resolver,
    ) {}

    /**
     * 구독할 훅 목록 반환
     *
     * @return array<string, array{method: string, priority: int}> 훅 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            // sync: 가입·관리자 생성 직후 연 회원 상세에 배정값이 보여야 한다 — 큐로 가면 워커가 돌 때까지 빈칸이다.
            // 「이미 설정돼 있으면 유지」 가드로 멱등이다.
            // 회원가입(유저 자가 가입)
            'core.auth.after_register' => ['method' => 'handleRegister', 'priority' => 20, 'sync' => true],
            // 관리자 회원생성
            'core.user.after_create' => ['method' => 'handleAdminCreate', 'priority' => 20, 'sync' => true],
        ];
    }

    /**
     * 기본 핸들러 (method 매핑 사용 — 직접 호출되지 않음)
     *
     * @param  mixed  ...$args  훅 인수
     * @return void 반환값 없음
     */
    public function handle(...$args): void
    {
        // method 매핑(handleRegister/handleAdminCreate)을 사용
    }

    /**
     * 회원가입 후 통화 부여 (core.auth.after_register)
     *
     * @param  User  $user  가입한 회원
     * @param  array  $context  가입 컨텍스트
     * @return void 반환값 없음
     */
    public function handleRegister(User $user, array $context = []): void
    {
        // 가입 화면이 낸 통화는 회원 항목 선언대로 코어가 검증해 가입 트랜잭션 안에서 이미 저장했다
        // (`UserPreferenceFieldHandler`). 그때는 아래 「이미 설정돼 있으면 유지」에 걸려 아무것도 하지 않는다.
        // 여기서 가입 데이터를 다시 읽는 것은 요청 검증을 거치지 않는 내부 가입 호출을 위한 것이다(정식 키 우선, 옛 키 수용).
        $registration = is_array($context['registration_data'] ?? null) ? $context['registration_data'] : [];
        $submitted = $registration[UserPreferenceFieldHandler::CURRENCY_FIELD]
            ?? $registration[UserPreferenceFieldHandler::LEGACY_CURRENCY_FIELD]
            ?? null;
        $this->assignCurrency($user, is_string($submitted) ? $submitted : null);
    }

    /**
     * 관리자 회원생성 후 통화 부여 (core.user.after_create)
     *
     * @param  User  $user  생성된 회원
     * @param  array  $originalData  생성 원본 데이터
     * @return void 반환값 없음
     */
    public function handleAdminCreate(User $user, array $originalData = []): void
    {
        $this->assignCurrency($user);
    }

    /**
     * 제출 통화(우선) 또는 유저 locale 기반 추정으로 통화를 결정해 user-profile 에 저장합니다.
     *
     * 이미 통화가 설정돼 있으면(중복 훅/관리자 명시 지정) 덮어쓰지 않는다.
     * 제출 통화가 등록 통화면 그 값을 우선(D-LOGIN-CUR), 없거나 무효면 locale 추정(D-SIGNUP).
     *
     * @param  User  $user  대상 회원
     * @param  string|null  $submittedCurrency  가입폼이 제출한 통화 코드(우선)
     * @return void 반환값 없음
     */
    protected function assignCurrency(User $user, ?string $submittedCurrency = null): void
    {
        if ($this->profileRepository->getPreferredCurrency($user->id) !== null) {
            return; // 이미 설정됨 (관리자 명시 지정 등) — 보존
        }

        $currency = $this->resolver->isRegistered($submittedCurrency)
            ? $submittedCurrency
            : $this->resolver->resolve($user->language ?? null);

        $this->profileRepository->setPreferredCurrency($user->id, $currency);
    }
}
