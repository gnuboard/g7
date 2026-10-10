<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * v1.1.0 업그레이드 스텝
 *
 * 결제 정보 카드 주입을 레이아웃 이름(overlay) 대신 공용 확장 슬롯으로 옮기면서 남는
 * 구 등록 행을 지운다. 주입 대상이 없어 그려지지 않던 주문서 간편결제 조각의 구 등록 행도 지운다.
 * 코어 7.0.x 업데이트가 남긴 1.0.x 판 조각(`admin_order_list_test_badge`·`checkout_easy_pay`) 파일과 그 등록 행도
 * 지운다 — 남으면 확장 갱신이 다시 등록해 주문 목록 열을 1.0.x 판으로 덮는다.
 *
 * 이커머스가 1.3.0 미만이면 맨 앞(`00_`)에서 멈춘다 — 이 버전의 결제 경로가 1.3.0 의 클래스를 쓴다.
 *
 * 모든 비즈니스 로직은 data/1.1.0/migrations/ 로 격리(AbstractUpgradeStep 규약).
 *
 * @upgrade-path B
 */
class Upgrade_1_1_0 extends AbstractUpgradeStep {}
