<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Lang;

use App\Helpers\ResponseHelper;
use App\Models\ActivityLog;
use Modules\Sirsoft\Ecommerce\Exceptions\CartQuantityLimitException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Exceptions\ProductHasOrderHistoryException;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * 이커머스 백엔드 영어 메시지의 단·복수 테스트.
 *
 * 종전 영어 문구는 `:count item(s)` 처럼 수와 상관없이 적었다. 프런트 사전과 같게 1 이면 단수,
 * 0·2 이상이면 복수로 고르고(`단수|복수` + trans_choice), 한국어 문구는 그대로다.
 */
class BackendPluralMessagesTest extends ModuleTestCase
{
    /**
     * 사전 키별 [치환값, 수 자리표시자 이름, 1일 때 영어, 2일 때 영어].
     *
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: string, 3: string, 4: string}> 데이터 이름 => [다국어 키, 수 외 치환값, 수 자리표시자, 1일 때 영어, 2일 때 영어]
     */
    public static function ecommerceKeys(): array
    {
        $log = 'sirsoft-ecommerce::activity_log.description.';
        $claim = ['claim_number' => 'C1'];
        $tracking = ['claim_number' => 'C1', 'tracking_number' => 'T1'];
        $rows = [
            'order_option_sync_status' => [$log.'order_option_sync_status', ['order_number' => 'O1'], 'count',
                'Item status changed by order status sync (O1, 1 item)', 'Item status changed by order status sync (O1, 2 items)'],
            'order_tax_invoice_stale' => [$log.'order_tax_invoice_stale', ['order_number' => 'O1'], 'count',
                'Tax invoice not handled after cancellation (O1, 1 invoice)', 'Tax invoice not handled after cancellation (O1, 2 invoices)'],
        ];

        foreach (['exchange' => 'Exchange request', 'return' => 'Return request', 'refund' => 'Refund request', 'cancel' => 'Cancellation record'] as $type => $label) {
            $rows["claim_{$type}_option_status_change"] = [$log."claim_{$type}_option_status_change", $claim, 'count',
                "{$label} item status changed (C1, 1 item)", "{$label} item status changed (C1, 2 items)"];
        }

        foreach ([
            'claim_exchange_collection_tracking_update' => 'Exchange request collection tracking changed',
            'claim_exchange_redelivery_tracking_update' => 'Exchange request redelivery tracking changed',
            'claim_return_collection_tracking_update' => 'Return request collection tracking changed',
        ] as $key => $label) {
            $rows[$key] = [$log.$key, $claim, 'count', "{$label} (C1 · 1 item)", "{$label} (C1 · 2 items)"];
        }

        foreach ([
            'claim_exchange_collection_tracking_deliver' => 'Exchange collection tracking marked as arrived',
            'claim_exchange_redelivery_tracking_deliver' => 'Exchange redelivery tracking marked as arrived',
            'claim_return_collection_tracking_deliver' => 'Return collection tracking marked as arrived',
        ] as $key => $label) {
            $rows[$key] = [$log.$key, $tracking, 'count', "{$label} (C1 · T1 · 1 item)", "{$label} (C1 · T1 · 2 items)"];
        }

        $rows['claim_exchange_stock_restore'] = [$log.'claim_exchange_stock_restore', $claim + ['quantity' => 5], 'count',
            'Exchange claim stock restored (C1 · 1 item · qty 5)', 'Exchange claim stock restored (C1 · 2 items · qty 5)'];
        $rows['claim_return_stock_restore'] = [$log.'claim_return_stock_restore', $claim + ['quantity' => 5], 'count',
            'Return claim stock restored (C1 · 1 item · qty 5)', 'Return claim stock restored (C1 · 2 items · qty 5)'];

        $p = 'sirsoft-ecommerce::';

        return $rows + [
            'exceptions.claim_quantity_locked' => [$p.'exceptions.claim_quantity_locked', ['available' => 0], 'requested',
                'Another request is already in progress, so 1 item cannot be requested. (Available: 0)',
                'Another request is already in progress, so 2 items cannot be requested. (Available: 0)'],
            'products.has_order_history' => [$p.'messages.products.has_order_history', [], 'count',
                'This product has 1 order and cannot be deleted.', 'This product has 2 orders and cannot be deleted.'],
            'options.bulk_updated' => [$p.'messages.options.bulk_updated', [], 'count',
                '1 option has been updated.', '2 options have been updated.'],
            'coupons.direct_issued' => [$p.'messages.coupons.direct_issued', [], 'issued',
                'Coupon issued to 1 member.', 'Coupon issued to 2 members.'],
            'coupons.direct_issued_with_skip' => [$p.'messages.coupons.direct_issued_with_skip', ['skipped' => 3], 'issued',
                'Coupon issued to 1 member. (3 excluded for not meeting issue conditions)',
                'Coupon issued to 2 members. (3 excluded for not meeting issue conditions)'],
            'cart.admin_bulk_deleted' => [$p.'messages.cart.admin_bulk_deleted', [], 'count',
                '1 cart item deleted.', '2 cart items deleted.'],
            'wishlist.admin_bulk_deleted' => [$p.'messages.wishlist.admin_bulk_deleted', [], 'count',
                '1 wishlist item deleted.', '2 wishlist items deleted.'],
            'user_address.admin_bulk_deleted' => [$p.'messages.user_address.admin_bulk_deleted', [], 'count',
                '1 address deleted.', '2 addresses deleted.'],
            'product_inquiry.admin_bulk_deleted' => [$p.'messages.product_inquiry.admin_bulk_deleted', [], 'count',
                '1 inquiry deleted.', '2 inquiries deleted.'],
            'orders.status_transition.bulk_invalid' => [$p.'validation.orders.status_transition.bulk_invalid', ['to' => 'Shipped', 'from' => 'Cancelled'], 'count',
                '1 item cannot be changed to Shipped. (current: Cancelled)', '2 items cannot be changed to Shipped. (current: Cancelled)'],
            'cart.quantity_limit_exceeded' => [$p.'validation.cart.quantity_limit_exceeded', ['attempted' => 3], 'limit',
                'You can add up to 1 item per product to the cart. (requested: 3)', 'You can add up to 2 items per product to the cart. (requested: 3)'],
        ];
    }

