/**
 * setDateRange 핸들러 테스트
 *
 * 핸들러는 순수 함수로 날짜 범위를 계산하여 반환만 합니다.
 * 상태 업데이트는 레이아웃 JSON의 sequence + setState에서 처리합니다.
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { setDateRangeHandler } from '../setDateRange';

describe('setDateRangeHandler', () => {
    beforeEach(() => {
        // 테스트 날짜 고정 (2024-01-15)
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2024-01-15'));
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    describe('today preset', () => {
        it('오늘 날짜로 시작일과 종료일을 설정해야 한다', () => {
            const result = setDateRangeHandler(
                { params: { preset: 'today' } }
            );

            expect(result.startDate).toBe('2024-01-15');
            expect(result.endDate).toBe('2024-01-15');
            expect(result.preset).toBe('today');
        });
    });

    describe('3days preset', () => {
        it('오늘 포함 3일 전부터 오늘까지 설정해야 한다', () => {
            const result = setDateRangeHandler(
                { params: { preset: '3days' } }
            );

            expect(result.startDate).toBe('2024-01-13');
            expect(result.endDate).toBe('2024-01-15');
            expect(result.preset).toBe('3days');
        });
    });

    describe('week preset', () => {
        it('오늘 포함 7일 전부터 오늘까지 설정해야 한다', () => {
            const result = setDateRangeHandler(
                { params: { preset: 'week' } }
            );

            expect(result.startDate).toBe('2024-01-09');
            expect(result.endDate).toBe('2024-01-15');
            expect(result.preset).toBe('week');
        });
    });

    describe('month preset', () => {
        it('1개월 전부터 오늘까지 설정해야 한다', () => {
            const result = setDateRangeHandler(
                { params: { preset: 'month' } }
            );

            expect(result.startDate).toBe('2023-12-15');
            expect(result.endDate).toBe('2024-01-15');
            expect(result.preset).toBe('month');
        });
    });

    describe('3months preset', () => {
        it('3개월 전부터 오늘까지 설정해야 한다', () => {
            const result = setDateRangeHandler(
                { params: { preset: '3months' } }
            );

            expect(result.startDate).toBe('2023-10-15');
            expect(result.endDate).toBe('2024-01-15');
            expect(result.preset).toBe('3months');
        });
    });

    describe('6months preset', () => {
        it('6개월 전부터 오늘까지 설정해야 한다', () => {
            const result = setDateRangeHandler(
                { params: { preset: '6months' } }
            );

            expect(result.startDate).toBe('2023-07-15');
            expect(result.endDate).toBe('2024-01-15');
            expect(result.preset).toBe('6months');
        });
    });

    describe('1year preset', () => {
        it('1년 전부터 오늘까지 설정해야 한다', () => {
            const result = setDateRangeHandler(
                { params: { preset: '1year' } }
            );

            expect(result.startDate).toBe('2023-01-15');
            expect(result.endDate).toBe('2024-01-15');
            expect(result.preset).toBe('1year');
        });
    });

    describe('all preset', () => {
        it('시작일과 종료일을 빈 문자열로 설정해야 한다', () => {
            const result = setDateRangeHandler(
                { params: { preset: 'all' } }
            );

            expect(result.startDate).toBe('');
            expect(result.endDate).toBe('');
            expect(result.preset).toBe('all');
        });
    });

    describe('params 없음', () => {
        it('기본값으로 today를 사용해야 한다', () => {
            const result = setDateRangeHandler({});

            expect(result.preset).toBe('today');
        });
    });
});
