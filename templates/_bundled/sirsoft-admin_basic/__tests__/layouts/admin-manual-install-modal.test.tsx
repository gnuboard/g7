/**
 * @file admin-manual-install-modal.test.tsx
 * @description 확장 수동 설치 모달 레이아웃 테스트
 *
 * 테스트 대상:
 * - templates/.../partials/admin_module_list/_modal_manual_install.json
 * - templates/.../partials/admin_plugin_list/_modal_manual_install.json
 * - templates/.../partials/admin_template_list/_modal_manual_install.json
 *
 * 검증 항목:
 * - 3개 모달 구조 동일성 (TabNavigation underline, 에러 배너, 필드 에러)
 * - if 조건 기반 탭 콘텐츠 전환 (file_upload / github)
 * - 에러 배너 조건부 렌더링
 * - 필드별 에러 표시 (적색 테두리)
 * - 설치 버튼 disabled 조건
 * - 설치 중 스피너 표시
 * - errors.error 상세 에러 메시지 표시
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import fs from 'fs';
import path from 'path';

// ==============================
// 테스트용 컴포넌트 정의
// ==============================

const TestDiv: React.FC<{
  className?: string;
  children?: React.ReactNode;
  'data-testid'?: string;
}> = ({ className, children, 'data-testid': testId }) => (
  <div className={className} data-testid={testId}>{children}</div>
);

const TestButton: React.FC<{
  type?: string;
  className?: string;
  disabled?: boolean;
  children?: React.ReactNode;
  onClick?: () => void;
  'data-testid'?: string;
}> = ({ type, className, disabled, children, onClick, 'data-testid': testId }) => (
  <button
    type={type as any}
    className={className}
    disabled={disabled}
    onClick={onClick}
    data-testid={testId}
    data-disabled={disabled ? 'true' : 'false'}
  >
    {children}
  </button>
);

const TestSpan: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => (
  <span className={className}>{children || text}</span>
);

const TestP: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => (
  <p className={className}>{children || text}</p>
);

const TestIcon: React.FC<{
  name?: string;
  className?: string;
  size?: string;
}> = ({ name, className }) => (
  <i className={`icon-${name} ${className || ''}`} data-testid={`icon-${name}`} data-icon={name} />
);

const TestLabel: React.FC<{
  htmlFor?: string;
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ htmlFor, className, children, text }) => (
  <label htmlFor={htmlFor} className={className}>
    {children || text}
  </label>
);

const TestInput: React.FC<{
  type?: string;
  value?: string;
  placeholder?: string;
  className?: string;
  onChange?: (e: React.ChangeEvent<HTMLInputElement>) => void;
  'data-testid'?: string;
}> = ({ type, value, placeholder, className, onChange, 'data-testid': testId }) => (
  <input
    type={type}
    value={value}
    placeholder={placeholder}
    className={className}
    onChange={onChange}
    data-testid={testId}
  />
);

const TestFileInput: React.FC<{
  accept?: string;
  className?: string;
  buttonText?: string;
  placeholder?: string;
  onChange?: (e: any) => void;
}> = ({ accept, className, buttonText, placeholder }) => (
  <div data-testid="file-input" className={className} data-accept={accept}>
    <span>{buttonText}</span>
    <span>{placeholder}</span>
  </div>
);

const TestTabNavigation: React.FC<{
  tabs?: Array<{ id: string; label: string; iconName?: string }>;
  activeTabId?: string;
  variant?: string;
  onTabChange?: (tabId: string) => void;
  children?: React.ReactNode;
}> = ({ tabs, activeTabId, variant, children }) => (
  <div data-testid="tab-navigation" data-variant={variant} data-active-tab={activeTabId}>
    {tabs?.map((tab) => (
      <button key={tab.id} data-testid={`tab-${tab.id}`} data-active={tab.id === activeTabId ? 'true' : 'false'}>
        {tab.label}
      </button>
    ))}
    {children}
  </div>
);

const TestModal: React.FC<{
  id?: string;
  isOpen?: boolean;
  title?: string;
  size?: string;
  children?: React.ReactNode;
}> = ({ id, isOpen, title, size, children }) => (
  isOpen ? (
    <div data-testid={`modal-${id}`} role="dialog" data-size={size}>
      <h2>{title}</h2>
      {children}
    </div>
  ) : null
);

const TestFragment: React.FC<{
  children?: React.ReactNode;
}> = ({ children }) => <>{children}</>;

// ==============================
// 레지스트리 설정
// ==============================

function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();

  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Label: { component: TestLabel, metadata: { name: 'Label', type: 'basic' } },
    Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
    FileInput: { component: TestFileInput, metadata: { name: 'FileInput', type: 'basic' } },
    TabNavigation: { component: TestTabNavigation, metadata: { name: 'TabNavigation', type: 'composite' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
    Modal: { component: TestModal, metadata: { name: 'Modal', type: 'composite' } },
  };

  return registry;
}

// ==============================
// JSON 파일 로드 헬퍼
// ==============================

function loadModalJson(extensionType: 'module' | 'plugin' | 'template'): any {
  const basePath = path.resolve(
    __dirname,
    '../../layouts/partials'
  );
  const filePath = path.join(basePath, `admin_${extensionType}_list`, '_modal_manual_install.json');
  return JSON.parse(fs.readFileSync(filePath, 'utf-8'));
}

// ==============================
// 3개 모달 JSON 구조 동일성 테스트
// ==============================

describe('수동 설치 모달 3개 구조 동일성 검증', () => {
  /**
   * @scenario extension_type=module, extension_type=plugin, extension_type=template
   * @effects manual_install_tabs_use_underline_variant
   */
  it('3개 모달 모두 TabNavigation variant="underline" 사용', () => {
    const types = ['module', 'plugin', 'template'] as const;

    for (const type of types) {
      const json = loadModalJson(type);
      const tabNav = json.children.find((c: any) => c.name === 'TabNavigation');
      expect(tabNav, `${type}: TabNavigation이 존재해야 함`).toBeDefined();
      expect(tabNav.props.variant, `${type}: variant가 underline이어야 함`).toBe('underline');
    }
  });

  /**
   * @scenario extension_type=module, extension_type=plugin, extension_type=template
   * @effects manual_install_tabs_are_file_upload_and_github
   */
  it('3개 모달 모두 동일한 탭 구조 (file_upload, github)', () => {
    const types = ['module', 'plugin', 'template'] as const;

    for (const type of types) {
      const json = loadModalJson(type);
      const tabNav = json.children.find((c: any) => c.name === 'TabNavigation');
      const tabIds = tabNav.props.tabs.map((t: any) => t.id);
      expect(tabIds, `${type}: 탭 ID가 동일해야 함`).toEqual(['file_upload', 'github']);
    }
  });

  /**
   * @scenario extension_type=module, extension_type=plugin, extension_type=template
   * @effects manual_install_error_banner_uses_if_and_alert_danger
   */
  it('3개 모달 모두 에러 배너(if 조건) 포함', () => {
    const types = ['module', 'plugin', 'template'] as const;
    const errorStateKeys: Record<string, string> = {
      module: 'moduleInstallError',
      plugin: 'pluginInstallError',
      template: 'templateInstallError',
    };

    for (const type of types) {
      const json = loadModalJson(type);
      const errorBanner = json.children.find(
        (c: any) => c.if && c.if.includes(errorStateKeys[type])
      );
      expect(errorBanner, `${type}: 에러 배너가 존재해야 함`).toBeDefined();
      expect(errorBanner.props.className).toContain('alert-danger');
    }
  });

  /**
   * @scenario extension_type=module, extension_type=plugin, extension_type=template
   * @effects manual_install_uses_if_not_condition
   */
  it('3개 모달 모두 condition 속성 미사용 (if만 사용)', () => {
    const types = ['module', 'plugin', 'template'] as const;

    for (const type of types) {
      const json = loadModalJson(type);
      const jsonStr = JSON.stringify(json);
      expect(jsonStr).not.toContain('"condition"');
    }
  });

  /**
   * @scenario extension_type=module, extension_type=plugin, extension_type=template
   * @effects manual_install_button_has_download_and_spinner_icons
   */
  it('3개 모달 모두 설치 버튼에 download/spinner 아이콘 포함', () => {
    const types = ['module', 'plugin', 'template'] as const;

    for (const type of types) {
      const json = loadModalJson(type);
      const jsonStr = JSON.stringify(json);
      expect(jsonStr, `${type}: download 아이콘 존재`).toContain('"download"');
      expect(jsonStr, `${type}: spinner 아이콘 존재`).toContain('"spinner"');
    }
  });

  /**
   * @scenario extension_type=module, extension_type=plugin, extension_type=template
   * @effects manual_install_uses_file_input_component
   */
  it('3개 모달 모두 FileInput 컴포넌트 사용', () => {
    const types = ['module', 'plugin', 'template'] as const;

    for (const type of types) {
      const json = loadModalJson(type);
      const jsonStr = JSON.stringify(json);
      expect(jsonStr, `${type}: FileInput 사용`).toContain('"FileInput"');
    }
  });

  /**
   * @scenario extension_type=module, extension_type=plugin, extension_type=template
   * @effects file_input_change_stores_event_target_value
   */
  it('3개 모달 모두 FileInput onChange에서 $event.target.value 패턴 사용', () => {
    const types = ['module', 'plugin', 'template'] as const;
    const uploadFileKeys: Record<string, string> = {
      module: 'moduleUploadFile',
      plugin: 'pluginUploadFile',
      template: 'templateUploadFile',
    };

    for (const type of types) {
      const json = loadModalJson(type);
      const jsonStr = JSON.stringify(json);
      const key = uploadFileKeys[type];
      // raw value가 아닌 $event.target.value 패턴 사용 확인
      expect(jsonStr, `${type}: ${key}이 $event.target.value 패턴 사용`)
        .toContain(`"${key}":"{{$event.target.value}}"`);
      expect(jsonStr, `${type}: fileName이 $event.target.value?.name 패턴 사용`)
        .toContain('{{$event.target.value?.name}}');
    }
  });

  /**
   * @scenario extension_type=module, extension_type=plugin, extension_type=template
   * @effects detail_error_message_bound_to_errors_error
   */
  it('3개 모달 모두 errors.error 상세 에러 메시지 표시 P 요소 포함', () => {
    const types = ['module', 'plugin', 'template'] as const;
    const errorsStateKeys: Record<string, string> = {
      module: 'moduleInstallErrors',
      plugin: 'pluginInstallErrors',
      template: 'templateInstallErrors',
    };

    for (const type of types) {
      const json = loadModalJson(type);
      const jsonStr = JSON.stringify(json);
      const key = errorsStateKeys[type];
      // errors.error 상세 메시지를 표시하는 P 요소가 존재해야 함
      expect(jsonStr, `${type}: ${key}?.error if 조건 존재`).toContain(`_global.${key}?.error`);
      expect(jsonStr, `${type}: ${key}.error 텍스트 바인딩 존재`).toContain(`_global.${key}.error`);
    }
  });

  /**
   * @scenario extension_type=module, extension_type=plugin, extension_type=template
   * @effects field_errors_use_form_error_xs
   */
  it('3개 모달 모두 필드별 에러 표시(.form-error-xs) 포함 (#408)', () => {
    const types = ['module', 'plugin', 'template'] as const;

    for (const type of types) {
      const json = loadModalJson(type);
      const jsonStr = JSON.stringify(json);
      // text-red-500 + dark:text-red-400 + text-xs 토큰을 .form-error-xs 자산이 흡수
      const redErrorCount = (jsonStr.match(/form-error-xs/g) || []).length;
      expect(redErrorCount, `${type}: 필드 에러 2개(file, github_url)`).toBeGreaterThanOrEqual(2);
    }
  });
});

