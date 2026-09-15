import {test,expect} from '@playwright/test';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import {authenticatePage,issueToken,issueScopedToken} from '../../../../../../tests/Playwright/fixtures/auth';

const path='/admin/plugins/sirsoft-koreaexim/settings';
let admin:string;
const fixture=fileURLToPath(new URL('../fixtures/environment.php',import.meta.url));
function environment(action:string){return execFileSync('php',[fixture,action],{env:{...process.env,G7_PLAYWRIGHT_BYPASS:'1'},encoding:'utf8'});}
test.beforeAll(()=>{environment('restore');environment('prepare');admin=issueToken();});
test.afterAll(()=>{console.log(environment('restore'));});
test.beforeEach(async({page})=>{await authenticatePage(page,admin);await page.addInitScript(()=>localStorage.setItem('g7_locale','ko'));});

/**
 * @scenario flow=tabs
 * @effects admin_ui_contract
 */
test('settings tabs, masked key, channel editor and history survive navigation',async({page})=>{
  await page.goto(path);
  await expect(page.getByRole('heading',{name:'수출입은행 환율',exact:true})).toBeVisible();
  await expect(page.locator('#koreaexim_auth_key')).toHaveValue('••••••••');
  await expect(page.getByRole('checkbox',{name:'월',exact:true})).toBeVisible();
  // 켠 수단의 문구가 한 목록에 함께 보이므로 「템플릿 편집」은 채널 수만큼 있다 — 첫 줄을 연다.
  const templateRows=page.locator('[id^=koreaexim_template_row]');
  await expect(templateRows).toHaveCount(2);
  await templateRows.first().getByRole('button',{name:'템플릿 편집',exact:true}).click();
  await expect(page.getByRole('dialog',{name:'알림 템플릿 편집',exact:true})).toBeVisible();
  await expect(page.getByRole('textbox',{name:'제목',exact:true})).toBeVisible();
  await expect(page.getByRole('textbox',{name:'본문',exact:true})).toBeVisible();
  await page.getByRole('dialog',{name:'알림 템플릿 편집',exact:true}).getByRole('button',{name:'모달 닫기',exact:true}).click();
  await page.getByRole('tab',{name:'갱신 이력',exact:true}).click();
  await expect(page).toHaveURL(/tab=history/);
  await page.reload();
  await expect(page.getByRole('tab',{name:'갱신 이력',exact:true})).toHaveAttribute('aria-selected','true');
  await expect(page.getByText('조회 기준일',{exact:true}).last()).toBeVisible();
  await page.getByRole('button',{name:'플러그인 목록으로',exact:true}).click();
  await expect(page).toHaveURL(/\/admin\/plugins$/);
});

/**
 * @scenario flow=validation
 * @effects admin_ui_contract
 */
test('invalid update time returns 422 and valid correction saves through the UI',async({page})=>{
  // Validate with the real endpoint; save a valid original value to leave settings unchanged.
  await page.goto(path);
  const field=page.locator('#koreaexim_update_time');
  await expect(field).toHaveValue(/^\d{2}:\d{2}$/);
  const original=await field.inputValue();
  await field.fill('25:99');
  const invalid=page.waitForResponse(r=>r.url().includes('/api/admin/plugins/sirsoft-koreaexim/settings')&&r.request().method()==='PUT');
  await page.getByRole('button',{name:'저장',exact:true}).click();
  expect((await invalid).status()).toBe(422);
  await expect(page.locator('.form-error').filter({hasText:/./}).first()).toBeVisible();
  await field.fill(original);
  const valid=page.waitForResponse(r=>r.url().includes('/api/admin/plugins/sirsoft-koreaexim/settings')&&r.request().method()==='PUT');
  await page.getByRole('button',{name:'저장',exact:true}).click();
  expect((await valid).status()).toBe(200);
  await expect(page.getByRole('button',{name:'저장',exact:true})).toBeDisabled();
  await page.reload();
  await expect(field).toHaveValue(original);
});

/**
 * @scenario flow=filter
 * @effects admin_ui_contract
 */
test('history status filter sends a string and resetting it restores rows',async({page})=>{
  await page.goto(path+'?tab=history&page=3');
  const selector=page.getByRole('button',{name:'전체',exact:true}).first();
  await selector.click();
  await page.getByRole('option',{name:'성공',exact:true}).click();
  await expect(page).toHaveURL(/status=success/);
  await expect(page).toHaveURL(/page=1/);
  await page.reload();
  await expect(page.getByRole('button',{name:'성공',exact:true})).toBeVisible();
});

/**
 * @scenario flow=expand
 * @effects admin_ui_contract
 */
