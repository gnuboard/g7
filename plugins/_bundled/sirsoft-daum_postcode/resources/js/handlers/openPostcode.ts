/**
 * openPostcode 핸들러
 *
 * 다음(카카오) 우편번호 검색 창을 띄우고, 고른 주소를 G7 표준 형식으로 바꿔 콜백을 실행한다.
 * - callbackAction 있으면: 커스텀 동작 실행 (Extension Point에서 정의한 onAddressSelect)
 * - callbackAction 없으면: 플러그인 기본 동작 (설정 기반 setState)
 *
 * 표시 방식은 기기별로 고른다(1.2.0). 기기는 **클릭 시점 화면 폭**으로 가른다 — 엔진 반응형
 * `portable` 범위(모바일+태블릿, 상한 1023px) 안이면 모바일, 넘으면 PC 다.
 * - PC(`displayModePc`, 설정 `display_mode_pc`): popup · layer(기본) · inline
 * - 모바일(`displayModeMobile`, 설정 `display_mode_mobile`): inline(기본) · fullscreen
 *
 * 방식별 동작:
 * - inline: 클릭한 버튼이 있는 주소 입력 줄 바로 뒤에 폭 100% 영역을 펼쳐 끼워 넣는다(embed). 일반 쇼핑몰처럼
 *   페이지 안에서 펼쳐지므로 좁은 화면에서도 잘리지 않는다 — 카카오 가이드가 모바일에 권하는 방식이다.
 *   클릭 이벤트가 없으면(코드에서 직접 호출 등) 펼칠 자리를 모르므로 PC 는 레이어, 모바일은 전체 화면으로 띄운다.
 * - layer: 화면 위에 겹쳐 띄운다. 크기는 설정값과 화면 크기 중 작은 쪽이다.
 * - popup: 새 창으로 연다.
 * - fullscreen: 화면 전체를 덮는 창(위쪽 제목 + 닫기 단추) 안에 100% 높이로 끼워 넣는다. 고르거나 닫기·ESC 로 닫힌다.
 *   브라우저 뒤로 가기는 가로채지 않는다 — 엔진 라우터가 모든 popstate 에 화면을 다시 그리므로(입력 중인 주문서가
 *   다시 그려진다) 기록을 하나 쌓아 뒤로 가기로 닫는 방식은 엔진 차원의 지원 없이 넣지 않는다.
 *
 * G7 표준 주소 이벤트 형식:
 * - zipcode: 우편번호
 * - address: 기본 주소 (도로명/지번)
 * - addressDetail: 상세 주소 (사용자 입력)
 * - region: 시/도
 * - city: 시/군/구
 * - countryCode: 국가 코드
 * - _raw: 원본 우편번호 API 응답 (필요 시 접근 가능)
 */

import type { ActionContext } from '../types';
import {
    clearPostcodeSdkFailure,
    getPostcodeConstructor,
    isPostcodeSdkReady,
    notifyPostcodeSdkFailure,
    reloadPostcodeSdk,
    t,
} from './postcodeSdk';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('DaumPostcode:OpenPostcode')) ?? {
    log: (...args: unknown[]) => console.log('[DaumPostcode:OpenPostcode]', ...args),
    warn: (...args: unknown[]) => console.warn('[DaumPostcode:OpenPostcode]', ...args),
    error: (...args: unknown[]) => console.error('[DaumPostcode:OpenPostcode]', ...args),
};

interface ActionWithParams {
    handler: string;
    params?: {
        /** 주소 선택 시 실행할 콜백 액션 (Extension Point props에서 전달) */
        callbackAction?: any;
        /** PC 표시 방식 (popup | layer | inline) — 설정 display_mode_pc */
        displayModePc?: string;
        /** 모바일 표시 방식 (inline | fullscreen) — 설정 display_mode_mobile */
        displayModeMobile?: string;
        /**
         * 1.1.x 의 단일 표시 방식 (popup | layer). 1.2.0 이전 조각(템플릿 덮어쓰기 포함)이 넘긴다 — PC 값의 마지막
         * 대체로만 쓴다(설정 display_mode_pc 가 먼저다).
         */
        displayMode?: string;
        /** 팝업/레이어 너비 */
        width?: number;
        /** 팝업/레이어 높이 (펼치기 모드에서는 첫 높이 — 이후 검색 결과 높이에 맞춘다) */
        height?: number;
        /** 테마 설정 */
        theme?: {
            searchBgColor?: string;
            queryTextColor?: string;
        };
        /** 대상 필드 매핑 (기본 동작 시 사용) */
        targetFields?: {
            zipcode?: string;
            address?: string;
            region?: string;
            city?: string;
        };
    };
    [key: string]: any;
}

