/**
 * @file header-menu-coupons-auto-item.test.ts
 * @description 헤더 메뉴 자동 항목 「쿠폰존」 (#952 C) — 메뉴 편집기 목록에 있고, 기본 메뉴에는 없으며, 쇼핑몰이 꺼지면 빠진다.
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';
import { dataBindingEngine } from '@core/template-engine/DataBindingEngine';

const ROOT = path.resolve(__dirname, '../..');
const base = JSON.parse(fs.readFileSync(path.join(ROOT, 'layouts/_user_base.json'), 'utf8'));
const defaults = fs.readFileSync(path.join(ROOT, 'config/settings/defaults.json'), 'utf8');
const header = fs.readFileSync(path.join(ROOT, 'src/components/composite/Header.tsx'), 'utf8');
const expr = String(base.computed.siteMenu).replace(/^\{\{/, '').replace(/\}\}$/, '');

const evaluate = (menu: unknown, activeModules: string[]) =>
  dataBindingEngine.evaluateExpression(expr, {
    _global: { activeModules, templateSettings: { header: { menu } } },
  } as any) as Array<{ id: string }>;

describe('헤더 메뉴 자동 항목 쿠폰존 (#952)', () => {
  // @scenario case=header_menu_coupons
  // @effects header_menu_coupons_auto_item
  it('메뉴 편집기의 자동 항목 목록에 「쿠폰존」 이 있고 다국어 키를 쓴다', () => {
    expect(defaults).toContain('"coupons"');
    expect(defaults).toContain('$t:settings.header.menu.auto.coupons.label');
  });

  // @scenario case=header_menu_coupons
  // @effects header_menu_coupons_auto_item
  it('운영자가 넣으면 보이고, 쇼핑몰 모듈이 꺼지면 빠진다', () => {
    const menu = [{ id: 'home', kind: 'home' }, { id: 'coupons', kind: 'coupons' }];
    expect(evaluate(menu, ['sirsoft-ecommerce']).map((i) => i.id)).toEqual(['home', 'coupons']);
    expect(evaluate(menu, ['sirsoft-board']).map((i) => i.id)).toEqual(['home']);
  });

  // @scenario case=header_menu_coupons
  // @effects header_menu_coupons_auto_item
  it('기본 메뉴(설정 없음)에는 쿠폰존이 없다', () => {
    const items = dataBindingEngine.evaluateExpression(expr, { _global: { activeModules: ['sirsoft-ecommerce', 'sirsoft-board'] } } as any) as Array<{ id: string }>;
    expect(items.map((i) => i.id)).not.toContain('coupons');
  });

  // @scenario case=header_menu_coupons
  // @effects header_menu_coupons_auto_item
  it('헤더 컴포넌트가 coupons 항목을 쇼핑몰 쿠폰존 링크로 그린다', () => {
    expect(header).toContain("case 'coupons':");
    expect(header).toContain('data-testid="nav-coupons"');
    expect(header).toContain("'nav.coupons'");
  });
});
