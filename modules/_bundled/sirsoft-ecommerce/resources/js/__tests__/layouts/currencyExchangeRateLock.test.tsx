import React from 'react';
import {beforeEach, afterEach, describe, it, expect} from 'vitest';
import {createLayoutTest} from '@core/template-engine/__tests__/utils/layoutTestUtils';
import {ComponentRegistry} from '@core/template-engine/ComponentRegistry';
import table from '../../../layouts/admin/partials/admin_ecommerce_settings/_currency_exchange_table.json';
import cards from '../../../layouts/admin/partials/admin_ecommerce_settings/_currency_exchange_cards.json';
import tab from '../../../layouts/admin/partials/admin_ecommerce_settings/_tab_language_currency.json';
import settingsKo from '../../../lang/partial/ko/admin/settings.json';

function nodes(value: any): any[] {
  if (!value || typeof value !== 'object') return [];
  return [value,...Object.values(value).flatMap(nodes)];
}
let utils: ReturnType<typeof createLayoutTest>;
beforeEach(() => {
  const registry:any=ComponentRegistry.getInstance();
  registry.registry={
    Div:{component:({children,...props}:any)=><div {...props}>{children}</div>,metadata:{name:'Div',type:'basic'}},
    Span:{component:({children,...props}:any)=><span {...props}>{children}</span>,metadata:{name:'Span',type:'basic'}},
    Label:{component:({children,...props}:any)=><label {...props}>{children}</label>,metadata:{name:'Label',type:'basic'}},
    A:{component:({children,...props}:any)=><a {...props}>{children}</a>,metadata:{name:'A',type:'basic'}},
    Fragment:{component:({children}:any)=><>{children}</>,metadata:{name:'Fragment',type:'layout'}},
    Input:{component:({value,...props}:any)=><input {...props} value={value} readOnly/>,metadata:{name:'Input',type:'basic'}},
    Select:{component:({options=[],value,...props}:any)=><select {...props} value={value} onChange={()=>{}}>{options.map((option:any)=><option key={option.value} value={option.value}>{option.label}</option>)}</select>,metadata:{name:'Select',type:'basic'}},
  };
});

describe('환율 제공자 선택과 상태 안내',()=>{
  /** @effects currency_lock_layout */
  it.each([{providers:[]},{providers:[{id:'bank-a',name:{ko:'첫 번째 은행'}},{id:'bank-b',name:{ko:'두 번째 은행'}}]}])('제공자 목록 $providers에서 수동 선택을 유지한다',async ({providers})=>{
    const input=nodes(tab).find(n=>n.id==='exchange_rate_provider_select');
    utils=createLayoutTest({version:'1.0.0',layout_name:'provider_options',components:[input]} as any,{
      initialData:{settings:{data:{available_rate_providers:providers}},_computed:{isReadOnly:false}},
      initialState:{_local:{form:{language_currency:{rate_provider:''}},errors:{}}},
    });
    const {container}=await utils.render();
    expect([...container.querySelectorAll('option')].map(e=>e.value)).toEqual(providers.length?['',...providers.map(p=>p.id)]:[]);
    expect(container.querySelector('select')?.value).toBe(providers.length?'':undefined);
  });
  /** @effects currency_lock_layout */
  it.each([
    ['auth_key_required','인증키를 입력하면 자동 관리가 시작됩니다.'],
    ['auto_manage_disabled','제공자 설정에서 환율 자동 관리를 켜세요.'],
    ['unsupported_base','기본통화를 지원하지 않아 환율을 직접 입력해야 합니다.'],
  ])('%s 상태 배너에 번역·이름·갱신 시각·설정 링크가 렌더된다',async(notice,message)=>{
    const banner=nodes(tab).find(n=>n.id==='exchange_rate_provider_banner');
    utils=createLayoutTest({version:'1.0.0',layout_name:'provider_notice',components:[banner]} as any,{
      translations:{'sirsoft-ecommerce':{admin:{settings:settingsKo}}},locale:'ko',
      initialData:{$templateId:'test-template',$locale:'ko',_computed:{activeRateProvider:{
        name:{ko:'첫 번째 은행'},last_updated_at_formatted:'2026-09-14 11:30',settings_url:'/admin/plugins/bank-a/settings',notice_key:notice,
      }}},
    });
    const {container}=await utils.render();
    const status=container.querySelector('[role="status"]');
    expect(status?.textContent).toContain(message);
    expect(status?.textContent).toContain('첫 번째 은행');
    expect(status?.textContent).toContain('2026-09-14 11:30');
    expect(status?.querySelector('a')?.getAttribute('href')).toBe('/admin/plugins/bank-a/settings');
  });
  /** @effects currency_lock_layout */
  it('수동 입력 상태에서는 제공자 배너를 렌더하지 않는다',async()=>{
    const banner=nodes(tab).find(n=>n.id==='exchange_rate_provider_banner');
    utils=createLayoutTest({version:'1.0.0',layout_name:'provider_manual',components:[banner]} as any,{
      initialData:{_computed:{activeRateProvider:null}},
    });
    const {container}=await utils.render();
    expect(container.querySelector('[role="status"]')).toBeNull();
  });
});
afterEach(()=>{utils?.cleanup();(ComponentRegistry.getInstance() as any).registry={};});

