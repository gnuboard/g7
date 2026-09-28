<?php

namespace Modules\Sirsoft\Ecommerce\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * Ecommerce 모듈 1.3.0 업그레이드 스텝
 *
 * 교환/반품/환불 통합 클레임 기능 신설에 따른 데이터 전환을 수행한다.
 * 신규 테이블·컬럼 생성은 database/migrations 가, 선언형 산출물(권한/메뉴/알림)
 * 동기화는 ModuleManager 가 담당하므로 여기서는 데이터 전환만 다룬다:
 *
 *   1. 기존 환불 이력의 발생 출처를 polymorphic 축(refund_source_*)으로 백필
 *   2. 교환/반품 사유 카탈로그 시드 + 운영자·시스템 발 환불 사유 2종(`admin_cancel`·`auto_cancel_expired`) 보장
 *   3. claim 설정 카테고리 기본값 병합 (즉시취소 상태는 기존 값 승계)
 *   4. 배송지 스냅샷이 빈 구형 주문(1.0.0-beta.5 이전)의 배송지 백필
 *   5. 클레임 채번 시퀀스 3종(교환·반품·클레임 추가결제) 시드
 *   6. 이미 저장된 `mc_*` 다중 통화 금액의 절사 잔차 표기 정규화
 *
 * 6 번이 필요한 이유 — 1.3.0 은 절사 결과를 만드는 지점에서 되곱하기 잔차를 지우지만 그것은
 * 이후 기록분에만 적용된다. 출시본에 이미 있던 주문·주문옵션·결제·배송·환불 테이블의 과거
 * 행은 `45.050000000000004` 같은 표기를 그대로 들고 있고, 그 값이 연동 시스템이 내려받는
 * 원본 금액으로 계속 나간다. 클레임 테이블은 1.3.0 신설이라 대상 행이 없다.
 *
 * 5 번이 여기 있는 이유 — 시퀀스는 위 문단이 말한 "선언형 산출물" 이 아니다. 권한/메뉴/알림은
 * ModuleManager::syncDeclarativeArtifacts() 가 업데이트마다 다시 맞춰 주지만, 시퀀스는
 * SequenceSeeder 가 만들고 그 시더는 ModuleManager::runModuleSeeders() 안에 있으며 그
 * 메서드는 installModule() 에서만 불린다. 그래서 1.3.0 이 새로 도입한 시퀀스 타입은
 * 신규 설치본에만 생기고 기존 설치본은 업그레이드해도 행이 없는 채로 남는다.
 *
 * 상품·분류 이미지와 상품 문의의 임시 키가 UUID 만 받게 되면서(1.3.0), 레이아웃 편집기로 고쳐 둔 화면이나
 * 「편집본 유지」 업데이트 화면에 남은 예전 임시 키 생성식을 `$uuid()` 로 바꾼다.
 *
 * 모든 비즈니스 로직은 data/1.3.0/migrations/ 로 격리(AbstractUpgradeStep 규약).
 *
 * 백필하지 않는 것 — `ecommerce_orders.total_refunded_amount` / `total_refunded_points_amount`
 *
 * 1.3.0 부터 RefundExecutionService::execute() 가 이 두 컬럼을 누적한다(클레임 환불 경로).
 * 기존 주문은 값이 전부 0 이지만 과거 환불액으로 채우지 않는다 — 1.3.0 이전의 환불은 전부
 * 취소 경로에서 발생했고, 취소는 `total_paid_amount` 자체를 재계산값으로 덮어써 이미 반영돼
 * 있기 때문이다. 여기에 과거 환불액을 더하면 OrderAdjustmentService 의 잔여 결제 한도
 * (`total_paid_amount - total_refunded_amount`)가 같은 금액을 두 번 빼게 되어, 아직 환불
 * 가능한 기존 주문의 환불 한도가 실제보다 낮게 잠긴다. 오류 없이 금액만 줄어드는 형태라
 * 발견도 어렵다.
 *
 * @upgrade-path A
 */
class Upgrade_1_3_0 extends AbstractUpgradeStep {}
