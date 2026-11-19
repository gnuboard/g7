/**
 * @file password-reconfirm-throttle-message.test.ts
 * @description 비밀번호 재확인 칸이 횟수 초과(429)·잠김(423) 응답을 「비밀번호가 일치하지 않습니다」로 덮지 않고 서버 문구(남은 시간 포함)를 보이는지
 *
 * — 내 정보 수정 진입 비밀번호 확인이 429 「비밀번호 확인을 너무 여러 번 실패했습니다. N초 후 다시 시도해 주세요.」를 받아도
 * 실패 처리가 불리언만 세워 고정 문구를 보였다. 잠긴 동안 맞는 비밀번호를 넣어도 「일치하지 않음」으로 보여 사용자가 잠금을 모른다.
 * 같은 꼴인 게시판 비밀번호 확인 창 둘(비회원 글 수정 진입, 비회원 글·댓글 수정·삭제 확인)도 함께 본다 — 서버 제한기가 429 를 낸다.
 *
 * @scenario-manifest tests/scenarios/password-reconfirm-throttle-message.yaml
 */

import { describe, it, expect } from 'vitest';

import verifySection from '../../layouts/partials/mypage/profile/_password_verify_section.json';
import boardFormModal from '../../layouts/partials/board/form/_password_verify_modal.json';
import boardShowModal from '../../layouts/partials/board/show/modals/_password_verify_modal.json';
import profileEdit from '../../layouts/mypage/profile-edit.json';

type Node = Record<string, any>;

/** 트리의 모든 객체를 모은다 */
function walk(input: unknown, out: Node[] = []): Node[] {
  if (Array.isArray(input)) {
    input.forEach((item) => walk(item, out));
  } else if (input && typeof input === 'object') {
    out.push(input as Node);
    Object.values(input as Node).forEach((value) => walk(value, out));
  }
  return out;
}

/** 표현식({{ }})이면 주어진 변수로 계산하고, 아니면 그대로 돌려준다 */
function resolve(value: unknown, scope: Record<string, unknown>): any {
  if (typeof value !== 'string' || !/^\{\{[\s\S]*\}\}$/.test(value)) return value;
  const body = value.replace(/^\{\{/, '').replace(/\}\}$/, '');
  // eslint-disable-next-line no-new-func
  return new Function(...Object.keys(scope), 'return (' + body + ');')(...Object.values(scope));
}

/** 대상 주소를 부르는 apiCall 의 실패 처리 setState 들 */
function failSetStates(layout: unknown, targetPart: string): Node[] {
  const call = walk(layout).find((node) => node.handler === 'apiCall' && String(node.target).includes(targetPart));
  expect(call, `${targetPart} 호출`).toBeDefined();
  const onError = Array.isArray(call!.onError) ? call!.onError : [call!.onError];
  return walk(onError).filter((node) => node.handler === 'setState' && node.params?.target === 'local');
}

const $t = (key: string) => `t:${key}`;
const throttled = { status: 429, message: '비밀번호 확인을 너무 여러 번 실패했습니다. 193초 후 다시 시도해 주세요.' };
const locked = { status: 423, message: '계정이 잠겼습니다. 5분 후 다시 시도해 주세요.' };
const mismatch = { status: 401, message: '비밀번호가 일치하지 않습니다.' };

describe('비밀번호 재확인 — 횟수 초과·잠김은 서버 문구로 알린다', () => {
  describe('내 정보 수정 진입 비밀번호 확인 (_password_verify_section)', () => {
    const shownText = (error: Node): string | null => {
      const local: Node = {};
      for (const action of failSetStates(verifySection, '/api/me/verify-password').filter((a) => 'verifyPasswordError' in a.params)) {
        for (const [key, value] of Object.entries(action.params)) {
          if (key !== 'target') local[key] = resolve(value, { error, $t });
        }
      }
      // 칸 아래 오류 줄 — 보이는 줄의 글자를 고른다
      const lines = walk(verifySection).filter((node) => typeof node.if === 'string' && node.if.includes('verifyPasswordError'));
      const visible = lines.filter((node) => resolve(node.if, { _local: local }));
      expect(visible.length, '오류 줄은 하나만 보인다').toBe(1);
      const text = visible[0].text ?? visible[0].children?.[0]?.text;
      return String(resolve(text, { _local: local, $t }) ?? text);
    };

    /**
     * @scenario screen=profile_verify, status=throttled
     * @effects throttle_or_lock_shows_server_message
     */
    it('429·423 이면 서버 문구(남은 시간 포함)를 보인다', () => {
      expect(shownText(throttled)).toBe(throttled.message);
      expect(shownText(locked)).toBe(locked.message);
    });

    /**
     * @scenario screen=profile_verify, status=mismatch
     * @effects mismatch_keeps_fixed_message
     */
    it('비밀번호가 틀리면(401) 종전 고정 문구다', () => {
      expect(shownText(mismatch)).toBe('$t:mypage.profile.verify_password_error');
    });

    /**
     * @scenario screen=profile_verify, status=mismatch
     * @effects error_message_cleared_on_retry
     */
    it('다시 제출하거나 화면에 들어오면 지난 서버 문구를 지운다', () => {
      const submitStart = walk(verifySection).find((node) => node.handler === 'setState' && node.params?.isVerifyingPassword === true);
      expect(submitStart?.params?.verifyPasswordErrorMessage).toBe('');
      const init = walk((profileEdit as Node).init_actions ?? profileEdit).find((node) => node.handler === 'setState' && 'verifyPasswordError' in (node.params ?? {}));
      expect(init?.params?.verifyPasswordErrorMessage).toBe('');
    });
  });

  describe.each([
    ['게시판 비회원 글 수정 진입 확인 창', boardFormModal, 'verify-password-for-modify', 'passwordError'],
    ['게시판 비회원 글·댓글 확인 창', boardShowModal, 'guestPasswordModal?.apiEndpoint', 'guestPasswordError'],
  ])('%s', (_name, layout, target, key) => {
    const errorText = (error: Node) => {
      const action = failSetStates(layout, target).find((a) => key in a.params)!;
      expect(action).toBeDefined();
      return resolve(action.params[key], { error, $t });
    };

    /**
     * @scenario screen=board_modal, status=throttled
     * @effects throttle_or_lock_shows_server_message
     */
    it('429·423 이면 서버 문구를 보인다', () => {
      expect(errorText(throttled)).toBe(throttled.message);
      expect(errorText(locked)).toBe(locked.message);
    });

    /**
     * @scenario screen=board_modal, status=mismatch
     * @effects mismatch_keeps_fixed_message
     */
    it('비밀번호가 틀리면 종전 고정 문구다', () => {
      expect(errorText(mismatch)).toBe('t:board.password_verify.incorrect');
    });
  });
});
