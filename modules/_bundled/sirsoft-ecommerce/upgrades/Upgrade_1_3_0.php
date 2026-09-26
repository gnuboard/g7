<?php

namespace Modules\Sirsoft\Ecommerce\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * Ecommerce 모듈 1.3.0 업그레이드 스텝
 *
 * 상품·분류 이미지와 상품 문의의 임시 키가 UUID 만 받게 되면서(1.3.0), 레이아웃 편집기로 고쳐 둔 화면이나
 * 「편집본 유지」 업데이트 화면에 남은 예전 임시 키 생성식을 `$uuid()` 로 바꾼다.
 *
 * 모든 비즈니스 로직은 data/1.3.0/migrations/ 로 격리(AbstractUpgradeStep 규약).
 *
 * @upgrade-path A
 */
class Upgrade_1_3_0 extends AbstractUpgradeStep {}
