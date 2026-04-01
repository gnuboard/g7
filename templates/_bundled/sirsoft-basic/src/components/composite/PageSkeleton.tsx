import React from 'react';

/**
 * 레이아웃 컴포넌트 정의 (엔진에서 전달하는 트리 구조)
 */
interface LayoutComponent {
    name?: string;
    type?: string;
    className?: string;
    props?: Record<string, any>;
    children?: LayoutComponent[];
    iteration?: {
        source?: string;
        item_var?: string;
        index_var?: string;
    };
    if?: string;
    responsive?: {
        desktop?: { props?: Record<string, any> };
        tablet?: { props?: Record<string, any> };
    };
}

/**
 * 스켈레톤 컴포넌트 Props (엔진에서 전달)
 */
export interface PageSkeletonProps {
    /** 레이아웃 JSON의 components 배열 (전체 컴포넌트 트리) */
    components: LayoutComponent[];
    /** 스켈레톤 옵션 */
    options: {
        animation: 'pulse' | 'wave' | 'none';
        iteration_count: number;
    };
}

/** Tailwind 반응형 breakpoint (px) */
const TAILWIND_BREAKPOINTS: Record<string, number> = {
    sm: 640, md: 768, lg: 1024, xl: 1280, '2xl': 1536,
};

/** CSS display 관련 클래스 (Tailwind) */
const DISPLAY_CLASSES = new Set([
    'hidden', 'block', 'inline-block', 'inline', 'flex', 'inline-flex',
    'grid', 'inline-grid', 'table', 'table-row', 'table-cell',
    'contents', 'list-item', 'flow-root',
]);

/** 레이아웃 컨테이너 컴포넌트 이름 목록 (자식 순회) */
const LAYOUT_CONTAINERS = new Set([
    'Div', 'Flex', 'Grid', 'Section', 'Article', 'Nav', 'Aside',
    'Main', 'Header', 'Footer', 'Fragment', 'Form', 'Container',
]);

/** 텍스트 컴포넌트 → 스켈레톤 바로 치환 */
const TEXT_COMPONENTS = new Set([
    'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'P', 'Span', 'Label', 'A',
    'Strong', 'Em', 'Small', 'Badge',
]);

/** 인풋 컴포넌트 → 스켈레톤 사각형으로 치환 */
const INPUT_COMPONENTS = new Set([
    'Input', 'Textarea', 'Select', 'Checkbox', 'Radio', 'Toggle',
    'DatePicker', 'TagInput', 'SearchBar',
]);

/** 미디어 컴포넌트 → 큰 사각형 */
const MEDIA_COMPONENTS = new Set([
    'Img', 'Image', 'Avatar', 'AvatarUploader', 'ImageGallery',
    'ProductImageViewer', 'FileUploader',
]);

