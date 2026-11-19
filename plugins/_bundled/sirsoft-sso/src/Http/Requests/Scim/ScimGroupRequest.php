<?php

namespace Plugins\Sirsoft\Sso\Http\Requests\Scim;

/**
 * SCIM Group 생성, 교체 요청 (POST, PUT `/Groups`).
 *
 * @since 1.0.0
 */
class ScimGroupRequest extends ScimRequest
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
            'displayName' => ['required', 'string', 'max:255'],
            'externalId' => ['nullable', 'string', 'max:191'],
            'members' => ['nullable', 'array', 'max:5000'],
            'members.*.value' => ['nullable'],
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
            'displayName' => $this->label('displayName'),
            'externalId' => $this->label('externalId'),
            'members' => $this->label('members'),
            'members.*.value' => $this->label('members.*.value'),
        ];
    }
}
