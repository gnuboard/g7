<?php

namespace Modules\Sirsoft\Ecommerce\Support\ApiDoc;

use App\Contracts\ApiDoc\ApiDocSampleSeeder;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Sirsoft\Ecommerce\Models\Brand;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\ExtraFeeTemplate;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductCommonInfo;
use Modules\Sirsoft\Ecommerce\Models\ProductInquiry;
use Modules\Sirsoft\Ecommerce\Models\ProductLabel;
use Modules\Sirsoft\Ecommerce\Models\ProductNoticeTemplate;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Models\UserAddress;

/**
 * sirsoft-ecommerce API 문서 실측용 완전 샘플 시더
 *
 * 이커머스 도메인의 상세 GET 라우트는 대부분 route-model binding 대신 컨트롤러가
 * 서비스로 int id·문자열(product_code·slug·order_number·settings category)을 직접
 * 조회합니다(boards/{slug}/... 와 유사). docgen 의 route key 자동 치환은 이런 문자열
 * path 파라미터를 실측하지 못하므로, 이 시더는 각 도메인의 완전 샘플 레코드를 멱등
 * 생성하고 `path_params` 맵으로 id·code·slug·orderNumber 를 실제 값으로 치환할 수 있게
 * 하여 상세 조회를 실측 가능하게 합니다.
 *
 * `api:docgen --scope=module:sirsoft-ecommerce --seed` 실행 시 커맨드가 규약 위치
 * (`Modules\Sirsoft\Ecommerce\Support\ApiDoc\ApiDocSampleService`)로 자동 발견해
 * 코어 시드 뒤에 병합합니다.
 */
class ApiDocSampleService implements ApiDocSampleSeeder
{
    /**
     * @var string 샘플 상품 식별용 product_code 마커
     */
    private const SAMPLE_PRODUCT_CODE = 'APIDOCSAMPLE01';

    /**
     * @var string 샘플 카테고리 식별용 slug 마커
     */
    private const SAMPLE_CATEGORY_SLUG = 'apidoc-sample-category';

