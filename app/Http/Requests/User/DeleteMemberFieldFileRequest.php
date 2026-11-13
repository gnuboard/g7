<?php

namespace App\Http\Requests\User;

use App\Extension\HookManager;
use App\Models\FieldDefinition;
use App\Services\MemberFieldFileService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 회원 파일·이미지 항목의 파일 삭제 요청 (본인, 관리자 공용).
 *
 * @since 7.2.0
 */
class DeleteMemberFieldFileRequest extends FormRequest
{
    /**
     * 요청 권한 — 본인 경로는 인증, 관리자 경로는 라우트의 권한 미들웨어가 확인한다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청 대상 파일 항목을 반환합니다.
     *
     * @return FieldDefinition|null 파일 항목 (없거나 이 경로에서 다룰 수 없으면 null)
     */
    public function targetField(): ?FieldDefinition
    {
        $surface = $this->route('user') !== null ? MemberFieldFileService::SURFACE_ADMIN : MemberFieldFileService::SURFACE_SELF;

        return app(MemberFieldFileService::class)->findField((string) $this->route('key'), $surface);
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, mixed>> 검증 규칙
     */
    public function rules(): array
    {
        $rules = [
            'attachment_id' => ['required', 'integer', 'min:1'],
        ];

        return HookManager::applyFilters('core.user.field_file_delete_validation_rules', $rules, $this);
    }

    /**
     * 검증 속성명.
     *
     * @return array<string, string> 속성 => 이름
     */
    public function attributes(): array
    {
        return [
            'attachment_id' => __('fields.attributes.attachment_id'),
        ];
    }
}
