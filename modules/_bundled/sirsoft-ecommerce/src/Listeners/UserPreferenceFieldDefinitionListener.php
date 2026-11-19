<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Enums\PermissionType;
use App\Fields\FieldContext;
use App\Fields\FieldSet;
use App\Models\FieldDefinition;
use App\Models\User;
use Illuminate\Support\Collection;
use Modules\Sirsoft\Ecommerce\Fields\UserPreferenceFieldHandler;
use Modules\Sirsoft\Ecommerce\Services\ShippingCountryResolver;
use Modules\Sirsoft\Ecommerce\Services\SignupCurrencyResolver;
use Modules\Sirsoft\Ecommerce\Services\UserCurrencyService;
use Modules\Sirsoft\Ecommerce\Services\UserShippingCountryService;

/**
 * 회원 항목 「결제 통화」와 「배송국가」의 선택지를 요청 시점에 채우는 리스너 (필터 `core.field.filter_definitions`).
 *
 * 두 항목의 선택지는 사이트 설정(통화 목록과 환율, 배송 가능 국가)에서 나오므로 선언에 박아 둘 수 없다.
 * 코어가 항목 목록을 만들 때마다 이 필터가 항목 사본에 선택지를 채우고, 유형 규칙(선택지 안의 값)이 그 집합으로 검증한다.
 *
 * | 조건 | 처리 |
 * | --- | --- |
 * | 결제 통화 | 선택지 = 기본 통화이거나 환율이 설정된 통화. 초기값 = 기본 통화 |
 * | 가입 화면에서 고를 통화가 하나뿐 | 결제 통화를 목록에서 뺀다(가입 뒤 기본 통화 지정 리스너가 정한다) |
 * | 배송국가 | 선택지 = 활성 배송 가능 국가. 초기값 = 기본 국가 |
 * | 해외배송을 끈 사이트 | 화면과 저장 경로(표면이 정해진 요청)에서 배송국가를 뺀다. 운영자 항목 표에는 남되 「필수」는 적용하지 않고 잠금 사유로 알린다(미입력 판정에서도 빠진다) |
 * | 내 정보 수정에서 저장된 배송국가가 지금 배송할 수 없는 국가 | 항목 안내에 「적용되지 않는다」는 문장과 지금 적용 중인 국가를 싣는다 |
 * | 대상 회원의 저장 값이 지금은 고를 수 없는 값 | 그 값을 선택지에 더한다(다른 칸만 고친 저장이 막히지 않게) |
 * | 관리자 수정에서 요청자가 이커머스 권한이 없음 | 칸을 읽기 전용으로 내보낸다(`ui.readonly`, `ui.locked_reason = permission`) |
 */
class UserPreferenceFieldDefinitionListener implements HookListenerInterface
{
    /**
     * @param  SignupCurrencyResolver  $currencyResolver  선택 가능 통화 판정기
     * @param  ShippingCountryResolver  $shippingCountryResolver  배송 가능 국가 판정기
     * @param  UserCurrencyService  $currencyService  회원 결제 통화 서비스 (저장 값 조회)
     * @param  UserShippingCountryService  $shippingCountryService  회원 배송국가 서비스 (저장 값 조회)
     */
    public function __construct(
        private SignupCurrencyResolver $currencyResolver,
        private ShippingCountryResolver $shippingCountryResolver,
        private UserCurrencyService $currencyService,
        private UserShippingCountryService $shippingCountryService,
    ) {}

    /**
     * 구독할 훅 목록 반환
     *
     * @return array<string, array{method: string, priority: int, type: string, sync: bool}> 훅 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.field.filter_definitions' => [
                'method' => 'fillPreferenceOptions',
                'priority' => 20,
                'type' => 'filter',
                'sync' => true,
            ],
        ];
    }

    /**
     * 기본 핸들러 (getSubscribedHooks 의 method 매핑 사용)
     *
     * @param  mixed  ...$args  훅 인수
     * @return void 반환값 없음
     */
    public function handle(...$args): void
    {
        // method 매핑(fillPreferenceOptions)을 사용하므로 직접 호출되지 않음
    }

