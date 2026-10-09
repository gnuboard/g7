<?php

namespace App\Support\LayoutMarkup;

use App\Enums\LayoutMarkupErrorCode;

/**
 * `.g7.html` 문서 하나 전체를 JSON 레이아웃으로 컴파일하는 최상위 층 (매핑 사전 A·B).
 *
 * 아래 층들이 각각 노드(S2)·액션(S3)·상태 초기화(S2)를 맡지만, 그것들을 조립해 **문서**를 만드는
 * 것은 이 층의 책임이다. 이 층이 확정하는 것은 넷이다.
 *
 * **① 루트가 문서의 종류를 정한다.** 소스 파일은 다섯 계층이고 봉투가 넷이다 — `<layout>`(본
 * 레이아웃), `<partial>`(루트가 노드인 조각), `<extension>`(확장 주입 fragment — 다른 봉투),
 * `<override>`(템플릿 오버라이드). 루트 태그가 무엇을 컴파일할지 정하므로, 노드 층은 이 넷을 노드
 * 자리에서 만나면 거부한다(RootTagInNodePosition).
 *
 * **② 최상위 섹션은 `<body>` 대신 섹션 태그로 온다.** `<meta>`·`<data-source>`·`<computed>`·
 * `<init-*>`·`<permissions>` 등은 컴포넌트가 아니라 문서 메타데이터라, 노드로 컴파일하지 않고 각
 * JSON 최상위 키로 방출한다. 같은 이름의 컴포넌트가 있는 태그(`<modal>`)는 **위치로** 갈린다 —
 * 문서 직속이면 섹션(modals), 노드 안이면 컴포넌트(Modal).
 *
 * **③ 상태 초기화는 중첩 태그 트리다.** `<init-local>`/`<init-global>`/`<init-isolated>` 는 JSON
 * 리터럴 덩어리(D5 폐기)가 아니라 `<field>`/`<from-source>` 트리로 초기값을 쓴다. 트리를 상태
 * 객체로 접는 것은 StateTreeCompiler 가 맡고 이 층은 블록을 대상 키에 병합만 한다.
 *
 * **④ 표현식은 건드리지 않는다.** 하위 층과 같은 규칙 — `{{...}}`/`$t:` 는 어느 위치에서도
 * 선평가하지 않는다(D3). computed 식·헤더 값·주입 조건 모두 원문 그대로 방출한다.
 *
 * 산출 JSON 의 최상위 키는 KEY_ORDER 로 고정 정렬한다 — diff·골든 테스트가 성립하려면 같은
 * 입력이 매번 같은 바이트를 내야 한다(하위 층과 동일 규율).
 */
final class DocumentCompiler
{
    /** 문서 루트 봉투 태그 → 컴파일 갈래 */
    private const ROOT_TAGS = ['layout', 'partial', 'extension', 'override'];

    /**
     * `<layout>`/`<override>` 안에서 한 번만 올 수 있는 최상위 섹션 (중복은 조용한 덮어쓰기).
     *
     * @var array<int, string>
     */
    private const SINGLETON_SECTIONS = [
        'meta', 'permissions', 'defines', 'error-handling', 'global-headers', 'transition-overlay', 'init',
    ];

    /**
     * JSON 최상위 키의 결정적 직렬화 순서 (LayoutService 의 extends 조립 순서와 정합).
     *
     * @var array<int, string>
     */
    private const KEY_ORDER = [
        'version', 'layout_name', 'extends', 'target_layout', 'extension_point', 'mode', 'priority',
        'meta', 'data_sources', 'initLocal', 'initGlobal', 'initIsolated', 'computed', 'permissions',
        'defines', 'errorHandling', 'globalHeaders', 'transition_overlay', 'scripts', 'named_actions',
        'init_actions', 'slots', 'components', 'modals', 'injections', 'version_constraint',
    ];

    private readonly NodeCompiler $nodes;

    private readonly ActionCompiler $actions;

    private readonly StateTreeCompiler $state;

    private readonly StructuredValueCompiler $values;

    /**
     * @param  ComponentRegistry|null  $registry  검사용 템플릿 컴포넌트 레지스트리 (산출에는 쓰지 않는다)
     */
    public function __construct(private readonly ?ComponentRegistry $registry = null)
    {
        $this->nodes = new NodeCompiler($registry);
        $this->actions = new ActionCompiler;
        $this->state = new StateTreeCompiler;
        $this->values = new StructuredValueCompiler(LayoutMarkupErrorCode::UnexpectedStructuralChild);
    }

    /**
     * 소스 위치 표식을 켜거나 끕니다 (7.1.0 P16).
     *
     * 켜면 노드·액션 배열마다 `LayoutLinter::SOURCE_POSITION_KEY => [줄, 칸]` 을 싣는다. 위치 없는 lint 경고의
     * 자리를 찾는 재컴파일에서만 켠다 — 저장·반환 JSON 에는 싣지 않는다.
     *
     * @param  bool  $on  켤지 여부
     * @return void 반환값 없음
     */
    public function markSourcePositions(bool $on): void
    {
        $this->nodes->markSourcePositions($on);
        $this->actions->markSourcePositions($on);
    }

    /**
     * 문서 루트 요소를 JSON 레이아웃으로 컴파일합니다.
     *
     * @param  array<int, MarkupElement>  $roots  트리 빌더가 만든 루트 요소들
     * @param  string|null  $path  소스 파일 경로 (오류 메시지용)
     * @return array<string, mixed> JSON 레이아웃
     *
     * @throws CompileError 루트가 없거나 둘 이상이거나 봉투·섹션 표기가 규칙을 벗어난 경우
     */
    public function compile(array $roots, ?string $path = null): array
    {
        if ($roots === []) {
            throw new CompileError(LayoutMarkupErrorCode::EmptyDocument, 1, 1, [], $path);
        }

        if (count($roots) > 1) {
            throw $this->error($roots[1], LayoutMarkupErrorCode::MultipleDocumentRoots, $path, [
                'tag' => $roots[1]->name,
            ]);
        }

        $root = $roots[0];

        // 봉투(`<layout>`·`<extension>`·`<override>`) 바로 안의 글자는 담길 노드가 없어 산출에서 조용히
        // 사라진다 — 문서 최상위 글자와 같은 이유로 거부한다(트리 빌더는 혼재 판정을 하위 컴파일러에
        // 넘기는데, 봉투 컴파일러는 본문 글자를 읽지 않아 판정 자리가 비어 있었다).
        // `<partial>` 은 루트가 곧 노드라 노드 컴파일러가 판정한다.
        if (in_array($root->name, ['layout', 'extension', 'override'], true)
            && $root->text !== null && trim($root->text) !== '') {
            $found = trim($root->text);

            throw $this->error($root, LayoutMarkupErrorCode::TextOutsideElement, $path, [
                'found' => mb_strlen($found, 'UTF-8') > 20 ? mb_substr($found, 0, 20, 'UTF-8').'…' : $found,
            ]);
        }

        $document = match ($root->name) {
            'layout' => $this->compileLayout($root, $path),
            'partial' => $this->compilePartial($root, $path),
            'extension' => $this->compileFragment($root, $path),
            'override' => $this->compileOverride($root, $path),
            default => throw $this->error($root, LayoutMarkupErrorCode::UnknownDocumentRoot, $path, [
                'tag' => $root->name,
            ]),
        };

        // 봉투 앞의 `<!-- -->` 은 문서 전체를 설명하는 주석이다. 다만 `<partial>` 은 노드를 최상위로
        // 펼치므로 노드가 이미 자기 `comment` 를 채웠을 수 있다 — 그때는 덮어쓰지 않는다(노드 주석이
        // 정본이고, 방출기도 그쪽에 찍는다).
        if ($root->comment !== null && ! isset($document['comment'])) {
            $document = ['comment' => $root->comment] + $document;
        }

        return $document;
    }

