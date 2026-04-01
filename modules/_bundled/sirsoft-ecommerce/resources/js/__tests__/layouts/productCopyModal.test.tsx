/**
 * 상품 복사 모달 레이아웃 검증 테스트
 *
 * @description
 * - 복사 모달 체크박스의 setState에서 $event.target.checked 사용 확인
 * - $event 직접 사용 (raw event 객체 저장) 방지 검증
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';

// 레이아웃 JSON 임포트
import productListCopyModal from '../../../layouts/admin/partials/admin_ecommerce_product_list/_modal_copy_product.json';
import productFormCopyModal from '../../../layouts/admin/partials/admin_ecommerce_product_form/_modal_copy_product.json';

/** 재귀적으로 JSON 트리에서 특정 조건의 노드를 찾는 유틸리티 */
function findNodes(node: any, predicate: (n: any) => boolean, results: any[] = []): any[] {
    if (!node) return results;
    if (predicate(node)) results.push(node);
    if (node.children) {
        for (const child of node.children) {
            findNodes(child, predicate, results);
        }
    }
    return results;
}

/** 액션의 params 값에서 특정 패턴 포함 여부를 확인하는 유틸리티 */
function findParamValues(actions: any[]): string[] {
    const values: string[] = [];
    if (!actions) return values;
    for (const action of actions) {
        if (action.params) {
            for (const [key, val] of Object.entries(action.params)) {
                if (key !== 'target' && typeof val === 'string') {
                    values.push(val);
                }
            }
        }
    }
    return values;
}

describe('상품 복사 모달 — 체크박스 이벤트 바인딩 검증', () => {
    const modals = [
        { name: '상품 목록', layout: productListCopyModal },
        { name: '상품 폼', layout: productFormCopyModal },
    ];

    for (const { name, layout } of modals) {
        describe(`${name} 복사 모달`, () => {
            it('모달 최상위 구조가 올바름 (type: composite, name: Modal)', () => {
                expect(layout.type).toBe('composite');
                expect(layout.name).toBe('Modal');
                expect(layout.id).toBe('modal_copy_product');
            });

            it('모든 Checkbox change 액션에서 $event.target.checked 사용', () => {
                const checkboxNodes = findNodes(layout, (n: any) =>
                    n.name === 'Checkbox' && n.actions?.length > 0
                );

                expect(checkboxNodes.length).toBe(11);

                for (const checkbox of checkboxNodes) {
                    const changeActions = checkbox.actions.filter((a: any) => a.type === 'change');
                    expect(changeActions.length).toBeGreaterThanOrEqual(1);

                    for (const action of changeActions) {
                        const paramValues = findParamValues([action]);
                        for (const val of paramValues) {
                            // $event.target.checked 사용해야 함
                            expect(val).toContain('$event.target.checked');
                            // $event 단독 사용 금지 (raw event 객체 저장 방지)
                            expect(val).not.toBe('{{$event}}');
                        }
                    }
                }
            });

            it('Checkbox setState target이 $parent._local임', () => {
                const checkboxNodes = findNodes(layout, (n: any) =>
                    n.name === 'Checkbox' && n.actions?.length > 0
                );

                for (const checkbox of checkboxNodes) {
                    const changeActions = checkbox.actions.filter((a: any) => a.type === 'change');
                    for (const action of changeActions) {
                        expect(action.params.target).toBe('$parent._local');
                    }
                }
            });

            it('11개 복사 옵션 체크박스가 모두 존재함', () => {
                const expectedOptions = [
                    'copy_images', 'copy_options', 'copy_categories',
                    'copy_sales_info', 'copy_description', 'copy_notice',
                    'copy_common_info', 'copy_other_info', 'copy_shipping',
                    'copy_seo', 'copy_identification',
                ];

                const checkboxNodes = findNodes(layout, (n: any) =>
                    n.name === 'Checkbox' && n.props?.name
                );

                const checkboxNames = checkboxNodes.map((n: any) => n.props.name);

                for (const option of expectedOptions) {
                    expect(checkboxNames).toContain(option);
                }
            });
        });
    }
});
