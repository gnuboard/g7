/**
 * strictly necessary 허용목록 커버리지·정합성 테스트
 *
 * functionalCleaner 는 functional 미동의(기본 상태)에서 **부팅마다** 허용목록 밖의
 * localStorage / sessionStorage 를 전량 파기한다. 그래서 코어·확장이 새로 저장 키를
 * 도입했는데 출하 카탈로그에도 없고 운영자도 등재하지 않으면, 그 설정은 "저장은 되는데
 * 새로고침하면 사라지는" 상태가 된다 — 예외도 콘솔 오류도 남지 않아 증상만으로는 원인을
 * 특정할 수 없다.
 *
 * 이 테스트는 목록을 **손으로 열거하지 않는다.** 저장소의 소스를 훑어 `.setItem()` 이 쓰는
 * 키를 기계 도출하고, 그 전량이 출하 카탈로그(`plugin.php`)에 등재되었거나 의도적 비필수
 * (INTENTIONALLY_NON_NECESSARY)로 선언되었는지 검사한다.
 *
 * 허용목록이 코드 상수에서 운영자 설정으로 옮겨진 뒤로 대조 대상이 **PHP 출하 카탈로그**다.
 * TS 상수를 계속 대조하면, 그 상수가 잠금 폴백만 남았으므로 모집단 대부분이 붉어지거나
 * (반대로) 판정 대상이 사라져 아무것도 재지 않는 초록이 된다.
 *
 * 검사 축:
 *   1. 모집단 하한 — 스캐너가 죽으면 붉어진다
 *   2. 정적 해석 불가 지점이 선언 목록과 정확히 일치 (부분 누락 방지)
 *   3. 도출된 키 전량이 카탈로그 등재 또는 의도적 비필수 선언
 *   4. 의도적 비필수 선언 사문화 방지
 *   5. 서버 쿠키 게이트가 하드코딩 목록이 아니라 설정을 읽는다 (발견 ①)
 *   6. TS 폴백이 PHP 잠금 집합과 정확히 일치 (발견 ①)
 *   7. TS 폴백이 카탈로그 전체 사본이 아니다 (드리프트 재발 차단)
 *   8. 카탈로그가 잠금 항목을 담지 않는다 (담기면 API 로 지울 수 있게 된다)
 *   9. 카탈로그 항목이 저장 검증 규칙을 통과한다 (통과 못 하면 시드값을 다시 저장할 수 없다)
 *  10. 와일드카드 매칭 규칙이 PHP·TS 에서 동형이다 (발견 ②)
 *
 * @module sirsoft-gdpr/__tests__/necessaryAllowlistCoverage
 *
 * @scenario scope=cookie, notation=wildcard, locked=locked_item, settings_state=unreachable, request=valid_item
 * @effects ts_fallback_matches_php_locked_set, ts_fallback_is_not_a_catalog_copy, wildcard_rule_is_isomorphic_across_php_and_ts, scope_vocabulary_matches_across_php_and_ts, server_cookie_gate_reads_settings_not_hardcoded_list, locked_set_absent_from_shipped_catalog, shipped_catalog_items_pass_save_validation
 */

import { describe, it, expect } from 'vitest';
import * as fs from 'fs';
import * as path from 'path';

import {
    ALLOWLIST_SCOPES,
    LOCKED_FALLBACK,
    matchesAllowlistPattern,
    type AllowlistScope,
} from '../necessaryAllowlist';

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
const PLUGIN_ROOT = path.join(REPO_ROOT, 'plugins', '_bundled', 'sirsoft-gdpr');

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
 * @param  file     호출이 있는 파일 절대경로
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
 * @return 도출된 키 목록과 해석 실패 지점
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
 * PHP 원문에서 `이름 => [ ... ]` 형태의 스코프 그룹을 문자열 배열로 파싱합니다.
 *
 * 대상은 `plugin.php` 의 출하 카탈로그와 `Support/NecessaryAllowlist.php` 의 잠금 집합.
 * PHP 를 실행하지 않고 원문을 읽는 이유는, 이 대조가 **두 언어의 목록이 어긋났는지** 를
 * 보는 것이라 한쪽 런타임에 의존하면 안 되기 때문이다.
 *
 * @param  source  PHP 원문 (블록만 잘라낸 것)
 * @return 스코프 => 문자열 배열
 */
