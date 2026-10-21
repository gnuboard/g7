/**
 * 플러그인 sirsoft-daum_postcode 레이아웃 — 입력칸 접근 가능한 이름 잠금 시험 (#757)
 *
 * @description
 * 화면 낭독기가 입력칸을 이름 없이 「편집 가능 텍스트」·「체크박스」·「스위치」로만 읽거나,
 * 목록형 선택칸을 고른 값(「최신순」)으로만 읽던 결함을 레이아웃 전체에서 규칙으로 잠근다.
 * 검증 방식: 굽기 산출물(JSON) 전수의 정적 판정(DOM 비의존). 새 화면도 자동으로 잠긴다.
 *
 * 판정(관리자 곁 라벨 연결 훅 규칙을 JSON 트리에 옮긴 판):
 *   - 대상 칸: Input·Select·Textarea·Checkbox·Toggle·PasswordInput·TagInput·MultilingualInput·
 *     SearchableDropdown·FileInput·MultilingualTagInput·IconSelect (type=hidden·sr-only 제외)
 *   - 이름이 있다 = ① 자기 이름 prop(aria-label·ariaLabel·aria-labelledby·placeholder·title·label —
 *     목록형 선택칸(Select options·SearchableDropdown)은 aria-label·aria-labelledby 만. placeholder 는 단추에 닿지 않는다)
 *     ② 조상 Label ③ for/htmlFor 로 이어진 Label ④ (관리자, 체크박스·라디오 제외) 훅이 잇는 곁 라벨
 *     ⑤ (관리자 Toggle) 앞 형제 제목 — 중 하나
 *   - 사용자 화면(조각 포함)에는 곁 라벨 연결 훅이 없어 ④⑤ 를 인정하지 않는다.
 *   - DataGrid·CardGrid 셀 렌더 안 이름이 행 값을 쓰면 「$t:defer:」 여야 한다(「$t:」 는 셀 문맥 없이 먼저 번역된다).
 *   - 확장마다 독립 패키지라 판정 함수를 공용 파일에서 import 하지 않고 이 파일에 둔다.
 *
 * @scenario-manifest tests/scenarios/control-accessible-names.yaml
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

/** 확장 루트 */
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../..');
/** 모집단 디렉터리(확장 루트 기준) */
const ROOTS = ['resources/layouts', 'resources/extensions'];
/** 템플릿 단위로 면이 정해진 경우(예제 관리자·사용자 템플릿) */
const FORCED_SIDE: 'admin' | 'user' | null = null;

/** 판정 대상 칸 */
const CTRL = new Set([
    'Input', 'Select', 'Textarea', 'Checkbox', 'Toggle', 'PasswordInput', 'TagInput',
    'MultilingualInput', 'SearchableDropdown', 'FileInput', 'MultilingualTagInput', 'IconSelect',
]);
/** 관리자 곁 라벨 연결 훅이 붙는 칸 */
const HOOKED = new Set(['Input', 'Select', 'Textarea', 'Toggle', 'TagInput', 'PasswordInput']);
/** 경로에 admin 이 없어도 관리자 화면에 주입되는 조각 */
const ADMIN_EXT = new Set(['notification_template_form_sections.json', 'notification_template_form_footer_actions.json']);

const isNode = (x) =>
    !!x && typeof x === 'object' && !Array.isArray(x) && typeof x.name === 'string' && Boolean(x.type || x.props || x.children);
const classOf = (n) => String(n?.props?.className ?? '');
const isCtrl = (n) => isNode(n) && CTRL.has(n.name) && n.props?.type !== 'hidden' && !/\bsr-only\b/.test(classOf(n));
const isLabelEl = (n) => isNode(n) && (n.name === 'Label' || /\b(form-label|filter-label|text-heading)\b/.test(classOf(n)));
const kidsOf = (n) => (Array.isArray(n?.children) ? n.children.filter(isNode) : []);
const deep = (n, pred) => isNode(n) && (pred(n) || kidsOf(n).some((c) => deep(c, pred)));
const hasText = (n) =>
    deep(n, (x) => (typeof x.text === 'string' && x.text.trim() !== '') || (typeof x.props?.text === 'string' && x.props.text.trim() !== ''));
