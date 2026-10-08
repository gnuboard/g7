/**
 * 주문 목록 — 주문 조회 권한만 가진 운영자가 들어와도 권한 오류 알림이 뜨지 않는다 (S3-D6, 2026-10-07 실측)
 *
 * @description
 * 주문 목록은 필터·일괄처리 선택지를 다른 권한의 엔드포인트에서 뒤늦게 받는다 — 분류(상품 권한)·쇼핑몰 설정
 * (설정 권한)·택배사·배송정책. 주문 조회 권한만 가진 운영자에게는 이 넷이 403 이 정상 응답인데, 오류 처리가
 * 없어 진입할 때마다 「해당 권한이 없습니다.」 알림이 떴다(실측: 역할 e130s3_order_readonly — 프리셋 200,
 * categories·settings·shipping-carriers/active·shipping-policies/active 403). 403 은 전파를 끊고 선택지만 비운다.
 * 목록 본체(`orders`)의 403 오류 페이지는 그대로다.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';

import layout from '../../../layouts/admin/admin_ecommerce_order_list.json';

const sources: any[] = (layout as any).data_sources ?? [];

/**
 * 데이터 소스를 id 로 찾는다.
 *
 * @param id 데이터 소스 id
 * @returns 데이터 소스 정의
 */
function source(id: string): any {
    const found = sources.find((s) => s?.id === id);
    expect(found, `${id} 데이터 소스`).toBeTruthy();

    return found;
}

describe('주문 목록 배경 데이터 소스의 403', () => {
    /**
     * @scenario source=background
     *
     * @effects background_sources_suppress_403_toast
     */
    it.each(['categories', 'ecommerce_settings', 'active_carriers', 'shipping_policies', 'presets'])(
        '%s 는 403 을 억제한다',
        (id) => {
            expect(source(id).errorHandling?.['403']?.handler).toBe('suppress');
        },
    );

    /**
     * @scenario source=main
     *
     * @effects main_list_403_still_shows_error_page
     */
    it('목록 본체의 403 은 여전히 오류 페이지다', () => {
        expect(source('orders').errorHandling?.['403']?.handler).not.toBe('suppress');
    });
});
