/**
 * @file admin-user-detail-search-history.test.tsx
 * @description 관리자 회원 상세·수정 「최근 검색어」 구획 (코어 7.1.5)
 *
 * 최근 검색어는 회원 정보라 회원 조회에서 볼 수 있어야 한다. 이 구획이 지키는 계약:
 *  - 코어 범위(`core.site`)만 그린다 — 상품·게시글 검색처럼 확장이 선언한 범위는 그 확장이 자기 탭에 그린다
 *    (모듈이 소유하는 회원 데이터는 모듈 탭, 설계 결정 2026-09-21).
 *  - 값은 상세 응답에만 실리는 `search_history`(범위 id 가 키)를 읽는다. 0건이어도 자리를 비우지 않는다.
 *  - 회원이 자동 저장을 끄거나 사이트가 기능을 꺼도 저장된 검색어는 보이고, 그 상태를 함께 알린다.
 *  - 수정 화면에도 같은 자리에 잠긴 채로 선다(상세 ↔ 수정 정합 — 고칠 수 있는 값이 아니다).
 *
 * 렌더는 실제 엔진(createLayoutTest)으로 한다 — 키에 점이 든 범위를 `scopes['core.site']` 로 읽는 식이 실제로 풀리는지 본다.
 *
 * @scenario-manifest tests/scenarios/search-history.yaml
 */

import React from 'react';
import { describe, it, expect, afterEach } from 'vitest';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

const detail = require('../../layouts/admin_user_detail.json');
const form = require('../../layouts/admin_user_form.json');

const pick = (node: any, id: string): any => {
  if (!node || typeof node !== 'object') return null;
  if (Array.isArray(node)) {
    for (const child of node) {
      const hit = pick(child, id);
      if (hit) return hit;
    }
    return null;
  }
  if (node.id === id) return node;
  for (const value of Object.values(node)) {
    const hit = pick(value, id);
    if (hit) return hit;
  }
  return null;
};

const TestDiv: React.FC<any> = ({ className, children, text }) => <div className={className}>{children || text}</div>;
const TestSpan: React.FC<any> = ({ className, children, text }) => <span className={className}>{children || text}</span>;
const TestH3: React.FC<any> = ({ className, children, text }) => <h3 className={className}>{children || text}</h3>;
const TestLabel: React.FC<any> = ({ className, children, text }) => <label className={className}>{children || text}</label>;
const TestUl: React.FC<any> = ({ className, children }) => <ul className={className}>{children}</ul>;
const TestLi: React.FC<any> = ({ className, children }) => <li className={className}>{children}</li>;
const TestIcon: React.FC<any> = ({ className }) => <i className={className} />;
const TestFragment: React.FC<any> = ({ children }) => <>{children}</>;
const TestInput: React.FC<any> =({ value, disabled }) => <input data-testid="locked-input" value={value ?? ''} disabled={disabled} readOnly />;

function setupRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    H3: { component: TestH3, metadata: { name: 'H3', type: 'basic' } },
    Label: { component: TestLabel, metadata: { name: 'Label', type: 'basic' } },
    Ul: { component: TestUl, metadata: { name: 'Ul', type: 'basic' } },
    Li: { component: TestLi, metadata: { name: 'Li', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
    // 시험 도구가 뿌리를 Fragment 로 감싼다 — 빠뜨리면 빈 컨테이너만 남아 부정 단언이 거짓 통과한다
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };
  return registry;
}

const translations = {
  admin: {
    users: {
      detail: {
        tabs: { search_history_info: '최근 검색어' },
        search_history: {
          auto_save: '검색어 자동 저장',
          auto_save_on: '켜짐',
          auto_save_off: '꺼짐 — 새 검색어를 기록하지 않습니다',
          feature_off: '사이트에서 최근 검색어 기능을 끈 상태입니다. 저장돼 있던 검색어만 보입니다.',
          keywords: '검색어',
          count: '{count}건',
          empty: '저장된 검색어가 없습니다.',
        },
      },
    },
  },
};

/** 상세 응답 모양의 `search_history` */
const history = (overrides: Record<string, any> = {}) => ({
  enabled: true,
  auto_save: true,
  scopes: {
    'core.site': {
      owner: 'core',
      label: '통합 검색',
      items: [
        { keyword: '텐트 폴대', searched_at: '2026-11-04 11:00' },
        { keyword: '등산화', searched_at: '2026-11-04 10:00' },
      ],
    },
    'sirsoft-ecommerce.products': {
      owner: 'module:sirsoft-ecommerce',
      label: '상품 검색',
      items: [{ keyword: '캠핑 의자', searched_at: '2026-11-04 09:00' }],
    },
  },
  ...overrides,
});

