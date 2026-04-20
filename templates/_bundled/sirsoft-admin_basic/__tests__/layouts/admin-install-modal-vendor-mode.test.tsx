/**
 * @file admin-install-modal-vendor-mode.test.tsx
 * @description 모듈/플러그인 설치 모달의 vendor_mode Select 필드 레이아웃 구조 회귀 테스트
 *
 * 테스트 대상:
 * - templates/.../partials/admin_module_list/_modal_install.json
 * - templates/.../partials/admin_plugin_list/_modal_install.json
 *
 * 검증 항목:
 * - vendor_mode Select 컴포넌트 존재
 * - options 배열에 auto/composer/bundled 3종 포함
 * - apiCall body 에 vendor_mode 바인딩 포함
 * - change 액션이 _global.{module|plugin}InstallVendorMode 로 setState
 * - 다국어 키 ($t:admin.modules.modals.vendor_mode_* / admin.plugins.modals.vendor_mode_*) 사용
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

const repoRoot = path.resolve(__dirname, '../../../../..');

interface LayoutNode {
  type?: string;
  name?: string;
  props?: Record<string, any>;
  children?: LayoutNode[];
  text?: string;
  actions?: Array<{
    type?: string;
    handler: string;
    params?: Record<string, any>;
    target?: string;
    onSuccess?: any[];
    onError?: any[];
    actions?: any[];
  }>;
}

/**
 * 트리 순회 헬퍼 — 조건을 만족하는 모든 노드를 수집한다.
 */
function findAll(node: LayoutNode | undefined, predicate: (n: LayoutNode) => boolean): LayoutNode[] {
  if (!node) return [];
  const result: LayoutNode[] = [];
  if (predicate(node)) result.push(node);
  if (Array.isArray(node.children)) {
    for (const child of node.children) {
      result.push(...findAll(child, predicate));
    }
  }
  // actions 내부 sequence 의 중첩 actions 도 탐색
  if (Array.isArray(node.actions)) {
    for (const action of node.actions) {
      if (Array.isArray((action as any).actions)) {
        for (const sub of (action as any).actions) {
          // sequence sub-actions — apiCall body 등 탐색 대상에 포함
          result.push(...findAll(sub as LayoutNode, predicate));
        }
      }
    }
  }
  return result;
}

function loadLayout(relativePath: string): LayoutNode {
  const filePath = path.join(repoRoot, relativePath);
  const content = fs.readFileSync(filePath, 'utf8');
  return JSON.parse(content);
}

