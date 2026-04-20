<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders;

use App\Extension\Helpers\NotificationSyncHelper;
use Illuminate\Database\Seeder;

class EcommerceNotificationDefinitionSeeder extends Seeder
{
    /**
     * 이커머스 알림 정의 및 템플릿을 시딩합니다.
     *
     * 데이터 정합성 (upsert + stale cleanup) 은 NotificationSyncHelper 에 위임하며,
     * 사용자 수정(user_overrides) 은 helper 내부 HasUserOverrides trait 를 통해 보존됩니다.
     *
     * @return void
     */
    public function run(): void
    {
        $this->command?->info('이커머스 알림 정의 시딩 시작...');

        $helper = app(NotificationSyncHelper::class);
        $definitions = $this->getDefaultDefinitions();
        $definedTypes = [];

        foreach ($definitions as $data) {
            $definition = $helper->syncDefinition($data);
            $definedTypes[] = $definition->type;

            $definedChannels = [];
            foreach ($data['templates'] as $template) {
                $helper->syncTemplate($definition->id, $template);
                $definedChannels[] = $template['channel'];
            }

            // 완전 동기화: seeder 에서 제거된 channel 의 template 삭제
            $helper->cleanupStaleTemplates($definition->id, $definedChannels);

            $this->command?->info("  - {$data['type']} 알림 정의 등록 완료");
        }

        // 완전 동기화: seeder 에서 제거된 이커머스 definition 삭제 (cascade 로 template 도 정리)
        $helper->cleanupStaleDefinitions('module', 'sirsoft-ecommerce', $definedTypes);

        $this->command?->info('이커머스 알림 정의 시딩 완료 ('.count($definitions).'종)');
    }

    /**
     * 이커머스 기본 알림 정의 데이터를 반환합니다.
     *
     * @return array
     */
    public function getDefaultDefinitions(): array
    {
        return [
            $this->orderConfirmedDefinition(),
            $this->orderShippedDefinition(),
            $this->orderCompletedDefinition(),
            $this->orderCancelledDefinition(),
            $this->newOrderAdminDefinition(),
            $this->inquiryReceivedDefinition(),
            $this->inquiryRepliedDefinition(),
        ];
    }

    /**
     * 주문 확인 알림 정의.
     *
     * @return array
     */
    private function orderConfirmedDefinition(): array
    {
        return [
            'type' => 'order_confirmed',
            'hook_prefix' => 'sirsoft-ecommerce',
            'extension_type' => 'module',
            'extension_identifier' => 'sirsoft-ecommerce',
            'name' => ['ko' => '주문 확인', 'en' => 'Order Confirmed'],
            'description' => ['ko' => '주문 확인 시 고객에게 발송', 'en' => 'Sent to customer when order is confirmed'],
            'channels' => ['mail', 'database'],
            'hooks' => ['sirsoft-ecommerce.order.after_confirm'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트 이름'],
                ['key' => 'order_number', 'description' => '주문번호'],
                ['key' => 'total_amount', 'description' => '결제 금액'],
                ['key' => 'order_url', 'description' => '주문 상세 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => [
                        'ko' => '[{app_name}] 주문이 확인되었습니다 (주문번호: {order_number})',
                        'en' => '[{app_name}] Your order has been confirmed (Order #{order_number})',
                    ],
                    'body' => [
                        'ko' => '<div style="font-family:\'Malgun Gothic\',sans-serif;max-width:600px;margin:0 auto;padding:20px">'
                            .'<h2 style="color:#333;border-bottom:2px solid #4F46E5;padding-bottom:10px">주문 확인</h2>'
                            .'<p style="color:#555;line-height:1.6">{name}님, 주문해 주셔서 감사합니다.</p>'
                            .'<div style="background:#f8f9fa;padding:15px;border-radius:8px;margin:15px 0">'
                            .'<p style="margin:5px 0"><strong>주문번호:</strong> {order_number}</p>'
                            .'<p style="margin:5px 0"><strong>결제금액:</strong> {total_amount}</p>'
                            .'</div>'
                            .'<p style="color:#555;line-height:1.6">주문 상세 내용은 아래 버튼을 클릭하여 확인하실 수 있습니다.</p>'
                            .$this->button('주문 상세 보기', '{order_url}')
                            .'<hr style="border:none;border-top:1px solid #eee;margin:20px 0">'
                            .'<p style="color:#999;font-size:12px">본 메일은 {app_name}에서 발송되었습니다.</p>'
                            .'</div>',
                        'en' => '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px">'
                            .'<h2 style="color:#333;border-bottom:2px solid #4F46E5;padding-bottom:10px">Order Confirmed</h2>'
                            .'<p style="color:#555;line-height:1.6">Dear {name}, thank you for your order.</p>'
                            .'<div style="background:#f8f9fa;padding:15px;border-radius:8px;margin:15px 0">'
                            .'<p style="margin:5px 0"><strong>Order Number:</strong> {order_number}</p>'
                            .'<p style="margin:5px 0"><strong>Total Amount:</strong> {total_amount}</p>'
                            .'</div>'
                            .'<p style="color:#555;line-height:1.6">Click the button below to view your order details.</p>'
                            .$this->button('View Order', '{order_url}')
                            .'<hr style="border:none;border-top:1px solid #eee;margin:20px 0">'
                            .'<p style="color:#999;font-size:12px">This email was sent from {app_name}.</p>'
                            .'</div>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => ['ko' => '주문이 확인되었습니다', 'en' => 'Your order has been confirmed'],
                    'body' => ['ko' => '{name}님, 주문번호 {order_number}의 주문이 확인되었습니다.', 'en' => '{name}, your order {order_number} has been confirmed.'],
                    'click_url' => '{order_url}',
                ],
            ],
        ];
    }

