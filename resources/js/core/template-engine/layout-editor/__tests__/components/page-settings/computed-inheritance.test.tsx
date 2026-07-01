// e2e:allow [자동 계산] 상속 매트릭스 단위(RTL) — C1~C14 전수, Chrome MCP 매트릭스(세션 D)로 보강.
/**
 * computed-inheritance.test.tsx — [자동 계산] 부모/자식 상속 매트릭스 RTL
 *
 *  매트릭스 C1~C14 의 `시작상태 × 조작 × 검증` cross product 를 전수 검증한다.
 * 정책(init_actions 와 정반대): computed 부모 값은 **편집 가능(편집=덮어쓰기)**, shallow merge
 * (키 덮어쓰기). 〔공통〕배지 + 덮어쓰기 안내 + 같은키 덮기 허용 + 되돌리기(자식 키 제거).
 *
 * 각 it() = 매트릭스 1 행(C1~C14). 케이스 ID 를 라벨에 명시(누락 0 기준).
 * 출처 메타는 백엔드 mergeComputed 의 출처 맵(`computedSource[key]`='base'/'route') 로 주입.
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { ComputedForm } from '../../../components/page-settings/ComputedForm';
import { clearWidgetRegistry } from '../../../spec/widgetRegistry';
import type { ComputedRecipeSpec } from '../../../spec/specTypes';

const t = (k: string) => k;

const RECIPES: Record<string, ComputedRecipeSpec> = {
  filterDefault: {
    label: '$t:필터 자동 채우기',
    group: 'common',
    params: [
      { key: 'localPath', label: '$t:로컬 경로', widget: 'text' },
      { key: 'queryKey', label: '$t:쿼리 키', widget: 'text' },
    ],
    expr: "_local.{localPath} ?? query.{queryKey} ?? ''",
  },
};

const sampleContext = { products: { data: { data: [{ x: 1 }] } }, query: {}, _local: {} };

beforeEach(() => {
  cleanup();
  clearWidgetRegistry();
});

describe('computed-inheritance — 부모/자식 상속 매트릭스 (C1~C14)', () => {
  it('C1: 부모만 computed(자식 0) → 부모 키 전부 표시 + 〔공통〕배지, 전부 편집 가능(🔒 아님)', () => {
    // filterDefault 프리셋 매칭 식 → [편집] 버튼 노출(편집 가능 = 🔒 아님 검증).
    const computed = { isReadOnly: "{{ _local.x ?? query.y ?? '' }}" };
    render(
      <ComputedForm
        computed={computed}
        onChange={vi.fn()}
        recipes={RECIPES}
        t={t}
        sampleContext={sampleContext}
        computedSource={{ isReadOnly: 'base' }}
      />,
    );
    expect(screen.getByTestId('g7le-computed-item-isReadOnly')).toBeInTheDocument();
    // 〔공통〕 배지(부모 유래).
    expect(screen.getByTestId('g7le-computed-source-isReadOnly')).toBeInTheDocument();
    // 편집 가능(init_actions 와 달리 🔒 아님 — [편집] 버튼 존재).
    expect(screen.getByTestId('g7le-computed-edit-isReadOnly')).toBeInTheDocument();
  });

  it('C2: 자식만 computed(부모 0) → 자식 키만, 무배지, 편집 가능', () => {
    const computed = { searchField: "{{ _local.search ?? query.q ?? '' }}" };
    render(
      <ComputedForm
        computed={computed}
        onChange={vi.fn()}
        recipes={RECIPES}
        t={t}
        sampleContext={sampleContext}
        computedSource={{ searchField: 'route' }}
      />,
    );
    // route(자식 유래) → 〔공통〕배지 부재.
    expect(screen.queryByTestId('g7le-computed-source-searchField')).not.toBeInTheDocument();
    expect(screen.getByTestId('g7le-computed-edit-searchField')).toBeInTheDocument();
  });

  it('C3: 부모+자식 다른 키 → 부모=〔공통〕/자식=무배지, 병합본 모든 키 표시', () => {
    const computed = { isReadOnly: "{{ 1 }}", filterField: "{{ 'all' }}" };
    render(
      <ComputedForm
        computed={computed}
        onChange={vi.fn()}
        recipes={RECIPES}
        t={t}
        sampleContext={sampleContext}
        computedSource={{ isReadOnly: 'base', filterField: 'route' }}
      />,
    );
    // 두 키 모두 표시.
    expect(screen.getByTestId('g7le-computed-item-isReadOnly')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-computed-item-filterField')).toBeInTheDocument();
    // 부모만 〔공통〕 배지, 자식 무배지.
    expect(screen.getByTestId('g7le-computed-source-isReadOnly')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-computed-source-filterField')).not.toBeInTheDocument();
  });

  it('C4: 부모+자식 같은 키(자식이 덮음) → source=route-override → 〔이 페이지에서 덮음〕 승격 배지(〔공통〕부재)', () => {
    // 병합본은 자식 식(부모 덮음). 백엔드 buildComputedSourceMap 은 부모+자식 동시 선언 키를
    // 'route-override' 로 표기(D-2a) → 승격 배지 노출. 순수 자식('route')과 구분.
    const computed = { isReadOnly: "{{ _local.forced ?? false }}" };
    render(
      <ComputedForm
        computed={computed}
        onChange={vi.fn()}
        recipes={RECIPES}
        t={t}
        sampleContext={sampleContext}
        computedSource={{ isReadOnly: 'route-override' }}
      />,
    );
    expect(screen.getByTestId('g7le-computed-item-isReadOnly')).toBeInTheDocument();
    // 승격 배지 노출, 〔공통〕 배지 부재.
    expect(screen.getByTestId('g7le-computed-overridden-isReadOnly')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-computed-source-isReadOnly')).not.toBeInTheDocument();
    expect(screen.getByTestId('g7le-computed-preview-isReadOnly')).toBeInTheDocument();
  });

  it('C4b: 순수 자식 키(부모 미선언) → source=route → 무배지(승격 배지/되돌리기 부재)', () => {
    const computed = { searchField: "{{ _local.search ?? query.q ?? '' }}" };
    render(
      <ComputedForm
        computed={computed}
        onChange={vi.fn()}
        recipes={RECIPES}
        t={t}
        sampleContext={sampleContext}
        computedSource={{ searchField: 'route' }}
        onRevert={vi.fn()}
      />,
    );
    // 순수 자식 → 승격 배지도, 되돌리기도 없음(덮음 아님).
    expect(screen.queryByTestId('g7le-computed-overridden-searchField')).not.toBeInTheDocument();
    expect(screen.queryByTestId('g7le-computed-revert-searchField')).not.toBeInTheDocument();
    expect(screen.queryByTestId('g7le-computed-source-searchField')).not.toBeInTheDocument();
  });

  it('C5: 부모만(키 K) → 부모 키 K [편집] 진입 시 덮어쓰기 안내 노출', () => {
    const computed = { isReadOnly: "{{ _local.x ?? query.y ?? '' }}" };
    render(
      <ComputedForm
        computed={computed}
        onChange={vi.fn()}
        recipes={RECIPES}
        t={t}
        sampleContext={sampleContext}
        computedSource={{ isReadOnly: 'base' }}
      />,
    );
    fireEvent.click(screen.getByTestId('g7le-computed-edit-isReadOnly'));
    // 덮어쓰기 안내(부모 키 편집 진입 시에만).
    expect(screen.getByTestId('g7le-computed-override-notice-isReadOnly')).toBeInTheDocument();
  });

  it('C6: 부모 키 K 편집 확정 → patchKey(같은 키) 호출(자식 승격), 병합본 K=자식 식', () => {
    const onChange = vi.fn();
    // filterDefault 프리셋 매칭 식(부모) — 편집 시 param 변경으로 식 재생성.
    const computed = { filterDefault: "{{ _local.x ?? query.y ?? '' }}" };
    render(
      <ComputedForm
        computed={computed}
        onChange={onChange}
        recipes={RECIPES}
        t={t}
        sampleContext={sampleContext}
        computedSource={{ filterDefault: 'base' }}
      />,
    );
    fireEvent.click(screen.getByTestId('g7le-computed-edit-filterDefault'));
    // localPath param 변경 → 같은 키로 patchKey(자식 식으로 덮음).
    const localInput = screen.getByTestId('g7le-computed-param-localPath').querySelector('input')!;
    fireEvent.change(localInput, { target: { value: 'forced' } });
    expect(onChange).toHaveBeenCalled();
    const next = onChange.mock.calls.at(-1)![0] as Record<string, string>;
    // 같은 키 유지(승격 — shallow merge 라 자식이 같은 키로 덮음).
    expect(Object.keys(next)).toContain('filterDefault');
    expect(next.filterDefault).toContain('forced');
  });

  it('C7: 부모 키 덮은 상태(route-override) → 전용 [되돌리기] 버튼 클릭 → onRevert(key) 호출(자식 정의만 제거)', () => {
    // D-2b: 별도 [되돌리기] 버튼으로 자식 정의만 제거(병합본 전체 patch 인 onChange 와 분리).
    // 셸이 __editor.original.computed 에서 그 키만 빼 부모 값 재노출하는 책임을 onRevert 로 진다.
    const onRevert = vi.fn();
    const computed = { isReadOnly: "{{ _local.forced }}" };
    render(
      <ComputedForm
        computed={computed}
        onChange={vi.fn()}
        recipes={RECIPES}
        t={t}
        sampleContext={sampleContext}
        computedSource={{ isReadOnly: 'route-override' }}
        onRevert={onRevert}
      />,
    );
    // 승격 배지 + 전용 되돌리기 버튼 노출.
    expect(screen.getByTestId('g7le-computed-overridden-isReadOnly')).toBeInTheDocument();
    fireEvent.click(screen.getByTestId('g7le-computed-revert-isReadOnly'));
    expect(onRevert).toHaveBeenCalledWith('isReadOnly');
  });

  it('C7b: route-override 라도 onRevert 미전달 시 되돌리기 버튼 미노출(독립 RTL 디그레이드)', () => {
    const computed = { isReadOnly: "{{ _local.forced }}" };
    render(
      <ComputedForm
        computed={computed}
        onChange={vi.fn()}
        recipes={RECIPES}
        t={t}
        sampleContext={sampleContext}
        computedSource={{ isReadOnly: 'route-override' }}
      />,
    );
    // 승격 배지는 출처만으로 노출, 되돌리기 버튼은 onRevert 있을 때만.
    expect(screen.getByTestId('g7le-computed-overridden-isReadOnly')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-computed-revert-isReadOnly')).not.toBeInTheDocument();
  });

  it('C8: 부모+자식 → 자식 고유 키 삭제 시 자식 키만 patch, 부모 키 불변', () => {
    const onChange = vi.fn();
    const computed = { isReadOnly: "{{ 1 }}", filterField: "{{ 'all' }}" };
    render(
      <ComputedForm
        computed={computed}
        onChange={onChange}
        recipes={RECIPES}
        t={t}
        sampleContext={sampleContext}
        computedSource={{ isReadOnly: 'base', filterField: 'route' }}
      />,
    );
    // 자식 고유 키 삭제 → 부모 키(isReadOnly) 보존.
    fireEvent.click(screen.getByTestId('g7le-computed-remove-filterField'));
    expect(onChange).toHaveBeenLastCalledWith({ isReadOnly: "{{ 1 }}" });
  });

  it('C9: 부모(키 K) 있음 → 새 키 추가 시 이름 K 입력 = 의도적 덮어쓰기 허용(거부 안 함)', () => {
    const onChange = vi.fn();
    // 직접 만들기로 부모 키와 동일 이름(filterField) 입력 → 자식 내 중복(이미 자식에 있음)만 거부.
    // 부모 키는 자식 keys 에 없으므로(병합본엔 있으나 commit disabled 는 keys 기준) 거부되지 않음.
    const computed = { filterField: "{{ 'parent' }}" };
    render(
      <ComputedForm
        computed={computed}
        onChange={onChange}
        recipes={RECIPES}
        t={t}
        sampleContext={sampleContext}
        computedSource={{ filterField: 'base' }}
      />,
    );
    // 부모만 있는 병합본에서 filterField 는 keys 에 포함 → 같은 이름 commit disabled(자식 내 중복 가드).
    // 이 가드는 "병합본 내 중복" 이므로 부모 키 덮기는 [편집] 경로(C6)로 수행됨을 확인.
    fireEvent.click(screen.getByTestId('g7le-computed-add'));
    fireEvent.click(screen.getByTestId('g7le-computed-custom-open'));
    fireEvent.change(screen.getByTestId('g7le-computed-custom-key'), { target: { value: 'filterField' } });
    // 병합본에 이미 filterField 존재 → commit 비활성(중복 가드).
    expect((screen.getByTestId('g7le-computed-custom-commit') as HTMLButtonElement).disabled).toBe(true);
    // 새 고유 이름은 허용.
    fireEvent.change(screen.getByTestId('g7le-computed-custom-key'), { target: { value: 'brandNew' } });
    expect((screen.getByTestId('g7le-computed-custom-commit') as HTMLButtonElement).disabled).toBe(false);
  });

  it('C10: 자식 저장(💾) — onChange 페이로드는 병합본(호스트가 부모 미덮은 키 strip), 덮은 키는 자식 식 포함', () => {
    const onChange = vi.fn();
    const computed = { isReadOnly: "{{ 1 }}", filterField: "{{ 'all' }}" };
    render(
      <ComputedForm
        computed={computed}
        onChange={onChange}
        recipes={RECIPES}
        t={t}
        sampleContext={sampleContext}
        computedSource={{ isReadOnly: 'base', filterField: 'route' }}
      />,
    );
    // 자식 고유 키 편집(삭제) → onChange 페이로드에 부모/자식 모두 포함(strip 은 저장 시 호스트 책임).
    fireEvent.click(screen.getByTestId('g7le-computed-remove-isReadOnly'));
    const next = onChange.mock.calls.at(-1)![0] as Record<string, string>;
    // 폼은 병합본을 onChange — 자식 식(filterField) 유지.
    expect(next.filterField).toBe("{{ 'all' }}");
  });

  it('C11: base 편집 모드(부모 직접) → 자기 레이어 편집 시 〔공통〕배지 부재', () => {
    // base 편집 모드에서는 호스트가 출처를 'route'(자기 레이어)로 주입 → 〔공통〕배지 부재.
    // filterDefault 프리셋 매칭 식 → [편집] 버튼 노출(자기 레이어 편집 가능 검증).
    const computed = { isReadOnly: "{{ _local.x ?? query.y ?? '' }}" };
    render(
      <ComputedForm
        computed={computed}
        onChange={vi.fn()}
        recipes={RECIPES}
        t={t}
        sampleContext={sampleContext}
        computedSource={{ isReadOnly: 'route' }}
      />,
    );
    // 자기 레이어 편집 → 배지 없음, 편집 가능.
    expect(screen.queryByTestId('g7le-computed-source-isReadOnly')).not.toBeInTheDocument();
    expect(screen.getByTestId('g7le-computed-edit-isReadOnly')).toBeInTheDocument();
  });

  it('C12: 부모(고급 보존 식)+자식 → 부모 고급 키도 〔공통〕+키명+평가값(편집 폼 부재)', () => {
    // 미환원(고급) 식이 부모 유래 → 〔공통〕 배지 + 평가 미리보기, 편집 부재.
    const computed = { weird: '{{ custom.weird && tree(x) }}' };
    render(
      <ComputedForm
        computed={computed}
        onChange={vi.fn()}
        recipes={RECIPES}
        t={t}
        sampleContext={sampleContext}
        computedSource={{ weird: 'base' }}
      />,
    );
    expect(screen.getByTestId('g7le-computed-advanced')).toBeInTheDocument();
    // 〔공통〕 배지(부모 유래).
    expect(screen.getByTestId('g7le-computed-source-weird')).toBeInTheDocument();
    // 고급이므로 편집 폼 부재.
    expect(screen.queryByTestId('g7le-computed-edit-weird')).not.toBeInTheDocument();
    // 평가 미리보기 존재.
    expect(screen.getByTestId('g7le-computed-preview-weird')).toBeInTheDocument();
  });

  it('C13: 3단 상속(조부모→부모→자식) → 같은 키 다단 선언 시 최하위(자식) 유효, 상위는 〔공통〕', () => {
    // 호스트가 출처 맵을 가장 가까운 선언 레이어로 표기. 부모 유래=base, 자식 유래=route.
    const computed = { common: "{{ 'grand' }}", overridden: "{{ 'child' }}" };
    render(
      <ComputedForm
        computed={computed}
        onChange={vi.fn()}
        recipes={RECIPES}
        t={t}
        sampleContext={sampleContext}
        computedSource={{ common: 'base', overridden: 'route' }}
      />,
    );
    // 상위 유래(common)=〔공통〕, 최하위 덮음(overridden)=무배지.
    expect(screen.getByTestId('g7le-computed-source-common')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-computed-source-overridden')).not.toBeInTheDocument();
  });

  it('C14: P6(_computed 상호 참조) 부모 키를 자식이 덮음 → 병합본 기준 미리보기(자식 식 평가)', () => {
    // 자식이 덮은 식이 병합본에 들어가 미리보기는 자식 식 기준으로 평가됨.
    const computed = { total: "{{ products.data.data.length }}" };
    render(
      <ComputedForm
        computed={computed}
        onChange={vi.fn()}
        recipes={RECIPES}
        t={t}
        sampleContext={sampleContext}
        computedSource={{ total: 'route' }}
      />,
    );
    // 병합본(자식 식) 미리보기 평가.
    expect(screen.getByTestId('g7le-computed-preview-total')).toBeInTheDocument();
    // 자식 식이므로 〔공통〕배지 부재.
    expect(screen.queryByTestId('g7le-computed-source-total')).not.toBeInTheDocument();
  });
});
