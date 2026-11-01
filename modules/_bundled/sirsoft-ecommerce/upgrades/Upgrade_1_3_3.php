<?php

namespace Modules\Sirsoft\Ecommerce\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * Ecommerce 모듈 1.3.3 업그레이드 스텝
 *
 * 번호는 data/1.3.3/migrations/ 의 파일 접두 번호와 같다(파일 이름 순으로 실행된다):
 *
 *   01. 기존 주문상품 행의 과세 종류(order_options.tax_status) 백필
 *
 * 01 번이 필요한 이유 — 1.3.3 은 상품 옵션별 과세 종류(과세·면세·영세)를 도입하고, 주문 시점에 확정한
 * 과세 종류를 주문상품 행에 기록한다(#612). 새 컬럼은 기존 행에서 비어 있어, 주문상세 품목 배지와 클레임
 * 재계산이 그 행을 상품 스냅샷으로 다시 해석해야 한다. 업그레이드 때 상품 스냅샷의 과세 종류(없거나 읽을 수
 * 없으면 과세)로 한 번 채워, 새 주문과 같은 규칙으로 읽게 한다. 영세는 이번 판에 처음 생긴 값이라 기존 행에는 없다.
 *
 * 모든 비즈니스 로직은 data/1.3.3/migrations/ 로 격리(AbstractUpgradeStep 규약).
 *
 * @upgrade-path A
 */
class Upgrade_1_3_3 extends AbstractUpgradeStep {}
