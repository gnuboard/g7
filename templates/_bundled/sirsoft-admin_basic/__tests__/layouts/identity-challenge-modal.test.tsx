/**
 * @file identity-challenge-modal.test.tsx
 * @description 본인인증 공통 모달 파셜 정합성 회귀 테스트 (sirsoft-admin_basic)
 *
 * 검증 대상 (sirsoft-basic 측 동일 회귀 테스트와 평행):
 * 1. 모달 id 및 Modal composite 구조
 * 2. _global.identityChallenge 네임스페이스 일관 사용
 * 3. verify onSuccess 가 resolveIdentityChallenge { result: 'verified', token } 호출
 * 4. cancel onClick 이 resolveIdentityChallenge { result: 'cancelled' } 호출
 * 5. Extension Point 슬롯 — render_hint 별 + provider 별 슬롯 존재
 * 6. _admin_base.json modals 배열에 모달 파셜 등록
 * 7. routes.json 에 /identity/challenge admin 라우트 존재
 *
 * @since engine-v1.46.0
 */

import { describe, it, expect } from 'vitest';

import identityModal from '../../layouts/partials/_identity_challenge_modal.json';
import adminBase from '../../layouts/_admin_base.json';
import identityChallengePage from '../../layouts/auth/identity_challenge.json';
import routes from '../../routes.json';

type Node = {
  id?: string;
  type?: string;
  name?: string;
  if?: string;
  props?: Record<string, any>;
  children?: Node[] | string;
  text?: string;
  events?: Record<string, { actions?: Action[] }>;
  actions?: Action[];
  default?: Node[];
  slots?: Record<string, Node[]>;
};

type Action = {
  event?: string;
  type?: string;
  handler?: string;
  target?: string;
  params?: Record<string, any>;
  onSuccess?: Action[];
  onError?: Action[];
  actions?: Action[];
};

function walk(input: Node | Node[] | undefined, visit: (node: Node) => void): void {
  if (!input) return;
  const nodes = Array.isArray(input) ? input : [input];
  for (const node of nodes) {
    visit(node);
    if (node.default) walk(node.default, visit);
    if (node.children && Array.isArray(node.children)) walk(node.children as Node[], visit);
    if (node.slots) {
      for (const key of Object.keys(node.slots)) {
        walk(node.slots[key], visit);
      }
    }
  }
}

function collectActions(node: Node): Action[] {
  const out: Action[] = [];
  walk(node, (n) => {
    if (Array.isArray(n.actions)) out.push(...n.actions);
    if (n.events) {
      for (const ev of Object.values(n.events)) {
        if (Array.isArray(ev.actions)) out.push(...ev.actions);
      }
    }
  });
  return out;
}

function deepFindActions(actions: Action[], predicate: (a: Action) => boolean): Action[] {
  const result: Action[] = [];
  const visit = (list: Action[] | undefined) => {
    if (!list) return;
    for (const a of list) {
      if (predicate(a)) result.push(a);
      if (a.handler === 'sequence' && Array.isArray(a.params?.actions)) {
        visit(a.params!.actions as Action[]);
      }
      if (Array.isArray(a.actions)) visit(a.actions);
      if (Array.isArray(a.onSuccess)) visit(a.onSuccess);
      if (Array.isArray(a.onError)) visit(a.onError);
    }
  };
  visit(actions);
  return result;
}

