/**
 * strictly necessary allowlist 모집단 커버리지 테스트
 *
 * functionalCleaner 는 functional 미동의(기본 상태)에서 **부팅마다** allowlist 밖의
 * localStorage / sessionStorage 를 전량 파기한다. 그래서 코어·확장이 새로 저장 키를
 * 도입하면서 allowlist 등재를 잊으면, 그 설정은 "저장은 되는데 새로고침하면 사라지는"
 * 상태가 된다 — 예외도 콘솔 오류도 남지 않아 증상만으로는 원인을 특정할 수 없다.
 *
 * 이 테스트는 allowlist 를 **손으로 열거하지 않는다.** 저장소의 소스를 훑어
 * `.setItem()` 이 쓰는 키를 기계 도출하고, 그 전량이 allowlist 에 등재되었거나
 * 의도적 비필수(INTENTIONALLY_NON_NECESSARY)로 선언되었는지 검사한다.
 * 새 저장 키가 추가되면 그 시점에 붉어진다.
 *
 * @module sirsoft-gdpr/__tests__/necessaryAllowlistCoverage
 */

import { describe, it, expect } from 'vitest';
import * as fs from 'fs';
import * as path from 'path';

import { DEFAULT_NECESSARY_ALLOWLIST } from '../storageInterceptor';

/**
 * 저장소 루트를 탐색합니다. (artisan + composer.json 동시 보유 디렉토리)
 *
 * @return 저장소 루트 절대경로
 */
function findRepoRoot(): string {
    let dir = __dirname;
    for (let i = 0; i < 12; i += 1) {
        if (
            fs.existsSync(path.join(dir, 'artisan'))
            && fs.existsSync(path.join(dir, 'composer.json'))
        ) {
            return dir;
        }
        dir = path.dirname(dir);
    }
    throw new Error('저장소 루트를 찾지 못했습니다.');
}

const REPO_ROOT = findRepoRoot();

/**
 * 스캔 대상 디렉토리 (glob 없이 실제 디렉토리 열거로 확장 전량 포함).
 *
 * @return 존재하는 스캔 대상 절대경로 배열
 */
function scanRoots(): string[] {
    const roots: string[] = [path.join(REPO_ROOT, 'resources', 'js', 'core')];

    const extensionGroups: Array<[string, string[]]> = [
        ['templates', ['src']],
        ['modules', ['resources', 'js']],
        ['plugins', ['resources', 'js']],
    ];

    for (const [group, tail] of extensionGroups) {
        const bundled = path.join(REPO_ROOT, group, '_bundled');
        if (!fs.existsSync(bundled)) {
            continue;
        }
        for (const entry of fs.readdirSync(bundled)) {
            const candidate = path.join(bundled, entry, ...tail);
            if (fs.existsSync(candidate)) {
                roots.push(candidate);
            }
        }
    }

    return roots.filter((dir) => fs.existsSync(dir));
}

/**
 * 디렉토리를 재귀 순회하며 .ts/.tsx 파일을 수집합니다. (테스트 디렉토리 제외)
 *
 * @param  dir  순회 시작 디렉토리
 * @param  out  누적 배열
 * @return void
 */
function collectSourceFiles(dir: string, out: string[]): void {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            if (entry.name === '__tests__' || entry.name === 'node_modules' || entry.name === 'dist') {
                continue;
            }
            collectSourceFiles(full, out);
            continue;
        }
        if (/\.(ts|tsx)$/.test(entry.name) && !/\.d\.ts$/.test(entry.name)) {
            out.push(full);
        }
    }
}

/**
 * 파일 안에서 식별자에 대입된 문자열 리터럴을 찾습니다.
 *
 * @param  source  파일 원문
 * @param  ident   식별자
 * @return 리터럴 값 또는 null
 */
function resolveIdentifierLiteral(source: string, ident: string): string | null {
    const re = new RegExp(
        `(?:const|let|var|readonly)\\s+${ident}\\s*(?::[^=]+)?=\\s*(['"])([^'"]*)\\1`,
    );
    const m = re.exec(source);
    return m ? m[2] : null;
}

