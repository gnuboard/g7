<?php

namespace Plugins\Sirsoft\Sso\Http\Requests\Scim;

/**
 * SCIM 목록, 단건 조회 요청 (filter, startIndex, count, excludedAttributes).
 *
 * @since 1.0.0
 */
class ScimListRequest extends ScimRequest
{
    /**
     * 검증 규칙.
     *
     * @return array<string, mixed> 규칙
     */
    public function rules(): array
    {
        return [
            'filter' => ['nullable', 'string', 'max:500'],
            'startIndex' => ['nullable', 'integer'],
            'count' => ['nullable', 'integer'],
            'excludedAttributes' => ['nullable', 'string', 'max:200'],
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
            'filter' => $this->label('filter'),
            'startIndex' => $this->label('startIndex'),
            'count' => $this->label('count'),
            'excludedAttributes' => $this->label('excludedAttributes'),
        ];
    }

    /**
     * 소속(members)을 응답에서 뺄지 확인합니다.
     *
     * @return bool `excludedAttributes` 에 members 가 있으면 true
     */
    public function excludesMembers(): bool
    {
        $excluded = array_map(static fn ($v) => mb_strtolower(trim($v)), explode(',', (string) $this->query('excludedAttributes', '')));

        return in_array('members', $excluded, true);
    }
}
