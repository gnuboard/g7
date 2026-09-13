<?php

namespace App\Services;

use App\Exceptions\TemplateOperationException;
use App\Http\Resources\LayoutResource;
use App\Support\LayoutMarkup\CompileError;
use App\Support\LayoutMarkup\CompileResult;
use App\Support\LayoutMarkup\ComponentRegistry;
use App\Support\LayoutMarkup\EmptyMap;
use App\Support\LayoutMarkup\LayoutMarkupCompiler;
use App\Support\LayoutMarkup\LayoutMarkupDecompiler;
use App\Support\LayoutMarkup\LayoutMarkupRoundTrip;
use App\Support\LayoutMarkup\LintWarning;
use App\Support\LayoutMarkup\MarkupCompileContextResolver;

/**
 * 설치본(DB 행) 레이아웃의 JSON ↔ `.g7.html` 변환 서비스 — 코드 편집기·점검 커맨드의 공용 진입점.
 *
 * DB 는 JSON 만 유지한다(PO 결정 C1). 편집기가 행을 **열 때** JSON→마크업으로 되돌리고, **저장·미리보기
 * 때** 마크업→JSON 으로 프로덕션 컴파일한다. 두 방향 모두 설치 경로와 같은 클래스
 * ({@see LayoutMarkupDecompiler}·{@see LayoutMarkupCompiler})를 타며, 표(`components.json`)와 문맥은
 * 붙어 있는 템플릿에서 고른다 — 파일 경로가 없는 문서라 {@see MarkupCompileContextResolver::registryForTemplate()}·
 * {@see MarkupCompileContextResolver::contextForInstalled()} 를 쓴다.
 *
 * 컴파일 오류(`CompileError`)는 그대로 던진다 — 어디를 고쳐야 하는지(라인·컬럼·코드)를 싣고 있어 호출부가
 * 422 페이로드로 옮긴다({@see describeError()}).
 */
class LayoutMarkupService
{
    private readonly MarkupCompileContextResolver $resolver;

    /**
     * 템플릿별 레지스트리 캐시 (요청 단위 — 점검 커맨드가 수백 행을 돌 때 매니페스트를 매번 읽지 않는다).
     *
     * @var array<string, ComponentRegistry|null>
     */
    private array $registries = [];

    /**
     * 변환 서비스를 만듭니다.
     *
     * @param  MarkupCompileContextResolver|null  $resolver  표·문맥 해석기 (테스트가 대역을 주입할 수 있게 열어 둔다)
     */
    public function __construct(?MarkupCompileContextResolver $resolver = null)
    {
        $this->resolver = $resolver ?? new MarkupCompileContextResolver;
    }

    /**
     * JSON 레이아웃을 `.g7.html` 마크업으로 되돌립니다.
     *
     * `escapes` 는 첫 급부 문법이 표현하지 못해 `<json>` 탈출구로 나간 키 목록이다 — 편집기가 안내하고 점검
     * 커맨드가 예산으로 센다.
     *
     * @param  array<string, mixed>  $json  레이아웃 JSON (DB `content`)
     * @param  string  $templateId  붙어 있는 템플릿 식별자
     * @return array{markup: string, escapes: array<int, string>}
     *
     * @throws TemplateOperationException 템플릿 컴포넌트 매니페스트를 찾지 못하면
     */
    public function toMarkup(array $json, string $templateId): array
    {
        $markup = (new LayoutMarkupDecompiler($this->registry($templateId)))->decompile($json);

        return ['markup' => $markup, 'escapes' => LayoutMarkupRoundTrip::escapedKeys($markup)];
    }

    /**
     * 저장된 `content` JSON 문자열을 **빈 map 을 보존해** 디코드합니다 (편집기 표시용).
     *
     * `json_decode($s, true)` 는 `{}` 와 `[]` 를 똑같이 PHP `[]` 로 만든다. 그래서 행을 배열로 읽어
     * 역변환하면 작성자가 적은 `{}` 가 편집기에 `[]` 로 **틀리게 보이고**, 그 상태로 저장하면 DB 의
     * `{}` 가 실제로 `[]` 가 된다(실측: `sirsoft-board.admin_board_settings` 의 `fallback.data.meta`).
     * 그 값이 API 본문·질의로 실려 가면 서버 검증이 대상을 찾지 못한다 — {@see EmptyMap} 이 있는 이유다.
     *
     * 여기서 빈 객체를 표식으로 바꿔 두면 역변환기의 `json_encode` 가 그대로 `{}` 를 찍으므로
     * 역변환기는 이 타입을 알 필요가 없다.
     *
     * @param  string  $rawJson  DB 에 저장된 원본 JSON 문자열
     * @return array<string, mixed> 빈 map 이 {@see EmptyMap} 으로 남은 배열
     */
    public function decodeStoredContent(string $rawJson): array
    {
        $decoded = json_decode($rawJson, false);

        return is_object($decoded) || is_array($decoded) ? (array) $this->preserveEmptyMaps($decoded) : [];
    }

