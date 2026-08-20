<?php

namespace Modules\Sirsoft\Ecommerce\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * Ecommerce 모듈 1.2.0 업그레이드 스텝
 *
 * 교환/반품/환불 통합 클레임 기능 신설에 따른 데이터 전환을 수행한다.
 * 신규 테이블·컬럼 생성은 database/migrations 가, 선언형 산출물(권한/메뉴/알림)
 * 동기화는 ModuleManager 가 담당하므로 여기서는 데이터 전환만 다룬다:
 *
 *   1. 기존 환불 이력의 발생 출처를 polymorphic 축(refund_source_*)으로 백필
 *   2. 교환/반품 사유 카탈로그 시드
 *   3. claim 설정 카테고리 기본값 병합 (즉시취소 상태는 기존 값 승계)
 *
 * 모든 비즈니스 로직은 data/1.2.0/migrations/ 로 격리(AbstractUpgradeStep 규약).
 *
 * @upgrade-path A
 */
class Upgrade_1_2_0 extends AbstractUpgradeStep {}
