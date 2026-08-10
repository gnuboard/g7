<?php

namespace Tests\Feature\LanguagePack;

use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackSourceType;
use App\Models\LanguagePack;
use App\Services\LanguagePackService;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 모듈 다국어 경로 정합성 테스트.
 *
 * 모듈의 백엔드 다국어는 런타임이 `모듈/src/lang` 만 등록한다(`TranslationServiceProvider`).
 * 코어의 확장 설치 검증(`ValidatesTranslationPath`)도 모듈의 올바른 경로를 `src/lang` 으로 못박는다.
 *
 * 그런데 언어팩 시스템이 다른 경로를 스캔하면, 관리자 화면이 "내장 번역" 이라고 보여주는 것과
 * 실제 화면에 나오는 문구의 출처가 어긋난다. 그 디렉토리에만 있는 문구는 번역 대상으로 노출되면서
 * 정작 어디에도 렌더되지 않는다.
 */
class ModuleLangPathAlignmentTest extends TestCase
{
    // 파일시스템 기반 가상 언어팩만 읽고 DB 에 쓰지 않는다 — 마이그레이션 재실행이 불필요하다.

    /**
     * 번들 모듈의 내장 언어팩이 런타임이 읽는 경로(src/lang)를 가리키는지 검증합니다.
     */
    #[Test]
    public function bundled_module_built_in_pack_points_to_runtime_lang_path(): void
    {
        $packs = app(LanguagePackService::class)->getVirtualBuiltInPacks();

        $modulePacks = $packs->filter(
            fn (LanguagePack $p) => $p->scope === LanguagePackScope::Module->value
                && $p->source_type === LanguagePackSourceType::BuiltIn->value
        );

        $this->assertNotEmpty($modulePacks, '번들 모듈의 내장 언어팩이 하나 이상 합성되어야 합니다.');

        foreach ($modulePacks as $pack) {
            $this->assertMatchesRegularExpression(
                '#^modules/_bundled/[^/]+/src/lang/(ko|en)$#',
                (string) $pack->source_url,
                "모듈 내장 언어팩 경로는 런타임이 로드하는 src/lang 이어야 합니다: {$pack->target_identifier} ({$pack->locale}) → {$pack->source_url}"
            );
        }
    }

    /**
     * 번들 모듈이 런타임 미로드 경로에 백엔드 다국어 파일을 두지 않았는지 검증합니다.
     *
     * `resources/lang/{locale}/*.php` 는 프런트엔드용 `resources/lang/{locale}.json` ·
     * `resources/lang/partial/{locale}/` 와 달리 아무도 읽지 않는다. 거기 문구를 고치면
     * 고쳐지지 않고, 그 사실이 드러나지 않는다.
     */
    #[Test]
    public function bundled_modules_have_no_backend_lang_outside_runtime_path(): void
    {
        $root = base_path('modules/_bundled');

        if (! File::isDirectory($root)) {
            $this->markTestSkipped('번들 모듈 디렉토리가 없습니다.');
        }

        $stray = [];

        foreach (File::directories($root) as $moduleDir) {
            foreach (['ko', 'en'] as $locale) {
                $wrong = $moduleDir.'/resources/lang/'.$locale;

                if (! File::isDirectory($wrong)) {
                    continue;
                }

                $php = array_map(
                    fn ($f) => basename($moduleDir).'/resources/lang/'.$locale.'/'.basename($f),
                    File::glob($wrong.'/*.php') ?: []
                );

                $stray = array_merge($stray, $php);
            }
        }

        $this->assertSame(
            [],
            $stray,
            "런타임이 읽지 않는 경로의 백엔드 다국어 파일이 있습니다(모듈은 src/lang 만 로드):\n  ".implode("\n  ", $stray)
        );
    }
}
