<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\ActivityLog\ChangeDetector;
use App\ActivityLog\Traits\ResolvesActivityLogType;
use App\Contracts\Extension\HookListenerInterface;
use Modules\Sirsoft\Ecommerce\Models\Brand;
use Modules\Sirsoft\Ecommerce\Models\ExtraFeeTemplate;
use Modules\Sirsoft\Ecommerce\Models\ProductCommonInfo;
use Modules\Sirsoft\Ecommerce\Models\ProductImage;
use Modules\Sirsoft\Ecommerce\Models\ProductLabel;
use Modules\Sirsoft\Ecommerce\Models\ProductNoticeTemplate;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;

/**
 * 이커머스 관리자 엔티티 통합 활동 로그 리스너
 *
 * 브랜드, 상품 라벨, 상품 공통정보, 상품 고시정보 템플릿, 추가비용 템플릿,
 * 배송사, 상품 이미지, 상품 옵션, 상품 리뷰 등 소규모 관리자 엔티티의
 * 활동 로그를 통합하여 기록합니다.
 *
 * Log::channel('activity')를 통해 Monolog 기반으로 DB에 저장됩니다.
 */
class EcommerceAdminActivityLogListener implements HookListenerInterface
{
    use ResolvesActivityLogType;

    /**
     * 구독할 훅과 메서드 매핑 반환
     *
     * @return array 훅 매핑 배열
     */
    public static function getSubscribedHooks(): array
    {
        return [
            // ─── Brand ───
            'sirsoft-ecommerce.brand.after_create' => ['method' => 'handleBrandAfterCreate', 'priority' => 20],
            'sirsoft-ecommerce.brand.after_update' => ['method' => 'handleBrandAfterUpdate', 'priority' => 20],
            'sirsoft-ecommerce.brand.after_delete' => ['method' => 'handleBrandAfterDelete', 'priority' => 20],
            'sirsoft-ecommerce.brand.after_toggle_status' => ['method' => 'handleBrandAfterToggleStatus', 'priority' => 20],

            // ─── ProductLabel ───
            'sirsoft-ecommerce.label.after_create' => ['method' => 'handleLabelAfterCreate', 'priority' => 20],
            'sirsoft-ecommerce.label.after_update' => ['method' => 'handleLabelAfterUpdate', 'priority' => 20],
            'sirsoft-ecommerce.label.after_delete' => ['method' => 'handleLabelAfterDelete', 'priority' => 20],
            'sirsoft-ecommerce.label.after_toggle_status' => ['method' => 'handleLabelAfterToggleStatus', 'priority' => 20],

            // ─── ProductCommonInfo ───
            'sirsoft-ecommerce.product-common-info.after_create' => ['method' => 'handleCommonInfoAfterCreate', 'priority' => 20],
            'sirsoft-ecommerce.product-common-info.after_update' => ['method' => 'handleCommonInfoAfterUpdate', 'priority' => 20],
            'sirsoft-ecommerce.product-common-info.after_delete' => ['method' => 'handleCommonInfoAfterDelete', 'priority' => 20],

            // ─── ProductNoticeTemplate ───
            'sirsoft-ecommerce.product-notice-template.after_create' => ['method' => 'handleNoticeTemplateAfterCreate', 'priority' => 20],
            'sirsoft-ecommerce.product-notice-template.after_update' => ['method' => 'handleNoticeTemplateAfterUpdate', 'priority' => 20],
            'sirsoft-ecommerce.product-notice-template.after_delete' => ['method' => 'handleNoticeTemplateAfterDelete', 'priority' => 20],
            'sirsoft-ecommerce.product-notice-template.after_copy' => ['method' => 'handleNoticeTemplateAfterCopy', 'priority' => 20],

            // ─── ExtraFeeTemplate ───
            'sirsoft-ecommerce.extra_fee_template.after_create' => ['method' => 'handleExtraFeeAfterCreate', 'priority' => 20],
            'sirsoft-ecommerce.extra_fee_template.after_update' => ['method' => 'handleExtraFeeAfterUpdate', 'priority' => 20],
            'sirsoft-ecommerce.extra_fee_template.after_delete' => ['method' => 'handleExtraFeeAfterDelete', 'priority' => 20],
            'sirsoft-ecommerce.extra_fee_template.after_toggle_active' => ['method' => 'handleExtraFeeAfterToggleActive', 'priority' => 20],
            'sirsoft-ecommerce.extra_fee_template.after_bulk_delete' => ['method' => 'handleExtraFeeAfterBulkDelete', 'priority' => 20],
            'sirsoft-ecommerce.extra_fee_template.after_bulk_toggle_active' => ['method' => 'handleExtraFeeAfterBulkToggleActive', 'priority' => 20],
            'sirsoft-ecommerce.extra_fee_template.after_bulk_create' => ['method' => 'handleExtraFeeAfterBulkCreate', 'priority' => 20],

            // ─── ShippingPolicy ───
            'sirsoft-ecommerce.shipping_policy.after_bulk_delete' => ['method' => 'handleShippingPolicyAfterBulkDelete', 'priority' => 20],
            'sirsoft-ecommerce.shipping_policy.after_bulk_toggle_active' => ['method' => 'handleShippingPolicyAfterBulkToggleActive', 'priority' => 20],

            // ─── ShippingCarrier ───
            'sirsoft-ecommerce.shipping_carrier.after_create' => ['method' => 'handleCarrierAfterCreate', 'priority' => 20],
            'sirsoft-ecommerce.shipping_carrier.after_update' => ['method' => 'handleCarrierAfterUpdate', 'priority' => 20],
            'sirsoft-ecommerce.shipping_carrier.after_delete' => ['method' => 'handleCarrierAfterDelete', 'priority' => 20],
            'sirsoft-ecommerce.shipping_carrier.after_toggle_status' => ['method' => 'handleCarrierAfterToggleStatus', 'priority' => 20],

            // ─── ProductImage ───
            'sirsoft-ecommerce.product-image.after_upload' => ['method' => 'handleImageAfterUpload', 'priority' => 20],
            'sirsoft-ecommerce.product-image.after_delete' => ['method' => 'handleImageAfterDelete', 'priority' => 20],
            'sirsoft-ecommerce.product-image.after_reorder' => ['method' => 'handleImageAfterReorder', 'priority' => 20],

            // ─── ProductOption ───
            'sirsoft-ecommerce.product_option.after_bulk_price_update' => ['method' => 'handleOptionAfterBulkPriceUpdate', 'priority' => 20],
            'sirsoft-ecommerce.product_option.after_bulk_stock_update' => ['method' => 'handleOptionAfterBulkStockUpdate', 'priority' => 20],
            'sirsoft-ecommerce.option.after_bulk_update' => ['method' => 'handleOptionAfterBulkUpdate', 'priority' => 20],

            // ─── ProductReview ───
            'sirsoft-ecommerce.product-review.after_create' => ['method' => 'handleReviewAfterCreate', 'priority' => 20],
            'sirsoft-ecommerce.product-review.after_delete' => ['method' => 'handleReviewAfterDelete', 'priority' => 20],
            'sirsoft-ecommerce.product-review.after_bulk_delete' => ['method' => 'handleReviewAfterBulkDelete', 'priority' => 20],
        ];
    }

