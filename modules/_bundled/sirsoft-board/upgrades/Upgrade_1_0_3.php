<?php

namespace Modules\Sirsoft\Board\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * Board 모듈 1.0.3 업그레이드 스텝
 *
 * 댓글 계층 오염 데이터를 정리한다.
 *
 * - 다른 게시글의 댓글을 부모로 가진 고아 댓글: 부모 연결 해제(최상위로 승격)
 * - 부모 depth + 1 과 어긋난 depth: 루트부터 재계산
 * - 게시판 설정 max_comment_depth 초과 댓글: 리포트만 (자동 이동 없음)
 *
 * 모든 비즈니스 로직은 data/1.0.3/migrations/ 로 격리(AbstractUpgradeStep 규약).
 *
 * @upgrade-path A
 */
class Upgrade_1_0_3 extends AbstractUpgradeStep {}
