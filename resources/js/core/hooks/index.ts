/**
 * 그누보드7 템플릿 엔진 훅 모듈
 *
 * 템플릿 컴포넌트에서 사용할 수 있는 React 훅들을 제공합니다.
 *
 * @module hooks
 */

export { useActions, dispatchAction } from './useActions';
export type { ActionDefinition, ActionResult, DispatchFunction } from './useActions';

export {
  useControllableState,
  shallowArrayEqual,
  shallowObjectEqual,
} from './useControllableState';
export type { UseControllableStateOptions } from './useControllableState';