<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders\Sample;

use App\Models\User;
use App\Support\AssetUrl;
use Database\Seeders\Sample\ThumbnailMeasureKit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimImageUploaderTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonFaultTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Enums\ProductSalesStatus;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReviewStatus;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Models\OrderReturnOption;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Services\CategoryImageService;
use Modules\Sirsoft\Ecommerce\Services\CategoryService;
use Modules\Sirsoft\Ecommerce\Services\ClaimAttachmentService;
use Modules\Sirsoft\Ecommerce\Services\OrderCalculationService;
use Modules\Sirsoft\Ecommerce\Services\ProductImageService;
use Modules\Sirsoft\Ecommerce\Services\ProductReviewImageService;
use Modules\Sirsoft\Ecommerce\Services\ProductReviewService;
use Modules\Sirsoft\Ecommerce\Services\ShippingPolicyService;

/**
 * 이미지 썸네일 브라우저 실측 — 이커머스 데이터.
 *
 * 개발 DB 전용 샘플 시더다. `--sample` 묶음에 넣지 않으므로 `--class` 로 지정했을 때만 돈다.
 * 코어 `Database\Seeders\Sample\ThumbnailMeasureSeeder` 가 만든 작성자 회원이 있어야 돈다.
 *
 * 만드는 것 (이름, 제목 전부 `[실측-썸네일]` 표지):
 *   - 전용 무료 배송정책 1, 카테고리 `thumb-measure`(배너 2000×600 JPEG)
 *   - 상품 A(`THUMBMEASUREA001`) — 2000px 이미지 4장: JPEG, 투명 PNG, WebP, 가로형 2000×1000 JPEG.
 *     옵션 둘(빨강, 파랑 — 리뷰가 주문옵션마다 하나라 두 리뷰에 옵션을 하나씩 쓴다). 상세 설명에 상품 B 링크.
 *   - 상품 B(`THUMBMEASUREB001`) — 2000×2000 JPEG 1장. A → B 화면 이동(새로고침 없음)의 「관련 상품」 이다. 상점 상세에는
 *     관련 상품 영역이 없으므로 A 의 상세 설명 링크와 같은 카테고리 목록으로 닿는다.
 *   - 작성자 회원의 구매확정 주문 1(옵션 둘) → 노출 리뷰(이미지 3장), 숨김 리뷰(이미지 1장)
 *   - 작성자 회원의 배송완료 주문 1 → 반품 신청서(신청자 = 작성자, 이미지 2장)
 *
 * 업로드는 각 도메인의 업로드 서비스(상품, 카테고리, 리뷰, 클레임 이미지 → 저장소 드라이버 put)를 그대로 지난다.
 * 상품, 카테고리 이미지는 공개 원본(공개 썸네일 위치), 숨김 리뷰, 클레임 이미지는 권한 원본(권한 썸네일 위치)이다.
 *
 * 주문은 `PlaywrightSeedClaimDetail` 과 같은 순서로 만든다 — 금액은 `OrderCalculationService::calculate()` 가
 * 정하고, 주문, 주문옵션, 배송, 결제 행은 팩토리로 목표 상태에 바로 세운다(접수, 전이 서비스를 지나면 알림과
 * 정산 부수효과가 라이브 사이트에 생긴다). 리뷰는 운영 작성 경로(`ProductReviewService::createReview`, 구매확정
 * 판정 포함)를, 숨김은 관리자 상태 변경 경로를 쓴다. 팩토리를 쓰므로 개발 의존성(faker)이 설치된 환경 전용이다.
 *
 * 재실행하면 같은 표식의 데이터를 먼저 지운다(`ThumbnailMeasureCleanupSeeder::purge`).
 *
 * 실행 (PowerShell):
 *   $env:G7_PLAYWRIGHT_BYPASS='1'; php artisan module:seed sirsoft-ecommerce --class="Modules\Sirsoft\Ecommerce\Database\Seeders\Sample\ThumbnailMeasureSeeder"
 */