test('expanding a successful run renders its currency snapshots',async({page})=>{
  await page.goto(path+'?tab=history&status=success');
  await page.getByRole('button',{name:'Expand row',exact:true}).first().click();
  await expect(page.locator('#koreaexim_runs').getByText('USD',{exact:true})).toBeVisible();
  await expect(page.locator('#koreaexim_runs').getByText('KRW',{exact:true})).toBeVisible();
  await expect(page.locator('#koreaexim_runs').getByText('기본통화',{exact:true})).toBeVisible();
});

/**
 * @scenario flow=validation
 * @effects duplicate_run_submission_prevented
 */
test('three rapid pointer clicks send only one update request',async({page})=>{
  let requests=0;
  let release!:()=>void;
  const pending=new Promise<void>(resolve=>{release=resolve;});
  await page.route('**/api/plugins/sirsoft-koreaexim/admin/runs',async route=>{
    if(route.request().method()!=='POST'){await route.continue();return;}
    requests++;
    await pending;
    await route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({success:true,data:{id:0,status:'skipped',message:'검수 응답'}})});
  });
  await page.goto(path);
  const button=page.locator('#update_now');
  await expect(page.locator('#koreaexim_auth_key')).toHaveValue('••••••••');
  await page.waitForLoadState('networkidle');
  await expect(button).toBeEnabled();
  const rect=await button.boundingBox();
  expect(rect).not.toBeNull();
  const started=Date.now();
  await page.mouse.click(rect!.x+rect!.width/2,rect!.y+rect!.height/2,{clickCount:3});
  const elapsed=Date.now()-started;
  console.log(`triple_click_ms=${elapsed}, update_requests=${requests}, disabled=${await button.isDisabled()}`);
  expect(elapsed).toBeLessThan(2000);
  try {
    await expect.poll(()=>requests).toBe(1);
    console.log(`pending_requests=${requests}, disabled=${await button.isDisabled()}`);
    await expect(button).toBeDisabled();
  } finally {release();}
  await expect(button).toBeEnabled();
  expect(requests).toBe(1);
  console.log(`triple_click_ms=${elapsed}, update_requests=${requests}`);
});

/**
 * 연결 테스트를 누르면 「지금 갱신」에도 스피너가 돌던 회귀(제보 2026-09-15).
 * 두 버튼은 나란히 놓여 항상 둘 다 보이므로, 진행 표시는 누른 쪽에만 나타나야 한다.
 * 함께 비활성되는 것은 의도다 — 서버가 두 경로에 같은 실행 락을 건다.
 *
 * @scenario flow=buttons
 * @effects admin_ui_contract
 */
test('connection test spins only its own button',async({page})=>{
  let release!:()=>void;
  const pending=new Promise<void>(resolve=>{release=resolve;});
  await page.route('**/api/plugins/sirsoft-koreaexim/admin/runs',async route=>{
    if(route.request().method()!=='POST'){await route.continue();return;}
    await pending;
    await route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({success:true,data:{id:0,status:'dry_run',message:'검수 응답'}})});
  });
  await page.goto(path);
  await page.waitForLoadState('networkidle');
  const test_=page.locator('#test_connection');
  const update=page.locator('#update_now');
  const spinner=(scope:typeof test_)=>scope.locator('.spinner-sm');
  await expect(test_).toBeEnabled();
  await expect(spinner(test_)).toHaveCount(0);
  await expect(spinner(update)).toHaveCount(0);

  await test_.click();
  try {
    await expect(spinner(test_)).toHaveCount(1);
    // 핵심 단언 — 누르지 않은 버튼에는 진행 표시가 없다.
    await expect(spinner(update)).toHaveCount(0);
    // 잠금은 공유한다(서버 락과 일치).
    await expect(update).toBeDisabled();
    console.log(`test_spinner=${await spinner(test_).count()}, update_spinner=${await spinner(update).count()}, update_disabled=${await update.isDisabled()}`);
  } finally {release();}
  await expect(spinner(test_)).toHaveCount(0);
});

/**
 * 알림 발송 수단은 코어가 런타임에 정하는 채널 목록을 그대로 따라가고 **복수로** 지정된다
 * (결정 2026-09-15). 화면에 채널을 적어 두면 확장이 들인 채널에 도달할 수 없고,
 * 단일 선택이면 여러 수단으로 동시에 보낼 수 없다.
 *
 * @scenario flow=channels
 * @effects admin_ui_contract
 */
