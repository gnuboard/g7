import { requestPaymentHandler } from './requestPayment';
import { setPaymentMethodHandler } from './setPaymentMethod';

export const handlerMap: Record<string, (...args: unknown[]) => unknown> = {
    requestPayment: requestPaymentHandler,
    setPaymentMethod: setPaymentMethodHandler,
} as const;
