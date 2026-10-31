/**
 * 창 편집 미리보기에서 창 노드에 넘길 `$parent` 맥락을 고른다.
 *
 * 런타임은 창을 연 레이아웃의 데이터 맥락을 `parentDataContext` 로 창에 넘기고(template-engine.ts 의 모달 렌더),
 * 창은 그 맥락을 `{{$parent._local.*}}` 로 읽는다. 편집기는 창 편집 때 창 노드를 호스트 컴포넌트 뒤에 루트 형제로
 * 덧붙여 그리므로(useLayoutDocument 의 `modalContext.editIndexPath`), 같은 맥락을 직접 넘겨야 상태 표본의 `_local`
 * 이 창에 닿는다.
 *
 * @since engine-v1.76.0
 */

import type { EditMode } from '../LayoutEditorContext';
import type { DataBindingEngine } from '../../DataBindingEngine';
import { evaluateComputedDefinitions } from '../../helpers/ComputedEvaluator';

/** 창 노드가 받는 `$parent` — 런타임 openModal 이 레이아웃 맥락 스택에 쌓는 모양과 같다. */
export interface ModalEditParentContext {
  _local: Record<string, unknown> | undefined;
  _global: Record<string, unknown> | undefined;
  _computed: Record<string, unknown>;
}

/**
 * 루트 렌더 맥락에서 창 노드가 받을 `$parent` 를 만든다.
 *
 * 런타임 openModal 은 `{_local, _global, _computed}` 를 쌓는다(ActionDispatcher 의 layoutContextStack). 데이터소스까지
 * 넘기면 실제 화면에서는 비는 `$parent.*` 가 편집기에서만 보이므로 이 세 키만 담는다. 루트 맥락에는 computed 값이 아니라
 * 정의(`_computedDefinitions`)만 있어, 렌더와 같은 공용 평가기로 호스트 레이아웃의 값을 계산한다. 이 값이 빠지면
 * `{{$parent._computed.*}}` 를 읽는 창(게시판 일괄 적용 창의 게시판 이름 등)이 편집기에서만 비어 보인다.
 *
 * @param rootContext 루트 컴포넌트에 넘기는 데이터 맥락
 * @param engine 표현식 평가에 쓸 바인딩 엔진
 * @returns 창 노드의 `$parent` 맥락
 */
export function buildModalEditParentContext(
  rootContext: Record<string, any>,
  engine: DataBindingEngine,
): ModalEditParentContext {
  const definitions = rootContext._computedDefinitions as Record<string, unknown> | undefined;
  const computed = definitions && Object.keys(definitions).length > 0
    ? evaluateComputedDefinitions(definitions, rootContext, engine, { onError: 'undefined' })
    : {};

  return {
    _local: rootContext._local,
    _global: rootContext._global,
    _computed: computed,
  };
}

/**
 * 창 편집 모드에서 덧붙인 창 노드(편집 경로의 첫 인덱스)이면 루트 렌더 맥락을, 아니면 undefined 를 돌려준다.
 *
 * @param editMode 편집 모드
 * @param modalContext 창 편집 문서 맥락(편집 경로 포함)
 * @param index 루트 컴포넌트 인덱스
 * @param rootContext 루트 컴포넌트에 넘기는 데이터 맥락
 * @returns 창 노드의 `$parent` 맥락 또는 undefined
 */
export function modalEditParentContext<T>(
  editMode: EditMode | string,
  modalContext: { editIndexPath?: number[] } | undefined | null,
  index: number,
  rootContext: T,
): T | undefined {
  if (editMode !== 'modal') return undefined;
  const modalIndex = modalContext?.editIndexPath?.[0];
  return typeof modalIndex === 'number' && modalIndex === index ? rootContext : undefined;
}
