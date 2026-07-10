<?php

namespace Modules\Sirsoft\Ecommerce\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * Ecommerce 모듈 1.0.2 업그레이드 스텝
 *
 * 배송가능 국가명(shipping.available_countries[].name)의 저장본에 남아 있는 빈 로케일 키를
 * 제거한다. 구 국가 추가 폼이 한국어/영문 두 칸만 렌더했던 탓에, 한쪽을 비운 채 저장하면
 * 빈 문자열이 저장본에 박혔다. 부재 로케일은 비워 두는 것이 계약이며(언어팩이 읽기 시점에
 * 보강), 빈 문자열은 그 계약을 어기는 어중간한 상태였다.
 *
 * 국가명을 새로 채우지는 않는다 — 언어팩 보강이 그 역할을 한다.
 *
 * 모든 비즈니스 로직은 data/1.0.2/migrations/ 로 격리(AbstractUpgradeStep 규약).
 *
 * @upgrade-path B
 */
class Upgrade_1_0_2 extends AbstractUpgradeStep {}