/**
 * 스위치 앞 형제가 「제목」인지 — 식 하나뿐인 글자({{…count}})나 숫자뿐인 글자는 배지라 제목이 아니다.
 * 그런 글자를 제목으로 치면 스위치가 「9」처럼 숫자로 읽힌다.
 */
const isTitleString = (s) => {
    const t = s.trim();
    if (t === '') return false;
    if (/^\{\{[\s\S]*\}\}$/.test(t) && t.indexOf('{{', 2) < 0) return false;
    return !/^[\d\s.,%+-]+$/.test(t);
};
const hasTitleText = (n) =>
    deep(n, (x) => (typeof x.text === 'string' && isTitleString(x.text)) || (typeof x.props?.text === 'string' && isTitleString(x.props.text)));
/** 노드 아래 글자(text·props.text)를 모두 모은다. */
const textsOf = (n) => {
    const out = [];
    (function walk(x) {
        if (!isNode(x)) return;
        if (typeof x.text === 'string') out.push(x.text);
        if (typeof x.props?.text === 'string') out.push(x.props.text);
        kidsOf(x).forEach(walk);
    })(n);
    return out;
};
const isLabelSlot = (n) => isLabelEl(n) || (!deep(n, isCtrl) && deep(n, isLabelEl));

/**
 * 디렉터리 아래 .json 을 모두 모은다.
 *
 * @param dir 시작 디렉터리
 * @returns .json 절대경로 목록
 */
function collectJson(dir) {
    if (!fs.existsSync(dir)) return [];
    return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) return collectJson(full);
        return entry.name.endsWith('.json') ? [full] : [];
    });
}

/**
 * 레이아웃이 관리자 화면인지 사용자 화면인지 가른다(관리자만 곁 라벨 연결 훅이 있다).
 *
 * @param rel 확장 루트 기준 경로(/ 구분)
 * @returns admin 또는 user
 */
function sideOf(rel) {
    if (FORCED_SIDE) return FORCED_SIDE;
    const seg = rel.split('/');
    const base = seg[seg.length - 1];
    const admin = ADMIN_EXT.has(base) || seg.includes('admin') || base.startsWith('admin')
        || base.startsWith('plugin_settings') || base.startsWith('module_settings');
    return admin ? 'admin' : 'user';
}

/**
 * 레이아웃 하나의 칸을 모두 찾아 면(surface)·목록형 여부·이름 유무를 판정한다.
 * 판정식은 관리자 훅 규칙을 JSON 트리에 옮긴 정적 스캐너와 같다 —
 * ① 자기 이름 prop ② 조상 Label ③ for/htmlFor 로 이어진 Label ④ (관리자, 체크박스·라디오 제외) 훅이 잇는 곁 라벨
 * ⑤ (관리자 Toggle) 앞 형제 제목 중 하나도 없으면 이름 없음이다. 목록형 선택칸도 같은 판정으로 0 이어야 한다.
 *
 * @param json 레이아웃 JSON
 * @param rel 보고용 경로
 * @returns 칸 판정 목록
 */
