<?php

namespace App\Support\LayoutMarkup;

/**
 * JSON 레이아웃 하나를 `.g7.html` 소스로 되돌리는 최상위 파사드 (S9 — DocumentCompiler 의 역).
 *
 * 컴파일러가 소스 파일을 JSON 으로 만드는 사슬(토큰→트리→문서)을 거꾸로 잇는다: **JSON → 요소 트리
 * (이 층 + Node/Action/State 역변환) → 소스 문자열(Serializer)**. 왕복 하네스가
 * `compile(decompile(json)) ≡ json` 을 전량 검증하므로, 이 층의 유일한 책임은 각 최상위 키를 컴파일러가
 * 되읽을 섹션 태그로 정확히 되돌리는 것이다.
 *
 * **루트 종류는 JSON 시그니처로 판별한다.** 컴파일러는 루트 태그(`<layout>`/`<partial>`/`<extension>`/
 * `<override>`)로 갈래를 정하지만 역변환은 JSON 만 있으므로 키로 역추론한다 — `target_layout`/
 * `extension_point`=fragment, `meta.is_partial`=partial, `version_constraint`=override, 그 밖=layout.
 * (override 판별 키가 없는 경계 케이스는 layout 으로 찍어도 컴파일 산출이 동일해 왕복이 성립한다.)
 */
final class LayoutMarkupDecompiler
{
    use EmitsMarkup;

    private readonly NodeMarkupDecompiler $nodes;

    private readonly ActionMarkupDecompiler $actions;

    private readonly StateTreeMarkupDecompiler $state;

    private readonly LayoutMarkupSerializer $serializer;

    private readonly DocumentCompiler $compiler;

    /**
     * 태그는 이름의 표기 규칙으로, 종류는 `data-type` 으로 적는다 — 템플릿과 무관하다.
     *
     * @param  ComponentRegistry|null  $registry  검사용 템플릿 컴포넌트 레지스트리 (산출에는 쓰지 않는다)
     */
    public function __construct(?ComponentRegistry $registry = null)
    {
        $this->nodes = new NodeMarkupDecompiler($registry);
        $this->actions = new ActionMarkupDecompiler;
        $this->state = new StateTreeMarkupDecompiler;
        $this->serializer = new LayoutMarkupSerializer;
        $this->compiler = new DocumentCompiler($registry);
    }

    /**
     * JSON 레이아웃을 `.g7.html` 소스 문자열로 역변환합니다.
     *
     * @param  array<string, mixed>  $json  JSON 레이아웃
     * @return string `.g7.html` 소스 (끝 개행 포함)
     */
    public function decompile(array $json): string
    {
        return $this->serializer->serialize($this->documentChecked($json));
    }

    /**
     * 문서 루트를 짓되, layout/override 는 최상위 키 자기검증으로 미표현 키를 `<json>` 으로 방출합니다.
     *
     * @param  array<string, mixed>  $json  JSON 레이아웃
     * @return MarkupElement 문서 루트 요소
     */
    private function documentChecked(array $json): MarkupElement
    {
        // 섹션 마크업이 아예 실패하는 결함(문자열 액션·init key 금지 등)은 그 한 섹션 키만 `<json>` 으로
        // 방출한다 — 최상위 키를 통째로 blob 하면 slots/meta 등 정상 키까지 가독성을 잃는다.
        $throwEscape = [];

        try {
            $element = $this->document($json);
        } catch (\Throwable) {
            $throwEscape = $this->isolateThrowingKeys($json);
            $element = $this->document($json, $throwEscape);
        }

        // 노드 루트(partial)·fragment 는 하위 층(노드 자기검증·속성)이 커버한다. layout/override 만
        // 최상위 섹션 키를 자기검증한다 — 미지 top-level 키(state/schema/_comment 등)를 여기서 잡는다.
        if (! in_array($element->name, ['layout', 'override'], true)) {
            return $element;
        }

        try {
            $recompiled = $this->compiler->compile([$element]);
            $escape = array_values(array_unique(array_merge($throwEscape, $this->differingTopKeys($json, $recompiled))));
        } catch (\Throwable) {
            // 자기검증 되컴파일이 throw(예: init_action key 금지) — 전 키 blob 대신 결함 섹션만 최소 격리한
            // 뒤, 그 escape 로 다시 컴파일해 **미지 키(state 등)까지** 재-diff 로 잡는다(throw 격리는 컴파일만
            // 세우지 미지 키 round-trip 은 담보하지 못한다).
            $throwers = array_values(array_unique(array_merge($throwEscape, $this->isolateThrowingKeys($json))));

            try {
                $recompiled = $this->compiler->compile([$this->document($json, $throwers)]);
                $escape = array_values(array_unique(array_merge($throwers, $this->differingTopKeys($json, $recompiled))));
            } catch (\Throwable) {
                $escape = $throwers;
            }
        }

        return $escape === [] ? $element : $this->document($json, $escape);
    }

