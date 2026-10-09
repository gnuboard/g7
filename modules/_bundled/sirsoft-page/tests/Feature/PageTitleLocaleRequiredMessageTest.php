<?php

namespace Modules\Sirsoft\Page\Tests\Feature;

// FeatureTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../FeatureTestCase.php';

use App\Models\User;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Tests\FeatureTestCase;

/**
 * 페이지 제목 다국어 필수 문구의 칸 이름 테스트
 *
 * 페이지 생성·수정에서 제목을 비우면 「한국어(ko) 값은 필수입니다.」처럼 칸 이름 없이 나왔다(7.1.0 전수 실측 K4,
 * 결정 2026-10-08 「코어 요청 6종 모두 칸 이름 넣기」). 두 요청이 같은 칸 이름(ko 「페이지 제목」,
 * en 「page title」)을 싣는지 고정한다.
 *
 * @scenario-manifest tests/scenarios/page-title-locale-required.yaml
 */
class PageTitleLocaleRequiredMessageTest extends FeatureTestCase
{
    /**
     * 페이지 관리 권한 관리자
     */
    private User $admin;

    /**
     * 관리자를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminUser([
            'sirsoft-page.pages.read',
            'sirsoft-page.pages.create',
            'sirsoft-page.pages.update',
        ]);
    }

    /**
     * 생성·수정 요청에 빈 제목을 보내 받은 title 오류 문구를 모읍니다.
     *
     * @param  string  $locale  관리자 언어
     * @return array<string, string> 요청 이름 → 첫 오류 문구
     */
    private function collectTitleErrors(string $locale): array
    {
        $this->admin->forceFill(['language' => $locale])->save();
        $page = Page::factory()->create(['slug' => 'title-attr-'.$locale]);
        $payload = ['slug' => 'title-attr-new-'.$locale, 'title' => ['ko' => '', 'en' => '']];

        try {
            $store = $this->actingAs($this->admin)
                ->postJson('/api/modules/sirsoft-page/admin/pages', $payload)
                ->assertStatus(422)
                ->json('errors.title.0');

            $update = $this->actingAs($this->admin)
                ->putJson("/api/modules/sirsoft-page/admin/pages/{$page->id}", $payload)
                ->assertStatus(422)
                ->json('errors.title.0');
        } finally {
            $page->forceDelete();
        }

        return ['store' => (string) $store, 'update' => (string) $update];
    }

    /**
     * 한국어 관리자에게 칸 이름 「페이지 제목」과 언어 이름이 함께 나온다.
     *
     * @scenario locale=ko
     *
     * @effects page_title_locale_required_message_names_field
     *
     * @return void 반환값 없음
     */
    public function test_korean_message_names_page_title(): void
    {
        foreach ($this->collectTitleErrors('ko') as $request => $message) {
            $this->assertSame('페이지 제목의 한국어(ko) 값은 필수입니다.', $message, "[{$request}]");
        }
    }

    /**
     * 영어 관리자에게 칸 이름 「page title」과 언어 이름이 함께 나온다 — 필수 언어는 사이트 기본 언어(ko)다.
     *
     * @scenario locale=en
     *
     * @effects page_title_locale_required_message_names_field
     *
     * @return void 반환값 없음
     */
    public function test_english_message_names_page_title(): void
    {
        foreach ($this->collectTitleErrors('en') as $request => $message) {
            $this->assertSame('The 한국어 (ko) value of the page title field is required.', $message, "[{$request}]");
        }
    }
}