/**
 * G7 표준 주소 이벤트 인터페이스
 */
interface G7AddressEvent {
    /** 우편번호 */
    zipcode: string;
    /** 기본 주소 (도로명/지번) */
    address: string;
    /** 상세 주소 (사용자 입력) */
    addressDetail: string;
    /** 시/도 */
    region: string;
    /** 시/군/구 */
    city: string;
    /** 국가 코드 */
    countryCode: string;
    /** 원본 우편번호 API 응답 */
    _raw: any;
}

/** 펼치기 영역 공통 클래스 (다크 모드 규칙의 선택자) */
const INLINE_CLASS = 'g7-postcode-inline';

/** 펼치기 영역 스타일 element id (한 번만 싣는다) */
const INLINE_STYLE_ID = 'g7-postcode-inline-style';

/** 레이어가 화면 가장자리에 남기는 여백 합(px) — 좌우 16px 씩 */
const LAYER_VIEWPORT_GUTTER_X = 32;

/** 레이어가 화면 위아래에 남기는 여백 합(px) — 닫기 단추·주소창 몫 */
const LAYER_VIEWPORT_GUTTER_Y = 96;

/** PC 표시 방식 선택지와 기본값 */
const PC_MODES = ['popup', 'layer', 'inline'] as const;
const DEFAULT_PC_MODE = 'layer';

/** 모바일 표시 방식 선택지와 기본값 */
const MOBILE_MODES = ['inline', 'fullscreen'] as const;
const DEFAULT_MOBILE_MODE = 'inline';

/**
 * 모바일로 보는 화면 폭 상한(px) — 엔진 반응형 프리셋 `portable`(mobile 0~767 + tablet 768~1023)의 상한과 같다.
 * 실행 중에는 엔진(`G7Core.ResponsiveManager.parseRange('portable')`)에서 읽고, 없을 때만 이 값을 쓴다.
 */
const PORTABLE_MAX_WIDTH = 1023;

/** 전체 화면 창 id */
const FULLSCREEN_ID = 'daum-postcode-fullscreen';

/** 주소 입력 줄을 찾을 때 버튼 조각 컨테이너에서 거슬러 오르는 최대 단계 */
const ANCHOR_SEARCH_DEPTH = 4;

/** 영역 id 일련번호 */
let inlineSequence = 0;

/** 지금 열려 있는 펼치기 영역 — 버튼마다 하나 */
const openInlineAreas = new Map<HTMLElement, { area: HTMLElement; close: (restoreFocus: boolean) => void }>();

/**
 * 화면 위에 띄운 우편번호 창(레이어·전체 화면)이 열려 있는 동안 Esc 를 그 창 몫으로 받습니다.
 *
 * 창이 열려 있는 동안 Esc 는 맨 위 창인 우편번호 창의 것이다. 템플릿의 창(Modal)도 document 에서 Esc 를 받아 맨 위 창을
 * 닫는데, 우편번호 창은 body 에 붙어 템플릿 창이 「창 안에 펼친 팝업」으로 알아보지 못한다 — 둘이 같은 Esc 를 각자 받아,
 * 배송지 등록 창 안에서 연 검색 창에 Esc 를 한 번 누르면 그 아래 창까지 닫혀 입력이 사라졌다. 그래서 어느 document
 * 리스너보다 먼저 도는 window 캡처 단계에서 받아 처리하고 다른 리스너로 넘기지 않는다. 템플릿마다 창 구현이 달라도
 * 같은 결과가 나도록 템플릿 규칙(aria 속성 등)에 기대지 않는다.
 *
 * 창이 이미 문서에서 빠졌으면(다른 경로로 걷힘) Esc 를 가로채지 않고 리스너만 걷는다.
 *
 * @param element 열려 있는 창 요소
 * @param onEscape Esc 를 받았을 때 창을 닫는 처리
 * @return 리스너를 걷는 함수
 */
function captureEscapeWhileOpen(element: HTMLElement, onEscape: () => void): () => void {
    const handleKeyDown = (event: KeyboardEvent) => {
        if (event.key !== 'Escape') {
            return;
        }

        if (!element.isConnected) {
            detach();

            return;
        }

        event.stopImmediatePropagation();
        event.stopPropagation();
        onEscape();
    };

    const detach = () => window.removeEventListener('keydown', handleKeyDown, true);

    window.addEventListener('keydown', handleKeyDown, true);

    return detach;
}