    /**
     * 배송 시작 알림 정의.
     *
     * @return array
     */
    private function orderShippedDefinition(): array
    {
        return [
            'type' => 'order_shipped',
            'hook_prefix' => 'sirsoft-ecommerce',
            'extension_type' => 'module',
            'extension_identifier' => 'sirsoft-ecommerce',
            'name' => ['ko' => '배송 시작', 'en' => 'Order Shipped'],
            'description' => ['ko' => '배송 시작 시 고객에게 발송', 'en' => 'Sent to customer when order is shipped'],
            'channels' => ['mail', 'database'],
            'hooks' => ['sirsoft-ecommerce.order.after_ship'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트 이름'],
                ['key' => 'order_number', 'description' => '주문번호'],
                ['key' => 'carrier_name', 'description' => '택배사 이름'],
                ['key' => 'tracking_number', 'description' => '운송장 번호'],
                ['key' => 'order_url', 'description' => '주문 상세 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => [
                        'ko' => '[{app_name}] 주문하신 상품이 발송되었습니다 (주문번호: {order_number})',
                        'en' => '[{app_name}] Your order has been shipped (Order #{order_number})',
                    ],
                    'body' => [
                        'ko' => '<div style="font-family:\'Malgun Gothic\',sans-serif;max-width:600px;margin:0 auto;padding:20px">'
                            .'<h2 style="color:#333;border-bottom:2px solid #4F46E5;padding-bottom:10px">배송 시작 안내</h2>'
                            .'<p style="color:#555;line-height:1.6">{name}님, 주문하신 상품이 발송되었습니다.</p>'
                            .'<div style="background:#f8f9fa;padding:15px;border-radius:8px;margin:15px 0">'
                            .'<p style="margin:5px 0"><strong>주문번호:</strong> {order_number}</p>'
                            .'<p style="margin:5px 0"><strong>택배사:</strong> {carrier_name}</p>'
                            .'<p style="margin:5px 0"><strong>운송장번호:</strong> {tracking_number}</p>'
                            .'</div>'
                            .'<p style="color:#555;line-height:1.6">주문 상세 및 배송 현황은 아래 버튼을 클릭하여 확인하실 수 있습니다.</p>'
                            .$this->button('주문 상세 보기', '{order_url}')
                            .'<hr style="border:none;border-top:1px solid #eee;margin:20px 0">'
                            .'<p style="color:#999;font-size:12px">본 메일은 {app_name}에서 발송되었습니다.</p>'
                            .'</div>',
                        'en' => '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px">'
                            .'<h2 style="color:#333;border-bottom:2px solid #4F46E5;padding-bottom:10px">Order Shipped</h2>'
                            .'<p style="color:#555;line-height:1.6">Dear {name}, your order has been shipped.</p>'
                            .'<div style="background:#f8f9fa;padding:15px;border-radius:8px;margin:15px 0">'
                            .'<p style="margin:5px 0"><strong>Order Number:</strong> {order_number}</p>'
                            .'<p style="margin:5px 0"><strong>Carrier:</strong> {carrier_name}</p>'
                            .'<p style="margin:5px 0"><strong>Tracking Number:</strong> {tracking_number}</p>'
                            .'</div>'
                            .'<p style="color:#555;line-height:1.6">Click the button below to view your order and tracking details.</p>'
                            .$this->button('View Order', '{order_url}')
                            .'<hr style="border:none;border-top:1px solid #eee;margin:20px 0">'
                            .'<p style="color:#999;font-size:12px">This email was sent from {app_name}.</p>'
                            .'</div>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => ['ko' => '상품이 발송되었습니다', 'en' => 'Your order has been shipped'],
                    'body' => ['ko' => '{name}님, 주문번호 {order_number}이 {carrier_name}(송장번호: {tracking_number})으로 발송되었습니다.', 'en' => '{name}, your order {order_number} has been shipped via {carrier_name} (tracking: {tracking_number}).'],
                    'click_url' => '{order_url}',
                ],
            ],
        ];
    }

