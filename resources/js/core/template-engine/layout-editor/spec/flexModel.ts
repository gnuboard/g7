/**
 * flexModel.ts — "정렬 박스"(flex) 편집 모델
 *
 * flex 를 비개발자 친화 UI("정렬 박스")로 노출하기 위한 추상 모델. FlexEditor 가
 * 본 모델의 축(방향/주축 정렬/교차축 정렬/줄바꿈/간격, 아이템의 늘어남/혼자 정렬/
 * 순서)을 렌더하고, 각 축의 값은 editor-spec 의 `controls` 레시피(`apply`)로
 * 코드에 반영된다(원칙 4.8 — classToken/styleProp 어느 쪽이든 그 템플릿 결정).
 *
 * 코어는 어떤 CSS 시스템도 가정하지 않는다. 본 파일은 (1) 축 키 상수 (2) 컨테이너가
 * 실제 flex 로 렌더되는지 computed style 로 판정하는 헬퍼(12.3.4)만 제공한다. 실제
 * 코드 형식은 `flexControlKeys` 가 가리키는 컨트롤의 `apply` 가 정한다.
 *
 * @since engine-v1.50.0
 */

import type { ComponentCapabilitySpec, EditorControlSpec } from './specTypes';
import type { EditorNode } from '../utils/layoutTreeUtils';
import { reverseResolve } from './recipeEngine';
import { BASE_SCOPE, type StyleScope } from './styleScope';

/** flexEditor 역할 — capability.flexEditor 값 */
export type FlexEditorRole = 'container' | 'item' | 'auto';

/**
 * 컨테이너(정렬 박스) 편집 축 → editor-spec `controls` 키 매핑 규약.
 *
 * 번들 템플릿은 이 키들의 컨트롤을 editor-spec 에 선언한다. 미선언 축은
 * FlexEditor 가 그 축 컨트롤을 숨긴다(원칙 4.6 — 선언 없으면 비노출).
 */
export const FLEX_CONTAINER_CONTROL_KEYS = {
  /** 방향 — 가로(row)/세로(column) */
  direction: 'flexDirection',
  /** 주축 정렬 — 시작/가운데/끝/양끝/균등 */
  justify: 'flexJustify',
  /** 교차축 정렬 — 시작/가운데/끝/늘이기 */
  align: 'flexAlign',
  /** 줄바꿈 허용 */
  wrap: 'flexWrap',
  /** 자식 간격 */
  gap: 'flexGap',
  /** "정렬 박스로 만들기" — auto 컨테이너를 flex 로 전환 */
  enable: 'flexEnable',
} as const;

/**
 * 아이템(정렬 박스 안 자식) 편집 축 → editor-spec `controls` 키 매핑 규약.
 */
export const FLEX_ITEM_CONTROL_KEYS = {
  /** 늘어남 — 고정/채우기/비율 */
  grow: 'flexItemGrow',
  /** 혼자 정렬 — 시작/가운데/끝/늘이기 */
  selfAlign: 'flexItemAlign',
  /** 순서 */
  order: 'flexItemOrder',
} as const;

/** capability 에서 flexEditor 역할을 읽는다 (미선언 시 null) */
export function getFlexEditorRole(
  capability: ComponentCapabilitySpec | null | undefined,
): FlexEditorRole | null {
  const role = (capability as { flexEditor?: unknown } | null | undefined)?.flexEditor;
  if (role === 'container' || role === 'item' || role === 'auto') return role;
  return null;
}

/**
 * 컨테이너가 실제 flex 로 렌더되는지 computed style 로 판정.
 *
 * className 토큰이 아니라 `getComputedStyle().display` 가 `flex|inline-flex` 인지로
 * 판정한다 — 스타일 시스템 중립(원칙 4.8). SSR/테스트 환경(window 없음)·null 엘리먼트는
 * false 폴백(자유롭게 "정렬 박스로 만들기" 버튼 모드).
 *
 * @param el 대상 엘리먼트 (캔버스 렌더 DOM)
 * @return flex 로 렌더 중이면 true
 */
export function isRenderedAsFlex(el: Element | null | undefined): boolean {
  if (!el || typeof window === 'undefined' || typeof window.getComputedStyle !== 'function') {
    return false;
  }
  const display = (window.getComputedStyle(el).display ?? '').toLowerCase();
  return display === 'flex' || display === 'inline-flex';
}

/**
 * 노드가 "정렬 박스로 만들기"(enable) 레시피를 이미 적용했는지 — **노드 파생** 판정.
 *
 * enable 컨트롤(`flexEnable`)의 `apply` 를 노드의 className/style 에서 역해석한다.
 * computed style(`isRenderedAsFlex`)과 달리 캔버스 DOM 에 의존하지 않으므로, 모달
 * content 가 패치마다 재마운트되며 `liveElement` 가 패치 직전 DOM 으로 고정(stale)되는
 * 경로(§항목A)에서도 정확하다. 토글 즉시 정렬 박스 ON/OFF 가 모달에 반영된다.
 *
 * **scope**: scope≠base 면 그 scope 컨테이너(`responsive[bp].props`)
 * 에서 enable 레시피를 역해석한다 — 디바이스별 flex on/off 판정. base fallback 은 보지
 * 않는다(scope 자체에 명시 설정이 있어야 그 디바이스에서 flex 로 본다. flexEnable off
 * 레시피 D7 가 명시 해제 토큰을 쓰는 이유).
 *
 * @param node 대상 노드
 * @param enableControl flexEnable 컨트롤 (미선언 시 null → false)
 * @param scope 활성 StyleScope (기본 BASE_SCOPE)
 * @return enable 레시피가 적용돼 있으면 true
 * @since engine-v1.50.0
 */
