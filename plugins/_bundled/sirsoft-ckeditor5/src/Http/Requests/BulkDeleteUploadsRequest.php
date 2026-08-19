<?php

namespace Plugins\Sirsoft\Ckeditor5\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Plugins\Sirsoft\Ckeditor5\Models\Ckeditor5ImageUpload;

/**
 * 관리자 업로드 이미지 일괄 삭제 요청 검증
 */
class BulkDeleteUploadsRequest extends FormRequest
{
    /** 한 번에 삭제할 수 있는 최대 건수 */
    public const MAX_IDS = 100;

    /**
     * 요청 권한 — 라우트 permission 미들웨어가 담당하므로 true 고정.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, mixed>> 검증 규칙
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_IDS],
            'ids.*' => ['integer', Rule::exists(Ckeditor5ImageUpload::class, 'id')],
        ];
    }

    /**
     * 검증 메시지를 반환합니다.
     *
     * @return array<string, string> 메시지 배열
     */
    public function messages(): array
    {
        return [
            'ids.required' => __('sirsoft-ckeditor5::messages.uploads.ids_required'),
            'ids.min' => __('sirsoft-ckeditor5::messages.uploads.ids_required'),
            'ids.*.exists' => __('sirsoft-ckeditor5::messages.uploads.ids_invalid'),
            'ids.*.integer' => __('sirsoft-ckeditor5::messages.uploads.ids_invalid'),
        ];
    }

    /**
     * 삭제 대상 ID 목록을 반환합니다.
     *
     * @return array<int, int> ID 목록
     */
    public function ids(): array
    {
        /** @var array<int, int|string> $ids */
        $ids = $this->validated('ids') ?? [];

        return array_values(array_unique(array_map('intval', $ids)));
    }
}
