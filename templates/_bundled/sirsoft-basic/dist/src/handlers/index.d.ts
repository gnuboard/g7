import { addSelectedItemIfCompleteHandler, updateSelectedItemQuantityHandler } from './productOptions';
import { toggleCartItemSelectionHandler, selectAllCartItemsHandler, setCartOptionHandler, openCartDeleteModalHandler, openCartOptionModalHandler, recalculateCartHandler } from './cartHandlers';
import { findMatchingOptionHandler, initCartOptionSelectionHandler } from './cartOptionChange';
import { initCartKeyHandler, getCartKeyHandler, clearCartKeyHandler, regenerateCartKeyHandler, saveToStorageHandler, loadFromStorageHandler } from './storageHandlers';
import { getDisplayPriceHandler } from './getDisplayPrice';
import { formatCurrencyHandler, getCurrencySymbol } from './formatCurrency';
import { loadPreferredCurrencyHandler, savePreferredCurrencyHandler } from './loadPreferredCurrency';
import { setThemeHandler, initThemeHandler } from './setThemeHandler';
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
export declare const handlers: {
    'sirsoft-basic.addSelectedItemIfComplete': typeof addSelectedItemIfCompleteHandler;
    'sirsoft-basic.updateSelectedItemQuantity': typeof updateSelectedItemQuantityHandler;
    'sirsoft-basic.getDisplayPrice': typeof getDisplayPriceHandler;
    'sirsoft-basic.formatCurrency': typeof formatCurrencyHandler;
    'sirsoft-basic.getCurrencySymbol': typeof getCurrencySymbol;
    'sirsoft-basic.loadPreferredCurrency': typeof loadPreferredCurrencyHandler;
    'sirsoft-basic.savePreferredCurrency': typeof savePreferredCurrencyHandler;
    setTheme: typeof setThemeHandler;
    initTheme: typeof initThemeHandler;
    toggleCartItemSelection: typeof toggleCartItemSelectionHandler;
    selectAllCartItems: typeof selectAllCartItemsHandler;
    setCartOption: typeof setCartOptionHandler;
    openCartDeleteModal: typeof openCartDeleteModalHandler;
    openCartOptionModal: typeof openCartOptionModalHandler;
    recalculateCart: typeof recalculateCartHandler;
    findMatchingOption: typeof findMatchingOptionHandler;
    initCartOptionSelection: typeof initCartOptionSelectionHandler;
    initCartKey: typeof initCartKeyHandler;
    getCartKey: typeof getCartKeyHandler;
    clearCartKey: typeof clearCartKeyHandler;
    regenerateCartKey: typeof regenerateCartKeyHandler;
    saveToStorage: typeof saveToStorageHandler;
    loadFromStorage: typeof loadFromStorageHandler;
};
/**
 * 핸들러 맵 (handlerMap alias)
 *
 * index.ts에서 import할 때 사용
 */
export declare const handlerMap: {
    'sirsoft-basic.addSelectedItemIfComplete': typeof addSelectedItemIfCompleteHandler;
    'sirsoft-basic.updateSelectedItemQuantity': typeof updateSelectedItemQuantityHandler;
    'sirsoft-basic.getDisplayPrice': typeof getDisplayPriceHandler;
    'sirsoft-basic.formatCurrency': typeof formatCurrencyHandler;
    'sirsoft-basic.getCurrencySymbol': typeof getCurrencySymbol;
    'sirsoft-basic.loadPreferredCurrency': typeof loadPreferredCurrencyHandler;
    'sirsoft-basic.savePreferredCurrency': typeof savePreferredCurrencyHandler;
    setTheme: typeof setThemeHandler;
    initTheme: typeof initThemeHandler;
    toggleCartItemSelection: typeof toggleCartItemSelectionHandler;
    selectAllCartItems: typeof selectAllCartItemsHandler;
    setCartOption: typeof setCartOptionHandler;
    openCartDeleteModal: typeof openCartDeleteModalHandler;
    openCartOptionModal: typeof openCartOptionModalHandler;
    recalculateCart: typeof recalculateCartHandler;
    findMatchingOption: typeof findMatchingOptionHandler;
    initCartOptionSelection: typeof initCartOptionSelectionHandler;
    initCartKey: typeof initCartKeyHandler;
    getCartKey: typeof getCartKeyHandler;
    clearCartKey: typeof clearCartKeyHandler;
    regenerateCartKey: typeof regenerateCartKeyHandler;
    saveToStorage: typeof saveToStorageHandler;
    loadFromStorage: typeof loadFromStorageHandler;
};
/**
 * 핸들러 타입 정의 (TypeScript 자동완성용)
 */
export type SirsoftBasicHandlers = typeof handlers;
export { addSelectedItemIfCompleteHandler, updateSelectedItemQuantityHandler, getDisplayPriceHandler, formatCurrencyHandler, getCurrencySymbol, loadPreferredCurrencyHandler, savePreferredCurrencyHandler, setThemeHandler, initThemeHandler, toggleCartItemSelectionHandler, selectAllCartItemsHandler, setCartOptionHandler, openCartDeleteModalHandler, openCartOptionModalHandler, recalculateCartHandler, findMatchingOptionHandler, initCartOptionSelectionHandler, initCartKeyHandler, getCartKeyHandler, clearCartKeyHandler, regenerateCartKeyHandler, saveToStorageHandler, loadFromStorageHandler, };
