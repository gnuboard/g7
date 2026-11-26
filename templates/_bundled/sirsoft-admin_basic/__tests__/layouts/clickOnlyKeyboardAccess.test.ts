/**
 * 관리자 템플릿과 번들 확장 레이아웃 전수 — 클릭을 받는 비대화형 노드는 키보드로도 같은 일을 할 수 있다 (설계 확정 2026-11-08)
 *
 * 클릭 동작(`<on type="click">`)만 달린 div·span·li·tr·td·img 는 Tab 이 닿지 않고 Enter·Space 로 눌리지 않는다. 사용자 템플릿은
 * `templates/_bundled/sirsoft-basic/src/__tests__/layouts/click-only-keyboard.test.tsx` 가 같은 판정을 잠근다. 이 시험은 관리자 템플릿과 모듈·플러그인
 * 레이아웃(관리자·사용자)을 본다.
 *
 * 키보드 수단으로 인정하는 갈래(그 노드에 role·tabindex·키 이벤트가 없을 때):
 *   ① 덮개 — 자식이 없고, 같은 상태를 쓰는 단추가 같은 파일에 있다(모바일 메뉴 덮개, 통화 목록 덮개)
 *   ② 라디오 — 안에 같은 일을 하는 라디오(change)가 있다(게시판 설정 적용 대상, 날짜 표시 방식 카드)
 *   ③ 펼침 단추 — 안의 `aria-expanded` 단추가 같은 상태를 쓴다
 *   ④ 대리 단추 — 안에 자기 클릭 동작 없는 `aria-expanded`·`aria-pressed` 단추가 있어 눌림이 바깥으로 올라간다
 *      (결제 실패 이력 행과 카드의 펼치기 단추)
 *   ⑤ 같은 일의 링크 — 안의 주소 링크(`A` + href)가 같은 상태를 쓴다(공통 정보, 고시 목록 카드의 이름 링크)
 *
 * 조합과 효과 표식은 그것을 재는 시험마다 단다.
 */
