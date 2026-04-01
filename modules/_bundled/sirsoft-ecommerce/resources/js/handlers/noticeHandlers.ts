/**
 * 상품정보제공고시 관련 핸들러
 *
 * 상품 등록/수정 화면에서 상품정보제공고시 템플릿 관리, 항목 편집 기능을 처리합니다.
 */

import type { ActionContext } from '../types';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Ecom:Notice')) ?? {
    log: (...args: unknown[]) => console.log('[Ecom:Notice]', ...args),
    warn: (...args: unknown[]) => console.warn('[Ecom:Notice]', ...args),
    error: (...args: unknown[]) => console.error('[Ecom:Notice]', ...args),
};

/**
 * 지원하는 로케일에 대해 빈 문자열 객체를 생성합니다.
 * 하드코딩된 { ko: '', en: '' } 패턴 대신 사용합니다.
 *
 * @param defaultValue 기본값 (기본: 빈 문자열)
 * @returns 로케일별 빈 문자열 객체
 */
function createEmptyLocaleObject(defaultValue = ''): Record<string, string> {
    const G7Core = (window as any).G7Core;
    const supportedLocales: string[] = G7Core?.locale?.supported?.() ?? ['ko', 'en'];
    const result: Record<string, string> = {};
    for (const locale of supportedLocales) {
        result[locale] = defaultValue;
    }
    return result;
}

interface NoticeTemplate {
    id: number;
    name: Record<string, string>;
    fields: NoticeTemplateField[];
}

interface NoticeTemplateField {
    name: Record<string, string>;
    content: Record<string, string>;
}

interface NoticeItem {
    key: string;
    name: Record<string, string>;
    content: Record<string, string>;
    sort_order: number;
    is_custom?: boolean;
}

interface ActionWithParams {
    handler: string;
    params?: Record<string, any>;
    [key: string]: any;
}

/**
 * 상품정보제공고시 템플릿 선택을 확인합니다.
 * 기존 항목이 있으면 확인 모달을 표시하고, 없으면 바로 적용합니다.
 *
 * @param action 액션 객체 (params.templateId 필요)
 * @param context 액션 컨텍스트 (datasources.notice_templates 사용)
 */
export function confirmSelectNoticeTemplateHandler(
    action: ActionWithParams,
    context: ActionContext
): void {
    const params = action.params || {};
    const templateId = params.templateId as number | null;

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[confirmSelectNoticeTemplate] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};
    const existingItems: NoticeItem[] = state.form?.notice_items ?? [];
    const hasExistingItems = existingItems.length > 0 && existingItems.some(item => {
        const hasContent = Object.values(item.content || {}).some(v => v && v.trim() !== '');
        return hasContent;
    });

    // 템플릿 해제(null)이거나 기존 항목이 없으면 바로 적용
    if (!templateId || !hasExistingItems) {
        selectNoticeTemplateHandler(action, context);
        return;
    }

    // 기존 항목이 있으면 확인 모달 표시
    G7Core.state.setGlobal({
        pendingNoticeTemplateId: templateId,
    });
    G7Core.modal?.open?.('notice_template_confirm_modal');

    logger.log(`[confirmSelectNoticeTemplate] Showing confirm modal for template: ${templateId}`);
}

/**
 * 상품정보제공고시 템플릿을 선택합니다.
 *
 * @param action 액션 객체 (params.templateId 필요)
 * @param context 액션 컨텍스트 (datasources.notice_templates 사용)
 */