    /**
     * 원본과 되컴파일이 어긋나는(또는 미표현) 최상위 키를 찾습니다.
     *
     * @param  array<string, mixed>  $source  원본 JSON
     * @param  array<string, mixed>  $recompiled  되컴파일 JSON
     * @return array<int, string> 어긋나는 키 목록
     */
    private function differingTopKeys(array $source, array $recompiled): array
    {
        // 정규화 후 비교 — 컴파일러가 생략·파생하는 값(빈 props·params.actions·derived type 등)을
        // 최상위 키 escape 사유에서 뺀다(노드 differingKeys 와 동일 규율). 없으면 slots 등 정상 섹션이
        // 그 안의 정규화 대상 노드 때문에 통째로 blob 된다.
        // 문서 층 별칭(state→initLocal)도 함께 접는다 — 왕복 오라클과 같은 기준이어야 escape 판정이
        // 오라클과 어긋나지 않는다(접히는 문서를 여기서만 불일치로 세면 불필요한 blob).
        $source = LayoutMarkupRoundTrip::normalize(LayoutMarkupRoundTrip::normalizeDocument($source));
        $recompiled = LayoutMarkupRoundTrip::normalize(LayoutMarkupRoundTrip::normalizeDocument($recompiled));
        $diff = [];

        foreach ($source as $key => $value) {
            $got = $recompiled[$key] ?? null;

            if (! array_key_exists($key, $recompiled)
                || LayoutMarkupRoundTrip::encode(LayoutMarkupRoundTrip::canonicalize($value)) !== LayoutMarkupRoundTrip::encode(LayoutMarkupRoundTrip::canonicalize($got))) {
                $diff[] = (string) $key;
            }
        }

        return $diff;
    }

    /**
     * 섹션 마크업을 실패시키는 최소 최상위 키 집합을 찾습니다.
     *
     * 먼저 각 키를 단독 escape 해 서는지 본다(대개 한 섹션 결함) — 없으면 키를 하나씩 누적해 서는
     * 지점을 찾는다(여러 섹션 결함). 그래도 안 서면 전 키(최후 안전망). 정상 섹션(slots/meta 등)이
     * 결함 섹션 때문에 통째 blob 되는 coarse fallback 을 막는다.
     *
     * @param  array<string, mixed>  $json  JSON 레이아웃
     * @return array<int, string> escape 할 최상위 키
     */
    private function isolateThrowingKeys(array $json): array
    {
        $keys = array_map('strval', array_keys($json));

        foreach ($keys as $key) {
            if ($this->documentCompiles($json, [$key])) {
                return [$key];
            }
        }

        // 단일 키로 안 서면(섹션 결함 여럿) 전 키 escape 에서 시작해 불필요한 키를 하나씩 un-escape 해
        // 진짜 최소 집합을 찾는다 — 정상 섹션(meta/slots 등)이 결함 섹션 때문에 딸려 blob 되지 않는다.
        $escape = $keys;

        foreach ($keys as $key) {
            $trial = array_values(array_diff($escape, [$key]));

            if ($this->documentCompiles($json, $trial)) {
                $escape = $trial;
            }
        }

        return $escape;
    }

