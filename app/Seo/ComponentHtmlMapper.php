<?php

namespace App\Seo;

class ComponentHtmlMapper
{
    /**
     * SEO 렌더링 기준 뷰포트 폭 (px)
     *
     * 검색 봇은 데스크톱으로 간주하므로 responsive 오버라이드 매칭에 이 값을 사용합니다.
     */
    private const DESKTOP_VIEWPORT_WIDTH = 1024;

    /**
     * responsive 브레이크포인트 프리셋 → [min, max] 폭 범위 (px)
     *
     * React ResponsiveManager.ts 의 BREAKPOINT_PRESETS 와 동일한 값입니다.
     */
    private const BREAKPOINT_PRESETS = [
        'mobile' => [0, 767],
        'tablet' => [768, 1023],
        'desktop' => [1024, PHP_INT_MAX],
        'portable' => [0, 1023],
    ];

    /**
     * 템플릿 제공 컴포넌트 매핑 (seo-config.json의 component_map)
     */
    private array $componentMap = [];

    /**
     * 템플릿 제공 렌더 모드 정의 (seo-config.json의 render_modes)
     */
    private array $renderModes = [];

    /**
     * 셀프 클로징 태그 목록 (seo-config.json의 self_closing)
     */
    private array $selfClosing = [];

    /**
     * 텍스트 추출 대상 props 키 목록 (seo-config.json의 text_props로 오버라이드 가능)
     */
    private array $textProps = ['text', 'label', 'value', 'title'];

    /**
     * React→HTML 속성명 매핑 (seo-config.json의 attr_map으로 오버라이드 가능)
     */
    private array $attrMap = [
        'className' => 'class',
        'htmlFor' => 'for',
    ];

    /**
     * 허용된 HTML 속성 목록 (seo-config.json의 allowed_attrs로 오버라이드 가능)
     */
    private array $allowedAttrs = [
        'class', 'id', 'href', 'src', 'alt', 'title', 'name', 'type',
        'placeholder', 'for', 'target', 'rel', 'width', 'height',
        'role', 'aria-label', 'aria-describedby', 'data-testid', 'style',
    ];

    /**
     * SEO 변수 (meta.seo.vars에서 해석된 값, format 모드에서 사용)
     */
    private array $seoVars = [];

    /**
     * _global 표현식 해석 콜백 (SeoRenderer에서 주입)
     */
    private ?\Closure $globalResolver = null;

    /**
     * fields 렌더 모드 실행 중 임시 참조 (renderFieldEntry에서 $t: 번역 해석용)
     */
    private ?ExpressionEvaluator $fieldsEvaluator = null;

    /**
     * fields 렌더 모드 실행 중 임시 데이터 컨텍스트
     */
    private array $fieldsContext = [];

    /**
     * 사용자 작성 HTML 정화기 (raw 렌더 모드에서 지연 생성)
     */
    private ?HtmlSanitizer $htmlSanitizer = null;

    /**
     * `value` prop 을 텍스트로 승격하지 않는 태그 목록
     *
     * 폼 컨트롤의 `value` 는 현재 선택값(속성)이지 화면에 보이는 글자가 아닙니다.
     * React 는 `<select>` 안에 `<option>` 라벨을 그리므로, 봇 화면에서만 `value` 가
     * 글자로 노출되면(예: `<select>created_at_desc</select>`) 두 화면이 어긋나고
     * 색인에도 내부 식별자가 섞입니다.
     */
    private const VALUE_NOT_TEXT_TAGS = ['select', 'option', 'input', 'textarea', 'progress', 'meter'];

    /**
     * 템플릿 제공 컴포넌트 매핑을 설정합니다.
     *
     * @param  array  $componentMap  seo-config.json의 component_map
     */
    public function setComponentMap(array $componentMap): void
    {
        $this->componentMap = $componentMap;
    }

    /**
     * 템플릿 제공 렌더 모드를 설정합니다.
     *
     * @param  array  $renderModes  seo-config.json의 render_modes
     */
    public function setRenderModes(array $renderModes): void
    {
        $this->renderModes = $renderModes;
    }

    /**
     * 셀프 클로징 태그 목록을 설정합니다.
     *
     * @param  array  $selfClosing  seo-config.json의 self_closing
     */
    public function setSelfClosing(array $selfClosing): void
    {
        $this->selfClosing = $selfClosing;
    }

    /**
     * 텍스트 추출 대상 props 키 목록을 설정합니다.
     *
     * @param  array  $textProps  seo-config.json의 text_props
     */
    public function setTextProps(array $textProps): void
    {
        $this->textProps = $textProps;
    }

    /**
     * React→HTML 속성명 매핑을 설정합니다.
     *
     * @param  array  $attrMap  seo-config.json의 attr_map
     */
    public function setAttrMap(array $attrMap): void
    {
        $this->attrMap = $attrMap;
    }

    /**
     * 허용된 HTML 속성 목록을 설정합니다.
     *
     * @param  array  $allowedAttrs  seo-config.json의 allowed_attrs
     */
    public function setAllowedAttrs(array $allowedAttrs): void
    {
        $this->allowedAttrs = $allowedAttrs;
    }

    /**
     * SEO 변수를 설정합니다 (meta.seo.vars에서 해석된 값).
     *
     * format 렌더 모드에서 {key} 플레이스홀더 해석 시
     * props → seoVars → defaults 순서로 참조됩니다.
     *
     * @param  array  $seoVars  해석된 SEO 변수 (키 → 값)
     */
    public function setSeoVars(array $seoVars): void
    {
        $this->seoVars = $seoVars;
    }

    /**
     * _global 표현식 해석 콜백을 설정합니다.
     *
     * @param  \Closure  $resolver  _global 경로를 해석하는 콜백 (string → ?string)
     */
    public function setGlobalResolver(\Closure $resolver): void
    {
        $this->globalResolver = $resolver;
    }

    /**
     * 컴포넌트 트리를 HTML 문자열로 변환합니다.
     *
     * @param  array  $components  컴포넌트 배열
     * @param  array  $context  데이터 컨텍스트
     * @param  ExpressionEvaluator  $evaluator  표현식 평가기
     * @return string HTML 문자열
     */
    public function render(array $components, array $context, ExpressionEvaluator $evaluator): string
    {
        $html = '';

        foreach ($components as $component) {
            // children 배열 내 문자열 = 텍스트 노드
            // React DynamicRenderer 는 이 문자열을 그대로 반환한다(표현식·번역 미해석).
            // SEO 가 여기서 표현식을 해석하면 반대 방향의 패리티 격차가 생기므로,
            // 이스케이프만 하고 리터럴로 출력한다.
            if (is_string($component)) {
                $html .= e($component);

                continue;
            }
            if (! is_array($component)) {
                continue;
            }
            $html .= $this->renderComponent($component, $context, $evaluator);
        }

        return $html;
    }

    /**
     * 단일 컴포넌트를 HTML로 변환합니다.
     *
     * @param  array  $component  컴포넌트 정의
     * @param  array  $context  데이터 컨텍스트
     * @param  ExpressionEvaluator  $evaluator  표현식 평가기
     * @return string HTML 문자열
     */
    private function renderComponent(array $component, array $context, ExpressionEvaluator $evaluator): string
    {
        // Extension Point 주입 컴포넌트의 props를 평가 컨텍스트에 주입
        // LayoutExtensionService가 호스트 props를 주입 컴포넌트 최상위 extensionPointProps로
        // 부착하므로, 여기서 해석해 컨텍스트에 넣어야 {{extensionPointProps.xxx}}가 평가된다.
        // $context는 값 전달이므로 이 노드의 서브트리에만 상속되고 형제로 새지 않는다
        // (React DynamicRenderer의 extendedDataContext 전파와 동형).
        // if/iteration 평가에도 이미 반영되어야 하므로 가장 먼저 처리한다.
        $this->injectExtensionPointContext($component, $context, $evaluator);

        // type: "iterator" → iteration 속성으로 변환 (React DynamicRenderer와 동일 매핑)
        $component = $this->normalizeIteratorNode($component);

        // SEO는 데스크톱 뷰로 렌더링 (검색 봇 = 데스크톱)
        // 데스크톱 폭에 매칭되는 responsive 오버라이드를 기본 속성에 병합
        $component = $this->applyResponsiveOverrides($component);

        // 조건부 렌더링 (if / condition / conditions)
        //
        // iteration 이 있으면 여기서 끊지 않는다. 조건이 항목 변수(`{{user.is_active}}` 등)를
        // 참조하면 이 시점 컨텍스트에는 그 변수가 없어 항상 거짓이 되고, 목록 전체가
        // 사라진다. 항목별 컨텍스트에서 renderIteration 이 같은 조건을 다시 평가한다
        // (iteration 만 제거한 정의를 넘기므로 `if` 는 그대로 남는다).
        // React DynamicRenderer 와 동일한 순서 — 한쪽만 고치면 봇 화면에서만 목록이 빈다.
        if (! isset($component['iteration']) && ! $this->shouldRender($component, $context, $evaluator)) {
            return '';
        }

        // 반복 렌더링 (iteration)
        if (isset($component['iteration'])) {
            return $this->renderIteration($component, $context, $evaluator);
        }

        // classMap: 조건부 CSS 클래스 해석 → className에 병합
        if (isset($component['classMap'])) {
            $component = $this->resolveClassMap($component, $context, $evaluator);
        }

        $name = $component['name'] ?? '';

        // component_map에서 매핑 조회
        $configEntry = $this->componentMap[$name] ?? null;

        $tag = 'div'; // fallback
        $html = '';

        if ($configEntry) {
            // skip: true → SEO에서 렌더링 제외 (인터랙티브 전용 컴포넌트)
            if (! empty($configEntry['skip'])) {
                return '';
            }

            $tag = $configEntry['tag'] ?? 'div';

            // Fragment: 래퍼 태그 없이 children만 렌더링
            if ($tag === '') {
                return $this->renderChildren($component, $context, $evaluator);
            }

            // Icon name → Font Awesome class 변환
            if (! empty($configEntry['name_to_class'])) {
                $component = $this->transformIconProps($configEntry, $component, $context, $evaluator);
            }

            // 특수 렌더링 모드
            $renderMode = $configEntry['render'] ?? null;
            if ($renderMode) {
                $html = $this->renderByMode($renderMode, $tag, $configEntry, $component, $context, $evaluator);
            } else {
                // 일반 태그 렌더링
                $html = $this->renderTag($tag, $component, $context, $evaluator);
            }
        } else {
            // config에 없는 컴포넌트 → div fallback
            $html = $this->renderTag('div', $component, $context, $evaluator);
        }

        // navigate/openWindow 링크 자동 생성
        $linkAction = $this->extractLinkAction($component);
        if ($linkAction !== null) {
            $href = $this->resolveNavigateHref($linkAction['params'], $context, $evaluator);
            if ($href !== null) {
                $html = $this->applyNavigateLink($html, $tag, $href, $linkAction['handler'], $component);
            }
        }

        return $html;
    }

