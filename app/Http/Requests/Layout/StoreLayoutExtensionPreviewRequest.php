<?php

namespace App\Http\Requests\Layout;

use App\Contracts\Repositories\LayoutExtensionRepositoryInterface;
use App\Extension\HookManager;
use App\Http\Requests\Layout\Concerns\CompilesMarkupContent;
use App\Rules\LayoutContentGate;
use App\Services\LayoutMarkupService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 레이아웃 확장 미리보기 생성 요청 검증
 *
 * 편집 중인 확장 content를 임시 저장하여, 대표 레이아웃에 적용한 미리보기를 생성합니다.
 */
class StoreLayoutExtensionPreviewRequest extends FormRequest
{
    use CompilesMarkupContent;

    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
     *
     * @return bool 항상 true (권한은 미들웨어 체인에서 처리)
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
     * preview_layout: 미리보기에 사용할 대표 레이아웃명.
     * - overlay 타입은 target_layout 자체가 대표 레이아웃이므로 생략 가능.
     * - extension_point 타입은 프론트가 선택한 레이아웃명을 전달.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            // 본문 형식 — `json`(기본) | `markup`(`.g7.html` 문자열, 검증 전 컴파일)
            'format' => ['nullable', 'string', 'in:json,markup'],
            // 미리보기도 저장 경로와 같은 보안 게이트를 거친다 (KVE-2026-2350 — 게이트 없는 저장 경로였다)
            'content' => ['required', 'array', ...LayoutContentGate::rules()],
            'preview_layout' => ['nullable', 'string', 'max:255'],
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.layout_extension.store_preview_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'content.required' => __('validation.layout_extension.content.required'),
            'content.array' => __('validation.layout_extension.content.array'),
            'preview_layout.string' => __('validation.layout_extension.preview_layout.string'),
            'preview_layout.max' => __('validation.layout_extension.preview_layout.max'),
        ];
    }

    /**
     * 마크업 컴파일 문맥 — 라우트의 템플릿·확장 행에서 소속 정보를 얻는다.
     *
     * @return array{0: string|null, 1: string|null, 2: string|null, 3: string}
     */
    protected function markupCompileTarget(): array
    {
        $templateName = (string) $this->route('templateName');
        $extension = app(LayoutExtensionRepositoryInterface::class)->findById((int) $this->route('extensionId'));

        if ($templateName === '' || $extension === null) {
            return [null, null, null, 'extension.g7.html'];
        }

        // 다섯째 원소 — 저장본(미등록 템플릿 컴포넌트 허용 기준, #712)
        $baseline = app(LayoutMarkupService::class)->decodeStoredContent((string) $extension->getRawOriginal('content'));

        return [$templateName, $extension->source_type?->value, $extension->source_identifier, "{$extension->target_name}.g7.html", $baseline];
    }

    /**
     * 확장 요청은 확장 도메인 메시지 키를 쓴다.
     *
     * @return string 다국어 메시지 키
     */
    protected function markupCompileFailedMessageKey(): string
    {
        return 'validation.layout_extension.content.markup_compile_failed';
    }
}
