<?php

namespace Tests\Feature\LanguagePack;

use App\Services\LanguagePackService;
use Tests\TestCase;

/**
 * 번들 언어팩 설치 차단 사유 재판정 — `language-pack:provision` 이 그 팩 차례에 부르는 서비스 메서드 (#153 E).
 *
 * 실제 번들 manifest(`lang-packs/_bundled/{id}/language-pack.json`)를 읽어 지금 상태로 차단 사유를 계산한다.
 * 코어 범위 팩은 코어 locale 의존이 없고, 확장 팩은 같은 로케일의 코어 팩이 활성이 아니면 `core_locale_missing` 이다.
 */
class LanguagePackBlockedReasonRecheckTest extends TestCase
{
    /**
     * 코어 팩은 막히지 않고, 같은 로케일 코어 팩이 없을 때 확장 팩은 core_locale_missing 으로 다시 판정된다.
     *
     * @scenario surface=provision, edge=core_locale_pack
     *
     * @effects provision_rechecks_core_locale_block_from_bundled_manifest
     *
     * @return void 반환값 없음
     */
    public function test_recheck_reads_bundled_manifest_and_current_state(): void
    {
        $service = app(LanguagePackService::class);

        $this->assertNull($service->bundledInstallBlockedReason('g7-core-ja'), '코어 범위 팩은 코어 locale 의존이 없다');
        $this->assertSame('core_locale_missing', $service->bundledInstallBlockedReason('g7-module-sirsoft-board-ja'));
    }

    /**
     * 번들 manifest 가 없으면 사유를 만들지 않는다 — 설치 단계가 식별자 미존재로 사유와 함께 실패한다.
     *
     * @scenario surface=provision, edge=core_locale_pack
     *
     * @effects provision_rechecks_core_locale_block_from_bundled_manifest
     *
     * @return void 반환값 없음
     */
    public function test_unknown_bundled_identifier_has_no_reason(): void
    {
        $this->assertNull(app(LanguagePackService::class)->bundledInstallBlockedReason('acme-no-such-pack-ja'));
    }
}
