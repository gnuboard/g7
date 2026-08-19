<?php

namespace App\Support\ApiDoc;

use Illuminate\Support\Str;

/**
 * API 문서 스캐폴딩 생성기
 *
 * 라우트 메타데이터 + 요청 파라미터 + 실측 응답 스키마를 표준 마크다운 포맷으로
 * 조립합니다. @generated 블록 경계로 기존 문서의 사람 서술을 보존(idempotent)합니다.
 */
class ApiDocScaffolder
{
    /**
     * @var string 생성 블록 시작 마커 접두
     */
    private const GEN_START = '<!-- @generated:start:';

    /**
     * @var string 생성 블록 종료 마커
     */
    private const GEN_END = '<!-- @generated:end -->';

    /**
     * @var string 응답 본문이 실측 산출물임을 표시하는 출처 마커
     *
     * 이 마커가 없는 응답 본문은 사람이 작성한 것으로 간주해 재생성 시 보존한다.
     * 실측 표본 1회는 사람이 아는 사실(성공·엣지 케이스)보다 좁으므로 덮어쓸 권한이 없다.
     */
    private const PROBED_BODY_MARKER = '<!-- @probed -->';

    /**
     * @var int 응답 예시의 목록(`data.data[]`) 최대 표시 항목 수
     *
     * 목록 응답 body 는 클 수 있으므로 대표 항목만 남기고 나머지는 절단 주석으로 대체한다.
     */
    private const LIST_EXAMPLE_LIMIT = 2;

    /**
     * @var int 응답 예시 한 건의 최대 크기 (pretty JSON 기준 바이트)
     *
     * 목록 절단(`data.data[]`)만으로는 카탈로그·스펙처럼 목록이 아닌 큰 응답을 막지 못한다.
     * 상한을 넘으면 절단 강도를 단계적으로 올려 이 크기 이하로 줄인다.
     */
    private const EXAMPLE_MAX_BYTES = 8192;

    /**
     * @var string 요청 예시의 토큰 마스킹 placeholder
     *
     * 실측 토큰은 임시 발급분이므로 문서에 평문 유출하지 않고 placeholder 로 마스킹한다.
     */
    private const TOKEN_PLACEHOLDER = '{YOUR_TOKEN}';

    /**
     * @var string 요청 예시의 공개 Host placeholder
     *
     * 실측 기준 URL(로컬 개발 호스트 등)을 공개 문서에 노출하지 않고 중립 placeholder 로 마스킹한다.
     * RFC 2606 예약 도메인(example.com) 기반이라 어느 환경에도 종속되지 않는다.
     */
    private const DOC_HOST = 'api.example.com';

    /**
     * @param  ResourceFieldDescriber  $fieldDescriber  accessor/computed 필드 설명기
     * @param  ParameterDescriber  $paramDescriber  공통 요청 파라미터 설명기
     */
    public function __construct(
        private readonly ResourceFieldDescriber $fieldDescriber = new ResourceFieldDescriber,
        private readonly ParameterDescriber $paramDescriber = new ParameterDescriber
    ) {}

    /**
     * 엔드포인트의 `@generated` 블록 키를 만듭니다.
     *
     * 기본은 라우트명이다. 다만 `Route::match(['get','post'], ...)->name('x')` 처럼 **하나의
     * 라우트명이 여러 메서드**를 등록하면(KG이니시스 CBT/모바일 콜백), 한 문서에 같은 키의
     * 생성 블록이 둘 생긴다. 블록 조회는 `strpos` 로 첫 출현을 잡으므로 두 번째 블록의 사람
     * 서술이 재생성마다 유실된다. 그런 라우트에 한해 메서드를 접미로 붙여 키를 고유화한다.
     *
     * 중복이 아닌 절대다수의 라우트는 키를 그대로 둔다 — 전 문서의 키를 바꾸면 기존 블록과
     * 대조가 어긋나 사람 서술이 통째로 유실되기 때문이다.
     *
     * @param  array<string, mixed>  $route  라우트 메타데이터
     * @param  bool  $methodScoped  라우트명이 문서 안에서 중복되는가 (메서드 접미 필요)
     * @return string 생성 블록 키
     */
    public function generatedKey(array $route, bool $methodScoped = false): string
    {
        $name = $route['name'] ?: $route['uri'];

        return $methodScoped ? $name.'::'.strtolower((string) $route['method']) : $name;
    }

    /**
     * 단일 엔드포인트의 마크다운 섹션을 생성합니다.
     *
     * @param  array<string, mixed>  $route  라우트 메타데이터
     * @param  array<string, mixed>  $request  FormRequest 분석 결과
     * @param  array<string, mixed>|null  $schema  실측 응답 스키마 (null=실측 안 됨)
     * @param  array<string, mixed>  $probeMeta  실측 메타 (status, skipped_reason, base_url, resolved_uri, body)
     * @param  array<string, string>  $commentMap  컬럼명 => 주석 (필드 설명 기본값)
     * @param  bool  $methodScopedKey  라우트명이 문서 안에서 중복되어 메서드 접미가 필요한가
     * @return string 마크다운 섹션
     */
    public function endpointSection(array $route, array $request, ?array $schema, array $probeMeta, array $commentMap = [], bool $methodScopedKey = false): string
    {
        $name = $route['name'] ?: '(unnamed)';
        $heading = "### {$route['method']} {$route['uri']}";
        $genKey = $this->generatedKey($route, $methodScopedKey);

        $lines = [];
        $lines[] = $heading;
        $lines[] = self::GEN_START.$genKey.' -->';
        $lines[] = "- **라우트명**: `{$name}`";
        if ($route['controller']) {
            $lines[] = "- **컨트롤러**: `{$route['controller']}@{$route['controller_method']}`";
        }
        $lines[] = '- **인증/권한**: '.$this->authLine($route);
        $lines[] = '';

        $lines[] = '**요청 파라미터**';
        $lines[] = '';
        $lines[] = $this->requestParamTable($route, $request);
        if (! empty($request['hook_filters'])) {
            $hooks = implode('`, `', $request['hook_filters']);
            $lines[] = '';
            $lines[] = "> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`{$hooks}`).";
        }
        $lines[] = '';

        $lines[] = '**요청 예시**';
        $lines[] = '';
        $lines[] = $this->requestExampleBlock($route, $request, $probeMeta);
        $lines[] = '';

        $lines[] = '**응답 필드** (`data` 내부)';
        $lines[] = '';
        $lines[] = $this->responseFieldTable($schema, $probeMeta, $commentMap);
        $lines[] = '';

        $lines[] = '**응답 예시**';
        $lines[] = '';
        $lines[] = $this->responseExampleBlock($schema, $probeMeta);
        $lines[] = '';

        $lines[] = '**에러 응답**';
        $lines[] = '';
        $lines[] = $this->errorTable($route, $request);
        $lines[] = '';
        $lines[] = self::GEN_END;
        $lines[] = '';
        $lines[] = '**설명** <!-- TODO: 이 엔드포인트의 용도·주의사항·예시 시나리오를 작성하세요 -->';
        $lines[] = '';

        return implode("\n", $lines)."\n";
    }

    /**
     * @var string 코어 README 의 확장 API 목차 생성 블록 키
     */
    private const GEN_KEY_EXTENSIONS = 'api-readme-extensions';

    /**
     * 확장 목차 섹션 헤딩. extensionIndexBlock() 이 이 헤딩까지 소유하므로,
     * 사람 서술 추출 시 여기부터는 잘라낸다 (SSoT — 두 곳에 문자열을 두지 않는다).
     */
    private const EXTENSION_INDEX_HEADING = '## 확장 API 레퍼런스';

    /**
     * @var string 코어/확장 README 의 도메인 목차 생성 블록 키
     */
    private const GEN_KEY_INDEX = 'api-readme-index';

    /**
     * 대상(코어/확장)의 API 문서 디렉토리 목차(README.md)를 생성합니다.
     *
     * 도메인 파일별 링크 + 엔드포인트 수를 표로 나열해, 처음 진입한 개발자/AI 가 이 대상의
     * API 레퍼런스 전모를 한눈에 파악하도록 한다. 표는 `@generated` 블록 안에 두어 재생성 시
     * 갱신되며, 블록 밖 사람 서술(개요·주의사항)은 보존한다. 확장 API 문서의 발견성은
     * 코어 인덱스 생성기가 이 README 를 패턴 스캔(확장명 하드코딩 없이)해 확보한다.
     *
     * 코어 README 는 프로젝트 최상위 `README.md` 의 "API 레퍼런스" 진입점이므로,
     * 도메인 목차 뒤에 확장 API 목차 블록을 함께 싣는다. `$extensions` 가 null 이면
     * 확장 블록을 생성하지 않고 기존 블록(있으면)을 그대로 보존한다 — `--scope` 축소
     * 실행이 확장 목차를 소실시키지 않도록 하기 위함이다.
     *
     * @param  string  $ownerLabel  소유 라벨 (예: '코어', '모듈 `sirsoft-ecommerce`')
     * @param  array<int, array{domain: string, file: string, count: int}>  $entries  도메인 파일 목록
     * @param  string|null  $existing  기존 README (사람 서술 보존용, 없으면 null)
     * @param  array<int, array{id: string, type: string, path: string, docs: int, endpoints: int}>|null  $extensions  확장 API 목차 항목 (코어 전용, 미갱신 시 null)
     * @return string README 마크다운
     */
    public function readmeIndex(
        string $ownerLabel,
        array $entries,
        ?string $existing = null,
        ?array $extensions = null
    ): string {
        // 줄 끝 정규화 — mergeDocument 와 같은 이유 (CRLF 체크아웃에서 줄 단위 매칭이 전부 실패).
        $existing = $existing === null ? null : $this->normalizeEol($existing);

        usort($entries, fn ($a, $b) => strcmp($a['domain'], $b['domain']));
        $total = array_sum(array_column($entries, 'count'));

        $rows = ['| 문서 | 도메인 | 엔드포인트 |', '| --- | --- | --- |'];
        foreach ($entries as $e) {
            $rows[] = "| [{$e['file']}]({$e['file']}) | `{$e['domain']}` | {$e['count']} |";
        }

        $genKey = self::GEN_KEY_INDEX;
        $lines = [];
        $lines[] = '# API 레퍼런스 문서 목차';
        $lines[] = '';
        $lines[] = "> **소유**: {$ownerLabel} · **생성**: `php artisan api:docgen` (실측 기반).";
        $lines[] = '> 아래 표는 자동 생성됩니다. 각 문서를 열면 엔드포인트별 파라미터·응답·예시를 볼 수 있습니다.';
        $lines[] = '';

        // 헤더와 목차 표 사이의 사람 개요(공통 규약 등)를 보존/삽입한다.
        $overview = $existing !== null ? $this->extractReadmeOverview($existing, $genKey) : null;
        if ($overview !== null && $overview !== '') {
            $lines[] = $overview;
            $lines[] = '';
        }

        $lines[] = self::GEN_START.$genKey.' -->';
        $lines[] = '- **문서 수**: '.count($entries)." · **엔드포인트 수**: {$total}";
        $lines[] = '';
        $lines[] = implode("\n", $rows);
        $lines[] = '';
        $lines[] = self::GEN_END;
        $lines[] = '';

        $generated = implode("\n", $lines)."\n";

        // 목차 블록 밖 사람 서술(주의사항 등)이 있으면 보존한다.
        // 확장 목차(`## 확장 API 레퍼런스`) 이후는 별도 블록이므로 삼키지 않는다.
        $prose = $existing !== null ? $this->extractReadmeTrailingProse($existing, $genKey) : null;
        if ($prose !== null && $prose !== '') {
            $generated .= "\n".$prose."\n";
        }

        $extensionBlock = $extensions !== null
            ? $this->extensionIndexBlock($extensions)
            : ($existing !== null ? $this->extractGeneratedBlock($existing, self::GEN_KEY_EXTENSIONS) : null);

        if ($extensionBlock !== null) {
            $generated = rtrim($generated)."\n\n".trim($extensionBlock)."\n";
        }

        return $generated;
    }

    /**
     * 기존 코어 README 에서 확장 API 목차 블록만 in-place 갱신합니다.
     *
     * 확장 스코프 실행(`--scope=module:...`)이라 코어 도메인 목차를 재생성하지 않는 경우에도,
     * 그 확장의 문서 수·엔드포인트 수 변동을 코어 진입점에 반영하기 위해 사용한다.
     * 코어 README 에 확장 블록이 아직 없으면 문서 끝에 새로 덧붙인다.
     *
     * @param  string  $existing  기존 코어 README
     * @param  array<int, array{id: string, type: string, path: string, docs: int, endpoints: int}>  $extensions  확장 목록
     * @return string|null 갱신된 README (변경 없으면 null)
     */
    public function refreshExtensionIndex(string $existing, array $extensions): ?string
    {
        $block = $this->extensionIndexBlock($extensions);
        $current = $this->extractGeneratedBlock($existing, self::GEN_KEY_EXTENSIONS);

        if ($current === null) {
            return rtrim($existing)."\n\n".$block;
        }

        if (trim($current) === trim($block)) {
            return null;
        }

        return str_replace($current, trim($block), $existing);
    }

