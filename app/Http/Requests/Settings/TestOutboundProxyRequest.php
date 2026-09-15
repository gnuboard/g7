<?php

namespace App\Http\Requests\Settings;

use App\Extension\HookManager;
use App\Rules\ValidOutboundProxyUrl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 아웃바운드 프록시 연결 테스트 요청 검증
 *
 * 저장 전에 확인하는 것이 목적이므로 검사 대상은 저장된 설정이 아니라 이번에 제출된 값입니다.
 * 검증 강도는 저장 경로(SaveSettingsRequest)와 같게 둡니다 — 테스트만 통과하고 저장에서
 * 거부되거나 그 반대가 되면 운영자가 어느 쪽을 믿어야 할지 알 수 없습니다.
 *
 * @since 7.0.8
 */
class TestOutboundProxyRequest extends FormRequest
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
            'outbound_proxy' => ['required', 'string', 'max:500', new ValidOutboundProxyUrl],
            'outbound_proxy_bypass' => ['nullable', 'array'],
            'outbound_proxy_bypass.*' => ['string', 'max:255'],
        ];

        return HookManager::applyFilters('core.settings.test_outbound_proxy_validation_rules', $rules, $this);
    }

    /**
     * 검증 실패 메시지를 반환합니다.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'outbound_proxy.required' => __('validation.settings.outbound_proxy_required'),
            'outbound_proxy.string' => __('validation.settings.outbound_proxy_string'),
            'outbound_proxy.max' => __('validation.settings.outbound_proxy_max'),
            'outbound_proxy_bypass.array' => __('validation.settings.outbound_proxy_bypass_array'),
            'outbound_proxy_bypass.*.string' => __('validation.settings.outbound_proxy_bypass_item_string'),
            'outbound_proxy_bypass.*.max' => __('validation.settings.outbound_proxy_bypass_item_max'),
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
            'outbound_proxy' => __('validation.attributes.outbound_proxy'),
            'outbound_proxy_bypass' => __('validation.attributes.outbound_proxy_bypass'),
        ];
    }
}
