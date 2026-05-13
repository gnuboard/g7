<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders;

use App\Enums\ActivityLogType;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueStatus;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ProductSalesStatus;
use Modules\Sirsoft\Ecommerce\Models\Brand;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\ExtraFeeTemplate;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductCommonInfo;
use Modules\Sirsoft\Ecommerce\Models\ProductImage;
use Modules\Sirsoft\Ecommerce\Models\ProductLabel;
use Modules\Sirsoft\Ecommerce\Models\ProductNoticeTemplate;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;

/**
 * 이커머스 활동 로그 샘플 시더
 *
 * 데모용 ActivityLog를 생성합니다.
 * 각 리소스마다 rand(1, 50)건의 로그를 무작위 생성합니다.
 */
class ActivityLogSampleSeeder extends Seeder
{
    /** @var string 다국어 키 접두사 */
    private const PREFIX = 'sirsoft-ecommerce::activity_log.description.';

    /** @var array<string> 샘플 IP 목록 */
    private const IPS = ['192.168.1.10', '10.0.0.5', '172.16.0.1', '192.168.0.100', '10.10.10.1'];

    /** @var array<string> 샘플 User-Agent 목록 */
    private const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/125.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
        'Mozilla/5.0 (X11; Linux x86_64) Firefox/126.0',
    ];

    /**
     * 시더를 실행합니다.
     */
    public function run(): void
    {
        $admins = User::whereHas('roles', fn ($q) => $q->where('identifier', 'admin'))->get();
        if ($admins->isEmpty()) {
            $this->command->warn('관리자 사용자가 없어 이커머스 활동 로그 시더를 건너뜁니다.');

            return;
        }

        $users = User::whereDoesntHave('roles', fn ($q) => $q->where('identifier', 'admin'))->get();
        if ($users->isEmpty()) {
            $users = $admins;
        }

        // 기존 이커머스 활동 로그 삭제
        $deleted = ActivityLog::where('description_key', 'like', 'sirsoft-ecommerce::%')->delete();
        if ($deleted > 0) {
            $this->command->info("기존 이커머스 활동 로그 {$deleted}건 삭제.");
        }

        $count = 0;

        $count += $this->seedProductLogs($admins);
        $count += $this->seedProductImageLogs($admins);
        $count += $this->seedProductOptionLogs($admins);
        $count += $this->seedOrderLogs($admins);
        $count += $this->seedCouponLogs($admins);
        $count += $this->seedShippingPolicyLogs($admins);
        $count += $this->seedCategoryLogs($admins);
        $count += $this->seedBrandLogs($admins);
        $count += $this->seedReviewLogs($admins);
        $count += $this->seedLabelLogs($admins);
        $count += $this->seedCommonInfoLogs($admins);
        $count += $this->seedNoticeTemplateLogs($admins);
        $count += $this->seedExtraFeeLogs($admins);
        $count += $this->seedCarrierLogs($admins);
        $count += $this->seedSettingsLogs($admins);
        $count += $this->seedCartLogs($users);
        $count += $this->seedWishlistLogs($users);
        $count += $this->seedCouponUseLogs($users);
        $count += $this->seedMileageLogs($users);
        $count += $this->seedUserOrderLogs($users);

        $this->command->info("이커머스 활동 로그 {$count}건 생성 완료.");
    }

    // ──────────────────────────────────────────────
    // Admin 리소스 로그
    // ──────────────────────────────────────────────

    /**
     * 상품 활동 로그
     *
     * @param  Collection  $admins  관리자 컬렉션
     * @return int 생성된 로그 수
     */
    private function seedProductLogs($admins): int
    {
        $products = Product::get();
        if ($products->isEmpty()) {
            $this->command->warn('상품 데이터가 없어 상품 활동 로그를 건너뜁니다.');

            return 0;
        }

        $actions = [
            ['action' => 'product.create', 'key' => 'product_create', 'loggable' => true,
                'params' => fn ($p) => ['product_name' => $this->getLocalizedName($p->name)]],
            ['action' => 'product.update', 'key' => 'product_update', 'loggable' => true,
                'params' => fn ($p) => ['product_name' => $this->getLocalizedName($p->name)],
                'changes' => fn ($p) => [
                    ['field' => 'selling_price', 'label_key' => 'sirsoft-ecommerce::activity_log.fields.selling_price', 'old' => (int) ($p->selling_price * $this->randomFloat(0.8, 1.2)), 'new' => $p->selling_price, 'type' => 'currency'],
                    ['field' => 'sales_status', 'label_key' => 'sirsoft-ecommerce::activity_log.fields.sales_status', 'old' => $this->pickDifferentEnum(ProductSalesStatus::class, $p->sales_status), 'new' => $p->sales_status?->value ?? ProductSalesStatus::ON_SALE->value, 'type' => 'enum'],
                ]],
            ['action' => 'product.show', 'key' => 'product_show', 'loggable' => true,
                'params' => fn ($p) => ['product_id' => $p->id]],
            ['action' => 'product.delete', 'key' => 'product_delete', 'loggable' => false,
                'params' => fn ($p) => ['product_name' => $this->getLocalizedName($p->name)],
                'properties' => fn ($p) => ['deleted_id' => $p->id, 'product_name' => $this->getLocalizedName($p->name), 'product_code' => $p->product_code]],
            ['action' => 'product.bulk_update', 'key' => 'product_bulk_update', 'loggable' => true,
                'params' => fn ($p) => ['product_name' => $this->getLocalizedName($p->name), 'count' => 1],
                'changes' => fn ($p) => [
                    ['field' => 'sales_status', 'label_key' => 'sirsoft-ecommerce::activity_log.fields.sales_status', 'old' => $p->sales_status?->value ?? ProductSalesStatus::ON_SALE->value, 'new' => $this->pickDifferentEnum(ProductSalesStatus::class, $p->sales_status), 'type' => 'enum'],
                ]],
            ['action' => 'product.bulk_price_update', 'key' => 'product_bulk_price_update', 'loggable' => true,
                'params' => fn ($p) => ['product_name' => $this->getLocalizedName($p->name), 'count' => 1],
                'changes' => fn ($p) => [
                    ['field' => 'selling_price', 'label_key' => 'sirsoft-ecommerce::activity_log.fields.selling_price', 'old' => (int) ($p->selling_price * $this->randomFloat(0.8, 1.2)), 'new' => $p->selling_price, 'type' => 'currency'],
                ]],
            ['action' => 'product.bulk_stock_update', 'key' => 'product_bulk_stock_update', 'loggable' => true,
                'params' => fn ($p) => ['product_name' => $this->getLocalizedName($p->name), 'count' => 1],
                'changes' => fn ($p) => [
                    ['field' => 'stock_quantity', 'label_key' => 'sirsoft-ecommerce::activity_log.fields.stock_quantity', 'old' => rand(0, 50), 'new' => $p->stock_quantity ?? rand(10, 100), 'type' => 'number'],
                ]],
            ['action' => 'product.stock_sync', 'key' => 'product_stock_sync', 'loggable' => true,
                'params' => fn ($p) => ['product_name' => $this->getLocalizedName($p->name)]],
        ];

        $count = $this->generateResourceLogs($products, $admins, ActivityLogType::Admin, (new Product)->getMorphClass(), $actions);
        $count += $this->generateNonResourceLogs($admins, ActivityLogType::Admin, [
            ['action' => 'product.index', 'key' => 'product_index', 'params' => []],
        ]);

        return $count;
    }

    /**
     * 상품 이미지 활동 로그
     *
     * @param  Collection  $admins  관리자 컬렉션
     * @return int 생성된 로그 수
     */
    private function seedProductImageLogs($admins): int
    {
        $images = ProductImage::with('product')->get();
        if ($images->isEmpty()) {
            $this->command->warn('상품 이미지 데이터가 없어 이미지 활동 로그를 건너뜁니다.');

            return 0;
        }

        $getName = fn ($img) => $img->product ? $this->getLocalizedName($img->product->name) : '삭제된 상품';

        $actions = [
            ['action' => 'product_image.upload', 'key' => 'product_image_upload', 'loggable' => true,
                'params' => fn ($img) => ['product_name' => $getName($img)]],
            ['action' => 'product_image.delete', 'key' => 'product_image_delete', 'loggable' => false,
                'params' => fn ($img) => ['product_name' => $getName($img)],
                'properties' => fn ($img) => ['deleted_id' => $img->id]],
            ['action' => 'product_image.reorder', 'key' => 'product_image_reorder', 'loggable' => true,
                'params' => fn ($img) => ['product_name' => $getName($img)]],
        ];

        return $this->generateResourceLogs($images, $admins, ActivityLogType::Admin, (new ProductImage)->getMorphClass(), $actions);
    }

    /**
     * 상품 옵션 활동 로그
     *
     * @param  Collection  $admins  관리자 컬렉션
     * @return int 생성된 로그 수
     */
    private function seedProductOptionLogs($admins): int
    {
        $options = ProductOption::with('product')->get();
        if ($options->isEmpty()) {
            $this->command->warn('상품 옵션 데이터가 없어 옵션 활동 로그를 건너뜁니다.');

            return 0;
        }

        $actions = [
            ['action' => 'product_option.bulk_price_update', 'key' => 'product_option_bulk_price_update', 'loggable' => true,
                'params' => fn ($opt) => ['count' => rand(2, 20)],
                'changes' => fn ($opt) => [
                    ['field' => 'price_adjustment', 'label_key' => 'sirsoft-ecommerce::activity_log.fields.price_adjustment', 'old' => rand(0, 5000), 'new' => $opt->price_adjustment ?? 0, 'type' => 'currency'],
                ]],
            ['action' => 'product_option.bulk_stock_update', 'key' => 'product_option_bulk_stock_update', 'loggable' => true,
                'params' => fn ($opt) => ['count' => rand(2, 20)],
                'changes' => fn ($opt) => [
                    ['field' => 'stock_quantity', 'label_key' => 'sirsoft-ecommerce::activity_log.fields.stock_quantity', 'old' => rand(0, 30), 'new' => $opt->stock_quantity ?? rand(10, 100), 'type' => 'number'],
                ]],
            ['action' => 'product_option.bulk_update', 'key' => 'product_option_bulk_update', 'loggable' => true,
                'params' => fn ($opt) => ['count' => rand(2, 20)],
                'changes' => fn ($opt) => [
                    ['field' => 'sku', 'label_key' => 'sirsoft-ecommerce::activity_log.fields.sku', 'old' => 'OLD-SKU-'.rand(100, 999), 'new' => $opt->sku ?? 'SKU-'.rand(100, 999), 'type' => 'text'],
                ]],
        ];

        return $this->generateResourceLogs($options, $admins, ActivityLogType::Admin, (new ProductOption)->getMorphClass(), $actions);
    }

    /**
     * 주문 활동 로그
     *
     * @param  Collection  $admins  관리자 컬렉션
     * @return int 생성된 로그 수
     */
    private function seedOrderLogs($admins): int
    {
        $orders = Order::with('options')->get();
        if ($orders->isEmpty()) {
            $this->command->warn('주문 데이터가 없어 주문 활동 로그를 건너뜁니다.');

            return 0;
        }

        $orderActions = [
            ['action' => 'order.create', 'key' => 'order_create', 'loggable' => true,
                'params' => fn ($o) => ['order_number' => $o->order_number]],
            ['action' => 'order.show', 'key' => 'order_show', 'loggable' => true,
                'params' => fn ($o) => ['order_number' => $o->order_number]],
            ['action' => 'order.update', 'key' => 'order_update', 'loggable' => true,
                'params' => fn ($o) => ['order_number' => $o->order_number],
                'changes' => fn ($o) => [
                    ['field' => 'order_status', 'label_key' => 'sirsoft-ecommerce::activity_log.fields.order_status', 'old' => $this->pickDifferentEnum(OrderStatusEnum::class, $o->order_status), 'new' => $o->order_status?->value ?? OrderStatusEnum::PAYMENT_COMPLETE->value, 'type' => 'enum'],
                    ['field' => 'admin_memo', 'label_key' => 'sirsoft-ecommerce::activity_log.fields.admin_memo', 'old' => ($o->admin_memo ?? '').' (수정 전)', 'new' => $o->admin_memo ?? '', 'type' => 'text'],
                ]],
            ['action' => 'order.delete', 'key' => 'order_delete', 'loggable' => false,
                'params' => fn ($o) => ['order_number' => $o->order_number],
                'properties' => fn ($o) => ['deleted_id' => $o->id, 'order_number' => $o->order_number, 'total_amount' => $o->total_amount]],
            ['action' => 'order.cancel', 'key' => 'order_cancel', 'loggable' => true,
                'params' => fn ($o) => ['order_number' => $o->order_number],
                'properties' => fn ($o) => ['cancelled_amount' => $o->total_amount, 'reason' => '고객 요청에 의한 취소']],
            ['action' => 'order.partial_cancel', 'key' => 'order_partial_cancel', 'loggable' => true,
                'params' => fn ($o) => ['order_number' => $o->order_number]],
            ['action' => 'order.bulk_update', 'key' => 'order_bulk_update', 'loggable' => true,
                'params' => fn ($o) => ['order_number' => $o->order_number, 'count' => 1],
                'changes' => fn ($o) => [
                    ['field' => 'admin_memo', 'label_key' => 'sirsoft-ecommerce::activity_log.fields.admin_memo', 'old' => '이전 메모', 'new' => $o->admin_memo ?? '', 'type' => 'text'],
                ]],
            ['action' => 'order.bulk_status_update', 'key' => 'order_bulk_status_update', 'loggable' => true,
                'params' => fn ($o) => ['order_number' => $o->order_number, 'count' => 1],
                'changes' => fn ($o) => [
                    ['field' => 'order_status', 'label_key' => 'sirsoft-ecommerce::activity_log.fields.order_status', 'old' => $o->order_status?->value ?? OrderStatusEnum::PAYMENT_COMPLETE->value, 'new' => $this->pickDifferentEnum(OrderStatusEnum::class, $o->order_status), 'type' => 'enum'],
                ]],
            ['action' => 'order.bulk_shipping_update', 'key' => 'order_bulk_shipping_update', 'loggable' => true,
                'params' => fn ($o) => ['order_number' => $o->order_number, 'count' => 1],
                'changes' => fn ($o) => [
                    ['field' => 'order_status', 'label_key' => 'sirsoft-ecommerce::activity_log.fields.order_status', 'old' => OrderStatusEnum::PREPARING->value, 'new' => OrderStatusEnum::SHIPPING->value, 'type' => 'enum',
                     'old_label_key' => OrderStatusEnum::PREPARING->labelKey(), 'new_label_key' => OrderStatusEnum::SHIPPING->labelKey()],
                ]],
            ['action' => 'order.payment_complete', 'key' => 'order_payment_complete', 'loggable' => true,
                'params' => fn ($o) => ['order_number' => $o->order_number]],
            ['action' => 'order.payment_failed', 'key' => 'order_payment_failed', 'loggable' => true,
                'params' => fn ($o) => ['order_number' => $o->order_number]],
            ['action' => 'order.update_shipping_address', 'key' => 'order_update_shipping_address', 'loggable' => true,
                'params' => fn ($o) => ['order_number' => $o->order_number],
                'changes' => fn ($o) => [
                    ['field' => 'recipient_name', 'label_key' => 'sirsoft-ecommerce::activity_log.fields.recipient_name', 'old' => '홍길동', 'new' => '김철수', 'type' => 'text'],
                    ['field' => 'address', 'label_key' => 'sirsoft-ecommerce::activity_log.fields.address', 'old' => '서울시 강남구', 'new' => '서울시 서초구', 'type' => 'text'],
                ]],
            ['action' => 'order.send_email', 'key' => 'order_send_email', 'loggable' => true,
                'params' => fn ($o) => ['order_number' => $o->order_number]],
            ['action' => 'coupon.restore', 'key' => 'order_coupon_restore', 'loggable' => true,
                'params' => fn ($o) => ['order_number' => $o->order_number]],
            ['action' => 'mileage.restore', 'key' => 'order_mileage_restore', 'loggable' => true,
                'params' => fn ($o) => ['order_number' => $o->order_number, 'amount' => number_format(rand(500, 3000))]],
            ['action' => 'coupon.use', 'key' => 'coupon_use', 'loggable' => true,
                'params' => fn ($o) => ['coupon_name' => '샘플 쿠폰']],
            ['action' => 'mileage.use', 'key' => 'mileage_use', 'loggable' => true,
                'params' => fn ($o) => ['amount' => number_format(rand(500, 3000))]],
            ['action' => 'mileage.earn', 'key' => 'mileage_earn', 'loggable' => true,
                'params' => fn ($o) => ['amount' => number_format(rand(100, 5000))]],
            ['action' => 'payment.refund', 'key' => 'payment_refund', 'loggable' => true,
                'params' => fn ($o) => ['order_number' => $o->order_number]],
        ];

        $count = $this->generateResourceLogs($orders, $admins, ActivityLogType::Admin, (new Order)->getMorphClass(), $orderActions);

        // OrderOption 레벨 로그
        $allOptions = OrderOption::with('order')->whereIn('order_id', $orders->pluck('id'))->get();
        if ($allOptions->isNotEmpty()) {
            $optionActions = [
                ['action' => 'order_option.status_change', 'key' => 'order_option_status_change', 'loggable' => true,
                    'params' => fn ($opt) => ['order_number' => $opt->order?->order_number ?? 'N/A']],
                ['action' => 'order_option.bulk_status_change', 'key' => 'order_option_bulk_status_change', 'loggable' => true,
                    'params' => fn ($opt) => ['count' => 1]],
                ['action' => 'order_option.confirm', 'key' => 'order_option_confirm', 'loggable' => true,
                    'params' => fn ($opt) => ['order_number' => $opt->order?->order_number ?? 'N/A'],
                    'properties' => fn ($opt) => ['order_id' => $opt->order_id]],
                ['action' => 'order_option.partial_cancel', 'key' => 'order_option_partial_cancel', 'loggable' => true,
                    'params' => fn ($opt) => ['order_number' => $opt->order?->order_number ?? 'N/A'],
                    'properties' => fn ($opt) => ['order_id' => $opt->order_id, 'product_name' => $opt->product_name, 'quantity' => $opt->quantity]],
            ];

            $count += $this->generateResourceLogs($allOptions, $admins, ActivityLogType::Admin, (new OrderOption)->getMorphClass(), $optionActions);
        }

        $count += $this->generateNonResourceLogs($admins, ActivityLogType::Admin, [
            ['action' => 'order.index', 'key' => 'order_index', 'params' => []],
        ]);

        return $count;
    }

    /**
     * 쿠폰 활동 로그
     *
     * @param  Collection  $admins  관리자 컬렉션
     * @return int 생성된 로그 수
     */
    private function seedCouponLogs($admins): int
    {
        $coupons = Coupon::get();
        if ($coupons->isEmpty()) {
            $this->command->warn('쿠폰 데이터가 없어 쿠폰 활동 로그를 건너뜁니다.');

            return 0;
        }

        $actions = [
            ['action' => 'coupon.create', 'key' => 'coupon_create', 'loggable' => true,
                'params' => fn ($c) => ['coupon_name' => $c->name]],
            ['action' => 'coupon.update', 'key' => 'coupon_update', 'loggable' => true,
                'params' => fn ($c) => ['coupon_name' => $c->name],
                'changes' => fn ($c) => [
                    ['field' => 'discount_value', 'label_key' => 'sirsoft-ecommerce::activity_log.fields.discount_value', 'old' => (int) (($c->discount_value ?? 1000) * $this->randomFloat(0.8, 1.2)), 'new' => $c->discount_value, 'type' => 'currency'],
                    ['field' => 'issue_status', 'label_key' => 'sirsoft-ecommerce::activity_log.fields.issue_status', 'old' => $this->pickDifferentEnum(CouponIssueStatus::class, $c->issue_status), 'new' => $c->issue_status?->value ?? CouponIssueStatus::ISSUING->value, 'type' => 'enum'],
                ]],
            ['action' => 'coupon.show', 'key' => 'coupon_show', 'loggable' => true,
                'params' => fn ($c) => ['coupon_name' => $c->name]],
            ['action' => 'coupon.delete', 'key' => 'coupon_delete', 'loggable' => false,
                'params' => fn ($c) => ['coupon_name' => $c->name],
                'properties' => fn ($c) => ['deleted_id' => $c->id, 'coupon_name' => $c->name]],
            ['action' => 'coupon.bulk_status', 'key' => 'coupon_bulk_status', 'loggable' => true,
                'params' => fn ($c) => ['coupon_name' => $c->name, 'count' => 1],
                'changes' => fn ($c) => [
                    ['field' => 'issue_status', 'label_key' => 'sirsoft-ecommerce::activity_log.fields.issue_status', 'old' => $c->issue_status?->value ?? CouponIssueStatus::ISSUING->value, 'new' => $this->pickDifferentEnum(CouponIssueStatus::class, $c->issue_status), 'type' => 'enum'],
                ]],
        ];

        $count = $this->generateResourceLogs($coupons, $admins, ActivityLogType::Admin, (new Coupon)->getMorphClass(), $actions);
        $count += $this->generateNonResourceLogs($admins, ActivityLogType::Admin, [
            ['action' => 'coupon.index', 'key' => 'coupon_index', 'params' => []],
        ]);

        return $count;
    }

    /**
     * 배송정책 활동 로그
     *
     * @param  Collection  $admins  관리자 컬렉션
     * @return int 생성된 로그 수
     */
    private function seedShippingPolicyLogs($admins): int
    {
        $policies = ShippingPolicy::get();
        if ($policies->isEmpty()) {
            $this->command->warn('배송정책 데이터가 없어 배송정책 활동 로그를 건너뜁니다.');

            return 0;
        }

        $actions = [
            ['action' => 'shipping_policy.create', 'key' => 'shipping_policy_create', 'loggable' => true,
                'params' => fn ($p) => ['policy_name' => $this->getLocalizedName($p->name)]],
            ['action' => 'shipping_policy.update', 'key' => 'shipping_policy_update', 'loggable' => true,
                'params' => fn ($p) => ['policy_name' => $this->getLocalizedName($p->name)],
                'changes' => fn ($p) => [
                    ['field' => 'is_active', 'label_key' => 'sirsoft-ecommerce::activity_log.fields.is_active', 'old' => ! $p->is_active, 'new' => $p->is_active, 'type' => 'boolean'],
                ]],
            ['action' => 'shipping_policy.toggle_active', 'key' => 'shipping_policy_toggle_active', 'loggable' => true,
                'params' => fn ($p) => ['policy_name' => $this->getLocalizedName($p->name)]],
            ['action' => 'shipping_policy.set_default', 'key' => 'shipping_policy_set_default', 'loggable' => true,
                'params' => fn ($p) => ['policy_name' => $this->getLocalizedName($p->name)]],
            ['action' => 'shipping_policy.delete', 'key' => 'shipping_policy_delete', 'loggable' => false,
                'params' => fn ($p) => ['policy_name' => $this->getLocalizedName($p->name)],
                'properties' => fn ($p) => ['deleted_id' => $p->id, 'policy_name' => $this->getLocalizedName($p->name)]],
            ['action' => 'shipping_policy.bulk_delete', 'key' => 'shipping_policy_bulk_delete', 'loggable' => true,
                'params' => fn ($p) => ['policy_name' => $this->getLocalizedName($p->name), 'count' => 1]],
            ['action' => 'shipping_policy.bulk_toggle_active', 'key' => 'shipping_policy_bulk_toggle_active', 'loggable' => true,
                'params' => fn ($p) => ['policy_name' => $this->getLocalizedName($p->name), 'count' => 1]],
        ];

        $count = $this->generateResourceLogs($policies, $admins, ActivityLogType::Admin, (new ShippingPolicy)->getMorphClass(), $actions);
        $count += $this->generateNonResourceLogs($admins, ActivityLogType::Admin, [
            ['action' => 'shipping_policy.index', 'key' => 'shipping_policy_index', 'params' => []],
        ]);

        return $count;
    }

    /**
     * 카테고리 활동 로그
     *
     * @param  Collection  $admins  관리자 컬렉션
     * @return int 생성된 로그 수
     */
    private function seedCategoryLogs($admins): int
    {
        $categories = Category::get();
        if ($categories->isEmpty()) {
            $this->command->warn('카테고리 데이터가 없어 카테고리 활동 로그를 건너뜁니다.');

            return 0;
        }

        $actions = [
            ['action' => 'category.create', 'key' => 'category_create', 'loggable' => true,
                'params' => fn ($c) => ['category_name' => $this->getLocalizedName($c->name)]],
            ['action' => 'category.update', 'key' => 'category_update', 'loggable' => true,
                'params' => fn ($c) => ['category_name' => $this->getLocalizedName($c->name)],
                'changes' => fn ($c) => [
                    ['field' => 'sort_order', 'label_key' => 'sirsoft-ecommerce::activity_log.fields.sort_order', 'old' => $c->sort_order + rand(1, 5), 'new' => $c->sort_order, 'type' => 'number'],
                ]],
            ['action' => 'category.toggle_status', 'key' => 'category_toggle_status', 'loggable' => true,
                'params' => fn ($c) => ['category_name' => $this->getLocalizedName($c->name)]],
            ['action' => 'category.show', 'key' => 'category_show', 'loggable' => true,
                'params' => fn ($c) => ['category_name' => $this->getLocalizedName($c->name)]],
            ['action' => 'category.delete', 'key' => 'category_delete', 'loggable' => false,
                'params' => fn ($c) => ['category_name' => $this->getLocalizedName($c->name)],
                'properties' => fn ($c) => ['deleted_id' => $c->id, 'category_name' => $this->getLocalizedName($c->name)]],
        ];

        $count = $this->generateResourceLogs($categories, $admins, ActivityLogType::Admin, (new Category)->getMorphClass(), $actions);
        $count += $this->generateNonResourceLogs($admins, ActivityLogType::Admin, [
            ['action' => 'category.index', 'key' => 'category_index', 'params' => []],
            ['action' => 'category.reorder', 'key' => 'category_reorder', 'params' => []],
        ]);

        return $count;
    }

    /**
     * 브랜드 활동 로그
     *
     * @param  Collection  $admins  관리자 컬렉션
     * @return int 생성된 로그 수
     */
    private function seedBrandLogs($admins): int
    {
        $brands = Brand::get();
        if ($brands->isEmpty()) {
            $this->command->warn('브랜드 데이터가 없어 브랜드 활동 로그를 건너뜁니다.');

            return 0;
        }

        $actions = [
            ['action' => 'brand.create', 'key' => 'brand_create', 'loggable' => true,
                'params' => fn ($b) => ['brand_name' => $this->getLocalizedName($b->name)]],
            ['action' => 'brand.update', 'key' => 'brand_update', 'loggable' => true,
                'params' => fn ($b) => ['brand_name' => $this->getLocalizedName($b->name)],
                'changes' => fn ($b) => [
                    ['field' => 'website', 'label_key' => 'sirsoft-ecommerce::activity_log.fields.website', 'old' => 'https://old-brand.example.com', 'new' => $b->website ?? 'https://brand.example.com', 'type' => 'text'],
                ]],
            ['action' => 'brand.show', 'key' => 'brand_show', 'loggable' => true,
                'params' => fn ($b) => ['brand_name' => $this->getLocalizedName($b->name)]],
            ['action' => 'brand.toggle_status', 'key' => 'brand_toggle_status', 'loggable' => true,
                'params' => fn ($b) => ['brand_name' => $this->getLocalizedName($b->name)]],
            ['action' => 'brand.delete', 'key' => 'brand_delete', 'loggable' => false,
                'params' => fn ($b) => ['brand_name' => $this->getLocalizedName($b->name)],
                'properties' => fn ($b) => ['deleted_id' => $b->id, 'brand_name' => $this->getLocalizedName($b->name)]],
        ];

        $count = $this->generateResourceLogs($brands, $admins, ActivityLogType::Admin, (new Brand)->getMorphClass(), $actions);
        $count += $this->generateNonResourceLogs($admins, ActivityLogType::Admin, [
            ['action' => 'brand.index', 'key' => 'brand_index', 'params' => []],
        ]);

        return $count;
    }

    /**
     * 리뷰 활동 로그
     *
     * @param  Collection  $admins  관리자 컬렉션
     * @return int 생성된 로그 수
     */
    private function seedReviewLogs($admins): int
    {
        $reviews = ProductReview::with('product')->get();
        if ($reviews->isEmpty()) {
            $this->command->warn('리뷰 데이터가 없어 리뷰 활동 로그를 건너뜁니다.');

            return 0;
        }

        $actions = [
            ['action' => 'review.show', 'key' => 'review_show', 'loggable' => true,
                'params' => fn ($r) => ['review_id' => $r->id]],
            ['action' => 'review.create', 'key' => 'review_create', 'loggable' => true,
                'params' => fn ($r) => ['product_name' => $r->product ? $this->getLocalizedName($r->product->name) : '삭제된 상품']],
            ['action' => 'review.delete', 'key' => 'review_delete', 'loggable' => false,
                'params' => fn ($r) => ['review_id' => $r->id],
                'properties' => fn ($r) => ['deleted_id' => $r->id, 'product_id' => $r->product_id, 'user_id' => $r->user_id]],
            ['action' => 'review.reply', 'key' => 'review_reply', 'loggable' => true,
                'params' => fn ($r) => ['review_id' => $r->id]],
            ['action' => 'product_review.bulk_delete', 'key' => 'review_bulk_delete', 'loggable' => true,
                'params' => fn ($r) => ['review_id' => $r->id, 'count' => 1]],
            ['action' => 'product_review.create', 'key' => 'product_review_create', 'loggable' => true,
                'params' => fn ($r) => ['product_name' => $r->product ? $this->getLocalizedName($r->product->name) : '삭제된 상품']],
            ['action' => 'product_review.delete', 'key' => 'product_review_delete', 'loggable' => false,
                'params' => fn ($r) => ['product_name' => $r->product ? $this->getLocalizedName($r->product->name) : '삭제된 상품'],
                'properties' => fn ($r) => ['deleted_id' => $r->id]],
        ];

        $count = $this->generateResourceLogs($reviews, $admins, ActivityLogType::Admin, (new ProductReview)->getMorphClass(), $actions);
        $count += $this->generateNonResourceLogs($admins, ActivityLogType::Admin, [
            ['action' => 'review.index', 'key' => 'review_index', 'params' => []],
        ]);

        return $count;
    }

    /**
     * 라벨 활동 로그
     *
     * @param  Collection  $admins  관리자 컬렉션
     * @return int 생성된 로그 수
     */
    private function seedLabelLogs($admins): int
    {
        $labels = ProductLabel::get();
        if ($labels->isEmpty()) {
            return 0;
        }

        $actions = [
            ['action' => 'label.create', 'key' => 'label_create', 'loggable' => true,
                'params' => fn ($l) => ['label_name' => $this->getLocalizedName($l->name)]],
            ['action' => 'label.update', 'key' => 'label_update', 'loggable' => true,
                'params' => fn ($l) => ['label_name' => $this->getLocalizedName($l->name)]],
            ['action' => 'label.toggle_status', 'key' => 'label_toggle_status', 'loggable' => true,
                'params' => fn ($l) => ['label_name' => $this->getLocalizedName($l->name)]],
            ['action' => 'label.delete', 'key' => 'label_delete', 'loggable' => false,
                'params' => fn ($l) => ['label_name' => $this->getLocalizedName($l->name)],
                'properties' => fn ($l) => ['deleted_id' => $l->id]],
        ];

        $count = $this->generateResourceLogs($labels, $admins, ActivityLogType::Admin, (new ProductLabel)->getMorphClass(), $actions);
        $count += $this->generateNonResourceLogs($admins, ActivityLogType::Admin, [
            ['action' => 'label.index', 'key' => 'label_index', 'params' => []],
        ]);

        return $count;
    }

    /**
     * 공통정보 활동 로그
     *
     * @param  Collection  $admins  관리자 컬렉션
     * @return int 생성된 로그 수
     */
    private function seedCommonInfoLogs($admins): int
    {
        $infos = ProductCommonInfo::get();
        if ($infos->isEmpty()) {
            return 0;
        }

        $actions = [
            ['action' => 'product_common_info.create', 'key' => 'product_common_info_create', 'loggable' => true,
                'params' => fn ($i) => ['info_name' => $this->getLocalizedName($i->name)]],
            ['action' => 'product_common_info.update', 'key' => 'product_common_info_update', 'loggable' => true,
                'params' => fn ($i) => ['info_name' => $this->getLocalizedName($i->name)]],
            ['action' => 'product_common_info.delete', 'key' => 'product_common_info_delete', 'loggable' => false,
                'params' => fn ($i) => ['info_name' => $this->getLocalizedName($i->name)],
                'properties' => fn ($i) => ['deleted_id' => $i->id]],
        ];

        $count = $this->generateResourceLogs($infos, $admins, ActivityLogType::Admin, (new ProductCommonInfo)->getMorphClass(), $actions);
        $count += $this->generateNonResourceLogs($admins, ActivityLogType::Admin, [
            ['action' => 'product_common_info.index', 'key' => 'product_common_info_index', 'params' => []],
        ]);

        return $count;
    }

    /**
     * 고시정보 템플릿 활동 로그
     *
     * @param  Collection  $admins  관리자 컬렉션
     * @return int 생성된 로그 수
     */
    private function seedNoticeTemplateLogs($admins): int
    {
        $templates = ProductNoticeTemplate::get();
        if ($templates->isEmpty()) {
            return 0;
        }

        $actions = [
            ['action' => 'product_notice_template.create', 'key' => 'product_notice_template_create', 'loggable' => true,
                'params' => fn ($t) => ['template_name' => $this->getLocalizedName($t->name)]],
            ['action' => 'product_notice_template.update', 'key' => 'product_notice_template_update', 'loggable' => true,
                'params' => fn ($t) => ['template_name' => $this->getLocalizedName($t->name)]],
            ['action' => 'product_notice_template.copy', 'key' => 'product_notice_template_copy', 'loggable' => true,
                'params' => fn ($t) => ['template_name' => $this->getLocalizedName($t->name)]],
            ['action' => 'product_notice_template.delete', 'key' => 'product_notice_template_delete', 'loggable' => false,
                'params' => fn ($t) => ['template_name' => $this->getLocalizedName($t->name)],
                'properties' => fn ($t) => ['deleted_id' => $t->id]],
        ];

        $count = $this->generateResourceLogs($templates, $admins, ActivityLogType::Admin, (new ProductNoticeTemplate)->getMorphClass(), $actions);
        $count += $this->generateNonResourceLogs($admins, ActivityLogType::Admin, [
            ['action' => 'product_notice_template.index', 'key' => 'product_notice_template_index', 'params' => []],
        ]);

        return $count;
    }

    /**
     * 추가배송비 활동 로그
     *
     * @param  Collection  $admins  관리자 컬렉션
     * @return int 생성된 로그 수
     */
    private function seedExtraFeeLogs($admins): int
    {
        $fees = ExtraFeeTemplate::get();
        if ($fees->isEmpty()) {
            return 0;
        }

        $getName = fn ($f) => $f->region ?? $f->zipcode;

        $actions = [
            ['action' => 'extra_fee_template.create', 'key' => 'extra_fee_template_create', 'loggable' => true,
                'params' => fn ($f) => ['template_name' => $getName($f)]],
            ['action' => 'extra_fee_template.update', 'key' => 'extra_fee_template_update', 'loggable' => true,
                'params' => fn ($f) => ['template_name' => $getName($f)]],
            ['action' => 'extra_fee_template.toggle_active', 'key' => 'extra_fee_template_toggle_active', 'loggable' => true,
                'params' => fn ($f) => ['template_name' => $getName($f)]],
            ['action' => 'extra_fee_template.delete', 'key' => 'extra_fee_template_delete', 'loggable' => false,
                'params' => fn ($f) => ['template_name' => $getName($f)],
                'properties' => fn ($f) => ['deleted_id' => $f->id]],
            ['action' => 'extra_fee_template.bulk_delete', 'key' => 'extra_fee_template_bulk_delete', 'loggable' => true,
                'params' => fn ($f) => ['template_name' => $getName($f), 'count' => 1]],
            ['action' => 'extra_fee_template.bulk_toggle_active', 'key' => 'extra_fee_template_bulk_toggle_active', 'loggable' => true,
                'params' => fn ($f) => ['template_name' => $getName($f), 'count' => 1]],
            ['action' => 'extra_fee_template.bulk_create', 'key' => 'extra_fee_template_bulk_create', 'loggable' => true,
                'params' => fn ($f) => ['template_name' => $getName($f), 'count' => 1]],
        ];

        $count = $this->generateResourceLogs($fees, $admins, ActivityLogType::Admin, (new ExtraFeeTemplate)->getMorphClass(), $actions);
        $count += $this->generateNonResourceLogs($admins, ActivityLogType::Admin, [
            ['action' => 'extra_fee_template.index', 'key' => 'extra_fee_template_index', 'params' => []],
        ]);

        return $count;
    }

    /**
     * 배송사 활동 로그
     *
     * @param  Collection  $admins  관리자 컬렉션
     * @return int 생성된 로그 수
     */
    private function seedCarrierLogs($admins): int
    {
        $carriers = ShippingCarrier::get();
        if ($carriers->isEmpty()) {
            return 0;
        }

        $actions = [
            ['action' => 'shipping_carrier.create', 'key' => 'shipping_carrier_create', 'loggable' => true,
                'params' => fn ($c) => ['carrier_name' => $this->getLocalizedName($c->name)]],
            ['action' => 'shipping_carrier.update', 'key' => 'shipping_carrier_update', 'loggable' => true,
                'params' => fn ($c) => ['carrier_name' => $this->getLocalizedName($c->name)]],
            ['action' => 'shipping_carrier.show', 'key' => 'shipping_carrier_show', 'loggable' => true,
                'params' => fn ($c) => ['carrier_name' => $this->getLocalizedName($c->name)]],
            ['action' => 'shipping_carrier.toggle_status', 'key' => 'shipping_carrier_toggle_status', 'loggable' => true,
                'params' => fn ($c) => ['carrier_name' => $this->getLocalizedName($c->name)]],
            ['action' => 'shipping_carrier.delete', 'key' => 'shipping_carrier_delete', 'loggable' => false,
                'params' => fn ($c) => ['carrier_name' => $this->getLocalizedName($c->name)],
                'properties' => fn ($c) => ['deleted_id' => $c->id]],
        ];

        $count = $this->generateResourceLogs($carriers, $admins, ActivityLogType::Admin, (new ShippingCarrier)->getMorphClass(), $actions);
        $count += $this->generateNonResourceLogs($admins, ActivityLogType::Admin, [
            ['action' => 'shipping_carrier.index', 'key' => 'shipping_carrier_index', 'params' => []],
        ]);

        return $count;
    }

    /**
     * 이커머스 설정 활동 로그
     *
     * @param  Collection  $admins  관리자 컬렉션
     * @return int 생성된 로그 수
     */
    private function seedSettingsLogs($admins): int
    {
        return $this->generateNonResourceLogs($admins, ActivityLogType::Admin, [
            ['action' => 'ecommerce_settings.index', 'key' => 'ecommerce_settings_index', 'params' => []],
        ]);
    }

    // ──────────────────────────────────────────────
    // User 리소스 로그
    // ──────────────────────────────────────────────

    /**
     * 장바구니 활동 로그
     *
     * @param  Collection  $users  사용자 컬렉션
     * @return int 생성된 로그 수
     */
    private function seedCartLogs($users): int
    {
        $products = Product::where('sales_status', ProductSalesStatus::ON_SALE)->get();
        if ($products->isEmpty()) {
            $this->command->warn('판매중 상품이 없어 장바구니 활동 로그를 건너뜁니다.');

            return 0;
        }

        $actions = [
            ['action' => 'cart.add', 'key' => 'cart_add', 'loggable' => true,
                'params' => fn ($p) => ['product_name' => $this->getLocalizedName($p->name)]],
            ['action' => 'cart.update_quantity', 'key' => 'cart_update_quantity', 'loggable' => true,
                'params' => fn ($p) => ['product_name' => $this->getLocalizedName($p->name)],
                'changes' => fn ($p) => [
                    ['field' => 'quantity', 'label_key' => 'sirsoft-ecommerce::activity_log.fields.quantity', 'old' => rand(1, 3), 'new' => rand(1, 10), 'type' => 'number'],
                ]],
            ['action' => 'cart.change_option', 'key' => 'cart_change_option', 'loggable' => true,
                'params' => fn ($p) => ['product_name' => $this->getLocalizedName($p->name)]],
            ['action' => 'cart.delete', 'key' => 'cart_delete', 'loggable' => false,
                'params' => fn ($p) => [],
                'properties' => fn ($p) => ['product_id' => $p->id, 'product_name' => $this->getLocalizedName($p->name)]],
        ];

        $count = $this->generateResourceLogs($products, $users, ActivityLogType::User, (new Product)->getMorphClass(), $actions);
        $count += $this->generateNonResourceLogs($users, ActivityLogType::User, [
            ['action' => 'cart.delete_all', 'key' => 'cart_delete_all', 'params' => []],
        ]);

        return $count;
    }

    /**
     * 찜 활동 로그
     *
     * @param  Collection  $users  사용자 컬렉션
     * @return int 생성된 로그 수
     */
    private function seedWishlistLogs($users): int
    {
        $products = Product::where('sales_status', ProductSalesStatus::ON_SALE)->get();
        if ($products->isEmpty()) {
            return 0;
        }

        $actions = [
            ['action' => 'wishlist.add', 'key' => 'wishlist_add', 'loggable' => true,
                'params' => fn ($p) => ['product_name' => $this->getLocalizedName($p->name)]],
            ['action' => 'wishlist.remove', 'key' => 'wishlist_remove', 'loggable' => true,
                'params' => fn ($p) => ['product_name' => $this->getLocalizedName($p->name)]],
        ];

        return $this->generateResourceLogs($products, $users, ActivityLogType::User, (new Product)->getMorphClass(), $actions);
    }

    /**
     * 쿠폰 사용/다운로드/복원 활동 로그
     *
     * @param  Collection  $users  사용자 컬렉션
     * @return int 생성된 로그 수
     */
    private function seedCouponUseLogs($users): int
    {
        $coupons = Coupon::get();
        if ($coupons->isEmpty()) {
            return 0;
        }

        $actions = [
            ['action' => 'coupon.use', 'key' => 'coupon_use', 'loggable' => true,
                'params' => fn ($c) => ['coupon_name' => $c->name]],
            ['action' => 'coupon.restore', 'key' => 'coupon_restore', 'loggable' => true,
                'params' => fn ($c) => ['coupon_name' => $c->name]],
            ['action' => 'user_coupon.download', 'key' => 'user_coupon_download', 'loggable' => true,
                'params' => fn ($c) => ['coupon_name' => $c->name]],
        ];

        return $this->generateResourceLogs($coupons, $users, ActivityLogType::User, (new Coupon)->getMorphClass(), $actions);
    }

    /**
     * 마일리지 활동 로그
     *
     * @param  Collection  $users  사용자 컬렉션
     * @return int 생성된 로그 수
     */
    private function seedMileageLogs($users): int
    {
        $orders = Order::get();
        if ($orders->isEmpty()) {
            return 0;
        }

        $actions = [
            ['action' => 'mileage.earn', 'key' => 'mileage_earn', 'loggable' => true,
                'params' => fn ($o) => ['amount' => number_format($o->total_earned_points_amount > 0 ? $o->total_earned_points_amount : rand(100, 5000))]],
            ['action' => 'mileage.use', 'key' => 'mileage_use', 'loggable' => true,
                'params' => fn ($o) => ['amount' => number_format($o->total_points_used_amount > 0 ? $o->total_points_used_amount : rand(500, 3000))]],
            ['action' => 'mileage.restore', 'key' => 'mileage_restore', 'loggable' => true,
                'params' => fn ($o) => ['amount' => number_format(rand(100, 2000))]],
        ];

        return $this->generateResourceLogs($orders, $users, ActivityLogType::User, (new Order)->getMorphClass(), $actions);
    }

    /**
     * 사용자 주문/구매확인 활동 로그 (User 타입)
     *
     * @param  Collection  $users  사용자 컬렉션
     * @return int 생성된 로그 수
     */
    private function seedUserOrderLogs($users): int
    {
        $orders = Order::with('options')->get();
        if ($orders->isEmpty()) {
            return 0;
        }

        // 주문 레벨
        $orderActions = [
            ['action' => 'order.create', 'key' => 'user_order_create', 'loggable' => true,
                'params' => fn ($o) => ['order_number' => $o->order_number]],
        ];

        $count = $this->generateResourceLogs($orders, $users, ActivityLogType::User, (new Order)->getMorphClass(), $orderActions);

        // OrderOption 레벨
        $allOptions = OrderOption::with('order')->whereIn('order_id', $orders->pluck('id'))->get();
        if ($allOptions->isNotEmpty()) {
            $optionActions = [
                ['action' => 'order_option.confirm', 'key' => 'user_order_option_confirm', 'loggable' => true,
                    'params' => fn ($opt) => ['order_number' => $opt->order?->order_number ?? 'N/A'],
                    'properties' => fn ($opt) => ['order_id' => $opt->order_id]],
            ];

            $count += $this->generateResourceLogs($allOptions, $users, ActivityLogType::User, (new OrderOption)->getMorphClass(), $optionActions);
        }

        return $count;
    }

    // ──────────────────────────────────────────────
    // 헬퍼 메서드
    // ──────────────────────────────────────────────

    /**
     * 리소스별 rand(1, 50)건의 랜덤 활동 로그를 생성합니다.
     *
     * @param  iterable  $resources  리소스 컬렉션
     * @param  Collection  $actors  사용자 컬렉션
     * @param  ActivityLogType  $logType  로그 유형
     * @param  string  $morphType  모델 morph 타입
     * @param  array  $actions  액션 템플릿 배열
     * @return int 생성된 로그 수
     */
    private function generateResourceLogs(
        $resources,
        Collection $actors,
        ActivityLogType $logType,
        string $morphType,
        array $actions,
    ): int {
        $count = 0;

        foreach ($resources as $resource) {
            $logCount = rand(1, 50);

            for ($i = 0; $i < $logCount; $i++) {
                $action = $actions[array_rand($actions)];

                $this->createLog(
                    $logType,
                    $action['loggable'] ? $morphType : null,
                    $action['loggable'] ? $resource->id : null,
                    $actors->random()->id,
                    $action['action'],
                    self::PREFIX.$action['key'],
                    ($action['params'])($resource),
                    isset($action['changes']) ? ($action['changes'])($resource) : null,
                    isset($action['properties']) ? ($action['properties'])($resource) : null,
                );
                $count++;
            }
        }

        return $count;
    }

    /**
     * 리소스에 속하지 않는 글로벌 액션 로그를 생성합니다. (index 등)
     *
     * @param  Collection  $actors  사용자 컬렉션
     * @param  ActivityLogType  $logType  로그 유형
     * @param  array  $actions  액션 정의 배열
     * @return int 생성된 로그 수
     */
    private function generateNonResourceLogs(
        Collection $actors,
        ActivityLogType $logType,
        array $actions,
    ): int {
        $count = 0;

        foreach ($actions as $action) {
            $logCount = rand(1, 5);

            for ($i = 0; $i < $logCount; $i++) {
                $this->createLog(
                    $logType,
                    null,
                    null,
                    $actors->random()->id,
                    $action['action'],
                    self::PREFIX.$action['key'],
                    $action['params'],
                );
                $count++;
            }
        }

        return $count;
    }

    /**
     * ActivityLog 레코드를 생성합니다.
     *
     * @param  ActivityLogType  $logType  로그 유형
     * @param  string|null  $loggableType  모델 morph 타입
     * @param  int|null  $loggableId  모델 ID
     * @param  int  $userId  사용자 ID
     * @param  string  $action  액션명
     * @param  string  $descriptionKey  다국어 키
     * @param  array  $descriptionParams  다국어 파라미터
     * @param  array|null  $changes  변경 이력
     * @param  array|null  $properties  추가 속성
     */
    private function createLog(
        ActivityLogType $logType,
        ?string $loggableType,
        ?int $loggableId,
        int $userId,
        string $action,
        string $descriptionKey,
        array $descriptionParams,
        ?array $changes = null,
        ?array $properties = null,
    ): ActivityLog {
        return ActivityLog::create([
            'log_type' => $logType,
            'loggable_type' => $loggableType,
            'loggable_id' => $loggableId,
            'user_id' => $userId,
            'action' => $action,
            'description_key' => $descriptionKey,
            'description_params' => $descriptionParams,
            'changes' => $changes,
            'properties' => $properties,
            'ip_address' => self::IPS[array_rand(self::IPS)],
            'user_agent' => self::USER_AGENTS[array_rand(self::USER_AGENTS)],
            'created_at' => Carbon::now()->subDays(rand(1, 30))->subHours(rand(0, 23))->subMinutes(rand(0, 59)),
        ]);
    }

    /**
     * 다국어 name 배열에서 현재 로케일 값을 가져옵니다.
     *
     * @param  mixed  $name  name 속성 (배열 또는 문자열)
     */
    private function getLocalizedName(mixed $name): string
    {
        if (is_string($name)) {
            return $name;
        }

        if (is_array($name)) {
            $locale = app()->getLocale();

            return $name[$locale] ?? $name['ko'] ?? $name[array_key_first($name)] ?? '';
        }

        return '';
    }

    /**
     * Enum에서 현재 값과 다른 값을 반환합니다.
     *
     * @param  class-string  $enumClass  Enum 클래스
     * @param  mixed  $currentValue  현재 값
     */
    private function pickDifferentEnum(string $enumClass, mixed $currentValue): string
    {
        $cases = $enumClass::cases();
        $currentStr = $currentValue instanceof \BackedEnum ? $currentValue->value : (string) $currentValue;

        $others = array_filter($cases, fn ($c) => $c->value !== $currentStr);

        if (empty($others)) {
            return $cases[0]->value;
        }

        return $others[array_rand($others)]->value;
    }

    /**
     * 지정 범위 내 랜덤 float를 반환합니다.
     *
     * @param  float  $min  최소값
     * @param  float  $max  최대값
     */
    private function randomFloat(float $min, float $max): float
    {
        return $min + mt_rand() / mt_getrandmax() * ($max - $min);
    }
}
