<?php

namespace Tests\Feature\Api\Public;

use App\Http\Middleware\GzipEncodeResponse;
use App\Services\PublicStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

/**
 * 공개 스토리지 읽기 전용 서빙 라우트(B-3) 계약 테스트.
 *
 * `public/storage` 링크 불가 호스팅의 대체 통로가 실제로 대체재인지 — 두 URL 형태(R33)가
 * 같은 파일을 주고, 경로 순회·심볼릭 링크 경유가 거부되며, 응답이 스트리밍인지 검증한다.
 *
 * @scenario url_form=path_segment, target=regular_file, method=get_only
 */
class PublicStorageServeTest extends TestCase
{
    use RefreshDatabase;

    private string $fixtureDir;

    /**
     * 공개 스토리지 아래에 픽스처 디렉터리를 만들고 작은 텍스트 파일과 압축 하한(1KB)을 넘는 텍스트 파일을 둔다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureDir = storage_path('app/public/g7-test-public-storage');
        if (! is_dir($this->fixtureDir)) {
            mkdir($this->fixtureDir, 0755, true);
        }
        file_put_contents($this->fixtureDir.'/sample.txt', 'g7 public storage fixture');

        // 압축 축을 재는 케이스용 — gzip 미들웨어의 최소 크기(1KB) 를 넘겨야 압축 분기에
        // 도달한다. 작은 파일만 두면 「압축되지 않았다」가 크기 때문인지 거부 때문인지
        // 구분되지 않아, 거부 배선을 지워도 초록인 게이트가 된다.
        file_put_contents($this->fixtureDir.'/large.txt', str_repeat('g7 public storage compressible fixture payload. ', 200));
    }

    /**
     * 픽스처 디렉터리 안의 파일과 링크를 지우고 디렉터리를 걷어낸다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach (glob($this->fixtureDir.'/*') ?: [] as $file) {
            if (is_link($file) || is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->fixtureDir);

        parent::tearDown();
    }

    /**
     * 경로 세그먼트 형태와 `?file=` 쿼리 형태가 같은 파일 본문을 내주는지 확인한다.
     *
     * @scenario asset_url_mode=extension, url_form=path_segment
     *
     * @effects public_storage_route_serves_both_url_forms
     *
     * @return void 반환값 없음
     */
    public function test_serves_file_in_both_url_forms(): void
    {
        $segment = $this->get('/api/system/public-storage/g7-test-public-storage/sample.txt');
        $segment->assertOk();
        $this->assertSame('g7 public storage fixture', $segment->streamedContent());

        $query = $this->get('/api/system/public-storage?file='.rawurlencode('g7-test-public-storage/sample.txt'));
        $query->assertOk();
        $this->assertSame('g7 public storage fixture', $query->streamedContent(), '확장자 없는 형태(?file=)도 같은 파일을 서빙해야 한다 (R33)');
    }

    /**
     * HEAD 와 부분 응답(206)의 계약을 이 라우트 자신이 잰다.
     *
     * 이 축은 종전에 **형제 라우트**(플러그인 번들)에서만 측정됐다. 이후 검토가 이
     * 라우트를 압축에서 명시적으로 빼면서 그 형제 검증은 더 이상 이 라우트를 대표하지
     * 않게 됐는데(압축 경로를 타지 않으므로 응답을 만드는 코드가 다르다), 그 사실이 같은
     * 라운드 안에서 재평가되지 않았다.
     *
     * @scenario url_form=path_segment, range_request=head
     *
     * @effects public_storage_route_honours_head_and_range_like_static_serving
     *
     * @return void 반환값 없음
     */
    public function test_head_and_range_match_the_get_representation(): void
    {
        $url = '/api/system/public-storage/g7-test-public-storage/sample.txt';

        $get = $this->get($url);
        $get->assertOk();

        $head = $this->call('HEAD', $url);
        $head->assertOk();

        // 본문 유무는 Symfony 가 HEAD 에서 처리하므로 여기서 재지 않는다(스트리밍 응답은
        // GET 에서도 `getContent()` 가 버퍼를 돌려주지 않는다). 이 라우트가 소유하는 것은
        // **두 응답이 같은 표현을 광고하는가** 다.
        $this->assertSame(
            $get->headers->get('ETag'),
            $head->headers->get('ETag'),
            'HEAD 로 검증자를 미리 재는 클라이언트가 GET 과 다른 값을 보면 안 된다'
        );
        $this->assertSame(
            $get->headers->get('Content-Type'),
            $head->headers->get('Content-Type'),
        );

        // 압축을 타지 않으므로 GET 이 `-gzip` 접미사를 붙이지 않는다 — 두 응답이 같은
        // 표현을 광고한다(압축 경로에서 갈리던 비대칭이 이 라우트에는 없다).
        $this->assertStringNotContainsString('-gzip', (string) $get->headers->get('ETag'));

        $ranged = $this->withHeaders(['Range' => 'bytes=0-4'])->get($url);
        $ranged->assertStatus(206);
        $this->assertSame(
            'bytes 0-4/'.filesize($this->fixtureDir.'/sample.txt'),
            $ranged->headers->get('Content-Range'),
            '206 의 Content-Range 가 요청 범위·전체 크기와 어긋나면 클라이언트가 파일을 잘못 이어붙인다'
        );
        $this->assertSame('5', $ranged->headers->get('Content-Length'), '부분 응답의 길이는 요청 범위만큼이다');
        $this->assertSame('bytes', $get->headers->get('Accept-Ranges'), '범위 요청을 받는 응답은 그 사실을 광고한다');
    }

