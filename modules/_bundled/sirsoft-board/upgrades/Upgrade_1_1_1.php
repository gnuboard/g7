<?php

namespace Modules\Sirsoft\Board\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * Board 모듈 1.1.1 업그레이드 스텝
 *
 * 비회원 게시글 수정 시 본인 확인용 비밀번호가 저장 데이터로 흘러 기존 해시를
 * 평문으로 덮던 결함의 잔존 데이터를 복구한다. 소스 교정만으로는 이미 평문이 된
 * 행이 낫지 않으며, 그 글의 작성자는 계속 수정·삭제를 할 수 없다.
 *
 * 모든 비즈니스 로직은 data/1.1.1/migrations/ 로 격리(AbstractUpgradeStep 규약).
 *
 * @upgrade-path A
 */
class Upgrade_1_1_1 extends AbstractUpgradeStep {}