export function selectNoticeTemplateHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const params = action.params || {};
    const templateId = params.templateId as number | null;

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[selectNoticeTemplate] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};
    // G7Core.dataSource를 통해 데이터소스 접근 (context.data가 아닌 G7Core API 사용)
    const dsData = G7Core.dataSource?.get?.('notice_templates');
    const templates: NoticeTemplate[] = dsData?.data?.data ?? dsData?.data ?? [];

    if (!templateId) {
        G7Core.state.setLocal({
            form: {
                ...state.form,
                notice_items: [],
            },
            ui: {
                ...state.ui,
                selectedNoticeTemplateId: null,  // UI용 템플릿 선택 상태
            },
            hasChanges: true,
        });
        return;
    }

    const template = templates.find((t) => t.id === templateId);
    if (!template) {
        logger.warn(`[selectNoticeTemplate] Template not found: ${templateId}`);
        return;
    }

    // 기존 값 유지하면서 새 템플릿 항목으로 매핑
    const existingItems: NoticeItem[] = state.form?.notice_items ?? [];
    const newItems: NoticeItem[] = (template.fields ?? []).map((field, index) => {
        // 템플릿 필드의 name을 기준으로 기존 값 매칭
        const fieldKey = JSON.stringify(field.name);
        const existing = existingItems.find((e) => JSON.stringify(e.name) === fieldKey);
        return {
            key: `field_${index}`,
            name: field.name,
            content: existing?.content ?? field.content ?? createEmptyLocaleObject(),
            sort_order: index,
            is_custom: false,
        };
    });

    G7Core.state.setLocal({
        form: {
            ...state.form,
            notice_items: newItems,  // 템플릿은 UI용, form에 저장하지 않음
        },
        ui: {
            ...state.ui,
            selectedNoticeTemplateId: templateId,  // UI용 템플릿 선택 상태
        },
        hasChanges: true,
    });

    logger.log(`[selectNoticeTemplate] Selected template: ${templateId}`);
}

/**
 * 상품정보제공고시 항목 값을 업데이트합니다.
 *
 * @param action 액션 객체 (params.index, params.locale, params.value 필요)
 * @param _context 액션 컨텍스트
 */
export function updateNoticeItemHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const params = action.params || {};
    const index = params.index as number;
    const locale = params.locale as string;
    const value = params.value as string;

    if (index === undefined || !locale) {
        logger.warn('[updateNoticeItem] Missing required params');
        return;
    }

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[updateNoticeItem] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};
    const items = [...(state.form?.notice_items ?? [])];

    items[index] = {
        ...items[index],
        content: {
            ...items[index].content,
            [locale]: value,
        },
    };

    G7Core.state.setLocal({
        form: { ...state.form, notice_items: items },
        hasChanges: true,
    });
}

/**
 * 상품정보제공고시 항목을 삭제합니다.
 *
 * @param action 액션 객체 (params.index 필요)
 * @param _context 액션 컨텍스트
 */
export function removeNoticeItemHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const params = action.params || {};
    const index = params.index as number;

    if (index === undefined) {
        logger.warn('[removeNoticeItem] Missing index param');
        return;
    }

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[removeNoticeItem] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};
    const items = [...(state.form?.notice_items ?? [])];
    items.splice(index, 1);

    // sort_order 재정렬
    items.forEach((item, i) => {
        item.sort_order = i;
    });

    G7Core.state.setLocal({
        form: { ...state.form, notice_items: items },
        hasChanges: true,
    });

    logger.log(`[removeNoticeItem] Removed notice item at index ${index}`);
}

/**
 * 상품정보제공고시 항목 순서를 변경합니다.
 *
 * @param action 액션 객체 (params.oldIndex, params.newIndex 필요)
 * @param _context 액션 컨텍스트
 */
export function reorderNoticeItemsHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const params = action.params || {};
    const oldIndex = params.oldIndex as number;
    const newIndex = params.newIndex as number;

    if (oldIndex === undefined || newIndex === undefined) {
        logger.warn('[reorderNoticeItems] Missing oldIndex or newIndex param');
        return;
    }

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[reorderNoticeItems] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};
    const items = [...(state.form?.notice_items ?? [])];

    const [moved] = items.splice(oldIndex, 1);
    items.splice(newIndex, 0, moved);

    items.forEach((item, i) => {
        item.sort_order = i;
    });

    G7Core.state.setLocal({
        form: { ...state.form, notice_items: items },
        hasChanges: true,
    });

    logger.log(`[reorderNoticeItems] Moved item from ${oldIndex} to ${newIndex}`);
}