/**
 * 우편번호 API 응답을 G7 표준 형식으로 변환합니다.
 */
function convertToG7AddressEvent(daumData: any): G7AddressEvent {
    return {
        zipcode: daumData.zonecode || '',
        address: daumData.roadAddress || daumData.jibunAddress || '',
        addressDetail: '',
        region: daumData.sido || '',
        city: daumData.sigungu || '',
        countryCode: 'KR',
        _raw: daumData,
    };
}

/**
 * 지금 화면이 모바일(엔진 반응형 portable 범위)인지 판정합니다 — 클릭 시점 화면 폭 기준.
 *
 * @return bool 모바일(휴대폰·태블릿)이면 true
 */
function isPortableViewport(): boolean {
    const range = (window as any).G7Core?.ResponsiveManager?.parseRange?.('portable');
    const max = typeof range?.max === 'number' && Number.isFinite(range.max) ? range.max : PORTABLE_MAX_WIDTH;

    return window.innerWidth <= max;
}

/**
 * 후보 중 처음으로 허용 목록에 드는 값을 고릅니다.
 *
 * @param allowed 허용 값
 * @param candidates 우선순위 순 후보
 * @param fallback 모두 아니면 쓸 값
 * @return 고른 값
 */
function pickMode(allowed: readonly string[], candidates: unknown[], fallback: string): string {
    for (const candidate of candidates) {
        if (typeof candidate === 'string' && allowed.includes(candidate)) {
            return candidate;
        }
    }

    return fallback;
}

/**
 * 이번 클릭에 쓸 표시 방식을 정합니다.
 *
 * PC: 매개변수 `displayModePc` → 설정 `display_mode_pc` → 옛 매개변수 `displayMode` → layer.
 * 모바일: 매개변수 `displayModeMobile` → 설정 `display_mode_mobile` → inline.
 * 기기에 맞지 않는 값(PC 의 fullscreen, 모바일의 popup·layer)은 건너뛴다.
 *
 * @param params 액션 매개변수
 * @param settings 플러그인 설정(프론트 노출분)
 * @return 기기와 방식
 */
function resolveDisplayMode(
    params: NonNullable<ActionWithParams['params']>,
    settings: Record<string, any>
): { portable: boolean; mode: string } {
    if (isPortableViewport()) {
        return {
            portable: true,
            mode: pickMode(MOBILE_MODES, [params.displayModeMobile, settings.display_mode_mobile], DEFAULT_MOBILE_MODE),
        };
    }

    return {
        portable: false,
        mode: pickMode(PC_MODES, [params.displayModePc, settings.display_mode_pc, params.displayMode], DEFAULT_PC_MODE),
    };
}

/**
 * 클릭 이벤트에서 누른 버튼을 찾습니다.
 *
 * 엔진의 비동기 핸들러가 받는 React 합성 이벤트는 위임이 끝나면 `currentTarget` 이 비고, `target` 은 버튼 안의
 * 글자·아이콘일 수 있다. 그래서 `currentTarget` 이 버튼이면 그것을, 아니면 `target` 에서 가장 가까운 버튼을 쓴다.
 * 위임 루트(React 루트 div)가 `currentTarget` 으로 오는 경우를 버튼으로 오인하지 않도록 버튼인지 확인한다.
 *
 * @param event 액션 컨텍스트의 이벤트
 * @return 버튼 요소 (없으면 null)
 */
function resolveTriggerButton(event: any): HTMLElement | null {
    const current = event?.currentTarget;

    if (current instanceof HTMLElement && current.matches('button, [role="button"]')) {
        return current;
    }

    const target = event?.target;

    if (target instanceof Element) {
        return target.closest<HTMLElement>('button, [role="button"]');
    }

    return null;
}

/**
 * 펼치기 영역을 넣을 기준 요소(주소 입력 줄)를 찾습니다.
 *
 * 1. 가까운 조상 중 `[data-postcode-anchor]` 가 있으면 그것 — 화면이 자리를 직접 지정한다.
 * 2. 버튼 조각 컨테이너(버튼의 부모)에서 몇 단계 거슬러 올라 입력 칸을 가진 첫 조상 — 확장점은 버튼 조각을
 *    래퍼 div 로 한 번 더 감싸 「우편번호 칸 + 버튼」 줄 안에 두므로, 컨테이너의 부모(래퍼)는 아직 줄 안이다.
 *    입력 칸을 가진 조상이 그 줄이다.
 * 3. 그것도 없으면 버튼 조각 컨테이너의 부모.
 *
 * @param button 누른 버튼
 * @return 기준 요소 (그 바로 뒤에 영역을 넣는다)
 */