/** 복합 컴포넌트 → 특화된 스켈레톤 */
const COMPOSITE_SKELETONS: Record<string, (animClass: string, iterCount: number) => React.ReactElement> = {
    DataGrid: (animClass, iterCount) => (
        <div className="w-full border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
            <div className={`h-10 bg-gray-100 dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 ${animClass}`}>
                <div className="flex items-center h-full px-4 gap-8">
                    <div className="h-3 w-16 bg-gray-300 dark:bg-gray-600 rounded" />
                    <div className="h-3 w-24 bg-gray-300 dark:bg-gray-600 rounded" />
                    <div className="h-3 w-20 bg-gray-300 dark:bg-gray-600 rounded" />
                    <div className="h-3 w-16 bg-gray-300 dark:bg-gray-600 rounded" />
                </div>
            </div>
            {Array.from({ length: iterCount }, (_, i) => (
                <div key={i} className={`h-12 border-b border-gray-100 dark:border-gray-800 ${animClass}`} style={{ animationDelay: `${i * 75}ms` }}>
                    <div className="flex items-center h-full px-4 gap-8">
                        <div className="h-3 w-12 bg-gray-200 dark:bg-gray-700 rounded" />
                        <div className="h-3 flex-1 bg-gray-200 dark:bg-gray-700 rounded" />
                        <div className="h-3 w-16 bg-gray-100 dark:bg-gray-800 rounded" />
                        <div className="h-3 w-14 bg-gray-100 dark:bg-gray-800 rounded" />
                    </div>
                </div>
            ))}
        </div>
    ),
    Pagination: (animClass) => (
        <div className="flex justify-center gap-1.5 py-3">
            {Array.from({ length: 5 }, (_, i) => (
                <div key={i} className={`w-8 h-8 bg-gray-200 dark:bg-gray-700 rounded ${animClass}`} />
            ))}
        </div>
    ),
    Tabs: (animClass) => (
        <div>
            <div className="flex gap-4 border-b border-gray-200 dark:border-gray-700 pb-2 mb-3">
                {Array.from({ length: 3 }, (_, i) => (
                    <div key={i} className={`h-4 w-20 bg-gray-200 dark:bg-gray-700 rounded ${animClass}`} />
                ))}
            </div>
            <div className={`h-32 bg-gray-100 dark:bg-gray-800 rounded ${animClass}`} />
        </div>
    ),
    TabNavigation: (animClass) => (
        <div className="flex gap-4 border-b border-gray-200 dark:border-gray-700 pb-2">
            {Array.from({ length: 3 }, (_, i) => (
                <div key={i} className={`h-4 w-20 bg-gray-200 dark:bg-gray-700 rounded ${animClass}`} />
            ))}
        </div>
    ),
    ProductCard: (animClass) => (
        <div className="rounded-lg overflow-hidden">
            <div className={`h-48 w-full bg-gray-200 dark:bg-gray-700 ${animClass}`} />
            <div className="p-3 space-y-2">
                <div className={`h-3.5 w-3/4 bg-gray-200 dark:bg-gray-700 rounded ${animClass}`} />
                <div className={`h-4 w-1/3 bg-gray-300 dark:bg-gray-600 rounded ${animClass}`} />
            </div>
        </div>
    ),
    UserInfo: (animClass) => (
        <div className={`h-3 w-14 bg-gray-200 dark:bg-gray-700 rounded ${animClass}`} />
    ),
    QuantitySelector: (animClass) => (
        <div className={`h-9 w-28 bg-gray-100 dark:bg-gray-800 rounded border border-gray-200 dark:border-gray-700 ${animClass}`} />
    ),
    Header: (animClass) => (
        <header className="bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700">
            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div className="flex items-center justify-between h-16">
                    <div className="flex items-center gap-8">
                        <div className={`h-8 w-24 bg-gray-200 dark:bg-gray-700 rounded ${animClass}`} />
                        <div className="hidden lg:flex items-center gap-6">
                            {Array.from({ length: 4 }, (_, i) => (
                                <div key={i} className={`h-3.5 w-16 bg-gray-200 dark:bg-gray-700 rounded ${animClass}`} />
                            ))}
                        </div>
                    </div>
                    <div className="flex items-center gap-4">
                        {Array.from({ length: 3 }, (_, i) => (
                            <div key={i} className={`h-8 w-8 bg-gray-200 dark:bg-gray-700 rounded-full ${animClass}`} />
                        ))}
                    </div>
                </div>
            </div>
        </header>
    ),
    Footer: (animClass) => (
        <footer className="bg-white dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700 mt-auto">
            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                <div className="flex flex-col items-center gap-4">
                    <div className={`h-4 w-32 bg-gray-200 dark:bg-gray-700 rounded ${animClass}`} />
                    <div className={`h-3 w-48 bg-gray-100 dark:bg-gray-800 rounded ${animClass}`} />
                    <div className="flex gap-3">
                        {Array.from({ length: 3 }, (_, i) => (
                            <div key={i} className={`h-6 w-6 bg-gray-200 dark:bg-gray-700 rounded-full ${animClass}`} />
                        ))}
                    </div>
                    <div className={`h-3 w-40 bg-gray-100 dark:bg-gray-800 rounded ${animClass}`} />
                </div>
            </div>
        </footer>
    ),
    Modal: () => <></>,
    Toast: () => <></>,
    ConfirmDialog: () => <></>,
};

