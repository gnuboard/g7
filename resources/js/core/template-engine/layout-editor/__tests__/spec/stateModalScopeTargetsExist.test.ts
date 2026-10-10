/**
 * stateModalScopeTargetsExist.test.ts — 창(modal) 범위 상태 묶음이 편집기가 여는 창을 가리키는지 정적 가드
 *
 * 편집기는 레이아웃 `modals[]`(인라인 정의와 `{ "partial": … }` 참조)에 등록된 창만 「이 화면의 모달」로 열고,
 * 상태 묶음의 `scope.match` 는 그 창 id 와 글자 그대로 맞아야 상태 고르개에 붙는다. 맞지 않으면 묶음은
 * 오류 없이 영영 쓰이지 않는다 — 7.1.0 실측에서 같은 부류가 세 번 나왔다.
 *
 *  - 쇼핑몰 배송지 목록 창 id 가 범용 이름으로 바뀌어 `modal_addresses_*` 묶음이 닿지 않음.
 *  - 관리자 회원 수정의 탈퇴 확인 창은 `data-if` 로 여는 인라인 창이라 창 목록에 없어 묶음이 닿지 않음.
 *  - 모듈 비활성 경고 창은 어느 화면도 싣지 않는 고아 조각이라 묶음이 닿지 않음.
 *  - 사용자 기본 템플릿 스펙에 관리자 화면에만 있는 주문 취소 창(`modal_cancel_order`) 묶음이 있어, 그 템플릿 편집기에서는
 *    영영 쓰이지 않았다. 템플릿 스펙의 창 묶음은 그 템플릿이 그리는 화면(자신 + 같은 쪽 확장 레이아웃)의 창이어야 한다.
 *
 * 모집단은 저장소에서 파생한다(손으로 적은 목록 없음).
 *
 * @since engine-v1.76.0
 */

import { describe, it, expect } from 'vitest';
import { readFileSync, existsSync, readdirSync, statSync } from 'node:fs';
import { resolve, dirname, join } from 'node:path';

const ROOT = process.cwd();
const BUNDLE_BASES = ['modules/_bundled', 'plugins/_bundled', 'templates/_bundled'];

/** 디렉터리 아래 JSON 파일을 모은다. */
function walkJson(absDir: string, out: string[] = []): string[] {
  if (!existsSync(absDir)) return out;
  for (const entry of readdirSync(absDir)) {
    const abs = join(absDir, entry);
    if (statSync(abs).isDirectory()) walkJson(abs, out);
    else if (entry.endsWith('.json')) out.push(abs);
  }
  return out;
}

const readJson = (abs: string): any => JSON.parse(readFileSync(abs, 'utf8'));

/**
 * 조각 참조 경로를 실제 파일로 푼다 — 경로는 호스트 폴더가 아니라 레이아웃 루트 기준이다
 * (템플릿 `layouts/`, 확장 `resources/layouts/{admin,user}`). 호스트 폴더부터 위로 올라가며 찾는다.
 */
function resolvePartial(hostDir: string, extRoot: string, partial: string): string | null {
  let dir = hostDir;
  while (dir.startsWith(extRoot)) {
    const candidate = resolve(dir, partial);
    if (existsSync(candidate)) return candidate;
    const parent = dirname(dir);
    if (parent === dir) break;
    dir = parent;
  }
  return null;
}

/** 번들 레이아웃·확장 조각의 `modals[]` 가 등록하는 창 id 전부. */
function collectRegisteredModalIds(): Set<string> {
  const roots: Array<{ dir: string; extRoot: string }> = [];
  for (const base of BUNDLE_BASES) {
    const baseDir = resolve(ROOT, base);
    if (!existsSync(baseDir)) continue;
    for (const ext of readdirSync(baseDir)) {
      const extRoot = resolve(baseDir, ext);
      for (const sub of ['layouts', 'resources/layouts', 'resources/extensions']) roots.push({ dir: resolve(extRoot, sub), extRoot });
    }
  }
  return collectRegisteredModalIdsFrom(roots);
}

/**
 * 템플릿이 그리는 화면에 등록된 창 id — 템플릿 자신의 레이아웃과, 같은 쪽(user/admin) 확장 레이아웃·확장 조각.
 * 템플릿 스펙의 창 묶음은 그 템플릿 편집기에서만 쓰이므로, 다른 쪽 화면의 창을 가리키면 영영 쓰이지 않는다.
 */
function collectTemplateHostedModalIds(template: string): Set<string> {
  const tplRoot = resolve(ROOT, 'templates/_bundled', template);
  const side = readJson(resolve(tplRoot, 'template.json'))?.type === 'admin' ? 'admin' : 'user';
  const roots: Array<{ dir: string; extRoot: string }> = [{ dir: resolve(tplRoot, 'layouts'), extRoot: tplRoot }];
  for (const base of ['modules/_bundled', 'plugins/_bundled']) {
    const baseDir = resolve(ROOT, base);
    if (!existsSync(baseDir)) continue;
    for (const ext of readdirSync(baseDir)) {
      const extRoot = resolve(baseDir, ext);
      roots.push({ dir: resolve(extRoot, 'resources/layouts', side), extRoot });
      roots.push({ dir: resolve(extRoot, 'resources/extensions'), extRoot });
    }
  }
  return collectRegisteredModalIdsFrom(roots);
}