    /**
     * 이커머스 도메인 완전 샘플을 멱등 생성하고 도메인별 대표 route key + path_params 맵을 반환합니다.
     *
     * @return array<string, array{model: class-string, key: string, value: string, path_params?: array<string, string>}> 도메인 => 대표 레코드 정보
     */
    public function seed(): array
    {
        $actor = $this->sampleActor();

        $product = $this->seedProduct();
        $category = $this->seedCategory();
        $order = $actor ? $this->seedOrder($actor) : null;
        $review = ($actor && $product) ? $this->seedReview($actor, $product) : null;
        $inquiry = ($actor && $product) ? $this->seedInquiry($actor, $product) : null;
        $address = $actor ? $this->seedAddress($actor) : null;
        $mileage = $actor ? $this->seedMileageTransaction($actor) : null;

        $brand = $this->seedBrand();
        $coupon = $this->seedCoupon();
        $shippingPolicy = $this->seedShippingPolicy();
        $carrier = $this->seedShippingCarrier();
        $claimReason = $this->seedClaimReason();
        $label = $this->seedProductLabel();
        $commonInfo = $this->seedProductCommonInfo();
        $noticeTemplate = $this->seedNoticeTemplate();
        $extraFee = $this->seedExtraFeeTemplate();

        $map = [];

        // products: 공개 상세(products/{id}) 는 숫자 id + VISIBLE, admin 상세는 id/product_code 허용.
        if ($product) {
            $pid = (string) $product->getKey();
            $map['products'] = [
                'model' => Product::class,
                'key' => 'id',
                'value' => $pid,
                'path_params' => [
                    'id' => $pid,
                    'identifier' => $pid,
                    'code' => (string) $product->product_code,
                    'productId' => $pid,
                    'product' => $pid,
                ],
            ];
        }

        // categories: 공개는 slug, admin 은 id.
        if ($category) {
            $cid = (string) $category->getKey();
            $map['categories'] = [
                'model' => Category::class,
                'key' => 'id',
                'value' => $cid,
                'path_params' => [
                    'id' => $cid,
                    'slug' => (string) $category->slug,
                    'category' => $cid,
                ],
            ];
        }

        // orders: 회원 주문 상세(user/orders/{id}) + 주문번호 조회(user/orders/{orderNumber}).
        if ($order) {
            $oid = (string) $order->getKey();
            $map['orders'] = [
                'model' => Order::class,
                'key' => 'id',
                'value' => $oid,
                'path_params' => [
                    'id' => $oid,
                    'order' => $oid,
                    'orderNumber' => (string) $order->order_number,
                ],
            ];
        }

        // reviews: admin 상세는 route-model binding(id), 공개 상품 리뷰 목록은 productId.
        if ($review) {
            $rid = (string) $review->getKey();
            $map['reviews'] = [
                'model' => ProductReview::class,
                'key' => 'id',
                'value' => $rid,
                'path_params' => [
                    'review' => $rid,
                    'id' => $rid,
                    'orderOptionId' => (string) ($review->order_option_id ?? ''),
                ],
            ];
        }

        // inquiries: 상품 문의 목록 실측용 대표 문의(products/{productId}/inquiries 는 products 맵 사용).
        if ($inquiry) {
            $iid = (string) $inquiry->getKey();
            $map['inquiries'] = [
                'model' => ProductInquiry::class,
                'key' => 'id',
                'value' => $iid,
                'path_params' => ['id' => $iid, 'inquiry' => $iid],
            ];
        }

        // addresses: 본인 배송지 상세(user/addresses/{id}).
        if ($address) {
            $aid = (string) $address->getKey();
            $map['addresses'] = [
                'model' => UserAddress::class,
                'key' => 'id',
                'value' => $aid,
                'path_params' => ['id' => $aid],
            ];
        }

        // mileage-transactions: 연관 거래 조회(admin/mileage-transactions/{id}/linked).
        if ($mileage) {
            $mid = (string) $mileage->getKey();
            $map['mileage-transactions'] = [
                'model' => MileageTransaction::class,
                'key' => 'id',
                'value' => $mid,
                'path_params' => ['id' => $mid],
            ];
        }

        // 단순 id 상세 도메인(admin/{domain}/{id}).
        $this->putSimpleIdDomain($map, 'brands', Brand::class, $brand);
        $this->putSimpleIdDomain($map, 'promotion-coupons', Coupon::class, $coupon);
        $this->putSimpleIdDomain($map, 'shipping-policies', ShippingPolicy::class, $shippingPolicy);
        $this->putSimpleIdDomain($map, 'shipping-carriers', ShippingCarrier::class, $carrier);
        $this->putSimpleIdDomain($map, 'claim-reasons', ClaimReason::class, $claimReason);
        $this->putSimpleIdDomain($map, 'product-labels', ProductLabel::class, $label);
        $this->putSimpleIdDomain($map, 'product-common-infos', ProductCommonInfo::class, $commonInfo);
        $this->putSimpleIdDomain($map, 'product-notice-templates', ProductNoticeTemplate::class, $noticeTemplate);
        $this->putSimpleIdDomain($map, 'extra-fee-templates', ExtraFeeTemplate::class, $extraFee);

        // settings: 레코드 없이 유효 카테고리 문자열 고정(admin/settings/{category}).
        $map['settings'] = [
            'model' => Product::class,
            'key' => 'id',
            'value' => $product ? (string) $product->getKey() : '1',
            'path_params' => ['category' => 'basic_info'],
        ];

        return $map;
    }

    /**
     * 단순 id 상세 도메인 항목을 맵에 등록합니다(모델이 null 이면 건너뜀).
     *
     * @param  array<string, mixed>  $map  대상 맵(참조)
     * @param  string  $domain  도메인 그룹명
     * @param  class-string  $model  모델 FQCN
     * @param  Model|null  $record  대표 레코드
     */
    private function putSimpleIdDomain(array &$map, string $domain, string $model, $record): void
    {
        if (! $record) {
            return;
        }

        $id = (string) $record->getKey();
        $map[$domain] = [
            'model' => $model,
            'key' => 'id',
            'value' => $id,
            'path_params' => ['id' => $id],
        ];
    }

    /**
     * 완전 샘플 상품을 멱등 생성합니다(전시 상태로 공개 상세 실측 가능).
     *
     * @return Product|null 대표 상품 (생성 실패 시 null)
     */
    private function seedProduct(): ?Product
    {
        $product = Product::query()->where('product_code', self::SAMPLE_PRODUCT_CODE)->first();
        if ($product) {
            return $product;
        }

        return Product::factory()->create([
            'name' => ['ko' => 'API 문서 샘플 상품', 'en' => 'API Doc Sample Product'],
            'product_code' => self::SAMPLE_PRODUCT_CODE,
            'sales_status' => 'on_sale',
            'display_status' => 'visible',
        ]);
    }

