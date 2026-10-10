<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use App\Enums\ActivityLogType;
use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Log;
use Mockery;
use Modules\Sirsoft\Ecommerce\Listeners\EcommerceAdminActivityLogListener;
use Modules\Sirsoft\Ecommerce\Models\Brand;
use Modules\Sirsoft\Ecommerce\Models\ExtraFeeTemplate;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductCommonInfo;
use Modules\Sirsoft\Ecommerce\Models\ProductImage;
use Modules\Sirsoft\Ecommerce\Models\ProductLabel;
use Modules\Sirsoft\Ecommerce\Models\ProductNoticeTemplate;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Psr\Log\LoggerInterface;

/**
 * EcommerceAdminActivityLogListener 테스트
 *
 * 이커머스 관리자 엔티티 통합 활동 로그 리스너의 모든 훅 메서드를 검증합니다.
 * - 로그 기록 (37개): Brand(5), Label(5), CommonInfo(4), NoticeTemplate(5),
 *   ExtraFee(9), ShippingPolicy(4), Carrier(5), Image(3), Option(건별 로그), Review(4)
 * - 스냅샷은 Service 계층에서 캡처하여 after_* 훅에 인수로 전달됩니다.
 */
class EcommerceAdminActivityLogListenerTest extends ModuleTestCase
{
    private EcommerceAdminActivityLogListener $listener;

    private $logChannel;

