import { HandlerContext, TemplateActionDefinition } from '../types';
/**
 * localStorage에서 선호 통화를 로드하여 전역 상태에 설정합니다.
 *
 * @param action - 액션 정의 (`params.defaultCurrency`)
 * @param _context - 핸들러 컨텍스트 (미사용 — 전역 상태는 G7Core.state.set() 으로 쓴다)
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
export declare function loadPreferredCurrencyHandler(action: TemplateActionDefinition, _context?: HandlerContext): string;
/**
 * 선호 통화를 localStorage에 저장합니다.
 *
 * @param action - 액션 정의 (`params.currencyCode`)
 * @param _context - 핸들러 컨텍스트 (미사용 — 전역 상태는 G7Core.state.set() 으로 쓴다)
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
export declare function savePreferredCurrencyHandler(action: TemplateActionDefinition, _context?: HandlerContext): void;
