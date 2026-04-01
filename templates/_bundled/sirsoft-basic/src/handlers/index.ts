/**
 * sirsoft-basic 템플릿 핸들러 등록
 *
 * 이 파일에서 모든 커스텀 핸들러를 export합니다.
 * G7Core에서 자동으로 로드하여 사용할 수 있습니다.
 */

// 상품 옵션 관련 핸들러
import {
  addSelectedItemIfCompleteHandler,
  updateSelectedItemQuantityHandler,
} from './productOptions';

// 장바구니 관련 핸들러
import {
  toggleCartItemSelectionHandler,
  selectAllCartItemsHandler,
  setCartOptionHandler,
  openCartDeleteModalHandler,
  openCartOptionModalHandler,
  recalculateCartHandler,
} from './cartHandlers';

// 장바구니 옵션 변경 관련 핸들러
import {
  findMatchingOptionHandler,
  initCartOptionSelectionHandler,
} from './cartOptionChange';

// 스토리지 관련 핸들러
import {
  initCartKeyHandler,
  getCartKeyHandler,
  clearCartKeyHandler,
  regenerateCartKeyHandler,
  saveToStorageHandler,
  loadFromStorageHandler,
} from './storageHandlers';

// 다중 통화 관련 핸들러
import { getDisplayPriceHandler } from './getDisplayPrice';
import { formatCurrencyHandler, getCurrencySymbol } from './formatCurrency';
import {
  loadPreferredCurrencyHandler,
  savePreferredCurrencyHandler,
} from './loadPreferredCurrency';

// 테마 관련 핸들러
import { setThemeHandler, initThemeHandler } from './setThemeHandler';

// 언어 관련 핸들러는 엔진 레벨(ActionDispatcher)에서 처리
// setLocale 핸들러는 ActionDispatcher에 빌트인으로 등록되어 있음

/**
 * sirsoft-basic 템플릿의 모든 커스텀 핸들러
 *
 * 네이밍 규칙: 'sirsoft-basic.[핸들러명]'
 *
 * @example
 * // 레이아웃 JSON에서 사용
 * {
 *   "handler": "custom",
 *   "name": "addSelectedItemIfComplete",
 *   "params": { ... }
 * }
 *
 * // 또는 풀네임으로
 * {
 *   "handler": "sirsoft-basic.addSelectedItemIfComplete",
 *   "params": { ... }
 * }
 */
export const handlers = {
  // 상품 옵션
  'sirsoft-basic.addSelectedItemIfComplete': addSelectedItemIfCompleteHandler,
  'sirsoft-basic.updateSelectedItemQuantity': updateSelectedItemQuantityHandler,

  // 다중 통화
  'sirsoft-basic.getDisplayPrice': getDisplayPriceHandler,
  'sirsoft-basic.formatCurrency': formatCurrencyHandler,
  'sirsoft-basic.getCurrencySymbol': getCurrencySymbol,
  'sirsoft-basic.loadPreferredCurrency': loadPreferredCurrencyHandler,
  'sirsoft-basic.savePreferredCurrency': savePreferredCurrencyHandler,

  // 테마
  setTheme: setThemeHandler,
  initTheme: initThemeHandler,

  // 언어: setLocale은 엔진 레벨(ActionDispatcher)에서 빌트인으로 처리

  // 장바구니
  toggleCartItemSelection: toggleCartItemSelectionHandler,
  selectAllCartItems: selectAllCartItemsHandler,
  setCartOption: setCartOptionHandler,
  openCartDeleteModal: openCartDeleteModalHandler,
  openCartOptionModal: openCartOptionModalHandler,
  recalculateCart: recalculateCartHandler,

  // 장바구니 옵션 변경
  findMatchingOption: findMatchingOptionHandler,
  initCartOptionSelection: initCartOptionSelectionHandler,

  // 스토리지
  initCartKey: initCartKeyHandler,
  getCartKey: getCartKeyHandler,
  clearCartKey: clearCartKeyHandler,
  regenerateCartKey: regenerateCartKeyHandler,
  saveToStorage: saveToStorageHandler,
  loadFromStorage: loadFromStorageHandler,
};

/**
 * 핸들러 맵 (handlerMap alias)
 *
 * index.ts에서 import할 때 사용
 */
export const handlerMap = handlers;

/**
 * 핸들러 타입 정의 (TypeScript 자동완성용)
 */
export type SirsoftBasicHandlers = typeof handlers;

// 개별 핸들러 export (직접 import용)
export {
  addSelectedItemIfCompleteHandler,
  updateSelectedItemQuantityHandler,
  getDisplayPriceHandler,
  formatCurrencyHandler,
  getCurrencySymbol,
  loadPreferredCurrencyHandler,
  savePreferredCurrencyHandler,
  setThemeHandler,
  initThemeHandler,
  // setLocaleHandler는 엔진 레벨에서 처리하므로 제거됨
  // 장바구니
  toggleCartItemSelectionHandler,
  selectAllCartItemsHandler,
  setCartOptionHandler,
  openCartDeleteModalHandler,
  openCartOptionModalHandler,
  recalculateCartHandler,
  // 장바구니 옵션 변경
  findMatchingOptionHandler,
  initCartOptionSelectionHandler,
  // 스토리지
  initCartKeyHandler,
  getCartKeyHandler,
  clearCartKeyHandler,
  regenerateCartKeyHandler,
  saveToStorageHandler,
  loadFromStorageHandler,
};
