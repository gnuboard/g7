/**
 * SafeExpressionEvaluator
 *
 * G7 템플릿 엔진용 안전한 JavaScript 표현식 인터프리터.
 *
 * `new Function(...)` / `with(ctx)` 기반 평가를 대체한다. 그 방식은
 * `''.constructor.constructor('code')()` 형태의 샌드박스 탈출을 허용했다
 * (CWE-184 / CWE-94, KVE-2026-1915).
 *
 * 이 파일은 표현식 문자열을 AST 로 파싱한 뒤, `eval` / `new Function` /
 * `with` / 코드 문자열 컴파일을 전혀 사용하지 않고 직접 해석(interpret)한다.
 *
 * 의존성이 없어야 한다 (다른 프로젝트 파일 import 금지, npm 의존성 금지) —
 * strict-CSP artifact 컨텍스트에 번들되기 때문.
 *
 * @since engine-v1.59.0
 */

/* ------------------------------------------------------------------ *
 * 보안 상수
 * ------------------------------------------------------------------ */

/**
 * 접근이 금지된 프로퍼티 이름 (프로토타입 오염 / 샌드박스 탈출 방지)
 *
 * legacy 접근자 4종(`__lookupGetter__` 계열)은 프로퍼티를 **키가 아니라 문자열 인자**로
 * 지목하므로 `normalizeKey` 의 키 검사를 원리상 거치지 않는다. 이들은 Object facade 에서
 * 제거한 `getPrototypeOf`/`setPrototypeOf`/`defineProperty` 와 **같은 능력**을 모든 객체에서
 * 상속으로 제공하므로, 이름 자체를 막지 않으면 그 제거가 무의미해진다. 이 4개가 평가기에서
 * 도달 가능한 유일한 프로토타입 읽기·쓰기 프리미티브다.
 *
 * @since engine-v1.60.4
 */
const BLOCKED_PROPERTIES = new Set([
    'constructor',
    '__proto__',
    'prototype',
    '__lookupGetter__',
    '__lookupSetter__',
    '__defineGetter__',
    '__defineSetter__',
]);

/** context 로 shadowing 되지 않았을 때 참조 시 즉시 throw 하는 위험 전역 식별자 */
const DANGEROUS_GLOBALS = new Set([
    'Function',
    'eval',
    'globalThis',
    'window',
    'self',
    'document',
    'require',
    'module',
    'process',
    'Reflect',
    'Proxy',
    'WebAssembly',
    'import',
    'constructor',
]);

/**
 * 화이트리스트에 노출하는 Object 대체 facade.
 *
 * 네이티브 Object 를 그대로 노출하면 리플렉션 static
 * (getPrototypeOf / getOwnPropertyDescriptor / setPrototypeOf / defineProperty / create 등)
 * 을 통해 프로토타입·프로퍼티 디스크립터·Function 에 도달할 수 있다:
 * `Object.getOwnPropertyDescriptor(Object.getPrototypeOf(String), 'constructor').value` → Function.
 * 이 static 들은 프로퍼티 키가 아니라 **문자열 인자**로 프로퍼티를 지목하므로 키 정규화(normalizeKey)로는
 * 잡히지 않는다. 따라서 리플렉션·프로토타입·디스크립터 계열을 전부 제거하고 순수 데이터 계열만 노출한다.
 * (KVE-2026-1915)
 *
 * @since engine-v1.60.1
 */
/**
 * 대상 객체가 공유 전역(화이트리스트 전역/생성자)이면 거부합니다.
 *
 * `WHITELIST_GLOBALS` 는 `Math`/`JSON`/`Date` 등 **실제 전역 참조**를 노출하므로,
 * 대상 검사 없이 `Object.assign`/`freeze` 를 노출하면 표현식이 페이지 전체에 지속되는
 * 전역 변조(`Object.assign(Math, { floor: … })`)를 일으킬 수 있습니다. `delete` 연산자가
 * 이미 identity 로 차단하고 있으므로 facade 의 변형 메서드도 같은 강도로 맞춥니다.
 *
 * @since engine-v1.60.2
 * @param target 변형 대상 후보
 * @param method 거부 메시지에 넣을 메서드명
 * @returns 검사를 통과한 target (그대로 반환)
 */
function assertNotSharedGlobal<T>(target: T, method: string): T {
    if (
        target !== null &&
        (typeof target === 'object' || typeof target === 'function') &&
        WHITELIST_GLOBAL_OBJECT_SET.has(target as object)
    ) {
        throw new Error(`Object.${method} on a built-in global object is not allowed`);
    }

    return target;
}

const SAFE_OBJECT = Object.freeze({
    keys: Object.keys,
    values: Object.values,
    entries: Object.entries,
    // 변형 메서드는 공유 전역을 대상으로 삼지 못하게 감싼다 (delete 가드와 대칭).
    // 복사는 네이티브 Object.assign 이 아니라 defineOwn 으로 한다 — 네이티브는 [[Set]] 이라
    // source 에 own `__proto__` 키가 있으면(`JSON.parse('{"__proto__":…}')` 가 정확히 그렇다)
    // target 의 setter 를 깨워 프로토타입을 교체한다. defineOwn 은 항상 own data property 를
    // 정의하므로 그 경로가 구조적으로 닫힌다. 금지 키는 아예 복사하지 않는다.
    assign: (target: unknown, ...sources: unknown[]): unknown => {
        const dest = assertNotSharedGlobal(target, 'assign') as Record<string, unknown>;

        for (const source of sources) {
            if (source === null || source === undefined) {
                continue;
            }

            for (const key of Object.keys(source as object)) {
                if (BLOCKED_PROPERTIES.has(key)) {
                    throw new Error(`Access to "${key}" is forbidden`);
                }
                defineOwn(dest, key, (source as Record<string, unknown>)[key]);
            }
        }

        return dest;
    },
    freeze: <T>(target: T): T => Object.freeze(assertNotSharedGlobal(target, 'freeze')),
    fromEntries: Object.fromEntries,
    // create 는 프로토타입·디스크립터를 *읽지도 쓰지도* 않는다(신규 객체 생성만) — normalizeKey
    // 로 `.constructor`/`.__proto__` 접근이 이미 차단되므로 탈출 벡터가 아니다. 레이아웃이
    // `Object.assign(Object.create(null), …)` 로 null-proto 맵을 만드는 정당한 사용처가 있다.
    create: Object.create,
    isFrozen: Object.isFrozen,
});

/** context 에 없을 때 해석 가능한 화이트리스트 전역 (실제 JS 참조 제공) */
const WHITELIST_GLOBALS: Record<string, unknown> = {
    Math,
    JSON,
    Date,
    Array,
    Object: SAFE_OBJECT,
    Number,
    String,
    Boolean,
    Set,
    Map,
    WeakSet,
    WeakMap,
    parseInt,
    parseFloat,
    isNaN,
    isFinite,
};

/**
 * `new` 연산이 허용되는 화이트리스트 생성자 이름.
 *
 * `new X(...)` 는 context 를 무시하고 오직 이 이름의 실제 전역 생성자만 사용한다.
 * `Function` / `eval` / `Proxy` / `Reflect` 등은 여기에 없으므로 `new` 대상이 될 수 없다.
 */
const WHITELIST_CONSTRUCTORS: Record<string, new (...args: unknown[]) => unknown> = {
    Date: Date as unknown as new (...args: unknown[]) => unknown,
    Set: Set as unknown as new (...args: unknown[]) => unknown,
    Map: Map as unknown as new (...args: unknown[]) => unknown,
    WeakSet: WeakSet as unknown as new (...args: unknown[]) => unknown,
    WeakMap: WeakMap as unknown as new (...args: unknown[]) => unknown,
    Array: Array as unknown as new (...args: unknown[]) => unknown,
    Number: Number as unknown as new (...args: unknown[]) => unknown,
    String: String as unknown as new (...args: unknown[]) => unknown,
    Boolean: Boolean as unknown as new (...args: unknown[]) => unknown,
    Object: Object as unknown as new (...args: unknown[]) => unknown,
};

