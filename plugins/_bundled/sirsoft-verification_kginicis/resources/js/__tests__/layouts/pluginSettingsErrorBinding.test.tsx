/**
 * @file pluginSettingsErrorBinding.test.tsx
 * @description 회귀 테스트 — 설정 저장 실패(422) 시 검증 에러가 화면에 노출되는지 검증.
 *
 * 배경: onError 의 errors 바인딩이 `{{$error.errors}}` ($ 접두사) 였을 때 ActionDispatcher
 * 컨텍스트 변수명과 불일치하여 _local.errors 가 null 로 남고, validation_error 박스가
 * 렌더되지 않는 silent failure 회귀가 발생했다. 올바른 컨텍스트 변수명은 `{{error.errors}}`.
 * (CLAUDE.md 데이터 바인딩 규칙: onSuccess/onError 컨텍스트는 $ 접두사 없음)
 */

import { describe, it, expect } from 'vitest';
import pluginSettingsLayout from '../../../layouts/admin/plugin_settings.json';

/** 레이아웃 트리를 순회하며 술어를 만족하는 첫 노드를 찾는다. */
function findNode(
  node: unknown,
  predicate: (n: Record<string, unknown>) => boolean,
): Record<string, unknown> | undefined {
  if (!node || typeof node !== 'object') {
    return undefined;
  }
  const value = node as Record<string, unknown>;
  if (predicate(value)) {
    return value;
  }
  for (const child of Object.values(value)) {
    const found = findNode(child, predicate);
    if (found) {
      return found;
    }
  }
  return undefined;
}

/** save_button 의 apiCall 액션 onError 배열을 추출한다. */
function findOnError(): unknown[] | undefined {
  const apiCall = findNode(
    pluginSettingsLayout,
    (n) => n.handler === 'apiCall' && Array.isArray((n as Record<string, unknown>).onError),
  );
  return apiCall?.onError as unknown[] | undefined;
}

describe('plugin_settings 저장 실패 에러 바인딩 (회귀)', () => {
  it('onError 의 errors 바인딩은 $ 접두사 없는 {{error.errors}} 여야 한다', () => {
    const onError = findOnError();
    expect(onError).toBeDefined();

    const setStateAction = (onError as Array<Record<string, unknown>>).find(
      (a) => a.handler === 'setState',
    );
    expect(setStateAction).toBeDefined();

    const params = (setStateAction?.params ?? {}) as Record<string, unknown>;
    expect(params.errors).toBe('{{error.errors}}');
    // 회귀 패턴($error.errors) 이 다시 들어오지 않도록 명시적으로 차단
    expect(params.errors).not.toBe('{{$error.errors}}');
  });

  it('validation_error 박스가 _local.errors 로 조건부 렌더되도록 정의되어 있어야 한다', () => {
    const box = findNode(pluginSettingsLayout, (n) => n.id === 'validation_error');
    expect(box).toBeDefined();
    expect(box?.if).toBe('{{_local.errors}}');
  });

  it('onError 에 에러 토스트 발화가 정의되어 있어야 한다', () => {
    const onError = findOnError() as Array<Record<string, unknown>>;
    const toast = onError.find((a) => a.handler === 'toast');
    expect(toast).toBeDefined();
    const params = (toast?.params ?? {}) as Record<string, unknown>;
    expect(params.type).toBe('error');
  });

  it('라이브 MID 입력칸에 오류 시 빨간 테두리 + 하단 에러 텍스트가 정의되어 있어야 한다', () => {
    const errText = findNode(pluginSettingsLayout, (n) => n.id === 'field_live_mid_error');
    expect(errText).toBeDefined();
    expect(errText?.if).toBe('{{_local.errors?.live_mid}}');
    expect(String(errText?.text)).toContain('_local.errors?.live_mid');

    // 입력칸 래퍼 테두리가 _local.errors.live_mid 조건으로 빨간색 전환되는지
    const midWrapper = findNode(
      pluginSettingsLayout,
      (n) => typeof (n.props as Record<string, unknown>)?.className === 'string'
        && (n.props as Record<string, unknown>).className.toString().includes("_local.errors?.live_mid ?")
        && (n.props as Record<string, unknown>).className.toString().includes('border-red-500'),
    );
    expect(midWrapper).toBeDefined();
  });

  it('라이브 API 키 입력칸에 오류 시 빨간 테두리 + 하단 에러 텍스트가 정의되어 있어야 한다', () => {
    const errText = findNode(pluginSettingsLayout, (n) => n.id === 'field_live_api_key_error');
    expect(errText).toBeDefined();
    expect(errText?.if).toBe('{{_local.errors?.live_api_key}}');
    expect(String(errText?.text)).toContain('_local.errors?.live_api_key');

    const keyInput = findNode(
      pluginSettingsLayout,
      (n) => (n.props as Record<string, unknown>)?.name === 'live_api_key',
    );
    expect(keyInput).toBeDefined();
    expect(String((keyInput?.props as Record<string, unknown>).className)).toContain('border-red-500');
  });

  it('validation_error 박스는 상단 안내 박스와 붙지 않도록 형제 카드와 동일한 상단 여백(mt-6)을 가져야 한다', () => {
    // 회귀: 빨간 에러 박스가 상단 파란 안내(info_panel) 박스 바로 아래에 여백 없이 붙어
    // 시각적으로 한 덩어리처럼 보이던 문제. 형제 카드(test_mode_card / live_mode_warning)는
    // 모두 mt-6 을 가지므로 에러 박스도 동일 간격을 유지해야 한다.
    const box = findNode(pluginSettingsLayout, (n) => n.id === 'validation_error');
    expect(box).toBeDefined();
    const boxClass = String((box?.props as Record<string, unknown>)?.className ?? '');
    expect(boxClass).toContain('mt-6');
    expect(boxClass).toContain('alert-danger');
  });

  it('상단 에러박스 항목이 불릿(list-disc) Ul 로 표시되고 Li 가 불릿을 가리지 않아야 한다', () => {
    // 에러 목록 Ul 은 list-disc (불릿) 사용
    const ul = findNode(
      pluginSettingsLayout,
      (n) => n.name === 'Ul'
        && typeof (n.props as Record<string, unknown>)?.className === 'string'
        && (n.props as Record<string, unknown>).className.toString().includes('list-disc'),
    );
    expect(ul).toBeDefined();

    // Li 는 불릿 마커를 가리는 grid 레이아웃 클래스를 쓰지 않아야 한다 (게시판 표준과 동일)
    const li = findNode(ul as Record<string, unknown>, (n) => n.name === 'Li');
    expect(li).toBeDefined();
    const liClass = String((li?.props as Record<string, unknown>)?.className ?? '');
    expect(liClass).not.toContain('grid');
  });
});
