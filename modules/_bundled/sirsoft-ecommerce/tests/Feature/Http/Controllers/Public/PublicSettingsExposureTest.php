<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Public;

use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 공개 설정 API 의 노출 키 테스트
 *
 * 비회원도 부를 수 있는 설정 API 가 설정 카테고리 원본을 통째로 돌려주지 않고,
 * 설정 기본값 파일의 공개 키 목록(public_api_schema)에 있는 키만 돌려준다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-ecommerce/tests/scenarios/public-settings-api-exposure.yaml
 */
class PublicSettingsExposureTest extends ModuleTestCase
{
    private const BASE = '/api/modules/sirsoft-ecommerce/settings';

    /**
     * 설정 기본값 파일의 공개 키 목록을 읽습니다.
     *
     * @return array<string, array<int, string>> 카테고리별 공개 키
     */
    private function publicSchema(): array
    {
        $defaults = json_decode(
            file_get_contents(dirname(__DIR__, 5).'/config/settings/defaults.json'),
            true
        );

        return $defaults['public_api_schema'] ?? [];
    }

    /**
     * 배송·후기 설정 응답의 키가 공개 키 목록과 같다 (운영 내부 값은 나가지 않는다).
     *
     * @scenario endpoint=shipping_and_review, viewer=guest
     *
     * @effects shipping_settings_only_public_keys, review_settings_only_public_keys
     *
     * @return void 반환값 없음
     */
    public function test_shipping_and_review_settings_return_only_public_keys(): void
    {
        $schema = $this->publicSchema();

        $shipping = $this->getJson(self::BASE.'/shipping')->assertOk()->json('data.shipping');
        $this->assertEqualsCanonicalizing($schema['shipping'], array_keys($shipping));
        foreach (['free_shipping_threshold'] as $internal) {
            $this->assertArrayNotHasKey($internal, $shipping);
        }

        $review = $this->getJson(self::BASE.'/review')->assertOk()->json('data.review_settings');
        $this->assertEqualsCanonicalizing($schema['review_settings'], array_keys($review));
        $this->assertArrayNotHasKey('author_name_masking', $review);
    }

    /**
     * 결제 설정 응답도 공개 키만 싣는다 (주문서가 쓰는 결제수단·은행·입금 계좌·입금 기한은 그대로).
     *
     * @scenario endpoint=payment, viewer=guest
     *
     * @effects payment_settings_only_public_keys, payment_settings_keep_checkout_keys
     *
     * @return void 반환값 없음
     */
    public function test_payment_settings_return_only_public_keys(): void
    {
        $schema = $this->publicSchema();

        $order = $this->getJson(self::BASE.'/payment')->assertOk()->json('data.order_settings');

        $this->assertEmpty(array_diff(array_keys($order), $schema['order_settings']));
        foreach (['payment_methods', 'banks', 'auto_cancel_days'] as $needed) {
            $this->assertArrayHasKey($needed, $order);
        }
        foreach (['stock_restore_on_cancel', 'cart_expiry_days', 'pending_order_expire_minutes', 'shipping_fee_tax_policy', 'cancellable_statuses'] as $internal) {
            $this->assertArrayNotHasKey($internal, $order);
        }
    }

    /**
     * 묶음 응답(checkout)도 두 카테고리 모두 같은 공개 키만 싣는다.
     *
     * @scenario endpoint=checkout, viewer=guest
     *
     * @effects checkout_settings_use_the_same_public_keys
     *
     * @return void 반환값 없음
     */
    public function test_checkout_settings_use_the_same_public_keys(): void
    {
        $schema = $this->publicSchema();

        $data = $this->getJson(self::BASE.'/checkout')->assertOk()->json('data');

        $this->assertEqualsCanonicalizing($schema['shipping'], array_keys($data['shipping']));
        $this->assertEmpty(array_diff(array_keys($data['order_settings']), $schema['order_settings']));
        $this->assertArrayNotHasKey('stock_restore_on_cancel', $data['order_settings']);
    }
}
