/**
 * @file semanticClasses.ts
 * @description 사용자 템플릿 의미 클래스(`src/styles/main.css` 의 `.text-muted`, `.form-error`, `.card-plain` 등)를
 * 그 정의(@apply 유틸)로 펼쳐 주는 시험 도우미 (#399 R-1).
 *
 * 레이아웃이 반복되던 Tailwind 묶음(예: `text-xs text-red-600 dark:text-red-400`)을 의미 클래스(`form-error`)로 바꿨으므로,
 * 모양을 지키던 시험은 클래스 이름만 보지 않고 그 이름이 템플릿 CSS 에서 같은 유틸로 정의돼 있는지까지 본다.
 * 펼친 결과에는 원래 토큰과 정의 토큰이 함께 들어 있어 종전 단정(`toContain('text-red-600')`, `/dark:/`)을 그대로 쓸 수 있다.
 */

import fs from 'node:fs';
import path from 'node:path';

/** jsdom 환경에서는 import.meta.url 이 file 주소가 아니므로 이 파일 위치(vite-node 가 주는 __dirname)로 찾는다 */
const MAIN_CSS_PATH = path.resolve(__dirname, '../../../src/styles/main.css');

let cssCache: string | null = null;

/**
 * 템플릿 main.css 원문.
 *
 * @returns CSS 원문
 */
function mainCss(): string {
    if (cssCache === null) cssCache = fs.readFileSync(MAIN_CSS_PATH, 'utf8');
    return cssCache;
}

/**
 * 의미 클래스 하나의 @apply 유틸 토큰(단독 선택자 `.이름 { ... }` 블록 기준).
 *
 * @param name 점 없는 클래스 이름
 * @returns 정의 토큰 목록, 정의가 없으면 null
 */
export function semanticApplyTokens(name: string): string[] | null {
    const escaped = name.replace(/[-/\\^$*+?.()|[\]{}:]/g, '\\$&');
    const block = mainCss().match(new RegExp(`(?:^|\\n)\\s*\\.${escaped}\\s*\\{([^}]*)\\}`))?.[1];
    if (block === undefined) return null;
    const tokens = [...block.matchAll(/@apply\s+([^;]+);/g)].flatMap((m) => m[1].trim().split(/\s+/));
    return tokens.length > 0 ? tokens : null;
}

/**
 * className 의 의미 클래스를 정의 유틸로 펼친 토큰 목록(원래 토큰 + 정의 토큰).
 *
 * @param className 레이아웃 노드 className
 * @returns 펼친 토큰 목록
 */
export function expandSemanticTokens(className: string | undefined | null): string[] {
    const tokens = String(className ?? '').split(/\s+/).filter(Boolean);
    return tokens.flatMap((t) => [t, ...(semanticApplyTokens(t) ?? [])]);
}

/**
 * className 의 의미 클래스를 정의 유틸로 펼친 문자열(공백 구분).
 *
 * @param className 레이아웃 노드 className
 * @returns 펼친 className
 */
export function expandSemanticClassName(className: string | undefined | null): string {
    return expandSemanticTokens(className).join(' ');
}
