/**
 * styleScope.ts — 색 모드(라이트/다크) × 디바이스(breakpoint) 직교 2축 StyleScope
 *
 * 레이아웃 편집기의 속성 모달 스타일/표시조건 탭이 base `props` 한 곳만이 아니라
 * **선택 scope** 가 가리키는 위치(base `props`/`if` 또는 `responsive[bp].props`/`.if`,
 * `dark:` classToken)에 무손실로 읽기/쓰기하도록 일반화하는 위치 결정 계층이다
 *
 *
 * 색 모드(2) × 디바이스(N) 는 직교 2축이다:
 *  - **색 모드** = Tailwind `dark:` variant. classToken 컨트롤만 다크 편집 가능
 *    (`dark:` prefix 토큰). 인라인 styleProp/cssVar/propValue 는 `dark:` 불가 →
 *    다크 탭에서 읽기전용(무손실 보존).
 *  - **디바이스** = `node.responsive[breakpoint]` 오버라이드 컨테이너 리다이렉트.
 *    'base'(공통)·preset(`desktop`/`tablet`/`mobile`/`portable`)·커스텀 범위
 *    문자열(`"600-900"` 등) 모두 responsive 키로 직접 사용.
 *
 * 본 모듈은 프레임워크 비의존인 recipeEngine 과 분리해 둔다 — `dark:` 는 Tailwind-ism
 * 이므로 editor-spec/recipe 영역(styleScope)에 위치(원칙 4.8). recipeEngine 은
 * scope 를 받아 컨테이너만 리다이렉트하고, `dark:` prefix 헬퍼는 여기서 가져다 쓴다.
 *
 * 모든 쓰기 함수는 순수 함수 — 입력 노드를 변경하지 않고 새 사본을 반환한다.
 *
 * @since engine-v1.50.0
 */

import { responsiveManager, BREAKPOINT_PRESETS } from '../../ResponsiveManager';
import type { EditorNode } from '../utils/layoutTreeUtils';
import { clonePropsWithStyle } from './recipeEngine';
import type { RecipeApply } from './recipeEngine';
import type { PreviewDevice } from '../LayoutEditorContext';

/** 색 모드 — base = 라이트(공통), dark = 다크 variant */
export type ColorScheme = 'base' | 'dark';

/**
 * 디바이스 scope — 'base'(공통) | preset | 커스텀 범위 문자열.
 * = responsive 키와 동일 어휘. 고정 enum 이 아니라 string 일반화(엔진 1급 커스텀 범위 지원).
 */
export type ScopeBreakpoint = 'base' | string;

export interface StyleScope {
  colorScheme: ColorScheme;
  breakpoint: ScopeBreakpoint;
}

/** 기본 scope — 라이트 × 공통(base). recipeEngine 의 scope 미지정 시 기본값. */
export const BASE_SCOPE: StyleScope = { colorScheme: 'base', breakpoint: 'base' };

/** 다크 variant prefix */
export const DARK_PREFIX = 'dark:';

/**
 * 디바이스 scope 유효성 — 'base' 이거나 `ResponsiveManager.parseRange` 가 파싱
 * 가능한 키(preset 또는 유효한 범위 문자열, min<=max). 커스텀 추가 입력 검증에 사용.
 *
 * @param bp 검사할 breakpoint 키
 * @return 유효하면 true
 */
export function isValidScopeBreakpoint(bp: string): boolean {
  if (bp === 'base') return true;
  return responsiveManager.parseRange(bp) !== null;
}

/** preset 키(`mobile`/`tablet`/`desktop`/`portable`)인지 — 동적 커스텀 탭 구분에 사용 */
export function isPresetBreakpoint(bp: string): boolean {
  return bp in BREAKPOINT_PRESETS;
}

/**
 * 상단 미리보기 디바이스/폭 → 모달 기본 세부탭(breakpoint).
 *
 * preset 디바이스(`desktop`/`tablet`/`mobile`)는 동명 키. custom 폭이면 그 폭에
 * 노드의 `responsive` 키 중 `getMatchingKey`(커스텀>preset, 좁은>넓은 우선순위)로
 * 매칭되는 키, 없으면 'base'. 편집 결과와 런타임 우선순위를 일치시킨다.
 *
 * @param device 상단 미리보기 디바이스
 * @param node 대상 노드
 * @param customWidth custom 디바이스일 때 프레임 폭(px)
 * @return 기본 세부탭 breakpoint
 */
