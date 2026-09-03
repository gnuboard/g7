/**
 * 유저 상품상세 추가옵션 흐름 공용 헬퍼 — 대상 상품 조회 + 커스텀 드롭다운 조작.
 *
 * `additional-options.spec.ts`(선택 payload 축)와
 * `additional-option-currency-conversion.spec.ts`(표시통화 환산 축)가 같은 화면의 같은
 * 조작을 한다. 사본을 각 spec 에 두면 한쪽만 고쳐졌을 때 그 차집합이 사각이 되므로
 * 여기 한 곳에서 소유한다.
 *
 * 이 템플릿(sirsoft-basic)의 Select 는 `options` 가 있으면 네이티브 `<select>` 가 아니라
 * `button[role=option]` 커스텀 드롭다운을 렌더한다 — `selectOption()` 은 동작하지 않는다.
 */
import type { Page } from '@playwright/test';

/** 공개 상품 목록 API */
export const PRODUCT_LIST_API = '/api/modules/sirsoft-ecommerce/products?per_page=40';

export interface AddOptProduct {
  /** 상품 상세 경로 */
  url: string;
  /** 메인 옵션 그룹별로 고를 값 라벨 (그룹을 **전부** 골라야 블럭이 생긴다) */
  mainValues: string[];
  /** 추가옵션 그룹 id */
  groupId: number;
  /** 그 그룹에서 고를 선택지 이름 */
  valueName: string;
  /** 그 선택지의 추가금 (기본통화 기준, 0 이면 총액 증가 단언은 건너뛴다) */
  priceAdjustment: number;
}

/**
 * 공개 목록에서 "메인 옵션 2개 이상 + 추가옵션 그룹 보유" 상품을 찾는다.
 *
 * @param page Playwright 페이지
 * @return 찾은 상품 (없으면 null)
 */
export async function findAdditionalOptionProduct(page: Page): Promise<AddOptProduct | null> {
  const codes: string[] = await page.evaluate(async (api) => {
    const res = await fetch(api, { headers: { Accept: 'application/json' } });
    if (!res.ok) return [];
    const json = await res.json();
    const rows = json?.data?.data ?? json?.data ?? [];
    return rows.map((p: any) => p.product_code).filter(Boolean);
  }, PRODUCT_LIST_API);

  for (const code of codes.slice(0, 20)) {
    const detail = await page.evaluate(async (c) => {
      const res = await fetch(`/api/modules/sirsoft-ecommerce/products/${c}`, {
        headers: { Accept: 'application/json' },
      });
      if (!res.ok) return null;
      const json = await res.json();
      const d = json?.data ?? json;
      return {
        optionCount: (d?.options ?? []).length,
        // 옵션 그룹을 전부 골라야 선택 블럭이 만들어지고 그 안에 추가옵션이 렌더된다
        mainValues: (d?.option_groups ?? []).map((g: any) => g?.values_localized?.[0] ?? null),
        additional: d?.additional_options ?? [],
      };
    }, code);

    if (!detail || detail.optionCount <= 1) continue;
    if (!detail.mainValues.length || detail.mainValues.some((v: any) => !v)) continue;

    for (const group of detail.additional as any[]) {
      const active = (group.values ?? []).filter((v: any) => v.is_active !== false);
      // 총액 변화를 볼 수 있도록 추가금이 양수인 선택지를 우선한다
      const value = active.find((v: any) => Number(v.price_adjustment ?? 0) > 0) ?? active[0];
      if (!value) continue;
      return {
        url: `/shop/products/${code}`,
        mainValues: detail.mainValues.map(String),
        groupId: Number(group.id),
        valueName: String(value.name),
        priceAdjustment: Number(value.price_adjustment ?? 0),
      };
    }
  }
  return null;
}

/**
 * 커스텀 드롭다운 Select 에서 라벨(부분 일치)로 항목을 고른다.
 *
 * @param page Playwright 페이지
 * @param testId Select 래퍼의 data-testid
 * @param label 고를 항목 라벨 (부분 일치)
 * @return 없음
 */
export async function pickOption(page: Page, testId: string, label: string | RegExp): Promise<void> {
  await page.getByTestId(testId).getByRole('button').first().click();
  await page.getByRole('option', { name: label }).first().click();
}