    /**
     * `<layout>` 봉투를 본 레이아웃 JSON 으로 컴파일합니다.
     *
     * @param  MarkupElement  $root  `<layout>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed> 레이아웃 JSON
     *
     * @throws CompileError 표기 규칙을 벗어난 경우
     */
    private function compileLayout(MarkupElement $root, ?string $path): array
    {
        $doc = [];
        $this->applyLayoutAttributes($root, $doc, $path);

        foreach ($root->children as $child) {
            $this->applySection($child, $doc, $path);
        }

        return $this->order($doc);
    }

    /**
     * `<layout>` 의 속성(name/version/extends)을 방출합니다.
     *
     * @param  MarkupElement  $root  `<layout>` 요소
     * @param  array<string, mixed>  $doc  문서 (참조)
     * @param  string|null  $path  소스 파일 경로
     * @return void 반환값 없음
     *
     * @throws CompileError 알 수 없는 속성인 경우
     */
    private function applyLayoutAttributes(MarkupElement $root, array &$doc, ?string $path): void
    {
        foreach ($root->attributes as $attribute) {
            match ($attribute->name) {
                'name' => $doc['layout_name'] = (string) $attribute->value,
                'version' => $doc['version'] = (string) $attribute->value,
                'extends' => $doc['extends'] = (string) $attribute->value,
                default => throw $this->attributeError($attribute, LayoutMarkupErrorCode::UnknownRootAttribute, $path, [
                    'attr' => $attribute->name,
                    'tag' => 'layout',
                ]),
            };
        }
    }

    /**
     * `<layout>`/`<override>` 직속 자식 하나를 최상위 섹션 또는 컴포넌트로 배치합니다.
     *
     * @param  MarkupElement  $child  직속 자식
     * @param  array<string, mixed>  $doc  문서 (참조)
     * @param  string|null  $path  소스 파일 경로
     * @return void 반환값 없음
     *
     * @throws CompileError 표기 규칙을 벗어난 경우
     */
    private function applySection(MarkupElement $child, array &$doc, ?string $path): void
    {
        if (in_array($child->name, self::SINGLETON_SECTIONS, true)) {
            $this->assertSectionOnce($child, $doc, $path);
        }

        switch ($child->name) {
            case 'meta':
                $doc['meta'] = $this->compileMeta($child, $path);

                return;

            case 'data-source':
                $doc['data_sources'][] = $this->compileDataSource($child, $path);

                return;

            case 'computed':
                $this->applyComputed($child, $doc, $path);

                return;

            case 'init-local':
            case 'init-global':
            case 'init-isolated':
                $this->applyInitState($child, $doc, $path);

                return;

            case 'init':
                $doc['init_actions'] = $this->compileInitActions($child, $path);

                return;

            case 'action':
                $this->applyNamedAction($child, $doc, $path);

                return;

            case 'permissions':
                $doc['permissions'] = (new PermissionsCompiler)->compile($child, $path);

                return;

            case 'defines':
                $doc['defines'] = $this->compileDefines($child, $path);

                return;

            case 'error-handling':
                $doc['errorHandling'] = $this->withLeadingComment(
                    $child,
                    $this->actions->compileErrorHandlingSection($child, $path),
                );

                return;

            case 'global-headers':
                $doc['globalHeaders'] = $this->compileGlobalHeaders($child, $path);

                return;

            case 'transition-overlay':
                $doc['transition_overlay'] = $this->compileTransitionOverlay($child, $path);

                return;

            case 'script':
                $doc['scripts'][] = $this->compileScript($child, $path);

                return;

            case 'slot':
                $this->applySlot($child, $doc, $path);

                return;

            case 'modal':
                $doc['modals'][] = $this->compileModal($child, $path);

                return;

            case 'json':
                // 표기 탈출구(S9) — 첫 급부 문법이 표현하지 못하는 최상위 키를 JSON 본문 그대로 방출.
                $doc[$this->requiredAttribute($child, 'key', $path)] = $this->decodeJsonBody($child, $path);

                return;
        }

        // 섹션 태그가 아니면 standalone 컴포넌트 노드다(slots 없는 레이아웃). 노드 층에 위임한다.
        $doc['components'][] = $this->nodes->compile($child, $path);
    }

    /**
     * `<meta>` 를 meta 객체로 컴파일합니다 (속성 → 키, `<seo>` 자식 → meta.seo).
     *
     * @param  MarkupElement  $element  `<meta>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed> meta 객체
     *
     * @throws CompileError 표기 규칙을 벗어난 경우
     */
    private function compileMeta(MarkupElement $element, ?string $path): array
    {
        $meta = $this->attributesToObject($element, $path);

        foreach ($element->children as $child) {
            if ($child->name !== 'seo') {
                throw $this->error($child, LayoutMarkupErrorCode::UnexpectedStructuralChild, $path, [
                    'tag' => $child->name,
                    'parent' => 'meta',
                ]);
            }

            if (array_key_exists('seo', $meta)) {
                throw $this->error($child, LayoutMarkupErrorCode::DuplicateSection, $path, ['tag' => 'seo']);
            }

            $meta['seo'] = $this->compileSeo($child, $path);
        }

        return $meta;
    }

    /**
     * `<seo>` 를 meta.seo 객체로 컴파일합니다 (10필드 — 구조값 자식은 `<field>`/`<item>` 트리).
     *
     * og·vars·structured_data 는 임의 깊이 객체라 `<seo>` 속성으로는 못 편다. extensions 는
     * `{type,id}` 배열, data_sources 는 string[] 이라 마찬가지다. 이 다섯 자리의 값 표기는
     * **다른 구조값 자리와 같은 문법**을 쓴다 (D51 G14 — `StructuredValueCompiler` 단일 SSoT):
     * 평면 스칼라 맵은 속성 field-map(`<og type="website"/>`), 그 밖은 `<field>`/`<item>` 트리다.
     *
     * S4(D31)는 이 다섯을 **JSON 본문 전용**으로 두었는데, D42 가 구조값 표기를 도입하면서
     * 역변환기는 구조값 표기를 내고 컴파일러는 JSON 본문만 받는 **비대칭**이 남았다 — `seo` 를 쓰는
     * 레이아웃이 admin 웨이브(W3)에 없어 W4 착수 실측에서야 드러났다(escape 13건). JSON 본문 표기는
     * 하위 호환으로 계속 받는다(표기를 주지 않은 형태의 정본 탈출구 — `<define>` 과 같은 규율).
     *
     * @param  MarkupElement  $element  `<seo>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed> seo 객체
     *
     * @throws CompileError 표기 규칙을 벗어난 경우
     */
    private function compileSeo(MarkupElement $element, ?string $path): array
    {
        $seo = $this->attributesToObject($element, $path);

        foreach ($element->children as $child) {
            $key = match ($child->name) {
                'og' => 'og',
                'vars' => 'vars',
                'extensions' => 'extensions',
                'structured-data' => 'structured_data',
                'data-sources' => 'data_sources',
                default => throw $this->error($child, LayoutMarkupErrorCode::UnexpectedStructuralChild, $path, [
                    'tag' => $child->name,
                    'parent' => 'seo',
                ]),
            };

            if (array_key_exists($key, $seo)) {
                throw $this->error($child, LayoutMarkupErrorCode::DuplicateSection, $path, ['tag' => $child->name]);
            }

            $seo[$key] = $this->compileStructuredValue($child, $path);
        }

        return $seo;
    }

