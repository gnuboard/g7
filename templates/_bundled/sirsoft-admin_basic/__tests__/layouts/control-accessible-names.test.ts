/**
 * @file control-accessible-names.test.ts
 * @description 관리자 템플릿 레이아웃의 입력칸은 모두 접근 가능한 이름을 갖는다 (#757)
 *
 * 관리자 화면 입력칸 일부가 화면 낭독기에서 이름 없이 「체크박스」·「스위치, 켬」으로만 읽히거나, 목록형 선택칸이
 * 고른 값(「최신순」)만 읽혔다. 브라우저 측정은 처음 열었을 때 보이는 칸만 세서 모달·탭 뒤·행 안 칸을 놓쳤으므로,
 * 굽기 산출물(layouts·extensions 의 .json)을 전수로 훑어 규칙으로 잠근다.
 *
 * 판정식은 관리자 곁 라벨 자동 연결 훅(useAdjacentLabel)의 규칙을 JSON 트리에 옮긴 것이다(계획서 부록 A 의 named()).
 * 칸(Input·Select·Textarea·Checkbox·Toggle·…, hidden·sr-only 제외)이 다음 중 하나도 없으면 이름 없음이다.
 *  ① 자기 이름(aria-label·ariaLabel·aria-labelledby·placeholder·title·label)
 *  ② 감싸는 Label  ③ for/htmlFor 로 이어진 Label
 *  ④ (체크박스·라디오 제외) 훅이 잇는 곁 라벨 — 조상 3단계, 라벨 범위에 다른 칸이 없을 때
 *  ⑤ (Toggle) 바로 앞 형제 상자의 제목 글자
 * 목록형 선택칸(Select options·SearchableDropdown)은 이름이 없으면 고른 값만 읽히므로 같은 판정으로 0 이어야 한다(D2).
 * 단 목록형은 ①에서 aria-label·aria-labelledby 만 인정한다 — placeholder·title 은 단추 이름이 되지 않고, 값이 비면
 * placeholder 글자가 단추 글자로 보여 결국 「값만」 읽힌다(판정 강화, 2026-10-14 사용자 템플릿 실측).
 *
 * 확장마다 독립 패키지라 공용 파일을 import 하지 않고 판정식을 이 파일에 둔다.
 *
 * @scenario-manifest tests/scenarios/control-accessible-names.yaml
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

const ROOT = path.resolve(__dirname, '../..');
const SCAN_DIRS = ['layouts', 'extensions'].map((d) => path.join(ROOT, d)).filter((d) => fs.existsSync(d));

type Json = any;

interface Hit {
  file: string;
  cat: string;
  control: string;
  key: string;
}

const hasExpr = (n: Json) => deep(n, (x) => /\{\{/.test(String(x.text ?? '')) || /\{\{/.test(String(x.props?.text ?? '')));

const CTRL = new Set(['Input', 'Select', 'Textarea', 'Checkbox', 'Toggle', 'PasswordInput', 'TagInput', 'MultilingualInput', 'SearchableDropdown', 'FileInput', 'MultilingualTagInput', 'IconSelect']);
const HOOKED = new Set(['Input', 'Select', 'Textarea', 'Toggle', 'TagInput', 'PasswordInput']);

function jsonFiles(dir: string): string[] {
  return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) return ['node_modules', 'dist', '__tests__', 'tests'].includes(entry.name) ? [] : jsonFiles(full);
    return entry.name.endsWith('.json') ? [full] : [];
  });
}

const isNode = (x: Json) => x && typeof x === 'object' && !Array.isArray(x) && typeof x.name === 'string' && (x.type || x.props || x.children);
const cls = (n: Json) => String(n?.props?.className ?? '');
const isCtrl = (n: Json) => isNode(n) && CTRL.has(n.name) && n.props?.type !== 'hidden' && !/\bsr-only\b/.test(cls(n));
const isLabelEl = (n: Json) => isNode(n) && (n.name === 'Label' || /\b(form-label|filter-label|text-heading)\b/.test(cls(n)));
const kids = (n: Json): Json[] => (Array.isArray(n?.children) ? n.children.filter(isNode) : []);
const deep = (n: Json, p: (x: Json) => boolean): boolean => (isNode(n) ? p(n) || kids(n).some((c) => deep(c, p)) : false);
const hasText = (n: Json) => deep(n, (x) => (typeof x.text === 'string' && x.text.trim() !== '') || (typeof x.props?.text === 'string' && x.props.text.trim() !== ''));
/**
 * 스위치 행 제목이 될 글자 — 식 하나뿐인 글자(`{{template.fields_count}}`)·숫자만인 글자는 제목이 아니다.
 * 항목 수 배지가 앞 형제라 상품정보고시 스위치가 「9」「10」으로 읽혔다(판정 강화 2, 2026-10-14 이커머스 실측).
 */