    /**
     * 코어 README 의 확장 API 목차 블록을 생성합니다.
     *
     * 확장명을 하드코딩하지 않고, 커맨드가 `{modules,plugins}/_bundled/*\/docs/api/README.md`
     * 를 스캔해 넘긴 목록을 그대로 표로 만든다.
     *
     * @param  array<int, array{id: string, type: string, path: string, docs: int, endpoints: int}>  $extensions  확장 목록
     * @return string 확장 목차 마크다운 블록 (@generated 경계 포함)
     */
    private function extensionIndexBlock(array $extensions): string
    {
        usort($extensions, function ($a, $b) {
            return [$a['type'], $a['id']] <=> [$b['type'], $b['id']];
        });

        $lines = [];
        $lines[] = self::EXTENSION_INDEX_HEADING;
        $lines[] = '';
        $lines[] = '> 각 확장이 자신의 API 문서를 소유합니다. 아래 표는 자동 생성됩니다.';
        $lines[] = '';
        $lines[] = self::GEN_START.self::GEN_KEY_EXTENSIONS.' -->';

        if ($extensions === []) {
            $lines[] = '_설치된 번들 확장 중 API 문서를 소유한 확장이 없습니다._';
            $lines[] = '';
            $lines[] = self::GEN_END;

            return implode("\n", $lines)."\n";
        }

        $lines[] = '- **확장 수**: '.count($extensions)
            .' · **엔드포인트 수**: '.array_sum(array_column($extensions, 'endpoints'));
        $lines[] = '';
        $lines[] = '| 확장 | 유형 | API 문서 목차 | 문서/엔드포인트 |';
        $lines[] = '| --- | --- | --- | --- |';

        foreach ($extensions as $e) {
            $type = $e['type'] === 'module' ? '모듈' : '플러그인';
            $lines[] = "| `{$e['id']}` | {$type} | [docs/api/]({$e['path']}) | {$e['docs']} / {$e['endpoints']} |";
        }

        $lines[] = '';
        $lines[] = self::GEN_END;

        return implode("\n", $lines)."\n";
    }

    /**
     * 인증/권한 라인을 구성합니다.
     *
     * @param  array<string, mixed>  $route  라우트 메타데이터
     * @return string 인증/권한 설명
     */
    private function authLine(array $route): string
    {
        $mw = $route['middleware'] ?? [];
        $parts = [];

        // optional.sanctum(회원/비회원 모두 접근 — Bearer 토큰 있으면 인증, 없으면 guest)은
        // auth:sanctum(인증 필수)과 계약이 다르므로 별도 표기한다. 'sanctum' 부분일치가
        // optional.sanctum 까지 auth:sanctum 으로 오표기하던 회귀를 막는다.
        if ($this->hasMiddleware($mw, 'optional.sanctum')) {
            $parts[] = '`optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)';
        } elseif ($this->hasMiddleware($mw, 'sanctum')) {
            $parts[] = '`auth:sanctum`';
        }
        if ($this->hasMiddleware($mw, 'AdminMiddleware')) {
            $parts[] = '`admin`';
        }
        if ($route['permission']) {
            $parts[] = "`permission:{$route['permission']}`";
        }

        return $parts === [] ? '공개 (인증 불필요)' : implode(' + ', $parts);
    }

