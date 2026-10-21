/**
 * @file control-accessible-names.test.ts
 * @description 사용자 템플릿 레이아웃의 입력칸이 모두 접근 가능한 이름을 갖는다 (#757)
 *
 * 검증 방식: 레이아웃 굽기 산출물(JSON) 전수의 정적 판정(DOM 비의존).
 *
 * 결함: 마이페이지 필터 선택칸·목록 체크박스, 주문서 쿠폰·환불 은행·배송 국가·배송 메모 선택칸,
 *   회원정보 수정 국가·언어·시간대 선택칸, 교환 재배송지 입력칸 등이 화면 낭독기에서 이름 없이
 *   「콤보 상자」「체크박스」「편집 가능 텍스트」로만 읽혔다.
 *
 * 판정 규칙(관리자 템플릿 잠금 시험과 같은 판정식에서 곁 라벨 연결만 뺀 판):
 *   - 대상 칸: Input·Select·Textarea·Checkbox·Toggle·PasswordInput·TagInput·MultilingualInput·
 *     SearchableDropdown·FileInput·MultilingualTagInput·IconSelect (type=hidden·sr-only 제외)
 *   - 이름이 있다 = ① 자기 이름 prop(aria-label·ariaLabel·aria-labelledby·placeholder·title·label)
 *     ② 조상 Label ③ `for`/`htmlFor` 로 이어진 Label — 중 하나
 *   - 사용자 템플릿에는 곁 라벨 자동 연결 훅이 없다. 칸 옆에 글자가 보여도 이름이 되지 않으므로 인정하지 않는다.
 *   - 목록형 Select(options 모드)는 ① 중 aria-label·aria-labelledby 만 인정한다 — placeholder·title 은 단추에 닿지 않는다
 *     (상품 옵션 선택칸 6곳이 placeholder 만 있어 고른 값만 읽히던 같은 결함군).
 *
 * 모집단: layouts/**, extensions/** 의 굽기 산출물 JSON 전부(새 화면도 자동으로 잠긴다).
 *
 * @scenario-manifest tests/scenarios/control-accessible-names.yaml
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const EXT_ROOT = path.resolve(__dirname, '../..');

type Node = Record<string, any>;

const CTRL = new Set([
  'Input', 'Select', 'Textarea', 'Checkbox', 'Toggle', 'PasswordInput', 'TagInput',
  'MultilingualInput', 'SearchableDropdown', 'FileInput', 'MultilingualTagInput', 'IconSelect',
]);

const walk = (dir: string, out: string[] = []): string[] => {
  if (!fs.existsSync(dir)) return out;
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) walk(p, out);
    else if (p.endsWith('.json')) out.push(p);
  }

  return out;
};

const FILES = [...walk(path.join(EXT_ROOT, 'layouts')), ...walk(path.join(EXT_ROOT, 'extensions'))];

const isNode = (x: unknown): x is Node =>
  !!x && typeof x === 'object' && !Array.isArray(x) && typeof (x as Node).name === 'string'
  && Boolean((x as Node).type || (x as Node).props || (x as Node).children);

const isCtrl = (n: Node): boolean =>
  CTRL.has(n.name) && n.props?.type !== 'hidden' && !/\bsr-only\b/.test(String(n.props?.className ?? ''));

/**
 * 목록형 Select(options 모드)는 커스텀 단추를 그린다 — 컴포넌트가 단추에 잇는 것은 aria-label·aria-labelledby 뿐이고
 * placeholder·title·label 은 단추 이름이 되지 않는다(바깥 상자 속성으로 새거나 버려진다).
 */
const ownName = (n: Node): boolean => {
  const p = n.props ?? {};
  if (n.name === 'Select' && p.options !== undefined) return Boolean(p['aria-label'] || p['aria-labelledby']);

  return Boolean(p['aria-label'] || p.ariaLabel || p['aria-labelledby'] || p.placeholder || p.title || p.label);
};

interface Hit { file: string; ctl: string; key: string }

/**
 * 레이아웃 하나에서 칸 목록과 이름 없는 칸을 센다.
 * props 안에 든 노드(셀 렌더 등)도 칸으로 본다 — 그 조상은 props 를 가진 노드다.
 */
