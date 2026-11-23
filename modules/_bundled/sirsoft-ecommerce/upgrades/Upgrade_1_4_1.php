<?php

namespace Modules\Sirsoft\Ecommerce\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * Ecommerce 모듈 1.4.1 업그레이드 스텝
 *
 * 번호는 data/1.4.1/migrations/ 의 파일 접두 번호와 같다(파일 이름 순으로 실행된다):
 *
 *   01. 저장만 되고 아무 데도 쓰이지 않던 배송 설정 키 2개(`address_validation_enabled`·`address_api_provider`)를 저장값에서 지운다
 *
 * 01 번이 필요한 이유 — 두 키는 화면이 없고 읽는 코드도 없는 잔재였다(#952 전수조사). 1.4.1 은 기본값과 저장 검증에서 뺐으므로
 * 이미 저장된 사이트의 설정 파일에도 남기지 않는다.
 *
 * 쿠폰 자동발급 컬럼은 마이그레이션(`2026_11_21_000001`·`000002`)이 더한다. 이미 자동발급으로 등록해 둔 발급중 쿠폰은 손대지 않는다 —
 * 업데이트 직후부터 실제로 발급된다(CHANGELOG 업데이트 안내).
 *
 * 모든 비즈니스 로직은 data/1.4.1/migrations/ 로 격리(AbstractUpgradeStep 규약).
 *
 * @upgrade-path A
 */
class Upgrade_1_4_1 extends AbstractUpgradeStep {}
