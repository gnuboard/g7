/**
 * version-content-render.test.tsx — 버전 content 레이아웃 렌더링 테스트
 *
 * 버전 히스토리의 복원·미리보기는 저장된 버전의 `content`(= 일반 레이아웃 JSON)를
 * 다룬다. 복원은 그 content 를 레이아웃에 적용해 캔버스를 재로드하고, 미리보기는 그 content 를
 * `/preview/{token}` 으로 실제 렌더한다. 본 테스트는 "버전에 저장된 content 가 일반 레이아웃과
 * 동일하게 DynamicRenderer 로 정상 렌더된다"는 S11 의 전제를 `createLayoutTest()` 실제 렌더
 * 라운드트립으로 잠근다(단위 시뮬레이션이 아닌 실 렌더).
 *
 * 모달 UI 자체(VersionHistoryModal/VersionDiffView)는 EditorModalContext 가 렌더하는 편집기
 * React 컴포넌트라 RTL 컴포넌트 테스트(__tests__/components/*)로 커버한다 — 본 파일은 그 모달이
 * 다루는 "레이아웃 content 의 렌더 가능성"을 보완한다.
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest, screen } from '../../../__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '../../../ComponentRegistry';

/** 테스트용 Div — children 컨테이너. */
const TestDiv: React.FC<{ children?: React.ReactNode; 'data-testid'?: string }> = ({
  children,
  'data-testid': testId,
}) => <div data-testid={testId}>{children}</div>;

/** 테스트용 Span — text/children 표시. */
const TestSpan: React.FC<{
  text?: string;
  children?: React.ReactNode;
  'data-testid'?: string;
}> = ({ text, children, 'data-testid': testId }) => (
  <span data-testid={testId}>{children || text}</span>
);

/** Fragment — DynamicRenderer 가 렌더 트리 wrapper 로 사용(미등록 시 빈 렌더). */
const TestFragment: React.FC<{ children?: React.ReactNode }> = ({ children }) => <>{children}</>;

/** Div/Span/Fragment 를 ComponentRegistry 에 등록한 레지스트리를 만든다(pageStateSimulation 패턴). */
function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  (registry as unknown as { registry: Record<string, unknown> }).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    // Fragment 필수 — DynamicRenderer 의 렌더 트리 wrapper. 누락 시 빈 렌더가 된다.
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };
  return registry;
}

/** 버전 v1 에 저장된 content (작은 레이아웃 — Span 1개). */
const versionContentV1 = {
  version: '1.0.0',
  endpoint: null,
  data_sources: [],
  metadata: { title: 'Cart v1' },
  components: [
    {
      id: 'cart-root-node',
      type: 'basic',
      name: 'Div',
      props: { 'data-testid': 'cart-root' },
      children: [
        { id: 'cart-title-node', type: 'basic', name: 'Span', text: '장바구니', props: { 'data-testid': 'cart-title' } },
      ],
    },
  ],
};

/** 버전 v2 에 저장된 content (Span 1개 추가 — 복원 대상 비교용). */
const versionContentV2 = {
  version: '1.0.0',
  endpoint: null,
  data_sources: [],
  metadata: { title: 'Cart v2' },
  components: [
    {
      id: 'cart-root-node',
      type: 'basic',
      name: 'Div',
      props: { 'data-testid': 'cart-root' },
      children: [
        { id: 'cart-title-node', type: 'basic', name: 'Span', text: '장바구니', props: { 'data-testid': 'cart-title' } },
        { id: 'cart-extra-node', type: 'basic', name: 'Span', text: '재주문', props: { 'data-testid': 'cart-extra' } },
      ],
    },
  ],
};

/** data_sources 를 가진 버전 content (미리보기 시 데이터 바인딩 렌더 검증). */
const versionContentWithData = {
  version: '1.0.0',
  endpoint: null,
  metadata: { title: 'Cart with data' },
  data_sources: [{ id: 'cart', type: 'api', endpoint: '/api/cart', auto_fetch: true }],
  components: [
    {
      id: 'cart-root-node',
      type: 'basic',
      name: 'Div',
      props: { 'data-testid': 'cart-root' },
      children: [
        {
          id: 'cart-bound-node',
          type: 'basic',
          name: 'Span',
          text: '{{cart?.data?.title ?? ""}}',
          props: { 'data-testid': 'cart-bound-title' },
        },
      ],
    },
  ],
};

describe('버전 content 레이아웃 렌더링', () => {
  let registry: ComponentRegistry;
  let active: ReturnType<typeof createLayoutTest> | null = null;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  afterEach(() => {
    active?.cleanup();
    active = null;
  });

  it('복원/미리보기 대상 버전 content(v1)가 DynamicRenderer 로 정상 렌더된다', async () => {
    active = createLayoutTest(versionContentV1, { componentRegistry: registry });
    await active.render();

    // 버전에 저장된 content 의 컴포넌트가 실제 DOM 으로 렌더됨
    expect(screen.getByTestId('cart-root')).toBeInTheDocument();
    expect(screen.getByTestId('cart-title')).toHaveTextContent('장바구니');
  });

  it('다른 버전 content(v2)로 교체해도 렌더가 정합한다 (복원 시나리오)', async () => {
    // 복원은 다른 버전의 content 로 캔버스를 재로드한다 — v2 content 도 정상 렌더되어야 함.
    active = createLayoutTest(versionContentV2, { componentRegistry: registry });
    await active.render();

    expect(screen.getByTestId('cart-title')).toHaveTextContent('장바구니');
    // v2 에 추가된 Span 도 렌더됨 (v1→v2 의 +1 라인 변경이 실제 노드로 반영)
    expect(screen.getByTestId('cart-extra')).toHaveTextContent('재주문');
  });

  it('data_sources 를 가진 버전 content 가 데이터 바인딩과 함께 렌더된다 (미리보기)', async () => {
    active = createLayoutTest(versionContentWithData, { componentRegistry: registry });
    active.mockApi('cart', { response: { data: { title: '주문 요약' } } });
    await active.render();

    // 미리보기는 저장된 content 를 실데이터로 렌더 — data_sources 바인딩이 해석되어야 함.
    expect(await screen.findByTestId('cart-bound-title')).toHaveTextContent('주문 요약');
  });
});
