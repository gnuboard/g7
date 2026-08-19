<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Tosspayments\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * v1.0.1 업그레이드 스텝
 *
 * 저장된 이커머스 주문설정의 토스 결제수단 항목에 PG 고정 선언을 백필한다.
 *
 * 모든 비즈니스 로직은 data/1.0.1/migrations/ 로 격리(AbstractUpgradeStep 규약).
 *
 * @upgrade-path B
 */
class Upgrade_1_0_1 extends AbstractUpgradeStep {}