class ThumbnailMeasureSeeder extends Seeder
{
    /** 상품 코드 (공개 상세 라우트 제약 `[0-9A-Za-z]+` — 하이픈 없음) */
    public const PRODUCT_A_CODE = 'THUMBMEASUREA001';

    public const PRODUCT_B_CODE = 'THUMBMEASUREB001';

    /** 정리 시더가 상품을 고르는 코드 접두 */
    public const PRODUCT_CODE_PREFIX = 'THUMBMEASURE';

    /** 카테고리 슬러그 */
    public const CATEGORY_SLUG = 'thumb-measure';

    /** 주문번호 접두 — 정리 시더의 모집단 */
    public const ORDER_PREFIX = 'THUMB-MEAS-';

    /** 시드 단가 */
    private const UNIT_PRICE = 19000;

    private ThumbnailMeasureKit $kit;

    /** @var list<list<scalar|null>> 콘솔 표 행 */
    private array $rows = [];

    /**
     * 시더를 실행합니다.
     *
     * @return void 반환값 없음
     */
    public function run(): void
    {
        $this->command?->info(__('sirsoft-ecommerce::messages.seeders.thumbnail_measure.start'));

        ThumbnailMeasureKit::guard();

        $this->kit = new ThumbnailMeasureKit;
        $this->kit->silenceOutbound();

        $admin = ThumbnailMeasureKit::superAdmin();
        $author = ThumbnailMeasureKit::member(ThumbnailMeasureKit::AUTHOR_EMAIL);

        $previous = (new ThumbnailMeasureCleanupSeeder)->purge();
        if (array_sum($previous) > 0) {
            $this->command?->line(__('sirsoft-ecommerce::messages.seeders.thumbnail_measure.previous_removed', ['counts' => ThumbnailMeasureKit::summarize($previous, __('sirsoft-ecommerce::messages.seeders.thumbnail_measure.cleanup_headers'))]));
        }

        try {
            Auth::setUser($admin);

            $policy = $this->shippingPolicy();
            $category = $this->category();
            [$productB, $optionB] = $this->product(self::PRODUCT_B_CODE, 'B', $policy, $category, [['파랑', 'Blue']], null);
            [$productA, $optionsA] = $this->product(self::PRODUCT_A_CODE, 'A', $policy, $category, [['빨강', 'Red'], ['파랑', 'Blue']], $productB);
            $optionB = $optionB[0];

            $images = app(ProductImageService::class);
            foreach ([
                [$this->kit->jpeg(2000, 2000, [200, 60, 60], 'PRODUCT A JPEG'), 'thumb-product-a-2000.jpg'],
                [$this->kit->png(2000, 2000, [60, 120, 220], 'PRODUCT A PNG ALPHA', true), 'thumb-product-a-alpha-2000.png'],
                [$this->kit->webp(2000, 2000, [60, 170, 120], 'PRODUCT A WEBP'), 'thumb-product-a-2000.webp'],
                [$this->kit->jpeg(2000, 1000, [220, 160, 40], 'PRODUCT A WIDE'), 'thumb-product-a-wide-2000x1000.jpg'],
            ] as [$path, $name]) {
                $image = $images->upload($this->kit->file($path, $name), $productA->id, 'main');
                $this->row(__('sirsoft-ecommerce::messages.seeders.thumbnail_measure.kinds.product_a_image'), $productA->id, $image);
            }

            $image = $images->upload($this->kit->file($this->kit->jpeg(2000, 2000, [120, 60, 200], 'PRODUCT B JPEG'), 'thumb-product-b-2000.jpg'), $productB->id, 'main');
            $this->row(__('sirsoft-ecommerce::messages.seeders.thumbnail_measure.kinds.product_b_image'), $productB->id, $image);

            // 구매확정 주문 → 리뷰 둘 (작성자 본인이 쓴다 — 운영 작성 경로의 본인, 구매확정 판정을 지난다)
            $confirmed = $this->order($author, 'REVIEW', [[$productA, $optionsA[0]], [$productA, $optionsA[1]]], OrderStatusEnum::CONFIRMED);
            $this->rows[] = self::recordRow(__('sirsoft-ecommerce::messages.seeders.thumbnail_measure.kinds.order_confirmed'), $confirmed->id, $confirmed->order_number, '/mypage/orders/'.$confirmed->order_number);

            Auth::setUser($author);
            $reviews = app(ProductReviewService::class);
            $reviewImages = app(ProductReviewImageService::class);

            $visible = $reviews->createReview($author->id, [
                'order_option_id' => $confirmed->options[0]->id,
                'rating' => 5,
                'content' => ThumbnailMeasureKit::MARK.' 노출 리뷰 (이미지 3장)',
            ]);
            foreach ([[220, 90, 90], [90, 200, 120], [90, 120, 220]] as $i => $rgb) {
                $image = $reviewImages->upload($this->kit->file($this->kit->jpeg(2000, 2000, $rgb, 'REVIEW VISIBLE '.($i + 1)), 'thumb-review-visible-'.($i + 1).'.jpg'), $visible);
                $this->row(__('sirsoft-ecommerce::messages.seeders.thumbnail_measure.kinds.review_visible_image'), $visible->id, $image);
            }

            $hidden = $reviews->createReview($author->id, [
                'order_option_id' => $confirmed->options[1]->id,
                'rating' => 2,
                'content' => ThumbnailMeasureKit::MARK.' 숨김 리뷰 (이미지 1장)',
            ]);
            $image = $reviewImages->upload($this->kit->file($this->kit->jpeg(2000, 2000, [80, 80, 80], 'REVIEW HIDDEN'), 'thumb-review-hidden.jpg'), $hidden);
            $this->row(__('sirsoft-ecommerce::messages.seeders.thumbnail_measure.kinds.review_hidden_image'), $hidden->id, $image);

            Auth::setUser($admin);
            $reviews->updateStatus($hidden, ReviewStatus::HIDDEN->value);

            // 배송완료 주문 → 반품 신청서 + 이미지 둘 (신청자 = 작성자)
            $delivered = $this->order($author, 'CLAIM', [[$productB, $optionB]], OrderStatusEnum::DELIVERED);
            $return = $this->returnClaim($delivered, $author);
            $this->rows[] = self::recordRow(__('sirsoft-ecommerce::messages.seeders.thumbnail_measure.kinds.order_delivered'), $delivered->id, $delivered->order_number, '/mypage/orders/'.$delivered->order_number);
            $this->rows[] = self::recordRow(__('sirsoft-ecommerce::messages.seeders.thumbnail_measure.kinds.return_claim'), $return->id, $return->return_number, '-');

            Auth::setUser($author);
            $claims = app(ClaimAttachmentService::class);
            foreach ([[200, 120, 60], [60, 140, 180]] as $i => $rgb) {
                $image = $claims->uploadFor(
                    $this->kit->file($this->kit->jpeg(2000, 2000, $rgb, 'CLAIM '.($i + 1)), 'thumb-claim-'.($i + 1).'.jpg'),
                    ClaimTypeEnum::RETURN,
                    $return->id,
                    $author->id,
                    ClaimImageUploaderTypeEnum::CUSTOMER,
                );
                // 클레임 첨부는 직접 주소가 없다(권한 게이트 서빙) — 서빙 라우트 주소를 찍는다
                $this->row(__('sirsoft-ecommerce::messages.seeders.thumbnail_measure.kinds.claim_image'), $return->id, $image, AssetUrl::sitePath(route('api.modules.sirsoft-ecommerce.claim-image.download', ['hash' => $image->hash], false)));
            }
        } finally {
            $this->kit->cleanup();
            Auth::forgetUser();
        }

        ThumbnailMeasureKit::table($this->command, __('sirsoft-ecommerce::messages.seeders.thumbnail_measure.data_title'), array_values((array) __('sirsoft-ecommerce::messages.seeders.thumbnail_measure.data_headers')), $this->rows);
        $this->command?->line(__('sirsoft-ecommerce::messages.seeders.thumbnail_measure.category', [
            'path' => $this->shopPath('category/'.self::CATEGORY_SLUG),
            'id' => $category->id,
            'hash' => $category->images()->first()?->hash ?? '-',
        ]));
        $this->command?->line(__('sirsoft-ecommerce::messages.seeders.thumbnail_measure.products', [
            'a_path' => $this->shopPath('products/'.self::PRODUCT_A_CODE),
            'a_id' => $productA->id,
            'b_path' => $this->shopPath('products/'.self::PRODUCT_B_CODE),
            'b_id' => $productB->id,
        ]));
        $this->command?->info(__('sirsoft-ecommerce::messages.seeders.thumbnail_measure.done'));
    }

