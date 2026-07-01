// e2e:allow 조각 CRUD 편집기 단위(RTL) — 조각당 I18nTextField 는 경량 mock(자체 테스트 보유). 추가/
// 삭제/순서변경(드래그)/편집 → 재결합 onChange 를 검증. 라이브는 Chrome MCP 매트릭스.
/**
 * SegmentedValueEditor.test.tsx — 조각 CRUD 편집기 RTL
 *
 * 검증:
 *  ① 초기 분해 — `{{식}} 평문 {{바인딩}}` → 조각 카드 3개(각 손잡이·삭제·I18nTextField)
 *  ② 조각 편집 → 그 조각 value 만 갱신해 전체 재결합 onChange(나머지 보존)
 *  ③ 조각 추가([+고정글자]/[+조건분기]/[+데이터]) → 끝에 새 조각 + 재결합
 *  ④ 조각 삭제(✕) → 그 조각 제거 + 재결합 / 전부 삭제 시 빈 조각 1개 유지
 *  ⑤ 순서 변경(⠿ 드래그) → moveTo 재배치 + 재결합
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';

// 조각당 I18nTextField — 경량 input mock(조각 CRUD/재결합에 집중). value 를 그대로 노출·갱신.
vi.mock('../../../components/property-controls/I18nTextField', () => ({
  I18nTextField: ({
    value,
    onChange,
    testidPrefix,
  }: {
    value: string;
    onChange: (v: string | undefined) => void;
    testidPrefix: string;
  }) => (
    <input
      data-testid={`${testidPrefix}-mock`}
      value={value ?? ''}
      onChange={(e) => onChange(e.target.value)}
    />
  ),
}));

import { SegmentedValueEditor } from '../../../components/page-settings/SegmentedValueEditor';
import type { BindingCandidate } from '../../../spec/bindingCandidates';

const t = (k: string) => k;

// 데이터 조각/조건 자동완성 검증용 scalar 후보 풀(2건).
const CANDS: BindingCandidate[] = [
  { expression: '{{form_meta.data.board.name}}', source: 'data_source', sourceId: 'form_meta', path: 'data.board.name', shape: 'scalar', preview: '공지' },
  { expression: '{{user.name}}', source: 'data_source', sourceId: 'user', path: 'name', shape: 'scalar', preview: '홍길동' },
];

beforeEach(() => cleanup());

/** 드래그 시뮬레이션(HTML5) — 핸들 dragStart → 대상 카드 위/아래 절반 dragOver → drop. */
function dragReorder(fromHandle: HTMLElement, toCard: HTMLElement, half: 'before' | 'after' = 'after'): void {
  fireEvent.dragStart(fromHandle);
  // jsdom getBoundingClientRect 는 0 → before(clientY<0=false 회피 위해 -1)/after(1) 로 절반 지정.
  fireEvent.dragOver(toCard, { clientY: half === 'before' ? -1 : 1 });
  fireEvent.drop(toCard);
}

