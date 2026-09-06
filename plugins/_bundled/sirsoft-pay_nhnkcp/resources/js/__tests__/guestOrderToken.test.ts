/**
 * 비회원 주문 조회 토큰 배선 검증 (NHN KCP)
 *
 * 서버는 `X-Guest-Order-Token` 으로 비회원 주문 소유자를 확인한다. 화면이 그 값을
 * 보내지 않으면 서버는 주문을 찾지 못하고, 비회원 손님에게는 영수증 버튼이 아예
 * 나타나지 않는다 — 예외도 콘솔 오류도 남지 않아 원인을 알 수 없는 결함이라 구조로 잠근다.
 *
 * @scenario actor=guest, surface=receipt_injector
 *
 * @effects guest_order_token_header_built,guest_order_detail_route_matched
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { buildOrderRequestHeaders, getGuestOrderToken } from '../guestOrderToken';

const JS = resolve(__dirname, '..');

beforeEach(() => {
    localStorage.clear();
    sessionStorage.clear();
});

afterEach(() => {
    delete (window as any).G7Core;
});

describe('buildOrderRequestHeaders', () => {
    it('회원 토큰이 있으면 Authorization 을 쓴다', () => {
        localStorage.setItem('auth_token', 'member-token');

        expect(buildOrderRequestHeaders()).toEqual({
            Accept: 'application/json',
            Authorization: 'Bearer member-token',
        });
    });

    it('회원 토큰이 없고 비회원 토큰이 있으면 X-Guest-Order-Token 을 쓴다', () => {
        sessionStorage.setItem('g7_guest_order_token', 'guest-token');

        expect(buildOrderRequestHeaders()).toEqual({
            Accept: 'application/json',
            'X-Guest-Order-Token': 'guest-token',
        });
    });

    it('둘 다 없으면 null 을 돌려준다 (호출 자체를 막는다)', () => {
        expect(buildOrderRequestHeaders()).toBeNull();
    });

    it('sessionStorage 가 비어도 전역 상태 폴백을 본다', () => {
        (window as any).G7Core = {
            state: { get: (k: string) => (k === '_global' ? { guestOrderToken: 'global-token' } : undefined) },
        };

        expect(getGuestOrderToken()).toBe('global-token');
    });
});

describe('영수증 화면 배선', () => {
    it.each(['orderCompleteReceiptInjector.ts', 'mypageOrderShowInjector.ts'])(
        '%s 가 비회원 토큰 헤더를 경유한다',
        (file) => {
            const source = readFileSync(resolve(JS, file), 'utf-8');

            expect(
                source.includes('buildOrderRequestHeaders'),
                `${file} 가 비회원 토큰을 싣지 않습니다 — 비회원 손님에게 영수증 버튼이 나타나지 않습니다.`
            ).toBe(true);

            expect(
                /localStorage\.getItem\('auth_token'\)/.test(source),
                `${file} 에 회원 전용 토큰 조회가 남아 있습니다 — 비회원 분기가 다시 막힙니다.`
            ).toBe(false);
        }
    );

    it('주문 상세 경로 판정이 비회원 주소도 매칭한다', () => {
        const source = readFileSync(resolve(JS, 'mypageOrderShowInjector.ts'), 'utf-8');
        const match = source.match(/const ORDER_SHOW_RE = (\/.+\/);/);

        expect(match, '주문 상세 경로 정규식을 찾지 못했습니다.').not.toBeNull();

        const re = new RegExp(match![1].slice(1, -1));

        expect(re.test('/mypage/orders/12'), '회원 주문 상세 경로가 매칭되지 않습니다.').toBe(true);
        expect(
            re.test('/shop/guest/orders/20260904-0001'),
            '비회원 주문 상세 경로가 매칭되지 않습니다 — 그 화면에서는 영수증이 조회조차 되지 않습니다.'
        ).toBe(true);
    });
});