function resolveInlineAnchor(button: HTMLElement): HTMLElement | null {
    const declared = button.closest<HTMLElement>('[data-postcode-anchor]');

    if (declared) {
        return declared;
    }

    const container = button.parentElement;

    if (!container) {
        return null;
    }

    let candidate: HTMLElement | null = container.parentElement;

    for (let depth = 0; candidate && depth < ANCHOR_SEARCH_DEPTH; depth++) {
        if (candidate === document.body) {
            break;
        }

        const hasField = Array.from(candidate.querySelectorAll('input, textarea, select'))
            .some((field) => !container.contains(field));

        if (hasField) {
            return candidate;
        }

        candidate = candidate.parentElement;
    }

    return container.parentElement ?? container;
}

/**
 * 펼치기 영역 스타일을 문서에 한 번만 싣습니다.
 *
 * 영역은 엔진이 그리는 레이아웃 밖에서 핸들러가 만든 DOM 이라 템플릿 Tailwind 클래스가 생성된다는 보장이 없다.
 * 그래서 자체 규칙을 싣고, 다크 모드(`html.dark`)에서도 테두리·머리줄이 보이도록 색을 따로 둔다. 검색 창 본문은
 * 카카오 iframe 이 그리므로 밝은 바탕 그대로다.
 *
 * @return void
 */
function ensureInlineStyle(): void {
    if (document.getElementById(INLINE_STYLE_ID)) {
        return;
    }

    const style = document.createElement('style');
    style.id = INLINE_STYLE_ID;
    style.textContent = `
.${INLINE_CLASS} { box-sizing: border-box; width: 100%; margin-top: 8px; border: 1px solid #d1d5db; border-radius: 8px; background: #ffffff; overflow: hidden; }
.${INLINE_CLASS}__bar { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 6px 8px 6px 12px; border-bottom: 1px solid #e5e7eb; background: #f9fafb; color: #374151; font-size: 13px; font-weight: 500; }
.${INLINE_CLASS}__close { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border: 0; border-radius: 6px; background: transparent; color: #4b5563; font-size: 20px; line-height: 1; cursor: pointer; }
.${INLINE_CLASS}__close:hover { background: #e5e7eb; }
.${INLINE_CLASS}__close:focus-visible { outline: 2px solid #2563eb; outline-offset: 1px; }
.${INLINE_CLASS}__frame { position: relative; width: 100%; background: #ffffff; }
.dark .${INLINE_CLASS} { border-color: #4b5563; background: #1f2937; }
.dark .${INLINE_CLASS}-fullscreen { background-color: #111827 !important; }
.dark .${INLINE_CLASS}__bar { border-bottom-color: #374151; background: #111827; color: #e5e7eb; }
.dark .${INLINE_CLASS}__close { color: #d1d5db; }
.dark .${INLINE_CLASS}__close:hover { background: #374151; }
`;
    document.head.appendChild(style);
}

/**
 * 누른 버튼의 주소 입력 줄 바로 뒤에 펼치기 영역을 만듭니다.
 *
 * @param button 누른 버튼
 * @param height 첫 높이(px) — 검색 결과가 그려지면 onresize 로 맞춘다
 * @return 영역·embed 대상·닫기 함수 (기준 요소를 못 찾으면 null)
 */
function createInlineArea(button: HTMLElement, height: number): {
    area: HTMLElement;
    frame: HTMLElement;
    close: (restoreFocus: boolean) => void;
} | null {
    const anchor = resolveInlineAnchor(button);

    if (!anchor) {
        return null;
    }

    ensureInlineStyle();

    const area = document.createElement('div');
    area.id = `g7-postcode-inline-${++inlineSequence}`;
    area.className = INLINE_CLASS;
    area.setAttribute('role', 'region');
    area.setAttribute('aria-label', t('postcode.search', '주소 검색'));
    area.style.width = '100%';

    const bar = document.createElement('div');
    bar.className = `${INLINE_CLASS}__bar`;

    const title = document.createElement('span');
    title.textContent = t('postcode.search', '주소 검색');

    const closeButton = document.createElement('button');
    closeButton.type = 'button';
    closeButton.className = `${INLINE_CLASS}__close`;
    closeButton.setAttribute('aria-label', t('postcode.close', '주소 검색 닫기'));
    closeButton.textContent = '×';

    bar.appendChild(title);
    bar.appendChild(closeButton);

    const frame = document.createElement('div');
    frame.className = `${INLINE_CLASS}__frame`;
    frame.style.height = `${Math.min(height, 500)}px`;

    area.appendChild(bar);
    area.appendChild(frame);
    anchor.insertAdjacentElement('afterend', area);

    button.setAttribute('aria-expanded', 'true');
    button.setAttribute('aria-controls', area.id);

    const close = (restoreFocus: boolean) => {
        area.remove();
        openInlineAreas.delete(button);
        button.setAttribute('aria-expanded', 'false');
        button.removeAttribute('aria-controls');

        if (restoreFocus && button.isConnected) {
            button.focus();
        }
    };

    closeButton.addEventListener('click', () => close(true));
    openInlineAreas.set(button, { area, close });

    return { area, frame, close };
}

