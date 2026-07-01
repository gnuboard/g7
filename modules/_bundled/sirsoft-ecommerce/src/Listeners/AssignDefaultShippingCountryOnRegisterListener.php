<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;
use Illuminate\Support\Facades\Request;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\EcommerceUserProfileRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\ShippingCountryResolver;

/**
 * 가입 시 배송국가 부여 리스너 (MP08 후속 — D6)
 *
 * 회원가입(core.auth.after_register) 및 관리자 회원생성(core.user.after_create) 시
 * 배송국가를 user-profile 에 저장한다(AssignDefaultCurrencyOnRegisterListener 미러).
 * 우선순위: ① 가입폼 제출값(비회원 세션 선택) → ② GeoIP → ③ default_country.
 *
 * 모듈 미설치 시 리스너 미등록 → 배송국가 부여 안 일어남(코어 가입 정상).
 */
class AssignDefaultShippingCountryOnRegisterListener implements HookListenerInterface
{
    public function __construct(
        protected EcommerceUserProfileRepositoryInterface $profileRepository,
        protected ShippingCountryResolver $resolver,
    ) {}

    /**
     * 구독할 훅 목록 반환
     *
     * @return array<string, array{method: string, priority?: int, type?: string}> 훅 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            // 회원가입(유저 자가 가입)
            'core.auth.after_register' => ['method' => 'handleRegister', 'priority' => 20],
            // 관리자 회원생성
            'core.user.after_create' => ['method' => 'handleAdminCreate', 'priority' => 20],
            // 가입 검증 규칙: preferred_shipping_country 를 활성 국가로 제한(무효 국가 강제 전송 차단)
            'core.auth.register_validation_rules' => [
                'method' => 'addShippingCountryRule',
                'type' => 'filter',
                'priority' => 20,
            ],
        ];
    }

    /**
     * 가입 검증 규칙에 배송국가(preferred_shipping_country) 규칙을 추가합니다. (filter 훅)
     *
     * 선택값이라 nullable. 값이 있으면 활성 배송가능 국가여야 한다.
     *
     * @param  array<string, mixed>  $rules  기존 가입 검증 규칙
     * @return array<string, mixed> 배송국가 규칙이 추가된 규칙
     */
    public function addShippingCountryRule(array $rules): array
    {
        $rules['preferred_shipping_country'] = [
            'nullable',
            'string',
            function (string $attribute, mixed $value, \Closure $fail): void {
                if (! $this->resolver->isAllowed(is_string($value) ? $value : null)) {
                    $fail(__('sirsoft-ecommerce::validation.custom.user_shipping_country.invalid'));
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
     * 회원가입 후 배송국가 부여 (core.auth.after_register)
     *
     * @param  User  $user  가입한 회원
     * @param  array  $context  가입 컨텍스트
     */
    public function handleRegister(User $user, array $context = []): void
    {
        $submitted = $context['registration_data']['preferred_shipping_country'] ?? null;
        $this->assignShippingCountry($user, is_string($submitted) ? $submitted : null);
    }

    /**
     * 관리자 회원생성 후 배송국가 부여 (core.user.after_create)
     *
     * @param  User  $user  생성된 회원
     * @param  array  $originalData  생성 원본 데이터
     */
    public function handleAdminCreate(User $user, array $originalData = []): void
    {
        $this->assignShippingCountry($user);
    }

    /**
     * 제출 배송국가(우선) 또는 GeoIP/default 로 배송국가를 결정해 user-profile 에 저장합니다.
     *
     * 이미 배송국가가 설정돼 있으면(중복 훅/관리자 명시 지정) 덮어쓰지 않는다.
     * 제출값이 활성 국가면 그 값을 우선, 없거나 무효면 GeoIP→default(resolve) 폴백.
     *
     * @param  User  $user  대상 회원
     * @param  string|null  $submittedCountry  가입폼이 제출한 국가 코드(우선)
     */
    protected function assignShippingCountry(User $user, ?string $submittedCountry = null): void
    {
        if ($this->profileRepository->getPreferredShippingCountry($user->id) !== null) {
            return; // 이미 설정됨 (관리자 명시 지정 등) — 보존
        }

        $country = $this->resolver->isAllowed($submittedCountry)
            ? strtoupper((string) $submittedCountry)
            : $this->resolver->resolve(null, Request::ip());

        $this->profileRepository->setPreferredShippingCountry($user->id, $country);
    }
}
