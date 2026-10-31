/**
 * 초점 이동 핸들러
 *
 * 레이아웃이 그린 펼침 목록(헤더 통화·배송국가 선택 등)을 키보드로 다룰 때 초점을 트리거 단추에 붙여 둔다.
 * - Esc 로 닫을 때: 목록 항목에 초점이 있는 채로 목록을 지우면 초점이 문서 몸통으로 떨어져, 다음 Tab 이 화면 처음부터
 *   시작된다(템플릿 부품 LanguageSelector·Dropdown 은 Esc 로 닫으며 초점을 트리거로 돌려준다 — 같은 동작을 레이아웃에 준다).
 * - 열 때(`defer`): 슬롯에 주입된 조각은 첫 그림에서 주입 앵커 자리에도 한 벌 그려졌다가(슬롯 문맥이 그림 뒤에 열린다),
 *   다음 다시 그리기에서 그 사본이 빠진다. 그 사본의 트리거로 목록을 열면 단추가 사라지며 초점이 문서 몸통으로 떨어진다.
 *   다시 그린 뒤 살아 있는 트리거로 초점을 옮긴다 — 초점이 이미 다른 곳(사용자가 옮긴 자리)에 있으면 건드리지 않는다.
 *
 * 같은 조각이 한 화면에 두 번 그려질 수 있으므로(넓은 화면 헤더·좁은 화면 서랍) 문서 전체가 아니라 이벤트가 난
 * 요소의 상자(`closest`) 안에서 대상을 찾는다. 그 상자가 문서에서 떨어졌으면 문서에 붙어 있고 보이는 같은 상자에서 찾는다.
 * Esc 닫기에서는 목록을 닫기 **전에** 부른다. 상태는 쓰지 않는다.
 */

interface FocusElementParams {
    /** 초점을 줄 요소 선택자 */
    selector?: string;
    /** 이벤트가 난 요소에서 위로 찾을 상자 선택자(없으면 문서 전체에서 찾는다) */
    closest?: string;
    /** 참이면 다시 그리기 뒤로 미뤄, 초점을 잃었을 때만 옮긴다 */
    defer?: boolean | string;
}

/** 다시 그리기를 기다리는 시점(ms) — 상태 갱신 뒤 렌더가 한두 틱 늦게 붙는다 */
const DEFER_STEPS_MS = [0, 50, 150, 300];

/**
 * 요소가 화면에 그려지는지(배치 상자가 있는지).
 *
 * @param el 요소
 * @returns 그려지면 true
 */
function isRendered(el: Element): boolean {
    return typeof (el as HTMLElement).getClientRects === 'function' && (el as HTMLElement).getClientRects().length > 0;
}

/**
 * 초점을 줄 대상을 찾는다.
 *
 * @param selector 대상 선택자
 * @param closest 상자 선택자
 * @param origin 이벤트가 난 요소
 * @returns 대상(없으면 null)
 */
function resolveTarget(selector: string, closest: string | undefined, origin: EventTarget | null | undefined): HTMLElement | null {
    if (closest) {
        const box = origin instanceof Element ? origin.closest(closest) : null;
        if (box && box.isConnected) {
            return box.querySelector<HTMLElement>(selector);
        }
        // 상자가 문서에서 떨어졌다(다시 그리기로 사본이 빠짐) — 붙어 있고 보이는 같은 상자 안에서 찾는다
        // 숨은 사본(좁은 화면 서랍 등)보다 그려진 것을 먼저 고른다 — 배치 정보가 없는 환경(시험)에서는 붙어 있는 첫 상자
        const live = Array.from(document.querySelectorAll(closest))
            .filter((candidate) => candidate.isConnected)
            .map((candidate) => candidate.querySelector<HTMLElement>(selector))
            .filter((found): found is HTMLElement => Boolean(found));
        const picked = live.find(isRendered) ?? live[0];
        if (picked) return picked;
        if (box) return null;
    }

    return document.querySelector<HTMLElement>(selector);
}

/**
 * 선택자로 찾은 요소에 초점을 줍니다.
 *
 * @param action 액션 정의 (`params.selector`, `params.closest`, `params.defer`)
 * @param context 액션 컨텍스트 (`event.target` 에서 상자를 찾는다)
 * @returns 초점을 옮겼으면 true (`defer` 면 예약했으면 true)
 */
export function focusElementHandler(
    action: { params?: FocusElementParams },
    context?: { event?: { target?: EventTarget | null } | Event }
): boolean {
    const { selector, closest, defer } = action.params ?? {};
    if (!selector || typeof document === 'undefined') {
        return false;
    }
    const origin = context?.event?.target ?? null;

    if (defer === true || defer === 'true') {
        const lost = (): boolean => {
            const active = document.activeElement;

            return !active || active === document.body || !active.isConnected || active === origin;
        };
        const attempt = (): void => {
            if (!lost()) return;
            const target = resolveTarget(selector, closest, origin);
            if (target && target !== document.activeElement && typeof target.focus === 'function') {
                target.focus();
            }
        };
        DEFER_STEPS_MS.forEach((ms) => setTimeout(attempt, ms));

        return true;
    }

    const target = resolveTarget(selector, closest, origin);
    if (!target || typeof target.focus !== 'function') {
        return false;
    }
    target.focus();

    return true;
}