function judgeLayout(json, rel) {
    const side = sideOf(rel);
    const parent = new Map();
    const cells = new Set();
    const fors = new Map();
    const controls = [];

    const visit = (x, par) => {
        if (Array.isArray(x)) {
            x.forEach((y) => visit(y, par));
            return;
        }
        if (!x || typeof x !== 'object') return;
        if (!isNode(x)) {
            Object.values(x).forEach((v) => visit(v, par));
            return;
        }
        parent.set(x, par);
        const p = x.props ?? {};
        if (x.name === 'Label' && (p.htmlFor || p.for)) fors.set(String(p.htmlFor || p.for), x);
        if (isCtrl(x)) controls.push(x);
        for (const [k, v] of Object.entries(x)) {
            if (k === 'children') visit(v, x);
            else if (k === 'props') {
                // props 안에 든 노드(셀 렌더 등)는 조상과 끊긴 독립 칸으로 본다
                (function inProps(y) {
                    if (Array.isArray(y)) return y.forEach(inProps);
                    if (!y || typeof y !== 'object') return;
                    if (isNode(y)) {
                        const cell = { name: '__cell', children: [y] };
                        cells.add(cell);
                        visit(y, cell);
                        return;
                    }
                    Object.values(y).forEach(inProps);
                })(v);
            } else if (v && typeof v === 'object') visit(v, par);
        }
    };
    visit(json, null);

    const up = (n) => parent.get(n) ?? null;

    return controls.map((C) => {
        const p = C.props ?? {};
        // 목록형 선택칸은 placeholder·title·label 이 단추 이름에 닿지 않는다(값이 없으면 placeholder 글자가 곧 「값만」) —
        // 자기 이름은 aria-label·aria-labelledby 만 인정한다
        const listbox = (C.name === 'Select' && Boolean(p.options)) || C.name === 'SearchableDropdown';
        // 강한 자기 이름(aria-label·ariaLabel·aria-labelledby·label)은 곁 라벨보다 앞선다. placeholder·title 은
        // 관리자 훅이 무시하고 곁 라벨을 잇으므로 곁 라벨도 없을 때의 마지막 수단이다(목록형은 아예 인정하지 않는다)
        const strongKeys = listbox ? ['aria-label', 'aria-labelledby'] : ['aria-label', 'ariaLabel', 'aria-labelledby', 'label'];
        const weakKeys = listbox ? [] : ['placeholder', 'title'];
        const own = strongKeys.some((k) => Boolean(p[k]));
        const weak = weakKeys.some((k) => Boolean(p[k]));
        // 이름 출처 글자 — 반복 행에서 행마다 다른 이름인지(행 값 {{…}} 을 담는지) 판정한다
        const sources = strongKeys.map((k) => p[k]).filter((v) => typeof v === 'string');
        let named = own || Boolean(p.id && fors.has(String(p.id)));
        if (p.id && fors.has(String(p.id))) sources.push(...textsOf(fors.get(String(p.id))));
        for (let a = up(C), d = 0; a && d < 30; d++, a = up(a)) {
            if (a.name === 'Label') {
                named = true;
                sources.push(...textsOf(a));
            }
        }
        const checkable = C.name === 'Checkbox' || (C.name === 'Input' && /checkbox|radio/.test(String(p.type)));
        if (!named && side === 'admin' && HOOKED.has(C.name) && !checkable) {
            let anc = up(C);
            let child = C;
            for (let d = 0; anc && d < 3; d++) {
                const ch = kidsOf(anc);
                const ei = ch.indexOf(child);
                let found = null;
                let blocked = false;
                for (let i = ei - 1; i >= 0; i--) {
                    const s = ch[i];
                    if (isLabelSlot(s)) {
                        if (!(s.props?.htmlFor || s.props?.for) && hasText(s)) {
                            found = i;
                            break;
                        }
                    } else if (deep(s, isCtrl)) {
                        blocked = true;
                        break;
                    }
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
                    if (ok) sources.push(...textsOf(ch[found]));
                    break;
                }
                if (d === 0) {
                    for (let i = ei + 1; i < ch.length; i++) {
                        const s = ch[i];
                        if (isLabelSlot(s) && hasText(s)) {
                            named = true;
                            sources.push(...textsOf(s));
                            break;
                        }
                        if (deep(s, isCtrl)) break;
                    }
                    if (named) break;
                }
                child = anc;
                anc = up(anc);
            }
            if (!named && C.name === 'Toggle') {
                const ch = kidsOf(up(C));
                const prev = ch[ch.indexOf(C) - 1];
                if (prev && !deep(prev, isCtrl) && hasTitleText(prev)) {
                    named = true;
                    sources.push(...textsOf(prev));
                }
            }
        }

        // 면: 반복 행(iteration·셀 렌더) > 모달 > 화면 본문
        // 마지막 수단 — placeholder·title
        if (!named && weak) {
            named = true;
            sources.push(...weakKeys.map((k) => p[k]).filter((v) => typeof v === 'string'));
        }

        // 반복 = data-each(iteration) · <sortable>(노드 최상위 sortable 객체) · DataGrid·CardGrid 셀 렌더
        let row = false;
        let inCell = false;
        let modal = /(^|\/)_?modal|_modal/.test(rel);
        for (let a = C; a; a = up(a)) {
            if (cells.has(a)) inCell = true;
            const sortable = a.sortable && typeof a.sortable === 'object' && !Array.isArray(a.sortable);
            if (inCell || a.iteration || sortable) row = true;
            if (a.name === 'Modal') modal = true;
        }
        // 반복 안 칸인데 이름 출처 어디에도 행 값({{…}})이 없으면 행마다 같은 이름(「사용」)으로 읽힌다
        const rowConstant = row && named && !sources.some((s) => /\{\{/.test(s));
        let surface;
        if (side === 'user') surface = rel.split('/').includes('extensions') ? 'user_extension' : 'user_layout';
        else surface = row ? 'admin_row' : modal ? 'admin_modal' : 'admin_layout';

        // DataGrid·CardGrid 셀 렌더(props 안 노드) 안에서 행 값을 인자로 쓰는 이름은 `$t:defer:` 여야 한다 —
        // `$t:` 는 셀 문맥 없이 먼저 번역돼 행 이름이 빈 문자열이 된다
        const eagerInCell = inCell && ['aria-label', 'ariaLabel', 'aria-labelledby'].some((k) => {
            const v = p[k];
            return typeof v === 'string' && /^\$t:(?!defer:)/.test(v) && /\{\{/.test(v);
        });

        return {
            surface,
            listbox,
            named,
            eagerInCell,
            rowConstant,
            label: `${rel}: ${C.name}${p.type ? `:${p.type}` : ''} ${String(p.name ?? p.value ?? p.checked ?? '').slice(0, 70)}`,
        };
    });
}

/**
 * 행 선택(selectable)이 켜졌거나 셀에 $rowLabel 을 쓰는 DataGrid 를 모아 rowLabelField 유무를 본다.
 * rowLabelField 가 없으면 행 이름이 첫 표시 열 값(날짜 원문 등)으로 떨어져 「2026-10-07T02:50:00+00:00 선택」처럼 읽힌다.
 *
 * @param json 레이아웃 JSON
 * @param rel 보고용 경로
 * @returns DataGrid 판정 목록
 */
function judgeDataGrids(json, rel) {
    const grids = [];
    (function walk(x) {
        if (Array.isArray(x)) return x.forEach(walk);
        if (!x || typeof x !== 'object') return;
        if (isNode(x) && x.name === 'DataGrid') grids.push(x);
        Object.values(x).forEach(walk);
    })(json);
    return grids.map((g) => {
        const p = g.props ?? {};
        const selectable = p.selectable !== undefined && p.selectable !== false && p.selectable !== 'false';
        const usesRowLabel = JSON.stringify(g).includes('$rowLabel');
        return {
            needsRowLabel: selectable || usesRowLabel,
            hasRowLabelField: typeof p.rowLabelField === 'string' && p.rowLabelField.trim() !== '',
            label: `${rel}: DataGrid ${String(g.id ?? p['data-ref'] ?? '')}`,
        };
    });
}

describe('플러그인 sirsoft-daum_postcode — 입력칸 접근 가능한 이름 (#757)', () => {
    const files = ROOTS.flatMap((r) => collectJson(path.join(ROOT, r)));
    const judged = files.flatMap((f) =>
        judgeLayout(JSON.parse(fs.readFileSync(f, 'utf8')), path.relative(ROOT, f).split(path.sep).join('/')),
    );

    /**
     * 모집단이 비면 판정이 공허하게 통과한다 — 레이아웃 경로가 바뀌어도 잡히게 크기를 단언한다.
     *
     * @scenario surface=admin_layout
     * @effects controls_have_accessible_name
     */
    it('모집단(레이아웃 JSON·입력칸)이 비어 있지 않다', () => {
        expect(files.length).toBeGreaterThanOrEqual(1);
        expect(judged.length).toBeGreaterThanOrEqual(3);
    });

    /**
     * 관리자 화면 본문 — 이름 없는 칸이 0 개다.
     *
     * @scenario surface=admin_layout
     * @effects controls_have_accessible_name
     */
    it('admin_layout: 관리자 화면 본문의 칸이 모두 이름을 갖는다', () => {
        const here = judged.filter((j) => j.surface === 'admin_layout');
        expect(here.length).toBeGreaterThan(0);
        expect(here.filter((j) => !j.named).map((j) => j.label)).toEqual([]);
        // 반복(data-each·<sortable>·셀) 안 칸의 이름 출처에 행 값 {{…}} 이 없으면 행마다 같은 이름으로 읽힌다
        expect(here.filter((j) => j.rowConstant).map((j) => j.label)).toEqual([]);
    });
});