    /**
     * `<data-source>` 를 data_sources 항목으로 컴파일합니다 (매핑 사전 B).
     *
     * @param  MarkupElement  $element  `<data-source>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed> data_sources 항목
     *
     * @throws CompileError 표기 규칙을 벗어난 경우
     */
    private function compileDataSource(MarkupElement $element, ?string $path): array
    {
        $source = [];

        foreach ($element->attributes as $attribute) {
            $this->applyDataSourceAttribute($attribute, $element->name, $source, $path);
        }

        foreach ($element->children as $child) {
            $this->applyDataSourceChild($child, $source, $path);
        }

        if ($element->comment !== null) {
            $source['comment'] = $element->comment;
        }

        return $source;
    }

    /**
     * `<data-source>` 속성 하나를 배치합니다 (`:attr` 타입 리터럴, 무접두 문자열).
     *
     * @param  MarkupAttribute  $attribute  속성
     * @param  string  $tag  태그명 (오류 메시지용)
     * @param  array<string, mixed>  $source  data_sources 항목 (참조)
     * @param  string|null  $path  소스 파일 경로
     * @return void 반환값 없음
     *
     * @throws CompileError 표기 규칙을 벗어난 경우
     */
    private function applyDataSourceAttribute(MarkupAttribute $attribute, string $tag, array &$source, ?string $path): void
    {
        $typed = str_starts_with($attribute->name, ':');
        $key = $typed ? substr($attribute->name, 1) : $attribute->name;

        if ($key === '') {
            throw $this->attributeError($attribute, LayoutMarkupErrorCode::InvalidAttributeName, $path, ['found' => $attribute->name]);
        }

        if (array_key_exists($key, $source)) {
            throw $this->attributeError($attribute, LayoutMarkupErrorCode::DuplicateAttribute, $path, ['tag' => $tag, 'attr' => $key]);
        }

        $source[$key] = $typed
            ? MarkupValue::decodeTypeLiteral($attribute, $key, $path)
            : ($attribute->isBoolean() ? true : $attribute->value);
    }

    /**
     * `<data-source>` 구조체 값 자식(static data·onSuccess/onError·init 트리 등)을 배치합니다.
     *
     * @param  MarkupElement  $child  자식 요소
     * @param  array<string, mixed>  $source  data_sources 항목 (참조)
     * @param  string|null  $path  소스 파일 경로
     * @return void 반환값 없음
     *
     * @throws CompileError 표기 규칙을 벗어난 경우
     */
    private function applyDataSourceChild(MarkupElement $child, array &$source, ?string $path): void
    {
        switch ($child->name) {
            case 'data':
            case 'params':
            case 'headers':
            case 'fallback':
                $key = $child->name;
                $this->assertDataSourceKeyUnset($child, $source, $key, $path);
                $source[$key] = $this->compileStructuredValue($child, $path);

                return;

            case 'success':
                $this->assertDataSourceKeyUnset($child, $source, 'onSuccess', $path);
                $source['onSuccess'] = $this->compileCallback($child, $path);

                return;

            case 'fail':
                $this->assertDataSourceKeyUnset($child, $source, 'onError', $path);
                $source['onError'] = $this->compileCallback($child, $path);

                return;

            case 'error-handling':
                $this->assertDataSourceKeyUnset($child, $source, 'errorHandling', $path);
                $source['errorHandling'] = $this->withLeadingComment(
                    $child,
                    $this->actions->compileErrorHandlingSection($child, $path),
                );

                return;

            case 'init-local':
            case 'init-global':
            case 'init-isolated':
                $this->applyDataSourceInit($child, $source, $path);

                return;

            case 'all-of':
            case 'any-of':
                $this->assertDataSourceKeyUnset($child, $source, 'conditions', $path);
                $source['conditions'] = $this->compileConditions($child, $path);

                return;

            case 'on-receive':
                $this->assertDataSourceKeyUnset($child, $source, 'onReceive', $path);
                $source['onReceive'] = $this->compileCallback($child, $path);

                return;

            case 'field':
                // 임의 이름의 구조값 키 (D42 G11) — `depends_on` 리스트·`errorCondition` 객체 등.
                // 나머지 키는 속성으로 가는데, 값이 객체·배열이면 속성이 통짜 JSON 이 된다.
                // 표기 규칙은 액션 `<field name>`(G6)·`<prop>`(G3) 과 같은 `StructuredValueCompiler`.
                $name = (string) $this->requiredAttribute($child, 'name', $path);
                $this->assertDataSourceKeyUnset($child, $source, $name, $path);
                $source[$name] = $this->values->compileChildren($child, $path);

                return;
        }

        throw $this->error($child, LayoutMarkupErrorCode::UnexpectedStructuralChild, $path, [
            'tag' => $child->name,
            'parent' => 'data-source',
        ]);
    }

    /**
     * 데이터소스 레벨 `<init-*>` 블록을 initLocal/initGlobal/initIsolated 에 병합합니다.
     *
     * @param  MarkupElement  $child  `<init-*>` 요소
     * @param  array<string, mixed>  $source  data_sources 항목 (참조)
     * @param  string|null  $path  소스 파일 경로
     * @return void 반환값 없음
     *
     * @throws CompileError 표기 규칙을 벗어난 경우
     */
    private function applyDataSourceInit(MarkupElement $child, array &$source, ?string $path): void
    {
        $key = match ($child->name) {
            'init-local' => 'initLocal',
            'init-global' => 'initGlobal',
            'init-isolated' => 'initIsolated',
        };

        $source[$key] = $this->mergeInitContribution($source[$key] ?? null, $child, $path);
    }

    /**
     * `<computed name="x">식</computed>` 항목을 computed 에 배치합니다.
     *
     * @param  MarkupElement  $element  `<computed>` 요소
     * @param  array<string, mixed>  $doc  문서 (참조)
     * @param  string|null  $path  소스 파일 경로
     * @return void 반환값 없음
     *
     * @throws CompileError 이름이 없거나 중복인 경우
     */
    private function applyComputed(MarkupElement $element, array &$doc, ?string $path): void
    {
        $name = $this->requiredAttribute($element, 'name', $path);

        if (isset($doc['computed'][$name])) {
            throw $this->error($element, LayoutMarkupErrorCode::DuplicateSection, $path, ['tag' => 'computed:'.$name]);
        }

        $doc['computed'][$name] = (string) ($element->text ?? '');
    }

    /**
     * `<init-local>`/`<init-global>`/`<init-isolated>` 블록을 해당 최상위 키에 병합합니다.
     *
     * @param  MarkupElement  $element  `<init-*>` 요소
     * @param  array<string, mixed>  $doc  문서 (참조)
     * @param  string|null  $path  소스 파일 경로
     * @return void 반환값 없음
     *
     * @throws CompileError 표기 규칙을 벗어난 경우
     */
    private function applyInitState(MarkupElement $element, array &$doc, ?string $path): void
    {
        $key = match ($element->name) {
            'init-local' => 'initLocal',
            'init-global' => 'initGlobal',
            'init-isolated' => 'initIsolated',
        };

        $doc[$key] = $this->mergeInitContribution($doc[$key] ?? null, $element, $path);
    }

    /**
     * `<init-*>` 기여를 기존 값에 병합합니다 — 비어도 **map** 으로 남깁니다.
     *
     * `initLocal`/`initGlobal`/`initIsolated` 는 정의상 이름표 붙은 값 묶음이다. PHP 배열은
     * list 와 map 을 구분하지 않으므로 기여가 비면 `json_encode` 가 `[]` 를 내고, 프론트는 그것을
     * 순서 목록으로 읽는다. 그 값이 요청 본문·질의로 실려 가면 서버가 대상을 찾지 못해 422 나
     * 침묵 미적용이 되며, 화면도 안 깨지고 로그도 안 남는다({@see EmptyMap}).
     *
     * @param  mixed  $current  기존 값 (없으면 null, 빈 map 표식일 수 있음)
     * @param  MarkupElement  $element  `<init-*>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed>|EmptyMap 병합 결과 (비면 빈 map 표식)
     *
     * @throws CompileError 표기 규칙을 벗어난 경우
     */
    private function mergeInitContribution(mixed $current, MarkupElement $element, ?string $path): array|EmptyMap
    {
        $base = (is_array($current) ? $current : []);
        $merged = array_merge($base, $this->state->compileInitBlock($element, $path));

        return $merged === [] ? EmptyMap::instance() : $merged;
    }