    /**
     * 전용 무료 배송정책을 만듭니다 (운영 배송정책을 쓰면 주문 계산이 그 정책의 배송비를 싣는다).
     *
     * 관리자 화면과 같은 `ShippingPolicyService::create` 를 지난다 — 생성 훅, 통화를 상점 기본 통화로 맞추는 규칙,
     * 작성자 기록이 운영과 같다.
     *
     * @return ShippingPolicy 배송정책
     */
    private function shippingPolicy(): ShippingPolicy
    {
        return app(ShippingPolicyService::class)->create([
            'name' => ['ko' => ThumbnailMeasureKit::MARK.' 무료 배송', 'en' => ThumbnailMeasureKit::MARK.' Free shipping'],
            'is_default' => false,
            'is_active' => true,
            'country_settings' => [[
                'country_code' => 'KR',
                'shipping_method' => 'parcel',
                'currency_code' => 'KRW',
                'charge_policy' => ChargePolicyEnum::FREE,
                'base_fee' => 0,
                'free_threshold' => null,
                'extra_fee_enabled' => false,
                'is_active' => true,
            ]],
        ]);
    }

    /**
     * 배너 이미지가 있는 실측 카테고리를 관리자 등록 순서로 만듭니다 (임시 업로드 → 카테고리 저장이 연결).
     *
     * @return Category 카테고리
     */
    private function category(): Category
    {
        $tempKey = (string) Str::uuid();
        $banner = app(CategoryImageService::class)->upload(
            $this->kit->file($this->kit->jpeg(2000, 600, [30, 110, 160], 'CATEGORY BANNER 2000x600'), 'thumb-category-banner-2000x600.jpg'),
            null,
            'main',
            $tempKey
        );

        $category = app(CategoryService::class)->createCategory([
            'name' => ['ko' => ThumbnailMeasureKit::MARK.' 카테고리', 'en' => ThumbnailMeasureKit::MARK.' Category'],
            'description' => ['ko' => ThumbnailMeasureKit::MARK.' 이미지 썸네일 실측 전용', 'en' => ThumbnailMeasureKit::MARK.' Thumbnail measurement only'],
            'slug' => self::CATEGORY_SLUG,
            'parent_id' => null,
            'is_active' => true,
            'temp_key' => $tempKey,
        ]);

        $this->row(__('sirsoft-ecommerce::messages.seeders.thumbnail_measure.kinds.category_banner'), $category->id, $banner->fresh());

        return $category;
    }

