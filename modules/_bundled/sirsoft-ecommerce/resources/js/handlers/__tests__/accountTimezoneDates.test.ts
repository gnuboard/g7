/**
 * 관리자 화면 날짜 버튼 — 「오늘」은 계정 시간대의 날짜 (7.1.0 전수 실측 QE-O3 · validate q2 R1)
 *
 * 원칙: 유저가 보는 화면은 유저의 시간대 기준. 관리자 화면의 날짜 버튼은 그 관리자의 계정 시간대(회원 시간대 →
 * 사이트 기본 시간대)로 「오늘」을 정한다.
 * - 상품 라벨 기간 버튼(편집 패널·인라인)은 `toISOString()`(UTC 날짜)·브라우저 날짜로 시작일을 만들어, 한국 시간
 *   오전 9시 전에는 시작일이 어제였다.
 * - 목록 빠른 기간(setDateRange)은 브라우저 날짜라 계정과 브라우저의 날짜가 다른 시간에 하루 어긋났다.
 *
 * 기준 순간 UTC 2026-10-08 05:00 = 한국(이 PC)·UTC 10-08, 호놀룰루 10-07 19:00 — 계정 시간대를 호놀룰루로 둬 갈라 본다.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { setDateRangeHandler } from '../setDateRange';
import { setLabelDatePresetHandler } from '../miscHandlers';
import { setLabelDatePresetInlineHandler } from '../labelHandlers';

/**
 * 로그인 사용자·사이트 시간대와 상태 API 를 흉내 낸 G7Core 를 심는다.
 */
function installG7Core(userTimezone: string | null, siteTimezone: string | null, local: Record<string, any> = {}) {
    const g7Core = {
        AuthManager: { getInstance: () => ({ getUser: () => (userTimezone ? { timezone: userTimezone } : null) }) },
        state: {
            get: () => ({ settings: { general: { timezone: siteTimezone } } }),
            getLocal: () => local,
            setLocal: vi.fn(),
            setGlobal: vi.fn(),
        },
    };
    (window as any).G7Core = g7Core;
    return g7Core;
}

describe('이커머스 관리자 날짜 버튼 — 계정 시간대', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(Date.UTC(2026, 9, 8, 5, 0, 0)));
    });

    afterEach(() => {
        vi.useRealTimers();
        delete (window as any).G7Core;
    });

    // @scenario boundary=admin_quick_dates, timezone=account
    // @effects ecommerce_admin_date_buttons_use_account_timezone
    it('목록 빠른 기간(setDateRange)은 회원 시간대의 오늘로 계산한다', () => {
        installG7Core('Pacific/Honolulu', 'Asia/Seoul');

        expect(setDateRangeHandler({ params: { preset: 'today' } })).toEqual({ startDate: '2026-10-07', endDate: '2026-10-07', preset: 'today' });
        expect(setDateRangeHandler({ params: { preset: '3days' } }).startDate).toBe('2026-10-05');
        expect(setDateRangeHandler({ params: { preset: 'month' } }).startDate).toBe('2026-09-07');
    });

    // @scenario boundary=admin_quick_dates, timezone=account
    // @effects ecommerce_admin_date_buttons_use_account_timezone
    it('회원 시간대가 없으면 사이트 기본 시간대의 오늘로 계산한다', () => {
        installG7Core(null, 'Pacific/Honolulu');

        expect(setDateRangeHandler({ params: { preset: 'week' } })).toEqual({ startDate: '2026-10-01', endDate: '2026-10-07', preset: 'week' });
    });

    // @scenario boundary=product_label_period, timezone=account
    // @effects ecommerce_admin_date_buttons_use_account_timezone
    it('라벨 기간 버튼(편집 패널)은 계정 시간대의 오늘부터 채운다', () => {
        const g7Core = installG7Core('Pacific/Honolulu', 'Asia/Seoul');

        setLabelDatePresetHandler({ handler: 'x', params: { preset: '7d' } } as any, {} as any);

        expect(g7Core.state.setGlobal).toHaveBeenCalledWith({
            'labelFormData.start_date': '2026-10-07',
            'labelFormData.end_date': '2026-10-14',
        });
    });

    // @scenario boundary=product_label_period, timezone=account
    // @effects ecommerce_admin_date_buttons_use_account_timezone
    it('라벨 기간 버튼(상품 폼 인라인)은 UTC 날짜가 아니라 계정 시간대의 오늘부터 채운다', () => {
        // 한국 계정 · 기준 순간 UTC 2026-10-07 20:00 = 한국 10-08 05:00 — UTC 날짜(10-07)로 만들면 어제가 된다.
        vi.setSystemTime(new Date(Date.UTC(2026, 9, 7, 20, 0, 0)));
        const g7Core = installG7Core('Asia/Seoul', 'Asia/Seoul', {
            form: { label_assignments: [{ label_id: 5, started_at: null, ended_at: null }] },
            ui: { lastClickedLabelId: 5 },
        });

        setLabelDatePresetInlineHandler({ handler: 'x', params: { preset: '30d' } } as any, {} as any);

        expect(g7Core.state.setLocal).toHaveBeenCalledWith({
            'form.label_assignments': [{ label_id: 5, started_at: '2026-10-08', ended_at: '2026-11-07' }],
            hasChanges: true,
        });
    });
});
