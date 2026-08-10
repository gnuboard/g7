<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Storage;
use Modules\Sirsoft\Board\Http\Requests\UploadAttachmentRequest;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 허용 확장자 빈 값 게시판의 업로드 폴백 테스트
 *
 * allowed_extensions 가 빈 배열([]) 또는 NULL 인 레거시 게시판에서
 * `implode(',', [])` 가 `mimes:` 빈 규칙을 만들어 전 파일 업로드가 거부되던 결함의 회귀 테스트.
 *
 * `??` 는 null 만 잡고 []는 통과시키므로 소비 시점에 `is_array() && !== []` 판정이 필요하다.
 *
 * @group board
 * @group attachment
 */
class AttachmentAllowedExtensionsFallbackTest extends BoardTestCase
{
    private User $memberUser;

    /**
     * 테스트 게시판 슬러그를 반환합니다.
     *
     * @return string 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'attachment-ext-fallback';
    }

    /**
     * 테스트 게시판 기본 속성을 반환합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @return array<string, mixed> 게시판 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '확장자 폴백 테스트 게시판', 'en' => 'Extension Fallback Test Board'],
            'is_active' => true,
            'use_file_upload' => true,
            'allowed_extensions' => [],
        ];
    }

    /**
     * 테스트 환경을 준비합니다.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->grantUserRolePermissions(['posts.read', 'posts.write', 'attachments.upload']);

        $this->memberUser = User::factory()->create(['email' => 'ext-fallback-member@test.com']);

        $userRole = Role::where('identifier', 'user')->first();
        if ($userRole) {
            $this->memberUser->roles()->attach($userRole->id);
        }
    }

    /**
     * UploadAttachmentRequest 를 대상 게시판 슬러그로 생성합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @return UploadAttachmentRequest 라우트 슬러그가 주입된 요청 객체
     */
    private function makeUploadRequest(string $slug): UploadAttachmentRequest
    {
        $request = new UploadAttachmentRequest;

        $request->setRouteResolver(function () use ($slug) {
            $route = \Mockery::mock(Route::class);
            $route->shouldReceive('parameter')->with('slug', null)->andReturn($slug);

            return $route;
        });

        return $request;
    }

    /**
     * file 규칙에서 mimes 파라미터를 추출합니다.
     *
     * @param  array<string, mixed>  $rules  검증 규칙
     * @return string|null mimes 확장자 목록 (규칙 부재 시 null)
     */
    private function extractMimes(array $rules): ?string
    {
        foreach ($rules['file'] ?? [] as $rule) {
            if (is_string($rule) && str_starts_with($rule, 'mimes:')) {
                return substr($rule, strlen('mimes:'));
            }
        }

        return null;
    }

    /**
     * allowed_extensions 가 빈 배열이면 mimes 규칙이 비어있지 않아야 한다 (회귀)
     */
    public function test_empty_allowed_extensions_does_not_produce_empty_mimes_rule(): void
    {
        $rules = $this->makeUploadRequest($this->board->slug)->rules();

        $mimes = $this->extractMimes($rules);

        $this->assertNotSame('', $mimes, 'allowed_extensions=[] 에서 mimes: 빈 규칙이 생성되면 전 파일이 거부된다');
        $this->assertNotEmpty($mimes, 'mimes 규칙은 기본 확장자 목록으로 폴백되어야 함');
    }

    /**
     * allowed_extensions 가 NULL 이어도 mimes 규칙이 비어있지 않아야 한다
     */
    public function test_null_allowed_extensions_does_not_produce_empty_mimes_rule(): void
    {
        $this->updateBoardSettings(['allowed_extensions' => null]);

        $rules = $this->makeUploadRequest($this->board->slug)->rules();

        $this->assertNotEmpty($this->extractMimes($rules), 'allowed_extensions=null 도 기본 확장자로 폴백되어야 함');
    }

    /**
     * allowed_extensions 가 빈 배열인 게시판에서도 실제 업로드가 성공해야 한다 (회귀)
     *
     * @scenario case=upload_enabled_extensions_empty
     *
     * @effects empty_extensions_upload_falls_back_to_defaults
     */
    public function test_upload_succeeds_when_allowed_extensions_is_empty(): void
    {
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $response = $this->actingAs($this->memberUser)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/attachments", [
                'file' => $file,
            ]);

        $response->assertStatus(201)->assertJsonPath('success', true);
    }

    /**
     * 허용 확장자가 지정된 게시판은 목록 밖 확장자를 계속 거부해야 한다 (회귀 방지)
     *
     * 폴백이 검증 자체를 무력화하지 않는지 확인한다.
     *
     * @scenario case=upload_enabled_extensions_nonempty
     *
     * @effects specified_extensions_still_reject_other_types
     */
    public function test_specified_allowed_extensions_still_reject_other_types(): void
    {
        $this->updateBoardSettings(['allowed_extensions' => ['jpg', 'png']]);

        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $response = $this->actingAs($this->memberUser)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/attachments", [
                'file' => $file,
            ]);

        $response->assertStatus(422);
    }

    /**
     * 테스트 정리
     */
    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }
}
