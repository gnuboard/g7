<?php

namespace Tests\Feature\Settings;

use App\Http\Requests\Settings\SaveSettingsRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 환경설정 입력 경계값 계약 테스트
 *
 * 관리자 설정 화면의 숫자 입력 제한(min/max)과 저장 검증 규칙은 같은 값을 말해야 한다.
 * 각자 리터럴을 들면 조용히 갈라져 "저장은 되는 값인데 화면이 막는다" 또는 "화면은 받아 놓고
 * 저장에서 422" 가 된다. 화면에서도 규칙에서도 `config('core.settings_limits')` 를 읽도록
 * 배선했으므로, 그 배선이 풀리지 않았는지 양쪽에서 확인한다.
 */
class SettingsBoundaryContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 검사 대상: 설정 키 → 한계값 키 접두사
     *
     * @return array<string, string>
     */
    private function boundKeys(): array
    {
        return [
            'upload.max_file_size' => 'upload_max_file_size',
            'upload.image_max_width' => 'upload_image_max_width',
            'upload.image_max_height' => 'upload_image_max_height',
            'upload.image_quality' => 'upload_image_quality',
            'seo.og_image_default_width' => 'seo_og_image_default_width',
            'seo.og_image_default_height' => 'seo_og_image_default_height',
            'seo.cache_ttl' => 'seo_cache_ttl',
            'seo.sitemap_cache_ttl' => 'seo_sitemap_cache_ttl',
            'seo.sitemap_urls_per_file' => 'seo_sitemap_urls_per_file',
            'security.password_min_length' => 'security_password_min_length',
            'security.auth_token_lifetime' => 'security_auth_token_lifetime',
            'security.max_login_attempts' => 'security_max_login_attempts',
            'security.login_lockout_time' => 'security_login_lockout_time',
            'advanced.layout_cache_ttl' => 'advanced_cache_ttl',
            'advanced.stats_cache_ttl' => 'advanced_cache_ttl',
            'advanced.seo_cache_ttl' => 'advanced_cache_ttl',
            'advanced.seo_sitemap_cache_ttl' => 'advanced_seo_sitemap_cache_ttl',
            'drivers.redis_port' => 'drivers_redis_port',
            'drivers.redis_database' => 'drivers_redis_database',
            'drivers.memcached_port' => 'drivers_memcached_port',
            'drivers.session_lifetime' => 'drivers_session_lifetime',
            'drivers.websocket_port' => 'drivers_websocket_port',
            'drivers.websocket_server_port' => 'drivers_websocket_server_port',
            'drivers.log_days' => 'drivers_log_days',
            'identity.challenge_ttl_minutes' => 'identity_challenge_ttl_minutes',
            'identity.max_attempts' => 'identity_max_attempts',
        ];
    }

    /**
     * 규칙 배열에서 min/max 숫자를 뽑습니다.
     *
     * @param  array<int, mixed>|string  $rules  검증 규칙
     * @return array{min: string|null, max: string|null} 경계값
     */
    private function boundsOf(array|string $rules): array
    {
        $flat = is_string($rules) ? explode('|', $rules) : $rules;
        $found = ['min' => null, 'max' => null];

        foreach ($flat as $rule) {
            if (! is_string($rule)) {
                continue;
            }

            foreach (['min', 'max'] as $bound) {
                if (str_starts_with($rule, $bound.':')) {
                    $found[$bound] = substr($rule, strlen($bound) + 1);
                }
            }

            // 'integer|min:1|max:1024' 처럼 한 문자열에 묶인 형태
            if (str_contains($rule, '|')) {
                foreach (explode('|', $rule) as $part) {
                    foreach (['min', 'max'] as $bound) {
                        if (str_starts_with($part, $bound.':')) {
                            $found[$bound] = substr($part, strlen($bound) + 1);
                        }
                    }
                }
            }
        }

        return $found;
    }

    /**
     * 저장 규칙의 경계값이 한계값 설정과 일치하는지 확인합니다.
     */
    #[Test]
    public function save_rules_use_the_shared_limit_values(): void
    {
        $limits = config('core.settings_limits');
        $rules = (new SaveSettingsRequest)->rules();

        foreach ($this->boundKeys() as $settingKey => $limitPrefix) {
            $this->assertArrayHasKey($settingKey, $rules, "{$settingKey} 규칙이 없습니다.");

            $bounds = $this->boundsOf($rules[$settingKey]);

            foreach (['min', 'max'] as $bound) {
                $limitKey = "{$limitPrefix}_{$bound}";

                if (! array_key_exists($limitKey, $limits)) {
                    continue;
                }

                $this->assertSame(
                    (string) $limits[$limitKey],
                    $bounds[$bound],
                    "{$settingKey} 의 {$bound} 이 한계값 설정({$limitKey})과 다릅니다 — "
                    .'규칙이 리터럴로 되돌아갔는지 확인하세요.'
                );
            }
        }
    }

    /**
     * 한계값 설정이 바뀌면 규칙도 따라 바뀌는지 확인합니다.
     *
     * 배선이 풀려 리터럴로 되돌아가면 이 테스트가 먼저 깨진다.
     */
    #[Test]
    public function changing_a_limit_changes_the_rule(): void
    {
        config(['core.settings_limits.drivers_log_days_max' => 999]);

        $bounds = $this->boundsOf((new SaveSettingsRequest)->rules()['drivers.log_days']);

        $this->assertSame('999', $bounds['max']);
    }

    /**
     * 모든 설정 필드가 오류 안내에 쓸 항목명을 갖는지 확인합니다.
     *
     * 항목명이 없으면 Laravel 이 키를 그대로 풀어 쓴다 — 화면에는
     * "security.password min length 필드는 64를 초과하면 안 됩니다" 처럼 내부 식별자가 노출된다.
     * 규칙에 필드를 추가하면서 항목명을 빠뜨리는 것이 이 결함의 유일한 경로이므로,
     * 규칙 키 전수와 항목명 전수를 직접 대조한다.
     */
    #[Test]
    public function every_setting_field_has_a_translated_attribute_label(): void
    {
        $request = new SaveSettingsRequest;
        $attributes = $request->attributes();

        // 배열 항목 규칙(`foo.*`)은 부모 키의 항목명을 물려받으므로 대조 대상에서 제외한다.
        $fieldKeys = array_values(array_filter(
            array_keys($request->rules()),
            fn (string $key) => str_contains($key, '.') && ! str_contains($key, '*')
        ));

        $missing = array_values(array_filter(
            $fieldKeys,
            fn (string $key) => ! array_key_exists($key, $attributes)
        ));

        $this->assertSame(
            [],
            $missing,
            '항목명이 없는 설정 필드가 있습니다 — 저장 실패 안내에 내부 식별자가 그대로 노출됩니다: '
            .implode(', ', $missing)
        );
    }

    /**
     * 항목명이 실제 번역 문장으로 해석되는지 확인합니다.
     *
     * `attributes()` 에 키를 채워도 lang 파일에 항목이 없으면 `validation.attributes.xxx`
     * 라는 키 문자열이 그대로 화면에 나온다 — 내부 식별자 노출이라는 결과는 같다.
     */
    #[Test]
    public function attribute_labels_resolve_to_real_translations(): void
    {
        $unresolved = [];

        foreach ((new SaveSettingsRequest)->attributes() as $field => $label) {
            if (str_starts_with((string) $label, 'validation.attributes.')) {
                $unresolved[] = $field;
            }
        }

        $this->assertSame(
            [],
            $unresolved,
            '번역이 없어 키 문자열이 그대로 노출되는 항목명이 있습니다: '.implode(', ', $unresolved)
        );
    }
}
