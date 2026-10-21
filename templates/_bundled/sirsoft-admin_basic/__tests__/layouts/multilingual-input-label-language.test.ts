/**
 * @file multilingual-input-label-language.test.ts
 * @description 다국어 입력칸(MultilingualInput)의 이름에 언어 이름을 박아 두지 않는다 — 번들 레이아웃 전수
 *
 * 다국어 입력칸은 이름 prop(ariaLabel·aria-label)이나 곁 라벨 글자 뒤에 칸마다 「({언어})」 를 붙여 읽힌다
 * (`common.field_in_language`, 7.1.1). 그런데 마케팅 설정 채널 창은 곁 라벨을 「항목명 (한국어)」 키로 둬서
 * 화면 낭독기가 「항목명 (한국어) (English)」 처럼 언어를 두 번, 그것도 서로 어긋나게 읽었다(관찰).
 * 언어는 부품이 칸마다 붙이므로, 이름 재료(이름 prop·FormField label·곁 라벨)는 언어 없는 칸 이름이어야 한다.
 *
 * 검증 방식: 번들 레이아웃 굽기 산출물(JSON) 전수의 정적 판정(DOM 비의존). 이 부품을 쓰는 모든 확장
 * (admin_basic·modules/_bundled·plugins/_bundled)을 모집단으로 저장소에서 파생하므로 새 화면도 자동으로 잠긴다.
 *
 * 이름 재료를 찾는 규칙(부품 쪽 규칙을 JSON 트리에 옮긴 판):
 *   ① 자기 이름 prop(ariaLabel·aria-label) — 있으면 그것만 본다
 *   ② 감싼 FormField 의 label
 *   ③ 곁 라벨 — 자기·조상 3단계까지 앞 형제 가운데 가장 가까운 라벨 자리(Label·form-label·filter-label·text-heading,
 *      또는 칸 없이 라벨을 품은 상자). 사이에 다른 칸이 있으면 그 라벨은 앞 칸의 것이라 멈춘다
 * `$t:` 키는 ko·en·ja(번들 언어팩) 사전으로 풀어 세 언어 모두에서 언어 이름이 없는지 본다.
 *
 * @scenario-manifest tests/scenarios/multilingual-input-label-language.yaml
 */

import fs from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';

/** 저장소 루트 */
const REPO = path.resolve(__dirname, '../../../../..');
const LOCALES = ['ko', 'en', 'ja'] as const;
type Locale = (typeof LOCALES)[number];
type Dict = Record<string, unknown>;

/**
 * 언어 파일을 읽어 `$partial` 을 펼친다.
 *
 * @param file 언어 파일 절대 경로
 * @returns 펼친 사전(파일이 없으면 빈 객체)
 */
function loadLang(file: string): Dict {
    if (!fs.existsSync(file)) return {};
    const base = path.dirname(file);
    const expand = (node: unknown): unknown => {
        if (node && typeof node === 'object' && !Array.isArray(node)) {
            const partial = (node as Dict).$partial;
            if (typeof partial === 'string') {
                const p = path.join(base, partial);
                return fs.existsSync(p) ? expand(JSON.parse(fs.readFileSync(p, 'utf8'))) : {};
            }
            return Object.fromEntries(Object.entries(node as Dict).map(([k, v]) => [k, expand(v)]));
        }
        return node;
    };
    return expand(JSON.parse(fs.readFileSync(file, 'utf8'))) as Dict;
}

/** 확장 목록(모듈·플러그인) */
const extensions = (['modules', 'plugins'] as const).flatMap((kind) =>
    fs.readdirSync(path.join(REPO, kind, '_bundled')).map((id) => ({ kind, id, dir: path.join(REPO, kind, '_bundled', id) })),
);

/** 로케일별 사전 — 확장 키는 식별자 접두 아래, 나머지는 관리자 템플릿 사전 */
const dicts: Record<Locale, Dict> = {
    ko: loadLang(path.join(REPO, 'templates/_bundled/sirsoft-admin_basic/lang/ko.json')),
    en: loadLang(path.join(REPO, 'templates/_bundled/sirsoft-admin_basic/lang/en.json')),
    ja: loadLang(path.join(REPO, 'lang-packs/_bundled/g7-template-sirsoft-admin_basic-ja/frontend/ja.json')),
};
for (const ext of extensions) {
    dicts.ko[ext.id] = loadLang(path.join(ext.dir, 'resources/lang/ko.json'));
    dicts.en[ext.id] = loadLang(path.join(ext.dir, 'resources/lang/en.json'));
    dicts.ja[ext.id] = loadLang(
        path.join(REPO, 'lang-packs/_bundled', `g7-${ext.kind.slice(0, -1)}-${ext.id}-ja`, 'frontend/ja.json'),
    );
}

/**
 * 글자 안의 `$t:키` 를 그 로케일 문구로 바꾼다(못 찾으면 그대로 둔다).
 *
 * @param locale 로케일
 * @param text 원문
 * @returns 푼 글자
 */
