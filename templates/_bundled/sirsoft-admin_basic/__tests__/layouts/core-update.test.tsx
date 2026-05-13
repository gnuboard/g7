/**
 * @file core-update.test.tsx
 * @description 코어 업데이트 관련 레이아웃 테스트
 *
 * 테스트 대상:
 * - errors/maintenance.json (Admin): 관리자 maintenance 페이지
 * - errors/maintenance.json (User): 사용자 maintenance 페이지
 * - partials/admin_settings/_modal_core_update_result.json: 업데이트 확인 결과 모달
 * - partials/admin_settings/_modal_core_changelog.json: 코어 변경사항 모달
 * - partials/admin_settings/_modal_core_update_guide.json: 업데이트 터미널 안내 모달
 *
 * 검증 항목:
 * - maintenance 레이아웃 JSON 구조 (extends, meta, slots)
 * - 업데이트 결과 모달 구조 (버전 정보, 업데이트 가능/불가 섹션, 액션)
 * - 변경사항 모달 구조 (3단 iteration: entry → category → items)
 * - 업데이트 안내 모달 구조 (경고, 터미널 명령어)
 * - 실제 렌더링 검증 (createLayoutTest 활용)
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import * as fs from 'fs';
import * as path from 'path';

// ==============================
// 레이아웃 JSON 파일 로드 헬퍼
// ==============================

const ADMIN_PARTIALS_BASE = path.resolve(
  __dirname,
  '../../layouts/partials',
);

const ADMIN_LAYOUTS_BASE = path.resolve(
  __dirname,
  '../../layouts',
);

const USER_LAYOUTS_BASE = path.resolve(
  __dirname,
  '../../../sirsoft-basic/layouts',
);

function loadAdminLayout(relativePath: string): any {
  const fullPath = path.resolve(ADMIN_LAYOUTS_BASE, relativePath);
  return JSON.parse(fs.readFileSync(fullPath, 'utf-8'));
}

function loadUserLayout(relativePath: string): any {
  const fullPath = path.resolve(USER_LAYOUTS_BASE, relativePath);
  return JSON.parse(fs.readFileSync(fullPath, 'utf-8'));
}

function loadPartial(relativePath: string): any {
  const fullPath = path.resolve(ADMIN_PARTIALS_BASE, relativePath);
  return JSON.parse(fs.readFileSync(fullPath, 'utf-8'));
}

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

const TestH1: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => (
  <h1 className={className}>{children || text}</h1>
);

const TestIcon: React.FC<{
  name?: string;
  size?: string;
  className?: string;
}> = ({ name, size, className }) => (
  <i className={`icon-${name} ${className || ''}`} data-testid={`icon-${name}`} data-icon={name} data-size={size} />
);

const TestUl: React.FC<{
  className?: string;
  children?: React.ReactNode;
}> = ({ className, children }) => (
  <ul className={className}>{children}</ul>
);

const TestLi: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => (
  <li className={className}>{children || text}</li>
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
    H1: { component: TestH1, metadata: { name: 'H1', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Ul: { component: TestUl, metadata: { name: 'Ul', type: 'basic' } },
    Li: { component: TestLi, metadata: { name: 'Li', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };

  return registry;
}

// ==============================
// 모달 children $parent._local → _local 변환 헬퍼
// (모달 children을 테스트에서 직접 렌더링 시 $parent._local 컨텍스트가 없으므로 변환 필요)
// ==============================

function replaceParentLocalRefs(obj: any): any {
  if (typeof obj === 'string') {
    return obj.replace(/\$parent\._local/g, '_local');
  }
  if (Array.isArray(obj)) {
    return obj.map(replaceParentLocalRefs);
  }
  if (obj && typeof obj === 'object') {
    const result: any = {};
    for (const [key, value] of Object.entries(obj)) {
      result[key] = replaceParentLocalRefs(value);
    }
    return result;
  }
  return obj;
}

// ==============================
// JSON 트리 탐색 헬퍼
// ==============================

function findInTree(nodes: any[], predicate: (node: any) => boolean): any | undefined {
  for (const node of nodes) {
    if (predicate(node)) return node;
    if (node.children) {
      const found = findInTree(node.children, predicate);
      if (found) return found;
    }
  }
  return undefined;
}

function findAllInTree(nodes: any[], predicate: (node: any) => boolean): any[] {
  const results: any[] = [];
  for (const node of nodes) {
    if (predicate(node)) results.push(node);
    if (node.children) {
      results.push(...findAllInTree(node.children, predicate));
    }
  }
  return results;
}

// ==============================
// 샘플 데이터
// ==============================

const sampleChangelog = [
  {
    version: '1.1.0',
    date: '2026-03-05',
    categories: [
      {
        name: 'Added',
        items: ['코어 업데이트 기능 추가', '유지보수 모드 지원'],
      },
      {
        name: 'Fixed',
        items: ['설정 저장 버그 수정'],
      },
    ],
  },
];

const multiVersionChangelog = [
  {
    version: '1.2.0',
    date: '2026-03-07',
    categories: [
      {
        name: 'Added',
        items: ['신규 기능 X 추가'],
      },
    ],
  },
  {
    version: '1.1.0',
    date: '2026-03-05',
    categories: [
      {
        name: 'Changed',
        items: ['기존 기능 Y 변경'],
      },
      {
        name: 'Fixed',
        items: ['버그 Z 수정', '버그 W 수정'],
      },
    ],
  },
];

const translations = {
  admin: {
    settings: {
      info: {
        update_result_title: '업데이트 확인 결과',
        current_version: '현재 버전',
        latest_version: '최신 버전',
        update_available_message: '새로운 업데이트가 있습니다.',
        no_update_message: '현재 최신 버전을 사용 중입니다.',
        view_changelog: '변경사항 보기',
        how_to_update: '업데이트 방법',
        changelog_title: '변경사항',
        no_changelog: '변경사항 정보가 없습니다.',
        update_guide_title: '업데이트 방법',
        update_guide_warning: '서버 터미널에서 실행하세요.',
        update_guide_desc: '아래 명령어를 실행하여 업데이트합니다.',
        update_guide_note: '업데이트 전 백업을 권장합니다.',
      },
    },
  },
  errors: {
    maintenance: {
      title: '시스템 점검 중',
      message: '현재 시스템 업데이트가 진행 중입니다.',
      description: '잠시 후 다시 접속해 주세요.',
    },
  },
  error: {
    maintenance: {
      title: '시스템 점검 중',
      description: '잠시 후 다시 접속해 주세요.',
      back_to_home: '홈으로 돌아가기',
    },
  },
};

// ============================================================
// 1. JSON 구조 검증 테스트
// ============================================================

describe('코어 업데이트 레이아웃 JSON 구조', () => {
  describe('Admin Maintenance 레이아웃', () => {
    let layout: any;

    beforeEach(() => {
      layout = loadAdminLayout('errors/maintenance.json');
    });

    it('_admin_base를 상속한다', () => {
      expect(layout.extends).toBe('_admin_base');
    });

    it('meta에 에러 레이아웃 플래그와 503 코드를 갖는다', () => {
      expect(layout.meta.is_error_layout).toBe(true);
      expect(layout.meta.error_code).toBe(503);
    });

    it('i18n 제목을 $t:errors.maintenance.title로 설정한다', () => {
      expect(layout.meta.title).toBe('$t:errors.maintenance.title');
    });

    it('content 슬롯에 maintenance 컨테이너가 있다', () => {
      expect(layout.slots.content).toHaveLength(1);
      expect(layout.slots.content[0].id).toBe('maintenance_container');
    });

    it('spinning gear 아이콘이 포함되어 있다', () => {
      const icon = findInTree(layout.slots.content, (n: any) => n.name === 'Icon');
      expect(icon).toBeDefined();
      expect(icon.props.name).toBe('gear');
      expect(icon.props.className).toContain('animate-spin');
    });

    it('제목, 메시지, 설명 텍스트가 i18n 키를 사용한다', () => {
      const h1 = findInTree(layout.slots.content, (n: any) => n.name === 'H1');
      expect(h1.text).toBe('$t:errors.maintenance.title');

      const pNodes = findAllInTree(layout.slots.content, (n: any) => n.name === 'P');
      expect(pNodes.length).toBeGreaterThanOrEqual(2);
      expect(pNodes[0].text).toBe('$t:errors.maintenance.message');
      expect(pNodes[1].text).toBe('$t:errors.maintenance.description');
    });
  });

  describe('User Maintenance 레이아웃', () => {
    let layout: any;

    beforeEach(() => {
      layout = loadUserLayout('errors/maintenance.json');
    });

    it('_user_base를 상속한다', () => {
      expect(layout.extends).toBe('_user_base');
    });

    it('meta에 에러 레이아웃 플래그를 갖는다', () => {
      expect(layout.meta.is_error_layout).toBe(true);
    });

    it('error. 네임스페이스의 i18n 키를 사용한다', () => {
      expect(layout.meta.title).toBe('$t:error.maintenance.title');
    });

    it('홈으로 돌아가기 버튼이 포함되어 있다', () => {
      const button = findInTree(layout.slots.content, (n: any) => n.name === 'Button');
      expect(button).toBeDefined();
      expect(button.actions).toBeDefined();
      const navigateAction = button.actions.find((a: any) => a.handler === 'navigate');
      expect(navigateAction).toBeDefined();
      expect(navigateAction.params.path).toBe('/');
    });

    it('spinning gear 아이콘이 포함되어 있다', () => {
      const icon = findInTree(layout.slots.content, (n: any) => n.name === 'Icon' && n.props?.name === 'gear');
      expect(icon).toBeDefined();
      expect(icon.props.className).toContain('animate-spin');
    });
  });

  describe('코어 업데이트 결과 모달', () => {
    let modal: any;

    beforeEach(() => {
      modal = loadPartial('admin_settings/_modal_core_update_result.json');
    });

    it('모달 ID가 core_update_result_modal이다', () => {
      expect(modal.id).toBe('core_update_result_modal');
    });

    it('모달 사이즈가 md이다', () => {
      expect(modal.props.size).toBe('md');
    });

    it('현재 버전과 최신 버전 표시 영역이 있다', () => {
      const spans = findAllInTree(modal.children, (n: any) => n.name === 'Span');
      const currentVersionText = spans.find((s: any) => s.text === '$t:admin.settings.info.current_version');
      const latestVersionText = spans.find((s: any) => s.text === '$t:admin.settings.info.latest_version');
      expect(currentVersionText).toBeDefined();
      expect(latestVersionText).toBeDefined();
    });

    it('업데이트 가능 섹션이 if 조건으로 제어된다', () => {
      const updateSection = findInTree(modal.children, (n: any) => n.id === 'update_available_section');
      expect(updateSection).toBeDefined();
      expect(updateSection.if).toBe('{{$parent._local.coreUpdateInfo?.update_available}}');
    });

    it('업데이트 없음 섹션이 if 조건으로 제어된다', () => {
      const noUpdateSection = findInTree(modal.children, (n: any) => n.id === 'no_update_section');
      expect(noUpdateSection).toBeDefined();
      expect(noUpdateSection.if).toBe('{{!$parent._local.coreUpdateInfo?.update_available}}');
    });

    it('변경사항 보기 버튼에 changelog API 호출 액션이 있다 (closeModal 없이 직접 apiCall)', () => {
      const buttons = findAllInTree(modal.children, (n: any) => n.name === 'Button');
      const viewChangelogBtn = buttons.find((b: any) => b.text === '$t:admin.settings.info.view_changelog');
      expect(viewChangelogBtn).toBeDefined();

      // closeModal 없이 직접 apiCall (child 모달을 result 모달 위에 겹쳐서 열기)
      const apiCallAction = viewChangelogBtn.actions[0];
      expect(apiCallAction.handler).toBe('apiCall');
      expect(apiCallAction.target).toBe('/api/admin/core-update/changelog');
      expect(apiCallAction.params.method).toBe('GET');

      // onSuccess에서 openModal로 changelog 모달 열기
      const openModalInSuccess = apiCallAction.onSuccess.find((a: any) => a.handler === 'openModal');
      expect(openModalInSuccess).toBeDefined();
      expect(openModalInSuccess.target).toBe('core_changelog_modal');
    });

    it('업데이트 방법 버튼에 openModal 액션이 있다 (closeModal 없이 직접 openModal)', () => {
      const buttons = findAllInTree(modal.children, (n: any) => n.name === 'Button');
      const howToUpdateBtn = buttons.find((b: any) => b.text === '$t:admin.settings.info.how_to_update');
      expect(howToUpdateBtn).toBeDefined();

      // closeModal 없이 직접 openModal (child 모달을 result 모달 위에 겹쳐서 열기)
      const openModalAction = howToUpdateBtn.actions[0];
      expect(openModalAction.handler).toBe('openModal');
      expect(openModalAction.target).toBe('core_update_guide_modal');
    });
  });

  describe('업데이트 확인 버튼 (정보 탭)', () => {
    let tabInfo: any;

    beforeEach(() => {
      tabInfo = loadPartial('admin_settings/_tab_info.json');
    });

    it('업데이트 확인 성공 시 openModal이 target 형식을 사용한다', () => {
      // 업데이트 확인 버튼 찾기: sequence → apiCall → onSuccess → openModal
      const allButtons = findAllInTree(
        Array.isArray(tabInfo) ? tabInfo : [tabInfo],
        (n: any) => n.name === 'Button',
      );

      // apiCall target이 /api/admin/core-update/check인 sequence를 가진 버튼 찾기
      const checkUpdateBtn = allButtons.find((btn: any) => {
        const action = btn.actions?.[0];
        if (action?.handler !== 'sequence') return false;
        return action.actions?.some(
          (a: any) => a.handler === 'apiCall' && a.target === '/api/admin/core-update/check',
        );
      });
      expect(checkUpdateBtn).toBeDefined();

      const sequenceAction = checkUpdateBtn.actions[0];
      const apiCallAction = sequenceAction.actions.find((a: any) => a.handler === 'apiCall');

      // onSuccess의 openModal이 target 형식을 사용하는지 검증
      const openModalInSuccess = apiCallAction.onSuccess.find(
        (a: any) => a.handler === 'openModal',
      );
      expect(openModalInSuccess).toBeDefined();
      expect(openModalInSuccess.target).toBe('core_update_result_modal');
      // params.id 형식이 아닌 target 형식이어야 함 (params.id는 모달 ID가 undefined로 전달됨)
      expect(openModalInSuccess.params?.id).toBeUndefined();
    });
  });

  describe('코어 변경사항 모달', () => {
    let modal: any;

    beforeEach(() => {
      modal = loadPartial('admin_settings/_modal_core_changelog.json');
    });

    it('모달 ID가 core_changelog_modal이다', () => {
      expect(modal.id).toBe('core_changelog_modal');
    });

    it('모달 사이즈가 lg이다', () => {
      expect(modal.props.size).toBe('lg');
    });

    it('빈 상태 메시지가 if 조건으로 제어된다', () => {
      const emptyDiv = findInTree(modal.children, (n: any) =>
        n.if === '{{!$parent._local.coreChangelog || $parent._local.coreChangelog.length === 0}}'
      );
      expect(emptyDiv).toBeDefined();
      expect(emptyDiv.text).toBe('$t:admin.settings.info.no_changelog');
    });

    it('3단 iteration 구조를 갖는다 (entry → category → items)', () => {
      // 1단: coreChangelog entries ($parent._local 경로 사용 - 모달 컨텍스트)
      const entryIteration = findInTree(modal.children, (n: any) =>
        n.iteration?.source === '$parent._local.coreChangelog'
      );
      expect(entryIteration).toBeDefined();
      expect(entryIteration.iteration.item_var).toBe('entry');

      // 2단: categories
      const categoryIteration = findInTree(entryIteration.children, (n: any) =>
        n.iteration?.source === 'entry.categories'
      );
      expect(categoryIteration).toBeDefined();
      expect(categoryIteration.iteration.item_var).toBe('category');

      // 3단: items
      const itemIteration = findInTree(categoryIteration.children, (n: any) =>
        n.iteration?.source === 'category.items'
      );
      expect(itemIteration).toBeDefined();
      expect(itemIteration.iteration.item_var).toBe('changeItem');
      expect(itemIteration.text).toBe('{{changeItem}}');
    });

    it('버전과 날짜 표시 영역이 있다', () => {
      const entryIteration = findInTree(modal.children, (n: any) =>
        n.iteration?.source === '$parent._local.coreChangelog'
      );
      const versionSpan = findInTree(entryIteration.children, (n: any) =>
        n.text === 'v{{entry.version}}'
      );
      expect(versionSpan).toBeDefined();

      const dateSpan = findInTree(entryIteration.children, (n: any) =>
        n.text === '{{entry.date}}'
      );
      expect(dateSpan).toBeDefined();
      expect(dateSpan.if).toBe('{{entry.date}}');
    });
  });

  describe('코어 업데이트 안내 모달', () => {
    let modal: any;

    beforeEach(() => {
      modal = loadPartial('admin_settings/_modal_core_update_guide.json');
    });

    it('모달 ID가 core_update_guide_modal이다', () => {
      expect(modal.id).toBe('core_update_guide_modal');
    });

    it('모달 사이즈가 md이다', () => {
      expect(modal.props.size).toBe('md');
    });

    it('경고 아이콘(triangle-exclamation)이 포함되어 있다', () => {
      const icon = findInTree(modal.children, (n: any) =>
        n.name === 'Icon' && n.props?.name === 'triangle-exclamation'
      );
      expect(icon).toBeDefined();
    });

    it('터미널 명령어 표시 영역이 있다', () => {
      const commandText = findInTree(modal.children, (n: any) =>
        n.text === '$ php artisan core:update'
      );
      expect(commandText).toBeDefined();
      expect(commandText.props.className).toContain('font-mono');
    });

    it('노란색 경고 배경이 있다', () => {
      const warningDiv = findInTree(modal.children, (n: any) =>
        n.props?.className?.includes('bg-yellow-50')
      );
      expect(warningDiv).toBeDefined();
    });
  });
});

// ============================================================
// 2. 렌더링 검증 테스트
// ============================================================

describe('코어 업데이트 레이아웃 렌더링', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  afterEach(() => {
    (registry as any).registry = {};
  });

  describe('Admin Maintenance 페이지 렌더링', () => {
    it('제목, 메시지, 설명이 렌더링된다', async () => {
      const layout = loadAdminLayout('errors/maintenance.json');
      const contentLayout = {
        version: '1.0.0',
        layout_name: 'maintenance_test',
        slots: {
          content: layout.slots.content,
        },
      };

      const testUtils = createLayoutTest(contentLayout, {
        translations,
      });

      await testUtils.render();

      expect(screen.getByText('시스템 점검 중')).toBeInTheDocument();
      expect(screen.getByText('현재 시스템 업데이트가 진행 중입니다.')).toBeInTheDocument();
      expect(screen.getByText('잠시 후 다시 접속해 주세요.')).toBeInTheDocument();

      testUtils.cleanup();
    });

    it('spinning gear 아이콘이 렌더링된다', async () => {
      const layout = loadAdminLayout('errors/maintenance.json');
      const contentLayout = {
        version: '1.0.0',
        layout_name: 'maintenance_icon_test',
        slots: {
          content: layout.slots.content,
        },
      };

      const testUtils = createLayoutTest(contentLayout, {
        translations,
      });

      await testUtils.render();

      const icon = screen.getByTestId('icon-gear');
      expect(icon).toBeInTheDocument();

      testUtils.cleanup();
    });
  });

  describe('업데이트 확인 결과 모달 렌더링', () => {
    it('업데이트가 있을 때 업데이트 가능 섹션이 표시된다', async () => {
      const modal = loadPartial('admin_settings/_modal_core_update_result.json');
      const layout = {
        version: '1.0.0',
        layout_name: 'update_result_test',
        initLocal: {
          coreUpdateInfo: {
            update_available: true,
            current_version: '1.0.0',
            latest_version: '1.1.0',
          },
        },
        slots: {
          content: replaceParentLocalRefs(modal.children),
        },
      };

      const testUtils = createLayoutTest(layout, {
        translations,
      });

      await testUtils.render();

      expect(screen.getByText('1.0.0')).toBeInTheDocument();
      expect(screen.getByText('1.1.0')).toBeInTheDocument();
      expect(screen.getByText('새로운 업데이트가 있습니다.')).toBeInTheDocument();

      testUtils.cleanup();
    });

    it('업데이트가 없을 때 최신 버전 메시지가 표시된다', async () => {
      const modal = loadPartial('admin_settings/_modal_core_update_result.json');
      const layout = {
        version: '1.0.0',
        layout_name: 'no_update_test',
        initLocal: {
          coreUpdateInfo: {
            update_available: false,
            current_version: '1.1.0',
            latest_version: '1.1.0',
          },
        },
        slots: {
          content: replaceParentLocalRefs(modal.children),
        },
      };

      const testUtils = createLayoutTest(layout, {
        translations,
      });

      await testUtils.render();

      expect(screen.getByText('현재 최신 버전을 사용 중입니다.')).toBeInTheDocument();

      testUtils.cleanup();
    });
  });

  describe('변경사항 모달 렌더링', () => {
    it('changelog 데이터가 없을 때 빈 상태 메시지가 표시된다', async () => {
      const modal = loadPartial('admin_settings/_modal_core_changelog.json');
      const layout = {
        version: '1.0.0',
        layout_name: 'changelog_empty_test',
        initLocal: {
          coreChangelog: [],
        },
        slots: {
          content: replaceParentLocalRefs(modal.children),
        },
      };

      const testUtils = createLayoutTest(layout, {
        translations,
      });

      await testUtils.render();

      expect(screen.getByText('변경사항 정보가 없습니다.')).toBeInTheDocument();

      testUtils.cleanup();
    });

    it('changelog 데이터로 버전별 변경사항이 렌더링된다', async () => {
      const modal = loadPartial('admin_settings/_modal_core_changelog.json');
      const layout = {
        version: '1.0.0',
        layout_name: 'changelog_render_test',
        initLocal: {
          coreChangelog: sampleChangelog,
        },
        slots: {
          content: replaceParentLocalRefs(modal.children),
        },
      };

      const testUtils = createLayoutTest(layout, {
        translations,
      });

      await testUtils.render();

      expect(screen.getByText('v1.1.0')).toBeInTheDocument();
      expect(screen.getByText('2026-03-05')).toBeInTheDocument();
      expect(screen.getByText('Added')).toBeInTheDocument();
      expect(screen.getByText('코어 업데이트 기능 추가')).toBeInTheDocument();
      expect(screen.getByText('유지보수 모드 지원')).toBeInTheDocument();
      expect(screen.getByText('Fixed')).toBeInTheDocument();
      expect(screen.getByText('설정 저장 버그 수정')).toBeInTheDocument();

      testUtils.cleanup();
    });

    it('여러 버전의 changelog가 모두 렌더링된다', async () => {
      const modal = loadPartial('admin_settings/_modal_core_changelog.json');
      const layout = {
        version: '1.0.0',
        layout_name: 'changelog_multi_test',
        initLocal: {
          coreChangelog: multiVersionChangelog,
        },
        slots: {
          content: replaceParentLocalRefs(modal.children),
        },
      };

      const testUtils = createLayoutTest(layout, {
        translations,
      });

      await testUtils.render();

      expect(screen.getByText('v1.2.0')).toBeInTheDocument();
      expect(screen.getByText('v1.1.0')).toBeInTheDocument();
      expect(screen.getByText('신규 기능 X 추가')).toBeInTheDocument();
      expect(screen.getByText('기존 기능 Y 변경')).toBeInTheDocument();
      expect(screen.getByText('버그 Z 수정')).toBeInTheDocument();
      expect(screen.getByText('버그 W 수정')).toBeInTheDocument();

      testUtils.cleanup();
    });
  });

  describe('업데이트 안내 모달 렌더링', () => {
    it('경고 메시지와 터미널 명령어가 렌더링된다', async () => {
      const modal = loadPartial('admin_settings/_modal_core_update_guide.json');
      const layout = {
        version: '1.0.0',
        layout_name: 'update_guide_test',
        slots: {
          content: modal.children,
        },
      };

      const testUtils = createLayoutTest(layout, {
        translations,
      });

      await testUtils.render();

      expect(screen.getByText('서버 터미널에서 실행하세요.')).toBeInTheDocument();
      expect(screen.getByText('$ php artisan core:update')).toBeInTheDocument();
      expect(screen.getByTestId('icon-triangle-exclamation')).toBeInTheDocument();

      testUtils.cleanup();
    });
  });
});