const scan = (file: string): { total: number; hits: Hit[] } => {
  const json = JSON.parse(fs.readFileSync(file, 'utf8'));
  const parent = new Map<Node, Node | null>();
  const fors = new Set<string>();
  const ctrls: Node[] = [];

  const visit = (x: unknown, par: Node | null): void => {
    if (Array.isArray(x)) { x.forEach((y) => visit(y, par)); return; }
    if (!x || typeof x !== 'object') return;
    if (!isNode(x)) { Object.values(x).forEach((v) => visit(v, par)); return; }
    if (parent.has(x)) return;
    parent.set(x, par);
    const p = x.props ?? {};
    if (x.name === 'Label' && (p.htmlFor || p.for)) fors.add(String(p.htmlFor || p.for));
    if (isCtrl(x)) ctrls.push(x);
    for (const [k, v] of Object.entries(x)) {
      if (v && typeof v === 'object') visit(v, k === 'children' || k === 'props' ? x : par);
    }
  };
  visit(json, null);

  const rel = path.relative(EXT_ROOT, file).split(path.sep).join('/');
  const hits: Hit[] = [];
  for (const c of ctrls) {
    const p = c.props ?? {};
    let named = ownName(c) || (p.id !== undefined && fors.has(String(p.id)));
    for (let a = parent.get(c) ?? null; !named && a; a = parent.get(a) ?? null) {
      if (a.name === 'Label') named = true;
    }
    if (!named) {
      hits.push({ file: rel, ctl: c.name + (p.type ? `:${p.type}` : ''), key: String(p.name ?? p.value ?? p.checked ?? '').slice(0, 70) });
    }
  }

  return { total: ctrls.length, hits };
};

