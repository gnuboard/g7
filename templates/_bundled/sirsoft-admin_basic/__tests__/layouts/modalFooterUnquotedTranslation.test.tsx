/**
 * @file modalFooterUnquotedTranslation.test.tsx
 * @description 고정 하단(`props.footer`)에 따옴표 없는 `$t:` 식을 쓴 번들 창을 실물 엔진·진짜 Modal 로 전부 그려, 하단이 오류 없이 번역돼 그려지는지 (#869 8차)
 *
 * 고정 하단을 skipBindingKeys 로 원문 그대로 그리게 된 뒤, 하단 단추 글자 식 안의 `$t:` 를 하단 렌더러가 번역 문맥 없이 풀다가
 * getTranslation 이 templateId 를 구조 분해하며 던졌다(관리자 회원 목록 「활성화」 확인 창 — 확인 단추가 「컴포넌트 로드 실패 [Button]」).
 * 엔진이 번역 문맥이 빠진 렌더러에 부모 렌더 환경의 번역 문맥을 이어 준다. 대상은 따옴표 없는 `$t:` 를 하단에 쓴 13곳(총괄 목록)을 품은 파일이다.
 *
 * @scenario-manifest tests/scenarios/modal-footer-context-parity.yaml
 *
 * @vitest-environment jsdom
 */
import React from 'react';
import { describe, expect, it, vi } from 'vitest';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import { Modal } from '../../src/components/composite/Modal';
import componentsManifest from '../../components.json';

vi.mock('react', async () => {
  const p = await import('path');
  const u = await import('url');
  const dir = p.dirname(u.fileURLToPath(import.meta.url));

  return import(/* @vite-ignore */ u.pathToFileURL(p.resolve(dir, '../../../../../node_modules/react/index.js')).href);
});

const SITES: Record<string, any> = import.meta.glob(['../../layouts/admin_user_list.json', '../../layouts/partials/admin_menu_list/_modal_bulk_roles_confirm.json', '../../layouts/partials/admin_role_list/_modal_delete.json', '../../layouts/partials/admin_user_list/_modal_bulk_roles.json', '../../layouts/partials/_modal_notification_delete_all_confirm.json', '../../../../../modules/_bundled/sirsoft-board/resources/layouts/admin/admin_board_reports_detail.json'], { eager: true, import: 'default' });

const modalMetadata: Record<string, any> = (componentsManifest as any).components.composite.find((c: any) => c.name === 'Modal');

/** 진짜 Modal — 하네스 G7Core 에 엔진 렌더러 접근자를 잇는다 */
const RealModal: React.FC<any> = (props) => {
  const g7 = (window as any).G7Core;
  if (g7 && typeof g7.getDynamicRenderer !== 'function') {
    g7.getDynamicRenderer = () => (window as any).__DynamicRenderer ?? null;
  }

  return <Modal {...props} isOpen onClose={props.onClose ?? (() => {})} />;
};

/** 하단 표면을 그리는 데 필요한 기본 부품 — 글자와 자식만 그린다 */
const passthrough = (tag: string): React.FC<any> => ({ text, children, id }) => React.createElement(tag, { id }, children ?? text);

/**
 * 정의 트리에서 하단이 있는 Modal 노드를 모은다.
 *
 * @param node 레이아웃 JSON
 * @param out 모은 노드
 * @returns 모은 노드
 */
function collectFooterModals(node: unknown, out: any[] = []): any[] {
  if (Array.isArray(node)) {
    node.forEach((n) => collectFooterModals(n, out));
  } else if (node && typeof node === 'object') {
    const n = node as any;
    if (n.name === 'Modal' && Array.isArray(n.props?.footer) && n.props.footer.length > 0) out.push(n);
    Object.values(n).forEach((v) => collectFooterModals(v, out));
  }

  return out;
}

/**
 * 트리에 나오는 부품 이름을 모은다.
 *
 * @param node 정의
 * @param out 이름 집합
 * @returns 이름 집합
 */
function collectNames(node: unknown, out = new Set<string>()): Set<string> {
  if (Array.isArray(node)) node.forEach((n) => collectNames(n, out));
  else if (node && typeof node === 'object') {
    const n = node as any;
    if (typeof n.name === 'string' && typeof n.type === 'string') out.add(n.name);
    Object.values(n).forEach((v) => collectNames(v, out));
  }

  return out;
}

const cases = Object.entries(SITES).flatMap(([file, json]) => collectFooterModals(json).map((modal) => ({ file, modal })));

describe('고정 하단에 따옴표 없는 $t: 를 쓴 번들 창 — 실물 엔진 렌더', () => {
  // @scenario open_path=direct_stack
  // @effects footer_and_body_share_data_context
  it('대상 창을 실제로 모았다(빈 모집단 방지)', () => {
    expect(cases.length).toBeGreaterThanOrEqual(10);
  });

  for (const { file, modal } of cases) {
    // @scenario open_path=direct_stack
    // @effects footer_and_body_share_data_context
    it(`${file.split('/').slice(-1)[0]} — ${modal.id}: 고정 하단이 오류 없이 번역돼 그려진다`, async () => {
      const errors: string[] = [];
      const originalError = console.error;
      console.error = (...args: unknown[]) => { errors.push(args.map(String).join(' ')); };
      try {
        const r = ComponentRegistry.getInstance();
        const entries: Record<string, any> = {};
        for (const name of collectNames(modal)) {
          entries[name] = { component: passthrough(name === 'Button' ? 'button' : 'span'), metadata: { name, type: 'basic' } };
        }
        entries.Fragment = { component: ({ children }: any) => <>{children}</>, metadata: { name: 'Fragment', type: 'layout' } };
        entries.Modal = { component: RealModal, metadata: { ...modalMetadata, name: 'Modal', type: 'composite' } };
        (r as any).registry = entries;
        const t = createLayoutTest({ version: '1.0.0', layout_name: 'footer_sweep', components: [], modals: [JSON.parse(JSON.stringify(modal))] } as any, {
          componentRegistry: r,
          includeModals: true,
          translations: { common: { confirm: '확인', cancel: '취소', processing: '처리 중', delete: '삭제', close: '닫기' } },
          initialState: { _global: { modalStack: [modal.id] } },
        } as any);
        await t.render();

        const footer = document.querySelector('.modal-footer-bar');
        expect(footer, '고정 하단이 그려지지 않았습니다').not.toBeNull();
        expect(document.body.textContent).not.toContain('컴포넌트 로드 실패');
        expect(footer!.textContent).not.toContain('$t:');
        expect(errors.filter((e) => /templateId/.test(e))).toEqual([]);
        t.cleanup();
      } finally {
        console.error = originalError;
      }
    });
  }
});