    /**
     * 미들웨어 목록에 특정 토큰이 포함되는지 확인합니다.
     *
     * @param  array<int, string>  $middleware  미들웨어 목록
     * @param  string  $needle  검색 토큰
     * @return bool 포함 여부
     */
    private function hasMiddleware(array $middleware, string $needle): bool
    {
        foreach ($middleware as $mw) {
            if (Str::contains($mw, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 요청 파라미터 표를 생성합니다.
     *
     * @param  array<string, mixed>  $route  라우트 메타데이터
     * @param  array<string, mixed>  $request  FormRequest 분석 결과
     * @return string 마크다운 표
     */
    private function requestParamTable(array $route, array $request): string
    {
        $rows = [];
        $pathParams = $route['path_params'];

        foreach ($pathParams as $pathParam) {
            $desc = $this->paramDescriber->describe($pathParam, 'path', 'string');
            $descCell = $desc !== null ? $this->escapeCell($desc) : '<!-- TODO: 용도 -->';
            $rows[] = "| {$pathParam} | path | string | 예 | — | {$descCell} |";
        }

        $location = in_array($route['method'], ['GET', 'DELETE'], true) ? 'query' : 'body';

        foreach ($request['params'] as $p) {
            // path 파라미터로 이미 출력한 이름은 FormRequest 규칙에서 중복 출력하지 않는다
            // (라우트 바인딩 세그먼트가 FormRequest rules() 에도 있으면 path 행이 우선).
            if (in_array($p['name'], $pathParams, true)) {
                continue;
            }

            $required = $p['required'] ? '예' : '아니오';
            $allowed = $p['allowed'] !== '' ? $p['allowed'] : '—';
            $desc = $this->paramDescriber->describe($p['name'], $location, $p['type']);
            $descCell = $desc !== null ? $this->escapeCell($desc) : '<!-- TODO: 용도 -->';
            $rows[] = "| {$p['name']} | {$location} | {$p['type']} | {$required} | {$allowed} | {$descCell} |";
        }

        if ($rows === []) {
            return '_요청 파라미터 없음._';
        }

        return "| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |\n| --- | --- | --- | --- | --- | --- |\n".implode("\n", $rows);
    }

    /**
     * 응답 필드 표 블록을 생성합니다 (examples-only 모드의 마커 갱신용 공개 래퍼).
     *
     * @param  array<string, mixed>|null  $schema  실측 응답 스키마
     * @param  array<string, mixed>  $probeMeta  실측 메타
     * @param  array<string, string>  $commentMap  컬럼명 => 주석 (필드 설명 기본값)
     * @return string 마크다운 표 또는 실측 제외 사유
     */
    public function responseFieldTableBlock(?array $schema, array $probeMeta, array $commentMap = []): string
    {
        return $this->responseFieldTable($schema, $probeMeta, $commentMap);
    }

    /**
     * 응답 필드 표를 생성합니다.
     *
     * @param  array<string, mixed>|null  $schema  실측 응답 스키마
     * @param  array<string, mixed>  $probeMeta  실측 메타
     * @param  array<string, string>  $commentMap  컬럼명 => 주석 (필드 설명 기본값)
     * @return string 마크다운 표 또는 실측 제외 사유
     */
    private function responseFieldTable(?array $schema, array $probeMeta, array $commentMap = []): string
    {
        if ($schema === null) {
            $reason = $probeMeta['skipped_reason'] ?? 'not-probed';

            return "<!-- 실측 제외: {$reason} — 응답 필드는 사람이 작성하세요. -->";
        }

        $note = '';
        if ($schema['shape'] === 'collection') {
            $note = '_목록 응답: `data.data[]` 배열 항목의 필드'.($schema['pagination'] ? ' + `data.pagination`' : '').'._';
        } elseif ($schema['shape'] === 'object') {
            $note = '_단건 응답: `data` 객체의 필드._';
        }

        if ($schema['fields'] === []) {
            return $note."\n\n<!-- 실측 응답에 필드 없음(빈 목록 등) — 데이터가 있는 상태로 재실측하거나 사람이 작성. -->";
        }

        $rows = [];
        foreach ($schema['fields'] as $f) {
            // 필드 설명 우선순위:
            //   1) 리소스 계약 사전 (accessor/computed — status_label, is_owner, *_at 등)
            //   2) 컬럼 주석 (한국어 comment — 테이블 실제 컬럼)
            //   3) TODO (사람 보강)
            // 계약 사전이 앞서는 이유: created_at 은 어느 테이블이든 "생성 일시" 이고,
            // status_label 은 컬럼이 아니라 Enum label() 산물이라 주석이 없기 때문.
            $desc = $this->fieldDescriber->describe($f['name'], $f['type'] ?? '')
                ?? ($commentMap[$f['name']] ?? null);
            $descCell = $desc !== null ? $this->escapeCell($desc) : '<!-- TODO: 설명 -->';
            $rows[] = "| {$f['name']} | {$f['type']} | `{$f['sample']}` | {$descCell} |";
        }

        $table = "| 필드 | 타입 | 실측 예시값 | 용도/설명 |\n| --- | --- | --- | --- |\n".implode("\n", $rows);

        return $note !== '' ? $note."\n\n".$table : $table;
    }

    /**
     * 에러 응답 표를 생성합니다.
     *
     * 라우트 메타에서 대표 에러 상태코드와 발생 조건을 자동 추론합니다.
     *   - 401: 인증 필수(`auth:sanctum`) 미들웨어. `optional.sanctum`(선택 인증)은 제외.
     *   - 403: `admin` 미들웨어 또는 `permission:` 요구 → 권한 부족 시.
     *   - 422: FormRequest 검증 규칙 존재 → 검증 실패 시.
     *   - 404: path 파라미터 존재 → 대상 리소스 미발견 시.
     *
     * 자동 추론은 대표 상태코드의 초안이며, 도메인 특이 에러(409 충돌·429 제한 등)는
     * `@generated` 블록 밖 사람 서술에서 보강한다.
     *
     * @param  array<string, mixed>  $route  라우트 메타데이터
     * @param  array<string, mixed>  $request  FormRequest 분석 결과
     * @return string 마크다운 표
     */
    private function errorTable(array $route, array $request): string
    {
        $mw = $route['middleware'] ?? [];
        $rows = [];

        // 401: 인증 필수. optional.sanctum(선택 인증)은 미인증도 허용하므로 제외.
        if (! $this->hasMiddleware($mw, 'optional.sanctum') && $this->hasMiddleware($mw, 'sanctum')) {
            $rows[] = '| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |';
        }

        // 403: admin 게이트 또는 permission 요구 → 권한 부족.
        // permission 은 여러 권한의 OR 조합(`core.modules.read | core.menus.read`)일 수 있으므로
        // 셀에 박기 전에 파이프를 이스케이프한다 — 안 하면 3열 표가 갈라져 깨진다.
        if ($this->hasMiddleware($mw, 'AdminMiddleware') || ! empty($route['permission'])) {
            $cond = ! empty($route['permission'])
                ? '요구 권한(`'.$this->escapeCell((string) $route['permission']).'`)이 없는 경우'
                : '관리자 권한이 없는 경우';
            $rows[] = "| 403 | Forbidden | {$cond} |";
        }

        // 422: FormRequest 검증 규칙 존재 → 검증 실패. (훅 주입 규칙 포함 가능)
        $hasValidation = ! empty($request['params']) || ! empty($request['hook_filters']);
        if ($hasValidation) {
            $rows[] = '| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |';
        }

        // 404: path 파라미터 존재 → 대상 리소스 미발견.
        if (! empty($route['path_params'])) {
            $rows[] = '| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |';
        }

        if ($rows === []) {
            return '_대표 에러 없음 (공개 조회). <!-- TODO: 도메인 특이 에러가 있으면 보강 -->_';
        }

        return "| 상태코드 | 의미 | 발생 조건 |\n| --- | --- | --- |\n".implode("\n", $rows);
    }

    /**
     * 실제 호출을 재현하는 raw HTTP 요청 예시 블록을 생성합니다.
     *
     * 요청 라인(`{METHOD} {path} HTTP/1.1`) + 헤더 + 바디로 raw HTTP 요청을 조립합니다
     * (응답 예시의 `HTTP/1.1 {status}` 상태줄과 대칭). curl 은 사용하지 않습니다.
     *   - 인증 필요(`auth:sanctum`/`optional.sanctum`) 시 `Authorization: Bearer {YOUR_TOKEN}` 마스킹.
     *   - 바디 있는 메서드(POST/PUT/PATCH)는 요청 파라미터 표의 필수 파라미터 + 타입별 샘플값을
     *     빈 줄 뒤 JSON 바디로 붙입니다.
     *
     * path 파라미터는 실측 치환값이 아니라 라우트 정의의 `{param}` placeholder 로 고정한다.
     * 실측 치환값(`resolved_uri`)을 쓰면 실측 성패·시드 데이터에 따라 같은 엔드포인트의 예시가
     * `/brands/1` ↔ `/brands/{brand}` 로 흔들려, 재실행마다 무관한 문서까지 diff 가 발생한다.
     * 레퍼런스 문서는 "어떤 파라미터를 넣는가"를 보여주는 것이 목적이므로 placeholder 가 정본이다.
     *
     * @param  array<string, mixed>  $route  라우트 메타데이터
     * @param  array<string, mixed>  $request  FormRequest 분석 결과
     * @param  array<string, mixed>  $probeMeta  실측 메타 (base_url, resolved_uri)
     * @return string 마크다운 코드블록
     */
    public function requestExampleBlock(array $route, array $request, array $probeMeta): string
    {
        $method = strtoupper((string) $route['method']);
        $path = (string) $route['uri'];

        // GET/DELETE 의 query 파라미터는 URL 쿼리스트링으로 반영한다(바디가 아니라 URL).
        if (in_array($method, ['GET', 'DELETE'], true)) {
            $path = $this->appendQueryString($path, $request);
        }

        // 요청 라인 + 헤더로 raw HTTP 요청 예시를 조립한다 (응답 예시의 HTTP/1.1 상태줄과 대칭).
        // Host 는 실측 기준 URL(로컬 호스트)을 노출하지 않고 공개 placeholder 로 마스킹한다.
        $lines = ["{$method} {$path} HTTP/1.1"];
        $lines[] = 'Host: '.self::DOC_HOST;
        $lines[] = 'Accept: application/json';

        // 인증 필요 시 Bearer 토큰 마스킹 (auth:sanctum 필수 / optional.sanctum 선택).
        $mw = $route['middleware'] ?? [];
        if ($this->hasMiddleware($mw, 'sanctum')) {
            $optional = $this->hasMiddleware($mw, 'optional.sanctum');
            $suffix = $optional ? '   (optional.sanctum: 비회원은 헤더 생략 가능)' : '';
            $lines[] = 'Authorization: Bearer '.self::TOKEN_PLACEHOLDER.$suffix;
        }

        // 바디 있는 메서드(POST/PUT/PATCH)는 바디를 붙인다.
        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $bodyParams = $this->bodyParams($request);

            if ($bodyParams !== []) {
                // 파일 업로드(image/file 타입) 파라미터가 있으면 multipart/form-data 로 표기한다
                // (application/json 으로는 파일을 전송할 수 없다).
                if ($this->hasFileParam($bodyParams)) {
                    foreach ($this->multipartBodyLines($bodyParams) as $bl) {
                        $lines[] = $bl;
                    }
                } else {
                    $lines[] = 'Content-Type: application/json';
                    $lines[] = '';
                    $body = $this->jsonBody($bodyParams);
                    $json = json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $lines[] = (string) $json;
                }
            }
        }

        return "```http\n".implode("\n", $lines)."\n```";
    }

    /**
     * GET/DELETE 의 query 파라미터를 URL 쿼리스트링으로 붙입니다.
     *
     * 이미 쿼리(`?per_page=..`)가 있으면 `&` 로 잇고, 각 파라미터는 타입별 대표값으로 채웁니다.
     * path 파라미터는 URL 세그먼트라 제외합니다.
     *
     * @param  string  $path  경로(치환된 path 파라미터 포함 가능)
     * @param  array<string, mixed>  $request  FormRequest 분석 결과
     * @return string 쿼리스트링이 붙은 경로
     */
    private function appendQueryString(string $path, array $request): string
    {
        $params = $request['params'] ?? [];
        if ($params === []) {
            return $path;
        }

        $pathParams = [];
        preg_match_all('/\{([^}]+)\}/', $path, $m);
        if (! empty($m[1])) {
            $pathParams = $m[1];
        }

        $pairs = [];
        foreach ($params as $p) {
            $name = (string) $p['name'];
            if (in_array($name, $pathParams, true)) {
                continue;
            }
            // 허용값 in: 열거가 있으면 그 첫 값을, 없으면 이름·타입 기반 대표값을 쓴다.
            $value = $this->exampleValue($name, (string) ($p['type'] ?? 'string'), (string) ($p['allowed'] ?? ''));
            if (is_array($value)) {
                $value = $value[0] ?? '';
            }
            $pairs[] = $name.'='.rawurlencode((string) $value);
        }

        if ($pairs === []) {
            return $path;
        }

        return $path.(str_contains($path, '?') ? '&' : '?').implode('&', $pairs);
    }

    /**
     * body 위치 파라미터 목록을 반환합니다 (전체 — 필수+선택).
     *
     * @param  array<string, mixed>  $request  FormRequest 분석 결과
     * @return array<int, array<string, mixed>> body 파라미터 목록
     */
    private function bodyParams(array $request): array
    {
        return array_values($request['params'] ?? []);
    }

    /**
     * 파라미터 목록에 파일(image/file) 타입이 있는지 판별합니다.
     *
     * @param  array<int, array<string, mixed>>  $params  파라미터 목록
     * @return bool 파일 파라미터 존재 여부
     */
    private function hasFileParam(array $params): bool
    {
        foreach ($params as $p) {
            if (in_array((string) ($p['type'] ?? ''), ['file', 'image'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * multipart/form-data 요청 예시의 헤더 + 파트 라인들을 생성합니다.
     *
     * 파일 파라미터는 `filename=` + `Content-Type` 파트로, 그 외는 값 파트로 표현합니다.
     *
     * @param  array<int, array<string, mixed>>  $params  body 파라미터 목록
     * @return array<int, string> multipart 요청 라인들
     */
    private function multipartBodyLines(array $params): array
    {
        $boundary = '----G7ExampleBoundary';
        $lines = ["Content-Type: multipart/form-data; boundary={$boundary}", ''];

        foreach ($params as $p) {
            $name = (string) $p['name'];
            $type = (string) ($p['type'] ?? 'string');
            $lines[] = "--{$boundary}";

            if (in_array($type, ['file', 'image'], true)) {
                // 배열 파일(files[]) 은 name 뒤에 [] 를 붙인다.
                $isArrayFile = str_ends_with($name, 's') || $name === 'files';
                $field = $isArrayFile ? $name.'[]' : $name;
                $fileName = $type === 'image' ? 'example.png' : 'example.pdf';
                $mime = $type === 'image' ? 'image/png' : 'application/octet-stream';
                $lines[] = "Content-Disposition: form-data; name=\"{$field}\"; filename=\"{$fileName}\"";
                $lines[] = "Content-Type: {$mime}";
                $lines[] = '';
                $lines[] = '(바이너리 파일 내용)';
            } else {
                $lines[] = "Content-Disposition: form-data; name=\"{$name}\"";
                $lines[] = '';
                $lines[] = (string) $this->exampleScalar($name, $type);
            }
        }

        $lines[] = "--{$boundary}--";

        return $lines;
    }

    /**
     * JSON 요청 바디 맵을 조립합니다 (전체 파라미터 — 필수+선택).
     *
     * 값은 이름·타입에 맞는 현실적인 예시값으로 채웁니다(placeholder `"string"` 남발 방지).
     *
     * @param  array<int, array<string, mixed>>  $params  body 파라미터 목록
     * @return array<string, mixed> 예시 바디 (이름 => 예시값)
     */
    private function jsonBody(array $params): array
    {
        $body = [];
        foreach ($params as $p) {
            $name = (string) $p['name'];
            $type = (string) ($p['type'] ?? 'string');
            $body[$name] = $this->exampleValue($name, $type, (string) ($p['allowed'] ?? ''));
        }

        return $body;
    }

    /**
     * 파라미터의 예시값을 이름·타입·허용값 근거로 생성합니다.
     *
     * `array` 타입은 빈 배열이 아니라 대표 원소 1개를, boolean/number 는 타입값을,
     * 그 외는 이름 기반 현실적 문자열(email/password/url 등)을 반환합니다.
     * 허용값에 `in:` 목록(백틱)이 있으면 그 첫 값을 채택합니다.
     *
     * @param  string  $name  파라미터명
     * @param  string  $type  타입
     * @param  string  $allowed  허용값 설명
     * @return mixed 예시값
     */
    private function exampleValue(string $name, string $type, string $allowed = ''): mixed
    {
        // 허용값에 in: 열거(백틱으로 감싼 첫 값)가 있으면 그것을 우선 채택한다.
        if (preg_match('/`([^`]+)`/', $allowed, $m)) {
            return $m[1];
        }

        return match ($type) {
            'array' => [$this->exampleScalar($this->singularName($name), 'string')],
            default => $this->exampleScalar($name, $type),
        };
    }

    /**
     * 스칼라 파라미터의 예시값을 이름·타입 근거로 생성합니다.
     *
     * @param  string  $name  파라미터명
     * @param  string  $type  타입
     * @return mixed 예시 스칼라값
     */
    private function exampleScalar(string $name, string $type): mixed
    {
        $lower = strtolower($name);

        // 타입 우선 판정
        if ($type === 'boolean') {
            return true;
        }
        if (in_array($type, ['integer', 'number'], true)) {
            return match (true) {
                str_contains($lower, 'page') => 1,
                str_ends_with($lower, '_id'), $lower === 'id' => 1,
                default => 1,
            };
        }
        if ($type === 'email' || str_contains($lower, 'email')) {
            return 'user@example.com';
        }
        if ($type === 'uuid') {
            return '9f8b2c1a-4d3e-4a2b-8c1d-0e1f2a3b4c5d';
        }
        if ($type === 'date') {
            return '2026-01-01';
        }

        // 이름 기반 현실적 값
        return match (true) {
            str_contains($lower, 'password') => 'Password123!',
            str_contains($lower, 'url') || str_contains($lower, 'homepage') => 'https://example.com',
            str_contains($lower, 'identifier') || str_contains($lower, 'slug') => 'example-key',
            str_contains($lower, 'name') => '예시 이름',
            str_contains($lower, 'title') => '예시 제목',
            str_contains($lower, 'mobile') || str_contains($lower, 'phone') => '010-1234-5678',
            str_contains($lower, 'locale') || $lower === 'language' => 'ko',
            str_contains($lower, 'country') => 'KR',
            str_contains($lower, 'timezone') => 'Asia/Seoul',
            str_contains($lower, 'zipcode') || str_contains($lower, 'postal') => '06234',
            str_contains($lower, 'address') => '서울특별시 강남구 테헤란로 1',
            str_contains($lower, 'content') || str_contains($lower, 'description') || str_contains($lower, 'bio') => '예시 내용입니다.',
            str_contains($lower, 'token') => self::TOKEN_PLACEHOLDER,
            str_contains($lower, 'color') => '#4F46E5',
            default => '예시값',
        };
    }

    /**
     * 배열 파라미터명의 단수형 근사값을 반환합니다 (예시 원소 이름용).
     *
     * @param  string  $name  파라미터명 (복수형 가능)
     * @return string 단수형 근사
     */
    private function singularName(string $name): string
    {
        return Str::singular($name);
    }

    /**
     * 실측 응답 body 전문(envelope 통짜)을 예시 블록으로 방출합니다.
     *
     * 실측 body 가 있으면 `{success, data, message, error}` envelope 를 pretty JSON 으로 출력합니다.
     * 목록 응답의 `data.data[]` 는 대표 항목으로 절단하고 나머지는 절단 주석으로 대체합니다.
     * 실측 제외(쓰기 메서드·바이너리·미치환 path)면 사람 보강 마커를 남깁니다.
     *
     * @param  array<string, mixed>|null  $schema  실측 응답 스키마 (null=실측 안 됨)
     * @param  array<string, mixed>  $probeMeta  실측 메타 (status, body, skipped_reason)
     * @return string 마크다운 코드블록 또는 실측 제외 마커
     */
    public function responseExampleBlock(?array $schema, array $probeMeta): string
    {
        $body = $probeMeta['body'] ?? null;

        if (! is_array($body)) {
            $reason = $probeMeta['skipped_reason'] ?? 'not-probed';

            return "<!-- 실측 제외: {$reason} — 응답 예시는 사람이 작성하세요. -->";
        }

        $status = $probeMeta['status'] ?? 200;
        $truncated = $this->truncateListBody($body);
        // 실측 응답에 섞여 나온 민감값(토큰·비밀번호·시크릿 등)을 마스킹한다.
        $sanitized = $this->sanitizeSensitive($truncated);
        // 응답 body 내부의 절대 URL(페이지네이터 링크·콜백 URL 등)에 실측 기준 호스트가
        // 그대로 직렬화돼 들어올 수 있으므로 공개 문서용 placeholder 호스트로 마스킹한다.
        $masked = $this->maskResponseHost($sanitized, $probeMeta['base_url'] ?? null);
        // 목록 절단은 페이지네이션 형태(`data.data[]`)만 다룬다. 카탈로그·스펙처럼 목록이 아닌
        // 큰 응답은 그대로 두면 문서 한 파일이 수 MB 로 불어난다 — 전체 크기 상한을 별도로 건다.
        $capped = $this->capExampleSize($masked);
        $json = json_encode($capped, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return self::PROBED_BODY_MARKER."\n\n```http\nHTTP/1.1 {$status}\n```\n\n```json\n".(string) $json."\n```";
    }

    /**
     * 응답 body 의 절대 URL 에 포함된 실측 기준 호스트를 공개 placeholder 호스트로 마스킹합니다 (재귀).
     *
     * Laravel 페이지네이터 메타(`first_page_url`/`last_page_url`/`path`/`url` 등)와 콜백/통보 URL 은
     * 실측 시 요청 base URL 의 호스트(로컬 개발 호스트 등)를 그대로 직렬화한다. 요청 예시의 `Host:`
     * 헤더는 {@see self::DOC_HOST} 로 마스킹되지만 응답 body 문자열값은 별도 처리가 없으면 유출된다.
     * base URL 의 scheme+host 를 `https://{DOC_HOST}` 로 치환해 응답 예시에서도 호스트를 노출하지 않는다.
     *
     * @param  mixed  $value  응답 값 (배열/스칼라)
     * @param  string|null  $baseUrl  실측 요청 base URL (없으면 마스킹 생략)
     * @return mixed 호스트가 마스킹된 값
     */
    private function maskResponseHost(mixed $value, ?string $baseUrl): mixed
    {
        if ($baseUrl === null || $baseUrl === '') {
            return $value;
        }

        $host = parse_url($baseUrl, PHP_URL_HOST);
        if (! is_string($host) || $host === '' || $host === self::DOC_HOST) {
            return $value;
        }

        if (is_string($value)) {
            // scheme://host 조합을 placeholder 로 치환 (포트 유무·http/https 모두 대응).
            return preg_replace(
                '#https?://'.preg_quote($host, '#').'(:\d+)?#i',
                'https://'.self::DOC_HOST,
                $value
            );
        }

        if (is_array($value)) {
            $result = [];
            foreach ($value as $k => $v) {
                $result[$k] = $this->maskResponseHost($v, $baseUrl);
            }

            return $result;
        }

        return $value;
    }

    /**
     * 응답 body 에서 민감한 필드 값을 placeholder 로 마스킹합니다 (재귀).
     *
     * 실측 응답에 실제 토큰·비밀번호 해시·시크릿·API 키가 섞여 나올 수 있으므로 공개 문서에
     * 방출하기 전에 마스킹한다. 필드명(키)에 민감 키워드가 포함되면 값을 placeholder 로 대체.
     *
     * @param  mixed  $value  응답 값 (배열/스칼라)
     * @return mixed 마스킹된 값
     */
    private function sanitizeSensitive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $sensitiveKeys = [
            'token', 'access_token', 'refresh_token', 'plaintexttoken', 'plain_text_token',
            'password', 'secret', 'api_key', 'apikey', 'private_key', 'client_secret',
            'authorization', 'remember_token', 'app_key',
        ];

        $result = [];
        foreach ($value as $k => $v) {
            $keyLower = is_string($k) ? strtolower($k) : '';
            $isSensitive = false;
            foreach ($sensitiveKeys as $needle) {
                if ($keyLower !== '' && str_contains($keyLower, $needle)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive && ! is_array($v) && $v !== null) {
                // 타입 표기용 접미(_type/_expires 등)는 마스킹 대상이 아니므로 스칼라만 마스킹.
                $result[$k] = ($keyLower === 'token_type') ? $v : '{MASKED}';
            } else {
                $result[$k] = $this->sanitizeSensitive($v);
            }
        }

        return $result;
    }

    /**
     * 목록 응답 body 의 `data.data[]` 를 대표 항목으로 절단합니다.
     *
     * 목록 body 는 클 수 있으므로 최대 LIST_EXAMPLE_LIMIT 항목만 남기고
     * 나머지는 `// ... (총 N건 중 K건 표시)` 절단 주석 항목으로 대체합니다.
     *
     * @param  array<string, mixed>  $body  실측 응답 body (envelope)
     * @return array<string, mixed> 절단된 body
     */
    private function truncateListBody(array $body): array
    {
        $data = $body['data'] ?? null;

        if (is_array($data) && isset($data['data']) && is_array($data['data'])) {
            $rows = $data['data'];
            $total = count($rows);

            if ($total > self::LIST_EXAMPLE_LIMIT) {
                $kept = array_slice($rows, 0, self::LIST_EXAMPLE_LIMIT);
                $kept[] = "... (총 {$total}건 중 ".self::LIST_EXAMPLE_LIMIT.'건 표시)';
                $body['data']['data'] = $kept;
            }
        }

        return $body;
    }

    /**
     * 응답 예시 전체 크기를 상한 이하로 줄입니다.
     *
     * `truncateListBody()` 는 페이지네이션 형태(`data.data[]`)만 절단하므로, 편집기 스펙·컴포넌트
     * 카탈로그·라우트 목록처럼 목록이 아닌 큰 응답은 통째로 직렬화된다. 그대로 두면 문서 한 파일이
     * 수 MB 가 되어 리뷰·git·에디터가 모두 망가진다(#518 에서 templates.md 가 115KB → 8.65MB).
     *
     * 절단 강도를 단계적으로 올리며 상한 이하가 될 때까지 재시도하고, 그래도 넘으면 최상위 키만
     * 남긴 요약으로 대체한다. 어느 경우에도 "무엇이 잘렸는지" 를 문서에 남긴다.
     *
     * @param  array<string, mixed>  $body  마스킹까지 끝난 응답 body
     * @return array<string, mixed> 상한 이하로 절단된 body
     */
    private function capExampleSize(array $body): array
    {
        if ($this->encodedSize($body) <= self::EXAMPLE_MAX_BYTES) {
            return $body;
        }

        // (배열 최대 항목 수, 문자열 최대 길이) 를 점점 조인다.
        foreach ([[5, 400], [3, 200], [1, 80]] as [$maxItems, $maxChars]) {
            $reduced = $this->shrinkNode($body, $maxItems, $maxChars);

            if ($this->encodedSize($reduced) <= self::EXAMPLE_MAX_BYTES) {
                return $reduced;
            }
        }

        // 여기까지 와도 상한을 넘으면 구조만 남긴다.
        $originalSize = $this->encodedSize($body);
        $data = $body['data'] ?? null;
        $keys = is_array($data) ? array_slice(array_keys($data), 0, 20) : [];

        $body['data'] = [
            '// 응답이 너무 커서 예시로 싣지 않습니다 (약 '.number_format($originalSize).' bytes)',
            '// 최상위 키: '.($keys === [] ? '(스칼라 또는 빈 응답)' : implode(', ', $keys)),
        ];

        return $body;
    }

    /**
     * 노드를 재귀적으로 축소합니다. 배열은 항목 수를, 문자열은 길이를 자릅니다.
     *
     * @param  mixed  $node  축소 대상
     * @param  int  $maxItems  배열/객체에서 남길 최대 항목 수
     * @param  int  $maxChars  문자열 최대 길이
     * @return mixed 축소된 노드
     */
    private function shrinkNode(mixed $node, int $maxItems, int $maxChars): mixed
    {
        if (is_string($node)) {
            return mb_strlen($node) > $maxChars
                ? mb_substr($node, 0, $maxChars).'… (생략)'
                : $node;
        }

        if (! is_array($node)) {
            return $node;
        }

        $isList = array_is_list($node);
        $total = count($node);
        $sliced = array_slice($node, 0, $maxItems, ! $isList);

        $result = [];
        foreach ($sliced as $key => $value) {
            $result[$key] = $this->shrinkNode($value, $maxItems, $maxChars);
        }

        if ($total > $maxItems) {
            $omitted = $total - $maxItems;
            if ($isList) {
                $result[] = "... (총 {$total}건 중 {$maxItems}건 표시)";
            } else {
                $result['...'] = "({$omitted}개 키 생략, 총 {$total}개)";
            }
        }

        return $result;
    }

    /**
     * 노드를 문서에 실릴 형태로 인코딩했을 때의 바이트 수를 반환합니다.
     *
     * @param  mixed  $node  측정 대상
     * @return int 바이트 수 (인코딩 실패 시 상한 초과로 간주)
     */
    private function encodedSize(mixed $node): int
    {
        $json = json_encode($node, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? PHP_INT_MAX : strlen($json);
    }

    /**
     * 마크다운 표 셀 안에서 안전하도록 파이프/개행을 이스케이프합니다.
     *
     * @param  string  $text  원본 텍스트
     * @return string 이스케이프된 텍스트
     */
    private function escapeCell(string $text): string
    {
        return str_replace(['|', "\n", "\r"], ['\\|', ' ', ''], $text);
    }

    /**
     * 기존 문서의 줄 끝을 LF 로 정규화합니다.
     *
     * 보존 로직(표 셀·응답 본문·에러 표·사람 서술)은 전부 `explode("\n")` 후 줄 끝 매칭
     * (`str_ends_with($line, ' |')` 등)으로 동작한다. Windows 체크아웃(core.autocrlf=true)이
     * 내려준 CRLF 문서에서는 줄 끝에 \r 이 남아 이 매칭이 전부 실패하고, 결과적으로 사람이
     * 채운 셀·본문이 하나도 보존되지 않은 채 TODO/마커로 퇴행한다.
     *
     * 문서는 `.gitattributes` 가 `eol=lf` 로 저장하므로 정규화가 저장 형식을 바꾸지 않는다.
     *
     * @param  string  $content  기존 문서 내용
     * @return string LF 로 정규화된 내용
     */
    private function normalizeEol(string $content): string
    {
        return str_replace(["\r\n", "\r"], "\n", $content);
    }

    /**
     * 기존 문서에 새 생성 블록을 병합합니다. 사람 서술은 보존합니다.
     *
     * @param  string|null  $existing  기존 문서 내용 (null=신규)
     * @param  string  $header  문서 헤더 (제목 + TL;DR 등)
     * @param  array<int, string>  $sections  엔드포인트 섹션 목록 (라우트명 순)
     * @param  array<int, string>  $sectionKeys  각 섹션의 라우트명 키
     * @return string 병합된 문서 내용
     */
    public function mergeDocument(?string $existing, string $header, array $sections, array $sectionKeys): string
    {
        if ($existing === null) {
            return $header."\n".implode("\n", $sections);
        }

        // Windows 체크아웃(core.autocrlf=true)은 문서를 CRLF 로 내려준다. 아래 보존 로직은
        // 전부 `explode("\n")` + 줄 끝 매칭(`str_ends_with($line, ' |')`) 기반이라, 줄 끝에
        // \r 이 남아 있으면 표 행이 하나도 파싱되지 않아 사람이 채운 셀이 통째로 유실된다.
        // 파싱 전에 줄 끝을 LF 로 정규화한다 (문서는 .gitattributes 가 eol=lf 로 저장한다).
        $existing = $this->normalizeEol($existing);

        $merged = $header."\n";

        foreach ($sections as $i => $section) {
            $key = $sectionKeys[$i];
            // 아직 생성 블록이 없던(사람이 직접 쓴) 섹션은 헤딩으로 찾는다. 키로만 찾으면
            // 최초 생성 회차에 그 섹션의 서술·표 설명이 통째로 사라진다.
            $legacy = $this->extractLegacySection($existing, $section, $key);

            $preserved = $this->extractHumanProse($existing, $key)
                ?? ($legacy === null ? null : $this->extractLegacyProse($legacy));
            $withProse = $this->applyPreservedProse($section, $preserved);
            // 순서 주의: 표 자체를 되살린 뒤에 셀 설명을 맞춘다.
            $withFields = $this->restoreEmptyProbeTable($withProse, $existing, $key, $legacy);
            $withTables = $this->restoreTableDescriptions($withFields, $existing, $key, $legacy);
            // 실측 불가 마커 자리·응답 예시를 사람이 채운 경우 되살린다
            // (@probed 출처 마커 부재 = 사람 작성분, 위치 기반이므로 블록 본문으로만 대조).
            $withBodies = $this->applyPreservedResponseBodies(
                $withTables,
                $this->extractGeneratedBlockBody($existing, $key) ?? $legacy
            );
            $withErrors = $this->restoreErrorSection($withBodies, $existing, $key, $legacy);
            // 에러 표는 상태코드 행 단위로도 병합한다 — restoreErrorSection 은 새 표가 통째로
            // 스텁일 때의 복원만 다루므로, 자동 추론이 초안을 만들어 낸 경우 사람이 보강한
            // 행(409 충돌·429 제한 등 도메인 특이 에러)이 덮여 사라진다. 행 병합을 겹쳐
            // 같은 상태코드는 사람 행이 이기고, 자동 추론에만 있는 상태코드는 새로 편입한다.
            // 에러 표는 상태코드가 엔드포인트마다 중복되어 키가 고유하지 않으므로, 이 엔드포인트만
            // 정확히 잘라낸 블록으로만 대조한다. extractGeneratedBlockBody 가 아니라
            // exactGeneratedBlock 을 쓰는 이유: 에러 표 추출(extractErrorTable)은 생성 블록
            // 종료 마커를 표의 끝 경계로 삼는데, 전자는 그 마커를 잘라내고 돌려준다.
            $merged .= $this->applyPreservedErrorTable(
                $withErrors,
                $this->exactGeneratedBlock($existing, $key),
                $section
            )."\n";
        }

        return $merged;
    }

    /**
     * 새 섹션의 TODO 설명 셀을 기존 문서의 사람 서술로 되돌립니다.
     *
     * `@generated` 블록의 파라미터/응답 필드 표는 재생성 때마다 통째로 다시 조립되므로,
     * 정적 추출로 재현할 수 없는 도메인 설명(예: `no_category: 카테고리 미지정 상품만 필터`)이
     * TODO 스텁으로 되돌아간다. 사람이 채운 설명이 재생성 한 번에 사라지는 것은 문서 손실이므로,
     * 행 이름이 같고 새 셀이 TODO 스텁일 때만 기존 설명을 이어받는다.
     *
     * 기존 셀에 설명이 있으면 항상 그쪽을 쓴다. 도구가 만드는 설명은 필드명에서 유추한 일반
     * 문구(예: `대상의 이름/명칭`)라 사람이 적은 도메인 사실(예: `플러그인 이름 (다국어 JSON)`,
     * `desc 는 주문별 가장 늦은 발송일`)보다 언제나 정보량이 적다. 문서 헤더가 약속한
     * "사람이 작성한 설명은 보존됩니다" 를 표 셀에도 적용한다. 사실이 바뀌면 사람이 그 셀을
     * 고치거나 비우고, 비운 셀은 다음 재생성이 채운다.
     *
     * 응답 필드 표의 `실측 예시값` 열도 보존한다. 실측값은 호출 시점 DB 상태가 낳은 표본
     * 하나라, 매번 새 값으로 덮으면 스펙이 그대로인 무관한 문서까지 재실행마다 diff 가 난다.
     * 스펙이 실제로 바뀐 경우에만 갱신하도록, 같은 필드의 타입이 그대로면 기존 예시값을 유지하고
     * 타입이 달라졌으면 새 실측값을 채택한다. 기존 문서에 없던 신규 필드는 새 값을 그대로 쓴다.
     *
     * @param  string  $section  새로 생성된 섹션
     * @param  string  $existing  기존 문서 전체
     * @param  string  $key  라우트명(생성 블록 키)
     * @param  string|null  $legacy  생성 블록이 없던 사람 작성 섹션 원문 (헤딩 매칭 폴백)
     * @return string 설명이 복원된 섹션
     */
    private function restoreTableDescriptions(string $section, string $existing, string $key, ?string $legacy = null): string
    {
        $oldBlock = $this->extractGeneratedBlockBody($existing, $key) ?? $legacy;
        if ($oldBlock === null) {
            return $section;
        }

        $previousCells = $this->collectTableCells($oldBlock);
        if ($previousCells === []) {
            return $section;
        }

        $lines = explode("\n", $section);
        foreach ($lines as $index => $line) {
            $parsed = $this->parseTableRow($line);
            if ($parsed === null) {
                continue;
            }

            $previousRow = $previousCells[$parsed['key']] ?? null;

            // 기존 문서에 없던 행(= 신규 필드/파라미터)은 새로 생성된 값을 그대로 채택한다.
            if ($previousRow === null) {
                continue;
            }

            $cols = $parsed['cols'];

            // 응답 필드 표(4열)의 타입·실측 예시값 열을 보존한다.
            // 실측값은 호출 시점 DB 상태의 표본 하나라, 매번 새 값으로 덮으면 스펙이 그대로인
            // 무관한 문서까지 재실행마다 diff 가 난다. 타입이 그대로면 기존 예시값을 유지하고,
            // 타입이 바뀌었으면(스펙 변경) 새 실측값으로 갱신한다.
            //
            // 단 nullable 필드는 그 행의 표본이 null 이냐에 따라 관측 타입이 흔들린다
            // (`loggable_type` 이 string ↔ null). null 관측은 "타입이 바뀌었다"가 아니라
            // "이번 표본에는 값이 없었다" 는 뜻이므로, 기존에 실제 값을 관측해 둔 행은 유지한다.
            if (count($cols) === 4 && count($previousRow['cols']) === 4) {
                $previousType = $previousRow['cols'][1];
                $previousExample = $previousRow['cols'][2];
                $observedNothing = $cols[1] === 'null';

                $keepPrevious = $previousExample !== ''
                    && ($previousType === $cols[1] || ($observedNothing && $previousType !== 'null'));

                if ($keepPrevious) {
                    $cols[1] = $previousType;
                    $cols[2] = $previousExample;
                }
            }

            // 마지막 열(사람 서술)은 기존 값이 있으면 언제나 우선한다.
            if ($previousRow['value'] !== null) {
                $cols[count($cols) - 1] = $previousRow['value'];
            }

            $rebuilt = '| '.implode(' | ', $cols).' |';

            if ($rebuilt !== $line) {
                $lines[$index] = $rebuilt;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * 사람이 보강한 에러 응답 표를 보존합니다.
     *
     * 에러 표는 라우트 메타(미들웨어·FormRequest 유무)에서 대표 상태코드만 추론하는 초안이라,
     * 도메인 특이 에러(409 충돌·429 제한, 구체적 발생 조건)는 사람이 행을 직접 추가해 채운다.
     * 재생성이 그 표를 자동 추론 결과로 덮으면 사람이 쓴 행이 통째로 사라진다
     * (실제로 KG이니시스 결제 문서의 403·404·422·429 행이 "대표 에러 없음" 으로 지워졌다).
     *
     * 행 키(상태코드) 단위로 병합한다. 자동 추론이 만든 행은 그대로 두되(라우트 메타가 바뀌면
     * 그 결과가 정본이다), 기존 문서에만 있는 행 — 사람이 추가한 도메인 특이 에러 — 은
     * 상태코드 오름차순 자리에 되살린다. 같은 상태코드가 양쪽에 있으면 사람이 쓴 발생 조건이
     * 자동 문구보다 구체적이므로 기존 행을 우선한다.
     *
     * @param  string  $section  표 셀 보존이 끝난 섹션
     * @param  string|null  $previous  기존 문서의 같은 엔드포인트 생성 블록
     * @return string 에러 표가 보존된 섹션
     */
    /**
     * 403 행의 요구 권한 식별자를 이번 재생성 산출값으로 갱신합니다.
     *
     * 에러 표 병합은 같은 상태코드에서 기존(사람) 행을 이기게 두는데, 403 의 권한 식별자는
     * 라우트 정의에서 파생된 사실이라 사람 서술과 같은 취급을 하면 안 된다. 두 행이 모두
     * 백틱 식별자를 가질 때만 그 부분을 치환하고, 나머지 문구(사람이 덧붙인 도메인 조건)는
     * 건드리지 않는다. 식별자가 없는 형태(관리자 게이트만 걸린 라우트)는 갱신 대상이 아니다.
     *
     * @param  string  $preserved  병합 결과로 살아남은 기존 403 행
     * @param  string  $generated  이번 재생성이 만든 403 행
     * @return string 식별자만 갱신된 403 행
     */
    private function refreshPermissionIdentifier(string $preserved, string $generated): string
    {
        if (! preg_match('/`([^`]+)`/', $generated, $new)) {
            return $preserved;
        }

        if (! preg_match('/`([^`]+)`/', $preserved, $old)) {
            return $preserved;
        }

        if ($old[1] === $new[1]) {
            return $preserved;
        }

        $needle = '`'.$old[1].'`';
        $pos = strpos($preserved, $needle);

        if ($pos === false) {
            return $preserved;
        }

        // preg_replace 는 대체 문자열의 `$`·`\` 를 역참조로 해석하므로 쓰지 않는다.
        return substr_replace($preserved, '`'.$new[1].'`', $pos, strlen($needle));
    }

    private function applyPreservedErrorTable(string $section, ?string $previous, ?string $generated = null): string
    {
        if ($previous === null) {
            return $section;
        }

        $previousRows = $this->errorTableRows($previous);
        $currentBody = $this->extractErrorTable($section);

        if ($currentBody === null) {
            return $section;
        }

        // 기존 본문이 표가 아닌 사람 서술인 경우 (자동 추론이 에러 행을 만들지 못하는 공개 조회
        // 엔드포인트에서, 사람이 `대표 에러 없음` 초안 자리를 "왜 도메인 에러가 없는지" 로 채운 것).
        // 표 행이 없다고 보존을 건너뛰면 재생성이 그 서술을 TODO 초안으로 덮는다.
        if ($previousRows === []) {
            $previousBody = $this->extractErrorTable($previous);

            $previousIsHumanProse = $previousBody !== null
                && trim($previousBody) !== ''
                && ! $this->isTodoCell($previousBody)
                && ! str_contains($previousBody, '대표 에러 없음');

            if ($previousIsHumanProse) {
                return str_replace($currentBody, trim($previousBody), $section);
            }

            return $section;
        }

        // 상태코드 키로 병합. `+` 는 왼쪽 우선이므로 기존 행을 먼저 둬서, 같은 상태코드면
        // 사람이 쓴 구체적 조건이 자동 문구를 이긴다. 자동 추론에만 있는 상태코드는 새로 편입된다.
        $currentRows = $this->errorTableRows($section);
        $merged = $previousRows + $currentRows;

        if ($merged === []) {
            return $section;
        }

        // 403 행의 권한 식별자만은 예외다 — 그것은 사람 서술이 아니라 라우트에서 파생된
        // 사실이라, 위 병합 규칙을 그대로 두면 라우트의 요구 권한을 바꿔도 옛 식별자가
        // 영구히 남아 문서가 조용히 틀린 권한을 안내한다. 사람이 보강한 조건 문구는 그대로
        // 두고 백틱 식별자만 갱신한다.
        //
        // 대조 원본은 반드시 **이번 회차가 생성한 원본 섹션**이어야 한다. 여기 들어오는
        // $section 은 restoreTableDescriptions 를 이미 거쳐 403 행의 설명 셀이 기존 문서
        // 값으로 되돌아가 있으므로(에러 표도 상태코드를 행 키로 갖는 표라 그 복원 대상에
        // 걸린다), 그것을 기준으로 삼으면 갱신이 언제나 no-op 이 된다.
        $generatedRows = $generated === null ? $currentRows : $this->errorTableRows($generated);

        if (isset($merged['403'], $generatedRows['403'])) {
            $merged['403'] = $this->refreshPermissionIdentifier($merged['403'], $generatedRows['403']);
        }

        ksort($merged, SORT_NUMERIC);

        $table = "| 상태코드 | 의미 | 발생 조건 |\n| --- | --- | --- |\n".implode("\n", $merged);

        return str_replace($currentBody, $table, $section);
    }

    /**
     * 섹션의 에러 응답 표에서 [상태코드 => 행] 맵을 수집합니다.
     *
     * @param  string  $section  엔드포인트 섹션 (또는 생성 블록)
     * @return array<string, string> 상태코드 => 표 행 원문
     */
    private function errorTableRows(string $section): array
    {
        $body = $this->extractErrorTable($section);

        if ($body === null) {
            return [];
        }

        $rows = [];

        foreach (explode("\n", $body) as $line) {
            if (! preg_match('/^\|\s*(\d{3})\s*\|/', $line, $m)) {
                continue;
            }

            $rows[$m[1]] = rtrim($line);
        }

        return $rows;
    }

    /**
     * 응답 예시 본문에서 JSON 의 키 구조(값 제외)를 추출합니다.
     *
     * 값이 아니라 **필드 집합**만 비교하기 위한 지문이다. 리소스에 필드가 추가/삭제되면
     * 지문이 달라지므로 새 실측 예시를 채택하고, 값만 달라진 경우(`updated_at` 등)에는
     * 지문이 같아 기존 예시를 유지한다.
     *
     * @param  string  $body  응답 예시 본문 (```json 블록 포함)
     * @return string|null 키 구조 지문 (JSON 블록이 없거나 파싱 실패 시 null)
     */
    private function exampleKeyShape(string $body): ?string
    {
        if (! preg_match('/```json\n(.*?)\n```/s', $body, $m)) {
            return null;
        }

        $decoded = json_decode($m[1], true);

        if (! is_array($decoded)) {
            return null;
        }

        $shape = function (mixed $value) use (&$shape): mixed {
            if (! is_array($value)) {
                return '_';
            }

            // 리스트는 첫 원소의 구조만 대표로 본다(원소 수는 데이터 양에 따라 달라진다).
            if (array_is_list($value)) {
                return $value === [] ? [] : [$shape($value[0])];
            }

            $out = [];

            foreach ($value as $k => $v) {
                $out[$k] = $shape($v);
            }

            ksort($out);

            return $out;
        };

        return json_encode($shape($decoded));
    }

    /**
     * 섹션에서 `**에러 응답**` 본문(표 또는 "대표 에러 없음" 문구)을 잘라냅니다.
     *
     * @param  string  $section  엔드포인트 섹션
     * @return string|null 에러 응답 본문 (없으면 null)
     */
    private function extractErrorTable(string $section): ?string
    {
        $start = strpos($section, '**에러 응답**');

        if ($start === false) {
            return null;
        }

        $bodyStart = $start + strlen('**에러 응답**');
        $end = strpos($section, self::GEN_END, $bodyStart);

        if ($end === false) {
            return null;
        }

        $body = trim(substr($section, $bodyStart, $end - $bodyStart));

        return $body === '' ? null : $body;
    }

    /**
     * 이번 실측이 빈 응답이라 필드 표가 사라졌으면 기존 표를 되살립니다.
     *
     * 실측 대상 데이터가 없는 상태로 재생성하면 응답 필드 표가 통째로
     * `<!-- 실측 응답에 필드 없음 ... -->` 한 줄로 대체된다. 이미 문서화돼 있던 필드 목록이
     * "이번에 데이터가 없었다" 는 이유로 사라지는 것은 사실의 후퇴이므로, 기존 표가 있으면 유지한다
     * (#518 에서 reviews.md 한 파일에서만 388줄이 이렇게 사라졌다).
     *
     * @param  string  $section  새로 생성된 섹션
     * @param  string  $existing  기존 문서 전체
     * @param  string  $key  라우트명(생성 블록 키)
     * @param  string|null  $legacy  생성 블록이 없던 사람 작성 섹션 원문
     * @return string 필드 표가 복원된 섹션
     */
    private function restoreEmptyProbeTable(string $section, string $existing, string $key, ?string $legacy = null): string
    {
        $newFields = $this->sliceFieldSection($section);

        // 이번 실측이 필드를 확보했으면(표가 있으면) 그대로 둔다.
        if ($newFields === null || ! str_contains($newFields, '실측 응답에 필드 없음')) {
            return $section;
        }

        $oldBlock = $this->extractGeneratedBlockBody($existing, $key) ?? $legacy;
        if ($oldBlock === null) {
            return $section;
        }

        $oldFields = $this->sliceFieldSection($oldBlock);
        if ($oldFields === null || ! str_contains($oldFields, '| --- |')) {
            return $section;
        }

        return str_replace($newFields, $oldFields, $section);
    }

    /**
     * 섹션에서 `**응답 필드**` 이후 ~ 다음 구획 전까지의 본문을 잘라냅니다.
     *
     * @param  string  $text  섹션 또는 생성 블록 본문
     * @return string|null 응답 필드 구간 본문 (없으면 null)
     */
    private function sliceFieldSection(string $text): ?string
    {
        $pos = strpos($text, '**응답 필드**');
        if ($pos === false) {
            return null;
        }

        $rest = substr($text, $pos);
        $lineEnd = strpos($rest, "\n");
        if ($lineEnd === false) {
            return null;
        }

        $body = substr($rest, $lineEnd);
        $at = strpos($body, "\n**");
        $end = $at === false ? strlen($body) : $at;

        $slice = trim(substr($body, 0, $end));

        return $slice === '' ? null : $slice;
    }

    /**
     * 에러 응답 구간이 일반 스텁으로 되돌아가면 기존의 사람 서술을 되살립니다.
     *
     * `errorTable()` 은 라우트 메타만 보고 "대표 에러 없음 (공개 조회)" 같은 일반 문구를 만든다.
     * 사람이 도메인 특이 에러(예: "활성 에셋이 없으면 빈 200 응답")를 적어 뒀다면 재생성 한 번에
     * 그 사실이 사라지므로, 새 문구가 TODO 를 포함한 스텁일 때만 기존 구간을 이어받는다.
     *
     * @param  string  $section  새로 생성된 섹션
     * @param  string  $existing  기존 문서 전체
     * @param  string  $key  라우트명(생성 블록 키)
     * @param  string|null  $legacy  생성 블록이 없던 사람 작성 섹션 원문
     * @return string 에러 구간이 복원된 섹션
     */
    private function restoreErrorSection(string $section, string $existing, string $key, ?string $legacy = null): string
    {
        $newError = $this->sliceErrorSection($section);

        // 새 문구가 스텁이 아니면(=도구가 실제 에러를 뽑았으면) 그대로 둔다.
        if ($newError === null || ! str_contains($newError, '<!-- TODO')) {
            return $section;
        }

        $oldBlock = $this->extractGeneratedBlockBody($existing, $key) ?? $legacy;
        if ($oldBlock === null) {
            return $section;
        }

        $oldError = $this->sliceErrorSection($oldBlock);
        if ($oldError === null || $oldError === '' || str_contains($oldError, '<!-- TODO')) {
            return $section;
        }

        return str_replace($newError, $oldError, $section);
    }

    /**
     * 섹션에서 `**에러 응답**` 이후 ~ 다음 구획 전까지의 본문을 잘라냅니다.
     *
     * @param  string  $text  섹션 또는 생성 블록 본문
     * @return string|null 에러 구간 본문 (없으면 null)
     */
    private function sliceErrorSection(string $text): ?string
    {
        $pos = strpos($text, '**에러 응답**');
        if ($pos === false) {
            return null;
        }

        $rest = substr($text, $pos + strlen('**에러 응답**'));

        // 다음 구획: 생성 블록 종료 마커 · 다음 굵은 헤딩 · 다음 ### 헤딩
        $stops = [];
        foreach ([self::GEN_END, "\n**", "\n### "] as $marker) {
            $at = strpos($rest, $marker);
            if ($at !== false) {
                $stops[] = $at;
            }
        }

        $end = $stops === [] ? strlen($rest) : min($stops);

        return trim(substr($rest, 0, $end));
    }

    /**
     * 생성 블록 마커가 없던 사람 작성 섹션을 헤딩으로 찾아 원문을 반환합니다.
     *
     * 문서가 수기 작성 → 생성 관리로 넘어가는 최초 회차에는 기존 섹션에 `@generated` 마커가
     * 없다. 키로만 찾으면 그 섹션의 표 설명과 서술이 통째로 사라지므로 헤딩(`### METHOD /uri`)
     * 으로 한 번 더 찾는다. 이미 키로 찾을 수 있으면 이 폴백은 쓰지 않는다.
     *
     * @param  string  $existing  기존 문서 전체
     * @param  string  $section  새로 생성된 섹션 (첫 줄이 헤딩)
     * @param  string  $key  라우트명(생성 블록 키)
     * @return string|null 사람 작성 섹션 원문 (없으면 null)
     */
    private function extractLegacySection(string $existing, string $section, string $key): ?string
    {
        if (str_contains($existing, self::GEN_START.$key.' -->')) {
            return null;
        }

        $heading = strtok($section, "\n");
        if (! is_string($heading) || ! str_starts_with($heading, '### ')) {
            return null;
        }

        $startPos = strpos($existing, "\n".$heading."\n");
        if ($startPos === false) {
            return null;
        }

        $rest = substr($existing, $startPos + 1);
        $nextHeading = preg_match('/\n### /', $rest, $m, PREG_OFFSET_CAPTURE)
            ? $m[0][1]
            : strlen($rest);

        return substr($rest, 0, $nextHeading);
    }

    /**
     * 사람 작성 섹션 원문에서 `**설명**` 이후의 서술을 뽑아냅니다.
     *
     * @param  string  $legacy  사람 작성 섹션 원문
     * @return string|null 보존할 서술 (없으면 null)
     */
    private function extractLegacyProse(string $legacy): ?string
    {
        $pos = strpos($legacy, '**설명**');
        if ($pos === false) {
            return null;
        }

        $prose = trim(substr($legacy, $pos));

        if ($prose === '' || Str::contains($prose, 'TODO: 이 엔드포인트의 용도')) {
            return null;
        }

        return $prose;
    }

    /**
     * 기존 문서에서 라우트의 `@generated` 블록 본문만 잘라냅니다 (헤딩 제외).
     *
     * 헤딩까지 함께 되살리는 {@see self::extractGeneratedBlock()} 과 달리, 표 셀 대조에만
     * 쓰이므로 블록 본문만 필요하다.
     *
     * @param  string  $existing  기존 문서 전체
     * @param  string  $key  라우트명(생성 블록 키)
     * @return string|null 블록 본문 (없으면 null)
     */
    private function extractGeneratedBlockBody(string $existing, string $key): ?string
    {
        $startPos = strpos($existing, self::GEN_START.$key.' -->');
        if ($startPos === false) {
            return null;
        }

        $endPos = strpos($existing, self::GEN_END, $startPos);
        if ($endPos === false) {
            return null;
        }

        return substr($existing, $startPos, $endPos - $startPos);
    }

    /** @var string 분해 중 인라인 코드 안 파이프를 감춰 두는 자리표시자 (문서에 등장할 수 없는 제어문자) */
    private const CELL_PIPE_PLACEHOLDER = "\x00PIPE\x00";

    /**
     * 표 행 문자열을 셀 배열로 분해합니다 (앞뒤 파이프 제거, 셀 구분자가 아닌 파이프 보존).
     *
     * 셀 구분자가 아닌 파이프는 두 종류다. 이스케이프된 파이프(`\|`)와 **인라인 코드 스팬 안의
     * 파이프**다. 후자는 실제 문서에 흔하다 — 셸 메타문자 안내처럼 파이프 자체를 설명하는 문장이
     * 그렇다. 이를 구분자로 보면 셀 수가 늘어 마지막 셀(=설명)이 파이프 뒤 조각으로 바뀌고,
     * {@see self::parseTableRow()} 가 그 조각을 사람이 쓴 설명으로 승계해 문장 앞부분이
     * 통째로 잘려 나간다. 오류 없이 문서만 훼손되므로 분해 단계에서 막는다.
     *
     * @param  string  $line  표 행
     * @return array<int, string> 셀 배열
     */
    private function splitRowCells(string $line): array
    {
        $trimmed = trim($line);
        $trimmed = preg_replace('/^\|/', '', $trimmed);
        $trimmed = preg_replace('/\|$/', '', (string) $trimmed);

        // 인라인 코드 스팬 안의 파이프를 잠시 감춘다 (분해 후 되돌린다).
        $masked = preg_replace_callback(
            '/`[^`]*`/',
            static fn (array $matches): string => str_replace('|', self::CELL_PIPE_PLACEHOLDER, $matches[0]),
            (string) $trimmed
        );

        // 이스케이프된 파이프(`\|`)도 셀 구분자가 아니다.
        $parts = preg_split('/(?<!\\\\)\|/', (string) $masked);

        if ($parts === false) {
            return [];
        }

        return array_map(
            static fn (string $cell): string => str_replace(self::CELL_PIPE_PLACEHOLDER, '|', $cell),
            $parts
        );
    }

    /**
     * 새 섹션의 `실측 제외` 응답 본문을, 기존 섹션에서 사람이 채운 본문으로 되살립니다.
     *
     * 실측이 불가한 엔드포인트(쓰기 메서드·바이너리·미치환 path)는 `**응답 필드**` / `**응답 예시**`
     * 자리에 `<!-- 실측 제외: ... -->` 마커만 생성된다. 사람이 코드를 근거로 그 자리를 채워도
     * 다음 재생성이 블록을 통째로 재조립하면서 마커로 되돌려, 작성분이 통째로 사라졌다.
     *
     * 보존 규칙은 표 셀(`restoreTableDescriptions`)과 같다 — **사람이 쓴 본문은 언제나 이긴다.**
     * 실측 결과는 기본값이지 덮어쓰기 권한이 아니다. 실측 1회가 관측한 표본은 사람이 코드에서
     * 읽어낸 사실(성공 케이스·엣지 케이스)보다 좁기 때문이다. 실제로 `reissue` 의 실측 표본은
     * `data: null`(전액 환불) 하나뿐이라, 실측 우선 규칙은 사람이 쓴 성공 예시를 지웠다.
     *
     * 두 하위 섹션의 판정 기준이 다르다:
     *   - **응답 예시**: 실측 산출물에 `@probed` 출처 마커가 붙는다. 마커가 없으면 사람 작성분.
     *   - **응답 필드**: 표 자체는 행 키로 대조하는 `restoreTableDescriptions` 가 이미 지킨다.
     *     따라서 여기서는 실측 실패로 마커만 남은 자리를 사람이 채운 경우만 되살린다.
     *
     * @param  string  $section  새로 생성된 섹션
     * @param  string|null  $previous  기존 문서의 같은 엔드포인트 생성 블록 (없으면 null)
     * @return string 사람이 작성한 응답 본문이 되살아난 섹션
     */
    private function applyPreservedResponseBodies(string $section, ?string $previous): string
    {
        if ($previous === null) {
            return $section;
        }

        // [본문 시작 헤딩, 본문 끝 헤딩 후보, 실측 산출물 판정자]
        $subsections = [
            [
                '**응답 필드** (`data` 내부)',
                ['**응답 예시**', '**에러 응답**'],
                // 실측 성공 시 표가 생기고 그 표는 restoreTableDescriptions 가 지킨다.
                // 여기서 되살릴 대상은 "실측 실패 마커 자리를 사람이 채운" 경우뿐이다.
                fn (string $body): bool => $this->isProbeSkipMarker($body),
            ],
            [
                '**응답 예시**',
                ['**에러 응답**'],
                fn (string $body): bool => $this->isProbeSkipMarker($body) || str_contains($body, self::PROBED_BODY_MARKER),
            ],
        ];

        foreach ($subsections as [$label, $terminators, $isAutoGenerated]) {
            $previousBody = $this->extractSubsectionBody($previous, $label, $terminators);

            if ($previousBody === null) {
                continue;
            }

            $bounds = $this->locateSubsectionBody($section, $label, $terminators);

            if ($bounds === null) {
                continue;
            }

            [$start, $end] = $bounds;
            $newBody = substr($section, $start, $end - $start);

            // 이번 실측이 실패했으면(마커만 생성) 기존에 관측해 둔 실측 본문을 유지한다.
            // 실측 성패는 호출 시점 데이터 유무에 좌우된다 — 예컨대 DELETE /checkout 은 삭제할
            // 체크아웃이 없으면 404 다. 실측 실패는 "새로 관측하지 못했다"는 뜻이지 "기존 관측이
            // 무효"라는 뜻이 아니므로, 마커로 덮어 지난 응답 예시를 지우면 정보만 잃는다.
            $probeFailedNow = $this->isProbeSkipMarker($newBody);
            $previousWasProbed = str_contains($previousBody, self::PROBED_BODY_MARKER);

            if ($probeFailedNow && $previousWasProbed) {
                $section = substr_replace($section, $previousBody, $start, $end - $start);

                continue;
            }

            // 양쪽 다 실측 산출물이고 응답의 **키 구조**가 같으면 기존 예시를 유지한다.
            // 응답 예시 JSON 은 호출 시점 표본이라 `updated_at` · `cache_version` 처럼 매 실측마다
            // 달라지는 값이 섞여 들어온다. 스펙이 그대로인데 값만 바뀐 diff 를 재실행마다 만들지
            // 않도록, 필드 집합이 달라졌을 때(= 스펙 변경)만 새 실측값으로 갱신한다.
            if ($previousWasProbed
                && str_contains($newBody, self::PROBED_BODY_MARKER)
                && $this->exampleKeyShape($previousBody) === $this->exampleKeyShape($newBody)
            ) {
                $section = substr_replace($section, $previousBody, $start, $end - $start);

                continue;
            }

            // 기존 본문이 이번 run 과 같은 자동 생성 결과면 되살릴 것이 없다.
            if ($isAutoGenerated($previousBody)) {
                continue;
            }

            // 새 본문이 사람 작성분이면(= 이 run 이 만든 것이 아니면) 손대지 않는다.
            // 실무상 새 섹션은 항상 자동 생성이지만, 방어적으로 확인한다.
            if (! $isAutoGenerated($newBody)) {
                continue;
            }

            $section = substr($section, 0, $start).$previousBody.substr($section, $end);
        }

        return $section;
    }

    /**
     * 응답 본문이 실측 실패로 남은 사람 보강 요청 마커인지 확인합니다.
     *
     * @param  string  $body  응답 본문
     * @return bool 실측 제외/빈 결과 마커면 true
     */
    private function isProbeSkipMarker(string $body): bool
    {
        return str_contains($body, '실측 제외') || str_contains($body, '실측 응답에 필드 없음');
    }

    /**
     * 블록에서 `$label` 헤딩 ~ 다음 종료 헤딩 사이의 본문 [시작, 끝] 오프셋을 찾습니다.
     *
     * @param  string  $block  생성 블록 문자열
     * @param  string  $label  본문 시작 헤딩
     * @param  array<int, string>  $terminators  본문 끝 헤딩 후보
     * @return array{0: int, 1: int}|null [시작 오프셋, 끝 오프셋] (미발견 시 null)
     */
    private function locateSubsectionBody(string $block, string $label, array $terminators): ?array
    {
        $labelPos = strpos($block, $label);
        $labelLength = strlen($label);

        // 라벨 정확 일치 실패 시 베이스 라벨(굵은 제목)만으로 재시도한다.
        // 봉투를 쓰지 않는 엔드포인트(레이아웃 JSON 을 root 에 그대로 내리는 미리보기 서빙 등)에서는
        // `**응답 필드** (`data` 내부)` 라벨이 사실과 달라 사람이 접미를 떼어낸다. 정확 일치만 보면
        // 기존 본문 구간을 못 찾아, 표가 아닌 **서술**로 채운 내용이 마커로 퇴행한다
        // (표는 applyPreservedTableCells 가 행 키로 따로 지키지만, 서술은 이 경로가 유일하다).
        // 라벨 줄 전체를 라벨 길이로 삼아야 본문 시작 위치가 어긋나지 않는다.
        if ($labelPos === false) {
            $base = Str::before($label, ' (');

            if ($base === $label) {
                return null;
            }

            $labelPos = strpos($block, $base);

            if ($labelPos === false) {
                return null;
            }

            $lineEnd = strpos($block, "\n", $labelPos);
            $labelLength = ($lineEnd === false ? strlen($block) : $lineEnd) - $labelPos;
        }

        $bodyStart = $labelPos + $labelLength;

        $candidates = [];
        foreach ($terminators as $terminator) {
            $pos = strpos($block, $terminator, $bodyStart);
            if ($pos !== false) {
                $candidates[] = $pos;
            }
        }

        if ($candidates === []) {
            return null;
        }

        return [$bodyStart, min($candidates)];
    }

    /**
     * 블록에서 `$label` 헤딩 ~ 다음 종료 헤딩 사이의 본문 문자열을 추출합니다.
     *
     * @param  string  $block  생성 블록 문자열
     * @param  string  $label  본문 시작 헤딩
     * @param  array<int, string>  $terminators  본문 끝 헤딩 후보
     * @return string|null 본문 (미발견 시 null)
     */
    private function extractSubsectionBody(string $block, string $label, array $terminators): ?string
    {
        $bounds = $this->locateSubsectionBody($block, $label, $terminators);

        if ($bounds === null) {
            return null;
        }

        [$start, $end] = $bounds;

        return substr($block, $start, $end - $start);
    }

    /**
     * 생성 블록에서 표 행의 [키 => 기존 행 정보] 맵을 수집합니다.
     *
     * `value` 는 마지막 열(사람 서술, TODO 스텁이면 null), `cols` 는 행 전체 열이다.
     * 응답 필드 표의 실측 예시값 열을 보존하려면 행 전체가 필요하다.
     *
     * @param  string  $block  생성 블록 원문
     * @return array<string, array{value: string|null, cols: array<int, string>}> 키 => 기존 행 정보
     */
    private function collectTableCells(string $block): array
    {
        $cells = [];

        foreach (explode("\n", $block) as $line) {
            $parsed = $this->parseTableRow($line);

            if ($parsed === null) {
                continue;
            }

            // 마지막 열(사람 서술)이 TODO 스텁이어도 예시값 열은 보존 대상이므로 행 자체는 수집한다.
            $cells[$parsed['key']] = [
                'value' => $this->isTodoCell($parsed['value']) ? null : $parsed['value'],
                'cols' => $parsed['cols'],
            ];
        }

        return $cells;
    }

    /**
     * 표 데이터 행을 파싱합니다 (헤더·구분선 제외).
     *
     * @param  string  $line  한 줄
     * @return array{key: string, value: string, prefix: string, cols: array<int, string>}|null 파싱 결과 (표 행이 아니면 null)
     */
    private function parseTableRow(string $line): ?array
    {
        if (! str_starts_with($line, '| ') || ! str_ends_with($line, ' |')) {
            return null;
        }

        // 헤더/구분선 제외
        if (str_starts_with($line, '| --- ')
            || str_starts_with($line, '| 이름 |')
            || str_starts_with($line, '| 필드 |')
            || str_starts_with($line, '| 상태코드 |')
        ) {
            return null;
        }

        // 셀 구분자가 아닌 파이프는 두 종류다 — 이스케이프된 파이프(`\|`)와 인라인 코드 스팬
        // 안의 파이프. 구분자로 오인하면 셀 수가 늘어 마지막 셀(=설명)이 파이프 뒤 조각으로
        // 바뀌고, 그 조각이 사람 서술로 승계돼 문장 앞부분이 잘린다. 두 경우 모두 가리는
        // splitRowCells 로 분해한다.
        $cols = array_map('trim', $this->splitRowCells($line));

        // 파라미터 표 6열(이름+위치가 키) / 응답 필드 표 4열(필드가 키) / 에러 응답 표 3열(상태코드가 키)
        $key = match (count($cols)) {
            6 => $cols[0].'|'.$cols[1],
            4 => $cols[0],
            3 => $cols[0],
            default => null,
        };

        if ($key === null || $cols[0] === '') {
            return null;
        }

        $lastIndex = count($cols) - 1;
        $prefix = '| '.implode(' | ', array_slice($cols, 0, $lastIndex)).' |';

        return ['key' => $key, 'value' => $cols[$lastIndex], 'prefix' => $prefix, 'cols' => $cols];
    }

    /**
     * 셀이 사람이 채워야 할 TODO 스텁인지 확인합니다.
     *
     * @param  string  $value  셀 값
     * @return bool TODO 스텁 여부
     */
    private function isTodoCell(string $value): bool
    {
        return $value === '' || str_contains($value, '<!-- TODO:');
    }

    /**
     * README 헤더(인용 블록)와 첫 생성 블록 사이의 사람 개요를 추출합니다.
     *
     * 개요(공통 규약: 인증·응답 봉투·에러·페이지네이션)는 목차 표보다 먼저 읽혀야 하므로
     * 생성 블록 앞에 둔다. 재생성 시 이 구간을 원문 그대로 되살려 사람 서술을 보존한다.
     *
     * @param  string  $existing  기존 README
     * @param  string  $key  목차 생성 블록 키
     * @return string|null 보존할 개요 (없으면 null)
     */
    private function extractReadmeOverview(string $existing, string $key): ?string
    {
        $startPos = strpos($existing, self::GEN_START.$key.' -->');
        if ($startPos === false) {
            return null;
        }

        $head = substr($existing, 0, $startPos);

        // 헤더 인용 블록(`> ...`) 의 마지막 줄 이후가 사람 개요다.
        if (! preg_match_all('/(?:^|\n)>[^\n]*/', $head, $all, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $last = end($all[0]);
        $overview = trim(substr($head, $last[1] + strlen($last[0])));

        return $overview === '' ? null : $overview;
    }

    /**
     * README 목차 블록 뒤 ~ 확장 목차 헤딩 앞의 사람 서술을 추출합니다.
     *
     * @param  string  $existing  기존 README
     * @param  string  $key  목차 생성 블록 키
     * @return string|null 보존할 사람 서술 (없으면 null)
     */
    private function extractReadmeTrailingProse(string $existing, string $key): ?string
    {
        $startPos = strpos($existing, self::GEN_START.$key.' -->');
        if ($startPos === false) {
            return null;
        }

        $endPos = strpos($existing, self::GEN_END, $startPos);
        if ($endPos === false) {
            return null;
        }

        $afterGen = substr($existing, $endPos + strlen(self::GEN_END));

        // 확장 목차 섹션(`## 확장 API 레퍼런스`)은 extensionIndexBlock() 이 헤딩까지 소유하므로 제외한다.
        // 그 외의 `## ` 헤딩(예: `## 개요`, `## 주의사항`)은 사람이 쓴 서술이므로 보존해야 한다.
        // (회귀: 임의의 `## ` 에서 잘라 목차 블록 뒤 사람 서술이 재생성 때마다 소실되던 문제)
        $cut = strpos($afterGen, "\n".self::EXTENSION_INDEX_HEADING);
        $prose = trim($cut !== false ? substr($afterGen, 0, $cut) : $afterGen);

        return $prose === '' ? null : $prose;
    }

    /**
     * 기존 문서에서 지정 키의 생성 블록을 헤딩·인용문 포함해 원문 그대로 추출합니다.
     *
     * `--scope` 축소 실행 등으로 이번 회차에 갱신 대상이 아닌 블록을 소실 없이 되살린다.
     *
     * @param  string  $existing  기존 문서
     * @param  string  $key  생성 블록 키
     * @return string|null 블록 원문 (없으면 null)
     */
    private function extractGeneratedBlock(string $existing, string $key): ?string
    {
        $startPos = strpos($existing, self::GEN_START.$key.' -->');
        if ($startPos === false) {
            return null;
        }

        $endPos = strpos($existing, self::GEN_END, $startPos);
        if ($endPos === false) {
            return null;
        }

        // 블록 앞의 섹션 헤딩(`## ...`)부터 함께 되살린다.
        $head = substr($existing, 0, $startPos);
        $headingPos = preg_match_all('/(?:^|\n)## [^\n]*/', $head, $all, PREG_OFFSET_CAPTURE)
            ? end($all[0])[1]
            : null;

        $from = $headingPos !== null ? $headingPos : $startPos;

        return trim(substr($existing, $from, ($endPos + strlen(self::GEN_END)) - $from));
    }

    /**
     * 기존 문서에서 이 엔드포인트의 생성 블록만 종료 마커까지 정확히 잘라냅니다.
     *
     * {@see self::extractGeneratedBlock()} 은 앞의 `## ` 헤딩까지 거슬러 올라가 여러 엔드포인트를
     * 함께 반환할 수 있고, {@see self::extractGeneratedBlockBody()} 는 종료 마커를 잘라내고
     * 돌려준다. 에러 표 병합처럼 "이 엔드포인트 하나 + 종료 마커를 끝 경계로" 가 필요한
     * 소비자를 위해 시작~종료 마커 구간을 원문 그대로 돌려준다.
     *
     * @param  string  $existing  기존 문서
     * @param  string  $key  생성 블록 키
     * @return string|null 블록 원문 (없으면 null)
     */
    private function exactGeneratedBlock(string $existing, string $key): ?string
    {
        $startPos = strpos($existing, self::GEN_START.$key.' -->');

        if ($startPos === false) {
            return null;
        }

        $endPos = strpos($existing, self::GEN_END, $startPos);

        if ($endPos === false) {
            return null;
        }

        return substr($existing, $startPos, ($endPos + strlen(self::GEN_END)) - $startPos);
    }

    /**
     * 기존 문서에서 특정 엔드포인트의 사람 서술(생성 블록 밖)을 추출합니다.
     *
     * @param  string  $existing  기존 문서
     * @param  string  $key  라우트명 키
     * @return string|null 보존할 사람 서술 (없으면 null)
     */
    private function extractHumanProse(string $existing, string $key): ?string
    {
        $startMarker = self::GEN_START.$key.' -->';
        $startPos = strpos($existing, $startMarker);

        if ($startPos === false) {
            return null;
        }

        $endPos = strpos($existing, self::GEN_END, $startPos);
        if ($endPos === false) {
            return null;
        }

        $afterGen = substr($existing, $endPos + strlen(self::GEN_END));
        // 다음 ### 헤딩 전까지가 이 엔드포인트의 사람 서술
        $nextHeading = preg_match('/\n### /', $afterGen, $m, PREG_OFFSET_CAPTURE)
            ? $m[0][1]
            : strlen($afterGen);

        $prose = trim(substr($afterGen, 0, $nextHeading));

        // 기본 TODO 스텁만 있으면 보존할 것 없음
        if ($prose === '' || Str::contains($prose, 'TODO: 이 엔드포인트의 용도')) {
            return null;
        }

        return $prose;
    }

    /**
     * 새 섹션의 기본 서술 스텁을 보존된 사람 서술로 치환합니다.
     *
     * @param  string  $section  새로 생성된 섹션
     * @param  string|null  $preserved  보존할 사람 서술
     * @return string 서술이 반영된 섹션
     */
    private function applyPreservedProse(string $section, ?string $preserved): string
    {
        if ($preserved === null) {
            return $section;
        }

        $stub = '**설명** <!-- TODO: 이 엔드포인트의 용도·주의사항·예시 시나리오를 작성하세요 -->';

        return str_replace($stub, $preserved, $section);
    }

    /**
     * 기존 문서의 각 엔드포인트 `@generated` 블록에 요청/응답 예시 블록을 in-place 삽입합니다.
     *
     * 전체 재생성(`endpointSection` + `mergeDocument`)은 `@generated` 블록 내부의
     * 파라미터/응답 필드 표를 통째로 다시 조립하므로, 정적 추출로 재현 불가능한 사람의
     * 도메인 서술 셀이 TODO 로 되돌아갑니다. 이 메서드는 표를 건드리지 않고 예시 2블록만
     * 표 뒤에 삽입/치환하여 그 퇴행 없이 예시를 방출합니다.
     *
     * 삽입 위치(스캐폴더 `endpointSection` 순서와 동일):
     *   - `**요청 예시**`  → `**응답 필드**` 헤딩 바로 앞
     *   - `**응답 예시**`  → `**에러 응답**` 헤딩 바로 앞
     *
     * 이미 예시 블록이 있으면(멱등) 그 블록만 새 내용으로 치환합니다.
     *
     * @param  string  $content  기존 문서 내용
     * @param  array<string, array{request: string, response: string}>  $exampleBlocks  라우트명 키 => 예시 블록
     * @return array{0: string, 1: int} [갱신된 문서, 삽입/치환한 예시 블록 수]
     */
    public function insertExampleBlocks(string $content, array $exampleBlocks): array
    {
        // 줄 끝 정규화 — mergeDocument 와 같은 이유 (CRLF 체크아웃에서 줄 단위 매칭이 전부 실패).
        $content = $this->normalizeEol($content);

        $inserted = 0;

        foreach ($exampleBlocks as $key => $blocks) {
            $startMarker = self::GEN_START.$key.' -->';
            $startPos = strpos($content, $startMarker);
            if ($startPos === false) {
                continue;
            }

            $endPos = strpos($content, self::GEN_END, $startPos);
            if ($endPos === false) {
                continue;
            }

            $block = substr($content, $startPos, $endPos - $startPos);

            // 이 엔드포인트의 사람 서술 영역(@generated:end 뒤 ~ 다음 ### 전)에 이미 사람이 작성한
            // `**응답 예시**` 가 있으면, 블록 안에 응답 예시 마커를 넣지 않는다(중복 헤딩 회피).
            // 요청 예시는 항상 삽입한다.
            $proseHasResponseExample = $this->proseHasResponseExample($content, $endPos);

            // 응답 필드 표가 실측 제외 마커(사람이 채운 셀 없음)이고 이번에 실측 표가 확보됐으면
            // 그 마커를 실측 필드 표로 갱신한다(쓰기 메서드 실측 도입분). 이미 필드 표(| 필드 | 타입 |)가
            // 있으면 건드리지 않아 사람 서술 셀을 보존한다.
            $updatedBlock = $block;

            // 요청 파라미터 표에서 path 행과 이름이 겹치는 query 행(자동 생성 중복)을 제거한다.
            // 라우트 바인딩 세그먼트가 FormRequest rules() 에도 있어 path/query 로 2회 출력되던 결함.
            $updatedBlock = $this->dedupeParamRows($updatedBlock, $inserted);

            if (! empty($blocks['response_fields'])) {
                $updatedBlock = $this->refreshResponseFieldMarker($updatedBlock, $blocks['response_fields'], $inserted);
            }

            $updatedBlock = $this->applyExampleBlock($updatedBlock, '**요청 예시**', '**응답 필드**', $blocks['request'], $inserted);

            if ($proseHasResponseExample) {
                // 서술 영역에 수기 응답 예시가 있으면 블록 안 응답 예시는 두지 않는다(중복 헤딩 회피).
                // 과거 run 이 삽입한 블록 내 응답 예시가 남아 있으면 제거한다.
                $updatedBlock = $this->removeExampleBlock($updatedBlock, '**응답 예시**', '**에러 응답**', $inserted);
            } else {
                $updatedBlock = $this->applyExampleBlock($updatedBlock, '**응답 예시**', '**에러 응답**', $blocks['response'], $inserted);
            }

            if ($updatedBlock !== $block) {
                $content = substr($content, 0, $startPos).$updatedBlock.substr($content, $endPos);
            }
        }

        return [$content, $inserted];
    }

    /**
     * 엔드포인트의 사람 서술 영역에 이미 사람이 작성한 `**응답 예시**` 가 있는지 확인합니다.
     *
     * 서술 영역 = `@generated:end` 뒤부터 다음 `### ` 헤딩(또는 문서 끝) 전까지. 여기에
     * `**응답 예시**` 가 있으면 블록 안에 응답 예시를 삽입하지 않아 중복 헤딩을 방지한다.
     * 파일 업로드(바이너리)·미설치 정적 예시처럼 실측 불가라 사람이 미리 채운 응답 예시가 대상.
     *
     * @param  string  $content  전체 문서 내용
     * @param  int  $endPos  이 엔드포인트 `@generated:end` 마커의 시작 위치
     * @return bool 서술 영역에 수기 응답 예시가 있으면 true
     */
    private function proseHasResponseExample(string $content, int $endPos): bool
    {
        $afterGen = substr($content, $endPos + strlen(self::GEN_END));

        // 다음 ### 헤딩 전까지가 이 엔드포인트의 사람 서술 영역
        $nextHeading = preg_match('/\n### /', $afterGen, $m, PREG_OFFSET_CAPTURE)
            ? $m[0][1]
            : strlen($afterGen);

        $prose = substr($afterGen, 0, $nextHeading);

        return Str::contains($prose, '**응답 예시**');
    }

    /**
     * 요청 파라미터 표에서 path 행과 이름이 겹치는 query 행(자동 생성 중복)을 제거합니다.
     *
     * 라우트 바인딩 세그먼트가 FormRequest rules() 에도 존재하면 같은 이름이 `| 이름 | path |` 과
     * `| 이름 | query |` 로 2회 출력되던 결함을 in-place 로 정정한다. path 행을 남기고 query 행만 제거.
     *
     * @param  string  $block  `@generated` 블록 문자열
     * @param  int  $inserted  정정 카운터 (참조 누적)
     * @return string 정정된 블록
     */
    private function dedupeParamRows(string $block, int &$inserted): string
    {
        $lines = explode("\n", $block);

        // path 행의 파라미터명 수집 (| name | path | ...)
        $pathNames = [];
        foreach ($lines as $line) {
            if (preg_match('/^\|\s*([a-zA-Z_0-9]+)\s*\|\s*path\s*\|/', $line, $m)) {
                $pathNames[$m[1]] = true;
            }
        }

        if ($pathNames === []) {
            return $block;
        }

        $out = [];
        $removed = false;
        foreach ($lines as $line) {
            // 같은 이름의 query 행이면 제거 (path 행이 우선).
            if (preg_match('/^\|\s*([a-zA-Z_0-9]+)\s*\|\s*query\s*\|/', $line, $m) && isset($pathNames[$m[1]])) {
                $removed = true;

                continue;
            }
            $out[] = $line;
        }

        if ($removed) {
            $inserted++;
        }

        return implode("\n", $out);
    }

    /**
     * 응답 필드 표가 실측 제외 마커일 때만 실측 필드 표로 치환합니다.
     *
     * `**응답 필드** (\`data\` 내부)` 헤딩 ~ 다음 헤딩(`**응답 예시**`/`**에러 응답**`) 사이 본문이
     * `<!-- 실측 제외: ... -->` 마커면 실측 표로 교체하고, 이미 표(`| 필드 | 타입 |`)가 있으면
     * 사람 서술 셀 보존을 위해 건드리지 않는다.
     *
     * @param  string  $block  `@generated` 블록 문자열
     * @param  string  $fieldTable  실측 응답 필드 표
     * @param  int  $inserted  치환 카운터 (참조 누적)
     * @return string 갱신된 블록
     */
    private function refreshResponseFieldMarker(string $block, string $fieldTable, int &$inserted): string
    {
        $label = '**응답 필드** (`data` 내부)';
        $labelPos = strpos($block, $label);
        if ($labelPos === false) {
            return $block;
        }

        $bodyStart = $labelPos + strlen($label);
        // 다음 헤딩(응답 예시 또는 에러 응답) 전까지가 응답 필드 본문
        $nextRespExample = strpos($block, '**응답 예시**', $bodyStart);
        $nextError = strpos($block, '**에러 응답**', $bodyStart);
        $candidates = array_filter([$nextRespExample, $nextError], fn ($v) => $v !== false);
        if ($candidates === []) {
            return $block;
        }
        $bodyEnd = min($candidates);

        $body = substr($block, $bodyStart, $bodyEnd - $bodyStart);

        // 사람이 채운 표(| 필드 | 타입 |)가 이미 있으면 보존 — 마커일 때만 교체.
        if (! str_contains($body, '실측 제외')) {
            return $block;
        }

        $inserted++;

        return substr($block, 0, $bodyStart)."\n\n".$fieldTable."\n\n".substr($block, $bodyEnd);
    }

    /**
     * `@generated` 블록 문자열에 예시 블록 하나를 삽입하거나 치환합니다.
     *
     * `$label`(예: `**요청 예시**`) 섹션이 이미 있으면 그 본문을 `$body` 로 치환하고,
     * 없으면 `$anchor`(예: `**응답 필드**`) 헤딩 바로 앞에 `label + body` 를 삽입합니다.
     *
     * @param  string  $block  `@generated` 블록 문자열
     * @param  string  $label  예시 섹션 헤딩 (`**요청 예시**` / `**응답 예시**`)
     * @param  string  $anchor  삽입 기준 헤딩 (`**응답 필드**` / `**에러 응답**`)
     * @param  string  $body  예시 블록 본문 (코드블록 또는 실측 제외 마커)
     * @param  int  $inserted  삽입/치환 카운터 (참조 누적)
     * @return string 갱신된 블록 문자열
     */
    private function applyExampleBlock(string $block, string $label, string $anchor, string $body, int &$inserted): string
    {
        $section = $label."\n\n".$body."\n\n";
        $labelLine = $label."\n";

        // 이미 예시 섹션이 있으면 그 label ~ (다음 빈 줄 + 다음 헤딩) 까지를 새 섹션으로 치환한다.
        $labelPos = strpos($block, $labelLine);
        if ($labelPos !== false) {
            $anchorPos = strpos($block, $anchor, $labelPos);
            if ($anchorPos === false) {
                return $block;
            }

            $replaced = substr($block, 0, $labelPos).$section.substr($block, $anchorPos);
            if ($replaced !== $block) {
                $inserted++;
            }

            return $replaced;
        }

        // 없으면 anchor 헤딩 바로 앞에 삽입.
        $anchorPos = strpos($block, $anchor);
        if ($anchorPos === false) {
            return $block;
        }

        $inserted++;

        return substr($block, 0, $anchorPos).$section.substr($block, $anchorPos);
    }

    /**
     * `@generated` 블록 문자열에서 예시 섹션 하나를 제거합니다.
     *
     * 서술 영역에 수기 예시가 있어 블록 안 예시가 중복이 되는 경우, `$label` 섹션
     * (`$label` ~ `$anchor` 직전)을 제거한다. 섹션이 없으면 그대로 반환한다.
     *
     * @param  string  $block  `@generated` 블록 문자열
     * @param  string  $label  제거할 예시 섹션 헤딩 (`**응답 예시**`)
     * @param  string  $anchor  섹션 끝 기준 헤딩 (`**에러 응답**`)
     * @param  int  $inserted  변경 카운터 (참조 누적)
     * @return string 갱신된 블록 문자열
     */
    private function removeExampleBlock(string $block, string $label, string $anchor, int &$inserted): string
    {
        $labelLine = $label."\n";
        $labelPos = strpos($block, $labelLine);
        if ($labelPos === false) {
            return $block;
        }

        $anchorPos = strpos($block, $anchor, $labelPos);
        if ($anchorPos === false) {
            return $block;
        }

        $removed = substr($block, 0, $labelPos).substr($block, $anchorPos);
        if ($removed !== $block) {
            $inserted++;
        }

        return $removed;
    }
}
