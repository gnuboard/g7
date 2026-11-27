/**
 * @file admin-package-integrity.test.tsx
 * @description 패키지 무결성 관리자 화면 레이아웃 테스트
 *
 * 테스트 대상:
 * - layouts/partials/_package_integrity_notice.json (설치·업데이트 창 공용 거절 안내)
 * - layouts/partials/admin_{module,plugin,template}_list/_modal_manual_install.json
 * - layouts/partials/admin_{module,plugin,template}_list/_modal_update.json
 * - layouts/partials/admin_{module,plugin,template}_list/_modal_detail.json · admin_language_pack_list/_modal_detail.json
 * - layouts/partials/admin_language_pack_list/_modal_install.json · _modal_update.json
 * - layouts/partials/admin_settings/_tab_security_extension_trust.json (확장 신뢰 키 카드)
 * - layouts/partials/admin_settings/_tab_update.json (코어 업데이트 서명 배지)
 *
 * 검증 항목:
 * - 409 미서명 거절이면 경고 패널을 그리고, 정책이 허용하면 실행 버튼이 「미서명으로 계속」으로 바뀌어
 *   같은 요청을 allow_unsigned=1 만 더해 다시 보낸다
 * - 「미서명 패키지 차단」이 켜져(can_continue=false) 있으면 계속 버튼이 없다
 * - 422 서명 불일치는 오류 패널(사유)만 그린다
 * - 작업 잠금 409(errors.operation_in_progress)는 무결성 패널로 가지 않는다
 * - 확장 상세의 「패키지 서명」 배지, 신뢰 키 카드(빈 내장 키 안내·제작자 키 행·차단 토글)
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { createLayoutTest, screen, fireEvent, waitFor } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import fs from 'fs';
import path from 'path';
import adminKo from '../../lang/partial/ko/admin.json';

// ==============================
// 테스트용 컴포넌트
// ==============================

const passthrough = (Tag: any) => {
  const C: React.FC<any> = ({ className, children, text, role, 'data-testid': testId, id }) => (
    <Tag className={className} role={role} data-testid={testId} id={id}>{children ?? text}</Tag>
  );
  return C;
};

const TestButton: React.FC<any> = ({ type, className, disabled, children, onClick }) => (
  <button type={type} className={className} disabled={disabled} onClick={onClick} data-disabled={disabled ? 'true' : 'false'}>
    {children}
  </button>
);

const TestIcon: React.FC<any> = ({ name }) => <i data-testid={`icon-${name}`} data-icon={name} />;

const TestInput: React.FC<any> = ({ type, value, placeholder, className, onChange, name, checked, disabled }) => (
  <input type={type} value={value ?? ''} placeholder={placeholder} className={className} onChange={onChange} name={name} checked={checked} disabled={disabled} />
);

const TestTextarea: React.FC<any> = ({ value, placeholder, className, onChange, name }) => (
  <textarea value={value ?? ''} placeholder={placeholder} className={className} onChange={onChange} name={name} />
);

const TestFileInput: React.FC<any> = ({ accept, className, placeholder }) => (
  <div data-testid="file-input" className={className} data-accept={accept}>
    <span>{placeholder}</span>
  </div>
);

const TestTabNavigation: React.FC<any> = ({ tabs, activeTabId }) => (
  <div data-testid="tab-navigation" data-active-tab={activeTabId}>
    {tabs?.map((tab: any) => <span key={tab.id} data-testid={`tab-${tab.id}`}>{tab.label}</span>)}
  </div>
);

const TestToggle: React.FC<any> = ({ name, disabled }) => (
  <span data-testid={`toggle-${name}`} data-disabled={disabled ? 'true' : 'false'} />
);

const TestFragment: React.FC<any> = ({ children }) => <>{children}</>;

function setupRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  const basic = (name: string, component: any) => ({ component, metadata: { name, type: 'basic' } });

  (registry as any).registry = {
    Div: basic('Div', passthrough('div')),
    Span: basic('Span', passthrough('span')),
    P: basic('P', passthrough('p')),
    H3: basic('H3', passthrough('h3')),
    H4: basic('H4', passthrough('h4')),
    Label: basic('Label', passthrough('label')),
    Code: basic('Code', passthrough('code')),
    A: basic('A', passthrough('a')),
    Img: basic('Img', () => null),
    Ul: basic('Ul', passthrough('ul')),
    Li: basic('Li', passthrough('li')),
    Button: basic('Button', TestButton),
    Icon: basic('Icon', TestIcon),
    Input: basic('Input', TestInput),
    Textarea: basic('Textarea', TestTextarea),
    FileInput: basic('FileInput', TestFileInput),
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
    TabNavigation: { component: TestTabNavigation, metadata: { name: 'TabNavigation', type: 'composite' } },
    Toggle: { component: TestToggle, metadata: { name: 'Toggle', type: 'composite' } },
  };

  return registry;
}

// ==============================
// 레이아웃 로드 — 조각 참조(`partial`)를 디스크의 짝 JSON 으로 펼친다(설치가 하는 인라인과 같은 결과)
// ==============================

const LAYOUTS_DIR = path.resolve(__dirname, '../../layouts');

function inlinePartials(node: any): any {
  if (Array.isArray(node)) {
    return node.map(inlinePartials);
  }
  if (node && typeof node === 'object') {
    if (typeof node.partial === 'string' && Object.keys(node).length === 1) {
      const loaded = JSON.parse(fs.readFileSync(path.join(LAYOUTS_DIR, node.partial), 'utf-8'));
      const { meta: _meta, ...rest } = loaded;
      return inlinePartials(rest);
    }
    const out: Record<string, any> = {};
    for (const [k, v] of Object.entries(node)) {
      out[k] = inlinePartials(v);
    }
    return out;
  }
  return node;
}

function loadPartial(relativePath: string): any {
  return inlinePartials(JSON.parse(fs.readFileSync(path.join(LAYOUTS_DIR, relativePath), 'utf-8')));
}

/** 모달 조각의 본문과 고정 하단(props.footer)을 일반 컴포넌트로 펼쳐 그리는 레이아웃 */
function modalContentLayout(relativePath: string, initGlobal: Record<string, any>, initLocal: Record<string, any> = {}) {
  const modal = loadPartial(relativePath);
  return {
    version: '1.0.0',
    layout_name: `integrity_test_${relativePath.replace(/[^a-z]/gi, '_')}`,
    initGlobal,
    initLocal,
    components: [
      {
        type: 'basic',
        name: 'Div',
        props: { 'data-testid': 'modal-content' },
        children: [...(modal.children ?? []), ...(modal.props?.footer ?? [])],
      },
    ],
  };
}

