<?php

namespace Database\Seeders;

use App\Extension\Helpers\IdentityPolicySyncHelper;
use Illuminate\Database\Seeder;

/**
 * 코어 본인인증 정책을 시딩합니다.
 *
 * config/core.php 의 `identity_policies` 블록을 읽어 DB 로 동기화합니다.
 * 운영자가 S1d UI 에서 수정한 값은 user_overrides 로 보존되어 재시딩 시 덮어써지지 않습니다.
 *
 * @since 7.0.0-beta.4
 */
class IdentityPolicySeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('코어 본인인증 정책 시딩 시작...');

        $helper = app(IdentityPolicySyncHelper::class);
        $policies = config('core.identity_policies', []);
        $definedKeys = [];

        if (empty($policies)) {
            $this->command?->warn('config/core.php 에 identity_policies 선언이 없습니다.');

            return;
        }

        foreach ($policies as $key => $data) {
            $data = array_merge($data, [
                'key' => $key,
                'source_type' => 'core',
                'source_identifier' => 'core',
            ]);

            $helper->syncPolicy($data);
            $definedKeys[] = $key;

            $this->command?->line("  ✓ {$key}");
        }

        $removed = $helper->cleanupStalePolicies('core', 'core', $definedKeys);
        if ($removed > 0) {
            $this->command?->line("  - stale 정책 {$removed}개 정리");
        }

        $this->command?->info('코어 본인인증 정책 시딩 완료: '.count($definedKeys).'개');
    }
}
