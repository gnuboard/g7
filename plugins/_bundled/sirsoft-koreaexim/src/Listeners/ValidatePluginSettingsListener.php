<?php

namespace Plugins\Sirsoft\Koreaexim\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Validation\Rule;
use Plugins\Sirsoft\Koreaexim\Enums\RateType;

class ValidatePluginSettingsListener implements HookListenerInterface
{
    /**
     * 구독 메서드와 동기 실행 계약을 선언합니다.
     *
     * @return array 처리 결과
     */
    public static function getSubscribedHooks(): array
    {
        return ['core.plugin_settings.update_validation_rules' => ['method' => 'rules', 'type' => 'filter', 'priority' => 10]];
    }

    /**
     * 등록된 처리를 실행합니다.
     *
     * @param  mixed  $args  args 입력값
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 입력 검증 규칙을 반환합니다.
     *
     * @param  array  $rules  rules 입력값
     * @param  string  $identifier  identifier 입력값
     * @param  array  $input  input 입력값
     * @return array 처리 결과
     */
    public function rules(array $rules, string $identifier, array $input = []): array
    {
        if ($identifier !== 'sirsoft-koreaexim') {
            return $rules;
        }
        $enabled = $input['auto_manage'] ?? plugin_setting($identifier, 'auto_manage', false);
        // HTTP 미들웨어가 빈 문자열을 null로 바꾸므로 미제출과 명시적 삭제를 구분합니다.
        $key = array_key_exists('auth_key', $input) ? $input['auth_key'] : plugin_setting($identifier, 'auth_key', '');
        if ($key === '••••••••') {
            $key = plugin_setting($identifier, 'auth_key', '');
        }
        $rules['auto_manage'] = ['sometimes', 'required', 'boolean', function ($attribute, $value, $fail) use ($enabled, $key) {
            if ($enabled && ! $key) {
                $fail(__('sirsoft-koreaexim::validation.auth_key_required'));
            }
        }];
        $rules['auth_key'] = [Rule::requiredIf((bool) $enabled && ! $key), 'nullable', 'string', 'max:200'];
        $rules['update_time'] = ['sometimes', 'required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/D'];
        $rules['run_days'] = ['sometimes', 'present', 'array', 'max:7'];
        $rules['run_days.*'] = ['integer', 'between:1,7', 'distinct'];
        $rules['rate_type'] = ['sometimes', 'required', Rule::enum(RateType::class)];
        foreach (['retry_max' => [0, 12], 'rate_decimal_places' => [0, 8], 'lookback_days' => [0, 14], 'history_retention_days' => [30, 3650]] as $field => [$min, $max]) {
            $rules[$field] = ['sometimes', 'required', 'integer', 'between:'.$min.','.$max];
        }
        $rules['notify_on_failure'] = ['sometimes', 'required', 'boolean'];

        return $rules;
    }
}
