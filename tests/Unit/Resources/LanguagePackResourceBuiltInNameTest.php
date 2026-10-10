<?php

namespace Tests\Unit\Resources;

use App\Http\Resources\LanguagePackResource;
use App\Models\LanguagePack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * 확장·코어에 내장된(보호) 언어팩의 표시 이름 (7.1.0 전수 실측 #736 O-F4).
 *
 * 내장 언어팩은 매니페스트 파일이 없어 이름이 없다. 언어팩 관리 카드는 이름이 없으면 식별자를 그려,
 * 보호 언어팩 카드 34장이 「sirsoft-template-sirsoft-admin_basic-en」 같은 내부 식별자로만 보였다
 * (같은 화면의 설치형 언어팩은 「G7 모듈 (sirsoft-board) 일본어 언어팩」처럼 사람이 읽는 이름).
 * 매니페스트 이름이 없는 내장 언어팩은 대상 확장 이름과 언어 이름으로 표시 이름을 만든다.
 */
class LanguagePackResourceBuiltInNameTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 내장 언어팩 모델을 저장하지 않고 만듭니다.
     *
     * @param  string  $scope  대상 범위(core·module·plugin·template)
     * @param  string|null  $target  대상 확장 식별자(코어는 null)
     * @param  string  $locale  로케일
     * @param  string  $nativeName  언어 고유 이름
     * @return LanguagePack 내장 언어팩
     */
    private function builtIn(string $scope, ?string $target, string $locale, string $nativeName): LanguagePack
    {
        $identifier = $target !== null ? "sirsoft-{$scope}-{$target}-{$locale}" : "sirsoft-{$scope}-{$locale}";
        $pack = new LanguagePack;
        $pack->identifier = $identifier;
        $pack->vendor = 'sirsoft';
        $pack->scope = $scope;
        $pack->target_identifier = $target;
        $pack->locale = $locale;
        $pack->locale_native_name = $nativeName;
        $pack->version = '1.0.0';
        $pack->status = 'active';
        $pack->is_protected = true;
        $pack->source_type = 'bundled_with_extension';
        $pack->manifest = [
            'identifier' => $identifier,
            'scope' => $scope,
            'target_identifier' => $target,
            'locale' => $locale,
            'bundled_with_extension' => true,
        ];

        return $pack;
    }

    /**
     * 매니페스트 이름이 없는 내장 언어팩은 식별자 대신 「대상 + 언어」로 이름을 만든다.
     *
     * @scenario case=built_in_without_manifest_name
     *
     * @effects built_in_language_pack_has_readable_name
     *
     * @return void 반환값 없음
     */
    public function test_built_in_pack_gets_readable_name(): void
    {
        App::setLocale('ko');
        $module = $this->builtIn('module', 'acme-board', 'en', 'English');
        $core = $this->builtIn('core', null, 'ko', '한국어');

        $moduleName = (new LanguagePackResource($module))->toArray(Request::create('/'))['name'];
        $coreName = (new LanguagePackResource($core))->toArray(Request::create('/'))['name'];

        $this->assertSame(
            __('language_packs.built_in_name', ['target' => 'acme-board', 'language' => 'English']),
            $moduleName
        );
        $this->assertSame(
            __('language_packs.built_in_name', ['target' => __('language_packs.core_target'), 'language' => '한국어']),
            $coreName
        );
        $this->assertNotSame($module->identifier, $moduleName);

        App::setLocale('en');
        $this->assertStringContainsString(
            'English',
            (new LanguagePackResource($module))->toArray(Request::create('/'))['name']
        );
    }

    /**
     * 매니페스트에 이름이 있으면 그 이름을 그대로 쓴다(설치형 언어팩 회귀 방지).
     *
     * @scenario case=manifest_name_present
     *
     * @effects built_in_language_pack_has_readable_name
     *
     * @return void 반환값 없음
     */
    public function test_manifest_name_wins(): void
    {
        App::setLocale('ko');
        $pack = $this->builtIn('module', 'acme-board', 'ja', '日本語');
        $pack->is_protected = false;
        $pack->source_type = 'bundled';
        $pack->manifest = $pack->manifest + ['name' => ['ko' => 'G7 모듈 일본어 언어팩', 'en' => 'G7 module Japanese language pack']];

        $this->assertSame('G7 모듈 일본어 언어팩', (new LanguagePackResource($pack))->toArray(Request::create('/'))['name']);
    }
}
