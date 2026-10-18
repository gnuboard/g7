/**
 * sirsoft-ecommerce 모듈 핸들러
 *
 * 모듈에서 사용하는 모든 커스텀 핸들러를 정의합니다.
 */

// 기존 핸들러
import { updateProductFieldHandler } from './updateProductField';
import { updateOptionFieldHandler } from './updateOptionField';
import { calculateCurrencyPricesHandler } from './calculateCurrencyPrices';
import { initPreferredCurrencyHandler } from './initPreferredCurrency';
import { initPreferredShippingCountryHandler } from './initPreferredShippingCountry';
import { setDateRangeHandler } from './setDateRange';
import { setDefaultOptionHandler } from './setDefaultOption';
import {
    toggleOptionHandler,
    toggleProductOptionsHandler,
    toggleAllOptionsInRowHandler,
    getProductOptionStatesHandler,
    syncProductSelectionHandler,
} from './optionSelection';
import { generateCopyProductCodeHandler } from './generateCopyProductCode';
import { copyProductHandler } from './copyProduct';

// 목록 확장 행 옵션 지연 로딩
import {
    loadExpandedOptionsHandler,
    retryExpandedOptionsHandler,
} from './expandedOptionsHandlers';

// 카테고리 관련 핸들러
import {
    selectCategoryHandler,
    selectCategoryMobileHandler,
    addCategoryToSelectionHandler,
    removeCategoryFromSelectionHandler,
    getCategoryBreadcrumbHandler,
    validateCategoryPathHandler,
} from './categoryHandlers';

// 브랜드 관련 핸들러
import {
    getBrandNameHandler,
    getBrandDescriptionHandler,
} from './brandHandlers';

// 가격 관련 핸들러
import {
    updatePriceHandler,
    calculateTotalOptionStockHandler,
    validatePriceRelationHandler,
} from './priceHandlers';

// 옵션 관련 핸들러
import {
    addOptionInputHandler,
    removeOptionInputHandler,
    updateOptionInputHandler,
    generateOptionsHandler,
    deleteOptionHandler,
    applyOptionAddToolHandler,
    addRequiredItemHandler,
    updateRequiredItemHandler,
    removeRequiredItemHandler,
    reorderRequiredItemsHandler,
    addAdditionalOptionHandler,
    updateAdditionalOptionHandler,
    removeAdditionalOptionHandler,
    reorderAdditionalOptionsHandler,
    clearAdditionalOptionsHandler,
    addAdditionalOptionValueHandler,
    updateAdditionalOptionValueHandler,
    removeAdditionalOptionValueHandler,
} from './optionHandlers';

// 이미지 관련 핸들러
import {
    setThumbnailHandler,
    reorderImagesHandler,
} from './imageHandlers';

// 상세설명 관련 핸들러
import {
    updateDescriptionHandler,
} from './descriptionHandlers';

// 상품정보제공고시 관련 핸들러
import {
    confirmSelectNoticeTemplateHandler,
    selectNoticeTemplateHandler,
    updateNoticeItemHandler,
    removeNoticeItemHandler,
    reorderNoticeItemsHandler,
    fillNoticeWithValueHandler,
    switchNoticeModeHandler,
    updateNewTemplateNameHandler,
    addNoticeItemHandler,
    updateNoticeItemNameHandler,
    saveAsNoticeTemplateHandler,
    confirmSaveNoticeTemplateHandler,
    fillTemplateFieldsWithDetailReferenceHandler,
    fillNoticeItemsWithDetailReferenceHandler,
} from './noticeHandlers';

// 기타 섹션 핸들러
import {
    toggleLabelHandler,
    generateProductCodeHandler,
    getShippingPolicyInfoHandler,
    getCommonInfoContentHandler,
    updateShoppingIntegrationHandler,
    updateShippingTypeHandler,
    updateIdentificationCodeHandler,
    openLabelPeriodModalHandler,
    saveLabelPeriodHandler,
    removeLabelPeriodHandler,
    setDefaultShippingPolicyHandler,
    setLabelDatePresetHandler,
    toggleDefaultShippingPolicyHandler,
} from './miscHandlers';

