<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Modules\Sirsoft\Ecommerce\Models\Brand;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Models\ExtraFeeTemplate;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductCommonInfo;
use Modules\Sirsoft\Ecommerce\Models\ProductImage;
use Modules\Sirsoft\Ecommerce\Models\ProductLabel;
use Modules\Sirsoft\Ecommerce\Models\ProductNoticeTemplate;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;

/**
 * 이커머스 활동 로그 description_params 해석 리스너
 *
 * 활동 로그에 저장된 엔티티 ID를 표시 시점에 사람이 읽을 수 있는 이름으로 변환합니다.
 * properties에 저장된 스냅샷 이름을 우선 사용하고, 없으면 DB를 조회합니다.
 *
 * 훅: core.activity_log.filter_description_params (Filter)
 */
class ActivityLogDescriptionResolver implements HookListenerInterface
{
    /**
     * 구독할 훅과 메서드 매핑 반환
     *
     * @return array 훅 매핑 배열
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.activity_log.filter_description_params' => [
                'method' => 'resolveDescriptionParams',
                'type' => 'filter',
                'priority' => 10,
            ],
        ];
    }

    /**
     * 훅 이벤트 처리 (기본 핸들러)
     *
     * @param mixed ...$args 훅에서 전달된 인수들
     * @return mixed
     */
    public function handle(...$args): void
    {
        // 기본 핸들러는 사용하지 않음
    }

    /**
     * description_params에 엔티티 이름을 해석하여 추가합니다.
     *
     * @param array $params 현재 description_params
     * @param string $descriptionKey 번역 키
     * @param array $properties 활동 로그 properties
     * @return array 해석된 description_params
     */
    public function resolveDescriptionParams(array $params, string $descriptionKey, array $properties): array
    {
        $prefix = 'sirsoft-ecommerce::activity_log.description.';
        if (! str_starts_with($descriptionKey, $prefix)) {
            return $params;
        }

        $keySuffix = str_replace($prefix, '', $descriptionKey);

        return match (true) {
            str_starts_with($keySuffix, 'brand_') => $this->resolveEntityName($params, $properties, 'brand_name', Brand::class, 'brand_id'),
            str_starts_with($keySuffix, 'label_') => $this->resolveEntityName($params, $properties, 'label_name', ProductLabel::class, 'label_id'),
            str_starts_with($keySuffix, 'product_common_info_') => $this->resolveEntityName($params, $properties, 'info_name', ProductCommonInfo::class, 'common_info_id'),
            str_starts_with($keySuffix, 'product_notice_template_') => $this->resolveEntityName($params, $properties, 'template_name', ProductNoticeTemplate::class, 'template_id'),
            str_starts_with($keySuffix, 'extra_fee_template_') => $this->resolveEntityName($params, $properties, 'template_name', ExtraFeeTemplate::class, 'template_id'),
            str_starts_with($keySuffix, 'shipping_carrier_') => $this->resolveEntityName($params, $properties, 'carrier_name', ShippingCarrier::class, 'carrier_id'),
            str_starts_with($keySuffix, 'product_image_') => $this->resolveProductNameFromImage($params, $properties),
            in_array($keySuffix, ['product_create', 'product_update', 'product_delete', 'product_stock_sync']) => $this->resolveEntityName($params, $properties, 'product_name', Product::class, 'product_id'),
            $keySuffix === 'product_review_create' => $this->resolveProductNameFromReview($params, $properties),
            $keySuffix === 'coupon_use' => $this->resolveCouponNames($params, $properties),
            $keySuffix === 'coupon_restore' => $this->resolveCouponNamesFromIssues($params, $properties),
            default => $params,
        };
    }

    /**
     * properties.name 스냅샷 또는 DB 조회로 엔티티 이름을 해석합니다.
     *
     * @param array $params description_params
     * @param array $properties 활동 로그 properties
     * @param string $paramKey 추가할 파라미터 키 (예: 'brand_name')
     * @param string $modelClass 모델 클래스
     * @param string $idKey params에서 ID를 찾을 키 (예: 'brand_id')
     * @return array 해석된 params
     */
    private function resolveEntityName(array $params, array $properties, string $paramKey, string $modelClass, string $idKey): array
    {
        // 이미 해석된 이름이 있으면 스킵
        if (! empty($params[$paramKey])) {
            return $params;
        }

        // 1순위: properties.name 스냅샷 (로그 기록 시점의 이름)
        if (! empty($properties['name'])) {
            $params[$paramKey] = $this->resolveI18nName($properties['name']);

            return $params;
        }

        // 2순위: DB에서 조회
        $id = $params[$idKey] ?? null;
        if ($id) {
            $entity = $modelClass::find($id);
            if ($entity && isset($entity->name)) {
                $params[$paramKey] = $this->resolveI18nName($entity->name);
            } else {
                $params[$paramKey] = "ID: {$id}";
            }
        }

        return $params;
    }