/**
 * 빈 항목에 일괄 입력합니다.
 *
 * @param action 액션 객체 (params.value 필요)
 * @param _context 액션 컨텍스트
 */
export function fillNoticeWithValueHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const params = action.params || {};
    const value = params.value as string;

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[fillNoticeWithValue] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};
    const locale = state.ui?.noticeLocale ?? 'ko';

    const items = (state.form?.notice_items ?? []).map((item: NoticeItem) => ({
        ...item,
        content: {
            ...item.content,
            [locale]: item.content?.[locale] || value, // 빈 값만 채움
        },
    }));

    G7Core.state.setLocal({
        form: { ...state.form, notice_items: items },
        hasChanges: true,
    });

    G7Core.toast?.success?.(
        G7Core.t?.('sirsoft-ecommerce.admin.product.notice.messages.filled')
        ?? 'Empty fields have been filled.'
    );
}

/**
 * 기존템플릿/신규등록 탭을 전환합니다.
 *
 * @param action 액션 객체 (params.mode 필요: 'existing' | 'new')
 * @param _context 액션 컨텍스트
 */
export function switchNoticeModeHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const params = action.params || {};
    const mode = params.mode as 'existing' | 'new';

    if (!mode) {
        logger.warn('[switchNoticeMode] Missing mode param');
        return;
    }

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[switchNoticeMode] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};

    G7Core.state.setLocal({
        ui: {
            ...state.ui,
            noticeMode: mode,
        },
    });

    logger.log(`[switchNoticeMode] Switched to ${mode} mode`);
}

/**
 * 신규 템플릿명을 업데이트합니다.
 *
 * @param action 액션 객체 (params.locale, params.value 필요)
 * @param _context 액션 컨텍스트
 */
export function updateNewTemplateNameHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const params = action.params || {};
    const locale = params.locale as string;
    const value = params.value as string;

    if (!locale) {
        logger.warn('[updateNewTemplateName] Missing locale param');
        return;
    }

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[updateNewTemplateName] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};

    G7Core.state.setLocal({
        form: {
            ...state.form,
            new_template_name: {
                ...state.form?.new_template_name,
                [locale]: value,
            },
        },
    });
}

/**
 * 신규등록 모드에서 고시 항목을 추가합니다.
 *
 * @param _action 액션 객체
 * @param _context 액션 컨텍스트
 */
export function addNoticeItemHandler(
    _action: ActionWithParams,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[addNoticeItem] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};
    const items: NoticeItem[] = state.form?.notice_items ?? [];

    const newItem: NoticeItem = {
        key: `custom_${Date.now()}`,
        name: createEmptyLocaleObject(),
        content: createEmptyLocaleObject(),
        sort_order: items.length,
        is_custom: true,
    };

    G7Core.state.setLocal({
        form: {
            ...state.form,
            notice_items: [...items, newItem],
        },
        hasChanges: true,
    });

    logger.log(`[addNoticeItem] Added new notice item. Total: ${items.length + 1}`);
}

/**
 * 고시 항목명을 수정합니다. (신규등록 모드에서)
 *
 * @param action 액션 객체 (params.index, params.locale, params.value 필요)
 * @param _context 액션 컨텍스트
 */
export function updateNoticeItemNameHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const params = action.params || {};
    const index = params.index as number;
    const locale = params.locale as string;
    const value = params.value as string;

    if (index === undefined || !locale) {
        logger.warn('[updateNoticeItemName] Missing required params');
        return;
    }

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[updateNoticeItemName] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};
    const items = [...(state.form?.notice_items ?? [])];

    items[index] = {
        ...items[index],
        name: {
            ...items[index].name,
            [locale]: value,
        },
    };

    G7Core.state.setLocal({
        form: { ...state.form, notice_items: items },
        hasChanges: true,
    });
}

