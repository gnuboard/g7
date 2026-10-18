/**
 * @file admin-filter-controls-a11y.test.ts
 * @description 목록 필터의 키보드·화면 읽기 접근성 — 빠른 기간 버튼·검색편집 체크박스
 *
 * 라이브 실측(7.1.0 영역 C, O-C5):
 * - 회원 목록 가입일·최근 로그인 「오늘/1주일/…」 빠른 기간이 `div` 라 Tab 으로 갈 수 없고 Enter/Space 로 누를 수 없었다
 *   (활동 로그·방문자 통계의 같은 버튼은 이미 `button`).
 * - 검색편집 모드에서 필터마다 붙는 「기본 검색에 표시」 체크박스에 접근 가능한 이름이 없었다.
 *
 * 레이아웃 JSON 전체를 훑어 같은 모양이 다른 화면에 다시 생기지 않게 잠근다.
 */

import fs from 'fs';
import path from 'path';
import { describe, it, expect } from 'vitest';

const LAYOUT_ROOT = path.resolve(__dirname, '../../layouts');
const read = (relative: string) => JSON.parse(fs.readFileSync(path.resolve(__dirname, relative), 'utf-8'));
const adminKo = read('../../lang/partial/ko/admin.json');
const adminEn = read('../../lang/partial/en/admin.json');
const adminJa = read('../../../../../lang-packs/_bundled/g7-template-sirsoft-admin_basic-ja/frontend/partial/admin.json');

/** 디렉터리 아래 레이아웃 JSON 경로를 모두 모은다. */
function layoutFiles(dir: string): string[] {
  return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) return layoutFiles(full);
    return entry.name.endsWith('.json') ? [full] : [];
  });
}

/** 트리에서 조건에 맞는 노드를 모두 모은다. */
function collect(node: any, match: (n: any) => boolean, found: any[] = []): any[] {
  if (Array.isArray(node)) {
    node.forEach((child) => collect(child, match, found));
    return found;
  }
  if (node && typeof node === 'object') {
    if (match(node)) found.push(node);
    Object.values(node).forEach((value) => {
      if (value && typeof value === 'object') collect(value, match, found);
    });
  }
  return found;
}

const classOf = (n: any): string => (typeof n?.props?.className === 'string' ? n.props.className : '');

describe('목록 필터 접근성 — 빠른 기간 버튼·검색편집 체크박스', () => {
  // @scenario control=quick_date
  // @effects quick_date_buttons_are_keyboard_buttons
  it('빠른 기간(`btn-date`) 은 모든 레이아웃에서 type="button" 인 button 이다', () => {
    const offenders: string[] = [];
    let seen = 0;

    for (const file of layoutFiles(LAYOUT_ROOT)) {
      const nodes = collect(JSON.parse(fs.readFileSync(file, 'utf-8')), (n) => n.type && /\bbtn-date\b/.test(classOf(n)));
      seen += nodes.length;
      for (const node of nodes) {
        if (node.name !== 'Button' || node.props?.type !== 'button') {
          offenders.push(`${path.relative(LAYOUT_ROOT, file)} → ${node.name}`);
        }
      }
    }

    expect(seen).toBeGreaterThan(0);
    expect(offenders).toEqual([]);
  });

  // @scenario control=edit_visibility_checkbox
  // @effects filter_edit_checkboxes_have_accessible_name
  it('검색편집 「기본 검색에 표시」 체크박스는 필터 이름이 든 접근 가능한 이름을 가진다', () => {
    const partial = read('../../layouts/partials/admin_user_list/_partial_filter_section.json');
    const toggles = collect(partial, (n) => n.name === 'Input' && n.props?.type === 'checkbox' && classOf(n).includes('isEditMode'));

    expect(toggles.length).toBeGreaterThanOrEqual(12);
    for (const toggle of toggles) {
      expect(toggle.props['aria-label'], JSON.stringify(toggle.props)).toMatch(/^\$t:admin\.users\.filter\.show_in_basic\|label=\$t:admin\.users\.filter\.[a-z_.]+$/);
    }

    for (const pack of [adminKo, adminEn, adminJa]) {
      expect(pack.users.filter.show_in_basic).toContain('{{label}}');
    }
  });

  // 라이브 실측(7.1.0 영역 C 후속, O-C2 같은 결함군): 서버는 날짜 칸을 보는 사람의 시간대 하루로 자르는데,
  // 빠른 기간 버튼이 `toISOString()` 으로 UTC 날짜를 만들면 한국 시간 오전 9시 전에는 「오늘」 이 어제가 된다.
  // @scenario control=quick_date
  // @effects quick_date_uses_local_calendar_day
  it('빠른 기간 식은 UTC 날짜(`toISOString()` 자르기)로 오늘을 만들지 않는다', () => {
    const offenders: string[] = [];
    for (const file of layoutFiles(LAYOUT_ROOT)) {
      const text = fs.readFileSync(file, 'utf-8');
      if (/toISOString\(\)\.(slice\(0, ?10\)|split\('T'\)\[0\])/.test(text)) {
        offenders.push(path.relative(LAYOUT_ROOT, file));
      }
    }
    expect(offenders).toEqual([]);
  });
});
