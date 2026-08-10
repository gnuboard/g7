<?php

namespace Plugins\Sirsoft\VerificationKginicis\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * sirsoft-verification_kginicis 플러그인 1.0.2 업그레이드 스텝
 *
 * 외래키 컬럼의 비어 있는 한국어 comment 를 채운다(1컬럼). `->comment()` 가
 * `->constrained()` 뒤에 체인되어 컬럼이 아닌 외래키 정의에 부착되던 문제를 소스에서
 * 교정했으나, 기설치본은 마이그레이션이 재실행되지 않아 그대로 남기 때문이다.
 *
 * 모든 비즈니스 로직은 data/1.0.2/migrations/ 로 격리(AbstractUpgradeStep 규약).
 */
class Upgrade_1_0_2 extends AbstractUpgradeStep {}
