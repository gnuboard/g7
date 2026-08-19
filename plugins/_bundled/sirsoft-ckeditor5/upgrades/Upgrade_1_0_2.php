<?php

namespace Plugins\Sirsoft\Ckeditor5\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * sirsoft-ckeditor5 플러그인 1.0.2 업그레이드 스텝
 *
 * 미사용 이미지 자동 정리 설정 두 키(`unusedImageCleanup`, `unusedImageRetentionDays`)를
 * 기설치본의 저장 설정 파일에 백필한다. defaults.json 은 설치 시점에만 저장 파일을 시드하므로
 * 이미 설치된 사이트에는 새 키가 생기지 않고, 그 상태에서는 스케줄 게이트가 설정을 찾지 못해
 * "설정을 만지지 않은 사이트에서 자동 삭제 0" 이 저장 파일 수준에서 보이지 않는다.
 *
 * 모든 비즈니스 로직은 data/1.0.2/migrations/ 로 격리(AbstractUpgradeStep 규약).
 */
class Upgrade_1_0_2 extends AbstractUpgradeStep {}
