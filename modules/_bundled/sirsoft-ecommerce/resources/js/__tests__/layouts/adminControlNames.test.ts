// e2e:allow 레이아웃 정적 구조(이름 속성·감싼 Label·곁 라벨) 단언 전용 — 브라우저 축은 admin-control-names.spec.ts 와 라이브 크롤 재측정이 덮는다(시나리오 control-accessible-names.yaml).
/**
 * @file adminControlNames.test.ts
 * @description 이커머스 레이아웃·확장 조각의 입력칸이 화면 낭독기에서 이름을 갖는지 (#757)
 *
 * 관리자 화면 입력칸 일부가 이름 없이 「편집 가능 텍스트」·「체크박스」·「스위치」로만 읽히거나(제목이 표식 없는
 * span·표 열 머리라 관리자 템플릿의 곁 라벨 잇기가 닿지 않음), 목록형 선택칸이 고른 값 글자(「최신순」)만 이름으로
 * 읽혔다(#747 측정 270칸 + 전수 조사). 사용자 화면에 주입되는 조각은 사용자 템플릿에 곁 라벨 잇기가 없어
 * 레이아웃이 직접 이름을 달아야 한다.
 *
 * 판정은 관리자 템플릿의 곁 라벨 잇기 규칙을 레이아웃 JSON 트리에 옮긴 것이다 — 칸이 ① 자기 이름(aria-label·ariaLabel·
 * aria-labelledby·placeholder·title·label) ② 감싸는 Label ③ for 로 이어진 Label ④ (관리자, 체크박스·라디오 제외)
 * 곁 라벨 ⑤ (관리자 스위치) 앞 형제 행 제목 — 중 하나도 없으면 이름 없음이다. 모집단은 레이아웃·확장 조각 JSON 전체에서
 * 파생한다 — 새 화면의 칸에도 규칙이 그대로 걸린다. 확장마다 독립 패키지라 판정 함수를 공용으로 import 하지 않는다.
 *
 * 목록형 선택칸(Select options·SearchableDropdown)은 단추라 ① 중 aria-label·aria-labelledby 만 이름으로 친다 —
 * placeholder·title 은 단추에 닿지 않고, 고른 값이 없으면 placeholder 글자가 단추 글자(=값만)가 된다.
 *
 * 표·반복 행의 칸은 이름이 있어도 행마다 같은 글자면 어느 행인지 알 수 없다 — DataGrid 셀·펼침 안 칸은 행 이름을 품은
 * 이름(「{행 이름} {열 이름}」·「{행 이름} 선택」)을 단다. 셀·펼침은 DataGrid 의 props 라 DynamicRenderer 가 행 문맥 없이
 * 먼저 번역하므로, 행 값을 넘기는 이름은 `$t:defer:` 로 쓴다(행마다 그릴 때 번역).
 *
 * @scenario surface=admin_layout
 * @effects controls_have_accessible_name
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative, resolve } from 'node:path';

const ROOT = resolve(__dirname, '../../../..');

const CONTROLS = new Set([
    'Input',
    'Select',
    'Textarea',
    'Checkbox',
    'Toggle',
    'PasswordInput',
    'TagInput',
    'MultilingualInput',
    'SearchableDropdown',
    'FileInput',
    'MultilingualTagInput',
    'IconSelect',
]);

/** 관리자 템플릿이 곁 라벨을 잇는 칸 */
const HOOKED = new Set(['Input', 'Select', 'Textarea', 'Toggle', 'TagInput', 'PasswordInput']);

/** 이름이 basename 으로는 사용자 조각처럼 보이지만 관리자 화면에 주입되는 조각 */
const ADMIN_EXTENSIONS = new Set(['notification_template_form_sections.json', 'notification_template_form_footer_actions.json']);

type Side = 'admin' | 'user';

interface Hit {
    side: Side;
    category: string;
    file: string;
    control: string;
}

/**
 * 디렉터리 아래 JSON 경로를 모읍니다.
 *
 * @param dir 시작 디렉터리
 * @return JSON 경로 목록
 */
function jsonFiles(dir: string): string[] {
    return readdirSync(dir).flatMap((entry) => {
        const path = join(dir, entry);
        if (statSync(path).isDirectory()) return jsonFiles(path);

        return path.endsWith('.json') ? [path] : [];
    });
}

/**
 * 파일이 관리자 화면인지 사용자 화면인지 가릅니다.
 *
 * @param rel resources 기준 상대 경로
 * @return 화면 쪽
 */
