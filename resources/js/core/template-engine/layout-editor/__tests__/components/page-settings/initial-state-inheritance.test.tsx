// e2e:allow [초기 상태] 상속 매트릭스 단위(RTL) — I1~I15 전수, Chrome MCP 매트릭스(세션 D)로 보강.
/**
 * initial-state-inheritance.test.tsx — [초기 상태] 부모/자식 상속 매트릭스 RTL
 *
 *  매트릭스 I1~I15 의 `시작상태 × 조작 × 검증` cross product 를 전수 검증한다.
 * 정책(computed 와 같은 부류, init_actions 와 정반대): initLocal/initGlobal 상속 = shallow merge,
 * 상속 키 **편집 가능(편집=덮어쓰기)**, 🔗상속 배지 + 되돌림(자기 override 제거) + legacy state 정규화.
 *
 * 각 it() = 매트릭스 1 행(I1~I15). 케이스 ID 를 라벨에 명시(누락 0 기준).
 * 출처는 `raw`(병합본) vs `own`(자기 선언분 `__editor.original`) 비교로 도출 — own∌key∧merged∋key
 * → 상속(🔗). 부모 상속 키도 폼에 병합본으로 들어오고 own 에 없으면 상속 분류.
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { InitialStateForm } from '../../../components/page-settings/InitialStateForm';

const t = (k: string) => k;

beforeEach(() => cleanup());

describe('initial-state-inheritance — 부모/자식 상속 매트릭스 (I1~I15)', () => {
  it('I1: 부모만 initLocal(자식 0) → 부모 키 전부 🔗상속 배지, 전부 편집 가능(🔒 아님)', () => {
    // 병합본엔 부모 키 있고 own(자기 선언)엔 없음 → 상속(🔗).
    render(
      <InitialStateForm
        raw={{ initLocal: { keyword: '', page: 1 } }}
        own={{ initLocal: {} }}
        patch={vi.fn()}
        t={t}
      />,
    );
    expect(screen.getByTestId('g7le-initstate-inherited-keyword')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-initstate-inherited-page')).toBeInTheDocument();
    // 상속 키는 편집 가능(값 위젯 + 되돌림 버튼) — 🔒 잠금 아님.
    expect(screen.getByTestId('g7le-initstate-revert-keyword')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-initstate-type-keyword')).toBeInTheDocument();
  });

  it('I2: 자식만 initLocal(부모 0) → 자식 키만, 무배지, [✕] 삭제 가능', () => {
    render(
      <InitialStateForm
        raw={{ initLocal: { mine: 'x' } }}
        own={{ initLocal: { mine: 'x' } }}
        patch={vi.fn()}
        t={t}
      />,
    );
    // own 에 있는 자기 키 → 🔗 배지 부재, [✕] 삭제.
    expect(screen.queryByTestId('g7le-initstate-inherited-mine')).not.toBeInTheDocument();
    expect(screen.getByTestId('g7le-initstate-remove-mine')).toBeInTheDocument();
  });

  it('I3: 부모+자식 다른 키 → 출처 구분(부모=🔗/자식=무배지), 병합본 모든 키 표시', () => {
    render(
      <InitialStateForm
        raw={{ initLocal: { fromParent: 1, fromChild: 2 } }}
        own={{ initLocal: { fromChild: 2 } }}
        patch={vi.fn()}
        t={t}
      />,
    );
    expect(screen.getByTestId('g7le-initstate-local-item-fromParent')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-initstate-local-item-fromChild')).toBeInTheDocument();
    // 부모만 🔗, 자식 무배지.
    expect(screen.getByTestId('g7le-initstate-inherited-fromParent')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-initstate-inherited-fromChild')).not.toBeInTheDocument();
  });

  it('I4: 부모+자식 같은 키(자식 덮음) → 병합본=자식 값, 자기 선언으로 분류(🔗 미표시, [✕])', () => {
    // own 에 그 키가 있으면(덮음) → 자기(self) 분류 → 🔗 부재, [✕] 노출.
    render(
      <InitialStateForm
        raw={{ initLocal: { shared: 'childValue' } }}
        own={{ initLocal: { shared: 'childValue' } }}
        patch={vi.fn()}
        t={t}
      />,
    );
    expect(screen.queryByTestId('g7le-initstate-inherited-shared')).not.toBeInTheDocument();
    expect(screen.getByTestId('g7le-initstate-remove-shared')).toBeInTheDocument();
  });

  it('I5: 부모만(키 K) → 상속 키 K 값 변경 가능(값 위젯 노출, 편집=덮어쓰기 경로)', () => {
    const patch = vi.fn();
    render(
      <InitialStateForm
        raw={{ initLocal: { keyword: 'parentVal' } }}
        own={{ initLocal: {} }}
        patch={patch}
        t={t}
      />,
    );
    // 상속 키 값 위젯(문자) 변경 → patchSection 으로 그 키 덮어쓰기(자기 페이지에 적용).
    const input = screen.getByTestId('g7le-initstate-value-keyword') as HTMLInputElement;
    fireEvent.change(input, { target: { value: 'overridden' } });
    expect(patch).toHaveBeenCalledWith('initLocal', { keyword: 'overridden' });
  });

  it('I6: 상속 키 K 값 확정 → patch(initLocal) 로 K=자식 값(병합본 덮음)', () => {
    const patch = vi.fn();
    render(
      <InitialStateForm
        raw={{ initLocal: { count: 5 } }}
        own={{ initLocal: {} }}
        patch={patch}
        t={t}
      />,
    );
    // 숫자 위젯 변경 → 자기 선언으로 승격(덮음).
    const input = screen.getByTestId('g7le-initstate-value-count') as HTMLInputElement;
    fireEvent.change(input, { target: { value: '10' } });
    expect(patch).toHaveBeenCalledWith('initLocal', { count: 10 });
  });

  it('I7: 상속 키 덮은 상태 → [되돌림] 클릭 → 자식 override 제거(키 제거 patch)', () => {
    const patch = vi.fn();
    render(
      <InitialStateForm
        raw={{ initLocal: { own: 1, inherited: 2 } }}
        own={{ initLocal: { own: 1 } }}
        patch={patch}
        t={t}
      />,
    );
    // inherited 는 상속(own 에 없음) → [되돌림] = 그 키 제거.
    fireEvent.click(screen.getByTestId('g7le-initstate-revert-inherited'));
    expect(patch).toHaveBeenLastCalledWith('initLocal', { own: 1 });
  });

  it('I8: 부모+자식 → 자식 고유 키 삭제 → 자식 키만 patch, 부모 키 불변', () => {
    const patch = vi.fn();
    render(
      <InitialStateForm
        raw={{ initLocal: { parentK: 1, childK: 2 } }}
        own={{ initLocal: { childK: 2 } }}
        patch={patch}
        t={t}
      />,
    );
    // 자식 키 [✕] → 부모 키(parentK) 보존.
    fireEvent.click(screen.getByTestId('g7le-initstate-remove-childK'));
    expect(patch).toHaveBeenLastCalledWith('initLocal', { parentK: 1 });
  });

  it('I9: 부모(키 K) 상속 → 새 값 추가 시 이름 K 입력 = 병합본 내 중복이라 거부(추가 안 됨)', () => {
    const patch = vi.fn();
    render(
      <InitialStateForm
        raw={{ initLocal: { keyword: 'parent' } }}
        own={{ initLocal: {} }}
        patch={patch}
        t={t}
      />,
    );
    // 병합본에 이미 keyword 존재 → 추가 폼에서 같은 이름 = 거부(addRow 의 `name in value` 가드).
    fireEvent.change(screen.getByTestId('g7le-initstate-local-add-name'), { target: { value: 'keyword' } });
    fireEvent.click(screen.getByTestId('g7le-initstate-local-add'));
    expect(patch).not.toHaveBeenCalled();
    // 상속 키 덮기는 값 위젯 편집(I5/I6) 경로로 수행.
  });

  it('I10: 자식 저장(💾) — 폼 patch 페이로드는 자기 분(미덮은 부모 키 strip 은 호스트), 덮은 키는 자식 값 포함', () => {
    const patch = vi.fn();
    render(
      <InitialStateForm
        raw={{ initLocal: { parentK: 1, childK: 2 } }}
        own={{ initLocal: { childK: 2 } }}
        patch={patch}
        t={t}
      />,
    );
    // 상속 키 값 변경(덮음) → patch 페이로드는 병합본 + 덮은 값(strip 은 저장 시 호스트).
    const input = screen.getByTestId('g7le-initstate-value-parentK') as HTMLInputElement;
    fireEvent.change(input, { target: { value: '99' } });
    expect(patch).toHaveBeenLastCalledWith('initLocal', { parentK: 99, childK: 2 });
  });

  it('I11: base 편집 모드(부모 직접) → 자기 레이어 편집 시 🔗상속 배지 부재', () => {
    // base 편집 모드에서는 호스트가 own=병합본(자기 레이어) 로 주입 → 모든 키 자기(self) → 🔗 부재.
    render(
      <InitialStateForm
        raw={{ initLocal: { baseKey: 1 } }}
        own={{ initLocal: { baseKey: 1 } }}
        patch={vi.fn()}
        t={t}
      />,
    );
    expect(screen.queryByTestId('g7le-initstate-inherited-baseKey')).not.toBeInTheDocument();
    expect(screen.getByTestId('g7le-initstate-remove-baseKey')).toBeInTheDocument();
  });

  it('I12: 부모(중첩 K={a:{b:1}})+자식 → 상속 중첩 키 통째 표시(shallow merge — 중첩 통째 교체)', () => {
    render(
      <InitialStateForm
        raw={{ initLocal: { filter: { status: 'all', sort: 'new' } } }}
        own={{ initLocal: {} }}
        patch={vi.fn()}
        t={t}
      />,
    );
    // 상속 중첩 키(filter) 표시 + 🔗 + 재귀 편집(하위 키 경로).
    expect(screen.getByTestId('g7le-initstate-inherited-filter')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-initstate-type-filter.status')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-initstate-type-filter.sort')).toBeInTheDocument();
  });

  it('I13: 3단 상속(조부모→부모→자식) → 같은 키 다단 시 최하위(자식) 유효, 상위 유래는 🔗', () => {
    // 병합본 = 자식 우선. own 에 있는 키(자식 덮음)=self, own 에 없는 키(상위 유래)=🔗.
    render(
      <InitialStateForm
        raw={{ initLocal: { fromGrand: 'g', overridden: 'childWins' } }}
        own={{ initLocal: { overridden: 'childWins' } }}
        patch={vi.fn()}
        t={t}
      />,
    );
    // 상위 유래(fromGrand)=🔗, 자식 덮음(overridden)=무배지.
    expect(screen.getByTestId('g7le-initstate-inherited-fromGrand')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-initstate-inherited-overridden')).not.toBeInTheDocument();
  });

  it('I14: 부모 initGlobal + 자식 initGlobal → initLocal 과 동일 정책(전역 섹션 상속/덮기/되돌림)', () => {
    const patch = vi.fn();
    render(
      <InitialStateForm
        raw={{ initGlobal: { theme: 'dark', mine: 'x' } }}
        own={{ initGlobal: { mine: 'x' } }}
        patch={patch}
        t={t}
      />,
    );
    // 전역 섹션: 부모 키 🔗 + 되돌림, 자식 키 무배지.
    expect(screen.getByTestId('g7le-initstate-inherited-theme')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-initstate-inherited-mine')).not.toBeInTheDocument();
    // 전역 되돌림 → initGlobal 패치(자식 분만).
    fireEvent.click(screen.getByTestId('g7le-initstate-revert-theme'));
    expect(patch).toHaveBeenLastCalledWith('initGlobal', { mine: 'x' });
  });

  it('I15: legacy state(부모) + initLocal(자식) → 정규화 후 병합(state→initLocal 취급), 저장 시 initLocal 로만 PUT', () => {
    const patch = vi.fn();
    render(
      <InitialStateForm
        raw={{ state: { legacyKey: 'v' }, initLocal: { newKey: 'w' } }}
        own={{ initLocal: { newKey: 'w' } }}
        patch={patch}
        t={t}
      />,
    );
    // 정규화 안내 노출 + 두 키 합산 표시.
    expect(screen.getByTestId('g7le-initstate-legacy-state')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-initstate-local-item-legacyKey')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-initstate-local-item-newKey')).toBeInTheDocument();
    // 편집(추가) → state 키 제거 + initLocal 로만 patch(legacy 이관).
    fireEvent.change(screen.getByTestId('g7le-initstate-local-add-name'), { target: { value: 'added' } });
    fireEvent.click(screen.getByTestId('g7le-initstate-local-add'));
    // state 제거 patch + initLocal 정규화 patch 둘 다 발화.
    expect(patch).toHaveBeenCalledWith('state', undefined);
    const initLocalCall = patch.mock.calls.find((c) => c[0] === 'initLocal');
    expect(initLocalCall).toBeDefined();
    expect(initLocalCall![1]).toMatchObject({ legacyKey: 'v', newKey: 'w', added: '' });
  });
});