/**
 * 화이트리스트 전역 객체(및 생성자)의 실제 참조 집합.
 *
 * `delete` 연산자가 공유 전역(`Math`/`JSON`/`Array` 등)의 프로퍼티를 지우지 못하도록
 * identity 로 차단한다 — 로컬 스코프 객체 사본에 대한 delete 만 허용한다.
 */
const WHITELIST_GLOBAL_OBJECT_SET: Set<object> = new Set(
    [...Object.values(WHITELIST_GLOBALS), ...Object.values(WHITELIST_CONSTRUCTORS)].filter(
        (v): v is object => v !== null && (typeof v === 'object' || typeof v === 'function'),
    ),
);

/**
 * 파서에서 파라미터 이름으로 쓸 수 없는 예약 키워드.
 *
 * `function` 은 함수 표현식으로 파싱되므로 파라미터 이름 위치에서만 거부된다
 * (arrow/함수 파라미터가 `function` 이 되는 것 방지).
 */
const FORBIDDEN_KEYWORDS = new Set(['function']);

/** 옵셔널 체이닝 단락(short-circuit)을 전파하는 내부 센티널 */
const SHORT_CIRCUIT = Symbol('short-circuit');

/* ------------------------------------------------------------------ *
 * 토큰
 * ------------------------------------------------------------------ */

type TokenType = 'num' | 'str' | 'ident' | 'punct' | 'template';

interface Token {
    type: TokenType;
    /** punct: 연산자 문자열 / ident: 이름 / num: 숫자값 / str: 문자열값 / template: '' */
    value: string | number;
    pos: number;
    /** template 전용: 문자열 조각 (exprs.length + 1 개) */
    quasis?: string[];
    /** template 전용: 각 ${} 내부 표현식 소스 문자열 */
    exprs?: string[];
}

/* ------------------------------------------------------------------ *
 * AST 노드
 * ------------------------------------------------------------------ */

type Node =
    | { type: 'Literal'; value: unknown }
    | { type: 'Identifier'; name: string }
    | { type: 'Member'; object: Node; property: Node; computed: boolean; optional: boolean }
    | { type: 'Call'; callee: Node; args: Node[]; optional: boolean }
    | { type: 'Unary'; operator: string; argument: Node }
    | { type: 'Delete'; argument: Node }
    | { type: 'Binary'; operator: string; left: Node; right: Node }
    | { type: 'Logical'; operator: string; left: Node; right: Node }
    | { type: 'Conditional'; test: Node; consequent: Node; alternate: Node }
    | { type: 'Array'; elements: Node[] }
    | { type: 'Object'; properties: ObjectProperty[] }
    | { type: 'Arrow'; params: Param[]; body: Node; isBlock: boolean }
    | { type: 'Function'; name: string | null; params: Param[]; body: Node /* Block */ }
    | { type: 'Template'; quasis: string[]; expressions: Node[] }
    | { type: 'New'; ctor: string; args: Node[] }
    | { type: 'Spread'; argument: Node }
    // ── statement 노드 (function/arrow 블록 본문 안에서만 도달) ──
    | { type: 'Block'; body: Node[] }
    | { type: 'VarDecl'; declarations: { name: string; init: Node }[] }
    | { type: 'If'; test: Node; consequent: Node; alternate: Node | null }
    | { type: 'ForOf'; name: string; iterable: Node; body: Node }
    | { type: 'Return'; argument: Node | null }
    | { type: 'ExprStmt'; expression: Node }
    | { type: 'Break' }
    | { type: 'Continue' }
    | { type: 'Empty' }
    | { type: 'Try'; block: Node; handlerParam: string | null; handler: Node | null; finalizer: Node | null };

/**
 * 함수/화살표 파라미터 (기본값 지원).
 *
 * `name` 이 null 이면 배열 구조분해 패턴 — `elements` 가 요소 이름 목록이며
 * null 요소는 홀(elision)이다 (`([, vid]) =>` → elements: [null, 'vid']).
 * 구 평가기(new Function)가 허용하던 형태로, 레이아웃 전반에서 사용된다.
 *
 * @since engine-v1.60.5
 */
interface Param {
    name: string | null;
    elements?: (string | null)[];
    default: Node | null;
}

interface ObjectProperty {
    kind: 'init' | 'spread';
    key?: Node; // init only
    computed?: boolean; // init only
    value: Node;
}

/* ------------------------------------------------------------------ *
 * 제어 흐름 시그널 (statement 본문 해석용)
 *
 * return/break/continue 는 예외로 던져 트리워킹을 되감는다. 사용자 예외와
 * 구분되도록 전용 클래스 인스턴스를 쓴다(try/catch 가 이들을 삼키지 않도록).
 * ------------------------------------------------------------------ */

class ReturnSignal {
    constructor(public value: unknown) {}
}
class BreakSignal {}
class ContinueSignal {}

/* ------------------------------------------------------------------ *
 * 스코프 (해석 환경)
 * ------------------------------------------------------------------ */

interface Scope {
    vars: Record<string, unknown>;
    parent: Scope | null;
}

/* ------------------------------------------------------------------ *
 * 토크나이저
 * ------------------------------------------------------------------ */

function isIdentStart(ch: string): boolean {
    return /[A-Za-z_$]/.test(ch);
}

function isIdentPart(ch: string): boolean {
    return /[A-Za-z0-9_$]/.test(ch);
}

function isDigit(ch: string): boolean {
    return ch >= '0' && ch <= '9';
}

/**
 * 문자열 리터럴을 건너뛴다 (열린 따옴표 위치부터 닫는 따옴표 다음까지).
 *
 * @param src 소스 문자열
 * @param start 여는 따옴표 인덱스
 * @param quote 따옴표 문자
 * @return 닫는 따옴표 다음 인덱스
 */
function skipStringLiteral(src: string, start: number, quote: string): number {
    let i = start + 1;
    while (i < src.length) {
        if (src[i] === '\\') {
            i += 2;
            continue;
        }
        if (src[i] === quote) {
            return i + 1;
        }
        i++;
    }
    throw new Error(`Unterminated string literal at position ${start}`);
}

/**
 * 백틱 템플릿 리터럴을 스캔해 문자열 조각(quasis)과 `${}` 내부 표현식 소스(exprs)로 분해.
 *
 * `${...}` 내부의 중첩 `{}` / 문자열 / 중첩 템플릿을 올바르게 건너뛴다. 내부 표현식은
 * 여기서 파싱하지 않고 소스 문자열로 보관했다가, 파서가 인터프리터로 재귀 파싱한다
 * (`new Function` 미사용 — 완전히 안전).
 *
 * @param src 소스 문자열
 * @param start 여는 백틱 인덱스
 * @return quasis / exprs / 닫는 백틱 다음 인덱스(end)
 */
function scanTemplateLiteral(src: string, start: number): { quasis: string[]; exprs: string[]; end: number } {
    let i = start + 1;
    const quasis: string[] = [];
    const exprs: string[] = [];
    let cur = '';

    while (i < src.length) {
        const ch = src[i];

        if (ch === '\\') {
            const esc = src[i + 1];
            switch (esc) {
                case 'n': cur += '\n'; break;
                case 't': cur += '\t'; break;
                case 'r': cur += '\r'; break;
                case 'b': cur += '\b'; break;
                case 'f': cur += '\f'; break;
                case 'v': cur += '\v'; break;
                case '0': cur += '\0'; break;
                case '\\': cur += '\\'; break;
                case '`': cur += '`'; break;
                case '$': cur += '$'; break;
                default: cur += esc; break;
            }
            i += 2;
            continue;
        }

        if (ch === '`') {
            quasis.push(cur);
            return { quasis, exprs, end: i + 1 };
        }

        if (ch === '$' && src[i + 1] === '{') {
            quasis.push(cur);
            cur = '';
            i += 2;
            const exprStart = i;
            let depth = 1;
            while (i < src.length && depth > 0) {
                const c = src[i];
                if (c === '{') {
                    depth++;
                    i++;
                } else if (c === '}') {
                    depth--;
                    if (depth === 0) break;
                    i++;
                } else if (c === '"' || c === "'") {
                    i = skipStringLiteral(src, i, c);
                } else if (c === '`') {
                    i = scanTemplateLiteral(src, i).end;
                } else {
                    i++;
                }
            }
            if (depth !== 0) {
                throw new Error('Unterminated ${...} in template literal');
            }
            exprs.push(src.slice(exprStart, i));
            i++; // 닫는 }
            continue;
        }

        cur += ch;
        i++;
    }

    throw new Error(`Unterminated template literal at position ${start}`);
}

