import { HandlerContext, TemplateActionDefinition } from '../types';
/**
 * 숫자 값을 통화 형식으로 포맷팅합니다.
 *
 * 원화 계열(₩/원)은 금액 뒤에 "원", 그 외는 기호를 앞에 붙입니다
 * (백엔드 messages.currency.prefix/suffix 와 동일한 표기 규칙).
 *
 * @param action - 액션 정의 (`params.value` / `params.currencyCode` / `params.locale`)
 * @param _context - 핸들러 컨텍스트 (미사용 — 통화 설정은 G7Core 전역에서 읽는다)
 * @returns 포맷팅된 통화 문자열
 *
 * @example
 * formatCurrencyHandler({ handler: 'formatCurrency', params: { value: 10000, currencyCode: 'KRW' } }, context) // => "10,000원"
 * formatCurrencyHandler({ handler: 'formatCurrency', params: { value: 99.99, currencyCode: 'USD' } }, context) // => "$99.99"
 */
export declare function formatCurrencyHandler(action: TemplateActionDefinition, _context?: HandlerContext): string;
/**
 * 통화 심볼만 반환합니다.
 *
 * 설정의 symbol 을 우선하고, 없으면 폴백 표, 그것도 없으면 통화 코드를 그대로 돌려줍니다.
 *
 * @param currencyCode - 통화 코드
 * @returns 통화 심볼
 */
export declare function getCurrencySymbol(currencyCode: string): string;
