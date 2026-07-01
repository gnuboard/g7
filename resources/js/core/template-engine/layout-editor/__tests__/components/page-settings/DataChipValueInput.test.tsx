// e2e:allow 값 전용 칩 입력기 단위(RTL) — 표현식 분해 위임/칩 시각화/키화 0. 라이브는 Chrome MCP 매트릭스.
/**
 * DataChipValueInput.test.tsx — 값 전용 데이터칩 입력기 RTL
 *
 * 검증:
 *  ① 평문(보간 없음) → 평문 input + 칩 이어붙이기 피커(종전 동작 보존)
 *  ② 단일 순수 바인딩(`{{src?.path}}`) → 친화 데이터 칩 + [데이터 바꾸기]
 *  ③ 평문+칩 혼합(`회원 {{x}}`) / `$core_settings:` → 칩+평문 시각화 + [수정]
 *  ④ 표현식(조건/폴백) → SegmentedValueEditor(값 모드) 분해 트리 위임
 *  ⑤ **키화 0** — 어떤 경로에서도 I18nTextField(키화 위젯)가 렌더되지 않음(값 칸은 번역키 미생성)
 *  ⑥ 평문 input 변경 → raw 값 그대로 onChange(키 토큰 미생성)
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';

// I18nTextField = 키화 위젯. 값 칸(DataChipValueInput)은 이 위젯을 **절대** 렌더하면 안 된다(키화 0).
// 마커 mock 으로 두어, DataChipValueInput 경로에서 이 testid 가 나타나면 키화 누수로 실패 처리한다.
// (실제 I18nTextField 는 useLayoutEditor 컨텍스트 의존이라 jsdom 직접 렌더 시 별도 provider 필요 —
//  여기선 "키화 마커"로만 두어 누수 검출에 집중.)
vi.mock('../../../components/property-controls/I18nTextField', () => ({
  I18nTextField: ({ testidPrefix }: { testidPrefix: string }) => (
    <div data-testid={`${testidPrefix}-KEYIFY-LEAK`}>I18nTextField(keyified)</div>
  ),
}));

import { DataChipValueInput } from '../../../components/page-settings/DataChipValueInput';
import type { BindingCandidate } from '../../../spec/bindingCandidates';

const t = (k: string) => k;

const CANDS: BindingCandidate[] = [
  { expression: '{{product.data.name}}', source: 'data_source', sourceId: 'product', path: 'data.name', shape: 'scalar', preview: '베이직 티셔츠' },
  { expression: '{{product.data.price}}', source: 'data_source', sourceId: 'product', path: 'data.price', shape: 'scalar', preview: '23200' },
];

beforeEach(() => cleanup());

/** 트리 어디에도 키화 위젯(I18nTextField 마커)이 없어야 한다(값 칸 키화 0). */
function expectNoKeyificationLeak(): void {
  expect(screen.queryByText('I18nTextField(keyified)')).toBeNull();
  expect(document.querySelector('[data-testid$="-KEYIFY-LEAK"]')).toBeNull();
}

