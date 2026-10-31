/**
 * 저장하지 않은 변경이 있는 화면을 떠날 때 확인하는 이탈 가드
 *
 * 레이아웃이 `meta.leave_guard`(마크업 `<meta leave_guard="{{식}}"/>`)를 선언하면, 다른 화면으로 떠나는 순간
 * 그 식을 **한 번, 읽기 전용으로** 평가해 참이면 확인 창을 띄운다. 선언이 없으면 아무것도 묻지 않는다(종전 동작).
 *
 * - 판정 지점: `Router.navigate`(주소를 바꾸기 전) · `Router` 의 popstate(뒤로/앞으로 — 이미 바뀐 주소를 되돌린다) ·
 *   `beforeunload`(새로고침·탭 닫기·같은 탭 전체 이동).
 * - 같은 경로(pathname)로의 이동(쿼리·해시만 바뀜)은 같은 화면이라 묻지 않는다.
 * - 식 평가는 상태를 쓰지 않는다 — 저장소 A·B, `__g7PendingLocalState` 등 어떤 상태 경로에도 쓰지 않고,
 *   렌더 사이클 캐시도 거치지 않는다(`skipCache`).
 * - 한 번 떠나기로 한 화면(확인 승인 또는 우회)은 다음 화면이 그려질 때까지 다시 묻지 않는다 — 리다이렉트 라우트·
 *   인증 리다이렉트가 같은 이동 안에서 두 번 묻지 않게 한다.
 *
 * @since engine-v1.82.0
 */

import { createLogger } from '../utils/Logger';
import { extractSingleBinding, resolveSingleBindingValue } from '../template-engine/BindingShape';
import type { DataBindingEngine } from '../template-engine/DataBindingEngine';

const logger = createLogger('LeaveGuard');

/** 레이아웃이 문구를 주지 않았을 때 쓰는 코어 기본 문구 키 */
export const LEAVE_GUARD_DEFAULT_MESSAGE_KEY = 'core.navigation.leave_confirm';

/** navigateBack·openWindow 우회가 다음 popstate/beforeunload 를 기다리는 최대 시간(ms) */
export const LEAVE_GUARD_ARMED_BYPASS_TTL_MS = 1000;

/**
 * 가드가 읽는 엔진 환경 — 엔진 초기화(G7CoreGlobals)가 주입한다.
 *
 * 라우터가 엔진 본체를 직접 가져오면 순환 의존이 생기므로 값을 꺼내는 함수만 받는다.
 */
export interface LeaveGuardEnvironment {
  /** 지금 화면의 레이아웃 JSON */
  getLayoutJson: () => any;
  /** 지금 화면의 데이터 컨텍스트(데이터 소스·route·query·_computed 등) */
  getDataContext: () => Record<string, any> | null | undefined;
  /** 식 평가 엔진 */
  getBindingEngine: () => DataBindingEngine | null | undefined;
  /** 번역 */
  translate: (key: string) => string;
  /**
   * 아직 상태에 닿지 않은 디바운스 입력이 있는지(읽기 전용 — 실행·정리하지 않는다).
   * 자동바인딩 디바운스 대기 쓰기·디바운스 액션은 브라우저 뒤로·새로고침 순간 상태에 없으므로 식만으로는 못 본다
   */
  hasPendingInput?: () => boolean;
}

let environment: LeaveGuardEnvironment | null = null;
/** 지금 화면을 떠나기로 이미 승인됨 — 다음 화면 커밋까지 유효 */
let leaveApproved = false;
/** 동기 범위 우회 깊이 (navigate 액션의 bypassLeaveGuard) */
let bypassScopeDepth = 0;
/** 1회용 우회 무장 만료 시각 (0 이면 무장 안 됨) */
let armedBypassUntil = 0;
let armedBypassTimer: ReturnType<typeof setTimeout> | null = null;
let unloadListenerInstalled = false;

/**
 * 엔진 환경을 주입합니다.
 *
 * @param env 가드가 읽을 엔진 환경 (null 이면 해제 — 가드는 언제나 거짓)
 */
export function configureLeaveGuard(env: LeaveGuardEnvironment | null): void {
  environment = env;
}

/**
 * 경로 문자열을 비교용 pathname 으로 바꿉니다 (쿼리·해시 제거, 끝 슬래시 정규화).
 *
 * @param path 경로 또는 같은 출처 주소
 * @returns 정규화된 pathname, 해석 불가면 null
 */
function toComparablePathname(path: string): string | null {
  try {
    const pathname = new URL(path, window.location.href).pathname;

    return pathname.length > 1 ? pathname.replace(/\/+$/, '') || '/' : pathname;
  } catch {
    return null;
  }
}