// ==============================
// fetch 모의 — 요청을 기록하고 정해 둔 응답을 차례로 돌려준다
// ==============================

type Reply = { status: number; body: any };

function installFetch(replies: Reply[]) {
  const calls: Array<{ url: string; init: RequestInit }> = [];
  const queue = [...replies];
  const fn = vi.fn(async (url: string, init: RequestInit = {}) => {
    // 쓰기 요청 앞의 CSRF 쿠키 요청은 기록하지 않고 응답 차례도 쓰지 않는다
    const reply = String(url).includes('csrf-cookie')
      ? { status: 204, body: null }
      : (calls.push({ url: String(url), init }), queue.shift() ?? { status: 200, body: { success: true, data: {} } });
    return {
      ok: reply.status >= 200 && reply.status < 300,
      status: reply.status,
      statusText: '',
      json: async () => reply.body,
      text: async () => JSON.stringify(reply.body),
      headers: new Headers({ 'Content-Type': 'application/json' }),
    } as Response;
  });
  globalThis.fetch = fn as unknown as typeof fetch;
  return calls;
}

/** 요청 본문을 객체로 읽는다 — multipart 는 FormData 항목, JSON 은 파싱 결과 */
function bodyOf(init: RequestInit): Record<string, any> {
  if (init.body instanceof FormData) {
    const out: Record<string, any> = {};
    init.body.forEach((v, k) => { out[k] = v; });
    return out;
  }
  return init.body ? JSON.parse(String(init.body)) : {};
}

/** 응답 integrity 페이로드 */
function integrity(overrides: Record<string, any> = {}) {
  return {
    status: 'unsigned',
    code: 'package_unsigned',
    can_continue: true,
    blocked_by_policy: false,
    reason: null,
    message: null,
    key_id: null,
    signer_label: null,
    key_source: null,
    package: { type: 'module', identifier: 'glitter-board', version: '1.0.0' },
    ...overrides,
  };
}

const UNSIGNED_409: Reply = {
  status: 409,
  body: { success: false, message: '서명되지 않은 패키지입니다.', errors: { package_unsigned: true, integrity: integrity() } },
};

const BLOCKED_409: Reply = {
  status: 409,
  body: {
    success: false,
    message: '미서명 패키지 설치가 차단되어 있습니다.',
    errors: { package_unsigned: true, integrity: integrity({ can_continue: false, blocked_by_policy: true }) },
  },
};

const INVALID_422: Reply = {
  status: 422,
  body: {
    success: false,
    message: '패키지 서명이 올바르지 않습니다.',
    errors: {
      package_signature_invalid: true,
      integrity: integrity({ status: 'invalid', code: 'package_signature_invalid', can_continue: false, reason: 'sha256_mismatch', message: 'ZIP 의 SHA-256 이 서명과 다릅니다.', key_id: 'glitter:3f2a9c0b1d4e5f60' }),
    },
  },
};

const LOCKED_409: Reply = {
  status: 409,
  body: { success: false, message: '다른 작업이 진행 중입니다.', errors: { operation_in_progress: true } },
};

const T = adminKo.package_integrity;

/**
 * 비동기 액션이 끝나기를 기다렸다가 다시 그린다 — 하네스는 전역 상태가 바뀌어도 스스로 다시 그리지 않는다.
 *
 * @param t 레이아웃 테스트 도구
 * @param done 끝났다고 볼 조건(전역 상태로 판정)
 */
async function settle(t: ReturnType<typeof createLayoutTest>, done: (g: any) => boolean): Promise<void> {
  await waitFor(() => expect(done(t.getState()._global)).toBe(true));
  await t.rerender();
}

function findButton(label: string): HTMLButtonElement {
  const el = screen.getAllByRole('button').find((b) => b.textContent?.includes(label));
  if (!el) {
    throw new Error(`버튼 「${label}」을 찾지 못했다`);
  }
  return el as HTMLButtonElement;
}

// ==============================
// 수동 설치 창 (모듈·플러그인·템플릿)
// ==============================

const MANUAL_INSTALL = [
  { type: 'module', prefix: 'module', install: adminKo.modules.actions.install, endpoint: '/api/admin/modules' },
  { type: 'plugin', prefix: 'plugin', install: adminKo.plugins.actions.install, endpoint: '/api/admin/plugins' },
  { type: 'template', prefix: 'template', install: adminKo.templates.actions.install, endpoint: '/api/admin/templates' },
] as const;

