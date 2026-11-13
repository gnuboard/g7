<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// 테스트 베이스 클래스 수동 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 첨부 미리보기 조건부 요청 계약 테스트
 *
 * 미리보기 서빙이 코어 형제(PublicAttachmentController · PublicTemplateController)와
 * 같은 강도의 캐시 계약을 갖는지 응답 헤더로 고정합니다.
 *
 * `Last-Modified` 가 없으면 ETag 를 쓰지 않고 `If-Modified-Since` 로만 재검증하는
 * 프록시가 매 요청 전문을 받아 갑니다 — 오류가 아니라 조용한 대역폭 낭비라
 * 응답 헤더를 직접 재는 것 말고는 드러날 통로가 없습니다.
 */
class AttachmentPreviewCacheContractTest extends BoardTestCase
{
    /**
     * 이 시험이 쓰는 게시판 슬러그를 돌려준다.
     *
     * @return string 게시판 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'preview-cache';
    }

    /**
     * 첨부 업로드를 켜고 파일 이름 축을 재려고 텍스트 확장자까지 허용한 게시판 속성을 돌려준다.
     *
     * @param  string  $slug  게시판 슬러그
     * @return array<string, mixed> 게시판 생성 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '미리보기 캐시 계약 테스트', 'en' => 'Preview Cache Contract'],
            'is_active' => true,
            'use_file_upload' => true,
            // 파일 이름 선언(따옴표·RFC 5987) 축은 **텍스트 파일**로 잰다 — 이미지로는
            // 원문 이름이 헤더에 실리는 형태를 재현할 수 없다. 그런데 팩토리 기본값
            // (`BoardFactory`)의 확장자 목록에는 `txt` 가 없어(`jpg,jpeg,png,gif,pdf,zip`)
            // 업로드가 `mimes:` 에서 422 로 막힌다 — 모듈 기본값에는 `txt` 가 있지만
            // 게시판 행의 명시 목록이 우선하기 때문이다. 그래서 이 게시판만 목록을
            // 넓힌다(프로덕션 기본값을 바꾸는 것이 아니다).
            'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'zip', 'txt'],
        ];
    }

    /**
     * 공개 디스크를 가짜로 바꾸고 비회원에게 읽기, 쓰기, 첨부 업로드 권한을 준다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->setGuestPermissions(['posts.read', 'posts.write', 'attachments.upload']);
    }

    /**
     * 이미지 첨부를 업로드하고 그 행을 돌려줍니다.
     *
     * @return object 업로드된 첨부 행
     */
    private function uploadImageAttachment(): object
    {
        // 기존 글에 붙이는 업로드는 그 글을 고칠 수 있는 사람만 — 비회원 글은 검증 토큰을 싣는다(#883)
        [$postId, $uploadHeaders] = $this->createGuestPostWithUploadToken([
            'title' => '미리보기 캐시 계약',
            'status' => 'published',
        ]);

        $file = UploadedFile::fake()->image('photo.jpg', 40, 40);

        $response = $this->postJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/attachments",
            ['file' => $file, 'post_id' => $postId],
            $uploadHeaders
        );

        $response->assertStatus(201);

        $row = DB::table('board_attachments')->orderByDesc('id')->first();
        $this->assertNotNull($row);

