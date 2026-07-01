<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Services\ProductService;

/**
 * KG 이니시스 CBT (일본 결제) 테스트용 상품 자동 생성 컨트롤러
 *
 * Japan 결제 (CBT, JPPG) 검증을 위해 JPY 가격이 설정된 샘플 상품을
 * 한 번에 생성한다. 운영자가 admin 상품 폼에서 JPY 가격 필드를 일일이
 * 채울 필요를 줄여 CBT 테스트 진입 장벽을 낮춘다.
 */
class AdminCbtTestProductController extends AdminBaseController
{
    public function __construct(
        private readonly ProductService $productService,
    ) {
        parent::__construct();
    }

    /**
     * CBT 테스트용 JPY 상품 생성
     *
     * @return JsonResponse 생성 결과 (성공 시 product_id / slug 반환)
     */
    public function create(): JsonResponse
    {
        try {
            $timestamp = now()->format('YmdHis');
            $productCode = 'CBT-TEST-' . $timestamp;

            // ProductService 가 currency_code 를 모듈 default_currency 로 자동 설정.
            // 운영 환경의 default_currency 가 JPY 이면 selling_price 가 JPY 로 해석됨.
            // name/description 은 AsUnicodeJson 캐스트 — 배열로 전달 (ko/en/ja 다국어)
            // options 배열은 옵션 없는 상품도 default 1행 필수 — CartService 가 default option 을
            // 못 찾으면 "존재하지 않는 옵션입니다" 예외를 던져 장바구니 담기가 실패한다.
            $product = $this->productService->create([
                'name'           => [
                    'ko' => '[테스트] CBT 일본 결제',
                    'en' => '[Test] CBT Japan Payment',
                    'ja' => '[テスト] CBT 日本決済',
                ],
                'product_code'   => $productCode,
                'sku'            => 'KGINICIS-' . $productCode,
                'selling_price'  => 100,    // 100엔 — CBT 테스트 최소 단위
                'list_price'     => 100,
                'stock_quantity' => 9999,
                'description'    => [
                    'ko' => 'KG 이니시스 일본 결제(CBT, JPPG) 테스트 전용 상품입니다.',
                    'en' => 'KG Inicis Japan Payment (CBT, JPPG) test product.',
                    'ja' => 'KG イニシス 日本決済 (CBT, JPPG) テスト用商品です。',
                ],
                'sales_status'   => 'on_sale',
                'display_status' => 'visible',
                'options'        => [
                    [
                        'option_code'     => 'default',
                        'option_values'   => [],
                        'option_name'     => ['ko' => '기본', 'en' => 'Default', 'ja' => 'デフォルト'],
                        'stock_quantity'  => 9999,
                        'is_default'      => true,
                        'is_active'       => true,
                    ],
                ],
            ]);

            Log::info('KG Inicis CBT: test product created', [
                'product_id'   => $product->id,
                'product_code' => $product->product_code,
            ]);

            return ResponseHelper::success('messages.success', [
                'product_id'   => $product->id,
                'product_code' => $product->product_code,
                'admin_url'    => '/admin/ecommerce/products/' . $product->id . '/edit',
                'shop_url'     => '/shop/products/' . $product->id . '?locale=ja',
            ]);
        } catch (\Throwable $e) {
            Log::error('KG Inicis CBT: test product creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::error('messages.failed', 500, ['detail' => $e->getMessage()]);
        }
    }
}