    /**
     * `stdClass` 트리를 배열로 낮추되 **빈 객체만** 표식으로 남깁니다.
     *
     * @param  mixed  $value  디코드된 값
     * @return mixed 배열·표식·스칼라
     */
    private function preserveEmptyMaps(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $props = get_object_vars($value);

            if ($props === []) {
                return EmptyMap::instance();
            }

            return array_map(fn (mixed $v): mixed => $this->preserveEmptyMaps($v), $props);
        }

        if (is_array($value)) {
            return array_map(fn (mixed $v): mixed => $this->preserveEmptyMaps($v), $value);
        }

        return $value;
    }

    /**
     * `.g7.html` 마크업을 프로덕션 컴파일해 검증된 JSON + lint 경고를 돌려줍니다.
     *
     * @param  string  $markup  `.g7.html` 소스
     * @param  string  $templateId  붙어 있는 템플릿 식별자 (표·핸들러·nesting 규칙)
     * @param  string|null  $sourceKind  행의 `source_type` (`template`·`module`·`plugin`·null)
     * @param  string|null  $identifier  행의 `source_identifier` (없으면 템플릿 식별자)
     * @param  string  $label  오류·경고 메시지의 파일명 자리에 들어갈 표시용 이름 (`{layout_name}.g7.html`). 실제 파일은 없다
     * @return CompileResult 산출 JSON + lint 경고
     *
     * @throws CompileError 토큰화·트리·문서·강검증 어느 단계든 위반 시
     * @throws TemplateOperationException 템플릿 컴포넌트 매니페스트를 찾지 못하면
     */
    public function toJson(string $markup, string $templateId, ?string $sourceKind, ?string $identifier, string $label): CompileResult
    {
        return (new LayoutMarkupCompiler($this->registry($templateId)))->compile(
            $markup,
            $label,
            $this->resolver->contextForInstalled($templateId, $sourceKind, $identifier ?? $templateId),
        );
    }

    /**
     * 산출 JSON 을 편집기 JSON 탭이 쓰는 문자열로 인코딩합니다.
     *
     * 플래그는 {@see LayoutResource} 의 `content` 와 **같아야 한다** — 편집기는 열 때
     * 그 필드를, 타이핑 뒤에는 이 값을 같은 탭에 보여주므로 인코딩이 다르면 첫 변환에서 들여쓰기·이스케이프가
     * 통째로 달라 보인다.
     *
     * 굽기 직렬화기(`App\Support\LayoutMarkup\LayoutJsonWriter`)를 쓰지 않는다 — 그것은 짝 `.json` 을
     * **디스크에 쓰는** 개발자 커맨드 전용 표면이고(D38), 설치·서빙·API 경로가 그 표면을 참조하지 않는다는 등식을
     * `LayoutDecompileSyncTest` 가 잠근다.
     *
     * @param  array<string, mixed>  $json  산출 JSON
     * @return string pretty-print JSON 문자열
     */
    public function encodeJson(array $json): string
    {
        return (string) json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * 컴파일 오류를 API 페이로드로 옮깁니다.
     *
     * @param  CompileError  $error  컴파일 오류
     * @return array{code: string, message: string, line: int, column: int}
     */
    public function describeError(CompileError $error): array
    {
        return [
            'code' => $error->getErrorCode()->value,
            'message' => $error->getDetail(),
            'line' => $error->getSourceLine(),
            'column' => $error->getSourceColumn(),
        ];
    }

    /**
     * lint 경고 목록을 API 페이로드로 옮깁니다.
     *
     * @param  array<int, LintWarning>  $warnings  lint 경고
     * @return array<int, array{code: string, message: string, line: int, column: int}>
     */
    public function describeWarnings(array $warnings): array
    {
        return array_values(array_map(static fn (LintWarning $warning): array => [
            'code' => $warning->getCode()->value,
            'message' => $warning->getDetail(),
            'line' => $warning->getLine(),
            'column' => $warning->getColumn(),
        ], $warnings));
    }

    /**
     * 템플릿의 컴포넌트 레지스트리를 돌려줍니다 (요청 단위 캐시).
     *
     * @param  string  $templateId  템플릿 식별자
     * @return ComponentRegistry 레지스트리
     *
     * @throws TemplateOperationException 매니페스트가 없으면
     */
    private function registry(string $templateId): ComponentRegistry
    {
        if (! array_key_exists($templateId, $this->registries)) {
            $this->registries[$templateId] = $this->resolver->registryForTemplate($templateId);
        }

        return $this->registries[$templateId]
            ?? throw new TemplateOperationException('exceptions.layout_markup.registry_not_found');
    }
}