// ==============================
// 모듈 수동 설치 모달 렌더링 테스트
// ==============================
// Partial JSON의 children을 직접 components로 렌더링 (모달 wrapper 없이)

describe('모듈 수동 설치 모달 렌더링 테스트', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  let registry: ComponentRegistry;

  /**
   * partial JSON의 children을 일반 레이아웃 components로 변환하여 렌더링.
   * 모달 open/close 메커니즘 없이 콘텐츠만 검증.
   */
  const createContentLayout = (globalOverrides: Record<string, any> = {}) => {
    const modalJson = loadModalJson('module');
    return {
      version: '1.0.0',
      layout_name: 'module_manual_install_content_test',
      initGlobal: {
        moduleInstallTab: null,
        moduleUploadFile: null,
        moduleUploadFileName: null,
        moduleGithubUrl: null,
        isModuleManualInstalling: false,
        moduleInstallError: null,
        moduleInstallErrors: null,
        ...globalOverrides,
      },
      components: [
        {
          type: 'basic',
          name: 'Div',
          props: { 'data-testid': 'modal-content' },
          // 본문과 고정 하단(props.footer)을 함께 그린다 — 설치 단추가 고정 하단으로 옮겨 갔다(#869 P1)
          children: [...modalJson.children, ...(modalJson.props?.footer ?? [])],
        },
      ],
    };
  };

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  afterEach(() => {
    testUtils?.cleanup();
  });

  /**
   * @scenario extension_type=module
   * @effects file_upload_tab_active_by_default
   */
  it('초기 렌더링 시 파일 업로드 탭이 활성화됨', async () => {
    testUtils = createLayoutTest(createContentLayout(), { componentRegistry: registry });
    await testUtils.render();

    // TabNavigation이 렌더링됨
    const tabNav = screen.getByTestId('tab-navigation');
    expect(tabNav).toBeInTheDocument();
    expect(tabNav.getAttribute('data-variant')).toBe('underline');

    // FileInput이 보여야 함 (file_upload 탭이 기본) — ZIP 칸과 서명 파일(.sig.json, #916) 칸 두 개
    expect(screen.getAllByTestId('file-input').map((el) => el.getAttribute('data-accept'))).toEqual(['.zip', '.json']);
  });

  /**
   * @scenario extension_type=module
   * @effects error_banner_hidden_without_install_error
   */
  it('에러 배너는 moduleInstallError가 없으면 렌더링되지 않음', async () => {
    testUtils = createLayoutTest(createContentLayout(), { componentRegistry: registry });
    await testUtils.render();

    // 에러 아이콘(triangle-exclamation)이 없어야 함
    expect(screen.queryByTestId('icon-triangle-exclamation')).not.toBeInTheDocument();
  });

  /**
   * @scenario extension_type=module
   * @effects error_banner_shown_with_install_error
   */
  it('moduleInstallError가 있으면 에러 배너가 렌더링됨', async () => {
    testUtils = createLayoutTest(
      createContentLayout({ moduleInstallError: '설치에 실패했습니다.' }),
      { componentRegistry: registry }
    );
    await testUtils.render();

    // 에러 아이콘이 보여야 함
    expect(screen.getByTestId('icon-triangle-exclamation')).toBeInTheDocument();
  });

  /**
   * @scenario extension_type=module
   * @effects file_field_red_border_on_file_error
   */
  it('moduleInstallErrors?.file이 있으면 파일 필드에 적색 테두리 적용', async () => {
    testUtils = createLayoutTest(
      createContentLayout({
        moduleInstallErrors: { file: ['ZIP 파일만 업로드 가능합니다.'] },
      }),
      { componentRegistry: registry }
    );
    await testUtils.render();

    const [fileInput, signatureInput] = screen.getAllByTestId('file-input');
    expect(fileInput.className).toContain('border-red-500');
    // 서명 파일 칸(#916)은 자기 오류(signature)가 없으면 붉지 않다
    expect(signatureInput.className).not.toContain('border-red-500');
  });

  /**
   * @scenario extension_type=module
   * @effects installing_shows_spinner_hides_download
   */
  it('설치 중(isModuleManualInstalling=true)일 때 spinner 표시, download 숨김', async () => {
    testUtils = createLayoutTest(
      createContentLayout({
        isModuleManualInstalling: true,
        moduleUploadFile: { name: 'test.zip' },
      }),
      { componentRegistry: registry }
    );
    await testUtils.render();

    // spinner가 보이고 download는 안 보여야 함
    expect(screen.getByTestId('icon-spinner')).toBeInTheDocument();
    expect(screen.queryByTestId('icon-download')).not.toBeInTheDocument();
  });

  /**
   * @scenario extension_type=module
   * @effects detail_error_message_shown_with_general_message
   */
  it('moduleInstallErrors?.error가 있으면 상세 에러 메시지가 표시됨', async () => {
    testUtils = createLayoutTest(
      createContentLayout({
        moduleInstallError: '설치에 실패했습니다.',
        moduleInstallErrors: { error: '필수 의존성 sirsoft-ecommerce (module)이 충족되지 않았습니다.' },
      }),
      { componentRegistry: registry }
    );
    await testUtils.render();

    // 에러 배너가 보여야 함
    expect(screen.getByTestId('icon-triangle-exclamation')).toBeInTheDocument();

    // 상세 에러 메시지가 표시되어야 함
    expect(screen.getByText('필수 의존성 sirsoft-ecommerce (module)이 충족되지 않았습니다.')).toBeInTheDocument();
    // 일반 메시지도 함께 표시됨
    expect(screen.getByText('설치에 실패했습니다.')).toBeInTheDocument();
  });

  /**
   * @scenario extension_type=module
   * @effects detail_error_message_hidden_without_errors_error
   */
  it('moduleInstallErrors?.error가 없으면 상세 에러 메시지 P 요소가 렌더링되지 않음', async () => {
    testUtils = createLayoutTest(
      createContentLayout({
        moduleInstallError: '설치에 실패했습니다.',
        moduleInstallErrors: { file: ['ZIP 파일만 업로드 가능합니다.'] },
      }),
      { componentRegistry: registry }
    );
    await testUtils.render();

    // 일반 에러는 표시
    expect(screen.getByText('설치에 실패했습니다.')).toBeInTheDocument();
    // errors.error가 없으므로 상세 메시지는 미표시
    expect(screen.queryByText('필수 의존성')).not.toBeInTheDocument();
  });

  /**
   * @scenario extension_type=module
   * @effects idle_shows_download_hides_spinner
   */
  it('설치 중이 아닐 때 download 아이콘 표시, spinner 숨김', async () => {
    testUtils = createLayoutTest(
      createContentLayout({ moduleUploadFile: { name: 'test.zip' } }),
      { componentRegistry: registry }
    );
    await testUtils.render();

    expect(screen.getByTestId('icon-download')).toBeInTheDocument();
    expect(screen.queryByTestId('icon-spinner')).not.toBeInTheDocument();
  });

  /**
   * @scenario extension_type=module
   * @effects github_tab_shows_url_input_instead_of_file_input
   */
  it('github 탭이면 파일 입력 대신 저장소 주소 입력이 그려진다', async () => {
    testUtils = createLayoutTest(
      createContentLayout({ moduleInstallTab: 'github' }),
      { componentRegistry: registry }
    );
    await testUtils.render();

    // 탭 표시와 본문이 같은 값을 따른다
    expect(screen.getByTestId('tab-navigation').getAttribute('data-active-tab')).toBe('github');
    expect(screen.getByPlaceholderText('https://github.com/username/module-name')).toBeInTheDocument();
    // 파일 업로드 본문은 if 로 빠진다
    expect(screen.queryByTestId('file-input')).not.toBeInTheDocument();
  });

  /**
   * @scenario extension_type=module
   * @effects install_button_locked_until_selected_tab_input_filled
   */
  it('설치 버튼은 고른 탭의 입력이 비면 잠기고 채우면 풀린다 (설치 중이면 늘 잠긴다)', async () => {
    const cases: Array<[Record<string, any>, boolean, string]> = [
      [{}, true, '파일 탭, 파일 없음'],
      [{ moduleUploadFile: { name: 'test.zip' } }, false, '파일 탭, 파일 있음'],
      [{ moduleInstallTab: 'github', moduleUploadFile: { name: 'test.zip' } }, true, 'github 탭, 주소 없음(파일만 있음)'],
      [{ moduleInstallTab: 'github', moduleGithubUrl: 'https://github.com/a/b' }, false, 'github 탭, 주소 있음'],
      [{ moduleUploadFile: { name: 'test.zip' }, isModuleManualInstalling: true }, true, '설치 중'],
    ];

    for (const [overrides, locked, label] of cases) {
      testUtils = createLayoutTest(createContentLayout(overrides), { componentRegistry: registry });
      await testUtils.render();

      const icon = screen.queryByTestId('icon-download') ?? screen.getByTestId('icon-spinner');
      const installButton = icon.closest('button');
      expect(installButton, `${label}: 설치 버튼`).not.toBeNull();
      expect(installButton!.getAttribute('data-disabled'), label).toBe(locked ? 'true' : 'false');

      testUtils.cleanup();
    }
  });
});
