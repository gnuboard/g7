// e2e:allow 레이아웃 데이터소스 파라미터와 서버 검증 규칙의 정적 대조 — 브라우저 실측(최고관리자 상품 등록 입력 23칸 잠김 재현·해소)은 총괄 통합 이력에 기록
/**
 * 권한 확인용 목록 조회(abilities probe)의 per_page 가 그 API 의 검증 최솟값을 지키는지 고정한다.
 *
 * @description
 * 상품 등록·배송정책 등록·쿠폰 등록 화면은 등록 권한(`can_create`)을 목록 응답의 컬렉션 abilities 로 받는다. 이때 한 건만
 * 받으려고 `per_page=1` 을 보냈는데, 두 목록 API 는 페이지당 10건 미만을 422 로 거절한다. 응답이 없으면 화면은
 * 잠기는 쪽(fail-closed)으로 판정하므로, 최고관리자까지 상품을 등록하지 못했다(7.1.0 통합 브라우저 실측).
 * 레이아웃 JSON 만 보는 렌더 테스트는 서버 검증을 모르므로 이 대조를 따로 둔다.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

/**
 * 모듈 루트(module.json 기준)를 위로 훑어 찾는다.
 *
 * @returns 모듈 루트 절대경로
 */
function moduleRoot(): string {
    let current = path.dirname(fileURLToPath(import.meta.url));

    for (let depth = 0; depth < 10; depth++) {
        if (fs.existsSync(path.join(current, 'module.json'))) {
            return current;
        }
        current = path.dirname(current);
    }

    throw new Error('module.json 을 가진 모듈 루트를 찾지 못했습니다.');
}

const ROOT = moduleRoot();

/**
 * FormRequest 의 per_page 규칙에서 최솟값을 읽는다.
 *
 * @param requestFile 모듈 루트 기준 Request 파일 경로
 * @returns per_page 최솟값
 */
function perPageMin(requestFile: string): number {
    const source = fs.readFileSync(path.join(ROOT, requestFile), 'utf8');
    // 배열 규칙(['nullable', 'integer', 'min:10', ...])과 문자열 규칙('nullable|integer|min:1|...') 둘 다 읽는다
    const rule = source.match(/'per_page'\s*=>\s*\[[^\]]*'min:(\d+)'/)
        ?? source.match(/'per_page'\s*=>\s*'(?:[^'|]*\|)*min:(\d+)/);
    expect(rule, `${requestFile} 에 per_page min 규칙이 없습니다`).toBeTruthy();

    return Number(rule![1]);
}

/**
 * 굽기 산출 레이아웃에서 데이터소스 하나를 찾는다.
 *
 * @param layoutFile 모듈 루트 기준 레이아웃 JSON 경로
 * @param id 데이터소스 id
 * @returns 데이터소스 정의
 */
function dataSource(layoutFile: string, id: string): Record<string, any> {
    const layout = JSON.parse(fs.readFileSync(path.join(ROOT, layoutFile), 'utf8'));
    const found = (layout.data_sources ?? []).find((ds: Record<string, any>) => ds.id === id);
    expect(found, `${layoutFile} 에 데이터소스 ${id} 가 없습니다`).toBeTruthy();

    return found;
}

const PROBES = [
    {
        layout: 'resources/layouts/admin/admin_ecommerce_product_form.json',
        id: 'product_abilities',
        request: 'src/Http/Requests/Admin/ProductListRequest.php',
    },
    {
        layout: 'resources/layouts/admin/admin_ecommerce_shipping_policy_form.json',
        id: 'policy_abilities',
        request: 'src/Http/Requests/Admin/ShippingPolicyListRequest.php',
    },
    {
        layout: 'resources/layouts/admin/admin_ecommerce_promotion_coupon_form.json',
        id: 'coupon_abilities',
        request: 'src/Http/Requests/Admin/CouponListRequest.php',
    },
];

describe('[case:network-abilities-probe-422-locks-everyone] 권한 확인용 목록 조회의 per_page 가 서버 검증을 통과한다', () => {
    /**
     * per_page 가 목록 API 의 최솟값보다 작으면 422 로 abilities 를 받지 못해 권한 있는 운영자까지 잠긴다.
     *
     * @scenario surface=admin,permission=granted
     *
     * @effects abilities_probe_per_page_satisfies_list_request_minimum
     */
    it.each(PROBES)('$id ($layout)', ({ layout, id, request }) => {
        const ds = dataSource(layout, id);
        const perPage = Number(ds.params?.per_page);

        expect(Number.isFinite(perPage), `${id} 에 per_page 가 없습니다`).toBe(true);
        expect(perPage).toBeGreaterThanOrEqual(perPageMin(request));
    });
});
