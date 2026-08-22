<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;
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
            // 회원가입(유저 자가 가입)
            'core.auth.after_register' => ['method' => 'handleRegister', 'priority' => 20],
            // 관리자 회원생성
            'core.user.after_create' => ['method' => 'handleAdminCreate', 'priority' => 20],
            // 가입 검증 규칙: preferred_currency 를 등록 통화로 제한(무효 통화 강제 전송 차단)
            'core.auth.register_validation_rules' => [
                'method' => 'addCurrencyRule',
                'type' => 'filter',
                'priority' => 20,
            ],
        ];
    }

    /**
     * 가입 검증 규칙에 결제 통화(preferred_currency) 규칙을 추가합니다. (filter 훅)
     *
     * 선택값이라 nullable. 값이 있으면 등록된 선택 가능 통화여야 한다(무효 통화 강제 전송 차단).
     *
     * @param  array<string, mixed>  $rules  기존 가입 검증 규칙
     * @return array<string, mixed> 통화 규칙이 추가된 규칙
     */
    public function addCurrencyRule(array $rules): array
    {
        $rules['preferred_currency'] = [
            'nullable',
            'string',
            function (string $attribute, mixed $value, \Closure $fail): void {
                if (! $this->resolver->isRegistered(is_string($value) ? $value : null)) {
                    $fail(__('sirsoft-ecommerce::validation.custom.user_currency.invalid'));
                }
            },
        ];

        return $rules;
    }

    /**
     * 기본 핸들러 (method 매핑 사용 — 직접 호출되지 않음)
     *
     * @param  mixed  ...$args  훅 인수
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
     */
    public function handleRegister(User $user, array $context = []): void
    {
        // 가입폼이 제출한 통화(registration_data.preferred_currency)가 등록 통화면 우선(D-LOGIN-CUR),
        // 없거나 무효면 locale 기반 추정(D-SIGNUP)으로 폴백.
        $submitted = $context['registration_data']['preferred_currency'] ?? null;
        $this->assignCurrency($user, is_string($submitted) ? $submitted : null);
    }

    /**
     * 관리자 회원생성 후 통화 부여 (core.user.after_create)
     *
     * @param  User  $user  생성된 회원
     * @param  array  $originalData  생성 원본 데이터
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
