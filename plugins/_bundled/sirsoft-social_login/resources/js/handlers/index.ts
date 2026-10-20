import { copyToClipboardHandler, openTestLoginHandler } from './adminTools';
import { startLinkHandler, startReauthHandler } from './providerRedirect';

/**
 * 이 플러그인이 레이아웃에 내주는 named action 목록.
 *
 * 레이아웃에서는 `sirsoft-social_login.{키}` 로 부른다 — 등록할 때 플러그인 식별자가 앞에 붙는다(`index.ts`).
 */
export const handlerMap: Record<string, (...args: never[]) => unknown> = {
  startLink: startLinkHandler,
  startReauth: startReauthHandler,
  copyToClipboard: copyToClipboardHandler,
  openTestLogin: openTestLoginHandler,
} as const;
