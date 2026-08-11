<?php

declare(strict_types=1);

namespace Vlad\SBS\Modules\Performance;

use Vlad\SBS\Core\SettingsManager;

final class ImageConverter {
    
    // Применяется при загрузке новых файлов в админке
    public function set_image_format(array $formats): array {
        $target_format = SettingsManager::get('performance', 'image_format', 'original');

        if ($target_format === 'webp') {
            $formats['image/jpeg'] = 'image/webp';
            $formats['image/png']  = 'image/webp';
        } elseif ($target_format === 'avif' && function_exists('imageavif')) {
            $formats['image/jpeg'] = 'image/avif';
            $formats['image/png']  = 'image/avif';
            $formats['image/webp'] = 'image/avif';
        }

        return $formats;
    }

    public function disable_image_sizes(array $sizes): array {
        $disable_huge = SettingsManager::get('performance', 'disable_huge_images', false);
        
        if ($disable_huge) {
            if (isset($sizes['1536x1536'])) unset($sizes['1536x1536']);
            if (isset($sizes['2048x2048'])) unset($sizes['2048x2048']);
        }

        return $sizes;
    }

    // Применяется "На лету" ко всему HTML перед отдачей страницы
    public static function rewrite_html(string $html): string {
        $target_format = SettingsManager::get('performance', 'image_format', 'original');
        if ($target_format === 'original') {
            return $html;
        }

        $upload_dir = wp_upload_dir();
        $base_url   = $upload_dir['baseurl'];
        $base_dir   = $upload_dir['basedir'];

        // Регулярное выражение: ищем любые URL, начинающиеся с базового URL uploads и заканчивающиеся на jpg/jpeg/png
        $pattern = '/(' . preg_quote($base_url, '/') . '[^\s"\'<>]*?\.(?:jpe?g|png))/i';

        return preg_replace_callback($pattern, function ($matches) use ($target_format, $base_dir, $base_url) {
            $url = $matches[1];
            
            // Получаем локальный путь к файлу
            $relative_path = urldecode(str_replace($base_url, '', $url));
            $file_path = $base_dir . $relative_path;

            // Если оригинального файла нет на диске - пропускаем
            if (!file_exists($file_path)) {
                return $url;
            }

            $ext = $target_format === 'avif' ? 'avif' : 'webp';
            $new_file_path = $file_path . '.' . $ext;
            $new_url = $url . '.' . $ext;

            // Если конвертированный файл уже есть - подменяем URL
            if (file_exists($new_file_path)) {
                return $new_url;
            }

            // Конвертируем файл на лету через ядро WP
            $editor = wp_get_image_editor($file_path);
            if (!is_wp_error($editor)) {
                $saved = $editor->save($new_file_path, 'image/' . $ext);
                if (!is_wp_error($saved)) {
                    return $new_url;
                }
            }

            // Фоллбэк: если конвертация не удалась, оставляем оригинальный .jpg URL
            return $url;
        }, $html);
    }
}