    /**
     * 훅 이벤트 처리 (기본 핸들러)
     *
     * @param mixed ...$args 훅에서 전달된 인수들
     * @return void
     */
    public function handle(...$args): void
    {
        // 개별 메서드에서 처리
    }

    // ═══════════════════════════════════════════
    // Brand 핸들러
    // ═══════════════════════════════════════════

    /**
     * 브랜드 생성 후 로그 기록
     *
     * @param Brand $brand 생성된 브랜드
     * @param array $data 생성 데이터
     * @return void
     */
    public function handleBrandAfterCreate(Brand $brand, array $data): void
    {
        $this->logActivity('brand.create', [

            'loggable' => $brand,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.brand_create',
            'description_params' => ['brand_id' => $brand->id],
            'properties' => ['name' => $brand->name ?? null],
        ]);
    }

    /**
     * 브랜드 수정 후 로그 기록
     *
     * @param Brand $brand 수정된 브랜드
     * @param array $data 수정 데이터
     * @param array|null $snapshot 수정 전 스냅샷 (Service에서 전달)
     * @return void
     */
    public function handleBrandAfterUpdate(Brand $brand, array $data, ?array $snapshot = null): void
    {
        $changes = ChangeDetector::detect($brand, $snapshot);

        $this->logActivity('brand.update', [

            'loggable' => $brand,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.brand_update',
            'description_params' => ['brand_id' => $brand->id],
            'changes' => $changes,
        ]);
    }

