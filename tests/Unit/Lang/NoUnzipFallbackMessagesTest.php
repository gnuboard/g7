<?php

namespace Tests\Unit\Lang;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 압축 해제 안내 문구가 이미 없앤 unzip 명령 폴백을 대안으로 안내하지 않는지 지킨다 (7.1.3).
 *
 * 7.1.3 은 항목 이름을 검사하지 않는 `unzip -o` 폴백을 없애고 PHP zip 확장(ZipArchive)을 필수로 했다.
 * 그런데 「zip 확장 또는 unzip 명령어 중 하나가 필요합니다」 같은 문구가 남으면, 운영자가 안내대로 unzip 을
 * 설치해도 업데이트는 여전히 실패한다. 모집단은 코어·번들 언어팩·번들 확장의 화면 문구 파일 전량이다.
 *
 * @scenario-manifest tests/scenarios/no-unzip-fallback-messages.yaml
 */
class NoUnzipFallbackMessagesTest extends TestCase
{
    /**
     * 화면 문구 파일(PHP 배열·JSON) 경로를 모두 모은다.
     *
     * @return array<int, string> 절대 경로 목록
     */
    private function messageFiles(): array
    {
        $root = base_path();
        $patterns = [
            'lang/*/*.php',
            'lang-packs/_bundled/*/backend/*/*.php',
            'lang-packs/_bundled/*/frontend/*.json',
            'lang-packs/_bundled/*/frontend/*/*.json',
            'lang-packs/_bundled/*/frontend/*/*/*.json',
            'templates/_bundled/*/lang/*.json',
            'templates/_bundled/*/lang/*/*/*.json',
            'modules/_bundled/*/resources/lang/*/*.php',
            'plugins/_bundled/*/resources/lang/*/*.php',
        ];

        $files = [];
        foreach ($patterns as $pattern) {
            $files = array_merge($files, glob($root.'/'.$pattern) ?: []);
        }

        return array_values(array_unique($files));
    }

    /**
     * 파일 하나의 문구를 `키 경로 => 값` 으로 펼친다.
     *
     * @param  string  $file  PHP 배열 또는 JSON 문구 파일
     * @return array<string, string> 점 표기 키 => 문구
     */
    private function flatten(string $file): array
    {
        $data = str_ends_with($file, '.php') ? require $file : json_decode((string) file_get_contents($file), true);
        $out = [];
        $walk = function ($node, string $prefix) use (&$walk, &$out): void {
            if (is_array($node)) {
                foreach ($node as $key => $value) {
                    $walk($value, $prefix === '' ? (string) $key : $prefix.'.'.$key);
                }

                return;
            }
            if (is_string($node)) {
                $out[$prefix] = $node;
            }
        };
        $walk(is_array($data) ? $data : [], '');

        return $out;
    }

    /**
     * 어떤 화면 문구도 unzip 명령을 언급하지 않는다.
     *
     * @scenario case=no_message_mentions_unzip
     *
     * @effects missing_zip_extension_message_requires_zip_extension_only
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function no_message_offers_unzip_command_as_alternative(): void
    {
        $offending = [];
        foreach ($this->messageFiles() as $file) {
            foreach ($this->flatten($file) as $key => $message) {
                if (stripos($message, 'unzip') !== false || stripos($key, 'unzip') !== false) {
                    $offending[] = str_replace(base_path().'/', '', $file).' '.$key;
                }
            }
        }

        $this->assertSame([], $offending, 'unzip 폴백은 7.1.3 에서 없어졌습니다 — zip 확장(ZipArchive) 필수로 안내하세요.');
    }

    /**
     * 어떤 화면 문구도 zip 확장이 없는 서버에 폴더 지정(--source) 수동 업데이트를 대안으로 안내하지 않는다 (7.1.4, #151).
     *
     * 7.1.4 의 코어 업데이트 사전점검은 새 코어의 필수 PHP 확장(zip 포함)이 없으면 `--source` 경로도 시작하지 않는다.
     * 「zip 이 없으면 --source 로 수동 업데이트」·「수동 업데이트 (ZIP 확장 미설치 환경)」 같은 문구가 남으면
     * 운영자가 안내대로 해도 업데이트는 막힌다.
     *
     * @scenario case=no_message_offers_source_update_without_zip
     *
     * @effects missing_zip_extension_message_requires_zip_extension_only
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function no_message_offers_manual_source_update_without_zip(): void
    {
        $zipExtension = '/zip\s*(확장|extension|拡張)|ZipArchive/iu';
        $absent = '/미설치|없는|not available|no zip|without|未インストール|がない/iu';
        $manual = '/수동|직접 다운로드|manual|download|手動|ダウンロード/iu';

        $offending = [];
        foreach ($this->messageFiles() as $file) {
            foreach ($this->flatten($file) as $key => $message) {
                if (! preg_match($zipExtension, $message)) {
                    continue;
                }
                $offersSource = str_contains($message, '--source');
                $manualForZipless = preg_match($absent, $message) && preg_match($manual, $message);
                if ($offersSource || $manualForZipless) {
                    $offending[] = str_replace(base_path().'/', '', $file).' '.$key;
                }
            }
        }

        $this->assertSame([], $offending, '7.1.4 부터 zip 확장이 없으면 --source 수동 업데이트도 사전점검에서 멈춥니다 — zip 확장 설치를 안내하세요.');
    }

    /**
     * 모집단이 비지 않았고 압축 해제 안내 문구를 실제로 담고 있다(모집단 0 방지).
     *
     * @scenario case=population_not_empty
     *
     * @effects missing_zip_extension_message_requires_zip_extension_only
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function population_contains_archive_messages(): void
    {
        $keys = [];
        foreach ($this->messageFiles() as $file) {
            $keys = array_merge($keys, array_keys($this->flatten($file)));
        }

        $this->assertContains('core_update.no_extract_method_available', $keys);
        $this->assertContains('settings.info.manual_update_desc', $keys);
    }
}
