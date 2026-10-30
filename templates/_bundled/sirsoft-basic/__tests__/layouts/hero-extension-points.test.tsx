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
  it('공통 틀은 본문 상자 바로 앞에 전폭 슬롯 page_top 을 둔다', () => {
    const area = JSON.stringify(load('_user_base.json'));
    const top = area.indexOf('"slot":"page_top"');
    expect(top).toBeGreaterThan(-1);
    expect(top).toBeLessThan(area.indexOf('"slot":"content"'));
    expect(area).toMatch(/"id":"page_top","type":"layout","name":"Container","slot":"page_top","props":\{"className":"w-full"\}/);
  });

  // @scenario surface=home_layout_has_home_hero_slot
  it('사이트 홈은 본문 상자 밖(page_top)에 home_hero 자리를 하나 두고 본문 상자 클래스를 넘긴다', () => {
    const home = load('home.json');
    expect(slotsNamed(home, 'home_hero')).toHaveLength(1);
    const top = home.slots.page_top as Node[];
    expect(top).toHaveLength(1);
    expect(top[0]).toMatchObject({ type: 'extension_point', name: 'home_hero', id: 'home_hero_slot', default: [] });
    const main = mainContent();
    expect(top[0].props).toEqual({ contentClass: 'px-4', contentClassDesktop: 'max-w-7xl mx-auto px-8' });
    // 넘기는 값은 공통 틀 본문 상자와 같은 여백·최대 폭이다(min-h-fit 같은 높이 클래스는 빼고)
    expect(main.props.className).toContain(top[0].props.contentClass);
    expect(main.responsive.desktop.props.className).toContain(top[0].props.contentClassDesktop);
    expect((home.slots.content as Node[])[0].name).toBe('Container');
  });

  // @scenario surface=shop_layout_has_shop_hero_slot
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
});
