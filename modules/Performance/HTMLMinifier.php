<?php

declare(strict_types=1);

namespace Vlad\SBS\Modules\Performance;

use Vlad\SBS\Core\SettingsManager;

final class HTMLMinifier {
    public static function minify(string $html): string {
        $minify_assets = SettingsManager::get('performance', 'minify_inline_js_css', false);

        // 1. Минификация inline JS/CSS (если включена)
        if ($minify_assets) {
            $html = preg_replace_callback(
                '/<style\b[^>]*>(.*?)<\/style>/is',
                [self::class, 'minify_css_callback'],
                $html
            );

            $html = preg_replace_callback(
                '/<script\b(?![^>]*\btype=[\'"]?(?:application\/json|text\/template|text\/html)[\'"]?)[^>]*>(.*?)<\/script>/is',
                [self::class, 'minify_js_callback'],
                $html
            );
        }

        // 2. Временная замена содержимого чувствительных тегов (чтобы не убить переносы строк в <pre> и <textarea>)
        $placeholders = [];
        $html = preg_replace_callback(
            '/<(?:pre|textarea|script)\b[^>]*>.*?<\/(?:pre|textarea|script)>/is',
            function ($matches) use (&$placeholders) {
                $key = '___SBS_MINIFY_PLACEHOLDER_' . count($placeholders) . '___';
                $placeholders[$key] = $matches[0];
                return $key;
            },
            $html
        );

        // 3. Агрессивная HTML минификация
        $preg_rules = [
            '/<!--(?!\[if\ss)(?!\[endif\]).*?-->/s' => '',   // Удаление комментариев
            '/\>[^\S ]+/s'                          => '>',  // Пробелы после закрывающего тега
            '/[^\S ]+\</s'                          => '<',  // Пробелы перед открывающим тегом
            '/(\s)+/s'                              => '\\1',// Сворачивание множественных пробелов
        ];

        $html = preg_replace(array_keys($preg_rules), array_values($preg_rules), $html);

        // 4. Возврат чувствительных тегов на место
        if (!empty($placeholders)) {
            $html = str_replace(array_keys($placeholders), array_values($placeholders), $html);
        }

        return trim($html);
    }

    private static function minify_css_callback(array $matches): string {
        $css = $matches[1];
        
        $css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);
        $css = str_replace([': ', ' :', ' {', '{ ', ' }', '} ', '; ', ' ;', ', '], [':', ':', '{', '{', '}', '}', ';', ';', ','], $css);
        $css = str_replace(["\r\n", "\r", "\n", "\t"], '', $css);
        $css = preg_replace('/ {2,}/', ' ', $css);
        
        return str_replace($matches[1], trim($css), $matches[0]);
    }

    private static function minify_js_callback(array $matches): string {
        $js = $matches[1];
        
        if (trim($js) === '') {
            return $matches[0];
        }

        $js = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $js);
        $js = preg_replace('/(?:(?:\/\*(?:[^*]|(?:\*+[^*\/]))*\*+\/)|(?:(?<!\:|\\\|\'|\")\/\/.*))/', '', $js);
        $js = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $js);
        $js = preg_replace('/ {2,}/', ' ', $js);
        
        return str_replace($matches[1], trim($js), $matches[0]);
    }
}