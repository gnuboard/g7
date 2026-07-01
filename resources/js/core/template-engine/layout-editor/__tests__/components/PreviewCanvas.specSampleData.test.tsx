/**
 * PreviewCanvas → useSampleData spec 연결 회귀 테스트
 *
 * 회귀 원인: `PreviewCanvas` 가 `useSampleData({ editorSpec: undefined })` 로
 * 하드코딩되어, Phase 4 에서 도입한 번들 editor-spec 의
 * `sampleData.byDataSourceId`/`bySource` 가 캔버스 샘플 해소에 전혀 반영되지
 * 않았다(모든 data_source 가 generic 폴백 → 캔버스 공백). Chrome 이 이미 병합
 * 스펙을 `spec` prop 으로 주입하고 있었으므로 `editorSpec: spec ?? undefined` 로
 * 연결해 해소.
 *
 * 본 테스트는 두 계층을 가드한다:
 * (1) PreviewCanvas 소스가 `useSampleData` 에 `editorSpec={spec}` 를 전달한다
 *     (정적 가드 — `editorSpec: undefined` 로 회귀하면 실패).
 * (2) useSampleData 가 그 spec 의 `bySource`/`byDataSourceId` 샘플을 실제로
 *     해소한다(동작 가드) — 캔버스가 받는 sampleProvider 가 올바른 값을 반환.
 *
 * (2) 는 jsdom 캔버스 풀 렌더에 의존하지 않는 결정적 단위 검증으로, 엔진 회귀
 * 검사 원칙(코드 경로 우선)에 맞춰 sampleProvider 계약을 직접 잠근다.
 */

import { describe, it, expect } from 'vitest';
import { renderHook } from '@testing-library/react';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { useSampleData } from '../../hooks/useSampleData';
import type { EditorSpec } from '../../spec/specTypes';
import type { DataSource } from '../../../DataSourceManager';

describe('PreviewCanvas → useSampleData spec 연결 (engine-v1.50.0)', () => {
  it('(1) PreviewCanvas 가 useSampleData 에 editorSpec={spec} 를 전달한다 (정적 가드)', () => {
    const here = path.dirname(fileURLToPath(import.meta.url));
    const src = readFileSync(path.resolve(here, '../../components/PreviewCanvas.tsx'), 'utf8');
    // editorSpec: undefined 로 회귀하면 실패. spec 을 editorSpec 으로 연결해야 한다.
    // (S6-3: useSampleData 가 sampleOverride 인자를 추가로 받으며 멀티라인 호출로 바뀜 —
    //  객체 리터럴 내부에 `editorSpec: spec ?? undefined` 토큰이 존재하는지만 검사한다.)
    expect(src).toMatch(/useSampleData\(\s*\{[\s\S]*?editorSpec:\s*spec\s*\?\?\s*undefined[\s\S]*?\}\s*\)/);
    expect(src).not.toMatch(/useSampleData\(\s*\{[\s\S]*?editorSpec:\s*undefined\s*[,}]/);
  });

  it('(2) useSampleData 가 spec.bySource 샘플을 출처별로 해소한다 (동작 가드)', () => {
    const spec: EditorSpec = {
      version: '1.0.0',
      sampleData: {
        bySource: {
          template: { product: { data: { id: 1, name: '샘플 상품 이름', sales_status: 'on_sale' } } },
        },
        byDataSourceId: { product: { data: { id: 1, name: '샘플 상품 이름', sales_status: 'on_sale' } } },
      },
    } as unknown as EditorSpec;

    const { result } = renderHook(() => useSampleData({ isEditMode: true, editorSpec: spec }));
    const provider = result.current;
    expect(provider).toBeDefined();

    // route 출처(__source.kind=route) → bySource['template'] 로 해소.
    const source = {
      id: 'product',
      endpoint: '/api/modules/sirsoft-ecommerce/products/1',
      __source: { kind: 'route', identifier: null },
    } as unknown as DataSource;

    const resolved = provider!.resolve(source) as { data?: { name?: string; sales_status?: string } };
    expect(resolved?.data?.name).toBe('샘플 상품 이름');
    expect(resolved?.data?.sales_status).toBe('on_sale');
  });

  it('(3) editorSpec 미전달 시 spec 샘플 미해소 (회귀 대조)', () => {
    const { result } = renderHook(() => useSampleData({ isEditMode: true, editorSpec: undefined }));
    const provider = result.current;
    expect(provider).toBeDefined();
    const source = {
      id: 'product',
      endpoint: '/api/modules/sirsoft-ecommerce/products/1',
      __source: { kind: 'route', identifier: null },
    } as unknown as DataSource;
    const resolved = provider!.resolve(source) as { data?: { name?: string } };
    // generic/preset 폴백 → 우리가 정의한 샘플 상품 이름이 나오지 않아야 한다.
    expect(resolved?.data?.name).not.toBe('샘플 상품 이름');
  });
});
