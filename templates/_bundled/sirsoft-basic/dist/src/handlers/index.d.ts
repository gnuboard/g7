import { addSelectedItemIfCompleteHandler, updateSelectedItemQuantityHandler, removeSelectedItemHandler, updateNoOptionQuantityHandler, setBlockAdditionalOptionHandler } from './productOptions';
import { toggleCartItemSelectionHandler, selectAllCartItemsHandler, setCartOptionHandler, openCartDeleteModalHandler, openCartOptionModalHandler, recalculateCartHandler } from './cartHandlers';
import { findMatchingOptionHandler, initCartOptionSelectionHandler } from './cartOptionChange';
import { initCartKeyHandler, ensureCartKeyHandler, getCartKeyHandler, clearCartKeyHandler, regenerateCartKeyHandler, saveToStorageHandler, loadFromStorageHandler, initGuestOrderTokenHandler, saveGuestOrderTokenHandler, clearGuestOrderTokenHandler, clearGuestTokenOnEntryHandler, initSecretViewTokenHandler, saveSecretViewTokenHandler, clearSecretViewTokenHandler } from './storageHandlers';
import { getDisplayPriceHandler } from './getDisplayPrice';
import { formatCurrencyHandler, getCurrencySymbol } from './formatCurrency';
import { loadPreferredCurrencyHandler, savePreferredCurrencyHandler } from './loadPreferredCurrency';
import { setThemeHandler, initThemeHandler } from './setThemeHandler';
import { redirectToLoginWithReturnHandler } from './redirectToLoginWithReturn';
import { downloadAttachmentHandler } from './downloadAttachment';
import { markLocalOnceHandler } from './markLocalOnce';
import { rememberIdentityClaimHandler } from './identityLauncher';
/**
 * sirsoft-basic 템플릿의 모든 커스텀 핸들러
 *
 * 네이밍 규칙: 'sirsoft-basic.[핸들러명]'. 접두어 없는 이름(setTheme 등)은 기존 레이아웃 호환으로 남아 있다.
 * 엔진 이름(navigate·setState·toast·refresh 등)과 같은 이름은 쓰지 않는다 — 정적 검사가 막고 엔진이 경고한다.
 *
 * @example
 * // 레이아웃 JSON에서 사용 — 등록한 이름 그대로 handler 에 적는다
 * {
 *   "handler": "sirsoft-basic.addSelectedItemIfComplete",
 *   "params": { ... }
 * }
 */
export declare const handlers: {
    'sirsoft-basic.addSelectedItemIfComplete': typeof addSelectedItemIfCompleteHandler;
    'sirsoft-basic.updateSelectedItemQuantity': typeof updateSelectedItemQuantityHandler;
    'sirsoft-basic.removeSelectedItem': typeof removeSelectedItemHandler;
    'sirsoft-basic.updateNoOptionQuantity': typeof updateNoOptionQuantityHandler;
    'sirsoft-basic.setBlockAdditionalOption': typeof setBlockAdditionalOptionHandler;
    'sirsoft-basic.getDisplayPrice': typeof getDisplayPriceHandler;
    'sirsoft-basic.formatCurrency': typeof formatCurrencyHandler;
    'sirsoft-basic.getCurrencySymbol': typeof getCurrencySymbol;
    'sirsoft-basic.loadPreferredCurrency': typeof loadPreferredCurrencyHandler;
    'sirsoft-basic.savePreferredCurrency': typeof savePreferredCurrencyHandler;
    setTheme: typeof setThemeHandler;
    initTheme: typeof initThemeHandler;
    redirectToLoginWithReturn: typeof redirectToLoginWithReturnHandler;
    downloadAttachment: typeof downloadAttachmentHandler;
    'sirsoft-basic.markLocalOnce': typeof markLocalOnceHandler;
    'sirsoft-basic.rememberIdentityClaim': typeof rememberIdentityClaimHandler;
    toggleCartItemSelection: typeof toggleCartItemSelectionHandler;
    selectAllCartItems: typeof selectAllCartItemsHandler;
    setCartOption: typeof setCartOptionHandler;
    openCartDeleteModal: typeof openCartDeleteModalHandler;
    openCartOptionModal: typeof openCartOptionModalHandler;
    recalculateCart: typeof recalculateCartHandler;
    findMatchingOption: typeof findMatchingOptionHandler;
    initCartOptionSelection: typeof initCartOptionSelectionHandler;
    initCartKey: typeof initCartKeyHandler;
    ensureCartKey: typeof ensureCartKeyHandler;
    getCartKey: typeof getCartKeyHandler;
    clearCartKey: typeof clearCartKeyHandler;
    regenerateCartKey: typeof regenerateCartKeyHandler;
    saveToStorage: typeof saveToStorageHandler;
    loadFromStorage: typeof loadFromStorageHandler;
    initGuestOrderToken: typeof initGuestOrderTokenHandler;
    saveGuestOrderToken: typeof saveGuestOrderTokenHandler;
    clearGuestOrderToken: typeof clearGuestOrderTokenHandler;
    clearGuestTokenOnEntry: typeof clearGuestTokenOnEntryHandler;
    initSecretViewToken: typeof initSecretViewTokenHandler;
    saveSecretViewToken: typeof saveSecretViewTokenHandler;
    clearSecretViewToken: typeof clearSecretViewTokenHandler;
};
/**
 * 핸들러 맵 (handlerMap alias)
 *
 * index.ts에서 import할 때 사용
 */