describe.each(MANUAL_INSTALL)('수동 설치 창 무결성 — $type', ({ type, prefix, install, endpoint }) => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  const file = new File(['zip'], 'glitter-board-1.0.0.zip', { type: 'application/zip' });
  const signature = new File(['{}'], 'glitter-board-1.0.0.zip.sig.json', { type: 'application/json' });

  const render = async (globals: Record<string, any> = {}) => {
    testUtils = createLayoutTest(
      modalContentLayout(`partials/admin_${type}_list/_modal_manual_install.json`, {
        [`${prefix}InstallTab`]: 'file_upload',
        [`${prefix}UploadFile`]: file,
        [`${prefix}InstallError`]: null,
        [`${prefix}InstallErrors`]: null,
        packageIntegrity: null,
        packageIntegrityMessage: null,
        ...globals,
      }) as any,
      { componentRegistry: setupRegistry(), translations: { admin: adminKo }, locale: 'ko' },
    );
    await testUtils.render();
  };

  beforeEach(() => {
    setupRegistry();
  });

  afterEach(() => {
    testUtils?.cleanup();
  });

  /**
   * 커버 축(마커 아님 — 평문): extension_type module·plugin·template
   * @scenario case=manual_install_signature_field
   * @effects manual_install_zip_tab_has_signature_file_input
   */
  it('ZIP 탭에 서명 파일(.json) 칸이 있다', async () => {
    await render();
    const inputs = screen.getAllByTestId('file-input');
    expect(inputs.map((el) => el.getAttribute('data-accept'))).toEqual(['.zip', '.json']);
    expect(screen.getByText(T.signature_file_label)).toBeInTheDocument();
  });

  /**
   * 커버 축(마커 아님 — 평문): extension_type module·plugin·template / response 409_package_unsigned
   * @scenario case=manual_install_unsigned_continue
   * @effects unsigned_panel_shown, install_button_becomes_continue_unsigned, retry_sends_same_body_with_allow_unsigned
   */
  it('409 미서명이면 경고 패널을 그리고 「미서명으로 계속」이 같은 본문에 allow_unsigned=1 만 더해 다시 보낸다', async () => {
    await render({ [`${prefix}UploadSignature`]: signature });
    const calls = installFetch([UNSIGNED_409, { status: 200, body: { success: true, data: { integrity_status: 'unsigned' } } }]);

    fireEvent.click(findButton(install));

    await settle(testUtils, (g) => !!g.packageIntegrity);
    expect(screen.getByText(T.unsigned_title)).toBeInTheDocument();
    expect(screen.getByText('서명되지 않은 패키지입니다.')).toBeInTheDocument();
    expect(screen.getByText(T.continue_hint)).toBeInTheDocument();
    // 붉은 일반 오류 띠는 쓰지 않는다
    expect(testUtils.getState()._global[`${prefix}InstallError`]).toBeNull();

    fireEvent.click(findButton(T.continue_unsigned));

    await waitFor(() => expect(calls).toHaveLength(2));
    expect(calls[0].url).toContain(`${endpoint}/install-from-file`);
    expect(calls[1].url).toBe(calls[0].url);

    const first = bodyOf(calls[0].init);
    const second = bodyOf(calls[1].init);
    expect(first.allow_unsigned).toBeUndefined();
    expect(first.file).toBeInstanceOf(File);
    expect(first.signature).toBeInstanceOf(File);
    expect(second.allow_unsigned).toBe('1');
    // 서명 확인을 아는 화면의 표식 — 첫 요청부터 늘 싣는다(서버가 옛 화면과 구별해 옛 화면도 호환)
    expect(first.integrity_aware).toBe('1');
    expect(second.integrity_aware).toBe('1');
    expect(second.file).toBe(first.file);
    expect(second.signature).toBe(first.signature);
  });

  /**
   * 커버 축(마커 아님 — 평문): extension_type module·plugin·template / tab github / response 409_package_unsigned
   * @scenario case=manual_install_github_unsigned_continue
   * @effects retry_sends_same_body_with_allow_unsigned
   */
  it('GitHub 탭도 같은 주소로 allow_unsigned=1 을 더해 다시 보낸다', async () => {
    await render({ [`${prefix}InstallTab`]: 'github', [`${prefix}GithubUrl`]: 'https://github.com/glitter/board' });
    const calls = installFetch([UNSIGNED_409, { status: 200, body: { success: true, data: {} } }]);

    fireEvent.click(findButton(install));
    await settle(testUtils, (g) => !!g.packageIntegrity);
    fireEvent.click(findButton(T.continue_unsigned));

    await waitFor(() => expect(calls).toHaveLength(2));
    expect(calls[0].url).toContain(`${endpoint}/install-from-github`);
    expect(bodyOf(calls[0].init)).toEqual({ github_url: 'https://github.com/glitter/board', allow_unsigned: null, integrity_aware: 1 });
    expect(bodyOf(calls[1].init)).toEqual({ github_url: 'https://github.com/glitter/board', allow_unsigned: 1, integrity_aware: 1 });
  });

  /**
   * 커버 축(마커 아님 — 평문): extension_type module·plugin·template / response 409_package_unsigned / block_unsigned true
   * @scenario case=manual_install_unsigned_blocked
   * @effects blocked_hint_shown, no_continue_unsigned_button
   */
  it('차단 정책(can_continue=false)이면 안내만 있고 「미서명으로 계속」은 없다', async () => {
    await render();
    installFetch([BLOCKED_409]);

    fireEvent.click(findButton(install));

    await settle(testUtils, (g) => !!g.packageIntegrity);
    expect(screen.getByText(T.blocked_hint)).toBeInTheDocument();
    expect(screen.queryByText(T.continue_unsigned)).not.toBeInTheDocument();
    expect(screen.queryByText(T.continue_hint)).not.toBeInTheDocument();
    expect(findButton(install)).toBeInTheDocument();
  });

  /**
   * 커버 축(마커 아님 — 평문): extension_type module·plugin·template / response 422_package_signature_invalid
   * @scenario case=manual_install_signature_invalid
   * @effects invalid_panel_shows_reason, no_continue_unsigned_button
   */
  it('422 서명 불일치는 사유가 든 오류 패널만 그린다', async () => {
    await render();
    installFetch([INVALID_422]);

    fireEvent.click(findButton(install));

    await settle(testUtils, (g) => !!g.packageIntegrity);
    expect(screen.getByText(T.invalid_title)).toBeInTheDocument();
    expect(screen.getByText('ZIP 의 SHA-256 이 서명과 다릅니다.')).toBeInTheDocument();
    expect(screen.getByText(T.signer_key.replace('{{key}}', 'glitter:3f2a9c0b1d4e5f60'))).toBeInTheDocument();
    expect(screen.queryByText(T.continue_unsigned)).not.toBeInTheDocument();
  });

  /**
   * 커버 축(마커 아님 — 평문): extension_type module·plugin·template / response 409_operation_in_progress
   * @scenario case=manual_install_operation_lock_409
   * @effects lock_409_not_treated_as_integrity
   */
  it('작업 잠금 409 는 무결성 패널이 아니라 일반 오류 띠로 간다', async () => {
    await render();
    installFetch([LOCKED_409]);

    fireEvent.click(findButton(install));

    await settle(testUtils, (g) => !!g[`${prefix}InstallError`]);
    expect(screen.getByText('다른 작업이 진행 중입니다.')).toBeInTheDocument();
    expect(testUtils.getState()._global.packageIntegrity).toBeNull();
    expect(screen.queryByText(T.unsigned_title)).not.toBeInTheDocument();
  });
});

