// e2e:allow Playwright 스펙 없음(이 플러그인 tests/Playwright/specs/sso-*.spec.ts 는 로그인 흐름만 덮는다) — 브라우저 실측은 F1 Chrome MCP 계획서 §9.6·§10 M4~M6·M10~M11(W40) — P4 는 Vitest 로 덮음
/**
 * @file p4-layout-helpers.ts
 * @description P4 레이아웃 계약 시험 공용 도우미 — 구운 JSON 읽기, 트리 평탄화, 문구 키 수집, ko/en 존재 확인.
 */
import fs from 'fs';
import path from 'path';

/** 플러그인 루트 */
export const ROOT = path.resolve(__dirname, '../../../..');

/**
 * 구운 레이아웃 JSON 을 읽습니다.
 *
 * @param relative - 플러그인 루트 기준 경로 (확장자 없이)
 * @returns JSON
 */
export function load(relative: string): any {
  return JSON.parse(fs.readFileSync(path.join(ROOT, `${relative}.json`), 'utf8'));
}

/**
 * 화면 문구 파일을 읽습니다.
 *
 * @param locale - ko, en
 * @returns 문구 트리
 */
export function lang(locale: string): any {
  return JSON.parse(fs.readFileSync(path.join(ROOT, `resources/lang/${locale}.json`), 'utf8'));
}

/**
 * 노드와 액션 트리를 평탄화합니다.
 *
 * @param node - 노드
 * @returns 객체 목록
 */
export function flatten(node: any): any[] {
  if (!node || typeof node !== 'object') return [];
  if (Array.isArray(node)) return node.flatMap(flatten);
  return [node, ...Object.values(node).flatMap(flatten)];
}

/**
 * 플러그인 문구 키를 모읍니다 (`$t:` 토큰과 `$t('…')` 고정 키 호출).
 *
 * @param json - 구운 JSON
 * @returns 키 목록 (플러그인 접두 제외)
 */
export function pluginKeys(json: any): string[] {
  const text = JSON.stringify(json);
  const tokens = [...text.matchAll(/\$t:(?:defer:)?sirsoft-sso\.([a-z0-9_.]+[a-z0-9_])/g)].map((m) => m[1]);
  const calls = [...text.matchAll(/\$t\('sirsoft-sso\.([a-z0-9_.]+[a-z0-9_])'\)/g)].map((m) => m[1]);

  return [...new Set([...tokens, ...calls])];
}

/**
 * 점 경로로 값을 꺼냅니다.
 *
 * @param obj - 객체
 * @param key - 점 경로
 * @returns 값
 */
export function dig(obj: any, key: string): unknown {
  return key.split('.').reduce((acc, part) => (acc == null ? undefined : acc[part]), obj);
}

/**
 * ko/en 어느 한쪽에라도 없는 키를 돌려줍니다.
 *
 * @param keys - 키 목록
 * @returns 빠진 키
 */
export function missingKeys(keys: string[]): string[] {
  const ko = lang('ko');
  const en = lang('en');

  return keys.filter((key) => typeof dig(ko, key) !== 'string' || typeof dig(en, key) !== 'string');
}

/**
 * 같은 data-ref(id) 를 가진 노드를 찾습니다.
 *
 * @param json - 구운 JSON
 * @param id - 노드 id
 * @returns 노드 (없으면 undefined)
 */
export function byId(json: any, id: string): any {
  return flatten(json).find((n) => n?.id === id);
}

/**
 * JSON 전체 문자열.
 *
 * @param json - 구운 JSON
 * @returns 문자열
 */
export const text = (json: any): string => JSON.stringify(json);
