import { requestPaymentHandler } from './requestPayment';
import { copyToClipboardHandler } from './copyToClipboard';
import { openReceiptHandler } from './openReceipt';

export const handlerMap: Record<string, (...args: unknown[]) => unknown> = {
    requestPayment: requestPaymentHandler,
    copyToClipboard: copyToClipboardHandler,
    openReceipt: openReceiptHandler,
} as const;