// ==============================
// 업데이트 창 (모듈·플러그인·템플릿)
// ==============================

/** 업데이트 미리보기가 돌려준 준비본 토큰(영숫자 40자) */
const PENDING_TOKEN = 'P'.repeat(40);

const UPDATE = [
  { type: 'module', selected: 'selectedModule', busy: 'isModuleUpdating', errorKey: 'moduleUpdateError', update: adminKo.modules.actions.update, endpoint: '/api/admin/modules' },
  { type: 'plugin', selected: 'selectedPlugin', busy: 'isPluginUpdating', errorKey: 'pluginUpdateError', update: adminKo.plugins.actions.update, endpoint: '/api/admin/plugins' },
  { type: 'template', selected: 'selectedTemplate', busy: 'isTemplateUpdating', errorKey: 'templateUpdateError', update: adminKo.templates.actions.update, endpoint: '/api/admin/templates' },
] as const;

describe.each(UPDATE)('업데이트 창 무결성 — $type', ({ type, selected, busy, errorKey, update, endpoint }) => {
  let testUtils: ReturnType<typeof createLayoutTest>;

  const render = async () => {
    testUtils = createLayoutTest(
      modalContentLayout(`partials/admin_${type}_list/_modal_update.json`, {
        [selected]: { identifier: 'glitter-board', name: 'Glitter Board', version: '1.0.0', latest_version: '1.1.0', is_compatible: true, update_source: 'github' },
        [busy]: false,
        [errorKey]: null,
        packageIntegrity: null,
        packageIntegrityMessage: null,
        // 업데이트 미리보기가 끝난 상태 — 실행 버튼은 미리보기가 준비돼야 풀리고, GitHub 판은 준비본 토큰을 싣는다
        layoutPreviewState: 'ready',
        layoutSyncStrategy: 'keep',
        layoutDecisions: {},
        layoutUpdatePreview: { pending_token: PENDING_TOKEN, declarative_deletions: [] },
      }) as any,
      { componentRegistry: setupRegistry(), translations: { admin: adminKo }, locale: 'ko' },
    );
    await testUtils.render();
  };

  afterEach(() => {
    testUtils?.cleanup();
  });

  /**
   * 커버 축(마커 아님 — 평문): extension_type module·plugin·template / response 409_package_unsigned
   * @scenario case=update_unsigned_continue
   * @effects unsigned_panel_shown, update_button_becomes_continue_unsigned, retry_sends_same_body_with_allow_unsigned
   */
  it('409 미서명이면 경고 패널과 「미서명으로 계속」이 뜨고 같은 본문에 allow_unsigned=1 을 더해 다시 보낸다', async () => {
    await render();
    const calls = installFetch([UNSIGNED_409, { status: 200, body: { success: true, message: '업데이트했습니다.', data: { integrity_status: 'unsigned' } } }]);

    fireEvent.click(findButton(update));
    await settle(testUtils, (g) => !!g.packageIntegrity);

    expect(screen.getByText(T.unsigned_title)).toBeInTheDocument();
    expect(testUtils.getState()._global[errorKey]).toBeNull();

    fireEvent.click(findButton(T.continue_unsigned));
    await waitFor(() => expect(calls).toHaveLength(2));

    expect(calls[0].url).toContain(`${endpoint}/glitter-board/update`);
    const first = bodyOf(calls[0].init);
    const second = bodyOf(calls[1].init);
    expect(first.allow_unsigned).toBeNull();
    expect(second.allow_unsigned).toBe(1);
    // 서명 확인을 아는 화면의 표식 — 첫 요청부터 늘 싣는다
    expect(String(first.integrity_aware)).toBe('1');
    // 「미서명으로 계속」 재요청도 미리보기 준비본 토큰을 그대로 싣는다 — 서버는 확인이 필요해 멈춘 준비본을 남겨 두고 이 재요청에 넘긴다
    expect(first.pending_token).toBe(PENDING_TOKEN);
    expect(second.pending_token).toBe(PENDING_TOKEN);
    const { allow_unsigned: _a, ...firstRest } = first;
    const { allow_unsigned: _b, ...secondRest } = second;
    expect(secondRest).toEqual(firstRest);
  });

  /**
   * 회귀 가드(7.2.1 출시 전 웹 코어 업데이트 실측): 서버의 큰 문구(`message`)가 사유(`integrity.message`)를 이미 품으면
   * 사유 문단을 다시 그리지 않는다 — 「이 패키지에는 서명 파일이 없습니다.」가 경고 상자에 두 번 보였다.
   * 커버 축(마커 아님 — 평문): extension_type module·plugin·template / response 409_package_unsigned(사유 포함)
   * @scenario case=update_unsigned_reason_once
   * @effects unsigned_reason_shown_once
   */
  it('409 미서명의 사유가 큰 문구에 이미 들어 있으면 사유를 한 번만 그린다', async () => {
    await render();
    const reason = '이 패키지에는 서명 파일이 없습니다.';
    installFetch([{
      status: 409,
      body: {
        success: false,
        message: `서명되지 않은 패키지입니다. 내용을 확인했다면 「미서명으로 계속」을 눌러 설치하세요. ${reason}`,
        errors: { package_unsigned: true, integrity: integrity({ reason: 'no_signature', message: reason }) },
      },
    }]);

    fireEvent.click(findButton(update));
    await settle(testUtils, (g) => !!g.packageIntegrity);

    const alert = screen.getByRole('alert');
    expect(alert.textContent?.split(reason).length).toBe(2);
  });

  /**
   * 커버 축(마커 아님 — 평문): extension_type module·plugin·template / response 409_package_unsigned / block_unsigned true
   * @scenario case=update_unsigned_blocked
   * @effects blocked_hint_shown, no_continue_unsigned_button
   */
  it('차단 정책이면 「미서명으로 계속」 없이 안내만 남는다', async () => {
    await render();
    installFetch([BLOCKED_409]);

    fireEvent.click(findButton(update));
    await settle(testUtils, (g) => !!g.packageIntegrity);

    expect(screen.getByText(T.blocked_hint)).toBeInTheDocument();
    expect(screen.queryByText(T.continue_unsigned)).not.toBeInTheDocument();
  });

  /**
   * 커버 축(마커 아님 — 평문): extension_type module·plugin·template / response 422_package_signature_invalid
   * @scenario case=update_signature_invalid
   * @effects invalid_panel_shows_reason, no_continue_unsigned_button
   */
  it('422 서명 불일치는 오류 상자 대신 사유가 든 패널을 그린다', async () => {
    await render();
    installFetch([INVALID_422]);

    fireEvent.click(findButton(update));
    await settle(testUtils, (g) => !!g.packageIntegrity);

    expect(screen.getByText(T.invalid_title)).toBeInTheDocument();
    expect(screen.getByText('ZIP 의 SHA-256 이 서명과 다릅니다.')).toBeInTheDocument();
    expect(testUtils.getState()._global[errorKey]).toBeNull();
    expect(screen.queryByText(T.continue_unsigned)).not.toBeInTheDocument();
  });
});