    /**
     * 공개 스토리지에 없는 파일을 요청하면 404 가 나오는지 확인한다.
     *
     * @scenario url_form=path_segment, conditional_request=none
     *
     * @effects public_storage_route_returns_404_for_missing_files
     *
     * @return void 반환값 없음
     */
    public function test_missing_file_returns_404(): void
    {
        $this->get('/api/system/public-storage/g7-test-public-storage/absent.txt')
            ->assertNotFound();
    }

    /**
     * 두 URL 모드가 **같은 입력에 같은 판정**을 낸다 (거부 축).
     *
     * R33 이중 모드 검증은 「같은 파일이 두 형태로 열린다」만 재고 있었다. 거부가 두
     * 형태에서 같은지는 재지 않았고, 실제로 갈려 있었다 — 쿼리 모드만 전역 트림·널 정규화를
     * 타서 `%00` 과 선행 공백이 잘렸다. 잘린 값은 **다른 파일**을 가리킬 수 있다.
     *
     * @scenario asset_url_mode=extensionless, path_shape=null_byte
     *
     * @effects public_storage_url_modes_reject_the_same_inputs
     *
     * @return void 반환값 없음
     */
    public function test_both_url_modes_reject_the_same_inputs(): void
    {
        foreach (['g7-test-public-storage/sample.txt%00', ' g7-test-public-storage/sample.txt'] as $raw) {
            $segment = $this->get('/api/system/public-storage/'.$raw);
            $query = $this->get('/api/system/public-storage?file='.rawurlencode($raw));

            $this->assertSame(
                $segment->status(),
                $query->status(),
                "두 모드가 같은 입력에 다른 판정을 낸다: {$raw}"
            );
            $this->assertNotSame(200, $query->status(), "잘린 값으로 다른 파일을 서빙하면 안 된다: {$raw}");
        }
    }

    /**
     * 두 URL 모드가 **같은 디코딩 규칙**을 쓴다 (`+` 축).
     *
     * 쿼리 모드는 `parse_str()` 로 값을 읽고 있었는데 그 함수는 폼 인코딩 규칙이라 `+` 를
     * 공백으로 되돌린다. 세그먼트 모드는 RFC 3986(`rawurldecode`)이라, 이름에 `+` 가 든
     * 파일이 실재하면 같은 파일이 세그먼트 200 / 쿼리 404 로 갈렸다. 이중 모드는 같은
     * 파일을 두 형태로 여는 장치이지 해석 규칙을 가르는 장치가 아니다.
     *
     * @scenario asset_url_mode=extensionless, url_form=path_segment
     *
     * @effects public_storage_url_modes_reject_the_same_inputs
     *
     * @return void 반환값 없음
     */
    public function test_both_url_modes_decode_the_path_with_the_same_rule(): void
    {
        file_put_contents($this->fixtureDir.'/a+b.png', 'plus');
        file_put_contents($this->fixtureDir.'/a&b.png', 'amp');

        // 세그먼트 모드 — 리터럴 `+` 는 파일 이름의 일부다.
        $segment = $this->get('/api/system/public-storage/g7-test-public-storage/a+b.png');
        $segment->assertOk();
        $this->assertSame('plus', $segment->streamedContent());

        // 쿼리 모드에 **리터럴** `+` 로 온 경우 — 종전에는 `a b.png` 로 접혀 404 였다.
        $literal = $this->get('/api/system/public-storage?file=g7-test-public-storage/a+b.png');
        $literal->assertOk();
        $this->assertSame('plus', $literal->streamedContent(), '두 모드가 같은 파일을 열어야 한다');

        // 퍼센트 인코딩 형태(서버·클라이언트가 실제로 만드는 형태)는 종전과 같다 — 회귀 축.
        foreach (['a+b.png' => 'plus', 'a&b.png' => 'amp'] as $name => $expected) {
            $encoded = $this->get('/api/system/public-storage?file='.rawurlencode('g7-test-public-storage/'.$name));
            $encoded->assertOk();
            $this->assertSame(
                $expected,
                $encoded->streamedContent(),
                "rawurlencode 로 만든 URL 은 종전대로 해석돼야 한다: {$name}"
            );
        }
    }

    /**
     * 점으로 시작하는 이름은 **두 URL 모드 모두**에서 같은 404 다.
     *
     * 판정 자체는 서비스가 경로 전 구간에 대해 수행하지만(`PublicStorageServiceTest`),
     * 그 계약이 HTTP 두 모드를 통과해 같은 응답으로 나가는지는 재고 있지 않았다. 배포
     * 레이아웃 ②의 루트 `.htaccess` 는 정적 서빙에만 걸리므로, 이 통로가 같은 규칙을 갖지
     * 않으면 링크가 없는 사이트에서만 숨김 파일이 열린다.
     *
     * @scenario asset_url_mode=extensionless, segment_position=leaf
     *
     * @effects public_storage_route_refuses_dot_files_like_the_static_defense_line
     *
     * @return void 반환값 없음
     */
    public function test_dot_files_are_refused_in_both_url_forms(): void
    {
        file_put_contents($this->fixtureDir.'/.gitignore', '*');
        mkdir($this->fixtureDir.'/.hidden', 0755, true);
        file_put_contents($this->fixtureDir.'/.hidden/inside.txt', 'hidden');

        try {
            foreach ([
                'g7-test-public-storage/.gitignore',
                'g7-test-public-storage/.hidden/inside.txt',
            ] as $relative) {
                $segment = $this->get('/api/system/public-storage/'.$relative);
                $query = $this->get('/api/system/public-storage?file='.rawurlencode($relative));

                $this->assertSame(404, $segment->status(), "숨김 경로가 열리면 정적 서빙의 방어선과 어긋난다: {$relative}");
                $this->assertSame(
                    $segment->status(),
                    $query->status(),
                    "두 모드가 같은 입력에 다른 판정을 낸다: {$relative}"
                );
            }
        } finally {
            @unlink($this->fixtureDir.'/.gitignore');
            @unlink($this->fixtureDir.'/.hidden/inside.txt');
            @rmdir($this->fixtureDir.'/.hidden');
        }
    }