    /**
     * `<init>` 의 자식들을 init_actions 배열로 컴파일합니다 (축소 화이트리스트 — S3).
     *
     * @param  MarkupElement  $element  `<init>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<int, array<string, mixed>> init_actions 배열
     *
     * @throws CompileError 자식이 없거나 화이트리스트 밖 키인 경우
     */
    private function compileInitActions(MarkupElement $element, ?string $path): array
    {
        if ($element->children === []) {
            throw $this->error($element, LayoutMarkupErrorCode::EmptyActionBlock, $path, ['tag' => 'init']);
        }

        return array_map(
            fn (MarkupElement $child): array => $this->actions->compileInitAction($child, $path),
            $element->children,
        );
    }

    /**
     * `<action name="x">` 을 named_actions 에 배치합니다.
     *
     * @param  MarkupElement  $element  `<action name>` 요소
     * @param  array<string, mixed>  $doc  문서 (참조)
     * @param  string|null  $path  소스 파일 경로
     * @return void 반환값 없음
     *
     * @throws CompileError 이름이 없거나 중복이거나 표기 규칙을 벗어난 경우
     */
    private function applyNamedAction(MarkupElement $element, array &$doc, ?string $path): void
    {
        $name = $this->requiredAttribute($element, 'name', $path);

        if (isset($doc['named_actions'][$name])) {
            throw $this->error($element, LayoutMarkupErrorCode::DuplicateSection, $path, ['tag' => 'action:'.$name]);
        }

        $doc['named_actions'][$name] = $this->withLeadingComment(
            $element,
            $this->actions->compileNamedAction($element, $path),
        );
    }

    /**
     * 요소 앞의 `<!-- -->` 을 그 섹션의 `comment` 키로 되붙입니다.
     *
     * 주석은 작성자가 남긴 설명이라 왕복에서 사라지면 안 된다(§189 편집 보존). 노드와 데이터소스는
     * 이미 이 규칙을 따르는데 섹션 단위(named action·errorHandling·globalHeaders 항목·문서 루트)는
     * 빠져 있어, 그 자리의 주석이 `<json>` 탈출구로 새고 있었다.
     *
     * @param  MarkupElement  $element  대상 요소
     * @param  array<string, mixed>  $compiled  컴파일 결과
     * @return array<string, mixed> `comment` 가 앞에 붙은 결과
     */
    private function withLeadingComment(MarkupElement $element, array $compiled): array
    {
        if ($element->comment === null) {
            return $compiled;
        }

        return ['comment' => $element->comment] + $compiled;
    }

    /**
     * `<defines>` 의 `<define name="x">JSON</define>` 자식들을 defines 객체로 컴파일합니다.
     *
     * defines 값은 임의 구조(국가 목록 등)라 속성으로 못 편다. 표현 가능한 값은 `<field>`/`<item>`
     * 트리로 적고(D42 G12 — `<prop>`·데이터소스와 같은 `StructuredValueCompiler`), 표기를 주지 않은
     * 형태(혼합 스칼라 리스트 등)만 JSON 본문으로 남는다.
     *
     * @param  MarkupElement  $element  `<defines>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed> defines 객체
     *
     * @throws CompileError 자식이 `<define>` 이 아니거나 이름 중복인 경우
     */
    private function compileDefines(MarkupElement $element, ?string $path): array
    {
        $defines = [];

        foreach ($element->children as $child) {
            if ($child->name !== 'define') {
                throw $this->error($child, LayoutMarkupErrorCode::UnexpectedStructuralChild, $path, [
                    'tag' => $child->name,
                    'parent' => 'defines',
                ]);
            }

            $name = $this->requiredAttribute($child, 'name', $path);

            if (array_key_exists($name, $defines)) {
                throw $this->error($child, LayoutMarkupErrorCode::DuplicateSection, $path, ['tag' => 'define:'.$name]);
            }

            $defines[$name] = $child->children !== []
                ? $this->values->compileChildren($child, $path)
                : $this->decodeJsonBody($child, $path);
        }

        return $defines;
    }

    /**
     * `<global-headers>` 의 `<match pattern>` 자식들을 `[{pattern, headers}]` 로 컴파일합니다.
     *
     * @param  MarkupElement  $element  `<global-headers>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<int, array<string, mixed>> globalHeaders 배열
     *
     * @throws CompileError 자식이 `<match>` 가 아니거나 pattern 이 없는 경우
     */
    private function compileGlobalHeaders(MarkupElement $element, ?string $path): array
    {
        $headers = [];

        foreach ($element->children as $child) {
            if ($child->name !== 'match') {
                throw $this->error($child, LayoutMarkupErrorCode::UnexpectedStructuralChild, $path, [
                    'tag' => $child->name,
                    'parent' => 'global-headers',
                ]);
            }

            $pattern = $this->requiredAttribute($child, 'pattern', $path);
            $entry = [];

            // 앞선 `<!-- -->` 은 이 항목의 소스 주석이다 — `data-source` 와 같은 규칙.
            if ($child->comment !== null) {
                $entry['comment'] = $child->comment;
            }

            $entry += ['pattern' => $pattern, 'headers' => []];

            foreach ($child->attributes as $attribute) {
                if ($attribute->name === 'pattern') {
                    continue;
                }

                $entry['headers'][$attribute->name] = $attribute->isBoolean() ? true : $attribute->value;
            }

            $headers[] = $entry;
        }

        return $headers;
    }

    /**
     * `<transition-overlay>` 를 boolean 축약 또는 객체형으로 컴파일합니다.
     *
     * 자식·값 있는 속성이 하나도 없고 bare 불리언 `enabled` 만 있으면 boolean 축약(`true`)이고,
     * 그 밖은 객체형이다 — `{enabled, style, target, fallback_target, wait_for[], skeleton, spinner}`.
     * skeleton·spinner 는 `<skeleton .../>`·`<spinner .../>` 의 속성을 객체로, wait_for 는
     * `<wait-for>src</wait-for>` 본문을 배열로 모은다.
     *
     * @param  MarkupElement  $element  `<transition-overlay>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return bool|array<string, mixed> transition_overlay 값
     *
     * @throws CompileError 표기 규칙을 벗어난 경우
     */
    private function compileTransitionOverlay(MarkupElement $element, ?string $path): bool|array
    {
        $enabledAttr = $element->attribute('enabled');
        $onlyBareEnabled = $element->children === []
            && count($element->attributes) === 1
            && $enabledAttr !== null
            && $enabledAttr->isBoolean();

        if ($onlyBareEnabled) {
            return true;
        }

        $overlay = [];

        foreach ($element->attributes as $attribute) {
            $typed = str_starts_with($attribute->name, ':');
            $key = match ($typed ? substr($attribute->name, 1) : $attribute->name) {
                'enabled' => 'enabled',
                'style' => 'style',
                'target' => 'target',
                'fallback-target' => 'fallback_target',
                default => throw $this->attributeError($attribute, LayoutMarkupErrorCode::UnknownRootAttribute, $path, [
                    'attr' => $attribute->name,
                    'tag' => 'transition-overlay',
                ]),
            };

            $overlay[$key] = $key === 'enabled'
                ? ($typed ? MarkupValue::decodeTypeLiteral($attribute, 'enabled', $path) : true)
                : (string) $attribute->value;
        }

        foreach ($element->children as $child) {
            switch ($child->name) {
                case 'wait-for':
                    $overlay['wait_for'][] = (string) ($child->text ?? '');
                    break;

                case 'skeleton':
                case 'spinner':
                    if (array_key_exists($child->name, $overlay)) {
                        throw $this->error($child, LayoutMarkupErrorCode::DuplicateSection, $path, ['tag' => $child->name]);
                    }
                    $overlay[$child->name] = $this->attributesToObject($child, $path);
                    break;

                default:
                    throw $this->error($child, LayoutMarkupErrorCode::UnexpectedStructuralChild, $path, [
                        'tag' => $child->name,
                        'parent' => 'transition-overlay',
                    ]);
            }
        }

        return $overlay;
    }