export function deviceToBreakpoint(
  device: PreviewDevice,
  node: EditorNode,
  customWidth?: number,
): ScopeBreakpoint {
  // custom — 그 폭에 매칭되는 노드 responsive 키 우선, 없으면 base.
  if (device === 'custom') {
    if (typeof customWidth === 'number') {
      const responsive = node.responsive;
      if (responsive && typeof responsive === 'object') {
        const matched = responsiveManager.getMatchingKey(
          responsive as Record<string, unknown>,
          customWidth,
        );
        if (matched) return matched;
      }
    }
    return 'base';
  }
  // 그 외 모든 디바이스 키(preset desktop/tablet/mobile/portable + 레이아웃 동적 커스텀 키)는
  // breakpoint 키와 동명이므로 그대로 반환한다(portable·커스텀 결선).
  return device;
}

/**
 * 디바이스 전용 '분리' 판정 결과.
 *
 *  - `separate`: 현재 디바이스 폭을 담는 (정확히 같은) children 분기가 없음 → "현재 디바이스 전용
 *    분리 생성". `key` = 생성할 분기 키(현재 디바이스 키). `sourceKey` 가 있으면 그 포괄 분기의
 *    children 을 복제 원본으로 쓴다(없으면 base 복제).
 *  - `merge`: 현재 디바이스 폭 범위와 **정확히 같은** children 분기가 있음 → "분리 해제". `key` = 그 분기 키.
 *
 * 포괄 분기(현재 폭을 담는 더 넓은 children 분기, portable ⊇ mobile 등)가 있어도 더 이상 버튼을
 * 숨기지 않는다: 그 포괄 구성으로 이동하는 점프 버튼(definedDeviceBranches)
 * 과, 현재 디바이스 전용으로 새로 분리하는 버튼(separate, sourceKey=포괄 분기)을 함께 노출한다.
 */
export interface BranchSeparationDecision {
  mode: 'separate' | 'merge';
  key: string;
  /** separate 모드에서 복제 원본으로 쓸 포괄 분기 키(있으면). 없으면 base 복제. */
  sourceKey?: string;
}

/**
 * breakpoint 분기에 `children` 교체가 있는지 — '분리' 판정의 유일 기준.
 *
 * "디바이스 전용 분리"는 **자식(children) 완전 교체**만을 뜻한다(props 스타일 오버라이드는
 * 속성>스타일 탭 영역으로 완전 무관). 따라서 분기에 `children` 배열이 있을 때만 그 분기를
 * "분리된 구성" 으로 본다. props 만 가진 분기는 분리로 치지 않는다.
 *
 * @param branch responsive 분기 값
 * @return children 배열을 가지면 true
 */
function branchHasChildren(branch: { children?: unknown } | undefined): boolean {
  return !!branch && typeof branch === 'object' && Array.isArray(branch.children);
}

/**
 * 현재 디바이스 보기에서 노드의 '분리' 동작을 판정한다.
 *
 * **판정 기준 = children 교체 유무만**(props 완전 무관). props-only 분기는
 * 무시하고, `children` 을 가진 분기만 "분리된 구성" 으로 본다. 규칙(현재 디바이스 폭 범위
 * `[curMin,curMax]` vs 노드의 **children 보유** 분기 키들):
 *  1. 현재 범위와 **정확히 같은** children 분기 → `merge`(분리 해제).
 *  2. 현재 범위를 **완전 포함(더 넓음)** 하는 children 분기 → `none`(포괄 분기 내 편집, 버튼 없음).
 *  3. 둘 다 없으면 → `separate`(현재 디바이스 전용 생성). 키 = 현재 디바이스 키.
 *
 * 모든 디바이스 동등(특정 디바이스를 base 로 특례화하지 않음). preset/커스텀 범위
 * 모두 `parseRange` 로 통일 처리하므로 엔진의 임의 범위 분기와 정합한다. 같은 키에 props 만
 * 있어도(스타일 분기) children 분리 생성은 가능하다(props/children 독립 축).
 *
 * @param device 현재 미리보기 디바이스(`desktop`/`tablet`/`mobile`/`portable`/`custom`/동적 키)
 * @param node 대상 노드(`responsive` 분기 키 집합 참조)
 * @param customWidth custom 디바이스일 때 프레임 폭(px)
 * @return 분리 판정 결과 — UI 가 separate/merge/none 으로 버튼 분기
 * @since engine-v1.50.0
 * @since engine-v1.50.0
 */
