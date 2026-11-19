<?php

namespace Tests\Feature\Api\Public;

use App\Enums\ExtensionStatus;
use App\Http\Responses\NotModifiedResponse;
use App\Models\Template;
use App\Models\TemplateLayoutAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 스토리지 스트림 서빙 경로(`streamedFileResponse`)의 조건부 요청 계약 테스트.
 *
 * 형제 경로(`fileResponse`)는 `Last-Modified` 를 내보내고 `If-Modified-Since` 도 처리하는데
 * 스트림 경로는 `If-None-Match` 만 보고 200·304 어느 쪽도 `Last-Modified` 를 싣지 않았다 —
 * 자기 주석이 「형제와 같은 규칙」을 선언한 자리에서 그 축만 비어 있었다. 결과는 조용하다:
 * ETag 를 쓰지 않고 시각으로만 재검증하는 프록시가 첨부·레이아웃 첨부 통로에서 **영원히
 * 전문 200** 을 받아 간다. 링크가 되는 서버의 정적 서빙에는 없는 성질이다.
 *
 * 기존 IMS 케이스(`PublicStorageServeTest`)는 `fileResponse` 경로만 재고 있어 이 축을
 * 볼 수 없었다.
 *
 * @scenario response_kind=streamed, conditional_request=none
 */
class StreamedFileConditionalRequestTest extends TestCase
{
    use RefreshDatabase;

    private const STORAGE_CATEGORY = 'template-layout-attachments';

    /**
     * 같은 스위트의 GDPR 미들웨어 의존 테스트와 migrate:fresh 정합성을 맞추기 위해
     * GDPR 플러그인 마이그레이션을 일관 선언한다.
     *
     * @var array<string>
     */
    protected array $requiredExtensions = [
        'plugins/sirsoft-gdpr',
    ];

    private Template $template;

    private TemplateLayoutAttachment $attachment;

    /**
     * 첨부 디스크를 가짜로 바꾸고 활성 템플릿 하나와 그 템플릿의 PNG 레이아웃 첨부 행(디스크에 실제 바이트 저장)을 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $disk = config('attachment.disk', 'attachments');
        Storage::fake($disk);

        $this->template = Template::create([
            'identifier' => 'sirsoft-basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본', 'en' => 'Basic'],
            'version' => '1.0.0',
            'type' => 'user',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '기본', 'en' => 'Basic'],
        ]);

        $relativePath = 'sirsoft-basic/2026/08/26/bg.png';
        Storage::disk($disk)->put(self::STORAGE_CATEGORY.'/'.$relativePath, 'streamed-fixture');

        $this->attachment = TemplateLayoutAttachment::create([
            'template_id' => $this->template->id,
            'layout_name' => 'home',
            'disk' => $disk,
            'path' => $relativePath,
            'original_name' => 'bg.png',
            'mime_type' => 'image/png',
            'size' => 16,
            'created_by' => User::factory()->create()->id,
        ]);
    }

    /**
     * 준비한 레이아웃 첨부를 스트림으로 내주는 공개 서빙 라우트 URL 을 만든다.
     *
     * @return string 레이아웃 첨부 파일 서빙 URL
     */
    private function serveUrl(): string
    {
        return route('api.public.templates.layout-attachment-file', [
            'identifier' => $this->template->identifier,
            'attachment' => $this->attachment->id,
        ]);
    }

    /**
     * 304 가 **본문 없는 전용 응답 객체**이고 200 의 캐시 축을 전부 싣는지 단언합니다.
     *
     * 상태·개별 헤더만 재면 `streamedFileResponse()` 를 「`$response->setNotModified();
     * return $response;`」 로 단순화해도 초록이다 — 그 형태는 라우터가 다시 부르는
     * `setNotModified()` 에 `Last-Modified` 를 잃고, 200 의 `Expires`·`Vary` 를 304 에
     * 싣지 않으며, 스트림 콜백을 든 채 나간다. 그래서 응답 클래스(`NotModifiedResponse` —
     * 라우터가 벗긴 `Last-Modified` 를 되살리는 유일한 형태)·빈 본문·200 과의 헤더
     * 대칭을 한 자리에서 잠근다.
     *
     * @param  TestResponse  $full  200 응답
     * @param  TestResponse  $notModified  304 응답
     * @return void 반환값 없음
     */
    private function assertNotModifiedMirrors200(TestResponse $full, TestResponse $notModified): void
    {
        $notModified->assertStatus(304);

        $this->assertInstanceOf(
            NotModifiedResponse::class,
            $notModified->baseResponse,
            '304 는 라우터가 벗기는 Last-Modified 를 되살리는 전용 응답 객체여야 한다 — 스트림 응답을 제자리에서 304 로 바꾸면 그 헤더를 잃는다'
        );
        $this->assertSame('', (string) $notModified->baseResponse->getContent(), '304 에 본문이 실렸다');

        foreach (['Last-Modified', 'Cache-Control', 'Expires'] as $header) {
            $this->assertNotNull($full->headers->get($header), "200 이 {$header} 를 싣지 않는다 — 대칭을 잴 수 없다");
            $this->assertSame(
                $full->headers->get($header),
                $notModified->headers->get($header),
                "304 가 200 과 다른 {$header} 를 광고한다 (RFC 9110 §15.4.5)"
            );
        }

        // `Vary` 는 **압축 대상 유형일 때만** 200 에 실린다(이 픽스처는 image/png 라 없다).
        // 304 에만 붙이면 반대 방향의 비대칭이므로 「있으면 같고, 없으면 둘 다 없다」로 잰다.
        $this->assertSame(
            $full->headers->get('Vary'),
            $notModified->headers->get('Vary'),
            '304 의 Vary 축이 200 과 다르다 — 공유 캐시가 어떤 축으로 갈린 표현인지 모른 채 항목을 갱신한다'
        );
    }

