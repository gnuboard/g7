import type React from 'react';

/**
 * 레이아웃 편집기 주입 속성 (editor attributes)
 *
 * 편집 모드에서 코어 `DynamicRenderer` 가 각 nesting 컴포넌트에 단일 prop 으로
 * 주입하는 DOM 표식/이벤트 핸들러 묶음입니다. 컴포넌트는 이 객체를 받아
 * **시각적 루트 요소**에 그대로 spread(`{...editorAttrs}`) 해야 합니다.
 *
 * - 사용자 페이지(비편집)에서는 주입되지 않으므로 `editorAttrs === undefined`,
 *   `{...undefined}` 는 no-op → DOM 구조/속성 불변 (사용자 페이지 ↔ 프리뷰 패리티 유지).
 * - 포함 내용: `data-editor-*` 표식(드롭 슬롯/드래그 핸들 DOM 쿼리용) + 선택/hover 핸들러.
 * - 도메인 prop 은 컴포넌트가 명시 구조분해하므로 이 객체로 누출되지 않습니다.
 *
 */
export interface EditorAttrs {
  'data-editor-id'?: string;
  'data-editor-name'?: string;
  'data-editor-type'?: string;
  'data-editor-path'?: string;
  onClick?: (event: React.MouseEvent) => void;
  onMouseMove?: (event: React.MouseEvent) => void;
  onMouseLeave?: (event: React.MouseEvent) => void;
  /** 미래 확장 여지 (현재 주입 키 외 임의 data-/aria- 속성 허용) */
  [key: string]: unknown;
}

/**
 * 액션 정의 (엔진 `ActionDefinition` 의 템플릿측 최소 표현)
 *
 * 엔진은 핸들러를 `handler(action, context)` 로 호출하며, 레이아웃이 선언한 값은
 * `action.params` 에 해석되어 들어온다. 첫 인자를 params 로 받는 형태로 작성하면
 * 레이아웃이 넘긴 값이 전부 `undefined` 가 된다.
 */
export interface TemplateActionDefinition {
  /** 핸들러 이름 */
  handler: string;
  /** 해석된 파라미터 (레이아웃 `params` 의 바인딩 해석 결과) */
  params?: Record<string, any>;
  /** 해석된 타겟 */
  target?: string;
  [key: string]: unknown;
}

/**
 * 핸들러 컨텍스트 — 엔진 `ActionContext` 와 같은 모양이다.
 *
 * 상태 조회/설정 API 는 컨텍스트가 아니라 전역 `G7Core` 가 제공한다:
 * - `G7Core.state.get()` / `G7Core.state.set()` — 전역 상태
 * - `G7Core.state.getLocal()` / `G7Core.state.setLocal()` — 로컬(`_local`) 상태
 * - `AuthManager.getInstance().getUser()` — 현재 사용자
 *
 * `context.setState(updates)` 는 **객체 하나**를 받는다. 스코프 인자를 앞에 두는
 * `setState('global', {...})` 형태로 호출하면 문자열 `'global'` 이 로컬 상태에
 * 전개되어, 오류 없이 상태만 오염된다.
 */
export interface HandlerContext {
  /** 데이터 컨텍스트 (`_global`, `_local`, `_computed` 등) */
  data?: Record<string, any>;
  /** 이벤트 객체 */
  event?: Event;
  /** 컴포넌트 props */
  props?: Record<string, any>;
  /** 현재 컴포넌트 로컬 상태 (저장소 A 스냅샷) */
  state?: Record<string, any>;
  /**
   * 로컬 상태 갱신 함수.
   *
   * 엔진은 커스텀 핸들러에게 저장소 A/B 를 함께 갱신하는 writer 를 넘긴다
   * (engine-v1.63.5). 그 이전 엔진에서도 동작하도록 하려면
   * `G7Core.state.setLocal()` 을 쓰는 것이 가장 안전하다.
   */
  setState?: (updates: Record<string, any>) => void;
  /** 라우터 네비게이션 함수 */
  navigate?: (path: string, options?: { replace?: boolean; state?: any }) => void;
  /** 격리 상태 컨텍스트 (isolatedState 속성이 있는 컴포넌트에서 주입) */
  isolatedContext?: {
    state: Record<string, any>;
    mergeState: (updates: Record<string, any>, mergeMode?: 'replace' | 'shallow' | 'deep') => void;
  };
  [key: string]: unknown;
}

/**
 * 핸들러 함수 타입 — 엔진 `ActionHandler` 와 같은 시그니처다.
 */
export type HandlerFunction = (
  action: TemplateActionDefinition,
  context: HandlerContext
) => any | Promise<any>;