function tokenize(src: string): Token[] {
    const tokens: Token[] = [];
    let i = 0;
    const n = src.length;

    const peekAt = (o: number): string => (i + o < n ? src[i + o] : '');

    while (i < n) {
        const ch = src[i];

        // 공백
        if (ch === ' ' || ch === '\t' || ch === '\n' || ch === '\r' || ch === '\f' || ch === '\v') {
            i++;
            continue;
        }

        // 숫자 (.5 형태 포함)
        if (isDigit(ch) || (ch === '.' && isDigit(peekAt(1)))) {
            const start = i;
            while (i < n && isDigit(src[i])) i++;
            if (src[i] === '.') {
                i++;
                while (i < n && isDigit(src[i])) i++;
            }
            if (src[i] === 'e' || src[i] === 'E') {
                i++;
                if (src[i] === '+' || src[i] === '-') i++;
                if (!isDigit(src[i])) throw new Error(`Invalid number literal at position ${start}`);
                while (i < n && isDigit(src[i])) i++;
            }
            const raw = src.slice(start, i);
            tokens.push({ type: 'num', value: Number(raw), pos: start });
            continue;
        }

        // 문자열
        if (ch === '"' || ch === "'") {
            const start = i;
            const quote = ch;
            i++;
            let out = '';
            while (i < n && src[i] !== quote) {
                if (src[i] === '\\') {
                    i++;
                    const esc = src[i];
                    switch (esc) {
                        case 'n': out += '\n'; break;
                        case 't': out += '\t'; break;
                        case 'r': out += '\r'; break;
                        case 'b': out += '\b'; break;
                        case 'f': out += '\f'; break;
                        case 'v': out += '\v'; break;
                        case '0': out += '\0'; break;
                        case '\\': out += '\\'; break;
                        case "'": out += "'"; break;
                        case '"': out += '"'; break;
                        case '`': out += '`'; break;
                        default: out += esc; break;
                    }
                    i++;
                } else {
                    out += src[i];
                    i++;
                }
            }
            if (i >= n) throw new Error(`Unterminated string literal at position ${start}`);
            i++; // 닫는 따옴표
            tokens.push({ type: 'str', value: out, pos: start });
            continue;
        }

        // 식별자
        if (isIdentStart(ch)) {
            const start = i;
            i++;
            while (i < n && isIdentPart(src[i])) i++;
            tokens.push({ type: 'ident', value: src.slice(start, i), pos: start });
            continue;
        }

        // 템플릿 리터럴 (지원): ${} 보간부는 인터프리터로 재귀 파싱된다
        if (ch === '`') {
            const scanned = scanTemplateLiteral(src, i);
            tokens.push({ type: 'template', value: '', pos: i, quasis: scanned.quasis, exprs: scanned.exprs });
            i = scanned.end;
            continue;
        }

        // 연산자 / 구두점
        const start = i;
        const two = src.substr(i, 2);
        const three = src.substr(i, 3);

        // 스프레드
        if (three === '...') {
            tokens.push({ type: 'punct', value: '...', pos: start });
            i += 3;
            continue;
        }

        switch (ch) {
            case '=': {
                if (peekAt(1) === '>') {
                    tokens.push({ type: 'punct', value: '=>', pos: start });
                    i += 2;
                } else if (peekAt(1) === '=') {
                    if (peekAt(2) === '=') {
                        tokens.push({ type: 'punct', value: '===', pos: start });
                        i += 3;
                    } else {
                        tokens.push({ type: 'punct', value: '==', pos: start });
                        i += 2;
                    }
                } else {
                    // bare '=' : 변수 선언(const x = …)·기본 파라미터((a = …) =>)에서만 소비된다.
                    // 표현식 문법은 '=' 를 소비하지 않으므로 대입식(x = y)은 파서에서 거부된다.
                    tokens.push({ type: 'punct', value: '=', pos: start });
                    i += 1;
                }
                continue;
            }
            case '!': {
                if (peekAt(1) === '=') {
                    if (peekAt(2) === '=') {
                        tokens.push({ type: 'punct', value: '!==', pos: start });
                        i += 3;
                    } else {
                        tokens.push({ type: 'punct', value: '!=', pos: start });
                        i += 2;
                    }
                } else {
                    tokens.push({ type: 'punct', value: '!', pos: start });
                    i += 1;
                }
                continue;
            }
            case '<': {
                if (peekAt(1) === '=') {
                    tokens.push({ type: 'punct', value: '<=', pos: start });
                    i += 2;
                } else if (peekAt(1) === '<') {
                    throw new Error('Bitwise/shift operators are not allowed');
                } else {
                    tokens.push({ type: 'punct', value: '<', pos: start });
                    i += 1;
                }
                continue;
            }
            case '>': {
                if (peekAt(1) === '=') {
                    tokens.push({ type: 'punct', value: '>=', pos: start });
                    i += 2;
                } else if (peekAt(1) === '>') {
                    throw new Error('Bitwise/shift operators are not allowed');
                } else {
                    tokens.push({ type: 'punct', value: '>', pos: start });
                    i += 1;
                }
                continue;
            }
            case '&': {
                if (peekAt(1) === '&') {
                    tokens.push({ type: 'punct', value: '&&', pos: start });
                    i += 2;
                } else {
                    throw new Error('Bitwise operators are not allowed');
                }
                continue;
            }
            case '|': {
                if (peekAt(1) === '|') {
                    tokens.push({ type: 'punct', value: '||', pos: start });
                    i += 2;
                } else {
                    throw new Error('Bitwise operators are not allowed');
                }
                continue;
            }
            case '?': {
                // 옵셔널 체이닝 ?. (단, ?.5 처럼 숫자 뒤는 삼항 + 소수)
                if (peekAt(1) === '.' && !isDigit(peekAt(2))) {
                    tokens.push({ type: 'punct', value: '?.', pos: start });
                    i += 2;
                } else if (peekAt(1) === '?') {
                    tokens.push({ type: 'punct', value: '??', pos: start });
                    i += 2;
                } else {
                    tokens.push({ type: 'punct', value: '?', pos: start });
                    i += 1;
                }
                continue;
            }
            case '+': {
                if (peekAt(1) === '+') throw new Error('Increment operator is not allowed');
                if (peekAt(1) === '=') throw new Error('Assignment operators are not allowed');
                tokens.push({ type: 'punct', value: '+', pos: start });
                i += 1;
                continue;
            }
            case '-': {
                if (peekAt(1) === '-') throw new Error('Decrement operator is not allowed');
                if (peekAt(1) === '=') throw new Error('Assignment operators are not allowed');
                tokens.push({ type: 'punct', value: '-', pos: start });
                i += 1;
                continue;
            }
            case '*': {
                if (peekAt(1) === '=') throw new Error('Assignment operators are not allowed');
                if (peekAt(1) === '*') throw new Error('Exponentiation operator is not supported');
                tokens.push({ type: 'punct', value: '*', pos: start });
                i += 1;
                continue;
            }
            case '/': {
                if (peekAt(1) === '=') throw new Error('Assignment operators are not allowed');
                tokens.push({ type: 'punct', value: '/', pos: start });
                i += 1;
                continue;
            }
            case '%': {
                if (peekAt(1) === '=') throw new Error('Assignment operators are not allowed');
                tokens.push({ type: 'punct', value: '%', pos: start });
                i += 1;
                continue;
            }
            case '~':
            case '^':
                throw new Error('Bitwise operators are not allowed');
            case '.':
            case ':':
            case '(':
            case ')':
            case '[':
            case ']':
            case '{':
            case '}':
            case ',':
                tokens.push({ type: 'punct', value: ch, pos: start });
                i += 1;
                continue;
            case ';':
                // statement 구분자 : 블록 본문 파서에서만 소비된다. 최상위 표현식은
                // 단일 식만 허용하므로(parseToAst 가 atEnd 강제) 여기서 남으면 거부된다.
                tokens.push({ type: 'punct', value: ';', pos: start });
                i += 1;
                continue;
            default:
                throw new Error(`Unexpected character "${ch}" at position ${start}` + (two ? '' : ''));
        }
    }

    return tokens;
}

