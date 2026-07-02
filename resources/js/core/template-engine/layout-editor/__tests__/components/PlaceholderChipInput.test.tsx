// e2e:allow 레이아웃 편집기 자리표시 칩 합성 입력 위젯 — contentEditable/드래그 의존으로 Playwright 자동화 부적합, Chrome MCP 매트릭스(§공통 검증) + 단위(파싱·렌더)로 검증 (InlineBindingSection.test.tsx L1 과 동일 정책)
/**
 * PlaceholderChipInput.test.tsx — 자리표시 칩 합성 입력 RTL + 파싱 단위
 *
 * 검증:
 *  - parseChipSegments: 자리표시/평문 무손실 분해(위치 보존)
 *  - 렌더: `{pN}` → 원자 칩(contentEditable=false, draggable) + 평문 span(편집 가능)
 *  - paramLabels 친화명 표시
 *  - 평문 0(칩만) 렌더 허용
 *  - insertChipInValue: 커서 위치 자리표시 삽입(편집 로케일)
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, cleanup, fireEvent } from '@testing-library/react';
import {
  PlaceholderChipInput,
  parseChipSegments,
  insertChipInValue,
  buildChipSlots,
  recomposeChipMove,
} from '../../components/property-controls/PlaceholderChipInput';
import type { ChipSlot } from '../../components/property-controls/PlaceholderChipInput';

const t = (k: string) => k;
afterEach(() => cleanup());

describe('buildChipSlots — 칩 양옆·사이·끝 평문 슬롯 보장 (어디서나 타이핑/이동)', () => {
  it('칩만(`{p0}`) → [text(빈), chip, text(빈)] — 칩 앞뒤 커서 슬롯 보장', () => {
    const slots = buildChipSlots('{p0}');
    expect(slots.map((s) => s.kind)).toEqual(['text', 'chip', 'text']);
  });

  it('인접 칩(`{p0}{p1}`) → 칩 사이에도 빈 평문 슬롯', () => {
    const slots = buildChipSlots('{p0}{p1}');
    expect(slots.map((s) => s.kind)).toEqual(['text', 'chip', 'text', 'chip', 'text']);
  });

  it('칩으로 끝남(`안녕 {p0}`) → 끝에 편집 슬롯(칩 뒤 타이핑 가능)', () => {
    const slots = buildChipSlots('안녕 {p0}');
    expect(slots.map((s) => s.kind)).toEqual(['text', 'chip', 'text']);
    expect(slots[0].text).toBe('안녕 ');
    expect(slots[2].text).toBe('');
  });

  it('빈 값 → 편집 슬롯 1개(타이핑 시작점)', () => {
    expect(buildChipSlots('')).toEqual([{ kind: 'text', text: '' }]);
  });

  it('멀티 칩 + 평문 혼합 → 모든 칩이 평문으로 둘러싸임', () => {
    const slots = buildChipSlots('{p0} 작성 {p1} 끝');
    // text, chip, text(' 작성 '), chip, text(' 끝')
    expect(slots.map((s) => s.kind)).toEqual(['text', 'chip', 'text', 'chip', 'text']);
  });
});

describe('parseChipSegments — 칩/평문 무손실 분해', () => {
  it('평문만 → text 1개', () => {
    const segs = parseChipSegments('안녕하세요');
    expect(segs).toEqual([{ kind: 'text', raw: '안녕하세요', start: 0, end: 5 }]);
  });

  it('자리표시만 → chip 1개(param 이름)', () => {
    const segs = parseChipSegments('{p0}');
    expect(segs).toHaveLength(1);
    expect(segs[0]).toMatchObject({ kind: 'chip', paramName: 'p0', raw: '{p0}' });
  });

  it('평문+자리표시 혼합 — 순서/위치 보존, raw 이으면 원문', () => {
    const v = '{p0} 작성 {p1}';
    const segs = parseChipSegments(v);
    expect(segs.map((s) => s.kind)).toEqual(['chip', 'text', 'chip']);
    expect(segs.map((s) => s.raw).join('')).toBe(v);
  });

  it('이중 중괄호 {{pN}} 도 인지', () => {
    const segs = parseChipSegments('{{p0}} 끝');
    expect(segs[0]).toMatchObject({ kind: 'chip', paramName: 'p0' });
  });

  it('빈 문자열 → 빈 배열', () => {
    expect(parseChipSegments('')).toEqual([]);
  });
});

describe('PlaceholderChipInput — 렌더', () => {
  it('자리표시는 원자 칩(contentEditable=false, 포인터 드래그), 평문은 편집 가능', () => {
    render(<PlaceholderChipInput value="{p0} 작성 {p1}" onChange={vi.fn()} t={t} testIdSuffix="ko" />);
    const chip0 = screen.getByTestId('g7le-chip-ko-p0');
    expect(chip0).toHaveAttribute('contenteditable', 'false');
    // 포인터 기반 드래그. grab 커서 + touchAction:none 으로 드래그 어포던스.
    expect(chip0).toHaveStyle({ cursor: 'grab' });
    // 슬롯: [text(""), chip(p0), text(" 작성 "), chip(p1), text("")]. 가운데 평문 = 인덱스 2.
    const text = screen.getByTestId('g7le-chip-text-ko-2');
    expect(text).toHaveAttribute('contenteditable', 'true');
    // 칩 앞(0)·뒤(4) 평문 슬롯도 존재 — 칩 끝 커서/타이핑 보장.
    expect(screen.getByTestId('g7le-chip-text-ko-0')).toHaveAttribute('contenteditable', 'true');
    expect(screen.getByTestId('g7le-chip-text-ko-4')).toHaveAttribute('contenteditable', 'true');
  });

  it('paramLabels 친화명 표시(없으면 param 이름)', () => {
    render(
      <PlaceholderChipInput
        value="{p0}"
        onChange={vi.fn()}
        t={t}
        testIdSuffix="ko"
        paramLabels={{ p0: '회원명' }}
      />,
    );
    expect(screen.getByTestId('g7le-chip-ko-p0')).toHaveTextContent('회원명');
  });

  it('평문 0(칩만) 렌더 허용 — 칩만 표시', () => {
    render(<PlaceholderChipInput value="{p0}" onChange={vi.fn()} t={t} testIdSuffix="ko" />);
    expect(screen.getByTestId('g7le-chip-ko-p0')).toBeInTheDocument();
  });

  it('빈 값 → 편집 가능한 빈 평문 span(타이핑 시작점)', () => {
    render(<PlaceholderChipInput value="" onChange={vi.fn()} t={t} testIdSuffix="ko" />);
    expect(screen.getByTestId('g7le-chip-text-ko-0')).toHaveAttribute('contenteditable', 'true');
  });

  it('onRequestInsert 미전달 시 +데이터 버튼 숨김', () => {
    render(<PlaceholderChipInput value="{p0}" onChange={vi.fn()} t={t} testIdSuffix="ko" />);
    expect(screen.queryByTestId('g7le-chip-insert-ko')).toBeNull();
  });

  it('onRequestInsert 전달 시 +데이터 버튼 노출', () => {
    render(
      <PlaceholderChipInput
        value="{p0}"
        onChange={vi.fn()}
        t={t}
        testIdSuffix="ko"
        onRequestInsert={vi.fn()}
      />,
    );
    expect(screen.getByTestId('g7le-chip-insert-ko')).toBeInTheDocument();
  });
});

describe('칩 우측 X = 데이터 연결 해제', () => {
  it('onRemoveChip 미전달 시 칩 X 미노출 (키 관리 모달 등 node 미보유 컨텍스트)', () => {
    render(<PlaceholderChipInput value="{p0} 작성 {p1}" onChange={vi.fn()} t={t} testIdSuffix="ko" />);
    expect(screen.queryByTestId('g7le-chip-remove-ko-p0')).toBeNull();
    expect(screen.queryByTestId('g7le-chip-remove-ko-p1')).toBeNull();
  });

  it('onRemoveChip 전달 시 칩마다 X 노출', () => {
    render(
      <PlaceholderChipInput value="{p0} 작성 {p1}" onChange={vi.fn()} t={t} testIdSuffix="ko" onRemoveChip={vi.fn()} />,
    );
    expect(screen.getByTestId('g7le-chip-remove-ko-p0')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-chip-remove-ko-p1')).toBeInTheDocument();
  });

  it('disabled 시 칩 X 미노출(onRemoveChip 전달돼도)', () => {
    render(
      <PlaceholderChipInput value="{p0}" onChange={vi.fn()} t={t} testIdSuffix="ko" onRemoveChip={vi.fn()} disabled />,
    );
    expect(screen.queryByTestId('g7le-chip-remove-ko-p0')).toBeNull();
  });

  it('칩 X 클릭 → 해당 param 으로 onRemoveChip 호출', () => {
    const onRemoveChip = vi.fn();
    render(
      <PlaceholderChipInput value="{p0} 작성 {p1}" onChange={vi.fn()} t={t} testIdSuffix="ko" onRemoveChip={onRemoveChip} />,
    );
    fireEvent.click(screen.getByTestId('g7le-chip-remove-ko-p1'));
    expect(onRemoveChip).toHaveBeenCalledWith('p1');
    expect(onRemoveChip).toHaveBeenCalledTimes(1);
  });

  it('칩 X pointerdown 은 칩 드래그 시작과 분리(stopPropagation) — onChange 미발화', () => {
    const onChange = vi.fn();
    render(
      <PlaceholderChipInput value="{p0}" onChange={onChange} t={t} testIdSuffix="ko" onRemoveChip={vi.fn()} />,
    );
    fireEvent.pointerDown(screen.getByTestId('g7le-chip-remove-ko-p0'));
    expect(onChange).not.toHaveBeenCalled();
  });

  // (계측 확정): 칩을 드래그해 놓으면 이동한 칩이 커서 아래(드롭 지점)로 와서, 그 칩의
  // X 가 커서 아래 위치 → pointerup 직후 합성 click 이 X 에 떨어져 onRemoveChip 이 **오발화**(node.text
  // `|pN=` 제거되고 칩 사라짐). 드래그 직후의 X click 1회는 무시돼야 한다.
  it('칩 드래그(pointerdown→move→up) 직후 그 칩 X click → onRemoveChip 미발화(드래그 trailing click 차단)', () => {
    const onRemoveChip = vi.fn();
    render(
      <PlaceholderChipInput value="{p0} 작성 {p1}" onChange={vi.fn()} t={t} testIdSuffix="ko" onRemoveChip={onRemoveChip} />,
    );
    // jsdom 은 document.elementFromPoint 미구현 — null 반환 mock 으로 drop target=null → pointerup
    // early-return(throw 없음). 가드 플래그는 pointermove 에서 켜지므로 검증에 영향 없음.
    const prevEFP = (document as unknown as { elementFromPoint?: unknown }).elementFromPoint;
    (document as unknown as { elementFromPoint?: unknown }).elementFromPoint = () => null;
    const chipP0 = screen.getByTestId('g7le-chip-ko-p0');
    // 칩 본체 드래그(실제 이동) — pointerdown → move(가드 플래그 ON) → up.
    fireEvent.pointerDown(chipP0, { pointerId: 1, clientX: 100, clientY: 10 });
    fireEvent.pointerMove(chipP0, { pointerId: 1, clientX: 40, clientY: 10 });
    fireEvent.pointerUp(chipP0, { pointerId: 1, clientX: 40, clientY: 10 });
    // 드롭 직후 합성 click 이 (커서 아래로 온) 그 칩의 X 에 떨어짐 → 가드가 무시해야 함.
    fireEvent.click(screen.getByTestId('g7le-chip-remove-ko-p0'));
    expect(onRemoveChip).not.toHaveBeenCalled();
    (document as unknown as { elementFromPoint?: unknown }).elementFromPoint = prevEFP;
  });

  it('드래그 없이 X 만 click → onRemoveChip 정상 발화(의도적 해제는 유지)', () => {
    const onRemoveChip = vi.fn();
    render(
      <PlaceholderChipInput value="{p0} 작성 {p1}" onChange={vi.fn()} t={t} testIdSuffix="ko" onRemoveChip={onRemoveChip} />,
    );
    fireEvent.click(screen.getByTestId('g7le-chip-remove-ko-p1'));
    expect(onRemoveChip).toHaveBeenCalledWith('p1');
  });
});

describe('recomposeChipMove — 칩을 글자 한 자 한 자 사이로 이동 (모든 위치 삽입)', () => {
  // 슬롯: [text("abcd"), chip(p0), text("")] — "abcd{p0}". p0 를 "abcd" 안 각 글자 사이로.
  const slots = (text0: string, after = ''): ChipSlot[] => [
    { kind: 'text', text: text0 },
    { kind: 'chip', paramName: 'p0' },
    { kind: 'text', text: after },
  ];

  it('글자 사이 각 위치(0~4)에 칩 삽입 — 모든 offset', () => {
    expect(recomposeChipMove(slots('abcd'), 'p0', 0, 0)).toBe('{p0}abcd');
    expect(recomposeChipMove(slots('abcd'), 'p0', 0, 1)).toBe('a{p0}bcd');
    expect(recomposeChipMove(slots('abcd'), 'p0', 0, 2)).toBe('ab{p0}cd');
    expect(recomposeChipMove(slots('abcd'), 'p0', 0, 3)).toBe('abc{p0}d');
    expect(recomposeChipMove(slots('abcd'), 'p0', 0, 4)).toBe('abcd{p0}');
  });

  it('한글 글자 사이 삽입', () => {
    expect(recomposeChipMove(slots('하나둘'), 'p0', 0, 1)).toBe('하{p0}나둘');
    expect(recomposeChipMove(slots('하나둘'), 'p0', 0, 2)).toBe('하나{p0}둘');
  });

  it('offset clamp(범위 밖) — 끝으로', () => {
    expect(recomposeChipMove(slots('ab'), 'p0', 0, 99)).toBe('ab{p0}');
  });

  it('멀티 칩 — 지정 칩만 이동, 다른 칩 위치 불변', () => {
    // 슬롯: [text("A "), chip(p0), text(" B "), chip(p1), text(" C")] = "A {p0} B {p1} C"
    const multi: ChipSlot[] = [
      { kind: 'text', text: 'A ' }, { kind: 'chip', paramName: 'p0' },
      { kind: 'text', text: ' B ' }, { kind: 'chip', paramName: 'p1' },
      { kind: 'text', text: ' C' },
    ];
    // p1 을 첫 슬롯 "A " 의 0 위치로 이동 → p0 는 그대로.
    expect(recomposeChipMove(multi, 'p1', 0, 0)).toBe('{p1}A {p0} B  C');
    // p0 을 마지막 슬롯 " C" 의 끝으로 이동 → p1 그대로.
    expect(recomposeChipMove(multi, 'p0', 4, 2)).toBe('A  B {p1} C{p0}');
  });

  it('이동 후 재이동 가능(연속) — 결과가 다시 슬롯화되어 또 이동', () => {
    // 1차: "ab{p0}" 에서 p0 를 0 으로 → "{p0}ab"
    const v1 = recomposeChipMove(slots('ab'), 'p0', 0, 0);
    expect(v1).toBe('{p0}ab');
    // 2차: v1 을 슬롯화 후 p0 를 끝으로 → "ab{p0}" (재이동 정상)
    const s2 = buildChipSlots(v1); // [text(""), chip, text("ab")]
    const dropIdx = s2.findIndex((s) => s.kind === 'text' && s.text === 'ab');
    expect(recomposeChipMove(s2, 'p0', dropIdx, 2)).toBe('ab{p0}');
  });
});

describe('insertChipInValue — 커서 위치 자리표시 삽입', () => {
  it('앞/중/끝 삽입', () => {
    expect(insertChipInValue('님 환영', 0, 'p0')).toBe('{p0} 님 환영');
    expect(insertChipInValue('작성함', 2, 'p1')).toBe('작성 {p1} 함');
    expect(insertChipInValue('작성', 2, 'p0')).toBe('작성 {p0}');
  });
});

describe('타이핑 안전성 — 비제어 평문(글자 뒤섞임 결함 회귀)', () => {
  it('평문 타이핑(연속 입력) 시 평문 span 을 React 가 덮어쓰지 않는다(비제어)', () => {
    // 결함 재현 방지: controlled 였다면 onChange→value 갱신→리렌더로 span textContent 가
    // 매 입력마다 재설정되어 커서가 튀고 글자 순서가 깨졌다("하나둘"→"퉁둘낭나한하").
    // 이제 평문 span 은 자식 텍스트를 React 로 렌더하지 않으므로(ref 주입), 구조 동일 타이핑은
    // DOM 을 보존한다 — 그 증거로 span 에 React text child 가 없음을 확인.
    // value "{p0} " → 슬롯 [text(""), chip(p0), text(" ")]. 칩 뒤 평문 슬롯 = 인덱스 2.
    let cur = '{p0} ';
    const onChange = vi.fn((next: string) => { cur = next; });
    const { rerender } = render(
      <PlaceholderChipInput value={cur} onChange={onChange} t={t} testIdSuffix="ko" />,
    );
    // 칩 뒤 평문 슬롯(비제어) — DOM 에 직접 타이핑을 시뮬레이션.
    const span = screen.getByTestId('g7le-chip-text-ko-2');
    span.textContent = ' 하나둘';
    span.dispatchEvent(new Event('input', { bubbles: true }));
    // recompose 가 [빈 텍스트, 칩, " 하나둘"] 을 순서대로 재조립 → "{p0} 하나둘".
    expect(onChange).toHaveBeenCalled();
    expect(onChange.mock.calls.at(-1)![0]).toBe('{p0} 하나둘');
    // 같은 구조 리렌더(타이핑) 시 DOM 평문 보존(스크램블 없음).
    rerender(<PlaceholderChipInput value={'{p0} 하나둘'} onChange={onChange} t={t} testIdSuffix="ko" />);
    expect(screen.getByTestId('g7le-chip-text-ko-2').textContent).toBe(' 하나둘');
  });

  it('칩 구조 변경(삽입) 시에는 평문 textContent 재주입(구조 동기)', () => {
    const onChange = vi.fn();
    const { rerender } = render(
      <PlaceholderChipInput value="안녕" onChange={onChange} t={t} testIdSuffix="ko" />,
    );
    expect(screen.getByTestId('g7le-chip-text-ko-0').textContent).toBe('안녕');
    // 칩이 추가된 새 value(구조 변경) → 평문 재주입 + 칩 등장.
    rerender(<PlaceholderChipInput value="안녕 {p0}" onChange={onChange} t={t} testIdSuffix="ko" />);
    expect(screen.getByTestId('g7le-chip-ko-p0')).toBeTruthy();
    expect(screen.getByTestId('g7le-chip-text-ko-0').textContent).toBe('안녕 ');
  });
});
