/**
 * @file admin-language-pack-drift.test.tsx
 * @description 언어팩 목록 드리프트(files_missing) 배지 + 원클릭 재설치 배선 회귀 가드 (이슈 #496 Part B3)
 *
 * 실제 partial(_content.json)의 선언형 노드를 읽어, DB active 인데 설치본 파일이 없는
 * 드리프트 행에 대해 (1) "파일 없음" 배지, (2) install-from-bundled 모달을 재사용하는
 * 재설치 버튼이 files_missing 조건으로 배선돼 있는지 검증한다.
 *
 * @effects admin_list_shows_drift_badge_and_reinstall,
 *          bundled_source_availability_flagged_independently_of_source_type,
 *          third_party_pack_without_bundle_source_not_offered_for_reinstall
 */

import React from 'react';
import { describe, it, expect, beforeEach } from 'vitest';
import fs from 'fs';
import path from 'path';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

const CONTENT_PATH = path.resolve(
  __dirname,
  '../../layouts/partials/admin_language_pack_list/_content.json',
);

/**
 * 노드 트리를 재귀 순회하며 조건에 맞는 첫 노드를 찾는다.
 */
function findNode(node: any, predicate: (n: any) => boolean): any | null {
  if (!node || typeof node !== 'object') return null;
  if (Array.isArray(node)) {
    for (const child of node) {
      const found = findNode(child, predicate);
      if (found) return found;
    }
    return null;
  }
  if (predicate(node)) return node;
  for (const key of Object.keys(node)) {
    const found = findNode(node[key], predicate);
    if (found) return found;
  }
  return null;
}

describe('admin language pack list — 드리프트(files_missing) 배선', () => {
  const layout = JSON.parse(fs.readFileSync(CONTENT_PATH, 'utf8'));

  it('files_missing 배지 노드가 존재한다', () => {
    const badge = findNode(
      layout,
      (n) =>
        typeof n.if === 'string' &&
        n.if.includes('row.files_missing === true') &&
        n.text === '$t:admin.language_packs.badge.files_missing',
    );
    expect(badge).not.toBeNull();
  });

  it('재설치 버튼이 번들 드리프트 행 조건으로 배선되어 install-bundled 모달을 재사용한다', () => {
    const button = findNode(
      layout,
      (n) =>
        n.name === 'Button' &&
        typeof n.if === 'string' &&
        n.if.includes('row.files_missing === true') &&
        n.if.includes('row.bundled_source_available === true') &&
        n.if.includes('row.abilities?.can_install === true'),
    );
    expect(button).not.toBeNull();

    // 재설치 액션이 selectedBundledLanguagePack 설정 + install-bundled 모달 오픈으로 배선됨
    const opensBundledModal = findNode(
      button,
      (n) => n.handler === 'openModal' && n.target === 'language_pack_install_bundled_modal',
    );
    expect(opensBundledModal).not.toBeNull();

    const setsSelected = findNode(
      button,
      (n) =>
        n.handler === 'setState' &&
        n.params &&
        n.params.target === 'global' &&
        n.params.selectedBundledLanguagePack === '{{row}}',
    );
    expect(setsSelected).not.toBeNull();

    // 재설치는 "복구" 이므로 활성 상태를 유지해야 한다 → 재설치 모드 플래그 ON.
    // 이 플래그가 빠지면 모달이 auto_activate=false 로 보내 팩이 강등되고,
    // 파일은 복구됐는데 해당 로케일은 계속 서빙되지 않는 상태가 된다.
    expect(setsSelected.params.languagePackReinstallMode).toBe(true);
  });

  it('신규 설치 버튼은 재설치 모드를 끈다 (활성화 여부는 운영자 결정)', () => {
    const installButton = findNode(
      layout,
      (n) => n.name === 'Button' && n.if === "{{row.status === 'uninstalled'}}",
    );
    expect(installButton).not.toBeNull();

    const setsSelected = findNode(
      installButton,
      (n) => n.handler === 'setState' && n.params?.selectedBundledLanguagePack === '{{row}}',
    );
    expect(setsSelected.params.languagePackReinstallMode).toBe(false);
  });

  it('설치 모달이 재설치 모드와 활성화 권한을 함께 보고 auto_activate 를 보낸다', () => {
    const modal = JSON.parse(
      fs.readFileSync(
        path.resolve(__dirname, '../../layouts/partials/admin_language_pack_list/_modal_install_bundled.json'),
        'utf8',
      ),
    );

    const apiCall = findNode(
      modal,
      (n) => n.handler === 'apiCall' && String(n.target).includes('install-from-bundled'),
    );
    expect(apiCall).not.toBeNull();

    const expression = apiCall.params.body.auto_activate;

    // 재설치는 "복구" 이므로 활성 상태를 유지해야 한다 → 재설치 모드가 조건에 남아 있어야 한다.
    expect(expression).toContain('_global.languagePackReinstallMode === true');

    // 서버가 auto_activate=true 에 활성화 권한(core.language_packs.manage)을 요구하므로,
    // 권한 없는 운영자에게 이 값을 실어 보내면 422 로 재설치 자체가 막힌다 —
    // 화면에는 이 플래그를 끄는 수단이 없어 막다른 길이 된다.
    // 권한이 없으면 보내지 않아야 재설치는 되고 팩만 installed 로 내려간다.
    expect(expression).toContain("selectedBundledLanguagePack?.abilities?.can_activate === true");

    // 두 조건은 AND 여야 한다 (하나라도 OR 이면 권한 없는 계정이 true 를 보낸다).
    expect(expression).toBe(
      '{{_global.languagePackReinstallMode === true && _global.selectedBundledLanguagePack?.abilities?.can_activate === true}}',
    );
  });
});

