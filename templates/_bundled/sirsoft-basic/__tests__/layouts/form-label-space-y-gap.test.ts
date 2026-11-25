/**
 * @file form-label-space-y-gap.test.ts
 * @description `space-y-*` 부모 아래 첫 자식 `form-label` 의 아래 여백 (7.2.1 출시 전 실측 W5-006)
 *
 * `.form-label` 은 components 층의 `mb-1` 이라, 부모의 `space-y-2`(utilities 층, 자식에 margin-block-end)가 이겨
 * 라벨 아래 여백이 4px → 8px 로 커졌다(주문서 상세 주소·해외 주소, 마이페이지 배송지 창). 종전처럼 4px 를 지키려면
 * 그 라벨은 utilities 층 `mb-1` 을 함께 적는다.
 */

import fs from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';

const layoutsDir = path.resolve(__dirname, '../../layouts');

function listJson(dir: string, out: string[] = []): string[] {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) listJson(full, out);
    else if (entry.name.endsWith('.json')) out.push(full);
  }
  return out;
}

const tokens = (cls: unknown) => String(cls ?? '').split(/\s+/).filter(Boolean);

function scan(node: any, file: string, out: string[]): void {
  if (!node || typeof node !== 'object') return;
  if (Array.isArray(node)) {
    node.forEach((n) => scan(n, file, out));
    return;
  }
  const parentTokens = tokens(node.props?.className);
  const children: any[] = Array.isArray(node.children) ? node.children : [];
  if (parentTokens.some((t) => /^space-y-(?!0\b|1\b)/.test(t)) && children.length > 1) {
    const first = children[0];
    const ft = tokens(first?.props?.className);
    if (first?.name === 'Label' && ft.includes('form-label') && !ft.some((t) => /^mb-/.test(t))) {
      out.push(`${path.relative(layoutsDir, file)} — ${first.props?.htmlFor ?? first.id ?? '?'}`);
    }
  }
  Object.values(node).forEach((v) => scan(v, file, out));
}

describe('space-y 부모 아래 form-label 여백 (W5-006)', () => {
  // @scenario case=form_label_space_y_gap
  // @effects form_label_gap_kept
  it('space-y-2 이상 부모의 첫 자식 form-label 은 mb-* 를 함께 적어 종전 4px 를 지킨다', () => {
    const found: string[] = [];
    for (const file of listJson(layoutsDir)) scan(JSON.parse(fs.readFileSync(file, 'utf-8')), file, found);
    expect(found).toEqual([]);
  });
});
