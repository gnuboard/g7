<?php

namespace App\Seo;

class ComponentHtmlMapper
{
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
        // SEO는 데스크톱 뷰로 렌더링 (검색 봇 = 데스크톱)
        // responsive.desktop 오버라이드를 기본 속성에 병합
        if (isset($component['responsive']['desktop'])) {
            $desktop = $component['responsive']['desktop'];
            if (isset($desktop['props'])) {
                $component['props'] = array_merge($component['props'] ?? [], $desktop['props']);
            }
            if (isset($desktop['if'])) {
                $component['if'] = $desktop['if'];
            }
            if (isset($desktop['text'])) {
                $component['text'] = $desktop['text'];
            }
        }

        // 조건부 렌더링 (if)
        if (isset($component['if'])) {
            $condition = $evaluator->evaluate($component['if'], $context);
            if ($condition === '' || $condition === 'false' || $condition === '0') {
                return '';
            }
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
     * 선언적 렌더 모드로 컴포넌트를 HTML로 변환합니다.
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
            $innerHtml = $this->resolveTextContent($component, $props, $context, $evaluator);
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

        $innerHtml = $this->renderChildren($component, $context, $evaluator);

        if ($innerHtml === '') {
            $innerHtml = $this->resolveTextContent($component, $props, $context, $evaluator);
        }

        // dangerouslySetInnerHTML 처리
        if ($innerHtml === '' && isset($props['dangerouslySetInnerHTML'])) {
            $rawHtml = $evaluator->evaluate((string) $props['dangerouslySetInnerHTML'], $context);
            if ($rawHtml !== '') {
                $innerHtml = $rawHtml;
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
            if ($itemAttrs) {
                // 속성 기반 렌더링 (이미지 갤러리 등)
                $attrStr = '';
                foreach ($itemAttrs as $attrName => $fieldPattern) {
                    $value = $this->resolveFieldPattern($fieldPattern, $item);
                    if ($value !== '') {
                        // src 속성의 상대 경로를 절대 경로로 변환
                        if ($attrName === 'src' && ! str_starts_with($value, 'http')) {
                            $value = url($value);
                        }
                        $attrStr .= " {$attrName}=\"".e($value).'"';
                    }
                }

                if (in_array($itemTag, $this->selfClosing)) {
                    $html .= "<{$itemTag}{$attrStr}>";
                } else {
                    $html .= "<{$itemTag}{$attrStr}></{$itemTag}>";
                }
            } elseif ($itemContent !== null) {
                // 콘텐츠 기반 렌더링 (탭 리스트 등)
                $content = $this->resolveFieldPattern($itemContent, $item);
                if ($content !== '') {
                    $evaluatedContent = e($evaluator->evaluate((string) $content, $context));
                    $badge = '';
                    if ($badgeField && isset($item[$badgeField])) {
                        $evaluatedBadge = $evaluator->evaluate((string) $item[$badgeField], $context);
                        if ($evaluatedBadge !== '' && $evaluatedBadge !== '0') {
                            $badge = ' <span>('.e($evaluatedBadge).')</span>';
                        }
                    }
                    $html .= "<{$itemTag}>".$evaluatedContent.$badge."</{$itemTag}>";
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
     * raw 타입: 원본 HTML/텍스트를 그대로 출력
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

        return $evaluator->evaluate((string) $contentExpr, $context);
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
    private function resolveTextContent(array $component, array $props, array $context, ExpressionEvaluator $evaluator): string
    {
        // 1. 컴포넌트 레벨 text 속성 (최우선)
        if (isset($component['text'])) {
            $text = $evaluator->evaluate((string) $component['text'], $context);
            if ($text !== '') {
                return e($text);
            }
        }

        // 2. props 레벨 텍스트 추출 (seo-config.json의 text_props)
        foreach ($this->textProps as $textProp) {
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

        $html = '';
        foreach (array_values($data) as $index => $item) {
            $iterContext = array_merge($context, [
                $itemVar => $item,
                $indexVar => $index,
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
