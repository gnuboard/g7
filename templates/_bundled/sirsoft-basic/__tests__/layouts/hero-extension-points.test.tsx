/**
 * @file hero-extension-points.test.tsx
 * @description 사이트 홈·쇼핑몰 메인 상단의 히어로 확장점(`home_hero`·`shop_hero`) — 자리가 본문 맨 앞(전폭)에 있고,
 * 주입이 없으면 아무것도 그리지 않아 지금 화면과 같다(#810). 확장점 이름은 다른 템플릿도 따르는 공개 계약이다.
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
  // @scenario surface=home_layout_has_home_hero_slot
  it('사이트 홈은 본문 맨 앞(본문 상자 앞, 전폭)에 home_hero 자리를 하나 둔다', () => {
    const home = load('home.json');
    expect(slotsNamed(home, 'home_hero')).toHaveLength(1);
    const content = home.slots.content as Node[];
    expect(content[0]).toMatchObject({ type: 'extension_point', name: 'home_hero', id: 'home_hero_slot', default: [] });
    expect(content[1].name).toBe('Container');
  });

  // @scenario surface=shop_layout_has_shop_hero_slot
  it('쇼핑몰 메인은 본문 맨 앞에 shop_hero 자리를 하나 둔다', () => {
    const shop = load('shop/index.json');
    expect(slotsNamed(shop, 'shop_hero')).toHaveLength(1);
    const content = shop.slots.content as Node[];
    expect(content[0]).toMatchObject({ type: 'extension_point', name: 'shop_hero', id: 'shop_hero_slot', default: [] });
    expect(content[1].name).toBe('Container');
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
