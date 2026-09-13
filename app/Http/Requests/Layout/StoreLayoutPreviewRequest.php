<?php

namespace App\Http\Requests\Layout;

use App\Contracts\Repositories\LayoutRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\LayoutSourceType;
use App\Extension\HookManager;
use App\Http\Requests\Layout\Concerns\CompilesMarkupContent;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 레이아웃 미리보기 생성 요청 검증
 *
 * 편집 중인 레이아웃 content를 임시 저장하여 미리보기를 생성합니다.
 */
class StoreLayoutPreviewRequest extends FormRequest
{
    use CompilesMarkupContent;

    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 전 데이터 전처리
     *
     * content가 JSON 문자열로 전송된 경우 배열로 변환합니다.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        // `format=markup` 이면 마크업을 프로덕션 컴파일해 JSON 배열로 바꾼다 — 그 뒤는 기존 규칙 그대로.
        $this->compileMarkupContentIfRequested();

        $content = $this->input('content');

        if (is_string($content)) {
            $decoded = json_decode($content, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $this->merge(['content' => $decoded]);
            }
        }
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            // 본문 형식 — `json`(기본) | `markup`(`.g7.html` 문자열, 검증 전 컴파일)
            'format' => ['nullable', 'string', 'in:json,markup'],
            'content' => ['required', 'array'],
        ];

        // 모듈/플러그인이 validation rules 를 동적으로 추가할 수 있도록 훅 제공 (확장 미리보기 요청과 같은 규약)
        return HookManager::applyFilters('core.layout.store_preview_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'content.required' => __('validation.layout.content.required'),
            'content.array' => __('validation.layout.content.array'),
        ];
    }

    /**
     * 마크업 컴파일 문맥 — 라우트의 템플릿·레이아웃 행에서 소속 정보를 얻는다.
     *
     * @return array{0: string|null, 1: string|null, 2: string|null, 3: string}
     */
    protected function markupCompileTarget(): array
    {
        $templateName = (string) $this->route('templateName');
        $name = (string) $this->route('name');
        $template = $templateName !== '' ? app(TemplateRepositoryInterface::class)->findByIdentifier($templateName) : null;

        if ($template === null) {
            return [null, null, null, "{$name}.g7.html"];
        }

        $layout = app(LayoutRepositoryInterface::class)->findByName($template->id, $name);
        $sourceKind = $layout?->source_type instanceof LayoutSourceType ? $layout->source_type->value : null;

        return [$templateName, $sourceKind, $layout?->source_identifier ?? $templateName, "{$name}.g7.html"];
    }
}
