<?php

namespace Plugins\Sirsoft\SocialLogin\Http\Requests\Admin;

use App\Rules\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Plugins\Sirsoft\SocialLogin\Enums\EmailMissingPolicy;
use Plugins\Sirsoft\SocialLogin\Enums\EmailTrust;

/**
 * 관리자 제공자 설정 저장 요청.
 *
 * 모든 키는 선택이다 — 보낸 키만 바뀐다. 비밀값(`client_secret`·`admin_key`)은 마스크(`••••••••`)를 그대로 보내면 유지,
 * 빈 문자열이면 삭제다(서비스가 해석). 키·드라이버·종류는 받지 않는다.
 *
 * @since 1.0.0
 */
class UpdateProviderRequest extends FormRequest
{
    /** 주소 칸 최대 길이 (컬럼 500) */
    protected const URL_MAX = 500;

    /**
     * 권한은 라우트 미들웨어(`settings.update`)가 본다.
     *
     * @return bool 허용 여부
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 설정 화면이 보내는 한 줄 범위(`scopes_text`)를 배열 `scopes` 로 바꿉니다.
     *
     * 화면 표현식은 정규식을 쓸 수 없어 나누기를 서버가 맡는다 — 공백·쉼표로 나누고 빈 조각은 버린다. 화면은 편집 칸을 통째로 보내
     * 옛 `scopes` 배열도 함께 오므로 문자열이 있으면 문자열이 이긴다(문자열 없이 배열만 오면 그 배열).
     *
     * @return void 반환값 없음
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('scopes_text')) {
            return;
        }

        $text = $this->input('scopes_text');
        $this->request->remove('scopes_text');
        $this->json?->remove('scopes_text');

        if (is_string($text) || $text === null) {
            $this->merge(['scopes' => array_values(array_filter(preg_split('/[\s,]+/', (string) $text) ?: [], fn ($part) => $part !== ''))]);
        }
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
            'label' => ['sometimes', 'array', new TranslatableField(maxLength: 100)],
            'client_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'client_secret' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'admin_key' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'scopes' => ['sometimes', 'nullable', 'array', 'max:20'],
            'scopes.*' => ['string', 'max:100', 'regex:/^[^\s]+$/'],
            'signup_allowed' => ['sometimes', 'boolean'],
            'email_missing_policy' => ['sometimes', Rule::enum(EmailMissingPolicy::class)],
            'email_trust' => ['sometimes', Rule::enum(EmailTrust::class)],
            'discovery_url' => ['sometimes', 'nullable', 'url:https', 'max:'.self::URL_MAX],
            'authorize_url' => ['sometimes', 'nullable', 'url:https', 'max:'.self::URL_MAX],
            'token_url' => ['sometimes', 'nullable', 'url:https', 'max:'.self::URL_MAX],
            'userinfo_url' => ['sometimes', 'nullable', 'url:https', 'max:'.self::URL_MAX],
            'jwks_url' => ['sometimes', 'nullable', 'url:https', 'max:'.self::URL_MAX],
            'issuer' => ['sometimes', 'nullable', 'string', 'max:255'],
            'graph_api_version' => ['sometimes', 'nullable', 'string', 'regex:/^v\d{1,3}\.\d{1,2}$/'],
            'button_style' => ['sometimes', 'nullable', 'array'],
            'button_style.bg' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{3,8}$/'],
            'button_style.fg' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{3,8}$/'],
            'button_style.border' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{3,8}$/'],
            'webhook_ip_allowlist_enabled' => ['sometimes', 'boolean'],
            'webhook_app_id' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9]{1,20}$/'],
        ];
    }

    /**
     * 검증 항목 라벨을 반환합니다.
     *
     * @return array<string, string> 필드 => 라벨
     */
    public function attributes(): array
    {
        $p = 'sirsoft-social_login::validation.attributes.provider.';

        return [
            'key' => __($p.'key'),
            'enabled' => __($p.'enabled'),
            'label' => __($p.'label'),
            'label.*' => __($p.'label'),
            'client_id' => __($p.'client_id'),
            'client_secret' => __($p.'client_secret'),
            'admin_key' => __($p.'admin_key'),
            'scopes' => __($p.'scopes'),
            'scopes.*' => __($p.'scopes'),
            'signup_allowed' => __($p.'signup_allowed'),
            'email_missing_policy' => __($p.'email_missing_policy'),
            'email_trust' => __($p.'email_trust'),
            'discovery_url' => __($p.'discovery_url'),
            'authorize_url' => __($p.'authorize_url'),
            'token_url' => __($p.'token_url'),
            'userinfo_url' => __($p.'userinfo_url'),
            'jwks_url' => __($p.'jwks_url'),
            'issuer' => __($p.'issuer'),
            'graph_api_version' => __($p.'graph_api_version'),
            'button_style' => __($p.'button_style'),
            'button_style.bg' => __($p.'button_style_bg'),
            'button_style.fg' => __($p.'button_style_fg'),
            'button_style.border' => __($p.'button_style_border'),
            'webhook_ip_allowlist_enabled' => __($p.'webhook_ip_allowlist_enabled'),
            'webhook_app_id' => __($p.'webhook_app_id'),
        ];
    }
}
