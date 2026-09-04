/**
 * setFieldReadOnly 핸들러
 *
 * 지정된 필드명의 input 요소를 찾아 readOnly 속성을 설정합니다.
 * 주소 검색 플러그인이 설치된 경우, 우편번호/주소 필드를 readOnly로 설정하여
 * 사용자가 직접 입력하지 못하도록 합니다.
 */

import type { ActionContext } from '../types';
import {
    clearPostcodeSdkFailure,
    notifyPostcodeSdkFailure,
    waitForPostcodeSdk,
} from './postcodeSdk';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('DaumPostcode:SetFieldReadOnly')) ?? {
    log: (...args: unknown[]) => console.log('[DaumPostcode:SetFieldReadOnly]', ...args),
    warn: (...args: unknown[]) => console.warn('[DaumPostcode:SetFieldReadOnly]', ...args),
    error: (...args: unknown[]) => console.error('[DaumPostcode:SetFieldReadOnly]', ...args),
};

interface ActionWithParams {
    handler: string;
    params?: {
        /** readOnly로 설정할 필드명 배열 */
        fields?: string[];
        /** readOnly 값 (기본값: true) */
        readOnly?: boolean;
        /**
         * SDK 확보를 기다리는 최대 시간(ms).
         *
         * 레이아웃 `scripts[]` 로더가 비동기 + 재시도로 스크립트를 넣으므로 마운트
         * 시점에 SDK 가 없는 것은 정상이다. 기본값은 코어 로더의 재시도 예산에 맞춘다.
         */
        sdkWaitMs?: number;
    };
    [key: string]: any;
}

/**
 * 지정된 필드명의 input 요소를 찾아 readOnly 속성을 설정합니다.
 *
 * @param action 액션 객체 (params.fields: 필드명 배열, params.readOnly: boolean)
 * @param _context 액션 컨텍스트
 *
 * @example
 * // 레이아웃 JSON에서 사용
 * {
 *   "handler": "sirsoft-daum_postcode.setFieldReadOnly",
 *   "params": {
 *     "fields": ["zonecode", "address"],
 *     "readOnly": true
 *   }
 * }
 */
export async function setFieldReadOnlyHandler(
    action: ActionWithParams,
    _context: ActionContext
): Promise<void> {
    const params = action.params || {};
    const fields = params.fields || [];
    const readOnly = params.readOnly !== false; // 기본값 true

    if (!fields.length) {
        logger.warn('[setFieldReadOnly] No fields specified');
        return;
    }

    // 읽기 전용은 "검색으로만 입력할 수 있다" 는 뜻이므로, 검색이 실제로 가능할 때만
    // 건다. SDK 를 못 불러온 상태에서 걸면 검색도 직접 입력도 불가능해져 주문을
    // 끝낼 방법이 사라진다. 해제(readOnly=false)는 언제나 안전하므로 기다리지 않는다.
    if (readOnly) {
        const ready = await waitForPostcodeSdk(params.sdkWaitMs);

        if (!ready) {
            logger.warn('[setFieldReadOnly] Daum Postcode SDK unavailable — leaving fields editable');
            applyReadOnly(fields, false);
            notifyPostcodeSdkFailure(() => {
                applyReadOnly(fields, true);
                clearPostcodeSdkFailure();
            });

            return;
        }

        clearPostcodeSdkFailure();
    }

    logger.log(`[setFieldReadOnly] Setting readOnly=${readOnly} for fields:`, fields);
    applyReadOnly(fields, readOnly);
}

/**
 * 지정된 필드에 readOnly 속성과 시각적 상태를 적용합니다.
 *
 * @param fields 필드명 배열
 * @param readOnly 적용할 readOnly 값
 * @return void
 */
function applyReadOnly(fields: string[], readOnly: boolean): void {
    // 각 필드에 대해 DOM에서 input 요소를 찾아 readOnly 설정
    fields.forEach((fieldName) => {
        // name 속성으로 input 요소 찾기
        const inputs = document.querySelectorAll<HTMLInputElement | HTMLTextAreaElement>(
            `input[name="${fieldName}"], textarea[name="${fieldName}"]`
        );

        if (inputs.length === 0) {
            logger.warn(`[setFieldReadOnly] No input found with name="${fieldName}"`);
            return;
        }

        inputs.forEach((input) => {
            input.readOnly = readOnly;

            // readOnly 상태에 따른 시각적 피드백 (선택적)
            if (readOnly) {
                input.classList.add('readonly');
            } else {
                input.classList.remove('readonly');
            }

            logger.log(`[setFieldReadOnly] Set readOnly=${readOnly} on input[name="${fieldName}"]`);
        });
    });
}