    /**
     * 스트림 응답의 `Last-Modified` 가 첨부 행의 `updated_at` 을 HTTP 날짜 형식으로 옮긴 값인지 확인한다.
     *
     * @scenario response_kind=streamed, conditional_request=none
     *
     * @effects streamed_file_response_declares_last_modified
     *
     * @return void 반환값 없음
     */
    public function test_streamed_response_declares_last_modified(): void
    {
        $response = $this->get($this->serveUrl());
        $response->assertOk();

        $this->assertSame(
            gmdate('D, d M Y H:i:s', (int) $this->attachment->updated_at?->getTimestamp()).' GMT',
            $response->headers->get('Last-Modified'),
            '스트림 응답도 형제 경로와 같은 검증자를 광고해야 한다'
        );
    }

    /**
     * `If-Modified-Since` 만 보내는 클라이언트도 304 를 받는다 (형제 경로와 같은 규칙).
     *
     * @scenario response_kind=streamed, conditional_request=if_modified_since
     *
     * @effects conditional_response_carries_the_full_cache_axis_of_200
     *
     * @return void 반환값 없음
     */
    public function test_if_modified_since_alone_yields_304_on_the_streamed_path(): void
    {
        $first = $this->get($this->serveUrl());
        $first->assertOk();

        $lastModified = (string) $first->headers->get('Last-Modified');
        $this->assertNotSame('', $lastModified);

        $second = $this->withHeaders(['If-Modified-Since' => $lastModified])->get($this->serveUrl());

        $this->assertNotModifiedMirrors200($first, $second);
    }

    /**
     * 판정 순서는 형제와 같다 — `If-None-Match` 가 우선이다 (RFC 9110 §13.1.2).
     *
     * 두 헤더가 함께 오고 ETag 가 어긋나면, 시각이 아무리 오래됐어도 전문을 준다.
     *
     * @scenario response_kind=streamed, conditional_request=if_none_match
     *
     * @effects if_none_match_takes_precedence_over_if_modified_since
     *
     * @return void 반환값 없음
     */
    public function test_if_none_match_takes_precedence_over_if_modified_since(): void
    {
        $first = $this->get($this->serveUrl());
        $first->assertOk();

        $stale = $this->withHeaders([
            'If-None-Match' => '"not-the-current-tag"',
            'If-Modified-Since' => (string) $first->headers->get('Last-Modified'),
        ])->get($this->serveUrl());

        $stale->assertOk();
    }

    /**
     * 첫 응답의 ETag 를 `If-None-Match` 로 되보내면 200 의 캐시 축을 그대로 실은 본문 없는 304 가 나오는지 확인한다.
     *
     * @scenario response_kind=streamed, conditional_request=if_none_match
     *
     * @effects conditional_response_carries_the_full_cache_axis_of_200
     *
     * @return void 반환값 없음
     */
    public function test_matching_etag_still_yields_304(): void
    {
        $first = $this->get($this->serveUrl());
        $first->assertOk();

        $second = $this->withHeaders(['If-None-Match' => (string) $first->headers->get('ETag')])
            ->get($this->serveUrl());

        $this->assertNotModifiedMirrors200($first, $second);
    }

