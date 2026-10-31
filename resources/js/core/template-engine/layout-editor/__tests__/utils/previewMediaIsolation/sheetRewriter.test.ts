/**
 * 미리보기 매체 격리 — 규칙 트리 순회·치환·복원 (이슈 #141 D7)
 *
 * 폭 `@media` 블록은 같은 자리에서 둘로 나뉜다.
 *   (가) 원본: 자식 선택자에 「미리보기 밖에서만」 가드 — 프레임 밖 동작은 그대로
 *   (나) 바로 뒤 복제본: `@container g7le-preview <조건>` — 프레임 자손만 판정
 * 같은 인덱스 자리에 넣어 소스 순서·`@layer` 소속을 보존하고, 언마운트 때 원문 그대로 되돌린다.
 */

import { describe, it, expect, beforeEach } from 'vitest';
import {
  createRewriteState,
  rewriteSheets,
  restoreAll,
  type RewriteState,
} from '../../../utils/previewMediaIsolation/sheetRewriter';
import { OUTSIDE_GUARD, INSIDE_GUARD } from '../../../utils/previewMediaIsolation/constants';
import {
  FakeSheet,
  FakeMediaRule,
  FakeStyleRule,
  FakeGroupRule,
  FakeKeyframesRule,
  FakeNestedDeclarations,
  FakeCrossOriginSheet,
  dump,
} from './fakeCssom';

const G = OUTSIDE_GUARD;

