/**
 * chrome i18n 정적 검사
 *
 * 검증:
 *  1. lang/partial/{ko,en}/layout_editor.json 두 파일이 존재 + 동일 키 집합
 *  2. lang/{ko,en}.json 엔트리에 layout_editor $partial 등록
 *  3. layout-editor/ 디렉토리의 .tsx 컴포넌트에 평문 사용자 대면 한국어 문자열이
 *     없음 — 모든 사용자 대면 문자열은 `$t:layout_editor.*` 키로만 노출
 *
 * 본 테스트는 회귀 가드 — 차후 컴포넌트 추가/수정 시 평문 한국어 노출을 자동
 * 차단한다.
 */

import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync, statSync } from 'fs';
import { resolve, join } from 'path';

const repoRoot = resolve(__dirname, '../../../../../../..');
const layoutEditorDir = resolve(__dirname, '../..');

function loadJSON(rel: string): Record<string, any> {
  const path = resolve(repoRoot, rel);
  return JSON.parse(readFileSync(path, 'utf-8'));
}

function flattenKeys(obj: any, prefix = ''): string[] {
  const keys: string[] = [];
  for (const [k, v] of Object.entries(obj ?? {})) {
    const key = prefix ? `${prefix}.${k}` : k;
    if (v && typeof v === 'object' && !Array.isArray(v)) {
      keys.push(...flattenKeys(v, key));
    } else {
      keys.push(key);
    }
  }
  return keys;
}

function walkFiles(dir: string, exts: string[]): string[] {
  const results: string[] = [];
  function walk(current: string): void {
    const entries = readdirSync(current);
    for (const e of entries) {
      const p = join(current, e);
      const st = statSync(p);
      if (st.isDirectory()) {
        if (e === '__tests__' || e === 'node_modules') continue;
        walk(p);
      } else if (st.isFile() && exts.some((ext) => p.endsWith(ext))) {
        results.push(p);
      }
    }
  }
  walk(dir);
  return results;
}

describe('chrome i18n coverage', () => {
  it('lang/partial/ko/layout_editor.json 과 lang/partial/en/layout_editor.json 의 키 집합이 동일하다', () => {
    const ko = loadJSON('lang/partial/ko/layout_editor.json');
    const en = loadJSON('lang/partial/en/layout_editor.json');
    const koKeys = new Set(flattenKeys(ko));
    const enKeys = new Set(flattenKeys(en));

    const missingInEn = [...koKeys].filter((k) => !enKeys.has(k));
    const missingInKo = [...enKeys].filter((k) => !koKeys.has(k));

    expect(missingInEn).toEqual([]);
    expect(missingInKo).toEqual([]);
  });

  it('lang/{ko,en}.json 엔트리에 layout_editor $partial 등록되어 있다', () => {
    const ko = loadJSON('lang/ko.json');
    const en = loadJSON('lang/en.json');
    expect(ko.layout_editor).toBeDefined();
    expect(ko.layout_editor.$partial).toBe('partial/ko/layout_editor.json');
    expect(en.layout_editor).toBeDefined();
    expect(en.layout_editor.$partial).toBe('partial/en/layout_editor.json');
  });

  it('layout-editor/ 컴포넌트 .tsx 에 평문 한국어 사용자 대면 문자열이 없다', () => {
    const files = walkFiles(layoutEditorDir, ['.tsx']);
    expect(files.length).toBeGreaterThan(0);

    // 한국어 음절 범위 (가 ~ 힣)
    const HANGUL_RE = /[가-힯]/;

    const violations: string[] = [];
    for (const path of files) {
      // 파일 전체에서 여러 줄에 걸친 블록 주석(/* ... */, {/* ... */})을 먼저 제거한다.
      // 단일 줄 처리만으로는 JSX 블록 주석 중간 줄(`//`/`*` 로 시작하지 않는 줄)이
      // 사용자 대면 문자열로 오판정된다. 줄 번호 보존을 위해 주석 내용은 개행만 남긴다.
      const raw = readFileSync(path, 'utf-8');
      const blockStripped = raw.replace(/\/\*[\s\S]*?\*\//g, (m) =>
        m.replace(/[^\n]/g, ' ')
      );
      const lines = blockStripped.split(/\r?\n/);
      lines.forEach((line, i) => {
        // 단일 줄 주석 라인 제외 — 회귀 가드 영향 외
        const trimmed = line.trim();
        if (trimmed.startsWith('//') || trimmed.startsWith('*')) {
          return;
        }
        // 라인 안 인라인 주석(//) 부분 제거 후 재검사 (블록 주석은 이미 제거됨)
        const stripped = line.replace(/\/\/.*$/, '');
        if (HANGUL_RE.test(stripped)) {
          violations.push(`${path}:${i + 1}: ${line.trim()}`);
        }
      });
    }

    expect(violations).toEqual([]);
  });
});

