<?php

namespace App\Support\Style;

/**
 * Tailwind 클래스 충돌 교체 병합기 (SEO 렌더러용).
 *
 * 엔진 `resources/js/core/template-engine/helpers/StyleHelpers.ts` 의 `mergeClasses`·`applyResponsiveClassMerge` 를
 * 그대로 옮긴 것이다. 그룹 표(`GROUPS`)·상위 그룹 표(`CONFLICTING`)는 엔진 표와 같은 순서·같은 정규식이며,
 * 두 구현은 공용 픽스처 `tests/Fixtures/class-merge/cases.json` 로 같은 결과를 증명한다
 * (`tests/Unit/Support/Style/ClassMergerTest.php`, 엔진 `StyleHelpers.classMergeParity.test.ts`).
 * 표를 고칠 때는 양쪽을 함께 고친다 — 표 이름 집합이 같은지도 시험이 확인한다.
 *
 * @since 7.2.1 (engine-v1.98.0 responsive classMerge 의 SEO 짝)
 */
final class ClassMerger
{
    /**
     * Tailwind 속성 그룹 — 엔진 TAILWIND_CLASS_GROUPS 와 같은 순서(첫 일치 그룹을 쓴다).
     *
     * @var array<string, string>
     */
    public const GROUPS = [
        'justify' => '#^justify-(start|end|center|between|around|evenly)$#',
        'items' => '#^items-(start|end|center|baseline|stretch)$#',
        'content' => '#^content-(start|end|center|between|around|evenly|stretch)$#',
        'self' => '#^self-(auto|start|end|center|stretch|baseline)$#',
        'flexDirection' => '#^flex-(row|row-reverse|col|col-reverse)$#',
        'flexWrap' => '#^flex-(wrap|wrap-reverse|nowrap)$#',
        'flex' => '#^flex-(1|auto|initial|none)$#',
        'grow' => '#^(grow|grow-0)$#',
        'shrink' => '#^(shrink|shrink-0)$#',
        'basis' => '#^basis-#',
        'order' => '#^-?order-#',
        'gapX' => '#^gap-x-#',
        'gapY' => '#^gap-y-#',
        'gap' => '#^gap-#',
        'gridCols' => '#^grid-cols-#',
        'gridRows' => '#^grid-rows-#',
        'colSpan' => '#^col-(span-|start-|end-)#',
        'rowSpan' => '#^row-(span-|start-|end-)#',
        'display' => '#^(block|inline-block|inline|flex|inline-flex|table|inline-table|table-caption|table-cell|table-column|table-column-group|table-footer-group|table-header-group|table-row-group|table-row|flow-root|grid|inline-grid|contents|list-item|hidden)$#',
        'position' => '#^(static|fixed|absolute|relative|sticky)$#',
        'insetX' => '#^-?inset-x-#',
        'insetY' => '#^-?inset-y-#',
        'inset' => '#^-?inset-#',
        'top' => '#^-?top-#',
        'right' => '#^-?right-#',
        'bottom' => '#^-?bottom-#',
        'left' => '#^-?left-#',
        'zIndex' => '#^z-#',
        'width' => '#^w-#',
        'minWidth' => '#^min-w-#',
        'maxWidth' => '#^max-w-#',
        'height' => '#^h-#',
        'minHeight' => '#^min-h-#',
        'maxHeight' => '#^max-h-#',
        'padding' => '#^p-#',
        'paddingX' => '#^px-#',
        'paddingY' => '#^py-#',
        'paddingS' => '#^ps-#',
        'paddingE' => '#^pe-#',
        'paddingT' => '#^pt-#',
        'paddingR' => '#^pr-#',
        'paddingB' => '#^pb-#',
        'paddingL' => '#^pl-#',
        'margin' => '#^-?m-#',
        'marginX' => '#^-?mx-#',
        'marginY' => '#^-?my-#',
        'marginS' => '#^-?ms-#',
        'marginE' => '#^-?me-#',
        'marginT' => '#^-?mt-#',
        'marginR' => '#^-?mr-#',
        'marginB' => '#^-?mb-#',
        'marginL' => '#^-?ml-#',
        'spaceXReverse' => '#^space-x-reverse$#',
        'spaceYReverse' => '#^space-y-reverse$#',
        'spaceX' => '#^-?space-x-#',
        'spaceY' => '#^-?space-y-#',
        'fontSize' => '#^text-(xs|sm|base|lg|xl|2xl|3xl|4xl|5xl|6xl|7xl|8xl|9xl)$#',
        'fontWeight' => '#^font-(thin|extralight|light|normal|medium|semibold|bold|extrabold|black)$#',
        'fontStyle' => '#^(italic|not-italic)$#',
        'fontFamily' => '#^font-(sans|serif|mono)#',
        'textAlign' => '#^text-(left|center|right|justify|start|end)$#',
        'textColor' => '#^text-(inherit|current|transparent|black|white|slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-#',
        'textDecoration' => '#^(underline|overline|line-through|no-underline)$#',
        'textTransform' => '#^(uppercase|lowercase|capitalize|normal-case)$#',
        'lineHeight' => '#^leading-#',
        'letterSpacing' => '#^tracking-#',
        'textOverflow' => '#^(truncate|text-ellipsis|text-clip)$#',
        'whitespace' => '#^whitespace-#',
        'wordBreak' => '#^break-(normal|words|all|keep)$#',
        'bgColor' => '#^bg-(inherit|current|transparent|black|white|slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-#',
        'bgGradient' => '#^bg-gradient-#',
        'bgSize' => '#^bg-(auto|cover|contain)$#',
        'bgPosition' => '#^bg-(bottom|center|left|left-bottom|left-top|right|right-bottom|right-top|top)$#',
        'bgRepeat' => '#^bg-(repeat|no-repeat|repeat-x|repeat-y|repeat-round|repeat-space)$#',
        'bgAttachment' => '#^bg-(fixed|local|scroll)$#',
        'bgClip' => '#^bg-clip-#',
        'bgOrigin' => '#^bg-origin-#',
        'borderWidth' => '#^border(-0|-2|-4|-8)?$#',
        'borderWidthX' => '#^border-x(-0|-2|-4|-8)?$#',
        'borderWidthY' => '#^border-y(-0|-2|-4|-8)?$#',
        'borderWidthS' => '#^border-s(-0|-2|-4|-8)?$#',
        'borderWidthE' => '#^border-e(-0|-2|-4|-8)?$#',
        'borderWidthT' => '#^border-t(-0|-2|-4|-8)?$#',
        'borderWidthR' => '#^border-r(-0|-2|-4|-8)?$#',
        'borderWidthB' => '#^border-b(-0|-2|-4|-8)?$#',
        'borderWidthL' => '#^border-l(-0|-2|-4|-8)?$#',
        'borderColor' => '#^border-(inherit|current|transparent|black|white|slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-#',
        'borderStyle' => '#^border-(solid|dashed|dotted|double|hidden|none)$#',
        'borderRadius' => '#^rounded(-none|-sm|-md|-lg|-xl|-2xl|-3xl|-full)?$#',
        'borderRadiusS' => '#^rounded-s(-none|-sm|-md|-lg|-xl|-2xl|-3xl|-full)?$#',
        'borderRadiusE' => '#^rounded-e(-none|-sm|-md|-lg|-xl|-2xl|-3xl|-full)?$#',
        'borderRadiusT' => '#^rounded-t(-none|-sm|-md|-lg|-xl|-2xl|-3xl|-full)?$#',
        'borderRadiusR' => '#^rounded-r(-none|-sm|-md|-lg|-xl|-2xl|-3xl|-full)?$#',
        'borderRadiusB' => '#^rounded-b(-none|-sm|-md|-lg|-xl|-2xl|-3xl|-full)?$#',
        'borderRadiusL' => '#^rounded-l(-none|-sm|-md|-lg|-xl|-2xl|-3xl|-full)?$#',
        'borderRadiusSS' => '#^rounded-ss(-none|-sm|-md|-lg|-xl|-2xl|-3xl|-full)?$#',
        'borderRadiusSE' => '#^rounded-se(-none|-sm|-md|-lg|-xl|-2xl|-3xl|-full)?$#',
        'borderRadiusEE' => '#^rounded-ee(-none|-sm|-md|-lg|-xl|-2xl|-3xl|-full)?$#',
        'borderRadiusES' => '#^rounded-es(-none|-sm|-md|-lg|-xl|-2xl|-3xl|-full)?$#',
        'borderRadiusTL' => '#^rounded-tl(-none|-sm|-md|-lg|-xl|-2xl|-3xl|-full)?$#',
        'borderRadiusTR' => '#^rounded-tr(-none|-sm|-md|-lg|-xl|-2xl|-3xl|-full)?$#',
        'borderRadiusBR' => '#^rounded-br(-none|-sm|-md|-lg|-xl|-2xl|-3xl|-full)?$#',
        'borderRadiusBL' => '#^rounded-bl(-none|-sm|-md|-lg|-xl|-2xl|-3xl|-full)?$#',
        'ringWidth' => '#^ring(-0|-1|-2|-4|-8|-inset)?$#',
        'ringColor' => '#^ring-(inherit|current|transparent|black|white|slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-#',
        'ringOffset' => '#^ring-offset-#',
        'shadow' => '#^shadow(-sm|-md|-lg|-xl|-2xl|-inner|-none)?$#',
        'opacity' => '#^opacity-#',
        'mixBlend' => '#^mix-blend-#',
        'bgBlend' => '#^bg-blend-#',
        'blur' => '#^blur(-none|-sm|-md|-lg|-xl|-2xl|-3xl)?$#',
        'brightness' => '#^brightness-#',
        'contrast' => '#^contrast-#',
        'grayscale' => '#^grayscale(-0)?$#',
        'hueRotate' => '#^-?hue-rotate-#',
        'invert' => '#^invert(-0)?$#',
        'saturate' => '#^saturate-#',
        'sepia' => '#^sepia(-0)?$#',
        'backdropBlur' => '#^backdrop-blur-#',
        'backdropBrightness' => '#^backdrop-brightness-#',
        'backdropContrast' => '#^backdrop-contrast-#',
        'backdropGrayscale' => '#^backdrop-grayscale-#',
        'backdropHueRotate' => '#^backdrop-hue-rotate-#',
        'backdropInvert' => '#^backdrop-invert-#',
        'backdropOpacity' => '#^backdrop-opacity-#',
        'backdropSaturate' => '#^backdrop-saturate-#',
        'backdropSepia' => '#^backdrop-sepia-#',
        'scaleX' => '#^-?scale-x-#',
        'scaleY' => '#^-?scale-y-#',
        'scale' => '#^-?scale-#',
        'rotate' => '#^-?rotate-#',
        'translateX' => '#^-?translate-x-#',
        'translateY' => '#^-?translate-y-#',
        'skewX' => '#^-?skew-x-#',
        'skewY' => '#^-?skew-y-#',
        'transformOrigin' => '#^origin-#',
        'transition' => '#^transition(-none|-all|-colors|-opacity|-shadow|-transform)?$#',
        'duration' => '#^duration-#',
        'ease' => '#^ease-(linear|in|out|in-out)$#',
        'delay' => '#^delay-#',
        'animate' => '#^animate-#',
        'cursor' => '#^cursor-#',
        'userSelect' => '#^select-#',
        'pointerEvents' => '#^pointer-events-#',
        'resize' => '#^resize(-none|-x|-y)?$#',
        'scrollBehavior' => '#^scroll-(auto|smooth)$#',
        'touchAction' => '#^touch-#',
        'overflow' => '#^overflow-(auto|hidden|clip|visible|scroll)$#',
        'overflowX' => '#^overflow-x-(auto|hidden|clip|visible|scroll)$#',
        'overflowY' => '#^overflow-y-(auto|hidden|clip|visible|scroll)$#',
        'overscroll' => '#^overscroll-(auto|contain|none)$#',
        'overscrollX' => '#^overscroll-x-(auto|contain|none)$#',
        'overscrollY' => '#^overscroll-y-(auto|contain|none)$#',
        'visibility' => '#^(visible|invisible|collapse)$#',
        'aspectRatio' => '#^aspect-#',
        'columns' => '#^columns-#',
        'breakAfter' => '#^break-after-#',
        'breakBefore' => '#^break-before-#',
        'breakInside' => '#^break-inside-#',
        'boxDecorationBreak' => '#^box-decoration-#',
        'boxSizing' => '#^box-(border|content)$#',
        'float' => '#^float-(right|left|none)$#',
        'clear' => '#^clear-(left|right|both|none)$#',
        'isolation' => '#^(isolate|isolation-auto)$#',
        'objectFit' => '#^object-(contain|cover|fill|none|scale-down)$#',
        'objectPosition' => '#^object-#',
    ];

