/**
 * 페이지 이동 대상 정책 (support/navPolicy) 단위 테스트.
 *
 * 같은 탭 이동 sink(openWindow `_self` · redirect · navigate 폴백)와 로그인 복귀 경로가
 * 공유하는 판정이다. 여기서 뚫리면 `?redirect=javascript:…` 이 관리자 origin 에서 실행되고
 * `?redirect=//evil` 이 오프사이트로 나간다(KVE-2026-2348).
 */

import { describe, it, expect } from 'vitest';
import { sanitizeNavPath, resolveSafeNavPath, isDangerousScheme } from '../navPolicy';

describe('navPolicy', () => {
    describe('sanitizeNavPath — 거부', () => {
        /**
         * @scenario target_kind=script_scheme, sink=same_tab
         *
         * @effects nav_sink_rejects_script_scheme
         */
        it('javascript:/data:/vbscript: 스킴은 거부한다 (대소문자·공백·제어문자 변형 포함)', () => {
            for (const payload of [
                'javascript:alert(1)',
                'JaVaScRiPt:alert(1)',
                ' javascript:alert(1)',
                '\x01javascript:alert(1)',
                'java\tscript:alert(1)',
                'data:text/html,<script>alert(1)</script>',
                'vbscript:msgbox(1)',
            ]) {
                expect(sanitizeNavPath(payload)).toBeNull();
            }
        });

        /**
         * @scenario target_kind=offsite, sink=same_tab
         *
         * @effects nav_sink_rejects_offsite_target
         */
        it('외부 절대 URL·protocol-relative·정규화 우회는 거부한다', () => {
            for (const payload of [
                'https://example.com/',
                'http://example.com',
                '//example.com/',
                '///example.com/',
                '/\\example.com/',
                '/\\/example.com/',
                '/\t/example.com/',
                '\\\\example.com',
            ]) {
                expect(sanitizeNavPath(payload)).toBeNull();
            }
        });

        /**
         * @effects nav_sink_rejects_offsite_target
         */
        it('상대경로·빈 값·비문자열은 거부한다', () => {
            expect(sanitizeNavPath('admin/orders')).toBeNull();
            expect(sanitizeNavPath('')).toBeNull();
            expect(sanitizeNavPath('   ')).toBeNull();
            expect(sanitizeNavPath(null)).toBeNull();
            expect(sanitizeNavPath(undefined)).toBeNull();
            expect(sanitizeNavPath({ toString: () => '/admin' })).toBeNull();
        });
    });

    describe('sanitizeNavPath — 정상 통과', () => {
        /**
         * @scenario target_kind=same_origin, sink=same_tab
         *
         * @effects same_origin_redirect_preserved
         */
        it('same-origin 절대 경로는 쿼리·해시를 바꾸지 않고 통과한다', () => {
            expect(sanitizeNavPath('/admin')).toBe('/admin');
            expect(sanitizeNavPath('/admin/orders?status=paid&page=2')).toBe(
                '/admin/orders?status=paid&page=2'
            );
            expect(sanitizeNavPath('/board/free#comment-3')).toBe('/board/free#comment-3');
            // 쿼리 안 백슬래시는 브라우저가 슬래시로 바꾸지 않으므로 그대로 둔다
            expect(sanitizeNavPath('/search?q=a\\b')).toBe('/search?q=a\\b');
            // 경로 중간의 연속 슬래시·콜론은 authority/scheme 을 만들지 않는다
            expect(sanitizeNavPath('/js//a')).toBe('/js//a');
            expect(sanitizeNavPath('/shop/item:1')).toBe('/shop/item:1');
        });

        /**
         * @effects same_origin_redirect_preserved
         */
        it('양끝 공백은 걷어낸 값을 돌려준다', () => {
            expect(sanitizeNavPath('  /mypage  ')).toBe('/mypage');
        });
    });

    describe('resolveSafeNavPath', () => {
        /**
         * @effects nav_sink_rejects_offsite_target, same_origin_redirect_preserved
         */
        it('안전하지 않으면 fallback, 안전하면 원래 경로', () => {
            expect(resolveSafeNavPath('javascript:alert(1)', '/')).toBe('/');
            expect(resolveSafeNavPath('//evil.com', '/admin')).toBe('/admin');
            expect(resolveSafeNavPath('/admin/users', '/')).toBe('/admin/users');
        });
    });

    describe('isDangerousScheme', () => {
        /**
         * @scenario target_kind=script_scheme, sink=new_window_or_link
         *
         * @effects dangerous_href_neutralized
         */
        it('실행 스킴을 판정한다 (변형 포함)', () => {
            expect(isDangerousScheme('javascript:alert(1)')).toBe(true);
            expect(isDangerousScheme('  JAVASCRIPT:alert(1)')).toBe(true);
            expect(isDangerousScheme('\x00\x1fjavascript:alert(1)')).toBe(true);
            expect(isDangerousScheme('jav\nascript:alert(1)')).toBe(true);
            expect(isDangerousScheme('data:text/html;base64,PHNjcmlwdD4=')).toBe(true);
            expect(isDangerousScheme('vbscript:x')).toBe(true);
        });

        /**
         * @scenario target_kind=same_origin, sink=new_window_or_link
         *
         * @effects external_link_preserved
         */
        it('정상 외부 링크·mailto·tel·blob·경로는 위험 스킴이 아니다 (과차단 방지)', () => {
            expect(isDangerousScheme('https://sir.kr/')).toBe(false);
            expect(isDangerousScheme('http://example.com')).toBe(false);
            expect(isDangerousScheme('mailto:help@example.com')).toBe(false);
            expect(isDangerousScheme('tel:010-0000-0000')).toBe(false);
            expect(isDangerousScheme('blob:https://g7.test/uuid')).toBe(false);
            expect(isDangerousScheme('/admin')).toBe(false);
            expect(isDangerousScheme('#')).toBe(false);
            expect(isDangerousScheme('/docs/javascript:intro')).toBe(false);
            expect(isDangerousScheme(undefined)).toBe(false);
        });
    });
});
