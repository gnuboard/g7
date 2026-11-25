/**
 * @file multilingual-error-single.test.ts
 * @description 다국어 입력 칸 오류 문구 한 줄 (7.2.1 출시 전 실측 W1-003)
 *
 * `MultilingualInput` 은 `error` prop 으로 칸 아래 오류 문구를 직접 그린다. 같은 부모에 같은 오류 식을 조건으로 쓰는
 * 오류 문구 노드를 또 두면 문구가 두 줄 보인다(역할 추가 폼 — 7.2.1 에서 두 줄이 같은 `.form-error` 모양이 되어 드러남).
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

const norm = (expr: unknown) => String(expr ?? '').replace(/^\{\{|\}\}$/g, '').replace(/\s+/g, '');

function findDuplicates(node: any, file: string, out: string[]): void {
  if (!node || typeof node !== 'object') return;
  if (Array.isArray(node)) {
    node.forEach((n) => findDuplicates(n, file, out));
    return;
  }
  const children: any[] = Array.isArray(node.children) ? node.children : [];
  for (const child of children) {
    if (child?.name !== 'MultilingualInput' || !child?.props?.error) continue;
    const errorExpr = norm(child.props.error);
    for (const sibling of children) {
      if (sibling === child) continue;
      if (norm(sibling?.if) === errorExpr) out.push(`${path.relative(layoutsDir, file)} — ${child.props.name ?? '?'}`);
    }
  }
  Object.values(node).forEach((v) => findDuplicates(v, file, out));
}

describe('다국어 입력 칸 오류 문구 한 줄 (W1-003)', () => {
  // @scenario case=multilingual_error_single
  // @effects multilingual_error_rendered_once
  it('error prop 을 받은 다국어 입력 옆에 같은 오류 식으로 문구를 또 그리는 노드가 없다', () => {
    const found: string[] = [];
    for (const file of listJson(layoutsDir)) {
      findDuplicates(JSON.parse(fs.readFileSync(file, 'utf-8')), file, found);
    }
    expect(found).toEqual([]);
  });
});
