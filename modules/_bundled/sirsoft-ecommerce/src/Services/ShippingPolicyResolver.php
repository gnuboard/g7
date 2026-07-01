<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ShippingPolicyRepositoryInterface;

/**
 * 배송정책 폴백 해석기
 *
 * 상품에 배송정책이 부여되지 않은 경우(shipping_policy_id=null)는 "기본 배송정책 사용"을
 * 의미하며, 런타임에 기본 배송정책(is_default=true)으로 폴백한다. 배송가능 판정과
 * 배송정책 해석의 단일 SSoT 로, 표시(Resource)·차단(Service)·계산(Calculation) 전 표면이
 * 동일한 폴백 규칙을 공유하도록 한다.
 *
 * 싱글톤으로 등록되어 요청 내 기본 배송정책 조회를 1회로 캐시한다.
 */
class ShippingPolicyResolver
{
    /**
     * 요청 내 기본 배송정책 캐시 (false = 아직 미조회)
     */
    private ShippingPolicy|null|false $defaultPolicyCache = false;

    /**
     * @param  ShippingPolicyRepositoryInterface  $shippingPolicyRepository  배송정책 Repository
     */
    public function __construct(
        private ShippingPolicyRepositoryInterface $shippingPolicyRepository
    ) {}

    /**
     * 기본 배송정책(is_default=true)을 반환합니다 (요청 내 1회 캐시).
     *
     * @return ShippingPolicy|null 기본 배송정책 (없으면 null)
     */
    public function getDefaultPolicy(): ?ShippingPolicy
    {
        if ($this->defaultPolicyCache === false) {
            $this->defaultPolicyCache = $this->shippingPolicyRepository->findDefault();
        }

        return $this->defaultPolicyCache;
    }

    /**
     * 상품에 실제로 적용될 배송정책을 해석합니다.
     *
     * 상품에 정책이 부여되어 있으면 그 정책을, 없으면(null) 기본 배송정책으로 폴백합니다.
     *
     * @param  Product  $product  상품 모델
     * @return ShippingPolicy|null 적용 배송정책 (기본정책도 없으면 null)
     */
    public function resolveForProduct(Product $product): ?ShippingPolicy
    {
        $policy = $product->shippingPolicy;

        if ($policy !== null) {
            return $policy;
        }

        return $this->getDefaultPolicy();
    }

    /**
     * 상품이 지정 배송국가로 배송 가능한지 판정합니다.
     *
     * 적용 배송정책(상품정책 또는 기본정책 폴백)에 해당 국가 설정이 있으면 배송 가능합니다.
     * 적용 가능한 정책이 전혀 없을 때만 국내(KR) 기본 배송으로 간주합니다.
     *
     * @param  Product  $product  상품 모델
     * @param  string  $country  배송국가 코드 (KR, JP 등)
     * @return bool 배송 가능 여부
     */
    public function isShippableToCountry(Product $product, string $country): bool
    {
        $policy = $this->resolveForProduct($product);

        if ($policy === null) {
            return $country === 'KR';
        }

        return $policy->getCountrySetting($country) !== null;
    }
}
