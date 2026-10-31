// e2e:allow Vitest 레이아웃 계약 파일 — 브라우저 흐름(설정 GET 지연·PUT 가짜 응답, 8탭 입력칸 0개→도착 뒤 입력 보존, ko/en)은 7.1.0 실측 K3 후속(KF3-2)에서 Playwright 로 처음부터 끝까지 재측정했다.
/**
 * 게시판 환경설정 — 설정을 다 받기 전에는 [저장] 이 눌리지 않는가
 *
 * 회귀 배경(7.1.0 브라우저 실측 K3): 설정 화면은 입력칸을 먼저 그리고 설정 본체(`settings` 데이터소스)를
 * 나중에 받는다. 받기 전에 칸 하나를 고치면 `hasChanges` 가 켜져 [저장] 이 눌렸고, 저장 본문이
 * `{ basic_defaults: { max_file_size: '10' }, notifications: {} }` 처럼 고친 칸 하나만 실었다.
 * 서버는 그 구획을 통째로 갈아 쓰고 빠진 스위치를 false 로 채우므로, 게시판 기본 권한·금지어·
 * 댓글/답글 사용 같은 기본값이 한 번에 지워졌다. 설정을 받기 전에는 저장을 잠근다.
 */

import { describe, it, expect } from 'vitest';

import layout from '../../../layouts/admin/admin_board_settings.json';

/** 노드 트리에서 id 가 맞는 노드를 찾는다. */
function findById(node: any, id: string): any {
  if (Array.isArray(node)) {
    for (const child of node) {
      const found = findById(child, id);
      if (found) return found;
    }
    return null;
  }
  if (node && typeof node === 'object') {
    if (node.id === id) return node;
    for (const value of Object.values(node)) {
      if (value && typeof value === 'object') {
        const found = findById(value, id);
        if (found) return found;
      }
    }
  }
  return null;
}

/** `{{ ... }}` 식 하나를 주어진 문맥으로 평가한다 (엔진과 같은 JS 식). */
function evaluate(expression: string, context: Record<string, any>): any {
  const body = expression.trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
  // eslint-disable-next-line no-new-func
  return new Function(...Object.keys(context), `return (${body});`)(...Object.values(context));
}

describe('게시판 환경설정 — 설정 본체를 받기 전 저장 잠금', () => {
  const button = findById(layout, 'footer_save_button');
  const disabled: string = button?.props?.disabled;

  // @scenario settings_loaded=false, has_changes=true
  // @effects settings_save_locked_until_loaded
  it('설정을 받기 전에는 칸을 고쳐도 저장 버튼이 잠긴다', () => {
    expect(typeof disabled).toBe('string');

    const result = evaluate(disabled, {
      _computed: { isReadOnly: false },
      _local: { hasChanges: true, isSaving: false, form: { basic_defaults: { max_file_size: '10' } } },
      settings: undefined,
    });

    expect(result).toBe(true);
  });

  // @scenario settings_loaded=false, has_changes=false
  // @effects settings_save_locked_until_loaded
  it('설정을 받기 전이고 고친 것도 없으면 잠겨 있다', () => {
    const result = evaluate(disabled, {
      _computed: { isReadOnly: false },
      _local: { hasChanges: false, isSaving: false },
      settings: undefined,
    });

    expect(result).toBe(true);
  });

  // @scenario settings_loaded=true, has_changes=true
  // @effects settings_save_locked_until_loaded
  it('설정을 받은 뒤 고친 것이 있으면 저장할 수 있다', () => {
    const result = evaluate(disabled, {
      _computed: { isReadOnly: false },
      _local: { hasChanges: true, isSaving: false },
      settings: { data: { abilities: { can_update: true } } },
    });

    expect(result).toBe(false);
  });

  // @scenario settings_loaded=true, has_changes=false
  // @effects settings_save_locked_until_loaded
  it('고친 것이 없으면 설정을 받은 뒤에도 잠겨 있다', () => {
    const result = evaluate(disabled, {
      _computed: { isReadOnly: false },
      _local: { hasChanges: false, isSaving: false },
      settings: { data: { abilities: { can_update: true } } },
    });

    expect(result).toBe(true);
  });

  // @scenario settings_loaded=false, has_changes=false
  // @effects settings_inputs_wait_for_loaded_settings
  it('설정 본체는 blocking 으로 받아, 받기 전에는 입력칸을 그리지 않는다 (받기 전 입력이 도착 값에 덮이던 결함)', () => {
    // 7.1.0 실측 K3 O-K3T-5: 받기 전에 칸을 고치면 도착한 설정(initLocal="form")이 그 입력을 말없이 덮었다.
    // 저장 잠금만으로는 입력이 사라지는 것을 막지 못한다 — 입력칸 자체를 설정 도착 뒤에 그린다.
    const source = (layout as any).data_sources.find((ds: any) => ds.id === 'settings');
    expect(source).toBeTruthy();
    expect(source.loading_strategy).toBe('blocking');
    expect(source.initLocal).toBe('form');
  });
});
