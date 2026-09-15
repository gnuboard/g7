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
import fs from 'node:fs';
import path from 'node:path';
import settingsLayout from '../../../layouts/admin/plugin_settings.json';
import tabSettings from '../../../layouts/admin/partials/plugin_settings/_tab_settings.json';
import tabHistory from '../../../layouts/admin/partials/plugin_settings/_tab_history.json';
import ko from '../../../lang/ko.json';
import en from '../../../lang/en.json';
import {Toggle} from '../../../../../../../templates/_bundled/sirsoft-admin_basic/src/components/composite/Toggle';

const pluginRoot=process.cwd();
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
    const toggle=nodes(notifications).find(n=>n.name==='Toggle');
    utils=createLayoutTest({version:'1.0.0',layout_name:'notification_accessible_name',components:[toggle]} as any,{
      translations:{'sirsoft-koreaexim':ko},locale:'ko',
      initialData:{$templateId:'test-template',$locale:'ko',def:{name:{ko:'환율 갱신 실패'}},template:{channel:'mail',is_active:true},_computed:{isNotificationReadOnly:false}},
    });
    const {container}=await utils.render();
    expect(container.querySelector('[role=switch]')?.getAttribute('aria-label')).toBe('환율 갱신 실패 / 메일');
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
  /** @scenario flow=notifications
   * @effects admin_ui_contract */
  it('requests the selected notification channel as a scalar', () => {
    const selector=nodes(notifications).find(n=>n.name==='Select');
    expect(JSON.stringify(selector.actions)).toContain('$event.target.value');
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