describe('사용자 템플릿 — 입력칸 접근 가능한 이름 (#757)', () => {
  const results = FILES.map(scan);
  const total = results.reduce((s, r) => s + r.total, 0);
  const hits = results.flatMap((r) => r.hits);

  /**
   * 모집단이 비면 판정이 공허하게 통과한다 — 레이아웃 경로가 바뀌어도 잡히게 크기를 단언한다.
   *
   * @scenario surface=user_layout
   * @effects controls_have_accessible_name
   */
  it('모집단(레이아웃 JSON·입력칸)이 비어 있지 않다', () => {
    expect(FILES.length).toBeGreaterThan(150);
    expect(total).toBeGreaterThan(200);
  });

  /**
   * 정렬·검색 대상·분류 선택칸도 이 판정에 든다 — 이름이 있으면 「이름 + 고른 값」으로 읽힌다.
   *
   * @scenario surface=user_layout
   * @effects controls_have_accessible_name, listbox_reads_name_and_value
   */
  it('레이아웃의 이름 없는 입력칸이 0 개다(곁 글자는 이름으로 치지 않는다)', () => {
    const layoutHits = hits.filter((h) => h.file.startsWith('layouts/'));
    expect(layoutHits.map((h) => `${h.file} ${h.ctl} ${h.key}`)).toEqual([]);
  });

  /**
   * @scenario surface=user_extension
   * @effects controls_have_accessible_name
   */
  it('다른 확장 화면에 끼우는 조각(extensions)의 이름 없는 입력칸이 0 개다', () => {
    const extensionHits = hits.filter((h) => h.file.startsWith('extensions/'));
    expect(extensionHits.map((h) => `${h.file} ${h.ctl} ${h.key}`)).toEqual([]);
  });

  /**
   * placeholder 는 입력하면 사라지는 안내라 이름 대신이 될 수 없다 — 칸 바로 앞에 보이는 제목(`$t:` 키 하나)이 있으면
   * 그 키를 aria-label 로 단다. 회원정보 수정·가입·로그인·주문서·배송지 등 60칸이 제목을 두고도
   * placeholder 로만 읽혔다(수정 전 62 — 그중 2는 제목 자리에 오류 문구가 있는 칸이라 대상 아님).
   *
   * @scenario surface=user_layout
   * @effects controls_have_accessible_name
   */
  it('보이는 제목이 있는데 placeholder 로만 이름이 붙은 칸이 0 개다', () => {
    const textOf = (n: Node): string =>
      [typeof n.text === 'string' ? n.text : '', ...(Array.isArray(n.children) ? n.children.filter(isNode).map(textOf) : [])].join(' ').trim();
    const holdsCtrl = (n: Node): boolean => isCtrl(n) || (Array.isArray(n.children) && n.children.filter(isNode).some(holdsCtrl));
    const misses: string[] = [];
    for (const file of FILES) {
      const rel = path.relative(EXT_ROOT, file).split(path.sep).join('/');
      (function visit(o: unknown, parent: Node | null, inLabel: boolean): void {
        if (Array.isArray(o)) { o.forEach((x) => visit(x, parent, inLabel)); return; }
        if (!o || typeof o !== 'object') return;
        if (!isNode(o)) { Object.values(o).forEach((v) => visit(v, parent, inLabel)); return; }
        const p = o.props ?? {};
        if (isCtrl(o) && p.placeholder && !p['aria-label'] && !p['aria-labelledby'] && !p.title && !inLabel) {
          const sibs = (parent?.children ?? []).filter(isNode);
          for (let k = sibs.indexOf(o) - 1; k >= 0; k--) {
            if (holdsCtrl(sibs[k])) break;
            const title = textOf(sibs[k]);
            if (title) {
              if (/^\$t:[a-z0-9_.]+( \*)?$/.test(title)) misses.push(`${rel} ${o.name} ${p.name ?? ''} — 제목 ${title}`);
              break;
            }
          }
        }
        for (const [k, v] of Object.entries(o)) {
          if (v && typeof v === 'object') visit(v, k === 'children' ? o : parent, inLabel || o.name === 'Label');
        }
      })(JSON.parse(fs.readFileSync(file, 'utf8')), null, false);
    }
    expect(misses).toEqual([]);
  });

  /**
   * 제목이 칸 바로 앞 형제가 아니라 감싼 줄의 앞(「주소」 제목 → 우편번호 줄 → 주소 칸)에 있거나 칸 옆에 단추가 붙어
   * 한 줄로 묶인 칸은 위 판정이 보지 못한다. 주문서의 할인코드·우편번호·주소 검색 칸과 배송지 추가·변경 모달의
   * 우편번호·주소 칸이 보이는 제목을 두고 placeholder(「할인코드를 입력하세요」「우편번호」「주소를 검색하세요」)로만
   * 읽혔다(7.1.1). 같은 묶음의 해외 주소 칸도 함께 잠근다.
   *
   * @scenario surface=user_layout
   * @effects controls_have_accessible_name
   */
  it.each([
    ['layouts/partials/shop/_checkout_discount.json', { discount_code: 'shop.checkout.discount_code' }],
    ['layouts/partials/shop/_checkout_shipping.json', {
      zipcode: 'shop.checkout.zipcode', address: 'shop.checkout.address', address_line_1: 'shop.checkout.intl_address',
      address_line_2: 'shop.checkout.detail_address', shipping_memo_custom: 'shop.checkout.shipping_memo',
    }],
    ['layouts/partials/shop/_modal_address_manage.json', {
      zipcode: 'shop.checkout.zipcode', address: 'shop.checkout.address', address_line_1: 'shop.checkout.intl_address',
      address_line_2: 'shop.checkout.detail_address', intl_city: 'shop.checkout.intl_city', intl_state: 'shop.checkout.intl_state',
      intl_postal_code: 'shop.checkout.intl_postal_code',
    }],
    ['layouts/partials/mypage/addresses/_modal_address.json', {
      zipcode: 'shop.checkout.zipcode', address: 'shop.checkout.address', address_line_2: 'shop.checkout.detail_address',
      intl_city: 'shop.checkout.intl_city', intl_state: 'shop.checkout.intl_state', intl_postal_code: 'shop.checkout.intl_postal_code',
    }],
    ['layouts/partials/mypage/orders/_modal_change_address.json', { zipcode: 'shop.checkout.zipcode', address: 'shop.checkout.address' }],
  ] as Array<[string, Record<string, string>]>)('%s 주소·할인코드 칸은 보이는 제목 키를 aria-label 로 단다', (file, expected) => {
    const json = JSON.parse(fs.readFileSync(path.join(EXT_ROOT, file), 'utf8'));
    const found: Record<string, string> = {};
    (function visit(o: unknown): void {
      if (Array.isArray(o)) { o.forEach(visit); return; }
      if (!o || typeof o !== 'object') return;
      const n = o as Node;
      if (isNode(n) && n.name === 'Input' && typeof n.props?.name === 'string' && n.props.name in expected) {
        found[n.props.name] = String(n.props['aria-label'] ?? '');
      }
      Object.values(n).forEach(visit);
    })(json);
    expect(found).toEqual(Object.fromEntries(Object.entries(expected).map(([k, v]) => [k, `$t:${v}`])));
  });

  /**
   * 위에서 aria-label 로 쓰는 키가 세 언어에 모두 있어야 원문 키가 읽히지 않는다.
   *
   * @scenario surface=user_layout
   * @effects controls_have_accessible_name
   */
  it.each(['zipcode', 'address', 'discount_code', 'detail_address', 'intl_address', 'intl_city', 'intl_state', 'intl_postal_code', 'shipping_memo'])(
    '주문서 키 shop.checkout.%s 가 ko·en·ja 에 있다',
    (key) => {
      const files = [
        path.join(EXT_ROOT, 'lang/partial/ko/shop.json'),
        path.join(EXT_ROOT, 'lang/partial/en/shop.json'),
        path.resolve(EXT_ROOT, '../../../lang-packs/_bundled/g7-template-sirsoft-basic-ja/frontend/partial/shop.json'),
      ];
      for (const f of files) {
        const dict = JSON.parse(fs.readFileSync(f, 'utf8'));
        expect(typeof dict.checkout?.[key], `${path.basename(path.dirname(f))} checkout.${key}`).toBe('string');
      }
    },
  );

  /**
   * 레이아웃이 반복(data-each)으로 그리는 행 안 칸은 이름에 행 값(`{{…}}`)이 있어야 한다 — 고정 문구면 행마다 같은 이름으로
   * 읽혀 어느 행 칸인지 모른다. 이름 출처는 자기 이름 속성 · 감싼 Label 글자 · `for` 로 이어진 Label 글자 중 하나.
   * 글자 없는 Label 은 이름이 아니다(주문 상세 품목 체크박스가 그랬다).
   * 수정 전 판정 강화분 6: 추가옵션 직접입력 3(상품 상세·장바구니 옵션 변경·교환 상품 변경) · 문의 답변 입력 2 · 주문 상세 품목 체크박스 1.
   *
   * @scenario surface=user_layout
   * @effects row_controls_read_row_and_column
   */
  it('반복 행 안 칸의 이름에 행 값이 들어 있다(행마다 같은 이름 0)', () => {
    const textOf = (n: Node): string =>
      [typeof n.text === 'string' ? n.text : '', ...(Array.isArray(n.children) ? n.children.filter(isNode).map(textOf) : [])].join(' ');
    const rowless: string[] = [];
    for (const file of FILES) {
      const rel = path.relative(EXT_ROOT, file).split(path.sep).join('/');
      const json = JSON.parse(fs.readFileSync(file, 'utf8'));
      const forText = new Map<string, string>();
      (function labels(o: unknown): void {
        if (Array.isArray(o)) { o.forEach(labels); return; }
        if (!o || typeof o !== 'object') return;
        const n = o as Node;
        if (isNode(n) && n.name === 'Label' && (n.props?.htmlFor || n.props?.for)) {
          forText.set(String(n.props.htmlFor || n.props.for), (forText.get(String(n.props.htmlFor || n.props.for)) ?? '') + ' ' + textOf(n));
        }
        Object.values(n).forEach(labels);
      })(json);
      (function visit(o: unknown, inRow: boolean, labelText: string): void {
        if (Array.isArray(o)) { o.forEach((x) => visit(x, inRow, labelText)); return; }
        if (!o || typeof o !== 'object') return;
        if (!isNode(o)) { Object.values(o).forEach((v) => visit(v, inRow, labelText)); return; }
        const row = inRow || !!o.iteration;
        const text = o.name === 'Label' ? `${labelText} ${textOf(o)}` : labelText;
        const p = o.props ?? {};
        if (row && isCtrl(o)) {
          const own = String(p['aria-label'] ?? p.ariaLabel ?? p['aria-labelledby'] ?? p.label ?? p.placeholder ?? p.title ?? '');
          const linked = p.id !== undefined ? forText.get(String(p.id)) ?? '' : '';
          if (![own, text, linked].some((s) => s.includes('{{'))) rowless.push(`${rel} ${o.name}${p.type ? ':' + p.type : ''} ${p.name ?? ''}`);
        }
        for (const v of Object.values(o)) if (v && typeof v === 'object') visit(v, row, text);
      })(json, false, '');
    }
    expect(rowless).toEqual([]);
  });

  /**
   * 행 이름·번호 대체·정렬·검색 대상 이름에 쓰는 공용 키가 세 언어에 모두 있어야 원문 키가 읽히지 않는다.
   *
   * @scenario surface=user_layout
   * @effects row_controls_read_row_and_column
   */
  it.each(['row_cell', 'row_n', 'select_row_n', 'select_item', 'sort_order', 'per_page', 'search_field', 'field_in_language'])(
    '공용 키 common.%s 가 ko·en·ja 에 있다',
    (key) => {
      const files = [
        path.join(EXT_ROOT, 'lang/partial/ko/common.json'),
        path.join(EXT_ROOT, 'lang/partial/en/common.json'),
        path.resolve(EXT_ROOT, '../../../lang-packs/_bundled/g7-template-sirsoft-basic-ja/frontend/partial/common.json'),
      ];
      for (const f of files) {
        const dict = JSON.parse(fs.readFileSync(f, 'utf8'));
        expect(typeof dict[key], `${path.basename(path.dirname(f))} ${key}`).toBe('string');
      }
    },
  );
});
