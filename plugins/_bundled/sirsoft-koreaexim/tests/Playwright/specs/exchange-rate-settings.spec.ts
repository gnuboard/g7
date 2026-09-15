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
  await expect(page.getByRole('button',{name:'템플릿 편집',exact:true})).toBeVisible();
  await page.getByRole('button',{name:'템플릿 편집',exact:true}).click();
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
  expect(response.status()).toBe(200);
  const run=(await response.json())?.data;
  console.log(`connection_test_status=${run?.status}, applied=${run?.applied_count}, requests=${run?.request_count}`);
  expect(['auth_error','network_error','bad_response','bad_request']).toContain(run?.status);
  expect(run?.applied_count ?? 0).toBe(0);
  const after=await page.evaluate(async()=>(await (await fetch('/api/plugins/sirsoft-koreaexim/admin/status',{headers:{Accept:'application/json'}})).json())?.data?.last_success_run?.id ?? null);
  expect(after).toBe(before);
});
