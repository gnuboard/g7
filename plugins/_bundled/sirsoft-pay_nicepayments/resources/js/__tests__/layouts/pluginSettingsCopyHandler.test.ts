/**
 * @file pluginSettingsCopyHandler.test.ts
 * @description sirsoft-pay_nicepayments 설정 화면의 가상계좌 입금통보 주소 복사 버튼이 이 플러그인이 소유·등록한 핸들러를 부르는지.
 *
 * 설정 레이아웃이 네임스페이스 없는 `copyToClipboard` 를 불렀는데 코어·템플릿·확장 어디에도 그런
 * 핸들러가 없어 복사 버튼이 아무 일도 하지 않았다(복사 완료 토스트도 뜨지 않음). 설정 화면은 어느
 * 관리자 템플릿으로 그려지든 같아야 하므로 플러그인이 자기 핸들러를 두고 접두어를 붙여 부른다.
 */

import { describe, it, expect } from 'vitest';
import pluginSettingsLayout from '../../../layouts/admin/plugin_settings.json';
import { handlerMap } from '../../handlers';

/** 레이아웃 트리에서 조건에 맞는 액션을 모두 모은다. */
function collectActions(node: unknown, out: Record<string, unknown>[] = []): Record<string, unknown>[] {
  if (Array.isArray(node)) {
    node.forEach((child) => collectActions(child, out));
    return out;
  }
  if (!node || typeof node !== 'object') {
    return out;
  }
  const value = node as Record<string, unknown>;
  if (typeof value.handler === 'string') {
    out.push(value);
  }
  Object.values(value).forEach((child) => collectActions(child, out));
  return out;
}

describe('sirsoft-pay_nicepayments plugin_settings — 입금통보 주소 복사', () => {
  const handlers = collectActions(pluginSettingsLayout).map((action) => action.handler as string);

  it('복사 버튼은 이 플러그인 접두어가 붙은 copyToClipboard 를 부른다', () => {
    expect(handlers).toContain('sirsoft-pay_nicepayments.copyToClipboard');
  });

  it('네임스페이스 없는 copyToClipboard 는 남아 있지 않다 (미등록 핸들러 무반응 회귀 차단)', () => {
    expect(handlers).not.toContain('copyToClipboard');
  });

  it('레이아웃이 부르는 복사 핸들러가 이 플러그인 핸들러 맵에 실제로 있다', () => {
    expect(typeof handlerMap.copyToClipboard).toBe('function');
  });

  it('복사 대상은 입금통보 주소(조회값이 없으면 기본 경로)다', () => {
    const copy = collectActions(pluginSettingsLayout).find((action) => action.handler === 'sirsoft-pay_nicepayments.copyToClipboard');
    const text = String((copy?.params as Record<string, unknown> | undefined)?.text ?? '');
    expect(text).toContain('_local.vbankInfo?.url');
    expect(text).toContain('/plugins/sirsoft-pay_nicepayments/payment/vbank-notify');
  });
});
