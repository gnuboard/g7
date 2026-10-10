// e2e:allow [동작] 탭 안내 띠 RTL — 화면 검증은 ko/en 편집기 실측(R-A 결과 문서)으로 한다.
/**
 * ActionRecipeEditor.rawValueHint.test.tsx — 원시값 콜백 칸의 「바뀐 값 읽기」 안내 (7.1.0 R-A 결정)
 *
 * 부품 역량이 `rawValueEvents` 로 표시한 칸(예: SearchableDropdown 의 「값이 바뀌면」)은 런타임이 `onChange(값)` 을
 * `$args` 로 싣는다. 이벤트 칸처럼 `$event.target.value` 로 읽으면 빈 값이므로, 칸 위에 「바뀐 값은 {{$args[0]}}
 * 로 읽는다」를 보인다. 값 위치가 첫 인자가 아닌 부품(FilterGroup `onChange(filterId, value)`)은 역량의
 * `rawValueArgIndex` 로 위치를 알린다.
 */
import React from 'react';
import { describe, it, expect, afterEach } from 'vitest';
import { render, screen, cleanup } from '@testing-library/react';
import { ActionRecipeEditor } from '../../../components/property-controls/ActionRecipeEditor';
import type { EditorSpec } from '../../../spec/specTypes';
import type { EditorNode } from '../../../utils/layoutTreeUtils';
import { TranslationEngine } from '../../../../TranslationEngine';
import { readFileSync } from 'fs';
import { resolve } from 'path';

const t = (k: string, params?: Record<string, unknown>) =>
  params ? `${k}${Object.entries(params).map(([pk, pv]) => `|${pk}=${String(pv)}`).join('')}` : k;

const SPEC: EditorSpec = {
  actionRecipes: {
    probe: { label: '$t:탐침', build: { handler: 'probeHandler' }, __source: { kind: 'core' } } as never,
  },
};

afterEach(() => cleanup());

describe('[동작] 탭 — 원시값 콜백 칸 안내', () => {
  // @scenario event_notation=event
  // @effects raw_value_slot_shows_args_hint
  it('rawValueEvents 칸에 「바뀐 값은 {{$args[0]}}」 안내가 보인다', () => {
    render(
      <ActionRecipeEditor
        node={{ name: 'SearchableDropdown' } as EditorNode}
        spec={SPEC}
        capability={{ events: ['onChange', 'onSearch'], rawValueEvents: ['onChange'] }}
        t={t as never}
        onPatchNode={() => {}}
      />,
    );
    const hint = screen.getByTestId('g7le-action-slot-onChange-raw-value-hint');
    expect(hint.getAttribute('role')).toBe('note');
    expect(hint.textContent).toBe('layout_editor.action.raw_value_hint|index=0');
    // 표식이 없는 칸(onSearch)에는 안내가 없다.
    expect(screen.queryByTestId('g7le-action-slot-onSearch-raw-value-hint')).toBeNull();
  });

  // @scenario event_notation=event
  // @effects raw_value_slot_shows_args_hint
  it('값 위치가 둘째 인자인 부품(FilterGroup)은 {{$args[1]}} 로 안내한다', () => {
    render(
      <ActionRecipeEditor
        node={{ name: 'FilterGroup' } as EditorNode}
        spec={SPEC}
        capability={{ events: ['onChange'], rawValueEvents: ['onChange'], rawValueArgIndex: { onChange: 1 } }}
        t={t as never}
        onPatchNode={() => {}}
      />,
    );
    expect(screen.getByTestId('g7le-action-slot-onChange-raw-value-hint').textContent)
      .toBe('layout_editor.action.raw_value_hint|index=1');
  });

  // @scenario event_notation=event
  // @effects raw_value_slot_shows_args_hint
  it('역량의 값 위치가 음수·정수 아님이면 첫 인자({{$args[0]}})로 안내한다', () => {
    for (const bad of [-1, 1.5]) {
      render(
        <ActionRecipeEditor
          node={{ name: 'BrokenCapability' } as EditorNode}
          spec={SPEC}
          capability={{ events: ['onChange'], rawValueEvents: ['onChange'], rawValueArgIndex: { onChange: bad } }}
          t={t as never}
          onPatchNode={() => {}}
        />,
      );
      expect(screen.getByTestId('g7le-action-slot-onChange-raw-value-hint').textContent)
        .toBe('layout_editor.action.raw_value_hint|index=0');
      cleanup();
    }
  });

  // @scenario event_notation=type
  // @effects raw_value_slot_shows_args_hint
  it('표식이 없는 부품의 「값이 바뀌면」 칸(DOM 이벤트)에는 안내가 없다', () => {
    render(
      <ActionRecipeEditor
        node={{ name: 'Input' } as EditorNode}
        spec={SPEC}
        capability={{ events: ['onChange'] }}
        t={t as never}
        onPatchNode={() => {}}
      />,
    );
    expect(screen.queryByTestId('g7le-action-slot-onChange-raw-value-hint')).toBeNull();
  });

  // @scenario event_notation=event
  // @effects raw_value_slot_shows_args_hint
  it('안내 문구가 ko/en/ja 에 있고, 번역 엔진을 거쳐도 {{$args[N]}} 가 글자 그대로 남는다', () => {
    const root = resolve(__dirname, '../../../../../../../..');
    const pick = (rel: string) => JSON.parse(readFileSync(resolve(root, rel), 'utf-8')).action.raw_value_hint as string;
    const ko = pick('lang/partial/ko/layout_editor.json');
    for (const text of [ko, pick('lang/partial/en/layout_editor.json'), pick('lang-packs/_bundled/g7-core-ja/frontend/partial/layout_editor.json')]) {
      expect(text).toContain('{{$args[{index}]}}');
    }
    // 편집기 t(키, { index: 1 }) 가 거치는 번역 엔진에서 위치만 바뀌고 {{ }} 는 바인딩으로 해석되지 않는다.
    const engine = new TranslationEngine({ defaultLocale: 'ko', fallbackLocale: 'en' });
    engine.setTranslationValue('core', 'ko', 'layout_editor.action.raw_value_hint', ko);
    expect(engine.translate('layout_editor.action.raw_value_hint', { templateId: 'core', locale: 'ko' }, '|index=1')).toContain('{{$args[1]}}');
  });
});