describe('admin install modals — vendor_mode Select 필드', () => {
  describe('모듈 설치 모달 (_modal_install.json)', () => {
    const layout = loadLayout(
      'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_module_list/_modal_install.json'
    );

    it('Select 컴포넌트가 존재한다', () => {
      const selects = findAll(layout, (n) => n.name === 'Select');
      expect(selects.length).toBeGreaterThanOrEqual(1);
    });

    it('vendor_mode options 가 auto/composer/bundled 3종을 포함한다', () => {
      const selects = findAll(
        layout,
        (n) => n.name === 'Select' && Array.isArray(n.props?.options),
      );
      const vendorSelect = selects.find((s) =>
        (s.props?.options ?? []).some((opt: any) => opt?.value === 'auto'),
      );

      expect(vendorSelect).toBeDefined();
      const values = (vendorSelect!.props!.options as Array<{ value: string }>).map((o) => o.value);
      expect(values).toContain('auto');
      expect(values).toContain('composer');
      expect(values).toContain('bundled');
    });

    it('vendor_mode Select 가 _global.moduleInstallVendorMode 를 value 로 바인딩한다', () => {
      const selects = findAll(layout, (n) => n.name === 'Select');
      const vendorSelect = selects.find((s) =>
        typeof s.props?.value === 'string' && s.props.value.includes('moduleInstallVendorMode'),
      );
      expect(vendorSelect).toBeDefined();
    });

    it('vendor_mode change 액션이 _global.moduleInstallVendorMode 로 setState 한다', () => {
      const selects = findAll(layout, (n) => n.name === 'Select');
      const vendorSelect = selects.find((s) =>
        (s.props?.options ?? []).some((opt: any) => opt?.value === 'bundled'),
      );
      expect(vendorSelect).toBeDefined();
      const changeAction = (vendorSelect!.actions ?? []).find(
        (a) => a.type === 'change' && a.handler === 'setState',
      );
      expect(changeAction).toBeDefined();
      expect(changeAction!.params).toMatchObject({
        target: 'global',
        moduleInstallVendorMode: expect.stringContaining('$event.target.value'),
      });
    });

    it('다국어 키 admin.modules.modals.vendor_mode_* 를 사용한다', () => {
      const content = JSON.stringify(layout);
      expect(content).toContain('$t:admin.modules.modals.vendor_mode_label');
      expect(content).toContain('$t:admin.modules.modals.vendor_mode_auto');
      expect(content).toContain('$t:admin.modules.modals.vendor_mode_composer');
      expect(content).toContain('$t:admin.modules.modals.vendor_mode_bundled');
    });

    it('apiCall body 에 vendor_mode 바인딩이 포함된다', () => {
      // sequence 내부 apiCall 찾기
      const content = JSON.stringify(layout);
      expect(content).toContain('"vendor_mode"');
      expect(content).toContain('{{_global.moduleInstallVendorMode');
    });
  });

  describe('플러그인 설치 모달 (_modal_install.json)', () => {
    const layout = loadLayout(
      'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_plugin_list/_modal_install.json'
    );

    it('vendor_mode Select 가 auto/composer/bundled 를 제공한다', () => {
      const selects = findAll(
        layout,
        (n) => n.name === 'Select' && Array.isArray(n.props?.options),
      );
      const vendorSelect = selects.find((s) =>
        (s.props?.options ?? []).some((opt: any) => opt?.value === 'auto'),
      );

      expect(vendorSelect).toBeDefined();
      const values = (vendorSelect!.props!.options as Array<{ value: string }>).map((o) => o.value);
      expect(values).toEqual(expect.arrayContaining(['auto', 'composer', 'bundled']));
    });

    it('_global.pluginInstallVendorMode 를 value 로 바인딩한다', () => {
      const selects = findAll(layout, (n) => n.name === 'Select');
      const vendorSelect = selects.find((s) =>
        typeof s.props?.value === 'string' && s.props.value.includes('pluginInstallVendorMode'),
      );
      expect(vendorSelect).toBeDefined();
    });

    it('change 액션이 _global.pluginInstallVendorMode 로 setState 한다', () => {
      const selects = findAll(layout, (n) => n.name === 'Select');
      const vendorSelect = selects.find((s) =>
        (s.props?.options ?? []).some((opt: any) => opt?.value === 'bundled'),
      );
      const changeAction = (vendorSelect!.actions ?? []).find(
        (a) => a.type === 'change' && a.handler === 'setState',
      );
      expect(changeAction).toBeDefined();
      expect(changeAction!.params).toMatchObject({
        target: 'global',
        pluginInstallVendorMode: expect.stringContaining('$event.target.value'),
      });
    });

    it('다국어 키 admin.plugins.modals.vendor_mode_* 를 사용한다', () => {
      const content = JSON.stringify(layout);
      expect(content).toContain('$t:admin.plugins.modals.vendor_mode_label');
      expect(content).toContain('$t:admin.plugins.modals.vendor_mode_auto');
      expect(content).toContain('$t:admin.plugins.modals.vendor_mode_composer');
      expect(content).toContain('$t:admin.plugins.modals.vendor_mode_bundled');
    });

    it('apiCall body 에 vendor_mode 바인딩이 포함된다', () => {
      const content = JSON.stringify(layout);
      expect(content).toContain('"vendor_mode"');
      expect(content).toContain('{{_global.pluginInstallVendorMode');
    });
  });
});