    /**
     * `?file=` 에 `../` 경로 순회(평문, 인코딩된 `%2f` 형태)를 넣으면 422 로 막히는지 확인한다.
     *
     * @scenario asset_url_mode=extensionless, api_validation_client=xhr
     *
     * @effects public_storage_route_rejects_traversal_and_symlink
     *
     * @return void 반환값 없음
     */
    public function test_traversal_is_rejected(): void
    {
        // FormRequest(SafeTemplatePath) 가 경로 순회를 차단한다 — `.env` 등 루트 파일 접근 불가.
        //
        // 단언을 `assertNotSame(200, …)` 으로 두지 않는다. 그 형태는 **302 도 통과시킨다** —
        // 그리고 실제로 브라우저 요청은 302 를 받고 있었다(아래 케이스 참조).
        $plain = $this->get('/api/system/public-storage?file='.rawurlencode('../../../.env'));
        $plain->assertStatus(422);

        $encoded = $this->getJson('/api/system/public-storage?file='.rawurlencode('..%2f..%2f.env'));
        $encoded->assertStatus(422);
    }

    /**
     * 검증 실패가 **엉뚱한 외부 주소로 튕겨 보내지 않는다**.
     *
     * Laravel 기본 동작은 `expectsJson()` 이 false 이면 이전 페이지로 리다이렉트하는데, 그
     * "이전 페이지" 의 출처는 요청의 `Referer` 헤더다 — 인증 없는 자산 주소 하나가 임의
     * 외부 주소로 보내는 통로가 된다. 이 라우트에서는 그 조건이 일상적으로 성립한다:
     * `<img src="…">` 는 `Accept: image/*` 로 오므로 `expectsJson()` 이 false 다.
     *
     * 응답 형태 자체도 갈렸다 — 링크가 있는 서버의 정적 서빙은 같은 요청에 404 를 준다.
     * 같은 라우트가 `Accept` 헤더에 따라 다른 종류의 응답을 내는 상태를 없앤다.
     *
     * @scenario api_validation_client=browser
     *
     * @effects api_validation_failure_never_redirects_to_referer
     *
     * @return void 반환값 없음
     */
    public function test_browser_style_request_failure_returns_json_not_a_referer_redirect(): void
    {
        $response = $this->get(
            '/api/system/public-storage?file='.rawurlencode('../../../.env'),
            [
                'Accept' => 'image/avif,image/webp,*/*',
                'Referer' => 'https://evil.example/',
            ]
        );

        $this->assertNotSame(302, $response->getStatusCode(), '자산 요청 거부가 리다이렉트가 되면 오픈 리다이렉트다');
        $this->assertNull($response->headers->get('Location'), '거부 응답에 Location 이 실려서는 안 된다');
        $response->assertStatus(422);
        $response->assertHeader('Content-Type', 'application/json');
    }

    /**
     * 304 가 200 과 **같은 캐싱 정책**과 검증자를 싣는다.
     *
     * 프레임워크 기본 304 는 `private, must-revalidate` 이고 `Last-Modified` 를 제거한다 —
     * 200 이 `public` 인 자산이 재검증 한 번으로 비공유로 격하되고(공유 캐시·CDN 이 항목을
     * 버린다), ETag 를 쓰지 않는 프록시는 검증자를 갱신받지 못한다. 정적 서빙은 304 에도
     * 200 과 같은 헤더를 재전송한다 — 링크 유무로 응답이 갈리지 않아야 한다.
     *
     * @scenario url_form=path_segment, conditional_request=if_none_match
     *
     * @effects conditional_response_carries_the_full_cache_axis_of_200
     *
     * @return void 반환값 없음
     */
    public function test_not_modified_carries_the_same_cache_policy_and_validators_as_200(): void
    {
        $url = '/api/system/public-storage/g7-test-public-storage/sample.txt';

        $first = $this->get($url);
        $first->assertOk();

        $etag = $first->headers->get('ETag');
        $cacheControl = $first->headers->get('Cache-Control');
        $this->assertNotNull($etag);

        $second = $this->get($url, ['If-None-Match' => $etag]);
        $second->assertStatus(304);

        $this->assertSame($etag, $second->headers->get('ETag'), '304 는 검증자를 그대로 실어야 한다');
        $this->assertNotNull(
            $second->headers->get('Last-Modified'),
            '304 에서 Last-Modified 가 사라지면 If-Modified-Since 만 쓰는 프록시가 근거를 잃는다'
        );
        $this->assertSame(
            $cacheControl,
            $second->headers->get('Cache-Control'),
            '200 은 public 인데 304 가 private 이면 공유 캐시가 항목을 격하·폐기한다'
        );
    }