    /**
     * 브랜드 삭제 후 로그 기록
     *
     * @param int $brandId 삭제된 브랜드 ID
     * @return void
     */
    public function handleBrandAfterDelete(int $brandId): void
    {
        $this->logActivity('brand.delete', [

            'description_key' => 'sirsoft-ecommerce::activity_log.description.brand_delete',
            'description_params' => ['brand_id' => $brandId],
            'properties' => ['brand_id' => $brandId],
        ]);
    }

    /**
     * 브랜드 상태 전환 후 로그 기록
     *
     * @param Brand $brand 브랜드
     * @return void
     */
    public function handleBrandAfterToggleStatus(Brand $brand): void
    {
        $this->logActivity('brand.toggle_status', [

            'loggable' => $brand,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.brand_toggle_status',
            'description_params' => ['brand_id' => $brand->id],
        ]);
    }

    // ═══════════════════════════════════════════
    // ProductLabel 핸들러
    // ═══════════════════════════════════════════

    /**
     * 상품 라벨 생성 후 로그 기록
     *
     * @param ProductLabel $label 생성된 라벨
     * @param array $data 생성 데이터
     * @return void
     */
    public function handleLabelAfterCreate(ProductLabel $label, array $data): void
    {
        $this->logActivity('label.create', [

            'loggable' => $label,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.label_create',
            'description_params' => ['label_id' => $label->id],
            'properties' => ['name' => $label->name ?? null],
        ]);
    }

    /**
     * 상품 라벨 수정 후 로그 기록
     *
     * @param ProductLabel $label 수정된 라벨
     * @param array $data 수정 데이터
     * @param array|null $snapshot 수정 전 스냅샷 (Service에서 전달)
     * @return void
     */
    public function handleLabelAfterUpdate(ProductLabel $label, array $data, ?array $snapshot = null): void
    {
        $changes = ChangeDetector::detect($label, $snapshot);

        $this->logActivity('label.update', [

            'loggable' => $label,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.label_update',
            'description_params' => ['label_id' => $label->id],
            'changes' => $changes,
        ]);
    }

    /**
     * 상품 라벨 삭제 후 로그 기록
     *
     * @param int $labelId 삭제된 라벨 ID
     * @return void
     */
    public function handleLabelAfterDelete(int $labelId): void
    {
        $this->logActivity('label.delete', [

            'description_key' => 'sirsoft-ecommerce::activity_log.description.label_delete',
            'description_params' => ['label_id' => $labelId],
            'properties' => ['label_id' => $labelId],
        ]);
    }

    /**
     * 상품 라벨 상태 전환 후 로그 기록
     *
     * @param ProductLabel $label 라벨
     * @return void
     */
    public function handleLabelAfterToggleStatus(ProductLabel $label): void
    {
        $this->logActivity('label.toggle_status', [

            'loggable' => $label,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.label_toggle_status',
            'description_params' => ['label_id' => $label->id],
        ]);
    }

    // ═══════════════════════════════════════════
    // ProductCommonInfo 핸들러
    // ═══════════════════════════════════════════

    /**
     * 상품 공통정보 생성 후 로그 기록
     *
     * @param ProductCommonInfo $commonInfo 생성된 공통정보
     * @param array $data 생성 데이터
     * @return void
     */
    public function handleCommonInfoAfterCreate(ProductCommonInfo $commonInfo, array $data): void
    {
        $this->logActivity('product_common_info.create', [

            'loggable' => $commonInfo,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.product_common_info_create',
            'description_params' => ['common_info_id' => $commonInfo->id],
            'properties' => ['name' => $commonInfo->name ?? null],
        ]);
    }

    /**
     * 상품 공통정보 수정 후 로그 기록
     *
     * @param ProductCommonInfo $commonInfo 수정된 공통정보
     * @param array $data 수정 데이터
     * @param array|null $snapshot 수정 전 스냅샷 (Service에서 전달)
     * @return void
     */
    public function handleCommonInfoAfterUpdate(ProductCommonInfo $commonInfo, array $data, ?array $snapshot = null): void
    {
        $changes = ChangeDetector::detect($commonInfo, $snapshot);

        $this->logActivity('product_common_info.update', [

            'loggable' => $commonInfo,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.product_common_info_update',
            'description_params' => ['common_info_id' => $commonInfo->id],
            'changes' => $changes,
        ]);
    }

