<?php

namespace Modules\Sirsoft\Ecommerce\Upgrades;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * v0.16.0 업그레이드 스텝
 *
 * - ecommerce_product_logs 테이블 삭제 (ActivityLog 통합으로 폐지)
 * - 레이아웃 캐시 클리어
 */
class Upgrade_0_16_0 implements UpgradeStepInterface
{
    /**
     * 업그레이드를 실행합니다.
     *
     * @param UpgradeContext $context 업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        $this->dropProductLogsTable($context);
        $this->clearLayoutCache($context);
    }

    /**
     * ecommerce_product_logs 테이블을 삭제합니다.
     *
     * ActivityLog 시스템으로 통합되어 별도 테이블이 불필요합니다.
     *
     * @param UpgradeContext $context 업그레이드 컨텍스트
     */
    private function dropProductLogsTable(UpgradeContext $context): void
    {
        if (! Schema::hasTable('ecommerce_product_logs')) {
            $context->logger->info('[v0.16.0] ecommerce_product_logs 테이블이 이미 삭제되었습니다.');

            return;
        }

        Schema::dropIfExists('ecommerce_product_logs');
        $context->logger->info('[v0.16.0] ecommerce_product_logs 테이블 삭제 완료 (ActivityLog 통합)');
    }

    /**
     * 레이아웃 캐시를 클리어합니다.
     *
     * @param UpgradeContext $context 업그레이드 컨텍스트
     */
    private function clearLayoutCache(UpgradeContext $context): void
    {
        try {
            Artisan::call('template:cache-clear');
            $context->logger->info('[v0.16.0] 템플릿 캐시 클리어 완료');
        } catch (\Exception $e) {
            $context->logger->warning("[v0.16.0] 템플릿 캐시 클리어 실패: {$e->getMessage()}");
        }
    }
}
