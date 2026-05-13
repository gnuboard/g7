<?php

namespace App\Http\Requests\Admin;

use App\Extension\HookManager;
use App\Extension\PluginManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 플러그인 설정 업데이트 요청 검증
 *
 * 플러그인의 설정 스키마를 기반으로 동적으로 유효성 검사 규칙을 생성합니다.
 */
class UpdatePluginSettingsRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인합니다.
     *
     * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙을 반환합니다.
     *
     * 플러그인의 설정 스키마를 기반으로 동적으로 규칙을 생성합니다.
     *
     * @return array 검증 규칙 배열
     */
    public function rules(): array
    {
        $identifier = $this->route('identifier');
        $pluginManager = app(PluginManager::class);
        $plugin = $pluginManager->getPlugin($identifier);

        if (! $plugin) {
            return [];
        }

        $schema = $plugin->getSettingsSchema();
        $rules = [];

        foreach ($schema as $field => $config) {
            $fieldRules = [];

            // 필수 여부
            if ($config['required'] ?? false) {
                $fieldRules[] = 'required';
            } else {
                $fieldRules[] = 'nullable';
            }

            // 타입별 규칙 추가
            $type = $config['type'] ?? 'string';

            switch ($type) {
                case 'string':
                    $fieldRules[] = 'string';
                    if (isset($config['max'])) {
                        $fieldRules[] = 'max:'.$config['max'];
                    }
                    if (isset($config['min'])) {
                        $fieldRules[] = 'min:'.$config['min'];
                    }
                    break;

                case 'integer':
                    $fieldRules[] = 'integer';
                    if (isset($config['min'])) {
                        $fieldRules[] = 'min:'.$config['min'];
                    }
                    if (isset($config['max'])) {
                        $fieldRules[] = 'max:'.$config['max'];
                    }
                    break;

                case 'boolean':
                    $fieldRules[] = 'boolean';
                    break;

                case 'enum':
                    $options = $config['options'] ?? [];
                    if (! empty($options)) {
                        $fieldRules[] = 'in:'.implode(',', $options);
                    }
                    break;

                case 'url':
                    $fieldRules[] = 'url';
                    break;

                case 'email':
                    $fieldRules[] = 'email';
                    break;

                case 'array':
                    $fieldRules[] = 'array';
                    break;
            }

            $rules[$field] = $fieldRules;
        }

        return HookManager::applyFilters('core.plugin_settings.update_rules', $rules, $identifier);
    }

    /**
     * 검증 에러 메시지를 반환합니다.
     *
     * @return array 에러 메시지 배열
     */
    public function messages(): array
    {
        return [
            'required' => __('validation.required'),
            'string' => __('validation.string'),
            'integer' => __('validation.integer'),
            'boolean' => __('validation.boolean'),
            'in' => __('validation.in'),
            'url' => __('validation.url'),
            'email' => __('validation.email'),
            'array' => __('validation.array'),
            'max' => __('validation.max'),
            'min' => __('validation.min'),
        ];
    }
}