    /**
     * 상품 공통정보 삭제 후 로그 기록
     *
     * @param int $commonInfoId 삭제된 공통정보 ID
     * @return void
     */
    public function handleCommonInfoAfterDelete(int $commonInfoId): void
    {
        $this->logActivity('product_common_info.delete', [

            'description_key' => 'sirsoft-ecommerce::activity_log.description.product_common_info_delete',
            'description_params' => ['common_info_id' => $commonInfoId],
            'properties' => ['common_info_id' => $commonInfoId],
        ]);
    }

    // ═══════════════════════════════════════════
    // ProductNoticeTemplate 핸들러
    // ═══════════════════════════════════════════

    /**
     * 상품 고시정보 템플릿 생성 후 로그 기록
     *
     * @param ProductNoticeTemplate $template 생성된 템플릿
     * @param array $data 생성 데이터
     * @return void
     */
    public function handleNoticeTemplateAfterCreate(ProductNoticeTemplate $template, array $data): void
    {
        $this->logActivity('product_notice_template.create', [

            'loggable' => $template,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.product_notice_template_create',
            'description_params' => ['template_id' => $template->id],
            'properties' => ['name' => $template->name ?? null],
        ]);
    }

    /**
     * 상품 고시정보 템플릿 수정 후 로그 기록
     *
     * @param ProductNoticeTemplate $template 수정된 템플릿
     * @param array $data 수정 데이터
     * @param array|null $snapshot 수정 전 스냅샷 (Service에서 전달)
     * @return void
     */
    public function handleNoticeTemplateAfterUpdate(ProductNoticeTemplate $template, array $data, ?array $snapshot = null): void
    {
        $changes = ChangeDetector::detect($template, $snapshot);

        $this->logActivity('product_notice_template.update', [

            'loggable' => $template,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.product_notice_template_update',
            'description_params' => ['template_id' => $template->id],
            'changes' => $changes,
        ]);
    }

    /**
     * 상품 고시정보 템플릿 삭제 후 로그 기록
     *
     * @param int $templateId 삭제된 템플릿 ID
     * @return void
     */
    public function handleNoticeTemplateAfterDelete(int $templateId): void
    {
        $this->logActivity('product_notice_template.delete', [

            'description_key' => 'sirsoft-ecommerce::activity_log.description.product_notice_template_delete',
            'description_params' => ['template_id' => $templateId],
            'properties' => ['template_id' => $templateId],
        ]);
    }

    /**
     * 상품 고시정보 템플릿 복사 후 로그 기록
     *
     * @param ProductNoticeTemplate $copied 복사된 템플릿
     * @param ProductNoticeTemplate $original 원본 템플릿
     * @return void
     */
    public function handleNoticeTemplateAfterCopy(ProductNoticeTemplate $copied, ProductNoticeTemplate $original): void
    {
        $this->logActivity('product_notice_template.copy', [

            'loggable' => $copied,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.product_notice_template_copy',
            'description_params' => ['template_id' => $copied->id],
            'properties' => [
                'original_id' => $original->id,
                'copied_id' => $copied->id,
            ],
        ]);
    }

    // ═══════════════════════════════════════════
    // ExtraFeeTemplate 핸들러
    // ═══════════════════════════════════════════

    /**
     * 추가비용 템플릿 생성 후 로그 기록
     *
     * @param ExtraFeeTemplate $template 생성된 추가비용 템플릿
     * @return void
     */
    public function handleExtraFeeAfterCreate(ExtraFeeTemplate $template): void
    {
        $this->logActivity('extra_fee_template.create', [

            'loggable' => $template,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.extra_fee_template_create',
            'description_params' => ['template_id' => $template->id],
            'properties' => ['name' => $template->name ?? null],
        ]);
    }