function translate(locale: Locale, text: string): string {
    return text.replace(/\$t:(?:defer:)?([A-Za-z0-9_.-]+)/g, (raw, key: string) => {
        let cur: unknown = dicts[locale];
        for (const seg of key.split('.')) cur = (cur as Dict | undefined)?.[seg];
        return typeof cur === 'string' ? cur : raw;
    });
}

/** 언어 이름 — 부품이 붙이는 언어명(`common.language_*` 세 언어 문구) + 흔한 표기 */
const languageNames = new Set<string>(['한국어', '영어', '일본어', '중국어', 'English', 'Korean', 'Japanese', 'Chinese', '日本語', '韓国語', '英語', '中文']);
for (const locale of LOCALES) {
    const common = (dicts[locale].common ?? {}) as Dict;
    for (const [key, value] of Object.entries(common)) {
        if (key.startsWith('language_') && typeof value === 'string' && value.trim() !== '') languageNames.add(value.trim());
    }
}
/** 괄호 안 언어 코드 표기 — 「이름 (KO)」 */
const LOCALE_CODE_RE = /[(（]\s*(ko|en|ja|zh)\s*[)）]/i;

/**
 * 글자에 언어 이름이 들어 있는지.
 *
 * @param text 푼 글자
 * @returns 들어 있으면 그 언어 이름
 */
function languageIn(text: string): string | null {
    for (const name of languageNames) if (text.includes(name)) return name;
    const code = text.match(LOCALE_CODE_RE);
    return code ? code[0] : null;
}

type Node = { name?: string; type?: string; text?: string; props?: Dict; children?: unknown[] } & Dict;
const isNode = (x: unknown): x is Node =>
    !!x && typeof x === 'object' && !Array.isArray(x) && typeof (x as Node).name === 'string' && Boolean((x as Node).type || (x as Node).props || (x as Node).children);
const CONTROLS = new Set(['Input', 'Select', 'Textarea', 'Toggle', 'Checkbox', 'PasswordInput', 'TagInput', 'MultilingualInput', 'SearchableDropdown', 'FileInput', 'MultilingualTagInput']);
const isControl = (n: Node) => CONTROLS.has(String(n.name)) && n.props?.type !== 'hidden';
const isLabelEl = (n: Node) => n.name === 'Label' || /(^|\s)(form-label|filter-label|text-heading)(\s|$)/.test(String(n.props?.className ?? ''));
const kidsOf = (n: Node): Node[] => (Array.isArray(n.children) ? n.children.filter(isNode) : []);
const deep = (n: Node, pred: (x: Node) => boolean): Node | null => {
    if (pred(n)) return n;
    for (const c of kidsOf(n)) {
        const hit = deep(c, pred);
        if (hit) return hit;
    }
    return null;
};
/** 노드 아래 글자를 모은다 */
const textOf = (n: Node): string => {
    const out: string[] = [];
    (function walk(x: Node) {
        if (typeof x.text === 'string') out.push(x.text);
        if (typeof x.props?.text === 'string') out.push(x.props.text as string);
        kidsOf(x).forEach(walk);
    })(n);
    return out.join(' ');
};

type Found = { file: string; via: string; raw: string };

/**
 * 레이아웃 트리에서 다국어 입력칸마다 이름 재료를 모은다.
 *
 * @param file 상대 경로(보고용)
 * @param root 레이아웃 JSON
 * @returns 칸별 이름 재료(재료가 없으면 via=none)
 */
function namesIn(file: string, root: unknown): Found[] {
    const found: Found[] = [];
    const visit = (value: unknown, ancestors: Node[]) => {
        if (Array.isArray(value)) {
            value.forEach((v) => visit(v, ancestors));
            return;
        }
        if (!value || typeof value !== 'object') return;
        const node = value as Node;
        if (isNode(node) && node.name === 'MultilingualInput') {
            const own = node.props?.ariaLabel ?? node.props?.['aria-label'];
            const formField = [...ancestors].reverse().slice(0, 3).find((a) => a.name === 'FormField' && typeof a.props?.label === 'string');
            if (typeof own === 'string' && own.trim() !== '') {
                found.push({ file, via: 'ariaLabel', raw: own });
            } else if (formField) {
                found.push({ file, via: 'FormField.label', raw: String(formField.props?.label) });
            } else {
                let chain: Node = node;
                let hit: string | null = null;
                for (let d = 0; d < 4 && d < ancestors.length && hit === null; d++) {
                    const parent = ancestors[ancestors.length - 1 - d];
                    const kids = kidsOf(parent);
                    for (let i = kids.indexOf(chain) - 1; i >= 0; i--) {
                        const sib = kids[i];
                        const label = isLabelEl(sib) ? sib : !deep(sib, isControl) ? deep(sib, isLabelEl) : null;
                        if (label) {
                            hit = textOf(label);
                            break;
                        }
                        if (deep(sib, isControl)) break;
                    }
                    chain = parent;
                }
                found.push({ file, via: hit === null ? 'none' : 'adjacent', raw: hit ?? '' });
            }
        }
        const next = isNode(node) ? [...ancestors, node] : ancestors;
        for (const [key, v] of Object.entries(node)) {
            if (key === 'children') visit(v, next);
            else if (Array.isArray(v) && isNode(node) && v.some(isNode)) visit(v, [...ancestors, { ...node, children: v }]);
            else if (v && typeof v === 'object') visit(v, ancestors);
        }
    };
    visit(root, []);
    return found;
}

