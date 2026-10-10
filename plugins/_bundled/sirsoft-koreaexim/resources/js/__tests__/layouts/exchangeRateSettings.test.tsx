import React from 'react';
import {afterEach, beforeEach, describe, expect, it} from 'vitest';
import {fireEvent, waitFor} from '@testing-library/react';
import {createLayoutTest} from '@core/template-engine/__tests__/utils/layoutTestUtils';
import {ComponentRegistry} from '@core/template-engine/ComponentRegistry';
import {actionsOf, asArray} from '@core/template-engine/__tests__/utils/layoutShapeUtils';
import filters from '../../../layouts/admin/partials/plugin_settings/_history_filter.json';
import notifications from '../../../layouts/admin/partials/plugin_settings/_notifications_card.json';
import form from '../../../layouts/admin/partials/plugin_settings/_settings_form.json';
import history from '../../../layouts/admin/partials/plugin_settings/_history_grid.json';
import statusCard from '../../../layouts/admin/partials/plugin_settings/_status_card.json';
import tplModal from '../../../layouts/admin/partials/plugin_settings/_modal_notification_template_edit.json';
import fs from 'node:fs';
import path from 'node:path';
import settingsLayout from '../../../layouts/admin/plugin_settings.json';
import tabSettings from '../../../layouts/admin/partials/plugin_settings/_tab_settings.json';
import tabHistory from '../../../layouts/admin/partials/plugin_settings/_tab_history.json';
import ko from '../../../lang/ko.json';
import en from '../../../lang/en.json';
import {Toggle} from '../../../../../../../templates/_bundled/sirsoft-admin_basic/src/components/composite/Toggle';

const pluginRoot=process.cwd();
// 코어가 /api/admin/notification-channels 로 내려주는 모양 — 확장 채널은 source 가 core 가 아니다.
const CORE_CHANNELS=[{id:'mail',name:'메일',source:'core'},{id:'database',name:'사이트내 알림',source:'core'}];
const EXT_CHANNELS=[{id:'sms',name:'SMS',source:'plugin:sirsoft-message_bizppurio',source_label:'문자 플러그인'},{id:'alimtalk',name:'알림톡',source:'plugin:sirsoft-message_bizppurio',source_label:'문자 플러그인'}];
let utils: ReturnType<typeof createLayoutTest>;
function nodes(value: any): any[] {
  if (!value || typeof value !== 'object') return [];
  return [value, ...Object.values(value).flatMap(nodes)];
}
beforeEach(() => {
  const registry: any = ComponentRegistry.getInstance();
  registry.registry = {};
  for (const [name, tag] of Object.entries({Div:'div', H3:'h3', P:'p', Label:'label', Span:'span', Input:'input', Checkbox:'input', Button:'button'})) {
    registry.registry[name] = {component: ({text, children, ...props}: any) => React.createElement(tag, {...props, ...(name === 'Checkbox' ? {type:'checkbox'} : {})}, tag === 'input' ? undefined : children || text), metadata:{name,type:'basic'}};
  }
  registry.registry.Select = {component: ({options, ...props}: any) => <select {...props}>{options?.map((o: any) => <option key={o.value} value={o.value}>{o.label}</option>)}</select>,metadata:{name:'Select',type:'composite'}};
  registry.registry.Fragment = {component: ({children}: any) => <>{children}</>, metadata:{name:'Fragment',type:'layout'}};
});
afterEach(() => {utils?.cleanup(); (ComponentRegistry.getInstance() as any).registry={}; delete (window as any).__templateApp;});