/**
 * 주석을 제거합니다.
 *
 * 주석 안의 `.setItem()` 서술이 호출로 오인되면 쓰레기 키가 모집단에 섞이고,
 * 그 노이즈가 실제 미해석 호출을 가린다.
 *
 * @param  source  파일 원문
 * @return 주석이 공백으로 치환된 원문 (오프셋 보존)
 */
function stripComments(source: string): string {
    return source
        .replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, ' '))
        .replace(/(^|[^:])\/\/[^\n]*/g, (m, p1) => p1 + ' '.repeat(m.length - p1.length));
}

/**
 * `import { X } from './y'` 를 따라가 다른 파일의 상수를 해석합니다.
 *
 * 코어의 저장 키 상수는 대부분 별도 모듈(`constants.ts` · `types.ts`)에 모여 있어,
 * 같은 파일만 보면 그 키가 통째로 모집단에서 빠진다.
 *
 * @param  file    호출이 있는 파일 절대경로
 * @param  source  그 파일 원문
 * @param  ident   식별자
 * @return 리터럴 값 또는 null
 */
function resolveImportedLiteral(file: string, source: string, ident: string): string | null {
    const importRe = new RegExp(
        `import\\s*\\{[^}]*\\b${ident}\\b[^}]*\\}\\s*from\\s*['"]([^'"]+)['"]`,
    );
    const m = importRe.exec(source);
    if (m === null || !m[1].startsWith('.')) {
        return null;
    }

    const base = path.resolve(path.dirname(file), m[1]);
    const candidates = [
        `${base}.ts`, `${base}.tsx`,
        path.join(base, 'index.ts'), path.join(base, 'index.tsx'),
    ];

    for (const candidate of candidates) {
        if (!fs.existsSync(candidate)) {
            continue;
        }
        const target = stripComments(fs.readFileSync(candidate, 'utf-8'));
        const value = resolveIdentifierLiteral(target, ident);
        if (value !== null) {
            return value;
        }
    }

    return null;
}

/**
 * 식별자에 대입된 **템플릿 리터럴** 원문을 찾습니다.
 *
 * `const fullKey = ` + 백틱 표현식처럼 키가 한 단계 변수를 거쳐 조립되는 형태를
 * 놓치면 그 키가 모집단에서 통째로 빠진다 (접두사 키가 전부 이 형태다).
 *
 * @param  source  파일 원문
 * @param  ident   식별자
 * @return 백틱을 포함한 템플릿 원문 또는 null
 */