/**
 * 조건부 분기 필터링 — 로딩/에러 상태 스킵, 콘텐츠 브랜치 선택
 *
 * 레이아웃 JSON에서 로딩/에러/콘텐츠 상태가 sibling으로 if 조건으로 분기되는 패턴이 일반적.
 * 스켈레톤은 "데이터 로드 완료 후 실제 UI" 형태를 보여줘야 하므로,
 * 로딩/에러 분기는 스킵하고 콘텐츠 분기(가장 풍부한 트리)만 렌더링.
 */
function filterChildren(children: LayoutComponent[]): LayoutComponent[] {
    if (!children || children.length === 0) return [];

    const withIf = children.filter(c => c.if);
    const withoutIf = children.filter(c => !c.if);

    // if 조건 없는 컴포넌트가 있고, if 있는 것도 있는 경우
    // → if 없는 것은 항상 렌더, if 있는 것 중 부정 조건 스킵
    if (withoutIf.length > 0 && withIf.length > 0) {
        const positiveIf = withIf.filter(c => !isNegativeCondition(c.if!));
        // 긍정 조건 여러 개가 상호 배타적 값 분기(=== 'value')이면 richest만 선택
        // 예: type === 'basic', type === 'gallery', type === 'card'
        if (positiveIf.length > 1 && areMutuallyExclusiveBranches(positiveIf)) {
            return [...withoutIf, pickRichestBranch(positiveIf)];
        }
        return [...withoutIf, ...positiveIf];
    }

    // 모든 컴포넌트가 if 조건을 가진 경우 → 상호 배타적 분기
    // → 부정 조건 스킵 후, 나머지 중 가장 풍부한 브랜치 선택
    if (withIf.length > 1 && withoutIf.length === 0) {
        const positive = withIf.filter(c => !isNegativeCondition(c.if!));
        if (positive.length === 0) {
            // 모두 부정 조건 (로딩/에러 상태만) → 전체 스킵
            // 스켈레톤은 "데이터 로드 완료 후 UI"를 보여주므로 로딩/에러 분기 불필요
            return [];
        }
        if (positive.length === 1) return positive;
        // 긍정 조건 여러 개 → 가장 풍부한 것 선택
        return [pickRichestBranch(positive)];
    }

    return children;
}

/**
 * 부정 조건 판별 — 로딩/에러 상태를 나타내는 if 조건
 */
function isNegativeCondition(ifExpr: string): boolean {
    const trimmed = ifExpr.replace(/^\{\{|\}\}$/g, '').trim();
    return trimmed.startsWith('!') ||
        /\b(hasError|isError|isLoading|error|Error)\b/.test(trimmed) ||
        // empty state 패턴: length === 0, total === 0, count === 0 등
        /===\s*0\s*(\)|\}|$)/.test(trimmed);
}

/**
 * 상호 배타적 값 분기 판별
 *
 * 여러 if 조건이 같은 변수에 대한 `=== 'value'` 패턴이면 상호 배타적.
 * 예: type === 'basic', type === 'gallery', type === 'card'
 * → 스켈레톤에서 하나만 선택해야 중복 렌더링 방지.
 */
