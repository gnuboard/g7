/**
 * @file shop-product-qna.test.tsx
 * @description 상품 상세 QnA 탭 렌더링 테스트 (Issue #208 Phase 3)
 *
 * 테스트 케이스:
 * - QnA API 응답 구조 (items + meta.board_settings)
 * - 문의 목록 테이블 형태 렌더링 (Q행 + A행)
 * - 비밀글 처리 (is_owner 기반)
 * - 빈 목록 표시
 * - 문의 작성 버튼: 로그인 사용자만 표시 (비회원은 로그인 유도 버튼)
 * - board_slug 미설정 시 탭 비노출
 * - 비밀글 제외 체크박스
 * - 문의 작성 모달 검증 오류 UI (빨간 테두리 + 오류 텍스트)
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect, beforeEach } from 'vitest';
import {
  createLayoutTest,
  screen,
  waitFor,
} from '@/core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@/core/template-engine/ComponentRegistry';

// ========== 테스트용 컴포넌트 ==========

const TestDiv: React.FC<{
  className?: string;
  children?: React.ReactNode;
  'data-testid'?: string;
}> = ({ className, children, 'data-testid': testId }) => (
  <div className={className} data-testid={testId}>{children}</div>
);

const TestSpan: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
  'data-testid'?: string;
}> = ({ className, children, text, 'data-testid': testId }) => (
  <span className={className} data-testid={testId}>{children ?? text}</span>
);

const TestP: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
  'data-testid'?: string;
}> = ({ className, children, text, 'data-testid': testId }) => (
  <p className={className} data-testid={testId}>{children ?? text}</p>
);

const TestButton: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
  type?: string;
  disabled?: boolean;
  onClick?: () => void;
  'data-testid'?: string;
}> = ({ className, children, text, type, disabled, onClick, 'data-testid': testId }) => (
  <button className={className} type={(type as any) ?? 'button'} disabled={disabled} onClick={onClick} data-testid={testId}>
    {children ?? text}
  </button>
);

const TestIcon: React.FC<{
  name?: string;
  size?: string;
  className?: string;
  'data-testid'?: string;
}> = ({ name, 'data-testid': testId }) => (
  <i data-testid={testId ?? `icon-${name}`} data-icon={name} />
);

const TestInput: React.FC<{
  type?: string;
  className?: string;
  checked?: boolean;
  disabled?: boolean;
  onChange?: (e: React.ChangeEvent<HTMLInputElement>) => void;
  'data-testid'?: string;
}> = ({ type, className, checked, disabled, onChange, 'data-testid': testId }) => (
  <input type={type} className={className} checked={checked} disabled={disabled} onChange={onChange} data-testid={testId} />
);

const TestLabel: React.FC<{
  className?: string;
  children?: React.ReactNode;
  htmlFor?: string;
  'data-testid'?: string;
}> = ({ className, children, htmlFor, 'data-testid': testId }) => (
  <label className={className} htmlFor={htmlFor} data-testid={testId}>{children}</label>
);

const TestFragment: React.FC<{ children?: React.ReactNode }> =
  ({ children }) => <>{children}</>;

// ========== 레지스트리 설정 ==========

function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
    Label: { component: TestLabel, metadata: { name: 'Label', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };
  return registry;
}

// ========== 공통 Fixture ==========

const makeQnaTabFixture = () => ({
  version: '1.0.0',
  layout_name: 'shop/show',
  data_sources: [
    {
      id: 'product',
      type: 'api',
      endpoint: '/api/modules/sirsoft-ecommerce/products/42',
      method: 'GET',
      auto_fetch: true,
      loading_strategy: 'blocking',
    },
    {
      id: 'qna',
      type: 'api',
      endpoint: '/api/modules/sirsoft-ecommerce/products/42/inquiries',
      method: 'GET',
      auto_fetch: true,
      auth_mode: 'optional',
      loading_strategy: 'progressive',
      fallback: {
        items: [],
        meta: {
          board_settings: { secret_mode: 'disabled' },
          current_page: 1,
          per_page: 10,
          total: 0,
          last_page: 1,
        },
      },
    },
  ],
  slots: {
    content: [
      {
        type: 'basic',
        name: 'Div',
        if: `{{(_local.activeTab ?? 'info') === 'qna' && !!_global.modules?.['sirsoft-ecommerce']?.inquiry?.board_slug}}`,
        props: { 'data-testid': 'qna-tab' },
        children: [
          {
            comment: '비밀글 제외 체크박스',
            type: 'basic',
            name: 'Input',
            props: {
              type: 'checkbox',
              'data-testid': 'exclude-secret-checkbox',
              checked: '{{_local.qnaExcludeSecret ?? false}}',
            },
          },
          {
            comment: '문의 작성 버튼 (로그인 여부에 따라 모달 열기 또는 토스트 안내)',
            type: 'basic',
            name: 'Button',
            props: { 'data-testid': 'write-qna-btn', type: 'button' },
            text: '문의하기',
          },
          {
            comment: '문의 목록 (Q행 + A행)',
            type: 'basic',
            name: 'Div',
            if: '{{qna.data.items && qna.data.items.length > 0}}',
            props: { 'data-testid': 'qna-list' },
            children: [
              {
                type: 'basic',
                name: 'Div',
                iteration: { source: '{{qna.data.items ?? []}}', item_var: 'item', index_var: 'idx' },
                children: [
                  {
                    comment: 'Q행',
                    type: 'basic',
                    name: 'Div',
                    props: { 'data-testid': 'qna-item-{{idx}}' },
                    children: [
                      {
                        type: 'basic',
                        name: 'Span',
                        props: { 'data-testid': 'qna-content-{{idx}}' },
                        text: `{{item.is_secret && !item.is_owner ? '비밀글입니다.' : (item.title ?? item.content ?? '')}}`,
                      },
                      {
                        type: 'basic',
                        name: 'Span',
                        props: { 'data-testid': 'qna-status-{{idx}}' },
                        text: `{{item.is_answered ? '답변완료' : '답변대기'}}`,
                      },
                    ],
                  },
                  {
                    comment: 'A행 (답변 있고, 비밀글이면 본인만 표시)',
                    type: 'basic',
                    name: 'Div',
                    if: '{{!!item.reply && (!item.is_secret || item.is_owner)}}',
                    props: { 'data-testid': 'qna-reply-{{idx}}' },
                    children: [
                      {
                        type: 'basic',
                        name: 'Span',
                        props: { 'data-testid': 'qna-reply-content-{{idx}}' },
                        text: '{{item.reply.content ?? ""}}',
                      },
                    ],
                  },
                ],
              },
            ],
          },
          {
            comment: '빈 목록',
            type: 'basic',
            name: 'P',
            if: '{{!qna.data.items || qna.data.items.length === 0}}',
            props: { 'data-testid': 'qna-empty' },
            text: '아직 문의가 없습니다.',
          },
        ],
      },
    ],
  },
});

// ========== 공통 API 모킹 헬퍼 ==========

const defaultBoardSettings = {
  secret_mode: 'disabled',
  categories: [],
  use_file_upload: false,
  max_file_count: 5,
  max_file_size: 10485760,
  allowed_extensions: [],
  min_title_length: 2,
  max_title_length: 200,
  min_content_length: 10,
  max_content_length: 10000,
};

const makeQnaResponse = (
  items: object[],
  boardSettings = defaultBoardSettings
) => ({
  data: {
    items,
    meta: {
      board_settings: boardSettings,
      current_page: 1,
      per_page: 10,
      total: items.length,
      last_page: 1,
    },
  },
});

const boardSlugGlobal = {
  modules: { 'sirsoft-ecommerce': { inquiry: { board_slug: 'qna-board' } } },
};

// ========== 테스트 케이스 ==========

describe('상품 상세 QnA 탭 (Issue #208 Phase 3)', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  describe('QnA 목록 렌더링', () => {
    it('items 배열이 비어있으면 빈 목록 안내 문구가 표시된다', async () => {
      const testUtils = createLayoutTest(makeQnaTabFixture(), {
        componentRegistry: registry,
        initialState: { _local: { activeTab: 'qna' }, _global: boardSlugGlobal },
      });

      testUtils.mockApi('product', { response: { data: { id: 42, name: '테스트 상품' } } });
      testUtils.mockApi('qna', { response: makeQnaResponse([]) });

      await testUtils.render();

      await waitFor(() => {
        expect(screen.getByTestId('qna-tab')).toBeInTheDocument();
      });

      expect(screen.getByTestId('qna-empty')).toBeInTheDocument();
      expect(screen.queryByTestId('qna-list')).not.toBeInTheDocument();

      testUtils.cleanup();
    });

    it('문의 목록이 있으면 각 항목의 내용이 Q행으로 렌더링된다', async () => {
      const testUtils = createLayoutTest(makeQnaTabFixture(), {
        componentRegistry: registry,
        initialState: { _local: { activeTab: 'qna' }, _global: boardSlugGlobal },
      });

      testUtils.mockApi('product', { response: { data: { id: 42, name: '테스트 상품' } } });
      testUtils.mockApi('qna', {
        response: makeQnaResponse([
          {
            id: 1,
            user_id: 10,
            author_name: '홍길동',
            title: '배송 문의',
            content: '배송 얼마나 걸리나요?',
            is_secret: false,
            is_owner: false,
            is_answered: false,
            created_at: '2026-03-01T00:00:00Z',
            reply: null,
            attachments: [],
          },
        ]),
      });

      await testUtils.render();

      await waitFor(() => {
        expect(screen.getByTestId('qna-tab')).toBeInTheDocument();
      });

      expect(screen.queryByTestId('qna-empty')).not.toBeInTheDocument();
      expect(screen.getByTestId('qna-content-0')).toHaveTextContent('배송 문의');
      expect(screen.getByTestId('qna-status-0')).toHaveTextContent('답변대기');

      testUtils.cleanup();
    });

    it('title이 없으면 content를 폴백으로 사용한다', async () => {
      const testUtils = createLayoutTest(makeQnaTabFixture(), {
        componentRegistry: registry,
        initialState: { _local: { activeTab: 'qna' }, _global: boardSlugGlobal },
      });

      testUtils.mockApi('product', { response: { data: { id: 42, name: '테스트 상품' } } });
      testUtils.mockApi('qna', {
        response: makeQnaResponse([
          {
            id: 2,
            user_id: 10,
            author_name: '홍길동',
            title: null,
            content: '제목 없는 문의 내용',
            is_secret: false,
            is_owner: false,
            is_answered: false,
            created_at: '2026-03-01T00:00:00Z',
            reply: null,
            attachments: [],
          },
        ]),
      });

      await testUtils.render();

      await waitFor(() => {
        expect(screen.getByTestId('qna-tab')).toBeInTheDocument();
      });

      expect(screen.getByTestId('qna-content-0')).toHaveTextContent('제목 없는 문의 내용');

      testUtils.cleanup();
    });

    it('답변이 있으면 A행이 표시되고 is_answered가 답변완료로 렌더링된다', async () => {
      const testUtils = createLayoutTest(makeQnaTabFixture(), {
        componentRegistry: registry,
        initialState: { _local: { activeTab: 'qna' }, _global: boardSlugGlobal },
      });

      testUtils.mockApi('product', { response: { data: { id: 42, name: '테스트 상품' } } });
      testUtils.mockApi('qna', {
        response: makeQnaResponse([
          {
            id: 3,
            user_id: 20,
            author_name: '이순신',
            title: '사이즈 문의',
            content: '사이즈가 어떻게 되나요?',
            is_secret: false,
            is_owner: false,
            is_answered: true,
            created_at: '2026-03-02T00:00:00Z',
            reply: {
              id: 10,
              content: '사이즈는 XL까지 있습니다.',
              created_at: '2026-03-03T00:00:00Z',
            },
            attachments: [],
          },
        ]),
      });

      await testUtils.render();

      await waitFor(() => {
        expect(screen.getByTestId('qna-tab')).toBeInTheDocument();
      });

      expect(screen.getByTestId('qna-status-0')).toHaveTextContent('답변완료');
      expect(screen.getByTestId('qna-reply-0')).toBeInTheDocument();
      expect(screen.getByTestId('qna-reply-content-0')).toHaveTextContent('사이즈는 XL까지 있습니다.');

      testUtils.cleanup();
    });

    it('답변이 없으면 A행이 렌더링되지 않는다', async () => {
      const testUtils = createLayoutTest(makeQnaTabFixture(), {
        componentRegistry: registry,
        initialState: { _local: { activeTab: 'qna' }, _global: boardSlugGlobal },
      });

      testUtils.mockApi('product', { response: { data: { id: 42, name: '테스트 상품' } } });
      testUtils.mockApi('qna', {
        response: makeQnaResponse([
          {
            id: 4,
            user_id: 30,
            author_name: '김철수',
            title: '재입고 문의',
            content: '재입고 예정인가요?',
            is_secret: false,
            is_owner: false,
            is_answered: false,
            created_at: '2026-03-04T00:00:00Z',
            reply: null,
            attachments: [],
          },
        ]),
      });

      await testUtils.render();

      await waitFor(() => {
        expect(screen.getByTestId('qna-tab')).toBeInTheDocument();
      });

      expect(screen.queryByTestId('qna-reply-0')).not.toBeInTheDocument();

      testUtils.cleanup();
    });
  });

  describe('비밀글 처리 (is_owner 기반)', () => {
    it('비밀글이고 is_owner=false이면 내용 대신 "비밀글입니다."가 표시된다', async () => {
      const testUtils = createLayoutTest(makeQnaTabFixture(), {
        componentRegistry: registry,
        initialState: {
          _local: { activeTab: 'qna' },
          _global: { ...boardSlugGlobal, currentUser: { id: 99 } },
        },
      });

      testUtils.mockApi('product', { response: { data: { id: 42, name: '테스트 상품' } } });
      testUtils.mockApi('qna', {
        response: makeQnaResponse([
          {
            id: 5,
            user_id: 10,
            author_name: '홍길동',
            title: '비밀 제목',
            content: '비밀 내용',
            is_secret: true,
            is_owner: false,
            is_answered: false,
            created_at: '2026-03-05T00:00:00Z',
            reply: null,
            attachments: [],
          },
        ], { ...defaultBoardSettings, secret_mode: 'enabled' }),
      });

      await testUtils.render();

      await waitFor(() => {
        expect(screen.getByTestId('qna-tab')).toBeInTheDocument();
      });

      expect(screen.getByTestId('qna-content-0')).toHaveTextContent('비밀글입니다.');

      testUtils.cleanup();
    });

    it('비밀글이고 is_owner=true이면 실제 내용이 표시된다', async () => {
      const testUtils = createLayoutTest(makeQnaTabFixture(), {
        componentRegistry: registry,
        initialState: {
          _local: { activeTab: 'qna' },
          _global: { ...boardSlugGlobal, currentUser: { id: 10 } },
        },
      });

      testUtils.mockApi('product', { response: { data: { id: 42, name: '테스트 상품' } } });
      testUtils.mockApi('qna', {
        response: makeQnaResponse([
          {
            id: 6,
            user_id: 10,
            author_name: '홍길동',
            title: '나만의 비밀 제목',
            content: '나만의 비밀 내용',
            is_secret: true,
            is_owner: true,
            is_answered: false,
            created_at: '2026-03-06T00:00:00Z',
            reply: null,
            attachments: [],
          },
        ], { ...defaultBoardSettings, secret_mode: 'always' }),
      });

      await testUtils.render();

      await waitFor(() => {
        expect(screen.getByTestId('qna-tab')).toBeInTheDocument();
      });

      expect(screen.getByTestId('qna-content-0')).toHaveTextContent('나만의 비밀 제목');

      testUtils.cleanup();
    });

    it('비밀글이고 is_owner=false이면 A행(답변)도 숨겨진다', async () => {
      const testUtils = createLayoutTest(makeQnaTabFixture(), {
        componentRegistry: registry,
        initialState: {
          _local: { activeTab: 'qna' },
          _global: { ...boardSlugGlobal, currentUser: { id: 99 } },
        },
      });

      testUtils.mockApi('product', { response: { data: { id: 42, name: '테스트 상품' } } });
      testUtils.mockApi('qna', {
        response: makeQnaResponse([
          {
            id: 7,
            user_id: 10,
            author_name: '홍길동',
            title: '비밀 문의',
            content: '비밀 내용',
            is_secret: true,
            is_owner: false,
            is_answered: true,
            created_at: '2026-03-07T00:00:00Z',
            reply: {
              id: 20,
              content: '비밀 답변',
              created_at: '2026-03-08T00:00:00Z',
            },
            attachments: [],
          },
        ], { ...defaultBoardSettings, secret_mode: 'always' }),
      });

      await testUtils.render();

      await waitFor(() => {
        expect(screen.getByTestId('qna-tab')).toBeInTheDocument();
      });

      // is_owner=false이므로 A행 숨김
      expect(screen.queryByTestId('qna-reply-0')).not.toBeInTheDocument();

      testUtils.cleanup();
    });

    it('비밀글이고 is_owner=true이면 A행(답변)이 표시된다', async () => {
      const testUtils = createLayoutTest(makeQnaTabFixture(), {
        componentRegistry: registry,
        initialState: {
          _local: { activeTab: 'qna' },
          _global: { ...boardSlugGlobal, currentUser: { id: 10 } },
        },
      });

      testUtils.mockApi('product', { response: { data: { id: 42, name: '테스트 상품' } } });
      testUtils.mockApi('qna', {
        response: makeQnaResponse([
          {
            id: 8,
            user_id: 10,
            author_name: '홍길동',
            title: '내 비밀 문의',
            content: '내 비밀 내용',
            is_secret: true,
            is_owner: true,
            is_answered: true,
            created_at: '2026-03-09T00:00:00Z',
            reply: {
              id: 21,
              content: '내 비밀 답변',
              created_at: '2026-03-10T00:00:00Z',
            },
            attachments: [],
          },
        ], { ...defaultBoardSettings, secret_mode: 'always' }),
      });

      await testUtils.render();

      await waitFor(() => {
        expect(screen.getByTestId('qna-tab')).toBeInTheDocument();
      });

      expect(screen.getByTestId('qna-reply-0')).toBeInTheDocument();
      expect(screen.getByTestId('qna-reply-content-0')).toHaveTextContent('내 비밀 답변');

      testUtils.cleanup();
    });
  });

  describe('문의 작성 버튼 표시 조건 (로그인 필수)', () => {
    it('로그인/비로그인 무관하게 문의 작성 버튼이 항상 표시된다', async () => {
      const testUtils = createLayoutTest(makeQnaTabFixture(), {
        componentRegistry: registry,
        initialState: {
          _local: { activeTab: 'qna' },
          _global: { ...boardSlugGlobal, currentUser: { id: 1, uuid: 'uuid-1', name: '홍길동' } },
        },
      });

      testUtils.mockApi('product', { response: { data: { id: 42, name: '테스트 상품' } } });
      testUtils.mockApi('qna', { response: makeQnaResponse([]) });

      await testUtils.render();

      await waitFor(() => {
        expect(screen.getByTestId('qna-tab')).toBeInTheDocument();
      });

      expect(screen.getByTestId('write-qna-btn')).toBeInTheDocument();

      testUtils.cleanup();
    });

    it('비회원이어도 문의 작성 버튼이 표시된다 (클릭 시 토스트 안내)', async () => {
      const testUtils = createLayoutTest(makeQnaTabFixture(), {
        componentRegistry: registry,
        initialState: {
          _local: { activeTab: 'qna' },
          _global: { ...boardSlugGlobal, currentUser: null },
        },
      });

      testUtils.mockApi('product', { response: { data: { id: 42, name: '테스트 상품' } } });
      testUtils.mockApi('qna', {
        response: makeQnaResponse([], { ...defaultBoardSettings, secret_mode: 'always' }),
      });

      await testUtils.render();

      await waitFor(() => {
        expect(screen.getByTestId('qna-tab')).toBeInTheDocument();
      });

      expect(screen.getByTestId('write-qna-btn')).toBeInTheDocument();

      testUtils.cleanup();
    });

    it('비회원이고 secret_mode=disabled여도 문의 작성 버튼이 표시된다', async () => {
      const testUtils = createLayoutTest(makeQnaTabFixture(), {
        componentRegistry: registry,
        initialState: {
          _local: { activeTab: 'qna' },
          _global: { ...boardSlugGlobal, currentUser: null },
        },
      });

      testUtils.mockApi('product', { response: { data: { id: 42, name: '테스트 상품' } } });
      testUtils.mockApi('qna', {
        response: makeQnaResponse([], { ...defaultBoardSettings, secret_mode: 'disabled' }),
      });

      await testUtils.render();

      await waitFor(() => {
        expect(screen.getByTestId('qna-tab')).toBeInTheDocument();
      });

      expect(screen.getByTestId('write-qna-btn')).toBeInTheDocument();

      testUtils.cleanup();
    });
  });

  describe('비밀글 제외 체크박스', () => {
    it('비밀글 제외 체크박스가 렌더링된다', async () => {
      const testUtils = createLayoutTest(makeQnaTabFixture(), {
        componentRegistry: registry,
        initialState: { _local: { activeTab: 'qna' }, _global: boardSlugGlobal },
      });

      testUtils.mockApi('product', { response: { data: { id: 42, name: '테스트 상품' } } });
      testUtils.mockApi('qna', { response: makeQnaResponse([]) });

      await testUtils.render();

      await waitFor(() => {
        expect(screen.getByTestId('qna-tab')).toBeInTheDocument();
      });

      expect(screen.getByTestId('exclude-secret-checkbox')).toBeInTheDocument();

      testUtils.cleanup();
    });
  });

  describe('인라인 상세 펼침', () => {
    const makeInlineFixture = () => ({
      version: '1.0.0',
      layout_name: 'shop/show',
      data_sources: [
        {
          id: 'product',
          type: 'api',
          endpoint: '/api/modules/sirsoft-ecommerce/products/42',
          method: 'GET',
          auto_fetch: true,
          loading_strategy: 'blocking',
        },
        {
          id: 'qna',
          type: 'api',
          endpoint: '/api/modules/sirsoft-ecommerce/products/42/inquiries',
          method: 'GET',
          auto_fetch: true,
          auth_mode: 'optional',
          loading_strategy: 'progressive',
          fallback: { items: [], meta: { current_page: 1, per_page: 10, total: 0, last_page: 1 } },
        },
      ],
      slots: {
        content: [
          {
            type: 'basic',
            name: 'Div',
            if: `{{(_local.activeTab ?? 'info') === 'qna' && !!_global.modules?.['sirsoft-ecommerce']?.inquiry?.board_slug}}`,
            props: { 'data-testid': 'qna-tab' },
            children: [
              {
                type: 'basic',
                name: 'Div',
                if: '{{qna.data.items && qna.data.items.length > 0}}',
                props: { 'data-testid': 'qna-list' },
                children: [
                  {
                    type: 'basic',
                    name: 'Div',
                    iteration: { source: '{{qna.data.items ?? []}}', item_var: 'item', index_var: 'idx' },
                    children: [
                      {
                        comment: 'Q행 버튼',
                        type: 'basic',
                        name: 'Button',
                        props: { 'data-testid': 'qna-row-{{idx}}', type: 'button' },
                        actions: [
                          {
                            type: 'click',
                            handler: 'sequence',
                            actions: [
                              {
                                handler: 'setState',
                                params: {
                                  target: 'local',
                                  qnaOpenId: `{{item.is_secret && !item.is_owner ? _local.qnaOpenId : (_local.qnaOpenId === item.id ? null : item.id)}}`,
                                },
                                if: '{{!item.is_secret || item.is_owner}}',
                              },
                              {
                                handler: 'toast',
                                params: { message: '비밀글은 작성자만 확인할 수 있습니다.', type: 'info' },
                                if: '{{item.is_secret && !item.is_owner}}',
                              },
                            ],
                          },
                        ],
                        children: [
                          {
                            type: 'basic',
                            name: 'Span',
                            props: { 'data-testid': 'qna-content-{{idx}}' },
                            text: `{{item.is_secret && !item.is_owner ? '비밀글입니다.' : (item.title ?? item.content ?? '')}}`,
                          },
                        ],
                      },
                      {
                        comment: '인라인 상세 패널',
                        type: 'basic',
                        name: 'Div',
                        if: '{{_local.qnaOpenId === item.id && (!item.is_secret || item.is_owner)}}',
                        props: { 'data-testid': 'qna-detail-{{idx}}' },
                        children: [
                          {
                            type: 'basic',
                            name: 'P',
                            props: { 'data-testid': 'qna-detail-content-{{idx}}' },
                            text: '{{item.content ?? ""}}',
                          },
                          {
                            comment: '판매자 답변',
                            type: 'basic',
                            name: 'Div',
                            if: '{{!!item.reply}}',
                            props: { 'data-testid': 'qna-detail-reply-{{idx}}' },
                            children: [
                              {
                                type: 'basic',
                                name: 'P',
                                props: { 'data-testid': 'qna-detail-reply-content-{{idx}}' },
                                text: '{{item.reply.content ?? ""}}',
                              },
                            ],
                          },
                          {
                            comment: '답변 없음',
                            type: 'basic',
                            name: 'Span',
                            if: '{{!item.reply}}',
                            props: { 'data-testid': 'qna-detail-no-reply-{{idx}}' },
                            text: '아직 답변이 없습니다.',
                          },
                        ],
                      },
                    ],
                  },
                ],
              },
            ],
          },
        ],
      },
    });

    it('qnaOpenId가 설정된 경우 해당 행의 인라인 상세가 표시된다', async () => {
      const testUtils = createLayoutTest(makeInlineFixture(), {
        componentRegistry: registry,
        // qnaOpenId=1 초기 설정으로 상세 패널 열린 상태 검증
        initialState: { _local: { activeTab: 'qna', qnaOpenId: 1 }, _global: boardSlugGlobal },
      });

      testUtils.mockApi('product', { response: { data: { id: 42 } } });
      testUtils.mockApi('qna', {
        response: makeQnaResponse([
          {
            id: 1,
            user_id: 10,
            author_name: '홍길동',
            title: '배송 문의',
            content: '배송 얼마나 걸리나요?',
            is_secret: false,
            is_owner: false,
            is_answered: false,
            created_at: '2026-03-01T00:00:00Z',
            reply: null,
            attachments: [],
          },
        ]),
      });

      await testUtils.render();
      await waitFor(() => expect(screen.getByTestId('qna-detail-0')).toBeInTheDocument());

      expect(screen.getByTestId('qna-detail-content-0')).toHaveTextContent('배송 얼마나 걸리나요?');
      expect(screen.getByTestId('qna-detail-no-reply-0')).toBeInTheDocument();

      testUtils.cleanup();
    });

    it('답변이 있으면 판매자 답변 박스가 표시된다', async () => {
      const testUtils = createLayoutTest(makeInlineFixture(), {
        componentRegistry: registry,
        initialState: { _local: { activeTab: 'qna', qnaOpenId: 3 }, _global: boardSlugGlobal },
      });

      testUtils.mockApi('product', { response: { data: { id: 42 } } });
      testUtils.mockApi('qna', {
        response: makeQnaResponse([
          {
            id: 3,
            user_id: 20,
            author_name: '이순신',
            title: '사이즈 문의',
            content: '사이즈가 어떻게 되나요?',
            is_secret: false,
            is_owner: false,
            is_answered: true,
            created_at: '2026-03-02T00:00:00Z',
            reply: { id: 10, content: '사이즈는 XL까지 있습니다.', created_at: '2026-03-03T00:00:00Z' },
            attachments: [],
          },
        ]),
      });

      await testUtils.render();
      await waitFor(() => expect(screen.getByTestId('qna-detail-0')).toBeInTheDocument());

      expect(screen.getByTestId('qna-detail-reply-0')).toBeInTheDocument();
      expect(screen.getByTestId('qna-detail-reply-content-0')).toHaveTextContent('사이즈는 XL까지 있습니다.');
      expect(screen.queryByTestId('qna-detail-no-reply-0')).not.toBeInTheDocument();

      testUtils.cleanup();
    });

    it('비밀글 비소유자는 상세 패널이 열리지 않는다', async () => {
      const testUtils = createLayoutTest(makeInlineFixture(), {
        componentRegistry: registry,
        initialState: {
          _local: { activeTab: 'qna', qnaOpenId: 5 },
          _global: { ...boardSlugGlobal, currentUser: { id: 99 } },
        },
      });

      testUtils.mockApi('product', { response: { data: { id: 42 } } });
      testUtils.mockApi('qna', {
        response: makeQnaResponse([
          {
            id: 5,
            user_id: 10,
            author_name: '홍길동',
            title: '비밀 제목',
            content: '비밀 내용',
            is_secret: true,
            is_owner: false,
            is_answered: false,
            created_at: '2026-03-05T00:00:00Z',
            reply: null,
            attachments: [],
          },
        ]),
      });

      await testUtils.render();
      await waitFor(() => expect(screen.getByTestId('qna-tab')).toBeInTheDocument());

      // is_owner=false이므로 상세 패널 비노출 (qnaOpenId=5여도)
      expect(screen.queryByTestId('qna-detail-0')).not.toBeInTheDocument();

      testUtils.cleanup();
    });

    it('qnaOpenId가 null이면 상세 패널이 표시되지 않는다', async () => {
      const testUtils = createLayoutTest(makeInlineFixture(), {
        componentRegistry: registry,
        // qnaOpenId=null → 상세 패널 닫힌 상태
        initialState: { _local: { activeTab: 'qna', qnaOpenId: null }, _global: boardSlugGlobal },
      });

      testUtils.mockApi('product', { response: { data: { id: 42 } } });
      testUtils.mockApi('qna', {
        response: makeQnaResponse([
          {
            id: 1,
            user_id: 10,
            author_name: '홍길동',
            title: '배송 문의',
            content: '배송 얼마나 걸리나요?',
            is_secret: false,
            is_owner: false,
            is_answered: false,
            created_at: '2026-03-01T00:00:00Z',
            reply: null,
            attachments: [],
          },
        ]),
      });

      await testUtils.render();
      await waitFor(() => expect(screen.getByTestId('qna-tab')).toBeInTheDocument());

      expect(screen.queryByTestId('qna-detail-0')).not.toBeInTheDocument();

      testUtils.cleanup();
    });
  });

  describe('탭 비활성화 시 렌더링', () => {
    it('activeTab이 qna가 아니면 탭 내용이 표시되지 않는다', async () => {
      const testUtils = createLayoutTest(makeQnaTabFixture(), {
        componentRegistry: registry,
        initialState: { _local: { activeTab: 'info' }, _global: boardSlugGlobal },
      });

      testUtils.mockApi('product', { response: { data: { id: 42, name: '테스트 상품' } } });
      testUtils.mockApi('qna', { response: makeQnaResponse([]) });

      await testUtils.render();

      await waitFor(() => {}, { timeout: 500 });

      expect(screen.queryByTestId('qna-tab')).not.toBeInTheDocument();

      testUtils.cleanup();
    });

    it('board_slug가 null이면 activeTab=qna여도 탭 내용이 표시되지 않는다', async () => {
      const testUtils = createLayoutTest(makeQnaTabFixture(), {
        componentRegistry: registry,
        initialState: {
          _local: { activeTab: 'qna' },
          _global: {
            modules: { 'sirsoft-ecommerce': { inquiry: { board_slug: null } } },
          },
        },
      });

      testUtils.mockApi('product', { response: { data: { id: 42, name: '테스트 상품' } } });
      testUtils.mockApi('qna', { response: makeQnaResponse([]) });

      await testUtils.render();

      await waitFor(() => {}, { timeout: 500 });

      expect(screen.queryByTestId('qna-tab')).not.toBeInTheDocument();

      testUtils.cleanup();
    });

    it('board_slug가 미설정이면 activeTab=qna여도 탭 내용이 표시되지 않는다', async () => {
      const testUtils = createLayoutTest(makeQnaTabFixture(), {
        componentRegistry: registry,
        initialState: {
          _local: { activeTab: 'qna' },
          _global: {},
        },
      });

      testUtils.mockApi('product', { response: { data: { id: 42, name: '테스트 상품' } } });
      testUtils.mockApi('qna', { response: makeQnaResponse([]) });

      await testUtils.render();

      await waitFor(() => {}, { timeout: 500 });

      expect(screen.queryByTestId('qna-tab')).not.toBeInTheDocument();

      testUtils.cleanup();
    });
  });

  describe('문의 작성 모달 검증 오류 UI', () => {
    // 오류 UI 핵심 로직만 추출한 테스트용 레이아웃
    const makeModalErrorFixture = () => ({
      version: '1.0.0',
      layout_name: 'modal-qna-error-test',
      components: [
        {
          type: 'basic',
          name: 'Div',
          props: { 'data-testid': 'modal-wrapper' },
          children: [
            {
              comment: '내용 오류 Span (qnaErrors.content가 있을 때만 표시)',
              type: 'basic',
              name: 'Span',
              if: '{{_global.qnaErrors?.content}}',
              props: { className: 'text-red-500 dark:text-red-400 text-xs mt-1 block' },
              text: "{{(_global.qnaErrors?.content ?? [])[0] ?? ''}}",
            },
            {
              comment: '제목 오류 Span (qnaErrors.title가 있을 때만 표시)',
              type: 'basic',
              name: 'Span',
              if: '{{_global.qnaErrors?.title}}',
              props: { className: 'text-red-500 dark:text-red-400 text-xs mt-1 block' },
              text: "{{(_global.qnaErrors?.title ?? [])[0] ?? ''}}",
            },
          ],
        },
      ],
    });

    it('qnaErrors.content가 있으면 오류 메시지 Span이 렌더링된다', async () => {
      const testUtils = createLayoutTest(makeModalErrorFixture(), {
        componentRegistry: registry,
        initialState: {
          _global: {
            qnaErrors: { content: ['문의 내용을 입력해주세요.'] },
          },
        },
      });

      await testUtils.render();

      await waitFor(() => {
        const errorSpans = document.querySelectorAll('span');
        const found = Array.from(errorSpans).some(
          (el) => el.textContent === '문의 내용을 입력해주세요.'
        );
        expect(found).toBe(true);
      });

      testUtils.cleanup();
    });

    it('qnaErrors가 null이면 오류 메시지 Span이 렌더링되지 않는다', async () => {
      const testUtils = createLayoutTest(makeModalErrorFixture(), {
        componentRegistry: registry,
        initialState: {
          _global: {
            qnaErrors: null,
          },
        },
      });

      await testUtils.render();

      await waitFor(() => {}, { timeout: 300 });

      const errorSpans = document.querySelectorAll('span');
      const hasErrorMsg = Array.from(errorSpans).some(
        (el) =>
          el.className?.includes('text-red-500') && el.textContent?.trim() !== ''
      );
      expect(hasErrorMsg).toBe(false);

      testUtils.cleanup();
    });

    it('qnaErrors.title이 있으면 제목 오류 메시지 Span이 렌더링된다', async () => {
      const testUtils = createLayoutTest(makeModalErrorFixture(), {
        componentRegistry: registry,
        initialState: {
          _global: {
            qnaErrors: { title: ['제목은 2자 이상 입력해주세요.'] },
          },
        },
      });

      await testUtils.render();

      await waitFor(() => {
        const errorSpans = document.querySelectorAll('span');
        const found = Array.from(errorSpans).some(
          (el) => el.textContent === '제목은 2자 이상 입력해주세요.'
        );
        expect(found).toBe(true);
      });

      testUtils.cleanup();
    });
  });

  describe('문의 수정/삭제 버튼 렌더링 (Phase 3 추가)', () => {
    // 수정/삭제 버튼 포함 최소 픽스처
    const makeEditDeleteFixture = () => ({
      version: '1.0.0',
      layout_name: 'shop/show',
      data_sources: [
        {
          id: 'product',
          type: 'api',
          endpoint: '/api/modules/sirsoft-ecommerce/products/42',
          method: 'GET',
          auto_fetch: true,
          loading_strategy: 'blocking',
        },
        {
          id: 'qna',
          type: 'api',
          endpoint: '/api/modules/sirsoft-ecommerce/products/42/inquiries',
          method: 'GET',
          auto_fetch: true,
          auth_mode: 'optional',
          loading_strategy: 'progressive',
          fallback: {
            items: [],
            meta: {
              board_settings: { secret_mode: 'disabled' },
              abilities: { can_update: false },
              current_page: 1, per_page: 10, total: 0, last_page: 1,
            },
          },
        },
      ],
      slots: {
        content: [
          {
            type: 'basic',
            name: 'Div',
            if: `{{(_local.activeTab ?? 'info') === 'qna' && !!_global.modules?.['sirsoft-ecommerce']?.inquiry?.board_slug}}`,
            props: { 'data-testid': 'qna-tab' },
            children: [
              {
                type: 'basic',
                name: 'Div',
                iteration: { source: '{{qna.data.items ?? []}}', item_var: 'item', index_var: 'idx' },
                children: [
                  {
                    comment: '문의 행 버튼 (클릭 시 상세 열림)',
                    type: 'basic',
                    name: 'Button',
                    props: { 'data-testid': 'qna-row-{{idx}}', type: 'button' },
                    text: '{{item.title ?? item.content ?? ""}}',
                  },
                  {
                    comment: '상세 패널 (qnaOpenId === item.id)',
                    type: 'basic',
                    name: 'Div',
                    if: '{{_local.qnaOpenId === item.id}}',
                    props: { 'data-testid': 'qna-detail-{{idx}}' },
                    children: [
                      {
                        comment: '문의 수정/삭제 버튼 (본인 소유 + 미답변)',
                        type: 'basic',
                        name: 'Div',
                        if: '{{item.is_owner && !item.is_answered}}',
                        props: { 'data-testid': 'qna-edit-delete-btns-{{idx}}' },
                        children: [
                          {
                            type: 'basic',
                            name: 'Button',
                            props: { 'data-testid': 'qna-edit-btn-{{idx}}', type: 'button' },
                            text: '수정',
                          },
                          {
                            type: 'basic',
                            name: 'Button',
                            props: { 'data-testid': 'qna-delete-btn-{{idx}}', type: 'button' },
                            text: '삭제',
                          },
                        ],
                      },
                      {
                        comment: '판매자 답변 영역 (답변 있을 때)',
                        type: 'basic',
                        name: 'Div',
                        if: '{{!!item.reply}}',
                        props: { 'data-testid': 'qna-reply-area-{{idx}}' },
                        children: [
                          {
                            type: 'basic',
                            name: 'Span',
                            props: { 'data-testid': 'qna-reply-content-{{idx}}' },
                            text: '{{item.reply.content ?? ""}}',
                          },
                          {
                            comment: '답변 수정/삭제 버튼 (can_update 권한)',
                            type: 'basic',
                            name: 'Div',
                            if: '{{!!(qna.data.meta.abilities.can_update)}}',
                            props: { 'data-testid': 'qna-reply-manage-btns-{{idx}}' },
                            children: [
                              {
                                type: 'basic',
                                name: 'Button',
                                props: { 'data-testid': 'qna-reply-edit-btn-{{idx}}', type: 'button' },
                                text: '답변 수정',
                              },
                              {
                                type: 'basic',
                                name: 'Button',
                                props: { 'data-testid': 'qna-reply-delete-btn-{{idx}}', type: 'button' },
                                text: '답변 삭제',
                              },
                            ],
                          },
                        ],
                      },
                      {
                        comment: '답변 작성 버튼 (can_update + 미답변)',
                        type: 'basic',
                        name: 'Button',
                        if: '{{!!(qna.data.meta.abilities.can_update) && !item.is_answered}}',
                        props: { 'data-testid': 'qna-write-reply-btn-{{idx}}', type: 'button' },
                        text: '답변 작성',
                      },
                    ],
                  },
                ],
              },
            ],
          },
        ],
      },
    });

    const makeItemWithAbilities = (
      overrides: Partial<{
        is_owner: boolean;
        is_answered: boolean;
        reply: object | null;
        can_update: boolean;
      }> = {}
    ) => {
      const {
        is_owner = true,
        is_answered = false,
        reply = null,
        can_update = false,
      } = overrides;

      return {
        items: [
          {
            id: 100,
            user_id: 10,
            author_name: '홍길동',
            title: '테스트 문의',
            content: '테스트 내용',
            is_secret: false,
            is_owner,
            is_answered,
            created_at: '2026-03-01T00:00:00Z',
            reply,
            attachments: [],
          },
        ],
        meta: {
          board_settings: { secret_mode: 'disabled' },
          abilities: { can_update },
          current_page: 1, per_page: 10, total: 1, last_page: 1,
        },
      };
    };

    it('본인 소유 + 미답변이면 수정/삭제 버튼이 렌더링된다', async () => {
      const testUtils = createLayoutTest(makeEditDeleteFixture(), {
        componentRegistry: registry,
        initialState: {
          _local: { activeTab: 'qna', qnaOpenId: 100 },
          _global: boardSlugGlobal,
        },
      });

      testUtils.mockApi('product', { response: { data: { id: 42 } } });
      testUtils.mockApi('qna', {
        response: { data: makeItemWithAbilities({ is_owner: true, is_answered: false }) },
      });

      await testUtils.render();
      await waitFor(() => expect(screen.getByTestId('qna-detail-0')).toBeInTheDocument());

      expect(screen.getByTestId('qna-edit-delete-btns-0')).toBeInTheDocument();
      expect(screen.getByTestId('qna-edit-btn-0')).toBeInTheDocument();
      expect(screen.getByTestId('qna-delete-btn-0')).toBeInTheDocument();

      testUtils.cleanup();
    });

    it('본인 소유여도 이미 답변이 있으면 수정/삭제 버튼이 렌더링되지 않는다', async () => {
      const testUtils = createLayoutTest(makeEditDeleteFixture(), {
        componentRegistry: registry,
        initialState: {
          _local: { activeTab: 'qna', qnaOpenId: 100 },
          _global: boardSlugGlobal,
        },
      });

      testUtils.mockApi('product', { response: { data: { id: 42 } } });
      testUtils.mockApi('qna', {
        response: {
          data: makeItemWithAbilities({
            is_owner: true,
            is_answered: true,
            reply: { id: 50, content: '답변 내용', created_at: '2026-03-02T00:00:00Z' },
          }),
        },
      });

      await testUtils.render();
      await waitFor(() => expect(screen.getByTestId('qna-detail-0')).toBeInTheDocument());

      expect(screen.queryByTestId('qna-edit-delete-btns-0')).not.toBeInTheDocument();

      testUtils.cleanup();
    });

    it('타인의 문의이면 수정/삭제 버튼이 렌더링되지 않는다', async () => {
      const testUtils = createLayoutTest(makeEditDeleteFixture(), {
        componentRegistry: registry,
        initialState: {
          _local: { activeTab: 'qna', qnaOpenId: 100 },
          _global: boardSlugGlobal,
        },
      });

      testUtils.mockApi('product', { response: { data: { id: 42 } } });
      testUtils.mockApi('qna', {
        response: { data: makeItemWithAbilities({ is_owner: false, is_answered: false }) },
      });

      await testUtils.render();
      await waitFor(() => expect(screen.getByTestId('qna-detail-0')).toBeInTheDocument());

      expect(screen.queryByTestId('qna-edit-delete-btns-0')).not.toBeInTheDocument();

      testUtils.cleanup();
    });

    it('can_update 권한이 있고 답변이 있으면 답변 수정/삭제 버튼이 렌더링된다', async () => {
      const testUtils = createLayoutTest(makeEditDeleteFixture(), {
        componentRegistry: registry,
        initialState: {
          _local: { activeTab: 'qna', qnaOpenId: 100 },
          _global: boardSlugGlobal,
        },
      });

      testUtils.mockApi('product', { response: { data: { id: 42 } } });
      testUtils.mockApi('qna', {
        response: {
          data: makeItemWithAbilities({
            is_owner: false,
            is_answered: true,
            reply: { id: 50, content: '운영자 답변', created_at: '2026-03-02T00:00:00Z' },
            can_update: true,
          }),
        },
      });

      await testUtils.render();
      await waitFor(() => expect(screen.getByTestId('qna-detail-0')).toBeInTheDocument());

      expect(screen.getByTestId('qna-reply-manage-btns-0')).toBeInTheDocument();
      expect(screen.getByTestId('qna-reply-edit-btn-0')).toBeInTheDocument();
      expect(screen.getByTestId('qna-reply-delete-btn-0')).toBeInTheDocument();

      testUtils.cleanup();
    });

    it('can_update 권한이 없으면 답변 수정/삭제 버튼이 렌더링되지 않는다', async () => {
      const testUtils = createLayoutTest(makeEditDeleteFixture(), {
        componentRegistry: registry,
        initialState: {
          _local: { activeTab: 'qna', qnaOpenId: 100 },
          _global: boardSlugGlobal,
        },
      });

      testUtils.mockApi('product', { response: { data: { id: 42 } } });
      testUtils.mockApi('qna', {
        response: {
          data: makeItemWithAbilities({
            is_owner: false,
            is_answered: true,
            reply: { id: 50, content: '운영자 답변', created_at: '2026-03-02T00:00:00Z' },
            can_update: false,
          }),
        },
      });

      await testUtils.render();
      await waitFor(() => expect(screen.getByTestId('qna-detail-0')).toBeInTheDocument());

      expect(screen.queryByTestId('qna-reply-manage-btns-0')).not.toBeInTheDocument();

      testUtils.cleanup();
    });

    it('can_update 권한이 있고 미답변이면 답변 작성 버튼이 렌더링된다', async () => {
      const testUtils = createLayoutTest(makeEditDeleteFixture(), {
        componentRegistry: registry,
        initialState: {
          _local: { activeTab: 'qna', qnaOpenId: 100 },
          _global: boardSlugGlobal,
        },
      });

      testUtils.mockApi('product', { response: { data: { id: 42 } } });
      testUtils.mockApi('qna', {
        response: {
          data: makeItemWithAbilities({
            is_owner: false,
            is_answered: false,
            reply: null,
            can_update: true,
          }),
        },
      });

      await testUtils.render();
      await waitFor(() => expect(screen.getByTestId('qna-detail-0')).toBeInTheDocument());

      expect(screen.getByTestId('qna-write-reply-btn-0')).toBeInTheDocument();

      testUtils.cleanup();
    });
  });

  describe('can_update 비밀글 노출 (Issue #208 2차 피드백)', () => {
    // can_update 권한자 비밀글 노출 테스트용 픽스처
    const makeCanManageFixture = () => ({
      version: '1.0.0',
      layout_name: 'shop/show',
      data_sources: [
        {
          id: 'product',
          type: 'api',
          endpoint: '/api/modules/sirsoft-ecommerce/products/42',
          method: 'GET',
          auto_fetch: true,
          loading_strategy: 'blocking',
        },
        {
          id: 'qna',
          type: 'api',
          endpoint: '/api/modules/sirsoft-ecommerce/products/42/inquiries',
          method: 'GET',
          auto_fetch: true,
          auth_mode: 'optional',
          loading_strategy: 'progressive',
          fallback: { items: [], meta: { current_page: 1, per_page: 10, total: 0, last_page: 1 } },
        },
      ],
      slots: {
        content: [
          {
            type: 'basic',
            name: 'Div',
            if: `{{(_local.activeTab ?? 'info') === 'qna' && !!_global.modules?.['sirsoft-ecommerce']?.inquiry?.board_slug}}`,
            props: { 'data-testid': 'qna-tab' },
            children: [
              {
                type: 'basic',
                name: 'Div',
                iteration: { source: '{{qna.data.items ?? []}}', item_var: 'item', index_var: 'idx' },
                children: [
                  {
                    comment: 'Q행: 비밀글 제목 (can_update 또는 소유자만)',
                    type: 'basic',
                    name: 'Span',
                    props: { 'data-testid': 'qna-content-{{idx}}' },
                    text: `{{(item.is_secret && !item.is_owner && !(qna.data.meta.abilities.can_update)) ? '비밀글입니다.' : (item.title ?? item.content ?? '')}}`,
                  },
                  {
                    comment: '인라인 상세 패널 (본인, 공개글, can_update)',
                    type: 'basic',
                    name: 'Div',
                    if: `{{_local.qnaOpenId === item.id && (!item.is_secret || item.is_owner || !!(qna.data.meta.abilities.can_update))}}`,
                    props: { 'data-testid': 'qna-detail-{{idx}}' },
                    children: [
                      {
                        type: 'basic',
                        name: 'P',
                        props: { 'data-testid': 'qna-detail-content-{{idx}}' },
                        text: '{{item.content ?? ""}}',
                      },
                    ],
                  },
                ],
              },
            ],
          },
        ],
      },
    });

    const makeSecretItem = (canManage: boolean) => ({
      data: {
        items: [
          {
            id: 99,
            user_id: 10,
            author_name: '홍길동',
            title: '비밀 제목',
            content: '비밀 내용 전문',
            is_secret: true,
            is_owner: false,
            is_answered: false,
            created_at: '2026-03-01T00:00:00Z',
            reply: null,
            attachments: [],
          },
        ],
        meta: {
          board_settings: { secret_mode: 'enabled' },
          abilities: { can_update: canManage },
          current_page: 1, per_page: 10, total: 1, last_page: 1,
        },
      },
    });

    it('can_update=true이면 비밀글 제목이 "비밀글입니다." 대신 실제 제목으로 표시된다', async () => {
      const testUtils = createLayoutTest(makeCanManageFixture(), {
        componentRegistry: registry,
        initialState: { _local: { activeTab: 'qna' }, _global: boardSlugGlobal },
      });

      testUtils.mockApi('product', { response: { data: { id: 42 } } });
      testUtils.mockApi('qna', { response: makeSecretItem(true) });

      await testUtils.render();
      await waitFor(() => expect(screen.getByTestId('qna-tab')).toBeInTheDocument());

      expect(screen.getByTestId('qna-content-0')).toHaveTextContent('비밀 제목');
      expect(screen.getByTestId('qna-content-0')).not.toHaveTextContent('비밀글입니다.');

      testUtils.cleanup();
    });

    it('can_update=false이고 is_owner=false이면 "비밀글입니다."가 표시된다', async () => {
      const testUtils = createLayoutTest(makeCanManageFixture(), {
        componentRegistry: registry,
        initialState: { _local: { activeTab: 'qna' }, _global: boardSlugGlobal },
      });

      testUtils.mockApi('product', { response: { data: { id: 42 } } });
      testUtils.mockApi('qna', { response: makeSecretItem(false) });

      await testUtils.render();
      await waitFor(() => expect(screen.getByTestId('qna-tab')).toBeInTheDocument());

      expect(screen.getByTestId('qna-content-0')).toHaveTextContent('비밀글입니다.');

      testUtils.cleanup();
    });

    it('can_update=true이면 비밀글이어도 qnaOpenId 설정 시 상세 패널이 열린다', async () => {
      const testUtils = createLayoutTest(makeCanManageFixture(), {
        componentRegistry: registry,
        initialState: { _local: { activeTab: 'qna', qnaOpenId: 99 }, _global: boardSlugGlobal },
      });

      testUtils.mockApi('product', { response: { data: { id: 42 } } });
      testUtils.mockApi('qna', { response: makeSecretItem(true) });

      await testUtils.render();
      await waitFor(() => expect(screen.getByTestId('qna-tab')).toBeInTheDocument());

      expect(screen.getByTestId('qna-detail-0')).toBeInTheDocument();
      expect(screen.getByTestId('qna-detail-content-0')).toHaveTextContent('비밀 내용 전문');

      testUtils.cleanup();
    });

    it('can_update=false이면 비밀글 qnaOpenId 설정 시 상세 패널이 열리지 않는다', async () => {
      const testUtils = createLayoutTest(makeCanManageFixture(), {
        componentRegistry: registry,
        initialState: { _local: { activeTab: 'qna', qnaOpenId: 99 }, _global: boardSlugGlobal },
      });

      testUtils.mockApi('product', { response: { data: { id: 42 } } });
      testUtils.mockApi('qna', { response: makeSecretItem(false) });

      await testUtils.render();
      await waitFor(() => expect(screen.getByTestId('qna-tab')).toBeInTheDocument());

      expect(screen.queryByTestId('qna-detail-0')).not.toBeInTheDocument();

      testUtils.cleanup();
    });
  });

  describe('수정됨 표시 (Issue #208 개편)', () => {
    // updated_at !== created_at 조건 검증용 픽스처
    const makeUpdatedLabelFixture = () => ({
      version: '1.0.0',
      layout_name: 'shop/show',
      data_sources: [
        {
          id: 'product',
          type: 'api',
          endpoint: '/api/modules/sirsoft-ecommerce/products/42',
          method: 'GET',
          auto_fetch: true,
          loading_strategy: 'blocking',
        },
        {
          id: 'qna',
          type: 'api',
          endpoint: '/api/modules/sirsoft-ecommerce/products/42/inquiries',
          method: 'GET',
          auto_fetch: true,
          auth_mode: 'optional',
          loading_strategy: 'progressive',
          fallback: { items: [], meta: { current_page: 1, per_page: 10, total: 0, last_page: 1 } },
        },
      ],
      slots: {
        content: [
          {
            type: 'basic',
            name: 'Div',
            if: `{{(_local.activeTab ?? 'info') === 'qna' && !!_global.modules?.['sirsoft-ecommerce']?.inquiry?.board_slug}}`,
            props: { 'data-testid': 'qna-tab' },
            children: [
              {
                type: 'basic',
                name: 'Div',
                iteration: { source: '{{qna.data.items ?? []}}', item_var: 'item', index_var: 'idx' },
                children: [
                  {
                    type: 'basic',
                    name: 'Span',
                    props: { 'data-testid': 'qna-date-{{idx}}' },
                    text: '{{item.created_at | date}}',
                  },
                  {
                    type: 'basic',
                    name: 'Span',
                    if: '{{!!item.updated_at && item.updated_at !== item.created_at}}',
                    props: { 'data-testid': 'qna-updated-label-{{idx}}' },
                    text: '(수정됨)',
                  },
                ],
              },
            ],
          },
        ],
      },
    });

    it('updated_at이 created_at과 다르면 수정됨 레이블이 표시된다', async () => {
      const testUtils = createLayoutTest(makeUpdatedLabelFixture(), {
        componentRegistry: registry,
        initialState: { _local: { activeTab: 'qna' }, _global: boardSlugGlobal },
      });

      testUtils.mockApi('product', { response: { data: { id: 42 } } });
      testUtils.mockApi('qna', {
        response: makeQnaResponse([
          {
            id: 1,
            user_id: 10,
            author_name: '홍길동',
            title: '배송 문의',
            content: '배송 얼마나 걸리나요?',
            is_secret: false,
            is_owner: false,
            is_answered: false,
            created_at: '2026-03-01T00:00:00Z',
            updated_at: '2026-03-05T00:00:00Z',
            reply: null,
            attachments: [],
          },
        ]),
      });

      await testUtils.render();
      await waitFor(() => expect(screen.getByTestId('qna-tab')).toBeInTheDocument());

      expect(screen.getByTestId('qna-updated-label-0')).toBeInTheDocument();

      testUtils.cleanup();
    });

    it('updated_at이 created_at과 같으면 수정됨 레이블이 표시되지 않는다', async () => {
      const testUtils = createLayoutTest(makeUpdatedLabelFixture(), {
        componentRegistry: registry,
        initialState: { _local: { activeTab: 'qna' }, _global: boardSlugGlobal },
      });

      testUtils.mockApi('product', { response: { data: { id: 42 } } });
      testUtils.mockApi('qna', {
        response: makeQnaResponse([
          {
            id: 2,
            user_id: 10,
            author_name: '홍길동',
            title: '일반 문의',
            content: '일반 내용',
            is_secret: false,
            is_owner: false,
            is_answered: false,
            created_at: '2026-03-01T00:00:00Z',
            updated_at: '2026-03-01T00:00:00Z',
            reply: null,
            attachments: [],
          },
        ]),
      });

      await testUtils.render();
      await waitFor(() => expect(screen.getByTestId('qna-tab')).toBeInTheDocument());

      expect(screen.queryByTestId('qna-updated-label-0')).not.toBeInTheDocument();

      testUtils.cleanup();
    });

    it('updated_at이 null이면 수정됨 레이블이 표시되지 않는다', async () => {
      const testUtils = createLayoutTest(makeUpdatedLabelFixture(), {
        componentRegistry: registry,
        initialState: { _local: { activeTab: 'qna' }, _global: boardSlugGlobal },
      });

      testUtils.mockApi('product', { response: { data: { id: 42 } } });
      testUtils.mockApi('qna', {
        response: makeQnaResponse([
          {
            id: 3,
            user_id: 10,
            author_name: '홍길동',
            title: '문의',
            content: '내용',
            is_secret: false,
            is_owner: false,
            is_answered: false,
            created_at: '2026-03-01T00:00:00Z',
            updated_at: null,
            reply: null,
            attachments: [],
          },
        ]),
      });

      await testUtils.render();
      await waitFor(() => expect(screen.getByTestId('qna-tab')).toBeInTheDocument());

      expect(screen.queryByTestId('qna-updated-label-0')).not.toBeInTheDocument();

      testUtils.cleanup();
    });
  });

  describe('삭제 버튼 모달 방식 검증 (Issue #208 개편)', () => {
    // 삭제 버튼 sequence+openModal 방식 검증 픽스처
    const makeDeleteModalFixture = () => ({
      version: '1.0.0',
      layout_name: 'shop/show',
      data_sources: [
        {
          id: 'product',
          type: 'api',
          endpoint: '/api/modules/sirsoft-ecommerce/products/42',
          method: 'GET',
          auto_fetch: true,
          loading_strategy: 'blocking',
        },
        {
          id: 'qna',
          type: 'api',
          endpoint: '/api/modules/sirsoft-ecommerce/products/42/inquiries',
          method: 'GET',
          auto_fetch: true,
          auth_mode: 'optional',
          loading_strategy: 'progressive',
          fallback: { items: [], meta: { current_page: 1, per_page: 10, total: 0, last_page: 1 } },
        },
      ],
      slots: {
        content: [
          {
            type: 'basic',
            name: 'Div',
            if: `{{(_local.activeTab ?? 'info') === 'qna' && !!_global.modules?.['sirsoft-ecommerce']?.inquiry?.board_slug}}`,
            props: { 'data-testid': 'qna-tab' },
            children: [
              {
                type: 'basic',
                name: 'Div',
                iteration: { source: '{{qna.data.items ?? []}}', item_var: 'item', index_var: 'idx' },
                children: [
                  {
                    type: 'basic',
                    name: 'Div',
                    if: '{{_local.qnaOpenId === item.id && item.is_owner && !item.is_answered}}',
                    props: { 'data-testid': 'qna-action-btns-{{idx}}' },
                    children: [
                      {
                        type: 'basic',
                        name: 'Button',
                        props: { 'data-testid': 'qna-delete-btn-{{idx}}', type: 'button' },
                        text: '삭제',
                        actions: [
                          {
                            type: 'click',
                            handler: 'sequence',
                            actions: [
                              {
                                handler: 'setState',
                                params: {
                                  target: 'global',
                                  qnaDeleteId: '{{item.id}}',
                                  qnaDeleteIsAnswered: '{{item.is_answered ?? false}}',
                                  qnaDeleteSource: 'product',
                                },
                              },
                              { handler: 'openModal', target: 'inquiry_delete_modal' },
                            ],
                          },
                        ],
                      },
                    ],
                  },
                ],
              },
            ],
          },
        ],
      },
    });

    it('삭제 버튼 클릭 시 qnaDeleteId 상태가 설정된다', async () => {
      const testUtils = createLayoutTest(makeDeleteModalFixture(), {
        componentRegistry: registry,
        initialState: {
          _local: { activeTab: 'qna', qnaOpenId: 1 },
          _global: boardSlugGlobal,
        },
      });

      testUtils.mockApi('product', { response: { data: { id: 42 } } });
      testUtils.mockApi('qna', {
        response: makeQnaResponse([
          {
            id: 1,
            user_id: 10,
            author_name: '홍길동',
            title: '삭제 테스트 문의',
            content: '삭제할 내용',
            is_secret: false,
            is_owner: true,
            is_answered: false,
            created_at: '2026-03-01T00:00:00Z',
            updated_at: '2026-03-01T00:00:00Z',
            reply: null,
            attachments: [],
          },
        ]),
      });

      await testUtils.render();
      await waitFor(() => expect(screen.getByTestId('qna-tab')).toBeInTheDocument());

      const deleteBtn = screen.getByTestId('qna-delete-btn-0');
      deleteBtn.click();

      await waitFor(() => {
        const state = testUtils.getState();
        expect(state._global.qnaDeleteId).toBe(1);
      });

      testUtils.cleanup();
    });

    it('삭제 버튼 클릭 시 qnaDeleteSource가 "product"로 설정된다', async () => {
      const testUtils = createLayoutTest(makeDeleteModalFixture(), {
        componentRegistry: registry,
        initialState: {
          _local: { activeTab: 'qna', qnaOpenId: 1 },
          _global: boardSlugGlobal,
        },
      });

      testUtils.mockApi('product', { response: { data: { id: 42 } } });
      testUtils.mockApi('qna', {
        response: makeQnaResponse([
          {
            id: 1,
            user_id: 10,
            author_name: '홍길동',
            title: '삭제 테스트 문의',
            content: '삭제할 내용',
            is_secret: false,
            is_owner: true,
            is_answered: false,
            created_at: '2026-03-01T00:00:00Z',
            updated_at: '2026-03-01T00:00:00Z',
            reply: null,
            attachments: [],
          },
        ]),
      });

      await testUtils.render();
      await waitFor(() => expect(screen.getByTestId('qna-tab')).toBeInTheDocument());

      const deleteBtn = screen.getByTestId('qna-delete-btn-0');
      deleteBtn.click();

      await waitFor(() => {
        const state = testUtils.getState();
        expect(state._global.qnaDeleteSource).toBe('product');
      });

      testUtils.cleanup();
    });
  });
});
