<?php

namespace Plugins\Sirsoft\Sso\Http\Requests\Admin;

use App\Models\Role;
use App\Rules\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * IdP 수정 요청 — 보낸 칸만 바꾼다(부분 저장). 키와 프로토콜은 바꿀 수 없다.
 *
 * 비밀값(secrets)에 가린 값이 그대로 오면 기존 값을 유지하고, null 이면 지운다.
 *
 * @since 1.0.0
 */
class IdpUpdateRequest extends FormRequest
{
    /**
     * 권한은 라우트 미들웨어가 본다.
     *
     * @return bool 허용 여부
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 규칙
     */
    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'label' => ['sometimes', 'array', new TranslatableField(100)],
            'button_visible' => ['sometimes', 'boolean'],
            'button_style' => ['sometimes', 'nullable', 'array'],
            'button_style.bg' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
            'button_style.fg' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
            'button_style.border' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
            'config' => ['sometimes', 'array'],
            'secrets' => ['sometimes', 'array'],
            'secrets.*' => ['nullable', 'string', 'max:20000'],
            'owned_domains' => ['sometimes', 'array', 'max:50'],
            'owned_domains.*' => ['string', 'max:191', 'regex:/^[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?)+$/'],
            'enforce_owned_domains' => ['sometimes', 'boolean'],
            'provisioning_mode' => ['sometimes', Rule::in(['jit', 'preregistered'])],
            'link_methods' => ['sometimes', 'array'],
            'link_methods.domain_auto' => ['sometimes', 'boolean'],
            'link_methods.verify' => ['sometimes', 'boolean'],
            'link_methods.admin' => ['sometimes', 'boolean'],
            'require_consent' => ['sometimes', 'boolean'],
            'identity_policy' => ['sometimes', Rule::in(['skip', 'apply'])],
            'attribute_map' => ['sometimes', 'array', 'max:50'],
            'attribute_map.*.field' => ['required_with:attribute_map', 'string', 'regex:/^(name|nickname|mobile|phone|language|email|attr:[a-z][a-z0-9_]{0,48})$/'],
            'attribute_map.*.source' => ['required_with:attribute_map', 'string', 'max:191'],
            'attribute_map.*.mode' => ['required_with:attribute_map', Rule::in(['sync_lock', 'sync', 'first_only', 'ignore'])],
            'role_mapping' => ['sometimes', 'array', 'max:200'],
            'role_mapping.*.group' => ['required_with:role_mapping', 'string', 'max:255'],
            'role_mapping.*.role' => ['required_with:role_mapping', 'string', 'max:100', Rule::exists(Role::class, 'identifier')],
            'role_sync_mode' => ['sometimes', Rule::in(['managed_only', 'authoritative', 'first_only'])],
            'group_source' => ['sometimes', Rule::in(['assertion', 'scim', 'ldap'])],
            'two_factor_mode' => ['sometimes', Rule::in(['always_skip', 'skip_if_mfa', 'always'])],
            'logout_mode' => ['sometimes', Rule::in(['local', 'rp', 'both'])],
            'idp_initiated' => ['sometimes', 'boolean'],
            'allow_local_password' => ['sometimes', 'boolean'],
            'max_session_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10080'],
            'deprovision' => ['sometimes', 'array'],
            'deprovision.scim' => ['sometimes', 'boolean'],
            'deprovision.scim_match' => ['sometimes', Rule::in(['userName', 'externalId'])],
            'deprovision.periodic' => ['sometimes', 'boolean'],
            'deprovision.periodic_minutes' => ['sometimes', 'integer', 'min:5', 'max:10080'],
            'deprovision.on_login' => ['sometimes', 'boolean'],
            'deprovision.withdraw_after_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
        ];
    }

    /**
     * 검증 항목 라벨을 반환합니다.
     *
     * @return array<string, string> 필드 => 라벨
     */
    public function attributes(): array
    {
        $p = 'sirsoft-sso::admin.attributes.';

        return [
            'enabled' => __($p.'enabled'),
            'sort_order' => __($p.'sort_order'),
            'label' => __($p.'label'),
            'button_visible' => __($p.'button_visible'),
            'button_style' => __($p.'button_style'),
            'button_style.bg' => __($p.'button_style_bg'),
            'button_style.fg' => __($p.'button_style_fg'),
            'button_style.border' => __($p.'button_style_border'),
            'config' => __($p.'config'),
            'secrets' => __($p.'secrets'),
            'secrets.*' => __($p.'secrets'),
            'owned_domains' => __($p.'owned_domains'),
            'owned_domains.*' => __($p.'owned_domains'),
            'enforce_owned_domains' => __($p.'enforce_owned_domains'),
            'provisioning_mode' => __($p.'provisioning_mode'),
            'link_methods' => __($p.'link_methods'),
            'link_methods.domain_auto' => __($p.'link_methods_domain_auto'),
            'link_methods.verify' => __($p.'link_methods_verify'),
            'link_methods.admin' => __($p.'link_methods_admin'),
            'require_consent' => __($p.'require_consent'),
            'identity_policy' => __($p.'identity_policy'),
            'attribute_map' => __($p.'attribute_map'),
            'attribute_map.*.field' => __($p.'attribute_map_field'),
            'attribute_map.*.source' => __($p.'attribute_map_source'),
            'attribute_map.*.mode' => __($p.'attribute_map_mode'),
            'role_mapping' => __($p.'role_mapping'),
            'role_mapping.*.group' => __($p.'role_mapping_group'),
            'role_mapping.*.role' => __($p.'role_mapping_role'),
            'role_sync_mode' => __($p.'role_sync_mode'),
            'group_source' => __($p.'group_source'),
            'two_factor_mode' => __($p.'two_factor_mode'),
            'logout_mode' => __($p.'logout_mode'),
            'idp_initiated' => __($p.'idp_initiated'),
            'allow_local_password' => __($p.'allow_local_password'),
            'max_session_minutes' => __($p.'max_session_minutes'),
            'deprovision' => __($p.'deprovision'),
            'deprovision.scim' => __($p.'deprovision_scim'),
            'deprovision.scim_match' => __($p.'deprovision_scim_match'),
            'deprovision.periodic' => __($p.'deprovision_periodic'),
            'deprovision.periodic_minutes' => __($p.'deprovision_periodic_minutes'),
            'deprovision.on_login' => __($p.'deprovision_on_login'),
            'deprovision.withdraw_after_days' => __($p.'deprovision_withdraw_after_days'),
        ];
    }
}
