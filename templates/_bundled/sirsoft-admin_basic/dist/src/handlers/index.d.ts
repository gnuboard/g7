import { setLocaleHandler } from './setLocaleHandler';
import { setThemeHandler, initThemeHandler } from './setThemeHandler';
import { scrollToSectionHandler } from './scrollToSectionHandler';
import { initMenuFromUrlHandler } from './initMenuFromUrlHandler';
import { initFilterVisibilityHandler, saveFilterVisibilityHandler, toggleFilterVisibilityHandler, resetFilterVisibilityHandler } from './filterVisibilityHandler';
import { saveMultilingualTagHandler, cancelMultilingualTagHandler, updateMultilingualTagValueHandler } from './multilingualTagHandler';
import { setDateRangeHandler } from './setDateRangeHandler';
/**
 * 핸들러 맵
 *
 * 키: 핸들러 이름 (ActionDispatcher에 등록될 이름)
 * 값: 핸들러 함수
 *
 * 새로운 핸들러 추가 시 여기에만 등록하면 자동으로 ActionDispatcher에 등록됩니다.
 */
export declare const handlerMap: {
    readonly setLocale: typeof setLocaleHandler;
    readonly setTheme: typeof setThemeHandler;
    readonly initTheme: typeof initThemeHandler;
    readonly scrollToSection: typeof scrollToSectionHandler;
    readonly initMenuFromUrl: typeof initMenuFromUrlHandler;
    readonly initFilterVisibility: typeof initFilterVisibilityHandler;
    readonly saveFilterVisibility: typeof saveFilterVisibilityHandler;
    readonly toggleFilterVisibility: typeof toggleFilterVisibilityHandler;
    readonly resetFilterVisibility: typeof resetFilterVisibilityHandler;
    readonly saveMultilingualTag: typeof saveMultilingualTagHandler;
    readonly cancelMultilingualTag: typeof cancelMultilingualTagHandler;
    readonly updateMultilingualTagValue: typeof updateMultilingualTagValueHandler;
    readonly setDateRange: typeof setDateRangeHandler;
};