    /**
     * Extension Point 주입 컴포넌트의 props를 평가 컨텍스트에 주입합니다.
     *
     * LayoutExtensionService는 extension_point 호스트의 props를 주입 컴포넌트 최상위
     * `extensionPointProps` 키로 부착합니다. 주입 컴포넌트는 `{{extensionPointProps.content}}`
     * 형태로 그 값을 참조하므로, 렌더링 전에 표현식을 해석해 컨텍스트에 넣어야 합니다.
     *
     * 해석된 값은 이 노드와 그 자손 전체에 상속됩니다 (React DynamicRenderer가
     * extendedDataContext를 자식에게 전달하는 동작과 동일).
     *
     * `extensionPointCallbacks`는 의도적으로 주입하지 않습니다 — SEO 파이프라인에는
     * 액션 디스패처가 없고, 액션 배열을 컨텍스트에 넣으면 HTML에 JSON 파편이
     * 노출될 수 있습니다.
     *
     * @param  array  $component  컴포넌트 정의 (해석 후 extensionPointProps 키 제거)
     * @param  array  $context  데이터 컨텍스트 (해석된 값이 주입됨)
     * @param  ExpressionEvaluator  $evaluator  표현식 평가기
     */
    private function injectExtensionPointContext(array &$component, array &$context, ExpressionEvaluator $evaluator): void
    {
        if (! isset($component['extensionPointProps']) || ! is_array($component['extensionPointProps'])) {
            return;
        }

        $context['extensionPointProps'] = $this->resolveAllProps(
            $component['extensionPointProps'],
            $context,
            $evaluator
        );

        // iteration 재진입 시 항목별로 재해석되지 않도록 제거
        // (React는 바깥 컨텍스트 기준 1회 해석 후 상속)
        unset($component['extensionPointProps']);
    }

    /**
     * `type: "iterator"` 노드를 `iteration` 속성 형태로 정규화합니다.
     *
     * React DynamicRenderer가 `{ type: "iterator", data, itemName, indexName }` 를
     * `iteration: { source, item_var, index_var }` 로 변환하는 것과 동일합니다.
     *
     * @param  array  $component  컴포넌트 정의
     * @return array 정규화된 컴포넌트 정의
     */
    private function normalizeIteratorNode(array $component): array
    {
        if (($component['type'] ?? null) !== 'iterator' || ! isset($component['data'])) {
            return $component;
        }

        $component['iteration'] = [
            'source' => $component['data'],
            'item_var' => $component['itemName'] ?? 'item',
            'index_var' => $component['indexName'] ?? null,
        ];

        unset($component['data'], $component['itemName'], $component['indexName']);

        return $component;
    }

    /**
     * 데스크톱 폭에 해당하는 responsive 오버라이드를 병합합니다.
     *
     * SEO는 검색 봇을 데스크톱 뷰포트로 간주하므로 `desktop` 프리셋과 데스크톱 폭
     * (1024px 이상)을 포함하는 숫자 범위 키만 적용합니다. mobile/tablet/portable 은
     * 데스크톱에서 매칭되지 않으므로 무시합니다.
     *
     * 오버라이드 항목은 React와 동일하게 props/if/text/children/iteration 입니다.
     *
     * @param  array  $component  컴포넌트 정의
     * @return array 오버라이드가 병합된 컴포넌트 정의
     */
    private function applyResponsiveOverrides(array $component): array
    {
        if (empty($component['responsive']) || ! is_array($component['responsive'])) {
            return $component;
        }

        $breakpoint = $this->matchingBreakpointKey($component['responsive']);
        if ($breakpoint === null) {
            return $component;
        }

        $override = $component['responsive'][$breakpoint];

        if (isset($override['props'])) {
            $component['props'] = array_merge($component['props'] ?? [], $override['props']);
        }
        foreach (['if', 'text', 'children', 'iteration'] as $key) {
            if (isset($override[$key])) {
                $component[$key] = $override[$key];
            }
        }

        return $component;
    }

    /**
     * 데스크톱 폭에 매칭되는 responsive 키를 하나만 고릅니다.
     *
     * React `ResponsiveManager::getMatchingKey()` 와 동일한 우선순위를 따릅니다:
     * ① 커스텀 범위가 프리셋보다 우선 ② 좁은 범위가 넓은 범위보다 우선.
     * 매칭되는 키를 전부 순차 병합하면 JSON 키 선언 순서에 따라 결과가 달라진다.
     *
     * @param  array  $responsive  responsive 오버라이드 맵
     * @return string|null 적용할 키 (없으면 null)
     */
    private function matchingBreakpointKey(array $responsive): ?string
    {
        $matched = [];

        foreach ($responsive as $breakpoint => $override) {
            if (! is_array($override)) {
                continue;
            }

            $range = $this->parseBreakpointRange((string) $breakpoint);
            if ($range === null) {
                continue;
            }

            [$min, $max] = $range;
            if ($min > self::DESKTOP_VIEWPORT_WIDTH || $max < self::DESKTOP_VIEWPORT_WIDTH) {
                continue;
            }

            $matched[] = [
                'key' => (string) $breakpoint,
                'isPreset' => isset(self::BREAKPOINT_PRESETS[$breakpoint]),
                'width' => $max - $min,
            ];
        }

        if ($matched === []) {
            return null;
        }

        usort($matched, function (array $a, array $b): int {
            if ($a['isPreset'] !== $b['isPreset']) {
                return $a['isPreset'] ? 1 : -1;
            }

            return $a['width'] <=> $b['width'];
        });

        return $matched[0]['key'];
    }

    /**
     * responsive 브레이크포인트 키를 [min, max] 범위로 파싱합니다.
     *
     * 프리셋(`desktop`/`portable`/`mobile`/`tablet`)과 `1024-`, `1280-1535`,
     * `-599` 같은 숫자 범위를 지원합니다. `min > max` 인 잘못된 범위는 무시합니다
     * (React `ResponsiveManager::parseRange()` 와 동일).
     *
     * @param  string  $breakpoint  브레이크포인트 키
     * @return array{0: int, 1: int}|null [min, max] (파싱 불가 시 null)
     */
    private function parseBreakpointRange(string $breakpoint): ?array
    {
        if (isset(self::BREAKPOINT_PRESETS[$breakpoint])) {
            return self::BREAKPOINT_PRESETS[$breakpoint];
        }

        if (! preg_match('/^(-?\d*)-(-?\d*)$/', $breakpoint, $matches)) {
            return null;
        }

        $min = $matches[1] === '' ? 0 : (int) $matches[1];
        $max = $matches[2] === '' ? PHP_INT_MAX : (int) $matches[2];

        return $min <= $max ? [$min, $max] : null;
    }

    /**
     * 컴포넌트를 렌더링해야 하는지 판정합니다 (if / condition / conditions).
     *
     * React RenderHelpers.evaluateRenderCondition과 동일한 우선순위를 따릅니다:
     * `if` → `condition`(별칭) → `conditions`(AND/OR 그룹 또는 if/else 체인).
     *
     * @param  array  $component  컴포넌트 정의
     * @param  array  $context  데이터 컨텍스트
     * @param  ExpressionEvaluator  $evaluator  표현식 평가기
     * @return bool 렌더링 대상이면 true
     */
    private function shouldRender(array $component, array $context, ExpressionEvaluator $evaluator): bool
    {
        if (isset($component['if'])) {
            return $this->evaluateBooleanExpression($component['if'], $context, $evaluator);
        }

        if (isset($component['condition'])) {
            return $this->evaluateBooleanExpression($component['condition'], $context, $evaluator);
        }

        if (! isset($component['conditions'])) {
            return true;
        }

        return $this->evaluateConditions($component['conditions'], $context, $evaluator);
    }

