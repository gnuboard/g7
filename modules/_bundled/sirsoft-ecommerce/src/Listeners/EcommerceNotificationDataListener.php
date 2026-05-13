<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\ProductInquiry;

/**
 * 이커머스 알림 데이터 필터 리스너
 *
 * notification_definitions의 extract_data 필터를 처리하여
 * 알림 발송에 필요한 데이터와 컨텍스트를 제공합니다.
 * 수신자 결정은 notification_definitions.recipients 설정에 위임합니다.
 */
class EcommerceNotificationDataListener implements HookListenerInterface
{
    /**
     * 구독할 훅 목록을 반환합니다.
     *
     * @return array
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.notification.extract_data' => [
                'method' => 'extractData',
                'priority' => 20,
                'type' => 'filter',
            ],
            'core.notification.filter_default_definitions' => [
                'method' => 'contributeDefaultDefinitions',
                'priority' => 20,
                'type' => 'filter',
            ],
        ];
    }

    /**
     * 훅 이벤트를 처리합니다.
     *
     * @param mixed ...$args 훅에서 전달된 인수들
     * @return void
     */
    public function handle(...$args): void {}

    /**
     * 이커머스 모듈의 기본 알림 정의를 코어 리셋 로직에 제공합니다.
     *
     * @param array $definitions 현재까지 수집된 기본 정의 목록
     * @param array $context type/channel 필터 컨텍스트
     * @return array 이커머스 시더 정의를 병합한 목록
     */
    public function contributeDefaultDefinitions(array $definitions, array $context = []): array
    {
        // module.php 의 getNotificationDefinitions() 가 SSoT — declarative getter 패턴
        /** @var \Modules\Sirsoft\Ecommerce\Module $module */
        $module = app(\App\Extension\ModuleManager::class)->getModule('sirsoft-ecommerce');
        if (! $module) {
            return $definitions;
        }

        $contributed = [];
        foreach ($module->getNotificationDefinitions() as $data) {
            $contributed[] = array_merge($data, [
                'extension_type' => 'module',
                'extension_identifier' => $module->getIdentifier(),
            ]);
        }

        return array_merge($definitions, $contributed);
    }

    /**
     * 알림 유형에 따라 데이터와 컨텍스트를 추출합니다.
     *
     * @param array $default 기본 extract_data 구조
     * @param string $type 알림 정의 유형
     * @param array $args 훅에서 전달된 원본 인수
     * @return array{notifiable: null, notifiables: null, data: array, context: array}
     */
    public function extractData(array $default, string $type, array $args): array
    {
        return match ($type) {
            'order_confirmed' => $this->extractOrderConfirmed($args),
            'order_shipped' => $this->extractOrderShipped($args),
            'order_completed' => $this->extractOrderCompleted($args),
            'order_cancelled' => $this->extractOrderCancelled($args),
            'new_order_admin' => $this->extractNewOrderAdmin($args),
            'inquiry_received' => $this->extractInquiryReceived($args),
            'inquiry_replied' => $this->extractInquiryReplied($args),
            default => $default,
        };
    }

    // ──────────────────────────────────────────────
    // 주문자 알림 (4종)
    // ──────────────────────────────────────────────

