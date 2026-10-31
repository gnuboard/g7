/**
 * 게시판 레이아웃 — 빈 객체 `{}` 로 「비우는」 setState 잠금 시험 (7.1.1 출시 전 점검)
 *
 * @description
 * setState 기본 병합(deep)은 값이 빈 객체 `{}` 이고 기존 값도 객체면 재귀 병합하므로 기존 키를 지우지 못한다
 * (엔진 계약: deep = 중첩 객체 보존). 7.1.0 부터 레이아웃의 `{}` 가 `[]` 로 바뀌지 않고 객체로 남아, 「비우기」로 쓴
 * `{}` 가 아무 일도 하지 않게 됐다 — 환경설정 › 본인인증 정책에서 조건이 있는 정책을 편집한 뒤 「새 정책」을 열면 앞 정책의
 * 조건(과 user_overrides)이 남았다.
 * 규칙: deep 병합 setState 의 값 안에 `{}` 가 있으면(errors 제외 — 엔진이 늘 교체), 같은 동작 목록의 앞쪽 setState 가
 * 그 경로(또는 조상)를 null 로 먼저 덮어야 한다(트러블슈팅 state-global 사례 7). 굽기 산출물(JSON) 전수 정적 판정.
 *
 * @scenario-manifest tests/scenarios/empty-object-reset.yaml
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

/** 확장 루트 */
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../..');
/** 모집단 디렉터리(확장 루트 기준) */
const ROOTS = ['resources/layouts', 'resources/extensions'];
/** 엔진이 늘 통째로 교체하는 키 */
const ALWAYS_REPLACED = new Set(['errors']);
/** setState params 의 제어 키 */
const CONTROL_KEYS = new Set(['target', 'merge', 'if']);

/**
 * 디렉터리 아래 .json 을 모두 모은다.
 *
 * @param dir 시작 디렉터리
 * @returns .json 절대경로 목록
 */
function collectJson(dir: string): string[] {
    if (!fs.existsSync(dir)) return [];
    return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) return collectJson(full);
        return entry.name.endsWith('.json') ? [full] : [];
    });
}

const isPlainObject = (v: unknown): v is Record<string, unknown> => !!v && typeof v === 'object' && !Array.isArray(v);
const isSetState = (a: unknown): a is { handler: string; params?: Record<string, unknown> } =>
    isPlainObject(a) && a.handler === 'setState';

/**
 * 값 안의 빈 객체 경로와 null 경로를 모은다(점 표기 키는 경로로 편다).
 *
 * @param value 값
 * @param prefix 경로 앞부분
 * @param empties 빈 객체 경로
 * @param nulls null 경로
 */
function walkPaths(value: unknown, prefix: string[], empties: string[][], nulls: string[][]): void {
    if (value === null) {
        nulls.push(prefix);
        return;
    }
    if (!isPlainObject(value)) return;
    const keys = Object.keys(value);
    if (keys.length === 0 && prefix.length > 0) {
        empties.push(prefix);
        return;
    }
    for (const k of keys) walkPaths(value[k], [...prefix, ...k.split('.')], empties, nulls);
}

/**
 * setState 하나의 (대상, 빈 객체 경로, null 경로)를 구한다. shallow·replace 병합은 대상이 아니다.
 *
 * @param a setState 동작
 * @returns 판정 재료
 */
function pathsOf(a: { params?: Record<string, unknown> }) {
    const p = a.params ?? {};
    const deep = p.merge === undefined || p.merge === 'deep';
    const empties: string[][] = [];
    const nulls: string[][] = [];
    for (const [k, v] of Object.entries(p)) {
        if (CONTROL_KEYS.has(k)) continue;
        walkPaths(v, k.split('.'), empties, nulls);
    }
    return { target: String(p.target ?? 'local'), deep, empties, nulls };
}

/**
 * 레이아웃 하나에서 「앞에서 null 로 비우지 않은 `{}`」 자리를 찾는다.
 *
 * @param json 레이아웃 JSON
 * @param rel 보고용 경로
 * @returns 위반 목록과 판정한 자리 수
 */
function judge(json: unknown, rel: string): { missing: string[]; checked: number } {
    const missing: string[] = [];
    let checked = 0;
    (function visit(x: unknown) {
        if (Array.isArray(x)) {
            // 동작 목록(순서가 있는 배열)마다 앞쪽 null 을 쌓아 가며 본다
            const cleared: { target: string; path: string[] }[] = [];
            for (const item of x) {
                if (isSetState(item)) {
                    const { target, deep, empties, nulls } = pathsOf(item);
                    if (deep) {
                        for (const e of empties) {
                            if (ALWAYS_REPLACED.has(e[e.length - 1])) continue;
                            checked++;
                            const ok = cleared.some(
                                (c) => c.target === target && c.path.length <= e.length && c.path.every((seg, i) => seg === e[i]),
                            );
                            if (!ok) missing.push(`${rel}: ${target}.${e.join('.')}`);
                        }
                    }
                    nulls.forEach((n) => cleared.push({ target, path: n }));
                }
                visit(item);
            }
            return;
        }
        if (isPlainObject(x)) Object.values(x).forEach(visit);
    })(json);
    return { missing, checked };
}

describe('게시판 — 빈 객체로 비우는 setState 는 앞에서 null 로 덮는다', () => {
    const files = ROOTS.flatMap((r) => collectJson(path.join(ROOT, r)));
    const results = files.map((f) => judge(JSON.parse(fs.readFileSync(f, 'utf8')), path.relative(ROOT, f).split(path.sep).join('/')));

    /**
     * @scenario surface=layout_json_corpus
     */
    it('모집단(빈 객체를 쓰는 deep setState)이 비어 있지 않다', () => {
        expect(results.reduce((n, r) => n + r.checked, 0)).toBeGreaterThan(0);
    });

    /**
     * @scenario surface=layout_json_corpus
     * @effects empty_object_preceded_by_null_reset
     */
    it('deep 병합 setState 의 `{}` 는 모두 앞쪽 null 비우기를 거친다', () => {
        expect(results.flatMap((r) => r.missing)).toEqual([]);
    });
});