function sideOf(rel: string): Side {
    const segments = rel.split('/');
    const base = segments[segments.length - 1];
    if (ADMIN_EXTENSIONS.has(base) || segments.includes('admin') || /^(admin|plugin_settings|module_settings)/.test(base)) {
        return 'admin';
    }

    return 'user';
}

const isNode = (x: any): boolean =>
    Boolean(x && typeof x === 'object' && !Array.isArray(x) && typeof x.name === 'string' && (x.type || x.props || x.children));
const classOf = (n: any): string => String(n?.props?.className ?? '');
const isControl = (n: any): boolean =>
    isNode(n) && CONTROLS.has(n.name) && n.props?.type !== 'hidden' && !/\bsr-only\b/.test(classOf(n));
const isLabelEl = (n: any): boolean =>
    isNode(n) && (n.name === 'Label' || /\b(form-label|filter-label|text-heading)\b/.test(classOf(n)));
const kids = (n: any): any[] => (Array.isArray(n?.children) ? n.children.filter(isNode) : []);
const deep = (n: any, pred: (x: any) => boolean): boolean => isNode(n) && (pred(n) || kids(n).some((c) => deep(c, pred)));
const hasText = (n: any): boolean =>
    deep(
        n,
        (x) =>
            (typeof x.text === 'string' && x.text.trim() !== '') ||
            (typeof x.props?.text === 'string' && x.props.text.trim() !== ''),
    );
/** 제목으로 칠 글자 — 번역 키나 고정 글자가 있어야 한다. 식 하나뿐인 글자(값)는 제목이 아니다 */
const isTitleString = (t: unknown): boolean =>
    typeof t === 'string' && t.trim() !== '' && !/^\{\{[\s\S]*\}\}$/.test(t.trim());
const hasTitleText = (n: any): boolean => deep(n, (x) => isTitleString(x.text) || isTitleString(x.props?.text));
const isLabelSlot = (n: any): boolean => isLabelEl(n) || (!deep(n, isControl) && deep(n, isLabelEl));
const ownName = (p: Record<string, any>): boolean =>
    Boolean(p['aria-label'] || p.ariaLabel || p['aria-labelledby'] || p.placeholder || p.title || p.label);
const isCheckable = (n: any): boolean =>
    n.name === 'Checkbox' || (n.name === 'Input' && /checkbox|radio/.test(String(n.props?.type)));
const isListbox = (n: any): boolean => (n.name === 'Select' && Boolean(n.props?.options)) || n.name === 'SearchableDropdown';
/**
 * 목록형 선택칸(단추)의 자기 이름 — aria-label·aria-labelledby 만 인정한다. placeholder·title·label 은 단추에 닿지 않고,
 * 고른 값이 없으면 placeholder 글자가 단추 글자(=값만)가 된다(판정식 보강, 브라우저 실측).
 */
const listboxOwnName = (p: Record<string, any>): boolean => Boolean(p['aria-label'] || p['aria-labelledby']);

/**
 * 레이아웃 하나에서 칸과 부모 관계를 모읍니다. props 안에 든 노드(셀 렌더 조각)는 부모 없는 셀에서 시작한다.
 *
 * @param root 레이아웃 JSON
 * @return 칸 목록·부모 표·for 대상·DataGrid 셀 안 칸
 */
function collect(root: any): {
    controls: any[];
    parent: WeakMap<object, any>;
    fors: Set<string>;
    inGrid: WeakSet<object>;
    inRepeat: WeakSet<object>;
} {
    const parent = new WeakMap<object, any>();
    const fors = new Set<string>();
    const controls: any[] = [];
    const inGrid = new WeakSet<object>();
    // 레이아웃 반복(`iteration` — data-each, `sortable` — 끌어 정렬하는 반복) 안 노드
    const inRepeat = new WeakSet<object>();
    const seen = new WeakSet<object>();

    const visit = (x: any, par: any, grid: boolean, rep = false): void => {
        if (Array.isArray(x)) return x.forEach((y) => visit(y, par, grid, rep));
        if (!x || typeof x !== 'object' || seen.has(x)) return;
        seen.add(x);
        if (!isNode(x)) {
            Object.values(x).forEach((v) => visit(v, par, grid, rep));

            return;
        }
        if (par) parent.set(x, par);
        if (grid) inGrid.add(x);
        const repeated = rep || Boolean(x.iteration) || Boolean(x.sortable);
        if (repeated) inRepeat.add(x);
        const p = x.props ?? {};
        if (x.name === 'Label' && (p.htmlFor || p.for)) fors.add(p.htmlFor || p.for);
        if (isControl(x)) controls.push(x);
        for (const [k, v] of Object.entries(x)) {
            if (k === 'children') visit(v, x, grid, repeated);
            else if (k === 'props') {
                const cellGrid = grid || x.name === 'DataGrid';
                const props = (y: any): void => {
                    if (Array.isArray(y)) return y.forEach(props);
                    if (!y || typeof y !== 'object') return;
                    if (isNode(y)) return visit(y, { name: '__cell', children: [y] }, cellGrid);
                    Object.values(y).forEach(props);
                };
                props(v);
            } else if (v && typeof v === 'object') visit(v, par, grid, repeated);
        }
    };
    visit(root, null, false);

    return { controls, parent, fors, inGrid, inRepeat };
}

