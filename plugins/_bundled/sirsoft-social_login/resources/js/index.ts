/**
 * 소셜 로그인 플러그인 프론트 엔트리.
 *
 * 1. 컴포넌트 `sirsoft-social_login.ProviderButtons`(제공자 버튼)·`ProviderMark`(연결 목록 줄의 제공자 심볼)를 코어
 *    레지스트리에 등록한다 — 로그인·가입·마이페이지 확장이 쓴다.
 * 2. named action `sirsoft-social_login.startLink`·`startReauth`(로그인 회원의 왕복 시작)와 관리자 설정 화면 도구
 *    `copyToClipboard`(주소 복사)·`openTestLogin`(브라우저 로그인 테스트 창)을 엔진 디스패처에 등록한다.
 *
 * 로그인 토큰을 읽지 않고, 코어 내부 앱 객체를 쓰지 않으며, `fetch` 를 덮어쓰지 않는다. API 는 코어 API
 * 클라이언트로만 부른다.
 */
import '../css/buttons.css';
import { ProviderButtons } from './components/composite/ProviderButtons';
import { ProviderMark } from './components/basic/ProviderMark';
import { handlerMap } from './handlers';

export { ProviderButtons, type ProviderButtonsProps } from './components/composite/ProviderButtons';
export { ProviderMark, type ProviderMarkProps } from './components/basic/ProviderMark';

/** 플러그인 식별자 — 컴포넌트·핸들러 이름 앞에 붙는다. */
const PLUGIN_IDENTIFIER = 'sirsoft-social_login';

/**
 * 컴포넌트를 코어 레지스트리에 등록합니다.
 *
 * @returns 등록했으면 true
 */
function registerComponents(): boolean {
  const registry = (window as any).G7Core?.getComponentRegistry?.();

  if (!registry?.registerExtensionComponent) {
    return false;
  }

  registry.registerExtensionComponent(`${PLUGIN_IDENTIFIER}.ProviderButtons`, ProviderButtons, {
    name: `${PLUGIN_IDENTIFIER}.ProviderButtons`,
    type: 'composite',
  });
  registry.registerExtensionComponent(`${PLUGIN_IDENTIFIER}.ProviderMark`, ProviderMark, {
    name: `${PLUGIN_IDENTIFIER}.ProviderMark`,
    type: 'basic',
  });

  return true;
}

/**
 * named action 을 엔진 디스패처에 꽂습니다.
 *
 * @returns 등록한 핸들러 수 — 디스패처가 아직 없으면 0
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

  // 메서드를 떼어 부르지 않는다 — registerHandler 는 내부에서 this 를 쓴다.
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
 * 디스패처가 준비될 때까지 기다렸다가 핸들러를 등록합니다 (100ms × 50 = 5초).
 *
 * @returns 반환값 없음
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
 *
 * @returns 반환값 없음
 */
function initPlugin(): void {
  registerHandlers();
}

if (typeof window !== 'undefined') {
  // 재등록 진입점 — 이름 고정 (TemplateApp.reinitializePluginHandlers).
  (window as unknown as Record<string, unknown>).__SirsoftSocialLogin = { identifier: PLUGIN_IDENTIFIER, initPlugin };

  registerComponents();
  try {
    startHandlerRegistration();
  } catch (error) {
    console.warn(`[${PLUGIN_IDENTIFIER}] handler registration failed`, error);
  }
}

export { initPlugin };