/* ------------------------------------------------------------------ *
 * 파서 (Pratt / precedence-climbing)
 * ------------------------------------------------------------------ */

const BINARY_BP: Record<string, number> = {
    '??': 1,
    '||': 1,
    '&&': 2,
    '===': 3,
    '!==': 3,
    '==': 3,
    '!=': 3,
    '<': 4,
    '>': 4,
    '<=': 4,
    '>=': 4,
    '+': 5,
    '-': 5,
    '*': 6,
    '/': 6,
    '%': 6,
};

const LOGICAL_OPS = new Set(['??', '||', '&&']);

class Parser {
    private tokens: Token[];
    private pos = 0;

    constructor(tokens: Token[]) {
        this.tokens = tokens;
    }

    atEnd(): boolean {
        return this.pos >= this.tokens.length;
    }

    peek(offset = 0): Token | null {
        const idx = this.pos + offset;
        return idx < this.tokens.length ? this.tokens[idx] : null;
    }

    private next(): Token {
        if (this.atEnd()) throw new Error('Unexpected end of expression');
        return this.tokens[this.pos++];
    }

    private isPunct(value: string, offset = 0): boolean {
        const t = this.peek(offset);
        return !!t && t.type === 'punct' && t.value === value;
    }

    private expectPunct(value: string): void {
        const t = this.peek();
        if (!t || t.type !== 'punct' || t.value !== value) {
            throw new Error(`Expected "${value}" but found "${t ? t.value : '<end>'}"`);
        }
        this.pos++;
    }

    /* ------- 최상위 표현식 (arrow → ternary) ------- */

    parseExpression(): Node {
        const arrow = this.tryParseArrow();
        if (arrow) return arrow;
        return this.parseTernary();
    }

    private tryParseArrow(): Node | null {
        const save = this.pos;
        const first = this.peek();
        if (!first) return null;

        // 단일 파라미터: x => ...
        if (first.type === 'ident' && this.isPunct('=>', 1) && !FORBIDDEN_KEYWORDS.has(String(first.value))) {
            this.pos += 1; // ident
            this.pos += 1; // =>
            const { body, isBlock } = this.parseArrowBody();
            return { type: 'Arrow', params: [{ name: String(first.value), default: null }], body, isBlock };
        }

        // 괄호 파라미터: (a, b = [], c) => ...   또는   () => ...
        if (first.type === 'punct' && first.value === '(') {
            this.pos += 1; // (
            const params = this.tryParseParamList();
            if (params && this.isPunct(')')) {
                this.pos += 1; // )
                if (this.isPunct('=>')) {
                    this.pos += 1; // =>
                    const { body, isBlock } = this.parseArrowBody();
                    return { type: 'Arrow', params, body, isBlock };
                }
            }
            // arrow 아님 → 되돌리기
            this.pos = save;
            return null;
        }

        this.pos = save;
        return null;
    }

    /**
     * 파라미터 목록을 파싱한다: `(ident | [배열패턴]) (= 기본값)?` 를 `,` 로 구분.
     * 열림 `(` 는 호출부가 이미 소비한 상태이며, 닫힘 `)` 는 소비하지 않는다(호출부가 검사).
     *
     * @return 파싱된 파라미터 배열, 또는 파라미터 형태가 아니면 null(arrow 아님)
     */
    private tryParseParamList(): Param[] | null {
        const params: Param[] = [];
        if (this.isPunct(')')) return params; // 빈 목록
        for (;;) {
            const p = this.peek();
            let name: string | null = null;
            let elements: (string | null)[] | undefined;
            if (p && p.type === 'punct' && p.value === '[') {
                const pattern = this.tryParseArrayPattern();
                if (!pattern) return null;
                elements = pattern;
            } else if (p && p.type === 'ident' && !FORBIDDEN_KEYWORDS.has(String(p.value))) {
                name = String(p.value);
                this.pos += 1;
            } else {
                return null;
            }
            let def: Node | null = null;
            if (this.isPunct('=')) {
                this.pos += 1; // =
                // 기본값은 assignment 레벨 식(중첩 arrow 허용). parseExpression 은 top-level
                // 콤마를 소비하지 않으므로 다음 파라미터 구분자 ',' 는 그대로 남는다.
                def = this.parseExpression();
            }
            params.push(elements ? { name, elements, default: def } : { name, default: def });
            if (this.isPunct(',')) {
                this.pos += 1;
                continue;
            }
            break;
        }
        return params;
    }

    /**
     * 파라미터 위치의 배열 구조분해 패턴 `[a, , b]` 를 파싱한다.
     * 요소는 식별자 또는 홀(elision)만 허용 — 중첩 패턴·rest·요소별 기본값은
     * 레이아웃 표현식에서 쓰이지 않으므로 지원하지 않는다(발견 시 arrow 아님으로 되돌림).
     *
     * @return 요소 이름 배열 (홀은 null), 패턴 형태가 아니면 null
     * @since engine-v1.60.5
     */
    private tryParseArrayPattern(): (string | null)[] | null {
        const save = this.pos;
        this.pos += 1; // [
        const elements: (string | null)[] = [];
        for (;;) {
            if (this.isPunct(']')) {
                this.pos += 1;
                return elements;
            }
            if (this.isPunct(',')) {
                elements.push(null); // 홀 (elision)
                this.pos += 1;
                continue;
            }
            const t = this.peek();
            if (!t || t.type !== 'ident' || FORBIDDEN_KEYWORDS.has(String(t.value))) {
                this.pos = save;
                return null;
            }
            elements.push(String(t.value));
            this.pos += 1;
            if (this.isPunct(',')) {
                this.pos += 1;
                continue;
            }
            if (this.isPunct(']')) {
                this.pos += 1;
                return elements;
            }
            this.pos = save;
            return null;
        }
    }

    private parseArrowBody(): { body: Node; isBlock: boolean } {
        // 블록 본문: (…) => { statements }   (return 으로 값 반환)
        if (this.isPunct('{')) {
            return { body: this.parseBlock(), isBlock: true };
        }
        // 식 본문: (…) => expr   (중첩 arrow / ternary 허용)
        return { body: this.parseExpression(), isBlock: false };
    }

    /* ------- statement 파서 (function/arrow 블록 본문 전용) ------- */

    /**
     * 블록 `{ stmt* }` 를 파싱한다. 여는 `{` 부터 닫는 `}` 까지.
     *
     * @return Block 노드
     */
    private parseBlock(): Node {
        this.expectPunct('{');
        const body: Node[] = [];
        while (!this.isPunct('}')) {
            if (this.atEnd()) throw new Error('Unterminated block');
            body.push(this.parseStatement());
        }
        this.expectPunct('}');
        return { type: 'Block', body };
    }

    private parseStatement(): Node {
        const t = this.peek();
        if (!t) throw new Error('Unexpected end of statement');

        if (t.type === 'punct' && t.value === '{') return this.parseBlock();
        if (t.type === 'punct' && t.value === ';') {
            this.pos += 1;
            return { type: 'Empty' };
        }

        if (t.type === 'ident') {
            switch (String(t.value)) {
                case 'const':
                case 'let':
                    return this.parseVarDecl();
                case 'if':
                    return this.parseIf();
                case 'for':
                    return this.parseForOf();
                case 'return':
                    return this.parseReturn();
                case 'try':
                    return this.parseTry();
                case 'break':
                    this.pos += 1;
                    this.consumeSemicolon();
                    return { type: 'Break' };
                case 'continue':
                    this.pos += 1;
                    this.consumeSemicolon();
                    return { type: 'Continue' };
                default:
                    break;
            }
        }

        // 식 statement (호출·delete·메서드 부수효과 등)
        const expression = this.parseExpression();
        this.consumeSemicolon();
        return { type: 'ExprStmt', expression };
    }

