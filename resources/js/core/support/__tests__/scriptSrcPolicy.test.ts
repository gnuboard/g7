/**
 * 동적 스크립트 주입 출처 정책 (support/scriptSrcPolicy) 단위 테스트.
 *
 * `docs/frontend/security.md` "same-origin 판정은 브라우저 URL 파서와 같아야 한다" 의
 * 우회 표 전 케이스를 고정한다. 이 판정은 레이아웃 `scripts[]` 뿐 아니라 loadScript
 * 액션·확장 핸들러 재로드·편집기 프리뷰·`G7Core.asset.loadScript` 가 공유하므로,
 * 여기서 뚫리면 그 모든 주입 경로가 같이 뚫린다.
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import {
    normalizeScriptSrcForOriginCheck,
    extractScriptHost,
    getTrustedScriptHosts,
    isAllowedScriptSrc,
} from '../scriptSrcPolicy';

describe('scriptSrcPolicy', () => {
    beforeEach(() => {
        Object.defineProperty(window, 'location', {
            value: {
                href: 'https://g7.test/',
                origin: 'https://g7.test',
                protocol: 'https:',
                pathname: '/',
                search: '',
            },
            writable: true,
            configurable: true,
        });
    });

    afterEach(() => {
        delete (window as any).G7Config;
    });

    describe('normalizeScriptSrcForOriginCheck', () => {
        it('tab/LF/CR 를 제거한다', () => {
            expect(normalizeScriptSrcForOriginCheck('/\t/evil.com/x.js')).toBe('//evil.com/x.js');
            expect(normalizeScriptSrcForOriginCheck('/\n/evil.com/x.js')).toBe('//evil.com/x.js');
            expect(normalizeScriptSrcForOriginCheck('/\r/evil.com/x.js')).toBe('//evil.com/x.js');
        });

        it('백슬래시를 슬래시로 바꾼다', () => {
            expect(normalizeScriptSrcForOriginCheck('/\\/evil.com/x.js')).toBe('//evil.com/x.js');
            expect(normalizeScriptSrcForOriginCheck('/\\evil.com/x.js')).toBe('//evil.com/x.js');
        });

        it('선행 슬래시 런을 접는다 (scheme 유무 모두)', () => {
            expect(normalizeScriptSrcForOriginCheck('///evil.com/x.js')).toBe('//evil.com/x.js');
            expect(normalizeScriptSrcForOriginCheck('https:///evil.com/x.js')).toBe(
                'https://evil.com/x.js'
            );
        });

        it('경로 중간의 연속 슬래시는 건드리지 않는다 (과차단 방지)', () => {
            expect(normalizeScriptSrcForOriginCheck('/js//a.js')).toBe('/js//a.js');
        });
    });

    describe('extractScriptHost', () => {
        it('protocol-relative 와 절대 URL 의 호스트를 소문자로 돌려준다', () => {
            expect(extractScriptHost('//CDN.Example.com/x.js')).toBe('cdn.example.com');
            expect(extractScriptHost('https://CDN.Example.com/x.js')).toBe('cdn.example.com');
        });

        it('http/https 가 아닌 scheme 은 null', () => {
            expect(extractScriptHost('javascript:alert(1)')).toBeNull();
            expect(extractScriptHost('data:text/javascript,alert(1)')).toBeNull();
        });

        it('same-origin 경로는 문서 origin 의 호스트로 해석된다', () => {
            expect(extractScriptHost('/api/widget.js')).toBe('g7.test');
        });
    });

    describe('getTrustedScriptHosts', () => {
        it('G7Config 미설정 시 빈 배열', () => {
            expect(getTrustedScriptHosts()).toEqual([]);
        });

        it('배열이 아니면 빈 배열', () => {
            (window as any).G7Config = { trustedScriptHosts: 'cdn.example.com' };
            expect(getTrustedScriptHosts()).toEqual([]);
        });

        it('소문자로 정규화해 돌려준다', () => {
            (window as any).G7Config = { trustedScriptHosts: ['CDN.Example.com'] };
            expect(getTrustedScriptHosts()).toEqual(['cdn.example.com']);
        });
    });

    describe('isAllowedScriptSrc — security.md 우회 표', () => {
        /** @effects trusted_script_host_allowlist_wired */
        it('same-origin 절대 경로는 허용', () => {
            expect(isAllowedScriptSrc('/api/widget.js', [])).toBe(true);
        });

        /** @effects untrusted_external_script_blocked */
        it('protocol-relative 외부 origin 은 차단', () => {
            expect(isAllowedScriptSrc('//evil.com/x.js', [])).toBe(false);
        });

        it.each([
            ['/\\/evil.com/x.js'],
            ['/\\evil.com/x.js'],
            ['/\t/evil.com/x.js'],
            ['///evil.com/x.js'],
        ])('authority 우회 형태 %s 는 차단 (@effects untrusted_external_script_blocked)', src => {
            expect(isAllowedScriptSrc(src, [])).toBe(false);
        });

        it('신뢰 호스트를 우회 형태로 쓴 것은 허용 (과차단 방지)', () => {
            expect(isAllowedScriptSrc('/\\/cdn.trusted.com/x.js', ['cdn.trusted.com'])).toBe(true);
        });

        it('경로 중간 백슬래시는 same-origin 으로 통과 (과차단 방지)', () => {
            expect(isAllowedScriptSrc('/js/a\\b.js', [])).toBe(true);
        });

        it('userinfo 자리에 신뢰 호스트를 끼운 주소는 실제 호스트로 판정한다', () => {
            expect(
                isAllowedScriptSrc('https://evil.com\\@cdn.trusted.com/x.js', ['cdn.trusted.com'])
            ).toBe(false);
        });

        /** @effects trusted_script_host_allowlist_wired */
        it('신뢰 호스트는 허용 (protocol-relative / https 양쪽)', () => {
            expect(isAllowedScriptSrc('//cdn.trusted.com/x.js', ['cdn.trusted.com'])).toBe(true);
            expect(isAllowedScriptSrc('https://cdn.trusted.com/x.js', ['cdn.trusted.com'])).toBe(
                true
            );
        });

        it('신뢰 호스트 비교는 대소문자를 무시한다', () => {
            expect(isAllowedScriptSrc('https://CDN.Trusted.com/x.js', ['cdn.trusted.com'])).toBe(
                true
            );
            expect(isAllowedScriptSrc('https://cdn.trusted.com/x.js', ['CDN.Trusted.com'])).toBe(
                true
            );
        });

        it('javascript:/data: scheme 은 차단', () => {
            expect(isAllowedScriptSrc('javascript:alert(1)', [])).toBe(false);
            expect(isAllowedScriptSrc('data:text/javascript,alert(1)', [])).toBe(false);
        });

        it('빈 값·비문자열은 차단', () => {
            expect(isAllowedScriptSrc('', [])).toBe(false);
            expect(isAllowedScriptSrc('   ', [])).toBe(false);
            expect(isAllowedScriptSrc(undefined as any, [])).toBe(false);
        });

        it('상대 경로(`/` 미시작)는 차단', () => {
            expect(isAllowedScriptSrc('js/widget.js', [])).toBe(false);
        });

        it('인자 생략 시 window.G7Config.trustedScriptHosts 를 읽는다', () => {
            (window as any).G7Config = { trustedScriptHosts: ['cdn.trusted.com'] };
            expect(isAllowedScriptSrc('https://cdn.trusted.com/x.js')).toBe(true);
            expect(isAllowedScriptSrc('https://evil.com/x.js')).toBe(false);
        });
    });
});