    /**
     * 상위 그룹 → 함께 덮는 하위 그룹 — 엔진 CONFLICTING_CLASS_GROUPS 와 같다.
     *
     * @var array<string, list<string>>
     */
    public const CONFLICTING = [
        'padding' => ['paddingX', 'paddingY', 'paddingS', 'paddingE', 'paddingT', 'paddingR', 'paddingB', 'paddingL'],
        'paddingX' => ['paddingR', 'paddingL'],
        'paddingY' => ['paddingT', 'paddingB'],
        'margin' => ['marginX', 'marginY', 'marginS', 'marginE', 'marginT', 'marginR', 'marginB', 'marginL'],
        'marginX' => ['marginR', 'marginL'],
        'marginY' => ['marginT', 'marginB'],
        'inset' => ['insetX', 'insetY', 'top', 'right', 'bottom', 'left'],
        'insetX' => ['right', 'left'],
        'insetY' => ['top', 'bottom'],
        'gap' => ['gapX', 'gapY'],
        'borderWidth' => ['borderWidthX', 'borderWidthY', 'borderWidthS', 'borderWidthE', 'borderWidthT', 'borderWidthR', 'borderWidthB', 'borderWidthL'],
        'borderWidthX' => ['borderWidthR', 'borderWidthL'],
        'borderWidthY' => ['borderWidthT', 'borderWidthB'],
        'borderRadius' => ['borderRadiusS', 'borderRadiusE', 'borderRadiusT', 'borderRadiusR', 'borderRadiusB', 'borderRadiusL', 'borderRadiusSS', 'borderRadiusSE', 'borderRadiusEE', 'borderRadiusES', 'borderRadiusTL', 'borderRadiusTR', 'borderRadiusBR', 'borderRadiusBL'],
        'borderRadiusS' => ['borderRadiusSS', 'borderRadiusES'],
        'borderRadiusE' => ['borderRadiusSE', 'borderRadiusEE'],
        'borderRadiusT' => ['borderRadiusTL', 'borderRadiusTR'],
        'borderRadiusR' => ['borderRadiusTR', 'borderRadiusBR'],
        'borderRadiusB' => ['borderRadiusBR', 'borderRadiusBL'],
        'borderRadiusL' => ['borderRadiusTL', 'borderRadiusBL'],
        'overflow' => ['overflowX', 'overflowY'],
        'overscroll' => ['overscrollX', 'overscrollY'],
        'scale' => ['scaleX', 'scaleY'],
    ];