    /**
     * `<script>` 를 scripts 항목으로 컴파일합니다 (외부 JS 로더 — src/id/async/if).
     *
     * @param  MarkupElement  $element  `<script>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed> scripts 항목
     *
     * @throws CompileError 표기 규칙을 벗어난 경우
     */
    private function compileScript(MarkupElement $element, ?string $path): array
    {
        $script = [];

        foreach ($element->attributes as $attribute) {
            $typed = str_starts_with($attribute->name, ':');
            $bare = $typed ? substr($attribute->name, 1) : $attribute->name;

            match ($bare) {
                'src' => $script['src'] = (string) $attribute->value,
                'id' => $script['id'] = (string) $attribute->value,
                // 로드 실패 안내에 쓸 사람이 읽는 이름(`$t:키` 허용) — 없으면 엔진이 일반 이름을 쓴다.
                'label' => $script['label'] = (string) $attribute->value,
                'async' => $script['async'] = $typed
                    ? MarkupValue::decodeTypeLiteral($attribute, 'async', $path)
                    : ($attribute->isBoolean() ? true : $attribute->value),
                'data-if' => $script['if'] = MarkupValue::normalizeCondition(
                    (string) $attribute->value, $attribute->line, $attribute->column, 'data-if', ':data-if', $path,
                ),
                default => throw $this->attributeError($attribute, LayoutMarkupErrorCode::UnknownRootAttribute, $path, [
                    'attr' => $attribute->name,
                    'tag' => 'script',
                ]),
            };
        }

        foreach (['src', 'id'] as $required) {
            if (! array_key_exists($required, $script)) {
                throw $this->error($element, LayoutMarkupErrorCode::MissingRequiredAttribute, $path, [
                    'tag' => 'script',
                    'attr' => $required,
                ]);
            }
        }

        return $script;
    }

    /**
     * `<slot name="x">` 자식 노드들을 slots[x] 배열로 배치합니다.
     *
     * @param  MarkupElement  $element  `<slot name>` 요소
     * @param  array<string, mixed>  $doc  문서 (참조)
     * @param  string|null  $path  소스 파일 경로
     * @return void 반환값 없음
     *
     * @throws CompileError 이름이 없거나 중복인 경우
     */
    private function applySlot(MarkupElement $element, array &$doc, ?string $path): void
    {
        $name = $this->requiredAttribute($element, 'name', $path);

        if (isset($doc['slots'][$name])) {
            throw $this->error($element, LayoutMarkupErrorCode::DuplicateSection, $path, ['tag' => 'slot:'.$name]);
        }

        $doc['slots'][$name] = array_map(
            fn (MarkupElement $child): array => $this->nodes->compile($child, $path),
            $element->children,
        );
    }

    /**
     * `<modal src>` 를 modals 참조로, `<modal [name]>` 본문을 인라인 노드(+`layout_name`·`data_sources`)로 컴파일합니다.
     *
     * 본문 루트의 `<data-source>` 자식은 노드가 아니라 모달의 `data_sources` 키가 된다 — 조각 루트와
     * 같은 규약이라 분리기({@see compilePartialNode()})를 공유한다.
     *
     * @param  MarkupElement  $element  `<modal>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed> modals 항목
     *
     * @throws CompileError 표기 규칙을 벗어난 경우
     */
    private function compileModal(MarkupElement $element, ?string $path): array
    {
        $src = $element->attribute('src');
        $name = $element->attribute('name');

        if ($src !== null) {
            if ($element->children !== []) {
                throw $this->error($element, LayoutMarkupErrorCode::ModalSrcWithBody, $path);
            }

            // `name` 은 인라인 항목의 `layout_name` 표현이다 — 참조 항목은 조각 파일이 이름을 갖는다.
            if ($name !== null) {
                throw $this->error($element, LayoutMarkupErrorCode::ModalNameWithSrc, $path);
            }

            $reference = (string) $src->value;

            // 표기 규약도 `<include src>` 와 같다 — 소스를 가리키고 산출 JSON 이 `.json` 을
            // 유지한다. 두 자리가 규칙을 복제하지 않도록 변환은 PartialReferenceNotation 한 벌만 쓴다.
            if (! PartialReferenceNotation::isAuthoringNotation($reference)) {
                throw $this->error($element, LayoutMarkupErrorCode::PartialSrcNotAuthoring, $path, [
                    'tag' => $element->name,
                    'src' => $reference,
                    'suggestion' => PartialReferenceNotation::toMarkup($reference),
                ]);
            }

            // 참조 항목의 주석은 "언제 뜨는 창인가" 를 적는 자리다 — 노드의 partial 참조가
            // `{comment, partial}` 를 싣는 것과 같은 형태로 보존한다(NodeCompiler::compileInclude).
            return $this->withLeadingComment($element, ['partial' => PartialReferenceNotation::toJson($reference)]);
        }

        if (count($element->children) !== 1) {
            throw $this->error($element, LayoutMarkupErrorCode::ModalInlineNotSingleNode, $path);
        }

        // 모달 본문 루트는 `<data-source>`(창이 열릴 때 받는 것)를 얹을 수 있다 — 조각 루트와 같은
        // 규약이라 같은 분리기를 쓴다. 설치가 `<modal src>` 조각을 인라인하면 그 조각의 data_sources 가
        // 이 자리로 내려오므로(DB 행), 편집기가 연 문서를 소스로 되돌릴 자리가 필요하다.
        [$node, $dataSources] = $this->compilePartialNode($element->children[0], $path);

        // 인라인 항목은 래퍼와 안쪽 노드가 같은 하나의 모달이라, 래퍼에 달린 주석을 노드로 올린다
        // (문서 봉투 주석을 루트 노드로 올리는 것과 같은 관용구). 둘 다 달렸으면 올릴 자리가 없고,
        // 그 경우는 파사드의 주석 귀속 검사가 거부한다 — 여기서 조용히 하나를 버리지 않는다.
        if ($element->comment !== null && ! isset($node['comment'])) {
            $node = ['comment' => $element->comment] + $node;
        }

        // `<modal name>` 은 인라인 항목의 `layout_name` 이다 — 설치가 `<modal src>` 조각을 인라인할 때
        // 그 조각의 이름을 이 키로 남기므로(DB 행), 편집기가 연 문서를 다시 소스로 되돌릴 자리가 있어야
        // 한다. `<partial name>` 이 최상위 `layout_name` 으로 접히는 것과 같은 규율이고 meta 로 흘리지 않는다.
        if ($name !== null) {
            $node['layout_name'] = (string) $name->value;
        }

        if ($dataSources !== []) {
            $node['data_sources'] = $dataSources;
        }

        return $node;
    }

