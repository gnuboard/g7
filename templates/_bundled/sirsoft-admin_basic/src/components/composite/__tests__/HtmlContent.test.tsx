/**
 * HtmlContent 정화 회귀 테스트
 *
 * @description 사용자 작성 HTML 을 렌더하는 유일한 경로의 정화 계약을 고정한다.
 * 정화 라이브러리(DOMPurify) 버전을 올릴 때 차단 강도가 약해지거나 정상 마크업이
 * 함께 사라지는 것을 막는다. 이 컴포넌트는 게시글·페이지 본문을 그대로 innerHTML
 * 로 넣으므로, 여기서 새는 것이 곧 저장형 XSS 다.
 *
 * @effects script_tag_blocked, event_handler_attr_blocked, javascript_url_blocked,
 *          embedded_frame_blocked, safe_markup_preserved, plain_text_mode_escapes_html
 */
// @scenario content_kind=script_injection, mode=html
// @scenario content_kind=event_handler, mode=html
// @scenario content_kind=javascript_url, mode=html
// @scenario content_kind=safe_markup, mode=html
// @scenario content_kind=script_injection, mode=text

import React from 'react';
import { render } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import { HtmlContent } from '../HtmlContent';

/** 렌더 후 정화된 HTML 문자열을 돌려준다. */
function sanitizedHtmlOf(content: string, props: Record<string, unknown> = {}): string {
    const { container } = render(<HtmlContent content={content} {...props} />);

    return container.innerHTML;
}

describe('HtmlContent 정화', () => {
    describe('스크립트 실행 경로 차단', () => {
        it('script 태그를 제거한다', () => {
            const html = sanitizedHtmlOf('<p>본문</p><script>window.__xss = 1;</script>');

            expect(html).not.toContain('<script');
            expect(html).not.toContain('window.__xss');
            expect(html).toContain('본문');
        });

        it('이미지 onerror 핸들러를 제거한다', () => {
            const html = sanitizedHtmlOf('<img src="x" onerror="window.__xss = 1">');

            expect(html.toLowerCase()).not.toContain('onerror');
            expect(html).not.toContain('window.__xss');
        });

        it('onclick / onload / onmouseover 핸들러를 제거한다', () => {
            const html = sanitizedHtmlOf(
                '<div onclick="a()" onmouseover="b()"><span onload="c()">텍스트</span></div>'
            );

            expect(html.toLowerCase()).not.toContain('onclick');
            expect(html.toLowerCase()).not.toContain('onmouseover');
            expect(html.toLowerCase()).not.toContain('onload');
            expect(html).toContain('텍스트');
        });

        it('javascript: 스킴 링크를 제거한다', () => {
            const html = sanitizedHtmlOf('<a href="javascript:window.__xss=1">클릭</a>');

            expect(html.toLowerCase()).not.toContain('javascript:');
            expect(html).toContain('클릭');
        });

        it('svg 안의 스크립트 벡터를 제거한다', () => {
            const html = sanitizedHtmlOf('<svg><script>window.__xss = 1;</script></svg>');

            expect(html.toLowerCase()).not.toContain('<svg');
            expect(html).not.toContain('window.__xss');
        });
    });

    describe('외부 콘텐츠·폼 삽입 차단', () => {
        it('iframe / object / embed 를 제거한다', () => {
            const html = sanitizedHtmlOf(
                '<iframe src="https://evil.example"></iframe>' +
                    '<object data="x"></object><embed src="x">'
            );

            expect(html.toLowerCase()).not.toContain('<iframe');
            expect(html.toLowerCase()).not.toContain('<object');
            expect(html.toLowerCase()).not.toContain('<embed');
        });

        it('form / input 등 피싱 요소를 제거한다', () => {
            const html = sanitizedHtmlOf(
                '<form action="https://evil.example"><input name="pw" type="password"></form>'
            );

            expect(html.toLowerCase()).not.toContain('<form');
            expect(html.toLowerCase()).not.toContain('<input');
        });

        it('style / link / meta / base 태그를 제거한다', () => {
            const html = sanitizedHtmlOf(
                '<style>body{display:none}</style><link rel="stylesheet" href="x">' +
                    '<meta http-equiv="refresh" content="0"><base href="https://evil.example/">'
            );

            expect(html.toLowerCase()).not.toContain('<style');
            expect(html.toLowerCase()).not.toContain('<link');
            expect(html.toLowerCase()).not.toContain('<meta');
            expect(html.toLowerCase()).not.toContain('<base');
        });
    });

    describe('정상 마크업 보존', () => {
        it('본문 서식 태그를 그대로 남긴다', () => {
            const html = sanitizedHtmlOf(
                '<h2>제목</h2><p><strong>굵게</strong> 그리고 <em>기울임</em></p>' +
                    '<ul><li>항목</li></ul><blockquote>인용</blockquote>'
            );

            expect(html).toContain('<h2>제목</h2>');
            expect(html).toContain('<strong>굵게</strong>');
            expect(html).toContain('<em>기울임</em>');
            expect(html).toContain('<li>항목</li>');
            expect(html).toContain('<blockquote>인용</blockquote>');
        });

        it('이미지와 표를 보존한다', () => {
            const html = sanitizedHtmlOf(
                '<img src="/uploads/a.png" alt="사진">' +
                    '<table><tbody><tr><td>칸</td></tr></tbody></table>'
            );

            expect(html).toContain('/uploads/a.png');
            expect(html).toContain('alt="사진"');
            expect(html).toContain('<td>칸</td>');
        });

        it('http(s) 링크에 rel="noopener noreferrer" 를 붙인다', () => {
            const html = sanitizedHtmlOf('<a href="https://example.com" target="_blank">링크</a>');

            expect(html).toContain('href="https://example.com"');
            expect(html).toContain('rel="noopener noreferrer"');
        });
    });

    describe('평문 모드', () => {
        it('isHtml=false 이면 태그를 해석하지 않고 문자로 출력한다', () => {
            const { container } = render(
                <HtmlContent content={'<script>window.__xss=1;</script>줄1\n줄2'} isHtml={false} />
            );

            expect(container.querySelector('script')).toBeNull();
            expect(container.textContent).toContain('<script>');
            expect(container.textContent).toContain('줄2');
        });
    });

    describe('purifyConfig 오버라이드', () => {
        it('사용자 설정이 보안 기본 차단 목록을 덮어쓰지 못한다', () => {
            const html = sanitizedHtmlOf('<p>본문</p><script>window.__xss=1;</script>', {
                purifyConfig: { FORBID_TAGS: [], FORBID_ATTR: [] },
            });

            expect(html).not.toContain('<script');
            expect(html).toContain('본문');
        });
    });
});