export function resolveBranchSeparationMode(
  device: PreviewDevice,
  node: EditorNode,
  customWidth?: number,
): BranchSeparationDecision {
  // 현재 디바이스의 폭 범위 + 생성 시 쓸 키.
  let curKey: string;
  let curMin: number;
  let curMax: number;
  if (device !== 'custom' && device in BREAKPOINT_PRESETS) {
    curKey = device;
    const preset = BREAKPOINT_PRESETS[device]!;
    curMin = preset.min;
    curMax = preset.max;
  } else if (device !== 'custom') {
    // 동적 커스텀 범위 키(예 "600-900") — 그 범위로 직접 판정.
    const range = responsiveManager.parseRange(device);
    if (range) {
      curKey = device;
      curMin = range.min;
      curMax = range.max;
    } else {
      // 파싱 불가 — 안전하게 현재 너비 단일폭으로 폴백.
      const w = typeof customWidth === 'number' ? customWidth : responsiveManager.getWidth();
      curKey = `${w}-${w}`;
      curMin = w;
      curMax = w;
    }
  } else {
    // custom — 폭 기준. 키는 단일폭 범위 문자열.
    const w = typeof customWidth === 'number' ? customWidth : responsiveManager.getWidth();
    curMin = w;
    curMax = w;
    curKey = `${w}-${w}`;
  }

  const responsive = node.responsive;
  if (responsive && typeof responsive === 'object') {
    // 현재 폭을 포괄하는 더 넓은 children 분기 중 가장 좁은(가까운) 것을 복제 원본 후보로.
    let widerKey: string | null = null;
    let widerSpan = Infinity;
    for (const key of Object.keys(responsive)) {
      // children 교체가 없는 분기(props-only 스타일 분기)는 분리 판정에서 무시.
      if (!branchHasChildren(responsive[key])) continue;
      const range = responsiveManager.parseRange(key);
      if (!range) continue;
      // 정확히 같은 범위 → 해제(되돌릴 수 있는 동일-디바이스 children 분기).
      if (range.min === curMin && range.max === curMax) {
        return { mode: 'merge', key };
      }
      // 현재 폭 범위를 완전 포함(더 넓음) → 복제 원본 후보(가장 좁은 포괄 분기 선택).
      if (range.min <= curMin && range.max >= curMax) {
        const span = range.max - range.min;
        if (span < widerSpan) {
          widerSpan = span;
          widerKey = key;
        }
      }
    }
    // 포괄 children 분기가 있으면 그것을 복제 원본으로 현재 디바이스 전용 생성.
    if (widerKey) return { mode: 'separate', key: curKey, sourceKey: widerKey };
  }

  // 포함/동일 children 분기 없음 → 현재 디바이스 전용 생성(base 복제).
  return { mode: 'separate', key: curKey };
}

/**
 * breakpoint 키 → 분기 배지 라벨.
 *
 * preset 키는 i18n 키를 반환(`t()` 로 번역) — `mobile`/`portable`→"모바일 구성",
 * `tablet`→"태블릿 구성", `desktop`→"데스크톱 구성". 커스텀 범위 문자열(`"600-900"` 등)은
 * 그 폭 표기를 그대로 라벨에 쓰도록 `null` i18n 키 + raw 값을 반환한다.
 *
 * @param key responsive 분기 키 원문
 * @return `{ i18nKey, raw }` — i18nKey 가 있으면 번역, 없으면 `구성 {raw}` 표기
 */
export function breakpointKeyLabel(key: string): { i18nKey: string | null; raw: string } {
  switch (key) {
    case 'mobile':
    case 'portable':
      return { i18nKey: 'layout_editor.overlay.branch_mobile', raw: key };
    case 'tablet':
      return { i18nKey: 'layout_editor.overlay.branch_tablet', raw: key };
    case 'desktop':
      return { i18nKey: 'layout_editor.overlay.branch_desktop', raw: key };
    default:
      return { i18nKey: null, raw: key };
  }
}

/**
 * 스코프 props 읽기 컨테이너.
 *  - base → `node.props`
 *  - 그 외 → `node.responsive?.[breakpoint]?.props`  (없으면 {})
 *
 * @param node 대상 노드
 * @param scope 활성 scope
 * @return 해당 scope 의 props (없으면 빈 객체)
 */
