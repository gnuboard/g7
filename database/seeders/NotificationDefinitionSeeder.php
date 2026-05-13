<?php

namespace Database\Seeders;

use App\Extension\Helpers\NotificationSyncHelper;
use Database\Seeders\Concerns\LoadsConfigSeedWithLangPackFilter;
use Illuminate\Database\Seeder;

/**
 * 코어 알림 정의를 시딩합니다.
 *
 * config/core.php 의 `notification_definitions` 블록을 SSoT 로 읽어 DB 로 동기화합니다.
 * IdentityPolicySeeder 와 동일한 패턴.
 *
 * 모듈/플러그인 자체 알림은 각자의 `getNotificationDefinitions()` 에서 선언하며
 * Manager 가 activate/update 시 자동 동기화하므로 본 시더는 코어 정의만 처리합니다.
 *
 * 운영자가 관리자 UI 에서 수정한 값은 user_overrides 로 보존되어 재시딩 시 덮어써지지 않습니다.
 *
 * @since 7.0.0-beta.4
 */
class NotificationDefinitionSeeder extends Seeder
{
    use LoadsConfigSeedWithLangPackFilter;

    /**
     * 코어 알림 정의 시딩 실행.
     *
     * @return void
     */
    public function run(): void
    {
        $this->command?->info('코어 알림 정의 시딩 시작...');

        $helper = app(NotificationSyncHelper::class);
        // config/core.php::notification_definitions + 언어팩 seed/notifications.json 병합
        $definitions = $this->loadConfigSeed('core.notification_definitions', 'seed.notifications.translations');

        $definedTypes = [];

        if (empty($definitions)) {
            $this->command?->warn('config/core.php 에 notification_definitions 선언이 없습니다.');

            return;
        }

        foreach ($definitions as $type => $data) {
            $data = array_merge($data, [
                'type' => $type,
                'extension_type' => 'core',
                'extension_identifier' => 'core',
            ]);

            $definition = $helper->syncDefinition($data);
            $definedTypes[] = $definition->type;

            $definedChannels = [];
            foreach ($data['templates'] ?? [] as $template) {
                $helper->syncTemplate($definition->id, $template);
                $definedChannels[] = $template['channel'];
            }

            // 완전 동기화: config 에서 제거된 channel 의 template 삭제
            $helper->cleanupStaleTemplates($definition->id, $definedChannels);

            $this->command?->info("  - {$type} 알림 정의 등록 완료");
        }

        // 완전 동기화: config 에서 제거된 코어 definition 삭제 (cascade 로 template 도 정리)
        $removed = $helper->cleanupStaleDefinitions('core', 'core', $definedTypes);
        if ($removed > 0) {
            $this->command?->line("  - stale 정의 {$removed}개 정리");
        }

        $this->command?->info('코어 알림 정의 시딩 완료 ('.count($definedTypes).'종)');
    }
}
