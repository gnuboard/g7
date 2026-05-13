<?php

namespace Modules\Sirsoft\Ecommerce\Upgrades;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * v0.6.0 업그레이드 스텝
 *
 * - 배송지명(name) 컬럼 json → string 변환 검증
 * - 주문 배송지 변경 API 추가에 따른 레이아웃 캐시 클리어
 */
class Upgrade_0_6_0 implements UpgradeStepInterface
{
    /**
     * 업그레이드를 실행합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        try {
            $this->verifyNameColumnMigration($context);
            $this->clearLayoutCache($context);
            $context->logger->info('[v0.6.0] 업그레이드 완료');
        } catch (\Exception $e) {
            $context->logger->error("[v0.6.0] 업그레이드 실패: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * name 컬럼 마이그레이션 검증
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    private function verifyNameColumnMigration(UpgradeContext $context): void
    {
        if (! Schema::hasTable('ecommerce_user_addresses')) {
            $context->logger->warning('[v0.6.0] ecommerce_user_addresses 테이블이 존재하지 않습니다.');

            return;
        }

        $columnType = Schema::getColumnType('ecommerce_user_addresses', 'name');

        if ($columnType === 'json') {
            throw new \Exception('name 컬럼이 아직 json 타입입니다. 마이그레이션을 먼저 실행하세요.');
        }

        // NULL 값 레코드 검출
        $nullCount = \Modules\Sirsoft\Ecommerce\Models\UserAddress::whereNull('name')
            ->orWhere('name', '')
            ->count();

        if ($nullCount > 0) {
            $context->logger->warning("[v0.6.0] name이 비어있는 배송지 {$nullCount}건 발견 — 수동 확인 필요");
        }

        $context->logger->info('[v0.6.0] 배송지명 컬럼 마이그레이션 검증 완료');
    }

    /**
     * 레이아웃 캐시를 클리어합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    private function clearLayoutCache(UpgradeContext $context): void
    {
        try {
            Artisan::call('template:cache-clear');
            $context->logger->info('[v0.6.0] 템플릿 캐시 클리어 완료');
        } catch (\Exception $e) {
            $context->logger->warning("[v0.6.0] 템플릿 캐시 클리어 실패: {$e->getMessage()}");
        }
    }
}