    /**
     * 추가비용 템플릿 수정 후 로그 기록
     *
     * @param ExtraFeeTemplate $template 수정된 추가비용 템플릿
     * @param array|null $snapshot 수정 전 스냅샷 (Service에서 전달)
     * @return void
     */
    public function handleExtraFeeAfterUpdate(ExtraFeeTemplate $template, ?array $snapshot = null): void
    {
        $changes = ChangeDetector::detect($template, $snapshot);

        $this->logActivity('extra_fee_template.update', [

            'loggable' => $template,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.extra_fee_template_update',
            'description_params' => ['template_id' => $template->id],
            'changes' => $changes,
        ]);
    }

    /**
     * 추가비용 템플릿 삭제 후 로그 기록
     *
     * @param int $templateId 삭제된 추가비용 템플릿 ID
     * @return void
     */
    public function handleExtraFeeAfterDelete(int $templateId): void
    {
        $this->logActivity('extra_fee_template.delete', [

            'description_key' => 'sirsoft-ecommerce::activity_log.description.extra_fee_template_delete',
            'description_params' => ['template_id' => $templateId],
            'properties' => ['template_id' => $templateId],
        ]);
    }

    /**
     * 추가비용 템플릿 활성화 전환 후 로그 기록
     *
     * @param ExtraFeeTemplate $template 추가비용 템플릿
     * @return void
     */
    public function handleExtraFeeAfterToggleActive(ExtraFeeTemplate $template): void
    {
        $this->logActivity('extra_fee_template.toggle_active', [

            'loggable' => $template,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.extra_fee_template_toggle_active',
            'description_params' => ['template_id' => $template->id],
        ]);
    }

    /**
     * 추가비용 템플릿 일괄 삭제 후 로그 기록
     *
     * @param array $ids 대상 ID 목록
     * @param int $count 삭제된 수
     * @param array $snapshots 삭제 전 스냅샷 맵 (id => snapshot, Service에서 전달)
     * @return void
     */
    public function handleExtraFeeAfterBulkDelete(array $ids, int $count, array $snapshots = []): void
    {
        foreach ($ids as $id) {
            $snapshot = $snapshots[$id] ?? null;

            $this->logActivity('extra_fee_template.bulk_delete', [
                'loggable_type' => ExtraFeeTemplate::class,
                'loggable_id' => $id,
                'description_key' => 'sirsoft-ecommerce::activity_log.description.extra_fee_template_bulk_delete',
                'description_params' => ['count' => 1],
                'properties' => [
                    'extra_fee_template_id' => $id,
                    'snapshot' => $snapshot,
                ],
            ]);
        }
    }

    /**
     * 추가비용 템플릿 일괄 활성화 전환 후 로그 기록
     *
     * @param array $ids 대상 ID 목록
     * @param bool $isActive 변경된 활성 상태
     * @param int $count 변경된 수
     * @param array $snapshots 변경 전 스냅샷 맵 (id => snapshot, Service에서 전달)
     * @return void
     */
    public function handleExtraFeeAfterBulkToggleActive(array $ids, bool $isActive, int $count, array $snapshots = []): void
    {
        $templates = ExtraFeeTemplate::whereIn('id', $ids)->get()->keyBy('id');

        foreach ($ids as $id) {
            $template = $templates->get($id);
            if (! $template) {
                continue;
            }

            $snapshot = $snapshots[$id] ?? null;
            $changes = $snapshot ? ChangeDetector::detect($template, $snapshot) : null;

            $this->logActivity('extra_fee_template.bulk_toggle_active', [
                'loggable' => $template,
                'description_key' => 'sirsoft-ecommerce::activity_log.description.extra_fee_template_bulk_toggle_active',
                'description_params' => ['count' => 1],
                'properties' => [
                    'template_id' => $id,
                    'is_active' => $isActive,
                ],
                'changes' => $changes,
            ]);
        }
    }

    /**
     * 추가비용 템플릿 일괄 등록 후 로그 기록 (per-item)
     *
     * @param array $items 등록 데이터 배열
     * @param int $count 등록된 수
     * @param \Illuminate\Database\Eloquent\Collection|null $createdTemplates 생성된 모델 컬렉션
     * @return void
     */
    public function handleExtraFeeAfterBulkCreate(array $items, int $count, $createdTemplates = null): void
    {
        if ($createdTemplates && $createdTemplates->isNotEmpty()) {
            foreach ($createdTemplates as $template) {
                $this->logActivity('extra_fee_template.bulk_create', [
                    'loggable' => $template,
                    'description_key' => 'sirsoft-ecommerce::activity_log.description.extra_fee_template_bulk_create',
                    'description_params' => ['count' => 1],
                    'properties' => [
                        'extra_fee_template_id' => $template->id,
                    ],
                ]);
            }

            return;
        }

        $this->logActivity('extra_fee_template.bulk_create', [
            'description_key' => 'sirsoft-ecommerce::activity_log.description.extra_fee_template_bulk_create',
            'description_params' => ['count' => $count],
            'properties' => [
                'count' => $count,
            ],
        ]);
    }

