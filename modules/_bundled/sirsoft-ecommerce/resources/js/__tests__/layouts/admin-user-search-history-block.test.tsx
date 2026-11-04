/**
 * @file admin-user-search-history-block.test.tsx
 * @description 회원 상세 「쇼핑 데이터」 탭 안 「최근 상품 검색어」 블록 (이커머스 1.3.4 · 코어 7.1.5)
 *
 * 이 블록이 지키는 계약:
 *  - 회원이 상품 검색창(범위 `sirsoft-ecommerce.products`)에 남긴 검색어를 보인다. 값은 코어 회원 상세 응답의
 *    `search_history.scopes['sirsoft-ecommerce.products']` — 이 모듈의 회원 데이터라 코어 본문이 아니라 이 탭에 선다.
 *  - 다른 범위(통합 검색·게시글 검색) 검색어는 그리지 않는다.
 *  - 범위가 응답에 없으면(검색어 기능이 없는 옛 코어) 블록이 서지 않고, 0건이면 자리를 남기고 없다고 말한다.
 *
 * @scenario-manifest tests/scenarios/admin-user-search-history.yaml
 */

import React from 'react';
import { describe, it, expect, afterEach } from 'vitest';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import { findById } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

const extension = require('../../../extensions/admin-user-ecommerce-data-tab.json');

const TestDiv: React.FC<any> = ({ className, children, text }) => <div className={className}>{children || text}</div>;
const TestSpan: React.FC<any> = ({ className, children, text }) => <span className={className}>{children || text}</span>;
const TestUl: React.FC<any> = ({ className, children }) => <ul className={className}>{children}</ul>;
const TestLi: React.FC<any> = ({ className, children }) => <li className={className}>{children}</li>;
const TestFragment: React.FC<any> = ({ children }) => <>{children}</>;

function setupRegistry(): void {
  (ComponentRegistry.getInstance() as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    Ul: { component: TestUl, metadata: { name: 'Ul', type: 'basic' } },
    Li: { component: TestLi, metadata: { name: 'Li', type: 'basic' } },
    // 시험 도구가 뿌리를 Fragment 로 감싼다 — 빠뜨리면 빈 컨테이너만 남아 부정 단언이 거짓 통과한다
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };
}

const translations = {
  'sirsoft-ecommerce': {
    admin: {
      user_ecommerce_data: {
        count_unit: '{{count}}건',
        search_history: '최근 상품 검색어',
        search_history_empty: '저장된 상품 검색어가 없습니다.',
      },
    },
  },
};

const block = findById(extension, 'ecommerce_search_history');

async function renderBlock(scopes: Record<string, any> | undefined) {
  setupRegistry();
  const test = createLayoutTest({ version: '1.0.0', layout_name: 'ecommerce_search_history', components: [block] } as any, {
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

describe('회원 상세 쇼핑 데이터 탭 — 최근 상품 검색어', () => {
  // @scenario case=ecommerce_scope_block
  // @effects ecommerce_tab_lists_product_scope_keywords_only
  it('상품 검색 범위의 검색어만 받은 순서대로 일시와 함께 그린다', async () => {
    const test = await renderBlock({
      'core.site': { owner: 'core', label: '통합 검색', items: [{ keyword: '공지', searched_at: '2026-11-04 12:00' }] },
      'sirsoft-ecommerce.products': {
        owner: 'module:sirsoft-ecommerce',
        label: '상품 검색',
        items: [
          { keyword: '캠핑 의자', searched_at: '2026-11-04 11:00' },
          { keyword: '등산화 끈', searched_at: '2026-11-04 10:00' },
        ],
      },
    });
    cleanup = test.cleanup;

    expect(screen.getAllByRole('listitem').map((li) => li.textContent)).toEqual(['캠핑 의자2026-11-04 11:00', '등산화 끈2026-11-04 10:00']);
    expect(screen.getByText('최근 상품 검색어 · 2건')).toBeTruthy();
    expect(screen.queryByText('공지')).toBeNull();
  });

  // @scenario case=ecommerce_scope_block
  // @effects ecommerce_tab_search_block_stands_when_empty
  it('0건이면 자리를 남기고 없다고 말한다', async () => {
    const test = await renderBlock({ 'sirsoft-ecommerce.products': { owner: 'module:sirsoft-ecommerce', label: '상품 검색', items: [] } });
    cleanup = test.cleanup;

    expect(screen.queryAllByRole('listitem')).toHaveLength(0);
    expect(screen.getByText('저장된 상품 검색어가 없습니다.')).toBeTruthy();
  });

  // @scenario case=ecommerce_scope_block
  // @effects ecommerce_tab_search_block_hidden_without_scope
  it('응답에 상품 검색 범위가 없으면(옛 코어) 블록이 서지 않는다', async () => {
    const test = await renderBlock(undefined);
    cleanup = test.cleanup;

    expect(screen.queryByText(/최근 상품 검색어/)).toBeNull();
    expect(screen.queryByText('저장된 상품 검색어가 없습니다.')).toBeNull();
  });

  // @scenario case=ecommerce_scope_block
  // @effects ecommerce_tab_lists_product_scope_keywords_only
  it('블록은 쇼핑 데이터 카드 안, 목록 링크 묶음 앞에 선다', () => {
    const card = findById(extension, 'section_ecommerce_data');
    const serialized = JSON.stringify(card);

    expect(serialized).toContain('"ecommerce_search_history"');
    expect(serialized.indexOf('"ecommerce_search_history"')).toBeLessThan(serialized.indexOf('"ecommerce_data_links"'));
  });
});