    /** 선택적 세미콜론 소비 (ASI 관대 처리) */
    private consumeSemicolon(): void {
        if (this.isPunct(';')) this.pos += 1;
    }

    private parseVarDecl(): Node {
        this.pos += 1; // const|let
        const declarations: { name: string; init: Node }[] = [];
        for (;;) {
            const nameTok = this.peek();
            if (!nameTok || nameTok.type !== 'ident') {
                throw new Error('Expected variable name in declaration');
            }
            const name = String(nameTok.value);
            this.pos += 1;
            this.expectPunct('='); // 선언은 초기화 필수 (const/let 재대입 없음)
            // init 은 assignment 레벨 식(arrow 포함). top-level 콤마는 소비하지 않으므로
            // 다음 선언자 구분자 ',' 는 아래 루프가 처리한다.
            const init = this.parseExpression();
            declarations.push({ name, init });
            if (this.isPunct(',')) {
                this.pos += 1;
                continue;
            }
            break;
        }
        this.consumeSemicolon();
        return { type: 'VarDecl', declarations };
    }

    private parseIf(): Node {
        this.pos += 1; // if
        this.expectPunct('(');
        const test = this.parseExpression();
        this.expectPunct(')');
        const consequent = this.parseStatement();
        let alternate: Node | null = null;
        const t = this.peek();
        if (t && t.type === 'ident' && t.value === 'else') {
            this.pos += 1; // else
            alternate = this.parseStatement();
        }
        return { type: 'If', test, consequent, alternate };
    }

    private parseForOf(): Node {
        this.pos += 1; // for
        this.expectPunct('(');
        const kw = this.peek();
        if (!kw || kw.type !== 'ident' || (kw.value !== 'const' && kw.value !== 'let')) {
            throw new Error('Only "for (const x of …)" / "for (let x of …)" loops are supported');
        }
        this.pos += 1; // const|let
        const nameTok = this.peek();
        if (!nameTok || nameTok.type !== 'ident') {
            throw new Error('Expected loop variable name');
        }
        const name = String(nameTok.value);
        this.pos += 1;
        const ofTok = this.peek();
        if (!ofTok || ofTok.type !== 'ident' || ofTok.value !== 'of') {
            throw new Error('Only for-of loops are supported (for-in / C-style for are not allowed)');
        }
        this.pos += 1; // of
        const iterable = this.parseExpression();
        this.expectPunct(')');
        const body = this.parseStatement();
        return { type: 'ForOf', name, iterable, body };
    }

    private parseReturn(): Node {
        this.pos += 1; // return
        // 인자 없는 return; / return }
        if (this.isPunct(';') || this.isPunct('}') || this.atEnd()) {
            this.consumeSemicolon();
            return { type: 'Return', argument: null };
        }
        const argument = this.parseExpression();
        this.consumeSemicolon();
        return { type: 'Return', argument };
    }

    private parseTry(): Node {
        this.pos += 1; // try
        const block = this.parseBlock();
        let handlerParam: string | null = null;
        let handler: Node | null = null;
        let finalizer: Node | null = null;
        const c = this.peek();
        if (c && c.type === 'ident' && c.value === 'catch') {
            this.pos += 1; // catch
            if (this.isPunct('(')) {
                this.pos += 1; // (
                const paramTok = this.peek();
                if (paramTok && paramTok.type === 'ident') {
                    handlerParam = String(paramTok.value);
                    this.pos += 1;
                }
                this.expectPunct(')');
            }
            handler = this.parseBlock();
        }
        const f = this.peek();
        if (f && f.type === 'ident' && f.value === 'finally') {
            this.pos += 1; // finally
            finalizer = this.parseBlock();
        }
        if (!handler && !finalizer) {
            throw new Error('Missing catch or finally after try');
        }
        return { type: 'Try', block, handlerParam, handler, finalizer };
    }

    /** 함수 표현식 `function name?(params) { block }` */
    private parseFunctionExpression(): Node {
        this.pos += 1; // function
        let name: string | null = null;
        const nameTok = this.peek();
        if (nameTok && nameTok.type === 'ident' && nameTok.value !== undefined && !this.isPunct('(')) {
            name = String(nameTok.value);
            this.pos += 1;
        }
        this.expectPunct('(');
        const params = this.tryParseParamList();
        if (!params) throw new Error('Invalid function parameter list');
        this.expectPunct(')');
        const body = this.parseBlock();
        return { type: 'Function', name, params, body };
    }

    private parseTernary(): Node {
        const test = this.parseBinary(0);
        if (this.isPunct('?')) {
            this.pos += 1;
            const consequent = this.parseExpression();
            this.expectPunct(':');
            const alternate = this.parseExpression();
            return { type: 'Conditional', test, consequent, alternate };
        }
        return test;
    }

    private parseBinary(minBp: number): Node {
        let left = this.parseUnary();
        for (;;) {
            const t = this.peek();
            if (!t || t.type !== 'punct') break;
            const op = String(t.value);
            const bp = BINARY_BP[op];
            if (bp === undefined || bp < minBp) break;
            this.pos += 1;
            const right = this.parseBinary(bp + 1);
            if (LOGICAL_OPS.has(op)) {
                left = { type: 'Logical', operator: op, left, right };
            } else {
                left = { type: 'Binary', operator: op, left, right };
            }
        }
        return left;
    }

    private parseUnary(): Node {
        const t = this.peek();
        if (t) {
            if (t.type === 'punct' && (t.value === '!' || t.value === '-' || t.value === '+')) {
                this.pos += 1;
                const argument = this.parseUnary();
                return { type: 'Unary', operator: String(t.value), argument };
            }
            if (t.type === 'ident' && t.value === 'typeof') {
                this.pos += 1;
                const argument = this.parseUnary();
                return { type: 'Unary', operator: 'typeof', argument };
            }
            if (t.type === 'ident' && t.value === 'delete') {
                this.pos += 1;
                const argument = this.parseUnary();
                if (argument.type !== 'Member') {
                    throw new Error('delete is only allowed on a property reference');
                }
                return { type: 'Delete', argument };
            }
        }
        return this.parsePostfix();
    }

    private parsePostfix(): Node {
        let node = this.parsePrimary();
        for (;;) {
            const t = this.peek();
            if (!t || t.type !== 'punct') break;

            if (t.value === '.') {
                this.pos += 1;
                const nameTok = this.next();
                if (nameTok.type !== 'ident') {
                    throw new Error(`Expected property name after "." but found "${nameTok.value}"`);
                }
                const name = String(nameTok.value);
                if (BLOCKED_PROPERTIES.has(name)) {
                    throw new Error(`Access to "${name}" is forbidden`);
                }
                node = {
                    type: 'Member',
                    object: node,
                    property: { type: 'Literal', value: name },
                    computed: false,
                    optional: false,
                };
            } else if (t.value === '?.') {
                this.pos += 1;
                if (this.isPunct('[')) {
                    this.pos += 1;
                    const prop = this.parseExpression();
                    this.expectPunct(']');
                    this.assertComputedKeyNotBlocked(prop);
                    node = { type: 'Member', object: node, property: prop, computed: true, optional: true };
                } else if (this.isPunct('(')) {
                    const args = this.parseArguments();
                    node = { type: 'Call', callee: node, args, optional: true };
                } else {
                    const nameTok = this.next();
                    if (nameTok.type !== 'ident') {
                        throw new Error(`Expected property name after "?." but found "${nameTok.value}"`);
                    }
                    const name = String(nameTok.value);
                    if (BLOCKED_PROPERTIES.has(name)) {
                        throw new Error(`Access to "${name}" is forbidden`);
                    }
                    node = {
                        type: 'Member',
                        object: node,
                        property: { type: 'Literal', value: name },
                        computed: false,
                        optional: true,
                    };
                }
            } else if (t.value === '[') {
                this.pos += 1;
                const prop = this.parseExpression();
                this.expectPunct(']');
                this.assertComputedKeyNotBlocked(prop);
                node = { type: 'Member', object: node, property: prop, computed: true, optional: false };
            } else if (t.value === '(') {
                const args = this.parseArguments();
                node = { type: 'Call', callee: node, args, optional: false };
            } else {
                break;
            }
        }
        return node;
    }

