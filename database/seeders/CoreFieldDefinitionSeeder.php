<?php

namespace Database\Seeders;

use App\Extension\Helpers\FieldDefinitionSyncHelper;
use App\Extension\HookManager;
use Illuminate\Database\Seeder;

/**
 * 코어 회원 항목을 시딩합니다.
 *
 * `config/core.php` 의 `fields` 블록을 읽어 field_definitions 테이블(source_type=core)로 동기화합니다.
 * 운영자가 환경설정 > 회원 탭에서 바꾼 사용·필수·순서 등은 user_overrides 로 보존되고,
 * 운영자가 만든 항목은 건드리지 않습니다.
 *
 * 저장 직전 선언은 `core.fields.config` 필터를 거친다 — 활성 코어 언어팩이 이름·안내 문구에 로케일 키를 더한다.
 *
 * DatabaseSeeder(설치)와 `CoreUpdateService::reloadCoreConfigAndResync()`(코어 업데이트)가 호출한다.
 *
 * @since 7.2.0
 */
class CoreFieldDefinitionSeeder extends Seeder
{
    /**
     * 코어 회원 항목 시딩 실행.
     *
     * @return void 반환값 없음
     */
    public function run(): void
    {
        $this->command?->info(__('common.console.seeders.core_field_definition.start'));

        $fields = config('core.fields', []);
        if (! is_array($fields) || $fields === []) {
            $this->command?->warn(__('common.console.seeders.core_field_definition.no_definitions'));

            return;
        }

        $filtered = HookManager::applyFilters('core.fields.config', $fields);

        $result = app(FieldDefinitionSyncHelper::class)->sync('core', 'core', is_array($filtered) ? $filtered : $fields);

        foreach ($result['skipped'] as $field => $reason) {
            $this->command?->warn(__('common.console.seeders.core_field_definition.skipped', ['field' => $field, 'reason' => $reason]));
        }
        if ($result['removed'] > 0) {
            $this->command?->line('  - '.trans_count('common.console.seeders.core_field_definition.stale_removed', ['count' => $result['removed']]));
        }

        $this->command?->info(__('common.console.seeders.core_field_definition.done', ['count' => count($result['synced'])]));
    }
}
