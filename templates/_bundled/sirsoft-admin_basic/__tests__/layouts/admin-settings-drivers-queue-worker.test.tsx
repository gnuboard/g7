/**
 * @file admin-settings-drivers-queue-worker.test.tsx
 * @description 드라이버 탭 「큐 워커」 구역 렌더 테스트 (#139)
 *
 * 큐 드라이버가 sync 면 워커가 필요 없다는 안내만, 그 밖이면 워커를 돌리는 연결·연결별 큐 입력칸을 그린다.
 * 상태 표는 **저장된** 드라이버 기준 응답(queueStatus)을 그리고, 서버 422 오류 키는 해당 칸 아래에만 단다
 * (연결 간 오류가 섞이지 않는다 — 계산값 queueErrors).
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { screen, within } from '@testing-library/react';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import { DataBindingEngine } from '@core/template-engine/DataBindingEngine';
import adminSettings from '../../layouts/admin_settings.json';
import driversPartial from '../../layouts/partials/admin_settings/_tab_drivers.json';
import adminKo from '../../lang/partial/ko/admin.json';

type Json = Record<string, any>;

/**
 * 태그 이름으로 testid·class·텍스트를 그대로 옮기는 테스트 컴포넌트를 만듭니다.
 *
 * @param Tag 렌더할 HTML 태그
 * @returns 테스트 컴포넌트
 */
function passthrough(Tag: keyof JSX.IntrinsicElements): React.FC<any> {
  return ({ className, children, text, title, 'data-testid': testId }) =>
    React.createElement(Tag, { className, title, 'data-testid': testId }, children ?? text);
}

const TestIcon: React.FC<any> = ({ name }) => <i data-icon={name} />;
const TestBadge: React.FC<any> = ({ text, color }) => <span data-badge={color}>{text}</span>;
const TestTagInput: React.FC<any> = ({ name, value, disabled }) => (
  <div data-tag-input={name ?? 'queue-names'} data-disabled={disabled ? 'true' : 'false'}>
    {(Array.isArray(value) ? value : []).map((chip: string) => (
      <span key={chip} data-chip="">
        {chip}
      </span>
    ))}
  </div>
);

/**
 * 테스트용 컴포넌트 레지스트리를 구성합니다.
 *
 * @returns 구성된 레지스트리
 */
function setupRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  const basic = (name: string, component: React.FC<any>) => ({ component, metadata: { name, type: 'basic' } });

  (registry as any).registry = {
    Div: basic('Div', passthrough('div')),
    P: basic('P', passthrough('p')),
    Span: basic('Span', passthrough('span')),
    H4: basic('H4', passthrough('h4')),
    Label: basic('Label', passthrough('label')),
    Button: basic('Button', passthrough('button')),
    Code: basic('Code', passthrough('code')),
    Table: basic('Table', passthrough('table')),
    Thead: basic('Thead', passthrough('thead')),
    Tbody: basic('Tbody', passthrough('tbody')),
    Tr: basic('Tr', passthrough('tr')),
    Th: basic('Th', passthrough('th')),
    Td: basic('Td', passthrough('td')),
    Icon: basic('Icon', TestIcon),
    Badge: { component: TestBadge, metadata: { name: 'Badge', type: 'composite' } },
    TagInput: { component: TestTagInput, metadata: { name: 'TagInput', type: 'composite' } },
    Fragment: { component: ({ children }: any) => <>{children}</>, metadata: { name: 'Fragment', type: 'layout' } },
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
 * 큐 워커 구역 노드를 찾습니다.
 *
 * @returns 구역 노드
 */
function queueWorkerSection(): Json {
  for (const root of (driversPartial as Json).components ?? [driversPartial]) {
    const found = findNodeById(root, 'queue_worker_section');
    if (found) return found;
  }
  throw new Error('queue_worker_section 노드가 없다');
}

/**
 * 본 레이아웃의 계산값을 주어진 상태로 평가합니다.
 *
 * @param local `_local`
 * @param readOnly 읽기 전용 여부(수정 권한 없음)
 * @returns `_computed` 로 실을 값
 */
function computedFor(local: Json, readOnly: boolean): Json {
  const engine = new DataBindingEngine();
  const context = { _local: local, _global: { settingsAbilities: { can_update: !readOnly } } };
  const computed = (adminSettings as Json).computed as Record<string, string>;

  return Object.fromEntries(
    Object.entries(computed).map(([key, expression]) => [
      key,
      engine.evaluateExpression(expression.replace(/^\{\{|\}\}$/g, ''), context as any),
    ])
  );
}

const STATUS = {
  driver: 'database',
  measured_at: '2026-10-13T00:00:00+09:00',
  heartbeat_stale_after_minutes: 5,
  rows: [
    {
      connection: 'database',
      queue: 'default',
      driver: 'database',
      pending: 3,
      pending_measurable: true,
      last_heartbeat_at: null,
      warnings: ['pending_without_worker'],
      clamped_timeout_count: 2,
      long_queue_hint: true,
    },
    {
      connection: 'redis',
      queue: 'default',
      driver: 'redis',
      pending: null,
      pending_measurable: false,
      last_heartbeat_at: null,
      warnings: ['no_heartbeat'],
      clamped_timeout_count: 0,
      long_queue_hint: false,
    },
  ],
  long_worker: { connection: 'database-long', queue: 'long', status: 'not_registered' },
  commands: [{ connection: 'database', queues: ['default'], command: 'php artisan queue:work database --queue=default' }],
  placement: { timeout_clamped: { count: 2, last: null }, fallback: { count: 0, last: null } },
};

/**
 * 큐 워커 구역을 렌더합니다.
 *
 * @param drivers 폼의 drivers 값
 * @param opts 저장된 드라이버·오류·상태 응답·읽기 전용
 * @returns 레이아웃 테스트 유틸
 */
async function renderSection(
  drivers: Json,
  opts: { savedDriver?: string; errors?: Json; status?: Json | null; readOnly?: boolean; failed?: boolean } = {}
) {
  const local = {
    form: {
      drivers,
      _meta: { queue_connection_options: [{ name: 'database' }, { name: 'database-long' }, { name: 'redis' }] },
    },
    originalDrivers: { queue_driver: opts.savedDriver ?? drivers.queue_driver ?? 'sync' },
    errors: opts.errors ?? {},
  };
  const testUtils = createLayoutTest(
    {
      version: '1.0.0',
      layout_name: 'test_queue_worker',
      computed: (adminSettings as Json).computed,
      components: [queueWorkerSection()],
    } as any,
    {
      componentRegistry: setupRegistry(),
      translations: { admin: adminKo },
      initialState: { _local: local, _global: { settingsAbilities: { can_update: !opts.readOnly }, queueStatusFailed: opts.failed === true } },
      initialData: {
        ...(opts.status === null ? {} : { queueStatus: { data: opts.status ?? STATUS } }),
        _computed: computedFor(local, opts.readOnly === true),
      },
    }
  );
  await testUtils.render();

  return testUtils;
}

describe('드라이버 탭 — 큐 워커 구역', () => {
  let testUtils: Awaited<ReturnType<typeof renderSection>> | undefined;

  beforeEach(() => {
    // 식 안의 $t() 는 런타임처럼 앱 설정에서 템플릿·로케일을 찾는다 — 테스트 번역 묶음(test-template)을 가리킨다
    (window as any).__templateApp = { getConfig: () => ({ templateId: 'test-template', locale: 'ko' }) };
  });

  afterEach(() => {
    testUtils?.cleanup();
    testUtils = undefined;
    delete (window as any).__templateApp;
  });

  // @scenario screen=driver_sync
  // @effects queue_worker_ui_hidden_for_sync
  it('sync 면 「워커 불필요」 안내만 보이고 입력칸·상태 표는 없다', async () => {
    testUtils = await renderSection({ queue_driver: 'sync' }, { status: null });

    expect(screen.getByTestId('queue-worker-section')).toBeInTheDocument();
    expect(screen.getAllByTestId('queue-worker-not-required')).toHaveLength(1);
    expect(screen.queryByTestId('queue-connections-input')).not.toBeInTheDocument();
    expect(screen.queryByTestId('queue-status-table')).not.toBeInTheDocument();
  });

  // @scenario screen=driver_database
  // @effects queue_worker_inputs_per_connection
  it('database 면 연결 입력칸과 등록한 연결마다 큐 입력칸이 보인다', async () => {
    testUtils = await renderSection({
      queue_driver: 'database',
      queue_connections: ['database', 'database-long'],
      queue_names: { database: ['default', 'mail'] },
    });

    expect(screen.queryByTestId('queue-worker-not-required')).not.toBeInTheDocument();
    expect(screen.getByTestId('queue-connections-input')).toBeInTheDocument();
    expect(screen.getByTestId('queue-names-database')).toBeInTheDocument();
    expect(screen.getByTestId('queue-names-database-long')).toBeInTheDocument();
    expect(screen.queryByTestId('queue-names-redis')).not.toBeInTheDocument();
    expect(screen.queryByTestId('queue-default-missing')).not.toBeInTheDocument();
  });

  // @scenario screen=default_queue_chips
  // @effects queue_name_chip_defaults
  it('연결별 큐 키가 없으면 -long 연결은 long, 그 밖은 default 칩을 그린다', async () => {
    testUtils = await renderSection({
      queue_driver: 'database',
      queue_connections: ['database', 'database-long'],
      queue_names: {},
    });

    const chips = (conn: string) =>
      within(screen.getByTestId(`queue-names-${conn}`))
        .queryAllByText(/.+/, { selector: '[data-chip]' })
        .map((el) => el.textContent);
    expect(chips('database')).toEqual(['default']);
    expect(chips('database-long')).toEqual(['long']);
  });

  // @scenario screen=saved_driver_differs
  // @effects saved_driver_notice
  it('폼 드라이버가 저장값과 다르면 「저장 뒤 반영」 안내가 한 번 뜨고, 같으면 뜨지 않는다', async () => {
    testUtils = await renderSection({ queue_driver: 'database' }, { savedDriver: 'sync', status: null });
    expect(screen.getAllByTestId('queue-status-saved-driver-differs')).toHaveLength(1);
    expect(screen.queryByTestId('queue-status-table')).not.toBeInTheDocument();
    testUtils.cleanup();

    testUtils = await renderSection({ queue_driver: 'database' }, { savedDriver: 'database' });
    expect(screen.queryByTestId('queue-status-saved-driver-differs')).not.toBeInTheDocument();
  });

  // @scenario screen=error_routing
  // @effects queue_errors_routed_per_field
  it('서버 오류 키 4형태가 해당 칸 아래에만 붙고 다른 연결로 새지 않는다', async () => {
    testUtils = await renderSection(
      { queue_driver: 'database', queue_connections: ['database', 'database-long', 'redis'], queue_names: {} },
      {
        errors: {
          'drivers.queue_connections.1': ['연결 오류'],
          'drivers.queue_names': ['큐 목록 오류'],
          'drivers.queue_names.database': ['database 큐 오류'],
          'drivers.queue_names.database-long.0': ['long 큐 이름 오류'],
        },
      }
    );

    expect(screen.getByTestId('queue-connections-error')).toHaveTextContent('연결 오류');
    expect(screen.getByTestId('queue-names-root-error')).toHaveTextContent('큐 목록 오류');
    expect(screen.getByTestId('queue-names-error-database')).toHaveTextContent('database 큐 오류');
    expect(screen.getByTestId('queue-names-error-database-long')).toHaveTextContent('long 큐 이름 오류');
    expect(screen.queryByTestId('queue-names-error-redis')).not.toBeInTheDocument();
    expect(screen.getByTestId('queue-names-error-database')).not.toHaveTextContent('long');
  });

  // @scenario screen=not_measurable
  // @effects pending_not_measurable_label
  it('대기 건수를 잴 수 없는 행은 숫자 대신 「측정 불가」를 그린다', async () => {
    testUtils = await renderSection({ queue_driver: 'database' }, { savedDriver: 'database' });

    const pending = screen.getAllByTestId('queue-status-pending').map((el) => el.textContent);
    expect(pending).toEqual(['3', '측정 불가']);
    expect(screen.getAllByTestId('queue-status-row')).toHaveLength(2);
    expect(screen.getAllByTestId('queue-supervisor-command')).toHaveLength(1);
    expect(screen.getByTestId('queue-long-worker-badge')).toHaveTextContent('미등록');
  });

  // @scenario screen=warning_badges
  // @effects queue_warning_badges
  it('경고 배지 4종이 해당 행에만 붙는다', async () => {
    testUtils = await renderSection({ queue_driver: 'database' }, { savedDriver: 'database' });

    const [databaseRow, redisRow] = screen.getAllByTestId('queue-status-row');
    expect(within(databaseRow).getByTestId('queue-warning-pending_without_worker')).toBeInTheDocument();
    expect(within(databaseRow).getByTestId('queue-warning-clamped_timeout')).toHaveTextContent('2');
    expect(within(databaseRow).getByTestId('queue-warning-long_queue_hint')).toBeInTheDocument();
    expect(within(databaseRow).queryByTestId('queue-warning-no_heartbeat')).not.toBeInTheDocument();
    expect(within(redisRow).getByTestId('queue-warning-no_heartbeat')).toHaveTextContent('5');
    expect(within(redisRow).queryByTestId('queue-warning-pending_without_worker')).not.toBeInTheDocument();
    expect(within(redisRow).queryByTestId('queue-warning-clamped_timeout')).not.toBeInTheDocument();
    expect(within(redisRow).queryByTestId('queue-warning-long_queue_hint')).not.toBeInTheDocument();
  });

  // @scenario screen=empty_status
  // @effects queue_status_empty_notice
  it('저장된 드라이버가 큐인데 상태 행이 0건이면 표 대신 빈 안내를 그린다', async () => {
    testUtils = await renderSection({ queue_driver: 'database' }, { savedDriver: 'database', status: { ...STATUS, rows: [], commands: [] } });

    expect(screen.queryByTestId('queue-status-table')).not.toBeInTheDocument();
    expect(screen.queryAllByTestId('queue-status-row')).toHaveLength(0);
    expect(screen.getByTestId('queue-worker-section')).toHaveTextContent(adminKo.settings.drivers.queue.worker.status.empty);
    expect(screen.queryByTestId('queue-supervisor-commands')).not.toBeInTheDocument();
  });

  // @scenario screen=load_failed
  // @effects queue_status_failure_notice
  it('상태 조회가 실패하면(_global 표식) 실패 안내를 그리고 「등록된 큐 없음」 안내는 그리지 않는다', async () => {
    testUtils = await renderSection(
      { queue_driver: 'database' },
      { savedDriver: 'database', failed: true, status: { ...STATUS, rows: [], commands: [] } }
    );

    expect(screen.getAllByTestId('queue-status-load-failed')).toHaveLength(1);
    expect(screen.getByTestId('queue-worker-section')).not.toHaveTextContent(adminKo.settings.drivers.queue.worker.status.empty);
  });

  // @scenario screen=long_worker_states
  // @effects long_worker_badge_states
  it('긴 작업 워커 배지는 등록됨·일부만을 그대로, 모르는 상태는 미등록으로 그린다', async () => {
    const labels = adminKo.settings.drivers.queue.worker.long;
    for (const [status, expected] of [['registered', labels.registered], ['partial', labels.partial], ['weird', labels.not_registered]] as const) {
      testUtils = await renderSection(
        { queue_driver: 'database' },
        { savedDriver: 'database', status: { ...STATUS, long_worker: { connection: 'database-long', queue: 'long', status } } }
      );
      expect(screen.getByTestId('queue-long-worker-badge')).toHaveTextContent(expected);
      testUtils.cleanup();
      testUtils = undefined;
    }
  });

  // @scenario screen=fallback_count
  // @effects queue_fallback_notice
  it('등록 안 된 큐로 보내려던 작업을 기본 큐로 보낸 횟수가 있으면 안내를 그리고, 0이면 그리지 않는다', async () => {
    const last = {
      listener: 'Vendor\Foo\NotifyListener',
      hook: 'vendor-foo.after_create',
      requested_connection: null,
      requested_queue: 'notifications',
      reasons: ['queue_unregistered'],
    };
    testUtils = await renderSection(
      { queue_driver: 'database' },
      { savedDriver: 'database', status: { ...STATUS, placement: { ...STATUS.placement, fallback: { count: 3, last } } } }
    );

    const notice = screen.getByTestId('queue-fallback-notice');
    expect(notice).toHaveTextContent('3');
    const lastLine = within(notice).getByTestId('queue-fallback-last');
    expect(lastLine).toHaveTextContent('Vendor\Foo\NotifyListener');
    expect(lastLine).toHaveTextContent('notifications');
    expect(lastLine).toHaveTextContent(adminKo.settings.drivers.queue.worker.fallback.default);
    testUtils.cleanup();

    testUtils = await renderSection({ queue_driver: 'database' }, { savedDriver: 'database' });
    expect(screen.queryByTestId('queue-fallback-notice')).not.toBeInTheDocument();
    testUtils.cleanup();

    testUtils = await renderSection(
      { queue_driver: 'database' },
      { savedDriver: 'database', failed: true, status: { ...STATUS, placement: { ...STATUS.placement, fallback: { count: 3, last } } } }
    );
    expect(screen.queryByTestId('queue-fallback-notice')).not.toBeInTheDocument();
  });

  // @scenario screen=read_only
  // @effects queue_inputs_read_only
  it('수정 권한이 없으면 두 입력칸이 모두 비활성이다', async () => {
    testUtils = await renderSection(
      { queue_driver: 'database', queue_connections: ['database'], queue_names: { database: ['default'] } },
      { readOnly: true, savedDriver: 'database' }
    );

    const inputs = document.querySelectorAll('[data-tag-input]');
    expect(inputs).toHaveLength(2);
    inputs.forEach((el) => expect(el.getAttribute('data-disabled')).toBe('true'));
  });
});