    /**
     * 완전 샘플 카테고리를 멱등 생성합니다(공개 slug 조회 실측 가능).
     *
     * @return Category|null 대표 카테고리 (생성 실패 시 null)
     */
    private function seedCategory(): ?Category
    {
        $category = Category::query()->where('slug', self::SAMPLE_CATEGORY_SLUG)->first();
        if ($category) {
            return $category;
        }

        return Category::query()->create([
            'name' => ['ko' => 'API 문서 샘플 카테고리', 'en' => 'API Doc Sample Category'],
            'slug' => self::SAMPLE_CATEGORY_SLUG,
            'path' => '0',
            'depth' => 0,
            'sort_order' => 0,
            'is_active' => true,
        ]);
    }

    /**
     * 실측 사용자 소유의 완전 샘플 주문을 멱등 생성합니다.
     *
     * @param  User  $actor  실측 사용자
     * @return Order|null 대표 주문 (생성 실패 시 null)
     */
    private function seedOrder(User $actor): ?Order
    {
        $order = Order::query()->where('user_id', $actor->id)
            ->where('order_number', 'like', 'APIDOC-%')
            ->first();
        if ($order) {
            return $order;
        }

        return Order::factory()->forUser($actor)->create([
            'order_number' => 'APIDOC-'.now()->format('Ymd').'-000001',
        ]);
    }

    /**
     * 완전 샘플 상품 리뷰를 멱등 생성합니다.
     *
     * @param  User  $actor  실측 사용자
     * @param  Product  $product  대표 상품
     * @return ProductReview|null 대표 리뷰 (생성 실패 시 null)
     */
    private function seedReview(User $actor, Product $product): ?ProductReview
    {
        $review = ProductReview::query()->where('product_id', $product->id)
            ->where('user_id', $actor->id)->first();
        if ($review) {
            return $review;
        }

        return ProductReview::factory()->create([
            'product_id' => $product->id,
            'user_id' => $actor->id,
        ]);
    }

    /**
     * 완전 샘플 상품 문의를 멱등 생성합니다.
     *
     * @param  User  $actor  실측 사용자
     * @param  Product  $product  대표 상품
     * @return ProductInquiry|null 대표 문의 (생성 실패 시 null)
     */
    private function seedInquiry(User $actor, Product $product): ?ProductInquiry
    {
        $inquiry = ProductInquiry::query()->where('product_id', $product->id)
            ->where('user_id', $actor->id)->first();
        if ($inquiry) {
            return $inquiry;
        }

        return ProductInquiry::factory()->create([
            'product_id' => $product->id,
            'user_id' => $actor->id,
        ]);
    }

    /**
     * 실측 사용자 소유의 완전 샘플 배송지를 멱등 생성합니다.
     *
     * @param  User  $actor  실측 사용자
     * @return UserAddress|null 대표 배송지 (생성 실패 시 null)
     */
    private function seedAddress(User $actor): ?UserAddress
    {
        $address = UserAddress::query()->where('user_id', $actor->id)
            ->where('name', 'API 문서 샘플 배송지')->first();
        if ($address) {
            return $address;
        }

        return UserAddress::factory()->create([
            'user_id' => $actor->id,
            'name' => 'API 문서 샘플 배송지',
        ]);
    }

    /**
     * 실측 사용자 소유의 완전 샘플 마일리지 거래를 멱등 생성합니다.
     *
     * @param  User  $actor  실측 사용자
     * @return MileageTransaction|null 대표 거래 (생성 실패 시 null)
     */
    private function seedMileageTransaction(User $actor): ?MileageTransaction
    {
        $tx = MileageTransaction::query()->where('user_id', $actor->id)
            ->where('type', 'admin_earn')->first();
        if ($tx) {
            return $tx;
        }

        return MileageTransaction::query()->create([
            'user_id' => $actor->id,
            'type' => 'admin_earn',
            'amount' => 1000,
            'balance_after' => 1000,
        ]);
    }

    /**
     * 완전 샘플 브랜드를 멱등 생성합니다.
     *
     * @return Brand|null 대표 브랜드 (생성 실패 시 null)
     */
    private function seedBrand(): ?Brand
    {
        return Brand::query()->firstOrCreate(
            ['slug' => 'apidoc-sample-brand'],
            ['name' => ['ko' => 'API 문서 샘플 브랜드', 'en' => 'API Doc Sample Brand']]
        );
    }

    /**
     * 완전 샘플 프로모션 쿠폰을 멱등 생성합니다.
     *
     * @return Coupon|null 대표 쿠폰 (생성 실패 시 null)
     */
    private function seedCoupon(): ?Coupon
    {
        return Coupon::query()->firstOrCreate(
            ['name->ko' => 'API 문서 샘플 쿠폰'],
            [
                'name' => ['ko' => 'API 문서 샘플 쿠폰', 'en' => 'API Doc Sample Coupon'],
                'target_type' => 'order_amount',
                'discount_type' => 'fixed',
                'discount_value' => 1000,
                'issue_method' => 'download',
                'issue_condition' => 'manual',
            ]
        );
    }

