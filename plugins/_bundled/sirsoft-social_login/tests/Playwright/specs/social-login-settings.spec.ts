import {test,expect} from '@playwright/test';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import {authenticatePage,issueToken} from '../../../../../../tests/Playwright/fixtures/auth';

let admin:string;
const fixture=fileURLToPath(new URL('../fixtures/environment.php',import.meta.url));
function environment(action:string){return execFileSync('php',[fixture,action],{env:{...process.env,G7_PLAYWRIGHT_BYPASS:'1'},encoding:'utf8'});}
test.beforeAll(()=>{environment('restore');environment('prepare');admin=issueToken();});
test.afterAll(()=>{console.log(environment('restore'));});
test.beforeEach(async({page})=>{await authenticatePage(page,admin);await page.addInitScript(()=>localStorage.setItem('g7_locale','ko'));});

// 정책 칸은 설정 화면의 「정책」 탭에 있다(제공자 탭이 기본 탭이다).
const SETTINGS='/admin/plugins/sirsoft-social_login/settings?tab=policy';
const FIELDS=['email_conflict_policy','extra_info_mode','webhook_unlink_policy','two_factor_enabled','allow_admin_social_login','allow_unlink_last_method','revoke_on_unlink',
  'state_ttl_minutes','pending_signup_ttl_minutes','custom_provider_limit','profile_raw_retention_days','attempt_log_retention_days','webhook_event_retention_days',
  'signup_surge_threshold_per_hour','provider_failure_streak_threshold'];

/**
 * 설정 화면이 데스크톱과 휴대폰 폭에서 15칸을 모두 보여 주고 가로 넘침이 없는지 확인한다.
 *
 * @scenario surface=settings, viewport=desktop|mobile
 * @effects settings_page_renders_all_fields
 */
test('settings page renders every policy field at desktop and mobile widths',async({page})=>{
  for(const width of [1440,390]){
    await page.setViewportSize({width,height:1000});
    await page.goto(SETTINGS);
    for(const name of FIELDS)await expect(page.locator(`[name="${name}"]`)).toHaveCount(1);
    await expect(page.locator('[id^="plugin_read_only_banner"]')).toHaveCount(0);
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
  }
});

/**
 * 범위 밖 값을 저장하면 422 와 칸 아래 오류가 보이고 값이 바뀌지 않는지 확인한다.
 *
 * @scenario surface=settings, input=out_of_range
 * @effects settings_out_of_range_rejected
 */
test('out of range value is rejected with an inline error',async({page})=>{
  await page.goto(SETTINGS);
  const ttl=page.locator('input[name="state_ttl_minutes"]');
  await ttl.fill('99');
  const response=page.waitForResponse(r=>r.url().includes('/api/admin/plugins/sirsoft-social_login/settings')&&r.request().method()==='PUT');
  await page.getByRole('button',{name:'저장',exact:true}).first().click();
  expect((await response).status()).toBe(422);
  await expect(page.locator('[id^="error_state_ttl_minutes"]').first()).toBeVisible();
  await page.reload();
  await expect(page.locator('input[name="state_ttl_minutes"]')).toHaveValue('10');
});

/**
 * 올바른 값을 저장하면 200 이고 새로고침 뒤에도 남는지 확인한다.
 *
 * @scenario surface=settings, input=valid
 * @effects settings_saved_and_persisted
 */
test('valid values are saved and persist after reload',async({page})=>{
  await page.goto(SETTINGS);
  await page.locator('input[name="state_ttl_minutes"]').fill('12');
  await page.locator('select[name="email_conflict_policy"]').selectOption('never_link');
  const response=page.waitForResponse(r=>r.url().includes('/api/admin/plugins/sirsoft-social_login/settings')&&r.request().method()==='PUT');
  await page.getByRole('button',{name:'저장',exact:true}).first().click();
  expect((await response).status()).toBe(200);
  await page.reload();
  await expect(page.locator('input[name="state_ttl_minutes"]')).toHaveValue('12');
  await expect(page.locator('select[name="email_conflict_policy"]')).toHaveValue('never_link');
});
