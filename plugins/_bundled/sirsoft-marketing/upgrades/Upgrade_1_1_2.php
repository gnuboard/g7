<?php

namespace Plugins\Sirsoft\Marketing\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * sirsoft-marketing 플러그인 1.1.2 업그레이드 스텝
 *
 * 1.1.1 까지 동의한 적 없는 항목에 「동의 안 함」이 저장되면 생기던 철회 기록(상태 행과 철회 이력)을 정리한다.
 * 실제로 동의했다가 철회한 기록, 지금 동의 중인 기록은 건드리지 않는다.
 *
 * 모든 비즈니스 로직은 data/1.1.2/migrations/ 로 격리(AbstractUpgradeStep 규약).
 */
class Upgrade_1_1_2 extends AbstractUpgradeStep {}
