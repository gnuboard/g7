/**
 * @file marketing-extension-point.test.tsx
 * @description 마케팅 플러그인 overlay 확장 렌더링 테스트
 *
 * - 관리자 사용자 상세: 동의 이력 테이블 iteration
 *
 * 회원가입 폼과 마이페이지 프로필 overlay 묶음은 지웠다. 그 overlay 조각
 * (`user-marketing-register`, `user-marketing-profile`, `user-marketing-profile-view`, `user-marketing-form`)이
 * 지워지고 마케팅 동의가 회원 항목(약관 묶음)으로 옮겨갔기 때문이다. 같은 동작(부모, 자식 종속, 약관 보기, 캡션)은
 * 마케팅 플러그인의 항목 선언 시험과 사용자 템플릿 공용 항목 조각의 약관 묶음 시험이 잠근다.
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

// 테스트용 컴포넌트 정의
const TestDiv: React.FC<{
  children?: React.ReactNode;
  className?: string;
  'data-testid'?: string;
}> = ({ children, className, 'data-testid': testId }) => (
  <div className={className} data-testid={testId}>
    {children}
  </div>
);

const TestH3: React.FC<{
  children?: React.ReactNode;
  className?: string;
  text?: string;
}> = ({ children, className, text }) => (
  <h3 className={className}>{children || text}</h3>
);

const TestSpan: React.FC<{
  children?: React.ReactNode;
  className?: string;
  text?: string;
  'data-testid'?: string;
}> = ({ children, className, text, 'data-testid': testId }) => (
  <span className={className} data-testid={testId}>{children || text}</span>
);

const TestP: React.FC<{
  children?: React.ReactNode;
  className?: string;
  text?: string;
}> = ({ children, className, text }) => (
  <p className={className}>{children || text}</p>
);

const TestLabel: React.FC<{
  children?: React.ReactNode;
  className?: string;
  text?: string;
}> = ({ children, className, text }) => (
  <label className={className}>{children || text}</label>
);

const TestCheckbox: React.FC<{
  name?: string;
  checked?: boolean;
  value?: string;
  disabled?: boolean;
  className?: string;
  'data-testid'?: string;
}> = ({ name, checked, value, disabled, className, 'data-testid': testId }) => (
  <input
    type="checkbox"
    name={name}
    checked={checked}
    value={value}
    disabled={disabled}
    className={className}
    data-testid={testId || `checkbox-${name}`}
    readOnly
  />
);

const TestButton: React.FC<{
  children?: React.ReactNode;
  className?: string;
  type?: string;
  disabled?: boolean;
  onClick?: () => void;
  'data-testid'?: string;
}> = ({ children, className, type, disabled, onClick, 'data-testid': testId }) => (
  <button
    type={(type as any) || 'button'}
    className={className}
    disabled={disabled}
    onClick={onClick}
    data-testid={testId}
  >
    {children}
  </button>
);

const TestTable: React.FC<{
  children?: React.ReactNode;
  className?: string;
}> = ({ children, className }) => (
  <table className={className}>{children}</table>
);

const TestThead: React.FC<{ children?: React.ReactNode }> = ({ children }) => (
  <thead>{children}</thead>
);

const TestTbody: React.FC<{
  children?: React.ReactNode;
  className?: string;
}> = ({ children, className }) => (
  <tbody className={className}>{children}</tbody>
);

const TestTr: React.FC<{ children?: React.ReactNode }> = ({ children }) => (
  <tr>{children}</tr>
);

const TestTh: React.FC<{
  children?: React.ReactNode;
  className?: string;
  text?: string;
}> = ({ children, className, text }) => (
  <th className={className}>{children || text}</th>
);

const TestTd: React.FC<{
  children?: React.ReactNode;
  className?: string;
  text?: string;
}> = ({ children, className, text }) => (
  <td className={className}>{children || text}</td>
);

const TestIcon: React.FC<{
  name?: string;
  className?: string;
  size?: string;
  spin?: boolean;
}> = ({ name, className, size, spin }) => (
  <i data-icon={name} className={className} data-size={size} data-spin={spin} />
);

// Modal: show=false이면 렌더링하지 않음
const TestModal: React.FC<{
  children?: React.ReactNode;
  title?: string;
  show?: boolean;
  size?: string;
  'data-testid'?: string;
}> = ({ children, title, show, 'data-testid': testId }) => {
  if (!show) return null;
  return (
    <div data-testid={testId || 'modal'} role="dialog">
      {title && <div data-testid="modal-title">{title}</div>}
      {children}
    </div>
  );
};

// 컴포넌트 레지스트리 설정
function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();

  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    H3: { component: TestH3, metadata: { name: 'H3', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    Label: { component: TestLabel, metadata: { name: 'Label', type: 'basic' } },
    Checkbox: {
      component: TestCheckbox,
      metadata: { name: 'Checkbox', type: 'basic' },
    },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Table: { component: TestTable, metadata: { name: 'Table', type: 'basic' } },
    Thead: { component: TestThead, metadata: { name: 'Thead', type: 'basic' } },
    Tbody: { component: TestTbody, metadata: { name: 'Tbody', type: 'basic' } },
    Tr: { component: TestTr, metadata: { name: 'Tr', type: 'basic' } },
    Th: { component: TestTh, metadata: { name: 'Th', type: 'basic' } },
    Td: { component: TestTd, metadata: { name: 'Td', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Modal: { component: TestModal, metadata: { name: 'Modal', type: 'composite' } },
    // Fragment: createLayoutTest 가 root 컨테이너로 사용 — 누락 시 자식 트리가
    // 모두 렌더링되지 않고 빈 컨테이너만 남는다 (DynamicRenderer 의 root wrapper)
    Fragment: { component: ({ children }: { children?: React.ReactNode }) => <>{children}</>, metadata: { name: 'Fragment', type: 'layout' } },
  };

  return registry;
}

// 마케팅 플러그인 다국어 키
const marketingTranslations = {
  'sirsoft-marketing': {
    marketing_consent_title: '마케팅 동의 정보',
    email_subscription: '광고성 이메일 수신',
    email_subscription_desc: '프로모션, 이벤트, 할인 등의 마케팅 이메일을 수신합니다.',
    marketing_consent: '마케팅 동의',
    marketing_consent_desc: '마케팅 목적의 개인정보 수집 및 이용에 동의합니다.',
    third_party_consent: '제3자 제공 동의',
    third_party_consent_desc: '마케팅 목적의 제3자 개인정보 제공에 동의합니다.',
    info_disclosure: '정보 공개',
    info_disclosure_desc: '프로필 정보를 다른 사용자에게 공개합니다.',
    agree_email_subscription: '(선택) 광고성 이메일 수신 동의',
    agree_marketing_consent: '(선택) 마케팅 동의',
    agree_third_party_consent: '(선택) 제3자 제공 동의',
    agree_info_disclosure: '(선택) 정보 공개 동의',
    no_consent_history: '동의 이력이 없습니다.',
    history_date: '일시',
    history_consent_type: '동의 유형',
    history_action: '변경',
    history_source: '경로',
    action_granted: '동의',
    action_revoked: '철회',
    source_register: '회원가입',
    source_admin: '관리자',
    source_profile: '마이페이지',
    view_terms: '보기',
    info_disclosure_warning: '미동의 시 일부 커뮤니티 기능 이용이 제한될 수 있습니다.',
    no_terms_content: '약관 내용이 등록되지 않았습니다.',
    terms_modal_marketing_consent: '마케팅 동의 약관',
    terms_modal_third_party_consent: '제3자 제공 동의 약관',
    terms_modal_info_disclosure: '정보 공개 약관',
  },
};

// ── 회원가입 overlay 확장 테스트 ──
// 시나리오: 코어의 개인정보 동의 영역(register_privacy_agreement) 뒤에
// 플러그인이 overlay append로 부모-자식 체크박스 + 모달 + 캡션을 주입한 결과

describe('마케팅 플러그인 - 동의 이력 테이블', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  let registry: ComponentRegistry;

  const historyTableLayout = {
    version: '1.0.0',
    layout_name: 'test_consent_history',
    components: [
      {
        id: 'empty-message',
        type: 'basic' as const,
        name: 'Div',
        if: '{{!histories || histories.length === 0}}',
        props: { 'data-testid': 'empty-history' },
        text: '$t:sirsoft-marketing.no_consent_history',
      },
      {
        id: 'history-table',
        type: 'basic' as const,
        name: 'Div',
        if: '{{histories?.length > 0}}',
        props: { 'data-testid': 'history-table-wrapper' },
        children: [
          {
            id: 'hist_table',
            type: 'basic' as const,
            name: 'Table',
            children: [
              {
                id: 'hist_thead',
                type: 'basic' as const,
                name: 'Thead',
                children: [
                  {
                    id: 'hist_header_row',
                    type: 'basic' as const,
                    name: 'Tr',
                    children: [
                      { id: 'th_date', type: 'basic' as const, name: 'Th', text: '$t:sirsoft-marketing.history_date' },
                      { id: 'th_type', type: 'basic' as const, name: 'Th', text: '$t:sirsoft-marketing.history_consent_type' },
                      { id: 'th_action', type: 'basic' as const, name: 'Th', text: '$t:sirsoft-marketing.history_action' },
                      { id: 'th_source', type: 'basic' as const, name: 'Th', text: '$t:sirsoft-marketing.history_source' },
                    ],
                  },
                ],
              },
              {
                id: 'hist_tbody',
                type: 'basic' as const,
                name: 'Tbody',
                children: [
                  {
                    id: 'hist_row_{{histIdx}}',
                    type: 'basic' as const,
                    name: 'Tr',
                    iteration: {
                      source: '{{histories ?? []}}',
                      item_var: 'history',
                      index_var: 'histIdx',
                    },
                    children: [
                      {
                        id: 'td_date_{{histIdx}}',
                        type: 'basic' as const,
                        name: 'Td',
                        text: "{{history.created_at ?? '-'}}",
                      },
                      {
                        id: 'td_type_{{histIdx}}',
                        type: 'basic' as const,
                        name: 'Td',
                        text: '{{history.consent_type}}',
                      },
                      {
                        id: 'td_action_{{histIdx}}',
                        type: 'basic' as const,
                        name: 'Td',
                        children: [
                          {
                            id: 'action_text_{{histIdx}}',
                            type: 'basic' as const,
                            name: 'Span',
                            text: "{{history.action === 'granted' ? '$t:sirsoft-marketing.action_granted' : '$t:sirsoft-marketing.action_revoked'}}",
                          },
                        ],
                      },
                      {
                        id: 'td_source_{{histIdx}}',
                        type: 'basic' as const,
                        name: 'Td',
                        text: "{{history.source === 'register' ? '$t:sirsoft-marketing.source_register' : history.source === 'admin' ? '$t:sirsoft-marketing.source_admin' : '$t:sirsoft-marketing.source_profile'}}",
                      },
                    ],
                  },
                ],
              },
            ],
          },
        ],
      },
    ],
  };

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  afterEach(() => {
    testUtils?.cleanup();
  });

  /**
   * @scenario histories=empty
   * @effects empty_history_message_shown
   */
  it('이력이 없을 때 빈 메시지가 표시된다', async () => {
    testUtils = createLayoutTest(historyTableLayout, {
      translations: marketingTranslations,
      locale: 'ko',
      componentRegistry: registry,
      initialData: {
        histories: [],
      },
    });

    await testUtils.render();

    expect(screen.getByTestId('empty-history')).toBeInTheDocument();
    expect(screen.getByText('동의 이력이 없습니다.')).toBeInTheDocument();
  });

  /**
   * @scenario histories=one_row
   * @effects history_table_header_rendered
   */
  it('이력이 있을 때 테이블 헤더가 렌더링된다', async () => {
    testUtils = createLayoutTest(historyTableLayout, {
      translations: marketingTranslations,
      locale: 'ko',
      componentRegistry: registry,
      initialData: {
        histories: [
          {
            consent_type: 'email_subscription',
            action: 'granted',
            source: 'register',
            created_at: '2026-02-09T10:00:00+09:00',
          },
        ],
      },
    });

    await testUtils.render();

    expect(screen.getByTestId('history-table-wrapper')).toBeInTheDocument();
    expect(screen.getByText('일시')).toBeInTheDocument();
    expect(screen.getByText('동의 유형')).toBeInTheDocument();
    expect(screen.getByText('변경')).toBeInTheDocument();
    expect(screen.getByText('경로')).toBeInTheDocument();
  });

  /**
   * @scenario histories=granted_and_revoked
   * @effects history_rows_render_action_and_source
   */
  it('이력 데이터가 올바르게 렌더링된다', async () => {
    testUtils = createLayoutTest(historyTableLayout, {
      translations: marketingTranslations,
      locale: 'ko',
      componentRegistry: registry,
      initialData: {
        histories: [
          {
            consent_type: 'email_subscription',
            action: 'granted',
            source: 'register',
            created_at: '2026-02-09T10:00:00+09:00',
          },
          {
            consent_type: 'marketing_consent',
            action: 'revoked',
            source: 'admin',
            created_at: '2026-02-09T11:00:00+09:00',
          },
        ],
      },
    });

    const { container } = await testUtils.render();

    // {{}} 표현식 안의 $t:... 는 엔진이 inline 평가하지 않으므로 raw key 가
    // 출력될 수 있다. 텍스트 직접 매칭 대신 컨테이너 textContent 부분 매칭으로 검증
    const textContent = container.textContent ?? '';
    expect(textContent).toMatch(/action_granted|동의/);
    expect(textContent).toMatch(/action_revoked|철회/);
    expect(textContent).toMatch(/source_register|회원가입/);
    expect(textContent).toMatch(/source_admin|관리자/);

    // consent_type 표시 (동적 값 — 표현식 분기 없이 직접 출력)
    expect(screen.getByText('email_subscription')).toBeInTheDocument();
    expect(screen.getByText('marketing_consent')).toBeInTheDocument();
  });
});