function parsePhpScopeMap(source: string): Record<string, string[]> {
    const stripped = source
        .replace(/\/\/[^\n]*/g, '')
        // 런타임 해석 항목(`(string) config('session.cookie', 'laravel_session')`)은 항목 이름이
        // 아니라 조회식이다. 벗겨내지 않으면 설정 키와 폴백 문자열이 목록 항목으로 섞인다.
        .replace(/(?:\(string\)\s*)?config\([^)]*\)/g, 'RUNTIME_RESOLVED');
    const result: Record<string, string[]> = {};

    const scopeRe = /'([A-Za-z]+)'\s*=>\s*\[/g;
    let m: RegExpExecArray | null = scopeRe.exec(stripped);
    while (m !== null) {
        const scope = m[1];
        let depth = 1;
        let i = scopeRe.lastIndex;
        while (i < stripped.length && depth > 0) {
            if (stripped[i] === '[') depth += 1;
            else if (stripped[i] === ']') depth -= 1;
            i += 1;
        }
        const body = stripped.slice(scopeRe.lastIndex, i - 1);
        result[scope] = [...body.matchAll(/'((?:[^'\\]|\\.)*)'/g)].map((x) => x[1]);
        m = scopeRe.exec(stripped);
    }

    return result;
}

/**
 * PHP 원문에서 `const X = [ ... ];` 또는 `return [ ... ];` 블록을 잘라냅니다.
 *
 * @param  source  PHP 파일 원문
 * @param  anchor  블록 시작 앵커 (그 뒤 첫 `[` 부터 대응 `]` 까지)
 * @return 블록 본문
 */
function sliceBracketBlock(source: string, anchor: string): string {
    const at = source.indexOf(anchor);
    if (at === -1) {
        throw new Error(`PHP 앵커를 찾지 못했습니다: ${anchor}`);
    }
    const open = source.indexOf('[', at);
    let depth = 1;
    let i = open + 1;
    while (i < source.length && depth > 0) {
        if (source[i] === '[') depth += 1;
        else if (source[i] === ']') depth -= 1;
        i += 1;
    }
    return source.slice(open + 1, i - 1);
}

const PLUGIN_PHP = fs.readFileSync(path.join(PLUGIN_ROOT, 'plugin.php'), 'utf-8');
const ALLOWLIST_PHP = fs.readFileSync(
    path.join(PLUGIN_ROOT, 'src', 'Support', 'NecessaryAllowlist.php'),
    'utf-8',
);
const MIDDLEWARE_PHP = fs.readFileSync(
    path.join(PLUGIN_ROOT, 'src', 'Http', 'Middleware', 'CookieConsentMiddleware.php'),
    'utf-8',
);
const REQUEST_PHP = fs.readFileSync(
    path.join(PLUGIN_ROOT, 'src', 'Http', 'Requests', 'UpdateAdminSettingsRequest.php'),
    'utf-8',
);

const SHIPPED_CATALOG = parsePhpScopeMap(
    sliceBracketBlock(PLUGIN_PHP, 'DEFAULT_NECESSARY_ALLOWLIST_CATALOG ='),
);
const PHP_LOCKED = parsePhpScopeMap(
    sliceBracketBlock(ALLOWLIST_PHP, 'public static function locked(): array'),
);

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

/**
 * 필수(strictly necessary) 가 **아니라고 의도적으로 판단한** 키.
 *
 * 여기 적힌 키는 functional 동의가 없으면 파기되는 것이 설계 의도다.
 * 새 키를 여기 넣을 때는 사용자에게 그 상실이 수용 가능한지 근거를 함께 남긴다.
 */
const INTENTIONALLY_NON_NECESSARY: ReadonlyArray<{ key: string; reason: string }> = [
    {
        key: 'g7_preferred_currency',
        reason: '표시 통화 — functional 카테고리 설명에 명시된 선호도. 미동의 시 기본 통화로 표시되는 것이 의도된 동작이다.',
    },
];

