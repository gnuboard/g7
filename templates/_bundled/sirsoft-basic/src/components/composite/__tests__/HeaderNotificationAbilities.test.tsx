// e2e:allow Vitest 컴포넌트 렌더 테스트(Header → NotificationCenter 권한 prop 전달 검증). 화면 노출은 모바일 알림센터와 같은 원천을 쓰며 브라우저 실측으로 별도 확인.
/**
 * @file HeaderNotificationAbilities.test.tsx
 * @description 데스크톱 Header 가 알림 수정·삭제 권한을 NotificationCenter 에 전달하는지 검증.
 *
 * 권한이 없을 때는 레이아웃(_user_base)이 abilities 를 읽어 false 를 명시해 넘기고, Header 는 그 값을
 * NotificationCenter 에 그대로 전달한다. 값을 받지 못하면(운영자가 루트 레이아웃을 고쳐 keep 으로
 * 업데이트해 새 prop 바인딩이 없는 사이트) 관리자·사용자 NotificationCenter 와 같은 기본값 true 로
 * 넘긴다 — false 로 두면 [모두 읽음]·삭제가 숨고 닫을 때 읽음 처리도 빠진다. 최종 방어선은 서버 403.
 * NotificationCenter 를 모킹해 Header 가 넘긴 prop 을 그대로 확인한다.
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { render } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

const captured: Array<Record<string, unknown>> = [];

vi.mock('../NotificationCenter', () => ({
  NotificationCenter: (props: Record<string, unknown>) => {
    captured.push(props);
    return <div data-testid="notification-center-mock" />;
  },
}));

import Header from '../Header';

beforeEach(() => {
  captured.length = 0;
  (window as any).G7Core = {
    t: (key: string) => key,
    useResponsive: () => ({ width: 1280, breakpoint: 'desktop' }),
    dispatch: vi.fn(),
    state: { getLocal: () => ({}), getGlobal: () => ({}) },
    getSlotContext: () => ({
      getSlotComponents: () => [],
      subscribeToSlot: () => () => {},
      isEnabled: true,
    }),
  };
  (window as any).__slotContextValue = (window as any).G7Core.getSlotContext();
});

afterEach(() => {
  vi.clearAllMocks();
  delete (window as any).G7Core;
  delete (window as any).__slotContextValue;
});

const user = { uuid: 'u-1', name: '회원' } as any;

const lastProps = () => captured[captured.length - 1];

describe('Header — 알림센터 권한 prop 전달', () => {
  // @scenario component=Header,can_update=false,can_delete=false
  // @effects header_passes_notification_abilities
  it('권한이 없으면 canUpdate/canDelete 를 false 로 넘긴다', () => {
    render(
      <Header
        siteName="S"
        user={user}
        availableLocales={['ko']}
        currentLocale="ko"
        notificationCanUpdate={false}
        notificationCanDelete={false}
      />,
    );
    expect(captured.length).toBeGreaterThan(0);
    expect(lastProps().canUpdate).toBe(false);
    expect(lastProps().canDelete).toBe(false);
  });

  // @scenario component=Header,can_update=true,can_delete=true
  // @effects header_passes_notification_abilities
  it('권한이 있으면 canUpdate/canDelete 를 true 로 넘긴다', () => {
    render(
      <Header
        siteName="S"
        user={user}
        availableLocales={['ko']}
        currentLocale="ko"
        notificationCanUpdate
        notificationCanDelete
      />,
    );
    expect(lastProps().canUpdate).toBe(true);
    expect(lastProps().canDelete).toBe(true);
  });

  // @scenario component=Header,state=enabled
  // @effects header_passes_notification_abilities
  it('권한 값을 받지 못하면 NotificationCenter 와 같은 기본값(true)으로 넘긴다 — keep 업데이트 사이트 회귀', () => {
    render(<Header siteName="S" user={user} availableLocales={['ko']} currentLocale="ko" />);
    expect(captured.length).toBeGreaterThan(0);
    expect(lastProps().canUpdate).toBe(true);
    expect(lastProps().canDelete).toBe(true);
  });
});
