<?php

namespace Modules\Sirsoft\Board\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * Board 모듈 1.1.0 업그레이드 스텝
 *
 * 기존 게시글의 본문 첫 내부 이미지 URL 을 content_thumbnail_url 캐시 컬럼에
 * 백필한다 (공개 이슈 #22 — 본문 이미지 목록 썸네일 폴백).
 *
 * 모든 비즈니스 로직은 data/1.1.0/migrations/ 로 격리(AbstractUpgradeStep 규약).
 *
 * @upgrade-path A
 */
class Upgrade_1_1_0 extends AbstractUpgradeStep {}
