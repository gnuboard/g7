<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Enums\PermissionType;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Ecommerce\Services\ShippingCountryResolver;
use Modules\Sirsoft\Ecommerce\Services\SignupCurrencyResolver;
use Modules\Sirsoft\Ecommerce\Services\UserCurrencyService;
use Modules\Sirsoft\Ecommerce\Services\UserShippingCountryService;

/**
 * 코어 회원 저장에 실려 온 결제 통화·배송국가를 검증·저장하는 리스너
 *
 * 회원 수정 화면(관리자 `admin_user_form`, 마이페이지 프로필 수정)은 코어 회원 저장 API
 * (`PUT /api/admin/users/{user}`, `PUT /api/me`) 하나만 호출한다. 이커머스 소유 두 칸
 * (`ecommerce_preferred_currency`, `ecommerce_preferred_shipping_country`)은 그 요청 본문에
 * 함께 실려 오고, 이 리스너가 코어 훅으로 같은 요청 안에서 처리한다.
 *
 * - 검증: `core.user.update_validation_rules`(관리자) · `core.user.update_profile_validation_rules`(본인)
 *   필터로 규칙을 덧붙인다. 오류 키가 필드 이름과 같아서 화면이 그 칸 아래에 오류를 띄운다.
 *   저장된 값과 같은 값은 다시 검증하지 않는다 — 폼은 조회 응답을 그대로 시드하므로, 운영자가
 *   그 통화·국가를 나중에 비활성화했더라도 다른 칸만 고친 저장이 막히지 않게 하기 위해서다.
 *   관리자 경로는 값이 바뀔 때만 이커머스 권한(`user-currency.manage`·`user-shipping-country.manage`)을 요구한다.
 * - 분리: `core.user.filter_update_data` 에서 두 키를 코어 데이터에서 걷어낸다 — 코어 users 테이블에는
 *   이 컬럼이 없고, 코어 저장이 모듈 키를 알 필요도 없다.
 * - 저장: `core.user.after_update`(동기)에서 원본 데이터의 두 값을 저장한다. 코어 저장이 커밋된
 *   뒤에만 반영되고, 요청 스레드 안에서 끝나므로 저장 응답 직후의 재조회가 새 값을 본다(큐로 미루지 않는다).
 *   다른 회원을 바꾸는 관리자 저장은 기존 서비스의 관리자 경로를 태워 변경 훅
 *   (`sirsoft-ecommerce.admin.user_currency.changed` 등)을 그대로 발화한다.
 *
 * 등록(`POST /api/admin/users`) 경로는 다루지 않는다 — 등록 화면에는 두 칸이 보이지 않고,
 * 생성 직후 기본값 부여는 `AssignDefault*OnRegisterListener` 가 맡는다.
 */
class UserPreferenceCoreSaveListener implements HookListenerInterface
{
    /**
     * 결제 통화 필드 이름 (코어 회원 저장 요청 본문 키)
     */
    public const CURRENCY_FIELD = 'ecommerce_preferred_currency';

    /**
     * 배송국가 필드 이름 (코어 회원 저장 요청 본문 키)
     */
    public const SHIPPING_COUNTRY_FIELD = 'ecommerce_preferred_shipping_country';

    /**
     * 관리자가 다른 회원의 결제 통화를 바꿀 때 필요한 권한
     */
    private const CURRENCY_PERMISSION = 'sirsoft-ecommerce.user-currency.manage';

    /**
     * 관리자가 다른 회원의 배송국가를 바꿀 때 필요한 권한
     */
    private const SHIPPING_COUNTRY_PERMISSION = 'sirsoft-ecommerce.user-shipping-country.manage';

    /**
     * @param  UserCurrencyService  $currencyService  회원 결제 통화 서비스
     * @param  UserShippingCountryService  $shippingCountryService  회원 배송국가 서비스
     * @param  SignupCurrencyResolver  $currencyResolver  선택 가능 통화 판정기
     * @param  ShippingCountryResolver  $shippingCountryResolver  배송 가능 국가 판정기
     */
    public function __construct(
        private UserCurrencyService $currencyService,
        private UserShippingCountryService $shippingCountryService,
        private SignupCurrencyResolver $currencyResolver,
        private ShippingCountryResolver $shippingCountryResolver,
    ) {}

