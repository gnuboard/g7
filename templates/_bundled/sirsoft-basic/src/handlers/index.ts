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
  removeSelectedItemHandler,
  updateNoOptionQuantityHandler,
  setBlockAdditionalOptionHandler,
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
  initGuestOrderTokenHandler,
  saveGuestOrderTokenHandler,
  clearGuestOrderTokenHandler,
  clearGuestTokenOnEntryHandler,
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

// 인증 리다이렉트 관련 핸들러
import { redirectToLoginWithReturnHandler } from './redirectToLoginWithReturn';

// 게시판 첨부 다운로드 핸들러 (토큰 동반 → 활동이력 행위자 기록)
import { downloadAttachmentHandler } from './downloadAttachment';

// 데이터소스 onSuccess 의 「한 번만」 가드 (onSuccess 컨텍스트에는 _local 이 없다)
import { markLocalOnceHandler } from './markLocalOnce';
import { rememberIdentityClaimHandler } from './identityLauncher';

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
  'sirsoft-basic.removeSelectedItem': removeSelectedItemHandler,
  'sirsoft-basic.updateNoOptionQuantity': updateNoOptionQuantityHandler,
  'sirsoft-basic.setBlockAdditionalOption': setBlockAdditionalOptionHandler,

  // 다중 통화
  'sirsoft-basic.getDisplayPrice': getDisplayPriceHandler,
  'sirsoft-basic.formatCurrency': formatCurrencyHandler,
  'sirsoft-basic.getCurrencySymbol': getCurrencySymbol,
  'sirsoft-basic.loadPreferredCurrency': loadPreferredCurrencyHandler,
  'sirsoft-basic.savePreferredCurrency': savePreferredCurrencyHandler,

  // 테마
  setTheme: setThemeHandler,
  initTheme: initThemeHandler,

  // 인증 리다이렉트 (비로그인 게시판/비밀글 진입 → /login?redirect=현재경로)
  redirectToLoginWithReturn: redirectToLoginWithReturnHandler,

  // 게시판 첨부 다운로드 (토큰 동반 → 활동이력 행위자 기록)
  downloadAttachment: downloadAttachmentHandler,

  // 데이터소스 onSuccess 의 「한 번만」 가드 — 주문서 기본 배송지 채움이 목록 재조회마다 선택을 덮지 않게
  'sirsoft-basic.markLocalOnce': markLocalOnceHandler,
  // 본인인증 링크 방식 — [다시 보내기]가 받은 새 건의 일회용 비밀값을 처리기에 넘긴다(전역 상태에 두지 않는다).
  'sirsoft-basic.rememberIdentityClaim': rememberIdentityClaimHandler,

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
  initGuestOrderToken: initGuestOrderTokenHandler,
  saveGuestOrderToken: saveGuestOrderTokenHandler,
  clearGuestOrderToken: clearGuestOrderTokenHandler,
  clearGuestTokenOnEntry: clearGuestTokenOnEntryHandler,
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
  removeSelectedItemHandler,
  updateNoOptionQuantityHandler,
  setBlockAdditionalOptionHandler,
  getDisplayPriceHandler,
  formatCurrencyHandler,
  getCurrencySymbol,
  loadPreferredCurrencyHandler,
  savePreferredCurrencyHandler,
  setThemeHandler,
  initThemeHandler,
  // 인증 리다이렉트
  redirectToLoginWithReturnHandler,
  // 게시판 첨부 다운로드
  downloadAttachmentHandler,
  markLocalOnceHandler,
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
  initGuestOrderTokenHandler,
  saveGuestOrderTokenHandler,
  clearGuestOrderTokenHandler,
  clearGuestTokenOnEntryHandler,
};
