/**
 * 관리자 화면 결함 3건 회귀 가드 (주입 단락에서 발견 → 설계 검토 지시로 수정)
 *
 * 세 자리 모두 **화면에서는 조용한** 종류라 단위 테스트가 없으면 다음 편집에서 되돌아온다:
 *
 * ⓐ 분류 삭제 — 여는 쪽이 아무도 읽지 않는 값 4개를 `_global.modal_data` 에 써 넣었다.
 *    그 칸은 여러 화면이 나눠 쓰는 자리라 다음 화면까지 남는다.
 * ⓑ 상품평 상태변경 — 확인 문구의 상태 이름이 한국어로 박혀 있어 다른 언어에서도 한국어로 나왔다.
 * ⓒ 배송정책 — 실패 갈래가 `{{$error.message}}`(엔진 컨텍스트에 없는 이름)를 읽어 **빈 토스트**가
 *    뜨고, 목록을 다시 부르지 않아 스위치가 넘어간 채 서버와 어긋났다.
 *
 * 소스가 아니라 **구워진 짝 `.json`**(런타임이 실제로 먹는 값)을 검사한다.
 * 부재 단언 앞에는 반드시 앵커의 **존재**를 먼저 확정한다 — 경로가 바뀌면 "없음" 이 공허하게 참이다.
 */
import { describe, it, expect } from 'vitest';

import categoryPanelView from '../../../layouts/admin/partials/admin_ecommerce_category_index/_panel_view.json';
import categoryDeleteModal from '../../../layouts/admin/partials/admin_ecommerce_category_index/_modal_delete_confirm.json';
import reviewStatusChangeModal from '../../../layouts/admin/partials/admin_ecommerce_product_review_index/_modal_status_change.json';
import shippingDatagrid from '../../../layouts/admin/partials/admin_ecommerce_shipping_policy_list/_partial_datagrid.json';
import shippingBulkDelete from '../../../layouts/admin/partials/admin_ecommerce_shipping_policy_list/_modal_bulk_delete.json';
import shippingBulkToggle from '../../../layouts/admin/partials/admin_ecommerce_shipping_policy_list/_modal_bulk_toggle.json';
import shippingDelete from '../../../layouts/admin/partials/admin_ecommerce_shipping_policy_list/_modal_delete.json';
import shippingSetDefault from '../../../layouts/admin/partials/admin_ecommerce_shipping_policy_list/_modal_set_default.json';

/**
 * 개발자 주석을 걷어낸 뒤 직렬화한다.
 *
 * 문자열 단언의 대상은 **런타임이 먹는 값**이어야 한다. 주석은 계약을 설명하느라 바로 그 이름
 * (`_global.modal_data` 등)을 담기 마련이라, 걷어내지 않으면 설명글이 단언을 깨뜨린다.
 * 공개 서빙도 같은 두 키를 재귀 제거하므로 이 형태가 브라우저가 보는 값과 같다.
 */
function renderJson(node: unknown): string {
    return JSON.stringify(node, (key, value) => (key === 'comment' || key === '_comment' ? undefined : value));
}

/** 레이아웃 트리를 깊이 순회하며 조건을 만족하는 객체를 모두 모은다. */
function collect(node: unknown, match: (o: Record<string, unknown>) => boolean): Record<string, unknown>[] {
    const found: Record<string, unknown>[] = [];
    const walk = (n: unknown): void => {
        if (Array.isArray(n)) {
            n.forEach(walk);

            return;
        }
        if (n === null || typeof n !== 'object') {
            return;
        }
        const obj = n as Record<string, unknown>;
        if (match(obj)) {
            found.push(obj);
        }
        Object.values(obj).forEach(walk);
    };
    walk(node);

    return found;
}

