<?php

namespace Tests\Feature\Settings;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Models\Attachment;
use App\Services\SettingsService;
use Tests\TestCase;

/**
 * 사이트 기본 OG 이미지(seo.og_image_default) 설정의 저장·읽기·해석을 고정합니다 (공개 #22).
 *
 * 이 설정은 화면(FileUploader) 한 곳에서 입력되지만 네 지점을 지나야 동작합니다 —
 * 검증(SaveSettingsRequest), 저장(seo 카테고리 + 첨부 ID 정제), 관리자 재로드(첨부
 * 객체 변환), 해석(getOgDefaultImageUrl → SeoMetaResolver og:image 폴백). 어느 한
 * 지점이 빠지면 "저장은 되는데 og:image 는 비는" 상태가 되고 화면에 드러나지 않습니다.
 *
 * @effects og_default_image_persists_to_seo_category
 * @effects og_default_image_resolves_first_image_url
 */
class OgDefaultImageSettingTest extends TestCase
{
    /**
     * og_image_default 컬렉션 이미지 첨부를 만듭니다.
     *
     * @return Attachment 생성된 첨부
     */
    private function createOgImageAttachment(): Attachment
    {
        return Attachment::create([
            'source_type' => 'core',
            'source_identifier' => 'og_image_default',
            'hash' => bin2hex(random_bytes(6)),
            'original_filename' => 'og-default.png',
            'stored_filename' => 'og-default-'.uniqid().'.png',
            'disk' => 'public',
            'path' => 'settings/og-default.png',
            'mime_type' => 'image/png',
            'size' => 1024,
            'collection' => 'og_image_default',
            'order' => 0,
        ]);
    }

    /**
     * 기존 설치본에도 새 키가 기본값으로 노출되는지 확인합니다 (defaults 병합).
     *
     * @scenario image_chain=none, setting_state=absent
     *
     * @effects og_default_image_persists_to_seo_category
     */
    public function test_new_key_present_through_defaults_merge(): void
    {
        $seo = app(ConfigRepositoryInterface::class)->getCategory('seo');

        $this->assertArrayHasKey('og_image_default', $seo);
        $this->assertSame([], $seo['og_image_default']);
    }

    /**
     * seo 탭 저장 시 첨부 ID 배열이 정제되어 seo 카테고리에 기록되는지 확인합니다.
     *
     * @scenario image_chain=site_default, setting_state=saved
     *
     * @effects og_default_image_persists_to_seo_category
     */
    public function test_saved_attachment_ids_persist_to_seo_category(): void
    {
        $attachment = $this->createOgImageAttachment();

        $saved = app(SettingsService::class)->saveSettings([
            '_tab' => 'seo',
            'seo' => [
                // 화면 제출 형태(첨부 객체 배열)와 ID 배열 모두 수용해야 한다
                'og_image_default' => [['id' => $attachment->id, 'hash' => $attachment->hash]],
            ],
        ]);

        $this->assertTrue($saved, 'seo 탭 저장이 실패했습니다.');

        $seo = app(ConfigRepositoryInterface::class)->getCategory('seo');
        $this->assertSame([$attachment->id], $seo['og_image_default']);

        // 존재하지 않는 첨부 ID 는 정제 단계에서 걸러진다
        app(SettingsService::class)->saveSettings([
            '_tab' => 'seo',
            'seo' => ['og_image_default' => [999999]],
        ]);

        $this->assertSame([], app(ConfigRepositoryInterface::class)->getCategory('seo')['og_image_default']);
    }

    /**
     * 저장된 첨부가 getOgDefaultImageUrl 로 첫 이미지 URL 로 해석되는지 확인합니다.
     *
     * @scenario image_chain=site_default, setting_state=saved
     *
     * @effects og_default_image_resolves_first_image_url
     */
    public function test_resolves_first_image_url(): void
    {
        $attachment = $this->createOgImageAttachment();

        app(SettingsService::class)->saveSettings([
            '_tab' => 'seo',
            'seo' => ['og_image_default' => [$attachment->id]],
        ]);

        $url = app(SettingsService::class)->getOgDefaultImageUrl();

        $this->assertNotNull($url, '저장된 기본 OG 이미지가 URL 로 해석되어야 합니다.');
        $this->assertStringContainsString($attachment->hash, $url);
    }

    /**
     * 미설정이면 null 을 돌려주는지 확인합니다 (og:image 태그 미출력 경로).
     *
     * @scenario image_chain=none, setting_state=absent
     *
     * @effects og_default_image_resolves_first_image_url
     */
    public function test_resolves_null_when_not_configured(): void
    {
        $this->assertNull(app(SettingsService::class)->getOgDefaultImageUrl());
    }
}
