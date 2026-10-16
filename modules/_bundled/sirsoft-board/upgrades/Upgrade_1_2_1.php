<?php

namespace Modules\Sirsoft\Board\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * Board 모듈 1.2.1 업그레이드 스텝
 *
 * 1.2.1 은 신고 관리 권한(`sirsoft-board.reports.view`·`reports.manage`)에서 범위(본인만·소유역할)를
 * 없앤다 — 신고는 게시판을 가로지르는 운영 업무라 「본인만」의 뜻이 없고, 1케이스 구조 뒤 케이스 테이블에
 * 신고자 컬럼도 없어 범위가 어떤 의미로도 일관되게 동작하지 않았다. 모듈 업데이트의 권한 동기화가 두
 * 권한의 범위 메타를 비우면 남은 self/role 부여 행은 실제로는 전체로 동작하므로, 화면·데이터를 실제
 * 동작과 맞게 범위 값을 비운다(01). 삭제가 아니라 비움이다 — 이미 전체로 동작 중이라 권한은 그대로다.
 *
 * 모든 비즈니스 로직은 data/1.2.1/migrations/ 로 격리(AbstractUpgradeStep 규약).
 *
 * @upgrade-path A
 */
class Upgrade_1_2_1 extends AbstractUpgradeStep {}
