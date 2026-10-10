<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Helpers\PermissionHelper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\MileageBalanceRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;

/**
 * 회원관리 목록의 「마일리지 잔액」 확장 필터 (#705 예제 E1)
 *
 * 코어 회원 목록이 연 확장 필터 규약의 서버 쪽 세 층을 한 리스너가 맡는다.
 *
 * - `core.user.list_validation_rules` — `ext.sirsoft-ecommerce.mileage_min|max` 규칙
 * - `core.user.list_validation_attributes` — 같은 키의 오류 문구 항목명
 * - `core.user.list_query_extensions` — 잔액 범위 조건 클로저
 *
 * 값은 화면 조각(`resources/extensions/admin-user-list-filter-mileage.g7.html`)이 `ext_filters[]` 로
 * 보낸다. 잔액은 상점 **기본 통화 한 가지**로만 본다 — 통화마다 행이 따로 있어 통화를 섞어 비교하면
 * 뜻이 없다. 잔액 캐시 행이 없는 회원은 잔액 0 으로 본다.
 *
 * 마일리지를 볼 권한(`sirsoft-ecommerce.mileage.read`)이 없으면 조건을 적용하지 않는다 — 화면 행도
 * 같은 권한으로 숨기지만, URL 을 직접 고쳐 잔액 범위로 회원을 가려내 잔액을 추정하는 길을 막는다.
 */
class UserListMileageFilterListener implements HookListenerInterface
{
    /**
     * 확장 필터 키 접두 (`ext.{확장id}`)
     */
    private const EXT = 'sirsoft-ecommerce';

    /**
     * 조건 적용에 필요한 권한
     */
    private const PERMISSION = 'sirsoft-ecommerce.mileage.read';

    /**
     * 의존성을 주입받습니다.
     *
     * @param  MileageBalanceRepositoryInterface  $balances  마일리지 잔액 캐시 저장소
     * @param  CurrencyConversionService  $currency  기본 통화 조회
     */
    public function __construct(
        private MileageBalanceRepositoryInterface $balances,
        private CurrencyConversionService $currency,
    ) {}

    /**
     * 구독할 훅 목록 반환
     *
     * @return array<string, array{method: string, type: string, priority: int}> 훅 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.user.list_validation_rules' => ['method' => 'addValidationRules', 'type' => 'filter', 'priority' => 20],
            'core.user.list_validation_attributes' => ['method' => 'addValidationAttributes', 'type' => 'filter', 'priority' => 20],
            'core.user.list_query_extensions' => ['method' => 'addQueryExtension', 'type' => 'filter', 'priority' => 20],
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
        // method 매핑을 사용하므로 직접 호출되지 않음
    }

    /**
     * 잔액 범위 키의 검증 규칙을 더합니다.
     *
     * 「상한 ≥ 하한」 은 하한이 있을 때만 건다 — `gte:` 는 비교 대상이 없으면 실패하므로 상한만
     * 건 요청이 422 가 된다.
     *
     * @param  array<string, mixed>  $rules  코어 규칙
     * @param  Request|null  $request  목록 요청 (코어가 훅에 넘긴다)
     * @return array<string, mixed> 확장 규칙이 더해진 규칙
     */
    public function addValidationRules(array $rules, ?Request $request = null): array
    {
        $min = 'ext.'.self::EXT.'.mileage_min';
        $maxRules = ['nullable', 'numeric', 'min:0'];

        if ($request !== null && is_numeric($request->input($min))) {
            $maxRules[] = 'gte:'.$min;
        }

        $rules[$min] = ['nullable', 'numeric', 'min:0'];
        $rules['ext.'.self::EXT.'.mileage_max'] = $maxRules;

        return $rules;
    }

    /**
     * 잔액 범위 키의 오류 문구 항목명을 더합니다.
     *
     * @param  array<string, string>  $attributes  코어 항목명
     * @return array<string, string> 확장 항목명이 더해진 목록
     */
    public function addValidationAttributes(array $attributes): array
    {
        $attributes['ext.'.self::EXT.'.mileage_min'] = __('sirsoft-ecommerce::validation.attributes.user_filter_mileage_min');
        $attributes['ext.'.self::EXT.'.mileage_max'] = __('sirsoft-ecommerce::validation.attributes.user_filter_mileage_max');

        return $attributes;
    }

    /**
     * 잔액 범위 조건 클로저를 더합니다.
     *
     * @param  array<int, callable>  $closures  이미 모인 클로저
     * @param  array<string, mixed>  $filters  검증된 목록 조건
     * @return array<int, callable> 클로저 목록
     */
    public function addQueryExtension(array $closures, array $filters = []): array
    {
        $values = $filters['ext'][self::EXT] ?? [];
        $min = $this->amount($values['mileage_min'] ?? null);
        $max = $this->amount($values['mileage_max'] ?? null);

        if (($min === null && $max === null) || ! PermissionHelper::check(self::PERMISSION)) {
            return $closures;
        }

        $currency = $this->currency->getDefaultCurrency();

        $closures[] = function (Builder $query) use ($min, $max, $currency) {
            // 하한이 0 보다 크면 잔액 행이 있는 회원만 남는다(행 없음 = 0).
            if ($min !== null && $min > 0) {
                $query->whereIn('id', $this->balances->userIdsByAvailableQuery($currency, '>=', $min));
            }

            // 상한은 「넘는 회원을 뺀다」로 푼다 — 행이 없는 회원(0)이 상한 조건에서 빠지지 않게.
            if ($max !== null) {
                $query->whereNotIn('id', $this->balances->userIdsByAvailableQuery($currency, '>', $max));
            }
        };

        return $closures;
    }

    /**
     * 금액 값을 해석합니다. 0 은 유효값이므로 `empty()` 로 거르지 않는다.
     *
     * @param  mixed  $value  요청 값
     * @return float|null 금액 (값이 없으면 null)
     */
    private function amount(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }
}
