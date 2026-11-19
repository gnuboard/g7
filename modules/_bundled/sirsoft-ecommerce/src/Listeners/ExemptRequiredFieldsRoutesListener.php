<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;

/**
 * 추가 정보를 아직 입력하지 않은 회원에게도 열어 둘 이 모듈의 쓰기 라우트를 코어에 알립니다.
 *
 * 코어는 비어 있는 필수 회원 항목이 있는 회원의 쓰기 요청(POST, PUT, PATCH, DELETE)을 `api` 그룹 전체에서
 * 403 `required_fields_pending` 으로 거부한다. 필터 `core.required_fields.filter_exempt_routes` 가 돌려준
 * 라우트 이름은 거부하지 않는다.
 *
 * | 라우트 | 열어 두는 이유 |
 * | --- | --- |
 * | 결제 취소 기록 (`orders.cancel-payment`) | 결제창에서 돌아온 뒤의 결과 처리다. 막으면 결제하지 않은 주문이 결제 대기로 남는다 |
 * | 클레임 추가 결제 승인 (`user.claim-payments.approve`) | 결제사가 이미 승인한 결제의 결과 처리다. 막으면 돈은 나갔는데 기록이 남지 않는다 |
 * | 결제 통화 전환, 배송국가 전환 | 화면의 표시 설정이다. 추가 정보 화면에서도 헤더에서 바꿀 수 있어야 한다 |
 *
 * 새 쓰기 라우트를 더할 때는 「회원이 추가 정보를 채우기 전에도 반드시 돼야 하는가」를 판단해 이 목록에 보탠다.
 * 주문, 장바구니, 리뷰, 문의, 클레임 신청과 결제 시작(`user.claim-payments.pay`)처럼 새 거래를 만드는 요청은 보태지 않는다.
 */
class ExemptRequiredFieldsRoutesListener implements HookListenerInterface
{
    /**
     * 미입력 회원에게도 열어 두는 라우트 이름.
     *
     * @var array<int, string>
     */
    public const ROUTES = [
        'api.modules.sirsoft-ecommerce.orders.cancel-payment',
        'api.modules.sirsoft-ecommerce.user.claim-payments.approve',
        'api.modules.sirsoft-ecommerce.user.currency.update',
        'api.modules.sirsoft-ecommerce.user.shipping-country.update',
    ];

    /**
     * 이 리스너가 구독하는 훅 정의를 반환합니다.
     *
     * @return array<string, array<string, mixed>> 훅 이름 => 구독 설정
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.required_fields.filter_exempt_routes' => [
                'method' => 'exemptRoutes',
                'type' => 'filter',
                'priority' => 10,
            ],
        ];
    }

    /**
     * 기본 핸들러 (미사용, exemptRoutes 에서 처리)
     *
     * @param  mixed  ...$args  훅 인수
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 지금까지 모인 허용 라우트 이름에 이 모듈의 라우트 이름을 덧붙입니다.
     *
     * @param  mixed  $routes  라우트 이름 목록 (다른 리스너가 이미 덧붙인 값 포함)
     * @param  mixed  ...$args  나머지 훅 인수 (현재 요청. 쓰지 않는다)
     * @return array<int, string> 이 모듈의 라우트 이름이 더해진 목록
     */
    public function exemptRoutes(mixed $routes, mixed ...$args): array
    {
        $routes = is_array($routes) ? $routes : [];

        return array_values(array_unique([...$routes, ...self::ROUTES]));
    }
}