export function getScopedProps(node: EditorNode, scope: StyleScope): Record<string, unknown> {
  if (scope.breakpoint === 'base') {
    return (node.props ?? {}) as Record<string, unknown>;
  }
  const branch = node.responsive?.[scope.breakpoint];
  return (branch?.props ?? {}) as Record<string, unknown>;
}

/**
 * 스코프 props 쓰기(+prune, 불변). mutator 에 클론된 props·style 을 전달 →
 * 변형 → 빈 style 제거, bp≠base 면 빈 `responsive[bp].props`/`responsive[bp]`/
 * `responsive` 까지 정리(코드 작성 `children/text/if/iteration` 남은 브랜치는 보존).
 *
 * @param node 대상 노드 (변경되지 않음)
 * @param scope 활성 scope
 * @param mutator 클론된 props/style 을 변형하는 콜백
 * @return 패치된 노드 사본
 */
export function withScopedProps(
  node: EditorNode,
  scope: StyleScope,
  mutator: (props: Record<string, unknown>, style: Record<string, unknown>) => void,
): EditorNode {
  if (scope.breakpoint === 'base') {
    const { props, style } = clonePropsWithStyle(node.props);
    mutator(props, style);
    if (Object.keys(style).length === 0) delete props.style;
    return { ...node, props };
  }

  // bp 브랜치 — responsive[bp].props 컨테이너에 적용
  const bp = scope.breakpoint;
  const responsive: Record<string, Record<string, unknown>> = {
    ...((node.responsive ?? {}) as Record<string, Record<string, unknown>>),
  };
  const branch: Record<string, unknown> = { ...(responsive[bp] ?? {}) };
  const { props, style } = clonePropsWithStyle(
    branch.props as Record<string, unknown> | undefined,
  );

  mutator(props, style);

  if (Object.keys(style).length === 0) delete props.style;

  // prune — 빈 props → 브랜치에서 제거
  if (Object.keys(props).length === 0) {
    delete branch.props;
  } else {
    branch.props = props;
  }

  // 브랜치 전체가 비면(코드 작성 children/text/if/iteration 없음) 브랜치 삭제
  if (Object.keys(branch).length === 0) {
    delete responsive[bp];
  } else {
    responsive[bp] = branch;
  }

  const next: EditorNode = { ...node };
  if (Object.keys(responsive).length === 0) {
    delete next.responsive;
  } else {
    next.responsive = responsive as EditorNode['responsive'];
  }
  return next;
}

/**
 * 스코프 표시조건(if) **자체값** 읽기 (상속 폴백 없음).
 *  - base → `node.if`
 *  - bp → `node.responsive?.[bp]?.if`
 *
 * 디바이스 브랜치에 자체 `if` 가 없으면 undefined 를 반환한다(base 로 폴백하지
 * 않는다). override 존재 판정(표시점)·prune 쓰기 경로가 "이 scope 가 직접 가진
 * 값" 을 알아야 하므로 자체값을 그대로 노출한다. 화면 표시용 유효값은
 * `getEffectiveScopedIf` 를 쓴다.
 *
 * @param node 대상 노드
 * @param scope 활성 scope
 * @return 해당 scope 자체 표시조건 식 (없으면 undefined)
 */
export function getScopedIf(node: EditorNode, scope: StyleScope): unknown {
  if (scope.breakpoint === 'base') {
    return node.if;
  }
  return node.responsive?.[scope.breakpoint]?.if;
}

/**
 * 스코프 표시조건(if) **유효값** 읽기 (디바이스 브랜치 → base 상속 폴백).
 *
 * `DynamicRenderer` 가 매칭 breakpoint 에서 `{...base, ...override}` 로 합성하므로,
 * 디바이스 브랜치에 `if` 오버라이드가 없으면 base `node.if` 가 그대로 유효 조건이다.
 * 표시조건 탭이 디바이스 세부탭(PC/태블릿/모바일)에서도 base 에 정의된 조건을
 * "현재 유효한 조건" 으로 노출하도록 이 유효값을 읽는다(base if 가
 * 디바이스 탭에서 빈 빌더로 가려지던 결함).
 *
 * - base scope → `node.if`
 * - bp scope → `node.responsive[bp].if` 가 **정의돼 있으면** 그 값(빈 문자열 포함),
 *   `if` 키 자체가 없으면 base `node.if` 로 폴백.
 *
 * 빈 문자열('')은 "이 디바이스에서 명시적으로 조건 없음" 을 뜻하는 유효한 override
 * 이므로 폴백하지 않는다. 키 부재(undefined)만 상속으로 본다.
 *
 * @param node 대상 노드
 * @param scope 활성 scope
 * @return 해당 scope 의 유효 표시조건 식 (없으면 undefined)
 */