        return $row;
    }

    /**
     * 미리보기 URL
     *
     * @param  string  $hash  첨부 해시
     * @return string 미리보기 URL
     */
    private function previewUrl(string $hash): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->board->slug}/attachment/{$hash}/preview";
    }

    /**
     * 미리보기 응답은 행 메타 기준 `Last-Modified` 를 내보낸다.
     *
     * @scenario serving_type=preview, contract_axis=conditional_request
     *
     * @effects preview_emits_last_modified_from_row_meta
     *
     * @return void 반환값 없음
     */
    public function test_preview_emits_last_modified_from_row_meta(): void
    {
        $row = $this->uploadImageAttachment();

        $response = $this->get($this->previewUrl($row->hash));

        $response->assertStatus(200);

        $expected = gmdate('D, d M Y H:i:s', strtotime($row->updated_at)).' GMT';
        $this->assertSame(
            $expected,
            $response->headers->get('Last-Modified'),
            '미리보기 응답은 행 메타(updated_at) 기준 Last-Modified 를 내보내야 합니다'
        );
    }

    /**
     * `If-Modified-Since` 만 보내는 클라이언트도 304 로 재검증된다.
     *
     * `Last-Modified` 가 없으면 이 왕복은 영원히 전문 200 이다.
     *
     * @scenario serving_type=preview, contract_axis=conditional_request
     *
     * @effects preview_answers_if_modified_since_with_304_carrying_the_cache_headers_of_200
     *
     * @return void 반환값 없음
     */
    public function test_preview_answers_if_modified_since_with_304(): void
    {
        $row = $this->uploadImageAttachment();

        $since = gmdate('D, d M Y H:i:s', strtotime($row->updated_at)).' GMT';

        // `Expires` 는 응답 시각(PHP time())에 max-age 를 더한 값이라 Carbon 시간 고정이 듣지 않는다.
        // 두 요청이 초 경계를 넘으면 같은 계약인데도 1초 차이로 갈린다 — 다음 초가 시작될 때 두 요청을 보낸다.
        time_sleep_until(floor(microtime(true)) + 1);

        $full = $this->get($this->previewUrl($row->hash));
        $full->assertStatus(200);

        $response = $this->get($this->previewUrl($row->hash), ['If-Modified-Since' => $since]);

        $response->assertStatus(304);

        // 304 는 200 이 실었을 헤더를 함께 싣는다 (RFC 9110 §15.4.5).
        //
        // 종전에는 이 라우트에서 상태코드만 봤다 — 형제 코어 통로
        // (`StreamedFileConditionalRequestTest`)는 200·304 의 캐시 축 동일성을
        // 단언하는데 이 통로만 그 축이 비어 있었다.
        foreach (['Cache-Control', 'Expires', 'Last-Modified', 'ETag'] as $header) {
            $this->assertSame(
                $full->headers->get($header),
                $response->headers->get($header),
                "304 가 200 과 다른 {$header} 를 광고한다 — 공유 캐시가 잘못된 근거로 항목을 갱신한다",
            );
        }
    }

    /**
     * 선언한 `Content-Length` 는 실제로 흘려보내는 바이트 수와 일치한다.
     *
     * 행 메타(size)로 선지정하므로 그 값이 저장된 바이트와 어긋나면 응답이 잘린다.
     *
     * @scenario serving_type=preview, contract_axis=content_length
     *
     * @effects preview_content_length_matches_streamed_bytes
     *
     * @return void 반환값 없음
     */
    public function test_preview_content_length_matches_streamed_bytes(): void
    {
        $row = $this->uploadImageAttachment();

        $response = $this->get($this->previewUrl($row->hash));

        $response->assertStatus(200);

        $body = $response->streamedContent();

        $this->assertSame((int) $row->size, strlen($body), '행 size 는 실제 저장 바이트와 같아야 합니다');
        $this->assertSame(
            (string) strlen($body),
            $response->headers->get('Content-Length'),
            '선언한 Content-Length 는 실제 전송 바이트와 같아야 합니다'
        );
    }

    /**
     * **축소 경로를 지난** 이미지에서도 선언한 크기가 실제 바이트와 같다.
     *
     * 위 케이스는 40×40 픽스처라 상한이 없는 기본 설정에서 `ImageResizer` 가 즉시
     * 반환한다 — 즉 축소 경로를 한 번도 지나지 않았다. 그런데 축소는 **제자리
     * 덮어쓰기**라 그 뒤에 읽는 크기가 PHP stat 캐시의 축소 전 값일 수 있고, 그러면
     * 행의 `size` 가 실제보다 커져 `Content-Length` 선언이 응답을 자른다.
     *
     * 「적용 전에 실측했다」고 적힌 축이 이것인데 코드로 고정된 것이 없었다.
     *
     * @scenario serving_type=preview, contract_axis=content_length
     *
     * @effects content_length_matches_after_the_resize_path
     *
     * @return void 반환값 없음
     */
    public function test_content_length_matches_after_the_resize_path(): void
    {
        config([
            'attachment.image_max_width' => 32,
            'attachment.image_max_height' => 32,
        ]);

        [$postId, $uploadHeaders] = $this->createGuestPostWithUploadToken(['title' => '축소 경로', 'status' => 'published']);

        // 상한을 넘는 크기 — 축소가 실제로 일어난다.
        $file = UploadedFile::fake()->image('big.jpg', 200, 200);

        $upload = $this->postJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/attachments",
            ['file' => $file, 'post_id' => $postId],
            $uploadHeaders
        );
        $upload->assertStatus(201);

        $row = DB::table('board_attachments')->orderByDesc('id')->first();

        // 축소가 **실제로 일어났는지** 먼저 확인한다 — 일어나지 않았다면 이 케이스는
        // 위 40×40 케이스의 사본일 뿐이고, 재려던 축을 재지 않는다.
        $meta = json_decode((string) $row->meta, true);
        $this->assertIsArray($meta);
        $this->assertLessThanOrEqual(32, $meta['width'], '축소가 일어나지 않았다 — 이 케이스가 축소 경로를 재지 못한다');
        $this->assertLessThanOrEqual(32, $meta['height']);

        $response = $this->get($this->previewUrl($row->hash));
        $response->assertStatus(200);

        $body = $response->streamedContent();

        $this->assertSame(
            (int) $row->size,
            strlen($body),
            '축소 후 기록한 size 가 실제 저장 바이트와 다르다 — Content-Length 선언이 응답을 자른다',
        );
        $this->assertSame((string) strlen($body), $response->headers->get('Content-Length'));
    }

    /**
     * **다운로드 성공 경로**를 실제로 태운다.
     *
     * 형제 `preview()` 가 반환 타입 union 결손으로 실파일 요청에서 전부 500 이던 것이
     * 초록으로 남았던 이유는 「그 경로를 성공까지 태우는 테스트가 하나도 없어서」였다.
     * 같은 조건이 `download()` 에 그대로 남아 있었다 — 그 메서드의 200 을 태우는
     * 테스트가 저장소에 0건이었다.
     *
     * @scenario serving_type=download, contract_axis=content_length
     *
     * @effects download_streams_the_stored_bytes
     *
     * @return void 반환값 없음
     */
    public function test_download_streams_the_stored_bytes(): void
    {
        $this->setGuestPermissions([
            'posts.read', 'posts.write', 'attachments.upload', 'attachments.download',
        ]);

        $row = $this->uploadImageAttachment();

        $response = $this->get(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/attachment/{$row->hash}"
        );

        $response->assertStatus(200);
        $this->assertSame((int) $row->size, strlen($response->streamedContent()));
    }

    /**
     * 다운로드의 파일 이름 선언은 **스토리지 단일 지점**이 만든다.
     *
     * 원문 파일명을 헤더로 직접 넘기면 Flysystem 어댑터가 자기 `makeDisposition()` 을
     * 통째로 건너뛴다 — 이름에 따옴표가 들어간 업로드에서 헤더 구조가 깨지고, 같은
     * 파일이 통로마다 다른 이름으로 저장된다. 형제 `preview()` 는 먼저 위임됐는데
     * 이 메서드만 남아 있었다.
     *
     * @scenario serving_type=download, contract_axis=filename_declaration
     *
     * @effects download_disposition_is_built_by_the_storage_layer
     *
     * @return void 반환값 없음
     */
    public function test_download_disposition_is_built_by_the_storage_layer(): void
    {
        $this->setGuestPermissions([
            'posts.read', 'posts.write', 'attachments.upload', 'attachments.download',
        ]);

        [$postId, $uploadHeaders] = $this->createGuestPostWithUploadToken(['title' => '따옴표 파일명', 'status' => 'published']);

        // 따옴표가 든 이름 — `getClientOriginalName()` 은 basename 만 하고 따옴표를 지우지 않는다.
        $file = UploadedFile::fake()->createWithContent('a";x.txt', 'hello');

        $upload = $this->postJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/attachments",
            ['file' => $file, 'post_id' => $postId],
            $uploadHeaders
        );
        $upload->assertStatus(201);

        $row = DB::table('board_attachments')->orderByDesc('id')->first();

        $response = $this->get(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/attachment/{$row->hash}"
        );

        $response->assertStatus(200);

        $disposition = (string) $response->headers->get('Content-Disposition');

        $this->assertStringStartsWith('attachment; ', $disposition);

        // 판정 기준은 **선언된 이름이 원문과 같은가**다.
        //
        // 어댑터가 만들면 안쪽 따옴표가 `\"` 로 이스케이프돼 값이 끝까지 이어진다
        // (`filename="a\";x.txt"`). 원문을 헤더로 직접 넘기면 그 이스케이프가 없어
        // 값이 첫 따옴표에서 끊기고(`filename="a"`) 나머지가 헤더 문법으로 새어 나간다
        // — 브라우저가 저장하는 이름이 원문과 달라진다.
        $this->assertSame(
            1,
            preg_match('/filename="((?:[^"\\\\]|\\\\.)*)"/', $disposition, $m),
            "Content-Disposition 에 따옴표로 감싼 filename 이 없다: {$disposition}",
        );

        $this->assertSame(
            $row->original_filename,
            stripcslashes($m[1]),
            "선언된 파일 이름이 원문과 다르다 — 어댑터의 정제 지점을 건너뛴 상태다: {$disposition}",
        );
    }

    /**
     * 비-ASCII 파일명은 RFC 5987 확장 파라미터로 **원문 그대로** 선언된다.
     *
     * `filename=` 값은 ASCII 폴백이라 한글 이름을 담지 못한다. 원문을 나르는 것은
     * `filename*=utf-8''…` 뿐이고, 그 인코딩은 스토리지 어댑터의 `makeDisposition()`
     * 단일 지점이 만든다. 이 축이 사라지면 브라우저가 저장하는 이름이 음역·물음표로
     * 바뀌는데 응답은 정상 200 이라 조용하다.
     *
     * 회차 10 이 이 축의 단언 2건을 mock 기반 유닛 테스트에서 삭제하면서 대체를 넣지
     * 않아 저장소 전체에서 이 축을 재는 곳이 0 이 됐다 — 그 두 건은 스토리지가
     * mock 이라 실제 헤더를 볼 수 없었으므로, 대체는 응답 헤더를 직접 재는 이 자리다
     * 따옴표 축 형제 케이스와 같은 형태로 둔다.
     *
     * @scenario serving_type=download, contract_axis=filename_declaration
     *
     * @effects download_declares_non_ascii_filename_via_rfc5987
     *
     * @return void 반환값 없음
     */
    public function test_download_declares_non_ascii_filename_via_rfc5987(): void
    {
        $this->setGuestPermissions([
            'posts.read', 'posts.write', 'attachments.upload', 'attachments.download',
        ]);

        [$postId, $uploadHeaders] = $this->createGuestPostWithUploadToken(['title' => '한글 파일명', 'status' => 'published']);

        $originalName = '문서 파일.txt';
        $file = UploadedFile::fake()->createWithContent($originalName, 'hello');

        $upload = $this->postJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/attachments",
            ['file' => $file, 'post_id' => $postId],
            $uploadHeaders
        );
        $upload->assertStatus(201);

        $row = DB::table('board_attachments')->orderByDesc('id')->first();
        $this->assertSame($originalName, $row->original_filename);

        $response = $this->get(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/attachment/{$row->hash}"
        );

        $response->assertStatus(200);

        $disposition = (string) $response->headers->get('Content-Disposition');

        // 토큰 이름(`utf-8`)의 대소문자는 규격상 자유롭다 — 판정은 값에 건다.
        $this->assertSame(
            1,
            preg_match("/filename\*=utf-8''([^;]+)/i", $disposition, $m),
            "Content-Disposition 에 RFC 5987 확장 파라미터가 없다 — 한글 이름을 나를 통로가 사라졌다: {$disposition}",
        );

        $this->assertSame(
            $row->original_filename,
            rawurldecode(trim($m[1])),
            "RFC 5987 값이 원문과 다르다 — 브라우저가 저장하는 이름이 바뀐다: {$disposition}",
        );
    }
}
