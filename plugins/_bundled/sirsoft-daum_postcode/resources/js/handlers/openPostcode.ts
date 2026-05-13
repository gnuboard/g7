/**
 * openPostcode 핸들러
 *
 * 다음 우편번호 API를 호출하고 G7 표준 형식으로 변환하여 콜백 실행
 * - callbackAction 있으면: 커스텀 동작 실행 (Extension Point에서 정의한 onAddressSelect)
 * - callbackAction 없으면: 플러그인 기본 동작 (설정 기반 setState)
 *
 * G7 표준 주소 이벤트 형식:
 * - zipcode: 우편번호
 * - address: 기본 주소 (도로명/지번)
 * - addressDetail: 상세 주소 (사용자 입력)
 * - region: 시/도
 * - city: 시/군/구
 * - countryCode: 국가 코드
 * - _raw: 원본 다음 API 응답 (필요 시 접근 가능)
 */

import type { ActionContext } from '../types';

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
        /** 표시 모드 (popup | layer) */
        displayMode?: string;
        /** 팝업/레이어 너비 */
        width?: number;
        /** 팝업/레이어 높이 */
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
    /** 원본 다음 API 응답 */
    _raw: any;
}

/**
 * 다음 우편번호 API 응답을 G7 표준 형식으로 변환합니다.
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
 * 다음 우편번호 API를 호출하고 G7 표준 형식으로 변환하여 콜백 실행
 */
export function openPostcodeHandler(
    action: ActionWithParams,
    context: ActionContext
): void {
    const params = action.params || {};
    const {
        callbackAction,
        displayMode = 'layer',
        width = 500,
        height = 600,
        theme,
        targetFields,
    } = params;

    // 플러그인 설정에서 기본 필드 매핑 조회
    const G7Core = (window as any).G7Core;
    const pluginSettings = G7Core?.plugin?.getSettings?.('sirsoft-daum_postcode') || {};
    const defaultTargetFields = pluginSettings.target_fields || {
        zipcode: 'shipping.zipcode',
        address: 'shipping.address',
        region: 'shipping.region',
        city: 'shipping.city',
    };

    logger.log('[openPostcode] Starting with params:', {
        displayMode,
        width,
        height,
        hasCallbackAction: !!callbackAction,
        targetFields: targetFields || defaultTargetFields,
    });

    // 다음 우편번호 API가 로드되었는지 확인
    if (!(window as any).daum?.Postcode) {
        logger.error('[openPostcode] Daum Postcode API not loaded');
        return;
    }

    const postcodeConfig: any = {
        oncomplete: (data: any) => {
            logger.log('[openPostcode] Address selected (raw):', data);

            // 다음 API 응답 → G7 표준 형식 변환
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
        },
        width,
        height,
    };

    // 테마 설정 적용
    if (theme) {
        postcodeConfig.theme = theme;
    }

    // 다음 우편번호 인스턴스 생성
    const postcode = new (window as any).daum.Postcode(postcodeConfig);

    if (displayMode === 'popup') {
        // 팝업 모드: 새 창으로 열기
        postcode.open();
    } else {
        // 레이어 모드: 오버레이에 임베드
        const { layer, closeLayer } = createEmbedLayer(width, height);

        // oncomplete 콜백 후 레이어 닫기 처리
        const originalOncomplete = postcodeConfig.oncomplete;
        postcodeConfig.oncomplete = (data: any) => {
            closeLayer();
            originalOncomplete(data);
        };

        // 새 config로 인스턴스 재생성 후 임베드
        const layerPostcode = new (window as any).daum.Postcode(postcodeConfig);
        layerPostcode.embed(layer);
    }
}

/**
 * 임베드용 레이어(오버레이) 요소를 생성합니다.
 * 플러그인 설정에서 전달받은 width, height를 사용합니다.
 */
function createEmbedLayer(width: number, height: number): {
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

    // 레이어 컨테이너
    const layer = document.createElement('div');
    layer.id = 'daum-postcode-layer';
    layer.style.cssText = `
        width: ${width}px;
        height: ${height}px;
        background-color: white;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    `;

    // 닫기 버튼
    const closeButton = document.createElement('button');
    closeButton.type = 'button';
    closeButton.innerHTML = '×';
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

    // 레이어 닫기 함수
    const closeLayer = () => {
        if (overlay.parentNode) {
            overlay.parentNode.removeChild(overlay);
        }
    };

    // 닫기 버튼 클릭 시 레이어 닫기
    closeButton.addEventListener('click', closeLayer);

    // 오버레이 배경 클릭 시 레이어 닫기
    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) {
            closeLayer();
        }
    });

    // ESC 키로 레이어 닫기
    const handleEscKey = (e: KeyboardEvent) => {
        if (e.key === 'Escape') {
            closeLayer();
            document.removeEventListener('keydown', handleEscKey);
        }
    };
    document.addEventListener('keydown', handleEscKey);

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
