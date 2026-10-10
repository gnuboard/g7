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

/**
 * @scenario flow=rate_lock
 * @effects admin_ui_contract
 */
test('desktop and mobile rate inputs stay locked and badges remain on one line',async({page})=>{
  for(const width of [1440,390]) {
    await page.setViewportSize({width,height:1000});
    await page.goto('/admin/ecommerce/settings?tab=language_currency');
    const rates=page.locator('input[name$=".exchange_rate"]');
    await expect(rates).toHaveCount(5);
    for(const rate of await rates.all())await expect(rate).toBeVisible();
    for(const rate of (await rates.all()).slice(0,4))await expect(rate).toBeDisabled();
    await expect(rates.last()).toBeEnabled();
    const badges=page.locator('span.whitespace-nowrap').filter({hasText:'자동 관리'});
    await expect(badges).toHaveCount(4);
    expect(await badges.evaluateAll(elements=>elements.every(e=>getComputedStyle(e).whiteSpace==='nowrap'&&e.getBoundingClientRect().height<=24))).toBe(true);
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
    await expect(page.locator('input[name$=".symbol"]').first()).toBeEnabled();
  }
});

/**
 * @scenario flow=rate_lock
 * @effects admin_ui_contract
 */
test('manual selection unlocks rates and provider reselection restores managed rates',async({page})=>{
  await page.goto('/admin/ecommerce/settings?tab=language_currency');
  for(const [label,managed] of [['직접 입력',false],['수출입은행 환율',true]] as const){
    await page.locator('#exchange_rate_provider').click();
    await page.getByRole('option',{name:label,exact:true}).click();
    const response=page.waitForResponse(r=>r.url().includes('/api/modules/sirsoft-ecommerce/admin/settings')&&['PUT','POST'].includes(r.request().method()));
    await page.getByRole('button',{name:'저장',exact:true}).click();
    expect((await response).status()).toBe(200);
    await page.reload();
    const rates=page.locator('input[name$=".exchange_rate"]');
    await expect(rates).toHaveCount(5);
    for(const rate of (await rates.all()).slice(0,4))expect(await rate.isDisabled()).toBe(managed);
    await expect(rates.last()).toBeEnabled();
    await expect(page.locator('#exchange_rate_provider_banner')).toHaveCount(managed?1:0);
  }
});
