/**
 * @file product-list-datasource-auth-mode.test.ts
 * @description 상품 API 를 받는 데이터소스가 모두 `auth_mode: optional` 인지 잠근다.
 *
 * 상품 목록 응답은 보는 회원의 찜 여부(`is_wishlisted`)를 싣는다. 데이터소스가 로그인 정보를
 * 보내지 않으면 회원에게도 `false` 가 오고, 카드의 찜 단추가 이미 찜한 상품을 「찜 안 함」으로
 * 그려 누르면 해제된다. 분류 화면의 상품 데이터소스에 이 설정이 빠져 있었다.
 *
 * @scenario-manifest tests/scenarios/shop-product-rail.yaml
 */

import { describe, it, expect } from 'vitest';
import * as fs from 'fs';
import * as path from 'path';

const templateDir = path.resolve(__dirname, '../..');
const layoutsDir = path.join(templateDir, 'layouts');

/**
 * 폴더 아래의 구운 레이아웃(.json)을 모두 찾는다.
 */
function listLayoutJson(dir: string, out: string[] = []): string[] {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) listLayoutJson(full, out);
    else if (entry.name.endsWith('.json')) out.push(full);
  }
  return out;
}

describe('상품 API 데이터소스의 로그인 정보 전달', () => {
  const sources: Array<{ file: string; id: string; endpoint: string; auth_mode?: string }> = [];

  for (const file of listLayoutJson(layoutsDir)) {
    const layout = JSON.parse(fs.readFileSync(file, 'utf-8'));
    for (const source of layout.data_sources ?? []) {
      if (typeof source.endpoint === 'string' && source.endpoint.startsWith('/api/modules/sirsoft-ecommerce/products')) {
        sources.push({
          file: path.relative(layoutsDir, file).replace(/\\/g, '/'),
          id: source.id,
          endpoint: source.endpoint,
          auth_mode: source.auth_mode,
        });
      }
    }
  }

  // @scenario case=c13
  // @effects product_datasources_send_optional_auth
  it('상품 API 데이터소스가 한 개 이상 있고 분류 화면이 포함된다', () => {
    expect(sources.length).toBeGreaterThan(5);
    expect(sources.some((s) => s.file === 'shop/category.json' && s.id === 'products')).toBe(true);
  });

  // @scenario case=c14
  // @effects product_datasources_send_optional_auth
  it('모두 auth_mode 가 optional 이다', () => {
    const missing = sources.filter((s) => s.auth_mode !== 'optional').map((s) => `${s.file}#${s.id}`);
    expect(missing).toEqual([]);
  });

  // @scenario case=c15
  // @effects ecommerce_requirement_is_1_3_5
  it('이커머스 연동 요구 버전이 목록 응답 보강이 들어간 1.3.5 이상이다', () => {
    const manifest = JSON.parse(fs.readFileSync(path.join(templateDir, 'template.json'), 'utf-8'));
    expect(manifest.integrations.modules['sirsoft-ecommerce']).toBe('>=1.3.5');
  });
});
