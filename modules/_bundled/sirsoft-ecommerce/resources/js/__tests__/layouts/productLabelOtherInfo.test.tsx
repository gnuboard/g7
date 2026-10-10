// e2e:allow 레이아웃 JSON 판독을 모양(actions 배열 첨자)에서 뜻(layoutShapeUtils.actionsOf)으로 옮긴 테스트 전용 리팩토링 — 대상 레이아웃·핸들러·런타임 동작은 바뀌지 않았고, 단언이 보는 명제도 동일하다 (U-SHAPEASSERT 판정 ⒜ · D67 ⓐ 미전환 잔여분 해소).
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
import { actionsOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

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
    /**
     * @scenario mode=create, permission=can_update_true
     * @effects add_button_opens_create_modal, permission_guards_buttons
     */
    it('labels_header 에 "+ 라벨 추가" 버튼(editingLabelId=null + openModal)이 존재', () => {
        const addBtn = findNode(otherInfo, (n) => n.id === 'label_add_button');
        expect(addBtn).toBeTruthy();
        expect(addBtn.text).toContain('labels.add');

        // 권한 가드 — 폼 읽기 전용(수정 진입은 상품 수정 권한, 등록·복사 진입은 등록 권한) + 라벨 등록 권한
        expect(addBtn.if).toContain('!_computed.isReadOnly');
        expect(addBtn.if).toContain('product_labels?.data?.abilities?.can_create === true');

        const seq = actionsOf(addBtn)[0];
        expect(seq.handler).toBe('sequence');
        const setState = actionsOf(seq).find((a: any) => a.handler === 'setState');
        expect(setState.params.target).toBe('global');
        expect(setState.params.editingLabelId).toBeNull();
        expect(setState.params.labelFormData.color).toBe('#6B7280');
        const openModal = actionsOf(seq).find((a: any) => a.handler === 'openModal');
        expect(openModal.target).toBe('modal_label_form');
    });

    /**
     * @scenario unassign=unassign_affordance
     * @effects unassign_affordance_separated
     */
    it('미리보기에 "할당 해제" 어포던스가 분리됨 (modal_label_uncheck_confirm)', () => {
        const unassignBtn = findNode(otherInfo, (n) => n.id === 'label_unassign_button');
        expect(unassignBtn).toBeTruthy();
        expect(unassignBtn.text).toContain('labels.unassign');

        const seq = actionsOf(unassignBtn)[0];
        const setState = actionsOf(seq).find((a: any) => a.handler === 'setState');
        expect(setState.params.labelToUncheckId).toBeTruthy();
        const openModal = actionsOf(seq).find((a: any) => a.handler === 'openModal');
        expect(openModal.target).toBe('modal_label_uncheck_confirm');
    });

    /**
     * @scenario unassign=delete_original
     * @effects delete_label_db_original
     */
    it('"라벨 삭제"는 DB 원본 삭제(modal_label_delete_confirm)로 용어 구분됨', () => {
        const s = flat(otherInfo);
        // 삭제 버튼은 labels.delete + labelToDeleteId + delete_confirm 모달
        expect(s).toContain('labels.delete');
        expect(s).toContain('labelToDeleteId');
        expect(s).toContain('modal_label_delete_confirm');
    });

    /**
     * @scenario mode=edit
     * @effects modal_title_branches
     */
    it('모달 제목이 editingLabelId 유무로 생성/수정 분기', () => {
        const title = (labelFormModal as any).props.title;
        expect(title).toContain('_global.editingLabelId');
        expect(title).toContain('labels.modal_title_create');
        expect(title).toContain('labels.modal_title_edit');
    });
});
