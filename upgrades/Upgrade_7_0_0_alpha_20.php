<?php

namespace App\Upgrades;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\UpgradeContext;

/**
 * 코어 7.0.0-alpha.20 업그레이드 스텝
 *
 * Laravel Scout 통합 및 FULLTEXT 검색엔진 드라이버 확장 시스템 도입.
 * scout.php 설정 파일 존재 여부를 검증합니다.
 */
class Upgrade_7_0_0_alpha_20 implements UpgradeStepInterface
{
    /**
     * 업그레이드 스텝을 실행합니다.
     *
     * @param UpgradeContext $context 업그레이드 컨텍스트
     * @return void
     */
    public function run(UpgradeContext $context): void
    {
        // Scout 설정 파일 존재 확인
        if (file_exists(config_path('scout.php'))) {
            $context->logger->info('[alpha.20] config/scout.php 설정 파일 확인됨');
        } else {
            $context->logger->warning('[alpha.20] config/scout.php 설정 파일이 없습니다. 코어 업데이트 후 확인하세요.');
        }

        // Scout 드라이버 설정 확인
        $driver = config('scout.driver', 'mysql-fulltext');
        $context->logger->info("[alpha.20] 검색 엔진 드라이버: {$driver}");

        $context->logger->info('[alpha.20] Laravel Scout + FULLTEXT 검색엔진 확장 시스템 도입 완료');
    }
}
