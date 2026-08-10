/**
 * 목록에서 뺀 값을 화면이 여전히 쓰는지 고정하는 회귀 테스트 (#518 / 공개 #76)
 *
 * 이번 변경은 여러 목록에서 "화면이 안 쓴다" 는 판단으로 값을 뺐다. 그 판단이 틀리면 응답만
 * 보는 테스트로는 잡히지 않는다 — 메뉴 목록에서 실제로 그렇게 데이터가 지워졌고, 배송정책
 * 목록에서는 국가 칩·배송비 요약 열이 조용히 비었다.
 *
 * 그래서 여기서는 **레이아웃 JSON 원본**을 읽어 두 가지를 함께 고정한다.
 *   1. 화면이 그 필드를 그리고 있다 (소비 사실)
 *   2. 그 화면의 목록 요청이 그 필드를 받아오도록 되어 있다 (공급 경로)
 * 둘 중 하나만 바뀌면 실패한다.
 *
 * @vitest-environment node
 */

import fs from 'fs';
import path from 'path';
import { describe, it, expect } from 'vitest';

const LAYOUT_ROOT = path.resolve(__dirname, '../../../layouts/admin');
// 저장소 루트 — 이 모듈의 목록 계약을 소비하는 사용자 템플릿까지 함께 고정한다.
const REPO_ROOT = path.resolve(__dirname, '../../../../../../..');

function readLayout(relativePath: string): any {
    return JSON.parse(fs.readFileSync(path.join(LAYOUT_ROOT, relativePath), 'utf-8'));
}

function readRaw(relativePath: string): string {
    return fs.readFileSync(path.join(LAYOUT_ROOT, relativePath), 'utf-8');
}

/**
 * @scenario endpoint=active_list,opt_in=default
 * @effects product_form_selector_still_draws_country_chips
 */
describe('배송정책 목록 — 국가별 설정 공급 경로', () => {
    const LIST = 'admin_ecommerce_shipping_policy_list.json';
    const DATAGRID = 'partials/admin_ecommerce_shipping_policy_list/_partial_datagrid.json';

    it('화면이 국가 칩·배송비 요약·국가별 설정을 그린다 (소비 사실)', () => {
        const raw = readRaw(DATAGRID);

        // 존재를 먼저 확정한다 — 화면이 이 값을 쓰지 않게 되면 아래 공급 요구도 함께 풀어야 한다.
        expect(raw).toContain('countries_display');
        expect(raw).toContain('fee_summary');
        expect(raw).toContain('country_settings');
    });

    it('목록 요청이 무거운 전체 컬럼을 켜지 않는다 (기본이 표시용 필드를 공급한다)', () => {
        // 서버 기본 응답이 목록 표시용 국가별 설정을 이미 싣는다. 여기서 opt-in 을 켜면
        // 화면이 쓰지도 않는 구간 설정·도서산간 설정·계산 API 설정까지 되받아, 프루닝
        // 효과가 0 이 된다 — 실제로 한 번 그렇게 되돌아갔다.
        const layout = readLayout(LIST);
        const source = (layout.data_sources ?? []).find(
            (ds: any) => ds.id === 'shipping_policies',
        );

        expect(source).toBeDefined();
        expect(source.params ?? {}).not.toHaveProperty('with_country_settings');
    });

    it('상품 등록/수정의 배송정책 선택기도 전체 컬럼을 켜지 않는다', () => {
        const layout = readLayout('admin_ecommerce_product_form.json');
        const source = (layout.data_sources ?? []).find((ds: any) =>
            String(ds.endpoint ?? '').includes('/admin/shipping-policies'),
        );

        expect(source, '배송정책 선택기 데이터소스가 있어야 한다').toBeDefined();
        expect(source.params ?? {}).not.toHaveProperty('with_country_settings');
    });
});

describe('마이페이지 주문내역 — 주문상품 공급 경로', () => {
    const USER_LAYOUT = path.join(REPO_ROOT, 'templates/_bundled/sirsoft-basic/layouts/mypage/orders.json');

    it('전량 나열 화면은 with_items 를 명시적으로 켠다 (공급 경로)', () => {
        // 서버 기본은 대표 1건이다. 이 화면은 주문상품을 전부 나열하므로 켜야 한다 —
        // 빠지면 주문마다 상품 한 줄만 남는다.
        const layout = JSON.parse(fs.readFileSync(USER_LAYOUT, 'utf-8'));
        const source = (layout.data_sources ?? []).find((ds: any) =>
            String(ds.endpoint ?? '').includes('/user/orders'),
        );

        expect(source, '주문내역 데이터소스가 있어야 한다').toBeDefined();
        expect(source.params).toHaveProperty('with_items');
        expect(String(source.params.with_items)).toBe('1');
    });

    it('params 에 주석 키를 두지 않는다 (전부 쿼리스트링으로 전송된다)', () => {
        // `_comment_*` 를 params 안에 두면 그 문장이 그대로 URL 쿼리로 나간다 —
        // 브라우저 실측에서 한글 주석 한 문단이 요청 URL 에 실려 있는 것을 확인했다.
        // 설명은 데이터소스 레벨 `comment` 에 둔다.
        const layout = JSON.parse(fs.readFileSync(USER_LAYOUT, 'utf-8'));
        const source = (layout.data_sources ?? []).find((ds: any) =>
            String(ds.endpoint ?? '').includes('/user/orders'),
        );

        const commentKeys = Object.keys(source.params ?? {}).filter((k) => k.startsWith('_comment'));
        expect(commentKeys, `params 안의 주석 키: ${commentKeys.join(', ')}`).toEqual([]);
    });

    it('화면이 주문상품 배열을 실제로 순회한다 (소비 사실)', () => {
        const partial = path.join(REPO_ROOT, 'templates/_bundled/sirsoft-basic/layouts/partials/mypage/orders/_list.json');

        // 소비가 사라지면 위의 공급 요구도 함께 풀어야 한다.
        expect(fs.readFileSync(partial, 'utf-8')).toContain('order.items');
    });
});

describe('주문 목록 — 대표 상품/배송 표시', () => {
    const DATAGRID = 'partials/admin_ecommerce_order_list/_partial_order_datagrid.json';

    it('목록은 대표 1건과 "외 N건" 집계만 쓴다 (옵션 배열 순회 없음)', () => {
        const raw = readRaw(DATAGRID);

        // 집계 필드를 실제로 쓰고 있어야 대표 1건 축약이 성립한다.
        expect(raw).toContain('options_count');

        // 옵션 배열 자체를 순회하면 목록이 다시 전량을 필요로 한다는 뜻이다.
        expect(raw).not.toContain('row.options.map');
        expect(raw).not.toContain('"source": "row.options');
    });
});