// ─── 렌더 평가 (createLayoutTest) ──────────────────────────────────────
// 구조 가드(위)는 배선 존재만 보장한다. 아래는 실제 _content.json 의 조건식을
// 그대로 옮겨 DynamicRenderer + ConditionEvaluator 로 평가해, 드리프트 행에서만
// 배지/재설치가 뜨고 정상 행/비-번들 행/권한 부재에서는 뜨지 않음을 검증한다.

const TestDiv: React.FC<any> = ({ className, children, 'data-testid': testId }) => (
  <div className={className} data-testid={testId}>{children}</div>
);
const TestSpan: React.FC<any> = ({ className, children, text, 'data-testid': testId }) => (
  <span className={className} data-testid={testId}>{children || text}</span>
);
const TestButton: React.FC<any> = ({ className, disabled, children, text, onClick, 'data-testid': testId }) => (
  <button className={className} disabled={disabled} onClick={onClick} data-testid={testId}>{children || text}</button>
);
const TestFragment: React.FC<any> = ({ children }) => <>{children}</>;

function setupRegistry(): void {
  const registry = ComponentRegistry.getInstance();
  (registry as any).registry = {
    // DynamicRenderer 는 루트를 Fragment 로 감싸므로 Fragment 미등록 시 자식이 렌더되지 않는다.
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
  };
}

// _content.json 의 실제 조건식을 그대로 사용 (badge/reinstall)
const BADGE_IF = '{{row.files_missing === true}}';
const REINSTALL_IF =
  '{{row.files_missing === true && row.bundled_source_available === true && row.abilities?.can_install === true}}';

function driftLayout(canInstall: boolean) {
  return {
    version: '1.0.0',
    layout_name: `lp_drift_render_${canInstall ? 'can' : 'cannot'}`,
    data_sources: [
      {
        id: 'language_packs',
        type: 'api',
        endpoint: '/api/admin/language-packs',
        method: 'GET',
        auto_fetch: true,
        fallback: { data: { data: [], meta: { total: 0 }, abilities: {} } },
      },
    ],
    state: {},
    components: [
      {
        id: 'rows',
        type: 'basic',
        name: 'Div',
        iteration: { source: 'language_packs?.data?.data', item_var: 'row' },
        children: [
          {
            id: 'badge',
            type: 'basic',
            name: 'Span',
            if: BADGE_IF,
            props: { 'data-testid': 'badge-{{row.identifier}}' },
            text: 'files-missing',
          },
          {
            id: 'reinstall',
            type: 'basic',
            name: 'Button',
            if: REINSTALL_IF,
            props: { 'data-testid': 'reinstall-{{row.identifier}}' },
            text: 'reinstall',
          },
        ],
      },
    ],
  };
}

