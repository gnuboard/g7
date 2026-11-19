<?php

namespace Database\Seeders;

use App\Enums\ExtensionOwnerType;
use App\Extension\Helpers\ExtensionScheduleSyncHelper;
use App\Extension\HookManager;
use Illuminate\Database\Seeder;

/**
 * 코어 스케줄을 시딩합니다.
 *
 * `config/core.php` 의 `schedules` 카탈로그를 SSoT 로 읽어 schedules 테이블(extension_type=core,
 * extension_identifier=core)로 동기화합니다. 운영자가 관리 화면에서 바꾼 값은 user_overrides 로 보존되고,
 * 카탈로그에서 빠진 코어 행은 지워집니다(직접 등록 행은 건드리지 않는다).
 *
 * 저장 직전 카탈로그는 `core.schedules.config` 필터를 거친다 — 활성 코어 언어팩의 seed 파일(`lang-packs/_bundled/g7-core-ja/seed/schedules.json` 형식)
 * 이 label·description 에 로케일 키를 더한다(core.menus.config 와 같은 방식).
 *
 * DatabaseSeeder(설치)와 `CoreUpdateService::reloadCoreConfigAndResync()`(코어 업데이트)가 호출한다.
 *
 * @since 7.2.0
 */
class CoreScheduleSeeder extends Seeder
{
    /**
     * 코어 스케줄 시딩 실행.
     *
     * @return void 반환값 없음
     */
    public function run(): void
    {
        $this->command?->info(__('common.console.seeders.core_schedule.start'));

        $catalog = config('core.schedules', []);
        if (! is_array($catalog) || $catalog === []) {
            $this->command?->warn(__('common.console.seeders.core_schedule.no_catalog'));

            return;
        }

        $catalog = HookManager::applyFilters('core.schedules.config', $catalog);

        $result = app(ExtensionScheduleSyncHelper::class)->syncSchedules(
            ExtensionOwnerType::Core,
            'core',
            is_array($catalog) ? $catalog : [],
        );

        foreach ($result['skipped'] as $name => $errors) {
            $this->command?->warn(__('common.console.seeders.core_schedule.skipped', ['name' => $name, 'errors' => implode(' / ', $errors)]));
        }
        if ($result['removed'] > 0) {
            $this->command?->line(trans_count('common.console.seeders.core_schedule.removed', ['count' => $result['removed']]));
        }

        $this->command?->info(__('common.console.seeders.core_schedule.done', ['count' => count($result['synced'])]));
    }
}
