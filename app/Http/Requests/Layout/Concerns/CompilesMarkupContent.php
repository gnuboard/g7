<?php

namespace App\Http\Requests\Layout\Concerns;

use App\Helpers\ResponseHelper;
use App\Services\LayoutMarkupService;
use App\Support\LayoutMarkup\CompileError;
use App\Support\LayoutMarkup\LintWarning;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * `format=markup` 요청의 `content`(`.g7.html` 문자열)를 프로덕션 컴파일해 JSON 배열로 바꾸는 FormRequest 공용 트레이트.
 *
 * 코드 편집기의 정본은 HTML 버퍼다. 저장·미리보기 요청은 `format=markup` 으로 마크업을
 * 보내고, 이 트레이트가 `prepareForValidation()` 첫 줄에서 그것을 JSON 으로 컴파일해 `content` 에 `merge` 한다.
 * 그 뒤는 **기존 규칙 그대로**다 — 강검증·`ValidLayoutStructure` 등은 손대지 않는다(JSON 으로 보내는 경로와
 * 같은 검증을 같은 순서로 지난다).
 *
 * 컴파일 오류는 `content` 가 문자열인 채로 남아 `array` 규칙에서 검증이 실패하고, {@see failedValidation()} 이
 * 그 422 응답의 `errors.compile_error` 와 최상위 `compile_error` 에 `{code, message, line, column}` 를 실어
 * 편집기가 마커를 찍게 한다(화면은 `{{error.errors?.compile_error}}` 로 읽는다).
 * lint 경고는 {@see markupWarnings()} 로 컨트롤러가 응답 `data.markup_warnings` 에 싣는다.
 */
trait CompilesMarkupContent
{
    /**
     * 컴파일 오류 (없으면 null).
     */
    private ?CompileError $markupCompileError = null;

    /** 컴파일한 마크업 원문 — 보안 위반의 소스 위치를 찾는 데 쓴다(7.1.0 P16) */
    private ?string $markupSource = null;

    /**
     * lint 경고 (컴파일 성공 시).
     *
     * @var array<int, LintWarning>
     */
    private array $markupLintWarnings = [];

    /**
     * 컴파일 문맥 — `[템플릿 식별자, source_kind, identifier, 표시용 파일명, 저장본?]`. 템플릿을 찾지 못하면 첫 원소가 null.
     *
     * 다섯째 원소(선택)는 편집 중인 행의 저장본이다. 주면 그 안에 이미 있던 미등록 템플릿 컴포넌트를 그 개수까지
     * 허용한다(7.0 이 무차별 등록한 행을 저장할 수 있게, #712). 새 파일 만들기는 저장본이 없으므로 주지 않는다.
     *
     * @return array{0: string|null, 1: string|null, 2: string|null, 3: string, 4?: array<string, mixed>|null} 컴파일 문맥 튜플
     */
    abstract protected function markupCompileTarget(): array;

    /**
     * `format=markup` 이면 `content` 마크업을 프로덕션 컴파일해 JSON 배열로 `merge` 합니다.
     *
     * @return void 반환값 없음
     */
    protected function compileMarkupContentIfRequested(): void
    {
        if ($this->input('format') !== 'markup') {
            return;
        }

        $markup = $this->input('content');

        if (! is_string($markup)) {
            return;
        }

        $target = $this->markupCompileTarget();
        [$templateId, $sourceKind, $identifier, $label] = $target;
        $baseline = isset($target[4]) && is_array($target[4]) ? $target[4] : null;

        // 템플릿·행이 없으면 컨트롤러가 404 를 내야 한다 — 여기서는 content 를 문자열로 두어 검증이 막게 한다.
        if ($templateId === null) {
            return;
        }

        try {
            $result = app(LayoutMarkupService::class)->toJson($markup, $templateId, $sourceKind, $identifier, $label, $baseline);
        } catch (CompileError $error) {
            $this->markupCompileError = $error;

            return;
        }

        $this->markupSource = $markup;
        $this->merge(['content' => $result->json]);
        $this->markupLintWarnings = $result->warnings;
    }

    /**
     * 컴파일이 낸 lint 경고를 API 페이로드로 돌려줍니다 (컨트롤러가 `data.markup_warnings` 에 싣는다).
     *
     * @return array<int, array{code: string, message: string, line: int, column: int}> lint 경고 페이로드 목록
     */
    public function markupWarnings(): array
    {
        return app(LayoutMarkupService::class)->describeWarnings($this->markupLintWarnings);
    }

    /**
     * 검증 실패 — 원인이 마크업 컴파일 오류면 422 응답에 `compile_error` 를 덧붙입니다.
     *
     * @param  Validator  $validator  실패한 검증기
     * @return void 반환값 없음
     *
     * @throws HttpResponseException 컴파일 오류인 경우
     */
    protected function failedValidation(Validator $validator): void
    {
        if ($this->markupCompileError === null) {
            // 컴파일은 됐는데 저장 게이트(보안 규칙)가 막았으면, 위반 값이 적힌 소스 자리를 compile_error 로 싣는다 —
            // 편집기 오류 패널·마커가 그 줄을 가리킨다(7.1.0 P16). 보안 위반이 아니면 종전 그대로.
            $content = $this->input('content');
            $described = $this->markupSource !== null && is_array($content)
                ? app(LayoutMarkupService::class)->describeContentViolation($this->markupSource, $content)
                : null;

            if ($described === null) {
                parent::failedValidation($validator);

                return;
            }

            $response = ResponseHelper::error(
                $this->markupCompileFailedMessageKey(),
                422,
                [...$validator->errors()->toArray(), 'compile_error' => $described],
                ['detail' => $described['message']],
            );
            $payload = $response->getData(true);
            $payload['compile_error'] = $described;
            $response->setData($payload);

            throw new HttpResponseException($response);
        }

        $service = app(LayoutMarkupService::class);
        $detail = $this->markupCompileError->getDetail();
        $described = $service->describeError($this->markupCompileError);

        // 구조화 오류는 **`errors` 안**에 싣는다. 엔진의 api-call 실패 문맥이 레이아웃에 노출하는 것은
        // `error.message`·`error.errors`·`error.status` 뿐이라, 최상위에만 두면 화면이 좌표를 읽지 못한다
        // (`{{error.data}}` 는 규정 금지 — `layout-binding-error-shape`).
        $response = ResponseHelper::error(
            $this->markupCompileFailedMessageKey(),
            422,
            ['content' => [$detail], 'compile_error' => $described],
            ['detail' => $detail],
        );

        // 최상위 `compile_error` 는 API 소비처(문서·SDK)용으로 그대로 둔다.
        $payload = $response->getData(true);
        $payload['compile_error'] = $described;
        $response->setData($payload);

        throw new HttpResponseException($response);
    }

    /**
     * 컴파일 실패 메시지 키 — 레이아웃·확장 요청이 각자 자기 도메인 키를 돌려준다.
     *
     * @return string 다국어 메시지 키
     */
    protected function markupCompileFailedMessageKey(): string
    {
        return 'validation.layout.content.markup_compile_failed';
    }
}
