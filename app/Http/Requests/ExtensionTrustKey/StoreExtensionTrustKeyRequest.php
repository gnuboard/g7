<?php

namespace App\Http\Requests\ExtensionTrustKey;

use App\Extension\HookManager;
use App\Extension\Integrity\BuiltinTrustKeys;
use App\Extension\Integrity\PackageSignatureFormat;
use App\Services\ExtensionTrustKeyService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * 확장 신뢰 키(제작자 공개키) 추가 요청.
 *
 * vendor 범위는 하나 이상 필수이고 `sirsoft` 는 내장 키 전용이라 받지 않는다(서명자 이름도 같다). 본인 재확인 입력
 * (`password` 또는 `verification_token`)을 같은 요청에 싣는다 — 확인은 컨트롤러가 서비스로 한다.
 *
 * @since 7.2.0
 */
class StoreExtensionTrustKeyRequest extends FormRequest
{
    /**
     * 권한 판정은 라우트 미들웨어가 담당합니다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 전 값 정규화 — vendor 범위를 쉼표 문자열로 보내도 배열로 받는다.
     *
     * @return void 반환값 없음
     */
    protected function prepareForValidation(): void
    {
        $scope = $this->input('vendor_scope');

        if (is_string($scope)) {
            $this->merge(['vendor_scope' => array_values(array_filter(array_map('trim', explode(',', $scope)), fn ($v) => $v !== ''))]);
        }
    }

    /**
     * 검증 규칙.
     *
     * @return array<string, mixed> 규칙
     */
    public function rules(): array
    {
        $rules = self::keyRules() + [
            // 비밀번호 없는 관리자(외부 인증 전용)는 아무것도 보내지 않는다 — 컨트롤러가 본인인증(428)을 요구하고, 엔진이 받은
            // 인증 토큰(verification_token)을 붙여 다시 보낸다. 비밀번호가 있는 관리자가 비우면 컨트롤러가 422 로 답한다
            'password' => ['nullable', 'string'],
            'verification_token' => ['nullable', 'string', 'max:128'],
        ];

        return HookManager::applyFilters('core.extension_trust_keys.store_validation_rules', $rules, $this);
    }

    /**
     * 키 입력 규칙 (Artisan `extension:trust-key add` 도 같은 규칙을 쓴다).
     *
     * @return array<string, mixed> 규칙
     */
    public static function keyRules(): array
    {
        return [
            'public_key' => ['required', 'string', 'max:64', function (string $attribute, mixed $value, Closure $fail) {
                if (! is_string($value) || PackageSignatureFormat::decodePublicKey($value) === null) {
                    $fail(__('extension_integrity.validation.public_key_invalid'));
                }
            }],
            'label' => ['required', 'string', 'regex:'.PackageSignatureFormat::LABEL_PATTERN, function (string $attribute, mixed $value, Closure $fail) {
                if (is_string($value) && str_starts_with(strtolower($value), BuiltinTrustKeys::RESERVED_VENDOR)) {
                    $fail(__('extension_integrity.validation.label_reserved'));
                }
            }],
            'vendor_scope' => ['required', 'array', 'min:1', 'max:50'],
            'vendor_scope.*' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9][a-z0-9_]*$/', function (string $attribute, mixed $value, Closure $fail) {
                if (is_string($value) && strtolower($value) === BuiltinTrustKeys::RESERVED_VENDOR) {
                    $fail(__('extension_integrity.validation.vendor_reserved'));
                }
            }],
        ];
    }

    /**
     * 키 입력 메시지.
     *
     * @return array<string, string> 메시지
     */
    public static function keyMessages(): array
    {
        return [
            'public_key.required' => __('extension_integrity.validation.public_key_required'),
            'label.required' => __('extension_integrity.validation.label_required'),
            'label.regex' => __('extension_integrity.validation.label_invalid'),
            'vendor_scope.required' => __('extension_integrity.validation.vendor_scope_required'),
            'vendor_scope.min' => __('extension_integrity.validation.vendor_scope_required'),
            'vendor_scope.*.regex' => __('extension_integrity.validation.vendor_invalid'),
        ];
    }

    /**
     * 규칙 통과 뒤 이미 등록된 키·내장 키와 겹치는지 확인합니다.
     *
     * @return array<int, Closure> 추가 검사
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $conflict = app(ExtensionTrustKeyService::class)->conflictFor((string) $this->input('label'), (string) $this->input('public_key'));
                if ($conflict !== null) {
                    $validator->errors()->add('public_key', $conflict);
                }
            },
        ];
    }

    /**
     * 검증 메시지.
     *
     * @return array<string, string> 메시지
     */
    public function messages(): array
    {
        return self::keyMessages() + [
            'password.string' => __('extension_integrity.validation.password_required'),
        ];
    }

    /**
     * 검증 오류 문구의 항목 이름.
     *
     * @return array<string, string> 항목 이름
     */
    public function attributes(): array
    {
        return [
            'public_key' => __('extension_integrity.attributes.public_key'),
            'label' => __('extension_integrity.attributes.label'),
            'vendor_scope' => __('extension_integrity.attributes.vendor_scope'),
            'vendor_scope.*' => __('extension_integrity.attributes.vendor_scope'),
            'password' => __('extension_integrity.attributes.password'),
        ];
    }
}
