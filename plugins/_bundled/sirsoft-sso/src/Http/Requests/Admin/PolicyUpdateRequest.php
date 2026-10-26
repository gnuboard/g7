<?php

namespace Plugins\Sirsoft\Sso\Http\Requests\Admin;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * SSO 정책 저장 요청 — 보낸 칸만 바꾼다. 최고관리자 전용 칸과 비상 계정 0명, IdP 없는 사이트 전체 강제는 서비스가 거절한다(W5, D26).
 *
 * @since 1.0.0
 */
class PolicyUpdateRequest extends FormRequest
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
            'enforce_site' => ['sometimes', 'boolean'],
            'enforce_roles' => ['sometimes', 'array', 'max:50'],
            'enforce_roles.*' => ['string', 'max:100', Rule::exists(Role::class, 'identifier')],
            'enforce_linked' => ['sometimes', 'boolean'],
            'emergency_user_ids' => ['sometimes', 'array', 'max:20'],
            'emergency_user_ids.*' => ['integer', Rule::exists(User::class, 'id')],
            'auto_redirect_idp' => ['sometimes', 'nullable', 'string', 'regex:/^[a-z][a-z0-9_]{1,49}$/'],
            'session_max_minutes' => ['sometimes', 'integer', 'min:0', 'max:10080'],
            'discovery_enabled' => ['sometimes', 'boolean'],
            'state_ttl_minutes' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'pending_ttl_minutes' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'attempts_retention_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'scim_retention_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'session_retention_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'failure_alert_count' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'failure_alert_window_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            'cert_expiry_notice_days' => ['sometimes', 'array', 'max:5'],
            'cert_expiry_notice_days.*' => ['integer', 'min:1', 'max:365'],
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
            'enforce_site' => __($p.'enforce_site'),
            'enforce_roles' => __($p.'enforce_roles'),
            'enforce_roles.*' => __($p.'enforce_roles'),
            'enforce_linked' => __($p.'enforce_linked'),
            'emergency_user_ids' => __($p.'emergency_user_ids'),
            'emergency_user_ids.*' => __($p.'emergency_user_ids'),
            'auto_redirect_idp' => __($p.'auto_redirect_idp'),
            'session_max_minutes' => __($p.'session_max_minutes'),
            'discovery_enabled' => __($p.'discovery_enabled'),
            'state_ttl_minutes' => __($p.'state_ttl_minutes'),
            'pending_ttl_minutes' => __($p.'pending_ttl_minutes'),
            'attempts_retention_days' => __($p.'attempts_retention_days'),
            'scim_retention_days' => __($p.'scim_retention_days'),
            'session_retention_days' => __($p.'session_retention_days'),
            'failure_alert_count' => __($p.'failure_alert_count'),
            'failure_alert_window_minutes' => __($p.'failure_alert_window_minutes'),
            'cert_expiry_notice_days' => __($p.'cert_expiry_notice_days'),
            'cert_expiry_notice_days.*' => __($p.'cert_expiry_notice_days'),
        ];
    }
}
