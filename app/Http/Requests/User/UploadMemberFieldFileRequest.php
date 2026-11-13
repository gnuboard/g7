<?php

namespace App\Http\Requests\User;

use App\Extension\HookManager;
use App\Models\FieldDefinition;
use App\Services\MemberFieldFileService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 회원 파일·이미지 항목의 파일 업로드 요청 (본인, 관리자 공용).
 *
 * 크기와 확장자 규칙은 운영자가 그 항목에 정한 값에서 온다. 항목을 찾지 못하면 컨트롤러가 404 로 응답한다.
 *
 * @since 7.2.0
 */
class UploadMemberFieldFileRequest extends FormRequest
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
        return app(MemberFieldFileService::class)->findField((string) $this->route('key'), $this->surface());
    }

    /**
     * 이 요청의 표면을 반환합니다 (관리자 경로면 관리자 표면).
     *
     * @return string 표면
     */
    public function surface(): string
    {
        return $this->route('user') !== null ? MemberFieldFileService::SURFACE_ADMIN : MemberFieldFileService::SURFACE_SELF;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, mixed>> 검증 규칙
     */
    public function rules(): array
    {
        $definition = $this->targetField();

        $rules = [
            // 항목을 찾지 못한 요청은 컨트롤러가 404 로 끝낸다 — 여기서는 파일 유무만 본다.
            'file' => $definition === null
                ? ['required', 'file']
                : app(MemberFieldFileService::class)->uploadRules($definition),
        ];

        return HookManager::applyFilters('core.user.field_file_upload_validation_rules', $rules, $this);
    }

    /**
     * 검증 속성명.
     *
     * @return array<string, string> 속성 => 이름
     */
    public function attributes(): array
    {
        return [
            'file' => $this->targetField()?->localized('name') ?? __('fields.attributes.file'),
        ];
    }
}