    /**
     * 구매 확정 알림 정의.
     *
     * @return array
     */
    private function orderCompletedDefinition(): array
    {
        return [
            'type' => 'order_completed',
            'hook_prefix' => 'sirsoft-ecommerce',
            'extension_type' => 'module',
            'extension_identifier' => 'sirsoft-ecommerce',
            'name' => ['ko' => '구매 확정', 'en' => 'Order Completed'],
            'description' => ['ko' => '구매 확정 시 고객에게 발송', 'en' => 'Sent to customer when order is completed'],
            'channels' => ['mail', 'database'],
            'hooks' => ['sirsoft-ecommerce.order.after_complete'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트 이름'],
                ['key' => 'order_number', 'description' => '주문번호'],
                ['key' => 'total_amount', 'description' => '결제 금액'],
                ['key' => 'order_url', 'description' => '주문 상세 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => [
                        'ko' => '[{app_name}] 구매가 확정되었습니다 (주문번호: {order_number})',
                        'en' => '[{app_name}] Your purchase has been completed (Order #{order_number})',
                    ],
                    'body' => [
                        'ko' => '<div style="font-family:\'Malgun Gothic\',sans-serif;max-width:600px;margin:0 auto;padding:20px">'
                            .'<h2 style="color:#333;border-bottom:2px solid #4F46E5;padding-bottom:10px">구매 확정 안내</h2>'
                            .'<p style="color:#555;line-height:1.6">{name}님, 구매가 확정되었습니다. 이용해 주셔서 감사합니다.</p>'
                            .'<div style="background:#f8f9fa;padding:15px;border-radius:8px;margin:15px 0">'
                            .'<p style="margin:5px 0"><strong>주문번호:</strong> {order_number}</p>'
                            .'<p style="margin:5px 0"><strong>결제금액:</strong> {total_amount}</p>'
                            .'</div>'
                            .'<p style="color:#555;line-height:1.6">주문 내역은 아래 버튼을 클릭하여 확인하실 수 있습니다.</p>'
                            .$this->button('주문 내역 보기', '{order_url}')
                            .'<hr style="border:none;border-top:1px solid #eee;margin:20px 0">'
                            .'<p style="color:#999;font-size:12px">본 메일은 {app_name}에서 발송되었습니다.</p>'
                            .'</div>',
                        'en' => '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px">'
                            .'<h2 style="color:#333;border-bottom:2px solid #4F46E5;padding-bottom:10px">Purchase Completed</h2>'
                            .'<p style="color:#555;line-height:1.6">Dear {name}, your purchase has been completed. Thank you for shopping with us.</p>'
                            .'<div style="background:#f8f9fa;padding:15px;border-radius:8px;margin:15px 0">'
                            .'<p style="margin:5px 0"><strong>Order Number:</strong> {order_number}</p>'
                            .'<p style="margin:5px 0"><strong>Total Amount:</strong> {total_amount}</p>'
                            .'</div>'
                            .'<p style="color:#555;line-height:1.6">Click the button below to view your order history.</p>'
                            .$this->button('View Order', '{order_url}')
                            .'<hr style="border:none;border-top:1px solid #eee;margin:20px 0">'
                            .'<p style="color:#999;font-size:12px">This email was sent from {app_name}.</p>'
                            .'</div>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => ['ko' => '구매가 확정되었습니다', 'en' => 'Your purchase has been confirmed'],
                    'body' => ['ko' => '{name}님, 주문번호 {order_number}의 구매가 확정되었습니다.', 'en' => '{name}, your order {order_number} has been confirmed.'],
                    'click_url' => '{order_url}',
                ],
            ],
        ];
    }