/**
 * 정규식 메타문자를 이스케이프한다.
 *
 * 추가옵션 이름에는 괄호가 흔하고(`연장 보증 (2년)`), 화면 라벨은 그 뒤에 추가금이 붙으므로
 * **부분 일치**가 필요하다 — 이스케이프 없이 RegExp 로 감싸면 괄호가 캡처 그룹으로 해석돼
 * 아무것도 매칭되지 않는다.
 *
 * @param text 원문
 * @return 이스케이프된 문자열
 */
export function escapeRegExp(text: string): string {
  return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * 메인 옵션 그룹을 순서대로 전부 고른다.
 *
 * 하위 그룹 Select 는 상위 그룹이 선택될 때까지 disabled 이고, **모든** 그룹이 선택돼야
 * 선택 블럭(과 그 안의 추가옵션)이 만들어진다.
 *
 * @param page Playwright 페이지
 * @param values 그룹 순서대로의 값 라벨
 * @return 없음
 */
export async function pickAllMainOptions(page: Page, values: string[]): Promise<void> {
  for (let i = 0; i < values.length; i++) {
    await pickOption(page, `option-group-${i}`, values[i]);
  }
}

/**
 * 헤더 통화 셀렉터에서 **보이는** 요소를 고른다.
 *
 * 이 셀렉터는 데스크톱 헤더와 모바일 드로어 두 곳에 렌더되므로 같은 `data-testid` 가
 * 항상 2개 이상 잡힌다. `.first()` 는 그중 숨은 쪽을 집을 수 있고, 그러면 클릭이
 * 타임아웃되거나 목록이 비어 보인다 — 화면 폭에 따라 어느 쪽이 먼저인지도 달라진다.
 *
 * 인덱스로 고른 요소(`nth(i)`)를 돌려주면 안 된다 — 헤더는 데이터소스가 정착하며 다시
 * 그려지고, 그때 순서가 바뀌면 같은 인덱스가 숨은 쪽을 가리킨다(클릭이 "not visible" 로
 * 50회 재시도 후 실패). `:visible` 필터를 담은 locator 를 돌려주면 **동작 시점에** 다시
 * 해석되므로 그 경합이 성립하지 않는다.
 *
 * @param page Playwright 페이지
 * @param testId 대상 testid
 * @param timeoutMs 최대 대기 (기본 15초)
 * @return 보이는 요소 locator (마감까지 없으면 null)
 */
async function visibleByTestId(page: Page, testId: string, timeoutMs = 15_000) {
  const locator = page.locator(`[data-testid="${testId}"]:visible`).first();
  try {
    await locator.waitFor({ state: 'visible', timeout: timeoutMs });
  } catch {
    return null;
  }
  return locator;
}

/**
 * 헤더 통화 셀렉터로 **현재와 다른** 표시통화로 전환한다.
 *
 * 셀렉터는 이커머스 모듈이 `_user_base` 에 주입하는 레이아웃 확장이 소유한다(템플릿이 아니다).
 * 전환 대상은 현재 트리거가 보여 주는 코드가 아닌 것 중 하나를 고른다 — 어느 통화가
 * 기본인지 spec 이 알 필요가 없다.
 *
 * @param page Playwright 페이지
 * @param exclude 이 코드들은 고르지 않는다 (기본값: 현재 표시통화만 제외)
 * @return 전환한 통화 코드 (셀렉터가 없거나 고를 통화가 없으면 null)
 */
export async function switchToOtherCurrency(page: Page, exclude: string[] = []): Promise<string | null> {
  const trigger = await visibleByTestId(page, 'currency-switcher');
  if (trigger === null) return null;
  const current = (await trigger.innerText()).replace(/\s+/g, ' ').trim();

  await trigger.click();
  const options = page.locator('[data-testid^="currency-option-"]');
  await options.first().waitFor({ state: 'attached', timeout: 10_000 }).catch(() => {});
  const codes: string[] = [];
  for (let i = 0; i < (await options.count()); i++) {
    const id = await options.nth(i).getAttribute('data-testid');
    const code = id ? id.replace('currency-option-', '') : null;
    if (code && !codes.includes(code)) codes.push(code);
  }

  const target = codes.find((c) => !current.includes(c) && !exclude.includes(c));
  if (!target) {
    await page.keyboard.press('Escape');
    return null;
  }
  const option = await visibleByTestId(page, `currency-option-${target}`);
  if (option === null) return null;
  await option.click();
  return target;
}