/**
 * 관리자 템플릿 곁 라벨 잇기 규칙으로 이름이 붙는지 봅니다(조상 3단계, 라벨 뒤 범위에 다른 칸이 있으면 잇지 않음).
 *
 * @param C 칸
 * @param parent 부모 표
 * @return 이름이 되는 노드(곁 라벨 자리·앞 형제), 없으면 null
 */
function hookNamed(C: any, parent: WeakMap<object, any>): any {
    const adjacent = adjacentLabelNamed(C, parent);
    if (adjacent) return adjacent;
    if (C.name !== 'Toggle') return null;
    // 스위치는 곁 라벨이 없어도 앞 형제의 행 제목을 이름으로 읽는다
    const ch = kids(parent.get(C));
    const prev = ch[ch.indexOf(C) - 1];

    // 단, 글자가 식 하나뿐(`{{template.fields_count}}`)이면 제목이 아니라 값이다 — 상품정보고시 목록 스위치가 항목 수
    // 배지를 이름으로 삼아 「9」「10」 으로 읽혔다(수정 전 브라우저 실측). 그런 스위치는 자기 이름(ariaLabel)을 단다.
    return prev && !deep(prev, isControl) && hasTitleText(prev) ? prev : null;
}

/**
 * 곁 라벨 하나를 찾아 잇는지 봅니다 — 앞쪽 라벨(뒤 범위에 다른 칸이 없을 때) 또는 바로 뒤 라벨.
 *
 * @param C 칸
 * @param parent 부모 표
 * @return 이어지는 라벨 자리, 없으면 null
 */
function adjacentLabelNamed(C: any, parent: WeakMap<object, any>): any {
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
                if (!(s.props?.htmlFor || s.props?.for) && hasText(s)) {
                    found = i;
                    break;
                }
            } else if (deep(s, isControl)) {
                blocked = true;
                break;
            }
        }
        if (blocked) return null;
        if (found !== null) {
            let ok = !deep(child, (x) => x !== C && isControl(x));
            for (let i = found + 1; ok && i < ch.length; i++) {
                if (i === ei) continue;
                if (isLabelSlot(ch[i]) && i > ei) break;
                if (deep(ch[i], isControl)) ok = false;
            }

            return ok ? ch[found] : null;
        }
        if (d === 0) {
            for (let i = ei + 1; i < ch.length; i++) {
                const s = ch[i];
                if (isLabelSlot(s) && hasText(s)) return s;
                if (deep(s, isControl)) break;
            }
        }
        child = anc;
        anc = parent.get(anc);
    }

    return null;
}

/**
 * 칸을 감싼 Label 의 글자에 행 값(`{{…}}`)이 있는지 봅니다 — 「{{option.label}}」 처럼 행마다 다른 글자면 이름도 다르다.
 *
 * @param C 칸
 * @param parent 부모 표
 * @return 행 값이 든 Label 로 감싸였는지
 */
