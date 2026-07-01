/**
 * @file admin-base-header-lang-currency.test.tsx
 * @description 관리자 헤더 언어·통화 선택기 패리티 회귀 테스트
 *
 * 배경(PO 검수 지적):
 * - 관리자 데스크톱 언어 버튼이 globe 아이콘만 표시하고 현재 선택 언어를 안 보여줌(유저는 코드 표시).
 *   → LanguageSelector 에 showCode 도입, _admin_base 가 showCode:true 로 사용.
 * - 관리자 모바일 헤더에 언어·통화 버튼이 둘 다 없음(데스크톱에만 존재).
 *   → mobile_header_right 에 language_selector_mobile + header_currency_slot_mobile 추가.
 *
 * 합의 정본: 4표면(유저·관리자 × 데스크톱·모바일) 모두 언어+통화가 같은 줄에 현재값과 함께 노출.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const baseDir = path.resolve(__dirname, '../..');

function loadJson(relPath: string): any {
  return JSON.parse(fs.readFileSync(path.resolve(baseDir, relPath), 'utf8'));
}

function find(node: any, id: string): any {
  if (!node || typeof node !== 'object') return null;
  if (Array.isArray(node)) { for (const n of node) { const r = find(n, id); if (r) return r; } return null; }
  if (node.id === id) return node;
  for (const k of ['children', 'components']) { if (node[k]) { const r = find(node[k], id); if (r) return r; } }
  return null;
}

describe('관리자 헤더 언어·통화 패리티', () => {
  const adminBase = loadJson('layouts/_admin_base.json');

  it('데스크톱 언어 선택기는 showCode:true 로 현재 로케일 코드를 표시한다', () => {
    const node = find(adminBase.components, 'language_selector_desktop');
    expect(node).toBeTruthy();
    expect(node.name).toBe('LanguageSelector');
    expect(node.props?.showCode).toBe(true);
  });

  it('모바일 헤더에 언어 선택기 + 통화 슬롯이 같은 줄에 노출된다', () => {
    const mobileRight = find(adminBase.components, 'mobile_header_right');
    expect(mobileRight).toBeTruthy();
    const ids = (mobileRight.children ?? []).map((c: any) => c.id);
    expect(ids).toContain('language_selector_mobile');
    expect(ids).toContain('header_currency_slot_mobile');
  });

  it('모바일 언어 선택기도 showCode:true (데스크톱과 동일 표기)', () => {
    const node = find(adminBase.components, 'language_selector_mobile');
    expect(node).toBeTruthy();
    expect(node.name).toBe('LanguageSelector');
    expect(node.props?.showCode).toBe(true);
  });

  it('모바일 통화 슬롯은 데스크톱과 동일한 header_currency 슬롯을 공유한다', () => {
    const node = find(adminBase.components, 'header_currency_slot_mobile');
    expect(node).toBeTruthy();
    expect(node.name).toBe('SlotContainer');
    expect(node.props?.slotId).toBe('header_currency');
  });
});
