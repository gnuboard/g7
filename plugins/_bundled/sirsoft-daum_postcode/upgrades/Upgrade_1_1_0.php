<?php

namespace Plugins\Sirsoft\DaumPostcode\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * sirsoft-daum_postcode 플러그인 1.1.0 업그레이드 스텝
 *
 * 1.1.0 부터 팝업 너비·높이를 100~2000 범위로 검증한다. 1.0.x 는 정수 여부만 봤으므로 그 범위 밖 값이
 * 저장된 사이트는 환경설정 화면에서 다른 항목을 저장할 때도 이 두 칸 때문에 저장이 막힌다. 범위 밖 값을
 * 기본값으로 되돌려 둔다.
 *
 * 모든 비즈니스 로직은 data/1.1.0/migrations/ 로 격리(AbstractUpgradeStep 규약).
 */
class Upgrade_1_1_0 extends AbstractUpgradeStep {}
