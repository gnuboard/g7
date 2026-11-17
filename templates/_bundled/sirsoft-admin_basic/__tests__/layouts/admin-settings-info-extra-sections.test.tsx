/**
 * @file admin-settings-info-extra-sections.test.tsx
 * @description 환경설정 > 정보 탭 확장 카드(일반 카드 렌더러) 렌더링 테스트 (#888)
 *
 * 테스트 대상: `_tab_info.json` 의 `card_extra_section_*` 노드 (실제 굽기 산출물을 그대로 렌더한다).
 * 확장은 필터 훅 `core.settings.filter_system_info_sections` 로 시스템 정보 응답의 `extra_sections` 에 카드를 더한다.
 * 서버가 형식을 검증해 넘기고(배지·항목·접는 목록·알림), 화면은 그 모양만 따른다.
 *
 * - 섹션 없음 → 카드를 하나도 그리지 않는다
 * - 배지·항목 → 제목·배지(색조 → StatusBadge 상태)·라벨/값, `mono` 값은 고정폭
 * - 목록 → 접지 않는 목록은 바로 보이고, 접는 목록은 단추를 눌러야 보인다
 * - 알림 → 색조별 알림 상자
 * - 값은 글자로만 그린다 — HTML 조각이 요소로 해석되지 않는다
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest, screen, fireEvent } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import infoTab from '../../layouts/partials/admin_settings/_tab_info.json';

type Props = { className?: string; children?: React.ReactNode; text?: string; 'data-testid'?: string };

const TestDiv: React.FC<Props> = ({ className, children, 'data-testid': testId }) => (
  <div className={className} data-testid={testId}>{children}</div>
);
const TestSpan: React.FC<Props> = ({ className, children, text }) => <span className={className}>{children || text}</span>;
const TestP: React.FC<Props> = ({ className, children, text }) => <p className={className}>{children || text}</p>;
const TestH3: React.FC<Props> = ({ className, children, text }) => <h3 className={className}>{children || text}</h3>;
const TestUl: React.FC<Props> = ({ className, children }) => <ul className={className}>{children}</ul>;
const TestLi: React.FC<Props> = ({ className, children, text }) => <li className={className}>{children || text}</li>;
const TestButton: React.FC<Props & { onClick?: (e: unknown) => void; 'aria-expanded'?: unknown }> = ({
  className,
  children,
  onClick,
  'aria-expanded': expanded,
}) => (
  <button type="button" className={className} onClick={onClick} aria-expanded={String(expanded)} data-testid="extra-list-toggle">
    {children}
  </button>
);
const TestIcon: React.FC<{ name?: string }> = ({ name }) => <i data-icon={name} />;
const TestStatusBadge: React.FC<{ status?: string; label?: string }> = ({ status, label }) => (
  <span data-testid="extra-badge" data-status={status}>{label}</span>
);
// 렌더러가 컴포넌트 목록을 Fragment 로 감싸므로 반드시 등록해야 한다 — 미등록이면 트리 전체가 빠져 「없다」 단언이 거짓 통과한다.
const TestFragment: React.FC<{ children?: React.ReactNode }> = ({ children }) => <>{children}</>;

function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  const basic = (name: string, component: React.FC<any>) => ({ component, metadata: { name, type: 'basic' } });

  (registry as any).registry = {
    Div: basic('Div', TestDiv),
    Span: basic('Span', TestSpan),
    P: basic('P', TestP),
    H3: basic('H3', TestH3),
    Ul: basic('Ul', TestUl),
    Li: basic('Li', TestLi),
    Button: basic('Button', TestButton),
    Icon: basic('Icon', TestIcon),
    StatusBadge: { component: TestStatusBadge, metadata: { name: 'StatusBadge', type: 'composite' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };

  return registry;
}

/**
 * 굽기 산출물에서 확장 카드 노드를 찾습니다.
 *
 * @param node 탐색할 노드
 * @returns 확장 카드 노드
 */
function findExtraSectionNode(node: any): any {
  if (!node || typeof node !== 'object') return null;
  if (typeof node.id === 'string' && node.id.startsWith('card_extra_section_')) return node;
  for (const value of Object.values(node)) {
    const found = findExtraSectionNode(value);
    if (found) return found;
  }
  return null;
}

const extraSectionNode = findExtraSectionNode(infoTab);

/**
 * 확장 카드 노드 하나와 렌더 확인용 앵커만 둔 시험 레이아웃을 만듭니다.
 */
function buildLayout() {
  return {
    version: '1.0.0',
    layout_name: 'test_admin_settings_info_extra_sections',
    data_sources: [
      {
        id: 'systemInfo',
        type: 'api',
        endpoint: '/api/admin/settings/system-info',
        method: 'GET',
        auto_fetch: true,
        auth_required: true,
        fallback: { data: {} },
      },
    ],
    components: [
      { id: 'anchor', type: 'basic', name: 'Div', props: { 'data-testid': 'anchor' } },
      extraSectionNode,
    ],
  };
}

/**
 * 시스템 정보 응답을 만듭니다.
 *
 * @param sections extra_sections 값
 */
function systemInfoResponse(sections: unknown[]) {
  return { success: true, data: { php_version: '8.3.0', extra_sections: sections } };
}

