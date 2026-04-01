/**
 * 핸들러 컨텍스트 타입 정의 (테스트 호환용)
 *
 * @deprecated 실제 핸들러는 ActionDispatcher의 (action, context) 시그니처를 따릅니다.
 * 이 타입은 테스트 목적으로만 유지됩니다.
 *
 * 실제 핸들러에서는 다음 API를 사용합니다:
 * - G7Core.state.set() - 전역 상태 설정
 * - G7Core.state.get() - 전역 상태 조회
 * - AuthManager.getInstance().getUser() - 현재 사용자 확인
 */
export interface HandlerContext {
  /** 상태 설정 함수 */
  setState: (scope: 'local' | 'global' | '_global', data: Record<string, any>) => void;
  /** 상태 조회 함수 */
  getState: (path: string) => any;
  /** 통화 포맷팅 함수 */
  formatCurrency: (amount: number, currency?: string) => string;
  /** 다중 통화 계산 함수 */
  calculateMultiCurrency: (amount: number) => Record<string, { value: number; formatted: string }>;
  /** 라우터 네비게이션 함수 */
  navigate: (path: string) => void;
  /** 토스트 메시지 표시 함수 */
  toast: (message: string, type?: 'success' | 'error' | 'warning' | 'info') => void;
  /** API 호출 함수 */
  apiCall: (url: string, options?: RequestInit) => Promise<any>;
  /** 번역 함수 */
  t: (key: string, params?: Record<string, string | number>) => string;
  /** 전역 설정 */
  settings: Record<string, any>;
  /** 현재 사용자 */
  currentUser: any;
}

/**
 * 핸들러 함수 타입 (테스트 호환용)
 *
 * @deprecated 실제 핸들러는 ActionDispatcher의 ActionHandler 타입을 따릅니다:
 * (action: ActionDefinition, context: ActionContext) => void | Promise<void>
 */
export type HandlerFunction = (params: any, context: HandlerContext) => void | Promise<void>;
