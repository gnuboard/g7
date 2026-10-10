import { requestPaymentHandler } from './requestPayment';
import { setPaymentMethodHandler } from './setPaymentMethod';
import { copyToClipboardHandler } from './copyToClipboard';

export const handlerMap: Record<string, (...args: unknown[]) => unknown> = {
    requestPayment: requestPaymentHandler,
    setPaymentMethod: setPaymentMethodHandler,
    copyToClipboard: copyToClipboardHandler,
} as const;