    /**
     * 304 가 200 과 같이 `Expires` 와 `Vary: Accept-Encoding` 을 싣는지, 200 쪽도 그 `Vary` 를 싣는지 함께 확인한다.
     *
     * @scenario url_form=path_segment, conditional_request=if_none_match
     *
     * @effects conditional_response_carries_the_full_cache_axis_of_200
     *
     * @return void 반환값 없음
     */
    public function test_not_modified_carries_the_expiry_and_variance_axes_of_200(): void
    {
        // RFC 9110 §15.4.5 는 200 에 실렸을 `Cache-Control`·`Expires` 를 304 에도 보내라고
        // 요구한다. `Vary` 도 같다 — 304 는 캐시 항목을 갱신하는 응답인데 어떤 축으로 갈린
        // 표현인지 말하지 않으면, 공유 캐시가 인코딩이 다른 표현을 하나의 재검증으로
        // 재사용할 여지가 생긴다(압축본에는 `-gzip` 접미사가 붙어 표현이 갈린다).
        $url = '/api/system/public-storage/g7-test-public-storage/sample.txt';

        $first = $this->get($url);
        $first->assertOk();
        $etag = $first->headers->get('ETag');

        $second = $this->get($url, ['If-None-Match' => $etag]);
        $second->assertStatus(304);

        $this->assertNotNull($second->headers->get('Expires'), '304 가 Expires 를 잃으면 200 과 만료 근거가 갈린다');

        // **200 쪽을 먼저 잰다.** 종전에는 304 만 단언했는데, 그러면 「200 이 싣지 않는
        // 축을 304 에만 붙인다」는 반대 방향 비대칭이 초록으로 통과한다 — 규정이 금지한
        // 바로 그 형태다. 이 통로는 압축에서 제외되지만 `Vary` 부착은 압축 판정보다
        // **앞**에 있으므로 200 도 싣는 것이 계약이다.
        $this->assertStringContainsString(
            'Accept-Encoding',
            (string) $first->headers->get('Vary'),
            '압축 대상 유형(text/plain)의 200 이 Vary 축을 싣지 않는다 — 그러면 304 의 Vary 는 근거 없는 축이 된다'
        );
        $this->assertStringContainsString(
            'Accept-Encoding',
            (string) $second->headers->get('Vary'),
            '압축 대상 유형(text/plain)의 304 는 200 과 같은 Vary 축을 실어야 한다'
        );
    }

    /**
     * gzip 을 받아들이는 클라이언트가 첫 응답의 ETag 로 재요청해도 304 가 성립하는지 확인한다.
     *
     * @scenario url_form=path_segment, conditional_request=if_none_match, encoding=gzip
     *
     * @effects public_storage_response_revalidates_instead_of_immutable
     *
     * @return void 반환값 없음
     */
    public function test_revalidation_succeeds_for_a_gzip_accepting_client(): void
    {
        // 이 통로는 **압축에서 제외**되어 있다(형제 테스트가 그 사실을 고정한다). 그래서
        // 여기서 재는 것은 「압축을 받아들이는 클라이언트에게도 304 가 성립하는가」이지
        // `-gzip` 접미사 흡수가 아니다 — 접미사가 붙지 않으므로 그 축은 엄격 비교로도
        // 통과해, 여기에 두면 「쟀다」와 「보지 않았다」가 구분되지 않는다.
        //
        // 접미사 흡수는 압축이 실제로 일어나는 통로가 소유한다:
        // `TemplateAssetServingTest::test_revalidation_succeeds_when_the_client_accepts_gzip`
        $url = '/api/system/public-storage/g7-test-public-storage/sample.txt';

        $first = $this->get($url, ['Accept-Encoding' => 'gzip, deflate']);
        $first->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertNotNull($etag);

        $second = $this->get($url, ['Accept-Encoding' => 'gzip, deflate', 'If-None-Match' => $etag]);
        $second->assertStatus(304);
    }

    /**
     * 압축 하한을 넘는 압축 대상 유형 파일도 `Content-Encoding` 없이, `-gzip` 접미사 없는 ETag 로 나가는지 확인한다.
     *
     * @scenario url_form=path_segment, encoding=gzip
     *
     * @effects public_storage_response_is_not_buffered_for_compression
     *
     * @return void 반환값 없음
     */
    public function test_public_storage_is_not_gzip_compressed(): void
    {
        // 압축 경로는 파일을 통째로 메모리에 읽는다. 이 통로는 무인증·무상한이라 그 적재가
        // 동시 요청 수만큼 곱해지고, 링크가 되는 서버의 정적 서빙은 애플리케이션 메모리를
        // 쓰지 않는다 — 두 통로가 같아야 하므로 이 라우트는 압축에서 빠진다 (제품 결정).
        // 전제: 이 파일은 압축 최소 크기를 넘고 유형도 압축 대상(text/plain)이다 —
        // 즉 거부 배선이 없으면 반드시 압축된다.
        $this->assertGreaterThan(1024, filesize($this->fixtureDir.'/large.txt'));

        $response = $this->get(
            '/api/system/public-storage/g7-test-public-storage/large.txt',
            ['Accept-Encoding' => 'gzip, deflate']
        );

        $response->assertOk();
        $this->assertNull(
            $response->headers->get('Content-Encoding'),
            '공개 스토리지 서빙은 압축하지 않는다 — 압축은 웹서버·CDN 의 몫이다'
        );
        $this->assertStringNotContainsString(
            '-gzip',
            (string) $response->headers->get('ETag'),
            '압축하지 않았으므로 표현 접미사도 붙지 않아야 한다'
        );
    }

