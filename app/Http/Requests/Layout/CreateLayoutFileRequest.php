<?php

namespace App\Http\Requests\Layout;

use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Extension\HookManager;
use App\Http\Requests\Layout\Concerns\CompilesMarkupContent;
use App\Rules\NoExternalUrls;
use App\Rules\SafeLayoutExpressions;
use App\Rules\ValidLayoutStructure;
use App\Services\LayoutFileService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 레이아웃 **파일 생성** 요청 검증.
 *
 * 본문은 `.g7.html` 마크업으로 받는다 — 코드 편집기의 정본이 마크업이기 때문이다(#476).
 * 본문을 비워 보내면 {@see LayoutFileService::defaultSkeleton()} 의 최소 골격을 대신 컴파일한다.
 * 빈 문자열을 그대로 저장하면 컴파일 오류라 파일을 만들자마자 빨간 줄이 뜬다.
 *
 * 컴파일 뒤에는 **저장 경로와 같은 검증을 같은 순서로** 지난다
 * ({@see ValidLayoutStructure} · {@see SafeLayoutExpressions} · {@see NoExternalUrls}) —
 * 생성 경로만 약하면 저장으로는 막히는 것이 생성으로는 들어온다.
 */
class CreateLayoutFileRequest extends FormRequest
{
    use CompilesMarkupContent;

    /**
     * 권한은 라우트의 permission 미들웨어가 판정합니다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 전 전처리 — 본문이 비었으면 기본 골격을 채우고, 마크업을 JSON 으로 컴파일합니다.
     *
     * @return void 반환값 없음
     */
    protected function prepareForValidation(): void
    {
        $name = trim((string) $this->input('name', ''));
        $content = $this->input('content');

        $this->merge(['name' => $name]);

        // 이름이 규칙을 벗어나면 골격을 만들 수 없다 — 그대로 두어 `regex` 규칙이 422 로 세우게 한다.
        if (! is_string($content) || trim($content) === '') {
            if ($name === '' || ! preg_match(LayoutFileService::NAME_PATTERN, $name)) {
                return;
            }

            $content = app(LayoutFileService::class)->defaultSkeleton($name);
        }

        $this->merge(['content' => $content, 'format' => 'markup']);
        $this->compileMarkupContentIfRequested();
    }

    /**
     * 검증 규칙.
     *
     * @return array<string, mixed> 규칙 배열
     */
    public function rules(): array
    {
        $rules = [
            'name' => [
                'required',
                'string',
                'max:255',
                // 세그먼트는 영숫자·`_`·`-` 만. 선행·연속 슬래시와 `.`·`..` 세그먼트가 구조적으로 불가능해진다.
                'regex:'.LayoutFileService::NAME_PATTERN,
            ],

            'format' => ['nullable', 'string', 'in:markup'],

            // 컴파일이 끝난 JSON — 실패하면 문자열로 남아 `array` 에서 걸리고,
            // `CompilesMarkupContent::failedValidation()` 이 422 에 `compile_error` 를 싣는다.
            // 이름이 틀리면 골격을 만들지 못해 본문이 비므로 본문은 보지 않는다 — 「본문 필수」가 덤으로 붙으면
            // 이름 오류 하나를 알리는 화면에 원인과 무관한 「외 1개 오류」가 붙는다.
            'content' => [
                Rule::excludeIf(fn (): bool => ! $this->hasValidName()),
                'required',
                'array',
                new ValidLayoutStructure,
                new SafeLayoutExpressions,
                new NoExternalUrls,
            ],
        ];

        return HookManager::applyFilters('core.layout.create_validation_rules', $rules, $this);
    }

    /**
     * 이름이 파일 이름 규칙을 지키는지 판정합니다.
     *
     * @return bool 규칙을 지키는 비어 있지 않은 이름이면 true
     */
    private function hasValidName(): bool
    {
        $name = (string) $this->input('name', '');

        return $name !== '' && preg_match(LayoutFileService::NAME_PATTERN, $name) === 1;
    }

    /**
     * 검증 메시지.
     *
     * @return array<string, string> 속성별 메시지
     */
    public function messages(): array
    {
        return [
            'name.regex' => __('layouts.errors.invalid_name', ['name' => (string) $this->input('name', '')]),
        ];
    }

    /**
     * 마크업 컴파일 문맥 — 새 파일이므로 소스 종류는 언제나 템플릿이다.
     *
     * @return array{0: string|null, 1: string|null, 2: string|null, 3: string} [식별자, source_kind, identifier, 표시 파일명]
     */
    protected function markupCompileTarget(): array
    {
        $templateName = (string) $this->route('templateName');
        $name = (string) $this->input('name', 'layout');
        $template = $templateName !== ''
            ? app(TemplateRepositoryInterface::class)->findByIdentifier($templateName)
            : null;

        if ($template === null) {
            return [null, null, null, "{$name}.g7.html"];
        }

        return [$templateName, 'template', $templateName, "{$name}.g7.html"];
    }
}
