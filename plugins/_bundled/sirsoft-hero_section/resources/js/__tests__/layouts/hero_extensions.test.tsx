/**
 * @file hero_extensions.test.tsx
 * @description 사용자 화면 주입 조각 — 홈·쇼핑 조각이 각자의 확장점을 겨냥하고, 공개 API 로 받은 배너가 있을 때만 슬라이더를
 * 그리며(0건·받기 실패면 숨김), data-source 이름은 플러그인 접두를 쓴다. 동적 위치(게시판·상품·카테고리) 조각은 화면 주소 값을 대상으로
 * 넘긴다. (매니페스트 user-extension.yaml · position-registry.yaml)
 *
 * @vitest-environment node
 */
import { describe, expect, it } from 'vitest';
import home from '../../../extensions/home_hero.json';
import shop from '../../../extensions/shop_hero.json';
import board from '../../../extensions/board_hero.json';
import product from '../../../extensions/product_hero.json';
import category from '../../../extensions/category_hero.json';
import ko from '../../../lang/ko.json';
import en from '../../../lang/en.json';
import { pick, strings } from './helpers';

const fragments = [
  { doc: home as any, point: 'home_hero', source: 'heroSectionHome' },
  { doc: shop as any, point: 'shop_hero', source: 'heroSectionShop' },
];

