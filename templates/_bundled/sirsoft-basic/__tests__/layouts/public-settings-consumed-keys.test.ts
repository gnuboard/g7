/**
 * @file public-settings-consumed-keys.test.ts
 * @description 이 템플릿이 공개 설정 API 응답에서 읽는 키가 이커머스의 공개 키 목록 안에 있는지 잠근다.
 *
 * 공개 설정 API(settings/shipping·payment·review)는 이커머스 설정 기본값 파일의 `public_api_schema` 에
 * 있는 키만 돌려준다. 레이아웃이 그 밖의 키를 읽으면 조용히 빈 값이 된다.
 *
 * @scenario-manifest tests/scenarios/shop-display-polish.yaml
 */

import { describe, it, expect } from 'vitest';
import * as fs from 'fs';
import * as path from 'path';

const templateDir = path.resolve(__dirname, '../..');
const layoutsDir = path.join(templateDir, 'layouts');
const defaultsPath = path.resolve(templateDir, '../../../modules/_bundled/sirsoft-ecommerce/config/settings/defaults.json');

function listMarkup(dir: string, out: string[] = []): string[] {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) listMarkup(full, out);
    else if (entry.name.endsWith('.g7.html')) out.push(full);
  }
  return out;
}

/** 데이터소스 이름 → 응답 안의 설정 카테고리 */
const SOURCES: Record<string, string> = {
  shippingSettings: 'shipping',
  paymentSettings: 'order_settings',
  reviewSettings: 'review_settings',
};

describe('공개 설정 API 소비 키', () => {
  // @scenario case=c07
  // @effects consumed_setting_keys_are_public
  it('레이아웃이 읽는 설정 키가 모두 이커머스 공개 키 목록에 있다', () => {
    const schema = JSON.parse(fs.readFileSync(defaultsPath, 'utf-8')).public_api_schema;
    const consumed: Record<string, Set<string>> = { shipping: new Set(), order_settings: new Set(), review_settings: new Set() };

    for (const file of listMarkup(layoutsDir)) {
      const text = fs.readFileSync(file, 'utf-8');
      for (const [source, category] of Object.entries(SOURCES)) {
        const pattern = new RegExp(`${source}\\??\\.data\\??\\.${category}\\??\\.([A-Za-z_]+)`, 'g');
        for (const m of text.matchAll(pattern)) consumed[category].add(m[1]);
      }
    }

    // 실제로 읽는 키가 잡혔는지(패턴이 낡아 빈 집합으로 통과하지 않게)
    expect(consumed.order_settings.size).toBeGreaterThan(0);
    expect(consumed.review_settings.size).toBeGreaterThan(0);
    expect(consumed.shipping.size).toBeGreaterThan(0);

    for (const [category, keys] of Object.entries(consumed)) {
      const missing = [...keys].filter((key) => !schema[category].includes(key));
      expect(missing, category).toEqual([]);
    }
  });

  // @scenario case=c08
  // @effects footer_leaves_business_info_to_ecommerce_fragment
  it('푸터는 사업자 정보를 받지 않고, 쇼핑몰이 그 바로 아래 공용 자리(user_global_footer)에 그린다', () => {
    const base = fs.readFileSync(path.join(layoutsDir, '_user_base.json'), 'utf-8');
    const layout = JSON.parse(base);
    const footers: any[] = [];
    const slots: any[] = [];
    const walk = (n: any): void => {
      if (!n || typeof n !== 'object') return;
      if (Array.isArray(n)) {
        n.forEach((child, i) => {
          if (child?.name === 'Footer' && child?.type === 'composite') footers.push({ node: child, siblings: n, index: i });
          walk(child);
        });
        return;
      }
      if (n.type === 'extension_point' && n.name === 'user_global_footer') slots.push(n);
      Object.values(n).forEach(walk);
    };
    walk(layout);
    expect(footers).toHaveLength(1);
    // 쇼핑몰 사업자 정보는 쇼핑몰 모듈 조각 하나가 그린다 — 푸터에도 넘기면 같은 정보가 두 번 나온다
    expect(footers[0].node.props ?? {}).not.toHaveProperty('businessInfo');
    expect(base).not.toContain('show_business_footer');
    // 그 조각이 붙는 자리는 푸터 바로 뒤에 있다
    expect(slots).toHaveLength(1);
    expect(footers[0].siblings[footers[0].index + 1]).toBe(slots[0]);
  });
});
