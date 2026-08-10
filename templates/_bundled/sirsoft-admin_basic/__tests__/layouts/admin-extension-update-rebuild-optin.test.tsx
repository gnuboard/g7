import { describe, it, expect } from 'vitest';
import moduleList from '../../layouts/admin_module_list.json';
import pluginList from '../../layouts/admin_plugin_list.json';
import moduleUpdateModal from '../../layouts/partials/admin_module_list/_modal_update.json';
import pluginUpdateModal from '../../layouts/partials/admin_plugin_list/_modal_update.json';

/**
 * 확장 업데이트 모달의 「검색 인덱스 재생성」 체크가 **모달을 열 때마다 해제**되는지 고정한다.
 *
 * 재생성은 인덱스 잠금·전체 재색인을 유발하므로 운영자가 그 모달에서 직접 선택했을 때만
 * 수행되어야 한다. 체크 상태를 전역에 남겨 두면 한 번 체크한 운영자가 다음 확장을
 * 업데이트할 때 아무것도 누르지 않았는데 재생성이 다시 수행된다 (브라우저 실측으로 확인).
 *
 * 모달을 여는 setState 시드에 `<x>RebuildSearchIndex: false` 가 없으면 여기서 red 가 난다.
 */

type Json = Record<string, unknown>;

/** 레이아웃 트리를 훑어 조건을 만족하는 setState params 를 모은다. */
function collectSetStateParams(node: unknown, out: Json[] = []): Json[] {
  if (Array.isArray(node)) {
    node.forEach((child) => collectSetStateParams(child, out));
    return out;
  }
  if (node && typeof node === 'object') {
    const obj = node as Json;
    if (obj.handler === 'setState' && obj.params && typeof obj.params === 'object') {
      out.push(obj.params as Json);
    }
    Object.values(obj).forEach((value) => collectSetStateParams(value, out));
  }
  return out;
}

describe('확장 업데이트 모달 — 검색 인덱스 재생성 옵트인', () => {
  const cases = [
    { name: '모듈', layout: moduleList, modal: moduleUpdateModal, strategyKey: 'moduleLayoutStrategy', rebuildKey: 'moduleRebuildSearchIndex' },
    { name: '플러그인', layout: pluginList, modal: pluginUpdateModal, strategyKey: 'pluginLayoutStrategy', rebuildKey: 'pluginRebuildSearchIndex' },
  ];

  it.each(cases)('$name 목록: 업데이트 모달을 여는 시드가 체크를 해제한다', ({ layout, strategyKey, rebuildKey }) => {
    const seeds = collectSetStateParams(layout).filter(
      (params) => params[strategyKey] === 'overwrite' && 'selectedModule' in params === ('selectedModule' in params),
    );
    const modalSeeds = seeds.filter((params) => params[strategyKey] === 'overwrite');

    expect(modalSeeds.length).toBeGreaterThan(0);
    modalSeeds.forEach((params) => {
      expect(params[rebuildKey], `${rebuildKey} 가 모달 진입 시드에서 해제되지 않습니다`).toBe(false);
    });
  });

  it.each(cases)('$name 모달: 업데이트 성공 후 상태를 되돌릴 때도 체크를 해제한다', ({ modal, strategyKey, rebuildKey }) => {
    const resets = collectSetStateParams(modal).filter(
      (params) => params[strategyKey] === 'overwrite' && params.selectedModule === null,
    );

    // 모듈 모달은 selectedModule, 플러그인 모달은 selectedPlugin 을 비운다
    const resetParams = resets.length
      ? resets
      : collectSetStateParams(modal).filter(
          (params) => params[strategyKey] === 'overwrite' && params.selectedPlugin === null,
        );

    expect(resetParams.length).toBeGreaterThan(0);
    resetParams.forEach((params) => {
      expect(params[rebuildKey], `${rebuildKey} 가 제출 후 초기화되지 않습니다`).toBe(false);
    });
  });

  it.each(cases)('$name 모달: 체크박스는 전역 상태 true 일 때만 체크된다', ({ modal, rebuildKey }) => {
    const json = JSON.stringify(modal);
    expect(json).toContain(`{{_global.${rebuildKey} === true}}`);
  });
});