/**
 * 지금 화면 레이아웃의 `meta.leave_guard` 를 읽기 전용으로 평가합니다.
 *
 * @returns 식이 참이면 true. 선언이 없거나 평가에 실패하면 false
 */
function evaluateGuardExpression(): boolean {
  if (!environment) {
    return false;
  }

  const meta = environment.getLayoutJson()?.meta;
  const declared = meta?.leave_guard;
  if (declared === undefined || declared === null || declared === false || declared === '') {
    return false;
  }
  if (declared === true) {
    return true;
  }
  if (typeof declared !== 'string') {
    return false;
  }

  const expr = extractSingleBinding(declared.trim());
  if (expr === null) {
    // 바인딩이 아닌 글자는 선언 오류로 보고 묻지 않는다(사용자를 화면에 가두지 않는다)
    logger.warn('meta.leave_guard 는 {{식}} 이어야 합니다:', declared);

    return false;
  }

  const engine = environment.getBindingEngine();
  if (!engine) {
    return false;
  }

  const G7Core = (window as any).G7Core;
  const baseContext = environment.getDataContext() || {};
  const freshGlobal = G7Core?.state?.get?.() ?? baseContext._global ?? {};
  const freshLocal = G7Core?.state?.getLocal?.() ?? baseContext._local ?? {};
  const context = {
    ...baseContext,
    _global: freshGlobal,
    _local: freshLocal,
    _computed: freshGlobal?._computed ?? baseContext._computed,
  };

  try {
    if (resolveSingleBindingValue(expr, context, engine, { skipCache: true })) {
      return true;
    }
  } catch (error) {
    logger.warn('meta.leave_guard 평가 실패 — 묻지 않고 이동합니다:', error);

    return false;
  }

  // 식이 거짓이어도 디바운스 대기 입력이 있으면 「변경 있음」 — 마지막 입력 뒤 디바운스 시간 안의 브라우저 뒤로·새로고침.
  // 대기분을 실행(flush)하지 않고 존재만 읽는다(상태 쓰기 0) (engine-v1.82.0)
  return hasPendingInput();
}

/**
 * 디바운스 대기 입력이 있는지 읽습니다.
 *
 * @returns 대기분이 있으면 true (환경이 알려 주지 않으면 false)
 */
function hasPendingInput(): boolean {
  try {
    return environment?.hasPendingInput?.() === true;
  } catch {
    return false;
  }
}

/**
 * 지금 떠나면 확인을 물어야 하는지 판정합니다 (확인 창은 띄우지 않는다).
 *
 * @param toPath 이동할 경로. 주면 같은 pathname(쿼리·해시만 다름)일 때 false. 없으면 화면을 떠나는 것으로 본다
 * @param fromPath 떠나는 화면의 경로. 없으면 지금 주소
 * @returns 확인이 필요하면 true
 */
export function shouldConfirmLeave(toPath?: string, fromPath?: string): boolean {
  if (leaveApproved) {
    return false;
  }

  if (typeof toPath === 'string') {
    const to = toComparablePathname(toPath);
    const from = toComparablePathname(fromPath ?? window.location.href);
    if (to !== null && to === from) {
      return false;
    }
  }

  return evaluateGuardExpression();
}

/**
 * 확인 문구를 만듭니다 — `meta.leave_message`(`$t:키` 또는 글자), 없으면 코어 기본 키.
 *
 * @returns 확인 창에 띄울 문구
 */
export function resolveLeaveMessage(): string {
  const raw = environment?.getLayoutJson()?.meta?.leave_message;
  const translate = environment?.translate ?? ((key: string) => key);

  if (typeof raw === 'string' && raw.trim() !== '') {
    const trimmed = raw.trim();

    return trimmed.startsWith('$t:') ? translate(trimmed.slice(3)) : trimmed;
  }

  return translate(LEAVE_GUARD_DEFAULT_MESSAGE_KEY);
}

/**
 * 화면을 떠나도 되는지 결정합니다. 필요하면 확인 창(`window.confirm`)을 띄웁니다.
 *
 * 승인되거나 우회하면 다음 화면 커밋({@link endLeaveApproval})까지 다시 묻지 않는다.
 *
 * @param toPath 이동할 경로 (없으면 화면을 떠나는 것으로 본다)
 * @param options bypass — 확인 없이 떠남(저장 성공 뒤 이동 등), fromPath — 떠나는 화면 경로
 * @returns 떠나도 되면 true
 */
