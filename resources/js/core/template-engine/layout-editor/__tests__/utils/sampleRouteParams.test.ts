/**
 * sampleRouteParams.test.ts — `:param` 토큰 추출 + 휴리스틱 샘플 값 회귀
 *
 *  (S4'') — 라우트 path 의 path parameter 가
 * 비어있어 endpoint URL 이 `/api/.../{param}/...` 깨지는 회귀를 회피하기 위한
 * 자동 주입 헬퍼.
 *
 * 매트릭스:
 *  - 빈/null path → {}
 *  - 단일 `:id` → { id: '1' } (NUMERIC_PARAM_HINTS 매칭)
 *  - 단일 `:slug` → { slug: 'sample' } (NUMERIC 미매칭)
 *  - `*Id` / `*_id` 접미사 → '1' 반환
 *  - 다중 토큰 + 혼합 케이스
 *
 * @since engine-v1.50.0
 */

import { describe, it, expect } from 'vitest';
import { deriveSampleRouteParams } from '../../utils/sampleRouteParams';

describe('deriveSampleRouteParams — 빈 입력', () => {
    it('null path → 빈 객체', () => {
        expect(deriveSampleRouteParams(null)).toEqual({});
    });

    it('undefined path → 빈 객체', () => {
        expect(deriveSampleRouteParams(undefined)).toEqual({});
    });

    it('빈 문자열 path → 빈 객체', () => {
        expect(deriveSampleRouteParams('')).toEqual({});
    });

    it('토큰 없는 정적 path → 빈 객체', () => {
        expect(deriveSampleRouteParams('/login')).toEqual({});
        expect(deriveSampleRouteParams('/admin/users')).toEqual({});
    });
});

describe('deriveSampleRouteParams — 단일 토큰 휴리스틱', () => {
    it(':id → "1" (NUMERIC_PARAM_HINTS)', () => {
        expect(deriveSampleRouteParams('/users/:id')).toEqual({ id: '1' });
    });

    it(':slug → "sample" (NUMERIC 미매칭)', () => {
        expect(deriveSampleRouteParams('/board/:slug')).toEqual({ slug: 'sample' });
    });

    it(':userId → "1" (camelCase Id 접미사)', () => {
        expect(deriveSampleRouteParams('/profile/:userId')).toEqual({ userId: '1' });
    });

    it(':user_id → "1" (snake_case _id 접미사)', () => {
        expect(deriveSampleRouteParams('/profile/:user_id')).toEqual({ user_id: '1' });
    });

    it(':productId → "1" (NUMERIC_PARAM_HINTS 명시 hint)', () => {
        expect(deriveSampleRouteParams('/products/:productId')).toEqual({ productId: '1' });
    });

    it(':code → "sample" (NUMERIC 미매칭)', () => {
        expect(deriveSampleRouteParams('/coupons/:code')).toEqual({ code: 'sample' });
    });

    it(':key → "sample"', () => {
        expect(deriveSampleRouteParams('/api/:key')).toEqual({ key: 'sample' });
    });
});

describe('deriveSampleRouteParams — 다중 토큰', () => {
    it('/board/:slug/:id → { slug: "sample", id: "1" }', () => {
        expect(deriveSampleRouteParams('/board/:slug/:id')).toEqual({
            slug: 'sample',
            id: '1',
        });
    });

    it('/orders/:orderId/items/:productId → 모두 "1"', () => {
        expect(deriveSampleRouteParams('/orders/:orderId/items/:productId')).toEqual({
            orderId: '1',
            productId: '1',
        });
    });

    it('혼합 — slug + id + 알 수 없는 토큰', () => {
        expect(deriveSampleRouteParams('/board/:slug/:id/:tab')).toEqual({
            slug: 'sample',
            id: '1',
            tab: 'sample',
        });
    });
});

describe('deriveSampleRouteParams — 토큰 형식', () => {
    it('토큰 이름이 영문자+숫자+언더스코어 패턴 허용', () => {
        expect(deriveSampleRouteParams('/path/:my_param_1')).toEqual({ my_param_1: 'sample' });
    });

    it('`:` 만 있고 토큰 이름 없으면 추출되지 않음', () => {
        expect(deriveSampleRouteParams('/path/:/segment')).toEqual({});
    });

    it('같은 토큰 이름 중복 — 마지막 값 유지 (Object key 동작)', () => {
        // 같은 이름의 토큰이 반복되어도 단일 매핑으로 수렴
        expect(deriveSampleRouteParams('/a/:id/b/:id')).toEqual({ id: '1' });
    });
});