function dynamicLabelAncestor(C: any, parent: WeakMap<object, any>): boolean {
    for (let a = parent.get(C); a; a = parent.get(a)) {
        if (a.name === 'Label') return deep(a, (x) => /\{\{/.test(String(x.text ?? '')) || /\{\{/.test(String(x.props?.text ?? '')));
        if (a.iteration) return false;
    }

    return false;
}

/**
 * 작성자 메뉴(ActionMenu) 트리거 안의 아바타 첫 글자(`charAt(0)`)가 이름에 섞이는 자리를 모읍니다.
 *
 * 트리거는 자식 글자 전체가 단추 이름이 되므로, 아바타 동그라미의 첫 글자가 이름 앞에 붙어 「관관리자」 로 읽혔다
 * (수정 후 브라우저 실측). 장식 글자는 `aria-hidden="true"` 로 이름에서 뺀다.
 *
 * @param root 레이아웃 JSON
 * @return 숨기지 않은 아바타 글자 노드의 글자
 */
function exposedAvatarLetters(root: any): string[] {
    const out: string[] = [];
    const walk = (n: any, inMenu: boolean): void => {
        if (Array.isArray(n)) return n.forEach((x) => walk(x, inMenu));
        if (!n || typeof n !== 'object') return;
        const menu = inMenu || n.name === 'ActionMenu';
        const text = String(n.text ?? n.props?.text ?? '');
        if (menu && /charAt\(0\)/.test(text) &&String(n.props?.['aria-hidden']) !== 'true') out.push(text.slice(0, 60));
        Object.values(n).forEach((v) => walk(v, menu));
    };
    walk(root, false);

    return out;
}

/**
 * 레이아웃 안의 DataGrid 노드를 모읍니다(props 안 노드 포함).
 *
 * @param root 레이아웃 JSON
 * @return DataGrid 노드 목록
 */
function dataGrids(root: any): any[] {
    const out: any[] = [];
    const walk = (n: any): void => {
        if (Array.isArray(n)) return n.forEach(walk);
        if (!n || typeof n !== 'object') return;
        if (n.name === 'DataGrid') out.push(n);
        Object.values(n).forEach(walk);
    };
    walk(root);

    return out;
}

/**
 * 칸의 유형을 가릅니다.
 *
 * @param C 칸
 * @param side 화면 쪽
 * @return 유형
 */
function categoryOf(C: any, side: Side): string {
    if (isListbox(C)) return side === 'admin' ? 'A_value_only_select' : 'F_native_select';
    if (C.name === 'Select') return 'F_native_select';
    if (isCheckable(C)) return 'B_checkable';
    if (C.name === 'Toggle') return 'C_toggle';
    if (C.name === 'MultilingualInput' || C.name === 'MultilingualTagInput') return 'E_multilingual';

    return 'D_text_number';
}

/**
 * 이커머스 레이아웃·확장 조각 전체를 판정합니다.
 *
 * @return 칸 수·이름 없는 칸·이름이 행을 가리키지 않는 DataGrid 셀 칸
 */
function scan(): {
    total: Record<Side, number>;
    hits: Hit[];
    rowless: string[];
    gridsWithoutRowLabel: string[];
    avatarLetters: string[];
} {
    const files = ['resources/layouts', 'resources/extensions'].flatMap((dir) => jsonFiles(join(ROOT, dir)));
    const total: Record<Side, number> = { admin: 0, user: 0 };
    const hits: Hit[] = [];
    const rowless: string[] = [];
    const gridsWithoutRowLabel: string[] = [];
    const avatarLetters: string[] = [];

    for (const path of files) {
        const rel = relative(join(ROOT, 'resources'), path).replace(/\\/g, '/');
        const side = sideOf(rel);
        const root = JSON.parse(readFileSync(path, 'utf-8'));
        const { controls, parent, fors, inGrid, inRepeat } = collect(root);
        exposedAvatarLetters(root).forEach((t) => avatarLetters.push(`${rel}: ${t}`));
        dataGrids(root).forEach((g) => {
            const p = g.props ?? {};
            const selectable = p.selectable !== undefined && p.selectable !== false;
            if ((selectable || JSON.stringify(p).includes('$rowLabel')) && !p.rowLabelField) {
                gridsWithoutRowLabel.push(`${rel}: DataGrid ${g.id ?? ''}`);
            }
        });
        for (const C of controls) {
            total[side]++;
            const p = C.props ?? {};
            const label = `${rel}: ${C.name}${p.type ? `:${p.type}` : ''} ${String(p.name ?? p.value ?? p.checked ?? '').slice(0, 70)}`;
            const listbox = isListbox(C);
            let named = listbox ? listboxOwnName(p) : ownName(p) || Boolean(p.id && fors.has(p.id));
            // 판정 강화분 표시 — 옛 판정(placeholder·title·label·label[for])이라면 이름으로 쳤을 목록형 칸
            const strict = listbox && !named && (ownName(p) || Boolean(p.id && fors.has(p.id)));
            for (let a = parent.get(C), d = 0; !named && a && d < 30; d++, a = parent.get(a)) {
                if (a.name === 'Label') named = true;
            }
            let hookSource: any = null;
            if (!named && side === 'admin' && HOOKED.has(C.name) && !isCheckable(C)) {
                hookSource = hookNamed(C, parent);
                named = Boolean(hookSource);
            }
            if (!named) {
                hits.push({ side, category: categoryOf(C, side), file: rel, control: strict ? `[판정강화] ${label}` : label });
                continue;
            }
            if (inGrid.has(C)) {
                const name = String(p['aria-label'] ?? p.ariaLabel ?? p['aria-labelledby'] ?? p.label ?? '');
                if (!/row/i.test(name)) rowless.push(label);
                // 셀·펼침 안은 DynamicRenderer 가 행 문맥 없이 props 를 먼저 번역한다 — 행 값을 넘기는 이름은 $t:defer:
                else if (/^\$t:(?!defer:)/.test(name) && name.includes('{{')) rowless.push(`[$t:defer 아님] ${label}`);
            } else if (inRepeat.has(C)) {
                // 레이아웃이 반복(data-each)으로 직접 그리는 행도 같다 — 자기 이름이 고정 문구면 행마다 같은 이름으로
                // 읽힌다(상품 옵션 표 마일리지·추가옵션 선택지 행, 수정 후 브라우저 실측). 행 값을 품은 이름(`{{…}}`)을 단다.
                // 이름 출처(자기 이름·감싼 Label·곁 라벨 어느 것이든)에 행 값(`{{…}}`)이 없으면 행마다 같은 이름이다.
                const own = String(p['aria-label'] ?? p.ariaLabel ?? p['aria-labelledby'] ?? p.label ?? p.placeholder ?? p.title ?? '');
                const dynamicHook = hookSource !== null && deep(hookSource, (x) => /{{/.test(String(x.text ?? '')) || /{{/.test(String(x.props?.text ?? '')));
                if (!own.includes('{{') && !dynamicLabelAncestor(C, parent) && !dynamicHook) rowless.push(`[반복 행 고정 이름] ${label}`);
            }
        }
    }

    return { total, hits, rowless, gridsWithoutRowLabel, avatarLetters };
}

const result = scan();
const list = (pred: (h: Hit) => boolean): string[] => result.hits.filter(pred).map((h) => `${h.category} ${h.control}`);

describe('이커머스 입력칸 이름 (#757)', () => {
    // @scenario surface=admin_layout
    // @effects controls_have_accessible_name
    it('모집단이 레이아웃·확장 조각 전체에서 파생된다', () => {
        expect(result.total.admin).toBeGreaterThan(1000);
        expect(result.total.user).toBeGreaterThan(5);
    });

    // @scenario surface=admin_layout
    // @effects controls_have_accessible_name
    it('관리자 화면의 글자·숫자칸·체크박스·스위치·다국어칸·기본 선택칸은 이름이 있다', () => {
        const missing = list((h) => h.side === 'admin' && h.category !== 'A_value_only_select');
        expect(missing, missing.join('\n')).toEqual([]);
    });

    // @scenario surface=admin_layout
    // @effects listbox_reads_name_and_value
    it('관리자 화면의 목록형 선택칸은 고른 값만이 아니라 이름(aria-label·aria-labelledby·곁 라벨)을 갖는다', () => {
        const missing = list((h) => h.side === 'admin' && h.category === 'A_value_only_select');
        expect(missing, missing.join('\n')).toEqual([]);
    });

    // @scenario surface=user_extension
    // @effects controls_have_accessible_name
    it('사용자 화면에 주입되는 조각의 칸은 곁 라벨 없이 자기 이름을 갖는다', () => {
        const missing = list((h) => h.side === 'user');
        expect(missing, missing.join('\n')).toEqual([]);
    });

    // @scenario surface=admin_layout
    // @effects controls_have_accessible_name
    it('작성자 메뉴 단추 안 아바타 첫 글자는 이름에서 뺀다(aria-hidden)', () => {
        const exposed = result.avatarLetters;
        expect(exposed, exposed.join('\n')).toEqual([]);
    });

    // @scenario surface=admin_row
    // @effects row_controls_read_row_and_column
    it('행 선택·행 이름을 쓰는 DataGrid 는 화면에 보이는 식별자를 행 이름(rowLabelField)으로 정한다', () => {
        // 정하지 않으면 첫 열 값이 행 이름이 된다 — 첫 열이 일시면 「2026-10-07T02:50:00+00:00 선택」 으로 읽힌다(브라우저 실측)
        const missing = result.gridsWithoutRowLabel;
        expect(missing, missing.join('\n')).toEqual([]);
    });

    // @scenario surface=admin_row
    // @effects row_controls_read_row_and_column
    it('DataGrid 셀 안 칸의 이름은 행을 가리킨다(「{행 이름} {열 이름}」·「{행 이름} 선택」)', () => {
        const missing = result.rowless;
        expect(missing, missing.join('\n')).toEqual([]);
    });
});