    /**
     * 요청 컨텍스트를 관리자 API 경로로 두고 리스너를 만든 뒤, activity 로그 채널을 목으로 바꿔
     * 각 테스트가 기록 호출을 단언할 수 있게 한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance('request', Request::create('/api/admin/sirsoft-ecommerce/test'));
        $this->listener = app(EcommerceAdminActivityLogListener::class);
        $this->logChannel = Mockery::mock(LoggerInterface::class);
        Log::shouldReceive('channel')
            ->with('activity')
            ->andReturn($this->logChannel);
        Log::shouldReceive('error')->byDefault();
    }

    // ═══════════════════════════════════════════
    // getSubscribedHooks
    // ═══════════════════════════════════════════

    /**
     * 구독 훅이 회원 데이터 일괄 삭제 훅 4개(#524)를 포함해 모두 45개이고, 도메인별 after_* 훅은 구독하되 before_* 훅은 구독하지 않는지 확인한다.
     *
     * @scenario domain=subscription
     *
     * @effects subscribed_hooks_cover_all_admin_domains
     *
     * @return void 반환값 없음
     */
    public function test_get_subscribed_hooks_returns_all_hooks(): void
    {
        $hooks = EcommerceAdminActivityLogListener::getSubscribedHooks();

        // Brand(4) + UserPreference(2) + Label(4) + CommonInfo(3) + NoticeTemplate(5) + ExtraFee(7)
        // + ShippingPolicy(2) + Carrier(4) + Image(3) + Option(3) + Review(3) + Settings(1)
        // + 회원 데이터 일괄 삭제(4: 찜·장바구니·배송지·상품 문의, #524)
        // + 클레임 사유(4: 생성·수정·삭제·사용 여부 전환) = 49
        $this->assertCount(49, $hooks);
        foreach (['wishlist', 'cart', 'user_address', 'product_inquiry'] as $domain) {
            $this->assertArrayHasKey("sirsoft-ecommerce.{$domain}.after_bulk_delete", $hooks);
        }

        // Brand
        $this->assertArrayHasKey('sirsoft-ecommerce.brand.after_create', $hooks);
        $this->assertArrayNotHasKey('sirsoft-ecommerce.brand.before_update', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.brand.after_update', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.brand.after_delete', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.brand.after_toggle_status', $hooks);

        // Label
        $this->assertArrayHasKey('sirsoft-ecommerce.label.after_create', $hooks);
        $this->assertArrayNotHasKey('sirsoft-ecommerce.label.before_update', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.label.after_update', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.label.after_delete', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.label.after_toggle_status', $hooks);

        // CommonInfo
        $this->assertArrayHasKey('sirsoft-ecommerce.product-common-info.after_create', $hooks);
        $this->assertArrayNotHasKey('sirsoft-ecommerce.product-common-info.before_update', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.product-common-info.after_update', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.product-common-info.after_delete', $hooks);

        // NoticeTemplate
        $this->assertArrayHasKey('sirsoft-ecommerce.product-notice-template.after_create', $hooks);
        $this->assertArrayNotHasKey('sirsoft-ecommerce.product-notice-template.before_update', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.product-notice-template.after_update', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.product-notice-template.after_delete', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.product-notice-template.after_copy', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.product-notice-template.after_toggle_active', $hooks);

        // Settings
        $this->assertArrayHasKey('sirsoft-ecommerce.settings.after_save', $hooks);

        // UserCurrency (관리자 회원 통화 변경 — MP08 §A3)
        $this->assertArrayHasKey('sirsoft-ecommerce.admin.user_currency.changed', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.admin.user_shipping_country.changed', $hooks);

        // ExtraFee
        $this->assertArrayHasKey('sirsoft-ecommerce.extra_fee_template.after_create', $hooks);
        $this->assertArrayNotHasKey('sirsoft-ecommerce.extra_fee_template.before_update', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.extra_fee_template.after_update', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.extra_fee_template.after_delete', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.extra_fee_template.after_toggle_active', $hooks);
        $this->assertArrayNotHasKey('sirsoft-ecommerce.extra_fee_template.before_bulk_delete', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.extra_fee_template.after_bulk_delete', $hooks);
        $this->assertArrayNotHasKey('sirsoft-ecommerce.extra_fee_template.before_bulk_toggle_active', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.extra_fee_template.after_bulk_toggle_active', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.extra_fee_template.after_bulk_create', $hooks);

        // ShippingPolicy
        $this->assertArrayNotHasKey('sirsoft-ecommerce.shipping_policy.before_bulk_delete', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.shipping_policy.after_bulk_delete', $hooks);
        $this->assertArrayNotHasKey('sirsoft-ecommerce.shipping_policy.before_bulk_toggle_active', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.shipping_policy.after_bulk_toggle_active', $hooks);

        // Carrier
        $this->assertArrayHasKey('sirsoft-ecommerce.shipping_carrier.after_create', $hooks);
        $this->assertArrayNotHasKey('sirsoft-ecommerce.shipping_carrier.before_update', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.shipping_carrier.after_update', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.shipping_carrier.after_delete', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.shipping_carrier.after_toggle_status', $hooks);

        // Image
        $this->assertArrayHasKey('sirsoft-ecommerce.product-image.after_upload', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.product-image.after_delete', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.product-image.after_reorder', $hooks);

        // Option
        $this->assertArrayNotHasKey('sirsoft-ecommerce.product_option.before_bulk_price_update', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.product_option.after_bulk_price_update', $hooks);
        $this->assertArrayNotHasKey('sirsoft-ecommerce.product_option.before_bulk_stock_update', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.product_option.after_bulk_stock_update', $hooks);
        $this->assertArrayNotHasKey('sirsoft-ecommerce.option.before_bulk_update', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.option.after_bulk_update', $hooks);

        // Review
        $this->assertArrayHasKey('sirsoft-ecommerce.product-review.after_create', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.product-review.after_delete', $hooks);
        $this->assertArrayNotHasKey('sirsoft-ecommerce.product-review.before_bulk_delete', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.product-review.after_bulk_delete', $hooks);
    }

    // ═══════════════════════════════════════════
    // Brand 핸들러 테스트
    // ═══════════════════════════════════════════

    /**
     * 브랜드 생성 훅이 brand.create 관리자 로그를 남기고, 다국어 이름(AsUnicodeJson)을 배열 그대로 properties 에 담는지 확인한다.
     *
     * @scenario domain=brand
     *
     * @effects entity_create_logged_with_loggable_and_properties
     *
     * @return void 반환값 없음
     */
    public function test_handle_brand_after_create_logs_activity(): void
    {
        $brand = $this->createModelMock(Brand::class, 1, 'Nike');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'brand.create'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.brand_create'
                    && $context['description_params']['brand_id'] === 1
                    && isset($context['loggable'])
                    // Brand.name 은 AsUnicodeJson cast — 다국어 배열
                    && $context['properties']['name'] === ['ko' => 'Nike', 'en' => 'Nike'];
            });

        $this->listener->handleBrandAfterCreate($brand, ['name' => 'Nike']);
    }

    /**
     * 변경 전 스냅샷과 함께 온 브랜드 수정 훅이 brand.update 로그에 loggable 과 changes 키를 담는지 확인한다.
     *
     * @scenario domain=brand
     *
     * @effects entity_update_logged_with_snapshot_changes
     *
     * @return void 반환값 없음
     */
    public function test_handle_brand_after_update_logs_activity_with_changes(): void
    {
        $brand = $this->createModelMock(Brand::class, 1, 'Nike Updated');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'brand.update'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.brand_update'
                    && $context['description_params']['brand_id'] === 1
                    && isset($context['loggable'])
                    && array_key_exists('changes', $context);
            });

        $this->listener->handleBrandAfterUpdate($brand, ['name' => 'Nike Updated'], ['id' => 1, 'name' => 'Nike']);
    }

    /**
     * 스냅샷 없이 온 브랜드 수정 훅은 changes 를 null 로 두고 로그를 남기는지 확인한다.
     *
     * @scenario domain=brand
     *
     * @effects entity_update_without_snapshot_logs_null_changes
     *
     * @return void 반환값 없음
     */
    public function test_handle_brand_after_update_without_snapshot(): void
    {
        $brand = $this->createModelMock(Brand::class, 99, 'No Snap');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(fn ($action, $context) => $action === 'brand.update' && $context['changes'] === null);

        $this->listener->handleBrandAfterUpdate($brand, []);
    }

    /**
     * 브랜드 삭제 훅이 모델 없이 ID 만으로 brand.delete 로그를 남기고 loggable 을 싣지 않는지 확인한다.
     *
     * @scenario domain=brand
     *
     * @effects entity_delete_logged_by_id_without_loggable
     *
     * @return void 반환값 없음
     */
    public function test_handle_brand_after_delete_logs_activity(): void
    {
        $brandId = 10;

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) use ($brandId) {
                return $action === 'brand.delete'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.brand_delete'
                    && $context['description_params']['brand_id'] === $brandId
                    && $context['properties']['brand_id'] === $brandId
                    && ! isset($context['loggable']);
            });

        $this->listener->handleBrandAfterDelete($brandId);
    }

    /**
     * 브랜드 상태 전환 훅이 brand.toggle_status 로그를 대상 모델과 함께 남기는지 확인한다.
     *
     * @scenario domain=brand
     *
     * @effects entity_status_toggle_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_brand_after_toggle_status_logs_activity(): void
    {
        $brand = $this->createModelMock(Brand::class, 1, 'Nike');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'brand.toggle_status'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.brand_toggle_status'
                    && $context['description_params']['brand_id'] === 1
                    && isset($context['loggable']);
            });

        $this->listener->handleBrandAfterToggleStatus($brand);
    }

    // ═══════════════════════════════════════════
    // Label 핸들러 테스트
    // ═══════════════════════════════════════════

    /**
     * 라벨 생성 훅이 label.create 로그를 남기고 이름을 properties 에 담는지 확인한다.
     *
     * @scenario domain=label
     *
     * @effects entity_create_logged_with_loggable_and_properties
     *
     * @return void 반환값 없음
     */
    public function test_handle_label_after_create_logs_activity(): void
    {
        $label = $this->createModelMock(ProductLabel::class, 1, 'NEW');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'label.create'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.label_create'
                    && $context['description_params']['label_id'] === 1
                    && isset($context['loggable'])
                    && $context['properties']['name'] === 'NEW';
            });

        $this->listener->handleLabelAfterCreate($label, ['name' => 'NEW']);
    }

    /**
     * 변경 전 스냅샷과 함께 온 라벨 수정 훅이 label.update 로그에 loggable 과 changes 키를 담는지 확인한다.
     *
     * @scenario domain=label
     *
     * @effects entity_update_logged_with_snapshot_changes
     *
     * @return void 반환값 없음
     */
    public function test_handle_label_after_update_logs_activity_with_changes(): void
    {
        $label = $this->createModelMock(ProductLabel::class, 2, 'SALE');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'label.update'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.label_update'
                    && $context['description_params']['label_id'] === 2
                    && isset($context['loggable'])
                    && array_key_exists('changes', $context);
            });

        $this->listener->handleLabelAfterUpdate($label, [], ['id' => 2, 'name' => 'OLD']);
    }

    /**
     * 스냅샷 없이 온 라벨 수정 훅은 changes 를 null 로 두고 로그를 남기는지 확인한다.
     *
     * @scenario domain=label
     *
     * @effects entity_update_without_snapshot_logs_null_changes
     *
     * @return void 반환값 없음
     */
    public function test_handle_label_after_update_without_snapshot(): void
    {
        $label = $this->createModelMock(ProductLabel::class, 99, 'X');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(fn ($action, $context) => $action === 'label.update' && $context['changes'] === null);

        $this->listener->handleLabelAfterUpdate($label, []);
    }

    /**
     * 라벨 삭제 훅이 ID 만으로 label.delete 로그를 남기고 설명 인자와 properties 에 그 ID 를 싣는지 확인한다.
     *
     * @scenario domain=label
     *
     * @effects entity_delete_logged_by_id_without_loggable
     *
     * @return void 반환값 없음
     */
    public function test_handle_label_after_delete_logs_activity(): void
    {
        $labelId = 5;

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) use ($labelId) {
                return $action === 'label.delete'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.label_delete'
                    && $context['description_params']['label_id'] === $labelId
                    && $context['properties']['label_id'] === $labelId;
            });

        $this->listener->handleLabelAfterDelete($labelId);
    }

    /**
     * 라벨 상태 전환 훅이 label.toggle_status 로그를 대상 모델과 함께 남기는지 확인한다.
     *
     * @scenario domain=label
     *
     * @effects entity_status_toggle_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_label_after_toggle_status_logs_activity(): void
    {
        $label = $this->createModelMock(ProductLabel::class, 3, 'HOT');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'label.toggle_status'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.label_toggle_status'
                    && $context['description_params']['label_id'] === 3
                    && isset($context['loggable']);
            });

        $this->listener->handleLabelAfterToggleStatus($label);
    }

    // ═══════════════════════════════════════════
    // ProductCommonInfo 핸들러 테스트
    // ═══════════════════════════════════════════

    /**
     * 상품 공통정보 생성 훅이 product_common_info.create 로그를 남기고 다국어 이름을 배열 그대로 담는지 확인한다.
     *
     * @scenario domain=common_info
     *
     * @effects entity_create_logged_with_loggable_and_properties
     *
     * @return void 반환값 없음
     */
    public function test_handle_common_info_after_create_logs_activity(): void
    {
        $info = $this->createModelMock(ProductCommonInfo::class, 1, 'Default Info');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'product_common_info.create'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.product_common_info_create'
                    && $context['description_params']['common_info_id'] === 1
                    && isset($context['loggable'])
                    // ProductCommonInfo.name 은 AsUnicodeJson cast — 다국어 배열
                    && $context['properties']['name'] === ['ko' => 'Default Info', 'en' => 'Default Info'];
            });

