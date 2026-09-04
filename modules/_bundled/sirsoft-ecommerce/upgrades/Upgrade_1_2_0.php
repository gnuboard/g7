<?php

namespace Modules\Sirsoft\Ecommerce\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * Ecommerce 모듈 1.2.0 업그레이드 스텝
 *
 * 기존 상품의 설명(에디터) 첫 내부 이미지 URL 을 content_thumbnail_url 캐시
 * 컬럼에 백필한다 (공개 이슈 #22 동종 — 상품 이미지 없는 상품의 목록/공유 썸네일).
 *
 * 모든 비즈니스 로직은 data/1.2.0/migrations/ 로 격리(AbstractUpgradeStep 규약).
 *
 * @upgrade-path A
 */
class Upgrade_1_2_0 extends AbstractUpgradeStep {}