describe('SegmentedValueEditor — 조각 CRUD', () => {
  const PO_CASE =
    "{{route.id ? '$t:board.edit_post' : '$t:board.new_post'}} - {{form_meta?.data?.board?.name || ''}}";

  it('① 초기 분해 — 조각 카드 3개(보간·평문·보간) + 각 손잡이/삭제/입력칸', () => {
    render(<SegmentedValueEditor value={PO_CASE} onChange={vi.fn()} t={t} />);
    // 카드 3개.
    expect(screen.getByTestId('g7le-seg-value-card-0')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-seg-value-card-1')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-seg-value-card-2')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-seg-value-card-3')).toBeNull();
    // 각 카드: 손잡이 + 삭제 + I18nTextField(mock).
    expect(screen.getByTestId('g7le-seg-value-drag-0')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-seg-value-remove-0')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-seg-value-field-0-mock')).toHaveValue("{{route.id ? '$t:board.edit_post' : '$t:board.new_post'}}");
    expect(screen.getByTestId('g7le-seg-value-field-1-mock')).toHaveValue(' - '); // 평문 조각
    expect(screen.getByTestId('g7le-seg-value-field-2-mock')).toHaveValue("{{form_meta?.data?.board?.name || ''}}");
  });

  it('② 조각 편집 → 그 조각만 갱신해 전체 재결합(나머지 보존)', () => {
    const onChange = vi.fn();
    render(<SegmentedValueEditor value={PO_CASE} onChange={onChange} t={t} />);
    // 평문 조각(" - ")을 " | " 로 변경.
    fireEvent.change(screen.getByTestId('g7le-seg-value-field-1-mock'), { target: { value: ' | ' } });
    expect(onChange).toHaveBeenCalledWith(
      "{{route.id ? '$t:board.edit_post' : '$t:board.new_post'}} | {{form_meta?.data?.board?.name || ''}}",
    );
  });

  it('③ 고정 글자 조각 추가 → 끝에 빈 text 조각 + 재결합', () => {
    const onChange = vi.fn();
    render(<SegmentedValueEditor value="{{route.id ? '$t:a' : '$t:b'}}" onChange={onChange} t={t} />);
    fireEvent.click(screen.getByTestId('g7le-seg-value-add-text'));
    // 카드 2개(기존 보간 + 새 빈 text).
    expect(screen.getByTestId('g7le-seg-value-card-1')).toBeInTheDocument();
    // 빈 text 추가라 결합값 변화 없음(빈 문자열).
    expect(onChange).toHaveBeenCalledWith("{{route.id ? '$t:a' : '$t:b'}}");
  });

  it('③ 조건 분기 조각 추가 → 시드 삼항 식(기준 값은 빈 조건, route.id 하드코딩 금지)', () => {
    const onChange = vi.fn();
    render(<SegmentedValueEditor value="안녕" onChange={onChange} t={t} />);
    fireEvent.click(screen.getByTestId('g7le-seg-value-add-expression'));
    const out = onChange.mock.calls.at(-1)![0] as string;
    expect(out.startsWith('안녕{{')).toBe(true);
    // 빈 조건은 중립 토큰 false 로 시드.
    expect(out).toContain('false ?');
    expect(out).not.toContain('route.id');
  });

  // "유저는 표현식 편집기에서 가능한 모든 조합과 양식을 정의할 수 있어야"(2026-06-13) — 폴백 양식도
  // 사용자가 직접 추가. 시드는 `{{'' ?? ''}}`(기본값/대신 빈 칸).
  it('③ 폴백 조각 추가([+값이 없을 때 대신]) → 시드 `?? ` 폴백 식', () => {
    const onChange = vi.fn();
    render(<SegmentedValueEditor value="안녕" onChange={onChange} t={t} />);
    fireEvent.click(screen.getByTestId('g7le-seg-value-add-fallback'));
    const out = onChange.mock.calls.at(-1)![0] as string;
    expect(out.startsWith('안녕{{')).toBe(true);
    expect(out).toContain('??');
  });

  it('④ 조각 삭제 → 그 조각 제거 + 재결합', () => {
    const onChange = vi.fn();
    render(<SegmentedValueEditor value={PO_CASE} onChange={onChange} t={t} />);
    // 평문 조각(1) 삭제 → 보간 두 개만.
    fireEvent.click(screen.getByTestId('g7le-seg-value-remove-1'));
    expect(onChange).toHaveBeenCalledWith(
      "{{route.id ? '$t:board.edit_post' : '$t:board.new_post'}}{{form_meta?.data?.board?.name || ''}}",
    );
  });

  it('④ 전부 삭제해도 빈 조각 1개 유지(편집 시작점)', () => {
    const onChange = vi.fn();
    render(<SegmentedValueEditor value="안녕" onChange={onChange} t={t} />);
    fireEvent.click(screen.getByTestId('g7le-seg-value-remove-0'));
    expect(onChange).toHaveBeenLastCalledWith('');
    expect(screen.getByTestId('g7le-seg-value-card-0')).toBeInTheDocument();
  });

  it('⑤ 손잡이 드래그 순서 변경 → moveTo 재배치 + 재결합', () => {
    const onChange = vi.fn();
    render(<SegmentedValueEditor value="A{{x.a}}B{{x.b}}" onChange={onChange} t={t} />);
    // 4 조각: "A"(0) "{{x.a}}"(1) "B"(2) "{{x.b}}"(3). 조각0 을 조각2 아래 절반으로 드래그.
    dragReorder(screen.getByTestId('g7le-seg-value-drag-0'), screen.getByTestId('g7le-seg-value-card-2'), 'after');
    // "A" 가 인덱스2 로 이동 → "{{x.a}}" "B" "A" "{{x.b}}".
    expect(onChange).toHaveBeenLastCalledWith('{{x.a}}BA{{x.b}}');
  });

  it('⑤ 드래그 중 드롭 예정 위치에 삽입선 활성 표시(한 곳만, 캔버스 DnD 와 동일)', () => {
    // jsdom 은 getBoundingClientRect=0·드래그 이벤트 좌표 미반영이라 정확한 절반 판정은 라이브
    // (Chrome MCP)로 검증한다([[feedback_chrome_mcp_synthetic_events_false_negative...]]). 여기선
    // "드래그 시작 시 삽입선 메커니즘이 켜지고, 활성은 정확히 한 곳"임을 검증(좌표 무관).
    render(<SegmentedValueEditor value="A{{x.a}}B" onChange={vi.fn()} t={t} />);
    const allLines = () => screen.queryAllByTestId(/g7le-seg-value-dropline-(\d+|end)/);
    // 비드래그 — 삽입선 전부 비활성.
    expect(allLines().filter((l) => l.getAttribute('data-active') === 'true')).toHaveLength(0);
    // 조각0 핸들 dragStart → 조각2 dragOver → 삽입선 정확히 1곳 활성.
    fireEvent.dragStart(screen.getByTestId('g7le-seg-value-drag-0'));
    fireEvent.dragOver(screen.getByTestId('g7le-seg-value-card-2'));
    expect(allLines().filter((l) => l.getAttribute('data-active') === 'true')).toHaveLength(1);
    // dragEnd → 전부 비활성 복귀.
    fireEvent.dragEnd(screen.getByTestId('g7le-seg-value-drag-0'));
    expect(allLines().filter((l) => l.getAttribute('data-active') === 'true')).toHaveLength(0);
  });

  // "원본 식 보기는 조각 편집기당 하나"(2026-06-13) — 통합 토글 1개, 전체 결합 식 표시.
  it('⑥ [원본 식 보기]는 세그먼트 편집기당 1개 — 전체 결합 식 표시', () => {
    render(<SegmentedValueEditor value={PO_CASE} onChange={vi.fn()} t={t} />);
    // 통합 토글 1개(카드별 토글 0 — mock I18nTextField 라 카드 내부 토글은 애초에 없지만 컨테이너 토글 1개 확인).
    const toggles = screen.getAllByTestId('g7le-seg-value-source-toggle');
    expect(toggles).toHaveLength(1);
    // 펼침 → 전체 결합 식(원문) 표시.
    fireEvent.click(toggles[0]);
    expect(screen.getByTestId('g7le-seg-value-source-code')).toHaveTextContent(
      "{{route.id ? '$t:board.edit_post' : '$t:board.new_post'}} - {{form_meta?.data?.board?.name || ''}}",
    );
  });
});

