/**
 * @file admin-reset-password-identity-modal.test.ts
 * @description 관리자 비밀번호 재설정 화면이 본인인증 창을 스스로 들고 있는지 확인하는 회귀 테스트
 *
 * 배경: 본인인증 창(`identity-challenge-modal`)은 `_admin_base` 에 들어 있다. 재설정 화면은 로그인 전
 * 화면이라 `_admin_base` 를 상속하지 않으므로, 비밀번호 재설정 본인인증 정책을 켜면 서버가 428 을
 * 돌려주고 런처가 `openModal` 을 불러도 열 창이 없었다. 확인 메일은 나가고 링크도 동작하지만 화면은
 * 「처리 중」에서 멈춘 채 아무 안내가 없었다(7.1.0 전수 실측).
 *
 * 이 테스트를 지우거나 모달 선언을 빼면 그 상태로 돌아간다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const baseDir = path.resolve(__dirname, '../..');

function loadJson(relPath: string): any {
  return JSON.parse(fs.readFileSync(path.resolve(baseDir, relPath), 'utf8'));
}

/** 레이아웃의 modals 배열이 가리키는 조각 경로를 모은다. */
function modalPartials(layout: any): string[] {
  return (layout.modals ?? []).map((m: any) => m?.partial).filter((p: unknown): p is string => typeof p === 'string');
}

describe('관리자 비밀번호 재설정 — 본인인증 창', () => {
  /**
   * @scenario surface=guest_password_flow_layout
   * @effects guest_password_flows_carry_challenge_modal
   */
  it('_admin_base 를 상속하지 않으므로 본인인증 창 조각을 직접 선언한다', () => {
    const layout = loadJson('layouts/admin_reset_password.json');

    expect(layout.extends ?? null).toBeNull();
    expect(modalPartials(layout)).toContain('partials/_identity_challenge_modal.json');
  });

  /**
   * @scenario surface=guest_password_flow_layout
   * @effects guest_password_flows_carry_challenge_modal
   */
  it('선언한 조각이 런처가 여는 창 id(identity-challenge-modal)를 가진다', () => {
    const partial = loadJson('layouts/partials/_identity_challenge_modal.json');
    const json = JSON.stringify(partial);

    expect(json).toContain('"id":"identity-challenge-modal"');
  });

  /**
   * 본인인증 창이 띄우는 토스트(「재전송했습니다」 등)를 받을 자리가 있다.
   *
   * 독립 레이아웃은 `_admin_base` 의 전역 토스트 호스트를 물려받지 못한다. 창 조각을 들여온 뒤로 이 화면이
   * 토스트를 발화하는데 받는 자리가 없어 조용히 사라졌다(실측 2026-10-06, 7.1.0 전수 실측 I1: 코드 편집기
   * 경고 패널 `standalone_missing_global_host` — 로그인 화면은 같은 이유로 호스트를 스스로 둔다).
   *
   * @scenario surface=guest_password_flow_layout
   * @effects guest_password_flows_carry_challenge_modal
   */
  it('창이 발화하는 토스트를 받을 전역 토스트 호스트를 직접 둔다', () => {
    const layout = loadJson('layouts/admin_reset_password.json');
    const json = JSON.stringify(layout.components ?? []);

    expect(json).toContain('"name":"Toast"');
    expect(json).toContain('_global.toasts');
  });
});
