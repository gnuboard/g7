/**
 * @file admin-settings-trusted-proxy-diagnostic.test.tsx
 * @description 환경설정 > 고급 의 리버스 프록시 진단 블록 (#124 W8 ②)
 *
 * 이 블록은 **읽기 전용**이다. 값 편집을 화면에 두지 않는 이유는 둘이다 —
 * ① 프록시 뒤에서는 관리자 화면 자체가 뜨지 않는 것이 이 결함이므로 정작 필요한
 *    순간에 그 화면에 도달할 수 없다(잠금 역설),
 * ② 웹에서 편집 가능해지면 관리자 계정 탈취가 곧 X-Forwarded-For 위조 경로가 된다.
 *
 * 그래서 입력 컨트롤이 0개라는 사실이 계약이고, 이 테스트가 그것을 잠근다. 값 자체는
 * 서버 진단(App\Support\TrustedProxyDiagnostic)이 유일한 판정자이므로 화면은 그것을
 * 표시만 한다 — 화면에서 조건을 다시 쓰면 서버와 다른 답을 내놓게 된다.
 *
 * 합성 레이아웃이 아니라 **실제 _tab_advanced.json** 을 읽는다.
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

const advancedPartial = JSON.parse(
  readFileSync(resolve(__dirname, '../../layouts/partials/admin_settings/_tab_advanced.json'), 'utf-8')
);

const TestDiv: React.FC<any> = ({ id, className, children }) => (
  <div id={id} className={className}>
    {children}
  </div>
);
const TestSpan: React.FC<any> = ({ id, className, children, text }) => (
  <span id={id} className={className}>
    {children || text}
  </span>
);
const TestH3: React.FC<any> = ({ id, className, children, text }) => (
  <h3 id={id} className={className}>
    {children || text}
  </h3>
);
const TestInput: React.FC<any> = ({ name, type }) => <input name={name} type={type} />;
const TestToggle: React.FC<any> = ({ name }) => <input type="checkbox" role="switch" name={name} />;
const TestSelect: React.FC<any> = ({ name }) => <select name={name} />;
const TestTextarea: React.FC<any> = ({ name }) => <textarea name={name} />;
const TestButton: React.FC<any> = ({ children, text }) => <button type="button">{children || text}</button>;
const TestA: React.FC<any> = ({ id, href, target, rel, className, children, text }) => (
  <a id={id} href={href} target={target} rel={rel} className={className}>
    {children || text}
  </a>
);
const TestFragment: React.FC<any> = ({ children }) => <>{children}</>;

/**
 * 테스트용 컴포넌트 레지스트리를 구성합니다.
 *
 * 입력 계열을 **모두 등록**한다 — 등록하지 않으면 렌더되지 않아 "컨트롤 0개" 가
 * 계약 준수가 아니라 미등록 때문에 통과한다.
 *
 * @returns 구성된 레지스트리
 */
