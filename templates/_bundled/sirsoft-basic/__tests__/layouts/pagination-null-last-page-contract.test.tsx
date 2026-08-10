/**
 * @file pagination-null-last-page-contract.test.tsx
 * @description 상한 목록의 last_page=null 을 화면이 1 로 붕괴시키지 않는지 전수 검증 (#519)
 *
 * 총 건수를 상한까지만 센 목록은 마지막 페이지를 계산할 수 없어 서버가 `last_page: null`
 * 을 보낸다. 화면이 이를 `?? 1` 로 채우면 "1페이지뿐" 이라고 잘못 말하고, 페이저 표시
 * 조건을 `last_page > 1` 로만 잡으면 페이저가 통째로 사라져 **"다음" 이동 경로 자체가
 * 없어진다.**
 *
 * 특정 화면을 손으로 열거하면 새로 추가되는 목록을 놓치므로, 이 템플릿의 레이아웃 전체를
 * 스캔해 조건으로 도출한다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import * as fs from 'fs';
import * as path from 'path';

const layoutsDir = path.resolve(__dirname, '../../layouts');

/** 레이아웃 JSON 파일 경로를 재귀 수집한다. */
function collectLayoutFiles(dir: string, found: string[] = []): string[] {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) collectLayoutFiles(full, found);
    else if (entry.isFile() && entry.name.endsWith('.json')) found.push(full);
  }
  return found;
}

const layoutFiles = collectLayoutFiles(layoutsDir);

/** 저장소 기준 상대 경로로 바꾼다 (실패 메시지 가독성). */
function rel(file: string): string {
  return path.relative(layoutsDir, file).replace(/\\/g, '/');
}

describe('상한 목록의 last_page=null 계약 (#519)', () => {
  it('레이아웃 파일을 실제로 수집해야 함 (스캔 대상이 0이면 이 테스트는 아무것도 증명하지 못한다)', () => {
    expect(layoutFiles.length).toBeGreaterThan(0);
  });

  it('last_page 를 1 로 채우는 표현식이 없어야 함', () => {
    // `last_page ?? 1` / `last_page || 1` — 계산 불가한 값을 사실처럼 채우는 형태
    const pattern = /last_page\s*(\?\?|\|\|)\s*1(?![0-9])/;
    const offenders: string[] = [];

    for (const file of layoutFiles) {
      const raw = fs.readFileSync(file, 'utf-8');
      raw.split('\n').forEach((line, i) => {
        if (pattern.test(line)) offenders.push(`${rel(file)}:${i + 1}`);
      });
    }

    expect(offenders).toEqual([]);
  });

  it('페이저 표시 조건이 last_page 하나에만 의존하지 않아야 함', () => {
    // `"if": "{{... last_page > 1}}"` 만으로 판정하면 null 일 때 페이저가 사라진다.
    // has_more_pages 또는 current_page 를 함께 보아야 한다.
    const offenders: string[] = [];

    for (const file of layoutFiles) {
      const raw = fs.readFileSync(file, 'utf-8');
      raw.split('\n').forEach((line, i) => {
        if (!/"if"\s*:/.test(line) || !/last_page/.test(line)) return;
        if (/has_more_pages/.test(line) || /current_page/.test(line)) return;
        offenders.push(`${rel(file)}:${i + 1}`);
      });
    }

    expect(offenders).toEqual([]);
  });

  it('totalPages 를 null 로 넘기는 Pagination 은 hasMorePages 를 함께 넘겨야 함', () => {
    // totalPages 가 null 이면 컴포넌트는 총 페이지 수를 모르므로,
    // hasMorePages 없이는 "다음" 버튼을 열 근거가 없어 비활성화된다.
    const offenders: string[] = [];

    /** Pagination 컴포넌트 노드를 재귀 수집한다. */
    function collectPaginations(node: unknown, acc: any[] = []): any[] {
      if (!node || typeof node !== 'object') return acc;
      const obj = node as Record<string, any>;
      if (obj.name === 'Pagination' && obj.props) acc.push(obj);
      for (const value of Object.values(obj)) {
        if (Array.isArray(value)) value.forEach((v) => collectPaginations(v, acc));
        else if (value && typeof value === 'object') collectPaginations(value, acc);
      }
      return acc;
    }

    for (const file of layoutFiles) {
      const raw = fs.readFileSync(file, 'utf-8');
      let parsed: unknown;
      try {
        parsed = JSON.parse(raw);
      } catch {
        continue;
      }

      for (const node of collectPaginations(parsed)) {
        const totalPages = node.props.totalPages;
        if (typeof totalPages !== 'string') continue;
        if (!/\?\?\s*null/.test(totalPages)) continue;
        if (node.props.hasMorePages === undefined) {
          offenders.push(`${rel(file)} — ${totalPages}`);
        }
      }
    }

    expect(offenders).toEqual([]);
  });
});
