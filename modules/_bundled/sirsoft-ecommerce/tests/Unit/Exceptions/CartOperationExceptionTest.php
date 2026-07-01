<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Exceptions;

use Modules\Sirsoft\Ecommerce\Exceptions\CartOperationException;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * CartOperationException 단위 테스트 (MP07 §1-c)
 *
 * 장바구니 단건 조작 예외가 사유별로 올바른 HTTP 상태코드와 메시지 키를 매핑하는지 가드한다.
 * (항목없음 404 / 권한없음 403 / 옵션없음·타상품옵션 422)
 */
class CartOperationExceptionTest extends ModuleTestCase
{
    /**
     * @return array<string, array{string, int, string}>
     */
    public static function reasonProvider(): array
    {
        return [
            'item_not_found → 404' => ['item_not_found', 404, 'sirsoft-ecommerce::exceptions.cart_item_not_found'],
            'access_denied → 403' => ['access_denied', 403, 'sirsoft-ecommerce::exceptions.cart_access_denied'],
            'option_not_found → 422' => ['option_not_found', 422, 'sirsoft-ecommerce::exceptions.option_not_found'],
            'invalid_option → 422' => ['invalid_option', 422, 'sirsoft-ecommerce::exceptions.invalid_option_for_product'],
        ];
    }

    #[DataProvider('reasonProvider')]
    public function test_reason_maps_to_status_and_message_key(string $reason, int $status, string $key): void
    {
        $e = new CartOperationException($reason);

        $this->assertSame($reason, $e->getReason());
        $this->assertSame($status, $e->getStatusCode());
        $this->assertSame($key, $e->getMessageKey());
        // 메시지는 __() 로 해석된 비-키 문자열이어야 한다 (하드코딩 금지 규율).
        $this->assertNotSame($key, $e->getMessage());
        $this->assertNotEmpty($e->getMessage());
    }

    /**
     * 미지정/알 수 없는 reason 은 안전하게 422(option_not_found) 로 폴백한다.
     */
    public function test_unknown_reason_falls_back_to_422(): void
    {
        $e = new CartOperationException('something_else');
        $this->assertSame(422, $e->getStatusCode());
        $this->assertSame('sirsoft-ecommerce::exceptions.option_not_found', $e->getMessageKey());
    }
}
