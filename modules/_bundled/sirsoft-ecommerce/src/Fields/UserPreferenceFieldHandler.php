<?php

namespace Modules\Sirsoft\Ecommerce\Fields;

use App\Enums\PermissionType;
use App\Fields\Contracts\FieldValueHandlerInterface;
use App\Fields\FieldContext;
use App\Models\FieldDefinition;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\EcommerceUserProfileRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\ShippingCountryResolver;
use Modules\Sirsoft\Ecommerce\Services\SignupCurrencyResolver;
use Modules\Sirsoft\Ecommerce\Services\UserCurrencyService;
use Modules\Sirsoft\Ecommerce\Services\UserShippingCountryService;

/**
 * 회원 항목(core.user)으로 선언한 결제 통화와 배송국가의 값 저장소 어댑터.
 *
 * 값은 종전과 같이 `ecommerce_user_profiles` 의 회원별 한 행에 둔다. 코어가 회원 저장(가입, 관리자 수정, 내 정보 수정)의
 * 같은 트랜잭션 안에서 바뀐 칸만 `write()` 로 넘긴다. 요청에 실리지 않은 칸은 넘어오지 않고 저장된 값이 그대로 남는다.
 *
 * - 선택지는 요청 시점에 `UserPreferenceFieldDefinitionListener` 가 채운다(사이트 설정에 따라 달라진다).
 *   고를 수 없는 값은 유형 규칙(선택지 안의 값)이 막는다.
 * - 관리자가 다른 회원의 값을 바꾸려면 이커머스 권한이 있어야 한다(`extraRules()`). 저장된 값과 같은 값은 묻지 않는다.
 *   폼은 조회 응답을 그대로 실어 보내므로, 권한 없는 관리자가 다른 칸만 고친 저장이 막히지 않게 하기 위해서다.
 * - 관리자가 다른 회원의 값을 바꾼 저장은 서비스의 관리자 경로를 태워 변경 훅
 *   (`sirsoft-ecommerce.admin.user_currency.changed`, `sirsoft-ecommerce.admin.user_shipping_country.changed`)을 발화한다.
 */
class UserPreferenceFieldHandler implements FieldValueHandlerInterface
{
    /**
     * 결제 통화 항목 키 (회원 저장 요청과 응답의 최상위 키)
     */
    public const CURRENCY_FIELD = 'ecommerce_preferred_currency';

    /**
     * 배송국가 항목 키 (회원 저장 요청과 응답의 최상위 키)
     */
    public const SHIPPING_COUNTRY_FIELD = 'ecommerce_preferred_shipping_country';

    /**
     * 가입 요청이 종전에 쓰던 결제 통화 키 (옛 키로 수용)
     */
    public const LEGACY_CURRENCY_FIELD = 'preferred_currency';

    /**
     * 가입 요청이 종전에 쓰던 배송국가 키 (옛 키로 수용)
     */
    public const LEGACY_SHIPPING_COUNTRY_FIELD = 'preferred_shipping_country';

    /**
     * 관리자가 다른 회원의 결제 통화를 바꿀 때 필요한 권한
     */
    public const CURRENCY_PERMISSION = 'sirsoft-ecommerce.user-currency.manage';

    /**
     * 관리자가 다른 회원의 배송국가를 바꿀 때 필요한 권한
     */
    public const SHIPPING_COUNTRY_PERMISSION = 'sirsoft-ecommerce.user-shipping-country.manage';

    /**
     * @param  UserCurrencyService  $currencyService  회원 결제 통화 서비스
     * @param  UserShippingCountryService  $shippingCountryService  회원 배송국가 서비스
     * @param  SignupCurrencyResolver  $currencyResolver  선택 가능 통화 판정기
     * @param  ShippingCountryResolver  $shippingCountryResolver  배송 가능 국가 판정기
     * @param  EcommerceUserProfileRepositoryInterface  $profileRepository  회원 쇼핑 프로필 저장소 (파기)
     */
    public function __construct(
        private UserCurrencyService $currencyService,
        private UserShippingCountryService $shippingCountryService,
        private SignupCurrencyResolver $currencyResolver,
        private ShippingCountryResolver $shippingCountryResolver,
        private EcommerceUserProfileRepositoryInterface $profileRepository,
    ) {}

    /**
     * 회원의 결제 통화 또는 배송국가를 읽습니다.
     *
     * @param  Model  $owner  회원
     * @param  FieldDefinition  $definition  항목 정의
     * @return string|null 저장된 코드 (정해진 적이 없으면 null)
     */
    public function read(Model $owner, FieldDefinition $definition): mixed
    {
        return match ($definition->key) {
            self::CURRENCY_FIELD => $this->currencyService->getPreferredCurrency((int) $owner->getKey()),
            self::SHIPPING_COUNTRY_FIELD => $this->shippingCountryService->getPreferredShippingCountry((int) $owner->getKey()),
            default => null,
        };
    }