// 라벨 할당 핸들러
import {
    toggleLabelAssignmentHandler,
    saveLabelSettingsHandler,
    deleteLabelHandler,
    updateLabelPeriodInlineHandler,
    setLabelDatePresetInlineHandler,
    confirmUncheckLabelHandler,
} from './labelHandlers';

// 상세설명 다국어 핸들러
import {
    removeDescriptionLocaleHandler,
    showAddLocaleModalHandler,
    addDescriptionLocaleHandler,
} from './descriptionLocaleHandlers';

// 상품옵션 추가 핸들러
import {
    setDefaultOptionHandler as setDefaultOptionHandler2,
    addOptionRowHandler,
    updateFormOptionFieldHandler,
    recalculateOptionPriceAdjustmentsHandler,
} from './productOptionHandlers';

// 통합 일괄 업데이트 핸들러
import {
    bulkUpdateHandler,
    buildConfirmDataHandler,
} from './bulkUpdateHandlers';

// 주문관리 핸들러
import {
    buildOrderColumnsHandler,
    toggleArrayValueHandler,
    syncOrderSelectionHandler,
    buildOrderBulkConfirmDataHandler,
    executeOrderBulkActionHandler,
    saveVisibleColumnsHandler,
    loadVisibleColumnsHandler,
    loadVisibleFiltersHandler,
} from './orderHandlers';

// 관리자 클레임 목록 핸들러 (S8b)
import {
    buildClaimBulkConfirmDataHandler,
    executeClaimBulkActionHandler,
    openClaimCompleteModalHandler,
    toggleClaimCompleteStockHandler,
    toggleClaimCompleteStockAllHandler,
    setClaimCompleteRefundRequestHandler,
    executeClaimCompletionHandler,
    clearClaimCompleteTimersHandler,
    openClaimDetailCompleteHandler,
    syncClaimItemSelectionHandler,
    openClaimItemQuantityConfirmHandler,
    updateClaimItemQuantityHandler,
    updateClaimBulkTrackingHandler,
    executeClaimItemBulkStatusHandler,
    executeClaimStatusTransitionHandler,
    storeClaimRefundRequestHandler,
    refreshClaimAttachmentsHandler,
    clearClaimImagesHandler,
    selectClaimItemBulkStatusHandler,
    selectClaimItemTrackingDirectionHandler,
    openClaimItemTrackingOnlyHandler,
    deliverClaimTrackingHandler,
    openClaimExchangeModeConfirmHandler,
    setClaimExchangeModeHandler,
    openClaimWithdrawOnBehalfHandler,
    withdrawClaimOnBehalfHandler,
    openClaimRequestEditHandler,
    updateClaimRequestOnBehalfHandler,
    restoreClaimStockHandler,
    toggleClaimCompleteUnreturnedHandler,
    setClaimCompleteUnreturnedReasonHandler,
    saveClaimAdminMemoHandler,
    saveClaimAmountsHandler,
    openClaimRefundExecuteHandler,
    executeClaimRefundHandler,
    formatRefundInputTotalHandler,
} from './claimHandlers';

// 상품 목록 핸들러

// 주문 상세 핸들러
import {
    initOrderDetailFormHandler,
    toggleProductSelectionHandler,
    toggleAllProductsHandler,
    buildOrderDetailBulkConfirmDataHandler,
    processOrderDetailBulkChangeHandler,
    saveAdminMemoHandler,
    updateChangeQuantityHandler,
    openConfirmDepositModalHandler,
    confirmDepositHandler,
} from './orderDetailHandlers';

// 수동 결제 처리 확인 창 핸들러 (Q13 — 재고 차감·사용 마일리지 차감·결제금액 기록)
import { setManualPaidOptionHandler } from './manualPaidEffectsHandlers';

// 주문 취소 핸들러 (관리자)
import {
    updateCancelQuantityHandler,
    estimateRefundAmountHandler,
    changeRefundPriorityHandler,
    executeCancelOrderHandler,
    clearCancelOrderTimers,
} from './cancelOrderHandlers';
import {
    clearAdminClaimRequestTimersHandler,
    openAdminClaimRequestHandler,
    setAdminClaimRequestFieldHandler,
    setAdminClaimRequestRowHandler,
    submitAdminClaimRequestHandler,
} from './adminClaimRequestHandlers';

