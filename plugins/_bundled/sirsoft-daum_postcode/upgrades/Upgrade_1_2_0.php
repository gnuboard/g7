<?php

namespace Plugins\Sirsoft\DaumPostcode\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * sirsoft-daum_postcode 플러그인 1.2.0 업그레이드 스텝
 *
 * 1.2.0 부터 표시 방식을 PC(`display_mode_pc`)와 모바일(`display_mode_mobile`)로 나눠 고른다. 1.1.x 의 단일 표시 방식
 * (`display_mode`)을 PC 칸으로 옮기고, 모바일은 페이지 안에 펼치기(inline)로 두며, 옛 키는 지운다.
 *
 * 모든 비즈니스 로직은 data/1.2.0/migrations/ 로 격리(AbstractUpgradeStep 규약).
 */
class Upgrade_1_2_0 extends AbstractUpgradeStep {}
