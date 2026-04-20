/**
 * @file html-editor.test.tsx
 * @description sirsoft-ckeditor5 html_editor extension_point 렌더링 테스트
 *
 * 테스트 환경 특성:
 * - extension_point는 백엔드 주입 없이 children을 렌더링
 * - 플러그인 미설치 = children 없음 → 빈 컨테이너
 * - 플러그인 설치 = 백엔드가 html-editor.json 컴포넌트를 children에 주입
 * - HtmlEditor 컴포넌트 동작은 type: "composite"로 직접 배치하여 검증
 */

import React from 'react';
import { describe, it, expect } from 'vitest';
import {
  createLayoutTest,
  screen,
  createMockComponentRegistry,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';

function buildRegistry(extra?: Record<string, React.FC<any>>) {
  const registry = createMockComponentRegistry();

  registry.register('layout', 'Fragment', ({ children }: any) =>
    React.createElement(React.Fragment, null, children)
  );
  registry.register('basic', 'Div', ({ children, className, id, ...rest }: any) =>
    React.createElement('div', { className, id, ...rest }, children)
  );
  registry.register('composite', 'HtmlEditor', ({ name, content, placeholder }: any) =>
    React.createElement('div', { 'data-testid': 'html-editor', 'data-name': name },
      React.createElement('textarea', {
        'data-testid': 'html-editor-textarea',
        defaultValue: content ?? '',
        placeholder,
      })
    )
  );

  if (extra) {
    for (const [name, component] of Object.entries(extra)) {
      registry.register('basic', name, component);
    }
  }

  return registry;
}

// 플러그인 미설치: extension_point children 없음
const htmlEditorNoPluginLayout = {
  version: '1.0.0',
  layout_name: 'test_html_editor_no_plugin',
  components: [
    {
      id: 'form_wrapper',
      type: 'basic',
      name: 'Div',
      props: { 'data-testid': 'form-wrapper' },
      children: [
        {
          id: 'content_editor',
          type: 'extension_point',
          name: 'html_editor',
          props: {
            name: 'content',
            content: '{{_local.form?.content ?? ""}}',
            isHtml: '{{(_local.form?.content_mode ?? "text") === "html"}}',
            placeholder: '내용을 입력하세요',
            rows: 15,
            showHtmlModeToggle: true,
          },
          // children 없음 = 플러그인 미설치
        },
      ],
    },
  ],
};

// 플러그인 설치 시: 백엔드가 html-editor.json 컴포넌트를 children에 주입
const htmlEditorWithPluginLayout = {
  version: '1.0.0',
  layout_name: 'test_html_editor_with_plugin',
  components: [
    {
      id: 'form_wrapper',
      type: 'basic',
      name: 'Div',
      props: { 'data-testid': 'form-wrapper' },
      children: [
        {
          id: 'content_editor',
          type: 'extension_point',
          name: 'html_editor',
          props: { name: 'content', content: '' },
          children: [
            {
              id: 'ckeditor5_container',
              type: 'basic',
              name: 'Div',
              props: {
                id: 'ckeditor5-content',
                className: 'ckeditor5-wrapper',
                'data-testid': 'ckeditor5-container',
              },
            },
          ],
        },
      ],
    },
  ],
};

// HtmlEditor 컴포넌트 직접 배치 (폴백 동작 검증)
const htmlEditorDirectLayout = {
  version: '1.0.0',
  layout_name: 'test_html_editor_direct',
  components: [
    {
      id: 'html_editor_direct',
      type: 'composite',
      name: 'HtmlEditor',
      props: {
        name: 'content',
        content: '{{_local.form?.content ?? ""}}',
        isHtml: '{{(_local.form?.content_mode ?? "text") === "html"}}',
        placeholder: '내용을 입력하세요',
        rows: 15,
        showHtmlModeToggle: true,
      },
    },
  ],
};

describe('html_editor extension_point — 플러그인 미설치', () => {
  it('extension_point 컨테이너 div가 렌더링된다', async () => {
    const testUtils = createLayoutTest(htmlEditorNoPluginLayout, {
      componentRegistry: buildRegistry(),
    });
    await testUtils.render();

    const container = document.getElementById('content_editor');
    expect(container).toBeInTheDocument();
    testUtils.cleanup();
  });

  it('플러그인 미설치 시 내부가 비어있다', async () => {
    const testUtils = createLayoutTest(htmlEditorNoPluginLayout, {
      componentRegistry: buildRegistry(),
    });
    await testUtils.render();

    expect(screen.queryByTestId('html-editor')).not.toBeInTheDocument();
    testUtils.cleanup();
  });

  it('form_wrapper가 렌더링된다', async () => {
    const testUtils = createLayoutTest(htmlEditorNoPluginLayout, {
      componentRegistry: buildRegistry(),
    });
    await testUtils.render();

    expect(screen.getByTestId('form-wrapper')).toBeInTheDocument();
    testUtils.cleanup();
  });
});

describe('html_editor extension_point — 플러그인 설치 (백엔드 주입)', () => {
  it('백엔드 주입된 CKEditor5 컨테이너가 렌더링된다', async () => {
    const testUtils = createLayoutTest(htmlEditorWithPluginLayout, {
      componentRegistry: buildRegistry(),
    });
    await testUtils.render();

    expect(screen.getByTestId('ckeditor5-container')).toBeInTheDocument();
    testUtils.cleanup();
  });

  it('extension_point id가 컨테이너 div에 적용된다', async () => {
    const testUtils = createLayoutTest(htmlEditorWithPluginLayout, {
      componentRegistry: buildRegistry(),
    });
    await testUtils.render();

    const container = document.getElementById('content_editor');
    expect(container).toBeInTheDocument();
    testUtils.cleanup();
  });
});

describe('html_editor extension_point — HtmlEditor 폴백 직접 렌더링', () => {
  it('HtmlEditor 컴포넌트가 렌더링된다', async () => {
    const testUtils = createLayoutTest(htmlEditorDirectLayout, {
      componentRegistry: buildRegistry(),
    });
    await testUtils.render();

    expect(screen.getByTestId('html-editor')).toBeInTheDocument();
    expect(screen.getByTestId('html-editor-textarea')).toBeInTheDocument();
    testUtils.cleanup();
  });

  it('content가 비어있으면 textarea가 비어있다', async () => {
    const testUtils = createLayoutTest(htmlEditorDirectLayout, {
      componentRegistry: buildRegistry(),
    });
    await testUtils.render();

    const textarea = screen.getByTestId('html-editor-textarea') as HTMLTextAreaElement;
    expect(textarea.value).toBe('');
    testUtils.cleanup();
  });

  it('initialState의 form.content가 textarea에 반영된다', async () => {
    const testUtils = createLayoutTest(htmlEditorDirectLayout, {
      componentRegistry: buildRegistry(),
      initialState: {
        _local: { form: { content: '초기 내용입니다.', content_mode: 'text' } },
      },
    });
    await testUtils.render();

    const textarea = screen.getByTestId('html-editor-textarea') as HTMLTextAreaElement;
    expect(textarea.value).toBe('초기 내용입니다.');
    testUtils.cleanup();
  });
});
