<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Tosspayments\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * v1.0.0 업그레이드 스텝
 *
 * 저장된 결제 리다이렉트 주소를 상점 주소 설정(route_path / no_route)을 따르도록 백필한다.
 *
 * 모든 비즈니스 로직은 data/1.0.0/migrations/ 로 격리(AbstractUpgradeStep 규약).
 *
 * @upgrade-path B
 */
class Upgrade_1_0_0 extends AbstractUpgradeStep {}
