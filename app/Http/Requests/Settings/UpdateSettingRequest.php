<?php

namespace App\Http\Requests\Settings;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Extension\HookManager;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;

/**
 * 단건 설정 저장 요청 검증
 *
 * 벌크 저장(`SaveSettingsRequest`)은 키마다 타입 규칙을 갖지만, 이 경로는 키를 URL 로 받아
 * 값 하나만 싣는다. 그래서 값의 형태는 `config/settings/defaults.json` 의 기본값에서 도출한다
 * — 기본값이 그 설정의 타입 선언이기 때문이다.
 */
class UpdateSettingRequest extends FormRequest
{
    /**
     * 문자열 값의 최대 길이
     */
    private const MAX_STRING_LENGTH = 1000;

    /**
     * 배열 값의 JSON 직렬화 최대 길이
     */
    private const MAX_ARRAY_JSON_LENGTH = 5000;

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool 권한 체크는 permission 미들웨어 체인이 담당하므로 항상 true 반환
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 전 입력 값을 설정의 선언 타입으로 정규화합니다.
     *
     * 폼 전송(`application/x-www-form-urlencoded`)은 모든 값을 문자열로 실어 보낸다.
     * 정규화 없이 저장하면 boolean 설정에 `"1"`, 정수 설정에 `"3600"` 같은 문자열이 남고,
     * 그 값을 읽는 쪽은 타입 비교(`=== true`)에서 조용히 어긋난다.
     *
     * 해석할 수 없는 값(예: boolean 설정에 `"maybe"`)은 건드리지 않는다 — null 이나 false 로
     * 바꿔 두면 오타 입력이 "정상 저장" 으로 통과한다. 그 판정은 규칙이 맡는다.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('value')) {
            return;
        }

        $default = $this->declaredDefault();
        $value = $this->input('value');

        // 문자열 설정을 비우면 빈 문자열로 남긴다. `ConvertEmptyStringsToNull` 미들웨어가
        // 이미 `''` 를 null 로 바꿔 두므로, 여기서 되돌리지 않으면 선언 타입이 문자열인
        // 설정에 null 이 저장되어 그 값을 읽는 쪽이 기본값(`''`)과 다른 형태를 받는다.
        if (is_string($default) && $value === null) {
            $this->merge(['value' => '']);

            return;
        }

        // 선언 타입이 문자열/배열이거나 기본값이 없으면 원본 유지
        if (! is_bool($default) && ! is_int($default) && ! is_float($default)) {
            return;
        }

        // 비-문자열 설정에서 빈 문자열은 "값을 지운다" 는 뜻이다
        if ($value === '') {
            $this->merge(['value' => null]);

            return;
        }

        if (! is_string($value)) {
            return;
        }

        if (is_bool($default)) {
            $casted = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($casted !== null) {
                $this->merge(['value' => $casted]);
            }

            return;
        }

        if (is_int($default) && preg_match('/^-?\d+$/', $value) === 1) {
            $this->merge(['value' => (int) $value]);

            return;
        }

        if (is_float($default) && is_numeric($value)) {
            $this->merge(['value' => (float) $value]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            // `required` 가 아니라 `present` 다 — 빈 문자열/null 은 "이 설정을 비운다" 는 정상
            // 입력이고, 이를 거부하면 운영자가 한 번 넣은 값을 화면에서 지울 수 없다.
            // 대신 `value` 키 자체가 빠진 요청은 그대로 거부한다.
            'value' => ['present', 'nullable', $this->valueShapeRule()],
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.settings.update_validation_rules', $rules, $this);
    }

    /**
     * 값의 형태(타입·길이)를 검증하는 규칙을 반환합니다.
     *
     * @return \Closure 검증 클로저
     */
    private function valueShapeRule(): \Closure
    {
        return function (string $attribute, mixed $value, callable $fail): void {
            if ($value === null) {
                return;
            }

            $default = $this->declaredDefault();

            // 선언 타입과 다른 값은 거부한다 — 정규화가 해석하지 못한 입력이 여기로 온다.
            if (is_bool($default) && ! is_bool($value)) {
                $fail(__('validation.setting.value.boolean'));

                return;
            }

            if (is_int($default) && ! is_int($value)) {
                $fail(__('validation.setting.value.integer'));

                return;
            }

            if (is_float($default) && ! is_int($value) && ! is_float($value)) {
                $fail(__('validation.setting.value.numeric'));

                return;
            }

            if (is_string($value)) {
                if (mb_strlen($value) > self::MAX_STRING_LENGTH) {
                    $fail(__('validation.setting.value.max', ['max' => self::MAX_STRING_LENGTH]));
                }

                return;
            }

            if (is_array($value)) {
                if (mb_strlen((string) json_encode($value)) > self::MAX_ARRAY_JSON_LENGTH) {
                    $fail(__('validation.setting.value.array_max', ['max' => self::MAX_ARRAY_JSON_LENGTH]));
                }

                return;
            }

            if (! is_bool($value) && ! is_int($value) && ! is_float($value)) {
                $fail(__('validation.setting.value.type'));
            }
        };
    }

    /**
     * 저장 대상 키의 선언 기본값을 반환합니다.
     *
     * 반환값의 타입이 곧 그 설정의 선언 타입입니다. 선언이 없으면 null 을 반환하며,
     * 이 경우 타입 강제 없이 형태 검증만 수행합니다 (확장이 추가한 키 등).
     *
     * @return mixed 선언 기본값 또는 null
     */
    private function declaredDefault(): mixed
    {
        $key = $this->route('key');

        if (! is_string($key) || $key === '') {
            return null;
        }

        return Arr::get(app(ConfigRepositoryInterface::class)->getDefaults(), $key);
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'value.present' => __('validation.setting.value.present'),
        ];
    }
}