    /**
     * 응답은 **파일 스트리밍**이다 — 본문을 메모리에 통째로 읽지 않는다 (R35).
     *
     * 두 축을 잰다. ① 컨트롤러가 돌려준 응답 클래스가 `BinaryFileResponse` 다 — 그 클래스는
     * 전송 시점에 파일을 흘려보내고 `getContent()` 가 `false` 다(본문을 들고 있지 않다).
     * ② 압축 미들웨어가 그 응답을 일반 응답으로 **치환하지 않았다** — 치환은 곧 전체 적재다.
     * 치환하지 않은 근거는 선언(`SKIP_ATTRIBUTE`)이어야 한다 — 클래스를 보고 건너뛰는 우연은
     * 미들웨어 판정이 바뀌는 순간 사라진다. 종전에는 이 효과를 미들웨어 단위 테스트가
     * 대표한다고 선언만 돼 있었고 실제 공개 스토리지 통로로는 재지 않았다.
     *
     * @scenario url_form=path_segment, target=regular_file, method=get_only
     *
     * @effects public_storage_route_streams_large_files_without_buffering, public_storage_response_is_not_buffered_for_compression
     *
     * @return void 반환값 없음
     */
    public function test_large_file_is_streamed_not_buffered(): void
    {
        $this->assertGreaterThan(1024, filesize($this->fixtureDir.'/large.txt'), '압축 최소 크기를 넘겨야 치환 분기에 도달한다');

        $response = $this->get(
            '/api/system/public-storage/g7-test-public-storage/large.txt',
            ['Accept-Encoding' => 'gzip, deflate']
        );

        $response->assertOk();
        $this->assertInstanceOf(
            BinaryFileResponse::class,
            $response->baseResponse,
            '공개 스토리지 응답이 파일 스트리밍 클래스가 아니다 — 본문이 메모리에 적재된 형태다'
        );
        $this->assertFalse($response->baseResponse->getContent(), 'BinaryFileResponse 는 본문을 들고 있지 않아야 한다');
        $this->assertSame(
            realpath($this->fixtureDir.'/large.txt'),
            realpath($response->baseResponse->getFile()->getPathname()),
            '스트리밍 대상이 요청한 파일이 아니다'
        );
        $this->assertTrue(
            $this->app['request']->attributes->get(GzipEncodeResponse::SKIP_ATTRIBUTE),
            '압축 제외가 선언되지 않았다 — 치환을 피한 것이 응답 클래스를 보고 건너뛴 우연이다'
        );
    }

    /**
     * 경로 세그먼트 형태와 `?file=` 형태 모두 `Content-Disposition` 에 inline 과 실제 파일 이름을 싣는지 확인한다.
     *
     * @scenario asset_url_mode=extensionless, url_form=path_segment
     *
     * @effects asset_response_declares_the_file_name
     *
     * @return void 반환값 없음
     */
    public function test_file_name_is_declared_in_both_url_forms(): void
    {
        // 무확장자 모드는 URL 마지막 세그먼트가 `public-storage` 라, 이름을 명시하지 않으면
        // 내려받기가 그 이름으로 저장된다 — 같은 파일이 자산 URL 모드에 따라 다른 이름으로
        // 저장되고 정적 서빙과도 다르다.
        foreach ([
            '/api/system/public-storage/g7-test-public-storage/sample.txt',
            '/api/system/public-storage?file='.rawurlencode('g7-test-public-storage/sample.txt'),
        ] as $url) {
            $disposition = (string) $this->get($url)->headers->get('Content-Disposition');

            $this->assertStringContainsString('inline', $disposition, "{$url}: 이 통로는 화면에 그리는 자리라 inline 이어야 한다");
            $this->assertStringContainsString('sample.txt', $disposition, "{$url}: 두 URL 형태가 같은 파일 이름을 선언해야 한다");
        }
    }

    /**
     * 공개 스토리지 안의 심볼릭 링크가 비공개 파일을 가리켜도 따라가지 않고 404 를 주는지 확인한다(링크 생성 불가 환경은 건너뜀).
     *
     * @scenario url_form=path_segment, link_kind=symlink
     *
     * @effects public_storage_route_rejects_traversal_and_symlink
     *
     * @return void 반환값 없음
     */
    public function test_symlink_inside_public_storage_is_not_followed(): void
    {
        $secret = storage_path('app/private/g7-test-secret.txt');
        if (! is_dir(dirname($secret))) {
            mkdir(dirname($secret), 0755, true);
        }
        file_put_contents($secret, 'secret');

        $link = $this->fixtureDir.'/leak.txt';
        if (! @symlink($secret, $link)) {
            @unlink($secret);
            $this->markTestSkipped('symlink 생성 불가 환경(Windows SeCreateSymbolicLink 권한 부재) — 링크 비추적 계약은 PublicStorageService 의 is_link 가드가 담당하며 POSIX 환경 실행에서 검증된다.');
        }

        try {
            // 링크 경유는 대상이 어디를 가리키든 거부한다 (B-3 — 심볼릭 링크 비추적).
            //
            // 거부 코드는 **404** 다. 이 파일이 스스로 세운 기준이 「403 이 『존재한다』를
            // 뜻하게 되면 파일 존재 여부가 샌다」이고, `..`·점 세그먼트·realpath 실패
            // 세 분기가 그 사유로 이미 404 를 고른다. 링크 분기만 403 이면 무인증
            // 프로버가 응답 코드로 「그 경로에 링크가 있다」를 알아낼 수 있고, 링크가
            // 되는 서버의 정적 서빙은 같은 입력에 200 을 주므로 배치별로 세 갈래가 된다.
            $this->get('/api/system/public-storage/g7-test-public-storage/leak.txt')
                ->assertNotFound();
        } finally {
            @unlink($link);
            @unlink($secret);
        }
    }

