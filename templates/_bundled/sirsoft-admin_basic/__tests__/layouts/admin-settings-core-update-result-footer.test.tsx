/**
 * @file admin-settings-core-update-result-footer.test.tsx
 * @description 코어 새 버전 확인 결과 창 — 단추가 고정 하단에 있고, 새 버전이 없으면 빈 바닥 줄이 남지 않는다 (#869 C1)
 *
 * 변경 이력 보기와 업데이트 단추는 「업데이트 있음」 갈래 안 본문 줄에 있어 640px 이하에서 쌓이지 않았다. 단추를 Modal 고정
 * 하단으로 옮기고 같은 조건(`$parent._local.coreUpdateInfo.update_available`)을 단추에 달았다. 새 버전이 없으면 단추가 모두
 * 빠지는데, Modal 이 빈 바닥 줄(테두리와 여백)을 숨긴다. 진짜 Modal 과 엔진으로 그린다.
 *
 * 조합과 효과 표식은 그것을 재는 시험마다 단다.
 */
import React from 'react';
import { describe, it, expect, afterEach, vi } from 'vitest';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import { makeHarnessModal } from './harnessModal';
import modalPartial from '../../layouts/partials/admin_settings/_modal_core_update_result.json';

// 엔진(코어)은 저장소 루트의 react 를, 진짜 Modal 은 이 템플릿 node_modules 의 react 를 읽어 사본이 둘이 된다 — 이 파일에서만
// 템플릿 쪽 react 를 루트 사본으로 바꿔 낀다(productFormRouteSwitchInputResidue.test.tsx 와 같은 방식)
vi.mock('react', async () => {
  const p = await import('path');
  const u = await import('url');
  const dir = p.dirname(u.fileURLToPath(import.meta.url));

  return import(/* @vite-ignore */ u.pathToFileURL(p.resolve(dir, '../../../../../node_modules/react/index.js')).href);
});

const Plain: React.FC<any> = ({ className, children, text }) => <div className={className}>{children ?? text}</div>;
const TestButton: React.FC<any> = ({ onClick, disabled, className, children, text }) => (
  <button type="button" onClick={onClick} disabled={disabled} className={className}>
    {children ?? text}
  </button>
);

function setupRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  const basic = (name: string, component: React.FC<any>) => ({ component, metadata: { name, type: 'basic' } });
  const names = ['Div', 'P', 'Span', 'Icon', 'Table', 'Thead', 'Tbody', 'Tr', 'Th', 'Td', 'Ul', 'Li', 'Strong', 'Code', 'H3', 'H4', 'A'];
  (registry as any).registry = {
    ...Object.fromEntries(names.map((n) => [n, basic(n, Plain)])),
    Button: basic('Button', TestButton),
    Modal: { component: makeHarnessModal(), metadata: { name: 'Modal', type: 'composite' } },
    Fragment: { component: ({ children }: any) => <>{children}</>, metadata: { name: 'Fragment', type: 'layout' } },
  };
  return registry;
}

describe('코어 새 버전 확인 결과 창 — 고정 하단 (#869 C1)', () => {
  let testUtils: ReturnType<typeof createLayoutTest> | null = null;

  afterEach(() => {
    testUtils?.cleanup();
    testUtils = null;
  });

  async function renderWith(updateAvailable: boolean) {
    testUtils = createLayoutTest(
      {
        version: '1.0.0',
        layout_name: 'core_update_result_footer_test',
        data_sources: [],
        initLocal: { coreUpdateInfo: { update_available: updateAvailable, current_version: '7.1.0', latest_version: '7.2.0' } },
        modals: [modalPartial],
        components: [{ id: 'main', type: 'basic', name: 'Div', children: [] }],
      } as any,
      { componentRegistry: setupRegistry(), includeModals: true, translations: {}, locale: 'ko' }
    );
    await testUtils.render();
    // 엔진 openModal 핸들러로 연다 — 부모 문맥($parent._local)이 창에 쌓인다(하네스 openModal 은 모달 스택만 바꾼다)
    await testUtils.triggerAction({ type: 'click', handler: 'openModal', target: 'core_update_result_modal' } as any);
    await testUtils.rerender();
  }

  /**
   * @scenario update_available=yes
   * @effects core_update_result_buttons_in_footer
   */
  it('새 버전이 있으면 변경 이력 보기와 업데이트 단추가 고정 하단에 그려진다', async () => {
    expect((modalPartial as any).props.footer.filter((n: any) => n.name === 'Button')).toHaveLength(2);
    await renderWith(true);

    const bar = document.querySelector('.modal-footer-bar') as HTMLElement;
    expect(bar).not.toBeNull();
    expect(bar.querySelectorAll('button')).toHaveLength(2);
    expect(document.querySelector('.modal-body-content')?.querySelectorAll('button').length ?? 0).toBe(0);
  });

  /**
   * @scenario update_available=no
   * @effects core_update_result_empty_footer_hidden
   */
  it('새 버전이 없으면 단추가 모두 빠지고 바닥 줄은 비어 숨김 규칙이 걸린다', async () => {
    await renderWith(false);

    const bar = document.querySelector('.modal-footer-bar') as HTMLElement;
    expect(bar).not.toBeNull();
    expect(bar.querySelectorAll('button')).toHaveLength(0);
    expect(bar.matches(':empty'), '빈 바닥 줄에 다른 노드가 남았습니다').toBe(true);
    expect(bar.className.split(/\s+/)).toContain('empty:hidden');
  });
});
