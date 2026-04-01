/**
 * sirsoft-ecommerce 모듈 핸들러
 *
 * 모듈에서 사용하는 모든 커스텀 핸들러를 정의합니다.
 */

// 기존 핸들러
import { updateProductFieldHandler } from './updateProductField';
import { updateOptionFieldHandler } from './updateOptionField';
import { buildProductColumnsHandler } from './buildProductColumns';
import { buildOptionColumnsHandler } from './buildOptionColumns';
import { calculateCurrencyPricesHandler } from './calculateCurrencyPrices';
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

// 카테고리 관련 핸들러
import {
    selectCategoryHandler,
    selectCategoryMobileHandler,
    addCategoryToSelectionHandler,
    removeCategoryFromSelectionHandler,
    getCategoryBreadcrumbHandler,
    validateCategoryPathHandler,
    initCategoryInfosFromProductHandler,
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
} from './optionHandlers';

// 이미지 관련 핸들러
import {
    uploadImagesHandler,
    setThumbnailHandler,
    confirmDeleteImageHandler,
    deleteImageHandler,
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
    updateActivityLogSortHandler,
    updateActivityLogPerPageHandler,
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
    toggleAutoMultiCurrencyHandler,
    setDefaultOptionHandler as setDefaultOptionHandler2,
    addOptionRowHandler,
    updateFormOptionFieldHandler,
    updateFormOptionCurrencyFieldHandler,
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
    toggleVisibleFilterHandler,
    syncOrderSelectionHandler,
    handleOrderRowActionHandler,
    processOrderBulkActionHandler,
    buildOrderBulkConfirmDataHandler,
    executeOrderBulkActionHandler,
    downloadOrderExcelHandler,
    saveVisibleColumnsHandler,
    loadVisibleColumnsHandler,
    loadVisibleFiltersHandler,
} from './orderHandlers';

// 상품 목록 핸들러
import { handleProductRowActionHandler } from './productListHandlers';

// 주문 상세 핸들러
import {
    initOrderDetailFormHandler,
    toggleProductSelectionHandler,
    toggleAllProductsHandler,
    buildOrderDetailBulkConfirmDataHandler,
    processOrderDetailBulkChangeHandler,
    saveAdminMemoHandler,
    updateChangeQuantityHandler,
} from './orderDetailHandlers';

// 주문 취소 핸들러 (관리자)
import {
    updateCancelQuantityHandler,
    estimateRefundAmountHandler,
    changeRefundPriorityHandler,
    executeCancelOrderHandler,
    clearCancelOrderTimers,
} from './cancelOrderHandlers';

// 주문 취소 핸들러 (사용자)
import {
    toggleItemSelectionHandler,
    toggleSelectAllItemsHandler,
    initUserCancelItemsHandler,
    toggleUserCancelItemHandler,
    toggleUserCancelSelectAllHandler,
    updateUserCancelQuantityHandler,
    estimateUserRefundHandler,
    changeUserRefundPriorityHandler,
    executeUserCancelOrderHandler,
    clearUserCancelOrderTimers,
} from './userCancelOrderHandlers';

// 구매확정 핸들러 (사용자)
import { confirmOrderOptionHandler } from './userConfirmOrderHandlers';

