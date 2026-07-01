/**
 * initialStateValueUtils.test.ts — 초기 상태 값 편집 순수 유틸
 *
 * 검증:
 *  ① inferValueKind 각 타입 분류
 *  ② defaultForKind 종류별 기본값
 *  ③ setAtPath/removeAtPath 점·인덱스 경로 불변 set/delete + 중간 경로 생성
 *  ④ classifyKeyOrigin own/merged 출처
 *  ⑤ normalizeLegacyState state→initLocal 이관
 *  ⑥ 표현식 문자열 = 종류=문자(무손실)
 *  ⑦ depth 무제한 중첩 라운드트립
 */

import { describe, it, expect } from 'vitest';
import {
  inferValueKind,
  defaultForKind,
  setAtPath,
  removeAtPath,
  classifyKeyOrigin,
  normalizeLegacyState,
} from '../../spec/initialStateValueUtils';

describe('inferValueKind', () => {
  it('각 타입을 정확히 분류한다', () => {
    expect(inferValueKind('abc')).toBe('string');
    expect(inferValueKind(1)).toBe('number');
    expect(inferValueKind(true)).toBe('boolean');
    expect(inferValueKind(null)).toBe('null');
    expect(inferValueKind(undefined)).toBe('null');
    expect(inferValueKind([])).toBe('list');
    expect(inferValueKind(['a'])).toBe('list');
    expect(inferValueKind({})).toBe('object');
    expect(inferValueKind({ a: 1 })).toBe('object');
  });

  it('표현식 문자열도 종류=문자(무손실)', () => {
    expect(inferValueKind('{{ route.id }}')).toBe('string');
  });
});

describe('defaultForKind', () => {
  it('종류 전환 시 기본값', () => {
    expect(defaultForKind('string')).toBe('');
    expect(defaultForKind('number')).toBe(0);
    expect(defaultForKind('boolean')).toBe(false);
    expect(defaultForKind('null')).toBeNull();
    expect(defaultForKind('list')).toEqual([]);
    expect(defaultForKind('object')).toEqual({});
  });
});

describe('setAtPath / removeAtPath', () => {
  it('점 경로 불변 set', () => {
    const root = { filter: { status: 'active' } };
    const next = setAtPath(root, 'filter.page', 1);
    expect(next).toEqual({ filter: { status: 'active', page: 1 } });
    expect(root).toEqual({ filter: { status: 'active' } }); // 불변.
  });

  it('인덱스 경로 set', () => {
    const root = { items: ['a', 'b'] };
    const next = setAtPath(root, 'items.0', 'z');
    expect((next.items as string[])[0]).toBe('z');
  });

  it('존재 안 하는 중간 경로 생성(다음 세그먼트 숫자면 배열)', () => {
    const next = setAtPath({}, 'a.b.0.c', 5);
    expect(next).toEqual({ a: { b: [{ c: 5 }] } });
  });

  it('점 경로 불변 delete', () => {
    const root = { filter: { status: 'active', page: 1 } };
    const next = removeAtPath(root, 'filter.page');
    expect(next).toEqual({ filter: { status: 'active' } });
    expect(root.filter.page).toBe(1); // 불변.
  });

  it('인덱스 delete 는 splice', () => {
    const root = { items: ['a', 'b', 'c'] };
    const next = removeAtPath(root, 'items.1');
    expect(next.items).toEqual(['a', 'c']);
  });
});

describe('classifyKeyOrigin', () => {
  it('own/merged 비교로 출처 판정', () => {
    expect(classifyKeyOrigin(['a', 'b', 'c'], ['a'], 'a')).toBe('self');
    expect(classifyKeyOrigin(['a', 'b', 'c'], ['a'], 'b')).toBe('inherited');
    // own + merged 둘 다(덮은 경우) → self.
    expect(classifyKeyOrigin(['a'], ['a'], 'a')).toBe('self');
  });
});

describe('normalizeLegacyState', () => {
  it('state 만 보유 → initLocal 이관(migrated)', () => {
    const { initLocal, migrated } = normalizeLegacyState({ state: { keyword: '' } });
    expect(initLocal).toEqual({ keyword: '' });
    expect(migrated).toBe(true);
  });

  it('둘 다 보유 → initLocal 우선 병합', () => {
    const { initLocal, migrated } = normalizeLegacyState({
      state: { keyword: 'a', page: 1 },
      initLocal: { keyword: 'b' },
    });
    expect(initLocal).toEqual({ keyword: 'b', page: 1 }); // initLocal 이 덮음.
    expect(migrated).toBe(true);
  });

  it('initLocal 만/없음 → 이관 안 함', () => {
    expect(normalizeLegacyState({ initLocal: { x: 1 } })).toEqual({ initLocal: { x: 1 }, migrated: false });
    expect(normalizeLegacyState({})).toEqual({ initLocal: {}, migrated: false });
  });
});

describe('중첩 라운드트립', () => {
  it('set → infer → remove 동등성(depth 3)', () => {
    let root: Record<string, unknown> = {};
    root = setAtPath(root, 'form.fields.0.name', '이름');
    expect(inferValueKind((root.form as Record<string, unknown>).fields)).toBe('list');
    root = removeAtPath(root, 'form.fields.0.name');
    expect(root).toEqual({ form: { fields: [{}] } });
  });
});