    private assertComputedKeyNotBlocked(prop: Node): void {
        // 문자열 리터럴 computed 키가 금지 프로퍼티라면 파싱 시점에 차단
        if (prop.type === 'Literal' && typeof prop.value === 'string' && BLOCKED_PROPERTIES.has(prop.value)) {
            throw new Error(`Access to "${prop.value}" is forbidden`);
        }
    }

    private parseArguments(): Node[] {
        this.expectPunct('(');
        const args: Node[] = [];
        if (!this.isPunct(')')) {
            for (;;) {
                if (this.isPunct('...')) {
                    this.pos += 1;
                    args.push({ type: 'Spread', argument: this.parseExpression() });
                } else {
                    args.push(this.parseExpression());
                }
                if (this.isPunct(',')) {
                    this.pos += 1;
                    continue;
                }
                break;
            }
        }
        this.expectPunct(')');
        return args;
    }

    private parseNew(): Node {
        this.expectIdent('new');
        const ctorTok = this.peek();
        // 생성자는 반드시 bare 식별자 (멤버 접근/computed 금지 → new x.constructor(...) 차단)
        if (!ctorTok || ctorTok.type !== 'ident') {
            throw new Error('new is only allowed on whitelisted built-in constructors');
        }
        const ctorName = String(ctorTok.value);
        if (!Object.prototype.hasOwnProperty.call(WHITELIST_CONSTRUCTORS, ctorName)) {
            throw new Error('new is only allowed on whitelisted built-in constructors');
        }
        this.pos += 1; // 생성자 이름
        let args: Node[] = [];
        if (this.isPunct('(')) {
            args = this.parseArguments();
        }
        return { type: 'New', ctor: ctorName, args };
    }

    private expectIdent(name: string): void {
        const t = this.peek();
        if (!t || t.type !== 'ident' || t.value !== name) {
            throw new Error(`Expected "${name}"`);
        }
        this.pos += 1;
    }

    private parsePrimary(): Node {
        const t = this.peek();
        if (!t) throw new Error('Unexpected end of expression');

        if (t.type === 'num') {
            this.pos += 1;
            return { type: 'Literal', value: t.value };
        }

        if (t.type === 'str') {
            this.pos += 1;
            return { type: 'Literal', value: t.value };
        }

        if (t.type === 'template') {
            this.pos += 1;
            const quasis = t.quasis || [''];
            const exprSources = t.exprs || [];
            const expressions = exprSources.map((src) => parseToAst(src));
            return { type: 'Template', quasis, expressions };
        }

        if (t.type === 'ident' && t.value === 'new') {
            return this.parseNew();
        }

        // 함수 표현식 `function (…) { … }` — 해석기 클로저로 실행되며 코드 컴파일이 아니다.
        // `.constructor`/`Function`/`eval` 접근 차단은 그대로이므로 샌드박스 탈출로 이어지지 않는다.
        if (t.type === 'ident' && t.value === 'function') {
            return this.parseFunctionExpression();
        }

        if (t.type === 'ident') {
            const name = String(t.value);
            if (FORBIDDEN_KEYWORDS.has(name)) {
                throw new Error(`Keyword "${name}" is not allowed`);
            }
            this.pos += 1;
            switch (name) {
                case 'true':
                    return { type: 'Literal', value: true };
                case 'false':
                    return { type: 'Literal', value: false };
                case 'null':
                    return { type: 'Literal', value: null };
                case 'undefined':
                    return { type: 'Literal', value: undefined };
                default:
                    return { type: 'Identifier', name };
            }
        }

        if (t.type === 'punct') {
            if (t.value === '(') {
                this.pos += 1;
                const expr = this.parseExpression();
                this.expectPunct(')');
                return expr;
            }
            if (t.value === '[') {
                return this.parseArrayLiteral();
            }
            if (t.value === '{') {
                return this.parseObjectLiteral();
            }
        }

        throw new Error(`Unexpected token "${t.value}"`);
    }

    private parseArrayLiteral(): Node {
        this.expectPunct('[');
        const elements: Node[] = [];
        while (!this.isPunct(']')) {
            if (this.isPunct(',')) {
                // 홀(hole) 은 지원하지 않으므로 undefined 로 취급
                this.pos += 1;
                elements.push({ type: 'Literal', value: undefined });
                continue;
            }
            if (this.isPunct('...')) {
                this.pos += 1;
                elements.push({ type: 'Spread', argument: this.parseExpression() });
            } else {
                elements.push(this.parseExpression());
            }
            if (this.isPunct(',')) {
                this.pos += 1;
                continue;
            }
            break;
        }
        this.expectPunct(']');
        return { type: 'Array', elements };
    }

    private parseObjectLiteral(): Node {
        this.expectPunct('{');
        const properties: ObjectProperty[] = [];
        while (!this.isPunct('}')) {
            if (this.isPunct('...')) {
                this.pos += 1;
                properties.push({ kind: 'spread', value: this.parseExpression() });
            } else {
                let key: Node;
                let computed = false;
                const t = this.peek();
                if (!t) throw new Error('Unexpected end of expression in object literal');

                if (t.type === 'punct' && t.value === '[') {
                    this.pos += 1;
                    key = this.parseExpression();
                    this.expectPunct(']');
                    computed = true;
                } else if (t.type === 'str') {
                    this.pos += 1;
                    key = { type: 'Literal', value: t.value };
                } else if (t.type === 'num') {
                    this.pos += 1;
                    key = { type: 'Literal', value: String(t.value) };
                } else if (t.type === 'ident') {
                    this.pos += 1;
                    key = { type: 'Literal', value: String(t.value) };
                } else {
                    throw new Error(`Unexpected token "${t.value}" in object literal`);
                }

                if (this.isPunct(':')) {
                    this.pos += 1;
                    const value = this.parseExpression();
                    properties.push({ kind: 'init', key, computed, value });
                } else {
                    // shorthand: { name } → { name: name }
                    if (computed || key.type !== 'Literal' || typeof key.value !== 'string') {
                        throw new Error('Invalid shorthand property in object literal');
                    }
                    if (t.type !== 'ident') {
                        throw new Error('Invalid shorthand property in object literal');
                    }
                    properties.push({
                        kind: 'init',
                        key,
                        computed: false,
                        value: { type: 'Identifier', name: key.value },
                    });
                }
            }

            if (this.isPunct(',')) {
                this.pos += 1;
                continue;
            }
            break;
        }
        this.expectPunct('}');
        return { type: 'Object', properties };
    }
}

/**
 * 표현식 문자열을 토크나이즈·파싱해 AST 로 반환한다.
 *
 * 템플릿 리터럴 `${}` 보간부도 이 함수로 재귀 파싱되므로, 금지 구문 차단 로직이
 * 보간 표현식에도 동일하게 적용된다.
 *
 * @param expression 파싱할 표현식 소스
 * @return 파싱된 AST 루트 노드
 * @throws {Error} 파싱 오류 또는 금지 구문 발견 시
 */
function parseToAst(expression: string): Node {
    const tokens = tokenize(expression);
    const parser = new Parser(tokens);
    const ast = parser.parseExpression();

    if (!parser.atEnd()) {
        const t = parser.peek();
        if (t && t.type === 'punct' && t.value === ',') {
            throw new Error('The comma/sequence operator is not allowed');
        }
        throw new Error(`Unexpected token "${t ? t.value : '<end>'}" after expression`);
    }

    return ast;
}

/* ------------------------------------------------------------------ *
 * 인터프리터
 * ------------------------------------------------------------------ */

function resolveIdentifier(name: string, scope: Scope): unknown {
    let s: Scope | null = scope;
    while (s) {
        if (Object.prototype.hasOwnProperty.call(s.vars, name)) {
            return s.vars[name];
        }
        s = s.parent;
    }
    // context 에 없을 때만 전역 검사 (context 값이 항상 우선)
    if (Object.prototype.hasOwnProperty.call(WHITELIST_GLOBALS, name)) {
        return WHITELIST_GLOBALS[name];
    }
    if (DANGEROUS_GLOBALS.has(name)) {
        throw new Error(`Reference to forbidden global "${name}" is not allowed`);
    }
    // with(ctx) 시맨틱: 없는 식별자는 undefined
    return undefined;
}