    // ═══════════════════════════════════════════
    // ShippingPolicy 핸들러
    // ═══════════════════════════════════════════

    /**
     * 배송정책 일괄 삭제 후 로그 기록
     *
     * @param array $ids 대상 ID 목록
     * @param int $count 삭제된 수
     * @param array $snapshots 삭제 전 스냅샷 맵 (id => snapshot, Service에서 전달)
     * @return void
     */
    public function handleShippingPolicyAfterBulkDelete(array $ids, int $count, array $snapshots = []): void
    {
        foreach ($ids as $id) {
            $snapshot = $snapshots[$id] ?? null;

            $this->logActivity('shipping_policy.bulk_delete', [
                'loggable_type' => ShippingPolicy::class,
                'loggable_id' => $id,
                'description_key' => 'sirsoft-ecommerce::activity_log.description.shipping_policy_bulk_delete',
                'description_params' => ['count' => 1],
                'properties' => [
                    'shipping_policy_id' => $id,
                    'snapshot' => $snapshot,
                ],
            ]);
        }
    }

    /**
     * 배송정책 일괄 활성화 전환 후 로그 기록
     *
     * @param array $ids 대상 ID 목록
     * @param bool $isActive 변경된 활성 상태
     * @param int $count 변경된 수
     * @param array $snapshots 변경 전 스냅샷 맵 (id => snapshot, Service에서 전달)
     * @return void
     */
    public function handleShippingPolicyAfterBulkToggleActive(array $ids, bool $isActive, int $count, array $snapshots = []): void
    {
        $policies = ShippingPolicy::whereIn('id', $ids)->get()->keyBy('id');

        foreach ($ids as $id) {
            $policy = $policies->get($id);
            if (! $policy) {
                continue;
            }

            $snapshot = $snapshots[$id] ?? null;
            $changes = $snapshot ? ChangeDetector::detect($policy, $snapshot) : null;

            $this->logActivity('shipping_policy.bulk_toggle_active', [
                'loggable' => $policy,
                'description_key' => 'sirsoft-ecommerce::activity_log.description.shipping_policy_bulk_toggle_active',
                'description_params' => ['count' => 1],
                'properties' => [
                    'shipping_policy_id' => $id,
                    'is_active' => $isActive,
                ],
                'changes' => $changes,
            ]);
        }
    }

    // ═══════════════════════════════════════════
    // ShippingCarrier 핸들러
    // ═══════════════════════════════════════════

    /**
     * 배송사 생성 후 로그 기록
     *
     * @param ShippingCarrier $carrier 생성된 배송사
     * @param array $data 생성 데이터
     * @return void
     */
    public function handleCarrierAfterCreate(ShippingCarrier $carrier, array $data): void
    {
        $this->logActivity('shipping_carrier.create', [

            'loggable' => $carrier,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.shipping_carrier_create',
            'description_params' => ['carrier_id' => $carrier->id],
            'properties' => ['name' => $carrier->name ?? null],
        ]);
    }

    /**
     * 배송사 수정 후 로그 기록
     *
     * @param ShippingCarrier $carrier 수정된 배송사
     * @param array $data 수정 데이터
     * @param array|null $snapshot 수정 전 스냅샷 (Service에서 전달)
     * @return void
     */
    public function handleCarrierAfterUpdate(ShippingCarrier $carrier, array $data, ?array $snapshot = null): void
    {
        $changes = ChangeDetector::detect($carrier, $snapshot);

        $this->logActivity('shipping_carrier.update', [

            'loggable' => $carrier,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.shipping_carrier_update',
            'description_params' => ['carrier_id' => $carrier->id],
            'changes' => $changes,
        ]);
    }