    /** 변형 접두 — 엔진 findClassGroup·extractVariant 의 정규식과 같다 */
    private const VARIANT_PATTERN = '#^((dark:|hover:|focus:|active:|disabled:|group-hover:|sm:|md:|lg:|xl:|2xl:)+)#';

    /**
     * 기본 클래스에 override 클래스를 충돌 교체로 합친다 (엔진 mergeClasses).
     *
     * @param  string|null  $base  기본 클래스
     * @param  string|null  $override  덮을 클래스
     * @param  list<string>  $extensionPrefixes  활성 확장 앞글자(`ecommerce` 등, 콜론 없음)
     * @return string 합친 클래스
     */
    public static function merge(?string $base, ?string $override, array $extensionPrefixes = []): string
    {
        $base = $base ?? '';
        if ($override === null || trim($override) === '') {
            return $base;
        }
        if (trim($base) === '') {
            return $override;
        }

        $baseTokens = self::tokens($base);
        $overrideTokens = self::tokens($override);

        $covered = [];
        foreach ($overrideTokens as $token) {
            $group = self::findGroup($token, $extensionPrefixes);
            if ($group === null) {
                continue;
            }
            $variant = self::extractVariant($token, $extensionPrefixes);
            $covered[$group.':'.$variant] = true;
            foreach (self::CONFLICTING[$group] ?? [] as $lower) {
                $covered[$lower.':'.$variant] = true;
            }
        }

        $kept = array_values(array_filter($baseTokens, function (string $token) use ($covered, $extensionPrefixes): bool {
            $group = self::findGroup($token, $extensionPrefixes);
            if ($group === null) {
                return true;
            }

            return ! isset($covered[$group.':'.self::extractVariant($token, $extensionPrefixes)]);
        }));

        return implode(' ', array_merge($kept, $overrideTokens));
    }