describe('rewriteSheets', () => {
  let state: RewriteState;

  beforeEach(() => {
    state = createRewriteState();
  });

  /**
   * @scenario sheet_shape=single_width_media
   * @effects preview_media_isolation_width_media_to_container
   */
  it('폭 media 1개 → 원본 가드 + 바로 뒤 container 복제 1개', () => {
    const media = new FakeMediaRule('(min-width:48rem)', [new FakeStyleRule('.md\\:flex', 'display: flex;')]);
    const sheet = new FakeSheet([new FakeStyleRule('.a', 'color: red;'), media, new FakeStyleRule('.z', 'color: blue;')]);

    const stats = rewriteSheets([sheet as any], state, { remPx: 16 });

    expect(sheet.cssRules).toHaveLength(4);
    expect(sheet.cssRules[1]).toBe(media);
    expect((media.cssRules[0] as FakeStyleRule).selectorText).toBe(`.md\\:flex${G}`);
    expect(sheet.cssRules[2].cssText).toBe('@container g7le-preview (min-width: 768px) { .md\\:flex { display: flex; } }');
    expect(sheet.cssRules[3].cssText).toBe('.z { color: blue; }');
    expect(stats.converted).toBe(1);
    expect(stats.unsupported).toBe(0);
  });

  /**
   * @scenario sheet_shape=layer_media_supports
   * @effects preview_media_isolation_width_media_to_container
   */
  it('layer → supports → media 재귀', () => {
    const media = new FakeMediaRule('(max-width: 640px)', [new FakeStyleRule('.x', 'display: none;')]);
    const supports = new FakeGroupRule('@supports (display: grid)', [media]);
    const layer = new FakeGroupRule('@layer utilities', [supports]);
    const sheet = new FakeSheet([layer]);

    rewriteSheets([sheet as any], state, { remPx: 16 });

    expect(supports.cssRules).toHaveLength(2);
    expect((media.cssRules[0] as FakeStyleRule).selectorText).toBe(`.x${G}`);
    expect(supports.cssRules[1].cssText).toBe('@container g7le-preview (max-width: 640px) { .x { display: none; } }');
  });

  /**
   * @scenario media_condition=list_two_width
   * @effects preview_media_isolation_width_media_to_container
   */
  it('쉼표 2항목 → 복제 2개, 바깥 조건은 @media 로 감싼다', () => {
    const media = new FakeMediaRule('(max-width: 480px), (min-width: 1200px) and (hover: hover)', [
      new FakeStyleRule('.y', 'color: red;'),
    ]);
    const sheet = new FakeSheet([media]);

    rewriteSheets([sheet as any], state, { remPx: 16 });

    expect(dump(sheet).slice(1)).toEqual([
      '@container g7le-preview (max-width: 480px) { .y { color: red; } }',
      '@media (hover: hover) { @container g7le-preview (min-width: 1200px) { .y { color: red; } } }',
    ]);
  });

  /**
   * @scenario media_children=keyframes
   * @effects preview_media_isolation_at_rules_not_cloned
   */
  it('@keyframes 는 원본에만 두고 복제하지 않는다', () => {
    const media = new FakeMediaRule('(min-width: 768px)', [
      new FakeKeyframesRule('spin', '@keyframes spin { to { transform: rotate(1turn); } }'),
      new FakeStyleRule('.k', 'animation: spin 1s;'),
    ]);
    const sheet = new FakeSheet([media]);

    rewriteSheets([sheet as any], state, { remPx: 16 });

    expect(sheet.cssRules[1].cssText).toBe('@container g7le-preview (min-width: 768px) { .k { animation: spin 1s; } }');
  });

  /**
   * @scenario media_parent=style_rule, children=nested_declarations
   * @effects preview_media_isolation_width_media_to_container
   */
  it('스타일 규칙 안 중첩 media 의 선언 → &:where(...) 규칙 + 중첩 container 복제', () => {
    const media = new FakeMediaRule('(width >= 48rem)', [new FakeNestedDeclarations('display: flex;')]);
    const parent = new FakeStyleRule('.md\\:flex', '', [media]);
    const sheet = new FakeSheet([parent]);

    rewriteSheets([sheet as any], state, { remPx: 16 });

    expect(media.cssRules[0].cssText).toBe(`&${G} { display: flex; }`);
    expect(parent.cssRules[1].cssText).toBe('@container g7le-preview (width >= 768px) { display: flex; }');
  });

  /**
   * @scenario selector_write=rejected
   * @effects preview_media_isolation_unsupported_counted
   */
  it('selectorText 가 거부되면 블록 전체를 되돌리고 복제하지 않는다', () => {
    const ok = new FakeStyleRule('.ok', 'color: red;');
    const rejected = new FakeStyleRule('.bad', 'color: blue;');
    rejected.rejectGuard = true;
    const media = new FakeMediaRule('(min-width: 768px)', [ok, rejected]);
    const sheet = new FakeSheet([media]);

    const stats = rewriteSheets([sheet as any], state, { remPx: 16 });

    expect(sheet.cssRules).toHaveLength(1);
    expect(ok.selectorText).toBe('.ok');
    expect(stats.unsupported).toBe(1);
    expect(stats.converted).toBe(0);
  });

  /**
   * @scenario insert_rule=throws
   * @effects preview_media_isolation_unsupported_counted
   */
  it('insertRule 이 예외를 던지면 블록을 원상 복구하고 센다', () => {
    const media = new FakeMediaRule('(min-width: 768px)', [new FakeStyleRule('.THROW', 'color: red;')]);
    const sheet = new FakeSheet([media]);

    const stats = rewriteSheets([sheet as any], state, { remPx: 16 });

    expect(sheet.cssRules).toHaveLength(1);
    expect((media.cssRules[0] as FakeStyleRule).selectorText).toBe('.THROW');
    expect(stats.unsupported).toBe(1);
  });

  /**
   * @scenario sheet=cross_origin
   * @effects preview_media_isolation_cross_origin_skipped
   */
  it('SecurityError 시트는 건너뛰고 skipped 를 센다 (재실행에서 다시 세지 않는다)', () => {
    const sheet = new FakeCrossOriginSheet();
    const stats = rewriteSheets([sheet as any], state, { remPx: 16 });
    expect(stats.skipped).toBe(1);
    expect(rewriteSheets([sheet as any], state, { remPx: 16 }).skipped).toBe(0);
  });

  /**
   * @scenario sheet=media_attribute|opt_out
   * @effects preview_media_isolation_unsupported_counted
   */
  it('시트 전체 media 속성은 unsupported, 옵트아웃 소유 노드는 skipped', () => {
    const withMedia = new FakeSheet([new FakeMediaRule('(min-width: 768px)', [new FakeStyleRule('.a', 'color: red;')])]);
    withMedia.media.mediaText = '(min-width: 768px)';
    const optOut = new FakeSheet([new FakeMediaRule('(min-width: 768px)', [new FakeStyleRule('.b', 'color: red;')])]);
    optOut.ownerNode = { hasAttribute: (name: string) => name === 'data-g7le-no-media-isolation' };

    const stats = rewriteSheets([withMedia as any, optOut as any], state, { remPx: 16 });

    expect(withMedia.cssRules).toHaveLength(1);
    expect(optOut.cssRules).toHaveLength(1);
    expect(stats.unsupported).toBe(1);
    expect(stats.skipped).toBe(1);
  });

  /**
   * @scenario apply_count=2
   * @effects preview_media_isolation_idempotent
   */
  it('2회 실행해도 변화가 없다', () => {
    const sheet = new FakeSheet([new FakeMediaRule('(min-width: 768px)', [new FakeStyleRule('.a', 'color: red;')])]);
    rewriteSheets([sheet as any], state, { remPx: 16 });
    const once = dump(sheet);
    const second = rewriteSheets([sheet as any], state, { remPx: 16 });
    expect(dump(sheet)).toEqual(once);
    expect(second.converted).toBe(0);
  });

  /**
   * @scenario lifecycle=unmount
   * @effects preview_media_isolation_restored_on_unmount
   */
  it('restore 후 원문 문자열이 같다 (중첩 선언·vw 포함)', () => {
    const sheet = new FakeSheet([
      new FakeStyleRule('.a', 'color: red;'),
      new FakeMediaRule('(min-width: 48rem)', [new FakeStyleRule('.md\\:p-4', 'padding: 1rem;')]),
      new FakeStyleRule('.wrap', '', [new FakeMediaRule('(max-width: 640px)', [new FakeNestedDeclarations('display: none;')])]),
      new FakeStyleRule('.modal-container', 'max-width: calc(100vw - 2rem);'),
    ]);
    const original = dump(sheet);

    rewriteSheets([sheet as any], state, { remPx: 16 });
    expect(dump(sheet)).not.toEqual(original);

    restoreAll(state);
    expect(dump(sheet)).toEqual(original);
  });

  /**
   * @scenario declaration_unit=vw, context=outside_width_media
   * @effects preview_media_isolation_vw_follows_frame_width
   */
  it('vw 규칙: 원본 가드 + 프레임 안 복제본(INSIDE 가드, var 치환)', () => {
    const rule = new FakeStyleRule('.modal-container', 'max-width: calc(100vw - 2rem); color: red;');
    const sheet = new FakeSheet([rule]);

    rewriteSheets([sheet as any], state, { remPx: 16 });

    expect(rule.selectorText).toBe(`.modal-container${G}`);
    expect(sheet.cssRules[1].cssText).toBe(
      `.modal-container${INSIDE_GUARD} { max-width: calc(calc(100 * var(--g7le-vw)) - 2rem); color: red; }`,
    );
  });

  /**
   * @scenario declaration_unit=vw, owner=style_without_vw|style_empty
   * @effects preview_media_isolation_vw_follows_frame_width
   */
  it('<style> 원문에 vw 가 없으면 vw 검사를 건너뛰고, 원문이 비었으면(CSS-in-JS) 규칙별로 본다', () => {
    const noVw = new FakeSheet([new FakeStyleRule('.a', 'width: 50vw;')]);
    noVw.ownerNode = { hasAttribute: () => false, tagName: 'STYLE', textContent: '.a{width:50%}' } as any;
    const cssInJs = new FakeSheet([new FakeStyleRule('.b', 'width: 50vw;')]);
    cssInJs.ownerNode = { hasAttribute: () => false, tagName: 'STYLE', textContent: '' } as any;

    rewriteSheets([noVw as any, cssInJs as any], state, { remPx: 16 });

    expect(noVw.cssRules).toHaveLength(1);
    expect(cssInJs.cssRules).toHaveLength(2);
  });

  /**
   * @scenario declaration_unit=vw, context=inside_width_media
   * @effects preview_media_isolation_vw_follows_frame_width
   */
  it('폭 media 안의 vw 는 container 복제본에서 var 로 치환된다', () => {
    const media = new FakeMediaRule('(min-width: 768px)', [new FakeStyleRule('.w', 'width: 50vw;')]);
    const sheet = new FakeSheet([media]);

    rewriteSheets([sheet as any], state, { remPx: 16 });

    expect(sheet.cssRules[1].cssText).toBe('@container g7le-preview (min-width: 768px) { .w { width: calc(50 * var(--g7le-vw)); } }');
  });
});
