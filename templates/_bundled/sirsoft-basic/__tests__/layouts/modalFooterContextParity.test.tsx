/**
 * @file modalFooterContextParity.test.tsx
 * @description Modal 고정 하단(`props.footer`)과 본문 자식이 같은 데이터 문맥으로 그려지는지 (#869 4라운드)
 *
 * 본문 자식은 엔진이 Modal 노드의 확장 데이터 컨텍스트로 그리고, 고정 하단은 Modal 이 `__componentContext.parentDataContext`
 * 로 그린다. 엔진의 컴포넌트 문맥 메모는 확장 데이터 컨텍스트 전체를 의존성에 두므로 두 값은 같은 렌더의 같은 객체다.
 * 모달을 여는 길(엔진 openModal 없이 `_global.modalStack` 만 바꿈, 레이아웃 컨텍스트 스택 직접 주입, 페이지 루트 Modal)과
 * 상관없이 `$parent._local`·`_local`·`_computed`·`_global`·`route` 가 두 표면에서 같아야 한다.
 *
 * @scenario-manifest tests/scenarios/modal-footer-context-parity.yaml
 *
 * @vitest-environment jsdom
 */
import React from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { fireEvent } from '@testing-library/react';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import { Modal } from '../../src/components/composite/Modal';
import componentsManifest from '../../components.json';

/** 이 템플릿 components.json 의 Modal 메타데이터 — 엔진이 props 를 어떻게 풀지(skipBindingKeys)는 여기서 정해진다 */
const modalMetadata: Record<string, any> = (componentsManifest as any).components.composite.find((c: any) => c.name === 'Modal');

// 엔진(코어)은 저장소 루트의 react 를, Modal 은 이 템플릿 node_modules 의 react 를 읽어 사본이 둘이 된다 — 루트 사본으로 맞춘다
vi.mock('react', async () => {
  const p = await import('path');
  const u = await import('url');
  const dir = p.dirname(u.fileURLToPath(import.meta.url));

  return import(/* @vite-ignore */ u.pathToFileURL(p.resolve(dir, '../../../../../node_modules/react/index.js')).href);
});

const Span: React.FC<any> = ({ text, children, id }) => <span id={id}>{children ?? text}</span>;
const Button: React.FC<any> = ({ children, text, id, onClick }) => <button id={id} type="button" onClick={onClick}>{children ?? text}</button>;
const Fragment: React.FC<any> = ({ children }) => <>{children}</>;

/** 하네스 G7Core 에 엔진 렌더러 접근자를 잇는 진짜 Modal (운영 G7CoreGlobals 와 같은 값) */
const RealModal: React.FC<any> = (props) => {
  const g7 = (window as any).G7Core;
  if (g7 && typeof g7.getDynamicRenderer !== 'function') {
    g7.getDynamicRenderer = () => (window as any).__DynamicRenderer ?? null;
  }

  return <Modal {...props} isOpen onClose={props.onClose ?? (() => {})} />;
};

// 본문과 고정 하단은 같은 렌더 사이클에 그려진다 — 같은 식 문자열이면 렌더 사이클 캐시가 본문 값을 하단에 돌려줘 하단 문맥이
// 비어도 같아 보인다. 그래서 하단 표식은 뜻은 같고 글자가 다른 식(괄호·큰따옴표)으로 쓴다
const BODY_EXPR = "{{$parent._local.flag ?? 'none'}}/{{_local.n}}/{{_computed.doubled}}/{{_global.deleting ? 'busy' : 'idle'}}/{{route.id ?? 'noroute'}}/{{_isolated.scope ?? 'noiso'}}";
const FOOTER_EXPR = '{{($parent._local.flag) ?? "none"}}/{{(_local.n)}}/{{(_computed.doubled)}}/{{(_global.deleting) ? "busy" : "idle"}}/{{(route.id) ?? "noroute"}}/{{(_isolated.scope) ?? "noiso"}}';
const probe = (id: string, expr: string) => ({ id, type: 'basic', name: 'Span', props: { id }, text: expr });
const bump = { id: 'bump', type: 'basic', name: 'Button', props: { id: 'bump' }, text: '+', actions: [{ type: 'click', handler: 'setState', params: { target: 'local', n: 5 } }] };
// Modal 자신의 격리 상태 — 엔진은 Modal 요소를 IsolatedStateProvider 로 감싸므로 본문·하단 렌더러만 이 값을 본다(Modal 노드 렌더러는 못 본다)
const modalDef = { id: 'ctx_modal', type: 'composite', name: 'Modal', isolatedState: { scope: 'iso' }, props: { title: 'ctx', footer: [probe('footer_probe', FOOTER_EXPR)] }, children: [probe('body_probe', BODY_EXPR), bump] };

/**
 * 시험용 레지스트리
 *
 * @returns 레지스트리
 */
