<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Support\ScopedPermissionEnforcement;
use Plugins\Sirsoft\PayNicepayments\Concerns\GuardsOrderScope;
use Plugins\Sirsoft\PayNicepayments\Controllers\AdminEscrowController;
use Plugins\Sirsoft\PayNicepayments\Controllers\AdminOrderListController;
use Plugins\Sirsoft\PayNicepayments\Controllers\AdminTransactionController;
use Plugins\Sirsoft\PayNicepayments\Controllers\AdminVbankNotificationController;
use Plugins\Sirsoft\PayNicepayments\Controllers\AdminVbankRefundController;

/**
 * 스코프 강제 지점 원장 — 이 플러그인의 관리자 라우트 몫
 *
 * 관리자 라우트가 이커머스 주문 권한을 걸면서 주문을 `{orderNumber}`·거래번호로 받으므로 권한 미들웨어가
 * 범위를 검사하지 않는다. 컨트롤러가 `GuardsOrderScope` 로 재판정하는 지점을 원장에 적는다. 같은 권한 키를
 * 이커머스 모듈 원장도 쓰므로 덮어쓰지 않고 라우트·강제 메서드를 덧붙인다(우선순위를 모듈보다 뒤로 둔다).
 */
class OrderScopeLedgerListener implements HookListenerInterface
{
    /** 라우트 이름 접두 */
    private const R = 'api.plugins.sirsoft-pay_nicepayments.admin.';

    /**
     * 구독할 훅 목록 반환
     *
     * @return array<string, array{method: string, priority: int, type: string}> 훅 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.permission.scope_enforcement_ledger' => [
                'method' => 'addLedgerEntries',
                'priority' => 20,
                'type' => 'filter',
            ],
        ];
    }

    /**
     * 기본 핸들러 (필터 훅만 쓰므로 비어 있음)
     *
     * @param  mixed  ...$args  훅 인자
     * @return void
     */
    public function handle(...$args): void {}

    /**
     * 이 플러그인의 라우트·강제 메서드를 기존 원장 항목에 덧붙입니다.
     *
     * @param  array<string, array<string, mixed>>  $ledger  지금까지의 원장
     * @return array<string, array<string, mixed>> 병합된 원장
     */
    public function addLedgerEntries(array $ledger): array
    {
        foreach (self::entries() as $identifier => $entry) {
            $current = $ledger[$identifier] ?? ['mode' => ScopedPermissionEnforcement::MODE_SERVICE, 'routes' => [], 'paths' => []];
            $current['routes'] = array_values(array_unique(array_merge($current['routes'] ?? [], $entry['routes'])));
            $current['paths'] = array_values(array_unique(array_merge($current['paths'] ?? [], $entry['paths'])));
            $ledger[$identifier] = $current;
        }

        return $ledger;
    }

    /**
     * 이 플러그인 몫의 원장 항목 (권한 식별자 → 라우트·강제 메서드)
     *
     * @return array<string, array{routes: array<int, string>, paths: array<int, string>}> 원장 항목
     */
    public static function entries(): array
    {
        $guard = [
            GuardsOrderScope::class.'::orderScopeDenial',
            GuardsOrderScope::class.'::transactionScopeDenial',
            GuardsOrderScope::class.'::orderNumbersWithinScope',
        ];

        return [
            'sirsoft-ecommerce.orders.read' => [
                'routes' => [
                    self::R.'orders.test-mode-map',
                    self::R.'transaction.query',
                    self::R.'orders.transaction-status',
                    self::R.'orders.escrow-payments',
                    self::R.'orders.vbank-notifications',
                ],
                'paths' => array_merge($guard, [
                    AdminOrderListController::class.'::testModeMap',
                    AdminTransactionController::class.'::query',
                    AdminTransactionController::class.'::queryByOrder',
                    AdminEscrowController::class.'::getEscrowPayments',
                    AdminVbankNotificationController::class.'::show',
                ]),
            ],
            'sirsoft-ecommerce.orders.update' => [
                'routes' => [self::R.'escrow.register-delivery'],
                'paths' => [AdminEscrowController::class.'::registerDelivery'],
            ],
            'sirsoft-ecommerce.claims.execute-refund' => [
                'routes' => [self::R.'vbank.refund'],
                'paths' => [AdminVbankRefundController::class.'::refund'],
            ],
        ];
    }
}