const isTitleText = (t: unknown) =>
  typeof t === 'string' && t.trim() !== '' && !/^\{\{[^{}]*\}\}$/.test(t.trim()) && !/^[\d\s.,:/+\-]+$/.test(t.trim());
const hasTitleText = (n: Json) => deep(n, (x) => isTitleText(x.text) || isTitleText(x.props?.text));
const isLabelSlot = (n: Json) => isLabelEl(n) || (!deep(n, isCtrl) && deep(n, isLabelEl));
const ownName = (p: Json) => Boolean(p['aria-label'] || p.ariaLabel || p['aria-labelledby'] || p.placeholder || p.title || p.label);

/** 레이아웃 JSON 한 개에서 이름 없는 칸과 전체 칸 수를 센다. */
function scanFile(file: string): { hits: Hit[]; controls: number; fixedRepeat: string[] } {
  const json = JSON.parse(fs.readFileSync(file, 'utf8'));
  const fors = new Set<string>();
  const parent = new Map<Json, Json>();
  // 레이아웃 반복(data-each → iteration, 끌어 정렬하는 sortable) 안 노드 — DataGrid 셀(props 안)은 따로 본다
  const inRepeat = new Set<Json>();

  // 부모 연결 — props 안의 노드(셀 렌더 자식 등)는 가상 셀 부모 아래에 둔다
  (function link(x: Json, par: Json, rep = false) {
    if (Array.isArray(x)) return x.forEach((y) => link(y, par, rep));
    if (!x || typeof x !== 'object') return;
    if (isNode(x)) {
      parent.set(x, par);
      const repeated = rep || Boolean(x.iteration) || Boolean(x.sortable);
      if (repeated) inRepeat.add(x);
      const p = x.props || {};
      if (x.name === 'Label' && (p.htmlFor || p.for)) fors.add(p.htmlFor || p.for);
      for (const [k, v] of Object.entries(x)) {
        if (k === 'children') link(v, x, repeated);
        else if (k === 'props') {
          (function inProps(y: Json) {
            if (Array.isArray(y)) return y.forEach(inProps);
            if (!y || typeof y !== 'object') return;
            if (isNode(y)) return link(y, { name: '__cell', children: [y] });
            Object.values(y).forEach(inProps);
          })(v);
        } else if (v && typeof v === 'object') link(v, par, repeated);
      }
    } else {
      Object.values(x).forEach((v) => link(v, par, rep));
    }
  })(json, null);

  const list: Json[] = [];
  (function gather(x: Json, seen: Set<Json>) {
    if (Array.isArray(x)) return x.forEach((y) => gather(y, seen));
    if (!x || typeof x !== 'object' || seen.has(x)) return;
    seen.add(x);
    if (isCtrl(x)) list.push(x);
    Object.values(x).forEach((v) => gather(v, seen));
  })(json, new Set());

  const hits: Hit[] = [];
  const fixedRepeat: string[] = [];
  for (const C of list) {
    const p = C.props || {};
    // 목록형(Select options·SearchableDropdown)은 단추라 placeholder·title 이 이름이 되지 않는다 — 값이 비면 placeholder 글자가
    // 단추 글자(=값만)로 읽힌다. id 는 바깥 상자에 붙어 label[for] 도 단추에 닿지 않는다. aria-label·aria-labelledby 만 인정한다.
    const listbox = (C.name === 'Select' && p.options) || C.name === 'SearchableDropdown';
    let named = listbox ? Boolean(p['aria-label'] || p['aria-labelledby']) : ownName(p) || (p.id && fors.has(p.id));
    // 이름 출처가 감싼 Label·곁 라벨이면 그 노드 — 반복 행에서 출처에 행 값이 있는지 본다
    let source: Json = null;
    for (let a = parent.get(C), d = 0; a && d < 30; d++, a = parent.get(a)) {
      if (a.name === 'Label') {
        named = true;
        if (!source) source = a;
      }
    }
    const checkable = C.name === 'Checkbox' || (C.name === 'Input' && /checkbox|radio/.test(String(p.type)));

    if (!named && HOOKED.has(C.name) && !checkable) {
      let anc = parent.get(C);
      let child = C;
      for (let d = 0; anc && d < 3; d++) {
        const ch = kids(anc);
        const ei = ch.indexOf(child);
        let found: number | null = null;
        let blocked = false;
        for (let i = ei - 1; i >= 0; i--) {
          const s = ch[i];
          if (isLabelSlot(s)) {
            if (!(s.props?.htmlFor || s.props?.for) && hasText(s)) { found = i; break; }
          } else if (deep(s, isCtrl)) { blocked = true; break; }
        }
        if (blocked) break;
        if (found !== null) {
          let ok = !deep(child, (x) => x !== C && isCtrl(x));
          for (let i = found + 1; ok && i < ch.length; i++) {
            if (i === ei) continue;
            if (isLabelSlot(ch[i]) && i > ei) break;
            if (deep(ch[i], isCtrl)) ok = false;
          }
          named = ok;
          if (ok) source = ch[found];
          break;
        }
        if (d === 0) {
          for (let i = ei + 1; i < ch.length; i++) {
            const s = ch[i];
            if (isLabelSlot(s) && hasText(s)) { named = true; source = s; break; }
            if (deep(s, isCtrl)) break;
          }
          if (named) break;
        }
        child = anc;
        anc = parent.get(anc);
      }
      if (!named && C.name === 'Toggle') {
        const ch = kids(parent.get(C));
        const prev = ch[ch.indexOf(C) - 1];
        if (prev && !deep(prev, isCtrl) && hasTitleText(prev)) { named = true; source = prev; }
      }
    }
    if (named) {
      // 레이아웃 반복 행 안 칸 — 이름 출처(자기 이름·placeholder·감싼 Label·곁 라벨)에 행 값(`{{…}}`)이 하나도 없으면
      // 행마다 같은 이름으로 읽힌다(판정 강화 3, 이커머스 마일리지 적립값 실측). R3 로 행 이름을 넣는다.
      if (inRepeat.has(C)) {
        const own = String(p['aria-label'] ?? p.ariaLabel ?? p['aria-labelledby'] ?? p.label ?? p.placeholder ?? p.title ?? '');
        if (!own.includes('{{') && !(source && hasExpr(source))) {
          fixedRepeat.push(`${path.relative(ROOT, file).split(path.sep).join('/')}: ${C.name}${p.type ? ':' + p.type : ''} ${String(p.name ?? p.value ?? p.checked ?? '').slice(0, 70)}`);
        }
      }
      continue;
    }

    let cat: string;
    if ((C.name === 'Select' && p.options) || C.name === 'SearchableDropdown') cat = 'A_value_only_select';
    else if (C.name === 'Select') cat = 'F_native_select';
    else if (checkable) cat = 'B_checkable';
    else if (C.name === 'Toggle') cat = 'C_toggle';
    else if (C.name === 'MultilingualInput' || C.name === 'MultilingualTagInput') cat = 'E_multilingual';
    else cat = 'D_text_number';
    hits.push({ file: path.relative(ROOT, file).split(path.sep).join('/'), cat, control: C.name + (p.type ? `:${p.type}` : ''), key: String(p.name ?? p.value ?? p.checked ?? '').slice(0, 70) });
  }

  return { hits, controls: list.length, fixedRepeat };
}