describe('Exchange rate history controls', () => {
  /** @scenario flow=notifications
   * @effects admin_ui_contract */
  it('실제 Toggle이 알림 이름과 채널을 접근성 이름으로 렌더한다',async()=>{
    (ComponentRegistry.getInstance() as any).registry.Toggle={component:Toggle,metadata:{name:'Toggle',type:'composite'}};
    const templateRow=nodes(notifications).find((n:any)=>String(n.id ?? '').startsWith('koreaexim_template_row'));
    const toggle=nodes(templateRow).find((n:any)=>n.name==='Toggle');
    utils=createLayoutTest({version:'1.0.0',layout_name:'notification_accessible_name',components:[toggle]} as any,{
      translations:{'sirsoft-koreaexim':ko},locale:'ko',
      initialData:{$templateId:'test-template',$locale:'ko',def:{name:{ko:'환율 갱신 실패'}},template:{channel:'mail',is_active:true},_computed:{isNotificationReadOnly:false},koreaeximNotificationChannels:{data:{channels:CORE_CHANNELS}}},
    });
    const {container}=await utils.render();
    expect(container.querySelector('[role=switch]')?.getAttribute('aria-label')).toBe('환율 갱신 실패 / 메일');
  });
  /** @scenario flow=channels
   * @effects admin_ui_contract */
  it('발송 수단이 코어 목록을 그대로 깔고 복수로 켜진다', async () => {
    (ComponentRegistry.getInstance() as any).registry.Toggle={component:Toggle,metadata:{name:'Toggle',type:'composite'}};
    const row=nodes(notifications).find((n:any)=>String(n.id ?? '').startsWith('koreaexim_channel_row'));
    expect(row).toBeTruthy();
    utils=createLayoutTest({version:'1.0.0',layout_name:'channel_rows',components:[row]} as any,{
      initialData:{def:{id:7,channels:['mail']},_computed:{isNotificationReadOnly:false},
        koreaeximNotificationChannels:{data:{channels:[...CORE_CHANNELS,...EXT_CHANNELS]}}},
    });
    const {container}=await utils.render();
    // 드롭다운 한 칸이 아니라 채널마다 줄이 깔린다 — 확장이 들인 채널까지.
    expect([...container.querySelectorAll('[role=switch]')]).toHaveLength(4);
    expect(container.textContent).toContain('SMS');
    expect(container.textContent).toContain('문자 플러그인');
    // 정의에 켜진 것만 on 이다.
    expect([...container.querySelectorAll('[role=switch]')].map(e=>e.getAttribute('aria-checked')))
      .toEqual(['true','false','false','false']);
  });
  /** @scenario flow=channels
   * @effects admin_ui_contract */
  it('켠 수단에 템플릿이 없으면 발송되지 않는다고 알린다', async () => {
    // 코어는 템플릿 없는 채널을 조용히 건너뛴다(로그만 남는다) — 화면이 그 사실을 말해야 한다.
    const row=nodes(notifications).find((n:any)=>String(n.id ?? '').startsWith('koreaexim_channel_row'));
    utils=createLayoutTest({version:'1.0.0',layout_name:'channel_missing_template',components:[row]} as any,{
      translations:{'sirsoft-koreaexim':ko},locale:'ko',
      initialData:{$templateId:'test-template',$locale:'ko',
        def:{id:7,channels:['mail','sms'],templates:[{channel:'mail'}]},
        _computed:{isNotificationReadOnly:false},
        koreaeximNotificationChannels:{data:{channels:[...CORE_CHANNELS,...EXT_CHANNELS]}}},
    });
    const {container}=await utils.render();
    expect(container.textContent).toContain('이 수단의 템플릿이 없어 발송되지 않습니다');
  });
  /** @scenario flow=channels
   * @effects admin_ui_contract */
  it('저장은 버튼을 눌러야 하고 한 수단도 없으면 막는다', () => {
    const save=nodes(notifications).find((n:any)=>String(n.id ?? '').startsWith('koreaexim_save_channels'));
    expect(save).toBeTruthy();
    // 고친 것이 없거나 전부 끄면 비활성 — 코어가 min:1 로 거절하기 전에 화면이 막는다.
    expect(save.props.disabled).toContain('.length === 0');
    const call=JSON.stringify(save.actions);
    expect(call).toContain('/api/admin/notification-definitions/');
    expect(call).toContain('"method":"PUT"');
    expect(call).toContain('channels');
    // 토글은 폼만 고친다 — 즉시 저장이 아니다.
    const toggle=nodes(notifications).find((n:any)=>n.name==='Toggle' && JSON.stringify(n.actions).includes('koreaeximChannels'));
    expect(JSON.stringify(toggle.actions)).not.toContain('apiCall');
  });
  /** @scenario flow=channels
   * @effects admin_ui_contract */
  it('저장 버튼이 진행 표시와 완료 후 복귀를 갖춘다', () => {
    // 누른 버튼에 진행 표시가 없으면 눌렸는지 알 수 없고, 끝난 뒤 폼을 비우지 않으면
    // 저장했는데도 「저장」·「취소」가 남아 아직 안 된 것처럼 보인다(제보 2026-09-15).
    const save=nodes(notifications).find((n:any)=>String(n.id ?? '').startsWith('koreaexim_save_channels'));
    const spinner=nodes(save).find((n:any)=>n.props?.className==='spinner-sm');
    expect(spinner).toBeTruthy();
    expect(spinner.if).toContain('koreaeximSavingChannels === def.id');   // 누른 버튼만
    expect(save.props.disabled).toContain('koreaeximSavingChannels');      // 저장 중 재클릭 금지

    const call=(save.actions[0].actions ?? []).find((a:any)=>a.handler==='apiCall');
    const okText=JSON.stringify(call.onSuccess);
    // 진행 표시는 양쪽에서 끈다.
    expect(okText).toContain('"koreaeximSavingChannels":null');
    expect(JSON.stringify(call.onError)).toContain('"koreaeximSavingChannels":null');
    // 성공해도 폼의 임시 값은 **비우지 않는다** — 목록 재조회가 끝나기 전에 비우면 낡은
    // def.channels 로 되돌아가 방금 끈 스위치가 켜졌다 꺼진다(제보 2026-09-15).
    expect(okText).not.toContain('"koreaeximChannels"');
    expect(okText).toContain('refetchDataSource');
    // 「고칠 것 없음」 판정은 존재 여부가 아니라 저장값과의 비교다 — 재조회가 오면 저절로 가라앉는다.
    expect(save.props.disabled).toContain('JSON.stringify');
    expect(save.props.disabled).toContain('def.channels');
    const cancel=nodes(notifications).find((n:any)=>String(n.id ?? '').startsWith('koreaexim_cancel_channels'));
    expect(cancel.if).toContain('JSON.stringify');
  });
  /** @scenario flow=channels
   * @effects admin_ui_contract */
  it('레이아웃이 채널 id·이름을 적어 두지 않는다', () => {
    // 화면에 채널을 적어 두면 확장이 들인 채널에 도달할 수 없다(제보 2026-09-15).
    const layouts=[notifications, tplModal, settingsLayout];
    for (const layout of layouts) {
      const text=JSON.stringify(layout);
      expect(text).not.toContain('sirsoft-koreaexim.channels.');
      expect(text).not.toMatch(/"value"\s*:\s*"(mail|database|sms|alimtalk)"/);
    }
    // 대신 코어 채널 목록을 받아 온다.
    const ds=(settingsLayout as any).data_sources.find((d:any)=>d.endpoint==='/api/admin/notification-channels');
    expect(ds).toBeTruthy();
    expect(ds.fallback?.data?.channels).toEqual([]);
    // 채널을 나눠 그리지 않으므로 템플릿을 전 채널로 받는다 — 채널 전환 때문에 화면을 다시 부르지 않는다.
    const defs=(settingsLayout as any).data_sources.find((d:any)=>String(d.endpoint).includes('notification-definitions'));
    expect(defs.params.template_channel).toBe('all');
  });
  /** @scenario flow=filter
   * @effects admin_ui_contract */
  it('passes selected status as a string and resets pagination', async () => {
    utils=createLayoutTest({version:'1.0.0',layout_name:'rate_filters',components:[filters]} as any,{translations:{'sirsoft-koreaexim':ko},locale:'ko',initialState:{query:{page:3}}});
    const {container}=await utils.render();
    const select=container.querySelectorAll('select')[0];
    fireEvent.change(select,{target:{value:'auth_error'}});
    await waitFor(() => expect(utils.mockNavigate).toHaveBeenCalled());
    const target=utils.mockNavigate.mock.calls[0][0];
    expect(target).toContain('status=auth_error');
    expect(target).toContain('page=1');
  });
  /** @scenario flow=expand
   * @effects admin_ui_contract */
  it('loads currency details when a row expands', () => {
    const event=nodes(history).find(n=>n.event==='onExpandChange');
    expect(event).toBeTruthy();
    expect(JSON.stringify(event)).toContain('/api/plugins/sirsoft-koreaexim/admin/runs/');
  });
  /** @scenario flow=weekdays
   * @effects admin_ui_contract */
  it.each([['ko',ko],['en',en]])('renders seven accessible weekday labels in %s', async (locale, dictionary) => {
    const labels=nodes(form).filter(n=>n.name==='Label' && n.children?.some((c:any)=>c.name==='Checkbox'));
    utils=createLayoutTest({version:'1.0.0',layout_name:'rate_days',components:labels} as any,{translations:{'sirsoft-koreaexim':dictionary},locale:locale as string,initialState:{_local:{form:{run_days:[1,2,3,4,5]}},_computed:{isReadOnly:false}}});
    const {container}=await utils.render();
    expect(container.querySelectorAll('input[type="checkbox"]')).toHaveLength(7);
    expect([...container.querySelectorAll('label')].every(e=>e.textContent?.trim())).toBe(true);
    expect(container.textContent).not.toContain('$t:');
  });
  /** @scenario flow=tabs
   * @effects admin_ui_contract */
  it('탭 2개를 선언하고 기본 탭이 settings 이며 본문을 if 로 가른다', () => {
    const nav=nodes(settingsLayout).find((n:any)=>n.name==='TabNavigation');
    expect(nav.props.tabs.map((t:any)=>t.id)).toEqual(['settings','history']);
    expect((settingsLayout as any).computed.activeTab).toBe("{{query.tab || _global.activeKoreaeximTab || 'settings'}}");
    expect(JSON.stringify(tabSettings)).toContain("_computed.activeTab === 'settings'");
    expect(JSON.stringify(tabHistory)).toContain("_computed.activeTab === 'history'");
  });
  /** @scenario flow=tabs
   * @effects admin_ui_contract */
  it('탭 전환이 기존 쿼리를 보존하며 tab 만 병합한다', () => {
    const nav=nodes(settingsLayout).find((n:any)=>n.name==='TabNavigation');
    const change=actionsOf(nav).find((a:any)=>a.event==='onTabChange');
    expect(change).toBeTruthy();
    expect(change!.handler).toBe('sequence');
    const [setState,navigate]=asArray((change as any).actions);
    expect(setState.handler).toBe('setState');
    expect(setState.params.target).toBe('global');
    expect(navigate.handler).toBe('navigate');
    expect(navigate.params.mergeQuery).toBe(true);
    expect(navigate.params.query.tab).toBe('{{$args[0]}}');
  });
  /** @scenario flow=tabs
   * @effects admin_ui_contract */
  it('탭1 이 상태·설정·알림·최근이력 카드를 순서대로 포함한다', () => {
    const partials=nodes(tabSettings).map((n:any)=>n.partial).filter(Boolean);
    expect(partials).toEqual(['_status_card.json','_settings_form.json','_notifications_card.json','_recent_runs.json']);
  });
  /** @scenario flow=tabs
   * @effects admin_ui_contract */
  it('상태 카드가 한 줄 요약행과 ml-auto 액션 버튼 구조를 지킨다', () => {
    const classed=nodes(statusCard).filter((n:any)=>typeof n.props?.className==='string');
    const summaries=classed.filter((n:any)=>/\bflex-wrap\b/.test(n.props.className) && !/\bml-auto\b/.test(n.props.className));
    expect(summaries).toHaveLength(1);
    const summary=summaries[0];
    expect(summary.props.className).toMatch(/\bflex\b/);
    expect(summary.props.className).not.toMatch(/\bflex-col\b/);
    const actions=asArray(summary.children).filter((c:any)=>typeof c?.props?.className==='string' && /\bml-auto\b/.test(c.props.className));
    expect(actions).toHaveLength(1);
    expect(nodes(actions[0]).filter((n:any)=>n.name==='Button')).toHaveLength(2);
    const ref=/koreaeximStatus\?\.data\?\.([A-Za-z_]+)/g;
    const all=new Set([...JSON.stringify(statusCard).matchAll(ref)].map(m=>m[1]));
    const inRow=new Set([...JSON.stringify(summary).matchAll(ref)].map(m=>m[1]));
    const summarised=[...all].filter(f=>f!=='scheduler_hint');
    expect(summarised.length).toBeGreaterThanOrEqual(6);
    for (const field of summarised) expect(inRow.has(field)).toBe(true);
  });
  /** @scenario flow=tabs
   * @effects admin_ui_contract */
  it('편집기 스펙 샘플이 레이아웃이 읽는 상태 필드를 전부 갖는다', () => {
    const dir=path.join(pluginRoot,'resources/layouts/admin');
    const files=[dir,path.join(dir,'partials/plugin_settings')].flatMap(d=>fs.readdirSync(d).filter(f=>f.endsWith('.json')).map(f=>path.join(d,f)));
    const used=new Set<string>();
    for (const file of files) for (const m of fs.readFileSync(file,'utf8').matchAll(/koreaeximStatus\?\.data\?\.([A-Za-z_]+)/g)) used.add(m[1]);
    expect(used.size).toBeGreaterThan(0);
    const spec=JSON.parse(fs.readFileSync(path.join(pluginRoot,'editor-spec.json'),'utf8'));
    const sample=spec?.sampleData?.byDataSourceId?.koreaeximStatus?.data ?? {};
    for (const field of used) expect(Object.keys(sample)).toContain(field);
  });
  /** @scenario flow=notifications
   * @effects admin_ui_contract */
  it('알림 데이터소스가 자기 확장 정의만 조회한다', () => {
    const ds=(settingsLayout as any).data_sources.filter((d:any)=>typeof d.endpoint==='string' && d.endpoint.includes('notification-definitions'));
    expect(ds.length).toBeGreaterThan(0);
    for (const d of ds) {
      expect(d.endpoint).toContain('extension_type=plugin');
      expect(d.endpoint).toContain('extension_identifier=sirsoft-koreaexim');
    }
  });
  /** @scenario flow=expand
   * @effects admin_ui_contract */
  it('이력 그리드가 서버 페이지네이션과 행 펼침을 쓰고 빈·403 상태를 갖춘다', () => {
    const grid=nodes(history).find((n:any)=>n.props?.serverSidePagination===true);
    expect(grid).toBeTruthy();
    expect(grid.props.expandChildren).toBeTruthy();
    // DataGridProps 에 loading 계약이 없으므로 로딩은 그리드 형제 스피너로 표현한다.
    const spinner=nodes(tabHistory).find((n:any)=>typeof n.if==='string' && n.if.includes('koreaeximRuns?.loading'));
    expect(spinner).toBeTruthy();
    expect(nodes(spinner).some((n:any)=>n.props?.className==='spinner-sm')).toBe(true);
    const runs=(settingsLayout as any).data_sources.filter((d:any)=>typeof d.endpoint==='string' && d.endpoint.includes('/admin/runs'));
    expect(runs.length).toBeGreaterThan(0);
    expect(runs.some((d:any)=>d.fallback?.data?.pagination)).toBe(true);
    expect(runs.some((d:any)=>d.errorHandling?.['403'])).toBe(true);
  });
  /** @scenario flow=buttons
   * @effects admin_ui_contract */
  it('진행 표시는 누른 버튼에만 나타난다 — 두 버튼이 한 플래그를 공유하지 않는다', () => {
    // 연결 테스트와 지금 갱신은 나란히 놓여 항상 둘 다 보인다. 진행 표시가 키 단독 truthy 판정이면
    // 한쪽을 눌러도 양쪽이 돌아 어느 쪽이 호출됐는지 화면이 거짓말을 한다(제보 2026-09-15).
    // 함께 disabled 되는 것은 의도다 — 서버가 manual-budget 락 하나를 두 경로가 공유한다.
    const owners=nodes(statusCard).filter((n:any)=>Array.isArray(n.actions) && n.actions.some((a:any)=>a?.type==='click'));
    const buttons=owners.filter((n:any)=>nodes(n).some((c:any)=>c.props?.className==='spinner-sm'));
    expect(buttons.map((b:any)=>b.id).sort()).toEqual(['test_connection','update_now']);

    for (const button of buttons) {
      const spinner=nodes(button).find((c:any)=>c.props?.className==='spinner-sm');
      // 자기 것만 본다 — 키 단독 truthy 가 아니라 누가 켰는지 비교해야 한다.
      expect(spinner.if).toMatch(/_local\.busy\s*===/);
      expect(spinner.if).toContain(button.id === 'test_connection' ? "'test'" : "'update'");
      // 잠금은 공유 유지.
      expect(String(button.props.disabled)).toContain('_local.busy ||');
    }
    // 켜는 값이 버튼마다 다르다.
    const set=nodes(statusCard).filter((n:any)=>n.handler==='setState' && n.params && 'busy' in n.params).map((n:any)=>n.params.busy);
    expect(set.filter((v:any)=>v!==null).sort()).toEqual(['test','update']);
  });
  /** @scenario flow=tabs
   * @effects admin_ui_contract */
  it('전용 이력 화면과 routes.json 을 만들지 않는다', () => {
    expect(fs.existsSync(path.join(pluginRoot,'plugin.json'))).toBe(true);
    expect(fs.existsSync(path.join(pluginRoot,'resources/routes.json'))).toBe(false);
    const screens=fs.readdirSync(path.join(pluginRoot,'resources/layouts/admin')).filter(f=>!fs.statSync(path.join(pluginRoot,'resources/layouts/admin',f)).isDirectory());
    expect(screens.length).toBeGreaterThan(0);
    expect([...screens].sort()).toEqual(['plugin_settings.g7.html','plugin_settings.json']);
  });
  /** @scenario flow=tabs
   * @effects admin_ui_contract */
  it('레이아웃이 참조하는 번역 키가 ko·en 양쪽에 있다', () => {
    expect(fs.existsSync(path.join(pluginRoot,'plugin.json'))).toBe(true);
    const walk=(d:string):string[]=>fs.readdirSync(d,{withFileTypes:true}).flatMap((e:any)=>e.isDirectory()?walk(path.join(d,e.name)):[path.join(d,e.name)]);
    const text=walk(path.join(pluginRoot,'resources/layouts')).map((f:string)=>fs.readFileSync(f,'utf8')).join('\n');
    const keys=[...new Set([...text.matchAll(/\$t:sirsoft-koreaexim\.([A-Za-z0-9_.]+)/g)].map(m=>m[1].replace(/\.+$/,'')))];
    expect(keys.length).toBeGreaterThan(0);
    const resolve=(dict:any,key:string)=>key.split('.').reduce((o:any,k:string)=>o==null?undefined:o[k],dict);
    expect(keys.filter(k=>resolve(ko,k)===undefined||resolve(en,k)===undefined)).toEqual([]);
  });
});

