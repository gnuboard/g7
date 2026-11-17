// e2e:allow 엔진 렌더 경로 단위 잠금 — 브라우저 동작은 #916 엔진 회귀 브라우저 실측(구·신 번들 대조, 서명자·분석 삭제·게시판 일괄·연동 안내 footer 토스트 4종)으로 확인했고 관리자 템플릿 Vitest admin-install-toast-footer 가 실제 Modal footer 클릭을 잠근다
/**
 * @file DynamicRenderer.propActionTranslation.test.tsx
 * @description 컴포넌트 정의를 담은 prop 안의 액션 정의는 렌더 시점에 미리 번역하지 않는다 (engine-v1.96.1, #916)
 *
 * 렌더러는 props 를 ① 바인딩(`DataBindingEngine.resolveObject`) ② 번역(`resolveTranslationsDeep`) 순서로 푼다. ①은 액션
 * 정의를 건너뛰고(engine-v1.56.0 콜백 prop 선평가 방지), 반복 렌더(`RenderHelpers.resolveValue`)도 건너뛰는데 ②만 그 안까지
 * 내려갔다. 그래서 Modal `footer` 같은 prop 의 단추 액션에 든 `$t:키|이름={{response…}}` 가 응답이 오기 전 렌더 문맥
 * (response 없음)으로 번역돼 인자 자리가 빈 글자로 굳었다 — 설치 창 성공 토스트 「(서명자: )」.
 *
 * 잠그는 것: prop 안 액션 정의는 원문 그대로 컴포넌트에 넘어간다. 그대로 유지되는 것: 같은 prop 안 컴포넌트 정의의
 * 글자(`text`·props 의 `$t:`)는 종전처럼 번역된다.
 */
import React from 'react';
import { describe, it, expect, afterEach } from 'vitest';
import { render, cleanup } from '@testing-library/react';
import DynamicRenderer from '../DynamicRenderer';
import { ComponentRegistry } from '../ComponentRegistry';
import { DataBindingEngine } from '../DataBindingEngine';
import { TranslationEngine, TranslationContext } from '../TranslationEngine';
import { ActionDispatcher } from '../ActionDispatcher';

const RAW_MESSAGE = "$t:test.signed|signer={{(response?.data?.key_id ?? '').split(':')[0]}}";

let captured: any[] | null = null;

/** footer prop 을 받은 그대로 기록하는 부품 */
const FooterHost: React.FC<any> = ({ footer }) => {
  captured = footer;
  return <div data-testid="footer-host" />;
};

function setupRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  (registry as any).registry = {
    Div: { component: ({ children }: any) => <div>{children}</div>, metadata: { name: 'Div', type: 'basic' } },
    FooterHost: { component: FooterHost, metadata: { name: 'FooterHost', type: 'composite' } },
  };
  return registry;
}

const footer = [
  {
    type: 'basic',
    name: 'Button',
    text: '$t:test.install',
    props: { 'aria-label': '$t:test.install' },
    actions: [
      {
        type: 'click',
        handler: 'apiCall',
        target: '/api/install',
        params: { method: 'POST' },
        onSuccess: [
          { handler: 'toast', params: { type: 'success', message: RAW_MESSAGE } },
          { handler: 'setState', params: { target: 'local', note: '$t:test.done' } },
        ],
      },
    ],
  },
];

describe('[case:state-advanced-modal-footer-action-pretranslated] prop 안 액션 정의의 렌더 시점 번역 (engine-v1.96.1)', () => {
  const translationContext: TranslationContext = { templateId: 'test-template', locale: 'ko' };

  afterEach(() => {
    cleanup();
    captured = null;
  });

  async function renderHost(): Promise<void> {
    const translationEngine = new TranslationEngine();
    (translationEngine as any).translations.set('test-template:ko', {
      test: { install: '설치', signed: '서명 확인 (서명자: {{signer}})', done: '완료' },
    });
    render(
      <DynamicRenderer
        componentDef={{ id: 'host', type: 'composite', name: 'FooterHost', props: { footer } } as any}
        dataContext={{}}
        translationContext={translationContext}
        registry={setupRegistry()}
        bindingEngine={new DataBindingEngine()}
        translationEngine={translationEngine}
        actionDispatcher={new ActionDispatcher({})}
      />,
    );
  }

  /**
   * @scenario case=footer_button_action
   * @effects prop_action_definition_kept_raw
   */
  it('footer 단추 액션의 토스트 문구는 응답 값 인자를 품은 원문 그대로 넘어간다', async () => {
    await renderHost();

    expect(captured).not.toBeNull();
    const onSuccess = captured![0].actions[0].onSuccess;
    expect(onSuccess[0].params.message).toBe(RAW_MESSAGE);
    expect(onSuccess[1].params.note).toBe('$t:test.done');
  });

  /**
   * @scenario case=footer_component_text
   * @effects prop_component_text_still_translated
   */
  it('같은 prop 안 컴포넌트 정의의 글자는 종전처럼 번역된다', async () => {
    await renderHost();

    expect(captured![0].text).toBe('설치');
    expect(captured![0].props['aria-label']).toBe('설치');
  });

  /**
   * 번역 면제 바인딩(`{{raw:식}}`)의 결과가 액션 모양 객체여도 원문 표식은 벗긴다 — 반복 렌더 경로(RenderHelpers 의
   * `stripRawDeep(finalProps)`)와 같은 결과. 표식이 남으면 handler 이름까지 감싸여 미등록 핸들러가 된다.
   *
   * @scenario case=raw_bound_action_object
   * @effects raw_bound_action_markers_stripped
   */
  it('raw 바인딩 결과가 액션 모양 객체여도 원문 표식을 벗겨 넘긴다', async () => {
    let pick: any = null;
    const PickHost: React.FC<any> = ({ onPick }) => {
      pick = onPick;
      return null;
    };
    const registry = setupRegistry();
    (registry as any).registry.PickHost = { component: PickHost, metadata: { name: 'PickHost', type: 'composite' } };
    const translationEngine = new TranslationEngine();
    (translationEngine as any).translations.set('test-template:ko', { test: { done: '완료' } });

    render(
      <DynamicRenderer
        componentDef={{ id: 'pick', type: 'composite', name: 'PickHost', props: { onPick: '{{raw:act}}' } } as any}
        dataContext={{ act: { handler: 'toast', params: { type: 'info', message: '$t:test.done' } } }}
        translationContext={translationContext}
        registry={registry}
        bindingEngine={new DataBindingEngine()}
        translationEngine={translationEngine}
        actionDispatcher={new ActionDispatcher({})}
      />,
    );

    expect(pick).not.toBeNull();
    expect(pick.handler).toBe('toast');
    expect(pick.params.type).toBe('info');
    // 번역 면제 값이므로 번역하지 않는다(종전 동작 그대로)
    expect(pick.params.message).toBe('$t:test.done');
  });
});
