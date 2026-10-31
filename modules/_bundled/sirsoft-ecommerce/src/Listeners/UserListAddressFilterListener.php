<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Helpers\PermissionHelper;
use Illuminate\Database\Eloquent\Builder;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\UserAddressRepositoryInterface;

/**
 * 회원관리 목록의 「배송지」 확장 필터 (#705 예제 E2)
 *
 * 두 조건을 맡는다 — 배송지 국가(`shipping_country`)와 배송지 등록 여부(`has_address`, 1=있음/0=없음).
 * 국가를 고르면 그 나라 배송지를 **하나라도** 가진 회원이다. 둘을 함께 걸면 「그 나라 배송지 있음」과
 * 「배송지 없음」은 동시에 참일 수 없으므로, 등록 여부 0 이 국가 조건보다 우선한다(결과 0 건이 된다).
 *
 * 코어 확장 필터 규약의 서버 쪽 세 층(검증 규칙·항목명·쿼리 클로저)을 한 리스너가 맡는다 — 마일리지
 * 예제(UserListMileageFilterListener)와 구조가 같다. 배송지 조회 권한(`sirsoft-ecommerce.user-addresses.read`)이
 * 없으면 조건을 적용하지 않는다.
 */
class UserListAddressFilterListener implements HookListenerInterface
{
    /**
     * 확장 필터 키 접두 (`ext.{확장id}`)
     */
    private const EXT = 'sirsoft-ecommerce';

    /**
     * 조건 적용에 필요한 권한
     */
    private const PERMISSION = 'sirsoft-ecommerce.user-addresses.read';

    /**
     * 의존성을 주입받습니다.
     *
     * @param  UserAddressRepositoryInterface  $addresses  회원 배송지 저장소
     */
    public function __construct(
        private UserAddressRepositoryInterface $addresses,
    ) {}

    /**
     * 구독할 훅 목록 반환
     *
     * @return array<string, array{method: string, type: string, priority: int}> 훅 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.user.list_validation_rules' => ['method' => 'addValidationRules', 'type' => 'filter', 'priority' => 21],
            'core.user.list_validation_attributes' => ['method' => 'addValidationAttributes', 'type' => 'filter', 'priority' => 21],
            'core.user.list_query_extensions' => ['method' => 'addQueryExtension', 'type' => 'filter', 'priority' => 21],
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
     * 배송지 키의 검증 규칙을 더합니다.
     *
     * @param  array<string, mixed>  $rules  코어 규칙
     * @return array<string, mixed> 확장 규칙이 더해진 규칙
     */
    public function addValidationRules(array $rules): array
    {
        $rules['ext.'.self::EXT.'.shipping_country'] = ['nullable', 'string', 'size:2', 'alpha'];
        $rules['ext.'.self::EXT.'.has_address'] = ['nullable', 'in:0,1'];

        return $rules;
    }

    /**
     * 배송지 키의 오류 문구 항목명을 더합니다.
     *
     * @param  array<string, string>  $attributes  코어 항목명
     * @return array<string, string> 확장 항목명이 더해진 목록
     */
    public function addValidationAttributes(array $attributes): array
    {
        $attributes['ext.'.self::EXT.'.shipping_country'] = __('sirsoft-ecommerce::validation.attributes.user_filter_shipping_country');
        $attributes['ext.'.self::EXT.'.has_address'] = __('sirsoft-ecommerce::validation.attributes.user_filter_has_address');

        return $attributes;
    }

    /**
     * 배송지 조건 클로저를 더합니다.
     *
     * @param  array<int, callable>  $closures  이미 모인 클로저
     * @param  array<string, mixed>  $filters  검증된 목록 조건
     * @return array<int, callable> 클로저 목록
     */
    public function addQueryExtension(array $closures, array $filters = []): array
    {
        $values = $filters['ext'][self::EXT] ?? [];
        $country = isset($values['shipping_country']) && $values['shipping_country'] !== '' ? strtoupper((string) $values['shipping_country']) : null;
        $hasAddress = isset($values['has_address']) && $values['has_address'] !== '' ? (string) $values['has_address'] : null;

        if (($country === null && $hasAddress === null) || ! PermissionHelper::check(self::PERMISSION)) {
            return $closures;
        }

        $closures[] = function (Builder $query) use ($country, $hasAddress) {
            if ($hasAddress === '0') {
                $query->whereNotIn('id', $this->addresses->userIdsWithAddressQuery());

                return;
            }

            if ($country !== null || $hasAddress === '1') {
                $query->whereIn('id', $this->addresses->userIdsWithAddressQuery($country));
            }
        };

        return $closures;
    }
}
