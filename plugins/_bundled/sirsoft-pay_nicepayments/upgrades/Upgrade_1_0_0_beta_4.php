<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * v1.0.0-beta.4 업그레이드 스텝
 *
 * 저장된 이커머스 주문설정의 나이스페이먼츠 간편결제 항목에 PG 고정 선언을 백필한다.
 *
 * 모든 비즈니스 로직은 data/1.0.0-beta.4/migrations/ 로 격리(AbstractUpgradeStep 규약).
 *
 * @upgrade-path B
 */
class Upgrade_1_0_0_beta_4 extends AbstractUpgradeStep {}