    /**
     * conditions 속성(AND/OR 그룹 또는 if/else 체인)을 평가합니다.
     *
     * - 문자열 / `{and: [...]}` / `{or: [...]}` → 조건식 평가
     * - `[{if: ...}, {if: ...}, {}]` → 하나라도 매칭되면 true (if 없는 항목은 else 브랜치)
     * - 위 어느 형식도 아니면 **렌더링**한다 — React `evaluateRenderCondition()` 이 알 수 없는
     *   형식에 대해 경고 후 true 를 반환하므로, 여기서 false 를 주면 봇 화면에서만 노드가 사라진다.
     *
     * @param  mixed  $conditions  conditions 속성 값
     * @param  array  $context  데이터 컨텍스트
     * @param  ExpressionEvaluator  $evaluator  표현식 평가기
     * @return bool 평가 결과
     */
    private function evaluateConditions(mixed $conditions, array $context, ExpressionEvaluator $evaluator): bool
    {
        if ($this->isConditionExpression($conditions)) {
            return $this->evaluateConditionExpression($conditions, $context, $evaluator);
        }

        if ($this->isConditionBranches($conditions)) {
            foreach ($conditions as $branch) {
                if (! is_array($branch)) {
                    continue;
                }
                // if 없는 항목 = else 브랜치 (항상 매칭)
                if (! isset($branch['if'])) {
                    return true;
                }
                if ($this->evaluateConditionExpression($branch['if'], $context, $evaluator)) {
                    return true;
                }
            }

            return false;
        }

        // 알 수 없는 형식 → 렌더링 (React 동형)
        return true;
    }

    /**
     * 값이 단일 ConditionExpression(문자열 / `{and:[]}` / `{or:[]}`) 인지 판정합니다.
     *
     * React `ConditionEvaluator.isConditionExpression()` 과 동일한 판정입니다.
     *
     * @param  mixed  $value  판정 대상
     * @return bool ConditionExpression 이면 true
     */
    private function isConditionExpression(mixed $value): bool
    {
        if (is_string($value) || is_bool($value)) {
            return true;
        }

        if (is_array($value) && ! array_is_list($value)) {
            return array_key_exists('and', $value) || array_key_exists('or', $value);
        }

        return false;
    }

    /**
     * 값이 ConditionBranch[] 인지 판정합니다.
     *
     * 빈 배열은 브랜치 배열로 보지 않으며, 첫 항목에 `if` 또는 `then` 이 있어야 합니다
     * (React `ConditionEvaluator.isConditionBranches()` 와 동일).
     *
     * @param  mixed  $value  판정 대상
     * @return bool ConditionBranch[] 이면 true
     */
    private function isConditionBranches(mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            return false;
        }

        $first = $value[0];
        if (! is_array($first)) {
            return false;
        }

