/**
 * @file html-content.test.tsx
 * @description sirsoft-ckeditor5 html_content extension_point 렌더링 테스트
 *
 * 테스트 환경 특성:
 * - extension_point(mode: append)는 백엔드가 children에 컴포넌트를 추가
 * - HtmlContent 컴포넌트 동작(HTML/텍스트 렌더링)은 type: "composite"로 직접 배치하여 검증
 * - extension_point 구조 검증은 children을 직접 배치하여 시뮬레이션
 */

import React from 'react';
import { describe, it, expect } from 'vitest';
import {
  createLayoutTest,
  screen,
  createMockComponentRegistry,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';

function buildRegistry() {
  const registry = createMockComponentRegistry();

  registry.register('layout', 'Fragment', ({ children }: any) =>
    React.createElement(React.Fragment, null, children)
  );
  registry.register('basic', 'Div', ({ children, className, id, ...rest }: any) =>
    React.createElement('div', { className, id, ...rest }, children)
  );
  registry.register('composite', 'HtmlContent', ({ content, isHtml, className }: any) => {
    if (!content || content.trim() === '') return null;

    if (!isHtml) {
      return React.createElement('div', {
        'data-testid': 'html-content-text',
        className,
      }, content);
    }

    return React.createElement('div', {
      'data-testid': 'html-content-html',
      className,
      dangerouslySetInnerHTML: { __html: content },
    });
  });

  return registry;
}

// HtmlContent 직접 배치 — API 데이터 바인딩 검증
const htmlContentApiLayout = {
  version: '1.0.0',
  layout_name: 'test_html_content_api',
  data_sources: [
    {
      id: 'post',
      type: 'api',
      endpoint: '/api/admin/boards/notice/posts/1',
      method: 'GET',
      auto_fetch: true,
    },
  ],
  components: [
    {
      id: 'post_content_wrapper',
      type: 'basic',
      name: 'Div',
      props: { 'data-testid': 'post-content-wrapper' },
      children: [
        {
          id: 'html_content_direct',
          type: 'composite',
          name: 'HtmlContent',
          props: {
            content: '{{post?.data?.content ?? ""}}',
            isHtml: '{{(post?.data?.content_mode ?? "text") === "html"}}',
            className: 'prose dark:prose-invert max-w-none text-gray-900 dark:text-gray-100',
          },
        },
      ],
    },
  ],
};

// extension_point(mode: append) — 플러그인 설치 시 시뮬레이션
// 백엔드가 원본 HtmlContent + CSS 주입 컴포넌트를 children에 추가
const htmlContentWithPluginLayout = {
  version: '1.0.0',
  layout_name: 'test_html_content_with_plugin',
  data_sources: [
    {
      id: 'post',
      type: 'api',
      endpoint: '/api/admin/boards/notice/posts/1',
      method: 'GET',
      auto_fetch: true,
    },
  ],
  components: [
    {
      id: 'normal_content',
      type: 'extension_point',
      name: 'html_content',
      props: {
        content: '{{post?.data?.content ?? ""}}',
        isHtml: '{{(post?.data?.content_mode ?? "text") === "html"}}',
        className: 'prose dark:prose-invert max-w-none text-gray-900 dark:text-gray-100',
      },
      // 백엔드 주입: 원본 HtmlContent + CSS 주입 컴포넌트
      children: [
        {
          type: 'composite',
          name: 'HtmlContent',
          props: {
            content: '{{post?.data?.content ?? ""}}',
            isHtml: '{{(post?.data?.content_mode ?? "text") === "html"}}',
            className: 'prose dark:prose-invert max-w-none text-gray-900 dark:text-gray-100',
          },
        },
        {
          id: 'ckeditor5_content_css_injector',
          type: 'basic',
          name: 'Div',
          props: { className: 'hidden', 'data-testid': 'css-injector' },
        },
      ],
    },
  ],
};

// 댓글 콘텐츠 — initialData 바인딩 검증
const replyContentLayout = {
  version: '1.0.0',
  layout_name: 'test_reply_content',
  components: [
    {
      id: 'reply_html_content',
      type: 'composite',
      name: 'HtmlContent',
      props: {
        content: '{{item?.content ?? ""}}',
        isHtml: '{{(item?.content_mode ?? "text") === "html"}}',
        className: 'prose dark:prose-invert max-w-none text-gray-900 dark:text-gray-100',
      },
    },
  ],
};

describe('HtmlContent 컴포넌트 — HTML/텍스트 렌더링 (API 바인딩)', () => {
  it('isHtml=true 시 HTML 콘텐츠가 렌더링된다', async () => {
    const testUtils = createLayoutTest(htmlContentApiLayout, {
      componentRegistry: buildRegistry(),
    });
    testUtils.mockApi('post', {
      response: {
        success: true,
        data: {
          id: 1,
          content: '<p>HTML <strong>게시글</strong> 내용입니다.</p>',
          content_mode: 'html',
        },
      },
    });
    await testUtils.render();

    const htmlContent = screen.getByTestId('html-content-html');
    expect(htmlContent).toBeInTheDocument();
    expect(htmlContent.innerHTML).toContain('<strong>게시글</strong>');
    testUtils.cleanup();
  });

  it('prose 클래스가 적용된다', async () => {
    const testUtils = createLayoutTest(htmlContentApiLayout, {
      componentRegistry: buildRegistry(),
    });
    testUtils.mockApi('post', {
      response: {
        success: true,
        data: { id: 1, content: '<p>내용</p>', content_mode: 'html' },
      },
    });
    await testUtils.render();

    const htmlContent = screen.getByTestId('html-content-html');
    expect(htmlContent.className).toContain('prose');
    testUtils.cleanup();
  });

  it('isHtml=false 시 일반 텍스트로 렌더링된다', async () => {
    const testUtils = createLayoutTest(htmlContentApiLayout, {
      componentRegistry: buildRegistry(),
    });
    testUtils.mockApi('post', {
      response: {
        success: true,
        data: { id: 1, content: '일반 텍스트 내용입니다.', content_mode: 'text' },
      },
    });
    await testUtils.render();

    const textContent = screen.getByTestId('html-content-text');
    expect(textContent).toBeInTheDocument();
    expect(textContent.textContent).toBe('일반 텍스트 내용입니다.');
    testUtils.cleanup();
  });

  it('content가 빈 문자열이면 렌더링되지 않는다', async () => {
    const testUtils = createLayoutTest(htmlContentApiLayout, {
      componentRegistry: buildRegistry(),
    });
    testUtils.mockApi('post', {
      response: {
        success: true,
        data: { id: 1, content: '', content_mode: 'html' },
      },
    });
    await testUtils.render();

    expect(screen.queryByTestId('html-content-html')).not.toBeInTheDocument();
    expect(screen.queryByTestId('html-content-text')).not.toBeInTheDocument();
    testUtils.cleanup();
  });

  it('래퍼 div가 렌더링된다', async () => {
    const testUtils = createLayoutTest(htmlContentApiLayout, {
      componentRegistry: buildRegistry(),
    });
    testUtils.mockApi('post', {
      response: {
        success: true,
        data: { id: 1, content: '내용', content_mode: 'text' },
      },
    });
    await testUtils.render();

    expect(screen.getByTestId('post-content-wrapper')).toBeInTheDocument();
    testUtils.cleanup();
  });
});

describe('html_content extension_point — 플러그인 설치 시 (CSS 주입 + HtmlContent)', () => {
  it('extension_point 컨테이너 id가 적용된다', async () => {
    const testUtils = createLayoutTest(htmlContentWithPluginLayout, {
      componentRegistry: buildRegistry(),
    });
    testUtils.mockApi('post', {
      response: {
        success: true,
        data: { id: 1, content: '<p>내용</p>', content_mode: 'html' },
      },
    });
    await testUtils.render();

    const container = document.getElementById('normal_content');
    expect(container).toBeInTheDocument();
    testUtils.cleanup();
  });

  it('HtmlContent와 CSS 주입 컴포넌트가 함께 렌더링된다', async () => {
    const testUtils = createLayoutTest(htmlContentWithPluginLayout, {
      componentRegistry: buildRegistry(),
    });
    testUtils.mockApi('post', {
      response: {
        success: true,
        data: { id: 1, content: '<p>HTML 내용</p>', content_mode: 'html' },
      },
    });
    await testUtils.render();

    expect(screen.getByTestId('html-content-html')).toBeInTheDocument();
    expect(screen.getByTestId('css-injector')).toBeInTheDocument();
    testUtils.cleanup();
  });
});

describe('html_content — 댓글 콘텐츠 패턴 (_reply_card_content.json)', () => {
  it('댓글 HTML 콘텐츠가 렌더링된다', async () => {
    const testUtils = createLayoutTest(replyContentLayout, {
      componentRegistry: buildRegistry(),
      initialData: {
        item: {
          id: 10,
          content: '<p>댓글 <em>내용</em>입니다.</p>',
          content_mode: 'html',
        },
      },
    });
    await testUtils.render();

    const htmlContent = screen.getByTestId('html-content-html');
    expect(htmlContent).toBeInTheDocument();
    expect(htmlContent.innerHTML).toContain('<em>내용</em>');
    testUtils.cleanup();
  });

  it('댓글 텍스트 콘텐츠가 렌더링된다', async () => {
    const testUtils = createLayoutTest(replyContentLayout, {
      componentRegistry: buildRegistry(),
      initialData: {
        item: {
          id: 10,
          content: '일반 댓글 내용',
          content_mode: 'text',
        },
      },
    });
    await testUtils.render();

    const textContent = screen.getByTestId('html-content-text');
    expect(textContent).toBeInTheDocument();
    expect(textContent.textContent).toBe('일반 댓글 내용');
    testUtils.cleanup();
  });
});
