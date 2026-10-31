<?php

namespace Modules\Sirsoft\Ecommerce\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * Ecommerce 모듈 1.4.0 업그레이드 스텝
 *
 * 번호는 data/1.4.0/migrations/ 의 파일 접두 번호와 같다(파일 이름 순으로 실행된다):
 *
 *   01. 기본 역할 「이커머스 관리자」에 사이트 알림 조회·읽음 처리 코어 권한 부여
 *
 * 01 번이 필요한 이유 — 이 모듈은 새 주문·상품 문의·클레임 알림을 이 역할에게도 사이트 알림으로 보내 왔고, 1.4.0 은 내보내기
 * 완료·실패 알림을 더했다. 그런데 이 역할에는 알림 목록·읽지 않은 수 API 가 요구하는 코어 권한이 없어 종 아이콘에서 볼 수 없었다.
 * 새 설치는 설치 데이터 시더(`ManagerNotificationPermissionSeeder`)가 붙이고, 기존 사이트는 이 단계가 붙인다.
 *
 * 모든 비즈니스 로직은 data/1.4.0/migrations/ 로 격리(AbstractUpgradeStep 규약).
 *
 * @upgrade-path A
 */
class Upgrade_1_4_0 extends AbstractUpgradeStep {}
