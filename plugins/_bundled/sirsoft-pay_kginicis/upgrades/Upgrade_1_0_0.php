<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * v1.0.0 업그레이드 스텝
 *
 * 네이버페이 전용 브랜드 버튼 설정 키를 KG 이니시스 간편결제 공통 브랜드 버튼 설정 키로 이관한다.
 *
 * 모든 비즈니스 로직은 data/1.0.0/migrations/ 로 격리(AbstractUpgradeStep 규약).
 *
 * @upgrade-path B
 */
class Upgrade_1_0_0 extends AbstractUpgradeStep {}