    /**
     * 회원의 결제 통화 또는 배송국가를 저장합니다.
     *
     * 빈 값은 저장하지 않는다(두 값은 한 번 정해지면 비울 수 없다). 요청 검증을 거치지 않은 내부 호출에 대비해
     * 고를 수 있는 값인지 한 번 더 본다.
     *
     * @param  Model  $owner  회원
     * @param  FieldDefinition  $definition  항목 정의
     * @param  mixed  $value  정규화한 값 (비우면 null)
     * @param  FieldContext  $context  요청 문맥
     * @return void 반환값 없음
     */
    public function write(Model $owner, FieldDefinition $definition, mixed $value, FieldContext $context): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $userId = (int) $owner->getKey();
        $byAdmin = $this->isAdminChange($owner, $context);

        if ($definition->key === self::CURRENCY_FIELD) {
            $currency = trim($value);
            if (! $this->currencyResolver->isRegistered($currency)) {
                return;
            }
            $byAdmin
                ? $this->currencyService->changeUserCurrencyByAdmin($userId, $currency)
                : $this->currencyService->setPreferredCurrency($userId, $currency);

            return;
        }

        if ($definition->key === self::SHIPPING_COUNTRY_FIELD) {
            $country = strtoupper(trim($value));
            if (! $this->shippingCountryResolver->isAllowed($country)) {
                return;
            }
            $byAdmin
                ? $this->shippingCountryService->changeUserShippingCountryByAdmin($userId, $country)
                : $this->shippingCountryService->setPreferredShippingCountry($userId, $country);
        }
    }

    /**
     * 관리자 수정에서 값이 바뀔 때 이커머스 권한을 요구하는 규칙을 반환합니다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @param  string  $attribute  요청 속성 경로
     * @param  FieldContext  $context  요청 문맥
     * @return array<int, mixed> 속성에 더할 규칙 (관리자 수정이 아니면 빈 배열)
     */
    public function extraRules(FieldDefinition $definition, string $attribute, FieldContext $context): array
    {
        if ($context->surface !== FieldContext::SURFACE_ADMIN_UPDATE || ! $context->owner instanceof Model) {
            return [];
        }

        [$permission, $message] = match ($definition->key) {
            self::CURRENCY_FIELD => [self::CURRENCY_PERMISSION, 'sirsoft-ecommerce::validation.custom.user_currency.forbidden'],
            self::SHIPPING_COUNTRY_FIELD => [self::SHIPPING_COUNTRY_PERMISSION, 'sirsoft-ecommerce::validation.custom.user_shipping_country.forbidden'],
            default => [null, null],
        };
        if ($permission === null) {
            return [];
        }

        $owner = $context->owner;
        $actor = $context->actor;

        return [function (string $attr, mixed $value, Closure $fail) use ($definition, $owner, $actor, $permission, $message): void {
            if (! is_string($value) || trim($value) === '') {
                return;
            }
            if ($this->sameCode($definition, $value, $this->read($owner, $definition))) {
                return; // 변경 없음 — 다른 칸만 고친 저장을 막지 않는다
            }
            if (! $actor instanceof User || ! $actor->hasPermission($permission, PermissionType::Admin)) {
                $fail(__($message));
            }
        }];
    }

    /**
     * 회원의 쇼핑 프로필 행을 지웁니다 (탈퇴 회원 파기, 회원 삭제).
     *
     * 두 항목이 차례로 불러도 안전하다(행이 없으면 아무 일도 하지 않는다).
     *
     * @param  Model  $owner  회원
     * @param  FieldDefinition  $definition  항목 정의
     * @return void 반환값 없음
     */
    public function purge(Model $owner, FieldDefinition $definition): void
    {
        $this->profileRepository->deleteByUserId((int) $owner->getKey());
    }

    /**
     * 관리자가 다른 회원의 값을 바꾸는 저장인지 반환합니다.
     *
     * @param  Model  $owner  값의 소유 회원
     * @param  FieldContext  $context  요청 문맥
     * @return bool 관리자 수정 표면이고 요청자가 소유 회원이 아니면 true
     */
    private function isAdminChange(Model $owner, FieldContext $context): bool
    {
        return $context->surface === FieldContext::SURFACE_ADMIN_UPDATE
            && $context->actor instanceof User
            && (int) $context->actor->getKey() !== (int) $owner->getKey();
    }

    /**
     * 요청 값이 저장된 값과 같은 코드인지 반환합니다 (국가 코드는 대소문자를 가리지 않는다).
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @param  string  $value  요청 값
     * @param  mixed  $stored  저장된 값
     * @return bool 같으면 true
     */
    private function sameCode(FieldDefinition $definition, string $value, mixed $stored): bool
    {
        if (! is_string($stored)) {
            return false;
        }

        return $definition->key === self::SHIPPING_COUNTRY_FIELD
            ? strtoupper(trim($value)) === strtoupper($stored)
            : trim($value) === $stored;
    }
}
