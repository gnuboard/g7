/**
 * @file layout-translation-keys.test.ts
 * @description 레이아웃이 참조하는 `$t:` 키가 템플릿 언어 파일에 실제로 정의돼 있는지 전수 검사
 *
 * Chrome MCP 정밀 점검에서 `common.refresh` / `common.activate` / `common.skip` 세 키가
 * 정의되지 않아, 게시판·이커머스 목록의 새로고침 버튼과 언어팩 재활성화 모달에 키 원문이
 * 그대로 노출됐다(D13). 화면에는 아무 오류도 뜨지 않고 문자열만 깨져 보인다.
 *
 * 특정 키를 손으로 열거하면 다음에 추가되는 키를 다시 놓친다. 그래서 이 테스트는
 * **레이아웃에서 참조된 키를 스캔해** 그 전부가 ko/en 양쪽에 있는지 확인한다.
 * 검사 대상에는 이 템플릿의 레이아웃뿐 아니라, 같은 관리자 화면에 얹히는 번들
 * 모듈/플러그인 레이아웃도 포함한다 — 이들이 참조하는 `common.*` 도 결국 이 템플릿의
 * 언어 파일로 해석되기 때문이다.
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const here = path.dirname(fileURLToPath(import.meta.url));
const templateDir = path.resolve(here, '../..');

/** artisan 파일을 기준으로 프로젝트 루트를 찾는다 (_bundled/활성 디렉토리 모두 호환) */
function findProjectRoot(startDir: string): string {
  let dir = startDir;
  while (dir !== path.dirname(dir)) {
    if (fs.existsSync(path.join(dir, 'artisan'))) return dir;
    dir = path.dirname(dir);
  }
  return path.resolve(startDir, '../..');
}

const rootDir = findProjectRoot(templateDir);

/** 디렉토리 하위의 모든 JSON 파일 경로를 수집한다 */
function collectJsonFiles(dir: string): string[] {
  if (!fs.existsSync(dir)) return [];

  return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) return collectJsonFiles(full);
    return entry.isFile() && entry.name.endsWith('.json') ? [full] : [];
  });
}

/** 확장 종류별 번들 레이아웃 디렉토리를 수집한다 */
function collectBundledLayoutDirs(kind: 'modules' | 'plugins'): string[] {
  const base = path.join(rootDir, kind, '_bundled');
  if (!fs.existsSync(base)) return [];

  return fs
    .readdirSync(base, { withFileTypes: true })
    .filter((e) => e.isDirectory())
    .map((e) => path.join(base, e.name, 'resources', 'layouts'))
    .filter((p) => fs.existsSync(p));
}

const LAYOUT_DIRS = [
  path.join(templateDir, 'layouts'),
  ...collectBundledLayoutDirs('modules'),
  ...collectBundledLayoutDirs('plugins'),
];

/** `$t:<namespace>.<key>` 참조를 수집한다 (namespace → key 집합) */
function collectTranslationRefs(): Map<string, Map<string, Set<string>>> {
  const refs = new Map<string, Map<string, Set<string>>>();
  const pattern = /\$t:([a-zA-Z0-9_]+)\.([a-zA-Z0-9_.]+)/g;

  for (const dir of LAYOUT_DIRS) {
    for (const file of collectJsonFiles(dir)) {
      const raw = fs.readFileSync(file, 'utf8');
      for (const match of raw.matchAll(pattern)) {
        const [, namespace, key] = match;
        // 표현식 끝의 마침표는 키의 일부가 아니다
        const normalized = key.replace(/\.$/, '');
        if (!refs.has(namespace)) refs.set(namespace, new Map());
        const byKey = refs.get(namespace)!;
        if (!byKey.has(normalized)) byKey.set(normalized, new Set());
        byKey.get(normalized)!.add(path.relative(rootDir, file));
      }
    }
  }

  return refs;
}

/** 로케일별 언어 파일을 읽는다 (없으면 null) */
function loadLangFile(locale: string, namespace: string): Record<string, unknown> | null {
  const file = path.join(templateDir, 'lang', 'partial', locale, `${namespace}.json`);
  if (!fs.existsSync(file)) return null;

  return JSON.parse(fs.readFileSync(file, 'utf8'));
}

/** 점 표기 경로가 객체에 존재하는지 확인한다 */
function hasPath(source: Record<string, unknown>, dotted: string): boolean {
  return dotted.split('.').reduce<unknown>(
    (acc, segment) =>
      acc && typeof acc === 'object' ? (acc as Record<string, unknown>)[segment] : undefined,
    source
  ) !== undefined;
}

const REFS = collectTranslationRefs();
const LOCALES = ['ko', 'en'];

describe('레이아웃 $t: 키 정의 검사', () => {
  it('검사 대상 레이아웃과 참조 키가 실제로 수집되어야 함 (모집단 도달 확인)', () => {
    expect(LAYOUT_DIRS.length).toBeGreaterThan(1);
    expect(REFS.get('common')?.size ?? 0).toBeGreaterThan(10);
  });

  it.each(LOCALES)('common 네임스페이스의 모든 참조 키가 %s 에 정의되어야 함', (locale) => {
    const defined = loadLangFile(locale, 'common');
    expect(defined, `lang/partial/${locale}/common.json 이 없습니다`).not.toBeNull();

    const missing: string[] = [];
    for (const [key, files] of REFS.get('common') ?? []) {
      if (!hasPath(defined!, key)) {
        missing.push(`common.${key} (참조: ${[...files].slice(0, 3).join(', ')})`);
      }
    }

    expect(
      missing,
      `정의되지 않은 키가 화면에 원문으로 노출됩니다:\n  - ${missing.join('\n  - ')}`
    ).toEqual([]);
  });

  it('D13 회귀: 새로고침·활성화·건너뛰기 키가 ko/en 양쪽에 있어야 함', () => {
    for (const locale of LOCALES) {
      const defined = loadLangFile(locale, 'common')!;
      for (const key of ['refresh', 'activate', 'skip']) {
        expect(hasPath(defined, key), `${locale}/common.json 에 ${key} 누락`).toBe(true);
      }
    }
  });
});
