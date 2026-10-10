/**
 * 실제 번들 레이아웃으로 잠그는 셀 경로 반응형 분기 (이슈 #141, §7 V4 대체)
 *
 * 이커머스 상품 관리 목록의 확장 행(`expandChildren`)은 `responsive.portable` 에서 옵션을
 * 카드(`option-row-*`)로, 기본(데스크톱)에서 표(`overflow-x-auto > table`)로 그린다.
 * 편집기 미리보기는 옵션을 불러오는 핸들러를 돌리지 않아 카드 분기(iteration)를 라이브로
 * 볼 수 없으므로, 번들 JSON 을 그대로 읽어 렌더 환경 폭에 따른 분기를 잠근다.
 */

import React from 'react';
import fs from 'node:fs';
import path from 'node:path';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { render, cleanup } from '@testing-library/react';
import { renderItemChildren } from '../RenderHelpers';
import { responsiveManager } from '../../ResponsiveManager';
import { TranslationEngine } from '../../TranslationEngine';

const LAYOUT_PATH = path.resolve(
  __dirname,
  '../../../../../../modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_list/_partial_product_datagrid.json',
);

/** 어느 컴포넌트 이름이든 className·data-testid·컴포넌트 이름을 그대로 싣는 범용 대역 */
const makeGeneric = (name: string): React.FC<Record<string, any>> => ({ className, children, ...rest }) => (
  <div
    className={typeof className === 'string' ? className : undefined}
    data-testid={rest['data-testid']}
    data-name={name}
  >
    {children}
  </div>
);
const genericCache = new Map<string, React.FC<Record<string, any>>>();
const componentMap = new Proxy({} as Record<string, React.ComponentType<any>>, {
  get: (_target, name: string) => {
    if (!genericCache.has(name)) genericCache.set(name, makeGeneric(name));
    return genericCache.get(name);
  },
});

/** 좁은 화면 카드(`admin-card`)와 표 행(`Tr`)은 같은 `option-row-*` testid 를 쓴다 */
const CARD = '.admin-card[data-testid^="option-row-"]';
const TABLE_ROW = '[data-name="Tr"][data-testid^="option-row-"]';

/** 레이아웃 트리에서 첫 `expandChildren` 을 찾는다 */
function findExpandChildren(node: any): any[] | null {
  if (!node || typeof node !== 'object') return null;
  if (Array.isArray(node)) {
    for (const child of node) {
      const found = findExpandChildren(child);
      if (found) return found;
    }
    return null;
  }
  if (Array.isArray(node.expandChildren)) return node.expandChildren;
  for (const value of Object.values(node)) {
    const found = findExpandChildren(value);
    if (found) return found;
  }
  return null;
}

describe('번들 상품 목록 확장 행 — 렌더 환경 폭으로 카드/표 분기', () => {
  let expandChildren: any[];
  let originalWidth: number;

  const row = {
    id: 101,
    abilities: { can_update: true },
    options: [
      { id: 1, option_name: { ko: '빨강' }, option_name_localized: '빨강', is_default: true },
      { id: 2, option_name: { ko: '파랑' }, option_name_localized: '파랑', is_default: false },
    ],
  };

  beforeEach(() => {
    const layout = JSON.parse(fs.readFileSync(LAYOUT_PATH, 'utf-8'));
    expandChildren = findExpandChildren(layout) as any[];
    originalWidth = responsiveManager.getWidth();
    responsiveManager._setWidthForTesting(1441);
  });

  afterEach(() => {
    responsiveManager._setWidthForTesting(originalWidth);
    cleanup();
  });

  const renderWithWidth = (width: number) =>
    render(
      <>
        {renderItemChildren(
          expandChildren,
          { row, optionsLoadingIds: [], optionsErrorIds: [], selectedOptionIds: [] },
          componentMap,
          'expand-101',
          {
            translationEngine: new TranslationEngine(),
            renderEnv: { responsiveWidth: width, isEditMode: true },
          },
        )}
      </>,
    );

  /**
   * @scenario preview_device=mobile, window_width=1441, render_path=expand_iteration, layout=bundled_product_list
   * @effects preview_cell_render_follows_preview_width
   */
  it('창 1441 + 미리보기 390 → 옵션 카드 2개, 표 래퍼 0개', () => {
    expect(expandChildren).toBeTruthy();
    const { container } = renderWithWidth(390);
    expect(container.querySelectorAll(CARD)).toHaveLength(2);
    expect(container.querySelectorAll(TABLE_ROW)).toHaveLength(0);
    expect(container.querySelectorAll('.overflow-x-auto')).toHaveLength(0);
  });

  /**
   * @scenario preview_device=desktop, window_width=1441, render_path=expand_iteration, layout=bundled_product_list
   * @effects preview_cell_render_follows_preview_width
   */
  it('미리보기 1280 → 옵션 카드 0개, 표 행 2개', () => {
    const { container } = renderWithWidth(1280);
    expect(container.querySelectorAll(CARD)).toHaveLength(0);
    expect(container.querySelectorAll(TABLE_ROW)).toHaveLength(2);
    expect(container.querySelectorAll('.overflow-x-auto').length).toBeGreaterThan(0);
  });
});