// 주문 상품 선택 핸들러 (회원/비회원 주문상세 공용 — 클레임 신청 진입이 쓴다)
import {
    toggleItemSelectionHandler,
    toggleSelectAllItemsHandler,
} from './orderItemSelectionHandlers';

// 구매확정 핸들러 (사용자)
import { confirmOrderOptionHandler } from './userConfirmOrderHandlers';

// 배송지 변경 핸들러 (회원/비회원 공용)
import { changeShippingAddressHandler } from './userChangeShippingAddressHandlers';

// 리뷰 작성 핸들러 (사용자)
import { submitReviewHandler } from './userReviewHandlers';

// 클레임 신청서 작성 핸들러 (회원/비회원 공용)
import {
    initClaimApplyHandler,
    toggleClaimItemHandler,
    toggleClaimSelectAllHandler,
    updateClaimQuantityHandler,
    setClaimReasonHandler,
    setClaimChargeMethodHandler,
    setClaimPaymentMethodHandler,
    startClaimRedeliveryEditHandler,
    applyClaimRedeliveryHandler,
    resetClaimRedeliveryHandler,
    openClaimChangeProductHandler,
    setClaimChangeProductModeHandler,
    applyClaimChangeProductHandler,
    estimateClaimHandler,
    submitClaimHandler,
    clearClaimApplyTimers,
    claimApplyOrderMissingHandler,
    initClaimEditHandler,
    saveClaimEditHandler,
    changeClaimRefundPriorityHandler,
    setClaimRefundAccountFieldHandler,
    toggleClaimComparisonHandler,
} from './claimApplyHandlers';

// 클레임 첨부 이미지 핸들러 (회원/비회원 공용)
import {
    setClaimImageFilesHandler,
    removeClaimImageHandler,
} from './claimImageHandlers';

// 클레임 신청내역 핸들러 (회원/비회원 공용) — 철회 · 미수납 추가결제
import {
    openClaimWithdrawHandler,
    closeClaimWithdrawHandler,
    withdrawClaimHandler,
    payClaimOutstandingHandler,
} from './claimListHandlers';

// 배송정책 폼 핸들러
import {
    initShippingPolicyFormHandler,
    addCountrySettingHandler,
    removeCountrySettingHandler,
    switchCountryTabHandler,
    updateCountryFieldHandler,
    onChargePolicyChangeHandler,
    addRangeTierHandler,
    removeRangeTierHandler,
    updateRangeTierFieldHandler,
    validateRangeTiersHandler,
    addExtraFeeRowHandler,
    removeExtraFeeRowHandler,
    applyExtraFeeTemplateHandler,
    updateUnitValueHandler,
    addApiRequestFieldHandler,
    updateApiRequestFieldHandler,
    removeApiRequestFieldHandler,
    toggleApiRequestFieldHandler,
    updateApiConfigFieldHandler,
    updateApiFieldMapHandler,
    testShippingApiHandler,
    updateExtraFeeFieldHandler,
} from './shippingPolicyFormHandlers';

// 파일 내려받기·다국어 태그 편집 창 (관리자 템플릿 핸들러에 기대지 않도록 모듈이 소유)
import { downloadFileHandler } from './downloadFile';
import {
    updateMultilingualTagValueHandler,
    cancelMultilingualTagHandler,
    saveMultilingualTagHandler,
} from './multilingualTagHandlers';

// 회원 목록 확장 필터 값 조립 (관리자 템플릿 핸들러에 기대지 않도록 모듈이 소유)
import { setUserFilterExtHandler } from './userListFilterExtHandlers';

// URL 상태 복원 핸들러
import { initCategoryFromUrlHandler } from './initCategoryFromUrl';
import { initBrandFromUrlHandler } from './initBrandFromUrl';
import { initCommonInfoFromUrlHandler } from './initCommonInfoFromUrl';
import { initNoticeFromUrlHandler } from './initNoticeFromUrl';