// ==============================
// 언어팩 설치·업데이트 창
// ==============================

describe('언어팩 설치 창 무결성', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  const L = adminKo.language_packs;

  const render = async (globals: Record<string, any>) => {
    testUtils = createLayoutTest(
      modalContentLayout('partials/admin_language_pack_list/_modal_install.json', {
        languagePackInstallError: null,
        languagePackInstallErrors: null,
        packageIntegrity: null,
        packageIntegrityMessage: null,
        ...globals,
      }) as any,
      { componentRegistry: setupRegistry(), translations: { admin: adminKo }, locale: 'ko' },
    );
    await testUtils.render();
  };

  afterEach(() => {
    testUtils?.cleanup();
  });

  /**
   * 커버 축(마커 아님 — 평문): tab file_upload·github·url
   * @scenario case=language_pack_install_tabs
   * @effects language_pack_install_has_url_tab, language_pack_zip_tab_has_signature_file_input
   */
  it('ZIP·GitHub·URL 세 탭이 있고 ZIP 탭에 서명 파일 칸이 있다', async () => {
    await render({ languagePackInstallTab: 'file_upload' });

    expect(screen.getByTestId('tab-url')).toHaveTextContent(L.install_modal.tabs.url);
    expect(screen.getAllByTestId('file-input').map((el) => el.getAttribute('data-accept'))).toEqual(['.zip', '.json']);
  });

  /**
   * 커버 축(마커 아님 — 평문): tab url / response 409_package_unsigned
   * @scenario case=language_pack_install_url_unsigned_continue
   * @effects language_pack_url_tab_sends_signature_url, retry_sends_same_body_with_allow_unsigned
   */
  it('URL 탭은 주소·체크섬·서명 파일 주소를 보내고, 409 뒤 「미서명으로 계속」은 allow_unsigned=1 만 더한다', async () => {
    await render({
      languagePackInstallTab: 'url',
      languagePackUrl: 'https://example.com/ja.zip',
      languagePackChecksum: '',
      languagePackSignatureUrl: 'https://example.com/ja.zip.sig.json',
    });
    expect(screen.getByText(T.signature_url_label)).toBeInTheDocument();
    const calls = installFetch([UNSIGNED_409, { status: 200, body: { success: true, data: {} } }]);

    fireEvent.click(findButton(L.actions.install));
    await settle(testUtils, (g) => !!g.packageIntegrity);
    fireEvent.click(findButton(T.continue_unsigned));
    await waitFor(() => expect(calls).toHaveLength(2));

    expect(calls[0].url).toContain('/api/admin/language-packs/install-from-url');
    expect(bodyOf(calls[0].init)).toEqual({ url: 'https://example.com/ja.zip', checksum: null, signature_url: 'https://example.com/ja.zip.sig.json', allow_unsigned: null, integrity_aware: 1 });
    expect(bodyOf(calls[1].init)).toEqual({ url: 'https://example.com/ja.zip', checksum: null, signature_url: 'https://example.com/ja.zip.sig.json', allow_unsigned: 1, integrity_aware: 1 });
  });

  /**
   * 커버 축(마커 아님 — 평문): tab file_upload
   * @scenario case=language_pack_install_file_signature
   * @effects language_pack_zip_upload_sends_signature
   */
  it('ZIP 탭은 서명 파일을 signature 로 함께 보낸다', async () => {
    const file = new File(['zip'], 'ja.zip');
    const signature = new File(['{}'], 'ja.zip.sig.json');
    await render({ languagePackInstallTab: 'file_upload', languagePackUploadFile: file, languagePackUploadSignature: signature });
    const calls = installFetch([{ status: 200, body: { success: true, data: { integrity_status: 'verified', integrity_key_id: 'glitter:3f2a9c0b1d4e5f60' } } }]);

    fireEvent.click(findButton(L.actions.install));
    await waitFor(() => expect(calls).toHaveLength(1));

    const body = bodyOf(calls[0].init);
    expect(calls[0].url).toContain('/api/admin/language-packs/install-from-file');
    expect(body.file).toBeInstanceOf(File);
    expect(body.signature).toBeInstanceOf(File);
    expect(body.allow_unsigned).toBeUndefined();
    expect(body.integrity_aware).toBe('1');
  });
});

