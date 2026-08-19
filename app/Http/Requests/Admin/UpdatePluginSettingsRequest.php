<?php

namespace App\Http\Requests\Admin;

// audit:allow api-doc-coverage 요청 파라미터·응답 구조 무변경 — 검증 안내의 항목 표시명 로케일 정합만 수정 (attributes 우선순위)

use App\Extension\HookManager;
use App\Extension\PluginManager;
use App\Services\DriverRegistryService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Lang;

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

        // 공개 자산 디스크는 스키마 타입만으로 검증할 수 없다 — 선택지가 코어 3종 +
        // 플러그인이 훅으로 등록한 디스크라 런타임에만 확정되기 때문이다.
        // PluginSettingsController 가 카탈로그를 부착하는 게이트(스키마에 이 키를 선언한
        // 플러그인)와 동일한 조건에서 검증도 걸어, "선택지를 내려준 표면" 과 "값을 받는
        // 표면" 의 강도를 맞춘다. 이렇게 두면 이 키를 선언하는 플러그인은 각자 훅을
        // 구독하지 않아도 코어 환경설정과 같은 422 를 얻는다.
        if (array_key_exists('public_asset_disk', $schema)) {
            $rules['public_asset_disk'][] = function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value === null || $value === '') {
                    return;
                }

                if (! app(DriverRegistryService::class)->isDriverAvailable('public_asset', $value)) {
                    $fail(__('validation.settings.public_asset_disk_invalid'));
                }
            };
        }

        // 표준 이름(`core.{대상}.{동작}_validation_rules`)으로 발행하되, 이미 공개돼 구독 중일 수 있는
        // 구 이름(`core.plugin_settings.update_rules`)도 함께 발행한다 — 구 이름을 구독하는 제3자
        // 확장이 조용히 멈추지 않도록 한다. 구 이름은 구독자가 있을 때만 1회 경고가 남는다.
        //
        // 3번째 인자로 이번 요청의 입력값을 함께 넘긴다. 확장이 "현재 입력한 모드에 따라
        // 다른 필드를 필수로 만드는" 조건부 규칙을 만들려면 입력값이 필요한데, 그것이 없어
        // 확장이 request() 를 직접 들여다보던 것을 없애기 위함이다.
        // 기존 2인자 구독자는 그대로 동작한다(초과 인자는 무시된다).
        return HookManager::applyFiltersWithLegacyName(
            'core.plugin_settings.update_validation_rules',
            'core.plugin_settings.update_rules',
            $rules,
            $identifier,
            $this->all(),
        );
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

    /**
     * 검증 오류에 쓸 필드 표시명을 반환합니다.
     *
     * 설정 스키마의 `label` 을 그대로 씁니다. 이 값이 없으면 Laravel 이 원시 키를 풀어쓴
     * 이름("unused image retention days")을 그대로 노출하는데, 같은 화면의 입력 라벨은
     * 한국어인 상태라 운영자에게는 어느 항목을 고치라는 말인지 닿지 않습니다.
     *
     * @return array<string, string> 필드 => 표시명
     */
    public function attributes(): array
    {
        $plugin = app(PluginManager::class)->getPlugin($this->route('identifier'));

        if (! $plugin) {
            return [];
        }

        $locale = app()->getLocale();
        $fallback = config('app.fallback_locale', 'en');
        $attributes = [];

        foreach ($plugin->getSettingsSchema() as $field => $config) {
            // 플러그인이 요청 시점에 번역선(validation.attributes.{field})을 현재 로케일로
            // 등록했다면 그것이 우선이다. 스키마 label 은 보통 ko/en 만 들고 있어, 여기서
            // 폴백 라벨을 세우면 언어팩/리스너가 제공한 로케일별 라벨(ja 등)을 customAttributes
            // 우선순위로 덮는다 — 검증 안내의 항목 이름만 다른 언어로 남는 회귀가 된다.
            $translationKey = "validation.attributes.{$field}";
            if (Lang::has($translationKey, $locale, false)) {
                $attributes[$field] = Lang::get($translationKey, [], $locale);

                continue;
            }

            $label = $config['label'] ?? null;

            if (is_array($label)) {
                $label = $label[$locale] ?? $label[$fallback] ?? reset($label);
            }

            if (is_string($label) && $label !== '') {
                $attributes[$field] = $label;
            }
        }

        return $attributes;
    }
}