/** 주어진 레이아웃 루트들의 `modals[]` 가 등록하는 창 id. */
function collectRegisteredModalIdsFrom(roots: Array<{ dir: string; extRoot: string }>): Set<string> {
  const ids = new Set<string>();
  const visit = (node: any, hostDir: string, extRoot: string): void => {
    if (!node || typeof node !== 'object') return;
    if (Array.isArray(node)) {
      node.forEach((child) => visit(child, hostDir, extRoot));
      return;
    }
    if (Array.isArray(node.modals)) {
      for (const entry of node.modals) {
        if (entry && typeof entry.partial === 'string') {
          const partialAbs = resolvePartial(hostDir, extRoot, entry.partial);
          if (partialAbs) {
            const partial = readJson(partialAbs);
            if (typeof partial?.id === 'string') ids.add(partial.id);
          }
        } else if (entry && typeof entry.id === 'string') {
          ids.add(entry.id);
        }
      }
    }
    for (const value of Object.values(node)) visit(value, hostDir, extRoot);
  };

  for (const { dir, extRoot } of roots) {
    for (const file of walkJson(dir)) {
      if (/[\\/]partials[\\/]/.test(file)) continue; // 조각은 호스트의 modals[] 참조로만 등록된다
      visit(readJson(file), dirname(file), extRoot);
    }
  }
  return ids;
}

/** 번들 스펙의 창 범위 상태 묶음 전부(`$include.states` 포함). */
function collectModalScopeGroups(): Array<{ spec: string; match: string }> {
  const out: Array<{ spec: string; match: string }> = [];
  for (const base of BUNDLE_BASES) {
    const baseDir = resolve(ROOT, base);
    if (!existsSync(baseDir)) continue;
    for (const ext of readdirSync(baseDir)) {
      const manifestRel = `${base}/${ext}/editor-spec.json`;
      const manifestAbs = resolve(ROOT, manifestRel);
      if (!existsSync(manifestAbs)) continue;
      const manifest = readJson(manifestAbs);
      const sources: Array<{ rel: string; states: any }> = [{ rel: manifestRel, states: manifest.states }];
      const inc = manifest?.$include?.states;
      for (const one of inc ? (Array.isArray(inc) ? inc : [inc]) : []) {
        const rel = join(dirname(manifestRel), one).split('\\').join('/');
        if (existsSync(resolve(ROOT, rel))) {
          const doc = readJson(resolve(ROOT, rel));
          sources.push({ rel, states: doc.groups ? doc : doc.states });
        }
      }
      for (const { rel, states } of sources) {
        for (const group of states?.groups ?? []) {
          if (group?.scope?.kind === 'modal' && typeof group.scope.match === 'string') {
            out.push({ spec: rel, match: group.scope.match });
          }
        }
      }
    }
  }
  return out;
}

const REGISTERED = collectRegisteredModalIds();
const MODAL_GROUPS = collectModalScopeGroups();

describe('창 범위 상태 묶음 — 모집단', () => {
  /**
   * @effects modal_scope_guard_population_is_derived_from_repository
   */
  it('등록 창 id 와 창 범위 묶음을 저장소에서 파생하며, 둘 다 비어 있지 않다', () => {
    expect(REGISTERED.size).toBeGreaterThanOrEqual(100);
    expect(REGISTERED.has('modal_addresses_delete')).toBe(true);
    expect(MODAL_GROUPS.length).toBeGreaterThanOrEqual(100);
  });
});

describe('창 범위 상태 묶음 — 대상 창', () => {
  /**
   * @effects modal_scope_groups_point_to_registered_modals
   */
  it('모든 창 범위 묶음이 레이아웃 modals[] 에 등록된 창을 가리킨다', () => {
    const offenders = MODAL_GROUPS.filter((g) => !REGISTERED.has(g.match)).map((g) => `${g.match} (${g.spec})`);
    expect([...new Set(offenders)].sort()).toEqual([]);
  });

  /**
   * @effects template_modal_groups_point_to_modals_the_template_renders
   */
  it('템플릿 스펙의 창 묶음은 그 템플릿이 그리는 화면(자신 + 같은 쪽 확장 레이아웃)의 창을 가리킨다', () => {
    const offenders: string[] = [];
    for (const template of ['sirsoft-basic', 'sirsoft-admin_basic']) {
      const hosted = collectTemplateHostedModalIds(template);
      expect(hosted.size).toBeGreaterThanOrEqual(10);
      for (const g of MODAL_GROUPS.filter((one) => one.spec.startsWith(`templates/_bundled/${template}/`))) {
        if (!hosted.has(g.match)) offenders.push(`${g.match} (${g.spec})`);
      }
    }
    expect([...new Set(offenders)].sort()).toEqual([]);
  });
});