describe('언어팩 업데이트 창 무결성', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;

  afterEach(() => {
    testUtils?.cleanup();
  });

  /**
   * 커버 축(마커 아님 — 평문): response 409_package_unsigned
   * @scenario case=language_pack_update_unsigned_continue
   * @effects unsigned_panel_shown, retry_sends_same_body_with_allow_unsigned
   */
  it('409 미서명이면 패널을 그리고 「미서명으로 계속」이 allow_unsigned=1 로 다시 보낸다', async () => {
    testUtils = createLayoutTest(
      modalContentLayout(
        'partials/admin_language_pack_list/_modal_update.json',
        { isLanguagePackUpdating: false, packageIntegrity: null, packageIntegrityMessage: null },
        { updateId: 7, updateCurrent: '1.0.0', updateLatest: '1.1.0' },
      ) as any,
      { componentRegistry: setupRegistry(), translations: { admin: adminKo }, locale: 'ko' },
    );
    await testUtils.render();
    const calls = installFetch([UNSIGNED_409, { status: 200, body: { success: true, data: {} } }]);

    fireEvent.click(findButton(adminKo.language_packs.actions.update_now));
    await settle(testUtils, (g) => !!g.packageIntegrity);
    expect(screen.getByText(T.unsigned_title)).toBeInTheDocument();

    fireEvent.click(findButton(T.continue_unsigned));
    await waitFor(() => expect(calls).toHaveLength(2));

    expect(calls[0].url).toContain('/api/admin/language-packs/7/update');
    // 서명 확인을 아는 화면의 표식 — 첫 요청부터 늘 싣는다
    // 7.2.1 — 고급 영역의 강제·소스도 함께 싣는다(기본은 강제 꺼짐·auto)
    expect(bodyOf(calls[0].init)).toEqual({ force: false, source: 'auto', allow_unsigned: null, integrity_aware: '1' });
    expect(bodyOf(calls[1].init)).toEqual({ force: false, source: 'auto', allow_unsigned: 1, integrity_aware: '1' });
  });
});

// ==============================
// 확장·언어팩 상세 — 패키지 서명 배지
// ==============================

const DETAIL = [
  { type: 'module', selected: 'selectedModule' },
  { type: 'plugin', selected: 'selectedPlugin' },
  { type: 'template', selected: 'selectedTemplate' },
] as const;

describe.each(DETAIL)('확장 상세 「패키지 서명」 — $type', ({ type, selected }) => {
  let testUtils: ReturnType<typeof createLayoutTest>;

  afterEach(() => {
    testUtils?.cleanup();
  });

  const cases: Array<[string | null, string | null, string]> = [
    ['verified', 'glitter:3f2a9c0b1d4e5f60', T.status_verified.replace('{{signer}}', 'glitter')],
    ['unsigned', null, T.status_unsigned],
    ['unverifiable', null, T.status_unverifiable],
    ['bundled', null, T.status_bundled],
    [null, null, T.status_none],
  ];

  /**
   * 커버 축(마커 아님 — 평문): extension_type module·plugin·template / integrity_status verified·unsigned·unverifiable·bundled·null
   * @scenario case=extension_detail_signature_badge
   * @effects detail_shows_package_signature_badge
   */
  it.each(cases)('integrity_status=%s 이면 그에 맞는 배지 하나만 그린다', async (status, keyId, expected) => {
    testUtils = createLayoutTest(
      modalContentLayout(`partials/admin_${type}_list/_modal_detail.json`, {
        [selected]: { identifier: 'glitter-board', name: 'Glitter Board', version: '1.0.0', status: 'active', integrity_status: status, integrity_key_id: keyId },
      }) as any,
      { componentRegistry: setupRegistry(), translations: { admin: adminKo }, locale: 'ko' },
    );
    await testUtils.render();

    const field = document.getElementById('package_signature_field');
    expect(field).not.toBeNull();
    expect(field!.textContent).toContain(T.signature_label);
    expect(field!.querySelectorAll('.badge')).toHaveLength(1);
    expect(field!.querySelector('.badge')!.textContent).toBe(expected);
  });
});

describe('언어팩 상세 「패키지 서명」', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;

  afterEach(() => {
    testUtils?.cleanup();
  });

  /**
   * 커버 축(마커 아님 — 평문): integrity_status verified
   * @scenario case=language_pack_detail_signature_badge
   * @effects detail_shows_package_signature_badge
   */
  it('verified 면 「서명 확인됨: 서명자」를 그린다', async () => {
    testUtils = createLayoutTest(modalContentLayout('partials/admin_language_pack_list/_modal_detail.json', {}) as any, {
      componentRegistry: setupRegistry(),
      translations: { admin: adminKo },
      locale: 'ko',
      initialData: {
        language_pack_detail: { data: { identifier: 'glitter-core-ja', name: 'Japanese', version: '1.0.0', locale: 'ja', integrity_status: 'verified', integrity_key_id: 'glitter:3f2a9c0b1d4e5f60' } },
      },
    });
    await testUtils.render();

    const field = document.getElementById('lp_package_signature_field');
    expect(field).not.toBeNull();
    expect(field!.querySelector('.badge')!.textContent).toBe(T.status_verified.replace('{{signer}}', 'glitter'));
  });
});