const ROWS = [
  // 드리프트 + 번들 소스 있음 → 배지 O, 재설치 O(권한 있을 때)
  {
    id: 1,
    identifier: 'drift-bundled',
    files_missing: true,
    source_type: 'bundled',
    bundled_source_available: true,
    status: 'active',
    abilities: { can_install: true },
  },
  // 정상(파일 있음) → 배지 X, 재설치 X
  {
    id: 2,
    identifier: 'healthy-bundled',
    files_missing: false,
    source_type: 'bundled',
    bundled_source_available: true,
    status: 'active',
    abilities: { can_install: true },
  },
  // zip 으로 설치됐지만 동일 식별자의 번들 소스가 있음 → 복구 가능하므로 재설치 O
  // (게이트를 source_type 에 걸면 이 행이 "보이기만 하고 못 고치는" 상태로 남는다)
  {
    id: 3,
    identifier: 'drift-zip-with-bundle',
    files_missing: true,
    source_type: 'zip',
    bundled_source_available: true,
    status: 'active',
    abilities: { can_install: true },
  },
  // 번들 소스가 없는 서드파티 팩(github) → 배지 O, 재설치 X (복구 소스 자체가 없음)
  {
    id: 4,
    identifier: 'drift-thirdparty',
    files_missing: true,
    source_type: 'github',
    bundled_source_available: false,
    status: 'active',
    abilities: { can_install: true },
  },
];

describe('admin language pack list — 드리프트 렌더 평가 (createLayoutTest)', () => {
  beforeEach(() => {
    setupRegistry();
  });

  it('files_missing=true 행에만 "파일 없음" 배지가 렌더된다', async () => {
    const utils = createLayoutTest(driftLayout(true));
    utils.mockApi('language_packs', {
      response: { data: { data: ROWS, meta: { total: ROWS.length }, abilities: { can_install: true } } },
    });

    await utils.render();

    expect(screen.getByTestId('badge-drift-bundled')).toBeInTheDocument();
    expect(screen.getByTestId('badge-drift-zip-with-bundle')).toBeInTheDocument();
    // 복구 소스가 없는 서드파티 드리프트도 "발견" 은 되어야 한다
    expect(screen.getByTestId('badge-drift-thirdparty')).toBeInTheDocument();
    expect(screen.queryByTestId('badge-healthy-bundled')).not.toBeInTheDocument();

    utils.cleanup();
  });

  it('재설치 버튼은 드리프트 + 번들 소스 실재 + can_install 인 행에만 렌더된다', async () => {
    const utils = createLayoutTest(driftLayout(true));
    utils.mockApi('language_packs', {
      response: { data: { data: ROWS, meta: { total: ROWS.length }, abilities: { can_install: true } } },
    });

    await utils.render();

    // 드리프트 + 번들 소스 → O
    expect(screen.getByTestId('reinstall-drift-bundled')).toBeInTheDocument();
    // zip 설치분이라도 번들 소스가 있으면 복구 가능 → O
    expect(screen.getByTestId('reinstall-drift-zip-with-bundle')).toBeInTheDocument();
    // 정상 → files_missing=false 로 X
    expect(screen.queryByTestId('reinstall-healthy-bundled')).not.toBeInTheDocument();
    // 번들 소스 부재(서드파티) → 복구 소스가 없으므로 X
    expect(screen.queryByTestId('reinstall-drift-thirdparty')).not.toBeInTheDocument();

    utils.cleanup();
  });

  it('행 권한 can_install=false 면 드리프트 번들 행이어도 재설치 버튼이 렌더되지 않는다', async () => {
    // 게이트는 행 단위 권한(row.abilities)이다 — 컬렉션 권한은 행 컨텍스트에서 해석되지 않으므로
    // 그쪽에 걸면 조건이 상시 false 가 되어 버튼이 영영 뜨지 않는다(브라우저 실측에서 확인).
    const rowsWithoutPermission = ROWS.map((r) => ({ ...r, abilities: { can_install: false } }));
    const utils = createLayoutTest(driftLayout(false));
    utils.mockApi('language_packs', {
      response: {
        data: { data: rowsWithoutPermission, meta: { total: rowsWithoutPermission.length }, abilities: { can_install: true } },
      },
    });

    await utils.render();

    // 배지는 권한과 무관하게 뜬다
    expect(screen.getByTestId('badge-drift-bundled')).toBeInTheDocument();
    // 권한 부재 → 재설치 버튼 전부 X
    expect(screen.queryByTestId('reinstall-drift-bundled')).not.toBeInTheDocument();

    utils.cleanup();
  });
});