/**
 * computed 키를 프로퍼티 키로 **1회** 정규화하고 금지 프로퍼티를 차단한다.
 *
 * `obj[key]` 는 JS 가 key 를 ToPropertyKey 로 강제변환한다 — `['constructor']`(배열) 이나
 * `{ toString: () => 'constructor' }`(객체) 같은 비-문자열 키는 접근 시점에 'constructor' 로
 * 변환되므로, `typeof key === 'string'` 만 검사하면 우회된다(KVE-2026-1915).
 * 여기서 String 강제변환을 **미리 한 번** 수행해 그 원시 문자열로 차단·접근하므로
 * 재변환(TOCTOU) 여지도 없다. 심볼 키는 금지 문자열 이름이 될 수 없어 그대로 통과시킨다.
 *
 * @param  key  해석된 computed 키(임의 타입)
 * @return 정규화된 안전한 프로퍼티 키
 * @since engine-v1.60.1
 */
function normalizeKey(key: unknown): string | symbol {
    const normalized = typeof key === 'symbol' ? key : String(key);
    if (typeof normalized === 'string' && BLOCKED_PROPERTIES.has(normalized)) {
        throw new Error(`Access to "${normalized}" is forbidden`);
    }
    return normalized;
}

/** Member/Call 체인 하위 노드를 SHORT_CIRCUIT 전파가 가능하도록 평가 */
function evalChainable(node: Node, scope: Scope): unknown {
    if (node.type === 'Member') return evalMember(node, scope);
    if (node.type === 'Call') return evalCall(node, scope);
    return evalNode(node, scope);
}

function evalMember(node: Node & { type: 'Member' }, scope: Scope): unknown {
    const obj = evalChainable(node.object, scope);
    if (obj === SHORT_CIRCUIT) return SHORT_CIRCUIT;
    if (node.optional && (obj === null || obj === undefined)) return SHORT_CIRCUIT;

    let key: unknown;
    if (node.computed) {
        key = evalNode(node.property, scope);
    } else {
        // 비-computed 프로퍼티는 항상 Literal 문자열
        key = (node.property as { type: 'Literal'; value: unknown }).value;
    }

    // 런타임 하드닝: computed 키를 1회 정규화해 금지 프로퍼티 차단 + 정규화된 키로만 접근
    const safeKey = normalizeKey(key);

    if (obj === null || obj === undefined) {
        // 비-optional nullish 접근은 with(ctx) 대비 관대하게 undefined 반환
        return undefined;
    }

    return (obj as Record<PropertyKey, unknown>)[safeKey];
}

function evalCall(node: Node & { type: 'Call' }, scope: Scope): unknown {
    let fn: unknown;
    let thisArg: unknown = undefined;

    if (node.callee.type === 'Member') {
        const member = node.callee;
        const obj = evalChainable(member.object, scope);
        if (obj === SHORT_CIRCUIT) return SHORT_CIRCUIT;
        if (member.optional && (obj === null || obj === undefined)) return SHORT_CIRCUIT;

        let key: unknown;
        if (member.computed) {
            key = evalNode(member.property, scope);
        } else {
            key = (member.property as { type: 'Literal'; value: unknown }).value;
        }
        const safeKey = normalizeKey(key);

        if (obj === null || obj === undefined) {
            fn = undefined;
        } else {
            fn = (obj as Record<PropertyKey, unknown>)[safeKey];
            thisArg = obj;
        }
    } else {
        fn = evalChainable(node.callee, scope);
        if (fn === SHORT_CIRCUIT) return SHORT_CIRCUIT;
    }

    if (node.optional && (fn === null || fn === undefined)) {
        return SHORT_CIRCUIT;
    }

    if (typeof fn !== 'function') {
        throw new Error('Attempted to call a non-function value');
    }

    const args = evalArguments(node.args, scope);
    return (fn as (...a: unknown[]) => unknown).apply(thisArg, args);
}

function evalArguments(argNodes: Node[], scope: Scope): unknown[] {
    const out: unknown[] = [];
    for (const a of argNodes) {
        if (a.type === 'Spread') {
            const v = evalNode(a.argument, scope);
            if (v !== null && v !== undefined) {
                for (const x of v as Iterable<unknown>) out.push(x);
            }
        } else {
            out.push(evalNode(a, scope));
        }
    }
    return out;
}

/**
 * 함수/화살표 파라미터를 로컬 스코프에 바인딩한다 (기본값 지원).
 *
 * 인자가 `undefined` 이고 기본값이 있으면 기본값을 로컬 스코프에서 평가한다
 * (앞선 파라미터를 참조할 수 있는 JS 시맨틱과 일치).
 *
 * @param params 파라미터 목록
 * @param args 실제 인자 배열
 * @param local 바인딩 대상 로컬 스코프
 * @return void
 */
function bindParams(params: Param[], args: unknown[], local: Scope): void {
    for (let idx = 0; idx < params.length; idx++) {
        const p = params[idx];
        let v = args[idx];
        if (v === undefined && p.default) {
            v = evalNode(p.default, local);
        }
        if (p.elements) {
            // 배열 구조분해 패턴 — JS iterator 시맨틱과 동일하게 null/undefined 는 예외
            if (v == null) {
                throw new TypeError(`Cannot destructure ${String(v)}: value is not iterable`);
            }
            const arr = Array.isArray(v) ? v : Array.from(v as Iterable<unknown>);
            for (let e = 0; e < p.elements.length; e++) {
                const name = p.elements[e];
                if (name !== null) local.vars[name] = arr[e];
            }
        } else if (p.name !== null) {
            local.vars[p.name] = v;
        }
    }
}

/**
 * 함수/화살표의 블록 본문을 실행하고 `return` 값을 돌려준다.
 *
 * `return` 은 `ReturnSignal` 로 던져져 여기서 잡힌다. break/continue 시그널은
 * 함수 경계를 넘지 못하므로(문법상 루프 밖 사용은 파싱되지 않음) 통과시킨다.
 *
 * @param body Block 노드
 * @param local 함수 로컬 스코프
 * @return return 값 (없으면 undefined)
 * @since engine-v1.60.0
 */
function runFunctionBody(body: Node, local: Scope): unknown {
    try {
        evalNode(body, local);
    } catch (e) {
        if (e instanceof ReturnSignal) return e.value;
        throw e;
    }
    return undefined;
}

