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
  it('3개 모달 모두 TabNavigation variant="underline" 사용', () => {
    const types = ['module', 'plugin', 'template'] as const;

    for (const type of types) {
      const json = loadModalJson(type);
      const tabNav = json.children.find((c: any) => c.name === 'TabNavigation');
      expect(tabNav, `${type}: TabNavigation이 존재해야 함`).toBeDefined();
      expect(tabNav.props.variant, `${type}: variant가 underline이어야 함`).toBe('underline');
    }
  });

  it('3개 모달 모두 동일한 탭 구조 (file_upload, github)', () => {
    const types = ['module', 'plugin', 'template'] as const;

    for (const type of types) {
      const json = loadModalJson(type);
      const tabNav = json.children.find((c: any) => c.name === 'TabNavigation');
      const tabIds = tabNav.props.tabs.map((t: any) => t.id);
      expect(tabIds, `${type}: 탭 ID가 동일해야 함`).toEqual(['file_upload', 'github']);
    }
  });

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
      expect(errorBanner.props.className).toContain('bg-red-50');
    }
  });

  it('3개 모달 모두 condition 속성 미사용 (if만 사용)', () => {
    const types = ['module', 'plugin', 'template'] as const;

    for (const type of types) {
      const json = loadModalJson(type);
      const jsonStr = JSON.stringify(json);
      expect(jsonStr).not.toContain('"condition"');
    }
  });

  it('3개 모달 모두 설치 버튼에 download/spinner 아이콘 포함', () => {
    const types = ['module', 'plugin', 'template'] as const;

    for (const type of types) {
      const json = loadModalJson(type);
      const jsonStr = JSON.stringify(json);
      expect(jsonStr, `${type}: download 아이콘 존재`).toContain('"download"');
      expect(jsonStr, `${type}: spinner 아이콘 존재`).toContain('"spinner"');
    }
  });

  it('3개 모달 모두 FileInput 컴포넌트 사용', () => {
    const types = ['module', 'plugin', 'template'] as const;

    for (const type of types) {
      const json = loadModalJson(type);
      const jsonStr = JSON.stringify(json);
      expect(jsonStr, `${type}: FileInput 사용`).toContain('"FileInput"');
    }
  });

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

  it('3개 모달 모두 필드별 에러 표시(text-red-500) 포함', () => {
    const types = ['module', 'plugin', 'template'] as const;

    for (const type of types) {
      const json = loadModalJson(type);
      const jsonStr = JSON.stringify(json);
      // 파일 에러 + URL 에러 모두 적색 텍스트
      const redErrorCount = (jsonStr.match(/text-red-500 dark:text-red-400 text-xs/g) || []).length;
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
          children: modalJson.children,
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

  it('초기 렌더링 시 파일 업로드 탭이 활성화됨', async () => {
    testUtils = createLayoutTest(createContentLayout(), { componentRegistry: registry });
    await testUtils.render();

    // TabNavigation이 렌더링됨
    const tabNav = screen.getByTestId('tab-navigation');
    expect(tabNav).toBeInTheDocument();
    expect(tabNav.getAttribute('data-variant')).toBe('underline');

    // FileInput이 보여야 함 (file_upload 탭이 기본)
    expect(screen.getByTestId('file-input')).toBeInTheDocument();
  });

  it('에러 배너는 moduleInstallError가 없으면 렌더링되지 않음', async () => {
    testUtils = createLayoutTest(createContentLayout(), { componentRegistry: registry });
    await testUtils.render();

    // 에러 아이콘(triangle-exclamation)이 없어야 함
    expect(screen.queryByTestId('icon-triangle-exclamation')).not.toBeInTheDocument();
  });

  it('moduleInstallError가 있으면 에러 배너가 렌더링됨', async () => {
    testUtils = createLayoutTest(
      createContentLayout({ moduleInstallError: '설치에 실패했습니다.' }),
      { componentRegistry: registry }
    );
    await testUtils.render();

    // 에러 아이콘이 보여야 함
    expect(screen.getByTestId('icon-triangle-exclamation')).toBeInTheDocument();
  });

  it('moduleInstallErrors?.file이 있으면 파일 필드에 적색 테두리 적용', async () => {
    testUtils = createLayoutTest(
      createContentLayout({
        moduleInstallErrors: { file: ['ZIP 파일만 업로드 가능합니다.'] },
      }),
      { componentRegistry: registry }
    );
    await testUtils.render();

    const fileInput = screen.getByTestId('file-input');
    expect(fileInput.className).toContain('border-red-500');
  });

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

  it('설치 중이 아닐 때 download 아이콘 표시, spinner 숨김', async () => {
    testUtils = createLayoutTest(
      createContentLayout({ moduleUploadFile: { name: 'test.zip' } }),
      { componentRegistry: registry }
    );
    await testUtils.render();

    expect(screen.getByTestId('icon-download')).toBeInTheDocument();
    expect(screen.queryByTestId('icon-spinner')).not.toBeInTheDocument();
  });
});