describe.each([['table',table],['cards',cards]])('Currency rate lock %s', (_name,partial) => {
  /** @effects currency_lock_layout */
  it.each([
    {managed:[],readOnly:false,expected:[false,false]},
    {managed:['USD'],readOnly:false,expected:[true,false]},
    {managed:[],readOnly:true,expected:[true,true]},
  ])('locks only managed rates for $managed and readOnly=$readOnly', async ({managed,readOnly,expected}) => {
    const input=nodes(partial).find(n=>n.name==='Input' && n.props?.name?.includes("'.exchange_rate'"));
    expect(input).toBeTruthy();
    utils=createLayoutTest({version:'1.0.0',layout_name:'currency_rate_lock',components:[{type:'basic',name:'Div',iteration:{source:'_local.rows',item_var:'currency'},children:[input]}]} as any,{initialData:{_computed:{managedRateCodes:managed,isReadOnly:readOnly}},initialState:{_local:{rows:[{code:'USD',_idx:0,exchange_rate:0.7},{code:'VND',_idx:1,exchange_rate:20}]}}});
    const {container}=await utils.render();
    expect([...container.querySelectorAll('input')].map(e=>e.disabled)).toEqual(expected);
  });
  /** @effects currency_lock_layout */
  it('잠긴 환율 입력이 커서만이 아니라 흐리기로도 구분된다', () => {
    // disabled:cursor-not-allowed 만 있으면 마우스를 올려야 잠긴 줄 안다 — 운영자는 편집 가능한 칸으로 읽는다.
    // 저장소 선례(main.css 의 .input/.btn, sirsoft-board)는 두 유틸을 항상 짝으로 쓴다.
    const input=nodes(partial).find(n=>n.name==='Input' && n.props?.name?.includes("'.exchange_rate'"));
    expect(input.props.className).toContain('disabled:cursor-not-allowed');
    expect(input.props.className).toContain('disabled:opacity-50');
  });
  /** @effects currency_lock_layout */
  it('잠금 표시 유틸 두 개가 파셜 전역에서 짝을 유지한다', () => {
    // 위 검사는 환율 입력 한 칸만 본다 — 같은 파셜의 아이콘 버튼들이 짝을 잃어도 통과했다.
    // 실제로 읽기 전용 상태에서 수정·삭제 버튼만 흐려지지 않는 회귀가 있었다(2026-09-15).
    const broken=nodes(partial)
      .filter(n=>typeof n.props?.className==='string' && n.props.className.includes('disabled:cursor-not-allowed'))
      .filter(n=>!n.props.className.includes('disabled:opacity-50'))
      .map(n=>`${n.name}: ${n.props.className}`);
    expect(broken).toEqual([]);
  });
  /** @effects currency_lock_layout */
  it('keeps management badges unbroken outside the rate input row', () => {
    const all=nodes(partial);
    const badge=all.find(n=>n.name==='Span' && n.if?.includes('managedRateCodes') && n.children);
    expect(badge.props.className).toContain('whitespace-nowrap');
    expect(badge.props.className).toContain('shrink-0');
    const inputRow=all.find(n=>n.name==='Div' && n.children?.some((c:any)=>c.name==='Input' && c.props?.name?.includes("'.exchange_rate'")));
    expect(inputRow.children).not.toContain(badge);
    const editable=all.filter(n=>n.name==='Input' && /symbol|base_unit/.test(n.props?.name??''));
    expect(editable.every(n=>!n.props.disabled.includes('managedRateCodes'))).toBe(true);
  });
});
