<?php

namespace App\Extension\Helpers;

use App\Enums\LanguagePackScope;
use App\Models\LanguagePack;
use App\Services\LanguagePack\LanguagePackRegistry;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * 확장의 언어 파일 한 개에서 한 키의 언어별 글을 모읍니다 (확장 자체 언어 파일 + 그 확장을 대상으로 하는 활성 언어팩).
 *
 * 확장이 선언 배열(회원 항목 이름처럼 DB 행에 다국어 JSON 으로 저장되는 글)을 만들 때 쓴다. 선언 동기화는 설치와
 * 갱신 도중에도 돌아, 그때는 확장의 번역 이름공간이나 언어팩의 보완 경로가 아직 등록되지 않았을 수 있다. 그래서 번역기를
 * 거치지 않고 파일을 직접 읽는다. 확장 자체 파일(`{언어 디렉터리}/{언어}/{파일}.php`)이 정한 언어가 먼저이고,
 * 활성 언어팩(`{팩}/backend/{언어}/{파일}.php`, 평탄형 `{팩}/backend/{파일}.php`)은 확장에 없는 언어만 더한다.
 *
 * 언어팩을 켜고 끄면 `RunSeedersOnLanguagePackLifecycle` 이 그 확장의 항목 선언을 다시 동기화해 DB 행에 반영한다.
 *
 * @since 7.2.0
 */
final class ExtensionLangTexts
{
    /**
     * 한 키의 언어별 글을 모읍니다.
     *
     * @param  string  $identifier  확장 식별자 (언어팩의 대상 식별자)
     * @param  string  $langDirectory  확장 자체 언어 디렉터리 (예: `{모듈 경로}/src/lang`)
     * @param  string  $file  언어 파일 이름 (확장자 제외, 예: fields)
     * @param  string  $path  파일 안의 점 표기 키 (예: user.notify_comment.name)
     * @return array<string, string> 언어 => 글 (빈 글은 뺀다)
     */
    public static function collect(string $identifier, string $langDirectory, string $file, string $path): array
    {
        $texts = [];

        // 언어 디렉터리 목록은 Finder 로 읽는다(호스팅이 막을 수 있는 glob() 을 쓰지 않는다)
        $localeDirectories = is_dir($langDirectory) ? File::directories($langDirectory) : [];
        sort($localeDirectories);
        foreach ($localeDirectories as $localeDirectory) {
            $value = Arr::get(self::read($localeDirectory.DIRECTORY_SEPARATOR.$file.'.php'), $path);
            if (is_string($value) && $value !== '') {
                $texts[basename($localeDirectory)] = $value;
            }
        }

        foreach (self::activePacksFor($identifier) as $pack) {
            $locale = (string) $pack->locale;
            if ($locale === '' || isset($texts[$locale])) {
                continue;
            }
            $backend = $pack->resolveDirectory().DIRECTORY_SEPARATOR.'backend';
            foreach ([$backend.DIRECTORY_SEPARATOR.$locale.DIRECTORY_SEPARATOR.$file.'.php', $backend.DIRECTORY_SEPARATOR.$file.'.php'] as $candidate) {
                $value = Arr::get(self::read($candidate), $path);
                if (is_string($value) && $value !== '') {
                    $texts[$locale] = $value;
                    break;
                }
            }
        }

        return $texts;
    }

    /**
     * 확장을 대상으로 하는 활성 모듈·플러그인 언어팩을 반환합니다.
     *
     * 언어팩 테이블이 없는 단계(코어 설치 도중 등)에서는 빈 목록이다.
     *
     * @param  string  $identifier  확장 식별자
     * @return array<int, LanguagePack> 활성 언어팩 목록
     */
    private static function activePacksFor(string $identifier): array
    {
        try {
            $registry = app(LanguagePackRegistry::class);

            return $registry->getActivePacks(LanguagePackScope::Module->value)
                ->merge($registry->getActivePacks(LanguagePackScope::Plugin->value))
                ->filter(fn (LanguagePack $pack) => $pack->target_identifier === $identifier)
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * 언어 파일을 읽습니다.
     *
     * @param  string  $path  파일 경로
     * @return array<string, mixed> 문구 배열 (파일이 없거나 배열이 아니면 빈 배열)
     */
    private static function read(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $texts = require $path;

        return is_array($texts) ? $texts : [];
    }
}
