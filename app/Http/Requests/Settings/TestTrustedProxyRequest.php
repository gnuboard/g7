<?php

namespace App\Http\Requests\Settings;

use App\Extension\HookManager;
use App\Rules\ValidTrustedProxyAddress;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 신뢰 프록시 사전 검사 요청 검증
 *
 * 저장 전에 확인하는 것이 목적이므로 검사 대상은 저장된 설정이 아니라 이번에 제출된 값입니다.
 * 검증 강도는 저장 경로(SaveSettingsRequest)와 같게 둡니다 — 검사만 통과하고 저장에서
 * 거부되거나 그 반대가 되면 운영자가 어느 쪽을 믿어야 할지 알 수 없습니다.
 *
 * 빈 배열은 「신뢰하지 않음」이라는 정상 선택이므로 `required` 가 아니라 `present` 입니다 —
 * 키 자체가 빠진 요청(프론트 버그)과 빈 목록(운영자의 선택)을 구분합니다.
 *
 * @since 7.1.0
 */
class TestTrustedProxyRequest extends FormRequest
{
    /**
     * 권한 검사는 permission 미들웨어가 담당합니다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'trusted_proxies' => ['present', 'array', 'max:50'],
            'trusted_proxies.*' => ['string', 'max:60', new ValidTrustedProxyAddress],
        ];

        return HookManager::applyFilters('core.settings.test_trusted_proxy_validation_rules', $rules, $this);
    }

    /**
     * 검증 실패 메시지를 반환합니다.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'trusted_proxies.present' => __('validation.settings.trusted_proxies_present'),
            'trusted_proxies.array' => __('validation.settings.trusted_proxies_array'),
            'trusted_proxies.max' => __('validation.settings.trusted_proxies_max'),
            'trusted_proxies.*.string' => __('validation.settings.trusted_proxy_address_invalid'),
            'trusted_proxies.*.max' => __('validation.settings.trusted_proxy_address_max'),
        ];
    }

    /**
     * 검증 속성명을 반환합니다.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'trusted_proxies' => __('validation.attributes.trusted_proxies'),
        ];
    }
}