    /**
     * 주어진 escape 집합으로 문서를 지어 되컴파일이 서는지 판정합니다.
     *
     * @param  array<string, mixed>  $json  JSON 레이아웃
     * @param  array<int, string>  $escape  escape 할 최상위 키
     * @return bool 컴파일이 서면 true
     */
    private function documentCompiles(array $json, array $escape): bool
    {
        try {
            $this->compiler->compile([$this->document($json, $escape)]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * JSON 레이아웃을 문서 루트 요소로 되돌립니다.
     *
     * @param  array<string, mixed>  $json  JSON 레이아웃
     * @param  array<int, string>  $escape  `<json>` 섹션으로 방출할 최상위 키 (layout/override 만)
     * @return MarkupElement 문서 루트 요소
     */
    public function document(array $json, array $escape = []): MarkupElement
    {
        if (isset($json['target_layout']) || isset($json['extension_point'])) {
            return $this->fragment($json);
        }

        // 루트가 노드인 조각 — 컴파일러가 노드를 최상위로 spread 하므로 top-level `type` 이 시그니처다.
        if (isset($json['type']) || isset($json['meta']['is_partial'])) {
            return $this->partial($json);
        }

        if (isset($json['version_constraint'])) {
            return $this->override($json, $escape);
        }

        return $this->layout($json, $escape);
    }

    /**
     * `<layout>` 봉투를 되돌립니다.
     *
     * @param  array<string, mixed>  $json  레이아웃 JSON
     * @param  array<int, string>  $escape  `<json>` 으로 방출할 최상위 키
     * @return MarkupElement `<layout>` 요소
     */
    private function layout(array $json, array $escape = []): MarkupElement
    {
        $filtered = $this->without($json, $escape);
        $attributes = [];

        if (isset($filtered['layout_name'])) {
            $attributes[] = $this->attr('name', (string) $filtered['layout_name']);
        }

        if (isset($filtered['version'])) {
            $attributes[] = $this->attr('version', (string) $filtered['version']);
        }

        if (isset($filtered['extends'])) {
            $attributes[] = $this->attr('extends', (string) $filtered['extends']);
        }

        return $this->el(
            'layout',
            $attributes,
            $this->sectionsWithEscape($filtered, $json, $escape),
            null,
            $this->documentComment($filtered, $escape),
        );
    }

    /**
     * 섹션 객체의 `comment` 키를 앞 주석 문자열로 꺼냅니다.
     *
     * @param  mixed  $section  섹션 값
     * @return string|null 주석, 없으면 null
     */
    private function sectionComment(mixed $section): ?string
    {
        return is_array($section) && is_string($section['comment'] ?? null) ? $section['comment'] : null;
    }

    /**
     * 문서 전체를 설명하는 `comment` 를 봉투 앞 주석으로 냅니다.
     *
     * 이 자리가 비어 있어서 최상위 `comment` 가 매번 `<json>` 탈출구로 새고 있었다 — 노드와
     * 데이터소스는 진작 주석을 왕복시키는데 문서 루트만 빠져 있던 비대칭이다.
     *
     * @param  array<string, mixed>  $filtered  탈출 키를 걷어낸 JSON
     * @param  array<int, string>  $escape  `<json>` 으로 방출할 최상위 키
     * @return string|null 봉투 앞 주석
     */
    private function documentComment(array $filtered, array $escape): ?string
    {
        if (in_array('comment', $escape, true) || ! isset($filtered['comment']) || ! is_string($filtered['comment'])) {
            return null;
        }

        return $filtered['comment'];
    }

    /**
     * `<override>` 봉투를 되돌립니다.
     *
     * @param  array<string, mixed>  $json  오버라이드 JSON
     * @param  array<int, string>  $escape  `<json>` 으로 방출할 최상위 키
     * @return MarkupElement `<override>` 요소
     */
    private function override(array $json, array $escape = []): MarkupElement
    {
        $filtered = $this->without($json, $escape);
        $attributes = [];

        if (isset($filtered['layout_name'])) {
            $attributes[] = $this->attr('name', (string) $filtered['layout_name']);
        }

        if (isset($filtered['version'])) {
            $attributes[] = $this->attr('version', (string) $filtered['version']);
        }

        if (isset($filtered['version_constraint'])) {
            $attributes[] = $this->attr('version-constraint', (string) $filtered['version_constraint']);
        }

        // 봉투 주석은 `<layout>` 과 같은 규칙으로 되돌린다 — 이 짝이 없으면 소스의 문서 주석이
        // 왕복에서 사라진다(`<partial>` 은 예외다: 루트 노드가 그 주석을 이미 싣는다).
        return $this->el(
            'override',
            $attributes,
            $this->sectionsWithEscape($filtered, $json, $escape),
            null,
            $this->documentComment($filtered, $escape),
        );
    }

    /**
     * 섹션 자식 + escape 키의 `<json>` 섹션을 함께 짓습니다.
     *
     * @param  array<string, mixed>  $filtered  escape 키를 뺀 JSON
     * @param  array<string, mixed>  $json  원본 JSON (escape 값 조회용)
     * @param  array<int, string>  $escape  `<json>` 으로 방출할 키
     * @return array<int, MarkupElement> 섹션 요소 목록
     */
    private function sectionsWithEscape(array $filtered, array $json, array $escape): array
    {
        $children = $this->sections($filtered);

        foreach ($escape as $key) {
            $children[] = $this->jsonElement($key, $json[$key]);
        }

        return $children;
    }

    /**
     * 지정 키를 뺀 배열을 반환합니다.
     *
     * @param  array<string, mixed>  $json  원본
     * @param  array<int, string>  $keys  뺄 키
     * @return array<string, mixed> 필터된 배열
     */
    private function without(array $json, array $keys): array
    {
        foreach ($keys as $key) {
            unset($json[$key]);
        }

        return $json;
    }

    /**
     * `<partial>` 봉투를 되돌립니다 (루트=노드 + meta 속성 + data_sources 를 노드에 얹음).
     *
     * @param  array<string, mixed>  $json  partial JSON
     * @return MarkupElement `<partial>` 요소
     */
    private function partial(array $json): MarkupElement
    {
        $meta = $json['meta'] ?? [];
        unset($meta['is_partial']);

        $attributes = [];

        // 최상위 `layout_name` 은 `<partial name>` 으로 — `<layout name>` 과 같은 규율.
        if (isset($json['layout_name'])) {
            $attributes[] = $this->attr('name', (string) $json['layout_name']);
        }

        foreach ($meta as $key => $value) {
            $attributes[] = $this->valueAttr((string) $key, $value);
        }

        $dataSources = $json['data_sources'] ?? [];
        $nodeJson = $json;
        unset($nodeJson['meta'], $nodeJson['data_sources'], $nodeJson['layout_name']);

        $node = $this->nodes->node($nodeJson);

        // 모달 조각의 오픈 시 fetch 데이터소스는 루트 노드에 <data-source> 자식으로 얹힌다.
        if ($dataSources !== []) {
            $dsChildren = array_map(fn (array $ds): MarkupElement => $this->dataSource($ds), $dataSources);
            $node = new MarkupElement(
                $node->name,
                $node->attributes,
                array_merge($dsChildren, $node->children),
                $node->text,
                $node->comment,
                false,
                0,
                0,
            );
        }

        return $this->el('partial', $attributes, [$node]);
    }

    /**
     * `<extension>` fragment 봉투를 되돌립니다.
     *
     * @param  array<string, mixed>  $json  fragment JSON
     * @return MarkupElement `<extension>` 요소
     */
    private function fragment(array $json): MarkupElement
    {
        $attributes = [];

        if (isset($json['target_layout'])) {
            $attributes[] = $this->attr('target-layout', (string) $json['target_layout']);
        }

        if (isset($json['extension_point'])) {
            $attributes[] = $this->attr('extension-point', (string) $json['extension_point']);
        }

        if (isset($json['mode'])) {
            $attributes[] = $this->attr('mode', (string) $json['mode']);
        }

        if (isset($json['priority'])) {
            $attributes[] = $this->attr('priority', (string) $json['priority']);
        }

        $children = [];

        foreach ($json['injections'] ?? [] as $injection) {
            $children[] = $this->injection($injection);
        }

        foreach ($json['components'] ?? [] as $component) {
            $children[] = $this->nodes->node($component);
        }

        foreach ($json['data_sources'] ?? [] as $dataSource) {
            $children[] = $this->dataSource($dataSource);
        }

        foreach ($json['scripts'] ?? [] as $script) {
            $children[] = $this->script($script);
        }

        foreach ($json['modals'] ?? [] as $modal) {
            $children[] = $this->modal($modal);
        }

        if (isset($json['init_actions'])) {
            $children[] = $this->initSection($json['init_actions']);
        }

        return $this->el('extension', $attributes, $children, null, $this->documentComment($json, []));
    }

    /**
     * `<inject>` 항목을 되돌립니다.
     *
     * @param  array<string, mixed>  $injection  injections 항목
     * @return MarkupElement `<inject>` 요소
     */
    private function injection(array $injection): MarkupElement
    {
        $attributes = [$this->attr('target-id', (string) ($injection['target_id'] ?? ''))];

        if (isset($injection['position'])) {
            $attributes[] = $this->attr('position', (string) $injection['position']);
        }

        if (isset($injection['mode'])) {
            $attributes[] = $this->attr('mode', (string) $injection['mode']);
        }

        $children = [];

        if (array_key_exists('props', $injection)) {
            $children[] = $this->injectionPropsElement($injection['props']);
        } else {
            foreach ($injection['components'] ?? [] as $component) {
                $children[] = $this->nodes->node($component);
            }
        }

        return $this->el('inject', $attributes, $children);
    }

    /**
     * 최상위 섹션 키들을 섹션 태그 자식으로 되돌립니다 (layout/override 공통).
     *
     * @param  array<string, mixed>  $json  레이아웃 JSON
     * @return array<int, MarkupElement> 섹션 요소 목록
     */
    private function sections(array $json): array
    {
        $children = [];

        if (isset($json['meta'])) {
            $children[] = $this->meta($json['meta']);
        }

        foreach ($json['data_sources'] ?? [] as $dataSource) {
            $children[] = $this->dataSource($dataSource);
        }

        // `state` 는 `initLocal` 의 deprecated 별칭이다 — 엔진은 `initLocal || state` 로 읽고
        // (TemplateApp.ts:1177) initLocal 이 이긴다. 신포맷은 initLocal 로만 방출하므로(계획 §288)
        // initLocal 이 없을 때만 별칭을 승격한다. **둘 다 있으면 state 는 엔진이 무시하는 죽은 키**라
        // 승격하면 동작이 바뀐다 — 승격하지 않고 `<json>` 으로 남겨 결함(S16)으로 계량되게 둔다.
        $initSections = ['initLocal' => 'init-local', 'initGlobal' => 'init-global', 'initIsolated' => 'init-isolated'];

        if (isset($json['state']) && is_array($json['state']) && ! isset($json['initLocal'])) {
            $json['initLocal'] = $json['state'];
        }

        foreach ($initSections as $key => $tag) {
            // 빈 map 표식({@see EmptyMap})도 **찍는다**. 건너뛰면 마크업에 태그가 없어지고, 컴파일러가
            // 그 자리를 기본값 `[]` 로 채워 작성자가 적은 `{}` 가 저장 한 번에 `[]` 로 바뀐다
            // (실측: `sirsoft-basic/mypage/addresses` 의 `initLocal`). 빈 태그는 컴파일러가 다시
            // 표식으로 되돌린다({@see DocumentCompiler::mergeInitContribution()}).
            if (isset($json[$key]) && EmptyMap::is($json[$key])) {
                $children[] = $this->el($tag, [], []);

                continue;
            }

            if (isset($json[$key]) && is_array($json[$key])) {
                $children[] = $this->el($tag, [], $this->state->fields($json[$key]));
            }
        }

        foreach ($json['computed'] ?? [] as $name => $expression) {
            $children[] = $this->el('computed', [$this->attr('name', (string) $name)], [], (string) $expression);
        }

        if (isset($json['permissions'])) {
            $children[] = $this->permissionsElement($json['permissions']);
        }

        if (isset($json['defines'])) {
            $children[] = $this->defines($json['defines']);
        }

        if (isset($json['errorHandling'])) {
            $children[] = $this->el(
                'error-handling',
                [],
                $this->actions->errorHandlingChildren($json['errorHandling']),
                null,
                $this->sectionComment($json['errorHandling']),
            );
        }

        if (isset($json['globalHeaders'])) {
            $children[] = $this->globalHeaders($json['globalHeaders']);
        }

        if (isset($json['transition_overlay'])) {
            $children[] = $this->transitionOverlay($json['transition_overlay']);
        }

        foreach ($json['scripts'] ?? [] as $script) {
            $children[] = $this->script($script);
        }

        foreach ($json['named_actions'] ?? [] as $name => $action) {
            // 주석은 `<action name>` 래퍼 앞에 한 번만 찍는다 — 컴파일러가 래퍼 주석을
            // `named_actions[name].comment` 로 싣기 때문이다(DocumentCompiler::withLeadingComment).
            // 같은 배열을 그대로 본문 빌더에 넘기면 안쪽 핸들러 태그가 같은 주석을 또 달아, 프로덕션
            // 컴파일의 주석 귀속 검사(`comment_not_attachable`)가 산출을 거부한다 — `dataSource()` 가
            // `comment` 를 떼고 본문을 짓는 것과 같은 관용구다.
            $body = is_array($action) ? $action : [];
            unset($body['comment']);

            $children[] = $this->el(
                'action',
                [$this->attr('name', (string) $name)],
                $this->actions->namedActionBody($body),
                null,
                $this->sectionComment($action),
            );
        }

        if (isset($json['init_actions'])) {
            $children[] = $this->initSection($json['init_actions']);
        }

        foreach ($json['slots'] ?? [] as $name => $slotNodes) {
            $children[] = $this->el('slot', [$this->attr('name', (string) $name)], array_map(
                fn (array $node): MarkupElement => $this->nodes->node($node),
                $slotNodes,
            ));
        }

        foreach ($json['components'] ?? [] as $component) {
            $children[] = $this->nodes->node($component);
        }

        foreach ($json['modals'] ?? [] as $modal) {
            $children[] = $this->modal($modal);
        }

        return $children;
    }

    /**
     * `meta` 객체를 `<meta>` (+`<seo>`) 로 되돌립니다.
     *
     * @param  array<string, mixed>  $meta  meta 객체
     * @return MarkupElement `<meta>` 요소
     */
    private function meta(array $meta): MarkupElement
    {
        $seo = $meta['seo'] ?? null;
        unset($meta['seo']);

        $attributes = [];

        foreach ($meta as $key => $value) {
            $attributes[] = $this->valueAttr((string) $key, $value);
        }

        $children = $seo !== null ? [$this->seo($seo)] : [];

        return $this->el('meta', $attributes, $children);
    }

    /**
     * `meta.seo` 객체를 `<seo>` 로 되돌립니다 (임의 깊이 키는 JSON 본문 자식).
     *
     * @param  array<string, mixed>  $seo  seo 객체
     * @return MarkupElement `<seo>` 요소
     */
    private function seo(array $seo): MarkupElement
    {
        $bodyKeys = ['og' => 'og', 'vars' => 'vars', 'extensions' => 'extensions', 'structured_data' => 'structured-data', 'data_sources' => 'data-sources'];
        $attributes = [];
        $children = [];

        foreach ($seo as $key => $value) {
            if (isset($bodyKeys[$key])) {
                $children[] = $this->structuredValueElement($bodyKeys[$key], $value);

                continue;
            }

            $attributes[] = $this->valueAttr((string) $key, $value);
        }

        return $this->el('seo', $attributes, $children);
    }

    /**
     * `data_sources` 항목을 `<data-source>` 로 되돌립니다.
     *
     * @param  array<string, mixed>  $source  data_sources 항목
     * @return MarkupElement `<data-source>` 요소
     */
    /**
     * 확장 주입 `<props>` 를 1급 표기로 되돌립니다 (D42 G5·G7).
     *
     * 각 키를 `<prop name>` 으로 내고, 값은 `EmitsMarkup::propElement` 가 형태에 맞는 표기를 고른다.
     * 병합 지시자(`_append`)는 `<append>` 자식으로 돌아간다.
     *
     * `columns` 는 DataGrid 컬럼 트리라 `<column>`/`<cell>` 이 정본이다 (G7) — 노드 자리와 **같은
     * 방출기**(`NodeMarkupDecompiler::columnElements`)를 쓰므로 컬럼 표기가 자리에 따라 갈리지 않는다.
     *
     * @param  mixed  $props  주입 props
     * @return MarkupElement `<props>` 요소
     */
    private function injectionPropsElement(mixed $props): MarkupElement
    {
        if (! is_array($props) || $props === [] || array_is_list($props)) {
            return $this->el('props', [], [], $this->encode($props));
        }

        $children = [];

        foreach ($props as $key => $value) {
            $columns = $key === 'columns' && is_array($value) ? $this->nodes->columnElements($value) : null;

            $children[] = $columns !== null
                ? $this->el('prop', [$this->attr('name', (string) $key)], $columns)
                : $this->propElement((string) $key, $value);
        }

        return $this->el('props', [], $children);
    }

    /**
     * 구조값을 1급 표기로 되돌립니다 (D42 G1·G2) — 평면이면 속성, 중첩이면 `<field>` 트리.
     *
     * JSON 본문은 **되돌릴 때 쓰지 않는다.** 소스 파일에 통짜 JSON 이 남지 않게 하는 것이 이 문법의
     * 목적이므로(D42), 표현 가능한 형태는 전부 태그·속성으로 방출한다. 리스트(`[1,2]`)처럼 키가 없는
     * 값만 본문으로 남으며, 그 경우도 빈 배열은 계량 대상이 아니다.
     *
     * @param  string  $tag  태그명 (`params`/`headers`/`data`/`fallback`)
     * @param  mixed  $value  구조값
     * @return MarkupElement 요소
     */
    private function structuredValueElement(string $tag, mixed $value): MarkupElement
    {
        if (! is_array($value) || $value === []) {
            return $this->el($tag, [], [], $this->encode($value));
        }

        // 리스트는 `<item>` 자식으로 (D51 G17). 종전에는 리스트를 무조건 JSON 본문으로 냈는데,
        // 그러면 `<data-sources>["boards"]</data-sources>` 처럼 표기가 **있는데도** 통짜 JSON 이
        // 남는다 — 맵만 `<field>` 로 풀고 리스트를 빼 둔 비대칭이었다(G14 가 `<seo>` 를 열면서
        // 드러났다). 표기 불가한 리스트(빈 리스트·비-문자열 스칼라 원소)는 아래 JSON 본문으로 간다.
        if (array_is_list($value)) {
            $items = $this->structuredChildren($value);

            return $items !== null
                ? $this->el($tag, [], $items)
                : $this->el($tag, [], [], $this->encode($value));
        }

        // 평면 스칼라 맵은 속성 field-map 이 가장 짧다 — 단, 키가 속성명으로 쓸 수 없으면 `<field>` 트리로.
        if ($this->isFlatScalarMapValue($value) && $this->hasAttributeSafeKeys($value)) {
            return $this->el($tag, $this->mapAttributes($value), []);
        }

        $children = $this->structuredChildren($value);

        return $children !== null
            ? $this->el($tag, [], $children)
            : $this->el($tag, [], [], $this->encode($value));
    }

    /**
     * `data_sources` 항목 하나를 `<data-source>` 요소로 되돌립니다.
     *
     * 스칼라는 속성으로, 구조체 값(`data`·`params`·`headers`·`fallback`)은 자식 태그로 나뉜다 —
     * 컴파일러가 그 넷을 `compileFieldMap` 으로 읽으므로 같은 자리로 되돌아간다. 항목 주석은
     * 속성이 아니라 요소 **앞**의 `<!-- -->` 이라 먼저 떼어 낸다.
     *
     * @param  array<string, mixed>  $source  data_sources 항목 JSON
     * @return MarkupElement `<data-source>` 요소
     */
    private function dataSource(array $source): MarkupElement
    {
        $comment = $source['comment'] ?? null;
        unset($source['comment']);

        // 구조체 값 자식 (JSON 본문·콜백·상태 초기화·조건)
        $bodyKeys = ['data' => 'data', 'params' => 'params', 'headers' => 'headers', 'fallback' => 'fallback'];
        $attributes = [];
        $children = [];

        foreach ($source as $key => $value) {
            if (isset($bodyKeys[$key])) {
                $children[] = $this->structuredValueElement($bodyKeys[$key], $value);

                continue;
            }

            if ($key === 'onSuccess' || $key === 'onError' || $key === 'onReceive') {
                $tag = $key === 'onSuccess' ? 'success' : ($key === 'onError' ? 'fail' : 'on-receive');
                $children[] = $this->el($tag, [], $this->actions->callbackChildren($value));

                continue;
            }

            if ($key === 'errorHandling') {
                $children[] = $this->el(
                    'error-handling',
                    [],
                    $this->actions->errorHandlingChildren($value),
                    null,
                    $this->sectionComment($value),
                );

                continue;
            }

            if ($key === 'conditions') {
                $children[] = $this->conditions($value);

                continue;
            }

            if (($key === 'initLocal' || $key === 'initGlobal' || $key === 'initIsolated') && is_array($value)) {
                $tag = ['initLocal' => 'init-local', 'initGlobal' => 'init-global', 'initIsolated' => 'init-isolated'][$key];
                $children[] = $this->el($tag, [], $this->state->fields($value));

                continue;
            }

            // 임의 이름의 구조값 키(`depends_on` 리스트·`errorCondition` 객체 등)는 `<field name>`
            // 자식으로 (D42 G11) — 속성으로 내면 통짜 JSON 이 되어 D2 를 어긴다. 표현 불가한 값만
            // 종전대로 속성 타입 리터럴로 남고 원시 JSON 대장에 계량된다.
            $structured = $this->structuredChildren($value);

            if ($structured !== null) {
                $children[] = $this->el('field', [$this->attr('name', (string) $key)], $structured);

                continue;
            }

            $attributes[] = $this->valueAttr((string) $key, $value);
        }

        return $this->el('data-source', $attributes, $children, null, $comment);
    }

    /**
     * data-source 조건(`{and|or}`)을 `<all-of>`/`<any-of>` (`<expr>` 피연산자)로 되돌립니다.
     *
     * @param  array<string, mixed>  $conditions  `{and|or: [...]}`
     * @return MarkupElement 조건 요소
     */
    private function conditions(array $conditions): MarkupElement
    {
        $operator = array_key_exists('and', $conditions) ? 'and' : 'or';
        $tag = $operator === 'and' ? 'all-of' : 'any-of';
        $operands = [];

        foreach ($conditions[$operator] ?? [] as $operand) {
            $operands[] = is_array($operand)
                ? $this->conditions($operand)
                : $this->el('expr', [], [], (string) $operand);
        }

        return $this->el($tag, [], $operands);
    }

    /**
     * `defines` 를 `<defines>` + `<define name>JSON</define>` 로 되돌립니다.
     *
     * @param  array<string, mixed>  $defines  defines 객체
     * @return MarkupElement `<defines>` 요소
     */
    private function defines(array $defines): MarkupElement
    {
        $children = [];

        foreach ($defines as $name => $value) {
            // 표현 가능한 구조값은 `<field>`/`<item>` 트리로 (D42 G12) — 태그 본문 JSON 도 계량 대상이다.
            $structured = $this->structuredChildren($value);

            $children[] = $structured !== null
                ? $this->el('define', [$this->attr('name', (string) $name)], $structured)
                : $this->el('define', [$this->attr('name', (string) $name)], [], $this->encode($value));
        }

        return $this->el('defines', [], $children);
    }

    /**
     * `globalHeaders` 를 `<global-headers>` + `<match>` 로 되돌립니다.
     *
     * @param  array<int, array<string, mixed>>  $headers  globalHeaders 배열
     * @return MarkupElement `<global-headers>` 요소
     */
    private function globalHeaders(array $headers): MarkupElement
    {
        $children = [];

        foreach ($headers as $entry) {
            $attributes = [$this->attr('pattern', (string) ($entry['pattern'] ?? ''))];

            foreach ($entry['headers'] ?? [] as $name => $value) {
                $attributes[] = $this->valueAttr((string) $name, $value);
            }

            $comment = is_string($entry['comment'] ?? null) ? $entry['comment'] : null;

            $children[] = $this->el('match', $attributes, [], null, $comment);
        }

        return $this->el('global-headers', [], $children);
    }

    /**
     * `transition_overlay` 를 되돌립니다 (불리언 축약 또는 객체형).
     *
     * @param  mixed  $overlay  transition_overlay 값
     * @return MarkupElement `<transition-overlay>` 요소
     */
    private function transitionOverlay(mixed $overlay): MarkupElement
    {
        if ($overlay === true) {
            return $this->el('transition-overlay', [$this->attr('enabled')]);
        }

        $attributeKeys = ['enabled' => 'enabled', 'style' => 'style', 'target' => 'target', 'fallback_target' => 'fallback-target'];
        $attributes = [];
        $children = [];

        foreach ($overlay as $key => $value) {
            if (isset($attributeKeys[$key])) {
                $attributes[] = $key === 'enabled'
                    ? ($value === true ? $this->attr('enabled') : $this->typedAttr('enabled', $value))
                    : $this->attr($attributeKeys[$key], (string) $value);

                continue;
            }

            if ($key === 'wait_for') {
                foreach ($value as $source) {
                    $children[] = $this->el('wait-for', [], [], (string) $source);
                }

                continue;
            }

            if ($key === 'skeleton' || $key === 'spinner') {
                $skeletonAttrs = [];

                foreach ($value as $attrName => $attrValue) {
                    $skeletonAttrs[] = $this->valueAttr((string) $attrName, $attrValue);
                }

                $children[] = $this->el($key, $skeletonAttrs);
            }
        }

        return $this->el('transition-overlay', $attributes, $children);
    }

    /**
     * `scripts` 항목을 `<script>` 로 되돌립니다.
     *
     * @param  array<string, mixed>  $script  scripts 항목
     * @return MarkupElement `<script>` 요소
     */
    private function script(array $script): MarkupElement
    {
        $attributes = [
            $this->attr('src', (string) ($script['src'] ?? '')),
            $this->attr('id', (string) ($script['id'] ?? '')),
        ];

        if (array_key_exists('label', $script)) {
            $attributes[] = $this->attr('label', (string) $script['label']);
        }

        if (array_key_exists('async', $script)) {
            $attributes[] = $script['async'] === true ? $this->attr(':async', 'true') : $this->typedAttr('async', $script['async']);
        }

        if (array_key_exists('if', $script)) {
            $attributes[] = $this->conditionAttr('data-if', $script['if']);
        }

        return $this->el('script', $attributes);
    }

    /**
     * `modals` 항목을 `<modal>` 로 되돌립니다 (참조 또는 인라인 노드).
     *
     * @param  array<string, mixed>  $modal  modals 항목
     * @return MarkupElement `<modal>` 요소
     */
    private function modal(array $modal): MarkupElement
    {
        // 참조 항목은 `{partial}` 이거나 소스 주석이 얹힌 `{comment, partial}` 이다 — 주석이 있다고
        // 노드 분기로 흘려보내면 참조가 노드로 오변환된다(DocumentCompiler::compileModal 의 짝).
        $keys = array_keys($modal);
        sort($keys);

        if ($keys === ['partial'] || $keys === ['comment', 'partial']) {
            return $this->el(
                'modal',
                // 산출 `.json` → 작성 `.g7.html`. `DocumentCompiler::compileModal` 의 역함수여야
                // 왕복(JSON→마크업→JSON)이 항등이다(PartialReferenceNotation).
                [$this->attr('src', PartialReferenceNotation::toMarkup((string) $modal['partial']))],
                [],
                null,
                isset($modal['comment']) ? (string) $modal['comment'] : null,
            );
        }

        // 인라인 항목의 주석은 컴파일 때 래퍼에서 노드로 올라갔으므로 노드가 그대로 다시 찍는다.
        // `layout_name` 은 설치가 조각을 인라인하며 붙인 이름 — `<modal name>` 으로 되돌리고 노드 빌더에는
        // 그 키를 뺀 사본을 넘긴다(DocumentCompiler::compileModal 의 역함수. 종전엔 `<json key="layout_name">`).
        $attributes = [];

        if (isset($modal['layout_name']) && is_scalar($modal['layout_name'])) {
            $attributes[] = $this->attr('name', (string) $modal['layout_name']);
            unset($modal['layout_name']);
        }

        // `data_sources` 는 창이 열릴 때 받는 것 — 본문 루트의 `<data-source>` 자식으로 되돌린다
        // (DocumentCompiler::compileModal 이 compilePartialNode 로 분리하는 것의 역함수).
        //
        // **텍스트 본문에는 얹지 않는다.** `text` 와 자식은 배타라 끼우면 컴파일이 서지 않는다 —
        // 그런 모달은 키를 그대로 두어 `<json>` 탈출구가 계량하게 한다(조용히 버리면 창이 데이터를 못 받는다).
        $sources = [];

        if (! isset($modal['text'])
            && isset($modal['data_sources'])
            && is_array($modal['data_sources'])
            && array_is_list($modal['data_sources'])
            && $modal['data_sources'] !== []) {
            $sources = $modal['data_sources'];
            unset($modal['data_sources']);
        }

        $node = $this->nodes->node($modal);

        if ($sources !== []) {
            $node = new MarkupElement(
                $node->name,
                $node->attributes,
                array_merge(array_map(fn (array $source): MarkupElement => $this->dataSource($source), $sources), $node->children),
                $node->text,
                $node->comment,
                false,
                $node->line,
                $node->column,
            );
        }

        return $this->el('modal', $attributes, [$node]);
    }

    /**
     * `init_actions` 를 `<init>` 로 되돌립니다.
     *
     * @param  array<int, array<string, mixed>>  $actions  init_actions 배열
     * @return MarkupElement `<init>` 요소
     */
    private function initSection(array $actions): MarkupElement
    {
        return $this->el('init', [], array_map(
            fn (array $action): MarkupElement => $this->actions->initAction($action),
            $actions,
        ));
    }
}