    /**
     * 이 통로의 URL 에는 캐시 무효화 버전이 없다 — 그래서 `immutable` 을 붙이면 안 된다.
     *
     * 확장 자산 URL 은 `?v=` 로 버전이 바뀌므로 `immutable` 이 맞지만, 공개 스토리지는
     * 같은 경로의 내용이 교체된다(첨부 이미지 재업로드 등). `immutable` 은 브라우저에게
     * 만료 전까지 재확인하지 말라는 지시라, 교체된 파일이 방문자 화면에 최대 만료 시간만큼
     * 옛것으로 남는다 — 링크가 되는 서버의 정적 서빙에는 없던 성질이다.
     *
     * @scenario url_form=path_segment, conditional_request=none
     *
     * @effects public_storage_response_revalidates_instead_of_immutable
     *
     * @return void 반환값 없음
     */
    public function test_response_is_revalidated_instead_of_immutable(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $response = $this->get('/api/system/public-storage/g7-test-public-storage/sample.txt');
        $response->assertOk();

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringNotContainsString(
            'immutable',
            $cacheControl,
            '공개 스토리지 URL 에는 버전이 없다 — immutable 을 붙이면 교체한 파일이 만료 전까지 반영되지 않는다'
        );
        $this->assertStringContainsString(
            'must-revalidate',
            $cacheControl,
            '브라우저가 매번 재확인해야 교체가 즉시 반영된다 (변경이 없으면 304 라 비용은 작다)'
        );
    }

    /**
     * 재확인 계약의 나머지 절반 — 안 바뀌었으면 304, 바뀌었으면 새 본문.
     *
     * @scenario url_form=path_segment, conditional_request=if_none_match
     *
     * @effects public_storage_response_revalidates_instead_of_immutable
     *
     * @return void 반환값 없음
     */
    public function test_conditional_request_returns_304_and_replacement_is_served(): void
    {
        $first = $this->get('/api/system/public-storage/g7-test-public-storage/sample.txt');
        $first->assertOk();
        $etag = (string) $first->headers->get('ETag');
        $this->assertNotSame('', $etag);

        $this->withHeaders(['If-None-Match' => $etag])
            ->get('/api/system/public-storage/g7-test-public-storage/sample.txt')
            ->assertStatus(304);

        // 같은 경로에 다른 내용을 덮어쓴다 (첨부 재업로드와 같은 상황).
        file_put_contents($this->fixtureDir.'/sample.txt', 'g7 public storage fixture v2');
        clearstatcache(true, $this->fixtureDir.'/sample.txt');

        $second = $this->withHeaders(['If-None-Match' => $etag])
            ->get('/api/system/public-storage/g7-test-public-storage/sample.txt');
        $second->assertOk();
        $this->assertSame('g7 public storage fixture v2', $second->streamedContent());
    }

    /**
     * ETag 는 RFC 9110 §8.8.3 의 문법(큰따옴표)을 따른다.
     *
     * 정적 서빙(Apache·nginx)은 항상 따옴표를 붙인다. 따옴표 없는 값은 규격상 유효한
     * entity-tag 가 아니라 중간 프록시·CDN 이 그 헤더를 버리거나 다시 쓸 수 있다.
     *
     * @scenario url_form=path_segment, conditional_request=none
     *
     * @effects file_serving_etag_is_a_quoted_entity_tag
     *
     * @return void 반환값 없음
     */
    public function test_etag_is_a_quoted_entity_tag(): void
    {
        $etag = (string) $this->get('/api/system/public-storage/g7-test-public-storage/sample.txt')
            ->headers->get('ETag');

        $this->assertMatchesRegularExpression('/^(W\/)?"[^"]+"$/', $etag);
    }

    /**
     * `If-Modified-Since` 만 보내는 클라이언트도 304 를 받는다.
     *
     * 종전에는 `Last-Modified` 를 내보내면서 그 조건부 요청은 무시해, ETag 를 보내지 않는
     * 프록시가 매번 전문을 받아 갔다 — 링크가 되는 서버의 정적 서빙에는 없던 성질이다.
     *
     * @scenario url_form=path_segment, conditional_request=if_modified_since
     *
     * @effects conditional_response_carries_the_full_cache_axis_of_200
     *
     * @return void 반환값 없음
     */
    public function test_if_modified_since_alone_yields_304(): void
    {
        $first = $this->get('/api/system/public-storage/g7-test-public-storage/sample.txt');
        $first->assertOk();
        $lastModified = (string) $first->headers->get('Last-Modified');
        $this->assertNotSame('', $lastModified);

        $this->withHeaders(['If-Modified-Since' => $lastModified])
            ->get('/api/system/public-storage/g7-test-public-storage/sample.txt')
            ->assertStatus(304);
    }