    /**
     * 회원 항목 목록에서 결제 통화와 배송국가의 선택지를 채우고, 지금 받지 않는 항목을 뺍니다.
     *
     * @param  mixed  $definitions  항목 목록 (사본의 Collection)
     * @param  mixed  ...$args  집합 식별자, 범위 키, 문맥 배열(surface, owner, actor 등)
     * @return mixed 조정한 항목 목록
     */
    public function fillPreferenceOptions(mixed $definitions, mixed ...$args): mixed
    {
        if (! $definitions instanceof Collection || ($args[0] ?? null) !== FieldSet::CORE_USER) {
            return $definitions;
        }

        $context = is_array($args[2] ?? null) ? $args[2] : [];
        $surface = is_string($context['surface'] ?? null) ? $context['surface'] : null;
        $owner = ($context['owner'] ?? null) instanceof User && $context['owner']->exists ? $context['owner'] : null;
        $actor = ($context['actor'] ?? null) instanceof User ? $context['actor'] : null;

        return $definitions->filter(function ($definition) use ($surface, $owner, $actor) {
            if (! $this->isOwnField($definition)) {
                return true;
            }

            return $definition->key === UserPreferenceFieldHandler::CURRENCY_FIELD
                ? $this->prepareCurrency($definition, $surface, $owner, $actor)
                : $this->prepareShippingCountry($definition, $surface, $owner, $actor);
        })->values();
    }

    /**
     * 결제 통화 항목에 선택지와 초기값을 채웁니다.
     *
     * @param  FieldDefinition  $definition  항목 사본
     * @param  string|null  $surface  표면 (운영자 항목 표 등 표면 없는 조회는 null)
     * @param  User|null  $owner  대상 회원
     * @param  User|null  $actor  요청한 사용자
     * @return bool 목록에 남기면 true
     */
    private function prepareCurrency(FieldDefinition $definition, ?string $surface, ?User $owner, ?User $actor): bool
    {
        $options = [];
        foreach ($this->currencyResolver->selectableCurrencies() as $currency) {
            $options[$currency['code']] = $currency['code'].' ('.$currency['symbol'].')';
        }

        // 고를 통화가 하나뿐이면 가입 화면에서 묻지 않는다.
        if ($surface === FieldContext::SURFACE_SIGNUP && count($options) < 2) {
            return false;
        }

        $stored = $owner !== null ? $this->currencyService->getPreferredCurrency((int) $owner->getKey()) : null;
        if (is_string($stored) && $stored !== '' && ! isset($options[$stored])) {
            $options[$stored] = $stored;
        }

        $this->apply(
            $definition,
            $options,
            $this->currencyResolver->defaultCurrency(),
            $this->lockedForActor($surface, $owner, $actor, UserPreferenceFieldHandler::CURRENCY_PERMISSION),
        );

        return true;
    }

    /**
     * 배송국가 항목에 선택지와 초기값을 채웁니다.
     *
     * @param  FieldDefinition  $definition  항목 사본
     * @param  string|null  $surface  표면 (운영자 항목 표 등 표면 없는 조회는 null)
     * @param  User|null  $owner  대상 회원
     * @param  User|null  $actor  요청한 사용자
     * @return bool 목록에 남기면 true
     */
    private function prepareShippingCountry(FieldDefinition $definition, ?string $surface, ?User $owner, ?User $actor): bool
    {
        // 해외배송을 끈 사이트에서는 고를 나라가 하나뿐이다 — 화면과 저장 경로에서 뺀다.
        if (! $this->shippingCountryResolver->isInternationalShippingEnabled()) {
            if ($surface !== null) {
                return false;
            }

            // 표면이 없는 조회(운영자 항목 표, 비어 있는 필수 항목 판정)에는 남기되 「필수」를 적용하지 않는다.
            // 어떤 화면에도 없는 칸이 미입력으로 판정되면 회원은 채울 칸이 없는 추가 정보 화면에 갇히고 모든 쓰기가 막힌다.
            // 저장된 값은 바꾸지 않는다 — 해외배송을 다시 켜면 운영자가 정해 둔 「필수」가 돌아온다.
            $definition->required = false;
            $definition->require_existing_members = false;
            $definition->locks = [
                ...($definition->locks ?? []),
                'required' => 'sirsoft-ecommerce::fields.user.'.UserPreferenceFieldHandler::SHIPPING_COUNTRY_FIELD.'.required_locked',
            ];
        }

        $options = [];
        foreach ($this->shippingCountryResolver->allowedShippingCountryCodes() as $code) {
            $options[$code] = $this->shippingCountryResolver->countryLabel($code);
        }

        $stored = $owner !== null ? $this->shippingCountryService->getPreferredShippingCountry((int) $owner->getKey()) : null;
        if (is_string($stored) && $stored !== '' && ! isset($options[$stored])) {
            $options[$stored] = $this->shippingCountryResolver->countryLabel($stored);

            // 저장된 국가가 지금은 배송할 수 없는 국가다. 내 정보 수정에서 그 값이 적용되지 않는다는 것과 지금 적용 중인 국가를 알린다.
            // 수정 화면은 칸 아래 안내(`help`)로, 보기 화면은 값 아래 알림 줄(`ui.notice`)로 그린다 — 보기 화면은 입력 안내를 그리지 않기 때문이다.
            if ($surface === FieldContext::SURFACE_SELF_UPDATE) {
                $notice = $this->savedNotAppliedNotice($stored);
                $definition->description = $notice;
                $definition->ui = [...($definition->ui ?? []), 'notice' => $notice[app()->getLocale()] ?? null];
            }
        }

        $this->apply(
            $definition,
            $options,
            $this->shippingCountryResolver->defaultCountry(),
            $this->lockedForActor($surface, $owner, $actor, UserPreferenceFieldHandler::SHIPPING_COUNTRY_PERMISSION),
        );

        return true;
    }