describe('본인인증 공통 모달 (sirsoft-admin_basic) — engine-v1.46.0', () => {
  describe('모달 파셜 구조', () => {
    it('id 가 identity-challenge-modal', () => {
      expect((identityModal as any).id).toBe('identity-challenge-modal');
    });

    it('Modal composite 컴포넌트', () => {
      expect((identityModal as any).type).toBe('composite');
      expect((identityModal as any).name).toBe('Modal');
    });

    it('admin namespace i18n 키 사용 (admin.identity.challenge.*)', () => {
      const props = (identityModal as any).props ?? {};
      expect(props.title).toBe('$t:admin.identity.challenge.title');
    });

    it('자동 닫기 차단 (verify 또는 명시 cancel 만)', () => {
      const props = (identityModal as any).props ?? {};
      expect(props.closeOnBackdropClick).toBe(false);
      expect(props.closeOnEscape).toBe(false);
      expect(props.showCloseButton).toBe(false);
    });
  });

  describe('Extension Point 슬롯', () => {
    const slots: Node[] = [];
    walk(identityModal as unknown as Node, (n) => {
      if (n.type === 'extension_point') slots.push(n);
    });

    it('render_hint:text_code 슬롯 존재', () => {
      const slot = slots.find((s) => s.name === 'identity_provider_ui:text_code');
      expect(slot).toBeDefined();
      expect(Array.isArray(slot!.default)).toBe(true);
    });

    it('render_hint:link 슬롯 존재', () => {
      const slot = slots.find((s) => s.name === 'identity_provider_ui:link');
      expect(slot).toBeDefined();
    });

    it('provider 별 슬롯 존재', () => {
      const slot = slots.find((s) => s.name === 'identity_provider_ui:provider');
      expect(slot).toBeDefined();
    });
  });

  describe('Verify 흐름 — onSuccess → resolveIdentityChallenge', () => {
    const allActions = collectActions(identityModal as unknown as Node);
    const apiCalls = deepFindActions(allActions, (a) => a.handler === 'apiCall');

    it('verify 엔드포인트 호출 존재', () => {
      const verifyCalls = apiCalls.filter((a) =>
        typeof a.target === 'string' && a.target.includes('/verify'),
      );
      expect(verifyCalls.length).toBeGreaterThanOrEqual(1);
    });

    it('verify onSuccess 안에 resolveIdentityChallenge(verified, token)', () => {
      const verifyCall = apiCalls.find((a) =>
        typeof a.target === 'string' && a.target.includes('/verify'),
      );
      const resolves = deepFindActions(verifyCall!.onSuccess ?? [], (a) =>
        a.handler === 'resolveIdentityChallenge' && a.params?.result === 'verified',
      );
      expect(resolves.length).toBeGreaterThanOrEqual(1);
      expect(typeof resolves[0].params?.token).toBe('string');
    });

    it('verify onSuccess 가 closeModal 도 호출', () => {
      const verifyCall = apiCalls.find((a) =>
        typeof a.target === 'string' && a.target.includes('/verify'),
      );
      const closes = deepFindActions(verifyCall!.onSuccess ?? [], (a) => a.handler === 'closeModal');
      expect(closes.length).toBeGreaterThanOrEqual(1);
    });
  });

  describe('Cancel 흐름', () => {
    const allActions = collectActions(identityModal as unknown as Node);
    const cancels = deepFindActions(allActions, (a) =>
      a.handler === 'resolveIdentityChallenge' && a.params?.result === 'cancelled',
    );

    it('cancelled 통보 액션이 존재', () => {
      expect(cancels.length).toBeGreaterThanOrEqual(1);
    });
  });

  describe('상태 네임스페이스 일관성', () => {
    const allActions = collectActions(identityModal as unknown as Node);
    const setStates = deepFindActions(allActions, (a) => a.handler === 'setState');

    it('모든 setState 가 target=global', () => {
      const wrong = setStates.find((s) => (s.params?.target ?? 'local') !== 'global');
      expect(wrong).toBeUndefined();
    });

    it('setState 키들이 identityChallenge.* 사용', () => {
      for (const s of setStates) {
        const params = s.params ?? {};
        const dataKeys = Object.keys(params).filter((k) => k !== 'target');
        for (const k of dataKeys) {
          expect(k.startsWith('identityChallenge')).toBe(true);
        }
      }
    });
  });

  /**
   * 회귀 — 재전송 클릭 즉시 입력 코드 초기화 + Input onChange 표준 패턴.
   * sirsoft-basic 측과 동일한 회귀: events:{onChange:{...}} 비표준 래퍼는 엔진이 인식하지 않아
   * onChange 미발생 → 확인 버튼 영구 비활성. 또한 재전송은 응답을 기다리지 않고 즉시 code 비워야 함.
   */
  describe('회귀 — Input onChange 표준 패턴 + 재전송 즉시 code 초기화', () => {
    const allActions = collectActions(identityModal as unknown as Node);

    it('재전송 sequence 의 첫 setState 가 identityChallenge.code 를 즉시 빈 문자열로 초기화', () => {
      const setStates = deepFindActions(allActions, (a) => a.handler === 'setState');
      const cooldownReset = setStates.find(
        (s) => s.params && s.params['identityChallenge.resendCooldown'] === 30,
      );
      expect(cooldownReset).toBeDefined();
      expect(cooldownReset!.params!['identityChallenge.code']).toBe('');
    });

    function findCodeInput(): Node | undefined {
      let found: Node | undefined;
      walk(identityModal as unknown as Node, (n) => {
        if (n.name === 'Input' && (n.props as any)?.name === 'code') found = n;
      });
      return found;
    }

    it('code Input 이 비표준 events 래퍼를 사용하지 않는다', () => {
      const codeInput = findCodeInput();
      expect(codeInput).toBeDefined();
      expect((codeInput as any).events).toBeUndefined();
    });

    it('code Input 의 onChange 가 actions: [{event:"onChange", setState global}] 패턴', () => {
      const codeInput = findCodeInput();
      const actions = (codeInput as any).actions ?? [];
      const onChange = actions.find((a: Action) => a.event === 'onChange');
      expect(onChange).toBeDefined();
      expect(onChange.handler).toBe('setState');
      expect(onChange.params?.target).toBe('global');
      expect(onChange.params?.['identityChallenge.code']).toContain('$event');
    });
  });

  describe('Base 레이아웃 마운트 + 라우트', () => {
    it('_admin_base.json modals 배열에 _identity_challenge_modal 포함', () => {
      const modals = (adminBase as any).modals ?? [];
      const found = modals.find(
        (m: any) => typeof m.partial === 'string' && m.partial.endsWith('_identity_challenge_modal.json'),
      );
      expect(found).toBeDefined();
    });

    it('routes.json 에 */admin/identity/challenge 라우트 등록', () => {
      const routesList = (routes as any).routes ?? [];
      const challengeRoute = routesList.find(
        (r: any) => r.path === '*/admin/identity/challenge',
      );
      expect(challengeRoute).toBeDefined();
      expect(challengeRoute.layout).toBe('auth/identity_challenge');
      expect(challengeRoute.auth_required).toBe(false);
    });
  });

  describe('풀페이지 폴백 (auth/identity_challenge.json)', () => {
    const allActions = collectActions(identityChallengePage as unknown as Node);
    const apiCalls = deepFindActions(allActions, (a) => a.handler === 'apiCall');

    it('verify onSuccess 가 resolveIdentityChallenge { verified } 호출', () => {
      const verifyCall = apiCalls.find((a) =>
        typeof a.target === 'string' && a.target.includes('/verify'),
      );
      expect(verifyCall).toBeDefined();
      const resolves = deepFindActions(verifyCall!.onSuccess ?? [], (a) =>
        a.handler === 'resolveIdentityChallenge' && a.params?.result === 'verified',
      );
      expect(resolves.length).toBeGreaterThanOrEqual(1);
    });

    it('extends 가 _admin_base 로 설정되어 admin 컨텍스트 일관', () => {
      expect((identityChallengePage as any).extends).toBe('_admin_base');
    });
  });
});