describe('DataChipValueInput — 값 모드(키화 0)', () => {
  it('① 평문(보간 없음) → 평문 input + 키화 0', () => {
    render(<DataChipValueInput value="https://example.com/og.png" onChange={vi.fn()} t={t} candidates={CANDS} />);
    const input = screen.getByTestId('g7le-data-chip-value-input') as HTMLInputElement;
    expect(input).toBeInTheDocument();
    expect(input.value).toBe('https://example.com/og.png');
    expectNoKeyificationLeak();
  });

  it('⑥ 평문 input 변경 → raw 값 그대로 onChange(키 토큰 미생성)', () => {
    const onChange = vi.fn();
    render(<DataChipValueInput value="" onChange={onChange} t={t} candidates={CANDS} />);
    fireEvent.change(screen.getByTestId('g7le-data-chip-value-input'), { target: { value: 'KRW' } });
    expect(onChange).toHaveBeenLastCalledWith('KRW');
    // `$t:custom.*` 토큰이 절대 흐르지 않는다.
    for (const call of onChange.mock.calls) {
      expect(String(call[0])).not.toMatch(/\$t:custom\./);
    }
  });

  // 회귀 — 빈 칸에서 `{{...}}` 를 타이핑하면 `{{` 가 들어가는 순간 칩/혼합
  // 분기로 전환돼 input 이 언마운트되어 나머지 입력이 유실됐다(라이브 실측: `{{` 만 저장됨). 포커스
  // 중에는 평문 input 을 유지하고 blur 시에만 칩으로 전환해야 한다.
  it('⑦ [회귀] 포커스 중 `{{...}}` 타이핑 → input 유지(유실 0), blur 후 칩 전환', () => {
    function Harness(): React.ReactElement {
      const [v, setV] = React.useState('');
      return <DataChipValueInput value={v} onChange={setV} t={t} candidates={CANDS} />;
    }
    render(<Harness />);
    const input = screen.getByTestId('g7le-data-chip-value-input') as HTMLInputElement;
    fireEvent.focus(input);
    // 한 글자씩 누적 입력(controlled — 부모 state 갱신). `{{` 직후에도 input 이 살아 있어야 한다.
    const target = '{{users.data.thumbnail}}';
    let cur = '';
    for (const ch of target) {
      cur += ch;
      const live = screen.getByTestId('g7le-data-chip-value-input') as HTMLInputElement; // 사라지면 throw
      fireEvent.change(live, { target: { value: cur } });
    }
    // 포커스 중 — 여전히 평문 input, 전체 값 보존(유실 0).
    const after = screen.getByTestId('g7le-data-chip-value-input') as HTMLInputElement;
    expect(after.value).toBe('{{users.data.thumbnail}}');
    // blur → 칩 전환(단일 순수 바인딩 → 칩 + [데이터 바꾸기]).
    fireEvent.blur(after);
    expect(screen.queryByTestId('g7le-data-chip-value-input')).toBeNull();
    expect(screen.getByTestId('g7le-data-chip-value-chip').textContent).toContain('thumbnail');
    expectNoKeyificationLeak();
  });

  it('② 단일 순수 바인딩 → 친화 데이터 칩 + [데이터 바꾸기], 키화 0', () => {
    render(<DataChipValueInput value="{{product.data.name}}" onChange={vi.fn()} t={t} candidates={CANDS} />);
    const chip = screen.getByTestId('g7le-data-chip-value-chip');
    expect(chip.textContent).toContain('data.name'); // bindingChipLabel = 경로
    expect(screen.getByTestId('g7le-data-chip-value-change')).toBeInTheDocument();
    // 평문 input(raw 코드)은 칩 모드에선 안 뜬다.
    expect(screen.queryByTestId('g7le-data-chip-value-input')).toBeNull();
    expectNoKeyificationLeak();
  });

  it('② [데이터 바꾸기] → 폴백 없는 순수 바인딩으로 교체', () => {
    const onChange = vi.fn();
    render(<DataChipValueInput value="{{product.data.name}}" onChange={onChange} t={t} candidates={CANDS} />);
    fireEvent.click(screen.getByTestId('g7le-data-chip-value-change'));
    // 후보 피커에서 price 선택(testid 격리) — 폴백(`?? ''`) 없는 순수 바인딩.
    fireEvent.click(screen.getByTestId('g7le-inline-binding-candidate-{{product.data.price}}'));
    const last = onChange.mock.calls.at(-1)?.[0];
    expect(last).toBe('{{product?.data?.price}}');
    expect(last).not.toContain('??');
  });

  it('③ 평문+칩 혼합 → 칩+평문 시각화 + [수정], 키화 0', () => {
    render(<DataChipValueInput value="회원 {{user.name}}" onChange={vi.fn()} t={t} candidates={CANDS} />);
    const chips = screen.getByTestId('g7le-data-chip-value-chips');
    expect(chips.textContent).toContain('회원'); // 평문 조각 보존
    expect(chips.textContent).toContain('🔗'); // 데이터 칩 시각화
    expect(chips.textContent).toContain('name'); // bindingChipLabel = 친화 경로(path)
    expect(screen.getByTestId('g7le-data-chip-value-edit-raw')).toBeInTheDocument();
    expectNoKeyificationLeak();
  });

  it('③ $core_settings: 설정 참조 → 칩 시각화, 키화 0', () => {
    render(<DataChipValueInput value="$core_settings:site.name" onChange={vi.fn()} t={t} candidates={CANDS} />);
    const chips = screen.getByTestId('g7le-data-chip-value-chips');
    // 마지막 경로 세그먼트가 친화 라벨.
    expect(chips.textContent).toContain('name');
    expectNoKeyificationLeak();
  });

  // [✎ 수정]은 어떤 칩 값(단일바인딩/설정참조/폴백)에서도 raw 코드 input 이 아니라
  // 인라인 칩 편집기(BindingChipTextInput)로 가야 한다(`{{_seo.x ?? ''}}`·`$core_settings:`
  // 가 수정 모드에서 raw 노출). 칩 편집기는 [✓ 완료]/칩 X 로 복귀·해제.
  it('② 단일 바인딩 [✎ 수정] → 칩 편집기(raw 코드 input 아님)', () => {
    render(<DataChipValueInput value="{{product.data.name}}" onChange={vi.fn()} t={t} candidates={CANDS} />);
    fireEvent.click(screen.getByTestId('g7le-data-chip-value-edit-raw'));
    // 칩 편집기 진입 — raw 평문 input(-input) 이 아니라 chipedit.
    expect(screen.getByTestId('g7le-data-chip-value-chipedit')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-data-chip-value-input')).toBeNull();
  });

  it('폴백 단일 바인딩(`{{x ?? \'\'}}`) [✎ 수정] → 칩 편집기(raw 노출 0)', () => {
    render(<DataChipValueInput value={"{{_seo.shop_index.title ?? ''}}"} onChange={vi.fn()} t={t} candidates={CANDS} />);
    // 칩/[데이터 바꾸기] 또는 [✎] 진입점이 있고, 진입하면 칩 편집기.
    const editBtn = screen.getByTestId('g7le-data-chip-value-edit-raw');
    fireEvent.click(editBtn);
    expect(screen.getByTestId('g7le-data-chip-value-chipedit')).toBeInTheDocument();
    // raw 평문 input 에 `{{` 코드가 그대로 노출되지 않음.
    expect(screen.queryByTestId('g7le-data-chip-value-input')).toBeNull();
  });

  it('$core_settings: [✎ 수정] → 칩 편집기(raw `$core_settings:` 노출 0)', () => {
    render(<DataChipValueInput value="$core_settings:general.site_name" onChange={vi.fn()} t={t} candidates={CANDS} />);
    fireEvent.click(screen.getByTestId('g7le-data-chip-value-edit-raw'));
    expect(screen.getByTestId('g7le-data-chip-value-chipedit')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-data-chip-value-input')).toBeNull();
  });

  // 평문+데이터칩 값의 [✎ 수정]은 raw 평문 input(칩이 `{{}}` 코드로
  // 노출)이 아니라 **인라인 칩 편집기**(BindingChipTextInput)여야 한다. 수정 중에도 데이터 부분은 칩으로
  // 유지되고, 평문만 타이핑·칩 추가(+데이터)/삭제(X)/위치 이동이 가능해야 한다(다국어 칩 편집과 동일 경험).
  it('③ [수정] 클릭 → 인라인 칩 편집기 노출(평문 input 아님, 칩 유지)', () => {
    render(<DataChipValueInput value="회원 {{user.name}}" onChange={vi.fn()} t={t} candidates={CANDS} />);
    fireEvent.click(screen.getByTestId('g7le-data-chip-value-edit-raw'));
    // 인라인 칩 편집기가 떠야 한다(raw 평문 input 아님).
    expect(screen.getByTestId('g7le-data-chip-value-chipedit')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-data-chip-value-input')).toBeNull();
    // 데이터 부분은 칩으로 유지(raw `{{}}` 노출 아님).
    const box = screen.getByTestId('g7le-data-chip-value-chipedit-box');
    expect(box.textContent).toContain('name');
    expect(box.textContent).not.toContain('{{user.name}}');
  });

  it('③ 인라인 칩 편집기 [완료] → 칩 시각화로 복귀', () => {
    function Harness(): React.ReactElement {
      const [v, setV] = React.useState('회원 {{user.name}}');
      return <DataChipValueInput value={v} onChange={setV} t={t} candidates={CANDS} />;
    }
    render(<Harness />);
    fireEvent.click(screen.getByTestId('g7le-data-chip-value-edit-raw'));
    const done = screen.getByTestId('g7le-data-chip-value-chipedit-done');
    fireEvent.click(done);
    // 칩 시각화(읽기) 복귀 — 칩+[수정] 노출, 칩 편집기 사라짐.
    expect(screen.queryByTestId('g7le-data-chip-value-chipedit')).toBeNull();
    expect(screen.getByTestId('g7le-data-chip-value-edit-raw')).toBeInTheDocument();
  });

  it('③ 인라인 칩 편집기 칩 X → 그 바인딩만 값에서 제거', () => {
    const onChange = vi.fn();
    render(<DataChipValueInput value="회원 {{user.name}}" onChange={onChange} t={t} candidates={CANDS} />);
    fireEvent.click(screen.getByTestId('g7le-data-chip-value-edit-raw'));
    // 첫 바인딩 칩의 X 클릭 — 그 `{{user.name}}` 토큰만 제거.
    const removeBtn = document.querySelector('[data-testid^="g7le-data-chip-value-chipedit-chip-remove-"]') as HTMLElement;
    expect(removeBtn).not.toBeNull();
    fireEvent.click(removeBtn);
    const last = onChange.mock.calls.at(-1)?.[0];
    expect(last).not.toContain('{{user.name}}');
    expect(last).toContain('회원');
  });

  // (구) [✎ 수정]→평문 input→[완료] 테스트는 인라인 칩 편집기 도입으로 대체됨 —
  //   위 '③ [수정] 클릭 → 인라인 칩 편집기' / '③ 인라인 칩 편집기 [완료] → 칩 복귀' 가 후속 SSoT.
  //   ✎ 수정은 더 이상 칩 포함 값에 raw 평문 input 을 띄우지 않는다(칩 편집기로 직행).

  it('③ 순수 평문(칩화 불가) 편집 중에는 [완료] 버튼 미노출', () => {
    render(<DataChipValueInput value="그냥 평문" onChange={vi.fn()} t={t} candidates={CANDS} />);
    // 평문은 칩화 불가 → editing 이어도 [완료] 없음(평문은 그대로 input).
    expect(screen.queryByTestId('g7le-data-chip-value-done')).toBeNull();
  });

  it('④ 단일 조건식 → 분해 트리(ConditionalValueEditor) 위임, 키화 0', () => {
    render(
      <DataChipValueInput
        value="{{product.data.in_stock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock'}}"
        onChange={vi.fn()}
        t={t}
        candidates={CANDS}
      />,
    );
    // 단일 분해식 → 조건 분기 트리(무한 재귀 회피 — SegmentedValueEditor 가 아니라 ConditionalValueEditor).
    expect(screen.getByTestId('g7le-data-chip-value-tree')).toBeInTheDocument();
    // 조건 빌더가 떠야 한다(분해 성공).
    expect(document.querySelector('[data-testid$="-cond"]')).not.toBeNull();
    // 트리 안 어떤 리프도 키화 위젯이 아니다(값 모드 리프 = DataChipValueInput 재귀).
    expectNoKeyificationLeak();
  });

  // 값 칸(엔드포인트 등)을 표현식으로 바꾼 뒤 다시 문자열+데이터칩(일반
  // 이름)으로 복귀할 방법이 없었다. 페이지 이름(I18nTextField)에는 [↩ 일반 이름으로] 가 있는데 값 모드엔
  // 없던 결함. 값 모드 표현식 분해 트리에도 [↩ 일반 이름으로] + 확인 대화가 있어야 한다(페이지이름 패리티).
  it('④ [회귀] 값 모드 표현식 → [↩ 일반 이름으로] 버튼 노출 + 복귀', () => {
    function Harness(): React.ReactElement {
      const [v, setV] = React.useState("{{route.id ? '/api/a' : '/api/b'}}");
      return <DataChipValueInput value={v} onChange={setV} t={t} candidates={CANDS} />;
    }
    render(<Harness />);
    // 표현식 분해 트리 + [↩ 일반 이름으로] 버튼.
    const revert = screen.getByTestId('g7le-data-chip-value-tree-to-plain');
    expect(revert).toBeInTheDocument();
    fireEvent.click(revert);
    // 확인 대화 노출.
    expect(screen.getByTestId('g7le-data-chip-value-tree-to-plain-confirm')).toBeInTheDocument();
    // 확인 → 첫 결과(then '/api/a')로 환원, 표현식 트리 사라짐.
    fireEvent.click(screen.getByTestId('g7le-data-chip-value-tree-to-plain-confirm-ok'));
    expect(screen.queryByTestId('g7le-data-chip-value-tree')).toBeNull();
  });

  it('④ [회귀] 다중 세그먼트 값 모드에도 [↩ 일반 이름으로] 노출', () => {
    render(
      <DataChipValueInput
        value="{{product.data.in_stock ? 'A' : 'B'}} / {{product.data.name}}"
        onChange={vi.fn()}
        t={t}
        candidates={CANDS}
      />,
    );
    expect(screen.getByTestId('g7le-data-chip-value-seg-to-plain')).toBeInTheDocument();
  });

  it('④ 다중 세그먼트 → 조각 편집기(SegmentedValueEditor) 위임, 키화 0', () => {
    render(
      <DataChipValueInput
        value="{{product.data.in_stock ? 'A' : 'B'}} / {{product.data.name}}"
        onChange={vi.fn()}
        t={t}
        candidates={CANDS}
      />,
    );
    // 다중 세그먼트(`{{식}} 평문 {{바인딩}}`) → 조각 편집기.
    expect(screen.getByTestId('g7le-data-chip-value-seg')).toBeInTheDocument();
    expectNoKeyificationLeak();
  });

  it('④ 폴백 표현식 → 분해 트리 위임, 키화 0', () => {
    render(<DataChipValueInput value="{{product.data.name ?? '상품'}}" onChange={vi.fn()} t={t} candidates={CANDS} />);
    // `{{path ?? '리터럴'}}` 은 D 변형(단일 바인딩+폴백) — 칩 또는 평문으로 환원될 수 있으나
    // 어느 분기든 키화는 0 이어야 한다.
    expectNoKeyificationLeak();
  });

  it('④ 표현식 트리 편집 → round-trip 무손상(onChange 가 유효 식 흘림)', () => {
    const onChange = vi.fn();
    render(
      <DataChipValueInput
        value="{{product.data.in_stock ? 'A' : 'B'}}"
        onChange={onChange}
        t={t}
        candidates={CANDS}
      />,
    );
    // 단일 분해식 → 조건 분기 트리 마운트(편집 상호작용은 ConditionalValueEditor 자체 테스트가
    // 커버 — 여기선 위임 + 키화 0 에 집중).
    expect(screen.getByTestId('g7le-data-chip-value-tree')).toBeInTheDocument();
    expectNoKeyificationLeak();
  });

  it('readOnly → 평문 input 읽기전용 + 피커 숨김', () => {
    render(<DataChipValueInput value="{{x.y}}" onChange={vi.fn()} t={t} candidates={CANDS} readOnly />);
    const input = screen.getByTestId('g7le-data-chip-value-input') as HTMLInputElement;
    expect(input).toHaveAttribute('readonly');
    expectNoKeyificationLeak();
  });

  it('enableExpressionTree=false → 종전 동작(평문 input + 이어붙이기, 분해 0)', () => {
    render(<DataChipValueInput value="{{product.data.name}}" onChange={vi.fn()} t={t} candidates={CANDS} enableExpressionTree={false} />);
    // 칩/세그먼트 없이 평문 input 만(디그레이드).
    expect(screen.getByTestId('g7le-data-chip-value-input')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-data-chip-value-seg')).toBeNull();
    expect(screen.queryByTestId('g7le-data-chip-value-chip')).toBeNull();
  });

  // (이슈3)이미 복잡한 식(친화 트리 분해 불가, shape=raw)은 [ƒx 표현식으로]가
  // 의미 없어(이미 식) 숨기되, "막힌 것" 오해를 막도록 안내문(+코드 편집 위임) 노출. 설계상 의도.
  it('(이슈3) raw 복잡식 → ƒx/?? 숨김 + "이미 식" 안내문 노출(코드 편집 위임)', () => {
    // filter/slice 가 든 분해 불가 복잡식 = shape:raw.
    const raw = '{{[Number(route.id), ...(a ?? [])].filter(id => id !== Number(route.id)).slice(0, 20)}}';
    render(<DataChipValueInput value={raw} onChange={vi.fn()} t={t} candidates={CANDS} />);
    // raw 식은 칩 시각화([✎ 수정]) — 클릭해 칩 편집기 진입.
    fireEvent.click(screen.getByTestId('g7le-data-chip-value-edit-raw'));
    // 안내문 노출 + ƒx/?? 승격 버튼 부재(이미 식이라 의미 없음).
    expect(screen.getByTestId('g7le-data-chip-value-raw-hint')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-data-chip-value-to-expr')).toBeNull();
    expect(screen.queryByTestId('g7le-data-chip-value-to-fallback')).toBeNull();
  });
});
