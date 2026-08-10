<?php

namespace Modules\Sirsoft\Page\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * sirsoft-page 모듈 1.0.2 업그레이드 스텝
 *
 * 외래키 컬럼의 비어 있는 한국어 comment 를 채운다(1컬럼). `->comment()` 가
 * `->constrained()` 뒤에 체인되어 컬럼이 아닌 외래키 정의에 부착되던 문제를 소스에서
 * 교정했으나, 기설치본은 마이그레이션이 재실행되지 않아 그대로 남기 때문이다.
 *
 * 함께 발행 페이지 목록의 정렬 색인을 기존 사이트에 반영한다(02). 마이그레이션만 추가하면
 * 신규 설치에만 반영되므로 업그레이드 시점에 동일 색인을 적용한다.
 *
 * 모든 비즈니스 로직은 data/1.0.2/migrations/ 로 격리(AbstractUpgradeStep 규약).
 */
class Upgrade_1_0_2 extends AbstractUpgradeStep {}
