/**
 * @file admin-settings-drivers-public-disk-hardening.test.tsx
 * @description 드라이버 탭 「공개 자산 스토리지」 카드 — 내보낼 확장자 입력칸과 웹서버 보호 규칙 경고
 *
 * 공개 디스크의 직접 주소(`/storage`) 응답은 서버를 거치지 않아 웹서버 규칙으로만 보호 헤더가 붙는다.
 * 서버가 저장 직후 그 헤더를 점검해 설정 메타(`_meta.public_disk_hardening`)에 싣고, 화면은 보호가 걸리지
 * 않았을 때(`ok === false`)만 경고를 보여 준다. 점검하지 못했거나(`ok === null`) 결과가 없으면 조용하다.
 */

// e2e:allow 입력칸 한 줄과 조건부 안내 문구 추가 — 실 브라우저 동작은 관리자 환경설정 실측 매트릭스(드라이버 탭 저장·경고 표시)로 확인한다

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

const driversPartial = JSON.parse(
  readFileSync(resolve(__dirname, '../../layouts/partials/admin_settings/_tab_drivers.json'), 'utf-8')
);

const TestDiv: React.FC<any> = ({ className, children, 'data-testid': testId }) => (
  <div className={className} data-testid={testId}>
    {children}
  </div>
);
const TestInput: React.FC<any> = ({ name, type, placeholder }) => (
  <input name={name} type={type} placeholder={placeholder} data-testid={name} />
);
const TestSelect: React.FC<any> = ({ name }) => <select name={name} data-testid={`select-${name}`} />;
const TestSpan: React.FC<any> = ({ children, text }) => <span>{children || text}</span>;
const TestP: React.FC<any> = ({ children, text }) => <p>{children || text}</p>;
const TestLabel: React.FC<any> = ({ children, text }) => <label>{children || text}</label>;
const TestH3: React.FC<any> = ({ children, text }) => <h3>{children || text}</h3>;
const TestA: React.FC<any> = ({ href, children, text }) => <a href={href}>{children || text}</a>;
const TestFragment: React.FC<any> = ({ children }) => <>{children}</>;

/**
 * 테스트용 컴포넌트 레지스트리를 구성합니다.
 *
 * @returns 구성된 레지스트리
 */
