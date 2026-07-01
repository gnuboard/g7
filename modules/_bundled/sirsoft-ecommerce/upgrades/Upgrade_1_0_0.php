<?php

namespace Modules\Sirsoft\Ecommerce\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * Ecommerce 모듈 1.0.0 업그레이드 스텝
 *
 * 입금 대기 주문 자동취소 기한을 결제수단 무관 단일 SSoT(`auto_cancel_days`)로 통일하면서
 * 제거되는 구 설정 키(`vbank_due_days`/`dbank_due_days`)의 기존 사용자 설정값을 보존한다.
 *
 * 추가로 다통화 환산 공식을 KRW-base 종속 ÷1000 에서 통화별 base_unit 기반으로 전환한다
 * (NormalizeCurrencyBaseUnit: 설정 환율 정규화, NormalizeOrderSnapshotBaseUnit: 주문 스냅샷
 * base_unit 박제 + 환율 정규화 — 환차손 0 유지).
 *
 * 모든 비즈니스 로직은 data/1.0.0/migrations/ 로 격리(AbstractUpgradeStep 규약).
 *
 * @upgrade-path B
 */
class Upgrade_1_0_0 extends AbstractUpgradeStep {}
