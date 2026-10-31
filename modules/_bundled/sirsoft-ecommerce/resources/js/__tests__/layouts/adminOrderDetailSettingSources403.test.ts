/**
 * 주문 상세 — 주문 조회 권한만 가진 운영자가 들어와도 권한 오류 알림이 뜨지 않는다 (2026-10-07 실측)
 *
 * @description
 * 주문 상세는 운송장 입력의 택배사 목록(설정 권한 게이트)과 취소/환불 창의 사유 목록(설정 조회·클레임 조회
 * 권한 게이트)을 함께 받는다. 주문 조회 권한만 가진 운영자에게는 이 둘이 403 이 정상 응답인데, 오류 처리가
 * 없어 상세에 들어갈 때마다 「해당 권한이 없습니다.」 알림이 떴다(실측: 본인 주문 상세 진입 시
 * shipping-carriers/active 403). 목록 화면(S3-D6)과 같이 403 은 전파를 끊고 선택지만 비운다.
 * 상세 본체(`order`)의 403 은 그대로 오류 처리한다.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';

import layout from '../../../layouts/admin/admin_ecommerce_order_detail.json';

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

describe('주문 상세 설정성 데이터 소스의 403', () => {
    /**
     * @scenario source=background
     *
     * @effects detail_setting_sources_suppress_403_toast
     */
    it.each(['active_carriers', 'refundReasons'])('%s 는 403 을 억제한다', (id) => {
        expect(source(id).errorHandling?.['403']?.handler).toBe('suppress');
    });

    /**
     * @scenario source=main
     *
     * @effects main_list_403_still_shows_error_page
     */
    it('상세 본체의 403 은 억제하지 않는다', () => {
        expect(source('order').errorHandling?.['403']?.handler).not.toBe('suppress');
    });
});
