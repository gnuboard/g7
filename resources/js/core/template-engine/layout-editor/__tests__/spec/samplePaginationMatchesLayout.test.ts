/**
 * samplePaginationMatchesLayout.test.ts — 편집기 표본의 목록 페이지 정보가 레이아웃이 읽는 모양인지 정적 가드
 *
 * 관리자 확장 목록(모듈·플러그인·템플릿)은 응답 `data.pagination.{total,current_page,per_page}` 로 「총 N개 중 a-b개 표시」를
 * 그린다(실 응답 실측: `{"data":[…],"pagination":{"total":3,…}}`). 표본은 같은 값을 `data` 바로 아래 평평하게 담아,
 * 7.1.0 실측(영역 I2)에서 편집기 카드 3장 아래에 「총 0개 중 1-0개 표시」가 보였다.
 *
 * 판정: 번들 레이아웃 문자열이 `{id}?.data?.pagination?.total` 을 읽고 표본 목록(`data.data`)에 항목이 있으면, 같은 번들 묶음(템플릿 분할 표본 + 모듈·플러그인
 * 스펙)의 `sampleData.byDataSourceId[id].data.pagination` 이 객체이고 그 key 를 담는다(표본이 있는 데이터소스만).
 *
 * @since engine-v1.78.3
 */

import { describe, it, expect } from 'vitest';
import { readFileSync, existsSync, readdirSync, statSync } from 'node:fs';
import { join, resolve } from 'node:path';

const ROOT = process.cwd();

type Json = any;

function walkJson(absDir: string, out: string[] = []): string[] {
  if (!existsSync(absDir)) return out;
  for (const entry of readdirSync(absDir)) {
    const abs = join(absDir, entry);
    if (statSync(abs).isDirectory()) walkJson(abs, out);
    else if (entry.endsWith('.json')) out.push(abs);
  }
  return out;
}

/** 표본 데이터소스 — id → 표본(모든 번들 스펙 합집합, 뒤가 덮음). */
function loadSamples(): Map<string, Json> {
  const map = new Map<string, Json>();
  const add = (byId: Record<string, Json> | undefined): void => {
    for (const [id, sample] of Object.entries(byId ?? {})) map.set(id, sample);
  };
  for (const base of ['modules/_bundled', 'plugins/_bundled']) {
    for (const ext of readdirSync(resolve(ROOT, base))) {
      const f = resolve(ROOT, base, ext, 'editor-spec.json');
      if (existsSync(f)) add(JSON.parse(readFileSync(f, 'utf-8'))?.sampleData?.byDataSourceId);
    }
  }
  for (const tpl of readdirSync(resolve(ROOT, 'templates/_bundled'))) {
    const f = resolve(ROOT, 'templates/_bundled', tpl, 'editor-spec', 'sampleData.json');
    if (existsSync(f)) add(JSON.parse(readFileSync(f, 'utf-8'))?.byDataSourceId);
  }
  return map;
}

describe('편집기 표본 — 목록 페이지 정보 모양', () => {
  // @scenario source=bundled
  // @effects sample_pagination_has_layout_shape
  it('레이아웃이 `{id}?.data?.pagination?.total` 로 읽는 데이터소스 표본이 `data.pagination` 객체를 담는다', () => {
    const samples = loadSamples();
    const reads = new Map<string, Set<string>>();
    for (const base of ['templates/_bundled', 'modules/_bundled', 'plugins/_bundled']) {
      for (const ext of readdirSync(resolve(ROOT, base))) {
        for (const dir of ['layouts', 'resources/layouts']) {
          for (const file of walkJson(join(resolve(ROOT, base, ext), dir))) {
            const text = readFileSync(file, 'utf-8');
            // 화면에 숫자로 그려지는 전체 건수(`total`)만 본다 — `has_more_pages` 등은 없으면 「더 보기」를 숨길 뿐이다.
            for (const m of text.matchAll(/\b([A-Za-z_]\w*)\?\.data\?\.pagination\?\.(total)\b/g)) {
              const set = reads.get(m[1]) ?? new Set<string>();
              set.add(m[2]);
              reads.set(m[1], set);
            }
          }
        }
      }
    }
    expect(reads.size).toBeGreaterThan(3);

    const offenders: string[] = [];
    for (const [id, keys] of reads) {
      const sample = samples.get(id);
      if (!sample || typeof sample !== 'object' || !sample.data || typeof sample.data !== 'object' || Array.isArray(sample.data)) continue;
      if (!Array.isArray(sample.data.data) || sample.data.data.length === 0) continue;
      const pagination = sample.data.pagination;
      for (const key of keys) {
        if (!pagination || typeof pagination !== 'object' || !(key in pagination)) offenders.push(`${id}.data.pagination.${key}`);
      }
    }
    // 이커머스(7.1.0 실측 범위 밖) 기존 결손 — 줄기만 한다.
    const KNOWN = new Set(['commonInfos.data.pagination.total']);
    expect(offenders.filter((o) => !KNOWN.has(o)).sort()).toEqual([]);
    expect([...KNOWN].filter((k) => !offenders.includes(k))).toEqual([]);
  });
});
