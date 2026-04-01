<?php

namespace Modules\Sirsoft\Board\Upgrades;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Board\Database\Seeders\BoardMailTemplateSeeder;
use Modules\Sirsoft\Board\Models\BoardMailTemplate;

/**
 * v0.4.0 업그레이드 스텝
 *
 * - 메일 템플릿 초기 데이터 시딩 (5종)
 * - 레이아웃 캐시 클리어 (메일 템플릿 관리 UI 반영)
 */
class Upgrade_0_4_0 implements UpgradeStepInterface
{
    /**
     * 업그레이드를 실행합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        $this->seedMailTemplates($context);
        $this->clearLayoutCache($context);
    }

    /**
     * 메일 템플릿 초기 데이터를 시딩합니다.
     *
     * 기존 데이터가 있으면 건너뛰고, 없는 타입만 생성합니다.
     * (사용자가 커스터마이징한 템플릿은 보존)
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    private function seedMailTemplates(UpgradeContext $context): void
    {
        if (! Schema::hasTable('board_mail_templates')) {
            $context->logger->warning('[v0.4.0] board_mail_templates 테이블이 존재하지 않습니다. 마이그레이션을 먼저 실행하세요.');

            return;
        }

        $templates = (new BoardMailTemplateSeeder)->getDefaultTemplates();

        $created = 0;
        foreach ($templates as $template) {
            $result = BoardMailTemplate::firstOrCreate(
                ['type' => $template['type']],
                $template
            );

            if ($result->wasRecentlyCreated) {
                $created++;
            }
        }

        $context->logger->info("[v0.4.0] 게시판 메일 템플릿 시딩 완료: {$created}건 생성 (총 ".count($templates).'건 중)');
    }

    /**
     * 레이아웃 캐시를 클리어합니다.
     *
     * 메일 템플릿 관리 UI가 캐시에 반영되도록 합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    private function clearLayoutCache(UpgradeContext $context): void
    {
        try {
            Artisan::call('template:cache-clear');
            $context->logger->info('[v0.4.0] 템플릿 캐시 클리어 완료');
        } catch (\Exception $e) {
            $context->logger->warning("[v0.4.0] 템플릿 캐시 클리어 실패: {$e->getMessage()}");
        }
    }
}