        return array_key_exists('if', $first) || array_key_exists('then', $first);
    }

    /**
     * 조건식(문자열 또는 AND/OR 그룹)을 평가합니다.
     *
     * 빈 AND 그룹은 true, 빈 OR 그룹은 false 를 반환합니다 (React와 동일).
     *
     * @param  mixed  $condition  조건식
     * @param  array  $context  데이터 컨텍스트
     * @param  ExpressionEvaluator  $evaluator  표현식 평가기
     * @return bool 평가 결과
     */
    private function evaluateConditionExpression(mixed $condition, array $context, ExpressionEvaluator $evaluator): bool
    {
        if (! is_array($condition)) {
            return $this->evaluateBooleanExpression($condition, $context, $evaluator);
        }

        if (array_key_exists('and', $condition)) {
            if (! is_array($condition['and']) || $condition['and'] === []) {
                return true;
            }
            foreach ($condition['and'] as $sub) {
                if (! $this->evaluateConditionExpression($sub, $context, $evaluator)) {
                    return false;
                }
            }

            return true;
        }

        if (array_key_exists('or', $condition)) {
            if (! is_array($condition['or']) || $condition['or'] === []) {
                return false;
            }
            foreach ($condition['or'] as $sub) {
                if ($this->evaluateConditionExpression($sub, $context, $evaluator)) {
                    return true;
                }
            }

            return false;
        }

        // 알 수 없는 조건 형식
        return false;
    }

    /**
     * 단일 조건 표현식을 boolean으로 평가합니다.
     *
     * 문자열 결과의 falsy 판정 목록은 React RenderHelpers.evaluateIfCondition과
     * 동일합니다 (`''`, `false`, `0`, `null`, `undefined` — 대소문자·공백 무시).
     *
     * @param  mixed  $condition  조건 표현식 (문자열 또는 boolean)
     * @param  array  $context  데이터 컨텍스트
     * @param  ExpressionEvaluator  $evaluator  표현식 평가기
     * @return bool 평가 결과
     */
    private function evaluateBooleanExpression(mixed $condition, array $context, ExpressionEvaluator $evaluator): bool
    {
        if (is_bool($condition)) {
            return $condition;
        }

        // 조건이 비어 있으면 조건 없음으로 간주 (React: `if (!ifCondition) return true`)
        if ($condition === null || $condition === '') {
            return true;
        }

        $resolved = $evaluator->evaluate((string) $condition, $context);

        return ! in_array(strtolower(trim($resolved)), ['', 'false', '0', 'null', 'undefined'], true);
    }

    /**
     * 선언적 렌더 모드로 컴포넌트를 HTML로 변환합니다.
     *
     * 우선순위가 renderTag() 와 반대인 것은 의도된 것이다 — renderTag() 는 text 를
     * children 보다 먼저 쓰지만(React DynamicRenderer 동형), 이 경로는 자체 렌더링을
     * 가진 집합 컴포넌트(Select·Pagination·HtmlContent 등) 전용이라 모드 출력이 먼저다.
     * React 에서도 Select 는 options prop 으로 스스로 option 을 그리고 text/children 을
     * 무시하므로, 여기서 text 를 우선하면 옵션 목록이 통째로 사라져 오히려 어긋난다.
     *
     * @param  string  $mode  렌더 모드명 (render_modes 키)
     * @param  string  $tag  외부 래퍼 HTML 태그
     * @param  array  $configEntry  seo-config.json의 component_map 엔트리
     * @param  array  $component  컴포넌트 정의
     * @param  array  $context  데이터 컨텍스트
     * @param  ExpressionEvaluator  $evaluator  표현식 평가기
     * @return string HTML 문자열
     */
    private function renderByMode(string $mode, string $tag, array $configEntry, array $component, array $context, ExpressionEvaluator $evaluator): string
    {
        $modeConfig = $this->renderModes[$mode] ?? null;
        if (! $modeConfig) {
            return '';
        }

        $props = $component['props'] ?? [];
        $attrs = $this->buildAttributes($props, $context, $evaluator);

        $innerHtml = match ($modeConfig['type'] ?? '') {
            'iterate' => $this->renderIterateMode($modeConfig, $configEntry, $component, $context, $evaluator),
            'format' => $this->renderFormatMode($modeConfig, $configEntry, $component, $context, $evaluator),
            'raw' => $this->renderRawMode($modeConfig, $configEntry, $component, $context, $evaluator),
            'fields' => $this->renderFieldsMode($modeConfig, $configEntry, $component, $context, $evaluator),
            'pagination' => $this->renderPaginationMode($modeConfig, $component, $context, $evaluator),
            default => '',
        };

        // children 추가
        $childrenHtml = $this->renderChildren($component, $context, $evaluator);
        if ($childrenHtml !== '') {
            $innerHtml .= $childrenHtml;
        }

        // 텍스트 콘텐츠 fallback
        if ($innerHtml === '') {
            $innerHtml = $this->resolveTextContent($component, $props, $context, $evaluator, $tag);
        }

        return "<{$tag}{$attrs}>{$innerHtml}</{$tag}>";
    }

    /**
     * 일반 태그로 렌더링합니다.
     *
     * @param  string  $tag  HTML 태그
     * @param  array  $component  컴포넌트 정의
     * @param  array  $context  데이터 컨텍스트
     * @param  ExpressionEvaluator  $evaluator  표현식 평가기
     * @return string HTML 문자열
     */
    private function renderTag(string $tag, array $component, array $context, ExpressionEvaluator $evaluator): string
    {
        $props = $component['props'] ?? [];
        $attrs = $this->buildAttributes($props, $context, $evaluator);

        if (in_array($tag, $this->selfClosing)) {
            return "<{$tag}{$attrs}>";
        }

        // 노드 레벨 text가 있으면 children보다 우선 (React DynamicRenderer와 동일 우선순위).
        // text 키가 있으면 해석 결과가 빈 문자열이어도 그것이 최종 값이다 — React 는
        // text !== undefined 인 순간 children 경로로 내려가지 않는다(DynamicRenderer.tsx:2591,2639).
        $nodeText = $this->resolveNodeText($component, $context, $evaluator);

        if ($nodeText !== null) {
            $innerHtml = $nodeText;
        } else {
            $innerHtml = $this->renderChildren($component, $context, $evaluator);

            if ($innerHtml === '') {
                $innerHtml = $this->resolveTextContent($component, $props, $context, $evaluator, $tag);
            }
        }

        // dangerouslySetInnerHTML 처리 — 일반 화면과 동일 강도로 정화 후 삽입
        if ($innerHtml === '' && isset($props['dangerouslySetInnerHTML'])) {
            $rawHtml = $evaluator->evaluate((string) $props['dangerouslySetInnerHTML'], $context);
            if ($rawHtml !== '') {
                $innerHtml = $this->htmlSanitizer()->sanitize($rawHtml);
            }
        }

        return "<{$tag}{$attrs}>{$innerHtml}</{$tag}>";
    }

    /**
     * iterate 타입: 배열 데이터를 순회하며 아이템별 HTML 생성
     *
     * @param  array  $modeConfig  render_modes 엔트리
     * @param  array  $configEntry  component_map 엔트리
     * @param  array  $component  컴포넌트 정의
     * @param  array  $context  데이터 컨텍스트
     * @param  ExpressionEvaluator  $evaluator  표현식 평가기
     * @return string HTML 문자열
     */
    private function renderIterateMode(array $modeConfig, array $configEntry, array $component, array $context, ExpressionEvaluator $evaluator): string
    {
        // source 해석: "$props_source" → configEntry의 props_source 키에서 실제 키 가져옴
        $sourceKey = $this->resolveSourceKey($modeConfig['source'] ?? '', $configEntry);

        $props = $component['props'] ?? [];
        $dataExpr = $props[$sourceKey] ?? '';

        if (! is_array($dataExpr) && $dataExpr === '') {
            return '';
        }

        // props에 배열이 직접 전달된 경우 바로 사용
        if (is_array($dataExpr)) {
            $data = $dataExpr;
        } else {
            // 표현식에서 배열 경로 추출
            $normalizedPath = str_replace(['?.', '{{', '}}'], ['.', '', ''], (string) $dataExpr);
            $normalizedPath = trim($normalizedPath);

            if (str_contains($normalizedPath, '??')) {
                $normalizedPath = trim(explode('??', $normalizedPath, 2)[0]);
            }

            $data = data_get($context, $normalizedPath);
        }

        if (! is_array($data) || empty($data)) {
            return '';
        }

        $itemTag = $modeConfig['item_tag'] ?? 'div';
        $itemAttrs = $modeConfig['item_attrs'] ?? [];
        $itemContent = $modeConfig['item_content'] ?? null;
        $badgeField = $modeConfig['badge_field'] ?? null;

        $html = '';
        foreach ($data as $item) {
            $attrStr = $itemAttrs ? $this->buildIterateItemAttributes($itemAttrs, $item) : '';

            if ($itemContent !== null) {
                // 콘텐츠 기반 렌더링 (탭 리스트, 선택 목록 등)
                // item_attrs 가 함께 선언되면 속성과 라벨을 모두 그린다 — `<option value="x">라벨</option>`
                // 처럼 둘이 다 필요한 요소를 표현하기 위함이다.
                $content = $this->resolveFieldPattern($itemContent, $item);
                $evaluatedContent = $content === ''
                    ? ''
                    : e($evaluator->evaluate((string) $content, $context));

                if ($evaluatedContent === '' && $attrStr === '') {
                    continue;
                }

                $badge = '';
                if ($badgeField && isset($item[$badgeField])) {
                    $evaluatedBadge = $evaluator->evaluate((string) $item[$badgeField], $context);
                    if ($evaluatedBadge !== '' && $evaluatedBadge !== '0') {
                        $badge = ' <span>('.e($evaluatedBadge).')</span>';
                    }
                }

                $html .= "<{$itemTag}{$attrStr}>".$evaluatedContent.$badge."</{$itemTag}>";
            } elseif ($itemAttrs) {
                // 속성 기반 렌더링 (이미지 갤러리 등)
                if (in_array($itemTag, $this->selfClosing)) {
                    $html .= "<{$itemTag}{$attrStr}>";
                } else {
                    $html .= "<{$itemTag}{$attrStr}></{$itemTag}>";
                }
            } else {
                // 단순 아이템 (문자열)
                $value = is_string($item) ? e($item) : '';
                $html .= "<{$itemTag}>".$value."</{$itemTag}>";
            }
        }

        return $html;
    }

    /**
     * iterate 모드 아이템의 속성 문자열을 만듭니다.
     *
     * @param  array  $itemAttrs  render_modes 의 item_attrs 정의
     * @param  mixed  $item  현재 아이템
     * @return string 속성 문자열 (앞에 공백 포함, 없으면 빈 문자열)
     */
    private function buildIterateItemAttributes(array $itemAttrs, mixed $item): string
    {
        $attrStr = '';

        foreach ($itemAttrs as $attrName => $fieldPattern) {
            $value = $this->resolveFieldPattern($fieldPattern, $item);
            if ($value === '') {
                continue;
            }

            // src 속성의 상대 경로를 절대 경로로 변환
            if ($attrName === 'src' && ! str_starts_with($value, 'http')) {
                $value = url($value);
            }

            $attrStr .= " {$attrName}=\"".e($value).'"';
        }

        return $attrStr;
    }

    /**
     * format 타입: 포맷 문자열의 {key} 플레이스홀더를 props로 치환
     *
     * @param  array  $modeConfig  render_modes 엔트리
     * @param  array  $configEntry  component_map 엔트리
     * @param  array  $component  컴포넌트 정의
     * @param  array  $context  데이터 컨텍스트
     * @param  ExpressionEvaluator  $evaluator  표현식 평가기
     * @return string HTML 문자열
     */
    private function renderFormatMode(array $modeConfig, array $configEntry, array $component, array $context, ExpressionEvaluator $evaluator): string
    {
        $format = $configEntry['format'] ?? '';
        $defaults = $configEntry['defaults'] ?? [];
        $props = $component['props'] ?? [];

        if ($format === '') {
            return '';
        }

        // 포맷 내 {key} 또는 {key.sub} 플레이스홀더를 props → seoVars → defaults 순서로 치환
        // dot notation 지원: {author.nickname} → props['author'] 객체에서 'nickname' 추출
        $seoVars = $this->seoVars;
        $result = preg_replace_callback('/\{([\w.]+)\}/', function ($matches) use ($props, $seoVars, $defaults, $context, $evaluator) {
            $key = $matches[1];

            // dot notation 처리 (예: author.nickname)
            if (str_contains($key, '.')) {
                [$rootKey, $subPath] = explode('.', $key, 2);
                if (isset($props[$rootKey])) {
                    $resolved = $evaluator->evaluateRaw((string) $props[$rootKey], $context);
                    if (is_array($resolved)) {
                        $value = data_get($resolved, $subPath, '');

                        return is_string($value) || is_numeric($value) ? e((string) $value) : '';
                    }
                }

                return e($defaults[$key] ?? '');
            }

            // 1. 컴포넌트 props (최우선)
            if (isset($props[$key])) {
                $value = $evaluator->evaluate((string) $props[$key], $context);
                if ($value !== '') {
                    return e($value);
                }
            }

            // 2. SEO 변수 (meta.seo.vars에서 해석된 값)
            if (isset($seoVars[$key]) && $seoVars[$key] !== '') {
                return e($seoVars[$key]);
            }

            // 3. seo-config.json defaults (최종 폴백)
            return e($defaults[$key] ?? '');
        }, $format);

        return $result ?? '';
    }

    /**
     * raw 타입: 콘텐츠를 `isHtml` 판정에 따라 평문 또는 정화된 HTML 로 출력
     *
     * 일반 화면의 `HtmlContent` 컴포지트와 동일한 규칙입니다.
     * - `isHtml=false` → 평문 (전체 이스케이프)
     * - `isHtml=true`(기본) → 정화 후 HTML 로 출력
     *
     * 정화·이스케이프를 생략하면 봇 화면에서만 사용자 작성 `<script>` 가 살아남아,
     * 주소에 봇 파라미터를 붙이는 것만으로 실행됩니다.
     *
     * @param  array  $modeConfig  render_modes 엔트리
     * @param  array  $configEntry  component_map 엔트리
     * @param  array  $component  컴포넌트 정의
     * @param  array  $context  데이터 컨텍스트
     * @param  ExpressionEvaluator  $evaluator  표현식 평가기
     * @return string HTML 문자열
     */
    private function renderRawMode(array $modeConfig, array $configEntry, array $component, array $context, ExpressionEvaluator $evaluator): string
    {
        // source 해석
        $sourceKey = $this->resolveSourceKey($modeConfig['source'] ?? '', $configEntry);

        $props = $component['props'] ?? [];

        // text 속성 우선, 그 다음 props_source
        $contentExpr = $component['text'] ?? $props[$sourceKey] ?? $props['text'] ?? '';

        if ($contentExpr === '') {
            return '';
        }

        $content = $evaluator->evaluate((string) $contentExpr, $context);

        if ($content === '') {
            return '';
        }

        // isHtml 미지정은 HTML 로 간주 (HtmlContent 의 기본값 isHtml = true 와 동일)
        if (! $this->evaluateBooleanExpression($props['isHtml'] ?? true, $context, $evaluator)) {
            return e($content);
        }

        return $this->htmlSanitizer()->sanitize($content);
    }

    /**
     * HTML 정화기를 반환합니다 (지연 생성).
     *
     * @return HtmlSanitizer 정화기
     */
    private function htmlSanitizer(): HtmlSanitizer
    {
        return $this->htmlSanitizer ??= new HtmlSanitizer;
    }

    /**
     * fields 타입: 객체 props에서 필드를 추출하여 HTML 생성
     *
     * 컴포지트 컴포넌트(ProductCard 등)가 받는 객체 prop에서
     * seo-config.json에 선언된 필드 목록을 추출하여 SEO용 HTML을 생성합니다.
     *
     * @param  array  $modeConfig  render_modes 엔트리
     * @param  array  $configEntry  component_map 엔트리
     * @param  array  $component  컴포넌트 정의
     * @param  array  $context  데이터 컨텍스트
     * @param  ExpressionEvaluator  $evaluator  표현식 평가기
     * @return string HTML 문자열
     */
    private function renderFieldsMode(array $modeConfig, array $configEntry, array $component, array $context, ExpressionEvaluator $evaluator): string
    {
        $source = $modeConfig['source'] ?? '';
        $props = $component['props'] ?? [];

        // $all_props: 모든 props를 표현식 해석하여 데이터 객체로 사용
        if ($source === '$all_props') {
            $data = $this->resolveAllProps($props, $context, $evaluator);
        } else {
            $sourceKey = $this->resolveSourceKey($source, $configEntry);
            $dataExpr = $props[$sourceKey] ?? '';
            $data = $this->resolveObjectFromExpression($dataExpr, $context);
        }

        if (! is_array($data) || empty($data)) {
            return '';
        }

        // fields 렌더링 중 evaluator/context 임시 저장 ($t: 번역 해석용)
        $this->fieldsEvaluator = $evaluator;
        $this->fieldsContext = $context;

        try {
            $fields = $modeConfig['fields'] ?? [];
            $html = '';

            foreach ($fields as $field) {
                $entry = $this->renderFieldEntry($field, $data);
                if ($entry !== '') {
                    $html .= $entry."\n";
                }
            }

            // link 래핑: 모든 필드를 <a> 태그로 감쌈
            if ($html !== '' && isset($modeConfig['link']['href'])) {
                $linkHref = $this->resolveFieldsLink($modeConfig['link'], $data);
                if ($linkHref !== null) {
                    $html = '<a href="'.e($linkHref).'">'."\n".$html.'</a>'."\n";
                }
            }

            return $html;
        } finally {
            $this->fieldsEvaluator = null;
            $this->fieldsContext = [];
        }
    }

    /**
     * pagination 모드: 컴포넌트 props에서 currentPage/totalPages를 읽어 페이지 링크를 생성합니다.
     *
     * seo-config.json 설정 예:
     *   "Pagination": { "tag": "nav", "render": "pagination_links", "current_page_prop": "currentPage", "total_pages_prop": "totalPages" }
     *
     * @param  array  $modeConfig  렌더 모드 설정 (max_links 등)
     * @param  array  $component  컴포넌트 정의
     * @param  array  $context  데이터 컨텍스트
     * @param  ExpressionEvaluator  $evaluator  표현식 평가기
     * @return string HTML 문자열
     */
    private function renderPaginationMode(array $modeConfig, array $component, array $context, ExpressionEvaluator $evaluator): string
    {
        $props = $component['props'] ?? [];

        // props에서 currentPage, totalPages 해석
        $currentPageProp = $modeConfig['current_page_prop'] ?? 'currentPage';
        $totalPagesProp = $modeConfig['total_pages_prop'] ?? 'totalPages';

        $currentPage = 1;
        $totalPages = 1;

        if (isset($props[$currentPageProp])) {
            $val = $evaluator->evaluate((string) $props[$currentPageProp], $context);
            if (is_numeric($val)) {
                $currentPage = (int) $val;
            }
        }
        if (isset($props[$totalPagesProp])) {
            $val = $evaluator->evaluate((string) $props[$totalPagesProp], $context);
            if (is_numeric($val)) {
                $totalPages = (int) $val;
            }
        }

        if ($totalPages <= 1) {
            return '';
        }

        // 현재 URL 경로 (route.path)
        $basePath = $context['route']['path'] ?? '/';
        $maxLinks = (int) ($modeConfig['max_links'] ?? 10);

        // 표시할 페이지 범위 계산
        $start = max(1, $currentPage - (int) floor($maxLinks / 2));
        $end = min($totalPages, $start + $maxLinks - 1);
        $start = max(1, $end - $maxLinks + 1);

        $html = '';
        for ($i = $start; $i <= $end; $i++) {
            $href = e($basePath.'?page='.$i);
            if ($i === $currentPage) {
                $html .= '<span>'.$i.'</span>';
            } else {
                $html .= '<a href="'.$href.'">'.$i.'</a>';
            }
        }

        return $html;
    }

    /**
     * fields 모드의 단일 필드 엔트리를 HTML로 렌더링합니다.
     *
     * @param  array  $field  필드 정의 (tag, content, attrs, if, iterate 등)
     * @param  array  $data  소스 객체 데이터
     * @return string HTML 문자열
     */
    private function renderFieldEntry(array $field, array $data): string
    {
        $tag = $field['tag'] ?? 'span';

        // 조건부 렌더링 (if)
        if (isset($field['if'])) {
            $condValue = $this->resolveFieldPattern($field['if'], $data);
            if ($condValue === '' || $condValue === '0' || $condValue === 'false') {
                return '';
            }
        }

        // iterate: 배열 필드를 순회하여 아이템별 HTML 생성
        if (isset($field['iterate'])) {
            return $this->renderFieldIterate($field, $data, $tag);
        }

        // 속성 기반 렌더링 (img 등)
        if (isset($field['attrs'])) {
            return $this->renderFieldWithAttrs($field, $data, $tag);
        }

        // children: 중첩 필드 그룹
        if (isset($field['children']) && is_array($field['children'])) {
            return $this->renderFieldChildren($field, $data, $tag);
        }

        // 콘텐츠 기반 렌더링
        if (isset($field['content'])) {
            $content = $this->resolveFieldContent($field['content'], $data);
            if ($content === '') {
                return '';
            }

            $classAttr = isset($field['class']) ? ' class="'.e($field['class']).'"' : '';

            return "<{$tag}{$classAttr}>".e($content)."</{$tag}>";
        }

        return '';
    }

    /**
     * fields 모드의 iterate 엔트리를 렌더링합니다.
     *
     * 배열 필드를 순회하여 아이템별 HTML 태그를 생성합니다.
     * 예: labels 배열의 각 요소를 <span> 태그로 출력
     *
     * @param  array  $field  필드 정의
     * @param  array  $data  소스 객체 데이터
     * @param  string  $wrapperTag  래퍼 태그
     * @return string HTML 문자열
     */
    private function renderFieldIterate(array $field, array $data, string $wrapperTag): string
    {
        $iterateKey = $field['iterate'];
        $items = data_get($data, $iterateKey);
        if (! is_array($items) || empty($items)) {
            return '';
        }

        $itemTag = $field['item_tag'] ?? 'span';
        $itemContent = $field['item_content'] ?? null;

        $itemAttrs = $field['item_attrs'] ?? [];

        $innerHtml = '';
        foreach ($items as $item) {
            if ($itemContent !== null && is_array($item)) {
                $content = $this->resolveFieldPattern($itemContent, $item);
                if ($content !== '') {
                    // item_attrs: 아이템별 속성 해석 (예: href="/board/{slug}")
                    $attrStr = '';
                    foreach ($itemAttrs as $attrName => $attrPattern) {
                        $attrValue = $this->resolveFieldContent($attrPattern, $item);
                        if ($attrValue !== '') {
                            $attrStr .= " {$attrName}=\"".e($attrValue).'"';
                        }
                    }
                    $innerHtml .= "<{$itemTag}{$attrStr}>".e($content)."</{$itemTag}>";
                }
            } elseif (is_string($item)) {
                $innerHtml .= "<{$itemTag}>".e($item)."</{$itemTag}>";
            }
        }

        if ($innerHtml === '') {
            return '';
        }

        $classAttr = isset($field['class']) ? ' class="'.e($field['class']).'"' : '';

        return "<{$wrapperTag}{$classAttr}>{$innerHtml}</{$wrapperTag}>";
    }

    /**
     * fields 모드의 children 그룹 엔트리를 렌더링합니다.
     *
     * 자식 필드를 재귀적으로 renderFieldEntry()를 호출하여 렌더링하고,
     * 래퍼 태그로 감쌉니다. 모든 자식이 빈 결과이면 래퍼 태그를 출력하지 않습니다.
     *
     * @param  array  $field  필드 정의 (children 키 포함)
     * @param  array  $data  소스 객체 데이터
     * @param  string  $tag  래퍼 HTML 태그
     * @return string HTML 문자열
     */
    private function renderFieldChildren(array $field, array $data, string $tag): string
    {
        $innerHtml = '';
        foreach ($field['children'] as $child) {
            $innerHtml .= $this->renderFieldEntry($child, $data);
        }

        if ($innerHtml === '') {
            return '';
        }

        $classAttr = isset($field['class']) ? ' class="'.e($field['class']).'"' : '';

        return "<{$tag}{$classAttr}>{$innerHtml}</{$tag}>";
    }

    /**
     * fields 모드의 link 설정을 해석하여 URL을 반환합니다.
     *
     * href 패턴에서 {field} 를 데이터 값으로 치환하고,
     * base_url이 있으면 앞에 붙입니다. $global: 접두사는 globalResolver로 해석합니다.
     *
     * @param  array  $linkConfig  link 설정 (href, base_url 등)
     * @param  array  $data  소스 객체 데이터
     * @return string|null 해석된 URL (null이면 링크 미생성)
     */
    private function resolveFieldsLink(array $linkConfig, array $data): ?string
    {
        $hrefPattern = $linkConfig['href'] ?? '';
        if ($hrefPattern === '') {
            return null;
        }

        $baseUrl = '';
        if (isset($linkConfig['base_url'])) {
            $ref = $linkConfig['base_url'];
            if (str_starts_with($ref, '$var:')) {
                // SEO vars에서 해석 (meta.seo.vars로 정의된 변수)
                $varName = substr($ref, strlen('$var:'));
                $baseUrl = $this->seoVars[$varName] ?? '';
            } elseif (str_starts_with($ref, '$global:')) {
                $globalKey = substr($ref, strlen('$global:'));
                if ($this->globalResolver) {
                    $resolved = ($this->globalResolver)('_global.'.$globalKey);
                    $baseUrl = $resolved ?? '';
                }
            } else {
                $baseUrl = $ref;
            }
            // base_url에 / 접두사 보장 (route_path 등은 / 없이 저장됨)
            if ($baseUrl !== '' && ! str_starts_with($baseUrl, '/')) {
                $baseUrl = '/'.$baseUrl;
            }
        }

        // href 패턴 내 {field} 중 해석 불가한 것이 있으면 링크 미생성
        $hasUnresolved = false;
        $href = preg_replace_callback('/\{(.+?)\}/', function ($matches) use ($data, &$hasUnresolved) {
            $fields = explode('|', $matches[1]);
            foreach ($fields as $field) {
                $field = trim($field);
                $value = data_get($data, $field);
                if ($value !== null && $value !== '') {
                    return (string) $value;
                }
            }
            $hasUnresolved = true;

            return '';
        }, $hrefPattern);

        if ($hasUnresolved || $href === '') {
            return null;
        }

        // base_url이 "/" 인 경우 이중 슬래시 방지
        if ($baseUrl === '/') {
            $baseUrl = '';
        }

        return $baseUrl.$href;
    }

    /**
     * fields 모드의 속성 기반 엔트리를 렌더링합니다.
     *
     * img 등 속성 기반 태그를 생성합니다.
     *
     * @param  array  $field  필드 정의
     * @param  array  $data  소스 객체 데이터
     * @param  string  $tag  HTML 태그
     * @return string HTML 문자열
     */
    private function renderFieldWithAttrs(array $field, array $data, string $tag): string
    {
        $attrStr = '';
        foreach ($field['attrs'] as $attrName => $fieldPattern) {
            $value = $this->resolveFieldPattern($fieldPattern, $data);
            if ($value !== '') {
                // src 속성의 상대 경로를 절대 경로로 변환
                if ($attrName === 'src' && ! str_starts_with($value, 'http')) {
                    $value = url($value);
                }
                $attrStr .= " {$attrName}=\"".e($value).'"';
            }
        }

        $classAttr = isset($field['class']) ? ' class="'.e($field['class']).'"' : '';

        if (in_array($tag, $this->selfClosing)) {
            return "<{$tag}{$classAttr}{$attrStr}>";
        }

        // content가 있으면 태그 내부에 렌더링
        $innerHtml = '';
        if (isset($field['content'])) {
            $innerHtml = e($this->resolveFieldContent($field['content'], $data));
        }

        return "<{$tag}{$classAttr}{$attrStr}>{$innerHtml}</{$tag}>";
    }

    /**
     * 필드 콘텐츠를 해석합니다 ({field} 패턴 + 리터럴 텍스트 혼합 지원).
     *
     * @param  string  $contentPattern  콘텐츠 패턴 (예: "{discount_rate}%", "{name}")
     * @param  array  $data  소스 객체 데이터
     * @return string 해석된 콘텐츠
     */
    private function resolveFieldContent(string $contentPattern, array $data): string
    {
        // $t: 번역 키 해석 (evaluator가 있는 경우)
        if (str_contains($contentPattern, '$t:') && $this->fieldsEvaluator) {
            $contentPattern = preg_replace_callback('/\$t:([\w.\-]+(?:\|[\w.\-]+=[\w.\-{}]+)*)/', function ($matches) {
                return $this->fieldsEvaluator->evaluate('$t:'.$matches[1], $this->fieldsContext);
            }, $contentPattern);
        }

        // {field|alt_field} 단독 패턴 → resolveFieldPattern 사용
        if (preg_match('/^\{(.+?)\}$/', $contentPattern)) {
            return $this->resolveFieldPattern($contentPattern, $data);
        }

        // {field} + 리터럴 혼합 패턴 → 각 {field}를 치환
        $result = preg_replace_callback('/\{(.+?)\}/', function ($matches) use ($data) {
            $fields = explode('|', $matches[1]);
            foreach ($fields as $field) {
                $field = trim($field);
                $value = data_get($data, $field);
                if ($value !== null && $value !== '') {
                    return (string) $value;
                }
            }

            return '';
        }, $contentPattern);

        return $result ?? '';
    }

    /**
     * 표현식에서 객체/배열 데이터를 해석합니다.
     *
     * {{variable}} 형태의 표현식에서 경로를 추출하고
     * context에서 해당 데이터를 찾아 반환합니다.
     *
     * @param  mixed  $expr  표현식 (문자열 또는 배열)
     * @return mixed 해석된 데이터 (배열/객체 또는 null)
     */
    private function resolveObjectFromExpression(mixed $expr, array $context): mixed
    {
        // 이미 배열인 경우 바로 반환
        if (is_array($expr)) {
            return $expr;
        }

        if (! is_string($expr) || $expr === '') {
            return null;
        }

        // {{path}} 형태에서 경로 추출
        $normalizedPath = str_replace(['?.', '{{', '}}'], ['.', '', ''], $expr);
        $normalizedPath = trim($normalizedPath);

        // ?? 이후 제거
        if (str_contains($normalizedPath, '??')) {
            $normalizedPath = trim(explode('??', $normalizedPath, 2)[0]);
        }

        return data_get($context, $normalizedPath);
    }

    /**
     * $props_source 참조를 실제 키로 해석합니다.
     *
     * @param  string  $sourceRef  source 참조 ($props_source 또는 직접 키)
     * @param  array  $configEntry  component_map 엔트리
     * @return string 해석된 소스 키
     */
    private function resolveSourceKey(string $sourceRef, array $configEntry): string
    {
        if ($sourceRef === '$props_source') {
            return $configEntry['props_source'] ?? 'content';
        }

        return $sourceRef !== '' ? $sourceRef : 'content';
    }

    /**
     * 컴포넌트의 모든 props를 표현식 해석하여 데이터 객체로 반환합니다.
     *
     * source: "$all_props" 모드에서 사용되며, 각 prop 값의 {{expression}}을
     * context에서 해석하여 flat 데이터 객체를 구성합니다.
     *
     * @param  array  $props  컴포넌트 props (표현식 포함)
     * @param  array  $context  데이터 컨텍스트
     * @param  ExpressionEvaluator  $evaluator  표현식 평가기
     * @return array 해석된 데이터 객체
     */
    private function resolveAllProps(array $props, array $context, ExpressionEvaluator $evaluator): array
    {
        $data = [];

        foreach ($props as $key => $value) {
            if (is_string($value) && str_contains($value, '{{')) {
                // 표현식 해석: 문자열/숫자는 evaluate, 배열/객체는 evaluateRaw
                $resolved = $evaluator->evaluateRaw($value, $context);
                $data[$key] = $resolved;
            } elseif (is_array($value)) {
                // 중첩 객체 (예: socialLinks): 재귀적으로 해석
                $data[$key] = $this->resolveAllPropsRecursive($value, $context, $evaluator);
            } else {
                $data[$key] = $value;
            }
        }

        return $data;
    }

    /**
     * 중첩 props 객체를 재귀적으로 해석합니다.
     *
     * @param  array  $values  중첩 객체/배열
     * @param  array  $context  데이터 컨텍스트
     * @param  ExpressionEvaluator  $evaluator  표현식 평가기
     * @return array 해석된 데이터
     */
    private function resolveAllPropsRecursive(array $values, array $context, ExpressionEvaluator $evaluator): array
    {
        $result = [];
        foreach ($values as $k => $v) {
            if (is_string($v) && str_contains($v, '{{')) {
                $result[$k] = $evaluator->evaluateRaw($v, $context);
            } elseif (is_array($v)) {
                $result[$k] = $this->resolveAllPropsRecursive($v, $context, $evaluator);
            } else {
                $result[$k] = $v;
            }
        }

        return $result;
    }

    /**
     * {field|alt_field} 패턴에서 아이템 값을 해석합니다.
     *
     * @param  string  $pattern  필드 패턴 (예: "{url|src}", "{alt}")
     * @param  mixed  $item  데이터 아이템
     * @return string 해석된 값
     */
    private function resolveFieldPattern(string $pattern, mixed $item): string
    {
        // {field|alt_field} 패턴 추출
        if (preg_match('/^\{(.+?)\}$/', $pattern, $matches)) {
            $fields = explode('|', $matches[1]);

            if (is_string($item)) {
                return $item;
            }

            if (is_array($item)) {
                foreach ($fields as $field) {
                    $field = trim($field);
                    $value = data_get($item, $field);
                    if ($value !== null && $value !== '') {
                        return (string) $value;
                    }
                }
            }

            return '';
        }

        // 패턴이 아닌 경우 그대로 반환
        return $pattern;
    }

    /**
     * children을 렌더링합니다.
     *
     * @param  array  $component  컴포넌트 정의
     * @param  array  $context  데이터 컨텍스트
     * @param  ExpressionEvaluator  $evaluator  표현식 평가기
     * @return string HTML 문자열
     */
    private function renderChildren(array $component, array $context, ExpressionEvaluator $evaluator): string
    {
        if (isset($component['children']) && is_array($component['children'])) {
            return $this->render($component['children'], $context, $evaluator);
        }

        return '';
    }

    /**
     * 텍스트 콘텐츠를 해석합니다 (컴포넌트 레벨 + props 레벨).
     *
     * @param  array  $component  컴포넌트 정의
     * @param  array  $props  컴포넌트 props
     * @param  array  $context  데이터 컨텍스트
     * @param  ExpressionEvaluator  $evaluator  표현식 평가기
     * @return string 텍스트 콘텐츠
     */
    private function resolveTextContent(array $component, array $props, array $context, ExpressionEvaluator $evaluator, string $tag = ''): string
    {
        // 1. 컴포넌트 레벨 text 속성 (최우선) — 키가 있으면 빈 값이어도 그것이 최종 값
        $nodeText = $this->resolveNodeText($component, $context, $evaluator);
        if ($nodeText !== null) {
            return $nodeText;
        }

        $skipValueProp = in_array(strtolower($tag), self::VALUE_NOT_TEXT_TAGS, true);

        // 2. props 레벨 텍스트 추출 (seo-config.json의 text_props)
        foreach ($this->textProps as $textProp) {
            if ($textProp === 'value' && $skipValueProp) {
                continue;
            }

            if (isset($props[$textProp])) {
                $text = $evaluator->evaluate((string) $props[$textProp], $context);
                if ($text !== '') {
                    return e($text);
                }
            }
        }

        return '';
    }

    /**
     * 노드 레벨 text 속성을 해석합니다.
     *
     * @param  array  $component  컴포넌트 정의
     * @param  array  $context  데이터 컨텍스트
     * @param  ExpressionEvaluator  $evaluator  표현식 평가기
     * @return string 이스케이프된 텍스트 (없으면 빈 문자열)
     */
    private function resolveNodeText(array $component, array $context, ExpressionEvaluator $evaluator): ?string
    {
        if (! array_key_exists('text', $component)) {
            return null;
        }

        $expression = (string) $component['text'];

        // JSX 시맨틱: bool / null 은 렌더되지 않는다.
        // React 는 단일 바인딩 text 를 raw 값으로 받아 그대로 자식에 넘기므로
        // {false} / {null} 이 화면에 나오지 않는다 (DynamicRenderer.tsx 의 renderChildren).
        // 문자열화하면 'false' 라는 글자가 봇 화면에만 노출된다.
        //
        // 판정에만 evaluateRaw 를 쓰고 출력은 evaluate 로 만든다 — evaluate 가 파이프·인라인
        // `$t:` 토큰 후처리를 담당하므로, 이를 건너뛰면 `?? '$t:key'` 같은 폴백 문자열의
        // 번역 토큰이 봇 화면에 그대로 남는다.
        $resolved = $evaluator->evaluateRaw($expression, $context);
        if (is_bool($resolved) || $resolved === null) {
            return '';
        }

        $text = $evaluator->evaluate($expression, $context);

        return $text === '' ? '' : e($text);
    }

    /**
     * 반복 렌더링을 처리합니다.
     *
     * @param  array  $component  컴포넌트 정의
     * @param  array  $context  데이터 컨텍스트
     * @param  ExpressionEvaluator  $evaluator  표현식 평가기
     * @return string HTML 문자열
     */
    private function renderIteration(array $component, array $context, ExpressionEvaluator $evaluator): string
    {
        $iteration = $component['iteration'];
        $dataPath = $iteration['source'] ?? $iteration['data'] ?? '';
        $itemVar = $iteration['item_var'] ?? 'item';
        $indexVar = $iteration['index_var'] ?? 'index';

        // 데이터 경로 평가
        $dataStr = $evaluator->evaluate($dataPath, $context);
        $data = is_string($dataStr) ? json_decode($dataStr, true) : null;

        // 직접 배열 경로 해석도 시도
        if ($data === null) {
            $normalizedPath = str_replace(['?.', '{{', '}}'], ['.', '', ''], $dataPath);
            $normalizedPath = trim($normalizedPath);
            $data = data_get($context, $normalizedPath);
        }

        if (! is_array($data)) {
            return '';
        }

        // iteration 속성 제거한 컴포넌트 복사
        $templateComponent = $component;
        unset($templateComponent['iteration']);

        // responsive 오버라이드가 항목 렌더링 시 iteration을 다시 주입해
        // 무한 재귀가 되지 않도록 함께 제거한다.
        if (isset($templateComponent['responsive']) && is_array($templateComponent['responsive'])) {
            foreach (array_keys($templateComponent['responsive']) as $breakpoint) {
                unset($templateComponent['responsive'][$breakpoint]['iteration']);
            }
        }

        $html = '';
        foreach (array_values($data) as $index => $item) {
            $iterContext = array_merge($context, [
                $itemVar => $item,
                $indexVar => $index,
                // `{item_var}_index` 자동 변수 — React 반복 렌더 경로와 동일하게 제공한다.
                $itemVar.'_index' => $index,
            ]);
            $html .= $this->renderComponent($templateComponent, $iterContext, $evaluator);
        }

        return $html;
    }

    /**
     * props를 HTML 속성 문자열로 변환합니다.
     *
     * @param  array  $props  컴포넌트 props
     * @param  array  $context  데이터 컨텍스트
     * @param  ExpressionEvaluator  $evaluator  표현식 평가기
     * @return string HTML 속성 문자열
     */
    private function buildAttributes(array $props, array $context, ExpressionEvaluator $evaluator): string
    {
        $attrs = '';

        foreach ($props as $key => $value) {
            $htmlAttr = $this->attrMap[$key] ?? $key;

            if (! in_array($htmlAttr, $this->allowedAttrs)) {
                continue;
            }

            if (is_string($value)) {
                $evaluated = $evaluator->evaluate($value, $context);
                if ($evaluated !== '') {
                    $attrs .= " {$htmlAttr}=\"".e($evaluated).'"';
                }
            } elseif (is_bool($value) && $value) {
                $attrs .= " {$htmlAttr}";
            }
        }

        return $attrs;
    }

    /**
     * classMap 속성을 해석하여 className에 병합합니다.
     *
     * 프론트엔드 엔진의 classMap 기능과 동일:
     * - base: 항상 적용되는 기본 클래스
     * - variants: key 값에 따라 선택되는 클래스 매핑
     * - key: 동적으로 평가되는 표현식
     * - default: 일치하는 variant가 없을 때 적용할 클래스
     *
     * 결과는 기존 className과 공백으로 결합됩니다.
     *
     * @param  array  $component  컴포넌트 정의 (classMap 포함)
     * @param  array  $context  데이터 컨텍스트
     * @param  ExpressionEvaluator  $evaluator  표현식 평가기
     * @return array classMap이 className으로 해석된 컴포넌트
     */
    private function resolveClassMap(array $component, array $context, ExpressionEvaluator $evaluator): array
    {
        $classMap = $component['classMap'];
        if (! is_array($classMap)) {
            return $component;
        }

        $base = $classMap['base'] ?? '';
        $variants = $classMap['variants'] ?? [];
        $keyExpr = $classMap['key'] ?? '';
        $default = $classMap['default'] ?? '';

        // key 표현식 평가
        $keyValue = '';
        if ($keyExpr !== '') {
            $keyValue = $evaluator->evaluate($keyExpr, $context);
        }

        // variants에서 매칭되는 클래스 찾기
        $variantClass = $variants[$keyValue] ?? $default;

        // base + variant 클래스 결합
        $classMapResult = trim($base.' '.$variantClass);

        // 기존 className과 병합
        $existingClass = $component['props']['className'] ?? '';
        if ($existingClass !== '') {
            // 기존 className도 표현식일 수 있으므로 평가는 buildAttributes에서 수행
            // 여기서는 정적 부분만 병합
            if (str_contains($existingClass, '{{')) {
                $existingClass = $evaluator->evaluate($existingClass, $context);
            }
            $component['props']['className'] = trim($existingClass.' '.$classMapResult);
        } else {
            $component['props']['className'] = $classMapResult;
        }

        // classMap은 처리 완료이므로 제거 (buildAttributes에서 무시하도록)
        unset($component['classMap']);

        return $component;
    }

    /**
     * Icon 컴포넌트의 name prop을 Font Awesome class로 변환합니다.
     *
     * seo-config.json의 name_to_class 템플릿 (예: "fas fa-{name}")에 따라
     * name prop을 CSS class로 변환하고, 기존 className과 병합합니다.
     *
     * @param  array  $configEntry  component_map 엔트리
     * @param  array  $component  컴포넌트 정의
     * @param  array  $context  데이터 컨텍스트
     * @param  ExpressionEvaluator  $evaluator  표현식 평가기
     * @return array 변환된 컴포넌트
     */
    private function transformIconProps(array $configEntry, array $component, array $context, ExpressionEvaluator $evaluator): array
    {
        $props = $component['props'] ?? [];
        $nameValue = $props['name'] ?? '';

        if ($nameValue === '') {
            return $component;
        }

        // name 표현식 평가 ({{icon_name}} 등)
        $resolvedName = $evaluator->evaluate((string) $nameValue, $context);
        if ($resolvedName === '') {
            return $component;
        }

        // fa- 접두사 제거 (중복 방지)
        $resolvedName = preg_replace('/^fa-/', '', $resolvedName);

        // name_to_class 템플릿으로 CSS class 생성
        $template = $configEntry['name_to_class'];
        $iconClass = str_replace('{name}', $resolvedName, $template);

        // 기존 className과 병합
        $existingClass = $props['className'] ?? '';
        if ($existingClass !== '') {
            $resolvedExisting = $evaluator->evaluate((string) $existingClass, $context);
            if ($resolvedExisting !== '') {
                $iconClass .= ' '.$resolvedExisting;
            }
        }

        // className 설정, name prop 제거 (HTML name 속성으로 출력 방지)
        $component['props']['className'] = $iconClass;
        unset($component['props']['name']);

        // aria-label 자동 생성 (접근성)
        if (! isset($props['aria-label'])) {
            $component['props']['aria-label'] = str_replace('-', ' ', $resolvedName);
        }

        // role="img" 추가 (접근성)
        if (! isset($props['role'])) {
            $component['props']['role'] = 'img';
        }

        return $component;
    }

    /**
     * 컴포넌트의 actions에서 링크 변환 대상 액션을 추출합니다.
     *
     * click 이벤트의 navigate/openWindow 핸들러만 추출하며,
     * sequence 내부에 중첩된 navigate/openWindow도 탐색합니다.
     * replace: true인 navigate는 제외합니다 (필터/페이지네이션).
     *
     * @param  array  $component  컴포넌트 정의
     * @return array|null ['handler' => string, 'params' => array] 또는 null
     */
    private function extractLinkAction(array $component): ?array
    {
        $actions = $component['actions'] ?? [];
        if (empty($actions) || ! is_array($actions)) {
            return null;
        }

        foreach ($actions as $action) {
            if (! is_array($action)) {
                continue;
            }

            // click 이벤트만 처리 (type 미지정 = click, event 미지정 = click)
            $eventType = $action['type'] ?? $action['event'] ?? 'click';
            if ($eventType !== 'click') {
                continue;
            }

            $handler = $action['handler'] ?? '';
            $params = $action['params'] ?? [];

            // 직접 navigate/openWindow
            if ($handler === 'navigate' || $handler === 'openWindow') {
                // replace: true인 navigate는 제외
                if ($handler === 'navigate' && ! empty($params['replace'])) {
                    continue;
                }

                return ['handler' => $handler, 'params' => $params];
            }

            // sequence 내부 탐색
            if ($handler === 'sequence') {
                $seqActions = $action['actions'] ?? $params['actions'] ?? [];
                foreach ($seqActions as $seqAction) {
                    if (! is_array($seqAction)) {
                        continue;
                    }
                    $seqHandler = $seqAction['handler'] ?? '';
                    $seqParams = $seqAction['params'] ?? [];

                    if ($seqHandler === 'navigate' || $seqHandler === 'openWindow') {
                        if ($seqHandler === 'navigate' && ! empty($seqParams['replace'])) {
                            continue;
                        }

                        return ['handler' => $seqHandler, 'params' => $seqParams];
                    }
                }
            }
        }

        return null;
    }

    /**
     * navigate/openWindow params의 path를 해석하여 href URL을 생성합니다.
     *
     * _global 참조는 globalResolver 콜백으로 선치환하고,
     * 나머지 표현식은 ExpressionEvaluator로 해석합니다.
     * 미해석 {{}} 토큰이 남아있으면 null을 반환합니다 (graceful skip).
     *
     * @param  array  $params  navigate/openWindow params
     * @param  array  $context  데이터 컨텍스트
     * @param  ExpressionEvaluator  $evaluator  표현식 평가기
     * @return string|null 해석된 URL 또는 null (해석 불가 시)
     */
    private function resolveNavigateHref(array $params, array $context, ExpressionEvaluator $evaluator): ?string
    {
        $pathExpr = $params['path'] ?? '';
        if ($pathExpr === '') {
            return null;
        }

        $pathExpr = (string) $pathExpr;

        // _global 참조를 globalResolver로 선치환
        if (str_contains($pathExpr, '_global')) {
            if (! $this->globalResolver) {
                // globalResolver 미설정 → _global 해석 불가 → skip
                return null;
            }

            $resolveFailed = false;
            $pathExpr = (string) preg_replace_callback(
                '/\{\{([^}]*_global\.[^}]*)\}\}/',
                function ($matches) use (&$resolveFailed) {
                    $resolved = ($this->globalResolver)($matches[1]);
                    if ($resolved === null) {
                        $resolveFailed = true;
                    }

                    return $resolved !== null ? $resolved : $matches[0];
                },
                $pathExpr
            );

            if ($resolveFailed) {
                return null;
            }
        }

        // ExpressionEvaluator로 나머지 표현식 해석
        $href = $evaluator->evaluate($pathExpr, $context);

        // 미해석 {{}} 토큰이 남아있으면 skip
        if (str_contains($href, '{{') || str_contains($href, '}}')) {
            return null;
        }

        // 빈 문자열이면 skip
        if ($href === '') {
            return null;
        }

        // query params 빌드
        $query = $params['query'] ?? [];
        if (! empty($query) && is_array($query)) {
            $resolvedQuery = [];
            foreach ($query as $key => $value) {
                $resolvedValue = $evaluator->evaluate((string) $value, $context);
                // 미해석 표현식 skip
                if (str_contains($resolvedValue, '{{') || str_contains($resolvedValue, '}}')) {
                    return null;
                }
                if ($resolvedValue !== '') {
                    $resolvedQuery[$key] = $resolvedValue;
                }
            }
            if (! empty($resolvedQuery)) {
                $href .= '?'.http_build_query($resolvedQuery);
            }
        }

        return $href;
    }

    /**
     * 렌더링된 HTML에 navigate 링크를 적용합니다.
     *
     * 태그 유형에 따라 변환/래핑/주입 전략을 선택합니다:
     * - button → <a>로 변환 (class 보존)
     * - a + href 없음 → href 주입
     * - a + href 있음 → 스킵
     * - div/section 등 → <a>로 래핑
     * - self-closing → <a>로 래핑
     * - Fragment (빈 태그) → 스킵
     *
     * @param  string  $html  렌더링된 HTML
     * @param  string  $tag  HTML 태그명
     * @param  string  $href  링크 URL
     * @param  string  $handler  핸들러명 (navigate|openWindow)
     * @param  array  $component  컴포넌트 정의
     * @return string 링크가 적용된 HTML
     */
    private function applyNavigateLink(string $html, string $tag, string $href, string $handler, array $component): string
    {
        if ($html === '' || $tag === '') {
            return $html;
        }

        $targetAttr = $handler === 'openWindow' ? ' target="_blank"' : '';
        $escapedHref = e($href);

        // a 태그: href 주입 또는 스킵
        if ($tag === 'a') {
            // 이미 href가 있으면 스킵 (명시적 href 우선)
            if (preg_match('/\bhref\s*=/', $html)) {
                return $html;
            }

            // href 주입: <a → <a href="..."
            return preg_replace(
                '/^<a(\s|>)/',
                '<a href="'.$escapedHref.'"'.$targetAttr.'$1',
                $html,
                1
            );
        }

        // button → <a>로 변환 (class 보존, HTML 유효성)
        if ($tag === 'button') {
            $html = preg_replace('/^<button(\s?)/', '<a href="'.$escapedHref.'"'.$targetAttr.'$1', $html, 1);
            $html = preg_replace('/<\/button>$/', '</a>', $html, 1);

            // type 속성 제거 (<a>에는 type="button" 불필요)
            $html = preg_replace('/\s*type="[^"]*"/', '', $html, 1);

            return $html;
        }

        // self-closing 태그 → <a>로 래핑
        if (in_array($tag, $this->selfClosing)) {
            return '<a href="'.$escapedHref.'"'.$targetAttr.'>'.$html.'</a>';
        }

        // div, section, article 등 블록 요소 → <a>로 래핑
        return '<a href="'.$escapedHref.'"'.$targetAttr.'>'.$html.'</a>';
    }
}
