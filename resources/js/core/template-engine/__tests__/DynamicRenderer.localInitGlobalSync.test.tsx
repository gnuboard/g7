/**
 * _localInit → 전역 `_local` 동기화가 **쓰기 시점의 canonical 상태**를 base 로 병합하는지 검증
 *
 * 회귀 배경 (#492 D-20)
 *
 * 목록 화면은 URL query 로 필터/정렬을 표현하고, 라우트 진입 시 `init_actions` 가 그 query 를
 * `_local` 에 시드해 필터 컨트롤을 복원한다. 그런데 `_localInit`(데이터소스 initLocal) 동기화가
 * 렌더 시점 스냅샷(`dataContext._global._local`)을 병합 base 로 쓰고, `setGlobalState` 는
 * `{ _local: X }` 를 얕게 펼쳐 저장소를 **통째로 교체**한다. 이 effect 는 렌더 커밋 뒤에 실행되므로
 * 시드가 그 사이에 일어나면 스냅샷 기반 교체가 시드를 되돌린다 —
 * 상세 진입 후 뒤로가기로 목록에 복귀하면 URL·목록·총건수는 필터가 걸린 상태인데
 * 필터 라디오만 `전체` 로 표시되는 결함.
 *
 * 아래 테스트는 그 순서를 그대로 재현한다: 스냅샷(stale) ≠ canonical(seeded).
 */

import React from 'react';
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render } from '@testing-library/react';
import DynamicRenderer, { ComponentDefinition } from '../DynamicRenderer';
import { ComponentRegistry } from '../ComponentRegistry';
import { DataBindingEngine } from '../DataBindingEngine';
import { TranslationEngine, TranslationContext } from '../TranslationEngine';
import { ActionDispatcher } from '../ActionDispatcher';
import { resetLocalInitTracking } from '../localInitSlot';

const TestDiv: React.FC<Record<string, unknown>> = (props) => {
  const { children, ...rest } = props as { children?: React.ReactNode };
  return <div {...(rest as Record<string, never>)}>{children}</div>;
};

describe('_localInit 전역 동기화 — canonical base 병합', () => {
  let registry: ComponentRegistry;
  let bindingEngine: DataBindingEngine;
  let translationEngine: TranslationEngine;
  let actionDispatcher: ActionDispatcher;
  let translationContext: TranslationContext;

  beforeEach(() => {
    resetLocalInitTracking();
    registry = ComponentRegistry.getInstance();
    (registry as any).registry = {
      Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    };
    bindingEngine = new DataBindingEngine();
    translationEngine = new TranslationEngine();
    actionDispatcher = new ActionDispatcher({ navigate: vi.fn() });
    translationContext = { templateId: 'test-template', locale: 'ko' };
  });

  const componentDef: ComponentDefinition = {
    id: 'list_root',
    type: 'basic',
    name: 'Div',
    props: { 'data-testid': 'root' },
    children: [],
  } as unknown as ComponentDefinition;

  /**
   * TemplateApp.setGlobalState 와 동일한 계약을 갖는 테스트 대역.
   * - 객체 업데이트: 얕은 펼침 (`{ _local: X }` 는 저장소를 통째로 교체)
   * - 함수형 업데이트: `prev` 를 인자로 받아 반환값으로 교체
   */
  const createStore = (initial: Record<string, any>) => {
    let state = initial;
    return {
      get current() {
        return state;
      },
      setGlobalState: (
        updates: Record<string, any> | ((prev: Record<string, any>) => Record<string, any>)
      ) => {
        state = typeof updates === 'function' ? updates(state) : { ...state, ...updates };
      },
    };
  };

  it('init_actions 시드가 렌더 뒤 _localInit 동기화로 되돌아가지 않아야 한다', () => {
    // canonical(저장소 B): init_actions 가 URL query 로 시드한 최신 값
    const store = createStore({
      _local: {
        filter: { issueStatus: 'issuing', targetType: 'all' },
        sortBy: 'name_asc',
      },
    });

    // 렌더 시점 스냅샷: 시드 이전 값(레이아웃 기본값)이 담긴 stale 컨텍스트
    const staleGlobalSnapshot = {
      _local: {
        filter: { issueStatus: 'all', targetType: 'all' },
        sortBy: 'created_at_desc',
      },
    };

    render(
      <DynamicRenderer
        componentDef={componentDef}
        dataContext={{
          _local: staleGlobalSnapshot._local,
          _global: staleGlobalSnapshot,
          _globalSetState: store.setGlobalState,
          _localInit: { rows: [{ id: 1 }] },
        }}
        translationContext={translationContext}
        registry={registry}
        bindingEngine={bindingEngine}
        translationEngine={translationEngine}
        actionDispatcher={actionDispatcher}
      />
    );

    // _localInit payload 는 반영되어야 한다
    expect(store.current._local.rows).toEqual([{ id: 1 }]);
    // 그리고 시드된 필터/정렬은 살아 있어야 한다 (수정 전에는 'all' / 'created_at_desc' 로 역전)
    expect(store.current._local.filter.issueStatus).toBe('issuing');
    expect(store.current._local.sortBy).toBe('name_asc');
  });

  it('_merge:"replace" 는 기존 계약대로 base 를 무시하고 payload 로 교체한다', () => {
    const store = createStore({
      _local: { filter: { issueStatus: 'issuing' }, stale: 'keep-out' },
    });

    render(
      <DynamicRenderer
        componentDef={componentDef}
        dataContext={{
          _local: {},
          _global: { _local: {} },
          _globalSetState: store.setGlobalState,
          _localInit: { _merge: 'replace', form: { name: 'A' } },
        }}
        translationContext={translationContext}
        registry={registry}
        bindingEngine={bindingEngine}
        translationEngine={translationEngine}
        actionDispatcher={actionDispatcher}
      />
    );

    expect(store.current._local.form).toEqual({ name: 'A' });
    expect(store.current._local.stale).toBeUndefined();
    expect(store.current._local.filter).toBeUndefined();
  });

  it('_global 이외의 전역 키는 동기화로 소실되지 않아야 한다', () => {
    const store = createStore({
      _local: { filter: { issueStatus: 'issuing' } },
      cartKey: 'ck-1',
      toasts: [],
    });

    render(
      <DynamicRenderer
        componentDef={componentDef}
        dataContext={{
          _local: {},
          _global: { _local: {} },
          _globalSetState: store.setGlobalState,
          _localInit: { rows: [] },
        }}
        translationContext={translationContext}
        registry={registry}
        bindingEngine={bindingEngine}
        translationEngine={translationEngine}
        actionDispatcher={actionDispatcher}
      />
    );

    expect(store.current.cartKey).toBe('ck-1');
    expect(store.current.toasts).toEqual([]);
  });
});
