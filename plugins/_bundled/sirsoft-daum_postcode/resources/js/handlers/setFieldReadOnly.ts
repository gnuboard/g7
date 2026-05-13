/**
 * setFieldReadOnly 핸들러
 *
 * 지정된 필드명의 input 요소를 찾아 readOnly 속성을 설정합니다.
 * 주소 검색 플러그인이 설치된 경우, 우편번호/주소 필드를 readOnly로 설정하여
 * 사용자가 직접 입력하지 못하도록 합니다.
 */

import type { ActionContext } from '../types';

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
export function setFieldReadOnlyHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const params = action.params || {};
    const fields = params.fields || [];
    const readOnly = params.readOnly !== false; // 기본값 true

    if (!fields.length) {
        logger.warn('[setFieldReadOnly] No fields specified');
        return;
    }

    logger.log(`[setFieldReadOnly] Setting readOnly=${readOnly} for fields:`, fields);

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
