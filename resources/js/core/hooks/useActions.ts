/**
 * useActions Hook
 *
 * 템플릿 컴포넌트에서 ActionDispatcher를 쉽게 사용할 수 있도록 하는 훅입니다.
 * 레이아웃 JSON의 액션 정의와 동일한 형태로 액션을 실행할 수 있습니다.
 *
 * @example
 * ```tsx
 * import { useActions } from '@g7/core/hooks';
 *
 * const MyComponent = () => {
 *   const dispatch = useActions();
 *
 *   const handleClick = () => {
 *     dispatch({
 *       handler: 'navigate',
 *       params: { path: '/admin/users/1/edit' }
 *     });
 *   };
 *
 *   const handleDelete = async () => {
 *     await dispatch({
 *       handler: 'apiCall',
 *       target: '/api/admin/users/1',
 *       params: { method: 'DELETE' },
 *       onSuccess: [
 *         { handler: 'toast', params: { type: 'success', message: '삭제되었습니다' } },
 *         { handler: 'navigate', params: { path: '/admin/users' } }
 *       ]
 *     });
 *   };
 * };
 * ```
 *
 * @module hooks/useActions
 */

import { useCallback, useMemo } from 'react';
import { createLogger } from '../utils/Logger';

const logger = createLogger('useActions');

/**
 * 액션 정의 인터페이스 (ActionDispatcher의 ActionDefinition과 동일)
 */
export interface ActionDefinition {
  /** 액션 핸들러 이름 */
  handler: string;
  /** 액션 타겟 (URL, API 엔드포인트, 모달 ID 등) */
  target?: string;
  /** 액션 파라미터 */
  params?: Record<string, any>;
  /** 액션 성공 시 실행할 후속 액션 */
  onSuccess?: ActionDefinition | ActionDefinition[];
  /** 액션 실패 시 실행할 후속 액션 */
  onError?: ActionDefinition | ActionDefinition[];
  /** 확인 메시지 표시 여부 */
  confirm?: string;
  /** switch 핸들러용 케이스 정의 */
  cases?: Record<string, ActionDefinition>;
  /** 인증 필요 여부 (apiCall 핸들러에서 Bearer 토큰 포함 여부) */
  auth_required?: boolean;
}

/**
 * 액션 실행 결과
 */
export interface ActionResult {
  success: boolean;
  data?: any;
  error?: Error;
}

/**
 * dispatch 함수 타입
 */
export type DispatchFunction = (action: ActionDefinition) => Promise<ActionResult>;

/**
 * TemplateApp에서 ActionDispatcher와 컨텍스트를 가져옵니다.
 */
function getDispatcherContext() {
  const templateApp = (window as any).__templateApp;

  if (!templateApp) {
    logger.warn('TemplateApp이 초기화되지 않았습니다.');
    return null;
  }

  const actionDispatcher = templateApp.getActionDispatcher?.();
  const router = templateApp.getRouter?.();
  const globalState = templateApp.getGlobalState?.();
  const setGlobalState = templateApp.setGlobalState?.bind(templateApp);

  if (!actionDispatcher) {
    logger.warn('ActionDispatcher를 찾을 수 없습니다.');
    return null;
  }

  return {
    actionDispatcher,
    context: {
      navigate: router ? (path: string) => router.navigate(path) : undefined,
      setState: setGlobalState,
      state: globalState,
      data: globalState,
    },
  };
}

/**
 * ActionDispatcher를 통해 액션을 실행할 수 있는 dispatch 함수를 반환하는 훅
 *
 * @returns dispatch 함수
 *
 * @example
 * ```tsx
 * const dispatch = useActions();
 *
 * // navigate
 * dispatch({ handler: 'navigate', params: { path: '/admin/dashboard' } });
 *
 * // apiCall with onSuccess
 * dispatch({
 *   handler: 'apiCall',
 *   target: '/api/admin/users',
 *   params: { method: 'GET' },
 *   onSuccess: [{ handler: 'toast', params: { type: 'success', message: '성공' } }]
 * });
 *
 * // openModal
 * dispatch({ handler: 'openModal', target: 'confirm_modal' });
 *
 * // setState
 * dispatch({
 *   handler: 'setState',
 *   params: { target: 'global', selectedIds: [1, 2, 3] }
 * });
 * ```
 */
export function useActions(): DispatchFunction {
  const dispatch = useCallback(async (action: ActionDefinition): Promise<ActionResult> => {
    const dispatcherContext = getDispatcherContext();

    if (!dispatcherContext) {
      return {
        success: false,
        error: new Error('ActionDispatcher를 사용할 수 없습니다. TemplateApp이 초기화되었는지 확인하세요.'),
      };
    }

    const { actionDispatcher, context } = dispatcherContext;

    try {
      // ActionDispatcher의 내부 executeAction 메서드 호출
      // handleAction은 이벤트 핸들러용이므로, 직접 액션을 실행하는 방식 사용
      const result = await actionDispatcher.dispatchAction(action, context);
      return result;
    } catch (error) {
      logger.error('액션 실행 오류:', error);
      return {
        success: false,
        error: error instanceof Error ? error : new Error(String(error)),
      };
    }
  }, []);

  return dispatch;
}

/**
 * 전역에서 사용할 수 있는 dispatch 함수 (훅 외부에서 사용)
 *
 * @example
 * ```tsx
 * import { dispatchAction } from '@g7/core/hooks';
 *
 * // 이벤트 핸들러나 비동기 콜백에서 사용
 * const handleExternalEvent = () => {
 *   dispatchAction({ handler: 'navigate', params: { path: '/admin' } });
 * };
 * ```
 */
export async function dispatchAction(action: ActionDefinition): Promise<ActionResult> {
  const dispatcherContext = getDispatcherContext();

  if (!dispatcherContext) {
    return {
      success: false,
      error: new Error('ActionDispatcher를 사용할 수 없습니다.'),
    };
  }

  const { actionDispatcher, context } = dispatcherContext;

  try {
    const result = await actionDispatcher.dispatchAction(action, context);
    return result;
  } catch (error) {
    logger.error('액션 실행 오류:', error);
    return {
      success: false,
      error: error instanceof Error ? error : new Error(String(error)),
    };
  }
}

export default useActions;