function scanAll(): { hits: Hit[]; controls: number; files: number; fixedRepeat: string[] } {
  const files = SCAN_DIRS.flatMap(jsonFiles);
  let controls = 0;
  const hits: Hit[] = [];
  const fixedRepeat: string[] = [];
  for (const f of files) {
    const r = scanFile(f);
    controls += r.controls;
    hits.push(...r.hits);
    fixedRepeat.push(...r.fixedRepeat);
  }
  return { hits, controls, files: files.length, fixedRepeat };
}

describe('관리자 템플릿 레이아웃 입력칸 이름', () => {
  const result = scanAll();

  /**
   * @scenario component=layout_lock
   * @effects controls_have_accessible_name
   */
  it('모집단이 비지 않았다(입력칸 150개 초과) — 경로가 틀려 0건으로 통과하지 않게', () => {
    expect(result.files).toBeGreaterThan(100);
    expect(result.controls).toBeGreaterThan(150);
  });

  /**
   * @scenario component=layout_lock
   * @effects controls_have_accessible_name
   */
  it('이름 없는 체크박스·스위치·글자칸이 없다(모달·탭 뒤·표 행 포함)', () => {
    const nameless = result.hits.filter((h) => h.cat !== 'A_value_only_select');
    expect(nameless, JSON.stringify(nameless, null, 1)).toEqual([]);
  });

  /**
   * @scenario component=layout_lock
   * @effects listbox_reads_name_and_value
   */
  it('목록형 선택칸(정렬·쪽당 개수·검색 대상·언어 등)이 고른 값만 읽히지 않는다', () => {
    const valueOnly = result.hits.filter((h) => h.cat === 'A_value_only_select');
    expect(valueOnly, JSON.stringify(valueOnly, null, 1)).toEqual([]);
  });

  /**
   * @scenario component=layout_lock
   * @effects row_controls_read_row_and_column
   */
  it('[반복 행 고정 이름] 레이아웃 반복(data-each·sortable) 안 칸은 이름에 행 값이 들어가 행마다 다르게 읽힌다', () => {
    expect(result.fixedRepeat, JSON.stringify(result.fixedRepeat, null, 1)).toEqual([]);
  });

  /**
   * @scenario component=data_grid
   * @effects row_controls_read_row_and_column
   */
  it('행 선택이 켜졌거나 셀에 $rowLabel 을 쓰는 DataGrid 는 rowLabelField 로 사람이 읽는 행 이름을 정한다', () => {
    // 지정하지 않으면 첫 표시 열 값이 행 이름이 된다 — 주문 목록에서 「2026-10-07T02:50:00+00:00 선택」으로 읽혔다(수정 전 실측)
    const missing: string[] = [];
    for (const file of SCAN_DIRS.flatMap(jsonFiles)) {
      const json = JSON.parse(fs.readFileSync(file, 'utf8'));
      (function find(x: Json) {
        if (Array.isArray(x)) return x.forEach(find);
        if (!x || typeof x !== 'object') return;
        if (x.name === 'DataGrid') {
          const p = x.props || {};
          const selectable = p.selectable === true || (typeof p.selectable === 'string' && p.selectable !== 'false');
          const usesRowLabel = JSON.stringify(x).includes('$rowLabel');
          if ((selectable || usesRowLabel) && !p.rowLabelField) missing.push(path.relative(ROOT, file).split(path.sep).join('/'));
        }
        Object.values(x).forEach(find);
      })(json);
    }
    expect(missing).toEqual([]);
  });

  /**
   * @scenario component=role_list_row_switch
   * @effects row_controls_read_row_and_column
   */
  it('역할 목록 행 스위치는 「{역할명} {상태 열}」으로 읽힌다', () => {
    const json = JSON.parse(fs.readFileSync(path.join(ROOT, 'layouts/admin_role_list.json'), 'utf8'));
    const toggles: Json[] = [];
    (function find(x: Json) {
      if (Array.isArray(x)) return x.forEach(find);
      if (!x || typeof x !== 'object') return;
      if (x.name === 'Toggle') toggles.push(x);
      Object.values(x).forEach(find);
    })(json);
    expect(toggles.length).toBeGreaterThan(0);
    for (const t of toggles) {
      const name = String(t.props?.ariaLabel ?? '');
      // DataGrid 셀 안이라 row 문맥이 생긴 뒤 번역해야 한다($t: 는 row 없이 먼저 풀려 행 이름이 비었다)
      expect(name).toMatch(/^\$t:defer:common\.row_cell\|/);
      expect(name).toContain('admin.roles.columns.status');
    }
  });
});
