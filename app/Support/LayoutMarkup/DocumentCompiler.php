<?php

namespace App\Support\LayoutMarkup;

use App\Enums\LayoutMarkupErrorCode;

/**
 * `.g7.html` 문서 하나 전체를 JSON 레이아웃으로 컴파일하는 최상위 층 (매핑 사전 A·B).
 *
 * 아래 층들이 각각 노드(S2)·액션(S3)·상태 초기화(S2)를 맡지만, 그것들을 조립해 **문서**를 만드는
 * 것은 이 층의 책임이다. 이 층이 확정하는 것은 넷이다.
 *
 * **① 루트가 문서의 종류를 정한다.** 저작 파일은 다섯 계층이고 봉투가 넷이다 — `<layout>`(본
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
        'init_actions', 'slots', 'components', 'injections', 'version_constraint',
    ];

    private readonly NodeCompiler $nodes;

    private readonly ActionCompiler $actions;

    private readonly StateTreeCompiler $state;

    /**
     * @param  ComponentRegistry  $registry  대상 템플릿의 컴포넌트 레지스트리
     */
    public function __construct(private readonly ComponentRegistry $registry)
    {
        $this->nodes = new NodeCompiler($registry);
        $this->actions = new ActionCompiler;
        $this->state = new StateTreeCompiler;
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

        return match ($root->name) {
            'layout' => $this->compileLayout($root, $path),
            'partial' => $this->compilePartial($root, $path),
            'extension' => $this->compileFragment($root, $path),
            'override' => $this->compileOverride($root, $path),
            default => throw $this->error($root, LayoutMarkupErrorCode::UnknownDocumentRoot, $path, [
                'tag' => $root->name,
            ]),
        };
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
                $doc['permissions'] = $this->compilePermissions($child, $path);

                return;

            case 'defines':
                $doc['defines'] = $this->compileDefines($child, $path);

                return;

            case 'error-handling':
                $doc['errorHandling'] = $this->actions->compileErrorHandlingSection($child, $path);

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
     * `<seo>` 를 meta.seo 객체로 컴파일합니다 (10필드 — 임의 깊이 객체는 JSON 본문 블록).
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

        // og·vars·structured_data 는 임의 깊이 객체라 속성으로 못 편다 — JSON 본문 블록으로 보존.
        // extensions 는 `{type,id}` 배열이라 역시 JSON 본문. data_sources 는 string[] 이라 JSON 본문.
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

            $seo[$key] = $this->decodeJsonBody($child, $path);
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
                $source[$key] = $this->decodeJsonBody($child, $path);

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
                $source['errorHandling'] = $this->actions->compileErrorHandlingSection($child, $path);

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

        $source[$key] = array_merge($source[$key] ?? [], $this->state->compileInitBlock($child, $path));
    }

    /**
     * `<computed name="x">식</computed>` 항목을 computed 에 배치합니다.
     *
     * @param  MarkupElement  $element  `<computed>` 요소
     * @param  array<string, mixed>  $doc  문서 (참조)
     * @param  string|null  $path  소스 파일 경로
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

        $doc[$key] = array_merge($doc[$key] ?? [], $this->state->compileInitBlock($element, $path));
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
     *
     * @throws CompileError 이름이 없거나 중복이거나 표기 규칙을 벗어난 경우
     */
    private function applyNamedAction(MarkupElement $element, array &$doc, ?string $path): void
    {
        $name = $this->requiredAttribute($element, 'name', $path);

        if (isset($doc['named_actions'][$name])) {
            throw $this->error($element, LayoutMarkupErrorCode::DuplicateSection, $path, ['tag' => 'action:'.$name]);
        }

        $doc['named_actions'][$name] = $this->actions->compileNamedAction($element, $path);
    }

    /**
     * `<permissions>` 의 `<permission>` 자식들을 문자열 배열로 컴파일합니다.
     *
     * @param  MarkupElement  $element  `<permissions>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<int, string> 권한 문자열 배열
     *
     * @throws CompileError 자식이 `<permission>` 이 아닌 경우
     */
    private function compilePermissions(MarkupElement $element, ?string $path): array
    {
        $permissions = [];

        foreach ($element->children as $child) {
            if ($child->name !== 'permission') {
                throw $this->error($child, LayoutMarkupErrorCode::UnexpectedStructuralChild, $path, [
                    'tag' => $child->name,
                    'parent' => 'permissions',
                ]);
            }

            $permissions[] = (string) ($child->text ?? '');
        }

        return $permissions;
    }

    /**
     * `<defines>` 의 `<define name="x">JSON</define>` 자식들을 defines 객체로 컴파일합니다.
     *
     * defines 값은 임의 JSON(국가 목록 등)이라 속성으로 못 편다 — `<prop>` 과 같은 JSON 본문 블록.
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

            $defines[$name] = $this->decodeJsonBody($child, $path);
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
            $entry = ['pattern' => $pattern, 'headers' => []];

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
     * `<modal src>` 를 modals 참조로, `<modal>` 본문을 인라인 노드로 컴파일합니다.
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

        if ($src !== null) {
            if ($element->children !== []) {
                throw $this->error($element, LayoutMarkupErrorCode::ModalSrcWithBody, $path);
            }

            return ['partial' => (string) $src->value];
        }

        if (count($element->children) !== 1) {
            throw $this->error($element, LayoutMarkupErrorCode::ModalInlineNotSingleNode, $path);
        }

        return $this->nodes->compile($element->children[0], $path);
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

        $meta = ['is_partial' => true] + $this->attributesToObject($root, $path);
        [$node, $dataSources] = $this->compilePartialNode($root->children[0], $path);

        $doc = ['meta' => $meta];

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

                $props = $this->decodeJsonBody($child, $path);

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