describe('알림 수신자 회원 검색 — 빈 검색어 요청 차단 (#703)', () => {
  // 코어 회원 검색 API 는 keyword 가 비면(공백뿐이어도 요청 정리 뒤 빈 값) 422 로 거절한다.
  // 검색칸을 지우면 드롭다운이 빈 문자열로 onSearch 를 부르므로 그때는 요청하지 않아야 한다.
  const userSearchCalls=()=>nodes(tplModal).filter((n:any)=>n?.handler==='apiCall'&&String(n.target).includes('/api/admin/users/search'));
  const evaluate=(raw:string,scope:Record<string,unknown>)=>new Function(...Object.keys(scope),`return (${String(raw).trim().replace(/^\{\{/,'').replace(/\}\}$/,'')});`)(...Object.values(scope));
  /** @scenario flow=notifications
   * @effects recipient_user_search_skips_empty_keyword */
  it('검색칸이 비거나 공백뿐이면 회원 검색을 요청하지 않고, 검색어가 있으면 keyword 로 보낸다',()=>{
    const calls=userSearchCalls();
    expect(calls.length).toBeGreaterThan(0);
    for(const call of calls){
      expect(call.if,'빈 검색어 차단 조건이 없습니다 — 서버가 422 로 거절합니다.').toBeDefined();
      expect(evaluate(call.if,{$args:['']})).toBe(false);
      expect(evaluate(call.if,{$args:['   ']})).toBe(false);
      expect(evaluate(call.if,{$args:[undefined]})).toBe(false);
      expect(evaluate(call.if,{$args:['홍']})).toBe(true);
      expect(call.params?.query?.keyword).toBe('{{$args[0]}}');
    }
  });
});
