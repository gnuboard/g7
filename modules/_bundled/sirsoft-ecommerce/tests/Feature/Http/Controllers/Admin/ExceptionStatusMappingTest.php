<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Exceptions\BrandOperationException;
use Modules\Sirsoft\Ecommerce\Exceptions\CouponOperationException;
use Modules\Sirsoft\Ecommerce\Exceptions\ProductCommonInfoOperationException;
use Modules\Sirsoft\Ecommerce\Exceptions\ShippingCarrierOperationException;
use Modules\Sirsoft\Ecommerce\Services\BrandService;
use Modules\Sirsoft\Ecommerce\Services\CouponService;
use Modules\Sirsoft\Ecommerce\Services\ProductCommonInfoService;
use Modules\Sirsoft\Ecommerce\Services\ShippingCarrierService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 관리자 CRUD 엔드포인트의 예외 → 상태코드 매핑 검증 (#104)
 *
 * 코어 GenericCatchStatusCodeContractTest 가 소스 전수로 "광역 catch 는 5xx" 계약을 고정한다면,
 * 이 테스트는 그 계약이 실제 HTTP 응답까지 도달하는지를 대표 엔드포인트로 확인한다.
 *
 * 축:
 *  - 도메인 예외(typed) → 기존 4xx 유지 (운영자 안내)
 *  - 그 외 예외(인프라/코드 결함) → 500 (장애가 입력 오류로 위장되지 않음)
 */
class ExceptionStatusMappingTest extends ModuleTestCase
{
    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->createAdminUser([
            'sirsoft-ecommerce.brands.read',
            'sirsoft-ecommerce.brands.update',
            'sirsoft-ecommerce.brands.delete',
            'sirsoft-ecommerce.promotion-coupon.read',
            'sirsoft-ecommerce.promotion-coupon.update',
            'sirsoft-ecommerce.promotion-coupon.delete',
            'sirsoft-ecommerce.settings.read',
            'sirsoft-ecommerce.settings.update',
            'sirsoft-ecommerce.product-common-infos.read',
            'sirsoft-ecommerce.product-common-infos.update',
            'sirsoft-ecommerce.product-common-infos.delete',
        ]);
    }

    /**
     * 대표 엔드포인트 정의.
     *
     * [라벨, HTTP 메서드, URI, 서비스 클래스, 서비스 메서드, typed 예외 클래스|null, typed 기대 상태코드]
     *
     * @return array<string, array{0: string, 1: string, 2: class-string, 3: string, 4: class-string|null, 5: int|null}>
     */
    public static function endpointProvider(): array
    {
        $base = '/api/modules/sirsoft-ecommerce/admin';

        return [
            '브랜드 삭제' => [
                'delete', $base.'/brands/1',
                BrandService::class, 'deleteBrand',
                BrandOperationException::class, 400,
            ],
            '브랜드 상태토글' => [
                'patch', $base.'/brands/1/toggle-status',
                BrandService::class, 'toggleStatus',
                BrandOperationException::class, 400,
            ],
            '쿠폰 삭제' => [
                'delete', $base.'/promotion-coupons/1',
                CouponService::class, 'deleteCoupon',
                CouponOperationException::class, 400,
            ],
            '배송사 상태토글' => [
                'patch', $base.'/shipping-carriers/1/toggle-status',
                ShippingCarrierService::class, 'toggleStatus',
                ShippingCarrierOperationException::class, 400,
            ],
            '상품 공통정보 삭제' => [
                'delete', $base.'/product-common-infos/1',
                ProductCommonInfoService::class, 'deleteCommonInfo',
                ProductCommonInfoOperationException::class, 400,
            ],
            '상품 공통정보 사용토글' => [
                'patch', $base.'/product-common-infos/1/toggle-active',
                ProductCommonInfoService::class, 'toggleActive',
                ProductCommonInfoOperationException::class, 400,
            ],
        ];
    }

    /**
     * 도메인 예외가 아닌 예외는 500 으로 구분되어야 한다.
     *
     * @scenario error_class=infrastructure
     *
     * @effects admin_brand_delete_infrastructure_exception_returns_500,
     *   admin_brand_toggle_status_infrastructure_exception_returns_500,
     *   admin_coupon_delete_infrastructure_exception_returns_500,
     *   admin_shipping_carrier_toggle_status_infrastructure_exception_returns_500,
     *   admin_product_common_info_delete_infrastructure_exception_returns_500,
     *   admin_product_common_info_toggle_active_infrastructure_exception_returns_500,
     *   admin_crud_infrastructure_response_omits_exception_text
     */
    #[DataProvider('endpointProvider')]
    public function test_infrastructure_exception_returns_500(
        string $verb,
        string $uri,
        string $serviceClass,
        string $serviceMethod,
        ?string $typedException,
        ?int $typedStatus
    ): void {
        $this->mock($serviceClass, function ($mock) use ($serviceMethod) {
            $mock->shouldReceive($serviceMethod)
                ->andThrow(new \RuntimeException('SQLSTATE[HY000]: General error'));
            $mock->shouldReceive()->andReturnNull();
        });

        $response = $this->actingAs($this->adminUser)->{$verb.'Json'}($uri, []);

        $response->assertStatus(500);
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }

    /**
     * 도메인 예외는 기존 상태코드를 유지해야 한다 (계약 변경 없음).
     *
     * @scenario error_class=domain
     *
     * @effects admin_brand_delete_domain_exception_keeps_400,
     *   admin_brand_toggle_status_domain_exception_keeps_400,
     *   admin_coupon_delete_domain_exception_keeps_400,
     *   admin_shipping_carrier_toggle_status_domain_exception_keeps_400,
     *   admin_product_common_info_delete_domain_exception_keeps_400,
     *   admin_product_common_info_toggle_active_domain_exception_keeps_400
     */
    #[DataProvider('endpointProvider')]
    public function test_domain_exception_keeps_original_status(
        string $verb,
        string $uri,
        string $serviceClass,
        string $serviceMethod,
        ?string $typedException,
        ?int $typedStatus
    ): void {
        if ($typedException === null) {
            $this->markTestSkipped('typed 도메인 예외가 없는 엔드포인트');
        }

        $this->mock($serviceClass, function ($mock) use ($serviceMethod, $typedException) {
            $mock->shouldReceive($serviceMethod)
                ->andThrow(new $typedException('sirsoft-ecommerce::exceptions.operation_failed'));
            $mock->shouldReceive()->andReturnNull();
        });

        $response = $this->actingAs($this->adminUser)->{$verb.'Json'}($uri, []);

        $response->assertStatus($typedStatus);
    }
}
