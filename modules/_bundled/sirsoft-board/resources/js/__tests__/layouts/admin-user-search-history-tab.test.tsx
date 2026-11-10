/**
 * @file admin-user-search-history-tab.test.tsx
 * @description 회원 상세 「게시글 검색어」 탭 확장 (게시판 1.2.3 · 코어 7.1.5)
 *
 * 이 조각이 지키는 계약:
 *  - 탭 버튼(`inject_props` → `tabs._append`)과 본문(`append_child`)은 서로 다른 앵커에 붙고 `id`
 *    (`ext_board_search_history`) 하나로 이어진다.
 *  - 회원이 게시글 검색창(범위 `sirsoft-board.posts`)에 남긴 검색어만 보인다. 값은 코어 회원 상세 응답의
 *    `search_history.scopes['sirsoft-board.posts']` — 이 모듈의 회원 데이터라 코어 본문이 아니라 이 모듈 탭에 선다.
 *  - 범위가 응답에 없으면(범위 선언이 빠진 사이트·옛 코어·프로필 응답) 탭도 본문도 서지 않는다. 0건이면 자리를 남긴다.
 *
 * @scenario-manifest tests/scenarios/admin-user-search-history.yaml
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect, afterEach } from 'vitest';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import { asArray } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

const extension = require('../../../extensions/admin-user-search-history-tab.json');

const injections = asArray(extension.injections);
const tabInjection = injections.find((i: any) => i.position === 'inject_props');
const contentInjection = injections.find((i: any) => i.position === 'append_child');
const tabItem = asArray(tabInjection?.props?.tabs?._append)[0];
const content = asArray(contentInjection?.components)[0];

const TestDiv: React.FC<any> = ({ className, children, text }) => <div className={className}>{children || text}</div>;
const TestSpan: React.FC<any> = ({ className, children, text }) => <span className={className}>{children || text}</span>;
const TestP: React.FC<any> = ({ className, children, text }) => <p className={className}>{children || text}</p>;
const TestH3: React.FC<any> = ({ className, children, text }) => <h3 className={className}>{children || text}</h3>;
const TestUl: React.FC<any> = ({ className, children }) => <ul className={className}>{children}</ul>;
const TestLi: React.FC<any> = ({ className, children }) => <li className={className}>{children}</li>;
const TestIcon: React.FC<any> = ({ className }) => <i className={className} />;
const TestBadge: React.FC<any> = () => <span data-testid="extension-badge" />;
const TestFragment: React.FC<any> = ({ children }) => <>{children}</>;

function setupRegistry(): void {
  (ComponentRegistry.getInstance() as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    H3: { component: TestH3, metadata: { name: 'H3', type: 'basic' } },
    Ul: { component: TestUl, metadata: { name: 'Ul', type: 'basic' } },
    Li: { component: TestLi, metadata: { name: 'Li', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    ExtensionBadge: { component: TestBadge, metadata: { name: 'ExtensionBadge', type: 'composite' } },
    // 시험 도구가 뿌리를 Fragment 로 감싼다 — 빠뜨리면 빈 컨테이너만 남아 부정 단언이 거짓 통과한다
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };
}

const translations = {
  'sirsoft-board': {
    admin: {
      users: {
        search_history: { tab_label: '게시글 검색어', title: '최근 게시글 검색어', count_unit: '{{count}}건', empty: '저장된 게시글 검색어가 없습니다.' },
      },
    },
  },
};

async function renderContent(scopes: Record<string, any> | undefined) {
  setupRegistry();
  const test = createLayoutTest({ version: '1.0.0', layout_name: 'board_search_history', components: [content] } as any, {
    translations,
    locale: 'ko',
    initialData: { user: { data: { uuid: 'u-1', search_history: scopes === undefined ? undefined : { enabled: true, auto_save: true, scopes } } }, $templateId: 'test-template', $locale: 'ko' },
  });
  await test.render();
  return test;
}

let cleanup: (() => void) | null = null;
afterEach(() => {
  cleanup?.();
  cleanup = null;
});

describe('회원 상세 게시글 검색어 탭 확장', () => {
  // @scenario case=board_scope_tab
  // @effects board_tab_button_and_body_share_one_id
  it('회원 상세를 대상으로 탭 버튼과 본문이 서로 다른 앵커에 붙고 같은 id 로 이어진다', () => {
    expect(extension.target_layout).toBe('admin_user_detail');
    expect(tabInjection.target_id).toBe('user_detail_tabs');
    expect(contentInjection.target_id).toBe('extension_tab_content');
    expect(tabItem.id).toBe('ext_board_search_history');
    expect(content.props.id).toBe('ext_board_search_history');
    expect(tabItem.label).toBe('$t:sirsoft-board.admin.users.search_history.tab_label');
  });

  // @scenario case=board_scope_tab
  // @effects board_tab_hidden_without_scope
  it('탭과 본문 모두 게시글 검색 범위가 응답에 있을 때만 선다', () => {
    expect(tabItem.if).toContain("['sirsoft-board.posts']");
    expect(content.if).toContain("['sirsoft-board.posts']");
  });

  // @scenario case=board_scope_tab
  // @effects board_tab_lists_post_scope_keywords_only
  it('게시글 검색 범위의 검색어만 받은 순서대로 일시와 함께 그린다', async () => {
    const test = await renderContent({
      'core.site': { owner: 'core', label: '통합 검색', items: [{ keyword: '공지', searched_at: '2026-11-04 12:00' }] },
      'sirsoft-board.posts': {
        owner: 'module:sirsoft-board',
        label: '게시글 검색',
        items: [
          { keyword: '정기 공연', searched_at: '2026-11-04 11:00' },
          { keyword: '강습 일정', searched_at: '2026-11-04 10:00' },
        ],
      },
    });
    cleanup = test.cleanup;

    expect(screen.getAllByRole('listitem').map((li) => li.textContent)).toEqual(['정기 공연2026-11-04 11:00', '강습 일정2026-11-04 10:00']);
    expect(screen.getByText('최근 게시글 검색어')).toBeTruthy();
    expect(screen.getByText('2건')).toBeTruthy();
    expect(screen.queryByText('공지')).toBeNull();
  });

  // @scenario case=board_scope_tab
  // @effects board_tab_stands_when_empty
  it('0건이면 자리를 남기고 없다고 말한다', async () => {
    const test = await renderContent({ 'sirsoft-board.posts': { owner: 'module:sirsoft-board', label: '게시글 검색', items: [] } });
    cleanup = test.cleanup;

    expect(screen.queryAllByRole('listitem')).toHaveLength(0);
    expect(screen.getByText('저장된 게시글 검색어가 없습니다.')).toBeTruthy();
    expect(screen.getByText('0건')).toBeTruthy();
  });
});