    /**
     * 주문 취소 알림 정의.
     *
     * @return array
     */
    private function orderCancelledDefinition(): array
    {
        return [
            'type' => 'order_cancelled',
            'hook_prefix' => 'sirsoft-ecommerce',
            'extension_type' => 'module',
            'extension_identifier' => 'sirsoft-ecommerce',
            'name' => ['ko' => '주문 취소', 'en' => 'Order Cancelled'],
            'description' => ['ko' => '주문 취소 시 고객에게 발송', 'en' => 'Sent to customer when order is cancelled'],
            'channels' => ['mail', 'database'],
            'hooks' => ['sirsoft-ecommerce.order.after_cancel'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트 이름'],
                ['key' => 'order_number', 'description' => '주문번호'],
                ['key' => 'cancel_reason', 'description' => '취소 사유'],
                ['key' => 'order_url', 'description' => '주문 상세 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => [
                        'ko' => '[{app_name}] 주문이 취소되었습니다 (주문번호: {order_number})',
                        'en' => '[{app_name}] Your order has been cancelled (Order #{order_number})',
                    ],
                    'body' => [
                        'ko' => '<div style="font-family:\'Malgun Gothic\',sans-serif;max-width:600px;margin:0 auto;padding:20px">'
                            .'<h2 style="color:#333;border-bottom:2px solid #DC2626;padding-bottom:10px">주문 취소 안내</h2>'
                            .'<p style="color:#555;line-height:1.6">{name}님, 주문이 취소되었습니다.</p>'
                            .'<div style="background:#f8f9fa;padding:15px;border-radius:8px;margin:15px 0">'
                            .'<p style="margin:5px 0"><strong>주문번호:</strong> {order_number}</p>'
                            .'<p style="margin:5px 0"><strong>취소 사유:</strong> {cancel_reason}</p>'
                            .'</div>'
                            .'<p style="color:#555;line-height:1.6">환불은 결제 수단에 따라 3~7영업일 이내에 처리됩니다. 주문 상세는 아래 버튼을 클릭하여 확인하실 수 있습니다.</p>'
                            .$this->button('주문 상세 보기', '{order_url}')
                            .'<hr style="border:none;border-top:1px solid #eee;margin:20px 0">'
                            .'<p style="color:#999;font-size:12px">본 메일은 {app_name}에서 발송되었습니다.</p>'
                            .'</div>',
                        'en' => '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px">'
                            .'<h2 style="color:#333;border-bottom:2px solid #DC2626;padding-bottom:10px">Order Cancelled</h2>'
                            .'<p style="color:#555;line-height:1.6">Dear {name}, your order has been cancelled.</p>'
                            .'<div style="background:#f8f9fa;padding:15px;border-radius:8px;margin:15px 0">'
                            .'<p style="margin:5px 0"><strong>Order Number:</strong> {order_number}</p>'
                            .'<p style="margin:5px 0"><strong>Cancel Reason:</strong> {cancel_reason}</p>'
                            .'</div>'
                            .'<p style="color:#555;line-height:1.6">Refunds will be processed within 3-7 business days depending on your payment method. Click the button below to view your order details.</p>'
                            .$this->button('View Order', '{order_url}')
                            .'<hr style="border:none;border-top:1px solid #eee;margin:20px 0">'
                            .'<p style="color:#999;font-size:12px">This email was sent from {app_name}.</p>'
                            .'</div>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => ['ko' => '주문이 취소되었습니다', 'en' => 'Your order has been cancelled'],
                    'body' => ['ko' => '{name}님, 주문번호 {order_number}이 취소되었습니다. 사유: {cancel_reason}', 'en' => '{name}, your order {order_number} has been cancelled. Reason: {cancel_reason}'],
                    'click_url' => '{order_url}',
                ],
            ],
        ];
    }