    /**
     * 상점에 보이는 실측 상품과 옵션을 만듭니다.
     *
     * @param  string  $code  상품 코드
     * @param  string  $letter  이름 꼬리 (A, B)
     * @param  ShippingPolicy  $policy  배송정책
     * @param  Category  $category  카테고리
     * @param  list<array{0: string, 1: string}>  $colors  옵션 색 [ko, en]
     * @param  Product|null  $related  상세 설명에 링크할 상품
     * @return array{0: Product, 1: list<ProductOption>} [상품, 옵션 목록]
     */
    private function product(string $code, string $letter, ShippingPolicy $policy, Category $category, array $colors, ?Product $related): array
    {
        $description = '<p>'.e(ThumbnailMeasureKit::MARK.' 상품 '.$letter.' — 이미지 썸네일 실측 전용').'</p>';
        if ($related !== null) {
            $description .= '<p><a href="'.e($this->shopPath('products/'.$related->product_code)).'">'.e(ThumbnailMeasureKit::MARK.' 관련 상품 B 보기').'</a></p>';
        }

        $product = ProductFactory::new()->create([
            'product_code' => $code,
            'sku' => 'THM-'.$letter,
            'name' => ['ko' => ThumbnailMeasureKit::MARK.' 상품 '.$letter, 'en' => ThumbnailMeasureKit::MARK.' Product '.$letter],
            'description' => ['ko' => $description, 'en' => $description],
            'description_mode' => 'html',
            'list_price' => self::UNIT_PRICE,
            'selling_price' => self::UNIT_PRICE,
            'stock_quantity' => 100,
            'sales_status' => ProductSalesStatus::ON_SALE,
            'display_status' => ProductDisplayStatus::VISIBLE,
            'tax_status' => 'taxable',
            'shipping_policy_id' => $policy->id,
            'meta_title' => null,
            'meta_description' => null,
            'meta_keywords' => null,
            'has_options' => true,
            'option_groups' => [[
                'name' => ['ko' => '색상', 'en' => 'Color'],
                'values' => array_map(fn (array $c) => ['ko' => $c[0], 'en' => $c[1]], $colors),
            ]],
        ]);

        $product->categories()->attach($category->id, ['is_primary' => true]);

        $options = [];
        foreach ($colors as $i => [$ko, $en]) {
            $options[] = ProductOptionFactory::new()->forProduct($product)->create([
                'option_code' => $code.'-'.strtoupper($en),
                'option_values' => [['key' => ['ko' => '색상', 'en' => 'Color'], 'value' => ['ko' => $ko, 'en' => $en]]],
                'option_name' => ['ko' => $ko, 'en' => $en],
                'price_adjustment' => 0,
                'stock_quantity' => 100,
                'is_default' => $i === 0,
                'is_active' => true,
                'sort_order' => $i,
            ])->fresh();
        }

        return [$product->fresh(), $options];
    }