// ==============================
// 환경설정 보안 탭 — 확장 신뢰 키 카드
// ==============================

describe('보안 탭 「확장 신뢰 키」 카드', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  const S = adminKo.settings.security.extension_trust;

  const render = async (data: any) => {
    const card = loadPartial('partials/admin_settings/_tab_security_extension_trust.json');
    testUtils = createLayoutTest(
      { version: '1.0.0', layout_name: 'integrity_test_trust_card', components: [card] } as any,
      { componentRegistry: setupRegistry(), translations: { admin: adminKo }, locale: 'ko', initialData: { extensionTrustKeys: { data } } },
    );
    await testUtils.render();
  };

  afterEach(() => {
    testUtils?.cleanup();
  });

  const vendorKey = {
    id: 3,
    key_id: 'glitter:3f2a9c0b1d4e5f60',
    label: 'glitter',
    public_key: 'AAAA',
    vendor_scope: ['glitter', 'glitter-labs'],
    created_by: 1,
    created_at: '2026-11-15T09:00:00+09:00',
  };

  /**
   * 커버 축(마커 아님 — 평문): builtin empty / vendor_keys 1
   * @scenario case=trust_key_card_render
   * @effects trust_card_shows_empty_builtin_notice, trust_card_lists_vendor_key_row, trust_card_has_block_unsigned_toggle
   */
  it('내장 키가 비면 안내를, 제작자 키는 key_id·서명자·vendor 범위 행을, 차단 토글을 그린다', async () => {
    await render({ builtin: [], keys: [vendorKey], block_unsigned: false, sodium_available: true });

    expect(screen.getByText(S.title)).toBeInTheDocument();
    expect(screen.getByText(S.builtin_empty)).toBeInTheDocument();
    expect(screen.getByText('glitter:3f2a9c0b1d4e5f60')).toBeInTheDocument();
    expect(screen.getByText('glitter-labs')).toBeInTheDocument();
    expect(screen.getByTestId('toggle-security.extension_block_unsigned')).toBeInTheDocument();
    expect(screen.queryByText(S.vendor_empty)).not.toBeInTheDocument();
    expect(screen.queryByText(S.sodium_missing)).not.toBeInTheDocument();
    expect(findButton(S.delete_key)).toBeInTheDocument();
  });

  /**
   * 커버 축(마커 아님 — 평문): sodium_available false / vendor_keys 0
   * @scenario case=trust_key_card_sodium_missing
   * @effects trust_card_warns_sodium_missing, trust_card_shows_empty_vendor_notice, trust_card_lists_builtin_key
   */
  it('sodium 이 없으면 경고를, 제작자 키가 없으면 빈 안내를, 내장 키는 상태 배지와 함께 그린다', async () => {
    await render({
      builtin: [{ key_id: 'sirsoft:0011223344556677', label: 'SIR', status: 'active', public_key: 'BBBB' }],
      keys: [],
      block_unsigned: true,
      sodium_available: false,
    });

    expect(screen.getByText(S.sodium_missing)).toBeInTheDocument();
    expect(screen.getByText(S.vendor_empty)).toBeInTheDocument();
    expect(screen.getByText('sirsoft:0011223344556677')).toBeInTheDocument();
    expect(screen.getByText(S.status_active)).toBeInTheDocument();
    expect(screen.queryByText(S.builtin_empty)).not.toBeInTheDocument();
  });
});

describe('확장 신뢰 키 추가·삭제 창', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  const M = adminKo.settings.extension_trust_key_modal;

  afterEach(() => {
    testUtils?.cleanup();
  });

  /**
   * 커버 축(마커 아님 — 평문): has_password true
   * @scenario case=trust_key_add_submit
   * @effects trust_key_add_posts_fields_with_password, trust_key_password_cleared_on_success
   */
  it('키 추가는 공개키·서명자·vendor 범위·비밀번호를 POST 하고 성공하면 비밀번호를 지운다', async () => {
    testUtils = createLayoutTest(
      modalContentLayout('partials/admin_settings/_modal_extension_trust_key_add.json', {
        trustKeyForm: { public_key: ' AAAABBBB ', label: 'glitter', vendor_scope: 'glitter, glitter-labs' },
        trustKeyPassword: 'secret',
        trustKeyErrors: null,
        trustKeyError: null,
      }) as any,
      { componentRegistry: setupRegistry(), translations: { admin: adminKo }, locale: 'ko' },
    );
    await testUtils.render();
    const calls = installFetch([{ status: 201, body: { success: true, data: { id: 1, key_id: 'glitter:3f2a9c0b1d4e5f60' } } }]);

    fireEvent.click(findButton(M.submit));
    await settle(testUtils, (g) => g.isSavingTrustKey === false);

    expect(calls[0].url).toContain('/api/admin/extension-trust-keys');
    expect(calls[0].init.method).toBe('POST');
    expect(bodyOf(calls[0].init)).toEqual({ public_key: 'AAAABBBB', label: 'glitter', vendor_scope: 'glitter, glitter-labs', password: 'secret' });
    expect(testUtils.getState()._global.trustKeyPassword).toBe('');
  });

  /**
   * 커버 축(마커 아님 — 평문): response 422
   * @scenario case=trust_key_add_validation_error
   * @effects trust_key_add_shows_field_errors, trust_key_password_kept_on_failure
   */
  it('422 칸별 오류(vendor_scope.0·password)는 해당 칸 아래에 남고 비밀번호는 지우지 않는다', async () => {
    testUtils = createLayoutTest(
      modalContentLayout('partials/admin_settings/_modal_extension_trust_key_add.json', {
        trustKeyForm: { public_key: 'AAAA', label: 'glitter', vendor_scope: 'sirsoft' },
        trustKeyPassword: 'wrong',
        trustKeyErrors: null,
        trustKeyError: null,
      }) as any,
      { componentRegistry: setupRegistry(), translations: { admin: adminKo }, locale: 'ko' },
    );
    await testUtils.render();
    installFetch([{
      status: 422,
      body: { success: false, message: '입력값을 확인하세요.', errors: { 'vendor_scope.0': ['sirsoft 는 지정할 수 없습니다.'], password: ['비밀번호가 올바르지 않습니다.'] } },
    }]);

    fireEvent.click(findButton(M.submit));
    await settle(testUtils, (g) => !!g.trustKeyErrors);

    expect(screen.getByText('sirsoft 는 지정할 수 없습니다.')).toBeInTheDocument();
    expect(screen.getByText('비밀번호가 올바르지 않습니다.')).toBeInTheDocument();
    expect(testUtils.getState()._global.trustKeyPassword).toBe('wrong');
  });

  /**
   * 커버 축(마커 아님 — 평문): has_password true
   * @scenario case=trust_key_delete_submit
   * @effects trust_key_delete_sends_password_to_key_endpoint
   */
  it('키 삭제는 대상 id 주소로 비밀번호를 담아 DELETE 한다', async () => {
    testUtils = createLayoutTest(
      modalContentLayout('partials/admin_settings/_modal_extension_trust_key_delete.json', {
        trustKeyDeleteTarget: { id: 3, key_id: 'glitter:3f2a9c0b1d4e5f60' },
        trustKeyPassword: 'secret',
      }) as any,
      { componentRegistry: setupRegistry(), translations: { admin: adminKo }, locale: 'ko' },
    );
    await testUtils.render();
    expect(screen.getByText(M.delete_confirm.replace('{{key_id}}', 'glitter:3f2a9c0b1d4e5f60'))).toBeInTheDocument();
    const calls = installFetch([{ status: 200, body: { success: true, data: null } }]);

    fireEvent.click(findButton(M.delete_submit));
    await settle(testUtils, (g) => g.isDeletingTrustKey === false);

    expect(calls[0].url).toContain('/api/admin/extension-trust-keys/3');
    expect(calls[0].init.method).toBe('DELETE');
    expect(bodyOf(calls[0].init)).toEqual({ password: 'secret' });
    expect(testUtils.getState()._global.trustKeyPassword).toBe('');
  });
});

