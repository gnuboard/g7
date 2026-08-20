import { HandlerContext } from '../types';
interface FormatCurrencyParams {
    value: number;
    currencyCode?: string;
    locale?: string;
}
/**
 * 숫자 값을 통화 형식으로 포맷팅합니다.
 *
 * 원화 계열(₩/원)은 금액 뒤에 "원", 그 외는 기호를 앞에 붙입니다
 * (백엔드 messages.currency.prefix/suffix 와 동일한 표기 규칙).
 *
 * @param params.value - 포맷팅할 숫자 값
 * @param params.currencyCode - 통화 코드 (미지정 시 표시 통화 → 기본 통화 순)
 * @param params.locale - 로케일 (숫자 구분자용, 미지정 시 브라우저 로케일)
 * @param context - 핸들러 컨텍스트
 * @returns 포맷팅된 통화 문자열
 *
 * @example
 * formatCurrencyHandler({ value: 10000, currencyCode: 'KRW' }, context) // => "10,000원"
 * formatCurrencyHandler({ value: 99.99, currencyCode: 'USD' }, context) // => "$99.99"
 */
export declare function formatCurrencyHandler(params: FormatCurrencyParams, context: HandlerContext): string;
/**
 * 통화 심볼만 반환합니다.
 *
 * 설정의 symbol 을 우선하고, 없으면 폴백 표, 그것도 없으면 통화 코드를 그대로 돌려줍니다.
 *
 * @param currencyCode - 통화 코드
 * @returns 통화 심볼
 */
export declare function getCurrencySymbol(currencyCode: string): string;
export {};
