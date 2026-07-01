/**
 * GDPR 사용자 의사 표명 추적기 (userInitiatedTracker)
 *
 * 마지막 사용자 인터랙션 (click / keydown / change / submit) 시각을 기록하여
 * 직후 발생하는 storage/cookie 쓰기가 "사용자 의사에 의한 것" 인지 판정.
 *
 * WP29 Opinion 04/2012 §3.6 + EDPB Guidelines 2/2023 §16 의 "user-initiated
 * preference" 면제 적용 — functional 카테고리 미동의 상태에서도 사용자가 직접
 * 트리거한 설정 저장 (다크모드 토글, 통화 변경 등) 은 허용해야 함. 그렇지 않으면
 * 메뉴 클릭 시점에 setItem 차단되어 UX 가 무너짐.
 *
 * 판정 기준:
 *   - 마지막 인터랙션 시각으로부터 USER_INITIATED_THRESHOLD_MS 이내 → user-initiated
 *   - window.event 로 현재 이벤트 객체가 살아있고 isTrusted=true → user-initiated 폴백
 *
 * 보수적 윈도우 (500ms) — Page Visibility / setTimeout 으로 지연된 storage 쓰기는
 * 비-사용자 트리거로 분류하여 functional_allow_user_initiated=false 와 동일 처리.
 *
 * 본 모듈은 install 시 capture-phase 리스너를 1회 등록. blocker / interceptor
 * 보다 먼저 install 되어야 정확한 타임스탬프를 잡을 수 있음.
 *
 * @module sirsoft-gdpr/userInitiatedTracker
 */

/**
 * "사용자 의사" 로 인정할 인터랙션 후 경과 시간 임계값 (ms).
 *
 * 너무 짧으면: framework 의 micro-task / re-render 후 setItem 누락
 * 너무 길면: 인터랙션과 무관한 background 쓰기까지 통과
 *
 * 500ms 는 일반적 SPA 의 click → setState → effect → localStorage 체인을 커버.
 */
export const USER_INITIATED_THRESHOLD_MS = 500;

const TRACKED_EVENTS: readonly (keyof WindowEventMap)[] = [
    'click',
    'keydown',
    'change',
    'submit',
    'pointerdown',
    'touchstart',
];

let installed = false;
let lastInteractionTimestamp = 0;
const listeners: Array<{ type: keyof WindowEventMap; handler: EventListener }> = [];

/**
 * 인터랙션 핸들러 — isTrusted=true 만 인정 (스크립트가 dispatchEvent 로 위장하는 케이스 차단).
 *
 * @param event 이벤트
 */
function handleInteraction(event: Event): void {
    if (!event.isTrusted) {
        return;
    }
    lastInteractionTimestamp = Date.now();
}

/**
 * 추적기를 설치합니다.
 *
 * - 중복 install 방지: installed 플래그
 * - capture phase 등록 — addEventListener(type, handler, true)
 * - passive: true — 스크롤 / 터치 성능 보존 (preventDefault 불필요)
 *
 * @return void
 */
export function installUserInitiatedTracker(): void {
    if (installed) {
        return;
    }
    installed = true;

    for (const type of TRACKED_EVENTS) {
        const handler = handleInteraction as EventListener;
        window.addEventListener(type, handler, { capture: true, passive: true });
        listeners.push({ type, handler });
    }
}

/**
 * 추적기를 해제합니다. (테스트 격리 / cleanup 용)
 *
 * @return void
 */
export function uninstallUserInitiatedTracker(): void {
    if (!installed) {
        return;
    }
    for (const { type, handler } of listeners) {
        window.removeEventListener(type, handler, { capture: true } as EventListenerOptions);
    }
    listeners.length = 0;
    lastInteractionTimestamp = 0;
    installed = false;
}

/**
 * 현재 호출이 "사용자 의사" 인지 판정합니다.
 *
 * 판정 우선순위:
 *   1. window.event 가 살아있고 isTrusted=true → true (가장 강력한 신호)
 *   2. lastInteractionTimestamp 가 임계값 이내 → true
 *   3. 그 외 → false
 *
 * window.event 는 legacy 이나 동기 호출 스택에서는 여전히 신뢰 가능 — Chrome / Firefox / Safari
 * 모두 지원. micro-task 이후엔 null 이 되므로 timestamp 백업이 필수.
 *
 * @return 사용자 의사 여부
 */
export function isUserInitiated(): boolean {
    // window.event 폴백 — 동기 호출 스택에서만 유효 (micro-task 이후 null).
    const currentEvent = (window as Window & { event?: Event }).event;
    if (currentEvent && currentEvent.isTrusted) {
        return true;
    }

    if (lastInteractionTimestamp === 0) {
        return false;
    }

    return Date.now() - lastInteractionTimestamp <= USER_INITIATED_THRESHOLD_MS;
}

/**
 * 테스트 헬퍼 — 마지막 인터랙션 시각을 강제 설정.
 *
 * 프로덕션 코드에선 사용하지 않음. __tests__ 에서만 import.
 *
 * @param  timestamp  설정할 ms epoch (0 은 미발생 상태)
 * @return void
 */
export function __setLastInteractionForTest(timestamp: number): void {
    lastInteractionTimestamp = timestamp;
}