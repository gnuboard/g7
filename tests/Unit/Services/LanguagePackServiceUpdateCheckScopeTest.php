<?php

namespace Tests\Unit\Services;

use App\Contracts\Repositories\LanguagePackRepositoryInterface;
use App\Models\LanguagePack;
use App\Services\LanguagePackService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 언어팩 업데이트 확인의 순회 범위 계약 테스트 (공개 이슈 #102 동형)
 *
 * 종전에는 `repository->paginate([], 1000)` 로 전량 순회를 의도했는데, page 인자를
 * 지정하지 않아 HTTP `page` 파라미터가 암묵 해석됐다. `?page=2` 상태로 업데이트 확인을
 * 호출하면 offset=1000 이 적용되어 `checked: 0` 의 조용한 오답이 됐다.
 *
 * 수정 후 계약: 업데이트 확인은 요청의 page 파라미터와 무관하게 설치된 전체 팩을 순회한다.
 *
 * @scenario case=update_check_scope
 *
 * @effects update_check_ignores_request_page
 */
class LanguagePackServiceUpdateCheckScopeTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 요청에 page=2 가 실려 있어도 순회 대상이 설치된 전체 팩인지 확인
     *
     * 순회 계층에서 건수를 정확히 단언한다 — 서비스 `checked` 는 GitHub URL 도 번들
     * manifest 도 없는 팩을 설계상 건너뛰므로 설치본 수와 같지 않고, 그 판정을 테스트가
     * 재구현하면 규칙이 두 벌이 되어 한쪽만 바뀐다. 결함이 있던 자리는 순회 그 자체다.
     */
    public function test_update_check_traverses_all_packs_regardless_of_request_page(): void
    {
        // Given: bundled manifest 가 실존하는 설치 팩 1건 (GitHub 미설정 → bundled 폴백, 네트워크 없음)
        LanguagePack::query()->firstOrCreate(
            ['identifier' => 'g7-core-ja'],
            [
                'vendor' => 'g7',
                'scope' => 'core',
                'target_identifier' => 'core',
                'locale' => 'ja',
                'locale_name' => 'Japanese',
                'locale_native_name' => '日本語',
                'text_direction' => 'ltr',
                'version' => '0.0.1',
                'license' => 'MIT',
                'status' => 'active',
                'source_type' => 'bundled',
                'manifest' => ['identifier' => 'g7-core-ja', 'version' => '0.0.1'],
            ]
        );

        $installed = LanguagePack::query()->count();
        $this->assertGreaterThan(0, $installed, '설치된 팩이 없어 순회 범위를 측정할 수 없습니다.');

        // 페이지네이터의 현재 페이지 암묵 해석이 읽는 자리 — #102 재현 조건
        request()->merge(['page' => 2]);

        $traversed = app(LanguagePackRepositoryInterface::class)->allForUpdateCheck();

        $this->assertCount(
            $installed,
            $traversed,
            "page=2 요청 상태에서 순회 대상이 {$traversed->count()} 건이었습니다 (설치 {$installed} 건) — 페이지네이션 암묵 page 해석(#102 동형)입니다."
        );

        // 서비스 경로도 같은 순회를 타는지 — 종단에서 0건 오답이 재발하지 않음을 고정
        $result = app(LanguagePackService::class)->checkUpdates();

        $this->assertGreaterThan(
            0,
            $result['checked'],
            '업데이트 확인이 0건 순회했습니다 — 순회는 정상인데 서비스가 다른 경로를 쓰고 있습니다.'
        );
    }
}
