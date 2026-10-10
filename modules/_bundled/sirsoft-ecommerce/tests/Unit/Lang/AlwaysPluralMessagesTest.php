<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Lang;

use App\Models\ActivityLog;
use Modules\Sirsoft\Ecommerce\Exceptions\BrandOperationException;
use Modules\Sirsoft\Ecommerce\Exceptions\CategoryOperationException;
use Modules\Sirsoft\Ecommerce\Exceptions\ClaimReasonException;
use Modules\Sirsoft\Ecommerce\Exceptions\CurrencySettingLockedException;
use Modules\Sirsoft\Ecommerce\Exceptions\InsufficientStockException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Exceptions\ProductImageUploadLimitException;
use Modules\Sirsoft\Ecommerce\Exceptions\ProductLabelOperationException;
use Modules\Sirsoft\Ecommerce\Exceptions\ReviewImageUploadLimitException;
use Modules\Sirsoft\Ecommerce\Exceptions\ShippingCarrierOperationException;
use Modules\Sirsoft\Ecommerce\Exceptions\ShippingTypeOperationException;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Public\CartController;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\DeleteAllCartItemsRequest;
use Modules\Sirsoft\Ecommerce\Listeners\PrivacyRetentionListener;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Privacy\OrderRetentionHandler;
use Modules\Sirsoft\Ecommerce\Services\CartService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * 괄호 없이 늘 복수로 적혀 있던 쇼핑몰 서버 영어 문구의 단·복수 테스트.
 *
 * 「(1 items)」·「1 products have been updated.」·「(Used 1 times)」 처럼 늘 복수였던 문구를 `단수|복수` +
 * `trans_choice`/`trans_count` 로 1 이면 단수로 고른다. 한국어 값은 그대로다. 리터럴 키를 직접 번역하는
 * 호출부는 코어 `PluralKeyDirectTranslationScanTest` 가 `__()` 로 남지 않았음을 지킨다.
 */
