/**
 * @file productLabelOtherInfo.test.tsx
 * @description A31 — 기타정보 라벨 CRUD 회귀 (구조 검증)
 *
 * - labels_header 에 "+ 라벨 추가" 버튼이 추가되고 editingLabelId=null + 빈 labelFormData 로
 *   modal_label_form 을 여는지
 * - 미리보기에 "할당 해제"(modal_label_uncheck_confirm) 어포던스가 분리됐는지
 * - 모달 제목이 editingLabelId 유무로 생성/수정 분기되는지
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import otherInfo from '../../../layouts/admin/partials/admin_ecommerce_product_form/_partial_other_info.json';
import labelFormModal from '../../../layouts/admin/partials/admin_ecommerce_product_form/_modal_label_form.json';

const flat = (obj: unknown) => JSON.stringify(obj);

function findNode(node: any, pred: (n: any) => boolean): any {
    if (!node || typeof node !== 'object') return null;
    if (pred(node)) return node;
    for (const key of Object.keys(node)) {
        const child = node[key];
        if (Array.isArray(child)) {
            for (const c of child) {
                const found = findNode(c, pred);
                if (found) return found;
            }
        } else if (child && typeof child === 'object') {
            const found = findNode(child, pred);
            if (found) return found;
        }
    }
    return null;
}

describe('A31 — 기타정보 라벨 CRUD', () => {
    it('labels_header 에 "+ 라벨 추가" 버튼(editingLabelId=null + openModal)이 존재', () => {
        const addBtn = findNode(otherInfo, (n) => n.id === 'label_add_button');
        expect(addBtn).toBeTruthy();
        expect(addBtn.text).toContain('labels.add');

        // 권한 가드
        expect(addBtn.if).toContain('can_update');

        const seq = addBtn.actions[0];
        expect(seq.handler).toBe('sequence');
        const setState = seq.actions.find((a: any) => a.handler === 'setState');
        expect(setState.params.target).toBe('global');
        expect(setState.params.editingLabelId).toBeNull();
        expect(setState.params.labelFormData.color).toBe('#6B7280');
        const openModal = seq.actions.find((a: any) => a.handler === 'openModal');
        expect(openModal.target).toBe('modal_label_form');
    });

    it('미리보기에 "할당 해제" 어포던스가 분리됨 (modal_label_uncheck_confirm)', () => {
        const unassignBtn = findNode(otherInfo, (n) => n.id === 'label_unassign_button');
        expect(unassignBtn).toBeTruthy();
        expect(unassignBtn.text).toContain('labels.unassign');

        const seq = unassignBtn.actions[0];
        const setState = seq.actions.find((a: any) => a.handler === 'setState');
        expect(setState.params.labelToUncheckId).toBeTruthy();
        const openModal = seq.actions.find((a: any) => a.handler === 'openModal');
        expect(openModal.target).toBe('modal_label_uncheck_confirm');
    });

    it('"라벨 삭제"는 DB 원본 삭제(modal_label_delete_confirm)로 용어 구분됨', () => {
        const s = flat(otherInfo);
        // 삭제 버튼은 labels.delete + labelToDeleteId + delete_confirm 모달
        expect(s).toContain('labels.delete');
        expect(s).toContain('labelToDeleteId');
        expect(s).toContain('modal_label_delete_confirm');
    });

    it('모달 제목이 editingLabelId 유무로 생성/수정 분기', () => {
        const title = (labelFormModal as any).props.title;
        expect(title).toContain('_global.editingLabelId');
        expect(title).toContain('labels.modal_title_create');
        expect(title).toContain('labels.modal_title_edit');
    });
});