    /**
     * 구독할 훅 목록 반환
     *
     * @return array<string, array{method: string, priority: int, type?: string, sync?: bool}> 훅 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            // 관리자 회원 수정 검증 규칙
            'core.user.update_validation_rules' => [
                'method' => 'addAdminUpdateRules',
                'priority' => 20,
                'type' => 'filter',
            ],
            // 본인 프로필 수정 검증 규칙
            'core.user.update_profile_validation_rules' => [
                'method' => 'addProfileUpdateRules',
                'priority' => 20,
                'type' => 'filter',
            ],
            // 코어 저장 데이터에서 모듈 키 분리
            'core.user.filter_update_data' => [
                'method' => 'stripPreferenceFields',
                'priority' => 20,
                'type' => 'filter',
            ],
            // 코어 저장 커밋 후 모듈 값 저장 — 저장 직후 재조회가 새 값을 보도록 요청 스레드에서 동기 실행
            'core.user.after_update' => [
                'method' => 'persistPreferences',
                'priority' => 20,
                'sync' => true,
            ],
        ];
    }

    /**
     * 기본 핸들러 (getSubscribedHooks 의 method 매핑 사용)
     *
     * @param  mixed  ...$args  훅 인수
     * @return void
     */
    public function handle(...$args): void
    {
        // method 매핑을 사용하므로 직접 호출되지 않음
    }

    /**
     * 관리자 회원 수정 검증 규칙에 결제 통화·배송국가 규칙을 덧붙입니다. (filter 훅)
     *
     * 대상 회원은 라우트 모델 바인딩(`{user}`)에서 얻고, 값이 바뀔 때만 이커머스 관리 권한을 요구합니다.
     *
     * @param  array<string, mixed>  $rules  코어가 만든 검증 규칙
     * @param  mixed  $request  검증 중인 FormRequest (코어가 두 번째 인자로 넘김)
     * @return array<string, mixed> 두 필드 규칙이 추가된 검증 규칙
     */
    public function addAdminUpdateRules(array $rules, mixed $request = null): array
    {
        $target = $request instanceof Request ? $request->route('user') : null;

        return $this->appendRules(
            $rules,
            $target instanceof User ? $target : null,
            $request instanceof Request ? $request->user() : null,
            true,
        );
    }

    /**
     * 본인 프로필 수정 검증 규칙에 결제 통화·배송국가 규칙을 덧붙입니다. (filter 훅)
     *
     * 대상은 로그인한 본인이며, 본인 선호값이라 별도 권한을 요구하지 않습니다.
     *
     * @param  array<string, mixed>  $rules  코어가 만든 검증 규칙
     * @param  mixed  $request  검증 중인 FormRequest (코어가 두 번째 인자로 넘김)
     * @return array<string, mixed> 두 필드 규칙이 추가된 검증 규칙
     */
    public function addProfileUpdateRules(array $rules, mixed $request = null): array
    {
        $self = $request instanceof Request ? $request->user() : null;

        return $this->appendRules($rules, $self instanceof User ? $self : null, null, false);
    }

    /**
     * 코어 회원 저장 데이터에서 이커머스 소유 키를 걷어냅니다. (filter 훅)
     *
     * 값은 `core.user.after_update` 가 넘기는 원본 데이터에서 다시 읽으므로 여기서 보관하지 않습니다.
     *
     * @param  array<string, mixed>  $data  코어가 저장할 회원 데이터
     * @param  User|null  $user  수정 대상 회원
     * @return array<string, mixed> 이커머스 키가 제거된 데이터
     */
    public function stripPreferenceFields(array $data, ?User $user = null): array
    {
        unset($data[self::CURRENCY_FIELD], $data[self::SHIPPING_COUNTRY_FIELD]);

        return $data;
    }