// 데이터 조각 = 데이터 검색 자동완성("데이터를 표출하려는 거라면 당연히 데이터 자동완성 검색이
// 되어야" 2026-06-13). 빈 데이터 조각은 평문 입력이 아니라 데이터 피커를 즉시 노출하고, 후보를 고르면
// 안전 바인딩(`{{src?.path ?? ''}}`)으로 채운다.
describe('SegmentedValueEditor — 데이터 조각 자동완성', () => {
  it('[+데이터] 추가 → 빈 데이터 조각은 데이터 검색 피커 즉시 노출(평문 입력 아님)', () => {
    const onChange = vi.fn();
    render(<SegmentedValueEditor value="" onChange={onChange} t={t} candidates={CANDS} />);
    fireEvent.click(screen.getByTestId('g7le-seg-value-add-data'));
    // 데이터 조각 카드(index 1)에 데이터 피커가 떠 있고(defaultOpen), I18nTextField 평문 입력은 없다.
    expect(screen.getByTestId('g7le-seg-value-field-1-data-picker')).toBeInTheDocument();
    // 검색 입력칸(InlineBindingScalarPicker defaultOpen)이 노출된다.
    expect(
      screen.getByTestId('g7le-inline-binding-search-input-g7le-seg-value-field-1-data'),
    ).toBeInTheDocument();
  });

  it('데이터 후보 선택 → 안전 바인딩(`{{src?.path ?? \'\'}}`)으로 조각 채움', () => {
    const onChange = vi.fn();
    render(<SegmentedValueEditor value="" onChange={onChange} t={t} candidates={CANDS} />);
    fireEvent.click(screen.getByTestId('g7le-seg-value-add-data'));
    // 후보(form_meta.data.board.name) 선택.
    fireEvent.click(screen.getByTestId('g7le-inline-binding-candidate-{{form_meta.data.board.name}}'));
    const out = onChange.mock.calls.at(-1)![0] as string;
    // 안전 바인딩(옵셔널 체이닝 + scalar 폴백)으로 채워진다.
    expect(out).toContain('{{form_meta?.data?.board?.name ??');
  });

  it('후보 풀 미전달 시 데이터 조각은 안내 폴백(피커 없음, 디그레이드)', () => {
    const onChange = vi.fn();
    render(<SegmentedValueEditor value="" onChange={onChange} t={t} />);
    fireEvent.click(screen.getByTestId('g7le-seg-value-add-data'));
    expect(screen.getByTestId('g7le-seg-value-field-1-data-empty')).toBeInTheDocument();
  });
});
