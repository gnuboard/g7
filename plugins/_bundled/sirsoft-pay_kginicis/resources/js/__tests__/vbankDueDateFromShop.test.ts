/**
 * KG이니시스 입금 안내의 입금 기한은 쇼핑몰이 서식을 입힌 값을 그대로 쓴다 (#599)
 *
 * 종전에는 플러그인이 입금 기한(ISO 값)을 한국 서식·한국 시간(ko-KR · Asia/Seoul)으로 다시 서식을 입혀, 영어·일본어
 * 화면에서도 「2026년 9월 30일 오후 11:59」처럼 나왔다. 날짜 서식은 쇼핑몰(이커머스)이 사용자 언어·시간대로 입혀
 * vbank_due_at_formatted 로 주므로 플러그인은 그 값을 그대로 쓴다. 주문 완료 화면의 날짜 글자를 한국식으로
 * 덮어쓰던 동작도 없앴다(그 화면은 이미 쇼핑몰의 서식 값을 표시한다).
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { installVbankInfoInjector } from '../vbankInfoInjector';

const FLAG = '__kginicisVbankInfoInjectorInstalled';

describe('KG이니시스 입금 기한 — 쇼핑몰 서식 값 사용', () => {
    let originalFetch: typeof globalThis.fetch;

    beforeEach(() => {
        originalFetch = globalThis.fetch;
        vi.useFakeTimers();
        globalThis.fetch = vi.fn().mockResolvedValue({ ok: true, status: 200, json: async () => ({}) } as Response);
    });

    afterEach(() => {
        globalThis.fetch = originalFetch;
        document.body.innerHTML = '';
        delete (window as Record<string, unknown>)[FLAG];
        delete (window as any).G7Core;
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    /**
     * 마이페이지 입금 안내의 입금 기한은 vbank_due_at_formatted 를 그대로 보여 주고 한국식으로 다시 서식을 입히지 않는다.
     *
     * @scenario surface=mypage_vbank_panel
     * @effects due_date_uses_shop_formatted_value
     */
    it('입금 기한을 쇼핑몰이 준 서식 값 그대로 표시한다', async () => {
        history.pushState(null, '', '/mypage/orders/20260928-0000000950');
        (window as any).G7Core = {
            getState: () => ({
                currentDataContext: {
                    order: {
                        data: {
                            order_number: '20260928-0000000950',
                            payment: {
                                pg_provider: 'kginicis',
                                payment_status: 'waiting_deposit',
                                payment_method: 'vbank',
                                vbank_name: 'Kookmin Bank',
                                vbank_number: '12345678901234',
                                vbank_holder: 'Test Inc.',
                                vbank_due_at: '2026-09-30T23:59:59+09:00',
                                vbank_due_at_formatted: '2026-09-30 10:59 AM',
                            },
                        },
                    },
                },
            }),
        };
        document.body.innerHTML = '<section id="order_payment_info_panel"><div class="space-y-2"></div></section>';

        installVbankInfoInjector();
        await vi.advanceTimersByTimeAsync(3000);

        const section = document.getElementById('kginicis-mp-vbank-info');
        expect(section).not.toBeNull();
        expect(section!.textContent).toContain('2026-09-30 10:59 AM');
        expect(section!.textContent).not.toMatch(/\d{4}년/);
    });

    /**
     * 결제 정보 카드는 id 로만 찾는다 — 「결제 정보」 제목만 있는 화면에는 입금 안내를 붙이지 않는다.
     *
     * @scenario surface=card_without_id
     * @effects card_found_by_id_only
     */
    it('결제 정보 카드 id 가 없으면 제목이 있어도 입금 안내를 붙이지 않는다', async () => {
        history.pushState(null, '', '/mypage/orders/20260928-0000000952');
        (window as any).G7Core = {
            getState: () => ({
                currentDataContext: {
                    order: {
                        data: {
                            order_number: '20260928-0000000952',
                            payment: {
                                pg_provider: 'kginicis',
                                payment_status: 'waiting_deposit',
                                payment_method: 'vbank',
                                vbank_number: '12345678901234',
                                vbank_due_at_formatted: '2026-09-30 10:59 AM',
                            },
                        },
                    },
                },
            }),
        };
        document.body.innerHTML = '<div><div><h3>결제 정보</h3></div><div class="space-y-2"></div></div>';

        installVbankInfoInjector();
        await vi.advanceTimersByTimeAsync(3000);

        expect(document.getElementById('kginicis-mp-vbank-info')).toBeNull();
    });

    /**
     * 주문 완료 화면의 날짜 글자를 덮어쓰지 않는다 — 화면이 그린 값이 그대로 남는다.
     *
     * @scenario surface=order_complete_page
     * @effects order_complete_text_untouched
     */
    it('주문 완료 화면의 날짜 글자를 한국식으로 덮어쓰지 않는다', async () => {
        history.pushState(null, '', '/shop/orders/20260928-0000000951/complete');
        document.body.innerHTML = '<p id="due">Deposit by 2026-09-30T23:59:59+09:00</p>';

        installVbankInfoInjector();
        await vi.advanceTimersByTimeAsync(3000);

        expect(document.getElementById('due')!.textContent).toBe('Deposit by 2026-09-30T23:59:59+09:00');
    });
});