    /**
     * 확장자를 모르는 파일은 **내용을 보지 않고** 내려받기로 나간다.
     *
     * 내용을 스니핑하면 확장자 없는 업로드물이라도 HTML 형태이면 `text/html` 로 승격되어
     * 브라우저가 페이지로 열어 실행한다. 링크가 되는 서버의 정적 서빙은 미지 유형을
     * octet-stream 으로 내보내므로, 스니핑은 이 대체 통로를 정적 서빙보다 넓게 만든다 —
     * 링크 유무에 따라 위험이 갈리면 「동등한 대체재」라는 계약이 깨진다.
     *
     * @scenario url_form=path_segment, target=regular_file
     *
     * @effects public_storage_unknown_extension_is_not_content_sniffed
     *
     * @return void 반환값 없음
     */
    public function test_unknown_extension_is_served_as_download_not_sniffed_html(): void
    {
        file_put_contents(
            $this->fixtureDir.'/payload.unknownext',
            '<html><body><script>alert(1)</script></body></html>'
        );

        $response = $this->get('/api/system/public-storage/g7-test-public-storage/payload.unknownext');
        $response->assertOk();

        $this->assertSame(
            'application/octet-stream',
            (string) $response->headers->get('Content-Type'),
            '내용 스니핑으로 text/html 이 되면 저장형 XSS 가 이 통로에서만 열린다'
        );
    }

    /**
     * 표에 있는 확장자는 종전대로 그 유형으로 나간다 (M5 의 대조군).
     *
     * 이 대조군이 없으면 「전부 octet-stream 으로 내보내는」 구현으로도 위 테스트가 초록이
     * 되고, 이미지·CSS 가 브라우저에서 렌더되지 않는 기능 축소를 아무도 잡지 못한다.
     *
     * @scenario url_form=path_segment, target=regular_file
     *
     * @effects public_storage_unknown_extension_is_not_content_sniffed, public_storage_mime_table_matches_static_serving
     *
     * @return void 반환값 없음
     */
    public function test_known_extension_keeps_its_content_type(): void
    {
        file_put_contents($this->fixtureDir.'/style.css', '.a{color:red}');

        $this->get('/api/system/public-storage/g7-test-public-storage/style.css')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/css; charset=utf-8');
    }

    /**
     * `.xhtml` 은 nginx `mime.types` 와 같은 유형으로 나간다.
     *
     * 표에 없어 octet-stream 으로 내려가면 링크가 되는 서버의 정적 서빙(`application/xhtml+xml`)
     * 과 갈린다. `.svgz` 는 표에 넣지 않는다 — 본문이 gzip 이라 `Content-Encoding: gzip` 을
     * 함께 실어야 하는데 이 통로는 그 축을 갖지 않으므로, 유형만 선언하면 브라우저가 압축
     * 바이트를 SVG 로 파싱해 깨진다. octet-stream(내려받기)이 그보다 낫다.
     *
     * @scenario url_form=path_segment, target=regular_file
     *
     * @effects public_storage_mime_table_matches_static_serving
     *
     * @return void 반환값 없음
     */
    public function test_xhtml_extension_matches_the_static_mime_table(): void
    {
        file_put_contents($this->fixtureDir.'/page.xhtml', '<html xmlns="http://www.w3.org/1999/xhtml"/>');

        $this->get('/api/system/public-storage/g7-test-public-storage/page.xhtml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xhtml+xml');
    }

