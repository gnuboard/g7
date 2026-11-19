<?php

namespace Plugins\Sirsoft\Sso\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 공개 IdP 목록 요청 (`GET /api/plugins/sirsoft-sso/idps`).
 *
 * @since 1.0.0
 */
class PublicIdpListRequest extends FormRequest
{
    /**
     * 공개 목록이다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙.
     *
     * @return array<string, array<int, string>> 규칙
     */
    public function rules(): array
    {
        return [
            'guard' => ['nullable', 'string', 'in:user,admin'],
            'redirect' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * 칸 이름.
     *
     * @return array<string, string> 칸 => 이름
     */
    public function attributes(): array
    {
        return [
            'guard' => __('sirsoft-sso::validation.attributes.guard'),
            'redirect' => __('sirsoft-sso::validation.attributes.redirect'),
        ];
    }

    /**
     * 로그인 경로 (기본 user).
     *
     * @return string user 또는 admin
     */
    public function guardName(): string
    {
        return $this->query('guard') === 'admin' ? 'admin' : 'user';
    }

    /**
     * 로그인 뒤 복귀 경로.
     *
     * @return string|null 경로
     */
    public function redirectPath(): ?string
    {
        $redirect = $this->query('redirect');

        return is_string($redirect) ? $redirect : null;
    }
}