    /**
     * 코어 저장이 끝난 뒤 원본 데이터에 실려 온 결제 통화·배송국가를 저장합니다. (action 훅, 동기)
     *
     * 비어 있거나 저장된 값과 같으면 아무것도 하지 않습니다. 검증을 거치지 않은 내부 호출에 대비해
     * 선택 가능 여부를 한 번 더 확인합니다. 다른 회원을 바꾸는 저장(관리자)은 서비스의 관리자 경로를
     * 태워 변경 훅을 발화하고, 본인 저장은 값만 바꿉니다.
     *
     * @param  User  $user  수정된 회원
     * @param  array<string, mixed>  $originalData  필터 적용 전 원본 저장 데이터
     * @param  array<string, mixed>  $snapshot  수정 전 회원 스냅샷 (사용하지 않음)
     * @return void
     */
    public function persistPreferences(User $user, array $originalData = [], array $snapshot = []): void
    {
        $actorId = Auth::id();
        $byAdmin = $actorId !== null && (int) $actorId !== (int) $user->id;

        $currency = $this->normalizeCode($originalData[self::CURRENCY_FIELD] ?? null, false);
        if ($currency !== null
            && $currency !== $this->currencyService->getPreferredCurrency($user->id)
            && $this->currencyResolver->isRegistered($currency)) {
            $byAdmin
                ? $this->currencyService->changeUserCurrencyByAdmin($user->id, $currency)
                : $this->currencyService->setPreferredCurrency($user->id, $currency);
        }

        $country = $this->normalizeCode($originalData[self::SHIPPING_COUNTRY_FIELD] ?? null, true);
        if ($country !== null
            && $country !== $this->shippingCountryService->getPreferredShippingCountry($user->id)
            && $this->shippingCountryResolver->isAllowed($country)) {
            $byAdmin
                ? $this->shippingCountryService->changeUserShippingCountryByAdmin($user->id, $country)
                : $this->shippingCountryService->setPreferredShippingCountry($user->id, $country);
        }
    }

    /**
     * 검증 규칙 배열에 두 필드 규칙을 추가합니다.
     *
     * @param  array<string, mixed>  $rules  기존 검증 규칙
     * @param  User|null  $target  수정 대상 회원 (저장값 대조용, 모르면 null)
     * @param  mixed  $actor  요청한 사용자 (권한 확인용)
     * @param  bool  $requirePermission  값이 바뀔 때 이커머스 관리 권한을 요구할지 여부
     * @return array<string, mixed> 두 필드 규칙이 추가된 검증 규칙
     */
    private function appendRules(array $rules, ?User $target, mixed $actor, bool $requirePermission): array
    {
        $rules[self::CURRENCY_FIELD] = [
            'nullable',
            'string',
            function (string $attribute, mixed $value, Closure $fail) use ($target, $actor, $requirePermission): void {
                $code = $this->normalizeCode($value, false);
                if ($code === null) {
                    return;
                }
                if ($target !== null && $code === $this->currencyService->getPreferredCurrency($target->id)) {
                    return; // 변경 없음 — 다른 칸만 고친 저장을 막지 않는다
                }
                if ($requirePermission && ! $this->actorHasPermission($actor, self::CURRENCY_PERMISSION)) {
                    $fail(__('sirsoft-ecommerce::validation.custom.user_currency.forbidden'));

                    return;
                }
                if (! $this->currencyResolver->isRegistered($code)) {
                    $fail(__('sirsoft-ecommerce::validation.custom.user_currency.invalid'));
                }
            },
        ];

        $rules[self::SHIPPING_COUNTRY_FIELD] = [
            'nullable',
            'string',
            function (string $attribute, mixed $value, Closure $fail) use ($target, $actor, $requirePermission): void {
                $code = $this->normalizeCode($value, true);
                if ($code === null) {
                    return;
                }
                if ($target !== null && $code === $this->shippingCountryService->getPreferredShippingCountry($target->id)) {
                    return; // 변경 없음 — 해외배송을 끈 뒤에도 다른 칸만 고친 저장을 막지 않는다
                }
                if ($requirePermission && ! $this->actorHasPermission($actor, self::SHIPPING_COUNTRY_PERMISSION)) {
                    $fail(__('sirsoft-ecommerce::validation.custom.user_shipping_country.forbidden'));

                    return;
                }
                if (! $this->shippingCountryResolver->isAllowed($code)) {
                    $fail(__('sirsoft-ecommerce::validation.custom.user_shipping_country.invalid'));
                }
            },
        ];

        return $rules;
    }

    /**
     * 요청한 사용자가 이커머스 관리자 권한을 가졌는지 확인합니다.
     *
     * @param  mixed  $actor  요청한 사용자
     * @param  string  $permission  권한 식별자
     * @return bool 권한 보유 시 true
     */
    private function actorHasPermission(mixed $actor, string $permission): bool
    {
        return $actor instanceof User && $actor->hasPermission($permission, PermissionType::Admin);
    }

    /**
     * 입력값을 코드 문자열로 정규화합니다. 비어 있거나 문자열이 아니면 null 입니다.
     *
     * @param  mixed  $value  입력값
     * @param  bool  $upper  대문자로 맞출지 여부 (국가 코드)
     * @return string|null 정규화된 코드 또는 null
     */
    private function normalizeCode(mixed $value, bool $upper): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $code = trim($value);

        return $upper ? strtoupper($code) : $code;
    }
}
