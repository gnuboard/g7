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
 * 백필하지 않는 것 — `ecommerce_orders.total_refunded_amount` / `total_refunded_points_amount`
 *
 * 1.2.0 부터 RefundExecutionService::execute() 가 이 두 컬럼을 누적한다(클레임 환불 경로).
 * 기존 주문은 값이 전부 0 이지만 과거 환불액으로 채우지 않는다 — 1.2.0 이전의 환불은 전부
 * 취소 경로에서 발생했고, 취소는 `total_paid_amount` 자체를 재계산값으로 덮어써 이미 반영돼
 * 있기 때문이다. 여기에 과거 환불액을 더하면 OrderAdjustmentService 의 잔여 결제 한도
 * (`total_paid_amount - total_refunded_amount`)가 같은 금액을 두 번 빼게 되어, 아직 환불
 * 가능한 기존 주문의 환불 한도가 실제보다 낮게 잠긴다. 오류 없이 금액만 줄어드는 형태라
 * 발견도 어렵다.
 *
 * @upgrade-path A
 */
class Upgrade_1_2_0 extends AbstractUpgradeStep {}
