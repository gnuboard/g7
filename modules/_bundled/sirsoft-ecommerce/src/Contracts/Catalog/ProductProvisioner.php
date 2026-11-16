<?php

namespace Modules\Sirsoft\Ecommerce\Contracts\Catalog;

use Modules\Sirsoft\Ecommerce\Contracts\Catalog\Data\ProvisionedProduct;
use Modules\Sirsoft\Ecommerce\Contracts\Catalog\Data\SimpleProductSpec;

/**
 * 단순 상품 생성 공개 계약
 *
 * 다른 확장(결제사 시험 상품 등)이 옵션 1개짜리 상품을 만들 때 쓴다. 상품 생성 훅은 관리자 상품 등록과 같게 발화한다.
 *
 * @since 1.4.0
 */
interface ProductProvisioner
{
    /** 계약 판 — 메서드 추가는 판을 올리지 않는다(구현은 이커머스만) */
    public const VERSION = 1;

    /**
     * 기본 옵션 1개를 가진 상품을 만듭니다. 명세의 통화가 기본 통화가 아니면 지금 환율로 기본 통화 가격을 정해 저장한다.
     *
     * @param  SimpleProductSpec  $spec  상품 명세
     * @return ProvisionedProduct 만든 상품의 id, 코드, 저장 가격
     *
     * @throws \InvalidArgumentException 명세 통화가 쇼핑몰 통화 설정에 없거나 환율이 없을 때
     */
    public function createSimpleProduct(SimpleProductSpec $spec): ProvisionedProduct;
}
