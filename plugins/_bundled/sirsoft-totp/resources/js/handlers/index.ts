import { copyToClipboardHandler } from './copyToClipboard';

/**
 * 이 플러그인이 레이아웃에 내주는 named action 목록.
 *
 * 레이아웃에서는 `sirsoft-totp.{키}` 로 부른다 — 등록할 때 플러그인 식별자가 앞에 붙는다
 * (`index.ts` 의 `registerHandlers()`).
 */
export const handlerMap: Record<string, (...args: never[]) => unknown> = {
    copyToClipboard: copyToClipboardHandler,
} as const;
