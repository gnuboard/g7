<?php

namespace App\Support;

/**
 * 레이아웃 테이블 이름을 실행 역할에 맞춰 돌려줍니다 — 검수 서버(stage)는 `stage_` 접두 테이블을 쓴다.
 *
 * 레이아웃 모델(`TemplateLayout`·`LayoutExtension`·`TemplateLayoutVersion`·`TemplateLayoutExtensionVersion`)은
 * `UsesRuntimeRoleLayoutTable` 트레이트로 자동으로 바뀐다. 모델을 거치지 않고 `DB::table()`·검증 규칙에 테이블 이름을 쓰는 코드는
 * 이 헬퍼를 거친다(정적 검사 `layout-table-name-helper`).
 *
 * @since 7.2.0
 */
final class LayoutTable
{
    /**
     * 역할별로 갈리는 레이아웃 테이블.
     */
    public const ROUTED = [
        'template_layouts',
        'template_layout_extensions',
        'template_layout_versions',
        'template_layout_extension_versions',
    ];

    /**
     * 스테이지 테이블 접두사.
     */
    public const STAGE_PREFIX = 'stage_';

    /**
     * 지금 역할의 테이블 이름.
     *
     * @param  string  $base  원 테이블 이름 (template_layouts 등)
     * @return string 테이블 이름
     */
    public static function name(string $base): string
    {
        if (! in_array($base, self::ROUTED, true)) {
            return $base;
        }

        return RuntimeRole::isStage() ? self::STAGE_PREFIX.$base : $base;
    }

    /**
     * 운영(live) 테이블 이름 — `stage-layouts:reset-from-live` 처럼 운영 행을 읽어야 하는 곳만 쓴다.
     *
     * @param  string  $base  원 테이블 이름
     * @return string 운영 테이블 이름
     */
    public static function live(string $base): string
    {
        return $base;
    }

    /**
     * 스테이지 테이블 이름.
     *
     * @param  string  $base  원 테이블 이름
     * @return string 스테이지 테이블 이름
     */
    public static function stage(string $base): string
    {
        return self::STAGE_PREFIX.$base;
    }

    /**
     * 지금 역할에서 쓰면 안 되는 테이블인지 — stage 는 운영 테이블, live 는 스테이지 테이블에 쓰지 않는다.
     *
     * @param  string  $table  쓰려는 테이블
     * @return bool 금지면 true
     */
    public static function isForbiddenWriteTarget(string $table): bool
    {
        $isStageTable = str_starts_with($table, self::STAGE_PREFIX) && in_array(substr($table, strlen(self::STAGE_PREFIX)), self::ROUTED, true);
        $isLiveTable = in_array($table, self::ROUTED, true);

        return RuntimeRole::isStage() ? $isLiveTable : $isStageTable;
    }
}