    /**
     * 상품 이미지 로그에서 상품명을 해석합니다.
     *
     * @param array $params description_params
     * @param array $properties 활동 로그 properties
     * @return array 해석된 params
     */
    private function resolveProductNameFromImage(array $params, array $properties): array
    {
        if (! empty($params['product_name'])) {
            return $params;
        }

        // properties에서 product_name 스냅샷 확인
        if (! empty($properties['product_name'])) {
            $params['product_name'] = $this->resolveI18nName($properties['product_name']);

            return $params;
        }

        // image_id로 상품 조회
        $imageId = $params['image_id'] ?? null;
        if ($imageId) {
            $image = ProductImage::with('product')->find($imageId);
            if ($image?->product) {
                $params['product_name'] = $this->resolveI18nName($image->product->name);
            } else {
                $params['product_name'] = "ID: {$imageId}";
            }
        }

        return $params;
    }

    /**
     * 리뷰 로그에서 상품명을 해석합니다.
     *
     * @param array $params description_params
     * @param array $properties 활동 로그 properties
     * @return array 해석된 params
     */
    private function resolveProductNameFromReview(array $params, array $properties): array
    {
        if (! empty($params['product_name'])) {
            return $params;
        }

        if (! empty($properties['product_name'])) {
            $params['product_name'] = $this->resolveI18nName($properties['product_name']);

            return $params;
        }

        $reviewId = $params['review_id'] ?? null;
        if ($reviewId) {
            $review = ProductReview::with('product')->find($reviewId);
            if ($review?->product) {
                $params['product_name'] = $this->resolveI18nName($review->product->name);
            }
        }

        return $params;
    }

    /**
     * 쿠폰 사용 로그에서 쿠폰명을 해석합니다.
     *
     * @param array $params description_params
     * @param array $properties 활동 로그 properties
     * @return array 해석된 params
     */
    private function resolveCouponNames(array $params, array $properties): array
    {
        if (! empty($params['coupon_name'])) {
            return $params;
        }

        $couponIds = $properties['applied_coupon_ids'] ?? [];
        if (empty($couponIds)) {
            return $params;
        }

        $locale = app()->getLocale();
        $names = Coupon::withTrashed()->whereIn('id', $couponIds)
            ->pluck('name')
            ->map(fn ($name) => $this->resolveI18nName($name))
            ->filter()
            ->implode(', ');

        $params['coupon_name'] = $names ?: ('ID: '.implode(', ', $couponIds));

        return $params;
    }

    /**
     * 쿠폰 복원 로그에서 쿠폰명을 해석합니다.
     *
     * @param array $params description_params
     * @param array $properties 활동 로그 properties
     * @return array 해석된 params
     */
    private function resolveCouponNamesFromIssues(array $params, array $properties): array
    {
        if (! empty($params['coupon_name'])) {
            return $params;
        }

        $issueIds = $properties['restored_coupon_issue_ids'] ?? [];
        if (empty($issueIds)) {
            return $params;
        }

        $names = CouponIssue::with('coupon')->whereIn('id', $issueIds)
            ->get()
            ->pluck('coupon.name')
            ->map(fn ($name) => $this->resolveI18nName($name))
            ->filter()
            ->unique()
            ->implode(', ');

        $params['coupon_name'] = $names ?: ('ID: '.implode(', ', $issueIds));

        return $params;
    }

    /**
     * 다국어 이름(배열 또는 문자열)을 현재 로케일에 맞게 해석합니다.
     *
     * @param mixed $name 이름 (문자열 또는 다국어 배열)
     * @return string 해석된 이름
     */
    private function resolveI18nName(mixed $name): string
    {
        if (is_string($name)) {
            return $name;
        }

        if (is_array($name)) {
            $locale = app()->getLocale();

            return $name[$locale] ?? $name['ko'] ?? (array_values($name)[0] ?? '');
        }

        return '';
    }
}