// ==============================
// 코어 업데이트 탭 — 릴리스 서명 배지
// ==============================

describe('코어 업데이트 탭 「릴리스 서명」', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;

  afterEach(() => {
    testUtils?.cleanup();
  });

  const render = async (signatureAvailable: boolean | null, globals: Record<string, any> = {}) => {
    const tab = loadPartial('partials/admin_settings/_tab_update.json');
    testUtils = createLayoutTest(
      {
        version: '1.0.0',
        layout_name: 'integrity_test_core_update',
        initGlobal: { activeSettingsTab: 'update', settingsAbilities: { can_core_update: true }, ...globals },
        initLocal: { coreUpdateInfo: { update_available: true, latest_version: '7.2.1', signature_available: signatureAvailable } },
        components: [tab],
      } as any,
      { componentRegistry: setupRegistry(), translations: { admin: adminKo }, locale: 'ko' },
    );
    await testUtils.render();
  };

  /**
   * 커버 축(마커 아님 — 평문): signature_available true·false
   * @scenario case=core_update_signature_badge
   * @effects core_update_shows_signature_available_badge
   */
  it.each([
    [true, T.core_signature_available],
    [false, T.core_signature_missing],
  ])('signature_available=%s 이면 「%s」 배지를 그린다', async (available, label) => {
    await render(available);
    const row = document.getElementById('core_update_signature');
    expect(row).not.toBeNull();
    expect(row!.textContent).toContain(label);
  });

  /**
   * 커버 축(마커 아님 — 평문): signature_available null
   * @scenario case=core_update_signature_badge
   * @effects core_update_hides_signature_badge_when_unknown
   */
  it('signature_available 를 모르면(null) 배지를 그리지 않는다', async () => {
    await render(null);
    expect(document.getElementById('core_update_signature')).toBeNull();
  });

  /**
   * 커버 축(마커 아님 — 평문): response 409_package_unsigned
   * @scenario case=core_update_start_unsigned_panel
   * @effects unsigned_panel_shown, start_button_becomes_continue_unsigned
   */
  it('착수 거절 기록이 있으면 시작 버튼 위에 경고 패널을, 버튼에 「미서명으로 계속」을 그린다', async () => {
    await render(false, { packageIntegrity: integrity({ package: { type: 'core', identifier: 'g7', version: '7.2.1' } }), packageIntegrityMessage: '서명되지 않은 코어 패키지입니다.' });

    expect(screen.getByText(T.unsigned_title)).toBeInTheDocument();
    expect(screen.getByText('서명되지 않은 코어 패키지입니다.')).toBeInTheDocument();
    expect(findButton(T.continue_unsigned)).toBeInTheDocument();
  });
});

// ==============================
// 스케줄 등록 창 도움말
// ==============================

describe('스케줄 등록 창 — 미서명 안내', () => {
  /**
   * 커버 축(마커 아님 — 평문): type artisan
   * @scenario case=schedule_form_allow_unsigned_hint
   * @effects schedule_form_hints_allow_unsigned_for_artisan
   */
  it('Artisan 명령 칸 아래에 --allow-unsigned 도움말이 있고 Artisan 유형일 때만 그린다', () => {
    const json = JSON.stringify(loadPartial('partials/admin_schedule_list/_modal_form.json'));
    expect(json).toContain('$t:admin.schedules.form.allow_unsigned_hint');
    expect(json).toContain("(_global.scheduleForm?.type ?? 'artisan') === 'artisan'");
    expect(adminKo.schedules.form.allow_unsigned_hint).toContain('--allow-unsigned');
  });
});
