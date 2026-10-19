<?php

namespace App\Upgrades\Data\V7_2_0\Migrations;

use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;

/**
 * 활성 모듈·플러그인의 선언형 산출물(7.2.0 부터 스케줄 포함)을 새 코드로 재동기화합니다.
 *
 * 7.1.x 까지 확장 예약은 `routes/console.php` 가 매 틱 `getSchedules()` 를 읽어 등록했다. 7.2.0 은 그 등록을 지우고
 * 스케줄 등록부(`schedules` 표)만 실행하므로, 확장 선언이 등록부에 동기화되어 있어야 돈다. 동기화는 확장 활성화·갱신
 * 때 일어나는데, 코어만 올리고 확장은 갱신하지 않은 사이트(또는 번들 갱신을 건너뛴 업데이트)는 그 계기가 없다 — 그러면
 * 업데이트 직후부터 입금 기한 주문 취소·마일리지 소멸·환율 갱신 같은 확장 예약이 조용히 멈춘다.
 *
 * 이 스텝은 모듈·플러그인 매니저의 `resyncAllActiveDeclarativeArtifacts()`(활성 확장 전수의 선언형 산출물 비파괴
 * 동기화 — 7.2.0 부터 스케줄 포함)를 부른다. 옛 형식 선언(`schedule`·`enabled_config`)도 동기화 헬퍼가 정규화한다.
 * 운영자 수정(user_overrides)은 보존되고, 이미 동기화된 사이트에서는 바꿀 것이 없다(멱등). 비활성 확장은 대상이 아니다
 * (활성화 때 동기화된다).
 *
 * V-1 안전 예외(스텝 00 과 같은 사유): 새 코드의 동기화를 부르는 것이 이 스텝의 목적이라 매니저를 직접 부른다. 메서드가
 * 없는 판에서는 건너뛰고 복구 방법을 경고로 남긴다. 실패도 예외로 올리지 않는다.
 */
class SyncActiveExtensionSchedules implements DataMigration
{
    /** 운영자 복구 안내 (확장 선언 재동기화 — 확장 갱신과 같은 효과) */
    private const RECOVERY_HINT = 'php artisan core:execute-upgrade-steps --from=7.2.0 --to=7.2.0 --force --skip-migrations --skip-bundled-updates (또는 각 확장 module:update / plugin:update)';

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'SyncActiveExtensionSchedules';
    }

    /**
     * 활성 모듈·플러그인의 선언형 산출물을 재동기화합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        // audit:allow upgrade-step-vone-safety reason: 새 코드의 확장 선언 동기화(스케줄 포함)를 부르는 것이 이 스텝의 목적 — method_exists 가드
        // audit:allow upgrade-step-data-snapshot reason: 위와 같음
        $moduleManager = app(ModuleManager::class);
        // audit:allow upgrade-step-vone-safety reason: 위와 같음 (플러그인 매니저)
        // audit:allow upgrade-step-data-snapshot reason: 위와 같음
        $pluginManager = app(PluginManager::class);
        $managers = ['module' => $moduleManager, 'plugin' => $pluginManager];

        foreach ($managers as $kind => $manager) {
            if (! method_exists($manager, 'resyncAllActiveDeclarativeArtifacts')) {
                $context->logger->warning("[7.2.0] 활성 {$kind} 선언 재동기화 건너뜀 — 재동기화 메서드 없음. 업데이트 뒤 실행: ".self::RECOVERY_HINT);

                continue;
            }

            try {
                $result = $manager->resyncAllActiveDeclarativeArtifacts();
                $context->logger->info("[7.2.0] 활성 {$kind} 선언형 산출물(스케줄 포함) 재동기화 완료 (운영자 수정 보존, 멱등)", [
                    'result' => $result,
                ]);
            } catch (\Throwable $e) {
                $context->logger->warning("[7.2.0] 활성 {$kind} 선언 재동기화 실패 — 업데이트 뒤 실행: ".self::RECOVERY_HINT, [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
