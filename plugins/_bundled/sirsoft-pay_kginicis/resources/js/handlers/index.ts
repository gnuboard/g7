import { requestPaymentHandler } from './requestPayment';

export const handlerMap: Record<string, (...args: unknown[]) => unknown> = {
    requestPayment: requestPaymentHandler,
} as const;