    /**
     * responsive 구간 classMerge 를 해석된 className 에 덧붙인다 (엔진 applyResponsiveClassMerge).
     *
     * 같은 토큰은 merge 쪽 하나만 남긴다(그룹 없는 의미 클래스가 두 번 적히지 않게).
     *
     * @param  string|null  $className  해석된 className
     * @param  string|null  $classMerge  해석된 구간 classMerge
     * @param  list<string>  $extensionPrefixes  활성 확장 앞글자
     * @return string 합친 className
     */
    public static function applyResponsive(?string $className, ?string $classMerge, array $extensionPrefixes = []): string
    {
        $base = $className ?? '';
        if ($classMerge === null || trim($classMerge) === '') {
            return $base;
        }

        $mergeTokens = array_values(array_unique(self::tokens($classMerge)));
        $mergeSet = array_flip($mergeTokens);
        $baseTokens = array_values(array_filter(self::tokens($base), fn (string $token): bool => ! isset($mergeSet[$token])));

        return trim(self::merge(implode(' ', $baseTokens), implode(' ', $mergeTokens), $extensionPrefixes));
    }

    /**
     * 공백으로 나눈 비어 있지 않은 토큰 목록.
     *
     * @param  string  $value  클래스 문자열
     * @return list<string> 토큰
     */
    private static function tokens(string $value): array
    {
        return array_values(array_filter(preg_split('/\s+/', $value) ?: [], fn (string $token): bool => $token !== ''));
    }

