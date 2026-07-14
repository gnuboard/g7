/**
 * @file pluginSettingsLiveMid.test.tsx
 * @description sirsoft-verification_kginicis 플러그인 환경설정 라이브 MID 입력칸 회귀 테스트 (#458)
 *
 * 작업 A — 라이브 MID 입력칸(live_mid)에 autoComplete="off" 가 적용되어 브라우저 자동완성
 *          (계정 이메일 등)이 채워지지 않도록 한다.
 * 작업 B — 라이브 MID 고정 프리픽스 Span 이 이니시스 정책 변경값 SRB 로 표시된다.
 */

import { describe, it, expect } from 'vitest';
import pluginSettingsLayout from '../../../layouts/admin/plugin_settings.json';

/** 레이아웃 트리에서 props.name 이 일치하는 첫 노드를 찾는다. */
function findByPropName(node: unknown, name: string): Record<string, unknown> | undefined {
  if (!node || typeof node !== 'object') {
    return undefined;
  }
  const value = node as Record<string, unknown>;
  const props = (value.props ?? {}) as Record<string, unknown>;
  if (props.name === name) {
    return value;
  }
  for (const child of Object.values(value)) {
    const found = findByPropName(child, name);
    if (found) {
      return found;
    }
  }
  return undefined;
}

/** 레이아웃 트리에서 text 가 일치하는 첫 노드를 찾는다. */
function findByText(node: unknown, text: string): Record<string, unknown> | undefined {
  if (!node || typeof node !== 'object') {
    return undefined;
  }
  const value = node as Record<string, unknown>;
  if (value.text === text) {
    return value;
  }
  for (const child of Object.values(value)) {
    const found = findByText(child, text);
    if (found) {
      return found;
    }
  }
  return undefined;
}

describe('plugin_settings 라이브 MID 입력칸 (#458)', () => {
  it('live_mid input 에 autoComplete="one-time-code" 가 적용되어 있어야 한다 (크롬 autofill/제안 차단)', () => {
    // 크롬은 autoComplete="off" 를 무시하고 저장된 계정(이메일)을 채운다. live_mid 는 비밀번호가
    // 아닌 일반 텍스트 MID 칸이므로, autofill/제안을 차단하되 "비밀번호 생성" 제안이 뜨지 않는
    // one-time-code 토큰을 사용한다 (new-password 는 live_api_key 처럼 실제 비번 칸에만) (#458).
    const liveMid = findByPropName(pluginSettingsLayout, 'live_mid');
    expect(liveMid).toBeDefined();

    const props = (liveMid?.props ?? {}) as Record<string, unknown>;
    expect(props.autoComplete).toBe('one-time-code');
  });

  it('live_api_key input 에 autoComplete="new-password" 가 적용되어 있어야 한다 (저장 비번 autofill 차단)', () => {
    // live_api_key 는 실제 새 API 키(비밀번호 성격) 입력칸이므로 의미상으로도 정확한 new-password 사용.
    const liveApiKey = findByPropName(pluginSettingsLayout, 'live_api_key');
    expect(liveApiKey).toBeDefined();

    const props = (liveApiKey?.props ?? {}) as Record<string, unknown>;
    expect(props.autoComplete).toBe('new-password');
  });

  it('라이브 MID 고정 프리픽스 Span 이 SRB 로 표시되어야 한다', () => {
    // 정책 전환 후 SRA 는 존재하지 않아야 한다.
    expect(findByText(pluginSettingsLayout, 'SRA')).toBeUndefined();
    expect(findByText(pluginSettingsLayout, 'SRB')).toBeDefined();
  });
});
