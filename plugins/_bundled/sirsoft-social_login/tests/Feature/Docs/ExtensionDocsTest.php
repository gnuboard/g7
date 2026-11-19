<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Feature\Docs;

use Illuminate\Support\Facades\Artisan;
use Plugins\Sirsoft\SocialLogin\Tests\PluginTestCase;

/**
 * 개발자 문서(AGENTS.md·README.md·docs)의 생성 블록이 코드 실측과 맞는지 — `ext:docgen --check` 이슈 0건. 라우트·훅·레이아웃을
 * 더하고 문서를 다시 굽지 않으면 여기서 걸린다.
 *
 * @since 1.0.0
 */
class ExtensionDocsTest extends PluginTestCase
{
    /**
     * docgen 드리프트 검사가 이 플러그인에서 이슈 0건인지 확인한다.
     *
     * @scenario check=docgen
     *
     * @effects docgen_check_clean
     *
     * @return void 반환값 없음
     */
    public function test_generated_doc_blocks_match_code(): void
    {
        $exit = Artisan::call('ext:docgen', ['--scope' => 'plugin:sirsoft-social_login', '--check' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('이슈 0건', $output);
    }
}