    /**
     * 200·304 모두 `X-Content-Type-Options: nosniff` 를 싣는다.
     *
     * 업로드된 SVG·HTML 이 앱 오리진에서 실행되는 축이다 — 정적 서빙 설정은 그 헤더를 붙이므로
     * 이 통로만 빠지면 링크 유무로 위험이 갈린다. 헬퍼 단일 지점이 붙이고, 304 는 200 이
     * 실었을 헤더를 함께 싣는다.
     *
     * @scenario url_form=path_segment, conditional_request=if_none_match
     *
     * @effects file_serving_response_declares_nosniff
     *
     * @return void 반환값 없음
     */
    public function test_full_and_not_modified_responses_declare_nosniff(): void
    {
        $url = '/api/system/public-storage/g7-test-public-storage/sample.txt';

        $first = $this->get($url);
        $first->assertOk();
        $first->assertHeader('X-Content-Type-Options', 'nosniff');

        $second = $this->get($url, ['If-None-Match' => (string) $first->headers->get('ETag')]);
        $second->assertStatus(304);
        $second->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * 실재하지만 읽을 수 없는 파일은 정적 서빙과 같은 **403** 이다.
     *
     * 종전에는 존재·realpath 판정을 통과한 뒤 `BinaryFileResponse` 가 `File must be readable`
     * 로 던져 **무인증 표면이 500 + 스택 로그**였다. 권한 판정은 서비스가 분리해 돌려주고
     * (`PublicStorageServiceTest` 가 그 판정을 잠근다 — Windows 에서는 `chmod` 로 재현할 수
     * 없다), 여기서는 그 결과가 403 으로 나가는 배선을 잰다.
     *
     * @scenario url_form=path_segment, target=regular_file
     *
     * @effects public_storage_unreadable_file_is_forbidden_like_static_serving
     *
     * @return void 반환값 없음
     */
    public function test_unreadable_file_is_forbidden_not_a_server_error(): void
    {
        $service = \Mockery::mock(PublicStorageService::class);
        $service->shouldReceive('getFilePath')->once()->andReturn([
            'success' => false,
            'filePath' => null,
            'mimeType' => null,
            'error' => 'file_not_readable',
        ]);
        $this->app->instance(PublicStorageService::class, $service);

        $this->get('/api/system/public-storage/g7-test-public-storage/sample.txt')
            ->assertStatus(403);
    }

    /**
     * 실행 가능한 문서 유형(html·xhtml·svg·xml)은 내려받기 + nosniff 로 나가고, 래스터 이미지와 그 밖의 유형은 inline 그대로인지 확인한다.
     *
     * 이 통로가 내주는 `storage/app/public` 은 업로드물이 놓이는 자리다 — 회원 아바타(`User::avatar`
     * 가 public 디스크에 쓴다)와 public 디스크를 고른 확장의 업로드가 그렇다. 그래서 규정
     * (`InlineMediaPolicy::harden()`)의 대상이고, 종전에는 운영자·회원이 올린 `.html`·`.svg` 가
     * 앱 오리진에서 `text/html`·`image/svg+xml` inline 으로 열려 그 안의 스크립트가 사이트 권한으로 돌았다.
     * 보정은 실행 가능한 문서 유형으로 좁힌다 — 이 통로는 링크가 되는 서버의 정적 서빙을 대신하는
     * 자리라 텍스트·문서·미디어는 inline 이 기존 계약이다(`test_file_name_is_declared_in_both_url_forms`).
     *
     * @scenario url_form=path_segment, target=regular_file
     *
     * @effects public_storage_active_documents_are_downloaded_not_rendered
     *
     * @return void 반환값 없음
     */
    public function test_active_documents_are_downloaded_and_raster_images_stay_inline(): void
    {
        file_put_contents($this->fixtureDir.'/page.html', '<script>alert(document.domain)</script>');
        file_put_contents($this->fixtureDir.'/page.xhtml', '<html xmlns="http://www.w3.org/1999/xhtml"><script>alert(1)</script></html>');
        file_put_contents($this->fixtureDir.'/icon.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        file_put_contents($this->fixtureDir.'/feed.xml', '<x:html xmlns:x="http://www.w3.org/1999/xhtml"><x:script>alert(1)</x:script></x:html>');
        file_put_contents($this->fixtureDir.'/pixel.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

        foreach (['page.html', 'page.xhtml', 'icon.svg', 'feed.xml'] as $name) {
            $response = $this->get('/api/system/public-storage/g7-test-public-storage/'.$name);

            $response->assertOk();
            $response->assertHeader('X-Content-Type-Options', 'nosniff');
            $disposition = (string) $response->headers->get('Content-Disposition');
            $this->assertStringStartsWith('attachment', $disposition, "{$name}: 실행 가능한 문서가 앱 오리진에서 inline 으로 열린다");
            $this->assertStringContainsString($name, $disposition, "{$name}: 내려받기 이름이 실제 파일 이름이어야 한다");
        }

        $png = $this->get('/api/system/public-storage/g7-test-public-storage/pixel.png');
        $png->assertOk();
        $png->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('inline', (string) $png->headers->get('Content-Disposition'), '래스터 이미지는 화면에 그려야 한다');
    }

    /**
     * 웹서버 파일 위임을 켜도 활성 문서는 내려받기다 — 위임 헤더와 `attachment` 가 함께 나가고 본문은 웹서버 몫이다.
     *
     * 이 통로의 실행 가능한 문서 보정(`InlineMediaPolicy::harden`)은 응답 헤더라 위임과 무관하게 실려야 한다.
     * 웹서버가 위임 응답의 헤더를 어떻게 전달하는지는 문서(docs/backend/file-serving-delegation.md)가 다룬다.
     *
     * @scenario case=public_storage_attachment
     *
     * @effects public_storage_delegation_keeps_attachment_disposition
     *
     * @return void 반환값 없음
     */
    public function test_delegated_active_document_is_still_an_attachment(): void
    {
        config(['core.file_serving.delegation' => 'x-sendfile']);
        file_put_contents($this->fixtureDir.'/page.html', '<script>alert(document.domain)</script>');

        $response = $this->get('/api/system/public-storage/g7-test-public-storage/page.html');

        $response->assertOk();
        $this->assertSame(realpath($this->fixtureDir.'/page.html'), $response->headers->get('X-Sendfile'));
        $this->assertStringStartsWith('attachment', (string) $response->headers->get('Content-Disposition'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertNull($response->headers->get('Content-Encoding'), '위임 응답은 앱 gzip 에서 빠진다');

        ob_start();
        $response->baseResponse->sendContent();
        $this->assertSame('', (string) ob_get_clean(), '본문은 웹서버가 보낸다');
    }

    /**
     * 웹서버 파일 위임을 켜도 심볼릭 링크 경유는 종전처럼 404 다 — 판정이 응답 생성보다 앞이다(링크 생성 불가 환경은 건너뜀).
     *
     * @scenario case=public_storage_symlink
     *
     * @effects public_storage_symlink_refusal_survives_delegation
     *
     * @return void 반환값 없음
     */
    public function test_symlink_refusal_survives_delegation(): void
    {
        config(['core.file_serving.delegation' => 'x-sendfile']);

        $secret = storage_path('app/private/g7-test-delegation-secret.txt');
        if (! is_dir(dirname($secret))) {
            mkdir(dirname($secret), 0755, true);
        }
        file_put_contents($secret, 'secret');

        $link = $this->fixtureDir.'/delegated-leak.txt';
        if (! @symlink($secret, $link)) {
            @unlink($secret);
            $this->markTestSkipped('symlink 생성 불가 환경(Windows SeCreateSymbolicLink 권한 부재) — 링크 비추적 계약은 PublicStorageService 의 is_link 가드가 담당하며 POSIX 환경 실행에서 검증된다.');
        }

        try {
            $response = $this->get('/api/system/public-storage/g7-test-public-storage/delegated-leak.txt');

            $response->assertNotFound();
            $this->assertNull($response->headers->get('X-Sendfile'), '위임 헤더로 비공개 파일 경로가 웹서버에 넘어가면 안 된다');
        } finally {
            @unlink($link);
            @unlink($secret);
        }
    }
}
