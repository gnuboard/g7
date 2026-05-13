/**
 * @file identity-challenge-modal.test.tsx
 * @description 본인인증 공통 모달 파셜 정합성 회귀 테스트
 *
 * 검증 대상:
 * 1. 모달 id 및 Modal composite 구조
 * 2. _global.identityChallenge 네임스페이스 일관 사용 (launcher → 모달 → resolver 단일 통로)
 * 3. verify onSuccess 가 resolveIdentityChallenge { result: 'verified', token } 호출
 * 4. cancel onClick 이 resolveIdentityChallenge { result: 'cancelled' } 호출
 * 5. Extension Point 슬롯 — render_hint 별 + provider 별 슬롯 존재
 * 6. _user_base.json modals 배열에 모달 파셜이 등록됨
 *
 * @since engine-v1.46.0
 */

import { describe, it, expect } from 'vitest';

import identityModal from '../../layouts/partials/_identity_challenge_modal.json';
import userBase from '../../layouts/_user_base.json';
import identityChallengePage from '../../layouts/auth/identity_challenge.json';

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

type NodeWithSlots = Node & { slots?: Record<string, Node[]> };

function walk(input: Node | Node[] | undefined, visit: (node: Node) => void): void {
  if (!input) return;
  const nodes = Array.isArray(input) ? input : [input];
  for (const node of nodes as NodeWithSlots[]) {
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

function flattenSequence(action: Action): Action[] {
  if (action.handler === 'sequence' && Array.isArray(action.params?.actions)) {
    return action.params!.actions as Action[];
  }
  return [action];
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

describe('본인인증 공통 모달 (sirsoft-basic) — engine-v1.46.0', () => {
  describe('모달 파셜 구조', () => {
    it('id 가 identity-challenge-modal', () => {
      expect((identityModal as any).id).toBe('identity-challenge-modal');
    });

    it('Modal composite 컴포넌트', () => {
      expect((identityModal as any).type).toBe('composite');
      expect((identityModal as any).name).toBe('Modal');
    });

    it('backdrop/escape/closeButton 으로 자동 닫기 차단 (verify 또는 명시 cancel 만 허용)', () => {
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
      expect((slot!.default as Node[]).length).toBeGreaterThan(0);
    });

    it('render_hint:link 슬롯 존재', () => {
      const slot = slots.find((s) => s.name === 'identity_provider_ui:link');
      expect(slot).toBeDefined();
    });

    it('provider 별 슬롯 존재 (외부 IDV 플러그인 진입점)', () => {
      const slot = slots.find((s) => s.name === 'identity_provider_ui:provider');
      expect(slot).toBeDefined();
    });
  });

  describe('Verify 흐름 — onSuccess → resolveIdentityChallenge { verified, token }', () => {
    const allActions = collectActions(identityModal as unknown as Node);
    const apiCallActions = allActions
      .flatMap(flattenSequence)
      .filter((a) => a.handler === 'apiCall');

    it('verify 엔드포인트 호출이 존재한다', () => {
      const verifyCalls = apiCallActions.filter((a) =>
        typeof a.target === 'string' && a.target.includes('/verify'),
      );
      expect(verifyCalls.length).toBeGreaterThanOrEqual(1);
    });

    it('verify onSuccess 안에 resolveIdentityChallenge(verified, token) 가 있다', () => {
      const verifyCall = apiCallActions.find((a) =>
        typeof a.target === 'string' && a.target.includes('/verify'),
      );
      expect(verifyCall).toBeDefined();

      const resolves = deepFindActions(verifyCall!.onSuccess ?? [], (a) =>
        a.handler === 'resolveIdentityChallenge',
      );
      expect(resolves.length).toBeGreaterThanOrEqual(1);
      expect(resolves[0].params?.result).toBe('verified');
      expect(typeof resolves[0].params?.token).toBe('string');
      expect(resolves[0].params?.token).toContain('verification_token');
    });

    it('verify onSuccess 가 closeModal 도 호출한다', () => {
      const verifyCall = apiCallActions.find((a) =>
        typeof a.target === 'string' && a.target.includes('/verify'),
      );
      const closes = deepFindActions(verifyCall!.onSuccess ?? [], (a) =>
        a.handler === 'closeModal',
      );
      expect(closes.length).toBeGreaterThanOrEqual(1);
    });
  });

  describe('Cancel 흐름 — 서버 cancel API 호출 + resolveIdentityChallenge { cancelled }', () => {
    const allActions = collectActions(identityModal as unknown as Node);
    const cancelChain = allActions
      .flatMap(flattenSequence)
      .find((a) =>
        a.handler === 'resolveIdentityChallenge' && a.params?.result === 'cancelled',
      ) ?? deepFindActions(allActions, (a) =>
        a.handler === 'resolveIdentityChallenge' && a.params?.result === 'cancelled',
      )[0];

    /**
     * cancel 버튼 sequence 첫 액션을 추출.
     * 취소 버튼은 sequence 로 [apiCall(cancel), resolveIdentityChallenge(cancelled), closeModal] 을 호출.
     */
    function findCancelSequence(): Action | undefined {
      const sequences = deepFindActions(allActions, (a) => a.handler === 'sequence');
      return sequences.find((seq) => {
        const inner = (seq.params?.actions ?? []) as Action[];
        return inner.some(
          (a) =>
            a.handler === 'resolveIdentityChallenge' && a.params?.result === 'cancelled',
        );
      });
    }

    it('cancelled 통보 액션이 존재한다', () => {
      expect(cancelChain).toBeDefined();
    });

    it('cancel sequence 의 첫 액션이 서버 cancel API 호출 — audit trail 정합', () => {
      const seq = findCancelSequence();
      expect(seq).toBeDefined();
      const inner = (seq!.params!.actions ?? []) as Action[];
      const apiCall = inner.find((a) => a.handler === 'apiCall');
      expect(apiCall).toBeDefined();
      // apiCall 표준: target 은 액션 top-level, params 안은 method/body 만
      expect(apiCall!.params?.method).toBe('POST');
      expect(typeof apiCall!.target).toBe('string');
      expect(apiCall!.target!).toContain('/api/identity/challenges/');
      expect(apiCall!.target!).toContain('/cancel');
      expect(apiCall!.target!).toContain('_global.identityChallenge.challenge_id');
    });

    it('cancel apiCall 이 challenge 발급된 경우에만 호출 (if 가드)', () => {
      const seq = findCancelSequence();
      const inner = (seq!.params!.actions ?? []) as Action[];
      const apiCall = inner.find((a) => a.handler === 'apiCall');
      expect((apiCall as any).if).toContain('_global.identityChallenge?.challenge_id');
    });

    it('cancel apiCall 실패해도 모달 닫기는 진행 (onError suppress)', () => {
      const seq = findCancelSequence();
      const inner = (seq!.params!.actions ?? []) as Action[];
      const apiCall = inner.find((a) => a.handler === 'apiCall');
      const onError = apiCall!.onError ?? [];
      expect(onError.length).toBeGreaterThanOrEqual(1);
      expect(onError[0].handler).toBe('suppress');
    });

    it('cancel sequence 가 [apiCall, resolveIdentityChallenge, closeModal] 순서', () => {
      const seq = findCancelSequence();
      const inner = (seq!.params!.actions ?? []) as Action[];
      const handlers = inner.map((a) => a.handler);
      expect(handlers).toEqual(['apiCall', 'resolveIdentityChallenge', 'closeModal']);
    });
  });

  describe('재전송 흐름 — challenge 재발급', () => {
    const allActions = collectActions(identityModal as unknown as Node);
    const apiCalls = deepFindActions(allActions, (a) => a.handler === 'apiCall');
    const challengeStarts = apiCalls.filter((a) =>
      typeof a.target === 'string'
      && a.target.endsWith('/api/identity/challenges'),
    );

    it('재전송 버튼이 POST /api/identity/challenges 를 호출한다', () => {
      expect(challengeStarts.length).toBeGreaterThanOrEqual(1);
      expect(challengeStarts[0].params?.method).toBe('POST');
    });

    /**
     * 회귀 — 재전송 클릭 즉시 입력 코드가 초기화되어야 함 (apiCall 응답을 기다리지 않음).
     * 과거 onSuccess 안에서만 code 를 비웠으나, Input value 가 controlled 였음에도
     * onChange 미동작과 합쳐져 화면 초기화가 안 되는 회귀가 있었음.
     */
    it('재전송 sequence 의 첫 setState 가 identityChallenge.code 를 즉시 빈 문자열로 초기화한다', () => {
      const setStates = deepFindActions(allActions, (a) => a.handler === 'setState');
      const cooldownReset = setStates.find(
        (s) => s.params && s.params['identityChallenge.resendCooldown'] === 30,
      );
      expect(cooldownReset).toBeDefined();
      expect(cooldownReset!.params!['identityChallenge.code']).toBe('');
    });
  });

  /**
   * 회귀 — Input 의 입력 이벤트는 G7 표준 actions: [{event:"onChange", ...}] 패턴이어야 함.
   * 비표준 events:{ onChange:{ actions:[...] } } 래퍼는 엔진이 인식하지 않아
   * onChange 가 발생하지 않고 → _global.identityChallenge.code 가 영원히 미갱신 → 확인 버튼 영구 비활성.
   */
  describe('Input onChange — 표준 actions 패턴 사용', () => {
    function findCodeInput(): Node | undefined {
      let found: Node | undefined;
      walk(identityModal as unknown as Node, (n) => {
        if (n.name === 'Input' && (n.props as any)?.name === 'code') {
          found = n;
        }
      });
      return found;
    }

    const codeInput = findCodeInput();

    it('code Input 이 존재한다', () => {
      expect(codeInput).toBeDefined();
    });

    it('비표준 events 래퍼를 사용하지 않는다', () => {
      expect((codeInput as any).events).toBeUndefined();
    });

    it('onChange 가 setState(target=global) 로 identityChallenge.code 를 갱신한다', () => {
      const actions = (codeInput as any).actions ?? [];
      const onChange = actions.find((a: Action) => a.event === 'onChange');
      expect(onChange).toBeDefined();
      expect(onChange.handler).toBe('setState');
      expect(onChange.params?.target).toBe('global');
      expect(onChange.params?.['identityChallenge.code']).toContain('$event');
    });
  });

  describe('상태 네임스페이스 일관성 — _global.identityChallenge.*', () => {
    const allActions = collectActions(identityModal as unknown as Node);
    const setStates = deepFindActions(allActions, (a) => a.handler === 'setState');

    it('모든 setState 가 target=global 사용', () => {
      const wrongTarget = setStates.find((s) => (s.params?.target ?? 'local') !== 'global');
      expect(wrongTarget).toBeUndefined();
    });

    it('setState 키들이 identityChallenge.* dot notation 사용', () => {
      for (const s of setStates) {
        const params = s.params ?? {};
        const dataKeys = Object.keys(params).filter((k) => k !== 'target');
        for (const k of dataKeys) {
          // identityChallenge prefix 또는 identityChallenge 자체로 시작
          expect(k.startsWith('identityChallenge')).toBe(true);
        }
      }
    });
  });

  describe('Base 레이아웃 마운트', () => {
    it('_user_base.json 의 modals 배열에 _identity_challenge_modal 이 포함되어 있다', () => {
      const modals = (userBase as any).modals ?? [];
      const found = modals.find(
        (m: any) => typeof m.partial === 'string' && m.partial.endsWith('_identity_challenge_modal.json'),
      );
      expect(found).toBeDefined();
    });
  });

  describe('풀페이지 폴백 — auth/identity_challenge.json 도 resolveIdentityChallenge 호출', () => {
    const allActions = collectActions(identityChallengePage as unknown as Node);
    const apiCalls = deepFindActions(allActions, (a) => a.handler === 'apiCall');

    it('풀페이지 verify onSuccess 도 resolveIdentityChallenge { verified } 를 호출 (defaultLauncher 폴백 일관성)', () => {
      const verifyCall = apiCalls.find((a) =>
        typeof a.target === 'string' && a.target.includes('/verify'),
      );
      expect(verifyCall).toBeDefined();

      const resolves = deepFindActions(verifyCall!.onSuccess ?? [], (a) =>
        a.handler === 'resolveIdentityChallenge' && a.params?.result === 'verified',
      );
      expect(resolves.length).toBeGreaterThanOrEqual(1);
    });
  });
});
