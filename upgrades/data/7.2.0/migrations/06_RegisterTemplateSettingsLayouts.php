<?php

namespace App\Upgrades\Data\V7_2_0\Migrations;

use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Extension\TemplateManager;
use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;

/**
 * 설치된 템플릿의 환경설정 화면을 활성 관리자 템플릿에 등록합니다.
 *
 * 7.2.0 부터 템플릿은 `templates/{id}/settings/template_settings.g7.html` 로 자기 설정 화면을 내놓고, 코어가 그 화면을 켜진 관리자
 * 템플릿에 `{템플릿}.template_settings` 로 등록한다. 등록은 템플릿 설치·업데이트·관리자 템플릿 활성화 때 일어나는데,
 * 코어만 올리고 템플릿 갱신·활성화를 하지 않은 사이트는 그 계기가 없다 — 그러면 템플릿 목록의 「설정」을 눌러도 화면이
 * 비어 있다. 이 스텝이 켜진 관리자 템플릿마다 설치된 템플릿 전부의 설정 화면을 등록한다.
 *
 * 등록은 keep 규칙(운영자가 편집기로 고친 부분 보존)이고 이미 등록된 사이트에서는 바꿀 것이 없다(멱등). 설정 화면 파일이
 * 없는 템플릿(설정 기능 이전 판)은 건너뛴다. 권한(`core.templates.settings`)·`general.site_favicon` 기본값은 이 스텝이 아니라
 * 업데이트의 코어 선언 재동기화·설정 기본값 병합이 맡는다.
 *
 * V-1 안전 예외: 새 코드의 등록 메서드를 부르는 것이 이 스텝의 목적이라 매니저를 직접 부른다. 메서드가 없는 판에서는 건너뛰고
 * 복구 방법을 경고로 남긴다. 실패도 예외로 올리지 않는다.
 */
class RegisterTemplateSettingsLayouts implements DataMigration
{
    /** 운영자 복구 안내 (관리자 템플릿 레이아웃 갱신 — 설치된 템플릿 설정 화면을 다시 등록한다) */
    private const RECOVERY_HINT = 'php artisan core:execute-upgrade-steps --from=7.2.0 --to=7.2.0 --force --skip-migrations --skip-bundled-updates (또는 각 템플릿 template:update)';

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'RegisterTemplateSettingsLayouts';
    }

    /**
     * 켜진 관리자 템플릿마다 설치된 템플릿의 설정 화면을 등록합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        // audit:allow upgrade-step-vone-safety reason: 새 코드의 템플릿 설정 화면 등록을 부르는 것이 이 스텝의 목적 — method_exists 가드
        // audit:allow upgrade-step-data-snapshot reason: 위와 같음
        $templateManager = app(TemplateManager::class);

        if (! method_exists($templateManager, 'registerSettingsLayoutsForAllInstalled')) {
            $context->logger->warning('[7.2.0] 템플릿 설정 화면 등록 건너뜀 — 등록 메서드 없음. 업데이트 뒤 실행: '.self::RECOVERY_HINT);

            return;
        }

        try {
            // audit:allow upgrade-step-vone-safety reason: 위와 같음 (활성 관리자 템플릿 조회)
            $hosts = app(TemplateRepositoryInterface::class)->getActiveByType('admin');
        } catch (\Throwable $e) {
            $context->logger->warning('[7.2.0] 템플릿 설정 화면 등록 건너뜀 — 관리자 템플릿 조회 실패. 업데이트 뒤 실행: '.self::RECOVERY_HINT, [
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($hosts->isEmpty()) {
            $context->logger->info('[7.2.0] 켜진 관리자 템플릿이 없어 템플릿 설정 화면 등록을 건너뜀 (관리자 템플릿을 켜면 등록된다)');

            return;
        }

        foreach ($hosts as $host) {
            try {
                $count = $templateManager->registerSettingsLayoutsForAllInstalled((string) $host->identifier);
                $context->logger->info('[7.2.0] 템플릿 설정 화면 등록 완료 (keep 규칙, 멱등)', [
                    'admin_template' => $host->identifier,
                    'registered' => $count,
                ]);
            } catch (\Throwable $e) {
                $context->logger->warning('[7.2.0] 템플릿 설정 화면 등록 실패 — 업데이트 뒤 실행: '.self::RECOVERY_HINT, [
                    'admin_template' => $host->identifier,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
