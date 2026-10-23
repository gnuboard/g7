<?php

namespace Plugins\Sirsoft\Gdpr\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * sirsoft-gdpr 플러그인 1.1.2 업그레이드 스텝
 *
 * 설치 때 설정 파일에 굳은 계산값 키(필수 저장 잠금 목록·확장 제공 목록)를 지운다 — 1.1.2 부터 두 키는 코어의
 * 계산값 키로 선언돼 저장되지 않고 늘 실시간 값으로 읽힌다. 굳은 사본이 남아 있으면 이후 설치된 확장의 제공 키가
 * 브라우저 판정에 반영되지 않았다.
 *
 * 또한 이커머스 주문 목록 열 선택 키(`g7_order_*`)를 필수 저장 허용목록에서 걷어낸다 — 이커머스(1.3.2+)가 계약 훅으로
 * 직접 제공한다(1.1.0 의 확장 소유 키 정리와 같은 유형의 잔여).
 *
 * 모든 비즈니스 로직은 data/1.1.2/migrations/ 로 격리(AbstractUpgradeStep 규약).
 */
class Upgrade_1_1_2 extends AbstractUpgradeStep {}
