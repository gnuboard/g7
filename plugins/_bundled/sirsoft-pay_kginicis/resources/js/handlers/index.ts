import { requestPaymentHandler } from './requestPayment';
import { copyToClipboardHandler } from './copyToClipboard';

export const handlerMap: Record<string, (...args: unknown[]) => unknown> = {
    requestPayment: requestPaymentHandler,
    copyToClipboard: copyToClipboardHandler,
} as const;