    /**
     * 작성자 회원의 주문을 계산 결과로 만듭니다 (주소, 배송, 결제 포함).
     *
     * @param  User  $author  주문 회원
     * @param  string  $kind  주문번호 꼬리
     * @param  list<array{0: Product, 1: ProductOption}>  $lines  [상품, 옵션] 목록 (각 수량 1)
     * @param  OrderStatusEnum  $status  주문, 주문옵션 상태
     * @return Order 주문 (options 로드됨)
     */
    private function order(User $author, string $kind, array $lines, OrderStatusEnum $status): Order
    {
        return DB::transaction(function () use ($author, $kind, $lines, $status) {
            $result = app(OrderCalculationService::class)->calculate(new CalculationInput(items: array_map(
                fn (array $line) => new CalculationItem(productId: $line[0]->id, productOptionId: $line[1]->id, quantity: 1),
                $lines
            )));

            if (count($result->items) !== count($lines)) {
                throw new \RuntimeException(__('sirsoft-ecommerce::messages.seeders.thumbnail_measure.order_calculation_dropped'));
            }

            $shippingPolicySnapshot = [];
            foreach ($result->items as $item) {
                if ($item->appliedShippingPolicy) {
                    $shippingPolicySnapshot[$item->productOptionId] = $item->appliedShippingPolicy->toArray();
                }
            }

            $finalAmount = $result->summary->finalAmount;
            $order = Order::factory()->forUser($author)->create([
                'order_number' => self::ORDER_PREFIX.$kind.'-'.now()->format('His'),
                'order_status' => $status,
                'ip_address' => '127.0.0.1',
                'is_first_order' => false,
                'subtotal_amount' => $result->summary->subtotal,
                'total_discount_amount' => $result->summary->totalDiscount,
                'base_shipping_amount' => $result->summary->baseShippingTotal,
                'extra_shipping_amount' => $result->summary->extraShippingTotal,
                'shipping_discount_amount' => $result->summary->shippingDiscount,
                'total_shipping_amount' => $result->summary->totalShipping,
                'total_amount' => $finalAmount,
                'total_paid_amount' => $finalAmount,
                'total_due_amount' => 0,
                'total_points_used_amount' => $result->summary->pointsUsed,
                'is_mileage_deducted' => $result->summary->pointsUsed > 0,
                'total_tax_amount' => $result->summary->taxableAmount,
                'total_tax_free_amount' => $result->summary->taxFreeAmount,
                'total_earned_points_amount' => 0,
                'item_count' => count($lines),
                'shipping_policy_applied_snapshot' => $shippingPolicySnapshot,
                'ordered_at' => now()->subDays(4),
                'paid_at' => now()->subDays(4),
                'confirmed_at' => $status === OrderStatusEnum::CONFIRMED ? now()->subDay() : null,
            ]);

            foreach ($result->items as $index => $item) {
                [$product, $option] = $lines[$index];

                $orderOption = OrderOption::factory()->forOrder($order)->create([
                    'product_id' => $item->productId,
                    'product_option_id' => $item->productOptionId,
                    'option_status' => $status,
                    'product_name' => $product->name,
                    'product_option_name' => $option->option_name,
                    'option_name' => $option->option_name,
                    'option_value' => ['ko' => '색상: '.($option->option_name['ko'] ?? ''), 'en' => 'Color: '.($option->option_name['en'] ?? '')],
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unitPrice,
                    'subtotal_price' => $item->subtotal,
                    'subtotal_discount_amount' => 0,
                    'coupon_discount_amount' => 0,
                    'code_discount_amount' => 0,
                    'subtotal_paid_amount' => $item->finalAmount,
                    'subtotal_tax_amount' => $item->taxableAmount,
                    'subtotal_tax_free_amount' => $item->taxFreeAmount,
                    'subtotal_points_used_amount' => $item->pointsUsedShare,
                    'subtotal_earned_points_amount' => $item->pointsEarning,
                    'product_snapshot' => $product->toSnapshotArray(),
                    'option_snapshot' => $option->toSnapshotArray(),
                    'delivered_at' => now()->subDays(2),
                    'confirmed_at' => $status === OrderStatusEnum::CONFIRMED ? now()->subDay() : null,
                    'is_stock_deducted' => true,
                ]);

                OrderShipping::factory()->forOrderOption($orderOption)->create([
                    'shipping_policy_id' => $product->shipping_policy_id,
                    'delivery_policy_snapshot' => ['country_code' => 'KR'],
                    'base_shipping_amount' => 0,
                    'extra_shipping_amount' => 0,
                    'total_shipping_amount' => 0,
                    'shipping_discount_amount' => 0,
                ]);
            }

            OrderAddress::factory()->create([
                'order_id' => $order->id,
                'address_type' => 'shipping',
                'orderer_name' => ThumbnailMeasureKit::MARK.' 주문자',
                'orderer_phone' => '010-0869-0001',
                'orderer_email' => $author->email,
                'recipient_name' => ThumbnailMeasureKit::MARK.' 수령인',
                'recipient_phone' => '010-0869-0002',
                'recipient_country_code' => 'KR',
                'zipcode' => '06236',
                'address' => '서울 강남구 테헤란로 1',
                'address_detail' => '869호',
            ]);

            OrderPayment::factory()->forOrder($order)->create([
                'payment_status' => PaymentStatusEnum::PAID,
                'paid_amount_local' => $finalAmount,
                'paid_amount_base' => $finalAmount,
                'currency' => $order->currency,
                'buyer_name' => ThumbnailMeasureKit::MARK.' 주문자',
                'buyer_email' => $author->email,
                'paid_at' => now()->subDays(4),
            ]);

            return $order->fresh(['options', 'shippings', 'payment']);
        });
    }

