/**
 * 설치 화면 확장 선택 해석기(JS) — 서버 해석기(PHP)와 같은 픽스처로 같은 결과를 내는지 잠근다.
 *
 * 같은 픽스처를 PHPUnit `ExtensionSelectionResolverTest` 가 PHP 해석기로 돌린다.
 * 두 테스트가 모두 초록이면 화면과 서버(확장 저장·사전 검증·터미널 설치)의 판정이 같다.
 */
import { describe, expect, it } from 'vitest';
import fs from 'fs';
import path from 'path';

const root = path.resolve(__dirname, '../../../..');
// 설치 화면은 이 파일을 일반 <script> 로 싣는다 — 브라우저와 같은 방식(전역 window 에 등록)으로 평가한다.
const scope: { G7ExtensionResolver?: any } = {};
new Function('window', fs.readFileSync(path.join(root, 'public/install/assets/js/installer-extension-resolver.js'), 'utf8'))(scope);
const resolver = scope.G7ExtensionResolver;
const fixture = JSON.parse(fs.readFileSync(path.join(root, 'tests/fixtures/installer/extension-resolver-cases.json'), 'utf8'));

describe('installer-extension-resolver (JS ≡ PHP 공용 픽스처)', () => {
    for (const testCase of fixture.cases) {
        /**
         * @effects resolver_js_matches_shared_fixture
         */
        it(`해석 결과가 픽스처와 같다 — ${testCase.name}`, () => {
            const result = resolver.resolveExtensionSelection(fixture.scan, testCase.requested);

            expect(result.selection).toEqual(testCase.expected.selection);
            expect(result.auto_added).toEqual(testCase.expected.auto_added);
            expect(result.excluded_language_packs).toEqual(testCase.expected.excluded_language_packs);
            expect(result.errors.map((e: { key: string }) => e.key)).toEqual(testCase.expected.error_keys);
            expect(result.install_order).toEqual(testCase.expected.install_order);
            expect(result.pulled_by).toEqual(testCase.expected.pulled_by);
        });
    }

    /**
     * @effects resolver_js_version_constraint_matches_shared_fixture
     */
    it('버전 제약 판정이 픽스처 표와 같다', () => {
        for (const [available, constraint, expected] of fixture.version_cases) {
            expect(resolver.satisfiesVersionConstraint(available, constraint), `${available} vs ${constraint}`).toBe(expected);
        }
    });
});
