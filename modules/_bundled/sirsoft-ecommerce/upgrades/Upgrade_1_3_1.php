<?php

namespace Modules\Sirsoft\Ecommerce\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * Ecommerce 모듈 1.3.1 업그레이드 스텝
 *
 * 번호는 data/1.3.1/migrations/ 의 파일 접두 번호와 같다(파일 이름 순으로 실행된다):
 *
 *   01. 탈퇴 회원에게 남은 마일리지를 탈퇴 소멸 계약대로 소멸 기록
 *
 * 01 번이 필요한 이유 — 회원 탈퇴는 남은 마일리지를 「탈퇴로 소멸」 거래로 기록해 0 으로 만든다
 * (`UserMileageCleanupListener` → `UserMileageService::forfeitForWithdrawal`, 거래 행은 보존). 주문 적립·환불 반환도
 * 탈퇴 회원에게 들어가면 즉시 소멸시킨다. 그런데 1.3.0 의 관리자 수동 지급·유효기간 연장은 탈퇴 회원을 거르지 않아,
 * 탈퇴 뒤 지급된 잔액이 쓸 수 없는 채 남았다. 1.3.1 은 그 지급·연장을 거절하고, 이미 남은 잔액은 여기서 탈퇴 때와
 * 같은 계약으로 소멸 기록한다. 탈퇴 회원은 로그인할 수 없어 그 잔액을 쓸 길이 없다.
 *
 * 모든 비즈니스 로직은 data/1.3.1/migrations/ 로 격리(AbstractUpgradeStep 규약).
 *
 * @upgrade-path A
 */
class Upgrade_1_3_1 extends AbstractUpgradeStep {}