export declare const handlerMap: {
    'sirsoft-basic.addSelectedItemIfComplete': typeof addSelectedItemIfCompleteHandler;
    'sirsoft-basic.updateSelectedItemQuantity': typeof updateSelectedItemQuantityHandler;
    'sirsoft-basic.removeSelectedItem': typeof removeSelectedItemHandler;
    'sirsoft-basic.updateNoOptionQuantity': typeof updateNoOptionQuantityHandler;
    'sirsoft-basic.setBlockAdditionalOption': typeof setBlockAdditionalOptionHandler;
    'sirsoft-basic.getDisplayPrice': typeof getDisplayPriceHandler;
    'sirsoft-basic.formatCurrency': typeof formatCurrencyHandler;
    'sirsoft-basic.getCurrencySymbol': typeof getCurrencySymbol;
    'sirsoft-basic.loadPreferredCurrency': typeof loadPreferredCurrencyHandler;
    'sirsoft-basic.savePreferredCurrency': typeof savePreferredCurrencyHandler;
    setTheme: typeof setThemeHandler;
    initTheme: typeof initThemeHandler;
    redirectToLoginWithReturn: typeof redirectToLoginWithReturnHandler;
    downloadAttachment: typeof downloadAttachmentHandler;
    'sirsoft-basic.markLocalOnce': typeof markLocalOnceHandler;
    'sirsoft-basic.rememberIdentityClaim': typeof rememberIdentityClaimHandler;
    toggleCartItemSelection: typeof toggleCartItemSelectionHandler;
    selectAllCartItems: typeof selectAllCartItemsHandler;
    setCartOption: typeof setCartOptionHandler;
    openCartDeleteModal: typeof openCartDeleteModalHandler;
    openCartOptionModal: typeof openCartOptionModalHandler;
    recalculateCart: typeof recalculateCartHandler;
    findMatchingOption: typeof findMatchingOptionHandler;
    initCartOptionSelection: typeof initCartOptionSelectionHandler;
    initCartKey: typeof initCartKeyHandler;
    ensureCartKey: typeof ensureCartKeyHandler;
    getCartKey: typeof getCartKeyHandler;
    clearCartKey: typeof clearCartKeyHandler;
    regenerateCartKey: typeof regenerateCartKeyHandler;
    saveToStorage: typeof saveToStorageHandler;
    loadFromStorage: typeof loadFromStorageHandler;
    initGuestOrderToken: typeof initGuestOrderTokenHandler;
    saveGuestOrderToken: typeof saveGuestOrderTokenHandler;
    clearGuestOrderToken: typeof clearGuestOrderTokenHandler;
    clearGuestTokenOnEntry: typeof clearGuestTokenOnEntryHandler;
    initSecretViewToken: typeof initSecretViewTokenHandler;
    saveSecretViewToken: typeof saveSecretViewTokenHandler;
    clearSecretViewToken: typeof clearSecretViewTokenHandler;
};
/**
 * 핸들러 타입 정의 (TypeScript 자동완성용)
 */
export type SirsoftBasicHandlers = typeof handlers;
export { addSelectedItemIfCompleteHandler, updateSelectedItemQuantityHandler, removeSelectedItemHandler, updateNoOptionQuantityHandler, setBlockAdditionalOptionHandler, getDisplayPriceHandler, formatCurrencyHandler, getCurrencySymbol, loadPreferredCurrencyHandler, savePreferredCurrencyHandler, setThemeHandler, initThemeHandler, redirectToLoginWithReturnHandler, downloadAttachmentHandler, markLocalOnceHandler, toggleCartItemSelectionHandler, selectAllCartItemsHandler, setCartOptionHandler, openCartDeleteModalHandler, openCartOptionModalHandler, recalculateCartHandler, findMatchingOptionHandler, initCartOptionSelectionHandler, initCartKeyHandler, ensureCartKeyHandler, getCartKeyHandler, clearCartKeyHandler, regenerateCartKeyHandler, saveToStorageHandler, loadFromStorageHandler, initGuestOrderTokenHandler, saveGuestOrderTokenHandler, clearGuestOrderTokenHandler, clearGuestTokenOnEntryHandler, initSecretViewTokenHandler, saveSecretViewTokenHandler, clearSecretViewTokenHandler, };
