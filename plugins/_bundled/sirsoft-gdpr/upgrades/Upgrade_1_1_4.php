<?php

namespace Plugins\Sirsoft\Gdpr\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * sirsoft-gdpr 플러그인 1.1.4 업그레이드 스텝
 *
 * 기설치본의 필수 저장 허용목록(sessionStorage)에 코어 스크롤 위치 키(`g7_scroll_positions`)를 덧붙인다 — 코어 엔진
 * (engine-v1.85.0)이 뒤로/앞으로 가기·새로고침 때 보던 위치로 되돌리는 데 쓰는 탭 수명 값이다.
 *
 * 모든 비즈니스 로직은 data/1.1.4/migrations/ 로 격리(AbstractUpgradeStep 규약).
 */
class Upgrade_1_1_4 extends AbstractUpgradeStep {}
