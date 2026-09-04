<?php

declare(strict_types=1);

namespace App\Upgrades\Data\Ext\Plugins\SirsoftGdpr\V1_0_4\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use App\Support\ExtensionStoragePath;
use Illuminate\Support\Facades\File;

/**
 * 저장된 쿠키 카테고리 안내에서 화면 테마의 분류를 정정합니다.
 *
 * 배경:
 *   화면 테마(`g7_color_scheme`)는 사용자가 화면에서 직접 고른 표시 환경이므로 언어 설정과
 *   같은 strictly necessary 항목입니다. 그런데 저장 게이트의 필수 목록에서 빠져 있어, 기능
 *   쿠키에 동의하기 전에는 테마를 바꿔도 저장이 조용히 버려졌습니다(새로고침하면 원래대로).
 *   재분류로 그 동작은 고쳤지만, 동의 안내는 여전히 "다크모드는 기능 쿠키이고 거부하면 매
 *   방문마다 기본값" 이라고 말합니다. 안내가 실제 동작과 어긋난 채 남으면 동의 고지 자체가
 *   사실과 다른 상태가 되므로 저장된 문구도 함께 정정합니다.
 *
 * 멱등: 이미 정정된 문구는 그대로 둡니다. 재실행해도 결과가 같습니다.
 *
 * 안전:
 *   - **알려진 구 문구와 정확히 일치할 때만** 교체합니다 — 운영자나 다른 경로가 바꾼 문구를
 *     덮어쓰지 않습니다.
 *   - 설명이 아예 없는 항목에는 **넣지 않습니다** — 배너는 설명이 있을 때만 문단을 그리므로,
 *     없던 설명을 주입하면 화면에 없던 문단이 새로 생깁니다(이 스텝의 목적 밖).
 *   - 카테고리 구성(키·필수 여부·라벨)은 건드리지 않습니다.
 *
 * V-1 안전(docs/extension/upgrade-step-guide.md §13): 파일 시스템과 코어 경로 해석기만
 * 사용하고 Service / Manager / Repository 를 해석하지 않습니다.
 */
final class RetagThemeAsStrictlyNecessary implements DataMigration
{
    /**
     * 정정 대상 문구 (1.0.4 시점 동결) — 카테고리 키 => [로케일 => [구 문구, 새 문구]].
     *
     * @var array<string, array<string, array{0: string, 1: string}>>
     */
    private const REPLACEMENTS = [
        'necessary' => [
            'ko' => [
                '세션·CSRF·로그인 토큰, 장바구니 식별자, 사용자가 가입 시 선택한 언어 설정, 쿠키 동의 기록 등 사이트 운영에 반드시 필요한 항목입니다. 비활성화할 수 없습니다.',
                '세션·CSRF·로그인 토큰, 장바구니 식별자, 사용자가 직접 고른 언어 설정과 화면 테마, 쿠키 동의 기록 등 사이트 운영에 반드시 필요한 항목입니다. 비활성화할 수 없습니다.',
            ],
            'en' => [
                'Strictly necessary for site operation: session/CSRF/auth tokens, shopping basket identifier, user-selected language preference at registration, cookie consent record. Cannot be disabled.',
                'Strictly necessary for site operation: session/CSRF/auth tokens, shopping basket identifier, user-selected language preference and display theme, cookie consent record. Cannot be disabled.',
            ],
        ],
        'functional' => [
            'ko' => [
                '사용자 선호도(다크모드, 표시 통화 등)를 기억하는 쿠키입니다. 거부 시 매 방문마다 기본값으로 표시됩니다.',
                '사용자 선호도(표시 통화 등)를 기억하는 쿠키입니다. 거부 시 매 방문마다 기본값으로 표시됩니다.',
            ],
            'en' => [
                'Cookies that remember user preferences such as dark mode and display currency. If declined, defaults are used on every visit.',
                'Cookies that remember user preferences such as display currency. If declined, defaults are used on every visit.',
            ],
        ],
    ];

    /**
     * 마이그레이션 식별자 (로그용).
     *
     * @return string 사람이 읽을 수 있는 짧은 식별자
     */
    public function name(): string
    {
        return 'RetagThemeAsStrictlyNecessary';
    }

    /**
     * 저장된 쿠키 카테고리 설명 문구를 정정합니다. idempotent.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트 (로거 등)
     */
    public function run(UpgradeContext $context): void
    {
        // 절대 경로는 코어 해석기가 디스크 root 를 기준으로 조립한다 — 확장마다 직접 조립하면
        // 테스트 환경에서 운영 설정 파일을 그대로 건드리게 된다.
        $path = ExtensionStoragePath::plugin('sirsoft-gdpr', 'settings').'/setting.json';

        if (! File::exists($path)) {
            $context->logger->info('[sirsoft-gdpr] 설정 파일 없음 — 설치 시 기본값이 시드하므로 skip');

            return;
        }

        $settings = json_decode(File::get($path), true);

        if (! is_array($settings) || ! isset($settings['cookie_categories'])) {
            $context->logger->info('[sirsoft-gdpr] 저장된 쿠키 카테고리 없음 — 문구 정정 skip');

            return;
        }

        // cookie_categories 는 settings 컬럼이 string 이라 json_encode 된 형태로 저장된다.
        $raw = $settings['cookie_categories'];
        $wasEncoded = is_string($raw);
        $categories = $wasEncoded ? json_decode($raw, true) : $raw;

        if (! is_array($categories)) {
            $context->logger->warning('[sirsoft-gdpr] 쿠키 카테고리 JSON 형식 비정상 — 문구 정정 skip');

            return;
        }

        $changed = [];

        foreach ($categories as $index => $category) {
            if (! is_array($category) || ! isset($category['key'])) {
                continue;
            }

            $rules = self::REPLACEMENTS[$category['key']] ?? null;

            // 설명이 없는 항목에는 넣지 않는다 — 없던 문단을 새로 만들지 않기 위함.
            if ($rules === null || ! isset($category['description']) || ! is_array($category['description'])) {
                continue;
            }

            foreach ($rules as $locale => [$before, $after]) {
                if (($category['description'][$locale] ?? null) === $before) {
                    $categories[$index]['description'][$locale] = $after;
                    $changed[] = "{$category['key']}.{$locale}";
                }
            }
        }

        if ($changed === []) {
            $context->logger->info('[sirsoft-gdpr] 정정 대상 문구 없음 — 이미 최신이거나 운영자가 수정함');

            return;
        }

        $settings['cookie_categories'] = $wasEncoded
            ? json_encode($categories, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : $categories;

        File::put($path, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $context->logger->info('[sirsoft-gdpr] 쿠키 카테고리 안내 문구 정정: '.implode(', ', $changed));
    }
}
