<?php

namespace Modules\Sirsoft\Ecommerce\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * Ecommerce 모듈 1.1.0 업그레이드 스텝
 *
 * 현금영수증 발급 용도(`ecommerce_order_payments.cash_receipt_type`)의 레거시 값을 정규화한다.
 * 마이그레이션 comment 는 income/expense 였으나 KG이니시스 플러그인이
 * income_deduction/expenditure_proof 를 기록해 왔다 — CashReceiptType Enum 을 SSoT 로 통일한다.
 *
 * 모든 비즈니스 로직은 data/1.1.0/migrations/ 로 격리(AbstractUpgradeStep 규약).
 *
 * @upgrade-path B
 */
class Upgrade_1_1_0 extends AbstractUpgradeStep {}