/**
 * 우편번호 검색 창을 띄우고, 고른 주소를 G7 표준 형식으로 바꿔 콜백을 실행합니다.
 */
export async function openPostcodeHandler(
    action: ActionWithParams,
    context: ActionContext
): Promise<void> {
    const params = action.params || {};
    const {
        callbackAction,
        width = 500,
        height = 600,
        theme,
        targetFields,
    } = params;

    // 플러그인 설정 — 기본 필드 매핑과 표시 방식 대체값
    const G7Core = (window as any).G7Core;
    const pluginSettings = G7Core?.plugin?.getSettings?.('sirsoft-daum_postcode') || {};

    // 기기·방식은 클릭 시점 화면 폭으로 정한다(재시도 뒤 다시 정하지 않는다)
    const { portable, mode: displayMode } = resolveDisplayMode(params, pluginSettings);

    // 누른 버튼은 기다리기(SDK 재시도) 전에 잡는다 — 합성 이벤트는 비동기 경계를 넘으면 currentTarget 이 빈다.
    // 펼치기는 영역을 넣을 자리로, 레이어·전체 화면은 닫힌 뒤 초점을 돌려줄 곳으로 쓴다.
    const clickedButton = resolveTriggerButton(context?.event);
    const trigger = displayMode === 'inline' ? clickedButton : null;

    // 같은 버튼으로 이미 펼쳐 둔 영역이 있으면 접고 끝낸다(토글).
    if (trigger) {
        const opened = openInlineAreas.get(trigger);

        if (opened && opened.area.isConnected) {
            opened.close(false);

            return;
        }

        openInlineAreas.delete(trigger);
    }

    // 플러그인 설정에서 기본 필드 매핑 조회
    const defaultTargetFields = pluginSettings.target_fields || {
        zipcode: 'shipping.zipcode',
        address: 'shipping.address',
        region: 'shipping.region',
        city: 'shipping.city',
    };

    logger.log('[openPostcode] Starting with params:', {
        device: portable ? 'mobile' : 'pc',
        displayMode,
        width,
        height,
        hasCallbackAction: !!callbackAction,
        targetFields: targetFields || defaultTargetFields,
    });

    // 우편번호 SDK 가 로드되었는지 확인
    // 종전에는 로그만 남기고 조용히 끝나 버튼이 무반응이었다 — 사용자에게는 클릭이
    // 먹히지 않는 것으로만 보였고, 원인을 알 방법이 없었다.
    if (!isPostcodeSdkReady()) {
        logger.warn('[openPostcode] Postcode SDK not loaded — retrying');

        if (!(await reloadPostcodeSdk())) {
            logger.error('[openPostcode] Postcode SDK unavailable');
            notifyPostcodeSdkFailure(() => openPostcodeHandler(action, context));

            return;
        }

        clearPostcodeSdkFailure();
    }

    const Postcode = getPostcodeConstructor();

    /**
     * 고른 주소를 콜백 액션 또는 기본 필드 기록으로 넘긴다.
     *
     * @param data 우편번호 API 응답
     */
    const deliver = (data: any) => {
        logger.log('[openPostcode] Address selected (raw):', data);

        // 우편번호 API 응답 → G7 표준 형식 변환
        const g7AddressEvent = convertToG7AddressEvent(data);
        logger.log('[openPostcode] Converted to G7 format:', g7AddressEvent);

        // 하이브리드 분기: callbackAction 유무에 따라 동작 결정
        if (callbackAction) {
            // 커스텀 동작: Extension Point에서 정의한 onAddressSelect 실행
            // context를 전달하여 비동기 콜백에서도 컴포넌트 상태 참조 가능
            logger.log('[openPostcode] Executing callbackAction');
            executeCallbackAction(callbackAction, g7AddressEvent, context);
        } else {
            // 기본 동작: 플러그인 설정 기반 setState
            logger.log('[openPostcode] Executing default setState');
            executeDefaultSetState(targetFields || defaultTargetFields, g7AddressEvent);
        }
    };

    const baseConfig: any = theme ? { theme } : {};

    if (displayMode === 'popup') {
        // 팝업 모드: 새 창으로 열기
        new Postcode({ ...baseConfig, oncomplete: deliver, width, height }).open();

        return;
    }

    if (displayMode === 'inline' && trigger) {
        const inline = createInlineArea(trigger, height);

        if (inline) {
            // 펼치기 모드: 주소 입력 줄 바로 뒤에 끼워 넣는다. 높이는 검색 결과에 맞춰 따라간다.
            new Postcode({
                ...baseConfig,
                width: '100%',
                height: '100%',
                oncomplete: (data: any) => {
                    // 영역을 먼저 걷고 버튼으로 초점을 돌린 뒤 콜백을 실행한다 — 콜백이 다른 칸으로 초점을
                    // 옮기면 그것이 마지막이 된다.
                    inline.close(true);
                    deliver(data);
                },
                onresize: (size: { height?: number }) => {
                    if (typeof size?.height === 'number' && size.height > 0) {
                        inline.frame.style.height = `${size.height}px`;
                    }
                },
            }).embed(inline.frame);

            inline.area.scrollIntoView?.({ block: 'nearest', behavior: 'smooth' });

            return;
        }
    }

    if (displayMode === 'inline') {
        logger.warn(`[openPostcode] No trigger element for inline mode — falling back to ${portable ? 'fullscreen' : 'layer'}`);
    }

    if (portable) {
        // 모바일 전체 화면: 화면 전체를 덮는 창 안에 100% 높이로 끼워 넣는다
        const fullscreen = createFullscreenDialog(clickedButton);

        new Postcode({
            ...baseConfig,
            width: '100%',
            height: '100%',
            oncomplete: (data: any) => {
                fullscreen.close(true);
                deliver(data);
            },
        }).embed(fullscreen.frame);

        return;
    }

    // 레이어 모드: 오버레이에 임베드
    const { layer, closeLayer } = createEmbedLayer(width, height, clickedButton);

    new Postcode({
        ...baseConfig,
        width: '100%',
        height: '100%',
        oncomplete: (data: any) => {
            closeLayer();
            deliver(data);
        },
    }).embed(layer);
}

