<?php

namespace Tests\Concerns;

use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackStatus;
use App\Models\LanguagePack;
use App\Services\LanguagePack\LanguagePackRegistry;

/**
 * 번들 언어팩(`lang-packs/_bundled/{식별자}`)을 활성 팩으로 등록하는 시험 도우미.
 *
 * 팩 파일을 복사하지 않고 행이 번들 디렉터리를 가리키게 한다(`source_type = bundled_with_extension`).
 * 행은 시험의 트랜잭션과 함께 사라진다. 확장이 선언한 글(회원 항목 이름 등)이 그 확장의 ja 팩으로 풀리는지 잴 때 쓴다.
 */
trait ActivatesBundledLanguagePack
{
    /**
     * 번들 언어팩 하나를 활성 행으로 등록하고 등록소 캐시를 비웁니다.
     *
     * @param  string  $bundledIdentifier  번들 팩 식별자 (예: g7-module-sirsoft-board-ja)
     * @param  LanguagePackScope  $scope  팩 범위
     * @param  string|null  $target  대상 확장 식별자 (코어 팩이면 null)
     * @param  string  $locale  팩 로케일
     * @return LanguagePack 등록한 팩
     */
    protected function activateBundledLanguagePack(string $bundledIdentifier, LanguagePackScope $scope, ?string $target, string $locale = 'ja'): LanguagePack
    {
        $pack = LanguagePack::query()->create([
            'identifier' => 'test-'.$bundledIdentifier.'-'.uniqid(),
            'vendor' => 'test',
            'scope' => $scope->value,
            'target_identifier' => $target,
            'locale' => $locale,
            'locale_name' => $locale,
            'locale_native_name' => $locale,
            'text_direction' => 'ltr',
            'version' => '1.0.0',
            'status' => LanguagePackStatus::Active->value,
            'is_protected' => false,
            'manifest' => [],
            'source_type' => 'bundled_with_extension',
            'source_url' => 'lang-packs/_bundled/'.$bundledIdentifier,
        ]);

        app(LanguagePackRegistry::class)->invalidate();

        return $pack;
    }
}