describe('사용자 화면 주입 조각', () => {
  // @scenario surface=home_extension_targets_home_hero
  // @effects extension_injected_conditionally
  it('홈 조각은 home_hero 자리에 붙고 그 위치 배너를 받는다', () => {
    expect(home.extension_point).toBe('home_hero');
    expect(home.mode).toBe('append');
    const source = (home as any).data_sources[0];
    expect(source.endpoint).toBe('/api/plugins/sirsoft-hero_section/hero');
    expect(source.params).toEqual({ position: 'home_hero' });
    // 로그인하지 않아도 받는다 — 로그인했으면 토큰을 실어 수정 권한만 더 받는다(선택 인증)
    expect(source.auth_required).not.toBe(true);
    expect(source.auth_mode).toBe('optional');
    expect((home as any).components[0].props.position).toBe('home_hero');
  });

  // @scenario surface=shop_extension_targets_shop_hero
  // @effects extension_injected_conditionally
  it('쇼핑 조각은 shop_hero 자리에 붙고 그 위치 배너를 받는다', () => {
    expect(shop.extension_point).toBe('shop_hero');
    const source = (shop as any).data_sources[0];
    expect(source.params).toEqual({ position: 'shop_hero' });
    expect((shop as any).components[0].props.position).toBe('shop_hero');
  });

  // @scenario surface=hidden_when_no_banners
  // @scenario surface=loading_skeleton_until_response
  // @effects extension_injected_conditionally
  it('슬라이더는 늘 붙고, 응답 전(값 없음)이면 받는 중으로 알려 스켈레톤을 그리게 하며 0건이면 슬라이더가 스스로 숨는다', () => {
    for (const { doc, source } of fragments) {
      const slider = doc.components[0];
      expect(slider.name).toBe('sirsoft-hero_section.HeroSlider');
      // 0건 숨김은 슬라이더가 맡는다 — 조각에 if 를 두면 받는 동안 자리를 잡지 못한다
      expect(slider.if).toBeUndefined();
      expect(slider.props.banners).toBe(`{{${source}?.data?.banners ?? []}}`);
      expect(slider.props.loading).toBe(`{{${source} == null}}`);
      // 받는 동안의 폭은 첫 로드 때 받은 플러그인 설정 힌트로 고른다
      expect(slider.props.skeletonWidth).toBe(`{{_global.plugins?.['sirsoft-hero_section']?.slot_widths?.${slider.props.position} ?? ''}}`);
      // 배너가 없는 위치는 첫 접속(브라우저 기억 없음)에도 자리를 잡지 않는다 — 첫 로드 힌트로 고른다
      expect(slider.props.skeletonBanners).toBe(`{{_global.plugins?.['sirsoft-hero_section']?.slot_banners?.${slider.props.position}}}`);
    }
  });

  // @scenario surface=hidden_when_fetch_fails
  // @effects extension_injected_conditionally
  it('받기에 실패하면 빈 값으로 떨어져 화면 오류 없이 숨는다', () => {
    for (const { doc } of fragments) {
      const source = doc.data_sources[0];
      expect(source.fallback).toEqual({ data: null });
      // 오류 화면·알림으로 바꾸지 않는다
      expect(JSON.stringify(source)).not.toContain('showErrorPage');
      expect(JSON.stringify(source)).not.toContain('toast');
    }
  });

  // @scenario surface=data_source_keys_prefixed
  // @effects extension_keys_use_plugin_prefix
  it('조각의 data-source·전역 키는 플러그인 접두(heroSection)를 쓴다', () => {
    for (const { doc, source } of fragments) {
      expect(doc.data_sources.map((s: any) => s.id)).toEqual([source]);
      expect(source.startsWith('heroSection')).toBe(true);
      // 조각이 쓰는 전역 키는 플러그인 접두다. 코어가 첫 로드 때 주는 플러그인 설정(`_global.plugins`)만 읽기로 예외다
      const globals = strings(doc).flatMap((t) => [...t.matchAll(/_global\.([A-Za-z_]\w*)/g)].map((m) => m[1]));
      expect(globals.filter((k) => k !== 'plugins').every((k) => k.startsWith('heroSection'))).toBe(true);
      expect(JSON.stringify(doc)).not.toMatch(/"global"[^}]*"plugins"|plugins=/);
      const key = (doc.data_sources[0].label_key as string).replace('$t:sirsoft-hero_section.', '');
      expect(pick(ko, key)).toBeTruthy();
      expect(pick(en, key)).toBeTruthy();
    }
  });

  // @scenario surface=admin_edit_link_for_editors
  // @effects extension_passes_edit_permission_to_slider
  it('로그인했으면 토큰을 실어 받고(선택 인증), 응답의 수정 권한을 슬라이더에 넘긴다', () => {
    for (const { doc, source } of fragments) {
      expect(doc.data_sources[0].auth_mode).toBe('optional');
      expect(doc.components[0].props.editable).toBe(`{{${source}?.data?.abilities?.can_update === true}}`);
    }
  });

  // @scenario surface=extension_passes_content_class
  // @effects extension_content_class_passed_to_slider
  it('템플릿이 확장점으로 넘긴 본문 상자 클래스를 슬라이더에 넘기고, 데스크톱은 따로 넘긴 값을 쓴다(없으면 기본 값)', () => {
    for (const { doc } of fragments) {
      const slider = doc.components[0];
      expect(slider.props.contentClass).toBe("{{extensionPointProps?.contentClass ?? ''}}");
      expect(slider.responsive.desktop.props.contentClass).toBe("{{extensionPointProps?.contentClassDesktop ?? extensionPointProps?.contentClass ?? ''}}");
      // 표시 폭은 공개 API 설정(settings.width)으로 받는다
      expect(slider.props.settings).toMatch(/\?\.data\?\.settings}}$/);
    }
  });

  // @scenario case=dynamic_fragments_pass_route_target
  // @effects dynamic_slot_renders_only_current_target
  it('동적 위치 조각은 화면 주소 값을 대상으로 받아 오고, 응답이 지금 주소 값의 것일 때만 배너를 넘긴다 — 첫 로드 힌트는 넘기지 않는다(D8)', () => {
    const dynamic = [
      { doc: board as any, point: 'board_hero', source: 'heroSectionBoard', route: 'route?.slug' },
      { doc: product as any, point: 'product_hero', source: 'heroSectionProduct', route: 'route?.product_code' },
      { doc: category as any, point: 'category_hero', source: 'heroSectionCategory', route: 'route?.slug' },
    ];
    for (const { doc, point, source, route } of dynamic) {
      expect(doc.extension_point).toBe(point);
      const ds = doc.data_sources[0];
      expect(ds.id).toBe(source);
      expect(ds.endpoint).toBe('/api/plugins/sirsoft-hero_section/hero');
      // 경로 값을 파라미터로 읽어야 엔진이 SPA 이동 때 다시 부른다(마운트 때 한 번 부르는 방식은 이동 뒤 다시 부르지 않았다 — 06 §5 실측)
      expect(ds.params).toEqual({ position: point, target: `{{${route}}}` });
      expect(ds.auth_mode).toBe('optional');
      const slider = doc.components[0];
      expect(slider.props.position).toBe(point);
      expect(slider.props.target).toBe(`{{String(${route} ?? '')}}`);
      // 「관리자에서 수정」 단추는 정적 위치와 같이 응답의 수정 권한으로 그린다
      expect(slider.props.editable).toBe(`{{${source}?.data?.abilities?.can_update === true}}`);
      expect(slider.props.skeletonWidth).toBeUndefined();
      expect(slider.props.skeletonBanners).toBeUndefined();
      expect(slider.props.skeletonOverlay).toBeUndefined();
      const value = (expr: string, data: unknown, routeValues: Record<string, string>) =>
        Function(source, 'route', `return (${expr.slice(2, -2)})`)(data, routeValues);
      const routeKey = route.replace('route?.', '');
      const here = { [routeKey]: 'free' };
      const matching = { data: { target: 'free', banners: [{ id: 1 }] } };
      const stale = { data: { target: 'event', banners: [{ id: 9 }] } };
      // 지금 주소 값의 응답만 그리고, 앞 대상 응답이 남아 있는 동안은 받는 중이다(앞 대상 배너가 보이지 않게)
      expect(value(slider.props.banners, matching, here)).toEqual([{ id: 1 }]);
      expect(value(slider.props.banners, stale, here)).toEqual([]);
      expect(value(slider.props.loading, stale, here)).toBe(true);
      expect(value(slider.props.loading, matching, here)).toBe(false);
      expect(value(slider.props.loading, undefined, here)).toBe(true);
      // 받기 실패(빈 값)는 받는 중이 아니다 — 숨는다
      expect(value(slider.props.loading, { data: null }, here)).toBe(false);
      for (const lang of [ko, en] as any[]) expect(lang.editor.data_source[source]).toBeTruthy();
    }
  });
});