// 동작 탭 이벤트 슬롯 라벨은 코어 UI 소관(383-shimmering-tulip).
// ActionRecipeEditor 가 t('layout_editor.action.event.${eventName}') 로 해석하며 fallback 이
// 없어, 번들 editor-spec 의 모든 events 가 코어 action.event 에 정의돼 있어야 raw 키 노출을 막는다.
describe('action.event 라벨 커버리지', () => {
  function collectBundledEvents(): Set<string> {
    const events = new Set<string>();
    const roots = ['templates/_bundled', 'modules/_bundled', 'plugins/_bundled'];
    function scan(dir: string): void {
      let entries: string[];
      try {
        entries = readdirSync(dir);
      } catch {
        return;
      }
      for (const e of entries) {
        const p = join(dir, e);
        let st;
        try {
          st = statSync(p);
        } catch {
          continue;
        }
        if (st.isDirectory()) {
          if (e === 'node_modules') continue;
          scan(p);
        } else if (e === 'componentCapabilities.json' || e === 'editor-spec.json') {
          let json: any;
          try {
            json = JSON.parse(readFileSync(p, 'utf-8'));
          } catch {
            continue;
          }
          const caps = json.componentCapabilities ?? json;
          if (caps && typeof caps === 'object') {
            for (const cap of Object.values(caps)) {
              const ev = (cap as any)?.events;
              if (Array.isArray(ev)) ev.forEach((x) => typeof x === 'string' && events.add(x));
            }
          }
        }
      }
    }
    for (const r of roots) scan(resolve(repoRoot, r));
    return events;
  }

  it('번들 editor-spec 의 모든 events 가 코어 action.event (ko/en) 에 친화 명칭으로 정의됨', () => {
    const ko = loadJSON('lang/partial/ko/layout_editor.json');
    const en = loadJSON('lang/partial/en/layout_editor.json');
    const koEvents = new Set(Object.keys(ko?.action?.event ?? {}));
    const enEvents = new Set(Object.keys(en?.action?.event ?? {}));

    const used = collectBundledEvents();
    expect(used.size, '번들에서 수집된 이벤트가 1개 이상이어야 함').toBeGreaterThan(0);

    const missing: string[] = [];
    for (const ev of used) {
      if (!koEvents.has(ev)) missing.push(`ko:${ev}`);
      if (!enEvents.has(ev)) missing.push(`en:${ev}`);
    }
    expect(missing, `코어 action.event 라벨 누락 → 동작 탭 raw 키 노출`).toEqual([]);
  });
});

// modal.open({ ariaLabel }) 에 `layout_editor.*` 키 원문 리터럴을 넘기면
// 팔레트 등 다이얼로그의 접근성 라벨이 미해석 raw 키("layout_editor.palette.title")로 노출된다.
// ariaLabel 은 반드시 해석 함수(editorAwareT/t/chromeT 등)를 거쳐야 한다. 본 가드는
// layout-editor *.tsx 전수에서 `ariaLabel:` 우변이 `'layout_editor.…'` 문자열 리터럴인
// 경우를 차단한다(EditorCanvasOverlay 회귀 방지).
describe('ariaLabel raw 키 노출 가드', () => {
  it('layout-editor 컴포넌트의 ariaLabel 에 layout_editor.* 키 원문 리터럴이 없다', () => {
    const files = walkFiles(layoutEditorDir, ['.tsx', '.ts']);
    const offenders: string[] = [];
    // ariaLabel: 'layout_editor.…' 또는 "layout_editor.…" (해석 함수 호출이 아닌 순수 리터럴)
    const re = /ariaLabel\s*:\s*(['"])layout_editor\.[^'"]+\1/;
    for (const f of files) {
      const src = readFileSync(f, 'utf-8');
      const lines = src.split('\n');
      lines.forEach((line, i) => {
        if (re.test(line)) offenders.push(`${f.replace(repoRoot, '')}:${i + 1}`);
      });
    }
    expect(
      offenders,
      `ariaLabel 에 raw layout_editor.* 키 리터럴 → 다이얼로그 a11y 라벨 미해석. editorAwareT(...) 로 감쌀 것`,
    ).toEqual([]);
  });
});
