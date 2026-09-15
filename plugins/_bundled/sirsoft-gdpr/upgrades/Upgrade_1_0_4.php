<?php

namespace Plugins\Sirsoft\Gdpr\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * sirsoft-gdpr 플러그인 1.0.4 업그레이드 스텝
 *
 * 저장된 쿠키 카테고리 설명에서 화면 테마를 「기능 쿠키」로 안내하던 문구를 정정한다.
 * 화면 테마(`g7_color_scheme`)가 언어 설정과 같은 strictly necessary 항목으로 재분류되어,
 * 동의 여부와 무관하게 저장되기 때문이다. 소스 기본값은 교정했으나 기설치본은 설치 시점에
 * 시드된 설정 파일을 그대로 쓰므로 안내 문구만 사실과 어긋난 채 남는다.
 *
 * 모든 비즈니스 로직은 data/1.0.4/migrations/ 로 격리(AbstractUpgradeStep 규약).
 */
class Upgrade_1_0_4 extends AbstractUpgradeStep {}