test('delivery channels follow the core registry and save as a set',async({page})=>{
  // 코어 채널 목록에 확장이 채널을 들인 상태를 주입한다 — 실제 확장 설치 없이 계약만 잰다.
  await page.route('**/api/admin/notification-channels',async route=>{
    try {
      const response=await route.fetch();
      const body=await response.json();
      const channels=body?.data?.channels ?? [];
      channels.push({id:'sms',name:'SMS',icon:'fas fa-comment',source:'plugin:sirsoft-message_bizppurio',source_label:'문자 플러그인',allow_guest:false});
      await route.fulfill({response,json:{...body,data:{...body.data,channels}}});
    } catch {/* 이동 중 컨텍스트가 닫히면 그대로 둔다 */}
  });
  // 정의의 현재 채널은 DB 상태다 — 시험이 출발점을 고정한다(브라우저에서 사람이 바꿔도 흔들리지 않게).
  // 저장 뒤의 재조회는 붙잡아 둔다 — 목록이 갱신되기 **전** 구간에서 화면이 흔들리는지 재기 위함이다.
  let saved:any=null;
  let listCalls=0;
  let releaseRefetch!:()=>void;
  const refetchPending=new Promise<void>(resolve=>{releaseRefetch=resolve;});
  await page.route('**/api/admin/notification-definitions?**',async route=>{
    try {
      listCalls+=1;
      if(listCalls>1) await refetchPending;
      const response=await route.fetch();
      const body=await response.json();
      // 재조회는 방금 저장한 값을 돌려준다 — 서버가 받아들인 것이 SSoT 다.
      const rows=(body?.data?.data ?? []).map((d:any)=>({...d,channels:listCalls>1?(saved?.channels ?? ['database']):['mail','database']}));
      await route.fulfill({response,json:{...body,data:{...body.data,data:rows}}});
    } catch {/* 이동 중 컨텍스트가 닫히면 그대로 둔다 */}
  });
  let releaseSave!:()=>void;
  const savePending=new Promise<void>(resolve=>{releaseSave=resolve;});
  await page.route('**/api/admin/notification-definitions/*',async route=>{
    if(route.request().method()!=='PUT'){await route.continue();return;}
    saved=route.request().postDataJSON();
    await savePending;   // 저장 중 화면 상태를 잴 수 있게 응답을 붙잡는다
    await route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({success:true,message:'저장했습니다',data:{}})});
  });
  await page.goto(path);
  await page.waitForLoadState('networkidle');

  const rows=page.locator('[id^=koreaexim_channel_row]');
  const labels=(await rows.locator('.text-heading').allTextContents()).map(t=>t.trim());
  console.log(`channel_rows=${labels.join('|')}`);
  // 코어 채널과 확장이 들인 채널이 모두 줄로 깔린다 — 드롭다운 단일 선택이 아니다.
  expect(labels).toEqual(['메일','사이트내 알림','SMS']);
  await expect(rows.filter({hasText:'SMS'})).toContainText('문자 플러그인');

  const save=page.locator('[id^=koreaexim_save_channels]');
  await expect(save).toBeDisabled();           // 고친 것이 없으면 저장할 것도 없다
  const mailToggle=rows.filter({hasText:'메일'}).getByRole('switch');
  const smsToggle=rows.filter({hasText:'SMS'}).getByRole('switch');
  await smsToggle.click();
  await mailToggle.click();                                   // 메일을 끈다
  await expect(mailToggle).toHaveAttribute('aria-checked','false');
  await expect(save).toBeEnabled();
  const cancel=page.locator('[id^=koreaexim_cancel_channels]');
  await expect(cancel).toHaveCount(1);          // 고친 것이 있으면 되돌릴 수 있어야 한다
  await save.click();

  // 저장 중 — 누른 버튼에 진행 표시가 뜨고, 그동안 다시 누르거나 되돌릴 수 없다.
  await expect(save.locator('.spinner-sm')).toHaveCount(1);
  await expect(save).toBeDisabled();
  await expect(cancel).toBeDisabled();
  console.log(`saving spinner=${await save.locator('.spinner-sm').count()}, cancel_disabled=${await cancel.isDisabled()}`);
  releaseSave();

  // 저장 응답이 온 뒤 목록 재조회가 끝나기 전 구간 — 껐던 스위치가 되살아나면 안 된다(제보 2026-09-15).
  // 끈 것은 꺼진 채로, 켠 것은 켜진 채로 — 양방향 모두 되살아나면 안 된다.
  await expect.poll(()=>saved?.channels).not.toBe(undefined);
  await expect(mailToggle).toHaveAttribute('aria-checked','false');
  await expect(smsToggle).toHaveAttribute('aria-checked','true');
  console.log(`between_save_and_refetch mail=${await mailToggle.getAttribute('aria-checked')}, sms=${await smsToggle.getAttribute('aria-checked')}`);
  releaseRefetch();
  await expect(mailToggle).toHaveAttribute('aria-checked','false');

  await expect.poll(()=>saved?.channels).toContain('sms');
  console.log(`saved_channels=${(saved?.channels ?? []).join('|')}`);
  // 기존 수단을 지우지 않고 **더한다** — 복수 지정이다.
  expect(saved.channels).toEqual(expect.arrayContaining(['database','sms']));
  expect(saved.channels).not.toContain('mail');   // 끈 수단은 빠진다

  // 저장이 끝나면 화면은 "고칠 것이 없는" 상태로 돌아온다(제보 2026-09-15).
  await expect(save.locator('.spinner-sm')).toHaveCount(0);
  await expect(save).toBeDisabled();
  await expect(cancel).toHaveCount(0);
  console.log(`after_save disabled=${await save.isDisabled()}, cancel=${await cancel.count()}`);
});