function resolveIdentifierTemplate(source: string, ident: string): string | null {
    // 초기화식이 삼항/`useMemo(() => ...)` 로 감싸인 경우까지 닿도록, 선언 뒤
    // 가까운 범위에서 첫 백틱 템플릿을 찾는다 (저장 키 조립의 실제 형태들).
    const declRe = new RegExp(`(?:const|let|var|readonly)\\s+${ident}\\s*(?::[^=]+)?=`);
    const decl = declRe.exec(source);
    if (decl === null) {
        return null;
    }

    const window = source.slice(decl.index, decl.index + 400);
    const tpl = /`[^`]*`/.exec(window);
    return tpl ? tpl[0] : null;
}

interface DiscoveredKey {
    key: string;
    matchType: 'exact' | 'prefix';
    origin: string;
}

/**
 * `.setItem(...)` 호출의 키 표현식을 정적으로 해석합니다.
 *
 * 해석 가능한 형태: 문자열 리터럴 · 식별자(같은 파일의 상수) · 템플릿 리터럴(정적 접두사).
 *
 * @param  keyExpr  키 표현식 원문
 * @param  source   파일 원문 (식별자 해석용)
 * @return 해석 결과 또는 null (해석 불가)
 */
function resolveKeyExpression(
    keyExpr: string,
    source: string,
    file: string,
): { key: string; matchType: 'exact' | 'prefix' } | null {
    const expr = keyExpr.trim();

    const literal = /^(['"])(.*)\1$/.exec(expr);
    if (literal) {
        return { key: literal[2], matchType: 'exact' };
    }

    // `this.TOKEN_KEY` · `TemplateApp.LOCALE_STORAGE_KEY` 같은 멤버 표현식은
    // 마지막 조각이 곧 상수명이다. 여기서 끊으면 코어 키 다수가 통째로 빠진다.
    const member = /^(?:[A-Za-z_$][\w$]*\.)+([A-Za-z_$][\w$]*)$/.exec(expr);
    const ident = member ? member[1] : expr;

    if (/^[A-Za-z_$][\w$]*$/.test(ident)) {
        const value = resolveIdentifierLiteral(source, ident);
        if (value !== null) {
            return { key: value, matchType: 'exact' };
        }
        // 변수를 한 단계 거쳐 조립되는 템플릿 키 (접두사 키의 대표 형태).
        const template = resolveIdentifierTemplate(source, ident);
        if (template !== null) {
            return resolveKeyExpression(template, source, file);
        }
        // 다른 모듈에 모여 있는 상수.
        const imported = resolveImportedLiteral(file, source, ident);
        return imported === null ? null : { key: imported, matchType: 'exact' };
    }

    // `storageKey()` 처럼 키를 조립해 돌려주는 무인자 함수. 본문의 return 템플릿을
    // 따라간다 — 여기서 끊으면 그 키의 허용목록 등재가 아무 검사도 받지 않는다
    // (등재를 지워도 초록인 죽은 축이 된다).
    const call = /^([A-Za-z_$][\w$]*)\(\s*\)$/.exec(expr);
    if (call !== null) {
        const fnRe = new RegExp(`function\\s+${call[1]}\\s*\\([^)]*\\)[^{]*\\{`);
        const fn = fnRe.exec(source);
        if (fn !== null) {
            const body = source.slice(fn.index, fn.index + 600);
            const ret = /return\s+(`[^`]*`|['"][^'"]*['"])/.exec(body);
            if (ret !== null) {
                return resolveKeyExpression(ret[1], source, file);
            }
        }
        return null;
    }

    if (expr.startsWith('`')) {
        const body = expr.slice(1, -1);
        // 선두가 `${IDENT}` 이면 그 상수를 펼쳐 정적 접두사를 만든다.
        const leading = /^\$\{\s*([A-Za-z_$][\w$]*)\s*\}/.exec(body);
        let prefix = '';
        let rest = body;
        if (leading) {
            const value = resolveIdentifierLiteral(source, leading[1]);
            if (value === null) {
                return null;
            }
            prefix = value;
            rest = body.slice(leading[0].length);
        }
        const staticHead = rest.split('${')[0];
        prefix += staticHead;
        return prefix === '' ? null : { key: prefix, matchType: 'prefix' };
    }

    return null;
}

/**
 * 저장소 전체에서 storage 저장 키를 도출합니다.
 *
 * @return 도출된 키 목록
 */