    /**
     * `<partial>` 봉투를 루트 노드 + meta.is_partial 로 컴파일합니다.
     *
     * partial 은 루트가 노드다 — 자식 하나가 조각의 루트 노드이고, `<partial>` 속성은 meta 로
     * 접힌다(is_partial 은 항상 참). 모달 조각은 그 루트 노드에 `<data-source>`(오픈 시 fetch)를
     * 얹을 수 있어, 노드로 넘기기 전에 데이터소스 자식을 분리해 data_sources 키로 올린다.
     *
     * @param  MarkupElement  $root  `<partial>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed> partial JSON
     *
     * @throws CompileError 루트 노드가 하나가 아니거나 표기 규칙을 벗어난 경우
     */
    private function compilePartial(MarkupElement $root, ?string $path): array
    {
        if (count($root->children) !== 1) {
            throw $this->error($root, LayoutMarkupErrorCode::PartialRootNotSingleNode, $path);
        }

        $attributes = $this->attributesToObject($root, $path);

        // `<partial name>` 은 `<layout name>` 과 같이 최상위 `layout_name` 이다 — meta 로 흘리지 않는다.
        // partial 은 DB 미등록이라 이 이름이 라우팅에 쓰이진 않으나, 원본이 지닌 값이므로 왕복 보존한다.
        $layoutName = $attributes['name'] ?? null;
        unset($attributes['name']);

        $meta = ['is_partial' => true] + $attributes;
        [$node, $dataSources] = $this->compilePartialNode($root->children[0], $path);

        // 조각 문서는 루트 노드 자체라 meta 가 한 자리뿐이다. 루트 노드 안의 `<meta>` 를 받아 주면
        // `<partial>` 속성 meta 에 덮여 소리 없이 사라지므로(편집기 라벨 등이 빠진 채 굽힘) 거부한다.
        if (isset($node['meta'])) {
            throw $this->error($root->children[0], LayoutMarkupErrorCode::PartialRootMetaNotAllowed, $path);
        }

        $doc = ['meta' => $meta];

        if ($layoutName !== null) {
            $doc['layout_name'] = (string) $layoutName;
        }

        if ($dataSources !== []) {
            $doc['data_sources'] = $dataSources;
        }

        return $doc + $node;
    }

    /**
     * partial 루트 노드를 컴파일하되 모달 루트의 `<data-source>` 자식을 분리해 올립니다.
     *
     * @param  MarkupElement  $element  루트 노드 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>} `[노드, data_sources]`
     *
     * @throws CompileError 표기 규칙을 벗어난 경우
     */
    private function compilePartialNode(MarkupElement $element, ?string $path): array
    {
        $dataSources = [];
        $rest = [];

        foreach ($element->children as $child) {
            if ($child->name === 'data-source') {
                $dataSources[] = $this->compileDataSource($child, $path);

                continue;
            }

            $rest[] = $child;
        }

        // 데이터소스를 걷어낸 요소로 노드를 컴파일한다 — MarkupElement 는 불변이라 새로 짓는다.
        $filtered = $dataSources === [] ? $element : new MarkupElement(
            $element->name,
            $element->attributes,
            $rest,
            $element->text,
            $element->comment,
            $element->selfClosing,
            $element->line,
            $element->column,
        );

        return [$this->nodes->compile($filtered, $path), $dataSources];
    }

    /**
     * `<extension>` 봉투를 확장 주입 fragment JSON 으로 컴파일합니다.
     *
     * fragment 는 레이아웃과 다른 봉투다 — `target-layout`(→injections) XOR `extension-point`
     * (→components) 로 갈리고, `priority`(주입 순서)·`mode`·부수 섹션(data-source/script/modal)을
     * 가진다.
     *
     * @param  MarkupElement  $root  `<extension>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed> fragment JSON
     *
     * @throws CompileError 대상 표기가 충돌·부재하거나 표기 규칙을 벗어난 경우
     */
    private function compileFragment(MarkupElement $root, ?string $path): array
    {
        $targetLayout = $root->attribute('target-layout');
        $extensionPoint = $root->attribute('extension-point');

        if ($targetLayout !== null && $extensionPoint !== null) {
            throw $this->error($root, LayoutMarkupErrorCode::FragmentTargetConflict, $path);
        }

        if ($targetLayout === null && $extensionPoint === null) {
            throw $this->error($root, LayoutMarkupErrorCode::FragmentTargetMissing, $path);
        }

        $doc = [];
        $this->applyFragmentAttributes($root, $doc, $path);

        foreach ($root->children as $child) {
            if ($child->name === 'inject') {
                if ($extensionPoint !== null) {
                    throw $this->error($child, LayoutMarkupErrorCode::UnexpectedFragmentChild, $path, [
                        'tag' => 'inject',
                    ]);
                }

                $doc['injections'][] = $this->compileInjection($child, $path);

                continue;
            }

            // 부수 섹션 — 두 형태 모두 data-source/script/modal 을 허용한다.
            if (in_array($child->name, ['data-source', 'script', 'modal'], true)) {
                $this->applySection($child, $doc, $path);

                continue;
            }

            // `<init>` — 주입 시 실행할 초기 액션 (축소 화이트리스트, 본 레이아웃과 동일).
            // 예: 통화 선택기 주입 시 현재 통화 로드. 두 형태(target_layout/extension_point) 모두 허용.
            if ($child->name === 'init') {
                $doc['init_actions'] = $this->compileInitActions($child, $path);

                continue;
            }

            // 나머지 직속 노드는 extension_point 형의 주입 컴포넌트다.
            if ($extensionPoint === null) {
                throw $this->error($child, LayoutMarkupErrorCode::UnexpectedFragmentChild, $path, [
                    'tag' => $child->name,
                ]);
            }

            $doc['components'][] = $this->nodes->compile($child, $path);
        }

        return $this->order($doc);
    }

    /**
     * `<extension>` 의 봉투 속성(target-layout/extension-point/mode/priority)을 방출합니다.
     *
     * @param  MarkupElement  $root  `<extension>` 요소
     * @param  array<string, mixed>  $doc  문서 (참조)
     * @param  string|null  $path  소스 파일 경로
     * @return void 반환값 없음
     *
     * @throws CompileError 알 수 없는 속성인 경우
     */
    private function applyFragmentAttributes(MarkupElement $root, array &$doc, ?string $path): void
    {
        foreach ($root->attributes as $attribute) {
            $typed = str_starts_with($attribute->name, ':');
            $bare = $typed ? substr($attribute->name, 1) : $attribute->name;

            match ($bare) {
                'target-layout' => $doc['target_layout'] = (string) $attribute->value,
                'extension-point' => $doc['extension_point'] = (string) $attribute->value,
                'mode' => $doc['mode'] = (string) $attribute->value,
                'priority' => $doc['priority'] = $this->toInteger($attribute, 'priority', (string) $attribute->value, $path),
                default => throw $this->attributeError($attribute, LayoutMarkupErrorCode::UnknownRootAttribute, $path, [
                    'attr' => $attribute->name,
                    'tag' => 'extension',
                ]),
            };
        }
    }

    /**
     * `<inject target-id position>` 를 injections 항목으로 컴파일합니다.
     *
     * position=inject_props 는 `<props>JSON</props>`(병합 전략 `_append`/`_prepend`/`_merge`),
     * 그 밖은 컴포넌트 노드 자식을 components 로 모은다.
     *
     * @param  MarkupElement  $element  `<inject>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed> injections 항목
     *
     * @throws CompileError target-id 가 없거나 props/components 를 함께 쓴 경우
     */
    private function compileInjection(MarkupElement $element, ?string $path): array
    {
        $entry = ['target_id' => $this->requiredAttribute($element, 'target-id', $path)];
        $position = $element->attribute('position');
        $entry['position'] = $position !== null ? (string) $position->value : 'append_child';

        $mode = $element->attribute('mode');

        if ($mode !== null) {
            $entry['mode'] = (string) $mode->value;
        }

        $props = null;
        $components = [];

        foreach ($element->children as $child) {
            if ($child->name === 'props') {
                if ($props !== null) {
                    throw $this->error($child, LayoutMarkupErrorCode::DuplicateSection, $path, ['tag' => 'props']);
                }

                $props = $this->compileInjectionProps($child, $path);

                continue;
            }

            $components[] = $this->nodes->compile($child, $path);
        }

        if ($props !== null && $components !== []) {
            throw $this->error($element, LayoutMarkupErrorCode::FragmentInjectAmbiguous, $path);
        }

        if ($props !== null) {
            $entry['props'] = $props;
        } else {
            $entry['components'] = $components;
        }

        return $entry;
    }