    /**
     * 304 는 **클라이언트가 보유한 표현**의 검증자를 그대로 돌려준다.
     *
     * 매치 판정은 `-gzip` 접미사를 흡수하는데(형제 헬퍼 단일 지점), 응답이 흡수 전
     * identity 값을 실으면 같은 요청이 두 값을 광고하는 셈이다 — 공유 캐시가 gzip
     * 표현의 항목을 identity 검증자로 갱신하면 200 에서 표현을 갈라 놓은 이유가
     * 그 자리에서 사라진다 (RFC 9110 §8.8.1).
     *
     * 이 통로는 스스로 압축하지 않지만(스트림 응답은 압축 미들웨어가 건너뛴다),
     * 앞단 CDN·정적 서빙이 붙여 준 `-gzip` 값을 클라이언트가 되보내는 경우가 있다.
     * 형제 두 지점은 `negotiatedEtag()` 를 거치는데 이 경로만 빠져 있었다.
     *
     * @scenario response_kind=streamed, conditional_request=if_none_match, encoding=gzip
     *
     * @effects not_modified_echoes_the_validator_of_the_representation_the_client_holds
     *
     * @return void 반환값 없음
     */
    public function test_304_echoes_the_representation_the_client_holds(): void
    {
        $first = $this->get($this->serveUrl());
        $first->assertOk();

        $identity = (string) $first->headers->get('ETag');
        $this->assertNotSame('', $identity);

        // 앞단이 압축본에 붙였을 형태 — 따옴표 **안쪽** 끝에 접미사가 붙는다.
        $gzip = preg_replace('/"$/', '-gzip"', $identity);

        $second = $this->withHeaders(['If-None-Match' => $gzip])->get($this->serveUrl());

        $this->assertNotModifiedMirrors200($first, $second);
        $this->assertSame(
            $gzip,
            $second->headers->get('ETag'),
            '304 가 클라이언트 보유 표현이 아닌 identity 검증자를 돌려줬다 — 공유 캐시가 잘못된 표현을 갱신한다',
        );
    }

    /**
     * 다중 값·약한 검증자에서도 비교와 응답이 **한 지점**을 공유한다.
     *
     * 승계하는 것은 `-gzip` 접미사뿐이다 — 그것만이 **다른 표현**을 뜻하기 때문이다.
     * `W/` 는 표현이 아니라 비교 강도를 말하므로, 약한 비교로 매치돼도 서버가 가진
     * 표현은 여전히 강한 검증자다(앞서 세운 계약). 이 케이스는 그
     * 구분이 유지되는지를 잠근다.
     *
     * @scenario response_kind=streamed, conditional_request=if_none_match
     *
     * @effects not_modified_echoes_the_validator_of_the_representation_the_client_holds
     *
     * @return void 반환값 없음
     */
    public function test_304_carries_the_strong_validator_for_a_weak_multi_value_match(): void
    {
        $first = $this->get($this->serveUrl());
        $first->assertOk();

        $identity = (string) $first->headers->get('ETag');

        $second = $this->withHeaders([
            'If-None-Match' => '"other-one", W/'.$identity.', "other-two"',
        ])->get($this->serveUrl());

        $second->assertStatus(304);
        $this->assertSame($identity, $second->headers->get('ETag'));
    }

    /**
     * 다중 값 안의 압축 표현도 그대로 되돌려준다 — 위 두 케이스의 교집합.
     *
     * @scenario response_kind=streamed, conditional_request=if_none_match, encoding=gzip
     *
     * @effects not_modified_echoes_the_validator_of_the_representation_the_client_holds
     *
     * @return void 반환값 없음
     */
    public function test_304_echoes_the_gzip_candidate_from_a_multi_value_header(): void
    {
        $first = $this->get($this->serveUrl());
        $first->assertOk();

        $identity = (string) $first->headers->get('ETag');
        $gzip = preg_replace('/"$/', '-gzip"', $identity);

        $second = $this->withHeaders([
            'If-None-Match' => '"other-one", '.$gzip.', "other-two"',
        ])->get($this->serveUrl());

        $second->assertStatus(304);
        $this->assertSame($gzip, $second->headers->get('ETag'));
    }

    /**
     * 200·304 모두 `X-Content-Type-Options: nosniff` 를 싣는다.
     *
     * 실제 HTTP 왕복으로 잰다 — 라우터가 304 응답을 다시 다듬으므로 컨트롤러 반환값만 보면
     * 「붙였다」와 「나갔다」가 구분되지 않는다.
     *
     * @scenario response_kind=streamed, conditional_request=if_none_match
     *
     * @effects file_serving_response_declares_nosniff
     *
     * @return void 반환값 없음
     */
    public function test_full_and_not_modified_responses_declare_nosniff(): void
    {
        $first = $this->get($this->serveUrl());
        $first->assertOk();
        $first->assertHeader('X-Content-Type-Options', 'nosniff');

        $second = $this->withHeaders(['If-None-Match' => (string) $first->headers->get('ETag')])
            ->get($this->serveUrl());

        $this->assertNotModifiedMirrors200($first, $second);
        $second->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
