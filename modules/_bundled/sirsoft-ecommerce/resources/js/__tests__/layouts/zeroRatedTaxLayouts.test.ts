/**
 * @file zeroRatedTaxLayouts.test.ts
 * @description 관리자 화면의 영세율·옵션별 과세 종류 표면 (#612)
 *
 * - 상품 폼 판매정보: 과세 여부 버튼에 「영세」가 넓은·좁은 화면 모두 있고, 옵션마다 바꾸는 곳 안내가 붙는다.
 * - 상품 폼 옵션 표: 판매가 다음 「과세 종류」 열 — 빈 값(기본값 따름)·과세·면세·영세 네 선택지, 바꾸면
 *   updateFormOptionField(field=tax_status) 로 그 행만 고친다. 좁은 화면 카드도 같은 칸을 가진다.
 * - 상품 목록 과세 필터: 「영세」 라디오.
 * - 주문상세: 품목 과세 종류 배지, 실결제 셀의 과세 행 전용 「과세:」 줄과 「영세:」 줄, 합계 줄 영세 금액,
 *   결제정보의 면세·영세·취소 부가세 행, 취소 모달 영세금액 행.
 */

import { describe, it, expect } from 'vitest';
import salesInfo from '../../../layouts/admin/partials/admin_ecommerce_product_form/_partial_sales_info.json';
import productOptions from '../../../layouts/admin/partials/admin_ecommerce_product_form/_partial_product_options.json';
import listFilter from '../../../layouts/admin/partials/admin_ecommerce_product_list/_partial_filter_section.json';
import orderInfo from '../../../layouts/admin/partials/admin_ecommerce_order_detail/_partial_order_info.json';
import paymentInfo from '../../../layouts/admin/partials/admin_ecommerce_order_detail/_partial_payment_info.json';
import cancelModal from '../../../layouts/admin/partials/admin_ecommerce_order_detail/_modal_cancel_order.json';

type Node = Record<string, any>;

/**
 * 조건에 맞는 노드를 모두 모은다.
 *
 * @param node 시작 노드
 * @param predicate 조건
 * @param out 결과 누적
 * @returns 찾은 노드 목록
 */
function findAll(node: unknown, predicate: (n: Node) => boolean, out: Node[] = []): Node[] {
    if (Array.isArray(node)) {
        node.forEach((child) => findAll(child, predicate, out));

        return out;
    }
    if (!node || typeof node !== 'object') return out;
    if (predicate(node as Node)) out.push(node as Node);
    Object.values(node as Node).forEach((value) => findAll(value, predicate, out));

    return out;
}

/**
 * 노드 안의 글자(text·children 문자열)를 이어 붙인다.
 *
 * @param node 노드
 * @returns 이어 붙인 글자
 */
function textOf(node: unknown): string {
    return JSON.stringify(node);
}

describe('관리자 영세율·옵션별 과세 종류 레이아웃 (#612)', () => {
    // @scenario surface=admin_product_form
    // @effects admin_product_form_offers_zero_rated
    it('판매정보 과세 여부 버튼에 영세가 넓은·좁은 화면 모두 있고 안내 문구가 붙는다', () => {
        const zeroButtons = findAll(salesInfo, (n) => n.name === 'Button' && textOf(n).includes('form.tax_status') && textOf(n).includes('zero_rated'));
        expect(zeroButtons).toHaveLength(2);
        zeroButtons.forEach((b) => expect(textOf(b)).toContain('admin.product.tax.zero_rated'));
        expect(findAll(salesInfo, (n) => typeof n.text === 'string' && n.text.includes('sales_info.tax_status_help'))).toHaveLength(2);
    });

    // @scenario surface=admin_product_form
    // @effects admin_option_tax_status_select
    it('옵션 표·카드에 과세 종류 선택(기본값 따름·과세·면세·영세)이 있고 바꾸면 그 행의 tax_status 만 고친다', () => {
        const selects = findAll(productOptions, (n) => n.name === 'Select' && textOf(n.actions ?? []).includes('"field":"tax_status"'));
        expect(selects).toHaveLength(2);

        selects.forEach((select) => {
            const values = findAll(select, (n) => n.name === 'Option').map((o) => o.props?.value);
            expect(values).toEqual(['', 'taxable', 'tax_free', 'zero_rated']);
            expect(select.props?.value).toBe("{{opt.tax_status ?? ''}}");
            expect(String(select.props?.['aria-label'] ?? select.props?.ariaLabel)).toContain('options.columns.tax_status');
            expect(textOf(select)).toContain('options.tax_status_inherit');
        });

        const headers = findAll(productOptions, (n) => n.name === 'Th').map((th) => textOf(th));
        const sellingIdx = headers.findIndex((h) => h.includes('options.columns.selling_price'));
        expect(headers[sellingIdx + 1]).toContain('options.columns.tax_status');
    });

    // @scenario surface=admin_product_list
    // @effects admin_tax_filter_offers_zero_rated
    it('상품 목록 과세 필터에 영세 라디오가 있다', () => {
        const radios = findAll(listFilter, (n) => n.name === 'Input' && n.props?.name === 'tax_status');
        expect(radios.map((r) => r.props?.checked)).toContain("{{_local.filter.taxStatus === 'zero_rated'}}");
    });

    // @scenario surface=admin_order_detail
    // @effects admin_order_row_tax_badge_and_lines
    it('주문상세 품목에 과세 종류 배지와 과세 행 전용 과세 줄·영세 줄, 합계 영세 줄이 있다', () => {
        const badges = findAll(orderInfo, (n) => n.name === 'Badge' && textOf(n).includes('row.tax_status_label'));
        expect(badges).toHaveLength(1);
        expect(badges[0].props?.color).toContain("row.tax_status === 'zero_rated' ? 'green'");

        const taxLine = findAll(orderInfo, (n) => n.if === "{{row.tax_status === 'taxable' && row.subtotal_tax_amount > 0}}");
        expect(taxLine).toHaveLength(1);
        expect(findAll(orderInfo, (n) => n.if === '{{row.subtotal_zero_rated_amount > 0}}')).toHaveLength(1);
        expect(findAll(orderInfo, (n) => n.if === '{{order.data?.total_zero_rated_amount > 0}}')).toHaveLength(1);
    });

    // @scenario surface=admin_order_detail
    // @effects admin_payment_info_zero_rated_rows
    it('결제정보에 면세·영세·취소 부가세 행이, 취소 모달에 영세금액 비교 행이 있다', () => {
        const ifs = findAll(paymentInfo, (n) => typeof n.if === 'string').map((n) => n.if);
        expect(ifs).toContain('{{order.data?.total_tax_free_amount > 0}}');
        expect(ifs).toContain('{{order.data?.total_zero_rated_amount > 0}}');
        expect(ifs).toContain('{{payment?.cancelled_vat_amount > 0}}');

        expect(textOf(cancelModal)).toContain('modal.cancel.zero_rated_amount');
        expect(textOf(cancelModal)).toContain('recalculated_snapshot?.total_zero_rated_amount');
    });
});
