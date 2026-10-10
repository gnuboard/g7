/**
 * setUserFilterExtHandler 테스트 (#705)
 *
 * 회원 목록 확장 필터 채널(`ext_filters[]`)의 원소 조립 — 같은 키 교체, 빈 값 제거, 원소 형식,
 * 잘못된 키 무시, 현재 배열을 컨텍스트에서 읽는 폴백.
 */

import { describe, it, expect, afterEach } from 'vitest';
import { setUserFilterExtHandler, applyUserFilterExt } from '../setUserFilterExtHandler';

describe('setUserFilterExtHandler', () => {
  afterEach(() => {
    delete (window as any).G7Core;
  });

  // @scenario filter_axis=extension, outcome=narrows
  // @effects ext_filter_channel_assembled
  it('새 키를 `{확장id}.{키}={값}` 원소로 더한다', () => {
    const result = setUserFilterExtHandler({ params: { key: 'sirsoft-ecommerce.mileage_min', value: '1000', current: [] } });

    expect(result).toEqual(['sirsoft-ecommerce.mileage_min=1000']);
  });

  // @scenario filter_axis=extension, outcome=narrows
  // @effects ext_filter_channel_assembled
  it('같은 키는 교체하고 다른 키는 보존한다', () => {
    const current = ['sirsoft-ecommerce.mileage_min=1000', 'sirsoft-ecommerce.has_address=1'];

    const result = setUserFilterExtHandler({ params: { key: 'sirsoft-ecommerce.mileage_min', value: 2000, current } });

    expect(result).toEqual(['sirsoft-ecommerce.has_address=1', 'sirsoft-ecommerce.mileage_min=2000']);
  });

  // @scenario filter_axis=extension, outcome=narrows
  // @effects ext_filter_channel_assembled
  it('빈 값·null·false 는 그 키를 지운다', () => {
    const current = ['sirsoft-ecommerce.mileage_min=1000', 'sirsoft-ecommerce.has_address=1'];

    expect(setUserFilterExtHandler({ params: { key: 'sirsoft-ecommerce.mileage_min', value: '', current } }))
      .toEqual(['sirsoft-ecommerce.has_address=1']);
    expect(setUserFilterExtHandler({ params: { key: 'sirsoft-ecommerce.has_address', value: null, current } }))
      .toEqual(['sirsoft-ecommerce.mileage_min=1000']);
    expect(setUserFilterExtHandler({ params: { key: 'sirsoft-ecommerce.has_address', value: false, current } }))
      .toEqual(['sirsoft-ecommerce.mileage_min=1000']);
  });

  // @scenario filter_axis=extension, outcome=narrows
  // @effects ext_filter_channel_assembled
  it('원래 배열을 바꾸지 않고 새 배열을 돌려준다', () => {
    const current = ['sirsoft-ecommerce.mileage_min=1000'];

    const result = setUserFilterExtHandler({ params: { key: 'sirsoft-ecommerce.mileage_max', value: '5000', current } });

    expect(result).not.toBe(current);
    expect(current).toEqual(['sirsoft-ecommerce.mileage_min=1000']);
  });

  // @scenario filter_axis=extension, outcome=narrows
  // @effects ext_filter_channel_assembled
  it('형식이 어긋난 키는 무시하고 기존 배열을 그대로 돌려준다', () => {
    const current = ['sirsoft-ecommerce.mileage_min=1000'];

    expect(setUserFilterExtHandler({ params: { key: 'NoDot', value: '1', current } })).toEqual(current);
    expect(setUserFilterExtHandler({ params: { key: 'Upper.Case', value: '1', current } })).toEqual(current);
    expect(setUserFilterExtHandler({ params: { value: '1', current } })).toEqual(current);
  });

  // @scenario filter_axis=extension, outcome=narrows
  // @effects ext_filter_channel_assembled
  it('값의 `&` 를 걷고 255자로 자른다 — 서버 정규식과 같은 경계', () => {
    const long = 'x'.repeat(300);

    const [entry] = applyUserFilterExt([], 'sirsoft-ecommerce.shipping_country', `a&b${long}`);

    expect(entry.startsWith('sirsoft-ecommerce.shipping_country=ab')).toBe(true);
    expect(entry.split('=')[1].length).toBe(255);
    expect(entry.includes('&')).toBe(false);
  });

  // @scenario filter_axis=extension, outcome=narrows
  // @effects ext_filter_channel_assembled
  it('current 를 주지 않으면 컨텍스트의 _local.userFilterExtraQuery 를 읽는다', () => {
    const result = setUserFilterExtHandler(
      { params: { key: 'sirsoft-ecommerce.has_address', value: '1' } },
      { data: { _local: { userFilterExtraQuery: ['sirsoft-ecommerce.mileage_min=1000'] } } }
    );

    expect(result).toEqual(['sirsoft-ecommerce.mileage_min=1000', 'sirsoft-ecommerce.has_address=1']);
  });

  // @scenario filter_axis=extension, outcome=narrows
  // @effects ext_filter_channel_assembled
  it('컨텍스트에도 없으면 G7Core 로컬 상태를 읽고, 그것도 없으면 빈 배열에서 시작한다', () => {
    (window as any).G7Core = { state: { getLocal: () => ({ userFilterExtraQuery: ['a.b=1'] }) } };
    expect(setUserFilterExtHandler({ params: { key: 'a.c', value: '2' } })).toEqual(['a.b=1', 'a.c=2']);

    delete (window as any).G7Core;
    expect(setUserFilterExtHandler({ params: { key: 'a.c', value: '2' } })).toEqual(['a.c=2']);
  });
});
