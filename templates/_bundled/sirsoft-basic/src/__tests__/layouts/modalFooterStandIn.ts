/**
 * 사용자 템플릿 Modal 대역용 고정 하단 렌더 (#869 2라운드)
 *
 * 레이아웃 시험은 템플릿 패키지의 React 와 코어 엔진의 React 가 다른 사본으로 풀려 진짜 Modal 을 그리지 못하고 대역을 쓴다.
 * 사용자 Modal 이 `footer` 를 그리게 되어(확인 창 단추가 고정 하단으로 옮김) 대역도 실물(components/composite/Modal.tsx)과
 * 같은 규칙으로 고정 하단을 그린다 — 엔진 렌더러로, Modal 노드의 확장 데이터 컨텍스트(`__componentContext.parentDataContext`)와
 * 컴포넌트 컨텍스트를 그대로, 렌더 환경(renderEnvRef) 우선. 훅을 쓰지 않으므로 어느 React 사본으로 만들어도 된다.
 *
 * @param props 대역이 받은 창 속성
 * @returns 고정 하단 요소(footer 가 없으면 null)
 */
export function renderModalFooterStandIn(props: any): any {
  const footer = props?.footer;
  if (!Array.isArray(footer) || footer.length === 0) return null;

  const CoreReact = (window as any).React;
  const DynamicRenderer = (window as any).__DynamicRenderer;
  if (!CoreReact || !DynamicRenderer) return null;

  const g7 = (window as any).G7Core;
  const env = props.__componentContext?.renderEnvRef?.current ?? null;
  const dataContext = props.__dataContext ?? props.__componentContext?.parentDataContext ?? {};

  return CoreReact.createElement(
    'div',
    { className: 'modal-footer-bar' },
    footer.map((componentDef: any, index: number) =>
      CoreReact.createElement(DynamicRenderer, {
        key: `footer-${index}`,
        componentDef,
        dataContext,
        parentComponentContext: props.__componentContext,
        translationContext: props.__translationContext,
        registry: env?.componentRegistry ?? g7?.getComponentRegistry?.(),
        bindingEngine: env?.bindingEngine ?? g7?.getDataBindingEngine?.(),
        translationEngine: env?.translationEngine ?? g7?.getTranslationEngine?.(),
        actionDispatcher: env?.actionDispatcher ?? g7?.getActionDispatcher?.(),
        isRootRenderer: false,
        isEditMode: env?.isEditMode,
      })
    )
  );
}