    /**
     * 「저장된 배송국가가 지금 배송할 수 없는 국가라 적용되지 않는다」는 안내를 요청 로케일의 글로 만듭니다.
     *
     * 항목 안내(`description`) 자리에 실려 항목 계약의 `help` 로 나간다. 지금 적용 중인 국가는 사이트의 기본 배송국가다.
     *
     * @param  string  $stored  저장된 국가 코드
     * @return array<string, string> 요청 로케일 => 안내 문장
     */
    private function savedNotAppliedNotice(string $stored): array
    {
        return [
            app()->getLocale() => __('sirsoft-ecommerce::fields.user.'.UserPreferenceFieldHandler::SHIPPING_COUNTRY_FIELD.'.saved_not_applied', [
                'saved' => $this->shippingCountryResolver->countryLabel($stored),
                'applied' => $this->shippingCountryResolver->countryLabel($this->shippingCountryResolver->defaultCountry()),
            ]),
        ];
    }

    /**
     * 항목 사본에 선택지, 초기값, 요청 한정 잠금을 적용합니다.
     *
     * @param  FieldDefinition  $definition  항목 사본
     * @param  array<string, string>  $options  값 => 표시 글
     * @param  string|null  $default  값이 없을 때 화면이 보여 줄 초기값
     * @param  bool  $locked  이 요청에서 읽기 전용으로 내보낼지
     * @return void 반환값 없음
     */
    private function apply(FieldDefinition $definition, array $options, ?string $default, bool $locked): void
    {
        $definition->options = array_map(
            fn (string $value, string $label) => ['value' => $value, 'label' => $label],
            array_map('strval', array_keys($options)),
            array_values($options),
        );

        if ($default !== null && isset($options[$default])) {
            $definition->constraints = [...($definition->constraints ?? []), 'default' => $default];
        }

        if ($locked) {
            $definition->ui = [...($definition->ui ?? []), 'readonly' => true, 'locked_reason' => 'permission'];
        }
    }

    /**
     * 이 모듈이 선언한 결제 통화 또는 배송국가 항목인지 반환합니다.
     *
     * @param  mixed  $definition  목록의 원소
     * @return bool 이 모듈의 두 항목 중 하나면 true
     */
    private function isOwnField(mixed $definition): bool
    {
        return $definition instanceof FieldDefinition
            && $definition->source_identifier === 'sirsoft-ecommerce'
            && in_array($definition->key, [
                UserPreferenceFieldHandler::CURRENCY_FIELD,
                UserPreferenceFieldHandler::SHIPPING_COUNTRY_FIELD,
            ], true);
    }

    /**
     * 관리자 수정에서 요청자가 그 값을 바꿀 권한이 없는지 반환합니다.
     *
     * @param  string|null  $surface  표면
     * @param  User|null  $owner  대상 회원
     * @param  User|null  $actor  요청한 사용자
     * @param  string  $permission  필요한 이커머스 권한
     * @return bool 관리자 수정 표면이고 요청자에게 권한이 없으면 true
     */
    private function lockedForActor(?string $surface, ?User $owner, ?User $actor, string $permission): bool
    {
        if ($surface !== FieldContext::SURFACE_ADMIN_UPDATE || $owner === null || $actor === null) {
            return false;
        }

        return ! $actor->hasPermission($permission, PermissionType::Admin);
    }
}
