<?php

namespace Tests\Unit\I18n;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * 다국어 값 대체 순서 강제 검사 (#148 D7·D9).
 *
 * 다국어 값(`['ko' => …, 'en' => …]`)에 지금 보는 언어의 값이 없을 때 자리마다
 * `app.fallback_locale`(env 고정, 번역 문구 대체용)이나 `'ko'` 를 직접 색인해, 한국어가 기본이
 * 아닌 사이트에서도 빈 값이 한국어로 채워졌다. 값 표시는 백엔드 `App\Support\LocalizedValue::pick()`,
 * 화면은 `G7Core.locale.pick()`·`$localized()` 로 모은다(요청 언어 → 사이트 기본 언어 → 영어).
 *
 * 새 코드가 다시 직접 색인하면 이 테스트가 막는다. 값 표시가 아닌 자리(번역 키 대체·필수 언어 검증·
 * 로케일별 생성·동등 비교)는 허용 목록에 파일별 건수와 사유로 고정한다 — 건수가 바뀌면 사유를 다시 본다.
 */
#[Group('core')]
class MultilingualValueFallbackGuardTest extends TestCase
{
    /**
     * `config('app.fallback_locale')` 를 써도 되는 파일 → 허용 건수·사유.
     *
     * @var array<string, array{0: int, 1: string}>
     */
    private const BACKEND_FALLBACK_LOCALE_ALLOWED = [
        'app/Helpers/locale_helpers.php' => [1, 'K: 번역 키(__()) 대체 언어 — 값 표시가 아님'],
        'app/Enums/Analytics/ArchiveSkipReason.php' => [1, 'K: 번역 문구 사유 라벨의 대체 언어'],
        'app/Services/LanguagePack/LanguagePackSeedInjector.php' => [1, 'K: 언어팩 시드 번역의 대체 언어'],
        'app/Listeners/LanguagePack/MergeCustomTranslations.php' => [1, 'K: 사용자 정의 번역 병합의 대체 언어'],
        'modules/_bundled/sirsoft-board/src/Http/Controllers/Admin/BoardController.php' => [1, 'D: 새 게시판 기본값 생성 로케일'],
        'modules/_bundled/sirsoft-ecommerce/src/Http/Requests/Admin/StoreShippingPolicyRequest.php' => [1, 'R: 필수 언어 검증'],
    ];

    /**
     * `['ko'] ??` 류 직접 색인을 써도 되는 파일 → 허용 건수·사유.
     *
     * @var array<string, array{0: int, 1: string}>
     */
    private const BACKEND_KO_INDEX_ALLOWED = [
        'modules/_bundled/sirsoft-board/src/Services/BoardService.php' => [4, 'G: 로케일별(ko/en) 메뉴 이름 생성'],
        'modules/_bundled/sirsoft-board/src/Services/BoardPermissionService.php' => [4, 'G: 로케일별(ko/en) 권한 이름 생성'],
        'modules/_bundled/sirsoft-ecommerce/src/Repositories/ProductReviewRepository.php' => [2, 'K: 옵션 키 동등 비교용 ko 정본'],
        'modules/_bundled/sirsoft-ecommerce/src/Services/ProductService.php' => [2, 'K: 옵션 그룹 묶음 동등 비교 키(normalizeOptionKey/Value) — 언어별로 바꾸면 묶음이 갈라진다'],
        'app/Console/Commands/PlaywrightCleanup.php' => [2, '동등 비교: 이름이 식별자 그대로인 테스트 잔존 권한 판정'],
        'app/Services/RoleService.php' => [1, '식별자 생성: en → ko → 첫 값 슬러그(표시 아님)'],
        'app/Support/ExtensionDoc/HookInventory.php' => [1, '한국어 확장 문서 생성기 — 문서 언어가 ko'],
    ];

    /**
     * 화면 코드에서 `?.ko ??`·`['ko'] ||` 류를 써도 되는 파일 → 허용 건수·사유.
     *
     * @var array<string, array{0: int, 1: string}>
     */
    private const FRONTEND_KO_INDEX_ALLOWED = [
        'modules/_bundled/sirsoft-ecommerce/resources/js/handlers/optionHandlers.ts' => [1, 'K: 옵션 키 동등 비교용 ko 정본'],
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_form/_modal_label_form.g7.html' => [1, 'R: 필수 언어(ko) 입력 검사'],
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_settings/_modal_mail_template_edit.g7.html' => [2, 'F14: 참조 0건 고아 파셜(기록만, 수정 안 함)'],
    ];

    /**
     * 백엔드 값 표시가 `app.fallback_locale` 이나 `'ko'` 를 직접 색인하지 않는다.
     *
     * @scenario shape=two_letter
     *
     * @effects backend_value_fallback_has_no_hardcoded_ko
     *
     * @return void 반환값 없음
     */
    public function test_backend_value_fallback_does_not_index_by_fallback_locale(): void
    {
        $files = $this->collect(['app'], ['modules/_bundled/*/src', 'plugins/_bundled/*/src'], '/\.php$/');
        // 검사 대상을 못 모으면 위반 0 으로 조용히 통과한다 — 모집단 자체를 잰다.
        $this->assertContains('app/Support/LocalizedValue.php', $files);
        $this->assertNotEmpty(preg_grep('#^modules/_bundled/[^/]+/src/#', $files));

        $this->assertSame(
            [],
            $this->violations($files, '/config\(\s*[\'"]app\.fallback_locale/', self::BACKEND_FALLBACK_LOCALE_ALLOWED),
            'config(\'app.fallback_locale\') 로 다국어 값을 색인하지 말고 LocalizedValue::pick() 을 쓴다'
        );
        $this->assertSame(
            [],
            // 앞 식은 첨자를 넘어 본다 — `$names[$identifier]->name['ko']` 같은 자리를 놓쳤었다.
            $this->violations($files, '/\[[\'"]ko[\'"]\]\s*\?\?|\?\?\s*\$[^\s?;,()]*\[[\'"]ko[\'"]\]/', self::BACKEND_KO_INDEX_ALLOWED),
            "['ko'] 로 다국어 값을 대체하지 말고 LocalizedValue::pick() 을 쓴다"
        );
    }

    /**
     * 화면 코드·레이아웃이 다국어 값을 `ko` 로 고정 대체하지 않는다.
     *
     * @scenario shape=two_letter
     *
     * @effects frontend_value_fallback_has_no_hardcoded_ko
     *
     * @return void 반환값 없음
     */
    public function test_frontend_value_fallback_does_not_hardcode_ko(): void
    {
        $scripts = $this->collect(
            ['resources/js/core'],
            ['templates/_bundled/*/src', 'modules/_bundled/*/resources/js', 'plugins/_bundled/*/resources/js'],
            '/\.tsx?$/'
        );
        $layouts = $this->collect([], ['modules/_bundled/*', 'plugins/_bundled/*', 'templates/_bundled/*'], '/\.g7\.html$/');
        $this->assertContains('resources/js/core/support/localeTag.ts', $scripts);
        $this->assertNotEmpty(preg_grep('#^templates/_bundled/[^/]+/src/#', $scripts));
        $this->assertNotEmpty($layouts);

        $this->assertSame(
            [],
            $this->violations(
                array_values(array_unique(array_merge($scripts, $layouts))),
                '/(\?\.|\.)ko\s*(\?\?|\|\|)|\[[\'"]ko[\'"]\]\s*(\?\?|\|\|)/',
                self::FRONTEND_KO_INDEX_ALLOWED
            ),
            'x?.ko ?? 대신 G7Core.locale.pick(x) 또는 $localized(x) 를 쓴다'
        );
    }

    /**
     * 허용 목록과 대조해 위반 위치를 돌려줍니다.
     *
     * @param  array<int, string>  $files  저장소 기준 상대 경로 목록
     * @param  string  $pattern  한 줄 단위 검사 정규식
     * @param  array<string, array{0: int, 1: string}>  $allowed  파일 → [허용 건수, 사유]
     * @return array<int, string> 위반 설명 (없으면 빈 배열)
     */
    private function violations(array $files, string $pattern, array $allowed): array
    {
        $found = [];
        foreach ($files as $relative) {
            $lines = file($this->root().'/'.$relative) ?: [];
            foreach ($lines as $number => $line) {
                if (preg_match($pattern, $line) === 1) {
                    $found[$relative][] = ($number + 1).': '.trim($line);
                }
            }
        }

        $violations = [];
        foreach ($found as $relative => $hits) {
            $limit = $allowed[$relative][0] ?? 0;
            if (count($hits) !== $limit) {
                $violations[] = sprintf('%s (허용 %d, 실제 %d)', $relative, $limit, count($hits)).PHP_EOL.'  '.implode(PHP_EOL.'  ', $hits);
            }
        }
        foreach ($allowed as $relative => [$limit, $reason]) {
            if (! isset($found[$relative]) && $limit > 0) {
                $violations[] = sprintf('%s 허용 목록 %d건이 0건으로 줄었다 — 목록을 갱신한다 (%s)', $relative, $limit, $reason);
            }
        }

        return $violations;
    }

    /**
     * 검사 대상 파일을 모읍니다 (`__tests__`·`dist`·`node_modules`·`vendor` 제외).
     *
     * @param  array<int, string>  $dirs  저장소 기준 디렉터리
     * @param  array<int, string>  $globs  저장소 기준 glob 디렉터리
     * @param  string  $filePattern  파일 이름 정규식
     * @return array<int, string> 저장소 기준 상대 경로 (`/` 구분)
     */
    private function collect(array $dirs, array $globs, string $filePattern): array
    {
        $root = $this->root();
        foreach ($globs as $glob) {
            foreach (glob($root.'/'.$glob, GLOB_ONLYDIR) ?: [] as $dir) {
                $dirs[] = substr($dir, strlen($root) + 1);
            }
        }

        $files = [];
        foreach ($dirs as $dir) {
            if (! is_dir($root.'/'.$dir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($root.'/'.$dir, \FilesystemIterator::SKIP_DOTS),
                    static fn (\SplFileInfo $file) => ! ($file->isDir() && in_array($file->getFilename(), ['__tests__', 'dist', 'node_modules', 'vendor', 'tests'], true))
                )
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && preg_match($filePattern, $file->getFilename()) === 1) {
                    $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                }
            }
        }

        sort($files);

        return array_values(array_unique($files));
    }

    /**
     * 저장소 루트 절대 경로.
     *
     * @return string 루트 경로
     */
    private function root(): string
    {
        return str_replace('\\', '/', dirname(__DIR__, 3));
    }
}
