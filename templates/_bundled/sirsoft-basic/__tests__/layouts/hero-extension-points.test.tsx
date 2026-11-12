/**
 * @file hero-extension-points.test.tsx
 * @description 사이트 홈·쇼핑몰 메인 상단의 히어로 확장점(`home_hero`·`shop_hero`) — 자리가 본문 상자 밖 전폭 슬롯
 * (`_user_base` 의 page_top, 본문 상자 앞)에 있고 본문 상자 클래스를 확장점 속성으로 넘기며, 주입이 없으면 아무것도 그리지
 * 않아 지금 화면과 같다(#810). 확장점 이름은 다른 템플릿도 따르는 공개 계약이다.
 *
 * @scenario-manifest tests/scenarios/hero-extension-points.yaml
 */
import React from 'react';
import fs from 'node:fs';
import path from 'node:path';
import { describe, it, expect } from 'vitest';
import { createLayoutTest } from '@/core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@/core/template-engine/ComponentRegistry';

type Node = Record<string, any>;

const LAYOUTS = path.resolve(__dirname, '../../layouts');
const load = (rel: string): Node => JSON.parse(fs.readFileSync(path.join(LAYOUTS, rel), 'utf8'));

/** 이름이 같은 확장점 노드 전부. */
const slotsNamed = (root: unknown, name: string, out: Node[] = []): Node[] => {
  if (Array.isArray(root)) root.forEach((child) => slotsNamed(child, name, out));
  else if (root && typeof root === 'object') {
    const obj = root as Node;
    if (obj.type === 'extension_point' && obj.name === name) out.push(obj);
    Object.values(obj).forEach((child) => slotsNamed(child, name, out));
  }
  return out;
};

