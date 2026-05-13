<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use App\Enums\ActivityLogType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mockery;
use Modules\Sirsoft\Ecommerce\Listeners\EcommerceAdminActivityLogListener;
use Modules\Sirsoft\Ecommerce\Models\Brand;
use Modules\Sirsoft\Ecommerce\Models\ExtraFeeTemplate;
use Modules\Sirsoft\Ecommerce\Models\ProductCommonInfo;
use Modules\Sirsoft\Ecommerce\Models\ProductImage;
use Modules\Sirsoft\Ecommerce\Models\ProductLabel;
use Modules\Sirsoft\Ecommerce\Models\ProductNoticeTemplate;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use App\Models\ActivityLog;
use Illuminate\Log\LogManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

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

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance('request', Request::create('/api/admin/sirsoft-ecommerce/test'));
        $this->listener = app(EcommerceAdminActivityLogListener::class);
        $this->logChannel = Mockery::mock(\Psr\Log\LoggerInterface::class);
        Log::shouldReceive('channel')
            ->with('activity')
            ->andReturn($this->logChannel);
        Log::shouldReceive('error')->byDefault();
    }

    // ═══════════════════════════════════════════
    // getSubscribedHooks
    // ═══════════════════════════════════════════

    public function test_getSubscribedHooks_returns_all_hooks(): void
    {
        $hooks = EcommerceAdminActivityLogListener::getSubscribedHooks();

        // Brand(4) + Label(4) + CommonInfo(3) + NoticeTemplate(4) + ExtraFee(6) + ShippingPolicy(2) + Carrier(4) + Image(3) + Option(3) + Review(4) = 37
        $this->assertCount(37, $hooks);

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

    public function test_handleBrandAfterCreate_logs_activity(): void
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

    public function test_handleBrandAfterUpdate_logs_activity_with_changes(): void
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

    public function test_handleBrandAfterUpdate_without_snapshot(): void
    {
        $brand = $this->createModelMock(Brand::class, 99, 'No Snap');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(fn ($action, $context) => $action === 'brand.update' && $context['changes'] === null);

        $this->listener->handleBrandAfterUpdate($brand, []);
    }

    public function test_handleBrandAfterDelete_logs_activity(): void
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

    public function test_handleBrandAfterToggleStatus_logs_activity(): void
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

    public function test_handleLabelAfterCreate_logs_activity(): void
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

    public function test_handleLabelAfterUpdate_logs_activity_with_changes(): void
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

    public function test_handleLabelAfterUpdate_without_snapshot(): void
    {
        $label = $this->createModelMock(ProductLabel::class, 99, 'X');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(fn ($action, $context) => $action === 'label.update' && $context['changes'] === null);

        $this->listener->handleLabelAfterUpdate($label, []);
    }

    public function test_handleLabelAfterDelete_logs_activity(): void
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

    public function test_handleLabelAfterToggleStatus_logs_activity(): void
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

    public function test_handleCommonInfoAfterCreate_logs_activity(): void
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

    public function test_handleCommonInfoAfterUpdate_logs_activity_with_changes(): void
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

    public function test_handleCommonInfoAfterUpdate_without_snapshot(): void
    {
        $info = $this->createModelMock(ProductCommonInfo::class, 99, 'X');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(fn ($action, $context) => $action === 'product_common_info.update' && $context['changes'] === null);

        $this->listener->handleCommonInfoAfterUpdate($info, []);
    }

    public function test_handleCommonInfoAfterDelete_logs_activity(): void
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

    public function test_handleNoticeTemplateAfterCreate_logs_activity(): void
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

    public function test_handleNoticeTemplateAfterUpdate_logs_activity_with_changes(): void
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

    public function test_handleNoticeTemplateAfterUpdate_without_snapshot(): void
    {
        $template = $this->createModelMock(ProductNoticeTemplate::class, 99, 'X');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(fn ($action, $context) => $action === 'product_notice_template.update' && $context['changes'] === null);

        $this->listener->handleNoticeTemplateAfterUpdate($template, []);
    }

    public function test_handleNoticeTemplateAfterDelete_logs_activity(): void
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

    public function test_handleNoticeTemplateAfterCopy_logs_activity(): void
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

    // ═══════════════════════════════════════════
    // ExtraFeeTemplate 핸들러 테스트
    // ═══════════════════════════════════════════

    public function test_handleExtraFeeAfterCreate_logs_activity(): void
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

    public function test_handleExtraFeeAfterUpdate_logs_activity_with_changes(): void
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

    public function test_handleExtraFeeAfterUpdate_without_snapshot(): void
    {
        $template = $this->createModelMock(ExtraFeeTemplate::class, 99, 'X');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(fn ($action, $context) => $action === 'extra_fee_template.update' && $context['changes'] === null);

        $this->listener->handleExtraFeeAfterUpdate($template, null);
    }

    public function test_handleExtraFeeAfterDelete_logs_activity(): void
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

    public function test_handleExtraFeeAfterToggleActive_logs_activity(): void
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

    public function test_handleExtraFeeAfterBulkDelete_logs_per_item(): void
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

    public function test_handleExtraFeeAfterBulkDelete_includes_snapshots(): void
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

    public function test_handleExtraFeeAfterBulkToggleActive_logs_activity(): void
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

    public function test_handleExtraFeeAfterBulkCreate_creates_per_item_logs(): void
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

        $collection = new \Illuminate\Database\Eloquent\Collection([$template1, $template2]);

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

    public function test_handleExtraFeeAfterBulkCreate_fallback_without_collection(): void
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

    public function test_handleShippingPolicyAfterBulkDelete_logs_per_item(): void
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

    public function test_handleShippingPolicyAfterBulkDelete_includes_snapshots(): void
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

    public function test_handleShippingPolicyAfterBulkToggleActive_logs_activity(): void
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

    public function test_handleCarrierAfterCreate_logs_activity(): void
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

    public function test_handleCarrierAfterUpdate_logs_activity_with_changes(): void
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

    public function test_handleCarrierAfterUpdate_without_snapshot(): void
    {
        $carrier = $this->createModelMock(ShippingCarrier::class, 99, 'X');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(fn ($action, $context) => $action === 'shipping_carrier.update' && $context['changes'] === null);

        $this->listener->handleCarrierAfterUpdate($carrier, []);
    }

    public function test_handleCarrierAfterDelete_logs_activity(): void
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

    public function test_handleCarrierAfterToggleStatus_logs_activity(): void
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

    public function test_handleImageAfterUpload_logs_activity(): void
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

    public function test_handleImageAfterDelete_logs_activity(): void
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

    public function test_handleImageAfterReorder_logs_activity(): void
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

    public function test_handleImageAfterReorder_with_empty_orders(): void
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

    public function test_handleOptionAfterBulkPriceUpdate_logs_per_option(): void
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

    public function test_handleOptionAfterBulkStockUpdate_logs_per_option(): void
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

    public function test_handleOptionAfterBulkPriceUpdate_logs_with_changes_when_snapshots_provided(): void
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

    public function test_handleOptionAfterBulkUpdate_logs_per_option(): void
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

    public function test_handleOptionAfterBulkUpdate_skips_when_no_updates(): void
    {
        $result = ['options_updated' => 0];
        $data = ['ids' => ['10-100']];

        $this->logChannel->shouldNotReceive('info');

        $this->listener->handleOptionAfterBulkUpdate($result, $data);
    }

    // ═══════════════════════════════════════════
    // ProductReview 핸들러 테스트
    // ═══════════════════════════════════════════

    public function test_handleReviewAfterCreate_logs_activity(): void
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

    public function test_handleReviewAfterDelete_logs_activity(): void
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

    public function test_handleReviewAfterBulkDelete_logs_per_item(): void
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

    public function test_handleReviewAfterBulkDelete_includes_snapshots(): void
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

    public function test_logActivity_catches_exception_and_logs_error(): void
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
     * @param string $class 모델 클래스명
     * @param int $id 모델 ID
     * @param string|null $name name 속성 (있는 경우)
     * @return mixed
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
