<?php

namespace Plugins\Sirsoft\SocialLogin\Http\Requests\Admin;

use App\Rules\LocaleRequiredTranslatable;
use Plugins\Sirsoft\SocialLogin\Services\ProviderAdminService;

/**
 * 일반 OIDC 제공자 추가 요청.
 *
 * 키·기본 언어 이름·클라이언트 ID·비밀값이 필수다. 주소는 discovery 주소 하나, 또는 인가·토큰·서명 키 주소와 발급자를
 * 모두 직접 넣는다. 예약 키(내장·`mock_` 접두)와 중복·한도는 서비스가 판정한다(`key_taken`·`custom_limit_reached`).
 *
 * @since 1.0.0
 */
class CreateProviderRequest extends UpdateProviderRequest
{
    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 규칙
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'key' => ['required', 'string', 'regex:'.ProviderAdminService::KEY_PATTERN],
            'label' => ['required', 'array', new LocaleRequiredTranslatable(maxLength: 100)],
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => ['required', 'string', 'max:2000'],
            'discovery_url' => ['nullable', 'url:https', 'max:'.self::URL_MAX, 'required_without_all:authorize_url,token_url,jwks_url,issuer'],
            'authorize_url' => ['nullable', 'url:https', 'max:'.self::URL_MAX, 'required_without:discovery_url'],
            'token_url' => ['nullable', 'url:https', 'max:'.self::URL_MAX, 'required_without:discovery_url'],
            'jwks_url' => ['nullable', 'url:https', 'max:'.self::URL_MAX, 'required_without:discovery_url'],
            'issuer' => ['nullable', 'string', 'max:255', 'required_without:discovery_url'],
        ]);
    }

    /**
     * 검증 항목 라벨을 반환합니다 — 추가 요청이 다시 정의한 칸을 이 파일에도 적는다.
     *
     * @return array<string, string> 필드 => 라벨
     */
    public function attributes(): array
    {
        $p = 'sirsoft-social_login::validation.attributes.provider.';

        return array_merge(parent::attributes(), [
            'key' => __($p.'key'),
            'label' => __($p.'label'),
            'client_id' => __($p.'client_id'),
            'client_secret' => __($p.'client_secret'),
            'discovery_url' => __($p.'discovery_url'),
            'authorize_url' => __($p.'authorize_url'),
            'token_url' => __($p.'token_url'),
            'jwks_url' => __($p.'jwks_url'),
            'issuer' => __($p.'issuer'),
        ]);
    }
}