export function getEffectiveScopedIf(node: EditorNode, scope: StyleScope): unknown {
  if (scope.breakpoint === 'base') {
    return node.if;
  }
  const branch = node.responsive?.[scope.breakpoint];
  // 디바이스 브랜치가 `if` 키를 직접 가지면(빈 문자열 명시 포함) 그 값이 유효값.
  if (branch && typeof branch === 'object' && 'if' in branch) {
    return (branch as { if?: unknown }).if;
  }
  // 디바이스별 override 없음 → base 상속.
  return node.if;
}

/**
 * 스코프 표시조건(if) 쓰기(+prune, 불변). 빈 식('' / null / undefined)이면 제거.
 *
 * @param node 대상 노드 (변경되지 않음)
 * @param scope 활성 scope
 * @param ifExpr 표시조건 식 ('' 면 제거)
 * @return 패치된 노드 사본
 */
export function setScopedIf(node: EditorNode, scope: StyleScope, ifExpr: string): EditorNode {
  const isEmpty = ifExpr === undefined || ifExpr === null || ifExpr === '';

  if (scope.breakpoint === 'base') {
    const next: EditorNode = { ...node };
    if (isEmpty) {
      delete next.if;
    } else {
      next.if = ifExpr;
    }
    return next;
  }

  const bp = scope.breakpoint;
  const responsive: Record<string, Record<string, unknown>> = {
    ...((node.responsive ?? {}) as Record<string, Record<string, unknown>>),
  };
  const branch: Record<string, unknown> = { ...(responsive[bp] ?? {}) };

  if (isEmpty) {
    delete branch.if;
  } else {
    branch.if = ifExpr;
  }

  if (Object.keys(branch).length === 0) {
    delete responsive[bp];
  } else {
    responsive[bp] = branch;
  }

  const next: EditorNode = { ...node };
  if (Object.keys(responsive).length === 0) {
    delete next.responsive;
  } else {
    next.responsive = responsive as EditorNode['responsive'];
  }
  return next;
}

// ============================================================================
// 다크 classToken 헬퍼 (`dark:` prefix)
// ============================================================================

/**
 * 다크 편집 가능한 apply 인지 — classToken 만 true.
 * styleProp/cssVar/propValue 는 인라인이라 `dark:` 불가 → 다크 탭 읽기전용.
 *
 * @param apply 컨트롤/옵션의 apply 선언
 * @return classToken 이면 true
 */
export function isDarkEditable(apply: RecipeApply | undefined): boolean {
  return apply?.type === 'classToken';
}

/** 토큰이 `dark:` prefix 를 가지는지 */
export function hasDarkPrefix(token: string): boolean {
  return token.startsWith(DARK_PREFIX);
}

/** `dark:bg-x` → `bg-x` (prefix 없으면 그대로) */
export function stripDarkPrefix(token: string): string {
  return token.startsWith(DARK_PREFIX) ? token.slice(DARK_PREFIX.length) : token;
}

/** `bg-x` → `dark:bg-x` (이미 prefix 면 그대로 — 멱등) */
export function addDarkPrefix(token: string): string {
  return token.startsWith(DARK_PREFIX) ? token : `${DARK_PREFIX}${token}`;
}

// ============================================================================
// scope override 제거(초기화) + 표시점 판정
// ============================================================================

/** className 문자열을 토큰 배열로 (내부 헬퍼) */
function toTokens(className: unknown): string[] {
  return typeof className === 'string' ? className.split(/\s+/).filter((t) => t.length > 0) : [];
}

/**
 * 활성 scope 의 override 를 제거해 "기본값으로 초기화"한다(불변).
 *
 *  - 디바이스 scope(라이트): `responsive[bp].props` 전체 제거 → 그 디바이스는 기본값 상속으로
 *    복귀(브랜치의 코드 작성 children/text/if/iteration 은 보존, 전부 비면 브랜치/responsive prune).
 *  - 다크 scope(breakpoint=base): `node.props.className` 의 `dark:` 토큰 전부 제거.
 *  - 다크 + 디바이스: `responsive[bp].props.className` 의 `dark:` 토큰 제거(라이트 토큰은 보존).
 *
 * B안 시드로 디바이스 override 가 base 복사본을 들고 있을 때, 사용자가 "기본값으로 초기화"를
 * 누르면 그 복사본을 비워 다시 기본값을 상속하게 만든다(stale 해소 수단).
 *
 * @param node 대상 노드 (변경되지 않음)
 * @param scope 활성 scope
 * @return override 가 제거된 노드 사본
 */