    /**
     * `<override>` 봉투를 템플릿 오버라이드 JSON 으로 컴파일합니다 (slots/meta/data_sources 발췌).
     *
     * @param  MarkupElement  $root  `<override>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed> 오버라이드 JSON
     *
     * @throws CompileError 표기 규칙을 벗어난 경우
     */
    private function compileOverride(MarkupElement $root, ?string $path): array
    {
        $doc = [];

        foreach ($root->attributes as $attribute) {
            match ($attribute->name) {
                'name' => $doc['layout_name'] = (string) $attribute->value,
                'version' => $doc['version'] = (string) $attribute->value,
                'version-constraint' => $doc['version_constraint'] = (string) $attribute->value,
                default => throw $this->attributeError($attribute, LayoutMarkupErrorCode::UnknownRootAttribute, $path, [
                    'attr' => $attribute->name,
                    'tag' => 'override',
                ]),
            };
        }

        foreach ($root->children as $child) {
            if (! in_array($child->name, ['slot', 'meta', 'data-source'], true)) {
                throw $this->error($child, LayoutMarkupErrorCode::UnexpectedDocumentChild, $path, [
                    'tag' => $child->name,
                    'parent' => 'override',
                ]);
            }

            $this->applySection($child, $doc, $path);
        }

        return $this->order($doc);
    }

    // ---------------------------------------------------------------------
    // 공통 헬퍼
    // ---------------------------------------------------------------------

    /**
     * 요소의 속성을 `{이름: 값}` 객체로 모읍니다 (`:attr` 타입 리터럴, 무접두 문자열).
     *
     * @param  MarkupElement  $element  요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed> 속성 객체
     *
     * @throws CompileError 속성명이 비었거나 중복이거나 타입 리터럴이 JSON 이 아닌 경우
     */
    private function attributesToObject(MarkupElement $element, ?string $path): array
    {
        $object = [];

        foreach ($element->attributes as $attribute) {
            $typed = str_starts_with($attribute->name, ':');
            $key = $typed ? substr($attribute->name, 1) : $attribute->name;

            if ($key === '') {
                throw $this->attributeError($attribute, LayoutMarkupErrorCode::InvalidAttributeName, $path, ['found' => $attribute->name]);
            }

            if (array_key_exists($key, $object)) {
                throw $this->attributeError($attribute, LayoutMarkupErrorCode::DuplicateAttribute, $path, [
                    'tag' => $element->name,
                    'attr' => $key,
                ]);
            }

            $object[$key] = $typed
                ? MarkupValue::decodeTypeLiteral($attribute, $key, $path)
                : ($attribute->isBoolean() ? true : $attribute->value);
        }

        return $object;
    }

    /**
     * `<success>`/`<fail>`/`<on-receive>` 본문을 액션 배열로 컴파일합니다.
     *
     * @param  MarkupElement  $element  콜백 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<int, array<string, mixed>> 액션 배열
     *
     * @throws CompileError 표기 규칙을 벗어난 경우
     */
    private function compileCallback(MarkupElement $element, ?string $path): array
    {
        if ($element->children === []) {
            throw $this->error($element, LayoutMarkupErrorCode::EmptyActionBlock, $path, ['tag' => $element->name]);
        }

        return array_map(
            fn (MarkupElement $child): array => $this->actions->compileNamedAction(
                new MarkupElement($element->name, [], [$child], null, null, false, $child->line, $child->column),
                $path,
            ),
            $element->children,
        );
    }

    /**
     * `<all-of>`/`<any-of>` 를 conditions 구조체로 컴파일합니다 (노드 층과 동일 규칙).
     *
     * @param  MarkupElement  $element  `<all-of>` 또는 `<any-of>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed> conditions 구조체
     *
     * @throws CompileError 자식이 `<expr>`/`<all-of>`/`<any-of>` 가 아닌 경우
     */
    private function compileConditions(MarkupElement $element, ?string $path): array
    {
        $operator = $element->name === 'all-of' ? 'and' : 'or';
        $operands = [];

        foreach ($element->children as $child) {
            $operands[] = match ($child->name) {
                'expr' => (string) ($child->text ?? ''),
                'all-of', 'any-of' => $this->compileConditions($child, $path),
                default => throw $this->error($child, LayoutMarkupErrorCode::UnexpectedStructuralChild, $path, [
                    'tag' => $child->name,
                    'parent' => $element->name,
                ]),
            };
        }

        return [$operator => $operands];
    }

    /**
     * 요소 본문을 JSON 값으로 디코드합니다 (`<data>`·`<prop>`·`<og>` 등).
     *
     * @param  MarkupElement  $element  요소
     * @param  string|null  $path  소스 파일 경로
     * @return mixed 디코드된 값
     *
     * @throws CompileError 본문이 JSON 이 아닌 경우
     */
    /**
     * 구조값을 세 표기 중 하나로 컴파일합니다 (D42 G1·G2).
     *
     * 우선순위대로:
     *  1. **`<field>`/`<item>` 자식** — 중첩 구조. `<fallback><field name="data"><field name="items" :value="[]"/></field></fallback>`
     *  2. **속성 field-map** — 평면 스칼라 맵. `<params page="{{query.page}}" :per_page="20"/>`
     *  3. **JSON 본문** — 종전 표기. 하위호환으로 계속 받는다(기존 마크업이 깨지지 않는다).
     *
     * 1·2 를 신설한 이유는 소스 파일에서 통짜 JSON 을 없애기 위해서다(D42). 셋 다 같은 값을 만들며,
     * 역변환기는 값 형태를 보고 1 또는 2 를 고른다 — 3 은 되돌릴 때 쓰지 않는다.
     *
     * @param  MarkupElement  $element  `<params>`/`<headers>`/`<data>`/`<fallback>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return mixed 구조값
     *
     * @throws CompileError 표기가 규칙을 벗어난 경우
     */
    private function compileStructuredValue(MarkupElement $element, ?string $path): mixed
    {
        if ($element->children !== []) {
            return $this->compileFieldTree($element, $path);
        }

        if ($element->attributes !== []) {
            return $this->compileValueFieldMap($element, $path);
        }

        return $this->decodeJsonBody($element, $path);
    }

    /**
     * 확장 주입 `<props>` 를 컴파일합니다 (D42 G5) — `<prop>` 자식 또는 JSON 본문.
     *
     * 주입 props 는 대상 레이아웃의 props 에 **병합**되므로 `_append` 같은 지시자를 갖는다.
     * `<prop name="tabs"><append><item id="…"/></append></prop>` 가 `{"tabs":{"_append":[{…}]}}` 이다.
     *
     * @param  MarkupElement  $element  `<props>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return mixed props 값
     *
     * @throws CompileError 자식 표기가 규칙을 벗어난 경우
     */
    private function compileInjectionProps(MarkupElement $element, ?string $path): mixed
    {
        if ($element->children === []) {
            return $this->decodeJsonBody($element, $path);
        }

        $props = [];

        foreach ($element->children as $child) {
            if ($child->name !== 'prop') {
                throw $this->error($child, LayoutMarkupErrorCode::UnexpectedStructuralChild, $path, [
                    'tag' => $child->name,
                    'parent' => $element->name,
                ]);
            }

            // 주입 props 는 `<column>` 컬럼 트리를 받는다 (D42 G7) — 대상 노드가 다른 레이아웃에 있어
            // 소유자(DataGrid)로 게이트할 수단이 없고, `<prop name="columns">` 컨테이너가 그 자리를
            // 대신한다. 그래서 게이트는 **이름이 `columns` 일 때만** 열린다(방출기와 같은 조건).
            $name = $this->requiredAttribute($child, 'name', $path);

            $props[$name] = $this->nodes->compilePropElementValue($child, $path, $name === 'columns');
        }

        return $props;
    }

