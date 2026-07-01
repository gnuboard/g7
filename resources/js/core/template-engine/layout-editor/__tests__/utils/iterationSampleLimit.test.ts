// e2e:allow 순수 데이터 변환 유틸 단위 테스트 — DOM/네트워크 영향 없음.
/**
 * iterationSampleLimit — 반복 항목 편집 샘플 1개 제한
 *
 * parseIterationSourcePath / limitIterationSourceToOne 의 경로 파싱 + 배열 1개 제한 검증.
 *
 * @since engine-v1.50.0
 */

import { describe, it, expect } from 'vitest';
import {
  parseIterationSourcePath,
  limitIterationSourceToOne,
} from '../../utils/iterationSampleLimit';

describe('parseIterationSourcePath', () => {
  it('단순 바인딩 경로 추출', () => {
    expect(parseIterationSourcePath('{{recent_posts?.data?.data}}')).toEqual([
      'recent_posts',
      'data',
      'data',
    ]);
    expect(parseIterationSourcePath('{{products.data}}')).toEqual(['products', 'data']);
    expect(parseIterationSourcePath('{{items}}')).toEqual(['items']);
  });

  it('공백 허용', () => {
    expect(parseIterationSourcePath('{{  recent_posts.data  }}')).toEqual(['recent_posts', 'data']);
  });

  it('널리시 병합 폴백(`?? []`)은 앞 경로만 추출', () => {
    // 흔한 패턴 — 우변(빈 폴백)을 버리고 좌변 경로를 취한다.
    expect(parseIterationSourcePath('{{popularPosts.data ?? []}}')).toEqual(['popularPosts', 'data']);
    expect(parseIterationSourcePath('{{items ?? []}}')).toEqual(['items']);
    expect(parseIterationSourcePath("{{a.b?.c ?? ''}}")).toEqual(['a', 'b', 'c']);
  });

  it('바인딩 아님/복합식은 null', () => {
    expect(parseIterationSourcePath('recent_posts.data')).toBeNull(); // {{}} 없음
    expect(parseIterationSourcePath('{{a.b.map(x=>x)}}')).toBeNull(); // 호출식
    expect(parseIterationSourcePath('{{a[0]}}')).toBeNull(); // 인덱싱
    expect(parseIterationSourcePath('')).toBeNull();
  });
});

describe('limitIterationSourceToOne', () => {
  it('source 경로 배열을 첫 1개로 제한 (나머지 컨텍스트 보존)', () => {
    const ctx = {
      recent_posts: { data: { data: [{ id: 1 }, { id: 2 }, { id: 3 }] } },
      other: { keep: true },
    };
    const out = limitIterationSourceToOne(ctx, '{{recent_posts?.data?.data}}');
    expect(out.recent_posts.data.data).toHaveLength(1);
    expect(out.recent_posts.data.data[0]).toEqual({ id: 1 });
    // 원본 불변(immutable)
    expect(ctx.recent_posts.data.data).toHaveLength(3);
    // 다른 키 보존
    expect(out.other).toEqual({ keep: true });
  });

  it('1개 이하 배열은 원본 그대로', () => {
    const ctx = { items: [{ id: 1 }] };
    expect(limitIterationSourceToOne(ctx, '{{items}}')).toBe(ctx);
    const empty = { items: [] };
    expect(limitIterationSourceToOne(empty, '{{items}}')).toBe(empty);
  });

  it('경로 미존재/배열 아님은 원본 그대로', () => {
    const ctx = { a: { b: 'not-array' } };
    expect(limitIterationSourceToOne(ctx, '{{a.b}}')).toBe(ctx);
    expect(limitIterationSourceToOne(ctx, '{{x.y.z}}')).toBe(ctx);
  });

  it('널리시 병합 폴백 source 도 좌변 경로로 제한', () => {
    const ctx = { popularPosts: { data: [{ id: 1 }, { id: 2 }, { id: 3 }] } };
    const out = limitIterationSourceToOne(ctx, '{{popularPosts.data ?? []}}');
    expect(out.popularPosts.data).toHaveLength(1);
    expect(out.popularPosts.data[0]).toEqual({ id: 1 });
  });

  it('파싱 불가 표현식(호출/인덱싱)은 원본 그대로', () => {
    const ctx = { a: [{ id: 1 }, { id: 2 }] };
    expect(limitIterationSourceToOne(ctx, '{{a.map(x=>x)}}')).toBe(ctx);
  });
});
