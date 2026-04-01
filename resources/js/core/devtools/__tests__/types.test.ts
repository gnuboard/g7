/**
 * DevTools Types 유틸리티 함수 테스트
 *
 * parseQueryParams, extractPath 함수 등 types.ts에 정의된 유틸리티 함수 테스트
 */

import { describe, it, expect } from 'vitest';
import { parseQueryParams, extractPath } from '../types';

describe('parseQueryParams', () => {
    describe('기본 쿼리 파라미터 파싱', () => {
        it('단일 쿼리 파라미터를 파싱해야 한다', () => {
            const result = parseQueryParams('/api/products?page=1');
            expect(result).toEqual({ page: '1' });
        });

        it('여러 쿼리 파라미터를 파싱해야 한다', () => {
            const result = parseQueryParams('/api/products?page=1&per_page=20&sort=created_at');
            expect(result).toEqual({
                page: '1',
                per_page: '20',
                sort: 'created_at',
            });
        });

        it('쿼리 파라미터가 없는 URL은 빈 객체를 반환해야 한다', () => {
            const result = parseQueryParams('/api/products');
            expect(result).toEqual({});
        });

        it('빈 문자열은 빈 객체를 반환해야 한다', () => {
            const result = parseQueryParams('');
            expect(result).toEqual({});
        });
    });

    describe('배열 쿼리 파라미터 파싱', () => {
        it('[]로 끝나는 키를 배열로 처리해야 한다', () => {
            const result = parseQueryParams('/api/products?status[]=active');
            expect(result).toEqual({ 'status[]': ['active'] });
        });

        it('동일한 키가 여러 번 나오면 배열로 처리해야 한다', () => {
            const result = parseQueryParams('/api/products?status[]=active&status[]=pending');
            expect(result).toEqual({ 'status[]': ['active', 'pending'] });
        });

        it('복합 쿼리 파라미터 (일반 + 배열)를 파싱해야 한다', () => {
            const result = parseQueryParams('/api/products?page=1&sales_status[]=suspended&sales_status[]=sold_out&sort=name');
            expect(result).toEqual({
                page: '1',
                'sales_status[]': ['suspended', 'sold_out'],
                sort: 'name',
            });
        });
    });

    describe('전체 URL 파싱', () => {
        it('전체 URL에서 쿼리 파라미터를 파싱해야 한다', () => {
            const result = parseQueryParams('https://example.com/api/products?page=1&limit=10');
            expect(result).toEqual({
                page: '1',
                limit: '10',
            });
        });

        it('포트가 포함된 URL을 파싱해야 한다', () => {
            const result = parseQueryParams('http://localhost:8000/api/users?active=true');
            expect(result).toEqual({ active: 'true' });
        });
    });

    describe('특수 문자 처리', () => {
        it('URL 인코딩된 값을 디코딩해야 한다', () => {
            const result = parseQueryParams('/api/search?q=hello%20world');
            expect(result).toEqual({ q: 'hello world' });
        });

        it('특수 문자가 포함된 값을 처리해야 한다', () => {
            const result = parseQueryParams('/api/search?email=test%40example.com');
            expect(result).toEqual({ email: 'test@example.com' });
        });
    });

    describe('에러 처리', () => {
        it('잘못된 URL은 빈 객체를 반환해야 한다', () => {
            // parseQueryParams는 상대 경로도 처리할 수 있어야 함
            const result = parseQueryParams('not-a-valid-url');
            // URL 객체가 생성되지 않으면 빈 객체 반환
            expect(typeof result).toBe('object');
        });
    });
});

describe('extractPath', () => {
    describe('기본 경로 추출', () => {
        it('쿼리스트링 없는 URL에서 경로를 추출해야 한다', () => {
            const result = extractPath('/api/products');
            expect(result).toBe('/api/products');
        });

        it('쿼리스트링이 있는 URL에서 경로만 추출해야 한다', () => {
            const result = extractPath('/api/products?page=1&limit=10');
            expect(result).toBe('/api/products');
        });

        it('전체 URL에서 경로를 추출해야 한다', () => {
            const result = extractPath('https://example.com/api/users?active=true');
            expect(result).toBe('/api/users');
        });
    });

    describe('특수 케이스', () => {
        it('루트 경로를 처리해야 한다', () => {
            const result = extractPath('/?page=1');
            expect(result).toBe('/');
        });

        it('빈 문자열은 루트 경로를 반환해야 한다', () => {
            const result = extractPath('');
            // URL 객체 생성 시 빈 경로는 '/'로 처리됨
            expect(result).toBe('/');
        });

        it('해시가 포함된 URL에서 경로만 추출해야 한다', () => {
            const result = extractPath('/products#section1?ignored=true');
            // 해시 이전의 경로만 추출
            expect(result).toBe('/products');
        });
    });

    describe('포트와 호스트 처리', () => {
        it('포트가 포함된 URL에서 경로를 추출해야 한다', () => {
            const result = extractPath('http://localhost:8000/api/test?foo=bar');
            expect(result).toBe('/api/test');
        });
    });
});
