/**
 * @file multilingualTagHandlers.test.ts
 * @description sirsoft-ecommerce 다국어 태그 편집 창 핸들러 — 언어별 값 입력·취소·저장이 바꾸는 상태.
 *
 * 상품 폼 옵션값의 다국어 태그 편집 창이 접두어 없는 `updateMultilingualTagValue`·`cancelMultilingualTag`·
 * `saveMultilingualTag` 를 불렀는데, 그 이름들은 관리자 템플릿(sirsoft-admin_basic)이 등록한 템플릿
 * 핸들러라 다른 관리자 템플릿에서는 창의 입력·취소·저장이 모두 무반응이었다. 이 모듈이 핸들러를 소유한다.
 *
 * 상태 계약: 편집 중 값은 전역 `_global.multilingualTagEdit` 에 있고(입력 컴포넌트가 채움), 저장은 창을 연
 * 상품 폼의 `_local` 을 `statePath`(예: `ui.optionInputs.0.values`) 경로로 고친다(setParentLocal).
 *
 * @vitest-environment jsdom
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    cancelMultilingualTagHandler,
    saveMultilingualTagHandler,
    updateMultilingualTagValueHandler,
} from '../multilingualTagHandlers';

/**
 * 전역 상태를 흉내 내는 G7Core 목을 만든다 — setGlobal 은 얕은 병합으로 충분하다(핸들러가 전체 객체를 넘긴다).
 */
function makeG7Core(initialGlobal: Record<string, any>) {
    let globalState: Record<string, any> = { ...initialGlobal };
    const core = {
        state: {
            getGlobal: vi.fn(() => globalState),
            setGlobal: vi.fn((updates: Record<string, any>) => {
                globalState = { ...globalState, ...updates };
            }),
            setParentLocal: vi.fn(),
        },
        modal: { close: vi.fn() },
        current: () => globalState,
    };
    return core;
}

const baseEdit = {
    fieldName: 'option_values_0',
    editingIndex: null as number | null,
    values: { ko: '', en: '' },
    supportedLocales: [
        { code: 'ko', label: '한국어' },
        { code: 'en', label: 'English' },
    ],
    defaultLocale: 'ko',
    statePath: 'ui.optionInputs.0.values',
    currentTags: [
        { ko: '빨강', en: 'Red' },
        { ko: '파랑', en: 'Blue' },
    ],
};

