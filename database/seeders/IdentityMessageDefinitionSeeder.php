<?php

namespace Database\Seeders;

use App\Extension\Helpers\IdentityMessageSyncHelper;
use App\Extension\HookManager;
use Illuminate\Database\Seeder;

/**
 * IDV 메시지 정의 시더.
 *
 * config/core.php 의 `identity_messages` 블록을 SSoT 로 읽어 DB 로 동기화합니다.
 * IdentityPolicySeeder / NotificationDefinitionSeeder 와 동일한 config-기반 패턴.
 *
 * 운영자가 관리자 UI 에서 수정한 값은 user_overrides 로 보존되어 재시딩 시 덮어써지지 않습니다.
 *
 * @since 7.0.0-beta.4
 */
class IdentityMessageDefinitionSeeder extends Seeder
{
    /**
     * 시드 실행.
     *
     * @return void
     */
    public function run(): void
    {
        $this->command?->info('코어 IDV 메시지 정의 시딩 시작...');

        $helper = app(IdentityMessageSyncHelper::class);
        $definitions = $this->getDefaultDefinitions();

        // 언어팩 시스템: 활성 코어 언어팩의 seed/identity_messages.json 으로 다국어 키 병합.
        // NotificationDefinitionSeeder 와 동일 패턴.
        $definitions = HookManager::applyFilters('seed.identity_messages.translations', $definitions);

        $definedScopes = [];

        if (empty($definitions)) {
            $this->command?->warn('config/core.php 에 identity_messages 선언이 없습니다.');

            return;
        }

        foreach ($definitions as $data) {
            $definition = $helper->syncDefinition($data);
            $definedScopes[] = [
                'provider_id' => $definition->provider_id,
                'scope_type' => $definition->scope_type->value,
                'scope_value' => $definition->scope_value,
            ];

            $definedChannels = [];
            foreach ($data['templates'] as $template) {
                $helper->syncTemplate($definition->id, $template);
                $definedChannels[] = $template['channel'];
            }

            $helper->cleanupStaleTemplates($definition->id, $definedChannels);

            $this->command?->info("  - {$definition->provider_id} | {$definition->scope_type->value} | {$definition->scope_value} 등록 완료");
        }

        $helper->cleanupStaleDefinitions('core', 'core', $definedScopes);

        $this->command?->info('코어 IDV 메시지 정의 시딩 완료 ('.count($definitions).'종)');
    }

    /**
     * 코어 기본 메시지 정의 데이터를 config/core.php 에서 조회합니다.
     *
     * `extension_type='core'` / `extension_identifier='core'` 자동 주입.
     * `variables: '__common__'` 마커는 commonVariables() 로 expand.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getDefaultDefinitions(): array
    {
        $messages = config('core.identity_messages', []);
        $result = [];

        foreach ($messages as $data) {
            if (($data['variables'] ?? null) === '__common__') {
                $data['variables'] = $this->commonVariables();
            }
            $data['extension_type'] = 'core';
            $data['extension_identifier'] = 'core';
            $result[] = $data;
        }

        return $result;
    }

    /**
     * 표준 변수 메타데이터 (모든 mail 정의 공통).
     *
     * config/core.php 에서 `'variables' => '__common__'` 마커로 참조됩니다.
     *
     * @return array<int, array{key: string, description: string}>
     */
    protected function commonVariables(): array
    {
        return [
            ['key' => 'code', 'description' => '인증 코드 (text_code 흐름)'],
            ['key' => 'action_url', 'description' => '검증 링크 URL (link 흐름)'],
            ['key' => 'expire_minutes', 'description' => '만료까지 남은 분'],
            ['key' => 'purpose_label', 'description' => '인증 목적 라벨 (다국어)'],
            ['key' => 'app_name', 'description' => '사이트명'],
            ['key' => 'site_url', 'description' => '사이트 URL'],
            ['key' => 'recipient_email', 'description' => '수신자 이메일'],
        ];
    }
}
