<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Feature\Listeners;

use App\Extension\HookManager;
use Illuminate\Support\Facades\Validator;
use Plugins\Sirsoft\SocialLogin\Tests\PluginTestCase;

/**
 * 정책 설정 저장 검증 — 코어 설정 저장 필터에 이 플러그인일 때만 규칙을 더하고, 범위·열거값 밖은 거절한다.
 *
 * @since 1.0.0
 */
class SettingsValidationTest extends PluginTestCase
{
    /**
     * 범위 안은 통과, 원본 프로필 보존 91일·모르는 정책 값·문자 불리언은 거절. 다른 플러그인 저장에는 규칙을 더하지 않는다.
     *
     * @scenario input=valid|out_of_range|bad_enum|other_plugin
     *
     * @effects valid_settings_pass, out_of_range_refused, bad_enum_refused, other_plugin_untouched
     *
     * @return void 반환값 없음
     */
    public function test_rules(): void
    {
        $rules = fn (string $identifier, array $input) => HookManager::applyFilters('core.plugin_settings.update_validation_rules', [], $identifier, $input);

        $valid = ['profile_raw_retention_days' => 14, 'email_conflict_policy' => 'verify_link', 'two_factor_enabled' => true, 'state_ttl_minutes' => 10];
        $this->assertFalse(Validator::make($valid, $rules('sirsoft-social_login', $valid))->fails());

        foreach ([
            ['profile_raw_retention_days' => 91],
            ['state_ttl_minutes' => 2],
            ['custom_provider_limit' => 11],
            ['email_conflict_policy' => 'merge_everything'],
            ['webhook_unlink_policy' => 'delete'],
            ['two_factor_enabled' => 'maybe'],
        ] as $bad) {
            $this->assertTrue(Validator::make($bad, $rules('sirsoft-social_login', $bad))->fails(), json_encode($bad));
        }

        $this->assertSame([], $rules('sirsoft-other', ['profile_raw_retention_days' => 999]));
    }
}
