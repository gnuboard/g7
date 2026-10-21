/**
 * 페이지 이동 대상 정책 (support/navPolicy) 단위 테스트.
 *
 * 같은 탭 이동 sink(openWindow `_self` · redirect · navigate 폴백)와 로그인 복귀 경로가
 * 공유하는 판정이다. 여기서 뚫리면 `?redirect=javascript:…` 이 관리자 origin 에서 실행되고
 * `?redirect=//evil` 이 오프사이트로 나간다(KVE-2026-2348).
 */

import { describe, it, expect } from 'vitest';
import { sanitizeNavPath, resolveSameTabNavPath, isDangerousScheme, toSameOriginNavPath } from '../navPolicy';

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

        /**
         * 서버가 알림 이동 주소(click_url)를 같은 사이트의 전체 주소로 준다 — 경로로 바꿔 통과시킨다
         * (종전에는 스킴이 있다는 이유로 거부해 첫 화면 `/` 로 문서 전체 이동했다).
         *
         * @scenario target_kind=same_origin_full_url, sink=same_tab
         *
         * @effects same_origin_full_url_becomes_path
         */
        it('같은 출처 전체 주소는 경로(+쿼리·해시)로 바꿔 통과한다', () => {
            const origin = window.location.origin;
            expect(sanitizeNavPath(`${origin}/board/free/14`)).toBe('/board/free/14');
            expect(sanitizeNavPath(`${origin}/board/free/14?page=2#comment-3`)).toBe('/board/free/14?page=2#comment-3');
            expect(sanitizeNavPath(`  ${origin}/admin/users  `)).toBe('/admin/users');
            expect(sanitizeNavPath(origin)).toBe('/');
            expect(toSameOriginNavPath(`${origin}/mypage`)).toBe('/mypage');
        });

        /**
         * @scenario target_kind=offsite, sink=same_tab
         *
         * @effects nav_sink_rejects_offsite_target
         */
        it('같은 출처처럼 보이는 다른 출처(사용자 정보·다른 스킴·다른 포트)는 계속 거부한다', () => {
            const { protocol, hostname, origin } = window.location;
            const otherScheme = protocol === 'https:' ? 'http:' : 'https:';
            for (const payload of [
                `${origin}@example.com/`,
                `${otherScheme}//${hostname}/x`,
                `${protocol}//${hostname}:1/x`,
                `${protocol}//${hostname}.example.com/x`,
                // 같은 출처 전체 주소인데 경로가 `//다른호스트` — 경로로 바꾸면 같은 탭 sink 가 바깥으로 간다
                `${origin}//example.com/x`,
                `${origin}/\\example.com/x`,
                `${origin}/\\/example.com/x`,
            ]) {
                expect(sanitizeNavPath(payload)).toBeNull();
                expect(toSameOriginNavPath(payload)).toBeNull();
            }
            // 스킴 없는 상대경로는 이 함수가 경로로 바꾸지 않는다(같은 탭 sink 는 절대 경로만)
            expect(toSameOriginNavPath('admin/orders')).toBeNull();
        });
    });

    describe('resolveSameTabNavPath', () => {
        /**
         * 거부된 주소는 null(이동하지 않음) — 종전 `resolveSafeNavPath` 는 fallback 으로 바꿔 첫 화면으로 보냈다
         * (결정 2026-10-15). 주소가 비면 「어디든 가야 하는」 자리의 기본 경로를 그대로 준다.
         *
         * @effects nav_sink_rejects_offsite_target, same_origin_redirect_preserved
         */
        it('거부된 주소는 null, 빈 주소는 fallback, 안전하면 그 경로', () => {
            expect(resolveSameTabNavPath('javascript:alert(1)', '/')).toBeNull();
            expect(resolveSameTabNavPath('//evil.com', '/admin')).toBeNull();
            expect(resolveSameTabNavPath('https://evil.example/x', '/')).toBeNull();
            expect(resolveSameTabNavPath('', '/')).toBe('/');
            expect(resolveSameTabNavPath('   ', '/admin')).toBe('/admin');
            expect(resolveSameTabNavPath(undefined, '/')).toBe('/');
            expect(resolveSameTabNavPath('/admin/users', '/')).toBe('/admin/users');
            expect(resolveSameTabNavPath(`${window.location.origin}/admin/users?x=1`, '/')).toBe('/admin/users?x=1');
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
