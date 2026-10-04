/**
 * stateScopeKindIsSupported.test.ts — 상태 묶음의 범위 종류가 편집기가 맞춰 보는 종류인지 정적 가드
 *
 * 편집기는 편집 모드에서 범위를 도출해(`deriveCurrentScope`) 묶음의 `scope.kind` 와 글자 그대로 비교한다.
 * 도출되는 종류는 route/base/modal 뿐이라, 그 밖의 종류로 쓴 묶음은 오류 없이 영영 붙지 않는다.
 *
 *  - 7.1.0 실측: 확장 관리 네 화면의 보기 방식(갤러리/목록) 묶음을 레이아웃 이름 범위
 *    (`kind: "layout"`)로 써서, 편집기에서 목록 보기 배치를 한 번도 열 수 없었다. 같은 종류로 쓴
 *    오류 화면(503) 묶음은 편집기 라우트 트리가 오류 레이아웃을 열지 않아 닿을 길도 없었다.
 *
 * 지원 종류는 손으로 적지 않고 `deriveCurrentScope` 가 실제로 내는 값에서 모은다. 모집단은 저장소에서 파생한다.
 *
 * @since engine-v1.76.0
 */

import { describe, it, expect } from 'vitest';
import { readFileSync, existsSync, readdirSync } from 'node:fs';
import { resolve, dirname, join } from 'node:path';
import { deriveCurrentScope } from '../../utils/matchStateScope';
import type { EditMode } from '../../LayoutEditorContext';

const ROOT = process.cwd();
const BUNDLE_BASES = ['modules/_bundled', 'plugins/_bundled', 'templates/_bundled'];

/** 편집 모드마다 대표 입력을 넣어 편집기가 도출하는 범위 종류를 모은다. */
function collectDerivableKinds(): Set<string> {
  const modes: EditMode[] = ['route', 'base', 'modal', 'extension', 'iteration_item'];
  const selected = ['/sample', '__base__/_sample_base', '__modal__/sample_modal'];
  const hosts = ['/host', '__base__/_host_base', '__modal__/host_modal'];
  const kinds = new Set<string>();
  for (const mode of modes) {
    for (const path of selected) {
      for (const host of [undefined, ...hosts]) {
        const scope = deriveCurrentScope(mode, path, host);
        if (scope) kinds.add(scope.kind);
      }
    }
  }
  return kinds;
}

/** 번들 스펙의 상태 묶음 전부(`$include.states` 포함). */
function collectGroups(): Array<{ spec: string; kind: unknown; match: unknown }> {
  const out: Array<{ spec: string; kind: unknown; match: unknown }> = [];
  for (const base of BUNDLE_BASES) {
    const baseDir = resolve(ROOT, base);
    if (!existsSync(baseDir)) continue;
    for (const ext of readdirSync(baseDir)) {
      const manifestRel = `${base}/${ext}/editor-spec.json`;
      const manifestAbs = resolve(ROOT, manifestRel);
      if (!existsSync(manifestAbs)) continue;
      const manifest = JSON.parse(readFileSync(manifestAbs, 'utf8'));
      const sources: Array<{ rel: string; states: any }> = [{ rel: manifestRel, states: manifest.states }];
      const inc = manifest?.$include?.states;
      for (const one of inc ? (Array.isArray(inc) ? inc : [inc]) : []) {
        const rel = join(dirname(manifestRel), one).split('\\').join('/');
        if (existsSync(resolve(ROOT, rel))) {
          const doc = JSON.parse(readFileSync(resolve(ROOT, rel), 'utf8'));
          sources.push({ rel, states: doc.groups ? doc : doc.states });
        }
      }
      for (const { rel, states } of sources) {
        for (const group of states?.groups ?? []) {
          out.push({ spec: rel, kind: group?.scope?.kind, match: group?.scope?.match });
        }
      }
    }
  }
  return out;
}

const DERIVABLE = collectDerivableKinds();
const GROUPS = collectGroups();

describe('상태 묶음 범위 종류 — 모집단', () => {
  /**
   * @effects scope_kind_guard_population_is_derived_from_repository
   */
  it('편집기가 도출하는 종류와 번들 상태 묶음을 저장소에서 파생하며, 둘 다 비어 있지 않다', () => {
    expect([...DERIVABLE].sort()).toEqual(['base', 'modal', 'route']);
    expect(GROUPS.length).toBeGreaterThanOrEqual(300);
    expect(new Set(GROUPS.map((g) => g.kind))).toContain('route');
  });
});

describe('상태 묶음 범위 종류 — 지원 종류', () => {
  /**
   * @effects state_scope_kinds_are_ones_the_editor_derives
   */
  it('모든 상태 묶음의 scope.kind 는 편집기가 도출하는 종류다', () => {
    const offenders = GROUPS.filter((g) => !DERIVABLE.has(String(g.kind))).map(
      (g) => `${String(g.kind)}:${String(g.match)} (${g.spec})`,
    );
    expect(offenders).toEqual([]);
  });
});
