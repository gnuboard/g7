<?php

namespace Modules\Sirsoft\Page\Tests\Feature;

// FeatureTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../FeatureTestCase.php';

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Tests\FeatureTestCase;

/**
 * 임시 첨부 키 검증 오류 문구의 항목 이름 테스트
 *
 * 페이지 생성·수정·첨부 업로드 요청은 UUID 가 아닌 임시 키를 422 로 거절한다. 그 문구가
 * 「temp key 필드는 유효한 UUID여야 합니다.」처럼 내부 필드 이름을 그대로 보였다(7.1.0 전수 실측
 * 영역 L, O-L3). 세 요청이 같은 항목 이름(ko 「임시 첨부 키」, en 「temporary attachment key」)을
 * 쓰는지 고정한다.
 *
 * @scenario-manifest tests/scenarios/temp-key-attribute-name.yaml
 */
class TempKeyAttributeNameTest extends FeatureTestCase
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
     * 세 요청에 비UUID 임시 키를 보내 받은 temp_key 오류 문구를 모읍니다.
     *
     * @param  string  $locale  관리자 언어
     * @return array<string, string> 요청 이름 → 첫 오류 문구
     */
    private function collectTempKeyErrors(string $locale): array
    {
        $this->admin->forceFill(['language' => $locale])->save();
        $page = Page::factory()->create(['slug' => 'temp-key-attr-'.$locale]);

        try {
            $store = $this->actingAs($this->admin)
                ->postJson('/api/modules/sirsoft-page/admin/pages', ['temp_key' => 'temp_1791350898_abc123def'])
                ->assertStatus(422)
                ->json('errors.temp_key.0');

            $update = $this->actingAs($this->admin)
                ->putJson("/api/modules/sirsoft-page/admin/pages/{$page->id}", ['temp_key' => '../../settings'])
                ->assertStatus(422)
                ->json('errors.temp_key.0');

            $upload = $this->actingAs($this->admin)
                ->postJson('/api/modules/sirsoft-page/admin/attachments', [
                    'file' => UploadedFile::fake()->create('probe.pdf', 10, 'application/pdf'),
                    'temp_key' => 'not-a-uuid',
                ])
                ->assertStatus(422)
                ->json('errors.temp_key.0');
        } finally {
            $page->forceDelete();
        }

        return ['store' => (string) $store, 'update' => (string) $update, 'upload' => (string) $upload];
    }

    /**
     * 한국어 문구는 「임시 첨부 키」를 항목 이름으로 쓴다.
     *
     * @return void 반환값 없음
     *
     * @scenario locale=ko
     *
     * @effects temp_key_error_uses_friendly_attribute_name
     */
    public function test_korean_message_uses_friendly_attribute_name(): void
    {
        foreach ($this->collectTempKeyErrors('ko') as $request => $message) {
            $this->assertStringContainsString('임시 첨부 키', $message, "{$request}: {$message}");
            $this->assertStringNotContainsString('temp key', $message, "{$request}: {$message}");
            $this->assertStringNotContainsString('temp_key', $message, "{$request}: {$message}");
        }
    }

    /**
     * 영어 문구는 「temporary attachment key」를 항목 이름으로 쓴다.
     *
     * @return void 반환값 없음
     *
     * @scenario locale=en
     *
     * @effects temp_key_error_uses_friendly_attribute_name
     */
    public function test_english_message_uses_friendly_attribute_name(): void
    {
        foreach ($this->collectTempKeyErrors('en') as $request => $message) {
            $this->assertStringContainsString('temporary attachment key', $message, "{$request}: {$message}");
            $this->assertStringNotContainsString('temp key', $message, "{$request}: {$message}");
        }
    }
}
