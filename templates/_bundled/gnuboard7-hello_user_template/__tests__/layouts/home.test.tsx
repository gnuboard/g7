/**
 * @file home.test.tsx
 * @description Hello User Template 홈 레이아웃 렌더링 테스트
 *
 * 테스트 대상:
 * - templates/_bundled/gnuboard7-hello_user_template/layouts/home.json
 *
 * 검증 항목:
 * - 환영 카드 제목 렌더링
 * - memos data_source 를 iteration 으로 렌더링
 * - 메모 2건이 화면에 제목으로 표시됨
 * - 홈으로 돌아가기 버튼 존재
 * - 빈 메모 상태 분기 (조건부 렌더링)
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect, afterEach } from 'vitest';
import {
  createLayoutTest,
  screen,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

// ========== 테스트용 컴포넌트 (Basic 8종) ==========

const TestDiv: React.FC<{
  className?: string;
  children?: React.ReactNode;
  'data-testid'?: string;
}> = ({ className, children, 'data-testid': testId }) => (
  <div className={className} data-testid={testId}>{children}</div>
);

const TestButton: React.FC<{
  className?: string;
  children?: React.ReactNode;
  onClick?: (e: React.MouseEvent) => void;
  'data-testid'?: string;
}> = ({ className, children, onClick, 'data-testid': testId }) => (
  <button type="button" className={className} onClick={onClick} data-testid={testId}>
    {children}
  </button>
);

const TestH1: React.FC<{ className?: string; children?: React.ReactNode }> = ({
  className,
  children,
}) => <h1 className={className}>{children}</h1>;

const TestH2: React.FC<{ className?: string; children?: React.ReactNode }> = ({
  className,
  children,
}) => <h2 className={className}>{children}</h2>;

const TestH3: React.FC<{ className?: string; children?: React.ReactNode }> = ({
  className,
  children,
}) => <h3 className={className}>{children}</h3>;

const TestA: React.FC<{
  href?: string;
  className?: string;
  children?: React.ReactNode;
}> = ({ href, className, children }) => (
  <a href={href} className={className}>{children}</a>
);

const TestSpan: React.FC<{ className?: string; children?: React.ReactNode }> = ({
  className,
  children,
}) => <span className={className}>{children}</span>;

const TestImg: React.FC<{ src?: string; alt?: string; className?: string }> = ({
  src,
  alt,
  className,
}) => <img src={src} alt={alt} className={className} />;

const TestFragment: React.FC<{ children?: React.ReactNode }> = ({ children }) => (
  <>{children}</>
);

function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    H1: { component: TestH1, metadata: { name: 'H1', type: 'basic' } },
    H2: { component: TestH2, metadata: { name: 'H2', type: 'basic' } },
    H3: { component: TestH3, metadata: { name: 'H3', type: 'basic' } },
    A: { component: TestA, metadata: { name: 'A', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    Img: { component: TestImg, metadata: { name: 'Img', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };
  return registry;
}

// ========== home 레이아웃 JSON 로드 ==========

import homeLayout from '../../layouts/home.json';
import baseLayout from '../../layouts/_user_base.json';

/**
 * home.json 은 `extends: "_user_base"` 로 정의되어 `slots.content` 에
 * 실제 페이지 콘텐츠가 담긴다. createLayoutTest() 는 별도 LayoutResolver 를
 * 태우지 않으므로 아래와 같이 슬롯 내용을 `components` 최상위로 끌어올린
 * "평탄화된 테스트 레이아웃" 을 구성하여 검증한다. home.json 의 data_sources
 * 와 slots.content 를 그대로 재사용하므로 실제 레이아웃의 회귀 검증 효과는
 * 동일하다.
 */
function flattenHomeLayoutForTest() {
  const h = homeLayout as any;
  return {
    version: h.version,
    layout_name: 'home_test_flattened',
    meta: h.meta,
    data_sources: h.data_sources,
    components: h.slots?.content ?? [],
  };
}

// ========== 다국어 ==========

const translations = {
  user: {
    base_layout_title: 'Hello 사용자 템플릿',
    base_layout_description: '학습용 샘플',
    header: { home_link: 'Hello', tagline: '학습용 최소 샘플' },
    footer: { copyright: '© gnuboard7' },
    nav: { home: '홈' },
    home: {
      page_title: '홈',
      page_description: '홈 설명',
      welcome_title: '환영합니다!',
      welcome_description: '이 화면은 학습용 샘플입니다.',
      memos_title: '최근 메모',
      memos_empty: '표시할 메모가 없습니다.',
      back_to_home: '홈으로',
    },
  },
};

// ========== 테스트 ==========

describe('home 레이아웃 (gnuboard7-hello_user_template)', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;

  afterEach(() => {
    testUtils?.cleanup();
  });

  it('환영 카드가 렌더링된다', async () => {
    const registry = setupTestRegistry();
    testUtils = createLayoutTest(flattenHomeLayoutForTest() as any, {
      componentRegistry: registry,
      translations,
      locale: 'ko',
    });
    testUtils.mockApi('memos', {
      response: { data: { data: [] } },
    });

    await testUtils.render();

    expect(screen.getByText('환영합니다!')).toBeInTheDocument();
    expect(
      screen.getByText('이 화면은 학습용 샘플입니다.'),
    ).toBeInTheDocument();
  });

  it('memos 데이터소스의 항목이 iteration 으로 렌더링된다', async () => {
    const registry = setupTestRegistry();
    testUtils = createLayoutTest(flattenHomeLayoutForTest() as any, {
      componentRegistry: registry,
      translations,
      locale: 'ko',
    });
    testUtils.mockApi('memos', {
      response: {
        data: {
          data: [
            { id: 1, title: '첫 번째 메모', content: '안녕하세요' },
            { id: 2, title: '두 번째 메모', content: '반갑습니다' },
          ],
        },
      },
    });

    await testUtils.render();

    expect(screen.getByText('첫 번째 메모')).toBeInTheDocument();
    expect(screen.getByText('두 번째 메모')).toBeInTheDocument();
    expect(screen.getByText('안녕하세요')).toBeInTheDocument();
    expect(screen.getByText('반갑습니다')).toBeInTheDocument();
  });

  it('memos 가 비어 있으면 빈 상태 메시지가 표시된다', async () => {
    const registry = setupTestRegistry();
    testUtils = createLayoutTest(flattenHomeLayoutForTest() as any, {
      componentRegistry: registry,
      translations,
      locale: 'ko',
    });
    testUtils.mockApi('memos', {
      response: { data: { data: [] } },
    });

    await testUtils.render();

    expect(screen.getByText('표시할 메모가 없습니다.')).toBeInTheDocument();
  });

  it('홈으로 돌아가기 버튼이 렌더링된다', async () => {
    const registry = setupTestRegistry();
    testUtils = createLayoutTest(flattenHomeLayoutForTest() as any, {
      componentRegistry: registry,
      translations,
      locale: 'ko',
    });
    testUtils.mockApi('memos', {
      response: { data: { data: [] } },
    });

    await testUtils.render();

    expect(screen.getByTestId('home-button')).toBeInTheDocument();
    expect(screen.getByText('홈으로')).toBeInTheDocument();
  });

  it('home 레이아웃이 _user_base 를 extends 한다', () => {
    expect((homeLayout as any).extends).toBe('_user_base');
    expect((baseLayout as any).layout_name).toBe('_user_base');
  });
});