function registry(): ComponentRegistry {
  const r = ComponentRegistry.getInstance();
  (r as any).registry = {
    Span: { component: Span, metadata: { name: 'Span', type: 'basic' } },
    Button: { component: Button, metadata: { name: 'Button', type: 'basic' } },
    Fragment: { component: Fragment, metadata: { name: 'Fragment', type: 'layout' } },
    Modal: { component: RealModal, metadata: { ...modalMetadata, name: 'Modal', type: 'composite' } },
  };

  return r;
}

const text = (id: string): string | null | undefined => document.getElementById(id)?.textContent;

describe('Modal 고정 하단과 본문의 데이터 문맥', () => {
  afterEach(() => {
    delete (window as any).__g7LayoutContextStack;
  });

  const cases: Array<{ name: string; asModal: boolean; stack: boolean; expected: string }> = [
    { name: '레이아웃 modals — openModal 없이 modalStack 만 바꿈', asModal: true, stack: false, expected: 'none/1/2/busy/7/iso' },
    { name: '레이아웃 modals — 레이아웃 컨텍스트 스택을 직접 넣고 modalStack 만 바꿈', asModal: true, stack: true, expected: 'P/1/2/busy/7/iso' },
    { name: '페이지 루트에 둔 Modal', asModal: false, stack: false, expected: 'none/1/2/busy/7/iso' },
  ];

  for (const c of cases) {
    // @scenario open_path=direct_stack
    // @effects footer_and_body_share_data_context
    it(`${c.name}: 본문과 고정 하단이 같은 값을 그리고, 본문에서 상태를 바꿔도 함께 바뀐다`, async () => {
      if (c.stack) {
        (window as any).__g7LayoutContextStack = [{ state: { flag: 'P' }, setState: () => {}, dataContext: { _local: { flag: 'P' }, _global: {} } }];
      }
      const layout: any = c.asModal
        ? { version: '1.0.0', layout_name: 'ctx', computed: { doubled: '{{(_local.n ?? 0) * 2}}' }, components: [], modals: [modalDef] }
        : { version: '1.0.0', layout_name: 'ctx', computed: { doubled: '{{(_local.n ?? 0) * 2}}' }, components: [modalDef] };
      const t = createLayoutTest(layout, {
        componentRegistry: registry(),
        includeModals: true,
        routeParams: { id: '7' },
        initialState: { _local: { n: 1 }, _global: { modalStack: ['ctx_modal'], deleting: true } },
      } as any);
      await t.render();

      expect(text('body_probe')).toBe(c.expected);
      expect(text('footer_probe')).toBe(text('body_probe'));

      fireEvent.click(document.getElementById('bump')!);
      await new Promise((resolve) => setTimeout(resolve, 50));

      expect(text('body_probe')).toBe(c.expected.replace('/1/2/', '/5/10/'));
      expect(text('footer_probe')).toBe(text('body_probe'));
      t.cleanup();
    });
  }

  // 회귀(#869 8차): 고정 하단을 skipBindingKeys 로 원문 그대로 그리게 된 뒤, 하단 단추 글자의 식 안 `$t:`(따옴표 없음)가 하단 렌더러에서
  // 풀렸는데 Modal 이 넘기던 번역 문맥(`__translationContext`)은 엔진이 주지 않는 값이라 비어 있었다 — getTranslation 이 templateId 를
  // 구조 분해하다 던져 단추가 「컴포넌트 로드 실패」 로 그려졌다(관리자 회원 목록 「활성화」 확인 창)
  // @scenario open_path=direct_stack
  // @effects footer_and_body_share_data_context
  it('고정 하단 단추 글자의 식 안 $t: 가 본문과 같은 번역 문맥으로 풀린다', async () => {
    const errors: unknown[] = [];
    const originalError = console.error;
    console.error = (...args: unknown[]) => { errors.push(args[0]); };
    try {
      const label = (id: string) => ({ id, type: 'basic', name: 'Span', props: { id }, text: '{{_global.busy ? $t:common.processing : $t:common.confirm}}' });
      const modal = { id: 'tr_modal', type: 'composite', name: 'Modal', props: { title: 't', footer: [{ id: 'ok', type: 'basic', name: 'Button', props: { id: 'ok' }, children: [label('footer_label')] }] }, children: [label('body_label')] };
      const t = createLayoutTest({ version: '1.0.0', layout_name: 'tr', components: [], modals: [modal] } as any, {
        componentRegistry: registry(),
        includeModals: true,
        translations: { common: { processing: '처리 중', confirm: '확인' } },
        initialState: { _global: { modalStack: ['tr_modal'], busy: false } },
      } as any);
      await t.render();

      expect(text('body_label')).toBe('확인');
      expect(text('footer_label')).toBe('확인');
      expect(document.body.textContent).not.toContain('컴포넌트 로드 실패');
      t.cleanup();
    } finally {
      console.error = originalError;
    }
  });
});