    /**
     * `<field>`/`<item>` 자식들을 중첩 구조값으로 컴파일합니다 (D42 G2).
     *
     * 규칙은 `StructuredValueCompiler` 가 단일 SSoT 다 — `<prop>`·액션 구조값 파라미터와 같은 문법을
     * 쓴다("구조를 가진 값은 어디에 있든 `<field>` 트리").
     *
     * @param  MarkupElement  $element  부모 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<array-key, mixed> 중첩 구조값
     *
     * @throws CompileError 자식 표기가 규칙을 벗어난 경우
     */
    private function compileFieldTree(MarkupElement $element, ?string $path): array
    {
        return $this->values->compileChildren($element, $path);
    }

    /**
     * 속성을 평면 키-값 맵으로 컴파일합니다 (D42 G1 — `name` 은 컨테이너 식별자라 제외하지 않는다).
     *
     * 읽는 규칙(`:attr`=타입 리터럴 / 불리언 속성=true / 그 밖=문자열)은 `<item>` 의 평면 객체와
     * **완전히 같으므로** `StructuredValueCompiler` 에 위임한다 — 복제해 두면 한쪽만 고쳤을 때 같은
     * 평면 맵이 자리(데이터소스 속성 field-map ↔ `<item>`)에 따라 다르게 해석된다.
     *
     * @param  MarkupElement  $element  요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, mixed> 평면 맵
     *
     * @throws CompileError 중복 키가 있는 경우
     */
    private function compileValueFieldMap(MarkupElement $element, ?string $path): array
    {
        return $this->values->attributeMap($element, $path);
    }

    /**
     * 요소 본문에 적힌 JSON 리터럴을 값으로 읽습니다 (`<json>` 계열 탈출구).
     *
     * 파싱 실패는 **예외로 세운다** — 조용히 `null` 을 돌려주면 그 자리가 빈 채로 컴파일돼
     * 화면에서야 값이 없는 것을 알게 된다. 본문이 문자 그대로 `null` 인 경우만 정상 통과다.
     *
     * @param  MarkupElement  $element  본문에 JSON 을 담은 요소
     * @param  string|null  $path  소스 파일 경로 (오류 메시지용)
     * @return mixed 파싱된 값
     *
     * @throws CompileError 본문이 올바른 JSON 이 아닐 때
     */
    private function decodeJsonBody(MarkupElement $element, ?string $path): mixed
    {
        $body = trim((string) ($element->text ?? ''));
        $value = json_decode($body, true);

        if ($value === null && strtolower($body) !== 'null') {
            throw $this->error($element, LayoutMarkupErrorCode::InvalidJsonBody, $path, [
                'tag' => $element->name,
                'reason' => json_last_error_msg(),
            ]);
        }

        return $value;
    }

    /**
     * 정수 속성 값을 변환합니다.
     *
     * @param  MarkupAttribute  $attribute  속성
     * @param  string  $name  속성명 (오류 메시지용)
     * @param  string  $value  원문 값
     * @param  string|null  $path  소스 파일 경로
     * @return int 정수 값
     *
     * @throws CompileError 정수가 아닌 경우
     */
    private function toInteger(MarkupAttribute $attribute, string $name, string $value, ?string $path): int
    {
        if (preg_match('/^-?[0-9]+$/', trim($value)) !== 1) {
            throw $this->attributeError($attribute, LayoutMarkupErrorCode::DirectiveNeedsInteger, $path, [
                'attr' => $name,
                'found' => $value,
            ]);
        }

        return (int) trim($value);
    }

    /**
     * 필수 속성을 읽습니다.
     *
     * @param  MarkupElement  $element  요소
     * @param  string  $name  속성명
     * @param  string|null  $path  소스 파일 경로
     * @return string 속성값
     *
     * @throws CompileError 속성이 없는 경우
     */
    private function requiredAttribute(MarkupElement $element, string $name, ?string $path): string
    {
        $attribute = $element->attribute($name);

        if ($attribute === null || $attribute->value === null || $attribute->value === '') {
            throw $this->error($element, LayoutMarkupErrorCode::MissingRequiredAttribute, $path, [
                'tag' => $element->name,
                'attr' => $name,
            ]);
        }

        return $attribute->value;
    }

    /**
     * 싱글턴 섹션이 두 번 오지 않았는지 확인합니다.
     *
     * @param  MarkupElement  $element  섹션 요소
     * @param  array<string, mixed>  $doc  문서
     * @param  string|null  $path  소스 파일 경로
     * @return void 반환값 없음
     *
     * @throws CompileError 이미 온 섹션인 경우
     */
    private function assertSectionOnce(MarkupElement $element, array $doc, ?string $path): void
    {
        $key = match ($element->name) {
            'error-handling' => 'errorHandling',
            'global-headers' => 'globalHeaders',
            'transition-overlay' => 'transition_overlay',
            'init' => 'init_actions',
            default => $element->name,
        };

        if (array_key_exists($key, $doc)) {
            throw $this->error($element, LayoutMarkupErrorCode::DuplicateSection, $path, ['tag' => $element->name]);
        }
    }

    /**
     * 데이터소스 키가 이미 채워지지 않았는지 확인합니다 (속성과 자식 태그의 충돌 방지).
     *
     * @param  MarkupElement  $element  자식 요소
     * @param  array<string, mixed>  $source  data_sources 항목
     * @param  string  $key  data_sources 키
     * @param  string|null  $path  소스 파일 경로
     * @return void 반환값 없음
     *
     * @throws CompileError 이미 채워진 키인 경우
     */
    private function assertDataSourceKeyUnset(MarkupElement $element, array $source, string $key, ?string $path): void
    {
        if (array_key_exists($key, $source)) {
            throw $this->error($element, LayoutMarkupErrorCode::DuplicateAttribute, $path, [
                'tag' => 'data-source',
                'attr' => $key,
            ]);
        }
    }

    /**
     * 최상위 키를 고정 순서로 정렬합니다.
     *
     * @param  array<string, mixed>  $doc  문서
     * @return array<string, mixed> 정렬된 문서
     */
    private function order(array $doc): array
    {
        $ordered = [];

        foreach (self::KEY_ORDER as $key) {
            if (array_key_exists($key, $doc)) {
                $ordered[$key] = $doc[$key];
            }
        }

        return $ordered + $doc;
    }

    /**
     * 요소 위치의 컴파일 오류를 만듭니다.
     *
     * @param  MarkupElement  $element  요소
     * @param  LayoutMarkupErrorCode  $code  에러 코드
     * @param  string|null  $path  소스 파일 경로
     * @param  array<string, string|int>  $params  메시지 치환 파라미터
     * @return CompileError 컴파일 오류
     */
    private function error(MarkupElement $element, LayoutMarkupErrorCode $code, ?string $path, array $params = []): CompileError
    {
        return new CompileError($code, $element->line, $element->column, $params, $path);
    }

    /**
     * 속성 위치의 컴파일 오류를 만듭니다.
     *
     * @param  MarkupAttribute  $attribute  속성
     * @param  LayoutMarkupErrorCode  $code  에러 코드
     * @param  string|null  $path  소스 파일 경로
     * @param  array<string, string|int>  $params  메시지 치환 파라미터
     * @return CompileError 컴파일 오류
     */
    private function attributeError(MarkupAttribute $attribute, LayoutMarkupErrorCode $code, ?string $path, array $params = []): CompileError
    {
        return new CompileError($code, $attribute->line, $attribute->column, $params, $path);
    }
}
