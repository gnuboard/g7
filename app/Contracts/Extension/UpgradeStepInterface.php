<?php

namespace App\Contracts\Extension;

use App\Extension\UpgradeContext;

/**
 * 버전별 업그레이드 스텝 인터페이스
 *
 * 모듈/플러그인의 버전별 업그레이드 로직을 정의합니다.
 * 각 스텝은 upgrades/ 디렉토리에 위치하며, 파일명에서 버전을 추출합니다.
 *
 * 주요 사용 사례:
 * - DB 스키마/데이터 마이그레이션
 * - 설정 구조 변경
 * - 정적 메뉴/권한 제거 (cleanupStaleMenus/cleanupStalePermissions 활용)
 *
 * ⚠️ 동적 데이터 정리:
 * 확장이 런타임에 생성한 동적 메뉴/권한/역할은 자동으로 정리되지 않습니다.
 * 새 버전에서 동적 데이터의 형식이 변경되거나, 기존 정적 메뉴/권한을 제거해야 하는 경우
 * UpgradeStep에서 ExtensionMenuSyncHelper::cleanupStaleMenus() 또는
 * ExtensionRoleSyncHelper::cleanupStalePermissions()를 명시적으로 호출하세요.
 *
 * @example Upgrade_1_1_0.php → 버전 1.1.0 업그레이드 스텝
 */
interface UpgradeStepInterface
{
    /**
     * 업그레이드 스텝을 실행합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트 (버전 정보, 로거 등)
     *
     * @throws \Exception 업그레이드 실패 시 예외 발생 (상위에서 백업 복원 처리)
     */
    public function run(UpgradeContext $context): void;
}