        $this->listener->handleCommonInfoAfterCreate($info, ['name' => 'Default Info']);
    }

    /**
     * 변경 전 스냅샷과 함께 온 공통정보 수정 훅이 product_common_info.update 로그에 loggable 과 changes 키를 담는지 확인한다.
     *
     * @scenario domain=common_info
     *
     * @effects entity_update_logged_with_snapshot_changes
     *
     * @return void 반환값 없음
     */
    public function test_handle_common_info_after_update_logs_activity_with_changes(): void
    {
        $info = $this->createModelMock(ProductCommonInfo::class, 2, 'Updated Info');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'product_common_info.update'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.product_common_info_update'
                    && $context['description_params']['common_info_id'] === 2
                    && isset($context['loggable'])
                    && array_key_exists('changes', $context);
            });

        $this->listener->handleCommonInfoAfterUpdate($info, [], ['id' => 2, 'name' => 'Old Info']);
    }

    /**
     * 스냅샷 없이 온 공통정보 수정 훅은 changes 를 null 로 두고 로그를 남기는지 확인한다.
     *
     * @scenario domain=common_info
     *
     * @effects entity_update_without_snapshot_logs_null_changes
     *
     * @return void 반환값 없음
     */
    public function test_handle_common_info_after_update_without_snapshot(): void
    {
        $info = $this->createModelMock(ProductCommonInfo::class, 99, 'X');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(fn ($action, $context) => $action === 'product_common_info.update' && $context['changes'] === null);

        $this->listener->handleCommonInfoAfterUpdate($info, []);
    }

    /**
     * 공통정보 삭제 훅이 ID 만으로 product_common_info.delete 로그를 남기는지 확인한다.
     *
     * @scenario domain=common_info
     *
     * @effects entity_delete_logged_by_id_without_loggable
     *
     * @return void 반환값 없음
     */
    public function test_handle_common_info_after_delete_logs_activity(): void
    {
        $infoId = 7;

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) use ($infoId) {
                return $action === 'product_common_info.delete'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.product_common_info_delete'
                    && $context['description_params']['common_info_id'] === $infoId
                    && $context['properties']['common_info_id'] === $infoId;
            });

        $this->listener->handleCommonInfoAfterDelete($infoId);
    }

    // ═══════════════════════════════════════════
    // ProductNoticeTemplate 핸들러 테스트
    // ═══════════════════════════════════════════

    /**
     * 상품정보제공고시 템플릿 생성 훅이 product_notice_template.create 로그를 남기고 이름을 담는지 확인한다.
     *
     * @scenario domain=notice_template
     *
     * @effects entity_create_logged_with_loggable_and_properties
     *
     * @return void 반환값 없음
     */
    public function test_handle_notice_template_after_create_logs_activity(): void
    {
        $template = $this->createModelMock(ProductNoticeTemplate::class, 1, 'Food Notice');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'product_notice_template.create'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.product_notice_template_create'
                    && $context['description_params']['template_id'] === 1
                    && isset($context['loggable'])
                    && $context['properties']['name'] === 'Food Notice';
            });

        $this->listener->handleNoticeTemplateAfterCreate($template, ['name' => 'Food Notice']);
    }

    /**
     * 변경 전 스냅샷과 함께 온 고시 템플릿 수정 훅이 product_notice_template.update 로그에 loggable 과 changes 키를 담는지 확인한다.
     *
     * @scenario domain=notice_template
     *
     * @effects entity_update_logged_with_snapshot_changes
     *
     * @return void 반환값 없음
     */
    public function test_handle_notice_template_after_update_logs_activity_with_changes(): void
    {
        $template = $this->createModelMock(ProductNoticeTemplate::class, 2, 'Updated Notice');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'product_notice_template.update'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.product_notice_template_update'
                    && $context['description_params']['template_id'] === 2
                    && isset($context['loggable'])
                    && array_key_exists('changes', $context);
            });

        $this->listener->handleNoticeTemplateAfterUpdate($template, [], ['id' => 2, 'name' => 'Old Notice']);
    }

    /**
     * 스냅샷 없이 온 고시 템플릿 수정 훅은 changes 를 null 로 두고 로그를 남기는지 확인한다.
     *
     * @scenario domain=notice_template
     *
     * @effects entity_update_without_snapshot_logs_null_changes
     *
     * @return void 반환값 없음
     */
    public function test_handle_notice_template_after_update_without_snapshot(): void
    {
        $template = $this->createModelMock(ProductNoticeTemplate::class, 99, 'X');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(fn ($action, $context) => $action === 'product_notice_template.update' && $context['changes'] === null);

        $this->listener->handleNoticeTemplateAfterUpdate($template, []);
    }

    /**
     * 고시 템플릿 삭제 훅이 ID 만으로 product_notice_template.delete 로그를 남기는지 확인한다.
     *
     * @scenario domain=notice_template
     *
     * @effects entity_delete_logged_by_id_without_loggable
     *
     * @return void 반환값 없음
     */
    public function test_handle_notice_template_after_delete_logs_activity(): void
    {
        $templateId = 8;

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) use ($templateId) {
                return $action === 'product_notice_template.delete'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.product_notice_template_delete'
                    && $context['description_params']['template_id'] === $templateId
                    && $context['properties']['template_id'] === $templateId;
            });

        $this->listener->handleNoticeTemplateAfterDelete($templateId);
    }

    /**
     * 고시 템플릿 복사 훅이 복사본을 대상으로 product_notice_template.copy 로그를 남기고 원본·복사본 ID 를 함께 담는지 확인한다.
     *
     * @scenario domain=notice_template
     *
     * @effects notice_template_copy_logs_original_and_copied_ids
     *
     * @return void 반환값 없음
     */
    public function test_handle_notice_template_after_copy_logs_activity(): void
    {
        $copied = $this->createModelMock(ProductNoticeTemplate::class, 10, 'Copied');
        $original = $this->createModelMock(ProductNoticeTemplate::class, 5, 'Original');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'product_notice_template.copy'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.product_notice_template_copy'
                    && $context['description_params']['template_id'] === 10
                    && isset($context['loggable'])
                    && $context['properties']['original_id'] === 5
                    && $context['properties']['copied_id'] === 10;
            });

        $this->listener->handleNoticeTemplateAfterCopy($copied, $original);
    }

    /**
     * 고시 템플릿 활성 전환 훅이 product_notice_template.toggle_active 로그에 전환 후 is_active 값을 담는지 확인한다.
     *
     * @scenario domain=notice_template
     *
     * @effects entity_status_toggle_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_notice_template_after_toggle_active_logs_activity(): void
    {
        $template = $this->createModelMock(ProductNoticeTemplate::class, 7, 'Toggled');
        $template->is_active = true;

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'product_notice_template.toggle_active'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.product_notice_template_toggle_active'
                    && $context['description_params']['template_id'] === 7
                    && isset($context['loggable'])
                    && $context['properties']['is_active'] === true;
            });

        $this->listener->handleNoticeTemplateAfterToggleActive($template);
    }

    /**
     * 이커머스 환경설정 저장 훅이 ecommerce_settings.update 로그를 남기고 저장된 설정 카테고리를 쉼표로 이어 설명 인자에 싣는지 확인한다.
     *
     * @scenario domain=settings
     *
     * @effects settings_save_logs_saved_categories
     *
     * @return void 반환값 없음
     */
    public function test_handle_settings_after_save_logs_activity(): void
    {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'ecommerce_settings.update'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.ecommerce_settings_update'
                    && $context['description_params']['categories'] === 'order_settings, review_settings';
            });

        $this->listener->handleSettingsAfterSave(['order_settings', 'review_settings']);
    }

    // ═══════════════════════════════════════════
    // ExtraFeeTemplate 핸들러 테스트
    // ═══════════════════════════════════════════

    /**
     * 추가배송비 템플릿 생성 훅이 extra_fee_template.create 로그를 남기고 이름을 담는지 확인한다.
     *
     * @scenario domain=extra_fee
     *
     * @effects entity_create_logged_with_loggable_and_properties
     *
     * @return void 반환값 없음
     */
    public function test_handle_extra_fee_after_create_logs_activity(): void
    {
        $template = $this->createModelMock(ExtraFeeTemplate::class, 1, 'Installation Fee');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'extra_fee_template.create'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.extra_fee_template_create'
                    && $context['description_params']['template_id'] === 1
                    && isset($context['loggable'])
                    && $context['properties']['name'] === 'Installation Fee';
            });

        $this->listener->handleExtraFeeAfterCreate($template);
    }

    /**
     * 변경 전 스냅샷과 함께 온 추가배송비 템플릿 수정 훅이 extra_fee_template.update 로그에 loggable 과 changes 키를 담는지 확인한다.
     *
     * @scenario domain=extra_fee
     *
     * @effects entity_update_logged_with_snapshot_changes
     *
     * @return void 반환값 없음
     */
    public function test_handle_extra_fee_after_update_logs_activity_with_changes(): void
    {
        $template = $this->createModelMock(ExtraFeeTemplate::class, 2, 'Updated Fee');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'extra_fee_template.update'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.extra_fee_template_update'
                    && $context['description_params']['template_id'] === 2
                    && isset($context['loggable'])
                    && array_key_exists('changes', $context);
            });

        $this->listener->handleExtraFeeAfterUpdate($template, ['id' => 2, 'name' => 'Old Fee']);
    }

    /**
     * 스냅샷이 null 로 온 추가배송비 템플릿 수정 훅은 changes 를 null 로 두고 로그를 남기는지 확인한다.
     *
     * @scenario domain=extra_fee
     *
     * @effects entity_update_without_snapshot_logs_null_changes
     *
     * @return void 반환값 없음
     */
    public function test_handle_extra_fee_after_update_without_snapshot(): void
    {
        $template = $this->createModelMock(ExtraFeeTemplate::class, 99, 'X');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(fn ($action, $context) => $action === 'extra_fee_template.update' && $context['changes'] === null);

        $this->listener->handleExtraFeeAfterUpdate($template, null);
    }

    /**
     * 추가배송비 템플릿 삭제 훅이 ID 만으로 extra_fee_template.delete 로그를 남기는지 확인한다.
     *
     * @scenario domain=extra_fee
     *
     * @effects entity_delete_logged_by_id_without_loggable
     *
     * @return void 반환값 없음
     */
    public function test_handle_extra_fee_after_delete_logs_activity(): void
    {
        $templateId = 9;

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) use ($templateId) {
                return $action === 'extra_fee_template.delete'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.extra_fee_template_delete'
                    && $context['description_params']['template_id'] === $templateId
                    && $context['properties']['template_id'] === $templateId;
            });

        $this->listener->handleExtraFeeAfterDelete($templateId);
    }

    /**
     * 추가배송비 템플릿 활성 전환 훅이 extra_fee_template.toggle_active 로그를 대상 모델과 함께 남기는지 확인한다.
     *
     * @scenario domain=extra_fee
     *
     * @effects entity_status_toggle_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_extra_fee_after_toggle_active_logs_activity(): void
    {
        $template = $this->createModelMock(ExtraFeeTemplate::class, 3, 'Toggle Fee');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'extra_fee_template.toggle_active'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.extra_fee_template_toggle_active'
                    && $context['description_params']['template_id'] === 3
                    && isset($context['loggable']);
            });

        $this->listener->handleExtraFeeAfterToggleActive($template);
    }

    /**
     * 추가배송비 템플릿 일괄 삭제 훅이 ID 마다 한 건씩(count=1) 로그를 남기고 각 건의 loggable_type·loggable_id 를 해당 템플릿으로 채우는지 확인한다.
     *
     * @scenario domain=extra_fee
     *
     * @effects bulk_delete_logs_one_entry_per_item
     *
     * @return void 반환값 없음
     */
    public function test_handle_extra_fee_after_bulk_delete_logs_per_item(): void
    {
        $ids = [10, 11, 12];

        $loggedContexts = [];
        $this->logChannel->shouldReceive('info')
            ->times(3)
            ->withArgs(function ($action, $context) use (&$loggedContexts, $ids) {
                if ($action !== 'extra_fee_template.bulk_delete') {
                    return false;
                }
                $loggedContexts[] = $context;

                return $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.extra_fee_template_bulk_delete'
                    && $context['description_params']['count'] === 1
                    && in_array($context['properties']['extra_fee_template_id'], $ids)
                    && $context['loggable_type'] === ExtraFeeTemplate::class
                    && in_array($context['loggable_id'], $ids);
            });

        $this->listener->handleExtraFeeAfterBulkDelete($ids, 3);

        $this->assertCount(3, $loggedContexts);
    }

    /**
     * 추가배송비 템플릿 일괄 삭제 훅에 스냅샷이 오면 각 건의 로그 properties.snapshot 에 해당 항목의 삭제 전 값을 싣는지 확인한다.
     *
     * @scenario domain=extra_fee
     *
     * @effects bulk_delete_includes_per_item_snapshot
     *
     * @return void 반환값 없음
     */
    public function test_handle_extra_fee_after_bulk_delete_includes_snapshots(): void
    {
        $ids = [10, 11];
        $snapshots = [10 => ['id' => 10, 'name' => 'Fee A'], 11 => ['id' => 11, 'name' => 'Fee B']];

        $loggedContexts = [];
        $this->logChannel->shouldReceive('info')
            ->times(2)
            ->withArgs(function ($action, $context) use (&$loggedContexts) {
                if ($action !== 'extra_fee_template.bulk_delete') {
                    return false;
                }
                $loggedContexts[] = $context;

                return is_array($context['properties']['snapshot']);
            });

        $this->listener->handleExtraFeeAfterBulkDelete($ids, 2, $snapshots);

        $this->assertCount(2, $loggedContexts);
        $this->assertEquals('Fee A', $loggedContexts[0]['properties']['snapshot']['name']);
        $this->assertEquals('Fee B', $loggedContexts[1]['properties']['snapshot']['name']);
    }

    /**
     * 실제 로그 채널로 추가배송비 템플릿 일괄 활성 전환을 기록하면 activity_logs 에 템플릿마다 한 건씩 관리자 로그가 저장되는지 확인한다.
     *
     * @scenario domain=extra_fee
     *
     * @effects bulk_toggle_active_persists_per_item_activity_logs
     *
     * @return void 반환값 없음
     */
    public function test_handle_extra_fee_after_bulk_toggle_active_logs_activity(): void
    {
        // 실제 Log 채널 복원 (DB 기록용)
        Log::swap(new LogManager($this->app));

        $templates = [];
        for ($i = 0; $i < 3; $i++) {
            $templates[] = ExtraFeeTemplate::create([
                'zipcode' => '9990'.$i,
                'fee' => 3000,
                'region' => '테스트지역'.$i,
                'is_active' => true,
            ]);
        }
        $ids = array_map(fn ($t) => $t->id, $templates);

        $this->listener = app(EcommerceAdminActivityLogListener::class);
        $this->listener->handleExtraFeeAfterBulkToggleActive($ids, true, 3);

        $logs = ActivityLog::where('action', 'extra_fee_template.bulk_toggle_active')->get();
        $this->assertCount(3, $logs);

        foreach ($logs as $index => $log) {
            $this->assertEquals(ActivityLogType::Admin, $log->log_type);
            $this->assertEquals(ExtraFeeTemplate::class, $log->loggable_type);
            $this->assertContains($log->loggable_id, $ids);
            $this->assertEquals(
                'sirsoft-ecommerce::activity_log.description.extra_fee_template_bulk_toggle_active',
                $log->description_key
            );
        }
    }

    /**
     * 생성된 모델 컬렉션과 함께 온 추가배송비 일괄 생성 훅이 모델마다 한 건씩 로그를 남기고 각 건에 그 모델 ID 를 싣는지 확인한다.
     *
     * @scenario domain=extra_fee
     *
     * @effects bulk_create_logs_per_created_model
     *
     * @return void 반환값 없음
     */
    public function test_handle_extra_fee_after_bulk_create_creates_per_item_logs(): void
    {
        $items = [['zipcode' => '12345', 'fee' => 3000], ['zipcode' => '67890', 'fee' => 5000]];

        $template1 = Mockery::mock(ExtraFeeTemplate::class)->makePartial();
        $template1->id = 1;
        $template1->shouldReceive('getMorphClass')->andReturn('extra_fee_template');
        $template1->shouldReceive('getKey')->andReturn(1);

        $template2 = Mockery::mock(ExtraFeeTemplate::class)->makePartial();
        $template2->id = 2;
        $template2->shouldReceive('getMorphClass')->andReturn('extra_fee_template');
        $template2->shouldReceive('getKey')->andReturn(2);

        $collection = new Collection([$template1, $template2]);

        $loggedIds = [];
        $this->logChannel->shouldReceive('info')
            ->times(2)
            ->withArgs(function ($action, $context) use (&$loggedIds) {
                if ($action !== 'extra_fee_template.bulk_create') {
                    return false;
                }
                $loggedIds[] = $context['properties']['extra_fee_template_id'];

                return $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.extra_fee_template_bulk_create'
                    && $context['description_params']['count'] === 1;
            });

        $this->listener->handleExtraFeeAfterBulkCreate($items, 2, $collection);

        $this->assertCount(2, $loggedIds);
        $this->assertContains(1, $loggedIds);
        $this->assertContains(2, $loggedIds);
    }

    /**
     * 생성 모델 컬렉션 없이 온 추가배송비 일괄 생성 훅은 건수만 담은 로그 한 건으로 대신하는지 확인한다.
     *
     * @scenario domain=extra_fee
     *
     * @effects bulk_create_without_collection_logs_single_entry
     *
     * @return void 반환값 없음
     */
    public function test_handle_extra_fee_after_bulk_create_fallback_without_collection(): void
    {
        $items = [['zipcode' => '12345', 'fee' => 3000]];

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'extra_fee_template.bulk_create'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.extra_fee_template_bulk_create'
                    && $context['description_params']['count'] === 1
                    && $context['properties']['count'] === 1;
            });

        $this->listener->handleExtraFeeAfterBulkCreate($items, 1);
    }

    // ═══════════════════════════════════════════
    // ShippingPolicy 핸들러 테스트
    // ═══════════════════════════════════════════

    /**
     * 배송정책 일괄 삭제 훅이 ID 마다 한 건씩(count=1) 로그를 남기고 loggable 을 해당 배송정책으로 채우는지 확인한다.
     *
     * @scenario domain=shipping_policy
     *
     * @effects bulk_delete_logs_one_entry_per_item
     *
     * @return void 반환값 없음
     */
    public function test_handle_shipping_policy_after_bulk_delete_logs_per_item(): void
    {
        $ids = [1, 2];

        $loggedContexts = [];
        $this->logChannel->shouldReceive('info')
            ->times(2)
            ->withArgs(function ($action, $context) use (&$loggedContexts, $ids) {
                if ($action !== 'shipping_policy.bulk_delete') {
                    return false;
                }
                $loggedContexts[] = $context;

                return $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.shipping_policy_bulk_delete'
                    && $context['description_params']['count'] === 1
                    && in_array($context['properties']['shipping_policy_id'], $ids)
                    && $context['loggable_type'] === ShippingPolicy::class
                    && in_array($context['loggable_id'], $ids);
            });

        $this->listener->handleShippingPolicyAfterBulkDelete($ids, 2);

        $this->assertCount(2, $loggedContexts);
    }

    /**
     * 배송정책 일괄 삭제 훅에 스냅샷이 오면 각 건의 로그 properties.snapshot 에 해당 정책의 삭제 전 값을 싣는지 확인한다.
     *
     * @scenario domain=shipping_policy
     *
     * @effects bulk_delete_includes_per_item_snapshot
     *
     * @return void 반환값 없음
     */
    public function test_handle_shipping_policy_after_bulk_delete_includes_snapshots(): void
    {
        $ids = [5, 6];
        $snapshots = [5 => ['id' => 5, 'name' => 'Policy A'], 6 => ['id' => 6, 'name' => 'Policy B']];

        $loggedContexts = [];
        $this->logChannel->shouldReceive('info')
            ->times(2)
            ->withArgs(function ($action, $context) use (&$loggedContexts) {
                if ($action !== 'shipping_policy.bulk_delete') {
                    return false;
                }
                $loggedContexts[] = $context;

                return is_array($context['properties']['snapshot']);
            });

        $this->listener->handleShippingPolicyAfterBulkDelete($ids, 2, $snapshots);

        $this->assertCount(2, $loggedContexts);
        $this->assertEquals('Policy A', $loggedContexts[0]['properties']['snapshot']['name']);
        $this->assertEquals('Policy B', $loggedContexts[1]['properties']['snapshot']['name']);
    }

    /**
     * 실제 로그 채널로 배송정책 일괄 활성 전환을 기록하면 activity_logs 에 정책마다 한 건씩 관리자 로그가 저장되는지 확인한다.
     *
     * @scenario domain=shipping_policy
     *
     * @effects bulk_toggle_active_persists_per_item_activity_logs
     *
     * @return void 반환값 없음
     */
    public function test_handle_shipping_policy_after_bulk_toggle_active_logs_activity(): void
    {
        // 실제 Log 채널 복원 (DB 기록용)
        Log::swap(new LogManager($this->app));

        $policies = [];
        for ($i = 0; $i < 3; $i++) {
            $policies[] = ShippingPolicy::create([
                'name' => ['ko' => '배송정책'.$i, 'en' => 'Policy'.$i],
                'is_active' => true,
            ]);
        }
        $ids = array_map(fn ($p) => $p->id, $policies);

        $this->listener = app(EcommerceAdminActivityLogListener::class);
        $this->listener->handleShippingPolicyAfterBulkToggleActive($ids, false, 3);

        $logs = ActivityLog::where('action', 'shipping_policy.bulk_toggle_active')->get();
        $this->assertCount(3, $logs);

        foreach ($logs as $log) {
            $this->assertEquals(ActivityLogType::Admin, $log->log_type);
            $this->assertEquals(ShippingPolicy::class, $log->loggable_type);
            $this->assertContains($log->loggable_id, $ids);
            $this->assertEquals(
                'sirsoft-ecommerce::activity_log.description.shipping_policy_bulk_toggle_active',
                $log->description_key
            );
        }
    }

    // ═══════════════════════════════════════════
    // ShippingCarrier 핸들러 테스트
    // ═══════════════════════════════════════════

    /**
     * 택배사 생성 훅이 shipping_carrier.create 로그를 남기고 이름을 담는지 확인한다.
     *
     * @scenario domain=carrier
     *
     * @effects entity_create_logged_with_loggable_and_properties
     *
     * @return void 반환값 없음
     */
    public function test_handle_carrier_after_create_logs_activity(): void
    {
        $carrier = $this->createModelMock(ShippingCarrier::class, 1, 'CJ대한통운');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'shipping_carrier.create'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.shipping_carrier_create'
                    && $context['description_params']['carrier_id'] === 1
                    && isset($context['loggable'])
                    && $context['properties']['name'] === 'CJ대한통운';
            });

        $this->listener->handleCarrierAfterCreate($carrier, ['name' => 'CJ대한통운']);
    }

    /**
     * 변경 전 스냅샷과 함께 온 택배사 수정 훅이 shipping_carrier.update 로그에 loggable 과 changes 키를 담는지 확인한다.
     *
     * @scenario domain=carrier
     *
     * @effects entity_update_logged_with_snapshot_changes
     *
     * @return void 반환값 없음
     */
    public function test_handle_carrier_after_update_logs_activity_with_changes(): void
    {
        $carrier = $this->createModelMock(ShippingCarrier::class, 2, 'Updated Carrier');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'shipping_carrier.update'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.shipping_carrier_update'
                    && $context['description_params']['carrier_id'] === 2
                    && isset($context['loggable'])
                    && array_key_exists('changes', $context);
            });

        $this->listener->handleCarrierAfterUpdate($carrier, [], ['id' => 2, 'name' => 'Old Carrier']);
    }

    /**
     * 스냅샷 없이 온 택배사 수정 훅은 changes 를 null 로 두고 로그를 남기는지 확인한다.
     *
     * @scenario domain=carrier
     *
     * @effects entity_update_without_snapshot_logs_null_changes
     *
     * @return void 반환값 없음
     */
    public function test_handle_carrier_after_update_without_snapshot(): void
    {
        $carrier = $this->createModelMock(ShippingCarrier::class, 99, 'X');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(fn ($action, $context) => $action === 'shipping_carrier.update' && $context['changes'] === null);

        $this->listener->handleCarrierAfterUpdate($carrier, []);
    }

    /**
     * 택배사 삭제 훅이 ID 만으로 shipping_carrier.delete 로그를 남기는지 확인한다.
     *
     * @scenario domain=carrier
     *
     * @effects entity_delete_logged_by_id_without_loggable
     *
     * @return void 반환값 없음
     */
    public function test_handle_carrier_after_delete_logs_activity(): void
    {
        $carrierId = 5;

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) use ($carrierId) {
                return $action === 'shipping_carrier.delete'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.shipping_carrier_delete'
                    && $context['description_params']['carrier_id'] === $carrierId
                    && $context['properties']['carrier_id'] === $carrierId;
            });

        $this->listener->handleCarrierAfterDelete($carrierId);
    }

    /**
     * 택배사 상태 전환 훅이 shipping_carrier.toggle_status 로그를 대상 모델과 함께 남기는지 확인한다.
     *
     * @scenario domain=carrier
     *
     * @effects entity_status_toggle_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_carrier_after_toggle_status_logs_activity(): void
    {
        $carrier = $this->createModelMock(ShippingCarrier::class, 3, 'Toggle Carrier');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'shipping_carrier.toggle_status'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.shipping_carrier_toggle_status'
                    && $context['description_params']['carrier_id'] === 3
                    && isset($context['loggable']);
            });

        $this->listener->handleCarrierAfterToggleStatus($carrier);
    }

    // ═══════════════════════════════════════════
    // ProductImage 핸들러 테스트
    // ═══════════════════════════════════════════

    /**
     * 상품 이미지 업로드 훅이 product_image.upload 로그를 이미지 모델과 함께 남기는지 확인한다.
     *
     * @scenario domain=image
     *
     * @effects entity_create_logged_with_loggable_and_properties
     *
     * @return void 반환값 없음
     */
    public function test_handle_image_after_upload_logs_activity(): void
    {
        $image = $this->createModelMock(ProductImage::class, 1);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'product_image.upload'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.product_image_upload'
                    && $context['description_params']['image_id'] === 1
                    && isset($context['loggable']);
            });

        $this->listener->handleImageAfterUpload($image);
    }

    /**
     * 상품 이미지 삭제 훅이 삭제된 이미지 모델을 loggable 로 실어 product_image.delete 로그를 남기는지 확인한다.
     *
     * @scenario domain=image
     *
     * @effects entity_delete_logged_by_id_without_loggable
     *
     * @return void 반환값 없음
     */
    public function test_handle_image_after_delete_logs_activity(): void
    {
        $image = $this->createModelMock(ProductImage::class, 2);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'product_image.delete'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.product_image_delete'
                    && $context['description_params']['image_id'] === 2
                    && isset($context['loggable']);
            });

        $this->listener->handleImageAfterDelete($image);
    }

    /**
     * 상품 이미지 순서 변경 훅이 특정 모델 없이 정렬 항목 수만 담은 product_image.reorder 로그를 남기는지 확인한다.
     *
     * @scenario domain=image
     *
     * @effects image_reorder_logs_count_without_loggable
     *
     * @return void 반환값 없음
     */
    public function test_handle_image_after_reorder_logs_activity(): void
    {
        $orders = [['id' => 1, 'sort' => 0], ['id' => 2, 'sort' => 1]];

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'product_image.reorder'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.product_image_reorder'
                    && $context['properties']['count'] === 2
                    && ! isset($context['loggable']);
            });

        $this->listener->handleImageAfterReorder($orders);
    }

    /**
     * 빈 정렬 목록으로 온 이미지 순서 변경 훅도 건수 0 으로 로그를 남기는지 확인한다.
     *
     * @scenario domain=image
     *
     * @effects image_reorder_logs_count_without_loggable
     *
     * @return void 반환값 없음
     */
    public function test_handle_image_after_reorder_with_empty_orders(): void
    {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'product_image.reorder'
                    && $context['properties']['count'] === 0;
            });

        $this->listener->handleImageAfterReorder([]);
    }

    // ═══════════════════════════════════════════
    // ProductOption 핸들러 테스트
    // ═══════════════════════════════════════════

    /**
     * 옵션 일괄 가격 변경 훅이 옵션마다 한 건씩 ProductOption 을 loggable 로 실은 로그를 넘겨받은 ID 순서대로 남기는지 확인한다.
     *
     * @scenario domain=option
     *
     * @effects option_bulk_change_logs_per_option
     *
     * @return void 반환값 없음
     */
    public function test_handle_option_after_bulk_price_update_logs_per_option(): void
    {
        $product = Product::factory()->create(['has_options' => true]);
        $option1 = ProductOption::factory()->create(['product_id' => $product->id, 'price_adjustment' => 1000]);
        $option2 = ProductOption::factory()->create(['product_id' => $product->id, 'price_adjustment' => 2000]);

        $loggedActions = [];
        $this->logChannel->shouldReceive('info')
            ->twice()
            ->withArgs(function ($action, $context) use (&$loggedActions) {
                $loggedActions[] = [
                    'action' => $action,
                    'option_id' => $context['description_params']['option_id'] ?? null,
                    'loggable' => $context['loggable'] ?? null,
                ];

                return $action === 'product_option.bulk_price_update'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.product_option_bulk_price_update'
                    && isset($context['loggable'])
                    && $context['loggable'] instanceof ProductOption;
            });

        $this->listener->handleOptionAfterBulkPriceUpdate([$option1->id, $option2->id], 2);

        $this->assertCount(2, $loggedActions);
        $this->assertEquals($option1->id, $loggedActions[0]['option_id']);
        $this->assertEquals($option2->id, $loggedActions[1]['option_id']);
    }

    /**
     * 옵션 일괄 재고 변경 훅이 옵션마다 한 건씩 ProductOption 을 loggable 로 실은 로그를 남기는지 확인한다.
     *
     * @scenario domain=option
     *
     * @effects option_bulk_change_logs_per_option
     *
     * @return void 반환값 없음
     */
    public function test_handle_option_after_bulk_stock_update_logs_per_option(): void
    {
        $product = Product::factory()->create(['has_options' => true]);
        $option1 = ProductOption::factory()->create(['product_id' => $product->id, 'stock_quantity' => 10]);
        $option2 = ProductOption::factory()->create(['product_id' => $product->id, 'stock_quantity' => 20]);

        $loggedActions = [];
        $this->logChannel->shouldReceive('info')
            ->twice()
            ->withArgs(function ($action, $context) use (&$loggedActions) {
                $loggedActions[] = [
                    'action' => $action,
                    'option_id' => $context['description_params']['option_id'] ?? null,
                ];

                return $action === 'product_option.bulk_stock_update'
                    && isset($context['loggable'])
                    && $context['loggable'] instanceof ProductOption;
            });

        $this->listener->handleOptionAfterBulkStockUpdate([$option1->id, $option2->id], 2);

        $this->assertCount(2, $loggedActions);
    }

    /**
     * 옵션 일괄 가격 변경 훅에 변경 전 스냅샷이 오면 로그의 changes 가 null 이 아닌 변경 내역으로 채워지는지 확인한다.
     *
     * @scenario domain=option
     *
     * @effects option_bulk_price_update_records_changes_from_snapshot
     *
     * @return void 반환값 없음
     */
    public function test_handle_option_after_bulk_price_update_logs_with_changes_when_snapshots_provided(): void
    {
        $product = Product::factory()->create(['has_options' => true]);
        $option = ProductOption::factory()->create(['product_id' => $product->id, 'price_adjustment' => 2000]);

        $snapshot = $option->toArray();
        $snapshot['price_adjustment'] = 1000; // 변경 전 값

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'product_option.bulk_price_update'
                    && $context['changes'] !== null;
            });

        $this->listener->handleOptionAfterBulkPriceUpdate([$option->id], 1, [$option->id => $snapshot]);
    }

    /**
     * 상품-옵션 복합 ID 로 온 옵션 일괄 수정 훅이 옵션마다 한 건씩 product_option.bulk_update 로그를 요청 순서대로 남기는지 확인한다.
     *
     * @scenario domain=option
     *
     * @effects option_bulk_change_logs_per_option
     *
     * @return void 반환값 없음
     */
    public function test_handle_option_after_bulk_update_logs_per_option(): void
    {
        $product = Product::factory()->create(['has_options' => true]);
        $option1 = ProductOption::factory()->create(['product_id' => $product->id]);
        $option2 = ProductOption::factory()->create(['product_id' => $product->id]);
        $option3 = ProductOption::factory()->create(['product_id' => $product->id]);

        $result = ['options_updated' => 3];
        $data = ['ids' => [$product->id.'-'.$option1->id, $product->id.'-'.$option2->id, $product->id.'-'.$option3->id]];

        $loggedActions = [];
        $this->logChannel->shouldReceive('info')
            ->times(3)
            ->withArgs(function ($action, $context) use (&$loggedActions) {
                $loggedActions[] = $context['description_params']['option_id'] ?? null;

                return $action === 'product_option.bulk_update'
                    && isset($context['loggable'])
                    && $context['loggable'] instanceof ProductOption;
            });

        $this->listener->handleOptionAfterBulkUpdate($result, $data);

        $this->assertCount(3, $loggedActions);
        $this->assertEquals($option1->id, $loggedActions[0]);
        $this->assertEquals($option2->id, $loggedActions[1]);
        $this->assertEquals($option3->id, $loggedActions[2]);
    }

    /**
     * 수정된 옵션 수가 0 인 옵션 일괄 수정 훅은 로그를 전혀 남기지 않는지 확인한다.
     *
     * @scenario domain=option
     *
     * @effects option_bulk_update_skips_when_nothing_updated
     *
     * @return void 반환값 없음
     */
    public function test_handle_option_after_bulk_update_skips_when_no_updates(): void
    {
        $result = ['options_updated' => 0];
        $data = ['ids' => ['10-100']];

        $this->logChannel->shouldNotReceive('info');

        $this->listener->handleOptionAfterBulkUpdate($result, $data);
    }

    // ═══════════════════════════════════════════
    // ProductReview 핸들러 테스트
    // ═══════════════════════════════════════════

    /**
     * 상품 리뷰 생성 훅이 product_review.create 로그를 리뷰 모델과 함께 남기는지 확인한다.
     *
     * @scenario domain=review
     *
     * @effects entity_create_logged_with_loggable_and_properties
     *
     * @return void 반환값 없음
     */
    public function test_handle_review_after_create_logs_activity(): void
    {
        $review = $this->createModelMock(ProductReview::class, 1);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'product_review.create'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.product_review_create'
                    && $context['description_params']['review_id'] === 1
                    && isset($context['loggable']);
            });

        $this->listener->handleReviewAfterCreate($review);
    }

    /**
     * 상품 리뷰 삭제 훅이 삭제된 리뷰 모델을 loggable 로 실어 product_review.delete 로그를 남기는지 확인한다.
     *
     * @scenario domain=review
     *
     * @effects entity_delete_logged_by_id_without_loggable
     *
     * @return void 반환값 없음
     */
    public function test_handle_review_after_delete_logs_activity(): void
    {
        $review = $this->createModelMock(ProductReview::class, 2);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'product_review.delete'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.product_review_delete'
                    && $context['description_params']['review_id'] === 2
                    && isset($context['loggable']);
            });

        $this->listener->handleReviewAfterDelete($review);
    }

    /**
     * 리뷰 일괄 삭제 훅이 ID 마다 한 건씩(count=1) review_bulk_delete 로그를 남기고 loggable 을 해당 리뷰로 채우는지 확인한다.
     *
     * @scenario domain=review
     *
     * @effects bulk_delete_logs_one_entry_per_item
     *
     * @return void 반환값 없음
     */
    public function test_handle_review_after_bulk_delete_logs_per_item(): void
    {
        $ids = [10, 11, 12, 13];

        $loggedContexts = [];
        $this->logChannel->shouldReceive('info')
            ->times(4)
            ->withArgs(function ($action, $context) use (&$loggedContexts, $ids) {
                if ($action !== 'product_review.bulk_delete') {
                    return false;
                }
                $loggedContexts[] = $context;

                return $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.review_bulk_delete'
                    && $context['description_params']['count'] === 1
                    && in_array($context['properties']['review_id'], $ids)
                    && $context['loggable_type'] === ProductReview::class
                    && in_array($context['loggable_id'], $ids);
            });

        $this->listener->handleReviewAfterBulkDelete($ids);

        $this->assertCount(4, $loggedContexts);
    }

    /**
     * 리뷰 일괄 삭제 훅에 스냅샷이 오면 각 건의 로그 properties.snapshot 에 해당 리뷰의 삭제 전 내용을 싣는지 확인한다.
     *
     * @scenario domain=review
     *
     * @effects bulk_delete_includes_per_item_snapshot
     *
     * @return void 반환값 없음
     */
    public function test_handle_review_after_bulk_delete_includes_snapshots(): void
    {
        $ids = [10, 11];
        $snapshots = [10 => ['id' => 10, 'content' => 'Great'], 11 => ['id' => 11, 'content' => 'Good']];

        $loggedContexts = [];
        $this->logChannel->shouldReceive('info')
            ->times(2)
            ->withArgs(function ($action, $context) use (&$loggedContexts) {
                if ($action !== 'product_review.bulk_delete') {
                    return false;
                }
                $loggedContexts[] = $context;

                return is_array($context['properties']['snapshot']);
            });

        $this->listener->handleReviewAfterBulkDelete($ids, $snapshots);

        $this->assertCount(2, $loggedContexts);
        $this->assertEquals('Great', $loggedContexts[0]['properties']['snapshot']['content']);
        $this->assertEquals('Good', $loggedContexts[1]['properties']['snapshot']['content']);
    }

    // ═══════════════════════════════════════════
    // 에러 핸들링 테스트
    // ═══════════════════════════════════════════

    /**
     * 활동 로그 채널 기록이 예외를 던져도 리스너가 삼키고, 실패한 액션과 오류 메시지를 담아 Log::error 로 한 번 남기는지 확인한다.
     *
     * @scenario domain=error_handling
     *
     * @effects activity_log_failure_reported_as_error
     *
     * @return void 반환값 없음
     */
    public function test_log_activity_catches_exception_and_logs_error(): void
    {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->andThrow(new \Exception('Connection reset'));

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'Failed to record activity log'
                    && $context['action'] === 'brand.delete'
                    && $context['error'] === 'Connection reset';
            });

        $this->listener->handleBrandAfterDelete(1);
    }

    // ═══════════════════════════════════════════
    // handle 기본 핸들러 테스트
    // ═══════════════════════════════════════════

    /**
     * 구독 계약상 두는 기본 handle() 은 어떤 인수가 와도 예외 없이 아무 일도 하지 않는지 확인한다.
     *
     * @scenario domain=default_handler
     *
     * @effects default_handle_is_noop
     *
     * @return void 반환값 없음
     */
    public function test_handle_does_nothing(): void
    {
        $this->listener->handle('arg1', 'arg2');
        $this->assertTrue(true);
    }

    // ═══════════════════════════════════════════
    // 헬퍼 메서드
    // ═══════════════════════════════════════════

    /**
     * 모델 Mock 생성 (범용)
     *
     * name 이 AsUnicodeJson cast 인 모델은 다국어 배열로, 그 밖에는 스칼라로 채우고
     * getKey·getMorphClass 를 고정해 리스너가 loggable 로 쓸 수 있게 한다.
     *
     * @param  string  $class  모델 클래스명
     * @param  int  $id  모델 ID
     * @param  string|null  $name  name 속성 (있는 경우)
     * @return mixed 지정한 ID·이름을 가진 부분 목 모델
     */
    private function createModelMock(string $class, int $id, ?string $name = null)
    {
        $model = Mockery::mock($class)->makePartial();

        // name cast 유형 확인: AsUnicodeJson 이면 다국어 배열로 저장, 'array' 는 스칼라도 허용
        $casts = $model->getCasts();
        $nameCast = $casts['name'] ?? null;
        $useMultilingual = $nameCast !== null && str_contains((string) $nameCast, 'AsUnicodeJson');

        if ($name === null) {
            $model->setRawAttributes(['id' => $id, 'name' => null], false);
        } elseif ($useMultilingual) {
            $nameArray = ['ko' => $name, 'en' => $name];
            $model->setRawAttributes([
                'id' => $id,
                'name' => json_encode($nameArray, JSON_UNESCAPED_UNICODE),
            ], false);
        } else {
            // 'array' cast 또는 cast 없음 — 기존 동작 유지
            $model->forceFill(['id' => $id, 'name' => $name]);
        }

        $model->shouldReceive('getKey')->andReturn($id);
        $model->shouldReceive('getMorphClass')->andReturn(class_basename($class));

        return $model;
    }
}
