import { HandlerContext } from '../types';
interface FormatCurrencyParams {
    value: number;
    currencyCode?: string;
    locale?: string;
}
/**
 * 숫자 값을 통화 형식으로 포맷팅합니다.
 *
 * @param params.value - 포맷팅할 숫자 값
 * @param params.currencyCode - 통화 코드 (KRW, USD, JPY 등)
 * @param params.locale - 로케일 (선택, 미지정 시 통화 기본 로케일 사용)
 * @param context - 핸들러 컨텍스트
 * @returns 포맷팅된 통화 문자열
 *
 * @example
 * formatCurrencyHandler({ value: 10000, currencyCode: 'KRW' }, context)
 * // => "₩10,000" 또는 "10,000원"
 *
 * formatCurrencyHandler({ value: 99.99, currencyCode: 'USD' }, context)
 * // => "$99.99"
 */
export declare function formatCurrencyHandler(params: FormatCurrencyParams, context: HandlerContext): string;
/**
 * 통화 심볼만 반환합니다.
 *
 * @param currencyCode - 통화 코드
 * @returns 통화 심볼
 */
export declare function getCurrencySymbol(currencyCode: string): string;
export {};
