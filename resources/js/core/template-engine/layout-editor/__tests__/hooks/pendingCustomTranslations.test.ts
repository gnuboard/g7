/**
 * pendingCustomTranslations.test.ts — 저장-지연 버퍼
 *
 * 칩 키 값 변경을 즉시 PUT 하지 않고 버퍼에 모았다가 레이아웃 저장 시 flush 하는 모듈.
 * set/get/has/clear + flush(서버 값 위 보류 로케일 덮어쓰기 + 성공 키 버퍼 제거).
 */

import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import {
  setPendingValue,
  setPendingValues,
  getPendingValue,
  hasPending,
  clearPending,
  flushPending,
  reseedPendingIntoEngine,
} from '../../hooks/pendingCustomTranslations';

beforeEach(() => { localStorage.setItem('auth_token', 'tok'); clearPending(); });
afterEach(() => { vi.restoreAllMocks(); clearPending(); });

describe('setPendingValue / getPendingValue / hasPending / clearPending', () => {
  it('값 기록·조회·보유 여부', () => {
    expect(hasPending()).toBe(false);
    setPendingValue('tpl', 'custom.a', 'ko', '값1');
    expect(getPendingValue('custom.a', 'ko')).toBe('값1');
    expect(getPendingValue('custom.a', 'en')).toBeUndefined();
    expect(hasPending()).toBe(true);
    clearPending();
    expect(hasPending()).toBe(false);
    expect(getPendingValue('custom.a', 'ko')).toBeUndefined();
  });

  it('setPendingValues — 여러 로케일 일괄', () => {
    setPendingValues('tpl', 'custom.b', { ko: 'K', en: 'E' });
    expect(getPendingValue('custom.b', 'ko')).toBe('K');
    expect(getPendingValue('custom.b', 'en')).toBe('E');
  });
});

// 결함 F — 콘텐츠 언어 전환 시 새 TranslationEngine + loadTranslations 가 pending seed 를
// 서버 스냅샷으로 덮어쓰던 결함. 사전 재로드 직후 reseedPendingIntoEngine 으로 전 키·전 로케일
// 버퍼를 다시 주입해 저장 전에도 전 로케일에서 새 키 값이 반영되게 한다.
describe('reseedPendingIntoEngine — 언어 전환 후 사전 재주입', () => {
  it('버퍼의 전 키·전 로케일 값을 엔진에 setTranslationValue 로 재주입', () => {
    setPendingValues('tpl', 'custom.x', { ko: '발{p0}행', en: 'Pub {p0}' });
    setPendingValue('tpl', 'custom.y', 'ja', '日{p0}');
    const calls: Array<[string, string, string, string]> = [];
    const engine = {
      setTranslationValue: (t: string, l: string, k: string, v: string) => { calls.push([t, l, k, v]); },
    };
    reseedPendingIntoEngine(engine, 'tpl');
    expect(calls).toContainEqual(['tpl', 'ko', 'custom.x', '발{p0}행']);
    expect(calls).toContainEqual(['tpl', 'en', 'custom.x', 'Pub {p0}']);
    expect(calls).toContainEqual(['tpl', 'ja', 'custom.y', '日{p0}']);
    expect(calls).toHaveLength(3);
  });

  it('빈 버퍼면 아무 호출 없음', () => {
    const calls: unknown[] = [];
    reseedPendingIntoEngine({ setTranslationValue: () => { calls.push(1); } }, 'tpl');
    expect(calls).toHaveLength(0);
  });

  it('엔진 setTranslationValue 가 throw 해도 전파 안 함(다음 키 계속)', () => {
    setPendingValue('tpl', 'custom.a', 'ko', 'A');
    setPendingValue('tpl', 'custom.b', 'ko', 'B');
    let count = 0;
    const engine = {
      setTranslationValue: () => { count += 1; if (count === 1) throw new Error('boom'); },
    };
    expect(() => reseedPendingIntoEngine(engine, 'tpl')).not.toThrow();
    expect(count).toBe(2);
  });
});

describe('flushPending — 레이아웃 저장 시 PUT', () => {
  function stub(values: Record<string, string>) {
    const calls: Array<{ url: string; method: string; body: any }> = [];
    vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
      const method = init?.method ?? 'GET';
      const body = init?.body ? JSON.parse(init.body as string) : null;
      calls.push({ url, method, body });
      if (url.includes('/custom-translations') && method === 'GET') {
        return { ok: true, status: 200, json: async () => ({ data: [{ id: 9, translation_key: 'custom.b', values, lock_version: 1 }] }) } as Response;
      }
      if (url.includes('/custom-translations/') && method === 'PUT') {
        return { ok: true, status: 200, json: async () => ({ data: { id: 9, lock_version: 2 } }) } as Response;
      }
      return { ok: true, status: 200, json: async () => ({ data: { cache_version: 1 } }) } as Response;
    }));
    return { calls };
  }

  it('보류 로케일을 서버 값 위에 덮어 PUT + 버퍼 비움', async () => {
    const { calls } = stub({ ko: '구ko', en: '구en' });
    setPendingValue('tpl', 'custom.b', 'ko', '새ko');
    const r = await flushPending('tpl');
    expect(r.ok).toBe(1);
    const put = calls.find((c) => c.method === 'PUT')!;
    expect(put.body.values.ko).toBe('새ko'); // 보류 반영
    expect(put.body.values.en).toBe('구en'); // 미편집 보존
    expect(hasPending()).toBe(false);
  });

  it('키 행 없음(삭제됨) → failed 에 기록, 버퍼 유지', async () => {
    vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
      const method = init?.method ?? 'GET';
      if (url.includes('/custom-translations') && method === 'GET') {
        return { ok: true, status: 200, json: async () => ({ data: [] }) } as Response; // 행 없음
      }
      return { ok: true, status: 200, json: async () => ({}) } as Response;
    }));
    setPendingValue('tpl', 'custom.missing', 'ko', 'x');
    const r = await flushPending('tpl');
    expect(r.failed).toContain('custom.missing');
    expect(hasPending()).toBe(true); // 실패 키는 다음 저장 재시도 위해 유지
  });
});