    /**
     * 완전 샘플 배송정책을 멱등 생성합니다.
     *
     * @return ShippingPolicy|null 대표 배송정책 (생성 실패 시 null)
     */
    private function seedShippingPolicy(): ?ShippingPolicy
    {
        return ShippingPolicy::query()->firstOrCreate(
            ['name->ko' => 'API 문서 샘플 배송정책'],
            ['name' => ['ko' => 'API 문서 샘플 배송정책', 'en' => 'API Doc Sample Shipping Policy']]
        );
    }

    /**
     * 완전 샘플 배송사를 멱등 생성합니다.
     *
     * @return ShippingCarrier|null 대표 배송사 (생성 실패 시 null)
     */
    private function seedShippingCarrier(): ?ShippingCarrier
    {
        return ShippingCarrier::query()->firstOrCreate(
            ['code' => 'apidoc'],
            [
                'name' => ['ko' => 'API 문서 샘플 배송사', 'en' => 'API Doc Sample Carrier'],
                'type' => 'domestic',
            ]
        );
    }

    /**
     * 완전 샘플 클레임 사유를 멱등 생성합니다.
     *
     * @return ClaimReason|null 대표 클레임 사유 (생성 실패 시 null)
     */
    private function seedClaimReason(): ?ClaimReason
    {
        return ClaimReason::query()->firstOrCreate(
            ['type' => 'refund', 'code' => 'apidoc_sample'],
            [
                'name' => ['ko' => 'API 문서 샘플 사유', 'en' => 'API Doc Sample Reason'],
                'fault_type' => 'customer',
            ]
        );
    }

    /**
     * 완전 샘플 상품 라벨을 멱등 생성합니다.
     *
     * @return ProductLabel|null 대표 라벨 (생성 실패 시 null)
     */
    private function seedProductLabel(): ?ProductLabel
    {
        return ProductLabel::query()->firstOrCreate(
            ['name->ko' => 'API 문서 샘플 라벨'],
            ['name' => ['ko' => 'API 문서 샘플 라벨', 'en' => 'API Doc Sample Label']]
        );
    }

    /**
     * 완전 샘플 상품 공통정보를 멱등 생성합니다.
     *
     * @return ProductCommonInfo|null 대표 공통정보 (생성 실패 시 null)
     */
    private function seedProductCommonInfo(): ?ProductCommonInfo
    {
        return ProductCommonInfo::query()->firstOrCreate(
            ['name->ko' => 'API 문서 샘플 공통정보'],
            ['name' => ['ko' => 'API 문서 샘플 공통정보', 'en' => 'API Doc Sample Common Info']]
        );
    }

    /**
     * 완전 샘플 상품정보고시 템플릿을 멱등 생성합니다.
     *
     * @return ProductNoticeTemplate|null 대표 고시 템플릿 (생성 실패 시 null)
     */
    private function seedNoticeTemplate(): ?ProductNoticeTemplate
    {
        return ProductNoticeTemplate::query()->firstOrCreate(
            ['name->ko' => 'API 문서 샘플 고시템플릿'],
            [
                'name' => ['ko' => 'API 문서 샘플 고시템플릿', 'en' => 'API Doc Sample Notice Template'],
                'fields' => [['label' => '품명', 'value' => '샘플']],
            ]
        );
    }

    /**
     * 완전 샘플 추가배송비 템플릿을 멱등 생성합니다.
     *
     * @return ExtraFeeTemplate|null 대표 추가배송비 템플릿 (생성 실패 시 null)
     */
    private function seedExtraFeeTemplate(): ?ExtraFeeTemplate
    {
        return ExtraFeeTemplate::query()->firstOrCreate(
            ['zipcode' => '00000'],
            ['fee' => 3000]
        );
    }

    /**
     * 샘플 작성자로 쓸 사용자를 반환합니다.
     *
     * 코어 완전 샘플 사용자(먼저 시드됨)를 우선하고, 없으면 첫 사용자로 폴백합니다.
     *
     * @return User|null 샘플 사용자 (없으면 null)
     */
    private function sampleActor(): ?User
    {
        return User::query()->where('email', 'apidoc-sample-user@example.com')->first()
            ?? User::query()->orderBy('id')->first();
    }
}