/**
 * 핸들러 맵
 *
 * 키: 핸들러 이름 (네임스페이스 없이)
 * 값: 핸들러 함수
 *
 * ActionDispatcher에 등록 시 모듈 식별자가 네임스페이스로 추가됩니다.
 * 예: 'updateProductField' -> 'sirsoft-ecommerce.updateProductField'
 */
export const handlerMap = {
    // ===== 기존 핸들러 =====
    updateProductField: updateProductFieldHandler,
    updateOptionField: updateOptionFieldHandler,
    calculateCurrencyPrices: calculateCurrencyPricesHandler,
    initPreferredCurrency: initPreferredCurrencyHandler,
    initPreferredShippingCountry: initPreferredShippingCountryHandler,
    setDateRange: setDateRangeHandler,
    setDefaultOption: setDefaultOptionHandler,
    // 옵션 선택 관련 핸들러
    toggleOption: toggleOptionHandler,
    toggleProductOptions: toggleProductOptionsHandler,
    toggleAllOptionsInRow: toggleAllOptionsInRowHandler,
    getProductOptionStates: getProductOptionStatesHandler,
    syncProductSelection: syncProductSelectionHandler,
    // 목록 확장 행 옵션 지연 로딩
    loadExpandedOptions: loadExpandedOptionsHandler,
    retryExpandedOptions: retryExpandedOptionsHandler,
    // 상품 폼 모달 관련 핸들러
    generateCopyProductCode: generateCopyProductCodeHandler,
    copyProduct: copyProductHandler,

    // ===== 카테고리 관련 =====
    selectCategory: selectCategoryHandler,
    selectCategoryMobile: selectCategoryMobileHandler,
    addCategoryToSelection: addCategoryToSelectionHandler,
    removeCategoryFromSelection: removeCategoryFromSelectionHandler,
    getCategoryBreadcrumb: getCategoryBreadcrumbHandler,
    validateCategoryPath: validateCategoryPathHandler,

    // ===== 브랜드 관련 =====
    getBrandName: getBrandNameHandler,
    getBrandDescription: getBrandDescriptionHandler,

    // ===== 가격 관련 =====
    updatePrice: updatePriceHandler,
    calculateTotalOptionStock: calculateTotalOptionStockHandler,
    validatePriceRelation: validatePriceRelationHandler,

    // ===== 옵션 관련 =====
    addOptionInput: addOptionInputHandler,
    removeOptionInput: removeOptionInputHandler,
    updateOptionInput: updateOptionInputHandler,
    generateOptions: generateOptionsHandler,
    deleteOption: deleteOptionHandler,
    applyOptionAddTool: applyOptionAddToolHandler,
    addRequiredItem: addRequiredItemHandler,
    updateRequiredItem: updateRequiredItemHandler,
    removeRequiredItem: removeRequiredItemHandler,
    reorderRequiredItems: reorderRequiredItemsHandler,
    addAdditionalOption: addAdditionalOptionHandler,
    updateAdditionalOption: updateAdditionalOptionHandler,
    removeAdditionalOption: removeAdditionalOptionHandler,
    reorderAdditionalOptions: reorderAdditionalOptionsHandler,
    clearAdditionalOptions: clearAdditionalOptionsHandler,
    addAdditionalOptionValue: addAdditionalOptionValueHandler,
    updateAdditionalOptionValue: updateAdditionalOptionValueHandler,
    removeAdditionalOptionValue: removeAdditionalOptionValueHandler,

    // ===== 다국어 태그 편집 창 (상품 옵션값) =====
    updateMultilingualTagValue: updateMultilingualTagValueHandler,
    cancelMultilingualTag: cancelMultilingualTagHandler,
    saveMultilingualTag: saveMultilingualTagHandler,

    // ===== 이미지 관련 =====
    setThumbnail: setThumbnailHandler,
    reorderImages: reorderImagesHandler,

    // ===== 상세설명 관련 =====
    updateDescription: updateDescriptionHandler,

    // ===== 상품정보제공고시 관련 =====
    confirmSelectNoticeTemplate: confirmSelectNoticeTemplateHandler,
    selectNoticeTemplate: selectNoticeTemplateHandler,
    updateNoticeItem: updateNoticeItemHandler,
    removeNoticeItem: removeNoticeItemHandler,
    reorderNoticeItems: reorderNoticeItemsHandler,
    fillNoticeWithValue: fillNoticeWithValueHandler,
    switchNoticeMode: switchNoticeModeHandler,
    updateNewTemplateName: updateNewTemplateNameHandler,
    addNoticeItem: addNoticeItemHandler,
    updateNoticeItemName: updateNoticeItemNameHandler,
    saveAsNoticeTemplate: saveAsNoticeTemplateHandler,
    confirmSaveNoticeTemplate: confirmSaveNoticeTemplateHandler,
    fillTemplateFieldsWithDetailReference: fillTemplateFieldsWithDetailReferenceHandler,
    fillNoticeItemsWithDetailReference: fillNoticeItemsWithDetailReferenceHandler,

    // ===== 기타 섹션 =====
    toggleLabel: toggleLabelHandler,
    generateProductCode: generateProductCodeHandler,
    getShippingPolicyInfo: getShippingPolicyInfoHandler,
    getCommonInfoContent: getCommonInfoContentHandler,
    updateShoppingIntegration: updateShoppingIntegrationHandler,
    updateShippingType: updateShippingTypeHandler,
    updateIdentificationCode: updateIdentificationCodeHandler,
    openLabelPeriodModal: openLabelPeriodModalHandler,
    saveLabelPeriod: saveLabelPeriodHandler,
    removeLabelPeriod: removeLabelPeriodHandler,
    setDefaultShippingPolicy: setDefaultShippingPolicyHandler,
    setLabelDatePreset: setLabelDatePresetHandler,
    toggleDefaultShippingPolicy: toggleDefaultShippingPolicyHandler,

    // ===== 라벨 할당 =====
    toggleLabelAssignment: toggleLabelAssignmentHandler,
    saveLabelSettings: saveLabelSettingsHandler,
    deleteLabel: deleteLabelHandler,
    updateLabelPeriodInline: updateLabelPeriodInlineHandler,
    setLabelDatePresetInline: setLabelDatePresetInlineHandler,
    confirmUncheckLabel: confirmUncheckLabelHandler,

    // ===== 상세설명 다국어 =====
    removeDescriptionLocale: removeDescriptionLocaleHandler,
    showAddLocaleModal: showAddLocaleModalHandler,
    addDescriptionLocale: addDescriptionLocaleHandler,

    // ===== 상품옵션 추가 =====
    setDefaultOptionFromGrid: setDefaultOptionHandler2,
    addOptionRow: addOptionRowHandler,
    updateFormOptionField: updateFormOptionFieldHandler,
    recalculateOptionPriceAdjustments: recalculateOptionPriceAdjustmentsHandler,

    // ===== 통합 일괄 업데이트 =====
    bulkUpdate: bulkUpdateHandler,
    buildConfirmData: buildConfirmDataHandler,

    // ===== 주문관리 =====
    buildOrderColumns: buildOrderColumnsHandler,
    toggleArrayValue: toggleArrayValueHandler,
    syncOrderSelection: syncOrderSelectionHandler,
    buildOrderBulkConfirmData: buildOrderBulkConfirmDataHandler,
    executeOrderBulkAction: executeOrderBulkActionHandler,
    downloadFile: downloadFileHandler,
    saveVisibleColumns: saveVisibleColumnsHandler,
    loadVisibleColumns: loadVisibleColumnsHandler,
    loadVisibleFilters: loadVisibleFiltersHandler,

    // ===== 관리자 클레임 목록 (S8b) =====
    buildClaimBulkConfirmData: buildClaimBulkConfirmDataHandler,
    executeClaimBulkAction: executeClaimBulkActionHandler,
    openClaimCompleteModal: openClaimCompleteModalHandler,
    toggleClaimCompleteStock: toggleClaimCompleteStockHandler,
    toggleClaimCompleteStockAll: toggleClaimCompleteStockAllHandler,
    setClaimCompleteRefundRequest: setClaimCompleteRefundRequestHandler,
    executeClaimCompletion: executeClaimCompletionHandler,
    clearClaimCompleteTimers: clearClaimCompleteTimersHandler,
    openClaimDetailComplete: openClaimDetailCompleteHandler,
    syncClaimItemSelection: syncClaimItemSelectionHandler,
    openClaimItemQuantityConfirm: openClaimItemQuantityConfirmHandler,
    updateClaimItemQuantity: updateClaimItemQuantityHandler,
    updateClaimBulkTracking: updateClaimBulkTrackingHandler,
    executeClaimItemBulkStatus: executeClaimItemBulkStatusHandler,
    executeClaimStatusTransition: executeClaimStatusTransitionHandler,
    storeClaimRefundRequest: storeClaimRefundRequestHandler,
    refreshClaimAttachments: refreshClaimAttachmentsHandler,
    clearClaimImages: clearClaimImagesHandler,
    selectClaimItemBulkStatus: selectClaimItemBulkStatusHandler,
    selectClaimItemTrackingDirection: selectClaimItemTrackingDirectionHandler,
    openClaimItemTrackingOnly: openClaimItemTrackingOnlyHandler,
    deliverClaimTracking: deliverClaimTrackingHandler,
    openClaimExchangeModeConfirm: openClaimExchangeModeConfirmHandler,
    setClaimExchangeMode: setClaimExchangeModeHandler,
    openClaimWithdrawOnBehalf: openClaimWithdrawOnBehalfHandler,
    withdrawClaimOnBehalf: withdrawClaimOnBehalfHandler,
    openClaimRequestEdit: openClaimRequestEditHandler,
    updateClaimRequestOnBehalf: updateClaimRequestOnBehalfHandler,
    restoreClaimStock: restoreClaimStockHandler,
    toggleClaimCompleteUnreturned: toggleClaimCompleteUnreturnedHandler,
    setClaimCompleteUnreturnedReason: setClaimCompleteUnreturnedReasonHandler,
    saveClaimAdminMemo: saveClaimAdminMemoHandler,
    saveClaimAmounts: saveClaimAmountsHandler,
    openClaimRefundExecute: openClaimRefundExecuteHandler,
    executeClaimRefund: executeClaimRefundHandler,
    formatRefundInputTotal: formatRefundInputTotalHandler,

    // ===== 상품 목록 =====

    // ===== 주문 상세 =====
    initOrderDetailForm: initOrderDetailFormHandler,
    toggleProductSelection: toggleProductSelectionHandler,
    toggleAllProducts: toggleAllProductsHandler,
    buildOrderDetailBulkConfirmData: buildOrderDetailBulkConfirmDataHandler,
    processOrderDetailBulkChange: processOrderDetailBulkChangeHandler,
    saveAdminMemo: saveAdminMemoHandler,
    updateChangeQuantity: updateChangeQuantityHandler,
    setManualPaidOption: setManualPaidOptionHandler,
    openConfirmDepositModal: openConfirmDepositModalHandler,
    confirmDeposit: confirmDepositHandler,

    // ===== 배송정책 폼 =====
    initShippingPolicyForm: initShippingPolicyFormHandler,
    addCountrySetting: addCountrySettingHandler,
    removeCountrySetting: removeCountrySettingHandler,
    switchCountryTab: switchCountryTabHandler,
    updateCountryField: updateCountryFieldHandler,
    onChargePolicyChange: onChargePolicyChangeHandler,
    addRangeTier: addRangeTierHandler,
    removeRangeTier: removeRangeTierHandler,
    updateRangeTierField: updateRangeTierFieldHandler,
    validateRangeTiers: validateRangeTiersHandler,
    addExtraFeeRow: addExtraFeeRowHandler,
    removeExtraFeeRow: removeExtraFeeRowHandler,
    applyExtraFeeTemplate: applyExtraFeeTemplateHandler,
    updateUnitValue: updateUnitValueHandler,
    addApiRequestField: addApiRequestFieldHandler,
    updateApiRequestField: updateApiRequestFieldHandler,
    removeApiRequestField: removeApiRequestFieldHandler,
    toggleApiRequestField: toggleApiRequestFieldHandler,
    updateApiConfigField: updateApiConfigFieldHandler,
    updateApiFieldMap: updateApiFieldMapHandler,
    testShippingApi: testShippingApiHandler,
    updateExtraFeeField: updateExtraFeeFieldHandler,

    // ===== 주문 취소 (관리자) =====
    updateCancelQuantity: updateCancelQuantityHandler,
    estimateRefundAmount: estimateRefundAmountHandler,
    changeRefundPriority: changeRefundPriorityHandler,
    executeCancelOrder: executeCancelOrderHandler,
    clearCancelOrderTimers: clearCancelOrderTimers,
    // 관리자 직권 접수 (§3-11) — 주문 상세 선택 바 [교환 접수]·[반품 접수]
    clearAdminClaimRequestTimers: clearAdminClaimRequestTimersHandler,
    openAdminClaimRequest: openAdminClaimRequestHandler,
    setAdminClaimRequestField: setAdminClaimRequestFieldHandler,
    setAdminClaimRequestRow: setAdminClaimRequestRowHandler,
    submitAdminClaimRequest: submitAdminClaimRequestHandler,

    // ===== 주문 상품 선택 (사용자) =====
    toggleItemSelection: toggleItemSelectionHandler,
    toggleSelectAllItems: toggleSelectAllItemsHandler,

    // ===== 구매확정 (사용자) =====
    confirmOrderOption: confirmOrderOptionHandler,

    // ===== 배송지 변경 (회원/비회원 공용) =====
    changeShippingAddress: changeShippingAddressHandler,

    // ===== 리뷰 작성 (사용자) =====
    submitReview: submitReviewHandler,

    // ===== 클레임 신청서 작성 (회원/비회원 공용) =====
    initClaimApply: initClaimApplyHandler,
    toggleClaimItem: toggleClaimItemHandler,
    toggleClaimSelectAll: toggleClaimSelectAllHandler,
    updateClaimQuantity: updateClaimQuantityHandler,
    setClaimReason: setClaimReasonHandler,
    setClaimChargeMethod: setClaimChargeMethodHandler,
    setClaimPaymentMethod: setClaimPaymentMethodHandler,
    startClaimRedeliveryEdit: startClaimRedeliveryEditHandler,
    applyClaimRedelivery: applyClaimRedeliveryHandler,
    resetClaimRedelivery: resetClaimRedeliveryHandler,
    openClaimChangeProduct: openClaimChangeProductHandler,
    setClaimChangeProductMode: setClaimChangeProductModeHandler,
    applyClaimChangeProduct: applyClaimChangeProductHandler,
    estimateClaim: estimateClaimHandler,
    submitClaim: submitClaimHandler,
    clearClaimApplyTimers: clearClaimApplyTimers,
    // 주문금액 비교 섹션(#599 후속) — 환불 우선순위 전환(미리보기 재호출) · 접기/펼치기
    changeClaimRefundPriority: changeClaimRefundPriorityHandler,
    setClaimRefundAccountField: setClaimRefundAccountFieldHandler,
    toggleClaimComparison: toggleClaimComparisonHandler,

    // ===== 클레임 신청서 수정 (회원/비회원 공용 — 작성 셸의 edit 모드) =====
    claimApplyOrderMissing: claimApplyOrderMissingHandler,
    initClaimEdit: initClaimEditHandler,
    saveClaimEdit: saveClaimEditHandler,

    // ===== 클레임 첨부 이미지 (회원/비회원 공용) =====
    setClaimImageFiles: setClaimImageFilesHandler,
    removeClaimImage: removeClaimImageHandler,

    // ===== 클레임 신청내역 (회원/비회원 공용) =====
    openClaimWithdraw: openClaimWithdrawHandler,
    closeClaimWithdraw: closeClaimWithdrawHandler,
    withdrawClaim: withdrawClaimHandler,
    payClaimOutstanding: payClaimOutstandingHandler,

    // ===== 회원 목록 확장 필터 (관리자 회원 목록 필터 확장점) =====
    setUserFilterExt: setUserFilterExtHandler,

    // ===== URL 상태 복원 =====
    initCategoryFromUrl: initCategoryFromUrlHandler,
    initBrandFromUrl: initBrandFromUrlHandler,
    initCommonInfoFromUrl: initCommonInfoFromUrlHandler,
    initNoticeFromUrl: initNoticeFromUrlHandler,
} as const;
