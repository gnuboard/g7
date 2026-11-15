/**
 * @file identity-challenge-resend-cooldown.test.tsx
 * @description 본인인증 메일 재전송 대기가 서버의 재요청 제한(같은 주소·목적 60초)과 같은지 잠근다.
 *
 * 화면 대기가 서버보다 먼저 풀리면 그 사이 누른 사용자가 「잠시 후 다시」 오류를 본다. 재전송 버튼은
 * 누르는 즉시(응답을 기다리지 않고) 60초 대기를 건다.
 *
 * @scenario-manifest templates/_bundled/sirsoft-basic/tests/scenarios/identity-challenge-resend-cooldown.yaml
 */

import { describe, expect, it } from 'vitest';

import fullPage from '../../layouts/auth/identity_challenge.json';
import modal from '../../layouts/partials/_identity_challenge_modal.json';

describe('본인인증 재전송 대기 (sirsoft-basic) — 서버 제한과 같은 60초', () => {
  /**
   * @scenario case=c01
   * @effects resend_cooldown_matches_server_limit
   */
  it('전체화면의 재전송 버튼이 누르는 즉시 60초 대기를 건다', () => {
    const json = JSON.stringify(fullPage);

    expect(json).not.toContain('"resendCooldown":30');
    expect(json.split('"resendCooldown":60').length - 1).toBe(2);
  });

  /**
   * @scenario case=c02
   * @effects resend_cooldown_matches_server_limit
   */
  it('모달의 재전송 버튼이 누르는 즉시 60초 대기를 건다', () => {
    const json = JSON.stringify(modal);

    expect(json).not.toContain('"identityChallenge.resendCooldown":30');
    expect(json.split('"identityChallenge.resendCooldown":60').length - 1).toBe(1);
  });

  /**
   * 메일 링크 안내 화면의 「인증 메일 다시 보내기」 도 같은 대기를 건다 — 대기 중에는 버튼이 잠기고 남은 초를 보인다.
   *
   * @scenario case=c03
   * @effects resend_cooldown_matches_server_limit
   */
  it('링크 안내 화면의 다시 보내기 버튼이 대기 중에 잠긴다', () => {
    const json = JSON.stringify(fullPage);
    const disabledByCooldown = json.split('"disabled":"{{(_local.resendCooldown ?? 0) > 0}}"').length - 1;

    // 코드 입력 화면의 재전송 + 링크 안내 화면의 다시 보내기
    expect(disabledByCooldown).toBe(2);
    expect(json).toContain('$t:user.identity.challenge.resend_link');
  });
});