    /**
     * 배송사 삭제 후 로그 기록
     *
     * @param int $carrierId 삭제된 배송사 ID
     * @return void
     */
    public function handleCarrierAfterDelete(int $carrierId): void
    {
        $this->logActivity('shipping_carrier.delete', [

            'description_key' => 'sirsoft-ecommerce::activity_log.description.shipping_carrier_delete',
            'description_params' => ['carrier_id' => $carrierId],
            'properties' => ['carrier_id' => $carrierId],
        ]);
    }

    /**
     * 배송사 상태 전환 후 로그 기록
     *
     * @param ShippingCarrier $carrier 배송사
     * @return void
     */
    public function handleCarrierAfterToggleStatus(ShippingCarrier $carrier): void
    {
        $this->logActivity('shipping_carrier.toggle_status', [

            'loggable' => $carrier,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.shipping_carrier_toggle_status',
            'description_params' => ['carrier_id' => $carrier->id],
        ]);
    }

    // ═══════════════════════════════════════════
    // ProductImage 핸들러
    // ═══════════════════════════════════════════

    /**
     * 상품 이미지 업로드 후 로그 기록
     *
     * @param ProductImage $image 업로드된 상품 이미지
     * @return void
     */
    public function handleImageAfterUpload(ProductImage $image): void
    {
        $this->logActivity('product_image.upload', [

            'loggable' => $image,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.product_image_upload',
            'description_params' => ['image_id' => $image->id],
        ]);
    }

    /**
     * 상품 이미지 삭제 후 로그 기록
     *
     * @param ProductImage $image 삭제된 상품 이미지
     * @return void
     */
    public function handleImageAfterDelete(ProductImage $image): void
    {
        $this->logActivity('product_image.delete', [

            'loggable' => $image,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.product_image_delete',
            'description_params' => ['image_id' => $image->id],
        ]);
    }

    /**
     * 상품 이미지 정렬 변경 후 로그 기록
     *
     * @param array $orders 정렬 순서 배열
     * @return void
     */
    public function handleImageAfterReorder(array $orders): void
    {
        $this->logActivity('product_image.reorder', [

            'description_key' => 'sirsoft-ecommerce::activity_log.description.product_image_reorder',
            'properties' => ['count' => count($orders)],
        ]);
    }

    // ═══════════════════════════════════════════
    // ProductOption 핸들러
    // ═══════════════════════════════════════════

    /**
     * 상품 옵션 일괄 가격 수정 후 로그 기록
     *
     * @param array $optionIds 대상 옵션 ID 목록
     * @param int $updatedCount 수정된 수
     * @param array $snapshots 수정 전 스냅샷 맵 (id => snapshot, Service에서 전달)
     * @return void
     */
    public function handleOptionAfterBulkPriceUpdate(array $optionIds, int $updatedCount, array $snapshots = []): void
    {
        $this->logPerOptionChanges($optionIds, $snapshots, 'product_option.bulk_price_update', 'product_option_bulk_price_update');
    }

    /**
     * 상품 옵션 일괄 재고 수정 후 로그 기록
     *
     * @param array $optionIds 대상 옵션 ID 목록
     * @param int $updatedCount 수정된 수
     * @param array $snapshots 수정 전 스냅샷 맵 (id => snapshot, Service에서 전달)
     * @return void
     */
    public function handleOptionAfterBulkStockUpdate(array $optionIds, int $updatedCount, array $snapshots = []): void
    {
        $this->logPerOptionChanges($optionIds, $snapshots, 'product_option.bulk_stock_update', 'product_option_bulk_stock_update');
    }

    /**
     * 상품 옵션 통합 일괄 수정 후 로그 기록
     *
     * @param array $result 업데이트 결과 (options_updated)
     * @param array $data 원본 요청 데이터
     * @param array $snapshots 수정 전 스냅샷 맵 (id => snapshot, Service에서 전달)
     * @return void
     */
    public function handleOptionAfterBulkUpdate(array $result, array $data, array $snapshots = []): void
    {
        $updatedCount = $result['options_updated'] ?? 0;

        if ($updatedCount === 0) {
            return;
        }

        $optionIds = [];
        if (! empty($data['ids'])) {
            foreach ($data['ids'] as $mixed) {
                $parts = explode('-', $mixed);
                $optionIds[] = (int) end($parts);
            }
        }

        $this->logPerOptionChanges($optionIds, $snapshots, 'product_option.bulk_update', 'product_option_bulk_update');
    }

