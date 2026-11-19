<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Support\SafeExceptionMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Contracts\Catalog\Data\SimpleProductSpec;
use Modules\Sirsoft\Ecommerce\Contracts\Catalog\ProductProvisioner;
use Modules\Sirsoft\Ecommerce\Support\EcommerceScreenUrl;

/**
 * KG 이니시스 CBT (일본 결제) 테스트용 상품 자동 생성 컨트롤러
 *
 * Japan 결제 (CBT, JPPG) 검증을 위해 JPY 가격이 설정된 샘플 상품을
 * 한 번에 생성한다. 운영자가 admin 상품 폼에서 JPY 가격 필드를 일일이
 * 채울 필요를 줄여 CBT 테스트 진입 장벽을 낮춘다.
 *
 * 생성은 이커머스 공개 계약 {@see ProductProvisioner} 에 맡긴다(#916). 가격은 명세 통화 JPY 100 이다 —
 * 상점 기본 통화가 KRW 여도 지금 환율로 기본 통화 가격을 역산해 저장하므로 결제 때 정확히 100엔이 된다
 * (환율이 바뀌면 엔화 금액도 바뀐다. JPY 환율이 없는 상점이면 생성에 실패한다).
 */
class AdminCbtTestProductController extends AdminBaseController
{
    /**
     * 컨트롤러를 생성합니다.
     *
     * @param  ProductProvisioner  $productProvisioner  테스트 상품을 생성하는 이커머스 공개 계약
     */
    public function __construct(
        private readonly ProductProvisioner $productProvisioner,
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
            // audit:allow datetime-display-user-timezone reason: 시험 상품 코드 고유 접미사(서버 시각) — 기계값
            $timestamp = now()->format('YmdHis');
            $productCode = 'CBT-TEST-'.$timestamp;

            // name/description 은 다국어 배열 그대로 저장된다 (ko/en/ja).
            // 기본 옵션 1행은 계약이 만든다 — 옵션 없는 상품은 장바구니 담기가 「존재하지 않는 옵션입니다」로 실패한다.
            $product = $this->productProvisioner->createSimpleProduct(new SimpleProductSpec(
                name: [
                    'ko' => '[테스트] CBT 일본 결제',
                    'en' => '[Test] CBT Japan Payment',
                    'ja' => '[テスト] CBT 日本決済',
                ],
                productCode: $productCode,
                sellingPrice: 100,    // 100엔 — CBT 테스트 최소 단위
                stockQuantity: 9999,
                sku: 'KGINICIS-'.$productCode,
                listPrice: 100,
                description: [
                    'ko' => 'KG 이니시스 일본 결제(CBT, JPPG) 테스트 전용 상품입니다.',
                    'en' => 'KG Inicis Japan Payment (CBT, JPPG) test product.',
                    'ja' => 'KG イニシス 日本決済 (CBT, JPPG) テスト用商品です。',
                ],
                salesStatus: 'on_sale',
                displayStatus: 'visible',
                defaultOptionName: ['ko' => '기본', 'en' => 'Default', 'ja' => 'デフォルト'],
                defaultOptionCode: 'default',
                currency: 'JPY',
            ));

            Log::info('KG Inicis CBT: test product created', [
                'product_id' => $product->id,
                'product_code' => $product->productCode,
                'stored_selling_price' => $product->storedSellingPrice,
                'stored_currency' => $product->storedCurrency,
            ]);

            return ResponseHelper::success('common.success', [
                'product_id' => $product->id,
                'product_code' => $product->productCode,
                'admin_url' => '/admin/ecommerce/products/'.$product->id.'/edit',
                // 상점 주소는 운영자 설정이라 리터럴로 두면 주소를 바꾼 상점에서 없는 페이지를 가리킨다.
                // 화면과 같은 상품 코드 주소(#792 W12) — 화면 주소 래퍼로 「사용자 화면 URL」·주소 필터를 따른다
                'shop_url' => EcommerceScreenUrl::product($product->productCode).'?locale=ja',
            ]);
        } catch (\InvalidArgumentException $e) {
            // 쇼핑몰 통화 설정에 엔화가 없거나 환율이 없다 — 100엔 상품 가격을 정할 수 없으니 원인을 알리고 멈춘다
            Log::warning('KG Inicis CBT: test product needs JPY currency', ['error' => $e->getMessage()]);

            return ResponseHelper::error('sirsoft-pay_kginicis::messages.cbt_test_product.jpy_required', 422);
        } catch (\Throwable $e) {
            Log::error('KG Inicis CBT: test product creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // 배열 errors 는 운영 모드 500 차단을 거치지 않는다 — DB 예외 원문(SQL·DB 접속 정보)은 일반 문구로 바꾼다(#858)
            return ResponseHelper::error('common.failed', 500, [
                'detail' => SafeExceptionMessage::for($e, ['plugin' => 'sirsoft-pay_kginicis', 'action' => 'cbt_test_product']),
            ]);
        }
    }
}