describe('sirsoft-ecommerce 다국어 태그 편집 창 핸들러', () => {
    let originalG7Core: unknown;

    beforeEach(() => {
        originalG7Core = (window as any).G7Core;
    });

    afterEach(() => {
        (window as any).G7Core = originalG7Core;
        vi.restoreAllMocks();
    });

    /**
     * @scenario action=update
     * @effects update_writes_locale_value_into_global_edit_state
     */
    it('입력값을 전역 편집 상태의 그 언어 칸에 넣고 다른 언어·편집 정보는 보존한다', () => {
        const core = makeG7Core({ multilingualTagEdit: { ...baseEdit, values: { ko: '초록', en: '' } } });
        (window as any).G7Core = core;

        updateMultilingualTagValueHandler({ params: { locale: 'en', value: 'Green' } });

        const edit = core.current().multilingualTagEdit;
        expect(edit.values).toEqual({ ko: '초록', en: 'Green' });
        expect(edit.statePath).toBe('ui.optionInputs.0.values');
        expect(edit.editingIndex).toBeNull();
        expect(core.state.setParentLocal).not.toHaveBeenCalled();
    });

    /**
     * @scenario action=update
     * @effects update_writes_locale_value_into_global_edit_state
     */
    it('value 파라미터가 없으면 이벤트의 입력값을 쓴다', () => {
        const core = makeG7Core({ multilingualTagEdit: { ...baseEdit } });
        (window as any).G7Core = core;

        updateMultilingualTagValueHandler({ params: { locale: 'ko' } }, { event: { target: { value: '노랑' } } as any });

        expect(core.current().multilingualTagEdit.values.ko).toBe('노랑');
    });

    /**
     * @scenario action=update
     * @effects update_writes_locale_value_into_global_edit_state
     */
    it('언어 코드가 없으면 상태를 바꾸지 않는다', () => {
        const core = makeG7Core({ multilingualTagEdit: { ...baseEdit } });
        (window as any).G7Core = core;

        updateMultilingualTagValueHandler({ params: { value: 'x' } });

        expect(core.state.setGlobal).not.toHaveBeenCalled();
    });

    /**
     * @scenario action=cancel
     * @effects cancel_closes_modal_and_clears_edit_state_without_touching_tags
     */
    it('취소하면 창을 닫고 편집 상태를 비우며 태그 배열은 건드리지 않는다', () => {
        const core = makeG7Core({ multilingualTagEdit: { ...baseEdit, values: { ko: '초록', en: 'Green' } } });
        (window as any).G7Core = core;

        cancelMultilingualTagHandler();

        expect(core.modal.close).toHaveBeenCalledTimes(1);
        expect(core.current().multilingualTagEdit).toBeNull();
        expect(core.state.setParentLocal).not.toHaveBeenCalled();
    });

    /**
     * @scenario action=save_new
     * @effects save_appends_new_tag_to_parent_local_state_path
     */
    it('새 태그를 저장하면 기존 태그 뒤에 붙인 배열을 상품 폼 _local 의 statePath 에 쓰고 창을 닫는다', () => {
        const core = makeG7Core({ multilingualTagEdit: { ...baseEdit, values: { ko: '초록', en: 'Green' } } });
        (window as any).G7Core = core;

        saveMultilingualTagHandler();

        expect(core.state.setParentLocal).toHaveBeenCalledWith({
            'ui.optionInputs.0.values': [
                { ko: '빨강', en: 'Red' },
                { ko: '파랑', en: 'Blue' },
                { ko: '초록', en: 'Green' },
            ],
        });
        expect(core.modal.close).toHaveBeenCalledTimes(1);
        expect(core.current().multilingualTagEdit).toBeNull();
    });

    /**
     * @scenario action=save_edit
     * @effects save_replaces_edited_tag_in_parent_local_state_path
     */
    it('기존 태그를 고쳐 저장하면 그 자리만 바꾼 배열을 쓴다', () => {
        const core = makeG7Core({
            multilingualTagEdit: { ...baseEdit, editingIndex: 1, values: { ko: '남색', en: 'Navy' } },
        });
        (window as any).G7Core = core;

        saveMultilingualTagHandler();

        expect(core.state.setParentLocal).toHaveBeenCalledWith({
            'ui.optionInputs.0.values': [
                { ko: '빨강', en: 'Red' },
                { ko: '남색', en: 'Navy' },
            ],
        });
    });

    /**
     * @scenario action=save_empty
     * @effects save_skips_when_all_locale_values_empty
     */
    it('언어별 값이 모두 비었으면 반영하지 않고 창만 닫는다', () => {
        const core = makeG7Core({ multilingualTagEdit: { ...baseEdit, values: { ko: '  ', en: '' } } });
        (window as any).G7Core = core;

        saveMultilingualTagHandler();

        expect(core.state.setParentLocal).not.toHaveBeenCalled();
        expect(core.modal.close).toHaveBeenCalledTimes(1);
        expect(core.current().multilingualTagEdit).toBeNull();
    });

    /**
     * @scenario action=save_new
     * @effects save_appends_new_tag_to_parent_local_state_path
     */
    it('statePath 가 없으면 form.{fieldName} 경로에 쓴다', () => {
        const core = makeG7Core({
            multilingualTagEdit: { ...baseEdit, statePath: undefined, currentTags: [], values: { ko: '대', en: 'L' } },
        });
        (window as any).G7Core = core;

        saveMultilingualTagHandler();

        expect(core.state.setParentLocal).toHaveBeenCalledWith({ 'form.option_values_0': [{ ko: '대', en: 'L' }] });
    });
});