function evalNode(node: Node, scope: Scope): unknown {
    switch (node.type) {
        case 'Literal':
            return node.value;

        case 'Identifier':
            return resolveIdentifier(node.name, scope);

        case 'Member': {
            const r = evalMember(node, scope);
            return r === SHORT_CIRCUIT ? undefined : r;
        }

        case 'Call': {
            const r = evalCall(node, scope);
            return r === SHORT_CIRCUIT ? undefined : r;
        }

        case 'Unary': {
            const v = evalNode(node.argument, scope);
            switch (node.operator) {
                case '!':
                    return !v;
                case '-':
                    return -(v as number);
                case '+':
                    return +(v as number);
                case 'typeof':
                    return typeof v;
                default:
                    throw new Error(`Unknown unary operator "${node.operator}"`);
            }
        }

        case 'Binary': {
            const l = evalNode(node.left, scope) as never;
            const r = evalNode(node.right, scope) as never;
            switch (node.operator) {
                case '+':
                    return (l as never) + (r as never);
                case '-':
                    return (l as number) - (r as number);
                case '*':
                    return (l as number) * (r as number);
                case '/':
                    return (l as number) / (r as number);
                case '%':
                    return (l as number) % (r as number);
                case '===':
                    return l === r;
                case '!==':
                    return l !== r;
                case '==':
                    /* eslint-disable-next-line eqeqeq */
                    return l == r;
                case '!=':
                    /* eslint-disable-next-line eqeqeq */
                    return l != r;
                case '<':
                    return l < r;
                case '>':
                    return l > r;
                case '<=':
                    return l <= r;
                case '>=':
                    return l >= r;
                default:
                    throw new Error(`Unknown binary operator "${node.operator}"`);
            }
        }

        case 'Logical': {
            const l = evalNode(node.left, scope);
            switch (node.operator) {
                case '&&':
                    return l ? evalNode(node.right, scope) : l;
                case '||':
                    return l ? l : evalNode(node.right, scope);
                case '??':
                    return l !== null && l !== undefined ? l : evalNode(node.right, scope);
                default:
                    throw new Error(`Unknown logical operator "${node.operator}"`);
            }
        }

        case 'Conditional':
            return evalNode(node.test, scope)
                ? evalNode(node.consequent, scope)
                : evalNode(node.alternate, scope);

        case 'Array': {
            const out: unknown[] = [];
            for (const el of node.elements) {
                if (el.type === 'Spread') {
                    const v = evalNode(el.argument, scope);
                    if (v !== null && v !== undefined) {
                        for (const x of v as Iterable<unknown>) out.push(x);
                    }
                } else {
                    out.push(evalNode(el, scope));
                }
            }
            return out;
        }

        case 'Object': {
            const out: Record<string, unknown> = {};
            for (const prop of node.properties) {
                if (prop.kind === 'spread') {
                    const v = evalNode(prop.value, scope);
                    if (v !== null && v !== undefined && typeof v === 'object') {
                        for (const k of Object.keys(v as Record<string, unknown>)) {
                            defineOwn(out, k, (v as Record<string, unknown>)[k]);
                        }
                    }
                } else {
                    let key: unknown;
                    if (prop.computed) {
                        key = evalNode(prop.key as Node, scope);
                    } else {
                        key = (prop.key as { type: 'Literal'; value: unknown }).value;
                    }
                    const value = evalNode(prop.value, scope);
                    defineOwn(out, String(key), value);
                }
            }
            return out;
        }

        case 'Arrow': {
            const closure = scope;
            const params = node.params;
            const body = node.body;
            const isBlock = node.isBlock;
            return function arrowFn(...args: unknown[]): unknown {
                const local: Scope = { vars: {}, parent: closure };
                bindParams(params, args, local);
                return isBlock ? runFunctionBody(body, local) : evalNode(body, local);
            };
        }

        case 'Function': {
            const closure = scope;
            const params = node.params;
            const body = node.body;
            const fname = node.name;
            const fn = function namedFn(...args: unknown[]): unknown {
                const local: Scope = { vars: {}, parent: closure };
                if (fname) local.vars[fname] = fn; // 재귀 참조 (function f(){ … f() … })
                bindParams(params, args, local);
                return runFunctionBody(body, local);
            };
            return fn;
        }

        case 'Delete': {
            // 파서가 argument 를 Member 로 강제함
            const m = node.argument as Node & { type: 'Member' };
            const obj = evalNode(m.object, scope);
            let key: unknown;
            if (m.computed) {
                key = evalNode(m.property, scope);
            } else {
                key = (m.property as { type: 'Literal'; value: unknown }).value;
            }
            const safeKey = normalizeKey(key); // constructor/__proto__/prototype 삭제 차단
            if (obj === null || obj === undefined) return true;
            if (typeof obj !== 'object' && typeof obj !== 'function') return true;
            if (WHITELIST_GLOBAL_OBJECT_SET.has(obj as object)) {
                throw new Error('delete on a built-in global object is not allowed');
            }
            return delete (obj as Record<PropertyKey, unknown>)[safeKey];
        }

        /* ── statement 노드 (function/arrow 블록 본문 안에서만 도달) — @since engine-v1.60.0 ── */

        case 'Block': {
            // 블록마다 새 스코프 → const 선언은 블록 지역, 상위 스코프 값은 상속
            const child: Scope = { vars: {}, parent: scope };
            for (const s of node.body) {
                evalNode(s, child);
            }
            return undefined;
        }

        case 'VarDecl': {
            for (const d of node.declarations) {
                scope.vars[d.name] = evalNode(d.init, scope);
            }
            return undefined;
        }

        case 'If': {
            if (evalNode(node.test, scope)) {
                evalNode(node.consequent, scope);
            } else if (node.alternate) {
                evalNode(node.alternate, scope);
            }
            return undefined;
        }

        case 'ForOf': {
            const iterable = evalNode(node.iterable, scope);
            if (iterable !== null && iterable !== undefined) {
                for (const v of iterable as Iterable<unknown>) {
                    const child: Scope = { vars: {}, parent: scope };
                    child.vars[node.name] = v;
                    try {
                        evalNode(node.body, child);
                    } catch (e) {
                        if (e instanceof ContinueSignal) continue;
                        if (e instanceof BreakSignal) break;
                        throw e;
                    }
                }
            }
            return undefined;
        }

        case 'Return':
            throw new ReturnSignal(node.argument ? evalNode(node.argument, scope) : undefined);

        case 'ExprStmt':
            evalNode(node.expression, scope);
            return undefined;

        case 'Break':
            throw new BreakSignal();

        case 'Continue':
            throw new ContinueSignal();

        case 'Empty':
            return undefined;

        case 'Try': {
            try {
                try {
                    evalNode(node.block, scope);
                } catch (e) {
                    // 제어 흐름 시그널은 catch 로 삼키지 않고 전파
                    if (e instanceof ReturnSignal || e instanceof BreakSignal || e instanceof ContinueSignal) {
                        throw e;
                    }
                    if (node.handler) {
                        const child: Scope = { vars: {}, parent: scope };
                        if (node.handlerParam) child.vars[node.handlerParam] = e;
                        evalNode(node.handler, child);
                    } else {
                        throw e;
                    }
                }
            } finally {
                if (node.finalizer) evalNode(node.finalizer, scope);
            }
            return undefined;
        }

        case 'New': {
            // context 를 무시하고 오직 화이트리스트 전역 생성자만 사용 (shadowing 무력화)
            const Ctor = WHITELIST_CONSTRUCTORS[node.ctor];
            if (typeof Ctor !== 'function') {
                throw new Error('new on non-whitelisted constructor');
            }
            const args = evalArguments(node.args, scope);
            return new Ctor(...args);
        }

        case 'Template': {
            let out = node.quasis[0] ?? '';
            for (let idx = 0; idx < node.expressions.length; idx++) {
                out += String(evalNode(node.expressions[idx], scope));
                out += node.quasis[idx + 1] ?? '';
            }
            return out;
        }

        case 'Spread':
            // Spread 는 Array/Object/Call 컨텍스트에서만 직접 처리된다
            throw new Error('Unexpected spread element');

        default:
            throw new Error(`Unknown node type "${(node as { type: string }).type}"`);
    }
}

/**
 * `__proto__` 같은 특수 키의 setter 발동을 피하기 위해 항상 own data property 로 정의.
 *
 * @param obj 대상 객체
 * @param key 프로퍼티 키
 * @param value 값
 * @return void
 */
function defineOwn(obj: Record<string, unknown>, key: string, value: unknown): void {
    Object.defineProperty(obj, key, {
        value,
        enumerable: true,
        writable: true,
        configurable: true,
    });
}

/* ------------------------------------------------------------------ *
 * 공개 API
 * ------------------------------------------------------------------ */

/**
 * 안전한 JavaScript 표현식을 평가한다.
 *
 * `{{ }}` 가 이미 제거된 단일 JS 표현식 문자열을 AST 로 파싱한 뒤 context 에
 * 대해 해석한다. `eval` / `new Function` / `with` 를 사용하지 않는다.
 *
 * @param expression 평가할 표현식 (단일 JS 식)
 * @param context 식별자 해석용 컨텍스트 객체
 * @return 표현식 평가 결과
 * @throws {Error} 파싱 오류 또는 금지 구문(보안 위반) 발견 시
 */
export function evaluateSafeExpression(expression: string, context: Record<string, unknown>): unknown {
    if (typeof expression !== 'string') {
        throw new Error('Expression must be a string');
    }

    const ast = parseToAst(expression);
    const rootScope: Scope = { vars: context || {}, parent: null };
    return evalNode(ast, rootScope);
}
