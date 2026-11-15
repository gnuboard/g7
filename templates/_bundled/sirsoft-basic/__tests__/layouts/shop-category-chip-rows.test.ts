/**
 * @file shop-category-chip-rows.test.ts
 * @description 상품 목록·분류 화면의 분류 칩 줄이 ChipRows 컨테이너 안에 있고, 칩 링크는 레이아웃에 그대로 남는지 잠근다.
 *
 * 칩을 컴포넌트 prop 으로 넘기지 않고 자식으로 두는 이유: 칩 링크의 이동 동작과 검색 엔진용 링크 수집이
 * 레이아웃에 남아야 한다.
 *
 * @scenario-manifest tests/scenarios/shop-category-chip-rows.yaml
 */

import { describe, it, expect } from 'vitest';
import * as fs from 'fs';
import * as path from 'path';

const templateDir = path.resolve(__dirname, '../..');
const layoutsDir = path.join(templateDir, 'layouts');

function loadJson(relative: string): any {
  return JSON.parse(fs.readFileSync(path.join(layoutsDir, relative), 'utf-8'));
}

function collect(node: any, match: (n: any) => boolean, out: any[] = []): any[] {
  if (!node || typeof node !== 'object') return out;
  if (Array.isArray(node)) {
    node.forEach((n) => collect(n, match, out));
    return out;
  }
  if (match(node)) out.push(node);
  Object.values(node).forEach((v) => collect(v, match, out));
  return out;
}

const SCREENS = ['shop/index.json', 'shop/category.json'] as const;

describe('분류 칩 줄', () => {
  // @scenario case=c13
  // @effects category_chips_live_inside_chip_rows
  it.each(SCREENS)('%s — 칩 줄의 뿌리가 ChipRows 이고 이름을 갖는다', (file) => {
    const rows = collect(loadJson(file), (n) => n.name === 'ChipRows' && n.type === 'composite');
    expect(rows).toHaveLength(1);
    expect(rows[0].props.ariaLabel).toBe('$t:shop.category.chips_label');
  });

  // @scenario case=c14
  // @effects category_chip_links_stay_in_layout
  it.each(SCREENS)('%s — 칩 링크와 이동 동작이 ChipRows 의 자식으로 남아 있다', (file) => {
    const rows = collect(loadJson(file), (n) => n.name === 'ChipRows')[0];
    const links = collect(rows.children, (n) => n.name === 'A');
    expect(links.length).toBeGreaterThan(0);
    for (const link of links) {
      expect(String(link.props.href)).toContain('{{_global.shopBase}}');
      expect(collect(link, (n) => n.handler === 'navigate')).toHaveLength(1);
    }
    // 반복(분류 목록)도 그대로다
    expect(collect(rows.children, (n) => typeof n.iteration === 'object').length).toBe(1);
  });

  // @scenario case=c15
  // @effects category_chip_classes_read_shrink_variables
  it.each(SCREENS)('%s — 칩의 여백·글자 크기가 줄이기 변수를 읽고(없으면 원래 크기) 긴 이름은 잘린다', (file) => {
    const rows = collect(loadJson(file), (n) => n.name === 'ChipRows')[0];
    const links = collect(rows.children, (n) => n.name === 'A');
    for (const link of links) {
      const className = String(link.props.className);
      expect(className).toContain('px-[var(--g7-chip-px,0.75rem)]');
      expect(className).toContain('text-[length:var(--g7-chip-fs,0.875rem)]');
      expect(className).not.toMatch(/(^|[ '"])px-3([ '"]|$)/);
      expect(className).not.toMatch(/(^|[ '"])text-sm([ '"]|$)/);
      // 칩 하나가 줄 전체를 차지하지 않게 폭 상한을 둔다
      expect(className).toContain('max-w-[16rem]');
    }
  });

  // @scenario case=c16
  // @effects chip_rows_registered_for_seo_and_styles
  it('검색 엔진용 HTML 에서는 nav 로 그려 자식 링크가 남고, 변수형 클래스가 스타일 산출 목록에 있다', () => {
    const seo = JSON.parse(fs.readFileSync(path.join(templateDir, 'seo-config.json'), 'utf-8'));
    expect(seo.component_map.ChipRows).toEqual({ tag: 'nav' });

    const css = fs.readFileSync(path.join(templateDir, 'src/styles/main.css'), 'utf-8');
    for (const cls of ['px-[var(--g7-chip-px,0.75rem)]', 'text-[length:var(--g7-chip-fs,0.875rem)]', 'max-w-[16rem]']) {
      expect(css, cls).toContain(cls);
    }
  });
});
