/**
 * 게시판 폼·게시글 폼 — 같은 레이아웃 재사용(수정 → 등록) 시 form 잔존 제거 (레이아웃 JSON 구조 검증)
 *
 * @description
 * 배경(브라우저 실측 2026-09-14, #599): 엔진은 layout_name 이 바뀔 때만 `_local` 을 비운다.
 * 게시판 수정 → 게시판 등록, 게시글 수정 → 게시글 등록은 같은 레이아웃이라 `_local.form` 이 남고,
 * 두 폼은 생성 모드에서도 데이터소스(initLocal: form)가 폼을 채우는데 엔진이 그 페이로드를
 * **기존 `_local.form` 과 깊은 병합**해 두므로(TemplateApp processInitOptions) 직전 글의 id,
 * 직전 게시판의 다국어 이름(name.en)이 등록 화면에 살아남았다.
 *
 * 대응 두 가지가 모두 있어야 한다:
 *  1. 데이터소스 initLocal 을 맵 형식 + `_merge: "replace"` 로 바꿔 페이로드가 기존 form 과
 *     병합되지 않게 한다(data-sources-advanced.md 「병합 전략 옵션」).
 *  2. init_actions 에서 생성 모드일 때 `form: null` 로 먼저 비운다(sirsoft-page admin_page_form 선례).
 *
 * 시나리오: tests/scenarios/admin-form-create-mode-reset.yaml
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';

import boardForm from '../../../layouts/admin/admin_board_form.json';
import postForm from '../../../layouts/admin/admin_board_post_form.json';

type InitAction = { handler?: string; if?: string; params?: Record<string, any> };

function initActions(layout: any): InitAction[] {
    return (layout.init_actions ?? layout.initActions ?? []) as InitAction[];
}

function findFormNullReset(actions: InitAction[], createCondition: string): number {
    return actions.findIndex(
        (a) =>
            a.handler === 'setState' &&
            a.if === createCondition &&
            a.params?.target === 'local' &&
            Object.prototype.hasOwnProperty.call(a.params ?? {}, 'form') &&
            a.params?.form === null
    );
}

function dataSource(layout: any, id: string): any {
    return (layout.data_sources ?? []).find((d: any) => d.id === id);
}

describe('admin_board_form.json — 생성 모드 form 잔존 제거', () => {
    const CREATE = '{{!route?.slug}}';

    /**
     * @scenario form=board
     * @effects datasource_initlocal_replace_avoids_merge_with_previous_form
     */
    it('boards 데이터소스 initLocal 이 replace 맵 형식으로 form 을 채운다(기존 form 과 병합 금지)', () => {
        const ds = dataSource(boardForm, 'boards');
        expect(ds).toBeDefined();
        expect(ds.initLocal).toEqual({ _merge: 'replace', form: '{{data}}' });
        expect(ds.refetchOnMount).toBe(true);
    });

    /**
     * @scenario form=board
     * @effects create_mode_clears_previous_form, dirty_flag_reset_on_create
     */
    it('생성 모드(!route?.slug)에서 form 을 null 로 비우고 hasChanges 를 내리는 init 액션이 있다', () => {
        const actions = initActions(boardForm);
        const idx = findFormNullReset(actions, CREATE);
        expect(idx).toBeGreaterThanOrEqual(0);
        expect(actions[idx].params?.hasChanges).toBe(false);
    });
});

describe('admin_board_post_form.json — 생성·답글 모드 form 잔존 제거', () => {
    const CREATE = '{{!route?.id}}';

    /**
     * @scenario form=board_post
     * @effects datasource_initlocal_replace_avoids_merge_with_previous_form
     */
    it('form_data 데이터소스 initLocal 이 replace 맵 형식으로 form 을 채운다(기존 form 과 병합 금지)', () => {
        const ds = dataSource(postForm, 'form_data');
        expect(ds).toBeDefined();
        expect(ds.initLocal).toEqual({ _merge: 'replace', form: '{{data}}' });
        expect(ds.refetchOnMount).toBe(true);
    });

    /**
     * @scenario form=board_post
     * @effects form_meta_untouched
     */
    it('form_meta 데이터소스는 initLocal 이 없다(변경 대상 아님)', () => {
        expect(dataSource(postForm, 'form_meta').initLocal).toBeUndefined();
    });

    /**
     * @scenario form=board_post
     * @effects create_mode_clears_previous_form, dirty_flag_reset_on_create
     */
    it('생성 모드(!route?.id)에서 form 을 null 로 비우고 hasChanges·errors 를 초기화하는 init 액션이 있다', () => {
        const actions = initActions(postForm);
        const idx = findFormNullReset(actions, CREATE);
        expect(idx).toBeGreaterThanOrEqual(0);
        expect(actions[idx].params?.hasChanges).toBe(false);
        expect(actions[idx].params?.errors).toBeNull();
    });

    /**
     * @scenario form=board_post
     * @effects temp_key_action_kept
     */
    it('tempKey 생성 액션은 그대로 남아 있다', () => {
        const actions = initActions(postForm);
        const tempKey = actions.find((a) => a.params?._local?.tempKey !== undefined);
        expect(tempKey).toBeDefined();
    });
});