    /**
     * 주문 확인(결제 완료) 알림 데이터를 추출합니다.
     *
     * @param array $args 훅 인수 [$order]
     * @return array
     */
    private function extractOrderConfirmed(array $args): array
    {
        $order = $args[0] ?? null;
        if (! $order instanceof Order) {
            return $this->emptyResult();
        }

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => $this->buildOrderData($order),
            'context' => $this->buildOrderContext($order),
        ];
    }

    /**
     * 배송 시작 알림 데이터를 추출합니다.
     *
     * @param array $args 훅 인수 [$order]
     * @return array
     */
    private function extractOrderShipped(array $args): array
    {
        $order = $args[0] ?? null;
        if (! $order instanceof Order) {
            return $this->emptyResult();
        }

        $shipping = $order->shippings()->latest()->first();

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => $this->buildOrderData($order, [
                'carrier_name' => $shipping?->carrier?->getLocalizedName() ?? '',
                'tracking_number' => $shipping?->tracking_number ?? '',
            ]),
            'context' => $this->buildOrderContext($order),
        ];
    }

    /**
     * 구매 확정 알림 데이터를 추출합니다.
     *
     * @param array $args 훅 인수 [$order]
     * @return array
     */
    private function extractOrderCompleted(array $args): array
    {
        $order = $args[0] ?? null;
        if (! $order instanceof Order) {
            return $this->emptyResult();
        }

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => $this->buildOrderData($order),
            'context' => $this->buildOrderContext($order),
        ];
    }

    /**
     * 주문 취소 알림 데이터를 추출합니다.
     *
     * @param array $args 훅 인수 [$order, $cancelSnapshot?]
     * @return array
     */
    private function extractOrderCancelled(array $args): array
    {
        $order = $args[0] ?? null;
        if (! $order instanceof Order) {
            return $this->emptyResult();
        }

        // audit:allow service-direct-data-access reason: OrderCancel 전용 Repository 미정의 — notification payload extract 한정 단일 사용처. 후속 OrderCancelRepository 도입 시 위임 (issue follow-up)
        $latestCancel = OrderCancel::where('order_id', $order->id)->latest('id')->first();
        $cancelReason = $latestCancel?->cancel_reason ?? '';

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => $this->buildOrderData($order, [
                'cancel_reason' => $cancelReason,
            ]),
            'context' => $this->buildOrderContext($order),
        ];
    }

    // ──────────────────────────────────────────────
    // 관리자 알림 (2종)
    // ──────────────────────────────────────────────

    /**
     * 신규 주문 관리자 알림 데이터를 추출합니다.
     *
     * @param array $args 훅 인수 [$order]
     * @return array
     */
    private function extractNewOrderAdmin(array $args): array
    {
        $order = $args[0] ?? null;
        if (! $order instanceof Order) {
            return $this->emptyResult();
        }

        $baseUrl = config('app.url');

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => [
                'name' => '{recipient_name}',
                'app_name' => config('app.name'),
                'order_number' => $order->order_number,
                'customer_name' => $order->user?->name ?? '',
                'total_amount' => number_format($order->total_paid_amount ?? $order->total_amount) . '원',
                'order_url' => "{$baseUrl}/admin/ecommerce/orders/{$order->order_number}",
                'site_url' => $baseUrl,
            ],
            'context' => [
                'trigger_user_id' => $order->user_id,
                'trigger_user' => $order->user,
            ],
        ];
    }

    /**
     * 상품 문의 접수 관리자 알림 데이터를 추출합니다.
     *
     * @param array $args 훅 인수 [$inquiry]
     * @return array
     */
    private function extractInquiryReceived(array $args): array
    {
        $inquiry = $args[0] ?? null;
        if (! $inquiry instanceof ProductInquiry) {
            return $this->emptyResult();
        }

        $baseUrl = config('app.url');
        $product = $inquiry->product;

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => [
                'name' => '{recipient_name}',
                'app_name' => config('app.name'),
                'product_name' => $product?->getLocalizedName() ?? ($inquiry->product_name_snapshot[app()->getLocale()] ?? ''),
                'customer_name' => $inquiry->user?->name ?? '',
                'inquiry_content' => mb_substr($inquiry->inquirable?->content ?? '', 0, 200),
                'inquiry_url' => "{$baseUrl}/admin/ecommerce/product-inquiries",
                'site_url' => $baseUrl,
            ],
            'context' => [
                'trigger_user_id' => $inquiry->user_id,
                'trigger_user' => $inquiry->user,
            ],
        ];
    }

    // ──────────────────────────────────────────────
    // 사용자 알림 (1종)
    // ──────────────────────────────────────────────

    /**
     * 문의 답변 완료 알림 데이터를 추출합니다.
     *
     * @param array $args 훅 인수 [$inquiry]
     * @return array
     */
    private function extractInquiryReplied(array $args): array
    {
        $inquiry = $args[0] ?? null;
        if (! $inquiry instanceof ProductInquiry) {
            return $this->emptyResult();
        }

        $baseUrl = config('app.url');

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => [
                'name' => $inquiry->user?->name ?? '',
                'app_name' => config('app.name'),
                'product_name' => $inquiry->product?->getLocalizedName() ?? ($inquiry->product_name_snapshot[app()->getLocale()] ?? ''),
                'inquiry_content' => mb_substr($inquiry->inquirable?->content ?? '', 0, 200),
                'inquiry_url' => "{$baseUrl}/mypage/inquiries",
                'site_url' => $baseUrl,
            ],
            'context' => [
                'trigger_user_id' => $inquiry->user_id,
                'trigger_user' => $inquiry->user,
                'related_users' => [
                    'author' => $inquiry->user,
                ],
            ],
        ];
    }

    // ──────────────────────────────────────────────
    // 헬퍼 메서드
    // ──────────────────────────────────────────────

    /**
     * 주문 알림 데이터 배열을 구성합니다.
     *
     * @param Order $order 주문 모델
     * @param array $extra 추가 변수
     * @return array
     */
    private function buildOrderData(Order $order, array $extra = []): array
    {
        $baseUrl = config('app.url');

        return array_merge([
            'name' => $order->user?->name ?? '',
            'app_name' => config('app.name'),
            'order_number' => $order->order_number,
            'total_amount' => number_format($order->total_paid_amount ?? $order->total_amount) . '원',
            'order_url' => "{$baseUrl}/mypage/orders/{$order->order_number}",
            'site_url' => $baseUrl,
        ], $extra);
    }

    /**
     * 주문 컨텍스트를 구성합니다.
     *
     * @param Order $order 주문 모델
     * @return array
     */
    private function buildOrderContext(Order $order): array
    {
        return [
            'trigger_user_id' => $order->user_id,
            'trigger_user' => $order->user,
        ];
    }

    /**
     * 빈 결과를 반환합니다.
     *
     * @return array
     */
    private function emptyResult(): array
    {
        return ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []];
    }
}
