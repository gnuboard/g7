import { HandlerContext, TemplateActionDefinition } from '../types';
/**
 * 선호 통화에 맞는 가격을 반환합니다.
 *
 * @param action - 액션 정의 (`params.product` / `params.priceField` / `params.currencyCode`)
 * @param _context - 핸들러 컨텍스트 (미사용 — 표시 통화는 G7Core 전역에서 읽는다)
 * @returns 포맷팅된 가격 문자열
 *
 * @example
 * // 레이아웃 JSON에서 사용
 * {
 *   "text": "{{handler('getDisplayPrice', { product: product.data, priceField: 'selling_price' })}}"
 * }
 */
export declare function getDisplayPriceHandler(action: TemplateActionDefinition, _context?: HandlerContext): string;