export function clearScopeOverride(node: EditorNode, scope: StyleScope): EditorNode {
  const dark = scope.colorScheme === 'dark';

  // 다크 색 모드 — className 의 dark: 토큰만 제거(해당 컨테이너에서). breakpoint 가 base 면
  // node.props, 그 외면 responsive[bp].props.
  if (dark) {
    return withScopedProps(node, scope, (props) => {
      const kept = toTokens(props.className).filter((t) => !hasDarkPrefix(t));
      if (kept.length === 0) delete props.className;
      else props.className = kept.join(' ');
    });
  }

  // 라이트 디바이스 scope — responsive[bp].props 전체 제거.
  if (scope.breakpoint === 'base') {
    // 라이트 × 공통 초기화는 정의상 "기본값 자체" — 제거 대상 없음(no-op).
    return node;
  }
  const bp = scope.breakpoint;
  if (!node.responsive?.[bp]) return node;

  const responsive: Record<string, Record<string, unknown>> = {
    ...(node.responsive as Record<string, Record<string, unknown>>),
  };
  const branch: Record<string, unknown> = { ...responsive[bp] };
  delete branch.props;
  if (Object.keys(branch).length === 0) {
    delete responsive[bp];
  } else {
    responsive[bp] = branch;
  }
  const next: EditorNode = { ...node };
  if (Object.keys(responsive).length === 0) {
    delete next.responsive;
  } else {
    next.responsive = responsive as EditorNode['responsive'];
  }
  return next;
}

/**
 * 활성 scope 에 "기본값과 다른 명시 설정"이 있는지 — 디바이스/커스텀/다크 탭의 표시점(●) 판정.
 *
 * B안 시드로 디바이스 override 가 base className 을 복사만 한 경우는 표시점을 띄우지 않는다
 * (사용자가 실제로 바꾼 게 없으므로). 즉 "scope className 토큰 집합이 base 와 다른가"로 본다.
 *
 *  - 디바이스 scope(라이트): `responsive[bp].props.className` 의 비-dark 토큰 집합이 base 의
 *    비-dark 토큰 집합과 다르면 true(시드만이면 동일 → false). props 에 className 외 다른 키가
 *    있어도 true(스타일 외 오버라이드).
 *  - 다크 scope: 해당 컨테이너 className 에 `dark:` 토큰이 하나라도 있으면 true.
 *
 * @param node 대상 노드
 * @param scope 활성 scope
 * @return 명시 override 가 있으면 true
 */
export function hasScopeOverride(node: EditorNode, scope: StyleScope): boolean {
  const dark = scope.colorScheme === 'dark';

  if (dark) {
    const container = scope.breakpoint === 'base' ? node.props : node.responsive?.[scope.breakpoint]?.props;
    return toTokens((container as Record<string, unknown> | undefined)?.className).some(hasDarkPrefix);
  }

  if (scope.breakpoint === 'base') return false; // 기본값 탭은 표시점 없음
  const branch = node.responsive?.[scope.breakpoint];
  if (!branch) return false;
  // props 외 코드 작성 키(children/text/if/iteration)만 있어도 override 로 본다.
  const branchProps = branch.props as Record<string, unknown> | undefined;
  const otherKeys = Object.keys(branch).filter((k) => k !== 'props');
  if (otherKeys.length > 0) return true;
  if (!branchProps) return false;
  // className 외 다른 props 키가 있으면 override.
  const propKeys = Object.keys(branchProps).filter((k) => k !== 'className');
  if (propKeys.length > 0) return true;
  // className 비-dark 토큰 집합을 base 와 비교 — 다르면 명시 변경(시드만이면 동일).
  const baseSet = new Set(toTokens((node.props as Record<string, unknown> | undefined)?.className).filter((t) => !hasDarkPrefix(t)));
  const scopeSet = toTokens(branchProps.className).filter((t) => !hasDarkPrefix(t));
  if (scopeSet.length !== baseSet.size) return true;
  return scopeSet.some((t) => !baseSet.has(t));
}