    /**
     * 관리자 신규 주문 알림 정의.
     *
     * @return array
     */
    private function newOrderAdminDefinition(): array
    {
        return [
            'type' => 'new_order_admin',
            'hook_prefix' => 'sirsoft-ecommerce',
            'extension_type' => 'module',
            'extension_identifier' => 'sirsoft-ecommerce',
            'name' => ['ko' => '신규 주문 관리자 알림', 'en' => 'New Order Admin Notification'],
            'description' => ['ko' => '신규 주문 접수 시 관리자에게 발송', 'en' => 'Sent to admin when a new order is placed'],
            'channels' => ['mail', 'database'],
            'hooks' => ['sirsoft-ecommerce.order.after_create'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자(관리자) 이름'],
                ['key' => 'app_name', 'description' => '사이트 이름'],
                ['key' => 'order_number', 'description' => '주문번호'],
                ['key' => 'customer_name', 'description' => '주문자 이름'],
                ['key' => 'total_amount', 'description' => '결제 금액'],
                ['key' => 'order_url', 'description' => '주문 관리 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'role', 'value' => 'admin', 'exclude_trigger_user' => true]],
                    'subject' => [
                        'ko' => '[{app_name}] 신규 주문이 접수되었습니다 (주문번호: {order_number})',
                        'en' => '[{app_name}] New order received (Order #{order_number})',
                    ],
                    'body' => [
                        'ko' => '<div style="font-family:\'Malgun Gothic\',sans-serif;max-width:600px;margin:0 auto;padding:20px">'
                            .'<h2 style="color:#333;border-bottom:2px solid #4F46E5;padding-bottom:10px">신규 주문 접수</h2>'
                            .'<p style="color:#555;line-height:1.6">{name}님, 새로운 주문이 접수되었습니다.</p>'
                            .'<div style="background:#f8f9fa;padding:15px;border-radius:8px;margin:15px 0">'
                            .'<p style="margin:5px 0"><strong>주문번호:</strong> {order_number}</p>'
                            .'<p style="margin:5px 0"><strong>주문자:</strong> {customer_name}</p>'
                            .'<p style="margin:5px 0"><strong>결제금액:</strong> {total_amount}</p>'
                            .'</div>'
                            .'<p style="color:#555;line-height:1.6">관리자 페이지에서 주문 상세를 확인해 주세요.</p>'
                            .$this->button('주문 관리 바로가기', '{order_url}')
                            .'<hr style="border:none;border-top:1px solid #eee;margin:20px 0">'
                            .'<p style="color:#999;font-size:12px">본 메일은 {app_name}에서 발송되었습니다.</p>'
                            .'</div>',
                        'en' => '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px">'
                            .'<h2 style="color:#333;border-bottom:2px solid #4F46E5;padding-bottom:10px">New Order Received</h2>'
                            .'<p style="color:#555;line-height:1.6">Dear {name}, a new order has been received.</p>'
                            .'<div style="background:#f8f9fa;padding:15px;border-radius:8px;margin:15px 0">'
                            .'<p style="margin:5px 0"><strong>Order Number:</strong> {order_number}</p>'
                            .'<p style="margin:5px 0"><strong>Customer:</strong> {customer_name}</p>'
                            .'<p style="margin:5px 0"><strong>Total Amount:</strong> {total_amount}</p>'
                            .'</div>'
                            .'<p style="color:#555;line-height:1.6">Please review the order details in the admin panel.</p>'
                            .$this->button('Go to Order Management', '{order_url}')
                            .'<hr style="border:none;border-top:1px solid #eee;margin:20px 0">'
                            .'<p style="color:#999;font-size:12px">This email was sent from {app_name}.</p>'
                            .'</div>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'role', 'value' => 'admin', 'exclude_trigger_user' => true]],
                    'subject' => ['ko' => '새로운 주문이 접수되었습니다', 'en' => 'New order received'],
                    'body' => ['ko' => '{customer_name}님이 주문번호 {order_number} (결제금액: {total_amount})을 접수했습니다.', 'en' => '{customer_name} placed order {order_number} (total: {total_amount}).'],
                    'click_url' => '{order_url}',
                ],
            ],
        ];
    }

    /**
     * 상품 문의 접수 관리자 알림 정의.
     *
     * @return array
     */
    private function inquiryReceivedDefinition(): array
    {
        return [
            'type' => 'inquiry_received',
            'hook_prefix' => 'sirsoft-ecommerce',
            'extension_type' => 'module',
            'extension_identifier' => 'sirsoft-ecommerce',
            'name' => ['ko' => '상품 문의 접수', 'en' => 'Inquiry Received'],
            'description' => ['ko' => '상품 문의 접수 시 관리자에게 발송', 'en' => 'Sent to admin when a product inquiry is received'],
            'channels' => ['mail', 'database'],
            'hooks' => ['sirsoft-ecommerce.product_inquiry.after_create'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자(관리자) 이름'],
                ['key' => 'app_name', 'description' => '사이트 이름'],
                ['key' => 'product_name', 'description' => '상품명'],
                ['key' => 'customer_name', 'description' => '문의자 이름'],
                ['key' => 'inquiry_content', 'description' => '문의 내용 (요약)'],
                ['key' => 'inquiry_url', 'description' => '문의 관리 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'role', 'value' => 'admin', 'exclude_trigger_user' => true]],
                    'subject' => [
                        'ko' => '[{app_name}] 새로운 상품 문의가 접수되었습니다',
                        'en' => '[{app_name}] New product inquiry received',
                    ],
                    'body' => [
                        'ko' => '<div style="font-family:\'Malgun Gothic\',sans-serif;max-width:600px;margin:0 auto;padding:20px">'
                            .'<h2 style="color:#333;border-bottom:2px solid #4F46E5;padding-bottom:10px">새 상품 문의 접수</h2>'
                            .'<p style="color:#555;line-height:1.6">{name}님, 새로운 상품 문의가 접수되었습니다.</p>'
                            .'<div style="background:#f8f9fa;padding:15px;border-radius:8px;margin:15px 0">'
                            .'<p style="margin:5px 0"><strong>상품명:</strong> {product_name}</p>'
                            .'<p style="margin:5px 0"><strong>문의자:</strong> {customer_name}</p>'
                            .'<p style="margin:5px 0"><strong>문의 내용:</strong> {inquiry_content}</p>'
                            .'</div>'
                            .'<p style="color:#555;line-height:1.6">관리자 페이지에서 문의 내용을 확인하고 답변해 주세요.</p>'
                            .$this->button('문의 확인하기', '{inquiry_url}')
                            .'<hr style="border:none;border-top:1px solid #eee;margin:20px 0">'
                            .'<p style="color:#999;font-size:12px">본 메일은 {app_name}에서 발송되었습니다.</p>'
                            .'</div>',
                        'en' => '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px">'
                            .'<h2 style="color:#333;border-bottom:2px solid #4F46E5;padding-bottom:10px">New Product Inquiry</h2>'
                            .'<p style="color:#555;line-height:1.6">Dear {name}, a new product inquiry has been received.</p>'
                            .'<div style="background:#f8f9fa;padding:15px;border-radius:8px;margin:15px 0">'
                            .'<p style="margin:5px 0"><strong>Product:</strong> {product_name}</p>'
                            .'<p style="margin:5px 0"><strong>Customer:</strong> {customer_name}</p>'
                            .'<p style="margin:5px 0"><strong>Inquiry:</strong> {inquiry_content}</p>'
                            .'</div>'
                            .'<p style="color:#555;line-height:1.6">Please review the inquiry and provide a response in the admin panel.</p>'
                            .$this->button('View Inquiry', '{inquiry_url}')
                            .'<hr style="border:none;border-top:1px solid #eee;margin:20px 0">'
                            .'<p style="color:#999;font-size:12px">This email was sent from {app_name}.</p>'
                            .'</div>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'role', 'value' => 'admin', 'exclude_trigger_user' => true]],
                    'subject' => ['ko' => '새로운 상품 문의가 접수되었습니다', 'en' => 'New product inquiry received'],
                    'body' => ['ko' => '{customer_name}님이 "{product_name}" 상품에 문의를 남겼습니다.', 'en' => '{customer_name} left an inquiry on "{product_name}".'],
                    'click_url' => '{inquiry_url}',
                ],
            ],
        ];
    }

    /**
     * 문의 답변 완료 알림 정의.
     *
     * @return array
     */
    private function inquiryRepliedDefinition(): array
    {
        return [
            'type' => 'inquiry_replied',
            'hook_prefix' => 'sirsoft-ecommerce',
            'extension_type' => 'module',
            'extension_identifier' => 'sirsoft-ecommerce',
            'name' => ['ko' => '문의 답변 완료', 'en' => 'Inquiry Replied'],
            'description' => ['ko' => '문의 답변 시 고객에게 발송', 'en' => 'Sent to customer when inquiry is replied'],
            'channels' => ['mail', 'database'],
            'hooks' => ['sirsoft-ecommerce.product_inquiry.after_reply'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자(문의자) 이름'],
                ['key' => 'app_name', 'description' => '사이트 이름'],
                ['key' => 'product_name', 'description' => '상품명'],
                ['key' => 'inquiry_content', 'description' => '원래 문의 내용 (요약)'],
                ['key' => 'inquiry_url', 'description' => '문의 상세 URL (마이페이지)'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'related_user', 'relation' => 'author']],
                    'subject' => [
                        'ko' => '[{app_name}] 상품 문의에 답변이 등록되었습니다',
                        'en' => '[{app_name}] Your product inquiry has been answered',
                    ],
                    'body' => [
                        'ko' => '<div style="font-family:\'Malgun Gothic\',sans-serif;max-width:600px;margin:0 auto;padding:20px">'
                            .'<h2 style="color:#333;border-bottom:2px solid #4F46E5;padding-bottom:10px">문의 답변 안내</h2>'
                            .'<p style="color:#555;line-height:1.6">{name}님, 문의하신 내용에 답변이 등록되었습니다.</p>'
                            .'<div style="background:#f8f9fa;padding:15px;border-radius:8px;margin:15px 0">'
                            .'<p style="margin:5px 0"><strong>상품명:</strong> {product_name}</p>'
                            .'<p style="margin:5px 0"><strong>문의 내용:</strong> {inquiry_content}</p>'
                            .'</div>'
                            .'<p style="color:#555;line-height:1.6">아래 버튼을 클릭하여 답변 내용을 확인하세요.</p>'
                            .$this->button('답변 확인하기', '{inquiry_url}')
                            .'<hr style="border:none;border-top:1px solid #eee;margin:20px 0">'
                            .'<p style="color:#999;font-size:12px">본 메일은 {app_name}에서 발송되었습니다.</p>'
                            .'</div>',
                        'en' => '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px">'
                            .'<h2 style="color:#333;border-bottom:2px solid #4F46E5;padding-bottom:10px">Inquiry Answered</h2>'
                            .'<p style="color:#555;line-height:1.6">Dear {name}, your product inquiry has been answered.</p>'
                            .'<div style="background:#f8f9fa;padding:15px;border-radius:8px;margin:15px 0">'
                            .'<p style="margin:5px 0"><strong>Product:</strong> {product_name}</p>'
                            .'<p style="margin:5px 0"><strong>Your Inquiry:</strong> {inquiry_content}</p>'
                            .'</div>'
                            .'<p style="color:#555;line-height:1.6">Click the button below to view the response.</p>'
                            .$this->button('View Response', '{inquiry_url}')
                            .'<hr style="border:none;border-top:1px solid #eee;margin:20px 0">'
                            .'<p style="color:#999;font-size:12px">This email was sent from {app_name}.</p>'
                            .'</div>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'related_user', 'relation' => 'author']],
                    'subject' => ['ko' => '문의 답변이 등록되었습니다', 'en' => 'Your inquiry has been answered'],
                    'body' => ['ko' => '{name}님, "{product_name}" 상품 문의에 답변이 등록되었습니다.', 'en' => '{name}, your inquiry on "{product_name}" has been answered.'],
                    'click_url' => '{inquiry_url}',
                ],
            ],
        ];
    }

    /**
     * 이메일용 CTA 버튼 HTML을 반환합니다.
     *
     * @param string $text 버튼 텍스트
     * @param string $url 버튼 링크
     * @return string 인라인 스타일 버튼 HTML
     */
    private function button(string $text, string $url): string
    {
        return '<div style="text-align:center;margin:25px 0">'
            .'<a href="'.$url.'" style="display:inline-block;padding:12px 30px;'
            .'background-color:#4F46E5;color:#ffffff;text-decoration:none;'
            .'border-radius:6px;font-weight:bold">'.$text.'</a>'
            .'</div>';
    }
}