    /**
     * 각 영어 메시지는 1 이면 단수, 0·2 이면 복수로 나온다.
     *
     * @scenario surface=dictionary
     *
     * @effects english_message_singular_for_one_plural_otherwise
     *
     * @param  string  $key  다국어 키
     * @param  array<string, mixed>  $params  수 외 치환값
     * @param  string  $numberParam  수 자리표시자 이름
     * @param  string  $one  1 일 때 기대 문구
     * @param  string  $two  2 일 때 기대 문구
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('ecommerceKeys')]
    public function english_message_is_singular_for_one_and_plural_otherwise(string $key, array $params, string $numberParam, string $one, string $two): void
    {
        $this->app->setLocale('en');

        $this->assertSame($one, trans_choice($key, 1, $params + [$numberParam => 1]));
        $this->assertSame($two, trans_choice($key, 2, $params + [$numberParam => 2]));
        $this->assertSame(preg_replace('/(?<!\d)2(?!\d)/', '0', $two, 1), trans_choice($key, 0, $params + [$numberParam => 0]), "{$key}: 0 은 복수");
    }

    /**
     * 한국어 문구는 복수 구분 기호가 없고, 복수 고르기 호출로 바꿔도 결과가 `__()` 와 같다.
     *
     * @scenario surface=dictionary
     *
     * @effects korean_message_unchanged
     *
     * @param  string  $key  다국어 키
     * @param  array<string, mixed>  $params  수 외 치환값
     * @param  string  $numberParam  수 자리표시자 이름
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('ecommerceKeys')]
    public function korean_message_is_unchanged(string $key, array $params, string $numberParam): void
    {
        $this->app->setLocale('ko');

        foreach ([1, 2] as $n) {
            $replace = $params + [$numberParam => $n];
            $legacy = __($key, $replace);

            $this->assertStringNotContainsString('|', $legacy, "{$key}: 한국어 값에 복수 구분 기호가 있다");
            $this->assertSame($legacy, trans_choice($key, $n, $replace));
        }
    }

    /**
     * 활동 로그 화면 문구는 저장된 `count`(문자열) 로 단·복수를 고른다.
     *
     * @scenario surface=activity_log
     *
     * @effects activity_log_description_follows_stored_count
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function activity_log_description_follows_stored_count(): void
    {
        $this->app->setLocale('en');

        $log = (new ActivityLog)->forceFill([
            'description_key' => 'sirsoft-ecommerce::activity_log.description.claim_return_option_status_change',
            'description_params' => ['claim_number' => 'C1', 'count' => '1'],
        ]);
        $this->assertSame('Return request item status changed (C1, 1 item)', $log->localized_description);

        $log->description_params = ['claim_number' => 'C1', 'count' => '3'];
        $this->assertSame('Return request item status changed (C1, 3 items)', $log->localized_description);

        $this->app->setLocale('ko');
        $this->assertSame(
            __('sirsoft-ecommerce::activity_log.description.claim_return_option_status_change', ['claim_number' => 'C1', 'count' => '3']),
            $log->localized_description
        );
    }

    /**
     * 예외 메시지와 그 예외로 만든 API 응답 문구가 수 1 에서 단수다.
     *
     * @scenario surface=exception_response
     *
     * @effects exception_and_response_message_follow_number
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function exception_messages_and_responses_follow_the_number(): void
    {
        $this->app->setLocale('en');

        $locked = OrderClaimException::quantityLocked(1, 0);
        $this->assertSame('Another request is already in progress, so 1 item cannot be requested. (Available: 0)', $locked->getMessage());
        $this->assertSame(
            'Another request is already in progress, so 1 item cannot be requested. (Available: 0)',
            ResponseHelper::error($locked->getMessageKey(), 409, null, $locked->getMessageParams())->getData(true)['message']
        );

        $this->assertSame('You can add up to 1 item per product to the cart. (requested: 2)', (new CartQuantityLimitException(1, 2))->getMessage());
        $this->assertSame('This product has 1 order and cannot be deleted.', (new ProductHasOrderHistoryException(1))->getMessage());
        $this->assertSame(
            '1 cart item deleted.',
            ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.cart.admin_bulk_deleted', null, 200, ['count' => 1])->getData(true)['message']
        );

        $this->app->setLocale('ko');
        $this->assertSame(
            __('sirsoft-ecommerce::exceptions.claim_quantity_locked', ['requested' => 1, 'available' => 0]),
            OrderClaimException::quantityLocked(1, 0)->getMessage()
        );
    }
}