async function renderSection(layout: any, searchHistory: any) {
  // 수정 화면 구획은 등록 모드를 닫는 `route?.id` 조건을 단다 — 구획 자체를 그리려고 그 조건만 뗀다
  const { if: _routeGate, ...section } = pick(layout, 'section_search_history_info');
  setupRegistry();
  const test = createLayoutTest(
    { version: '1.0.0', layout_name: 'search_history_section', components: [section] } as any,
    {
      translations,
      locale: 'ko',
      routeParams: { id: 'u-1' },
      initialData: { user: { data: { uuid: 'u-1', search_history: searchHistory } }, $templateId: 'test-template', $locale: 'ko' },
    },
  );
  await test.render();
  return test;
}

let cleanup: (() => void) | null = null;
afterEach(() => {
  cleanup?.();
  cleanup = null;
});

describe('관리자 회원 상세 「최근 검색어」 구획', () => {
  // @scenario case=admin_user_detail
  // @effects admin_detail_core_section_shows_core_scope_only
  it('통합 검색 검색어를 받은 순서(최신순)대로 일시와 함께 그리고, 확장 범위 검색어는 그리지 않는다', async () => {
    const test = await renderSection(detail, history());
    cleanup = test.cleanup;

    const items = screen.getAllByRole('listitem').map((li) => li.textContent);
    expect(items).toEqual(['텐트 폴대2026-11-04 11:00', '등산화2026-11-04 10:00']);
    expect(screen.getByText('통합 검색 · 2건')).toBeTruthy();
    expect(screen.getByText('켜짐')).toBeTruthy();
    expect(screen.queryByText('캠핑 의자')).toBeNull();
    expect(screen.queryByText(/기능을 끈 상태/)).toBeNull();
  });

  // @scenario case=admin_user_detail
  // @effects admin_detail_shows_stored_rows_when_saving_is_off
  it('자동 저장을 끈 회원·기능을 끈 사이트에서도 저장된 검색어를 보이고 그 상태를 알린다', async () => {
    const test = await renderSection(detail, history({ enabled: false, auto_save: false }));
    cleanup = test.cleanup;

    expect(screen.getAllByRole('listitem')).toHaveLength(2);
    expect(screen.getByText('꺼짐 — 새 검색어를 기록하지 않습니다')).toBeTruthy();
    expect(screen.getByText(/기능을 끈 상태입니다/)).toBeTruthy();
  });

  // @scenario case=admin_user_detail
  // @effects admin_detail_empty_scope_keeps_section
  it('검색어가 없어도 구획을 비우지 않고 「없음」을 말한다', async () => {
    const empty = history();
    empty.scopes['core.site'].items = [];
    const test = await renderSection(detail, empty);
    cleanup = test.cleanup;

    expect(screen.queryAllByRole('listitem')).toHaveLength(0);
    expect(screen.getByText('저장된 검색어가 없습니다.')).toBeTruthy();
    expect(screen.getByText('통합 검색 · 0건')).toBeTruthy();
  });

  // @scenario case=admin_user_detail
  // @effects admin_detail_empty_scope_keeps_section
  it('회원 응답을 받는 중(search_history 없음)에는 「없음」을 말하지 않는다', async () => {
    const test = await renderSection(detail, undefined);
    cleanup = test.cleanup;

    expect(screen.queryByText('저장된 검색어가 없습니다.')).toBeNull();
    expect(screen.queryAllByRole('listitem')).toHaveLength(0);
  });

  // @scenario case=admin_user_detail
  // @effects admin_form_search_history_section_locked
  it('수정 화면에도 같은 검색어를 잠긴 채로 보인다 — 입력칸은 disabled', async () => {
    const test = await renderSection(form, history({ auto_save: false }));
    cleanup = test.cleanup;

    expect(screen.getAllByRole('listitem').map((li) => li.textContent)).toEqual(['텐트 폴대2026-11-04 11:00', '등산화2026-11-04 10:00']);
    const input = screen.getByTestId('locked-input') as HTMLInputElement;
    expect(input.disabled).toBe(true);
    expect(input.value).toBe('꺼짐 — 새 검색어를 기록하지 않습니다');
  });

  // @scenario case=admin_user_detail
  // @effects admin_detail_core_section_shows_core_scope_only
  it('탭 항목과 구획이 짝을 이루고, 구획은 확장 주입 영역보다 앞이다', () => {
    const tabs = JSON.stringify(detail).match(/"id":"search_history_info","label":"\$t:admin\.users\.detail\.tabs\.search_history_info"/);
    expect(tabs).not.toBeNull();
    expect(pick(detail, 'section_search_history_info').props.id).toBe('search_history_info');
    const serialized = JSON.stringify(detail);
    expect(serialized.indexOf('section_search_history_info')).toBeGreaterThan(serialized.indexOf('section_notifications_info'));
    expect(serialized.indexOf('section_search_history_info')).toBeLessThan(serialized.indexOf('extension_tab_content'));
  });
});
