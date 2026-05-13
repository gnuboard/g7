<?php

namespace Modules\Sirsoft\Ecommerce\Upgrades;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\Helpers\SettingsMigrator;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * v0.9.0 업그레이드 스텝
 *
 * - 상품 1:1 문의 기능 추가 (게시판 모듈 연동)
 * - ecommerce_product_inquiries 피벗 테이블 생성
 * - inquiry.board_slug 설정 기본값 추가
 */
class Upgrade_0_9_0 implements UpgradeStepInterface
{
    /**
     * 업그레이드를 실행합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        try {
            $this->runMigration($context);
            $this->addInquirySettings($context);
            $context->logger->info('[v0.9.0] 업그레이드 완료');
        } catch (\Exception $e) {
            $context->logger->error("[v0.9.0] 업그레이드 실패: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * ecommerce_product_inquiries 피벗 테이블 마이그레이션을 실행합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    private function runMigration(UpgradeContext $context): void
    {
        if (Schema::hasTable('ecommerce_product_inquiries')) {
            $context->logger->info('[v0.9.0] ecommerce_product_inquiries 테이블이 이미 존재합니다. 마이그레이션 건너뜀');

            return;
        }

        Artisan::call('migrate', [
            '--path' => 'modules/_bundled/sirsoft-ecommerce/database/migrations/2026_04_01_000039_create_ecommerce_product_inquiries_table.php',
            '--force' => true,
        ]);

        $context->logger->info('[v0.9.0] ecommerce_product_inquiries 테이블 생성 완료');
    }

    /**
     * inquiry 설정 카테고리 및 기본값을 추가합니다.
     *
     * 기존 설정이 존재하는 경우 덮어쓰지 않습니다 (SettingsMigrator 보장).
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    private function addInquirySettings(UpgradeContext $context): void
    {
        $result = SettingsMigrator::forModule('sirsoft-ecommerce')
            ->addCategory('inquiry', [
                'board_slug' => null,
            ])
            ->apply();

        $context->logger->info("[v0.9.0] inquiry 설정 기본값 추가 완료 ({$result['applied']}건 적용)");
    }
}