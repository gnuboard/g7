// E2E: tests/Playwright/specs/sso-*.spec.ts 가 이 진입점으로 실린 핸들러를 탄다(로그인 흐름). 그 밖의 화면은 F1 Chrome MCP 계획서 §10 실측, Vitest 로 덮음
/**
 * SSO 연동 플러그인 프론트 진입점 — 핸들러를 코어 액션 디스패처에 등록한다.
 *
 * 화면(모달, 버튼)은 레이아웃 확장(`resources/extensions/*.g7.html`)이 그리고, 이 번들은 레이아웃이 부를 핸들러만 싣는다.
 */
import { handlerMap } from './handlers';

/** 플러그인 식별자 — 핸들러 이름 앞에 붙는다. */
const PLUGIN_IDENTIFIER = 'sirsoft-sso';

/**
 * 핸들러를 코어 액션 디스패처에 등록합니다.
 *
 * @returns 등록한 핸들러 수 (디스패처가 아직 없으면 0)
 */
function registerHandlers(): number {
  const getDispatcher = (window as any).G7Core?.getActionDispatcher;
  if (typeof getDispatcher !== 'function') {
    return 0;
  }

  const dispatcher = getDispatcher();
  if (!dispatcher || typeof dispatcher.registerHandler !== 'function') {
    return 0;
  }

  let count = 0;
  for (const [name, handler] of Object.entries(handlerMap)) {
    dispatcher.registerHandler.call(dispatcher, `${PLUGIN_IDENTIFIER}.${name}`, handler, {
      category: 'plugin',
      source: PLUGIN_IDENTIFIER,
    });
    count++;
  }

  return count;
}

/**
 * 디스패처가 준비될 때까지 잠시 다시 시도하며 등록합니다 (번들이 코어보다 먼저 실행될 때).
 */
function startHandlerRegistration(): void {
  if (registerHandlers() > 0) {
    return;
  }

  let retries = 0;
  const interval = setInterval(() => {
    retries++;
    if (registerHandlers() > 0 || retries >= 50) {
      clearInterval(interval);
      if (retries >= 50) {
        console.warn(`[${PLUGIN_IDENTIFIER}] ActionDispatcher not available after timeout`);
      }
    }
  }, 100);
}

/**
 * 코어 재초기화(로케일 전환) 때 불리는 진입점 — 새 디스패처에 핸들러를 다시 꽂습니다.
 */
function initPlugin(): void {
  registerHandlers();
}

if (typeof window !== 'undefined') {
  // 재등록 진입점 — 이름 고정 (TemplateApp.reinitializePluginHandlers).
  (window as unknown as Record<string, unknown>).__SirsoftSso = { identifier: PLUGIN_IDENTIFIER, initPlugin };

  try {
    startHandlerRegistration();
  } catch (error) {
    console.warn(`[${PLUGIN_IDENTIFIER}] handler registration failed`, error);
  }
}

export { initPlugin };