import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { actionsOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

type Node = Record<string, any>;

const REPO = path.resolve(__dirname, '../../../../..');

/** 스스로 키보드로 닿고 Enter·Space 로 누를 수 있는 부품 */
const KEYBOARD_NATIVE = new Set([
  'Button', 'IconButton', 'A', 'Link', 'Input', 'Select', 'Textarea', 'Checkbox', 'Toggle', 'Label', 'Form', 'Modal', 'ActionMenu',
  'DropdownButton', 'TabNavigation', 'TabNavigationScroll', 'Switch', 'RadioGroup',
]);

const isNode = (x: any): boolean =>
  Boolean(x && typeof x === 'object' && !Array.isArray(x) && typeof x.name === 'string' && (x.type || x.props || x.children));
const eventOf = (a: any): string => String(a?.event ?? a?.type ?? '').replace(/^on/i, '').toLowerCase();
const eventsOf = (n: any): string[] => actionsOf(n).map(eventOf);
const clickActionsOf = (n: any): any[] => actionsOf(n).filter((a: any) => eventOf(a) === 'click');

function collect(x: any, pred: (n: Node) => boolean, out: Node[] = []): Node[] {
  if (Array.isArray(x)) {
    x.forEach((y) => collect(y, pred, out));
    return out;
  }
  if (!x || typeof x !== 'object') return out;
  if (isNode(x) && pred(x)) out.push(x);
  Object.values(x).forEach((v) => collect(v, pred, out));
  return out;
}

/** 액션(과 그 안쪽 sequence·conditions)이 setState 로 쓰는 상태 키 */
function stateKeysOf(actions: any[]): Set<string> {
  const keys = new Set<string>();
  const walk = (a: any): void => {
    if (Array.isArray(a)) return a.forEach(walk);
    if (!a || typeof a !== 'object') return;
    if (a.handler === 'setState' && a.params) Object.keys(a.params).filter((k) => k !== 'target').forEach((k) => keys.add(k));
    Object.values(a).forEach(walk);
  };
  walk(actions);
  return keys;
}

/** 클릭을 받는 노드가 같은 일을 하는 키보드 수단을 따로 갖는지(머리 주석 ①~⑤) */
function keyboardAlternative(node: Node, fileButtons: Set<string>): string | null {
  const keys = [...stateKeysOf(clickActionsOf(node))];
  const sameKeys = (other: Node): boolean => keys.length > 0 && keys.every((k) => stateKeysOf(clickActionsOf(other)).has(k));
  if (!(node.children ?? []).length && keys.length > 0 && keys.every((k) => fileButtons.has(k))) return 'backdrop';
  if (collect(node.children, (n) => n.name === 'Input' && n.props?.type === 'radio' && eventsOf(n).includes('change')).length > 0) return 'radio';
  if (collect(node.children, (n) => n.name === 'Button' && n.props?.['aria-expanded'] !== undefined).some(sameKeys)) return 'disclosure';
  if (
    collect(
      node.children,
      (n) => n.name === 'Button' && !eventsOf(n).includes('click') && (n.props?.['aria-expanded'] !== undefined || n.props?.['aria-pressed'] !== undefined),
    ).length > 0
  ) {
    return 'proxy';
  }
  if (collect(node.children, (n) => n.name === 'A' && n.props?.href !== undefined).some(sameKeys)) return 'link';
  return null;
}

function clickOnlyNodes(root: any): string[] {
  const fileButtons = new Set<string>();
  collect(root, (n) => KEYBOARD_NATIVE.has(n.name)).forEach((b) => stateKeysOf(clickActionsOf(b)).forEach((k) => fileButtons.add(k)));
  return collect(root, (n) => eventsOf(n).includes('click') && !KEYBOARD_NATIVE.has(n.name))
    .filter((n) => !(n.props?.role && (n.props?.tabIndex ?? n.props?.tabindex) !== undefined && eventsOf(n).some((e) => e.startsWith('key'))))
    .filter((n) => keyboardAlternative(n, fileButtons) === null)
    .map((n) => `${n.name}${n.id ? `#${n.id}` : ''} .${String(n.props?.className ?? '').slice(0, 60)}`);
}

function layoutJsons(dir: string, out: string[] = []): string[] {
  if (!fs.existsSync(dir)) return out;
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) {
      if (/^(node_modules|dist|vendor|__tests__|tests)$/.test(e.name)) continue;
      layoutJsons(p, out);
    } else if (p.endsWith('.json') && /[\\/](layouts|extensions)[\\/]/.test(p)) out.push(p);
  }
  return out;
}

const roots = [
  path.join(REPO, 'templates/_bundled/sirsoft-admin_basic/layouts'),
  ...['modules/_bundled', 'plugins/_bundled'].flatMap((r) =>
    fs.readdirSync(path.join(REPO, r)).map((id) => path.join(REPO, r, id, 'resources')),
  ),
];

describe('클릭을 받는 비대화형 노드의 키보드 수단 — 관리자 템플릿과 번들 확장', () => {
  // @scenario surface=admin_and_extension_layouts
  // @effects click_only_regions_have_keyboard_path
  it('클릭만 받고 키보드로는 닿지 않는 노드가 없다(덮개, 라디오, 펼침 단추, 대리 단추, 같은 일의 링크 제외)', () => {
    const files = roots.flatMap((r) => layoutJsons(r));
    expect(files.length).toBeGreaterThan(300);
    const missing = files.flatMap((file) => {
      let json: any;
      try {
        json = JSON.parse(fs.readFileSync(file, 'utf8'));
      } catch {
        return [];
      }
      return clickOnlyNodes(json).map((s) => `${path.relative(REPO, file).split(path.sep).join('/')}: ${s}`);
    });
    expect(missing, missing.join('\n')).toEqual([]);
  });
});
