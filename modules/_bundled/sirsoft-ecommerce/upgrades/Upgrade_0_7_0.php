<?php

namespace Modules\Sirsoft\Ecommerce\Upgrades;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Extension\Helpers\SettingsMigrator;
use App\Extension\UpgradeContext;
/**
 * v0.7.0 업그레이드 스텝
 *
 * - SEO 설정 코어 이관에 따른 이커머스 모듈 설정 정리
 * - seo_user_agents → 코어 seo.bot_user_agents로 이관
 * - seo_site_main, meta_main_title, meta_main_description 제거 (이커머스 범위 아님)
 */
class Upgrade_0_7_0 implements UpgradeStepInterface
{
    /**
     * 업그레이드를 실행합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        $this->migrateUserAgentsToCore($context);
        $this->removeNonEcommerceSettings($context);
    }

    /**
     * 이커머스 seo_user_agents 값을 코어 seo.bot_user_agents로 이관합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    private function migrateUserAgentsToCore(UpgradeContext $context): void
    {
        $coreRepo = app(ConfigRepositoryInterface::class);

        $moduleUa = module_setting('sirsoft-ecommerce', 'seo.seo_user_agents');

        if (! empty($moduleUa) && is_array($moduleUa)) {
            $coreUa = $coreRepo->get('seo.bot_user_agents', []);
            $merged = array_values(array_unique(array_merge($coreUa, $moduleUa)));
            $coreRepo->set('seo.bot_user_agents', $merged);
            $context->logger->info('[v0.7.0] Migrated '.count($moduleUa).' user agents to core');
        } else {
            $context->logger->info('[v0.7.0] No custom user agents to migrate');
        }
    }

    /**
     * 이커머스 범위가 아닌 SEO 설정 필드를 제거합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    private function removeNonEcommerceSettings(UpgradeContext $context): void
    {
        $result = SettingsMigrator::forModule('sirsoft-ecommerce')
            ->removeField('seo.seo_user_agents')
            ->removeField('seo.seo_site_main')
            ->removeField('seo.meta_main_title')
            ->removeField('seo.meta_main_description')
            ->apply();

        $context->logger->info("[v0.7.0] Removed {$result['applied']} settings fields");
    }
}