export function isNodeFlexEnabled(
  node: EditorNode | null | undefined,
  enableControl: EditorControlSpec | null | undefined,
  scope: StyleScope = BASE_SCOPE,
): boolean {
  if (!node || !enableControl) return false;
  const r = reverseResolve(node, enableControl, scope);

  // enable 컨트롤이 on/off **options** 를 가지면(실제 editor-spec flexEnable: on=flex, off=block),
  // 역해석은 off 토큰(block)에도 매칭되어 `value='block', matched=true` 가 된다. 해제 후 className
  // 에 off 토큰이 남으면 "값이 존재한다"만으로 enabled 판정 시 해제가 반영되지 않아 "정렬 박스로
  // 만들기" 버튼이 복귀하지 않는다(§항목A 해제 토글 회귀). on/off 옵션 컨트롤은 역해석값이 on 값
  // (`onValue` 또는 첫 옵션값 — FlexEditor 의 enableFlex 가 적용하는 값과 동일 규약)과 일치할 때만
  // enabled 다. options 미선언 컨트롤(단순 classToken toggle: value=true)은 기존 동작(존재성 판정)
  // 유지 — 하위호환.
  const enableOptions = (enableControl as { options?: Array<{ value: unknown }> }).options;
  const onValue = Array.isArray(enableOptions)
    ? ((enableControl as { onValue?: unknown }).onValue ?? enableOptions[0]?.value)
    : undefined;
  const isEnabledValue = (value: unknown): boolean =>
    onValue !== undefined ? value === onValue : value !== undefined && value !== false;

  // scope≠base 면 scope 컨테이너 자체 토큰(scopedRawValue)을 본다 — flex enable 은 존재성
  // 컨트롤이라 base 와 동일값(예: base=flex, 디바이스=flex)이어도 그 디바이스에 토큰이 실재하면
  // flex 로 인정해야 한다(B안 placeholder 보정의 scopedValue 는 base 동일값을 undefined 로
  // 흐리므로 부적합). off 는 명시 해제 토큰(block)으로 기록되어 onValue 불일치로 구분된다(D7/D8).
  if (scope.breakpoint !== 'base') {
    return isEnabledValue(r.scopedRawValue);
  }
  return r.matched && isEnabledValue(r.value);
}

/**
 * FlexEditor 가 컨테이너 컨트롤(정렬 박스 본체)을 노출할지 결정 (12.3.4).
 *
 * - `container`: 항상 노출.
 * - `item`: 컨테이너 컨트롤 비노출(아이템 컨트롤만).
 * - `auto`: 정렬 박스로 만들어졌으면 컨테이너 컨트롤 + 해제 버튼, 아니면 "정렬 박스로
 *   만들기" 버튼만.
 *
 * `auto` 의 flex 여부 판정 우선순위:
 *  1. **노드 파생**(`nodeFlexEnabled`) — enable 컨트롤 역해석. 패치 직후에도 stale 하지
 *     않아 토글 ON/OFF 가 즉시 반영(§항목A 결함 근본 해소).
 *  2. enable 컨트롤이 없는 템플릿 한정 — computed style(`isRenderedAsFlex(el)`)
 *     로 폴백. 토글 대상이 없으므로 해제 버튼은 노출하지 않는다.
 *
 * **scope**: scope≠base 면 computed style 폴백(`isRenderedAsFlex`)은
 * 캔버스 폭 의존이라 scope 와 어긋나므로 **사용하지 않는다**. enable 컨트롤 없는 템플릿은
 * scope 탭에서 flex on/off 컨트롤을 비노출(혼란 방지) — auto + scope≠base + enable 없음 →
 * 컨테이너 컨트롤만(showEnableButton=false).
 *
 * @param role flexEditor 역할
 * @param el 캔버스 렌더 DOM (enable 컨트롤 없는 템플릿의 computed style 폴백용, base 만)
 * @param nodeFlexEnabled 노드 파생 flex 활성 여부 (enable 컨트롤 역해석 결과, scope 적용)
 * @param hasEnableControl enable 컨트롤이 editor-spec 에 선언돼 있는지
 * @param scope 활성 StyleScope (기본 BASE_SCOPE) — scope≠base 면 computed 폴백 비사용
 * @return { showContainer, showEnableButton, showDisableButton }
 */
export function resolveFlexContainerMode(
  role: FlexEditorRole | null,
  el: Element | null | undefined,
  nodeFlexEnabled = false,
  hasEnableControl = false,
  scope: StyleScope = BASE_SCOPE,
): { showContainer: boolean; showEnableButton: boolean; showDisableButton: boolean } {
  if (role === 'container') {
    return { showContainer: true, showEnableButton: false, showDisableButton: false };
  }
  if (role === 'item') {
    return { showContainer: false, showEnableButton: false, showDisableButton: false };
  }
  if (role === 'auto') {
    const isBase = scope.breakpoint === 'base';
    // enable 컨트롤 있으면 노드 파생(scope 적용). 없으면 base 에서만 computed style 폴백,
    // scope≠base + enable 없음 → flex on/off 편집 비노출(D8).
    if (!hasEnableControl && !isBase) {
      return { showContainer: false, showEnableButton: false, showDisableButton: false };
    }
    const isFlex = hasEnableControl ? nodeFlexEnabled : isRenderedAsFlex(el);
    if (isFlex) {
      return {
        showContainer: true,
        showEnableButton: false,
        // 정렬 박스 해제 버튼 — enable 컨트롤이 있을 때만(역적용 토글 가능).
        showDisableButton: hasEnableControl,
      };
    }
    return { showContainer: false, showEnableButton: true, showDisableButton: false };
  }
  return { showContainer: false, showEnableButton: false, showDisableButton: false };
}