export function requestLeave(toPath?: string, options?: { bypass?: boolean; fromPath?: string }): boolean {
  if (!shouldConfirmLeave(toPath, options?.fromPath)) {
    return true;
  }

  if (options?.bypass === true || bypassScopeDepth > 0) {
    leaveApproved = true;

    return true;
  }

  // 명시적 「취소」(false)만 머무른다 — 대화상자를 띄울 수 없는 환경(jsdom 등 confirm 미구현이 undefined 를 돌려줌)에서
  // 이동이 영영 막히지 않게 한다. 브라우저는 언제나 boolean 을 돌려준다
  if (window.confirm(resolveLeaveMessage()) !== false) {
    leaveApproved = true;

    return true;
  }

  return false;
}

/**
 * 새 화면이 커밋됐다 — 다음 이탈부터 다시 묻는다.
 */
export function endLeaveApproval(): void {
  leaveApproved = false;
}

/**
 * 지금 화면을 떠나면 확인 창이 뜨는지 돌려줍니다 (`G7Core.navigation.isLeaveGuarded()`).
 *
 * @returns 가드가 참이면 true
 */
export function isLeaveGuarded(): boolean {
  return shouldConfirmLeave();
}

/**
 * 콜백 안에서 일어나는 Router.navigate 의 확인을 생략합니다 (동기 범위 한정).
 *
 * navigate 액션의 `bypassLeaveGuard` 용 — 액션 문맥의 navigate 래퍼가 옵션을 버려도 같은 호출 스택 안이면 닿는다.
 *
 * @param fn 실행할 콜백
 * @returns 콜백의 반환값
 */
export function runWithLeaveGuardBypass<T>(fn: () => T): T {
  bypassScopeDepth++;
  try {
    return fn();
  } finally {
    bypassScopeDepth--;
  }
}

/**
 * 다음 popstate 또는 beforeunload 1회의 확인을 생략하도록 무장합니다.
 *
 * navigateBack(`history.back()`)·openWindow(`_self`, 전체 이동)의 `bypassLeaveGuard` 용 — 이동이 비동기로 일어나므로
 * 범위 플래그가 닿지 않는다. 한 번 소비되거나 {@link LEAVE_GUARD_ARMED_BYPASS_TTL_MS} 가 지나면 사라진다
 * (히스토리 첫 항목에서 뒤로 가기처럼 이동이 일어나지 않아도 남지 않는다).
 */
export function armLeaveGuardBypass(): void {
  armedBypassUntil = Date.now() + LEAVE_GUARD_ARMED_BYPASS_TTL_MS;
  if (armedBypassTimer !== null) {
    clearTimeout(armedBypassTimer);
  }
  armedBypassTimer = setTimeout(() => {
    armedBypassUntil = 0;
    armedBypassTimer = null;
  }, LEAVE_GUARD_ARMED_BYPASS_TTL_MS);
}

/**
 * 무장된 1회용 우회를 소비합니다.
 *
 * @returns 무장돼 있었으면 true (소비됨)
 */
export function consumeArmedLeaveGuardBypass(): boolean {
  const armed = armedBypassUntil > 0 && Date.now() <= armedBypassUntil;
  armedBypassUntil = 0;
  if (armedBypassTimer !== null) {
    clearTimeout(armedBypassTimer);
    armedBypassTimer = null;
  }

  return armed;
}

/**
 * beforeunload 처리 — 새로고침·탭 닫기·같은 탭 전체 이동.
 *
 * 브라우저는 사용자 문구를 보여 주지 않고 자체 문구를 띄운다.
 *
 * @param event beforeunload 이벤트
 */
export function handleLeaveGuardBeforeUnload(event: BeforeUnloadEvent): void {
  if (consumeArmedLeaveGuardBypass()) {
    return;
  }
  if (!shouldConfirmLeave()) {
    return;
  }

  event.preventDefault();
  // 구형 브라우저는 returnValue 가 있어야 묻는다
  event.returnValue = '';
}

/**
 * beforeunload 리스너를 한 번만 설치합니다 (Router 생성 시).
 */
export function installLeaveGuardUnloadListener(): void {
  if (unloadListenerInstalled || typeof window === 'undefined') {
    return;
  }
  unloadListenerInstalled = true;
  window.addEventListener('beforeunload', handleLeaveGuardBeforeUnload);
}

/**
 * 시험용 — 모듈 상태를 처음으로 되돌립니다.
 *
 * @internal
 */
export function resetLeaveGuardForTests(): void {
  environment = null;
  leaveApproved = false;
  bypassScopeDepth = 0;
  armedBypassUntil = 0;
  if (armedBypassTimer !== null) {
    clearTimeout(armedBypassTimer);
    armedBypassTimer = null;
  }
  if (unloadListenerInstalled && typeof window !== 'undefined') {
    window.removeEventListener('beforeunload', handleLeaveGuardBeforeUnload);
  }
  unloadListenerInstalled = false;
}