describe('ⓐ 분류 삭제 확인 창 — 아무도 읽지 않는 modal_data 를 쓰지 않는다', () => {
    /**
     * @scenario defect=category_dead_modal_data
     * @effects category_delete_opener_only_opens_the_modal
     */
    it('보기 패널의 삭제 버튼은 창만 연다 (openModal 단독)', () => {
        const openers = collect(
            categoryPanelView,
            (o) => o.handler === 'openModal' && o.target === 'modal_delete_confirm',
        );

        // 존재 확정 — 이 앵커가 사라지면 아래 부재 단언은 아무것도 검사하지 않는다
        expect(openers).toHaveLength(1);
    });

    /**
     * @scenario defect=category_dead_modal_data
     * @effects category_delete_opener_only_opens_the_modal
     */
    it('삭제 경로에서 _global.modal_data 를 채우지 않는다', () => {
        const writers = collect(
            categoryPanelView,
            (o) => o.handler === 'setState' && !!(o.params as Record<string, unknown> | undefined)?.modal_data,
        );

        expect(writers).toHaveLength(0);
    });

    /**
     * @scenario defect=category_dead_modal_data
     * @effects category_delete_modal_reads_selected_category_counts
     */
    it('창은 선택된 분류의 children_count / products_count 로 잠금을 판단한다', () => {
        const layoutStr = renderJson(categoryDeleteModal);

        // 관계 배열 길이(children?.length)가 아니라 응답이 항상 싣는 수를 본다
        expect(layoutStr).toContain('_global.selectedCategory?.children_count');
        expect(layoutStr).toContain('_global.selectedCategory?.products_count');
        expect(layoutStr).not.toContain('_global.modal_data');
    });
});

describe('ⓑ 상품평 상태변경 확인 창 — 상태 이름이 다국어 키다', () => {
    const messageNodes = collect(
        reviewStatusChangeModal,
        (o) => typeof o.text === 'string' && (o.text as string).includes('modal.status_change.message'),
    );

    /**
     * @scenario defect=review_hardcoded_status_label
     * @effects review_status_label_uses_the_same_key_as_the_batch_select
     */
    it('확인 문구 노드가 존재한다', () => {
        expect(messageNodes).toHaveLength(1);
    });

    /**
     * @scenario defect=review_hardcoded_status_label
     * @effects review_status_label_uses_the_same_key_as_the_batch_select
     */
    it('상태 이름이 한국어로 박혀 있지 않고 목록 선택칸과 같은 키를 쓴다', () => {
        const text = messageNodes[0].text as string;

        expect(text).not.toMatch(/'전시중'|'숨김'/);
        expect(text).toContain("$t('sirsoft-ecommerce.admin.product_review.filter.visible')");
        expect(text).toContain("$t('sirsoft-ecommerce.admin.product_review.filter.hidden')");
    });
});

describe('ⓒ 배송정책 목록 — 실패해도 화면이 서버와 어긋나지 않는다', () => {
    /**
     * @scenario defect=shipping_toggle_failure_desync
     * @effects shipping_toggle_failure_refetches_the_list
     */
    it('사용여부 스위치의 실패 갈래가 목록을 다시 부른다', () => {
        const toggleCalls = collect(
            shippingDatagrid,
            (o) => o.handler === 'apiCall' && typeof o.target === 'string'
                && (o.target as string).includes('/toggle-active'),
        );

        // 존재 확정 — 스위치가 사라지면 아래 단언이 공허해진다
        expect(toggleCalls).toHaveLength(1);

        const onError = toggleCalls[0].onError;
        const errorActions = Array.isArray(onError) ? onError : [onError];
        const refetches = errorActions.filter(
            (a) => (a as Record<string, unknown>)?.handler === 'refetchDataSource'
                && ((a as Record<string, unknown>)?.params as Record<string, unknown>)?.dataSourceId === 'shipping_policies',
        );

        expect(refetches).toHaveLength(1);
    });

    /**
     * @scenario defect=shipping_error_toast_empty
     * @effects shipping_error_toast_reads_the_engine_error_context
     */
    it.each([
        ['_partial_datagrid', shippingDatagrid],
        ['_modal_bulk_delete', shippingBulkDelete],
        ['_modal_bulk_toggle', shippingBulkToggle],
        ['_modal_delete', shippingDelete],
        ['_modal_set_default', shippingSetDefault],
    ])('%s — 에러 토스트가 엔진에 없는 $error 대신 error 를 읽는다', (_name, layout) => {
        const layoutStr = renderJson(layout);

        // 존재 확정 — 에러 토스트 자체가 있어야 아래 부재 단언에 의미가 있다
        expect(layoutStr).toContain('{{error.message}}');
        expect(layoutStr).not.toContain('{{$error.');
    });
});
