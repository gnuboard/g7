/**
 * stateLabelPerTemplate.test.ts — 상태 라벨 평문 금지 · 템플릿별 사전(ko·en·ja) 해석
 *
 * @since engine-v1.78.3
 */

import { describe, it, expect } from 'vitest';
import { readFileSync, existsSync, readdirSync } from 'node:fs';
import { resolve } from 'node:path';

const ROOT = process.cwd();

function load(rel: string): unknown {
  return JSON.parse(readFileSync(resolve(ROOT, rel), 'utf8'));
}

/** 객체를 `editor.a.b.c` 형태의 평탄 키 집합으로 펼친다(배열은 리프 취급). */
function flattenKeys(obj: unknown, prefix: string, out: Set<string>): Set<string> {
  if (obj && typeof obj === 'object' && !Array.isArray(obj)) {
    for (const [k, v] of Object.entries(obj)) {
      const next = prefix ? `${prefix}.${k}` : k;
      if (v && typeof v === 'object' && !Array.isArray(v)) flattenKeys(v, next, out);
      else out.add(next);
    }
  }
  return out;
}

/**
 * 7.1.0 실측(영역 I2) 보강 — 위 검사가 놓친 세 갈래를 잠근다.
 *
 *  1. 평문 라벨: 게시판 스펙의 「답글 펼침」「검증 실패」「첨부·답글 펼침」은 `$t:` 키가 아니라 한국어 평문이라,
 *     콘텐츠 언어를 English 로 바꿔도 상태 고르개에 한국어로 남았다.
 *  2. 템플릿별 해석: 관리자 화면 상태 라벨은 관리자 템플릿 사전으로만 풀린다. 사용자·관리자 사전의 합집합으로 검사하던 탓에
 *     관리자 글 상세의 `editor.state.read_normal`(사용자 사전에만 있음)이 통과했고, 실제 고르개에는 상태 id 「default」가 보였다.
 *  3. ja: 언어팩 사전에도 같은 키가 있어야 한다.
 *
 * 스펙 목록은 저장소에서 파생한다. 이커머스(7.1.0 실측 범위 밖)의 평문 라벨은 기존 결손 목록으로만 허용한다(줄기만 한다).
 */

const KNOWN_PLAIN_LABELS = new Set<string>([
  'sirsoft-ecommerce */admin/ecommerce/shipping-policies/:id/edit edit_mode',
  'sirsoft-ecommerce */admin/ecommerce/shipping-policies/:id/edit charge_fixed',
  'sirsoft-ecommerce */admin/ecommerce/shipping-policies/:id/edit charge_range',
  'sirsoft-ecommerce */admin/ecommerce/shipping-policies/:id/edit charge_per_weight',
  'sirsoft-ecommerce */admin/ecommerce/shipping-policies/:id/edit charge_free',
  'sirsoft-ecommerce */admin/ecommerce/shipping-policies/:id/edit charge_api',
]);

type Tpl = 'sirsoft-basic' | 'sirsoft-admin_basic';

function editorKeys(tpl: Tpl, locale: 'ko' | 'en' | 'ja'): Set<string> {
  const rel = locale === 'ja'
    ? `lang-packs/_bundled/g7-template-${tpl}-ja/frontend/partial/editor.json`
    : `templates/_bundled/${tpl}/lang/partial/${locale}/editor.json`;
  return flattenKeys(load(rel), 'editor', new Set<string>());
}

/** 모든 번들 states 스펙 — [확장 id, 경로]. */
function allStateSpecs(): Array<[string, string]> {
  const out: Array<[string, string]> = [
    ['sirsoft-basic', 'templates/_bundled/sirsoft-basic/editor-spec/states.json'],
    ['sirsoft-admin_basic', 'templates/_bundled/sirsoft-admin_basic/editor-spec/states.json'],
  ];
  for (const base of ['modules/_bundled', 'plugins/_bundled']) {
    for (const ext of readdirSync(resolve(ROOT, base))) {
      const rel = `${base}/${ext}/editor-spec.json`;
      if (existsSync(resolve(ROOT, rel))) out.push([ext, rel]);
    }
  }
  return out;
}

/** 그룹 scope 가 어느 템플릿 사전으로 풀리는가 — 모르면(창) null. */
function templateOf(ext: string, scope: { kind?: string; match?: string }): Tpl | null {
  if (ext === 'sirsoft-basic' || ext === 'sirsoft-admin_basic') return ext;
  const match = scope.match ?? '';
  if (scope.kind === 'route') return match.includes('/admin') ? 'sirsoft-admin_basic' : 'sirsoft-basic';
  if (scope.kind === 'base') return match.includes('admin') ? 'sirsoft-admin_basic' : 'sirsoft-basic';
  return null;
}

describe('states 라벨 — 평문 금지 · 템플릿별 해석 · ja', () => {
  const specs = allStateSpecs().map(([ext, rel]) => {
    const spec = load(rel) as { states?: { groups?: unknown }; groups?: unknown };
    return { ext, groups: ((spec.states?.groups ?? spec.groups) ?? []) as Array<{ scope?: { kind?: string; match?: string }; items?: Array<Record<string, unknown>> }> };
  });
  const dict = {
    'sirsoft-basic': { ko: editorKeys('sirsoft-basic', 'ko'), en: editorKeys('sirsoft-basic', 'en'), ja: editorKeys('sirsoft-basic', 'ja') },
    'sirsoft-admin_basic': { ko: editorKeys('sirsoft-admin_basic', 'ko'), en: editorKeys('sirsoft-admin_basic', 'en'), ja: editorKeys('sirsoft-admin_basic', 'ja') },
  } as const;

  const plain = (): Set<string> => {
    const found = new Set<string>();
    for (const { ext, groups } of specs) {
      for (const g of groups) {
        for (const item of g.items ?? []) {
          const label = item.label;
          if (typeof label === 'string' && !label.startsWith('$t:')) found.add(`${ext} ${g.scope?.match} ${String(item.id)}`);
        }
      }
    }
    return found;
  };

  // @scenario source=bundled
  // @effects state_label_is_translation_key
  it('상태 라벨은 `$t:` 키다(평문이면 콘텐츠 언어가 바뀌어도 한국어로 남는다)', () => {
    expect([...plain()].filter((p) => !KNOWN_PLAIN_LABELS.has(p)).sort()).toEqual([]);
    expect([...KNOWN_PLAIN_LABELS].filter((p) => !plain().has(p)).sort()).toEqual([]);
  });

  // @scenario source=bundled
  // @effects state_label_resolves_in_owning_template_all_locales
  it('`editor.*` 라벨 키가 그 화면을 그리는 템플릿 사전(ko·en·ja)에 있다', () => {
    const missing: string[] = [];
    for (const { ext, groups } of specs) {
      for (const g of groups) {
        const tpl = templateOf(ext, g.scope ?? {});
        for (const item of g.items ?? []) {
          const label = item.label;
          if (typeof label !== 'string' || !label.startsWith('$t:editor.')) continue;
          const key = label.slice(3);
          for (const loc of ['ko', 'en', 'ja'] as const) {
            const ok = tpl ? dict[tpl][loc].has(key) : (dict['sirsoft-basic'][loc].has(key) || dict['sirsoft-admin_basic'][loc].has(key));
            if (!ok) missing.push(`${ext} ${g.scope?.match} ${loc} ${key}`);
          }
        }
      }
    }
    expect(missing.sort()).toEqual([]);
  });
});