class AlwaysPluralMessagesTest extends ModuleTestCase
{
    /**
     * 사전을 작업 원본(_bundled)에서 읽도록 네임스페이스를 다시 잡습니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['translator']->addNamespace('sirsoft-ecommerce', base_path('modules/_bundled/sirsoft-ecommerce/src/lang'));
    }

    /**
     * 사전 키별 [다국어 키, 수 외 치환값, 수 자리표시자, 1일 때 영어, 2일 때 영어].
     *
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: string, 3: string, 4: string}> 데이터 이름 => [키, 치환값, 수 자리표시자, 1일 때, 2일 때]
     */
    public static function ecommerceKeys(): array
    {
        $p = 'sirsoft-ecommerce::';
        $rows = [];

        foreach ([
            'product_bulk_update' => 'Products bulk updated',
            'product_bulk_price_update' => 'Product prices bulk updated',
            'product_bulk_stock_update' => 'Product stocks bulk updated',
            'order_bulk_update' => 'Orders bulk updated',
            'order_bulk_status_update' => 'Order statuses bulk updated',
            'order_bulk_shipping_update' => 'Shipping info bulk entered',
            'order_option_bulk_status_change' => 'Order option statuses bulk changed',
            'coupon_bulk_status' => 'Coupon statuses bulk changed',
            'shipping_policy_bulk_delete' => 'Shipping policies bulk deleted',
            'shipping_policy_bulk_toggle_active' => 'Shipping policy active statuses bulk changed',
            'extra_fee_template_bulk_delete' => 'Extra fee templates bulk deleted',
            'extra_fee_template_bulk_toggle_active' => 'Extra fee template active statuses bulk changed',
            'extra_fee_template_bulk_create' => 'Extra fee templates bulk created',
            'review_bulk_delete' => 'Reviews bulk deleted',
        ] as $key => $head) {
            $rows[] = [$p.'activity_log.description.'.$key, [], 'count', "{$head} (1 item)", "{$head} (2 items)"];
        }

        $used = static fn (string $head) => ["{$head} (Used 1 time)", "{$head} (Used 2 times)"];

        foreach ([
            ['activity_log.description.mileage_extend_expiry', [], 'days', 'Mileage expiry extended (1 day)', 'Mileage expiry extended (2 days)'],
            ['exceptions.mileage.use_exceeds_balance', [], 'amount', 'Cannot use more than your available mileage (1 point).', 'Cannot use more than your available mileage (2 points).'],
            ['exceptions.mileage.below_min_use_amount', [], 'amount', 'The minimum usable amount is 1 point.', 'The minimum usable amount is 2 points.'],
            ['exceptions.mileage.exceeds_max_use', [], 'amount', 'Exceeds the maximum usable limit. (up to 1 point)', 'Exceeds the maximum usable limit. (up to 2 points)'],
            ['exceptions.brand_has_products', [], 'count',
                'Cannot delete brand because it has 1 product. Please change the brand of the product first.',
                'Cannot delete brand because it has 2 products. Please change the brand of products first.'],
            ['exceptions.category_has_products', [], 'count',
                'Cannot delete category because it has 1 product. Please change the category of the product first.',
                'Cannot delete category because it has 2 products. Please change the category of products first.'],
            ['exceptions.label_has_products', [], 'count',
                'Cannot delete label because it has 1 product. Please change the label of the product first.',
                'Cannot delete label because it has 2 products. Please change the label of products first.'],
            ['exceptions.currency_setting_locked', ['setting_type' => 'base_currency'], 'product_count',
                'Cannot modify base_currency setting because 1 product exists.', 'Cannot modify base_currency setting because 2 products exist.'],
            ['exceptions.product_image_limit_exceeded', [], 'max', 'You can upload up to 1 image.', 'You can upload up to 2 images.'],
            ['exceptions.insufficient_stock', [], 'count', '1 product has insufficient stock.', '2 products have insufficient stock.'],
            ['exceptions.claim_request_period_expired', [], 'days', 'The request period (1 day) has passed.', 'The request period (2 days) has passed.'],
            ['exceptions.claim_image_limit_exceeded', [], 'max', 'You can attach up to 1 image.', 'You can attach up to 2 images.'],
            ['exceptions.claim_reason_in_use', [], 'count', ...$used('Cannot delete a reason that is already in use.')],
            ['exceptions.claim_reason_code_locked_in_use', [], 'count', ...$used('Cannot change the code of a reason that is already in use. Submitted requests reference the reason by this code.')],
            ['exceptions.claim_reason_type_locked_in_use', [], 'count', ...$used('Cannot change the type of a reason that is already in use. Submitted requests reference the reason by this code and type.')],
            ['exceptions.shipping_type_code_locked_in_use', ['name' => 'Parcel'], 'count', ...$used('Cannot change the code of a shipping type (Parcel) that is already in use. Existing orders reference the shipping type by this code.')],
            ['exceptions.shipping_type_in_use', ['name' => 'Parcel'], 'count', ...$used('Cannot delete shipping type (Parcel) in use by orders.')],
            ['exceptions.shipping_carrier_in_use', [], 'count', ...$used('Cannot delete a shipping carrier in use by orders.')],
            ['messages.products.bulk_updated', [], 'count', '1 product has been updated.', '2 products have been updated.'],
            ['messages.products.bulk_price_updated', [], 'count', 'The price of 1 product has been updated.', 'Prices of 2 products have been updated.'],
            ['messages.products.bulk_stock_updated', [], 'count', 'Stock of 1 product has been updated.', 'Stock of 2 products has been updated.'],
            ['messages.options.bulk_price_updated', [], 'count', 'The price of 1 option has been updated.', 'Prices of 2 options have been updated.'],
            ['messages.options.bulk_stock_updated', [], 'count', 'Stock of 1 option has been updated.', 'Stock of 2 options has been updated.'],
            ['messages.coupons.status_changed', [], 'count', 'Issue status of 1 coupon has been updated.', 'Issue status of 2 coupons has been updated.'],
            ['messages.coupons.valid_days_format', [], 'days', '1 day from issue date', '2 days from issue date'],
            ['messages.orders.bulk_updated', [], 'count', '1 order has been updated.', '2 orders have been updated.'],
            ['messages.orders.option_status_changed', [], 'count', 'Status of 1 option has been changed.', 'Status of 2 options has been changed.'],
            ['messages.cart.merged', [], 'count', '1 item has been merged to cart.', '2 items have been merged to cart.'],
            ['messages.cart.deleted_multiple', [], 'deleted_count', '1 item has been removed.', '2 items have been removed.'],
            ['messages.cart.deleted_all', [], 'deleted_count', 'Cart has been cleared. 1 item removed.', 'Cart has been cleared. 2 items removed.'],
            ['messages.shipping_policy.bulk_deleted', [], 'count', '1 shipping policy has been deleted.', '2 shipping policies have been deleted.'],
            ['messages.shipping_policy.bulk_toggled', [], 'count', 'The status of 1 shipping policy has been changed.', 'The status of 2 shipping policies has been changed.'],
            ['messages.extra_fee_template.bulk_deleted', [], 'count', '1 extra fee template has been deleted.', '2 extra fee templates have been deleted.'],
            ['messages.extra_fee_template.bulk_toggled', [], 'count', 'The status of 1 extra fee template has been changed.', 'The status of 2 extra fee templates has been changed.'],
            ['messages.extra_fee_template.bulk_created', [], 'count', '1 extra fee template has been created.', '2 extra fee templates have been created.'],
            ['messages.privacy_retention.notice_section.after_anonymize', [], 'years',
                'For orders closed by purchase confirmation or cancellation, the orderer and recipient information is anonymized 1 year after closing.',
                'For orders closed by purchase confirmation or cancellation, the orderer and recipient information is anonymized 2 years after closing.'],
            ['messages.privacy_retention.notice_section.after_delete', [], 'years',
                'Orders closed by purchase confirmation or cancellation are deleted together with their records 1 year after closing.',
                'Orders closed by purchase confirmation or cancellation are deleted together with their records 2 years after closing.'],
            ['review.image_upload_limit_exceeded', [], 'max', 'You can upload up to 1 review image.', 'You can upload up to 2 review images.'],
            ['validation.claims.image_ids.max', [], 'max', 'You can specify up to 1 image at once.', 'You can specify up to 2 images at once.'],
        ] as [$key, $params, $number, $one, $two]) {
            $rows[] = [$p.$key, $params, $number, $one, $two];
        }

        $cases = [];
        foreach ($rows as $row) {
            $cases[substr($row[0], strlen($p))] = $row;
        }

        return $cases;
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
        $this->assertSame(
            preg_replace('/(?<![\w.])2(?![\w.])/', '0', $two),
            trans_choice($key, 0, $params + [$numberParam => 0]),
            "{$key}: 0 은 복수"
        );
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
     * 키와 치환값을 들고 다니는 예외는 메시지를 수 1 이면 단수로 만들고, 응답용 치환값에 `count` 를 싣는다.
     *
     * @scenario surface=exception
     *
     * @effects exception_message_follows_number
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function exception_messages_are_singular_for_one(): void
    {
        $this->app->setLocale('en');
        $p = 'sirsoft-ecommerce::exceptions.';

        $this->assertSame(
            'Cannot delete brand because it has 1 product. Please change the brand of the product first.',
            (new BrandOperationException($p.'brand_has_products', ['count' => 1]))->getMessage()
        );
        $this->assertStringContainsString('has 1 product.', (new CategoryOperationException($p.'category_has_products', ['count' => 1]))->getMessage());
        $this->assertStringContainsString('has 1 product.', (new ProductLabelOperationException($p.'label_has_products', ['count' => 1]))->getMessage());
        $this->assertStringEndsWith('(Used 1 time)', (new ShippingTypeOperationException($p.'shipping_type_in_use', ['name' => 'Parcel', 'count' => 1]))->getMessage());
        $this->assertStringEndsWith('(Used 1 time)', (new ShippingCarrierOperationException($p.'shipping_carrier_in_use', ['count' => 1]))->getMessage());
        $this->assertStringEndsWith('(Used 1 time)', ClaimReasonException::inUse(1)->getMessage());
        $this->assertStringEndsWith('(Used 3 times)', ClaimReasonException::inUse(3)->getMessage());
        $this->assertSame('Cannot modify base_currency setting because 1 product exists.', (new CurrencySettingLockedException('base_currency', 1))->getMessage());
        $this->assertSame('1 product has insufficient stock.', InsufficientStockException::fromItems([['product_id' => 1]])->getMessage());
        $this->assertSame('You can upload up to 1 image.', (new ProductImageUploadLimitException(1))->getMessage());
        $this->assertSame('You can upload up to 1 review image.', (new ReviewImageUploadLimitException(1))->getMessage());

        $expired = OrderClaimException::requestPeriodExpired(1);
        $this->assertSame('The request period (1 day) has passed.', $expired->getMessage());
        $this->assertSame(1, $expired->getMessageParams()['count'] ?? null);

        $images = OrderClaimException::imageLimitExceeded(1);
        $this->assertSame('You can attach up to 1 image.', $images->getMessage());
        $this->assertSame(1, $images->getMessageParams()['count'] ?? null);
    }

    /**
     * 마일리지 만료 연장 활동 로그는 저장된 일수로 고르고, `count` 없이 `days` 만 저장한 옛 행도 그 수로 고른다.
     *
     * @scenario surface=activity_log
     *
     * @effects activity_log_description_follows_stored_number
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function mileage_expiry_activity_log_follows_stored_days(): void
    {
        $this->app->setLocale('en');
        $key = 'sirsoft-ecommerce::activity_log.description.mileage_extend_expiry';
        $log = fn (array $params) => (new ActivityLog(['description_key' => $key, 'description_params' => $params]))->localized_description;

        $this->assertSame('Mileage expiry extended (1 day)', $log(['days' => 1, 'count' => 1]));
        $this->assertSame('Mileage expiry extended (1 day)', $log(['days' => 1]));
        $this->assertSame('Mileage expiry extended (30 days)', $log(['days' => 30]));
    }

    /**
     * 쿠폰 유효기간 표기·개인정보 통지문 주문 섹션은 1 이면 단수다.
     *
     * @scenario surface=display_label
     *
     * @effects display_label_follows_number
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function display_labels_are_singular_for_one(): void
    {
        $this->app->setLocale('en');

        $coupon = new Coupon;
        $coupon->valid_type = 'days_from_issue';
        $coupon->valid_days = 1;
        $this->assertSame('1 day from issue date', $coupon->valid_period_formatted);
        $coupon->valid_days = 7;
        $this->assertSame('7 days from issue date', $coupon->valid_period_formatted);

        $handler = \Mockery::mock(OrderRetentionHandler::class);
        $handler->shouldReceive('isEnabled')->andReturn(true);
        $handler->shouldReceive('mode')->andReturn('delete');
        $handler->shouldReceive('retentionYears')->andReturn(1);
        $this->app->instance(OrderRetentionHandler::class, $handler);

        $sections = (new PrivacyRetentionListener)->addOrderNoticeSection([], 'en');
        $this->assertStringContainsString('with their records 1 year after closing.', $sections[0]['body']);
    }

    /**
     * 장바구니 전체 비우기 응답은 지운 항목이 1 개면 「1 item removed.」 다.
     *
     * @scenario surface=response_message
     *
     * @effects response_message_follows_count
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function cart_clear_response_is_singular_for_one(): void
    {
        $this->app->setLocale('en');

        foreach ([1 => 'Cart has been cleared. 1 item removed.', 3 => 'Cart has been cleared. 3 items removed.'] as $deleted => $expected) {
            $service = \Mockery::mock(CartService::class);
            $service->shouldReceive('deleteAll')->andReturn($deleted);
            $this->app->instance(CartService::class, $service);

            $request = DeleteAllCartItemsRequest::create('/', 'DELETE');
            $request->headers->set('X-Cart-Key', 'ck_probe');

            $response = $this->app->make(CartController::class)->destroyAll($request);

            $this->assertSame($expected, $response->getData(true)['message']);
        }
    }
}