/**
 * 현재 고시 항목들을 새 템플릿으로 저장 요청합니다.
 *
 * @param _action 액션 객체
 * @param _context 액션 컨텍스트
 */
export function saveAsNoticeTemplateHandler(
    _action: ActionWithParams,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[saveAsNoticeTemplate] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};
    const templateName = state.form?.new_template_name;

    if (!templateName?.ko?.trim()) {
        G7Core.toast?.warning?.(
            G7Core.t?.('sirsoft-ecommerce.admin.product.notice.messages.template_name_required')
            ?? 'Please enter template name.'
        );
        return;
    }

    const items: NoticeItem[] = state.form?.notice_items ?? [];
    if (items.length === 0) {
        G7Core.toast?.warning?.(
            G7Core.t?.('sirsoft-ecommerce.admin.product.notice.messages.no_items')
            ?? 'No items to save.'
        );
        return;
    }

    // 저장 확인 모달 표시
    G7Core.state.setLocal({
        ui: {
            ...state.ui,
            showSaveTemplateModal: true,
        },
    });
}

/**
 * 로케일별 '상세설명참조' 번역 맵
 * 다국어 파일(ko.json, en.json)의 admin.product_notice_template.form.detail_reference 값과 동기화 필요
 */
const DETAIL_REF_TRANSLATIONS: Record<string, string> = {
    ko: '상세설명참조',
    en: 'See detail description',
};

/**
 * 상품정보제공고시 템플릿의 모든 필드를 '상세설명참조'로 채웁니다.
 * (템플릿 관리 화면용)
 *
 * @param _action 액션 객체
 * @param _context 액션 컨텍스트
 */
export function fillTemplateFieldsWithDetailReferenceHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[fillTemplateFieldsWithDetailReference] G7Core.state API is not available');
        return;
    }

    // params로 전달받은 form 사용 (모달 등 별도 스코프에서 호출 시 필요)
    const params = action.params || {};
    const form = params.form || {};
    const fields = form.fields ?? [];

    if (fields.length === 0) {
        G7Core.toast?.warning?.(
            G7Core.t?.('sirsoft-ecommerce.admin.product_notice_template.messages.no_fields_to_change') ?? '변경할 항목이 없습니다.'
        );
        return;
    }

    // 지원하는 로케일 목록 가져오기
    const supportedLocales: string[] = G7Core.locale?.supported?.() ?? ['ko', 'en'];

    // 지원되는 로케일에 대한 '상세설명참조' content 객체 생성
    const detailRefContent: Record<string, string> = {};
    for (const locale of supportedLocales) {
        detailRefContent[locale] = DETAIL_REF_TRANSLATIONS[locale] ?? DETAIL_REF_TRANSLATIONS['en'] ?? 'See detail description';
    }

    // 모든 필드의 content를 '상세설명참조'로 변경
    // 각 필드에 새로운 객체를 할당하여 참조 공유 문제 방지
    const updatedFields = fields.map((field: { name: Record<string, string>; content: Record<string, string> }) => ({
        ...field,
        content: { ...detailRefContent },
    }));

    // form.fields 경로를 직접 업데이트하여 DynamicFieldList 바인딩 갱신 보장
    // onComponentEvent로 부모 컨텍스트에서 실행되므로 일반 setLocal 사용
    G7Core.state.setLocal({
        'form.fields': updatedFields,
    });

    G7Core.toast?.success?.(
        G7Core.t?.('sirsoft-ecommerce.admin.product_notice_template.messages.all_fields_changed_to_detail_ref') ?? '모든 항목이 \'상세설명참조\'로 변경되었습니다.'
    );
    logger.log('[fillTemplateFieldsWithDetailReference] All fields updated to detail reference');
}

