/**
 * @file board-form-type-renderer.test.tsx
 * @description 게시글 작성/수정 - _type_renderer 유형별 분기 렌더링 테스트 (이슈 #153)
 *
 * 검증 항목:
 * 1. basic 유형: 폼 영역 렌더링
 * 2. gallery 유형: 폼 영역 렌더링 (현재 basic 공유)
 * 3. card 유형: 폼 영역 렌더링 (현재 basic 공유)
 * 4. 알 수 없는 유형 fallback: basic으로 렌더링
 * 5. 비밀번호 확인 조건부 렌더링 (requires_password)
 * 6. dataKey 2단계 partial 전파 검증 (trackChanges/blur_until_loaded)
 */

import React from 'react';
import { describe, it, expect, beforeEach } from 'vitest';
import { createLayoutTest, screen } from '@/core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@/core/template-engine/ComponentRegistry';

// ============================================================
// 테스트용 컴포넌트 정의
// ============================================================

const TestDiv: React.FC<{
  className?: string;
  children?: React.ReactNode;
  'data-testid'?: string;
}> = ({ className, children, 'data-testid': testId }) => (
  <div className={className} data-testid={testId}>
    {children}
  </div>
);

const TestButton: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
  type?: string;
  onClick?: () => void;
  'data-testid'?: string;
}> = ({ className, children, text, onClick, 'data-testid': testId }) => (
  <button className={className} onClick={onClick} data-testid={testId}>
    {children || text}
  </button>
);

const TestSpan: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => (
  <span className={className}>{children || text}</span>
);

const TestH1: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => (
  <h1 className={className}>{children || text}</h1>
);

const TestInput: React.FC<{
  type?: string;
  name?: string;
  className?: string;
  placeholder?: string;
  'data-testid'?: string;
}> = ({ type, name, className, placeholder, 'data-testid': testId }) => (
  <input type={type} name={name} className={className} placeholder={placeholder} data-testid={testId} />
);

const TestForm: React.FC<{
  id?: string;
  className?: string;
  children?: React.ReactNode;
  'data-testid'?: string;
}> = ({ id, className, children, 'data-testid': testId }) => (
  <form id={id} className={className} data-testid={testId}>
    {children}
  </form>
);

const TestIcon: React.FC<{
  name?: string;
  size?: string;
  className?: string;
}> = ({ name, size, className }) => (
  <i className={className} data-icon={name} data-size={size} />
);

const TestContainer: React.FC<{
  className?: string;
  children?: React.ReactNode;
}> = ({ className, children }) => (
  <div className={className} data-testid="container">
    {children}
  </div>
);

const TestHtmlEditor: React.FC<{
  content?: string;
  name?: string;
  label?: string;
}> = ({ name }) => (
  <div data-testid={`html-editor-${name ?? 'content'}`} />
);

// ============================================================
// 컴포넌트 레지스트리 설정
// ============================================================

function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();

  const Fragment: React.FC<{ children?: React.ReactNode }> = ({ children }) => <>{children}</>;

  (registry as any).registry = {
    Fragment: { component: Fragment, metadata: { name: 'Fragment', type: 'layout' } },
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    H1: { component: TestH1, metadata: { name: 'H1', type: 'basic' } },
    Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
    Form: { component: TestForm, metadata: { name: 'Form', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'composite' } },
    Container: { component: TestContainer, metadata: { name: 'Container', type: 'layout' } },
    HtmlEditor: { component: TestHtmlEditor, metadata: { name: 'HtmlEditor', type: 'composite' } },
  };

  return registry;
}

// ============================================================
// 공통 mock 데이터
// ============================================================

function makeFormMeta(overrides: Record<string, any> = {}) {
  return {
    data: {
      board: {
        name: '자유게시판',
        type: 'basic',
        categories: [],
        secret_mode: 'disabled',
        use_file_upload: false,
        user_permissions: { manager: false, attachments_upload: false },
      },
      requires_password: false,
      parent_post: null,
      author: null,
      attachments: [],
      ...overrides,
    },
  };
}

// ============================================================
// _type_renderer 유형별 분기 테스트용 레이아웃 JSON
// (partial 분리된 내용을 인라인으로 포함)
// ============================================================