    /**
     * 작성자가 신청한 반품 신청서를 만듭니다 (`PlaywrightSeedClaimDetail::returnClaim` 과 같은 행 모양).
     *
     * @param  Order  $order  배송완료 주문
     * @param  User  $author  신청자
     * @return OrderReturn 반품 신청서
     */
    private function returnClaim(Order $order, User $author): OrderReturn
    {
        $orderOption = $order->options->first();
        $unitPrice = (float) $orderOption->unit_price;

        $orderOption->forceFill(['option_status' => OrderStatusEnum::RETURN_IN_PROGRESS])->save();

        $return = OrderReturn::factory()->create([
            'order_id' => $order->id,
            'return_number' => 'RT-THUMBMEAS-'.$order->id,
            'return_status' => ReturnStatusEnum::REQUESTED,
            'reason_code' => 'changed_mind',
            'reason_detail' => ThumbnailMeasureKit::MARK.' 실측용 반품 신청',
            'fault_type' => ClaimReasonFaultTypeEnum::CUSTOMER,
            'claim_shipping_fee' => 0,
            'total_charge_amount' => 0,
            'currency_code' => $order->currency,
            'requested_by' => $author->id,
            'items_snapshot' => [[
                'option_id' => $orderOption->id,
                'product_name' => $orderOption->product_name,
                'option_name' => $orderOption->option_name,
                'option_value' => $orderOption->option_value,
                'quantity' => 1,
                'unit_price' => $unitPrice,
                'option_status' => OrderStatusEnum::DELIVERED->value,
            ]],
        ]);

        OrderReturnOption::factory()->create([
            'order_return_id' => $return->id,
            'order_id' => $order->id,
            'order_option_id' => $orderOption->id,
            'option_status' => ReturnStatusEnum::REQUESTED,
            'return_quantity' => 1,
            'original_quantity' => 1,
            'original_option_status' => OrderStatusEnum::DELIVERED,
            'unit_price' => $unitPrice,
            'subtotal_amount' => $unitPrice,
        ]);

        return $return->fresh();
    }