/**
 * 디렉터리 아래 .json 을 모두 모은다.
 *
 * @param dir 디렉터리
 * @returns 파일 경로 목록
 */
function jsonFiles(dir: string): string[] {
    if (!fs.existsSync(dir)) return [];
    return fs.readdirSync(dir, { withFileTypes: true }).flatMap((e) => {
        const p = path.join(dir, e.name);
        if (e.isDirectory()) return e.name === 'node_modules' ? [] : jsonFiles(p);
        return e.name.endsWith('.json') ? [p] : [];
    });
}

const layoutRoots = [
    path.join(REPO, 'templates/_bundled/sirsoft-admin_basic/layouts'),
    ...extensions.flatMap((e) => [path.join(e.dir, 'resources/layouts'), path.join(e.dir, 'resources/extensions')]),
];
const population: Found[] = layoutRoots.flatMap((dir) =>
    jsonFiles(dir).flatMap((file) => {
        const text = fs.readFileSync(file, 'utf8');
        if (!text.includes('"MultilingualInput"')) return [];
        return namesIn(path.relative(REPO, file).replace(/\\/g, '/'), JSON.parse(text));
    }),
);

describe('다국어 입력칸 이름에 언어 이름이 박혀 있지 않다', () => {
    /**
     * 아래 판정의 전제 — 모집단을 실제로 모았는지(번들 여러 확장의 칸, 이름 재료 세 갈래가 모두 잡힌다).
     *
     * @scenario source=adjacent_label
     * @effects population_derived_from_bundles
     */
    it('번들 레이아웃 전수에서 다국어 입력칸을 모은다', () => {
        expect(population.length).toBeGreaterThanOrEqual(40);
        const vias = new Set(population.map((f) => f.via));
        expect(vias.has('ariaLabel')).toBe(true);
        expect(vias.has('adjacent')).toBe(true);
        expect(vias.has('FormField.label')).toBe(true);
        expect(population.some((f) => f.file.startsWith('plugins/_bundled/sirsoft-marketing/'))).toBe(true);
    });

    /**
     * 이름 재료 한 갈래의 위반 목록 — ko·en·ja 로 풀어 언어 이름이 든 것.
     *
     * @param via 이름 재료 갈래
     * @returns 위반 설명 목록
     */
    const violationsOf = (via: string): string[] =>
        population
            .filter((f) => f.via === via)
            .flatMap((f) =>
                LOCALES.map((locale) => ({ ...f, locale, text: translate(locale, f.raw) }))
                    .map((r) => ({ ...r, language: languageIn(r.text) }))
                    .filter((r) => r.language !== null)
                    .map((r) => `${r.file} [${r.via}] ${r.raw} → ${r.locale}: 「${r.text}」 (${r.language})`),
            );

    /**
     * 마케팅 채널 창 「항목명 (한국어)」 가 이 갈래였다.
     *
     * @scenario source=adjacent_label
     * @effects multilingual_input_name_has_no_language
     */
    it('곁 라벨을 ko·en·ja 로 풀어도 언어 이름이 없다', () => {
        expect(violationsOf('adjacent')).toEqual([]);
    });

    /**
     * @scenario source=aria_label
     * @effects multilingual_input_name_has_no_language
     */
    it('이름 prop(ariaLabel·aria-label)을 ko·en·ja 로 풀어도 언어 이름이 없다', () => {
        expect(violationsOf('ariaLabel')).toEqual([]);
    });

    /**
     * @scenario source=form_field_label
     * @effects multilingual_input_name_has_no_language
     */
    it('감싼 FormField 의 label 을 ko·en·ja 로 풀어도 언어 이름이 없다', () => {
        expect(violationsOf('FormField.label')).toEqual([]);
    });

    /**
     * 판정기가 언어 이름을 실제로 잡는지 — 부품이 붙이는 언어명(세 언어 문구)과 괄호 코드 표기.
     *
     * @scenario source=adjacent_label
     * @effects multilingual_input_name_has_no_language
     */
    it('판정기: 「항목명 (한국어)」·「Name (Korean)」·「項目名（日本語）」·「이름 (KO)」 는 언어로 잡고 「항목명」 은 통과', () => {
        expect(languageIn('항목명 (한국어)')).not.toBeNull();
        expect(languageIn('Name (Korean)')).not.toBeNull();
        expect(languageIn('項目名（日本語）')).not.toBeNull();
        expect(languageIn('이름 (KO)')).not.toBeNull();
        expect(languageIn('항목명')).toBeNull();
        expect(languageIn('Name')).toBeNull();
    });
});
