<?php

namespace Tests\Unit\Support\ApiDoc;

use App\Support\ApiDoc\ApiDocExampleSanitizer;
use App\Support\ApiDoc\ApiDocScaffolder;
use App\Support\ApiDoc\ResponseSchemaInferrer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * API 문서 실측 응답 예시의 식별정보 마스킹 테스트.
 *
 * 실측 응답에는 개발 DB 의 실제 메일 주소·설치 경로·사이트 호스트·공인 IP 가 그대로 실린다.
 * 공개 문서(JSON 예시 블록과 필드 표 샘플값)에 쓰기 전에 자리표시자로 바뀌어야 한다.
 *
 * @scenario-manifest tests/scenarios/apidoc-example-identifier-masking.yaml
 */
class ApiDocExampleSanitizerTest extends TestCase
{
    /**
     * 실측 메타를 만든다.
     *
     * @param  array<string, mixed>  $data  응답 data
     * @return array<string, mixed> 실측 메타
     */
    private function probeMeta(array $data): array
    {
        return [
            'status' => 200,
            'skipped_reason' => null,
            'base_url' => 'https://api.example.com',
            'resolved_uri' => '/api/x',
            'body' => ['success' => true, 'data' => $data, 'message' => null, 'error' => null],
        ];
    }

    /**
     * 응답 예시 블록의 실제 메일 주소는 예시 주소로 바뀌고, 예약 도메인 주소와 `@2x` 파일명은 그대로 둔다.
     *
     * @scenario surface=example_block,value_kind=email
     *
     * @effects apidoc_identifiers_masked_in_example_block, apidoc_reserved_values_kept
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 응답_예시의_실제_메일_주소는_예시_주소로_바뀌고_예시_도메인은_유지된다(): void
    {
        $block = (new ApiDocScaffolder)->responseExampleBlock(null, $this->probeMeta([
            'email' => 'jane.doe@mailbox.co.kr',
            'admin_email' => 'Ops.Team+alerts@corp-mail.net',
            'sample' => 'apidoc-sample-user@example.com',
            'logo' => 'logo@2x.png',
        ]));

        $this->assertStringNotContainsString('mailbox.co.kr', $block);
        $this->assertStringNotContainsString('corp-mail.net', $block);
        $this->assertStringContainsString('"email": "user@example.com"', $block);
        $this->assertStringContainsString('"admin_email": "user@example.com"', $block);
        $this->assertStringContainsString('"sample": "apidoc-sample-user@example.com"', $block);
        $this->assertStringContainsString('"logo": "logo@2x.png"', $block);
    }

    /**
     * 응답 예시 블록의 설치 경로(`base_path()`·`storage_path()`)는 구분자 혼용과 무관하게 자리표시자로 바뀌고, 이름만 겹치는 형제 폴더는 그대로 둔다.
     *
     * @scenario surface=example_block,value_kind=install_path
     *
     * @effects apidoc_identifiers_masked_in_example_block, apidoc_reserved_values_kept
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 응답_예시의_설치_경로는_구분자_형태와_무관하게_자리표시자로_바뀐다(): void
    {
        $base = base_path();
        $mixed = str_replace('\\', '/', $base);

        $block = (new ApiDocScaffolder)->responseExampleBlock(null, $this->probeMeta([
            'install_path' => $base,
            'log_path' => storage_path('logs'),
            'config_path' => $mixed.'/storage/app/settings',
            'sibling' => $base.'_other',
        ]));

        $this->assertStringContainsString('"install_path": "<설치 경로>"', $block);
        $this->assertStringContainsString('"config_path": "<설치 경로>/storage/app/settings"', $block);
        $this->assertMatchesRegularExpression('#"log_path": "<설치 경로>/storage[\\\\/]+logs"#', $block);
        // 같은 접두로 시작하는 다른 폴더(경계 밖)는 설치 경로가 아니다.
        $this->assertStringNotContainsString('"sibling": "<설치 경로>', $block);
    }

    /**
     * 응답 예시 블록의 APP_URL 호스트(하위 도메인·포트 표기 포함)는 예시 호스트로 바뀌고, 이름만 겹치는 다른 호스트는 그대로 둔다.
     *
     * @scenario surface=example_block,value_kind=app_url_host
     *
     * @effects apidoc_identifiers_masked_in_example_block, apidoc_reserved_values_kept
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 응답_예시의_app_url_호스트는_예시_호스트로_바뀐다(): void
    {
        config(['app.url' => 'https://dev-box.dev']);

        $block = (new ApiDocScaffolder)->responseExampleBlock(null, $this->probeMeta([
            'click_url' => 'https://dev-box.dev/admin/orders/1',
            'ws_host' => 'dev-box.dev:443',
            'cdn' => 'https://cdn.dev-box.dev/a.png',
            'other' => 'https://xdev-box.dev/',
        ]));

        $this->assertStringNotContainsString('//dev-box.dev', $block);
        $this->assertStringContainsString('"click_url": "https://example.com/admin/orders/1"', $block);
        $this->assertStringContainsString('"ws_host": "example.com:443"', $block);
        $this->assertStringContainsString('"cdn": "https://cdn.example.com/a.png"', $block);
        $this->assertStringContainsString('"other": "https://xdev-box.dev/"', $block);
    }

    /**
     * 응답 예시 블록에서 값 전체가 공인 IPv4 인 문자열은 문서용 IP 로 바뀌고, 루프백·사설 IP 와 버전 문자열은 그대로 둔다.
     *
     * @scenario surface=example_block,value_kind=public_ip
     *
     * @effects apidoc_identifiers_masked_in_example_block, apidoc_reserved_values_kept
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 응답_예시의_공인_ip_는_문서용_ip_로_바뀌고_사설_ip_는_유지된다(): void
    {
        $block = (new ApiDocScaffolder)->responseExampleBlock(null, $this->probeMeta([
            'ip_address' => '8.8.8.8',
            'loopback' => '127.0.0.1',
            'lan' => '192.168.0.10',
            'ua' => 'Mozilla/5.0 Chrome/141.0.0.0 Safari/537.36',
        ]));

        $this->assertStringContainsString('"ip_address": "203.0.113.10"', $block);
        $this->assertStringContainsString('"loopback": "127.0.0.1"', $block);
        $this->assertStringContainsString('"lan": "192.168.0.10"', $block);
        $this->assertStringContainsString('Chrome/141.0.0.0', $block);
    }

    /**
     * 필드 표 샘플값은 마스킹을 거친 body 에서 추론돼 민감 키·메일·설치 경로·APP_URL 호스트가 표에 실리지 않는다.
     *
     * @scenario surface=schema_table,value_kind=sensitive_key
     *
     * @effects apidoc_identifiers_masked_in_schema_samples
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 필드_표_샘플값도_마스킹된_body_에서_추론된다(): void
    {
        config(['app.url' => 'https://dev-box.dev']);

        $body = ['success' => true, 'data' => [
            'email' => 'jane.doe@mailbox.co.kr',
            'token' => '75|abcdefghijklmnopqrstuvwxyz0123456789ABCD',
            'install_path' => base_path(),
            'url' => 'https://dev-box.dev/admin',
        ]];

        $schema = (new ResponseSchemaInferrer)->infer((new ApiDocExampleSanitizer)->sanitize($body, ['api.local-box.dev']));
        $samples = collect($schema['fields'])->pluck('sample', 'name');

        $this->assertSame('user@example.com', $samples['email']);
        $this->assertSame('{MASKED}', $samples['token']);
        $this->assertSame('<설치 경로>', $samples['install_path']);
        $this->assertSame('https://example.com/admin', $samples['url']);
    }

    /**
     * 표 추론 경로가 넘기는 실측 base URL 호스트도 포트 표기를 남긴 채 예시 호스트로 바뀐다.
     *
     * @scenario surface=schema_table,value_kind=probe_host
     *
     * @effects apidoc_identifiers_masked_in_schema_samples
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 추가로_넘긴_실측_호스트도_예시_호스트로_바뀐다(): void
    {
        $out = (new ApiDocExampleSanitizer)->sanitize(['next' => 'http://api.local-box.dev:8000/api/x?page=2'], ['api.local-box.dev']);

        $this->assertSame('http://example.com:8000/api/x?page=2', $out['next']);
    }

    /**
     * 값 안에 JSON 으로 직렬화돼 슬래시가 이스케이프된 URL(`https:\/\/host\/…`)의 실측 호스트도 예시 호스트로 바뀐다.
     *
     * 공개 문서 필드 표의 객체 샘플은 `https:\/\/…` 꼴로 보이지만 그것은 표를 그릴 때의 직렬화 결과다.
     * 마스킹은 그 전의 값에서 일어나며, 값 자체에 이스케이프된 꼴이 들어 있어도 호스트 앞뒤 경계가 같게 잡힌다.
     *
     * @scenario surface=schema_table,value_kind=probe_host
     *
     * @effects apidoc_identifiers_masked_in_schema_samples
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 슬래시가_이스케이프된_url_의_실측_호스트도_예시_호스트로_바뀐다(): void
    {
        $out = (new ApiDocExampleSanitizer)->sanitize([
            'escaped' => 'https:\/\/api.local-box.dev\/api\/x',
            'nested' => json_encode(['first' => 'https://api.local-box.dev/api/x?page=1']),
        ], ['api.local-box.dev']);

        $this->assertSame('https:\/\/example.com\/api\/x', $out['escaped']);
        $this->assertSame('{"first":"https:\/\/example.com\/api\/x?page=1"}', $out['nested']);
    }

    /**
     * URL 안의 밑줄 호스트는 APP_URL·실측 호스트 목록에 없어도 예시 호스트로 바뀌고, 밑줄이 경로·파일명에만 있으면 그대로다.
     *
     * 개발 DB 설정값(사이트 주소 등)에 다른 개발 환경의 호스트가 남아 있으면 목록 기반 치환만으로는 문서에 그대로 실렸다.
     *
     * @scenario surface=example_block,value_kind=app_url_host
     *
     * @effects apidoc_identifiers_masked_in_example_block, apidoc_reserved_values_kept
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function url_안의_밑줄_호스트는_목록에_없어도_예시_호스트로_바뀐다(): void
    {
        $out = (new ApiDocExampleSanitizer)->sanitize([
            'site_url' => 'https://dev_box.dev',
            'escaped' => 'https:\/\/dev_box.dev\/x?p=1',
            'sub_port' => 'https://sub.dev_box.dev:8443/x',
            'path_underscore' => 'https://cdn.example.org/x_y.png',
            'file' => 'file_name.json',
        ]);

        $this->assertSame('https://example.com', $out['site_url']);
        $this->assertSame('https:\/\/example.com\/x?p=1', $out['escaped']);
        $this->assertSame('https://example.com:8443/x', $out['sub_port']);
        $this->assertSame('https://cdn.example.org/x_y.png', $out['path_underscore']);
        $this->assertSame('file_name.json', $out['file']);
    }
}
