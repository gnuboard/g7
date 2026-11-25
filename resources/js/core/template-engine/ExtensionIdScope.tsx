/**
 * 확장점 주입 조각의 DOM id 스코프 (engine-v1.99.0, #408)
 *
 * 같은 확장 조각이 한 화면의 확장점 여러 곳에 주입되면(게시글 본문과 답글마다의 `html_content`,
 * 알림 정의 행마다의 `notification_row_footer`, 회원 항목 반복 안의 `address_search_slot` 등)
 * 조각 안 노드의 정적 id 가 그대로 DOM id 로 나가 같은 id 가 화면에 여러 개 생겼다(HTML 스펙 위반 —
 * `getElementById`·`<label for>`·`aria-*` 가 첫 요소에만 걸린다).
 *
 * 그래서 확장점 호스트 아래에서 그려지는 노드의 **보간 없는 정적 노드 id** 는 DOM 에 쓸 때
 * `{원래 id}__{가장 가까운 호스트 확장점의 DOM id}` 로 바꾼다(결정 D2). 선례는 SlotContainer 의
 * `scopeSlotChildDef` 다(#410, `{id}__{컨테이너 id}`).
 *
 * 바꾸지 않는 것(결정 D3)
 * - `{{…}}` 로 보간한 id — 작성자가 이미 행마다 다르게 만든 값이다.
 * - 명시한 `props.id`(g7.html 의 `id="…"`) — DynamicRenderer 의 `props.id ?? resolvedComponentId` 에서 우선한다.
 *   DOM 으로 정확히 찾아야 하는 조각은 이 명시 id 를 쓴다(예: ckeditor5 편집기, GDPR 쿠키 배너).
 * - 엔진 내부 식별(findById·slot 등록·DevTools·편집기 editorPath) — 원본 `componentDef.id` 를 계속 쓴다.
 *
 * 전제: 호스트 확장점의 id 가 화면 안에서 유일하다(감사 규칙 `layout-extension-point-host-id` 가 호스트 id 를
 * 강제하고, 반복 안 호스트는 `layout-iteration-static-id` 가 보간을 강제한다). 호스트 id 가 없으면 스코프하지
 * 않고 바깥 scope 를 그대로 물려준다.
 *
 * @since engine-v1.99.0
 */

import React, { createContext, useContext } from 'react';

/**
 * 가장 가까운 확장점 호스트의 DOM id. 확장점 밖이면 undefined.
 */
export const ExtensionIdScopeContext = createContext<string | undefined>(undefined);

/**
 * 노드의 원본 id 에 확장점 scope 를 붙인 DOM id 를 돌려준다.
 *
 * rawId 가 비어 있지 않은 문자열이고 `{{` 를 포함하지 않으며 scope 가 있을 때만 `${rawId}__${scope}` 다.
 * 그 밖에는 rawId 를 그대로 돌려준다.
 *
 * @param rawId 레이아웃 노드의 원본 id
 * @param scope 가장 가까운 확장점 호스트의 DOM id
 * @returns DOM 에 쓸 id
 */
export function scopeExtensionNodeId<T>(rawId: T, scope: string | undefined): T | string {
  if (typeof rawId !== 'string' || rawId === '' || rawId.includes('{{') || !scope) {
    return rawId;
  }
  return `${rawId}__${scope}`;
}

/**
 * 반복 컨텍스트(renderItemChildren — DataGrid·CardGrid 셀 등)의 확장점 호스트.
 *
 * 셀 경로는 컴포넌트가 직접 React 요소를 만들므로 DynamicRenderer 의 scope 값을 옵션으로 실어 나를 수 없다.
 * 호스트를 이 컴포넌트로 그리면 React 트리의 `ExtensionIdScopeContext` 를 그대로 상속해, 정적 호스트 id 에
 * 바깥 scope 를 붙이고 자기 id 로 자식에게 새 scope 를 연다.
 *
 * @param props.hostId 셀 컨텍스트로 해석한 호스트 id
 * @param props.isStaticId 원본 id 가 보간 없는 정적 id 인지
 * @param props.className 호스트 className
 * @param props.attrs 호스트 DOM 속성(data-*, aria-*)
 * @param props.children 주입 조각
 */
export function CellExtensionHost({
  hostId,
  isStaticId,
  className,
  attrs,
  children,
}: {
  hostId: string | undefined;
  isStaticId: boolean;
  className?: string;
  attrs?: Record<string, unknown>;
  children?: React.ReactNode;
}): React.ReactElement {
  const parentScope = useContext(ExtensionIdScopeContext);
  const domId = isStaticId ? (scopeExtensionNodeId(hostId, parentScope) as string | undefined) : hostId;
  const element = React.createElement('div', { id: domId, className, ...attrs }, children);
  if (!domId) {
    return element;
  }
  return React.createElement(ExtensionIdScopeContext.Provider, { value: domId }, element);
}