function discoverStorageKeys(): { keys: DiscoveredKey[]; unresolved: string[] } {
    const files: string[] = [];
    for (const root of scanRoots()) {
        collectSourceFiles(root, files);
    }

    const found = new Map<string, DiscoveredKey>();
    const unresolved: string[] = [];
    // 수신자 표현식을 좁히지 않는다 — `safeSessionStorage()?.setItem(...)` 처럼
    // 호출 결과에 바로 붙는 형태를 놓치면 그 키가 모집단에서 통째로 빠진다.
    const callRe = /\.setItem\(\s*([^,]+?)\s*,/g;

    for (const file of files) {
        const source = stripComments(fs.readFileSync(file, 'utf-8'));
        callRe.lastIndex = 0;
        let m: RegExpExecArray | null = callRe.exec(source);
        while (m !== null) {
            const origin = path.relative(REPO_ROOT, file).replace(/\\/g, '/');
            const resolved = resolveKeyExpression(m[1], source, file);
            if (resolved === null) {
                // 조용히 버리지 않는다 — 버리면 모집단이 부분적으로 비어도 초록이 된다.
                unresolved.push(`${m[1].replace(/\s+/g, ' ')} ← ${origin}`);
            } else {
                const id = `${resolved.matchType}:${resolved.key}`;
                if (!found.has(id)) {
                    found.set(id, { ...resolved, origin });
                }
            }
            m = callRe.exec(source);
        }
    }

    return {
        keys: [...found.values()].sort((a, b) => a.key.localeCompare(b.key)),
        unresolved: [...new Set(unresolved)].sort(),
    };
}

/**
 * 필수(strictly necessary) 가 **아니라고 의도적으로 판단한** 키.
 *
 * 여기 적힌 키는 functional 동의가 없으면 파기되는 것이 설계 의도다.
 * 새 키를 여기 넣을 때는 사용자에게 그 상실이 수용 가능한지 근거를 함께 남긴다.
 */
/**
 * 키가 실행 시점에 정해져 **정적으로 해석할 수 없는** 쓰기 지점.
 *
 * 이 목록은 사각을 지우는 것이 아니라 드러내 고정한다. 정렬된 문자열로 비교하므로
 * 새 동적 쓰기 지점이 생기면 그 즉시 붉어진다.
 */
const DECLARED_DYNAMIC_CALL_SITES: ReadonlyArray<{ site: string; reason: string }> = [
    {
        site: 'key ← resources/js/core/template-engine/ActionDispatcher.ts',
        reason: '`saveToLocalStorage` 핸들러 — 키를 레이아웃 액션이 넘긴다. 레이아웃이 임의로 정하는 값이라 목록으로 열거할 수 없다.',
    },
    {
        site: 'key ← templates/_bundled/sirsoft-basic/src/handlers/storageHandlers.ts',
        reason: '`setLocalStorage` 핸들러 — 위와 같은 이유 (레이아웃이 키를 정한다).',
    },
];

const INTENTIONALLY_NON_NECESSARY: ReadonlyArray<{ key: string; reason: string }> = [
    {
        key: 'g7_preferred_currency',
        reason: '표시 통화 — functional 카테고리 설명에 명시된 선호도. 미동의 시 기본 통화로 표시되는 것이 의도된 동작이다.',
    },
];

/**
 * 도출된 키가 allowlist 에 등재되어 있는지 검사합니다.
 *
 * 저장소 종류(localStorage/sessionStorage)는 개별 테스트가 잠그며, 여기서는
 * "필수 목록이 이 키를 알고 있는가" 만 본다.
 *
 * @param  discovered  도출된 키
 * @return 등재 여부
 */
function isDeclared(discovered: DiscoveredKey): boolean {
    return DEFAULT_NECESSARY_ALLOWLIST.some((entry) => {
        const entryMatch = entry.matchType ?? 'exact';
        if (entryMatch === 'exact') {
            return entry.key === discovered.key;
        }
        // prefix 항목은 그 접두사로 시작하는 키(정확형·접두형 모두)를 덮는다.
        return discovered.key.startsWith(entry.key);
    });
}

describe('strictly necessary allowlist 모집단 커버리지', () => {
    const { keys: discovered, unresolved } = discoverStorageKeys();

    it('저장 키 도출이 비어 있지 않다 (모집단 하한 — 스캐너가 죽으면 붉어진다)', () => {
        expect(discovered.length).toBeGreaterThanOrEqual(10);
    });

    it('정적 해석 불가 지점이 선언된 목록과 정확히 일치한다 (부분 누락 방지)', () => {
        // 해석 못 한 표현식을 버리면 그 키는 검사 대상에서 사라지는데, 결과는
        // "이상 0건" 과 구분되지 않는다. 그래서 버리지 않고 **선언**한다 —
        // 새 동적 쓰기 지점이 생기면 여기서 붉어지고, 그때 그 키가 파기돼도
        // 되는지 판단해 선언에 추가하거나 정적 상수로 바꾼다.
        expect(unresolved).toEqual(DECLARED_DYNAMIC_CALL_SITES.map((s) => s.site));
    });

    it('도출된 모든 저장 키가 allowlist 등재 또는 의도적 비필수 선언 중 하나에 해당한다', () => {
        const exempt = new Set(INTENTIONALLY_NON_NECESSARY.map((e) => e.key));
        const uncovered = discovered
            .filter((d) => !exempt.has(d.key))
            .filter((d) => !isDeclared(d))
            .map((d) => `${d.key} (${d.matchType}) ← ${d.origin}`);

        expect(uncovered).toEqual([]);
    });

    it('의도적 비필수 선언은 실제로 존재하는 키만 담는다 (사문화 방지)', () => {
        const discoveredKeys = new Set(discovered.map((d) => d.key));
        const stale = INTENTIONALLY_NON_NECESSARY
            .filter((e) => !discoveredKeys.has(e.key))
            .map((e) => e.key);

        expect(stale).toEqual([]);
    });
});
