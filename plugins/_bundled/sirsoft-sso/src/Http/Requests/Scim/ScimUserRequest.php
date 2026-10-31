<?php

namespace Plugins\Sirsoft\Sso\Http\Requests\Scim;

/**
 * SCIM User 생성, 교체 요청 (POST, PUT `/Users`).
 *
 * 대조 키(userName 또는 externalId)가 있는지는 IdP 설정에 따라 서비스가 본다 — 여기서는 모양만 확인한다.
 *
 * @since 1.0.0
 */
class ScimUserRequest extends ScimRequest
{
    /**
     * 검증 규칙.
     *
     * @return array<string, mixed> 규칙
     */
    public function rules(): array
    {
        return [
            'schemas' => ['sometimes', 'array'],
            'userName' => ['nullable', 'string', 'max:191'],
            'externalId' => ['nullable', 'string', 'max:191'],
            'displayName' => ['nullable', 'string', 'max:255'],
            'name' => ['nullable', 'array'],
            'name.formatted' => ['nullable', 'string', 'max:255'],
            'name.givenName' => ['nullable', 'string', 'max:255'],
            'name.familyName' => ['nullable', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'active' => ['nullable'],
            'emails' => ['nullable', 'array', 'max:20'],
            'emails.*.value' => ['nullable', 'string', 'max:255'],
            'phoneNumbers' => ['nullable', 'array', 'max:20'],
            'phoneNumbers.*.value' => ['nullable', 'string', 'max:30'],
        ];
    }

    /**
     * 검증 오류 문구의 칸 라벨.
     *
     * @return array<string, string> 칸 => 라벨
     */
    public function attributes(): array
    {
        return [
            'schemas' => $this->label('schemas'),
            'userName' => $this->label('userName'),
            'externalId' => $this->label('externalId'),
            'displayName' => $this->label('displayName'),
            'name' => $this->label('name'),
            'name.formatted' => $this->label('name.formatted'),
            'name.givenName' => $this->label('name.givenName'),
            'name.familyName' => $this->label('name.familyName'),
            'title' => $this->label('title'),
            'active' => $this->label('active'),
            'emails' => $this->label('emails'),
            'emails.*.value' => $this->label('emails.*.value'),
            'phoneNumbers' => $this->label('phoneNumbers'),
            'phoneNumbers.*.value' => $this->label('phoneNumbers.*.value'),
        ];
    }
}