describe('히어로 확장점', () => {
  /** `_user_base` 의 본문 상자(main_content) — 히어로 자리가 넘기는 본문 상자 클래스는 이 값과 같아야 한다. */
  const mainContent = (): Node => {
    const found: Node[] = [];
    const walk = (n: unknown) => {
      if (Array.isArray(n)) n.forEach(walk);
      else if (n && typeof n === 'object') {
        if ((n as Node).id === 'main_content') found.push(n as Node);
        Object.values(n as Node).forEach(walk);
      }
    };
    walk(load('_user_base.json'));
    return found[0];
  };

  // @scenario surface=home_layout_has_home_hero_slot
  // @effects hero_slot_placed_outside_content_box
  it('공통 틀은 본문 상자 바로 앞에 전폭 슬롯 page_top 을 둔다', () => {
    const area = JSON.stringify(load('_user_base.json'));
    const top = area.indexOf('"slot":"page_top"');
    expect(top).toBeGreaterThan(-1);
    expect(top).toBeLessThan(area.indexOf('"slot":"content"'));
    // g7-page-top — 맨 위 배너가 메뉴 겹침을 알리면 헤더 높이만큼 위로 당겨지는 자리(main.css)
    expect(area).toMatch(/"id":"page_top","type":"layout","name":"Container","slot":"page_top","props":\{"className":"g7-page-top w-full"\}/);
  });

  // @scenario surface=header_overlay_contract
  // @effects header_overlays_banner
  it('메뉴 겹침 — 데스크톱 헤더·모바일 툴바는 g7-site-header 로 표시되고, CSS 는 신호가 있을 때만 자리를 당기고 맨 위에서만 투명하게 한다', () => {
    const base = JSON.stringify(load('_user_base.json'));
    expect(base).toMatch(/"id":"mobile_header"[^]*?"className":"g7-site-header flex items-center justify-between h-14/);
    const header = fs.readFileSync(path.resolve(__dirname, '../../src/components/composite/Header.tsx'), 'utf8');
    expect(header).toContain('useHeaderOverlayController();');
    expect(header).toContain('className={`g7-site-header sticky top-0');
    const css = fs.readFileSync(path.resolve(__dirname, '../../src/styles/main.css'), 'utf8');
    expect(css).toContain('html[data-header-overlay] .g7-page-top {\n  margin-top: calc(-1 * var(--g7-header-height, 0px));');
    expect(css).toContain('html[data-header-overlay-active] .g7-site-header,');
    expect(css).toMatch(/html\[data-header-overlay-active="dark"\] \.g7-site-header \{\n  --g7-header-ink: #ffffff;/);
    expect(css).toMatch(/html\[data-header-overlay-active="light"\] \.g7-site-header \{\n  --g7-header-ink: #111827;/);
  });

  // @scenario surface=home_layout_has_home_hero_slot
  // @effects hero_slot_placed_outside_content_box
  it('사이트 홈은 본문 상자 밖(page_top)에 home_hero 자리를 하나 두고, 아래 카드 줄과 같은 폭의 상자 클래스를 넘긴다', () => {
    const home = load('home.json');
    expect(slotsNamed(home, 'home_hero')).toHaveLength(1);
    const top = home.slots.page_top as Node[];
    expect(top).toHaveLength(1);
    expect(top[0]).toMatchObject({ type: 'extension_point', name: 'home_hero', id: 'home_hero_slot', default: [] });
    // 홈 본문은 공통 틀 본문 상자(px-4 · 데스크톱 max-w-7xl mx-auto px-8) 안에 컨테이너 여백을 한 번 더 둔다 —
    // 히어로는 두 여백을 더한 값이라야 아래 카드 줄과 같은 폭이 된다(휴대폰 16+16 · 태블릿 16+24 · 데스크톱 32+32)
    const main = mainContent();
    expect(main.props.className).toContain('px-4');
    expect(main.responsive.desktop.props.className).toContain('max-w-7xl mx-auto px-8');
    const container = (home.slots.content as Node[])[0];
    expect(container.name).toBe('Container');
    expect(container.props.className).toBe('py-6 px-4');
    expect(container.responsive.tablet.props.className).toBe('py-6 px-6');
    expect(container.responsive.desktop.props.className).toBe('py-6 px-8');
    // 폭마다 다른 값은 Tailwind 폭 접두(md:)가 아니라 기기별 속성으로 넘긴다 — 히어로 조각이 엔진 responsive 로 고른다
    expect(top[0].props).toEqual({ contentClass: 'px-8', contentClassTablet: 'px-10', contentClassDesktop: 'max-w-7xl mx-auto px-16' });
    expect(Object.values(top[0].props).join(' ')).not.toMatch(/(?:^|\s)(?:sm|md|lg|xl|2xl):/);
  });

  // @scenario surface=shop_layout_has_shop_hero_slot
  // @effects hero_slot_placed_outside_content_box
  it('쇼핑몰 메인은 본문 상자 밖(page_top)에 shop_hero 자리를 하나 두고 본문 상자 클래스를 넘긴다', () => {
    const shop = load('shop/index.json');
    expect(slotsNamed(shop, 'shop_hero')).toHaveLength(1);
    const top = shop.slots.page_top as Node[];
    expect(top).toHaveLength(1);
    expect(top[0]).toMatchObject({ type: 'extension_point', name: 'shop_hero', id: 'shop_hero_slot', default: [] });
    expect(top[0].props).toEqual({ contentClass: 'px-4', contentClassDesktop: 'max-w-7xl mx-auto px-8' });
    expect((shop.slots.content as Node[])[0].name).toBe('Container');
    // 두 이름이 섞이지 않는다
    expect(slotsNamed(shop, 'home_hero')).toHaveLength(0);
    expect(slotsNamed(load('home.json'), 'shop_hero')).toHaveLength(0);
  });

  describe('주입이 없을 때', () => {
    /** 확장점 노드 하나만 담은 최소 레이아웃을 엔진으로 그린다. */
    const renderSlot = async (node: Node) => {
      const registry = ComponentRegistry.getInstance();
      (registry as any).registry = {
        Div: { component: ({ className, children }: { className?: string; children?: React.ReactNode }) => <div className={className}>{children}</div>, metadata: { name: 'Div', type: 'basic' } },
      };
      const t = createLayoutTest({ version: '1.0.0', layout_name: 'hero_slot_probe', components: [node] } as any, { componentRegistry: registry });
      try {
        const { container } = await t.render();
        return { text: container.textContent ?? '', media: container.querySelectorAll('img, a, button, section').length };
      } finally {
        t.cleanup();
      }
    };

    // @scenario surface=slot_without_injection_renders_nothing
    // @effects template_regression_free
    it.each([
      ['home.json', 'home_hero'],
      ['shop/index.json', 'shop_hero'],
    ])('%s 의 %s 는 아무 내용도 그리지 않는다', async (file, name) => {
      const result = await renderSlot(slotsNamed(load(file), name)[0]);
      expect(result.text).toBe('');
      expect(result.media).toBe(0);
    });
  });

  // @scenario surface=dynamic_layouts_have_hero_slots
  // @effects hero_slot_placed_outside_content_box
  it('게시판 목록·상품 상세·카테고리 목록도 본문 상자 밖(page_top)에 자기 히어로 자리를 하나씩 두고, 쇼핑몰 메인과 같은 본문 상자 클래스를 넘긴다', () => {
    const main = mainContent();
    for (const [file, name] of [['board/index.json', 'board_hero'], ['shop/show.json', 'product_hero'], ['shop/category.json', 'category_hero']]) {
      const layout = load(file);
      expect(slotsNamed(layout, name), file).toHaveLength(1);
      const top = layout.slots.page_top as Node[];
      expect(top).toHaveLength(1);
      expect(top[0]).toMatchObject({ type: 'extension_point', name, id: `${name}_slot`, default: [] });
      // 대상은 확장점이 넘기지 않는다 — 플러그인 조각이 화면 주소 값을 읽어야 SPA 이동 때 다시 받는다
      expect(top[0].props).toEqual({ contentClass: 'px-4', contentClassDesktop: 'max-w-7xl mx-auto px-8' });
      expect(main.props.className).toContain(top[0].props.contentClass);
      expect(main.responsive.desktop.props.className).toContain(top[0].props.contentClassDesktop);
    }
  });
});
