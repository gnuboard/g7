<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\LanguagePackScope;
use App\Http\Resources\LanguagePackResource;
use App\Services\LanguagePackService;
use Illuminate\Http\Request;

/**
 * 확장(모듈/플러그인/템플릿) detail 응답에 `language_packs` 필드를 주입하는 trait.
 *
 * 컨트롤러에서 `LanguagePackService::getPacksForExtension()` 을 1회 호출하고
 * `LanguagePackResource` 로 직렬화하여 detail 배열에 병합합니다 — N+1 회피 + 단일
 * 책임 유지.
 */
trait InjectsExtensionLanguagePacks
{
    /**
     * 확장 detail 배열에 `language_packs` 필드를 추가합니다.
     *
     * @param  array<string, mixed>  $detail  Resource toDetailArray 결과
     * @param  LanguagePackScope  $scope  대상 스코프
     * @param  string|null  $targetIdentifier  대상 식별자 (코어는 null)
     * @param  Request  $request  HTTP 요청
     * @return array<string, mixed> language_packs 가 추가된 detail 배열
     */
    protected function attachLanguagePacks(
        array $detail,
        LanguagePackScope $scope,
        ?string $targetIdentifier,
        Request $request,
    ): array {
        /** @var LanguagePackService $service */
        $service = app(LanguagePackService::class);

        $packs = $service->getPacksForExtension($scope, $targetIdentifier);

        $detail['language_packs'] = $packs
            ->map(fn ($pack) => (new LanguagePackResource($pack))->toArray($request))
            ->all();

        return $detail;
    }
}