/**
 * 상품 폼의 상품정보제공고시 항목의 모든 값을 '상세설명참조'로 채웁니다.
 * (상품 등록/수정 화면용)
 *
 * @param action 액션 객체
 * @param _context 액션 컨텍스트
 */
export function fillNoticeItemsWithDetailReferenceHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[fillNoticeItemsWithDetailReference] G7Core.state API is not available');
        return;
    }

    // params로 전달받은 form 사용 (모달 등 별도 스코프에서 호출 시 필요)
    const params = action.params || {};
    const form = params.form || {};
    const noticeItems = form.notice_items ?? [];

    if (noticeItems.length === 0) {
        G7Core.toast?.warning?.(
            G7Core.t?.('sirsoft-ecommerce.admin.product.notice.messages.no_items_to_change') ?? '변경할 항목이 없습니다.'
        );
        return;
    }

    // 지원하는 로케일 목록 가져오기
    const supportedLocales: string[] = G7Core.locale?.supported?.() ?? ['ko', 'en'];

    // 지원되는 로케일에 대한 '상세설명참조' content 객체 생성
    const detailRefContent: Record<string, string> = {};
    for (const locale of supportedLocales) {
        detailRefContent[locale] = DETAIL_REF_TRANSLATIONS[locale] ?? DETAIL_REF_TRANSLATIONS['en'] ?? 'See detail description';
    }

    // 모든 항목의 content를 '상세설명참조'로 변경
    // 각 항목에 새로운 객체를 할당하여 참조 공유 문제 방지
    const updatedItems = noticeItems.map((item: NoticeItem) => ({
        ...item,
        content: { ...detailRefContent },
    }));

    // form.notice_items 경로를 직접 업데이트하여 DynamicFieldList 바인딩 갱신 보장
    G7Core.state.setLocal({
        'form.notice_items': updatedItems,
    });

    G7Core.toast?.success?.(
        G7Core.t?.('sirsoft-ecommerce.admin.product.notice.messages.all_items_changed_to_detail_ref') ?? '모든 항목이 \'상세설명참조\'로 변경되었습니다.'
    );
    logger.log('[fillNoticeItemsWithDetailReference] All notice items updated to detail reference');
}

/**
 * 템플릿 저장을 확인합니다.
 *
 * @param _action 액션 객체
 * @param _context 액션 컨텍스트
 */
export async function confirmSaveNoticeTemplateHandler(
    _action: ActionWithParams,
    _context: ActionContext
): Promise<void> {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state || !G7Core?.api) {
        logger.warn('[confirmSaveNoticeTemplate] G7Core.state or G7Core.api is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};

    try {
        const items: NoticeItem[] = state.form?.notice_items ?? [];
        const response = await G7Core.api.post(
            '/api/modules/sirsoft-ecommerce/admin/products/notice-templates',
            {
                name: state.form?.new_template_name,
                items: items.map((item) => ({
                    key: item.key,
                    name: item.name,
                })),
            }
        );

        G7Core.state.setLocal({
            form: {
                ...state.form,
                new_template_name: createEmptyLocaleObject(),
            },
            ui: {
                ...state.ui,
                showSaveTemplateModal: false,
                noticeMode: 'existing',
            },
            hasChanges: true,
        });

        G7Core.toast?.success?.(
            G7Core.t?.('sirsoft-ecommerce.admin.product.notice.messages.template_saved')
            ?? 'Template has been saved.'
        );

        // 템플릿 목록 새로고침
        G7Core.datasources?.refresh?.('notice_templates');

        logger.log(`[confirmSaveNoticeTemplate] Template saved: ${response.data.id}`);
    } catch (error) {
        G7Core.toast?.error?.(
            G7Core.t?.('sirsoft-ecommerce.admin.product.notice.messages.template_save_error')
            ?? 'Failed to save template.'
        );
        logger.error('[confirmSaveNoticeTemplate] Failed to save template:', error);
    }
}