// 리뷰 작성 핸들러 (사용자)
import { submitReviewHandler } from './userReviewHandlers';

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
    updateExtraFeeFieldHandler,
} from './shippingPolicyFormHandlers';

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
    buildProductColumns: buildProductColumnsHandler,
    buildOptionColumns: buildOptionColumnsHandler,
    calculateCurrencyPrices: calculateCurrencyPricesHandler,
    setDateRange: setDateRangeHandler,
    setDefaultOption: setDefaultOptionHandler,
    // 옵션 선택 관련 핸들러
    toggleOption: toggleOptionHandler,
    toggleProductOptions: toggleProductOptionsHandler,
    toggleAllOptionsInRow: toggleAllOptionsInRowHandler,
    getProductOptionStates: getProductOptionStatesHandler,
    syncProductSelection: syncProductSelectionHandler,
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
    initCategoryInfosFromProduct: initCategoryInfosFromProductHandler,

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

    // ===== 이미지 관련 =====
    uploadImages: uploadImagesHandler,
    setThumbnail: setThumbnailHandler,
    confirmDeleteImage: confirmDeleteImageHandler,
    deleteImage: deleteImageHandler,
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
    updateActivityLogSort: updateActivityLogSortHandler,
    updateActivityLogPerPage: updateActivityLogPerPageHandler,
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
    toggleAutoMultiCurrency: toggleAutoMultiCurrencyHandler,
    setDefaultOptionFromGrid: setDefaultOptionHandler2,
    addOptionRow: addOptionRowHandler,
    updateFormOptionField: updateFormOptionFieldHandler,
    updateFormOptionCurrencyField: updateFormOptionCurrencyFieldHandler,
    recalculateOptionPriceAdjustments: recalculateOptionPriceAdjustmentsHandler,

    // ===== 통합 일괄 업데이트 =====
    bulkUpdate: bulkUpdateHandler,
    buildConfirmData: buildConfirmDataHandler,

    // ===== 주문관리 =====
    buildOrderColumns: buildOrderColumnsHandler,
    toggleArrayValue: toggleArrayValueHandler,
    toggleVisibleFilter: toggleVisibleFilterHandler,
    syncOrderSelection: syncOrderSelectionHandler,
    handleOrderRowAction: handleOrderRowActionHandler,
    processOrderBulkAction: processOrderBulkActionHandler,
    buildOrderBulkConfirmData: buildOrderBulkConfirmDataHandler,
    executeOrderBulkAction: executeOrderBulkActionHandler,
    downloadOrderExcel: downloadOrderExcelHandler,
    saveVisibleColumns: saveVisibleColumnsHandler,
    loadVisibleColumns: loadVisibleColumnsHandler,
    loadVisibleFilters: loadVisibleFiltersHandler,

    // ===== 상품 목록 =====
    handleProductRowAction: handleProductRowActionHandler,

    // ===== 주문 상세 =====
    initOrderDetailForm: initOrderDetailFormHandler,
    toggleProductSelection: toggleProductSelectionHandler,
    toggleAllProducts: toggleAllProductsHandler,
    buildOrderDetailBulkConfirmData: buildOrderDetailBulkConfirmDataHandler,
    processOrderDetailBulkChange: processOrderDetailBulkChangeHandler,
    saveAdminMemo: saveAdminMemoHandler,
    updateChangeQuantity: updateChangeQuantityHandler,

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
    updateExtraFeeField: updateExtraFeeFieldHandler,

    // ===== 주문 취소 (관리자) =====
    updateCancelQuantity: updateCancelQuantityHandler,
    estimateRefundAmount: estimateRefundAmountHandler,
    changeRefundPriority: changeRefundPriorityHandler,
    executeCancelOrder: executeCancelOrderHandler,
    clearCancelOrderTimers: clearCancelOrderTimers,

    // ===== 주문 상품 선택 (사용자) =====
    toggleItemSelection: toggleItemSelectionHandler,
    toggleSelectAllItems: toggleSelectAllItemsHandler,

    // ===== 주문 취소 (사용자) =====
    initUserCancelItems: initUserCancelItemsHandler,
    toggleUserCancelItem: toggleUserCancelItemHandler,
    toggleUserCancelSelectAll: toggleUserCancelSelectAllHandler,
    updateUserCancelQuantity: updateUserCancelQuantityHandler,
    estimateUserRefund: estimateUserRefundHandler,
    changeUserRefundPriority: changeUserRefundPriorityHandler,
    executeUserCancelOrder: executeUserCancelOrderHandler,
    clearUserCancelOrderTimers: clearUserCancelOrderTimers,

    // ===== 구매확정 (사용자) =====
    confirmOrderOption: confirmOrderOptionHandler,

    // ===== 리뷰 작성 (사용자) =====
    submitReview: submitReviewHandler,

    // ===== URL 상태 복원 =====
    initCategoryFromUrl: initCategoryFromUrlHandler,
    initBrandFromUrl: initBrandFromUrlHandler,
    initCommonInfoFromUrl: initCommonInfoFromUrlHandler,
    initNoticeFromUrl: initNoticeFromUrlHandler,
} as const;
