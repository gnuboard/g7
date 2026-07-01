/**
 * @file mp13-common-info-autobind.test.ts
 * @description A10 — 공통정보 다국어 탭 저장: name 입력칸 자동바인딩 회귀 테스트
 *
 * 결함: name MultilingualInput 이 value 바인딩 + 수동 setState(디바운스 없음)로
 * 탭 전환 시 직전 locale 키가 유실됐다. notice 폼처럼 dataKey 자동바인딩으로 교체한다.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const formPath = path.resolve(
  __dirname,
  '../../../layouts/admin/partials/admin_ecommerce_product_common_info_index/_panel_form.json'
);

function loadJson(p: string): any {
  return JSON.parse(fs.readFileSync(p, 'utf8'));
}

function findById(node: any, id: string): any {
  if (!node || typeof node !== 'object') return null;
  if (node.id === id) return node;
  for (const key of Object.keys(node)) {
    const child = node[key];
    if (Array.isArray(child)) {
      for (const item of child) {
        const found = findById(item, id);
        if (found) return found;
      }
    } else if (typeof child === 'object') {
      const found = findById(child, id);
      if (found) return found;
    }
  }
  return null;
}

function findFirst(node: any, predicate: (n: any) => boolean): any {
  if (!node || typeof node !== 'object') return null;
  if (predicate(node)) return node;
  for (const key of Object.keys(node)) {
    const child = node[key];
    if (Array.isArray(child)) {
      for (const item of child) {
        const found = findFirst(item, predicate);
        if (found) return found;
      }
    } else if (typeof child === 'object') {
      const found = findFirst(child, predicate);
      if (found) return found;
    }
  }
  return null;
}

describe('A10 — 공통정보 name 입력칸 자동바인딩', () => {
  const layout = loadJson(formPath);
  const nameField = findById(layout, 'name_field');

  it('name_field 래퍼에 dataKey="form" 이 있다', () => {
    expect(nameField).toBeTruthy();
    expect(nameField.dataKey).toBe('form');
  });

  it('MultilingualInput 은 name="name" 자동바인딩 (value/actions 제거)', () => {
    const input = findFirst(nameField, (n) => n.name === 'MultilingualInput');
    expect(input).toBeTruthy();
    expect(input.props.name).toBe('name');
    // 자동바인딩 비활성화 원인이던 value 제거
    expect(input.props.value).toBeUndefined();
    // 수동 setState(디바운스 없는 부분병합 누락) 제거
    expect(input.actions).toBeUndefined();
  });
});