    /**
     * 상점 경로를 만듭니다 (모듈 설정의 상점 접두 — 템플릿 라우트와 같은 판정).
     *
     * @param  string  $tail  접두 뒤 경로
     * @return string 사이트 경로
     */
    private function shopPath(string $tail): string
    {
        $basic = g7_module_settings('sirsoft-ecommerce', 'basic_info', []);
        $prefix = ! empty($basic['no_route']) ? '' : '/'.trim((string) ($basic['route_path'] ?? 'shop'), '/');

        return $prefix.'/'.$tail;
    }

    /**
     * 이미지 행을 표에 넣습니다.
     *
     * @param  string  $kind  구분 (번역된 표시 이름)
     * @param  int  $ownerId  소유 행 번호
     * @param  object  $image  이미지 모델 (hash, mime_type, width, height, download_url)
     * @param  string|null  $url  표에 찍을 주소 (없으면 모델의 download_url)
     * @return void 반환값 없음
     */
    private function row(string $kind, int $ownerId, object $image, ?string $url = null): void
    {
        $this->rows[] = self::imageRow($kind, $ownerId, $image, $url);
    }

    /**
     * 실측 데이터 표의 이미지 행을 만듭니다 (열 순서는 `data_headers` 와 같다).
     *
     * @param  string  $kind  구분 (번역된 표시 이름)
     * @param  int  $ownerId  소유 행 번호
     * @param  object  $image  이미지 (id, hash, mime_type, width, height, download_url)
     * @param  string|null  $url  표에 찍을 주소 (없으면 이미지의 download_url)
     * @return list<scalar|null> 행
     */
    public static function imageRow(string $kind, int $ownerId, object $image, ?string $url = null): array
    {
        return [
            $kind,
            $ownerId,
            $image->id,
            $image->hash,
            $image->mime_type,
            ($image->width ?? '?').'x'.($image->height ?? '?'),
            $url ?? $image->download_url ?? '-',
        ];
    }

    /**
     * 실측 데이터 표의 주문, 신청서 행을 만듭니다 (이미지 열은 `-`).
     *
     * @param  string  $kind  구분 (번역된 표시 이름)
     * @param  int  $id  행 번호
     * @param  string  $number  주문번호 또는 신청번호
     * @param  string  $url  화면 주소 (없으면 `-`)
     * @return list<scalar|null> 행
     */
    public static function recordRow(string $kind, int $id, string $number, string $url): array
    {
        return [$kind, $id, $number, '-', '-', '-', $url];
    }
}
