import { requestPaymentHandler } from './requestPayment';

/**
 * 토스페이먼츠 플러그인 핸들러 맵
 *
 * ActionDispatcher에 등록될 핸들러 목록입니다.
 * 네임스페이스 접두사(sirsoft-tosspayments.)는 index.ts에서 자동으로 추가됩니다.
 */
export const handlerMap: Record<string, (...args: unknown[]) => unknown> = {
    requestPayment: requestPaymentHandler,
} as const;
