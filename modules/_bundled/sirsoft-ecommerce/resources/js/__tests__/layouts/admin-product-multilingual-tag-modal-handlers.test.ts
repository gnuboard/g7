/**
 * @file admin-product-multilingual-tag-modal-handlers.test.ts
 * @description 상품 폼 다국어 태그 편집 창 — 입력·취소·저장이 이 모듈의 핸들러를 부르는지 (레이아웃 JSON 트리 분석)
 *
 * 결함: 창의 입력칸·취소·저장이 접두어 없는 `updateMultilingualTagValue`·`cancelMultilingualTag`·`saveMultilingualTag`
 *   를 불렀다. 그 이름들은 관리자 템플릿(sirsoft-admin_basic)이 등록한 템플릿 핸들러라 다른 관리자 템플릿에서는
 *   창이 모두 무반응이었다(레이아웃 검사 「미등록 핸들러」 경고).
 * 수정: 이 모듈이 세 핸들러를 소유하고 창은 `sirsoft-ecommerce.*` 로 부른다. 입력칸은 언어 코드와 입력값을 넘긴다.
 *   창을 여는 옵션값 입력(MultilingualTagInput)은 이 창 id 와 상품 폼 `_local` 경로(statePath)를 넘긴다 —
 *   저장 핸들러가 그 경로로 태그 배열을 쓴다.
 */

import { describe, it, expect } from 'vitest';

import tagModal from '../../../layouts/admin/partials/admin_ecommerce_product_form/_modal_multilingual_tag_edit.json';
import productOptions from '../../../layouts/admin/partials/admin_ecommerce_product_form/_partial_product_options.json';
import { handlerMap } from '../../handlers';
import {
    cancelMultilingualTagHandler,
    saveMultilingualTagHandler,
    updateMultilingualTagValueHandler,
} from '../../handlers/multilingualTagHandlers';

type Node = Record<string, any>;

function collect(node: unknown, predicate: (n: Node) => boolean): Node[] {
    const out: Node[] = [];
    const walk = (cur: unknown): void => {
        if (Array.isArray(cur)) {
            cur.forEach(walk);
            return;
        }
        if (cur && typeof cur === 'object') {
            const obj = cur as Node;
            if (predicate(obj)) {
                out.push(obj);
            }
            Object.values(obj).forEach(walk);
        }
    };
    walk(node);
    return out;
}

const byHandler = (name: string) => collect(tagModal, (n) => n.handler === name);

describe('상품 폼 — 다국어 태그 편집 창', () => {
    /**
     * @scenario action=update
     * @effects modal_calls_module_owned_tag_handlers
     */
    it('언어별 입력칸은 이 모듈의 updateMultilingualTagValue 에 언어 코드와 입력값을 넘긴다', () => {
        const actions = byHandler('sirsoft-ecommerce.updateMultilingualTagValue');
        expect(actions).toHaveLength(1);
        expect(actions[0].type).toBe('change');
        expect(actions[0].params).toEqual({ locale: '{{localeItem.code}}', value: '{{$event.target.value}}' });
        expect(handlerMap.updateMultilingualTagValue).toBe(updateMultilingualTagValueHandler);
    });

    /**
     * @scenario action=cancel
     * @effects modal_calls_module_owned_tag_handlers
     */
    it('취소 버튼은 이 모듈의 cancelMultilingualTag 를 부른다', () => {
        expect(byHandler('sirsoft-ecommerce.cancelMultilingualTag')).toHaveLength(1);
        expect(handlerMap.cancelMultilingualTag).toBe(cancelMultilingualTagHandler);
    });

    /**
     * @scenario action=save_new
     * @scenario action=save_edit
     * @effects modal_calls_module_owned_tag_handlers
     */
    it('저장 버튼은 이 모듈의 saveMultilingualTag 를 부른다', () => {
        expect(byHandler('sirsoft-ecommerce.saveMultilingualTag')).toHaveLength(1);
        expect(handlerMap.saveMultilingualTag).toBe(saveMultilingualTagHandler);
    });

    /**
     * @scenario action=update
     * @effects modal_calls_module_owned_tag_handlers
     */
    it('접두어 없는 템플릿 핸들러 이름은 남지 않는다', () => {
        for (const name of ['updateMultilingualTagValue', 'cancelMultilingualTag', 'saveMultilingualTag']) {
            expect(byHandler(name)).toHaveLength(0);
        }
    });

    /**
     * @scenario action=save_new
     * @effects option_tag_input_opens_this_modal_with_local_state_path
     */
    it('옵션값 입력은 이 창을 열고 상품 폼 _local 의 옵션값 경로를 넘긴다', () => {
        const inputs = collect(productOptions, (n) => n.name === 'MultilingualTagInput');
        expect(inputs.length).toBeGreaterThan(0);

        for (const input of inputs) {
            expect(input.props.modalId).toBe(tagModal.id);
            expect(input.props.statePath).toBe('ui.optionInputs.{{optIdx}}.values');
        }
    });
});