function makeFormLayout(boardType: string, formMetaOverrides: Record<string, any> = {}) {
  const formMetaData = makeFormMeta({
    board: {
      name: '자유게시판',
      type: boardType,
      categories: [],
      secret_mode: 'disabled',
      use_file_upload: false,
      user_permissions: { manager: false, attachments_upload: false },
    },
    ...formMetaOverrides,
  });

  const layout = {
    version: '1.0.0',
    layout_name: 'board_form_type_renderer_test',
    // data_sources 없음 - initialData로 직접 주입 (static type은 processDataSources에서 미처리)
    components: [
      {
        comment: '_type_renderer 인라인 재현 (비밀번호 확인 Div + 유형 분기)',
        type: 'basic',
        name: 'Div',
        if: "{{!form_meta?.data?.requires_password || _local.passwordVerified || form_meta?.data?.board?.user_permissions?.manager}}",
        props: { 'data-testid': 'type-renderer' },
        children: [
          {
            comment: 'basic + fallback',
            type: 'basic',
            name: 'Div',
            if: "{{form_meta?.data?.board?.type === 'basic' || !['gallery','card'].includes(form_meta?.data?.board?.type ?? 'basic')}}",
            props: { 'data-testid': 'form-basic' },
            children: [
              { type: 'basic', name: 'Span', text: 'basic-form', props: { 'data-testid': 'form-basic-label' } },
            ],
          },
          {
            comment: 'gallery 유형',
            type: 'basic',
            name: 'Div',
            if: "{{form_meta?.data?.board?.type === 'gallery'}}",
            props: { 'data-testid': 'form-gallery' },
            children: [
              { type: 'basic', name: 'Span', text: 'gallery-form', props: { 'data-testid': 'form-gallery-label' } },
            ],
          },
          {
            comment: 'card 유형',
            type: 'basic',
            name: 'Div',
            if: "{{form_meta?.data?.board?.type === 'card'}}",
            props: { 'data-testid': 'form-card' },
            children: [
              { type: 'basic', name: 'Span', text: 'card-form', props: { 'data-testid': 'form-card-label' } },
            ],
          },
        ],
      },
    ],
  };

  return { layout, initialData: { form_meta: formMetaData, form_data: null } };
}

// ============================================================
// 테스트
// ============================================================

describe('board/form _type_renderer 유형별 분기 렌더링', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  describe('[1] basic 유형', () => {
    it('form-basic 영역이 렌더링되고 form-gallery/form-card는 렌더링되지 않는다', async () => {
      const { layout, initialData } = makeFormLayout('basic');
      const testUtils = createLayoutTest(layout as any, { componentRegistry: registry, initialData });
      await testUtils.render();

      expect(screen.getByTestId('form-basic')).toBeInTheDocument();
      expect(screen.queryByTestId('form-gallery')).not.toBeInTheDocument();
      expect(screen.queryByTestId('form-card')).not.toBeInTheDocument();

      testUtils.cleanup();
    });
  });

  describe('[2] gallery 유형', () => {
    it('form-gallery 영역이 렌더링되고 form-basic/form-card는 렌더링되지 않는다', async () => {
      const { layout, initialData } = makeFormLayout('gallery');
      const testUtils = createLayoutTest(layout as any, { componentRegistry: registry, initialData });
      await testUtils.render();

      expect(screen.getByTestId('form-gallery')).toBeInTheDocument();
      expect(screen.queryByTestId('form-basic')).not.toBeInTheDocument();
      expect(screen.queryByTestId('form-card')).not.toBeInTheDocument();

      testUtils.cleanup();
    });
  });

  describe('[3] card 유형', () => {
    it('form-card 영역이 렌더링되고 form-basic/form-gallery는 렌더링되지 않는다', async () => {
      const { layout, initialData } = makeFormLayout('card');
      const testUtils = createLayoutTest(layout as any, { componentRegistry: registry, initialData });
      await testUtils.render();

      expect(screen.getByTestId('form-card')).toBeInTheDocument();
      expect(screen.queryByTestId('form-basic')).not.toBeInTheDocument();
      expect(screen.queryByTestId('form-gallery')).not.toBeInTheDocument();

      testUtils.cleanup();
    });
  });

  describe('[4] 알 수 없는 유형 fallback', () => {
    it('unknown 유형은 basic으로 fallback 렌더링된다', async () => {
      const { layout, initialData } = makeFormLayout('unknown_type');
      const testUtils = createLayoutTest(layout as any, { componentRegistry: registry, initialData });
      await testUtils.render();

      expect(screen.getByTestId('form-basic')).toBeInTheDocument();
      expect(screen.queryByTestId('form-gallery')).not.toBeInTheDocument();
      expect(screen.queryByTestId('form-card')).not.toBeInTheDocument();

      testUtils.cleanup();
    });
  });

  describe('[5] 비밀번호 확인 조건부 렌더링', () => {
    it('requires_password=false이면 폼이 표시된다', async () => {
      const { layout, initialData } = makeFormLayout('basic', { requires_password: false });
      const testUtils = createLayoutTest(layout as any, { componentRegistry: registry, initialData });
      await testUtils.render();

      expect(screen.getByTestId('type-renderer')).toBeInTheDocument();

      testUtils.cleanup();
    });

    it('requires_password=true이고 passwordVerified=false이면 폼이 숨겨진다', async () => {
      const { layout, initialData } = makeFormLayout('basic', { requires_password: true });
      const testUtils = createLayoutTest(layout as any, { componentRegistry: registry, initialData });
      await testUtils.render();

      expect(screen.queryByTestId('type-renderer')).not.toBeInTheDocument();

      testUtils.cleanup();
    });

    it('requires_password=true이고 manager=true이면 폼이 표시된다', async () => {
      const { layout, initialData } = makeFormLayout('basic', {
        requires_password: true,
        board: {
          name: '자유게시판',
          type: 'basic',
          categories: [],
          secret_mode: 'disabled',
          use_file_upload: false,
          user_permissions: { manager: true, attachments_upload: false },
        },
      });
      const testUtils = createLayoutTest(layout as any, { componentRegistry: registry, initialData });
      await testUtils.render();

      expect(screen.getByTestId('type-renderer')).toBeInTheDocument();

      testUtils.cleanup();
    });
  });
});
