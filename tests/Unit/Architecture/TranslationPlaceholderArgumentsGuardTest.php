<?php

namespace Tests\Unit\Architecture;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 치환자가 있는 번역 키를 로그 문구로 쓸 때 치환 인자를 빠뜨리지 않는지 잠근다 (#144 부수 관찰).
 *
 * `Log::error(__('templates.errors.layout_registration_failed'), [...])` 의 두 번째 배열은 로그 문맥이지 번역 치환 인자가
 * 아니다. 그래서 로그에 `레이아웃 등록에 실패했습니다: :error` 처럼 자리표시가 그대로 남아, 레이아웃 등록이 왜 실패했는지
 * 운영 로그에서 읽을 수 없었다(실패 사유는 문맥 배열 안에만 있다). FormRequest `messages()` 처럼 검증기가 나중에 치환하는
 * 자리는 대상이 아니다 — 로그 호출만 본다.
 */
class TranslationPlaceholderArgumentsGuardTest extends TestCase
{
    /**
     * `app/` 의 로그 호출 중 치환자 있는 키를 인자 없이 번역한 곳이 0건이다.
     *
     * @scenario case=log_translation_placeholder_guard
     *
     * @effects log_messages_carry_translation_arguments
     *
     * @return void 반환값 없음
     */
    public function test_log_calls_pass_arguments_for_placeholder_keys(): void
    {
        $placeholderKeys = $this->placeholderKeys();
        $this->assertGreaterThan(100, count($placeholderKeys), '치환자 있는 번역 키 수집이 비었습니다 — 검사 0 을 위반 0 으로 읽지 않는다.');

        $violations = [];
        $logCalls = 0;

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = $file->getContents();
            preg_match_all('/Log::(?:debug|info|notice|warning|error|critical)\(\s*__\(\s*[\'"]([^\'"]+)[\'"]\s*(\))?/', $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

            foreach ($matches as $match) {
                $logCalls++;
                $withoutArguments = isset($match[2]) && $match[2][1] !== -1;

                if ($withoutArguments && isset($placeholderKeys[$match[1][0]])) {
                    $line = substr_count(substr($source, 0, $match[0][1]), "\n") + 1;
                    $violations[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()).':'.$line.' '.$match[1][0];
                }
            }
        }

        $this->assertGreaterThan(20, $logCalls, '번역 문구를 쓰는 로그 호출을 찾지 못했습니다 — 검사 패턴이 낡았습니다.');
        $this->assertSame([], $violations, "치환자 있는 키를 인자 없이 로그에 썼습니다 (자리표시가 그대로 남습니다):\n".implode("\n", $violations));
    }

    /**
     * `lang/ko/*.php` 에서 치환자(`:name`)가 있는 키를 모읍니다.
     *
     * @return array<string, true> 점 표기 키 집합
     */
    private function placeholderKeys(): array
    {
        $keys = [];

        foreach (File::files(lang_path('ko')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $group = $file->getFilenameWithoutExtension();
            $messages = require $file->getPathname();

            if (! is_array($messages)) {
                continue;
            }

            foreach (Arr::dot($messages) as $key => $text) {
                if (is_string($text) && preg_match('/:[a-zA-Z_]+/', $text) === 1) {
                    $keys[$group.'.'.$key] = true;
                }
            }
        }

        return $keys;
    }
}