function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();

  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
    Select: { component: TestSelect, metadata: { name: 'Select', type: 'composite' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    Label: { component: TestLabel, metadata: { name: 'Label', type: 'basic' } },
    H3: { component: TestH3, metadata: { name: 'H3', type: 'basic' } },
    A: { component: TestA, metadata: { name: 'A', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };

  return registry;
}

/**
 * id 로 노드를 깊이 우선 탐색합니다.
 *
 * @param node 탐색 시작 노드
 * @param id 찾을 노드 id
 * @returns 찾은 노드 또는 null
 */
function findNodeById(node: any, id: string): any {
  if (!node || typeof node !== 'object') return null;
  if (node.id === id) return node;
  for (const child of node.children ?? []) {
    const found = findNodeById(child, id);
    if (found) return found;
  }
  return null;
}

/**
 * 주어진 설정 메타로 「공개 자산 스토리지」 카드를 렌더합니다.
 *
 * @param hardening `_meta.public_disk_hardening` 값
 * @returns 레이아웃 테스트 유틸
 */
function renderCard(hardening: unknown) {
  let card: any = null;
  for (const root of driversPartial.components ?? [driversPartial]) {
    card = findNodeById(root, 'card_public_asset_settings');
    if (card) break;
  }
  expect(card).not.toBeNull();

  return createLayoutTest(
    {
      version: '1.0.0',
      layout_name: 'test_drivers_public_disk_hardening',
      components: [card],
    } as any,
    {
      initialState: {
        _local: {
          form: {
            drivers: { public_asset_disk: 'public', public_asset_extensions: 'jpg,jpeg,png,gif,webp,avif' },
            available_drivers: { public_asset: [{ id: 'public', label: { ko: '로컬 공개 디스크' } }] },
            _meta: { public_disk_hardening: hardening },
          },
          errors: {},
        },
      },
    }
  );
}

// @scenario case=self_check__not_exportable
// @effects drivers_tab_warns_only_when_self_check_fails
describe('드라이버 탭 공개 디스크 보호 규칙 경고', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  afterEach(() => {
    (registry as any).registry = {};
  });

  // @scenario case=exportable_extensions_field
  // @effects drivers_tab_shows_exportable_extensions_field
  it('내보낼 확장자 입력칸이 카드에 있다', async () => {
    const testUtils = renderCard(null);
    await testUtils.render();

    const input = screen.getByTestId('drivers.public_asset_extensions');
    expect(input).toBeInTheDocument();
    expect(input.getAttribute('placeholder')).toBe('jpg,jpeg,png,gif,webp,avif');

    testUtils.cleanup();
  });

  // @scenario case=self_check__not_exportable
  // @effects drivers_tab_warns_only_when_self_check_fails
  it('점검 결과가 실패(ok=false)면 경고와 설치 문서 링크를 보여 준다', async () => {
    const testUtils = renderCard({ ok: false, nosniff: false, disposition: false, status: 200, checked_at: '2026-11-10T12:00:00+09:00' });
    await testUtils.render();

    const warning = screen.getByTestId('public-disk-hardening-warning');
    expect(warning).toBeInTheDocument();
    expect(warning.querySelector('a')?.getAttribute('href')).toContain('docs/requirements.md');

    testUtils.cleanup();
  });

  // @scenario case=self_check__not_exportable
  // @effects drivers_tab_warns_only_when_self_check_fails
  it('점검 결과가 정상(ok=true)이면 경고가 없다', async () => {
    // 존재를 먼저 확정한 뒤에 부재를 단언한다
    const failing = renderCard({ ok: false, nosniff: false, disposition: false, status: 200, checked_at: 'x' });
    await failing.render();
    expect(screen.getByTestId('public-disk-hardening-warning')).toBeInTheDocument();
    failing.cleanup();

    const passing = renderCard({ ok: true, nosniff: true, disposition: true, status: 200, checked_at: 'x' });
    await passing.render();
    expect(screen.queryByTestId('public-disk-hardening-warning')).toBeNull();
    expect(screen.getByTestId('drivers.public_asset_extensions')).toBeInTheDocument();
    passing.cleanup();
  });

  // @scenario case=settings_save__operator_added
  // @effects drivers_tab_warns_when_rules_write_failed
  it('규칙 파일을 쓰지 못했으면(rules_written=false) 헤더 점검이 정상이어도 쓰기 실패 경고를 보여 준다', async () => {
    const testUtils = renderCard({ ok: true, nosniff: true, disposition: true, status: 200, checked_at: 'x', rules_written: false });
    await testUtils.render();

    const warning = screen.getByTestId('public-disk-rules-write-failed-warning');
    expect(warning).toBeInTheDocument();
    expect(warning.textContent).toContain('admin.settings.drivers.public_asset.hardening_rules_write_failed');
    expect(screen.queryByTestId('public-disk-hardening-warning')).toBeNull();

    testUtils.cleanup();
  });

  // @scenario case=settings_save__exportable
  // @effects drivers_tab_warns_when_rules_write_failed
  it('공개 디스크를 쓰지 않아 헤더 점검을 하지 않았어도(ok=null) 쓰기 실패 경고는 보여 준다', async () => {
    const testUtils = renderCard({ ok: null, nosniff: false, disposition: false, status: null, checked_at: 'x', rules_written: false });
    await testUtils.render();

    expect(screen.getByTestId('public-disk-rules-write-failed-warning')).toBeInTheDocument();

    testUtils.cleanup();
  });

  // @scenario case=settings_save__operator_added
  // @effects drivers_tab_warns_when_rules_write_failed
  it('규칙 파일을 썼거나(rules_written=true) 그 값이 없는 옛 결과면 쓰기 실패 경고가 없다', async () => {
    const failing = renderCard({ ok: true, nosniff: true, disposition: true, status: 200, checked_at: 'x', rules_written: false });
    await failing.render();
    expect(screen.getByTestId('public-disk-rules-write-failed-warning')).toBeInTheDocument();
    failing.cleanup();

    for (const hardening of [
      { ok: true, nosniff: true, disposition: true, status: 200, checked_at: 'x', rules_written: true },
      { ok: true, nosniff: true, disposition: true, status: 200, checked_at: 'x' },
      null,
    ]) {
      const testUtils = renderCard(hardening);
      await testUtils.render();
      expect(screen.getByTestId('drivers.public_asset_extensions')).toBeInTheDocument();
      expect(screen.queryByTestId('public-disk-rules-write-failed-warning')).toBeNull();
      testUtils.cleanup();
    }
  });

  // @scenario case=self_check__not_exportable
  // @effects drivers_tab_warns_only_when_self_check_fails
  it('점검하지 못했거나(ok=null) 결과가 없으면 경고가 없다', async () => {
    for (const hardening of [{ ok: null, nosniff: false, disposition: false, status: null, checked_at: 'x' }, null, undefined]) {
      const testUtils = renderCard(hardening);
      await testUtils.render();
      expect(screen.getByTestId('drivers.public_asset_extensions')).toBeInTheDocument();
      expect(screen.queryByTestId('public-disk-hardening-warning')).toBeNull();
      testUtils.cleanup();
    }
  });
});
