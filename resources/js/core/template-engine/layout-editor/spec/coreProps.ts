/**
 * coreProps.ts — 레이아웃 편집기 코어 제공 속성 컨트롤
 *
 * 모든 draggable 컴포넌트의 [속성] 탭 최상단에 코어가 일괄 제공하는 컨트롤
 * (현재 = 요소 `id`). 템플릿 editor-spec 의 `propControls`(확장 제공)와 **유기적으로
 * 결합**되어 한 탭에 함께 렌더된다(코어 먼저 → 확장 propControls).
 *
 * 설계 원칙:
 *  - **컨트롤만 제공, DOM 강제 주입 안 함**: 값은 표준 `node.props.id` 로만 흘려보낸다.
 *    기존 레이아웃이 이미 쓴 id 는 그대로 읽고/편집(같은 필드), 컴포넌트가 자체 동적
 *    id 를 생성하면 코어는 강제하지 않는다. 런타임 DOM 반영은 컴포넌트 passthrough
 *    책임(미통과 컴포넌트는 별도 보강).
 *  - **유기적 결합**: 코어 제공 컨트롤(`coreControl:{key}`)을 확장 propControls 와 같은
 *    `ControlRenderer` 파이프라인으로 렌더 → 동일 위젯/역해석/패치 경로 재사용.
 *  - **opt-out**: 템플릿 capability 의 `coreProps:false` 면 코어 컨트롤 전무,
 *    `coreProps:['id']` 같은 부분집합이면 그 키만 노출(미선언=기본 전체 = `['id']`).
 *
 * 본 컨트롤은 합성 `EditorControlSpec` 이라 `controls.json`(템플릿 소유)에 정의가 없어도
 * 동작한다 — 코어 SSoT. propKey 충돌 회피: 템플릿 propControls 가 같은 propKey(id)를
 * 또 선언하면 코어가 우선(중복 제거)이며, 기존 `elemId`(→id) 스펙은 코어로 이전(제거).
 *
 * @since engine-v1.50.0
 */

import type { EditorControlSpec } from './specTypes';

/**
 * 코어 제공 속성 컨트롤 키 — `coreControl:{key}` 형태로 모달이 식별.
 *
 * `id` 는 `propValue` apply(props.id 로 흘림). `dataKey`/`isolatedState`/`isolatedScopeId`
 * 는 **노드 최상위 구조키**라 `nodeKey` apply 로 노드
 * 최상위에 패치한다(propValue 로 흘리면 `props.dataKey` 가 돼 엔진이 무시).
 */
export type CorePropKey = 'id' | 'dataKey' | 'isolatedState' | 'isolatedScopeId';

/**
 * 코어 제공 속성 컨트롤 정의 (합성 EditorControlSpec).
 *
 * `id` — 요소 DOM id. text 위젯 → `apply:{propValue, propKey:'id'}`.
 * `dataKey` — 폼 자동 바인딩 컨테이너 연결점. `core-datakey` 위젯 + `nodeKey` apply
 *   (노드 최상위 `node.dataKey`). opt-in capability(폼 컨테이너 전용 — DEFAULT 미포함).
 * `isolatedState` — 격리 영역 켜기. toggle + `nodeKey`(노드 최상위 `node.isolatedState`).
 * `isolatedScopeId` — 격리 영역 이름. `core-scopeid` 위젯(검색 드롭다운+자유입력) +
 *   `nodeKey`(노드 최상위 `node.isolatedScopeId`).
 *
 * 라벨은 코어 i18n `layout_editor.core_props.{key}.*`. 빈 값이면 노드 키 삭제(위젯 규약).
 */
export const CORE_PROP_CONTROLS: Record<CorePropKey, EditorControlSpec> = {
  // `apply` 는 런타임상 객체(`{type,propKey}`)지만 EditorControlSpec.apply 타입이
  // 레거시로 string 이라 controls.json 과 동일하게 객체를 두고 캐스팅한다(recipeEngine
  // 은 `typeof apply === 'object'` 로 객체를 읽음 — 타입과 무관하게 동작).
  id: {
    label: '$t:layout_editor.core_props.id.label',
    // 전용 `core-id` 위젯 — 바인딩(`{{...}}`) 디그레이드 + HTML 안전 문자 sanitize.
    widget: 'core-id',
    placeholder: '$t:layout_editor.core_props.id.placeholder',
    apply: { type: 'propValue', propKey: 'id' } as unknown as string,
  },
  dataKey: {
    label: '$t:layout_editor.core_props.dataKey.label',
    widget: 'core-datakey',
    placeholder: '$t:layout_editor.core_props.dataKey.placeholder',
    // 노드 최상위 패치 — props 오염 방지.
    apply: { type: 'nodeKey', nodeKey: 'dataKey' } as unknown as string,
  },
  isolatedState: {
    label: '$t:layout_editor.core_props.isolatedState.label',
    widget: 'toggle',
    apply: { type: 'nodeKey', nodeKey: 'isolatedState' } as unknown as string,
  },
  isolatedScopeId: {
    label: '$t:layout_editor.core_props.isolatedScopeId.label',
    widget: 'core-scopeid',
    placeholder: '$t:layout_editor.core_props.isolatedScopeId.placeholder',
    apply: { type: 'nodeKey', nodeKey: 'isolatedScopeId' } as unknown as string,
  },
};

/**
 * 코어가 기본 제공하는 속성 키 순서 (미선언 capability 의 기본값).
 *
 * `id` 만 기본 전체 제공. `dataKey` 는 opt-in(폼 컨테이너 capability 가 `coreProps:['id','dataKey']`
 * 명시 시만). `isolatedState`/`isolatedScopeId` 는 별도 "격리 영역" 그룹으로 전 컴포넌트 노출
 * (IsolatedScopeControl 가 직접 렌더 — DEFAULT_CORE_PROP_KEYS 경유 아님).
 */
export const DEFAULT_CORE_PROP_KEYS: CorePropKey[] = ['id'];

/**
 * capability 의 `coreProps` 선언을 해석해 실제 노출할 코어 속성 키 목록을 구한다.
 *
 * - 미선언(undefined) → 기본 전체(`DEFAULT_CORE_PROP_KEYS`).
 * - `false` → 빈 목록(opt-out, 코어 컨트롤 전무).
 * - `string[]` → 그 중 알려진 코어 키만(부분집합 + 미지 키 무시).
 *
 * @param coreProps capability.coreProps 선언값
 * @returns 노출할 코어 속성 키 배열
 */
export function resolveCorePropKeys(coreProps: unknown): CorePropKey[] {
  if (coreProps === false) return [];
  if (Array.isArray(coreProps)) {
    return coreProps.filter((k): k is CorePropKey => k in CORE_PROP_CONTROLS);
  }
  return [...DEFAULT_CORE_PROP_KEYS];
}