/**
 * 모바일 전체 화면 창을 만듭니다 — 화면 전체를 덮고, 위쪽에 제목과 닫기 단추, 그 아래를 검색 창이 채운다.
 *
 * 닫기: 닫기 단추 · ESC · 주소 선택. 열려 있는 동안 뒤 페이지 스크롤을 잠그고, 닫으면 되돌리며 누른 버튼으로 초점을
 * 돌린다. 이미 열린 창이 있으면 닫고 새로 연다.
 *
 * @param trigger 누른 버튼 (초점 복귀용, 없으면 null)
 * @return embed 대상과 닫기 함수
 */
function createFullscreenDialog(trigger: HTMLElement | null): {
    dialog: HTMLElement;
    frame: HTMLElement;
    close: (restoreFocus: boolean) => void;
} {
    document.getElementById(FULLSCREEN_ID)?.remove();
    ensureInlineStyle();

    const title = t('postcode.search', '주소 검색');

    const dialog = document.createElement('div');
    dialog.id = FULLSCREEN_ID;
    dialog.className = `${INLINE_CLASS}-fullscreen`;
    dialog.setAttribute('role', 'dialog');
    dialog.setAttribute('aria-modal', 'true');
    dialog.setAttribute('aria-label', title);
    dialog.setAttribute('style', [
        'position: fixed',
        'inset: 0',
        'z-index: 9999',
        'display: flex',
        'flex-direction: column',
        'background-color: #ffffff',
    ].join('; '));

    const bar = document.createElement('div');
    bar.className = `${INLINE_CLASS}__bar`;
    bar.style.flex = '0 0 auto';

    const heading = document.createElement('span');
    heading.textContent = title;

    const closeButton = document.createElement('button');
    closeButton.type = 'button';
    closeButton.className = `${INLINE_CLASS}__close`;
    closeButton.setAttribute('aria-label', t('postcode.close', '주소 검색 닫기'));
    closeButton.textContent = '×';

    bar.appendChild(heading);
    bar.appendChild(closeButton);

    const frame = document.createElement('div');
    frame.className = `${INLINE_CLASS}__frame`;
    frame.setAttribute('style', 'flex: 1 1 auto; min-height: 0; height: 100%; position: relative;');

    dialog.appendChild(bar);
    dialog.appendChild(frame);
    document.body.appendChild(dialog);

    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    const close = (restoreFocus: boolean) => {
        detachEscape();

        if (!dialog.isConnected) {
            return;
        }

        dialog.remove();
        document.body.style.overflow = previousOverflow;

        if (restoreFocus && trigger?.isConnected) {
            trigger.focus();
        }
    };

    closeButton.addEventListener('click', () => close(true));
    // Esc 는 이 창만 닫는다 — 아래에 열린 창(배송지 등록 등)으로 넘기지 않는다
    const detachEscape = captureEscapeWhileOpen(dialog, () => close(true));
    closeButton.focus();

    logger.log('[openPostcode] Fullscreen dialog created');

    return { dialog, frame, close };
}