describe('admin-settings 정보 탭 — 확장 카드 일반 렌더러 (#888)', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  afterEach(() => {
    (registry as any).registry = {};
  });

  // @scenario section_shape=none
  // @effects extra_section_card_node_baked
  it('굽기 산출물에 확장 카드 노드가 있다', () => {
    expect(extraSectionNode).toBeTruthy();
    expect(extraSectionNode.iteration?.source).toBe('{{systemInfo?.data?.extra_sections ?? []}}');
  });

  // @scenario section_shape=none
  // @effects extra_sections_render_nothing_when_empty
  it('섹션이 없으면 카드를 그리지 않는다', async () => {
    const testUtils = createLayoutTest(buildLayout());
    testUtils.mockApi('systemInfo', { response: systemInfoResponse([]) });

    const { container } = await testUtils.render();

    expect(screen.getByTestId('anchor')).toBeTruthy();
    expect(container.querySelectorAll('.admin-card')).toHaveLength(0);

    testUtils.cleanup();
  });

  // @scenario section_shape=badge_rows
  // @effects extra_sections_render_badge_and_rows
  it('배지와 항목을 그리고, 중립 색조는 기본 배지로, mono 값은 고정폭으로 그린다', async () => {
    const testUtils = createLayoutTest(buildLayout());
    testUtils.mockApi('systemInfo', {
      response: systemInfoResponse([
        {
          id: 'acme-server_status.overview',
          title: '서버 상태',
          badge: { text: '정상', tone: 'success' },
          rows: [
            { label: '큐 대기', value: '3건', mono: false },
            { label: '호스트 키', value: 'ab12cd', mono: true },
          ],
          list: null,
          notice: null,
        },
        {
          id: 'acme-server_status.disk',
          title: '디스크',
          badge: { text: '참고', tone: 'neutral' },
          rows: [],
          list: null,
          notice: null,
        },
      ]),
    });

    const { container } = await testUtils.render();

    const cards = container.querySelectorAll('.admin-card');
    expect(cards).toHaveLength(2);
    expect(cards[0].querySelector('h3')?.textContent).toBe('서버 상태');
    expect(cards[0].textContent).toContain('큐 대기');
    expect(cards[0].textContent).toContain('3건');

    const badges = screen.getAllByTestId('extra-badge');
    expect(badges.map((b) => [b.getAttribute('data-status'), b.textContent])).toEqual([
      ['success', '정상'],
      ['default', '참고'],
    ]);

    const monoValue = Array.from(cards[0].querySelectorAll('span')).find((s) => s.textContent === 'ab12cd');
    expect(monoValue?.className).toContain('font-mono');
    const plainValue = Array.from(cards[0].querySelectorAll('span')).find((s) => s.textContent === '3건');
    expect(plainValue?.className).not.toContain('font-mono');

    testUtils.cleanup();
  });

  // @scenario section_shape=list
  // @effects extra_sections_render_collapsible_list
  it('접지 않는 목록은 바로 보이고, 접는 목록은 단추를 눌러야 항목이 보인다', async () => {
    const testUtils = createLayoutTest(buildLayout());
    testUtils.mockApi('systemInfo', {
      response: systemInfoResponse([
        {
          id: 'acme-server_status.open',
          title: '열린 목록',
          badge: null,
          rows: [],
          list: { title: '항상 보임', items: ['항목 A', '항목 B'], collapsible: false },
          notice: null,
        },
        {
          id: 'acme-server_status.folded',
          title: '접힌 목록',
          badge: null,
          rows: [],
          list: { title: '최근 경고', items: ['경고 1', '경고 2', '경고 3'], collapsible: true },
          notice: null,
        },
      ]),
    });

    const { container } = await testUtils.render();

    const cards = container.querySelectorAll('.admin-card');
    expect(cards).toHaveLength(2);
    expect(Array.from(cards[0].querySelectorAll('li')).map((li) => li.textContent)).toEqual(['항목 A', '항목 B']);
    expect(cards[1].querySelectorAll('li')).toHaveLength(0);

    const toggle = screen.getByTestId('extra-list-toggle');
    expect(toggle.textContent).toContain('최근 경고 (3)');
    expect(toggle.getAttribute('aria-expanded')).toBe('false');

    fireEvent.click(toggle);
    await testUtils.rerender();

    const foldedCard = container.querySelectorAll('.admin-card')[1];
    expect(Array.from(foldedCard.querySelectorAll('li')).map((li) => li.textContent)).toEqual(['경고 1', '경고 2', '경고 3']);
    expect(testUtils.getState()._local.infoExtraOpenIds).toEqual(['acme-server_status.folded']);

    testUtils.cleanup();
  });

  // @scenario section_shape=notice
  // @effects extra_sections_render_notice_by_tone, extra_sections_render_text_only
  it('알림은 색조별 상자로 그리고, 값의 HTML 은 해석하지 않고 글자로 그린다', async () => {
    const testUtils = createLayoutTest(buildLayout());
    testUtils.mockApi('systemInfo', {
      response: systemInfoResponse([
        {
          id: 'acme-server_status.alert',
          title: '경고',
          badge: null,
          rows: [{ label: '메모', value: '<b>굵게</b><script>x()</script>', mono: false }],
          list: null,
          notice: { tone: 'warning', text: '디스크가 90% 찼습니다' },
        },
        {
          id: 'acme-server_status.danger',
          title: '위험',
          badge: null,
          rows: [],
          list: null,
          notice: { tone: 'danger', text: '백업 실패' },
        },
      ]),
    });

    const { container } = await testUtils.render();

    const warning = container.querySelector('.alert-warning');
    expect(warning?.textContent).toBe('디스크가 90% 찼습니다');
    expect(container.querySelector('.alert-danger')?.textContent).toBe('백업 실패');

    const firstCard = container.querySelectorAll('.admin-card')[0];
    expect(firstCard.textContent).toContain('<b>굵게</b><script>x()</script>');
    expect(firstCard.querySelector('b')).toBeNull();
    expect(firstCard.querySelector('script')).toBeNull();

    testUtils.cleanup();
  });
});
