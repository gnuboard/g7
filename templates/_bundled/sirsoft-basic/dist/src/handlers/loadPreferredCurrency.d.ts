import { HandlerContext } from '../types';
interface LoadPreferredCurrencyParams {
    defaultCurrency?: string;
}
/**
 * localStorage에서 선호 통화를 로드하여 전역 상태에 설정합니다.
 *
 * @param params.defaultCurrency - 기본 통화 (선택, 기본값: 'KRW')
 * @param context - 핸들러 컨텍스트
 * @returns 로드된 통화 코드
 *
 * @example
 * // _user_base.json의 init_actions에서 사용
 * {
 *   "init_actions": [
 *     {
 *       "handler": "loadPreferredCurrency",
 *       "params": { "defaultCurrency": "KRW" }
 *     }
 *   ]
 * }
 */
export declare function loadPreferredCurrencyHandler(params: LoadPreferredCurrencyParams, context: HandlerContext): string;
/**
 * 선호 통화를 localStorage에 저장합니다.
 *
 * @param currencyCode - 저장할 통화 코드
 * @param context - 핸들러 컨텍스트
 *
 * @example
 * // 통화 선택 드롭다운에서 사용
 * {
 *   "actions": [
 *     {
 *       "type": "click",
 *       "handler": "savePreferredCurrency",
 *       "params": { "currencyCode": "{{currency.code}}" }
 *     }
 *   ]
 * }
 */
export declare function savePreferredCurrencyHandler(params: {
    currencyCode: string;
}, context: HandlerContext): void;
export {};