function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();

  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    H3: { component: TestH3, metadata: { name: 'H3', type: 'basic' } },
    Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
    Toggle: { component: TestToggle, metadata: { name: 'Toggle', type: 'composite' } },
    Select: { component: TestSelect, metadata: { name: 'Select', type: 'basic' } },
    Textarea: { component: TestTextarea, metadata: { name: 'Textarea', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    A: { component: TestA, metadata: { name: 'A', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };

  return registry;
}

/**
 * id 로 노드를 깊이 우선 탐색합니다.
 *
 * @param node 탐색 시작 노드
 * @param id 찾을 노드 id
 * @returns 찾은 노드 또는 null
 */
function findNodeById(node: any, id: string): any {
  if (!node || typeof node !== 'object') return null;
  if (node.id === id) return node;
  for (const child of node.children ?? []) {
    const found = findNodeById(child, id);
    if (found) return found;
  }
  return null;
}

/**
 * partial 루트들에서 id 노드를 찾습니다.
 *
 * @param id 찾을 노드 id
 * @returns 찾은 노드 또는 null
 */
function findInPartial(id: string): any {
  for (const root of advancedPartial.components ?? [advancedPartial]) {
    const found = findNodeById(root, id);
    if (found) return found;
  }
  return null;
}

/**
 * 주어진 서버 진단 응답으로 카드를 렌더합니다.
 *
 * @param diagnostic 서버가 내려준 진단 결과
 * @returns 레이아웃 테스트 유틸
 */
function renderCard(diagnostic: Record<string, unknown>) {
  const card = findInPartial('card_trusted_proxy');
  expect(card, 'card_trusted_proxy 노드가 _tab_advanced.json 에 없습니다').not.toBeNull();

  const testUtils = createLayoutTest({
    version: '1.0.0',
    layout_name: 'test_trusted_proxy_diagnostic',
    data_sources: [
      {
        id: 'trustedProxy',
        type: 'api',
        endpoint: '/api/admin/settings/trusted-proxy',
        method: 'GET',
        auto_fetch: true,
        auth_required: true,
      },
    ],
    components: [card],
  } as any);

  testUtils.mockApi('trustedProxy', { response: { data: diagnostic } });

  return testUtils;
}

/** 프록시 헤더는 받는데 신뢰 설정이 없는 상태 (조치 필요) */
const WARNING = {
  forwarded_headers: ['X-Forwarded-For', 'X-Forwarded-Proto'],
  trusted_configured: false,
  configured_proxies: null,
  is_secure: false,
  client_ip: '10.0.0.5',
  remote_addr: '10.0.0.5',
  status: 'warning',
};

/** 신뢰 설정이 있는 정상 상태 */
const OK = {
  forwarded_headers: ['X-Forwarded-For', 'X-Forwarded-Proto'],
  trusted_configured: true,
  configured_proxies: '*',
  is_secure: true,
  client_ip: '203.0.113.77',
  remote_addr: '10.0.0.5',
  status: 'ok',
};

describe('환경설정 고급 — 리버스 프록시 진단 블록', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  afterEach(() => {
    (registry as any).registry = {};
  });

  it('입력 컨트롤을 하나도 렌더하지 않는다 (읽기 전용 계약)', async () => {
    const testUtils = renderCard(WARNING);
    await testUtils.render();

    // 블록이 실제로 렌더됐음을 먼저 확정한다 — 그래야 아래 부재 단언이 의미를 갖는다.
    expect(document.querySelector('#trusted_proxy_status_badge')).not.toBeNull();

    expect(
      document.querySelectorAll('input, select, textarea').length,
      '값 편집은 .env 전용입니다 — 화면에 편집 컨트롤을 두지 않습니다'
    ).toBe(0);

    testUtils.cleanup();
  });

  it('문서 안내가 실제로 클릭 가능한 GitHub 링크다', async () => {
    const testUtils = renderCard(WARNING);
    await testUtils.render();

    const link = document.querySelector('#trusted_proxy_doc_link') as HTMLAnchorElement | null;
    expect(link, '문서 링크가 렌더되지 않았다').not.toBeNull();
    expect(link!.getAttribute('href')).toBe(
      'https://github.com/gnuboard/g7/blob/main/docs/backend/reverse-proxy.md',
    );
    // 관리자 화면을 떠나지 않도록 새 탭으로 열고, opener 를 넘기지 않는다.
    expect(link!.getAttribute('target')).toBe('_blank');
    expect(link!.getAttribute('rel')).toBe('noopener noreferrer');

    // 링크는 읽기 전용 계약을 깨지 않는다 (입력 컨트롤이 아니다)
    expect(document.querySelectorAll('input, select, textarea').length).toBe(0);

    testUtils.cleanup();
  });

  it('경고 상태에서는 방문자 IP 와 직전 호출 IP 를 나란히 보여 준다', async () => {
    const testUtils = renderCard(WARNING);
    await testUtils.render();

    const text = document.body.textContent ?? '';

    // 두 값이 같으면서 프록시 헤더를 받고 있는 상태 = 모든 방문자가 한 사람으로 기록된다.
    expect(text).toContain('10.0.0.5');
    expect(text).toContain('X-Forwarded-For');
    expect(document.querySelector('#trusted_proxy_same_ip_hint'), '동일 IP 안내가 표시되지 않았습니다').not.toBeNull();

    testUtils.cleanup();
  });

  it('정상 상태에서는 설정값을 보여 주고 동일 IP 안내를 감춘다', async () => {
    const testUtils = renderCard(OK);
    await testUtils.render();

    const text = document.body.textContent ?? '';

    expect(text).toContain('203.0.113.77');
    expect(document.querySelector('#trusted_proxy_same_ip_hint'), '정상 상태에 동일 IP 안내가 떴습니다').toBeNull();

    testUtils.cleanup();
  });

  it('상태 배지는 서버가 준 status 를 그대로 따른다', async () => {
    const warning = renderCard(WARNING);
    await warning.render();
    const warningClass = document.querySelector('#trusted_proxy_status_badge')?.getAttribute('class') ?? '';
    expect(warningClass).toContain('bg-amber-100');
    warning.cleanup();

    const ok = renderCard(OK);
    await ok.render();
    const okClass = document.querySelector('#trusted_proxy_status_badge')?.getAttribute('class') ?? '';
    expect(okClass).toContain('bg-green-100');
    ok.cleanup();
  });
});