/**
 * 레이어 크기 선언을 만듭니다 — 설정값과 화면 크기 중 작은 쪽.
 *
 * 종전에는 `width: 500px` 고정이라 폭 360px 휴대폰에서 검색 창 좌우가 잘렸다. 높이는 모바일 주소창이 접히고
 * 펴지는 것을 반영하는 `dvh` 를 뒤에 한 번 더 적어, 지원하는 브라우저는 그 값을 쓰고 아니면 `vh` 가 남는다.
 *
 * @param width 설정 너비(px)
 * @param height 설정 높이(px)
 * @return 인라인 스타일 선언 문자열
 */
function layerSizeStyle(width: number, height: number): string {
    return [
        `width: min(${width}px, calc(100vw - ${LAYER_VIEWPORT_GUTTER_X}px))`,
        `height: min(${height}px, calc(100vh - ${LAYER_VIEWPORT_GUTTER_Y}px))`,
        `height: min(${height}px, calc(100dvh - ${LAYER_VIEWPORT_GUTTER_Y}px))`,
    ].join('; ');
}

/**
 * 임베드용 레이어(오버레이) 요소를 생성합니다.
 * 플러그인 설정에서 전달받은 width, height를 쓰되 화면보다 크면 화면에 맞춥니다.
 *
 * 닫기: 닫기 단추 · 바깥 클릭 · ESC · 주소 선택. 어느 경로로 닫혀도 누른 버튼으로 초점을 돌린다 — 종전에는 초점이
 * 문서 본문으로 떨어져, 배송지 등록 창 안에서 연 경우 키보드 사용자가 창 안 위치를 잃었다.
 *
 * @param width 설정 너비(px)
 * @param height 설정 높이(px)
 * @param trigger 누른 버튼 (초점 복귀용, 없으면 null)
 */