    /**
     * 등록된 확장 앞글자(`ecommerce:`)를 뗀 나머지 (엔진 splitExtensionClassPrefix).
     *
     * @param  string  $token  토큰
     * @param  list<string>  $extensionPrefixes  활성 확장 앞글자
     * @return string 나머지
     */
    private static function stripExtensionPrefix(string $token, array $extensionPrefixes): string
    {
        $colon = strpos($token, ':');
        if ($colon === false || $colon === 0) {
            return $token;
        }

        return in_array(substr($token, 0, $colon), $extensionPrefixes, true) ? substr($token, $colon + 1) : $token;
    }

    /**
     * 토큰의 그룹 이름 (엔진 findClassGroup).
     *
     * @param  string  $token  토큰
     * @param  list<string>  $extensionPrefixes  활성 확장 앞글자
     * @return string|null 그룹 (없으면 null)
     */
    private static function findGroup(string $token, array $extensionPrefixes): ?string
    {
        $bare = (string) preg_replace(self::VARIANT_PATTERN, '', self::stripExtensionPrefix($token, $extensionPrefixes));
        foreach (self::GROUPS as $group => $pattern) {
            if (preg_match($pattern, $bare) === 1) {
                return $group;
            }
        }

        return null;
    }

    /**
     * 토큰의 변형 접두 (엔진 extractVariant).
     *
     * @param  string  $token  토큰
     * @param  list<string>  $extensionPrefixes  활성 확장 앞글자
     * @return string 변형 (없으면 빈 문자열)
     */
    private static function extractVariant(string $token, array $extensionPrefixes): string
    {
        return preg_match(self::VARIANT_PATTERN, self::stripExtensionPrefix($token, $extensionPrefixes), $m) === 1 ? $m[1] : '';
    }
}
