/**
 * @file template-cleanup.test.ts
 * @description 기본 템플릿 정리 항목을 잠근다.
 *
 * - 어디서도 포함하지 않는 조각이 남아 있지 않다
 * - 검색 엔진용 HTML 의 상품 카드 링크가 화면 카드와 같은 주소(상품 코드)를 쓴다
 * - 편집기 팔레트의 상품 카드 기본 노드가 실제 상품 목록 응답 모양이다
 * - 컴포넌트 등록부의 상품 카드 인자가 실제 컴포넌트와 같다
 *
 * @scenario-manifest tests/scenarios/template-cleanup.yaml
 */

import { describe, it, expect } from 'vitest';
import * as fs from 'fs';
import * as path from 'path';

const templateDir = path.resolve(__dirname, '../..');
const layoutsDir = path.join(templateDir, 'layouts');

function listFiles(dir: string, out: string[] = []): string[] {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) listFiles(full, out);
    else out.push(full);
  }
  return out;
}

describe('기본 템플릿 정리', () => {
  // @scenario case=c01
  // @effects unused_partials_removed
  it('어디서도 포함하지 않는 상점 조각 세 개가 남아 있지 않다', () => {
    const removed = [
      'partials/shop/detail/_review_avatar',
      'partials/shop/list/_category_filter',
      'partials/shop/list/_category_breadcrumb',
    ];
    for (const name of removed) {
      expect(fs.existsSync(path.join(layoutsDir, `${name}.g7.html`)), name).toBe(false);
      expect(fs.existsSync(path.join(layoutsDir, `${name}.json`)), name).toBe(false);
    }

    // 남은 레이아웃 어디에도 그 이름이 없다
    const sources = listFiles(layoutsDir).filter((f) => f.endsWith('.g7.html'));
    for (const name of removed) {
      const base = path.basename(name);
      const users = sources.filter((f) => fs.readFileSync(f, 'utf-8').includes(`${base}.g7.html`));
      expect(users, base).toEqual([]);
    }
  });

  // @scenario case=c02
  // @effects seo_product_card_links_by_product_code
  it('검색 엔진용 상품 카드 링크가 상품 코드를 쓰고, 코드가 없는 응답만 번호로 대신한다', () => {
    const seo = JSON.parse(fs.readFileSync(path.join(templateDir, 'seo-config.json'), 'utf-8'));
    const link = seo.render_modes.product_card_view.link;

    expect(link.href).toBe('/products/{product_code|id}');
    expect(link.base_url).toBe('$var:shopBase');
  });

  // @scenario case=c03
  // @effects palette_product_card_matches_list_response
  it('팔레트의 상품 카드 기본 노드가 상품 목록 응답 모양이다(카드가 읽지 않는 옛 필드가 없다)', () => {
    const palette = JSON.parse(fs.readFileSync(path.join(templateDir, 'editor-spec/componentPalette.json'), 'utf-8'));
    const product = palette.entries.ProductCard.defaultNode.props.product;

    for (const key of ['id', 'name_localized', 'thumbnail_url', 'selling_price', 'selling_price_formatted', 'sales_status']) {
      expect(product, key).toHaveProperty(key);
    }
    expect(product).not.toHaveProperty('price');
    expect(product).not.toHaveProperty('image');
    // 견본 그림은 바깥 주소를 부르지 않는다
    expect(String(product.thumbnail_url)).toMatch(/^data:image\//);
  });

  // @scenario case=c04
  // @effects product_card_manifest_matches_component
  it('컴포넌트 등록부의 상품 카드 인자가 실제 컴포넌트의 인자와 같다', () => {
    const manifest = JSON.parse(fs.readFileSync(path.join(templateDir, 'components.json'), 'utf-8'));
    const entry = manifest.components.composite.find((c: any) => c.name === 'ProductCard');
    const source = fs.readFileSync(path.join(templateDir, 'src/components/composite/ProductCard.tsx'), 'utf-8');
    const destructured = source.match(/const ProductCard: React\.FC<ProductCardProps> = \(\{([\s\S]*?)\}\) =>/)![1];
    const actual = destructured
      .split(',')
      .map((p) => p.trim().split(/[ =]/)[0])
      .filter((p) => p && !['id', 'editorAttrs'].includes(p))
      .sort();

    expect(Object.keys(entry.props).sort()).toEqual(actual);
    expect(entry.props).not.toHaveProperty('showDiscount');
  });
});