/**
 * @scenario flow=read_only
 * @effects admin_ui_contract
 */
test('read-only user can view history but cannot run or save settings',async({page,request})=>{
  const token=issueScopedToken('core.plugins.read','core.settings.read','core.menus.read','core.notifications.read','sirsoft-koreaexim.exchange_rate.view');
  await authenticatePage(page,token);
  await page.goto(path);
  await expect(page.getByRole('button',{name:'연결 테스트',exact:true})).toBeDisabled();
  await expect(page.getByRole('button',{name:'지금 갱신',exact:true})).toBeDisabled();
  await expect(page.getByRole('button',{name:'저장',exact:true})).toBeDisabled();
  await page.getByRole('tab',{name:'갱신 이력',exact:true}).click();
  await expect(page.locator('#koreaexim_runs').getByRole('button',{name:'Expand row',exact:true})).toHaveCount(20);
  const response=await request.post('/api/plugins/sirsoft-koreaexim/admin/runs',{headers:{Authorization:'Bearer '+token},data:{dry_run:true}});
  expect(response.status()).toBe(403);
});

/**
 * @scenario flow=connection_test
 * @effects connection_test_reports_failure_without_applying
 */
test('connection test sends dry_run and reports the failure without applying rates',async({page})=>{
  // 픽스처 키는 유효하지 않으므로 실제 고시 응답은 인증 오류 계열로 돌아온다(계획 §7.3 · §8 M1 데이터 준비).
  environment('cooldown');
  await page.goto(path);
  await page.waitForLoadState('networkidle');
  const before=await page.evaluate(async()=>(await (await fetch('/api/plugins/sirsoft-koreaexim/admin/status',{headers:{Accept:'application/json'}})).json())?.data?.last_success_run?.id ?? null);
  const button=page.getByRole('button',{name:'연결 테스트',exact:true});
  await expect(button).toBeEnabled();
  const [request,response]=await Promise.all([
    page.waitForRequest(r=>r.method()==='POST' && r.url().includes('/admin/runs')),
    page.waitForResponse(r=>r.request().method()==='POST' && r.url().includes('/admin/runs'),{timeout:40000}),
    button.click(),
  ]);
  expect(JSON.parse(request.postData() ?? '{}').dry_run).toBe(true);
  // 연결 테스트는 하루 예산(5회)을 쓴다. 같은 날 앞선 측정이 예산을 다 쓰면 서버는 429 로
  // 거절하는데, 그것은 설계대로 동작한 것이지 이 흐름의 회귀가 아니다 — 그런데도 200 만
  // 단언하면 **시험이 실행 횟수에 따라 색이 바뀐다**(하루 6번째 실행은 코드가 옳아도 red).
  // 두 갈래 모두에서 이 시험의 요지 「환율은 적용되지 않는다」를 같은 강도로 고정한다.
  const budgetSpent=response.status()===429;
  if(budgetSpent){
    console.log('connection_test_status=budget_exhausted (429) — 하루 예산 소진, 설계대로 거절');
  }else{
    expect(response.status()).toBe(200);
    const run=(await response.json())?.data;
    console.log(`connection_test_status=${run?.status}, applied=${run?.applied_count}, requests=${run?.request_count}`);
    expect(['auth_error','network_error','bad_response','bad_request']).toContain(run?.status);
    expect(run?.applied_count ?? 0).toBe(0);
  }
  const after=await page.evaluate(async()=>(await (await fetch('/api/plugins/sirsoft-koreaexim/admin/status',{headers:{Accept:'application/json'}})).json())?.data?.last_success_run?.id ?? null);
  expect(after).toBe(before);
});