    /**
     * 옵션별 개별 활동 로그를 기록합니다.
     *
     * 벌크 작업 시 옵션 건별로 loggable을 지정하여 개별 로그를 생성합니다.
     *
     * @param array $optionIds 대상 옵션 ID 목록
     * @param array $snapshots 수정 전 스냅샷 맵 (id => snapshot, Service에서 전달)
     * @param string $action 액션명 (예: 'product_option.bulk_update')
     * @param string $descriptionKeySuffix 번역 키 접미사 (예: 'product_option_bulk_update')
     * @return void
     */
    private function logPerOptionChanges(array $optionIds, array $snapshots, string $action, string $descriptionKeySuffix): void
    {
        $options = ProductOption::whereIn('id', $optionIds)->get()->keyBy('id');

        foreach ($optionIds as $optionId) {
            $snapshot = $snapshots[$optionId] ?? null;
            $option = $options->get($optionId);

            if (! $option) {
                continue;
            }

            $changes = $snapshot ? ChangeDetector::detect($option, $snapshot) : null;

            $this->logActivity($action, [
                'loggable' => $option,
                'description_key' => 'sirsoft-ecommerce::activity_log.description.'.$descriptionKeySuffix,
                'description_params' => ['option_id' => $optionId],
                'properties' => ['option_id' => $optionId, 'product_id' => $option->product_id],
                'changes' => $changes,
            ]);
        }
    }

    // ═══════════════════════════════════════════
    // ProductReview 핸들러
    // ═══════════════════════════════════════════

    /**
     * 상품 리뷰 생성 후 로그 기록
     *
     * @param ProductReview $review 생성된 리뷰
     * @return void
     */
    public function handleReviewAfterCreate(ProductReview $review): void
    {
        $this->logActivity('product_review.create', [

            'loggable' => $review,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.product_review_create',
            'description_params' => ['review_id' => $review->id],
        ]);
    }

    /**
     * 상품 리뷰 삭제 후 로그 기록
     *
     * @param ProductReview $review 삭제된 리뷰
     * @return void
     */
    public function handleReviewAfterDelete(ProductReview $review): void
    {
        $this->logActivity('product_review.delete', [

            'loggable' => $review,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.product_review_delete',
            'description_params' => ['review_id' => $review->id],
        ]);
    }

    /**
     * 상품 리뷰 일괄 삭제 후 로그 기록
     *
     * @param array $ids 대상 리뷰 ID 목록
     * @param array $snapshots 삭제 전 스냅샷 맵 (id => snapshot, Service에서 전달)
     * @return void
     */
    public function handleReviewAfterBulkDelete(array $ids, array $snapshots = []): void
    {
        foreach ($ids as $id) {
            $snapshot = $snapshots[$id] ?? null;

            $this->logActivity('product_review.bulk_delete', [
                'loggable_type' => ProductReview::class,
                'loggable_id' => $id,
                'description_key' => 'sirsoft-ecommerce::activity_log.description.review_bulk_delete',
                'description_params' => ['count' => 1],
                'properties' => [
                    'review_id' => $id,
                    'snapshot' => $snapshot,
                ],
            ]);
        }
    }

    // ═══════════════════════════════════════════
    // 공통 헬퍼
    // ═══════════════════════════════════════════

    /**
     * 범용 Bulk 변경 감지 (스냅샷 대비 현재 DB 상태)
     *
     * @param string $modelClass 모델 클래스명
     * @param array $ids 대상 ID 목록
     * @param array $snapshots 변경 전 스냅샷 맵 (id => snapshot, Service에서 전달)
     * @return array 엔티티별 변경 내역
     */
    private function detectBulkChanges(string $modelClass, array $ids, array $snapshots): array
    {
        $allChanges = [];
        $models = $modelClass::whereIn('id', $ids)->get()->keyBy('id');

        foreach ($ids as $id) {
            $snapshot = $snapshots[$id] ?? null;
            $model = $models->get($id);

            if ($snapshot && $model) {
                $changes = ChangeDetector::detect($model, $snapshot);
                if ($changes) {
                    $allChanges[$id] = $changes;
                }
            }
        }

        return $allChanges;
    }
}
