<?php

namespace Plugins\Sirsoft\Sso\Http\Requests\Scim;

/**
 * SCIM PatchOp 요청 (PATCH `/Users/{id}`, `/Groups/{id}`).
 *
 * 연산 이름은 IdP 마다 대소문자가 달라(`replace`, `Replace`) 서비스가 소문자로 바꿔 판정한다.
 *
 * @since 1.0.0
 */
class ScimPatchRequest extends ScimRequest
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
            'Operations' => ['required', 'array', 'min:1', 'max:200'],
            'Operations.*.op' => ['required', 'string', 'max:10'],
            'Operations.*.path' => ['nullable', 'string', 'max:500'],
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
            'Operations' => $this->label('Operations'),
            'Operations.*.op' => $this->label('Operations.*.op'),
            'Operations.*.path' => $this->label('Operations.*.path'),
        ];
    }

    /**
     * 검증 실패 때의 SCIM 오류 종류.
     *
     * @return string scimType
     */
    protected function scimTypeOnFailure(): string
    {
        return 'invalidSyntax';
    }
}