function areMutuallyExclusiveBranches(branches: LayoutComponent[]): boolean {
    if (branches.length < 2) return false;

    // 각 조건에서 "expr === 'value'" 패턴의 기본 표현식(expr) 추출
    const baseExpressions: string[] = [];
    for (const branch of branches) {
        const trimmed = (branch.if || '').replace(/^\{\{|\}\}$/g, '').trim();
        // === 'value' 또는 === "value" 패턴 찾기
        const match = trimmed.match(/^(.+?)\s*===\s*['"]/);
        if (!match) {
            // || 로 연결된 복합 조건에서도 첫 번째 === 패턴 추출 (fallback 분기)
            const orMatch = trimmed.match(/(.+?)\s*===\s*['"]/);
            if (!orMatch) return false;
            baseExpressions.push(orMatch[1].trim());
        } else {
            baseExpressions.push(match[1].trim());
        }
    }

    // 모든 분기가 같은 기본 표현식을 참조하면 상호 배타적
    const first = baseExpressions[0];
    return baseExpressions.every(expr => expr === first);
}

/**
 * 자손 수 기준 가장 풍부한 분기 선택
 */
function pickRichestBranch(branches: LayoutComponent[]): LayoutComponent {
    return branches.reduce((best, curr) =>
        countDescendants(curr) > countDescendants(best) ? curr : best
    );
}

/**
 * 컴포넌트 트리의 총 자손 수 계산
 */
function countDescendants(component: LayoutComponent): number {
    if (!component.children) return 0;
    return component.children.length +
        component.children.reduce((sum, child) => sum + countDescendants(child), 0);
}

/**
 * className 내 {{...}} 표현식 해석
 *
 * 레이아웃 JSON의 className에 {{expr ? 'classA' : 'classB'}} 같은 표현식이 포함될 수 있음.
 * 스켈레톤에서는 데이터가 없으므로 표현식을 평가할 수 없음.
 * → 삼항 연산자의 true 분기 값을 추출하여 적용 (기본값으로 사용).
 * → 단순 표현식(삼항 아닌 경우)은 제거.
 */
function resolveExpressions(className: string): string {
    if (!className.includes('{{')) return className;

    return className.replace(/\{\{([^}]+)\}\}/g, (_, expr: string) => {
        // 삼항 연산자: condition ? 'value1' : 'value2' → value1 추출
        const ternaryMatch = expr.match(/\?[\s]*['"]([^'"]*)['"]\s*:\s*['"]/);
        if (ternaryMatch) {
            return ternaryMatch[1];
        }
        // 삼항 아닌 단순 표현식 → 제거
        return '';
    });
}

/**
 * 스켈레톤 렌더에 적합한 className 변환
 *
 * 1. {{...}} 표현식 해석 (삼항 → true 분기, 단순 표현식 → 제거)
 * 2. 레이아웃 구조 클래스(flex, grid, gap, padding, margin, max-width 등)는 원본 유지.
 * 3. 시각적 클래스(배경색, 텍스트색, 테두리, 그림자 등)는 제거.
 */
function sanitizeClassName(className: string, name: string): string {
    if (!className) return '';

    // 시각적 클래스 제거 (표현식/반응형은 renderSkeletonNode에서 이미 해석됨)
    let sanitized = className;
    sanitized = sanitized
        .replace(/\b(bg-\S+|text-\S+|border\b|border-\S+|shadow\S*|ring-\S+|outline-\S+|divide-\S+|opacity-\S+|font-\S+|tracking-\S+|leading-\S+|whitespace-\S+|truncate|line-clamp-\S+|underline|no-underline|decoration-\S+|cursor-\S+|hover:\S+|focus:\S+|active:\S+|group-hover:\S+|transition\S*|duration-\S+|ease-\S+|animate-\S+|dark:\S+)\b/g, '')
        .replace(/\s{2,}/g, ' ')
        .trim();

    // Grid: 숫자 컬럼 수 유지 (arbitrary value [...]는 보존 — extractArbitraryGridCols에서 처리)
    if (name === 'Grid' || /\bgrid-cols-\d/.test(sanitized)) {
        sanitized = sanitized.replace(/\bgrid-cols-(\d+)/g, (_, val) => {
            const num = parseInt(val);
            return num > 4 ? `grid-cols-${Math.ceil(num / 2)}` : `grid-cols-${num}`;
        });
    }

    return sanitized;
}

/**
 * Flex/Grid 컴포넌트의 레이아웃 props를 Tailwind 클래스로 변환
 *
 * Flex, Grid 컴포넌트는 justify, align, gap 등을 별도 props로 받아
 * 실제 렌더 시 Tailwind 클래스로 변환한다.
 * 스켈레톤은 이 컴포넌트들을 plain <div>로 대체하므로,
 * props → Tailwind 변환을 여기서 수행하여 className에 병합해야 한다.
 *
 * @since engine-v1.17.0
 */
function resolveLayoutProps(component: LayoutComponent): string {
    const { name, props } = component;
    if (!props || !name) return '';

    const classes: string[] = [];

    if (name === 'Flex') {
        classes.push('flex');

        const direction = props.direction || 'row';
        const directionMap: Record<string, string> = {
            row: 'flex-row', 'row-reverse': 'flex-row-reverse',
            col: 'flex-col', 'col-reverse': 'flex-col-reverse',
        };
        if (directionMap[direction]) classes.push(directionMap[direction]);

        const justify = props.justify || 'start';
        const justifyMap: Record<string, string> = {
            start: 'justify-start', end: 'justify-end', center: 'justify-center',
            between: 'justify-between', around: 'justify-around', evenly: 'justify-evenly',
        };
        if (justifyMap[justify]) classes.push(justifyMap[justify]);

        const align = props.align || 'stretch';
        const alignMap: Record<string, string> = {
            start: 'items-start', end: 'items-end', center: 'items-center',
            baseline: 'items-baseline', stretch: 'items-stretch',
        };
        if (alignMap[align]) classes.push(alignMap[align]);

        const wrap = props.wrap || 'nowrap';
        const wrapMap: Record<string, string> = {
            wrap: 'flex-wrap', nowrap: 'flex-nowrap', 'wrap-reverse': 'flex-wrap-reverse',
        };
        if (wrapMap[wrap]) classes.push(wrapMap[wrap]);

        if (props.gap && props.gap > 0) classes.push(`gap-${props.gap}`);
    }

    if (name === 'Grid') {
        classes.push('grid');

        const cols = props.cols || 1;
        classes.push(`grid-cols-${cols}`);

        if (props.responsive) {
            const r = props.responsive;
            if (r.sm) classes.push(`sm:grid-cols-${r.sm}`);
            if (r.md) classes.push(`md:grid-cols-${r.md}`);
            if (r.lg) classes.push(`lg:grid-cols-${r.lg}`);
            if (r.xl) classes.push(`xl:grid-cols-${r.xl}`);
            if (r['2xl']) classes.push(`2xl:grid-cols-${r['2xl']}`);
        }

        if (props.gap !== undefined && props.gap > 0) classes.push(`gap-${props.gap}`);
        if (props.rowGap !== undefined && props.rowGap > 0) classes.push(`gap-y-${props.rowGap}`);
        if (props.colGap !== undefined && props.colGap > 0) classes.push(`gap-x-${props.colGap}`);
    }

    return classes.join(' ');
}

/**
 * 애니메이션 클래스 결정
 */
function getAnimationClass(animation: string): string {
    switch (animation) {
        case 'pulse':
            return 'animate-pulse';
        case 'wave':
            return 'animate-pulse'; // wave는 별도 CSS 필요, pulse로 폴백
        case 'none':
            return '';
        default:
            return 'animate-pulse';
    }
}

/**
 * 자식 컴포넌트 렌더링 (조건부 분기 필터링 적용)
 */
function renderChildren(
    children: LayoutComponent[] | undefined,
    animClass: string,
    iterCount: number,
): (React.ReactElement | null)[] {
    if (!children || children.length === 0) return [];
    const filtered = filterChildren(children);
    return filtered.map((child, i) =>
        renderSkeletonNode(child, animClass, iterCount, i)
    );
}

/**
 * 현재 뷰포트에 맞는 className 결정
 *
 * responsive 속성이 있으면 현재 window 크기에 따라 적절한 className 선택.
 * - desktop (lg, 1024px+): responsive.desktop.props.className
 * - tablet (md, 768px+): responsive.tablet.props.className
 * - 기본: props.className
 */
function resolveClassName(component: LayoutComponent): string {
    const baseClassName = component.props?.className || component.className || '';

    if (!component.responsive) return baseClassName;

    const width = typeof window !== 'undefined' ? window.innerWidth : 1024;

    if (width >= 1024 && component.responsive.desktop?.props?.className) {
        return component.responsive.desktop.props.className;
    }
    if (width >= 768 && component.responsive.tablet?.props?.className) {
        return component.responsive.tablet.props.className;
    }

    return baseClassName;
}

/**
 * Tailwind 반응형 display 클래스를 현재 뷰포트 기준으로 해석
 *
 * 레이아웃 JSON의 className에 `hidden lg:grid`, `lg:hidden` 같은 Tailwind 반응형 패턴이 포함됨.
 * Tailwind은 빌드 시 CSS로 처리하지만, 스켈레톤은 런타임에서 JS로 해석해야 함.
 *
 * 동작:
 * 1. className에서 display 관련 클래스(hidden, flex, grid 등) + 반응형 접두사(lg:grid 등) 추출
 * 2. Tailwind cascade 규칙 적용: base → sm → md → lg → xl → 2xl 순서로 현재 width에 맞는 display 결정
 * 3. hidden이면 컴포넌트 숨김, 아니면 해석된 display 클래스 + 나머지 클래스 반환
 */
function resolveResponsiveDisplay(className: string): { visible: boolean; className: string } {
    const width = typeof window !== 'undefined' ? window.innerWidth : 1024;
    const tokens = className.split(/\s+/).filter(Boolean);

    const displayEntries: Array<{ breakpoint: number; display: string }> = [];
    const otherClasses: string[] = [];

    for (const token of tokens) {
        // 반응형 접두사 + display 클래스: "lg:grid", "sm:flex", "md:hidden"
        const responsiveMatch = token.match(/^(sm|md|lg|xl|2xl):(.+)$/);
        if (responsiveMatch) {
            const [, prefix, cls] = responsiveMatch;
            if (DISPLAY_CLASSES.has(cls)) {
                displayEntries.push({
                    breakpoint: TAILWIND_BREAKPOINTS[prefix] || 0,
                    display: cls,
                });
                continue;
            }
            // 반응형 접두사지만 display가 아닌 클래스 (lg:gap-4 등) → 뷰포트 체크 후 포함
            if ((TAILWIND_BREAKPOINTS[prefix] || 0) <= width) {
                otherClasses.push(cls); // 접두사 제거하고 포함
            }
            continue;
        }

        // 기본(접두사 없는) display 클래스: "hidden", "flex", "grid"
        if (DISPLAY_CLASSES.has(token)) {
            displayEntries.push({ breakpoint: 0, display: token });
            continue;
        }

        otherClasses.push(token);
    }

    // display 클래스가 없으면 그대로 반환
    if (displayEntries.length === 0) {
        return { visible: true, className };
    }

    // breakpoint 오름차순 정렬 후 cascade 적용
    displayEntries.sort((a, b) => a.breakpoint - b.breakpoint);

    let effectiveDisplay = '';
    for (const entry of displayEntries) {
        if (entry.breakpoint <= width) {
            effectiveDisplay = entry.display;
        }
    }

    // 해당 뷰포트에 적용되는 display가 없으면 첫 번째(base) 사용
    if (!effectiveDisplay) {
        effectiveDisplay = displayEntries[0].display;
    }

    const visible = effectiveDisplay !== 'hidden';
    const resolvedClassName = visible
        ? [effectiveDisplay, ...otherClasses].join(' ')
        : '';

    return { visible, className: resolvedClassName };
}

/**
 * className에서 arbitrary grid-cols-[...] 값을 추출하여 inline style로 변환
 *
 * Tailwind의 arbitrary value `grid-cols-[60px_minmax(300px,1fr)_160px]`는
 * 빌드 시 동적 표현식({{...}}) 안에 있으면 purge되어 CSS에 포함되지 않음.
 * → inline style `gridTemplateColumns`로 변환하여 런타임에서 직접 적용.
 */
function extractArbitraryGridCols(className: string): { className: string; style?: React.CSSProperties } {
    const match = className.match(/\bgrid-cols-\[([^\]]+)\]/);
    if (!match) return { className };

    const value = match[1].replace(/_/g, ' ');
    const cleanedClassName = className
        .replace(/\bgrid-cols-\[([^\]]+)\]/, '')
        .replace(/\s{2,}/g, ' ')
        .trim();

    return {
        className: cleanedClassName,
        style: { gridTemplateColumns: value },
    };
}

/**
 * 단일 컴포넌트를 스켈레톤으로 변환
 */
function renderSkeletonNode(
    component: LayoutComponent,
    animClass: string,
    iterCount: number,
    index: number,
): React.ReactElement | null {
    const { name, children, iteration } = component;
    // 1. responsive 속성에서 뷰포트에 맞는 className 선택
    const rawClassName = resolveClassName(component);
    // 1.5. Flex/Grid 레이아웃 props → Tailwind 클래스 병합
    const layoutClasses = resolveLayoutProps(component);
    const mergedClassName = layoutClasses
        ? `${layoutClasses} ${rawClassName}`.trim()
        : rawClassName;
    // 2. {{...}} 표현식 해석 (삼항 → true 분기)
    const exprResolved = resolveExpressions(mergedClassName);
    // 3. Tailwind 반응형 display 클래스 해석 (hidden lg:grid → grid at desktop)
    const responsive = resolveResponsiveDisplay(exprResolved);

    if (!name) return null;

    // 현재 뷰포트에서 숨겨진 컴포넌트 → 스킵
    if (!responsive.visible) return null;

    const className = responsive.className;

    // iteration 블록: 기본 반복 횟수만큼 반복 생성
    if (iteration) {
        return (
            <React.Fragment key={index}>
                {Array.from({ length: iterCount }, (_, i) => (
                    renderSkeletonNode(
                        { ...component, iteration: undefined },
                        animClass,
                        iterCount,
                        i,
                    )
                ))}
            </React.Fragment>
        );
    }

    // 복합 컴포넌트 특화 스켈레톤
    if (COMPOSITE_SKELETONS[name]) {
        return (
            <div key={index} className={className}>
                {COMPOSITE_SKELETONS[name](animClass, iterCount)}
            </div>
        );
    }

    // 레이아웃 컨테이너: className 유지, 자식 재귀 순회
    if (LAYOUT_CONTAINERS.has(name)) {
        const sanitized = sanitizeClassName(className, name);

        // 자식 없는 leaf 컨테이너 → 스켈레톤 바로 표시
        if (!children || children.length === 0) {
            const hasDimension = /\b(h-\S|w-\S)/.test(sanitized);
            const barClass = hasDimension
                ? `${sanitized} bg-gray-200 dark:bg-gray-700 rounded ${animClass}`
                : `${sanitized} h-3.5 w-full bg-gray-200 dark:bg-gray-700 rounded ${animClass}`.trim();
            return <div key={index} className={barClass} />;
        }

        // 자식 렌더링 (조건부 분기 필터링 적용)
        const renderedChildren = renderChildren(children, animClass, iterCount);
        const hasContent = renderedChildren.length > 0 && renderedChildren.some(c => c !== null);

        // 모든 자식이 필터링됨 (로딩/에러 wrapper 등) → 컨테이너 자체 스킵
        if (!hasContent) return null;

        // arbitrary grid-cols-[...] → inline style로 변환
        const gridResult = extractArbitraryGridCols(sanitized);

        return (
            <div key={index} className={gridResult.className} style={gridResult.style}>
                {renderedChildren}
            </div>
        );
    }

    // 비-컨테이너 컴포넌트: 원본 className에서 시각적 클래스(색상 등)를 제거
    // 스켈레톤 바의 색상은 고정(gray-200)이므로 원본 bg-red-500 등이 누출되면 안 됨
    const sanitizedLeafClass = sanitizeClassName(className, name);

    // 텍스트 컴포넌트: 회색 바
    if (TEXT_COMPONENTS.has(name)) {
        const heightMap: Record<string, string> = {
            H1: 'h-7', H2: 'h-6', H3: 'h-5', H4: 'h-5', H5: 'h-4', H6: 'h-4',
            P: 'h-3.5', Span: 'h-3', Label: 'h-3', A: 'h-3.5',
            Badge: 'h-5 w-16',
        };
        const widthMap: Record<string, string> = {
            H1: 'w-3/5', H2: 'w-2/5', H3: 'w-1/3', H4: 'w-1/3', H5: 'w-1/4', H6: 'w-1/4',
            P: 'w-full', Span: 'w-1/4', Label: 'w-1/4', A: 'w-1/3',
            Strong: 'w-1/5', Em: 'w-1/4', Small: 'w-1/5', Badge: 'w-16',
        };
        const height = heightMap[name] || 'h-3.5';
        const width = widthMap[name] || 'w-full';
        const spacing = name.startsWith('H') ? 'mb-2 mt-1' : 'mb-1.5';
        return (
            <div key={index} className={`${height} ${width} bg-gray-200 dark:bg-gray-700 rounded ${animClass} ${spacing} ${sanitizedLeafClass}`.trim()} />
        );
    }

    // 인풋 컴포넌트: 사각형
    if (INPUT_COMPONENTS.has(name)) {
        if (name === 'Textarea') {
            return <div key={index} className={`h-20 w-full bg-gray-100 dark:bg-gray-800 rounded border border-gray-200 dark:border-gray-700 mb-2 ${animClass}`} />;
        }
        if (name === 'Checkbox' || name === 'Radio' || name === 'Toggle') {
            return <div key={index} className={`h-5 w-5 bg-gray-200 dark:bg-gray-700 rounded mb-1.5 ${animClass}`} />;
        }
        return <div key={index} className={`h-9 w-full bg-gray-100 dark:bg-gray-800 rounded border border-gray-200 dark:border-gray-700 mb-2 ${animClass}`} />;
    }

    // 미디어 컴포넌트: 큰 사각형
    if (MEDIA_COMPONENTS.has(name)) {
        if (name === 'Avatar' || name === 'AvatarUploader') {
            return <div key={index} className={`h-10 w-10 bg-gray-200 dark:bg-gray-700 rounded-full ${animClass}`} />;
        }
        if (name === 'ProductImageViewer') {
            return <div key={index} className={`h-80 w-full bg-gray-200 dark:bg-gray-700 rounded ${animClass}`} />;
        }
        return <div key={index} className={`h-40 w-full bg-gray-200 dark:bg-gray-700 rounded mb-2 ${animClass}`} />;
    }

    // Button: children이 있으면 래퍼(컨테이너)로 처리, 없으면 바
    if (name === 'Button') {
        if (children && children.length > 0) {
            const sanitized = sanitizeClassName(className, name);
            const renderedChildren = renderChildren(children, animClass, iterCount);
            const hasContent = renderedChildren.length > 0 && renderedChildren.some(c => c !== null);
            if (!hasContent) return null;
            // 원본 className에 border/rounded가 있으면 실제 버튼 형태 → 스켈레톤 경계 추가
            const hasBorderStyle = /\b(border|rounded|px-\d|py-\d)/.test(rawClassName);
            const borderClass = hasBorderStyle
                ? 'border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 rounded-lg'
                : '';
            return (
                <div key={index} className={`${sanitized} ${borderClass}`.trim()}>
                    {renderedChildren}
                </div>
            );
        }
        return <div key={index} className={`h-9 w-24 bg-gray-200 dark:bg-gray-700 rounded ${animClass}`} />;
    }

    // Icon
    if (name === 'Icon') {
        return <div key={index} className={`h-5 w-5 bg-gray-200 dark:bg-gray-700 rounded ${animClass}`} />;
    }

    // 기타: children이 있으면 컨테이너로, 없으면 범용 바
    if (children && children.length > 0) {
        return (
            <div key={index} className={sanitizedLeafClass}>
                {renderChildren(children, animClass, iterCount)}
            </div>
        );
    }

    // 기본 폴백: 범용 스켈레톤 바
    return <div key={index} className={`h-3.5 w-full bg-gray-200 dark:bg-gray-700 rounded mb-1.5 ${animClass}`} />;
}

/**
 * PageSkeleton — 단일 스켈레톤 렌더러 컴포넌트
 *
 * 엔진이 레이아웃 JSON의 컴포넌트 트리를 props로 전달하면,
 * 재귀적으로 순회하며 각 컴포넌트의 스켈레톤 플레이스홀더를 생성합니다.
 *
 * - 레이아웃 컨테이너(Div, Flex, Grid 등): className 유지, 자식 순회
 * - 텍스트 컴포넌트(H1~H6, P 등): 회색 바
 * - 인풋 컴포넌트(Input, Select 등): 사각형
 * - 미디어 컴포넌트(Img, Avatar 등): 큰 사각형
 * - 복합 컴포넌트(DataGrid, Tabs 등): 특화 스켈레톤
 * - iteration 블록: iteration_count 횟수만큼 반복
 * - 조건부 분기: 로딩/에러 상태 스킵, 콘텐츠 브랜치만 렌더
 *
 * @since engine-v1.24.0
 */
export const PageSkeleton: React.FC<PageSkeletonProps> = ({ components, options }) => {
    const animClass = getAnimationClass(options.animation);

    return (
        <div
            className="w-full"
            role="status"
            aria-busy="true"
            aria-label="Loading..."
        >
            {renderChildren(components, animClass, options.iteration_count)}
        </div>
    );
};

export default PageSkeleton;
