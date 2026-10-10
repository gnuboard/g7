/**
 * 다국어 태그 편집 창 핸들러 (상품 옵션값)
 *
 * 상품 등록·수정 화면의 옵션값 입력(`MultilingualTagInput`, `modalId="multilingual_tag_edit_modal"`)은
 * 태그를 누르거나 새 값을 넣으면 편집 대상을 전역 상태 `_global.multilingualTagEdit` 에 싣고
 * 이 모듈의 다국어 태그 편집 창을 연다. 창은 그 전역 상태를 읽어 언어별 입력칸을 그리고, 이 파일의
 * 세 핸들러로 값 입력·취소·저장을 처리한다.
 *
 * `_global.multilingualTagEdit` 구조 (입력 컴포넌트가 채운다):
 * {
 *   fieldName: string,            // 입력 컴포넌트 name (예: 'option_values_0')
 *   editingIndex: number | null,  // null 이면 새 태그 추가, 숫자면 그 자리 태그 수정
 *   values: { [locale]: string }, // 언어별 값 — 창의 입력칸이 이 값을 보여 주고 고친다
 *   supportedLocales: Array<{ code: string, label: string }>,
 *   defaultLocale: string,
 *   statePath: string,            // 창을 연 화면 _local 안의 태그 배열 경로 (예: 'ui.optionInputs.0.values')
 *   currentTags: array,           // 창을 열 때의 태그 배열
 * }
 *
 * 저장은 창을 연 화면(상품 폼)의 `_local` 을 `statePath` 경로로 고친다 — 옵션값 입력칸의 change 액션
 * (`sirsoft-ecommerce.updateOptionInput`)이 쓰는 자리와 같다. 입력·취소·저장이 모두 전역 편집 상태를
 * 기준으로 움직이고, 창을 닫을 때 편집 상태를 비운다.
 *
 * 이 모듈이 직접 소유한다 — 상품 폼은 어느 관리자 템플릿으로 그려지든 같은 동작을 해야 하므로 특정
 * 템플릿이 등록한 핸들러에 기대지 않는다. 코어에는 이 편집 창 핸들러가 없다.
 */

import type { ActionContext } from '../types';

// Logger (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Ecommerce:MultilingualTag')) ?? {
    log: (...args: unknown[]) => console.log('[Ecommerce:MultilingualTag]', ...args),
    warn: (...args: unknown[]) => console.warn('[Ecommerce:MultilingualTag]', ...args),
    error: (...args: unknown[]) => console.error('[Ecommerce:MultilingualTag]', ...args),
};

/** 언어별 태그 값 */
type MultilingualValue = Record<string, string>;

/** `_global.multilingualTagEdit` 중 이 핸들러들이 읽는 부분 */
interface MultilingualTagEditState {
    fieldName?: string;
    editingIndex?: number | null;
    values?: MultilingualValue;
    statePath?: string;
    currentTags?: MultilingualValue[];
    [key: string]: unknown;
}

/** 액션 객체 중 이 핸들러들이 읽는 부분 */
interface MultilingualTagAction {
    params?: {
        locale?: unknown;
        value?: unknown;
    };
}

/**
 * 현재 편집 상태를 읽는다.
 *
 * @param G7Core 코어 전역 객체
 * @returns 편집 상태 (없으면 null)
 */
function getEditState(G7Core: any): MultilingualTagEditState | null {
    const state = G7Core?.state?.getGlobal?.()?.multilingualTagEdit;

    return state && typeof state === 'object' ? (state as MultilingualTagEditState) : null;
}

/**
 * 창을 닫고 편집 상태를 비운다.
 *
 * @param G7Core 코어 전역 객체
 */
function closeAndClear(G7Core: any): void {
    G7Core.modal?.close?.();
    G7Core.state?.setGlobal?.({ multilingualTagEdit: null });
}

/**
 * 언어별 입력칸 값을 편집 상태(`_global.multilingualTagEdit.values`)에 반영한다.
 *
 * 레이아웃은 `locale`(언어 코드)과 `value`(입력값)를 넘긴다. `value` 가 없으면 이벤트에서 읽는다.
 *
 * @param action `locale`·`value` 파라미터를 실은 액션 객체
 * @param context 액션 컨텍스트 (value 가 없을 때 이벤트를 읽는다)
 */
export function updateMultilingualTagValueHandler(
    action?: MultilingualTagAction,
    context?: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.error('[updateMultilingualTagValue] G7Core.state API is not available');
        return;
    }

    const locale = typeof action?.params?.locale === 'string' ? action.params.locale : '';
    if (locale === '') {
        logger.warn('[updateMultilingualTagValue] No locale specified');
        return;
    }

    let value = '';
    if (typeof action?.params?.value === 'string') {
        value = action.params.value;
    } else {
        const event = context?.event as any;
        if (typeof event?.target?.value === 'string') {
            value = event.target.value;
        } else if (typeof event?.value === 'string') {
            value = event.value;
        }
    }

    const editState = getEditState(G7Core) ?? {};

    G7Core.state.setGlobal({
        multilingualTagEdit: {
            ...editState,
            values: {
                ...(editState.values ?? {}),
                [locale]: value,
            },
        },
    });
}

/**
 * 편집을 취소한다 — 창을 닫고 편집 상태를 비운다. 태그 배열은 건드리지 않는다.
 *
 * @param _action 액션 객체 (미사용)
 * @param _context 액션 컨텍스트 (미사용)
 */
export function cancelMultilingualTagHandler(_action?: unknown, _context?: unknown): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core) {
        logger.error('[cancelMultilingualTag] G7Core not available');
        return;
    }

    closeAndClear(G7Core);
}

/**
 * 편집한 값을 태그 배열에 반영한다.
 *
 * 새 태그면 끝에 붙이고, 고치는 중이면 그 자리를 바꾼 배열을 창을 연 화면의 `_local` 에
 * `statePath` 경로(없으면 `form.{fieldName}`)로 쓴다. 언어별 값이 모두 비었으면 반영하지 않는다.
 * 어느 경우든 창을 닫고 편집 상태를 비운다.
 *
 * @param _action 액션 객체 (미사용)
 * @param _context 액션 컨텍스트 (미사용)
 */
export function saveMultilingualTagHandler(_action?: unknown, _context?: unknown): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core) {
        logger.error('[saveMultilingualTag] G7Core not available');
        return;
    }

    const editState = getEditState(G7Core);
    if (!editState) {
        logger.warn('[saveMultilingualTag] No multilingualTagEdit state found');
        closeAndClear(G7Core);
        return;
    }

    const { fieldName, editingIndex, statePath } = editState;
    const values: MultilingualValue = { ...(editState.values ?? {}) };

    const hasValue = Object.values(values).some((v) => typeof v === 'string' && v.trim() !== '');
    if (!hasValue) {
        closeAndClear(G7Core);
        return;
    }

    const currentTags = Array.isArray(editState.currentTags) ? editState.currentTags : [];
    const newTags =
        typeof editingIndex === 'number'
            ? currentTags.map((tag, idx) => (idx === editingIndex ? values : tag))
            : [...currentTags, values];

    const targetPath =
        typeof statePath === 'string' && statePath !== ''
            ? statePath
            : fieldName?.startsWith('form.')
              ? fieldName
              : `form.${fieldName ?? ''}`;

    G7Core.state?.setParentLocal?.({ [targetPath]: newTags });

    closeAndClear(G7Core);
}