/**
 * 출하 카탈로그(전 스코프 합집합)가 도출된 키를 덮는지 검사합니다.
 *
 * 저장소 종류는 개별 테스트가 잠그며, 여기서는 "출하 목록이 이 키를 알고 있는가" 만 본다.
 *
 * @param  discovered  도출된 키
 * @return 등재 여부
 */
function isInShippedCatalog(discovered: DiscoveredKey): boolean {
    return Object.values(SHIPPED_CATALOG).some((patterns) =>
        patterns.some((pattern) => (
            // 도출된 키가 접두형이면 카탈로그의 접두 패턴과 같은 계열인지도 본다.
            matchesAllowlistPattern(discovered.key, pattern)
            || (pattern.endsWith('*') && pattern.slice(0, -1) === discovered.key)
        )),
    );
}

describe('strictly necessary 허용목록 모집단 커버리지', () => {
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

    it('출하 카탈로그 파싱이 비어 있지 않다 (파서가 죽으면 3번 축이 공허 통과한다)', () => {
        expect(Object.keys(SHIPPED_CATALOG).sort()).toEqual([...ALLOWLIST_SCOPES].sort());
        expect(SHIPPED_CATALOG.localStorage.length).toBeGreaterThanOrEqual(10);
    });

    it('도출된 모든 저장 키가 출하 카탈로그 등재 또는 의도적 비필수 선언 중 하나에 해당한다', () => {
        const exempt = new Set(INTENTIONALLY_NON_NECESSARY.map((e) => e.key));
        const lockedKeys = new Set([...PHP_LOCKED.localStorage, ...PHP_LOCKED.sessionStorage]);

        const uncovered = discovered
            .filter((d) => !exempt.has(d.key))
            .filter((d) => !lockedKeys.has(d.key))
            .filter((d) => !isInShippedCatalog(d))
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

describe('서버·클라이언트 허용목록 출처 정합성', () => {
    // 발견 ①: 서버는 `config('session.cookie')` 를 런타임 해석하는데 클라이언트는
    // 'laravel_session' 을 하드코딩하고 있었다. SESSION_COOKIE 를 지정한 사이트에서는
    // 클라이언트 목록의 그 항목이 죽어 있었고, 두 목록을 대조하는 테스트가 없었다.
    it('서버 쿠키 게이트가 하드코딩 목록이 아니라 공용 해석기를 경유한다', () => {
        const body = MIDDLEWARE_PHP.slice(MIDDLEWARE_PHP.indexOf('function isStrictlyNecessary'));

        expect(body).toContain("NecessaryAllowlist::matches($name, 'cookie')");
        expect(body).not.toContain('in_array(');
        expect(body).not.toContain("'XSRF-TOKEN'");
        expect(body).not.toContain("'gdpr_session'");
    });

    it('공용 해석기가 운영자 설정을 읽는다 (잠금 집합만 보고 끝내지 않는다)', () => {
        expect(ALLOWLIST_PHP).toContain("g7_plugin_settings(self::PLUGIN_ID, 'necessary_storage_allowlist.'");
    });

    it('세션 쿠키 이름은 런타임 해석 값이다 (하드코딩 금지)', () => {
        const locked = sliceBracketBlock(ALLOWLIST_PHP, 'public static function locked(): array');

        expect(locked).toContain("config('session.cookie'");
        // 폴백 인자로서의 등장 1회만 허용 — 목록에 직접 적힌 항목으로 남아서는 안 된다.
        expect(locked.match(/'laravel_session'/g)?.length ?? 0).toBe(1);
    });

    it('TS 폴백이 PHP 잠금 집합과 정확히 일치한다 (런타임 세션 쿠키 제외)', () => {
        // 세션 쿠키 이름은 파서가 이미 걷어냈다 — TS 폴백에 담을 수 없는 런타임 값이라
        // 이 축의 대조 대상이 아니다 (그 항목의 존재는 바로 위 테스트가 잠근다).
        expect([...LOCKED_FALLBACK.localStorage].sort()).toEqual([...PHP_LOCKED.localStorage].sort());
        expect([...LOCKED_FALLBACK.sessionStorage].sort()).toEqual([...PHP_LOCKED.sessionStorage].sort());
        expect([...LOCKED_FALLBACK.cookie].sort()).toEqual([...PHP_LOCKED.cookie].sort());
    });

    it('TS 폴백은 카탈로그 전체 사본이 아니다 (드리프트 재발 차단)', () => {
        for (const scope of ALLOWLIST_SCOPES) {
            const shipped = SHIPPED_CATALOG[scope] ?? [];
            const overlap = LOCKED_FALLBACK[scope].filter((k) => shipped.includes(k));
            expect(overlap).toEqual([]);
        }

        const fallbackTotal = ALLOWLIST_SCOPES
            .reduce((n, scope) => n + LOCKED_FALLBACK[scope].length, 0);
        expect(fallbackTotal).toBeLessThan(5);
    });

    it('출하 카탈로그는 잠금 항목을 담지 않는다 (담기면 API 로 지울 수 있게 된다)', () => {
        const leaked: string[] = [];
        for (const scope of ALLOWLIST_SCOPES) {
            const locked = PHP_LOCKED[scope] ?? [];
            for (const item of SHIPPED_CATALOG[scope] ?? []) {
                if (locked.includes(item)) {
                    leaked.push(`${scope}:${item}`);
                }
            }
        }

        expect(leaked).toEqual([]);
    });

    it('출하 카탈로그 항목이 저장 검증 규칙을 통과한다 (통과 못 하면 시드값을 다시 저장할 수 없다)', () => {
        const m = /STORAGE_KEY_REGEX = '(.+)';/.exec(REQUEST_PHP);
        expect(m).not.toBeNull();

        // PHP 구분자(/.../)를 벗겨 JS 정규식으로 옮긴다.
        const body = (m as RegExpExecArray)[1].replace(/^\//, '').replace(/\/$/, '');
        const re = new RegExp(body);

        const rejected: string[] = [];
        for (const scope of ALLOWLIST_SCOPES) {
            for (const item of SHIPPED_CATALOG[scope] ?? []) {
                if (!re.test(item) || item.length > 128) {
                    rejected.push(`${scope}:${item}`);
                }
            }
        }

        expect(rejected).toEqual([]);
    });

    // 발견 ②: 저장소 목록은 접두사 매칭을 지원했는데 쿠키 목록은 `includes()` 정확 일치
    // 전용이었다. 규칙이 갈라지면 운영자가 쿠키 카드에 적은 와일드카드가 조용히 무시된다.
    it('와일드카드 매칭 규칙이 PHP·TS 에서 동형이다', () => {
        // PHP 는 원문으로 규칙 3요소를 확인한다 (테스트에서 PHP 를 실행할 수 없으므로).
        const phpBody = ALLOWLIST_PHP.slice(ALLOWLIST_PHP.indexOf('function matchesPattern'));
        expect(phpBody).toContain("str_ends_with($pattern, '*')");
        expect(phpBody).toContain("$prefix !== ''");
        expect(phpBody).toContain('str_starts_with($name, $prefix)');

        // TS 는 구현을 직접 호출해 규칙을 확인한다 (원문 검사보다 강한 증거).
        expect(matchesAllowlistPattern('g7_filters_orders_1', 'g7_filters_*')).toBe(true);
        expect(matchesAllowlistPattern('other_g7_filters_1', 'g7_filters_*')).toBe(false);
        expect(matchesAllowlistPattern('g7_locale', 'g7_locale')).toBe(true);
        expect(matchesAllowlistPattern('g7_locale_extra', 'g7_locale')).toBe(false);
        // `*` 단독은 전체 개방이므로 매칭하지 않는다.
        expect(matchesAllowlistPattern('anything', '*')).toBe(false);
    });

    it('스코프 어휘가 PHP·TS 에서 같다', () => {
        const phpScopes = /public const SCOPES = \[([^\]]+)\]/.exec(ALLOWLIST_PHP);
        expect(phpScopes).not.toBeNull();
        const parsed = [...(phpScopes as RegExpExecArray)[1].matchAll(/'([^']+)'/g)].map((x) => x[1]);

        expect(parsed).toEqual([...ALLOWLIST_SCOPES] as AllowlistScope[]);
    });
});
