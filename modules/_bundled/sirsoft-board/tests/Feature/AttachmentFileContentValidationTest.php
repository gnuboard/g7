<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

require_once __DIR__.'/../ModuleTestCase.php';

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 게시판 첨부 업로드의 파일 형식 검증이 코어 첨부와 같은 기준(이름 + 내용)을 따르는지 검증합니다.
 *
 * 수정 전 게시판은 Laravel `mimes:` 만 걸었습니다. `mimes` 는 내용으로 추론한 확장자만 보고
 * 파일 이름의 확장자는 보지 않으므로,
 * - 허용 확장자에 md·csv 를 넣어도 내용 추론이 txt 라 정상 md·csv 가 「허용되지 않은 파일 형식」으로 거부되고,
 * - 허용하지 않은 이름(`x.html`)이라도 내용이 PNG 면 그대로 올라가 `.html` 로 저장되며,
 * - 0바이트 파일은 빈 파일 안내 대신 형식 오류로 거부됐습니다.
 * 코어 7.1.0 의 `extensions:` + `AttachmentFileContent` 를 함께 걸어 이름·내용이 모두 허용 목록에 들 때만 통과시킵니다.
 */
#[Group('board')]
#[Group('attachment')]
class AttachmentFileContentValidationTest extends BoardTestCase
{
    private User $memberUser;

    /**
     * 테스트 게시판 슬러그를 반환합니다.
     *
     * @return string 게시판 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'attachment-content-rule';
    }

    /**
     * 테스트 게시판 기본 속성을 반환합니다 (허용 확장자 png·jpg·md·csv).
     *
     * @param  string  $slug  게시판 슬러그
     * @return array 게시판 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '첨부 형식 게시판', 'en' => 'Attachment Type Board'],
            'is_active' => true,
            'use_file_upload' => true,
            'max_file_count' => 10,
            'allowed_extensions' => ['png', 'jpg', 'md', 'csv'],
            'secret_mode' => 'enabled',
            'blocked_keywords' => [],
        ];
    }

    /**
     * 테스트 환경을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('modules');
        Storage::fake('public');

        $this->grantUserRolePermissions(['posts.read', 'posts.write', 'attachments.upload']);

        $this->memberUser = User::factory()->create(['email' => 'attachment-content-rule@test.com', 'language' => 'ko']);

        $userRole = Role::where('identifier', 'user')->first();
        if ($userRole) {
            $this->memberUser->roles()->attach($userRole->id);
        }
    }

    /**
     * 실제 내용을 가진 업로드 파일을 만듭니다.
     *
     * `UploadedFile::fake()` 는 MIME 을 이름으로 정하므로 내용 기반 판정을 재현하지 못한다 —
     * 임시 파일에 내용을 쓰고 test 모드 `UploadedFile` 로 감싼다.
     *
     * @param  string  $name  클라이언트 파일명
     * @param  string  $content  파일 내용
     * @return UploadedFile 업로드 파일
     */
    private function realUpload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'g7att');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    /**
     * 단건 업로드 요청을 보냅니다.
     *
     * @param  UploadedFile  $file  업로드 파일
     * @return TestResponse 응답
     */
    private function upload(UploadedFile $file)
    {
        return $this->actingAs($this->memberUser, 'sanctum')
            ->postJson("/api/modules/sirsoft-board/boards/{$this->getTestBoardSlug()}/attachments", [
                'file' => $file,
                'temp_key' => (string) Str::uuid(),
            ]);
    }

    /**
     * SVG 내용을 PNG 이름으로 올리면 코어와 같은 내용 불일치 문구로 거부된다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=svg_as_png
     *
     * @effects disguised_svg_rejected_with_content_message
     */
    public function test_svg_content_named_png_is_rejected_with_content_mismatch_message(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect width="10" height="10"/></svg>';

        $response = $this->upload($this->realUpload('evil.png', $svg));

        $response->assertStatus(422);
        $this->assertStringContainsString(
            '파일 내용이 허용된 형식과 일치하지 않습니다',
            implode(' ', $response->json('errors.file') ?? [])
        );
    }

    /**
     * 0바이트 파일은 형식 오류가 아니라 빈 파일 안내로 거부된다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=empty
     *
     * @effects empty_file_rejected_with_empty_message
     */
    public function test_zero_byte_file_is_rejected_with_empty_file_message(): void
    {
        $response = $this->upload($this->realUpload('zero.png', ''));

        $response->assertStatus(422);
        $this->assertContains('빈 파일(0바이트)은 올릴 수 없습니다.', $response->json('errors.file') ?? []);
    }

    /**
     * 허용 확장자에 든 md·csv 는 내용이 일반 텍스트여도 올라간다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=plain_text
     *
     * @effects plain_text_formats_allowed_when_listed
     */
    public function test_markdown_and_csv_are_accepted_when_listed(): void
    {
        $this->upload($this->realUpload('note.md', "# 제목\n\n- 항목\n"))->assertStatus(201);
        $this->upload($this->realUpload('data.csv', "a,b\n1,2\n"))->assertStatus(201);
    }

    /**
     * 허용 목록 밖 이름(html)은 내용이 PNG 여도 거부된다 — 이름 확장자도 검사한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=png_as_html
     *
     * @effects unlisted_name_extension_rejected
     */
    public function test_unlisted_name_extension_is_rejected_even_with_image_content(): void
    {
        $png = UploadedFile::fake()->image('source.png', 8, 8);
        $html = $this->realUpload('page.html', (string) file_get_contents($png->getRealPath()));
        $this->assertSame('png', $html->guessExtension(), '내용은 PNG 여야 이름 검사만으로 거부됨을 보일 수 있다');

        $this->upload($html)->assertStatus(422)->assertJsonValidationErrors('file');
    }

    /**
     * 정상 PNG 는 그대로 올라간다 (대조군).
     *
     * @return void 반환값 없음
     *
     * @scenario case=png
     *
     * @effects regular_image_still_accepted
     */
    public function test_regular_png_is_accepted(): void
    {
        $this->upload(UploadedFile::fake()->image('photo.png', 8, 8))->assertStatus(201);
    }

    /**
     * 게시글 저장 시 함께 보내는 files[] 도 같은 기준을 따른다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=post_files
     *
     * @effects post_files_follow_same_rule
     */
    public function test_post_files_follow_the_same_content_rule(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>';

        $response = $this->actingAs($this->memberUser, 'sanctum')
            ->postJson("/api/modules/sirsoft-board/boards/{$this->getTestBoardSlug()}/posts", [
                'title' => '형식 검사 게시글',
                'content' => '본문은 열 글자를 넘겨야 합니다.',
                'files' => [
                    $this->realUpload('note.md', "# 메모\n"),
                    $this->realUpload('evil.png', $svg),
                ],
            ]);

        $response->assertStatus(422);
        $errors = $response->json('errors') ?? [];
        $this->assertArrayNotHasKey('files.0', $errors, 'md 는 허용 목록에 있으므로 통과해야 한다');
        $this->assertArrayHasKey('files.1', $errors, 'SVG 내용 png 는 거부돼야 한다');
    }
}
