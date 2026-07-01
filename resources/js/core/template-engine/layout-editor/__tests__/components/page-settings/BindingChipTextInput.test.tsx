// e2e:allow 평문+데이터칩 인라인 칩 편집기 — 순수 파싱·재조립 유틸 단위(RTL 렌더는 contentEditable/포인터
// 드래그 의존이라 jsdom 부적합, 라이브는 Chrome MCP 매트릭스). 컴포넌트 자체 통합은 DataChipValueInput.test.
/**
 * BindingChipTextInput.test.tsx — 평문+데이터칩 인라인 칩 편집기 순수 유틸
 *
 * 검증(칩=원자, 평문=편집, 키화 0):
 *  ① tokenizeBindingSegments — 평문/바인딩/설정참조 무손실 분해(raw 이으면 원문)
 *  ② buildBindingChipSlots — 칩 앞·사이·끝 빈 평문 슬롯 보장(어디서나 타이핑/드롭)
 *  ③ recomposeBindingChipMove — 칩 이동(글자 사이 정밀, 원위치 제거)
 *  ④ insertBindingAt / removeBindingChip — 커서 삽입 / 토큰 제거
 *  ⑤ 렌더 — 칩+평문 슬롯, [+데이터]/[완료] 어포던스, 설정참조 칩 X 미노출
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, cleanup } from '@testing-library/react';

import {
  BindingChipTextInput,
  tokenizeBindingSegments,
  buildBindingChipSlots,
  recomposeBindingChipMove,
  insertBindingAt,
  removeBindingChip,
  type BindingChipSlot,
} from '../../../components/page-settings/BindingChipTextInput';
import type { BindingCandidate } from '../../../spec/bindingCandidates';

const t = (k: string) => k;
const CANDS: BindingCandidate[] = [
  { expression: '{{route.id}}', source: 'route', sourceId: 'route', path: 'id', shape: 'scalar', preview: '1' },
];

beforeEach(() => cleanup());

describe('tokenizeBindingSegments — 평문/바인딩/설정참조 무손실 분해', () => {
  it('평문 + 데이터칩(URL) → 평문 조각 + 바인딩 칩, raw 이으면 원문', () => {
    const v = '/api/modules/sirsoft-ecommerce/products/{{route.id}}';
    const toks = tokenizeBindingSegments(v);
    expect(toks.map((x) => x.kind)).toEqual(['text', 'chip']);
    expect(toks[0].raw).toBe('/api/modules/sirsoft-ecommerce/products/');
    expect(toks[1].raw).toBe('{{route.id}}');
    expect(toks[1].removable).toBe(true); // 바인딩 칩 = 해제 가능.
    expect(toks.map((x) => x.raw).join('')).toBe(v); // 무손실.
  });

  it('평문 사이 칩(`회원 {{user.name}} 님`) → text-chip-text', () => {
    const toks = tokenizeBindingSegments('회원 {{user.name}} 님');
    expect(toks.map((x) => x.kind)).toEqual(['text', 'chip', 'text']);
    expect(toks[2].raw).toBe(' 님');
  });

  it('다중 칩(`{{a.b}}/{{c.d}}`) → chip-text-chip', () => {
    const toks = tokenizeBindingSegments('{{a.b}}/{{c.d}}');
    expect(toks.map((x) => x.kind)).toEqual(['chip', 'text', 'chip']);
  });

  it('설정 참조($core_settings:) → 칩(해제 불가)', () => {
    const toks = tokenizeBindingSegments('접두 $core_settings:site.name 접미');
    expect(toks.map((x) => x.kind)).toEqual(['text', 'chip', 'text']);
    expect(toks[1].removable).toBe(false); // 설정 참조 = 코드 편집 전용(X 없음).
    expect(toks[1].raw).toBe('$core_settings:site.name');
  });

  it('빈 값 → 빈 배열', () => {
    expect(tokenizeBindingSegments('')).toEqual([]);
  });

  it('미닫힘 보간(`{{x`) → 평문으로 흡수(손상 0)', () => {
    const toks = tokenizeBindingSegments('abc{{x');
    expect(toks.map((x) => x.kind)).toEqual(['text']);
    expect(toks[0].raw).toBe('abc{{x');
  });

  it('보간 내부 객체 리터럴(`{{f(x ?? {})}}`) → 한 칩으로 흡수', () => {
    const toks = tokenizeBindingSegments('pre {{f(x ?? {})}} post');
    expect(toks.map((x) => x.kind)).toEqual(['text', 'chip', 'text']);
    expect(toks[1].raw).toBe('{{f(x ?? {})}}');
  });
});

describe('buildBindingChipSlots — 칩 앞·사이·끝 빈 평문 슬롯 보장', () => {
  it('맨 앞 칩 / 칩 사이 / 끝 칩 모두 평문 슬롯이 둘러싼다(text 로 시작·끝)', () => {
    const slots = buildBindingChipSlots('{{a.b}}{{c.d}}');
    expect(slots[0].kind).toBe('text'); // 맨 앞 평문 슬롯.
    expect(slots[slots.length - 1].kind).toBe('text'); // 끝 평문 슬롯.
    // 칩끼리 사이에 평문 슬롯 존재.
    const kinds = slots.map((s) => s.kind).join(',');
    expect(kinds).toBe('text,chip,text,chip,text');
  });

  it('빈 값 → 평문 슬롯 1개(커서 둘 곳)', () => {
    const slots = buildBindingChipSlots('');
    expect(slots).toEqual([{ kind: 'text', text: '' }]);
  });
});

describe('recomposeBindingChipMove — 칩 글자단위 이동', () => {
  it('끝 칩을 앞 평문 글자 사이로 이동', () => {
    // 슬롯: [text "abcd"][chip {{x.y}}][text ""]
    const slots: BindingChipSlot[] = [
      { kind: 'text', text: 'abcd' },
      { kind: 'chip', raw: '{{x.y}}' },
      { kind: 'text', text: '' },
    ];
    // 칩을 슬롯0의 글자 2(ab|cd) 위치로 이동.
    const next = recomposeBindingChipMove(slots, '{{x.y}}', 0, 2);
    expect(next).toBe('ab{{x.y}}cd');
  });

  it('원위치 칩 1개만 제거(중복 raw 여도 첫 일치)', () => {
    const slots: BindingChipSlot[] = [
      { kind: 'text', text: '' },
      { kind: 'chip', raw: '{{x}}' },
      { kind: 'text', text: 'mid' },
      { kind: 'chip', raw: '{{x}}' },
      { kind: 'text', text: '' },
    ];
    // 첫 {{x}} 이동 → 슬롯4(끝) offset 0.
    const next = recomposeBindingChipMove(slots, '{{x}}', 4, 0);
    expect(next).toBe('mid{{x}}{{x}}');
  });
});

describe('insertBindingAt / removeBindingChip — 삽입/제거', () => {
  it('insertBindingAt — 커서 위치에 토큰 삽입', () => {
    expect(insertBindingAt('/api/products/', 14, '{{route.id}}')).toBe('/api/products/{{route.id}}');
    expect(insertBindingAt('ab', 1, '{{x}}')).toBe('a{{x}}b');
  });

  it('insertBindingAt — 경계 클램프', () => {
    expect(insertBindingAt('ab', 99, '{{x}}')).toBe('ab{{x}}');
    expect(insertBindingAt('ab', -5, '{{x}}')).toBe('{{x}}ab');
  });

  it('removeBindingChip — 첫 일치 토큰만 제거', () => {
    expect(removeBindingChip('회원 {{user.name}} 님', '{{user.name}}')).toBe('회원  님');
    expect(removeBindingChip('{{x}}{{x}}', '{{x}}')).toBe('{{x}}');
  });

  it('removeBindingChip — 미존재 토큰이면 원본 유지', () => {
    expect(removeBindingChip('abc', '{{x}}')).toBe('abc');
  });
});

describe('BindingChipTextInput — 렌더', () => {
  it('평문+칩 → 칩 + 평문 슬롯 + [+데이터]/[완료] 어포던스', () => {
    render(<BindingChipTextInput value="회원 {{user.name}}" onChange={vi.fn()} t={t} candidates={CANDS} onDone={vi.fn()} testidPrefix="g7le-bct" />);
    expect(screen.getByTestId('g7le-bct')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-bct-box')).toBeInTheDocument();
    // 데이터 칩(친화 라벨 name) 렌더 — raw `{{}}` 노출 아님.
    expect(screen.getByTestId('g7le-bct-box').textContent).toContain('name');
    expect(screen.getByTestId('g7le-bct-box').textContent).not.toContain('{{user.name}}');
    // [+데이터](후보 있음) / [완료](onDone 있음).
    expect(screen.getByTestId('g7le-bct-insert')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-bct-done')).toBeInTheDocument();
  });

  it('바인딩 칩에는 X(해제) 버튼, 설정참조 칩에는 X 미노출', () => {
    render(<BindingChipTextInput value="{{user.name}} $core_settings:site.name" onChange={vi.fn()} t={t} candidates={CANDS} testidPrefix="g7le-bct" />);
    // 바인딩 칩 X 존재.
    expect(document.querySelector('[data-testid^="g7le-bct-chip-remove-"]')).not.toBeNull();
    // 설정참조 칩(라벨 name)은 X 없음 — 칩은 2개인데 X 버튼은 1개여야 한다.
    const removeBtns = document.querySelectorAll('[data-testid^="g7le-bct-chip-remove-"]');
    expect(removeBtns.length).toBe(1);
  });

  it('후보 없으면 [+데이터] 미노출(삽입 입구 없음)', () => {
    render(<BindingChipTextInput value="회원 {{user.name}}" onChange={vi.fn()} t={t} testidPrefix="g7le-bct" />);
    expect(screen.queryByTestId('g7le-bct-insert')).toBeNull();
  });
});
