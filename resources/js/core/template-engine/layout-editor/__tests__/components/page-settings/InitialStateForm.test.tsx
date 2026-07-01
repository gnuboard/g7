// e2e:allow [초기 상태] 폼 단위(RTL) — 3섹션/재귀편집/상속/정규화 위젯 합성, Chrome MCP 매트릭스(세션 D)로 보강.
/**
 * InitialStateForm.test.tsx — [초기 상태] 탭 폼 RTL
 *
 * 검증:
 *  ① 로컬/전역/격리 3섹션 렌더(라벨)
 *  ② initLocal/state 양쪽 읽어 로컬 합산 + state 정규화 안내
 *  ③ 값 추가(이름+종류) → initLocal 키 +1
 *  ④ 전역 0건 디그레이드
 *  ⑤ 표현식/고급 배지 행 0(advanced testid 부재)
 *  ⑥ 상속 🔗 배지 + 되돌림
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { InitialStateForm } from '../../../components/page-settings/InitialStateForm';

const t = (k: string) => k;

beforeEach(() => cleanup());

describe('InitialStateForm', () => {
  it('3섹션을 렌더한다', () => {
    render(<InitialStateForm raw={{ initLocal: { keyword: '' } }} patch={vi.fn()} t={t} />);
    expect(screen.getByTestId('g7le-initstate-section-local')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-initstate-section-global')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-initstate-section-isolated')).toBeInTheDocument();
  });

  it('initLocal/state 양쪽을 읽어 합산하고 state 정규화 안내를 표시한다', () => {
    render(<InitialStateForm raw={{ initLocal: { a: 1 }, state: { b: 2 } }} patch={vi.fn()} t={t} />);
    expect(screen.getByTestId('g7le-initstate-local-item-a')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-initstate-local-item-b')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-initstate-legacy-state')).toBeInTheDocument();
  });

  it('값 추가 → initLocal 키 +1 패치', () => {
    const patch = vi.fn();
    render(<InitialStateForm raw={{ initLocal: { a: 1 } }} patch={patch} t={t} />);
    fireEvent.change(screen.getByTestId('g7le-initstate-local-add-name'), { target: { value: 'page' } });
    fireEvent.click(screen.getByTestId('g7le-initstate-local-add'));
    expect(patch).toHaveBeenLastCalledWith('initLocal', { a: 1, page: '' });
  });

  it('값 추가 종류 select — number 선택 시 0, list 선택 시 [] 기본값 생성 (W6)', () => {
    const patch = vi.fn();
    const { rerender } = render(<InitialStateForm raw={{ initLocal: {} }} patch={patch} t={t} />);
    // 종류 select 가 존재해야 한다(종전엔 미노출 → 항상 string).
    const kindSel = screen.getByTestId('g7le-initstate-local-add-kind');
    expect(kindSel).toBeInTheDocument();
    // number 선택 → 0.
    fireEvent.change(kindSel, { target: { value: 'number' } });
    fireEvent.change(screen.getByTestId('g7le-initstate-local-add-name'), { target: { value: 'cnt' } });
    fireEvent.click(screen.getByTestId('g7le-initstate-local-add'));
    expect(patch).toHaveBeenLastCalledWith('initLocal', { cnt: 0 });

    // list 선택 → [].
    rerender(<InitialStateForm raw={{ initLocal: {} }} patch={patch} t={t} />);
    fireEvent.change(screen.getByTestId('g7le-initstate-local-add-kind'), { target: { value: 'list' } });
    fireEvent.change(screen.getByTestId('g7le-initstate-local-add-name'), { target: { value: 'items' } });
    fireEvent.click(screen.getByTestId('g7le-initstate-local-add'));
    expect(patch).toHaveBeenLastCalledWith('initLocal', { items: [] });
  });

  it('전역 0건 디그레이드 표시', () => {
    render(<InitialStateForm raw={{ initLocal: { a: 1 } }} patch={vi.fn()} t={t} />);
    expect(screen.getByTestId('g7le-initstate-global-empty')).toBeInTheDocument();
  });

  it('각 값 [</>] 토글 → 그 값의 JSON 코드 미리보기 노출', () => {
    render(<InitialStateForm raw={{ initLocal: { filters: ['a', 'b'], flag: true } }} patch={vi.fn()} t={t} />);
    // 토글 전 코드 블록 없음.
    expect(screen.queryByTestId('g7le-initstate-code-block-filters')).toBeNull();
    fireEvent.click(screen.getByTestId('g7le-initstate-code-filters'));
    const code = screen.getByTestId('g7le-initstate-code-block-filters');
    expect(code).toBeInTheDocument();
    // 목록 값이 JSON 으로 직렬화돼 표시.
    expect(code.textContent).toContain('"a"');
    expect(code.textContent).toContain('"b"');
    // 다른 값은 토글과 독립(값별 상태).
    expect(screen.queryByTestId('g7le-initstate-code-block-flag')).toBeNull();
    fireEvent.click(screen.getByTestId('g7le-initstate-code-flag'));
    expect(screen.getByTestId('g7le-initstate-code-block-flag').textContent).toBe('true');
  });

  it('표현식/고급 배지 행 0 — 손작성 {{}} 도 문자 행', () => {
    render(<InitialStateForm raw={{ initLocal: { weird: '{{ route.id }}' } }} patch={vi.fn()} t={t} />);
    expect(screen.queryByTestId('g7le-initstate-advanced-weird')).not.toBeInTheDocument();
    // 문자 종류로 편집 가능.
    expect((screen.getByTestId('g7le-initstate-type-weird') as HTMLSelectElement).value).toBe('string');
  });

  it('상속 키 🔗 배지 + 되돌림(자기 override 제거)', () => {
    const patch = vi.fn();
    render(
      <InitialStateForm
        raw={{ initLocal: { own: 1, inherited: 2 } }}
        own={{ initLocal: { own: 1 } }}
        patch={patch}
        t={t}
      />,
    );
    // own = 자기, inherited = 부모(own 에 없음).
    expect(screen.getByTestId('g7le-initstate-inherited-inherited')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-initstate-inherited-own')).not.toBeInTheDocument();
    // 되돌림 → 자기 override 제거(키 제거).
    fireEvent.click(screen.getByTestId('g7le-initstate-revert-inherited'));
    expect(patch).toHaveBeenLastCalledWith('initLocal', { own: 1 });
  });

  it('키 중복 이름 거부(추가 안 됨) + 안내 표시', () => {
    const patch = vi.fn();
    render(<InitialStateForm raw={{ initLocal: { a: 1 } }} patch={patch} t={t} />);
    fireEvent.change(screen.getByTestId('g7le-initstate-local-add-name'), { target: { value: 'a' } });
    fireEvent.click(screen.getByTestId('g7le-initstate-local-add'));
    expect(patch).not.toHaveBeenCalled();
    expect(screen.getByTestId('g7le-initstate-local-add-error')).toBeInTheDocument();
  });

  it('이름 빈 칸으로 [값 추가] 클릭 → 무반응 대신 안내 표시 (값 추가 무반응 버그 수정)', () => {
    const patch = vi.fn();
    render(<InitialStateForm raw={{ initLocal: {} }} patch={patch} t={t} />);
    // 종전엔 silent return → 버튼이 죽은 듯 보임. 이제 안내가 떠야 한다.
    fireEvent.click(screen.getByTestId('g7le-initstate-local-add'));
    expect(patch).not.toHaveBeenCalled();
    expect(screen.getByTestId('g7le-initstate-local-add-error')).toBeInTheDocument();
    // 이름 입력 시 안내가 사라진다.
    fireEvent.change(screen.getByTestId('g7le-initstate-local-add-name'), { target: { value: 'x' } });
    expect(screen.queryByTestId('g7le-initstate-local-add-error')).toBeNull();
  });

  it("'코드로' 토글 → JSON 텍스트 칸 노출, 블럭 UI 숨김", () => {
    render(<InitialStateForm raw={{ initLocal: { a: 1 } }} patch={vi.fn()} t={t} />);
    // 기본 블럭 모드 — 코드 칸 없음.
    expect(screen.queryByTestId('g7le-initstate-local-code')).toBeNull();
    fireEvent.click(screen.getByTestId('g7le-initstate-local-mode-toggle'));
    // 코드 모드 — JSON 칸 노출, 블럭의 값 추가 폼 숨김.
    const code = screen.getByTestId('g7le-initstate-local-code') as HTMLTextAreaElement;
    expect(code).toBeInTheDocument();
    expect(code.value).toBe('{\n  "a": 1\n}');
    expect(screen.queryByTestId('g7le-initstate-local-add')).toBeNull();
  });

  it('코드 모드 — 유효 JSON 편집 시 initLocal 패치', () => {
    const patch = vi.fn();
    render(<InitialStateForm raw={{ initLocal: { a: 1 } }} patch={patch} t={t} />);
    fireEvent.click(screen.getByTestId('g7le-initstate-local-mode-toggle'));
    fireEvent.change(screen.getByTestId('g7le-initstate-local-code'), { target: { value: '{"a":1,"b":2}' } });
    expect(patch).toHaveBeenLastCalledWith('initLocal', { a: 1, b: 2 });
  });

  it('코드 모드 — 깨진 JSON 은 오류 표시 + 패치 차단(저장 차단)', () => {
    const patch = vi.fn();
    render(<InitialStateForm raw={{ initLocal: { a: 1 } }} patch={patch} t={t} />);
    fireEvent.click(screen.getByTestId('g7le-initstate-local-mode-toggle'));
    patch.mockClear();
    fireEvent.change(screen.getByTestId('g7le-initstate-local-code'), { target: { value: '{ broken' } });
    expect(screen.getByTestId('g7le-initstate-local-code-error')).toBeInTheDocument();
    expect(patch).not.toHaveBeenCalled();
  });

  // ── 회귀 ──
  it('legacy state 가 __editor.original.state(own.state)로 오면 자기 선언으로 분류(상속 🔗 아님)', () => {
    // 서버 라이브 형태: 병합본은 raw.initLocal, 자기 state 는 own.state(=__editor.original.state).
    render(
      <InitialStateForm
        raw={{ initLocal: { searchQuery: '', showHidden: false } }}
        own={{ state: { searchQuery: '', showHidden: false } }}
        patch={vi.fn()}
        t={t}
      />,
    );
    // 자기 선언 → 🔗 배지 없음, ✕(삭제) 버튼.
    expect(screen.queryByTestId('g7le-initstate-inherited-searchQuery')).toBeNull();
    expect(screen.getByTestId('g7le-initstate-remove-searchQuery')).toBeInTheDocument();
    // legacy 정규화 안내 표시(migrated).
    expect(screen.getByTestId('g7le-initstate-legacy-state')).toBeInTheDocument();
  });

  it('legacy state 자기값 편집 → patch 발생(저장 가능) + state 키 정규화 이관', () => {
    const patch = vi.fn();
    render(
      <InitialStateForm
        raw={{ initLocal: { searchQuery: '' } }}
        own={{ state: { searchQuery: '' } }}
        patch={patch}
        t={t}
      />,
    );
    fireEvent.change(screen.getByTestId('g7le-initstate-value-searchQuery'), { target: { value: 'hello' } });
    // initLocal 패치 + 정규화 이관(state 제거) 둘 다 발생.
    expect(patch).toHaveBeenCalledWith('state', undefined);
    expect(patch).toHaveBeenCalledWith('initLocal', { searchQuery: 'hello' });
  });

  it('값 이름 식별자 검증 — 한글/공백/숫자시작/하이픈 거부 + 안내', () => {
    const patch = vi.fn();
    render(<InitialStateForm raw={{ initLocal: {} }} patch={patch} t={t} />);
    for (const bad of ['검색어', '1page', 'my key', 'a-b']) {
      fireEvent.change(screen.getByTestId('g7le-initstate-local-add-name'), { target: { value: bad } });
      fireEvent.click(screen.getByTestId('g7le-initstate-local-add'));
      expect(screen.getByTestId('g7le-initstate-local-add-error')).toBeInTheDocument();
    }
    expect(patch).not.toHaveBeenCalled();
    // 유효 식별자는 통과.
    fireEvent.change(screen.getByTestId('g7le-initstate-local-add-name'), { target: { value: 'valid_key$1' } });
    fireEvent.click(screen.getByTestId('g7le-initstate-local-add'));
    expect(patch).toHaveBeenLastCalledWith('initLocal', { valid_key$1: '' });
  });

  it('코드 모드 — 한글 키는 무효 처리(저장 차단)', () => {
    const patch = vi.fn();
    render(<InitialStateForm raw={{ initLocal: {} }} patch={patch} t={t} />);
    fireEvent.click(screen.getByTestId('g7le-initstate-local-mode-toggle'));
    patch.mockClear();
    fireEvent.change(screen.getByTestId('g7le-initstate-local-code'), { target: { value: '{"검색어":""}' } });
    expect(screen.getByTestId('g7le-initstate-local-code-error')).toBeInTheDocument();
    expect(patch).not.toHaveBeenCalled();
  });
});
