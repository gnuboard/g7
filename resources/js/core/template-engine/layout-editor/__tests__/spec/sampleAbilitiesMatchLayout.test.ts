/**
 * sampleAbilitiesMatchLayout.test.ts — 편집기 표본이 레이아웃이 읽는 권한 플래그(`abilities.can_*`)를 담는지 정적 가드
 *
 * 레이아웃은 `{id}?.data?.abilities?.can_update` 처럼 응답의 권한 플래그로 배너·버튼·입력 잠금을 가른다.
 * 표본에 그 플래그가 없으면 편집기 기본 화면이 「권한 없음」 모습으로 그려진다 — 7.1.0 실측(영역 I2 후속)에서
 * 게시판 환경설정 편집 화면이 「보기 권한만 있습니다」 띠를 띄우고 하단 [저장] 묶음을 숨긴 채 열렸다
 * (실 응답은 `abilities.can_update` 를 늘 싣는다 — BoardSettingsController::index).
 *
 * 판정: 번들 레이아웃 JSON 이 `{id}?.data?.abilities?.{key}` 를 읽고 그 화면이 쓰는 표본(`data` 가 객체)이 있으면,
 * 표본 `data.abilities` 가 그 key 를 **명시**한다(true/false 어느 쪽이든 — 빠진 key 는 의도를 알 수 없다).
 * 표본 해소는 편집기와 같다: 확장 레이아웃은 그 확장 스펙 표본(출처별 bySource) → 템플릿 표본 → 다른 확장 표본,
 * 템플릿 레이아웃은 템플릿 표본 → 확장 표본.
 *
 * @since engine-v1.78.4
 */

import { describe, it, expect } from 'vitest';
import { readFileSync, existsSync, readdirSync, statSync } from 'node:fs';
import { join, resolve, relative, sep } from 'node:path';

const ROOT = process.cwd();

type Json = any;
type ById = Record<string, Json>;

function walkJson(absDir: string, out: string[] = []): string[] {
  if (!existsSync(absDir)) return out;
  for (const entry of readdirSync(absDir)) {
    const abs = join(absDir, entry);
    if (statSync(abs).isDirectory()) walkJson(abs, out);
    else if (entry.endsWith('.json')) out.push(abs);
  }
  return out;
}

function readJson(abs: string): Json {
  return JSON.parse(readFileSync(abs, 'utf-8'));
}

/** 확장별 표본(module/plugin editor-spec) · 템플릿별 표본 · 확장 표본 합집합. */
function loadSamples(): { ext: Record<string, ById>; tpl: Record<string, ById>; flat: ById } {
  const ext: Record<string, ById> = {};
  const tpl: Record<string, ById> = {};
  const flat: ById = {};
  for (const base of ['modules/_bundled', 'plugins/_bundled']) {
    for (const id of readdirSync(resolve(ROOT, base))) {
      const f = resolve(ROOT, base, id, 'editor-spec.json');
      if (!existsSync(f)) continue;
      ext[id] = readJson(f)?.sampleData?.byDataSourceId ?? {};
      Object.assign(flat, ext[id]);
    }
  }
  for (const id of readdirSync(resolve(ROOT, 'templates/_bundled'))) {
    const f = resolve(ROOT, 'templates/_bundled', id, 'editor-spec', 'sampleData.json');
    if (existsSync(f)) tpl[id] = readJson(f)?.byDataSourceId ?? {};
  }
  return { ext, tpl, flat };
}

describe('편집기 표본 — 레이아웃이 읽는 권한 플래그', () => {
  // @scenario source=bundled
  // @effects sample_abilities_declare_layout_keys
  it('레이아웃이 `{id}?.data?.abilities?.{key}` 로 읽는 표본이 그 key 를 명시한다', () => {
    const { ext, tpl, flat } = loadSamples();
    const offenders = new Set<string>();
    let reads = 0;

    for (const base of ['templates/_bundled', 'modules/_bundled', 'plugins/_bundled']) {
      for (const owner of readdirSync(resolve(ROOT, base))) {
        for (const dir of ['layouts', 'resources/layouts', 'resources/extensions']) {
          for (const file of walkJson(join(resolve(ROOT, base, owner), dir))) {
            const rel = relative(ROOT, file).split(sep).join('/');
            const isTemplate = base === 'templates/_bundled';
            const hostTemplate = isTemplate
              ? owner
              : /\/(admin)\//.test(rel) || /admin_/.test(rel)
                ? 'sirsoft-admin_basic'
                : 'sirsoft-basic';
            const text = readFileSync(file, 'utf-8');
            for (const m of text.matchAll(/\b([A-Za-z_]\w*)\?\.data\?\.abilities\?\.(can_\w+)/g)) {
              reads++;
              const [, id, key] = m;
              const sample =
                (!isTemplate ? ext[owner]?.[id] : undefined) ?? tpl[hostTemplate]?.[id] ?? flat[id];
              if (!sample || typeof sample !== 'object') continue;
              const data = sample.data;
              if (!data || typeof data !== 'object' || Array.isArray(data)) continue;
              const abilities = data.abilities;
              if (!abilities || typeof abilities !== 'object' || !(key in abilities)) {
                offenders.add(`${owner}:${id}.data.abilities.${key}`);
              }
            }
          }
        }
      }
    }
    expect(reads).toBeGreaterThan(20);

    // 이커머스(7.1.0 실측 범위 밖) 기존 결손 — 늘지 않게만 묶는다.
    const KNOWN = new Set(['sirsoft-ecommerce:addresses.data.abilities.can_manage']);
    const list = [...offenders];
    expect(list.filter((o) => !KNOWN.has(o)).sort()).toEqual([]);
    expect([...KNOWN].filter((k) => !offenders.has(k))).toEqual([]);
  });
});