function createEmbedLayer(width: number, height: number, trigger: HTMLElement | null = null): {
    overlay: HTMLElement;
    layer: HTMLElement;
    closeLayer: () => void;
} {
    // 오버레이 배경
    const overlay = document.createElement('div');
    overlay.id = 'daum-postcode-overlay';
    overlay.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 9999;
    `;

    // 레이어 컨테이너 — min()·dvh 는 CSSOM 파서마다 지원이 달라 속성 문자열로 그대로 싣는다
    const layer = document.createElement('div');
    layer.id = 'daum-postcode-layer';
    layer.setAttribute('style', [
        layerSizeStyle(width, height),
        'background-color: white',
        'border-radius: 8px',
        'overflow: hidden',
        'box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25)',
    ].join('; '));

    // 닫기 버튼
    const closeButton = document.createElement('button');
    closeButton.type = 'button';
    closeButton.textContent = '×';
    closeButton.setAttribute('aria-label', t('postcode.close', '주소 검색 닫기'));
    closeButton.style.cssText = `
        position: absolute;
        top: -12px;
        right: -12px;
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background-color: #374151;
        color: white;
        border: none;
        cursor: pointer;
        font-size: 20px;
        line-height: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 10;
    `;

    // 레이어 래퍼 (닫기 버튼 포지셔닝용)
    const wrapper = document.createElement('div');
    wrapper.style.cssText = 'position: relative;';
    wrapper.appendChild(closeButton);
    wrapper.appendChild(layer);

    overlay.appendChild(wrapper);
    document.body.appendChild(overlay);

    // 레이어 닫기 함수 — 어느 경로로 닫혀도 ESC 리스너를 함께 걷고 누른 버튼으로 초점을 돌린다
    const closeLayer = () => {
        detachEscape();

        if (!overlay.parentNode) {
            return;
        }

        overlay.parentNode.removeChild(overlay);

        if (trigger?.isConnected) {
            trigger.focus();
        }
    };

    // ESC 는 이 레이어만 닫는다 — 아래에 열린 창(배송지 등록 등)으로 넘기지 않는다
    const detachEscape = captureEscapeWhileOpen(overlay, closeLayer);

    // 닫기 버튼 클릭 시 레이어 닫기
    closeButton.addEventListener('click', closeLayer);

    // 오버레이 배경 클릭 시 레이어 닫기
    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) {
            closeLayer();
        }
    });

    logger.log('[openPostcode] Layer created with size:', { width, height });

    return { overlay, layer, closeLayer };
}

/**
 * callbackAction을 실행합니다.
 * G7Core.dispatch의 componentContext 옵션을 활용하여 비동기 콜백에서도
 * 버튼 클릭 시점의 컴포넌트 상태를 정확히 전달합니다.
 *
 * dispatch 내부 우선순위: options.componentContext > __g7ActionContext > global fallback
 * 따라서 componentContext를 명시적으로 전달하면 __g7ActionContext 수동 관리가 불필요합니다.
 *
 * handlerContext.state 우선 사용 이유:
 * - handleSetState의 컴포넌트 경로(isRealComponentContext=true)는 React setState만 호출하고
 *   globalState._local을 업데이트하지 않음
 * - React 커밋 후 __g7PendingLocalState가 클리어되면 getLocal()은 stale globalState._local 반환
 * - handlerContext.state는 extendedDataContext._local(globalState + localDynamicState 병합)에서
 *   캡처되므로 컴포넌트 setState 결과가 반영된 최신 상태임
 */
function executeCallbackAction(callbackAction: any, g7AddressEvent: G7AddressEvent, handlerContext?: ActionContext): void {
    const G7Core = (window as any).G7Core;

    if (!G7Core?.dispatch) {
        logger.error('[openPostcode] G7Core.dispatch not available');
        return;
    }

    // handlerContext.state(버튼 클릭 시점 componentContext.state)를 우선 사용
    // getLocal()은 globalState._local만 반환하여 컴포넌트 setState 결과 누락 가능
    const currentLocalState = handlerContext?.state || G7Core?.state?.getLocal?.() || {};

    // dispatch의 componentContext 옵션으로 전달
    // → dispatch 내부에서 __g7ActionContext보다 우선 적용됨
    const componentContext = {
        state: currentLocalState,
        setState: handlerContext?.setState,
        data: {
            ...(handlerContext?.data || {}),
            _local: currentLocalState,
            $event: g7AddressEvent,
        },
        isolatedContext: handlerContext?.isolatedContext,
    };

    logger.log('[openPostcode] Dispatching with componentContext:', {
        hasLocalState: !!currentLocalState,
        sourceType: handlerContext?.state ? 'handlerContext' : 'getLocal',
        localCheckout: currentLocalState?.checkout,
        event: g7AddressEvent,
    });

    try {
        const actions = Array.isArray(callbackAction) ? callbackAction : [callbackAction];
        for (const cbAction of actions) {
            if (cbAction && typeof cbAction === 'object') {
                logger.log('[openPostcode] Dispatching action:', cbAction);
                G7Core.dispatch(cbAction, { componentContext });
            }
        }
    } catch (error) {
        logger.error('[openPostcode] callbackAction failed:', error);
    }
}

/**
 * 기본 setState 동작을 실행합니다.
 */
function executeDefaultSetState(
    targetFields: Record<string, string>,
    g7AddressEvent: G7AddressEvent
): void {
    const G7Core = (window as any).G7Core;

    if (!G7Core?.state?.setLocal) {
        logger.error('[openPostcode] G7Core.state.setLocal not available');
        return;
    }

    // targetFields 매핑에 따라 상태 업데이트
    const updates: Record<string, string> = {};

    if (targetFields.zipcode) {
        updates[targetFields.zipcode] = g7AddressEvent.zipcode;
    }
    if (targetFields.address) {
        updates[targetFields.address] = g7AddressEvent.address;
    }
    if (targetFields.region) {
        updates[targetFields.region] = g7AddressEvent.region;
    }
    if (targetFields.city) {
        updates[targetFields.city] = g7AddressEvent.city;
    }

    logger.log('[openPostcode] Setting local state:', updates);
    G7Core.state.setLocal(updates);
}
