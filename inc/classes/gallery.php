<?php

// 内建随机图api
// 工作目录在wp-content/uploads/iro_gallery

namespace Sakura\API;

class gallery
{
    private $image_dir;
    private $image_list;
    private $image_folder;
    private $backup_folder;
    private $log = '';

    //定义工作目录
    public function __construct() {
        $upload_dir = wp_get_upload_dir()['basedir'];
        $this->image_dir = $upload_dir . '/iro_gallery';
        $this->image_list = $this->image_dir . '/imglist.json';
        $this->image_folder = $this->image_dir . '/img';
        $this->backup_folder = $this->image_dir . '/backup';
        //创建目录和索引
        $this->init_dirs();
    }

    private function init_dirs() {
        //初始化工作目录
        $dirs = [$this->image_dir, $this->image_folder, $this->backup_folder];

        foreach ($dirs as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
                $this->log .= __("Unable to create directory: $dir. Please check permissions.", "sakurairo") . '<br>';
                return $this->log;
            }
        }
        //初始化索引
        if (!file_exists($this->image_list)) {
            if (!touch($this->image_list)) {
                $this->log .= __("Unable to create file: {$this->image_list}. Please check permissions.", "sakurairo") . '<br>';
                return $this->log;
            }
        }
    }

    //生成索引并进行分拣
    public function init() {
        $allowedExtensions = ['jpg', 'jpeg', 'bmp', 'png', 'webp', 'gif'];
        $imageFiles = ['long' => [], 'wide' => []];

        $allFiles = $this->get_all_files($this->image_folder);

        foreach ($allFiles as $filePath) {
            if (in_array(strtolower(pathinfo($filePath, PATHINFO_EXTENSION)), $allowedExtensions)) {
                // Prefer a valid additive copy while leaving original URLs usable.
                $copy = $filePath . '.webp';
                if (!is_link($copy) && is_file($copy)) {
                    $copyInfo = @getimagesize($copy);
                    if ($copyInfo && $copyInfo[2] === IMAGETYPE_WEBP) {
                        $filePath = $copy;
                    }
                }
                //获取图片信息进行分拣
                $imageSize = @getimagesize($filePath);

                if ($imageSize === false) {
                    continue;
                }

                $width = $imageSize[0];
                $height = $imageSize[1];

                $filePath = str_replace($this->image_folder, '/iro_gallery/img', $filePath);

                //根据比例分拣图片
                if ($width / $height < 9 / 10) {
                    $imageFiles['long'][] = $filePath;
                } else {
                    $imageFiles['wide'][] = $filePath;
                }
            }
        }

        $imageFiles['long'] = array_values(array_unique($imageFiles['long']));
        $imageFiles['wide'] = array_values(array_unique($imageFiles['wide']));
        //保存索引
        file_put_contents($this->image_list, json_encode($imageFiles));

        $this->log .= __("Successfully initialized the index.", "sakurairo") . '<br>';
        return $this->log;
    }

    //遍历目录方法
    private function get_all_files($directory) {
        $result = [];
        $files = scandir($directory);

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $filePath = $directory . '/' . $file;
            if (is_link($filePath)) {
                continue;
            }
            if (is_dir($filePath)) {
                $result = array_merge($result, $this->get_all_files($filePath));
            } else {
                $result[] = $filePath;
            }
        }

        return $result;
    }

    // Create additive copies: originals, backups, and the active index never move.
    public function webp() {
        $this->log = '';
        if (!function_exists('imagewebp') || is_link($this->image_folder)
            || !is_dir($this->image_folder) || !is_writable($this->image_folder)) {
            return esc_html__('WebP support and a writable image directory are required.', 'sakurairo');
        }
        $lock = fopen($this->image_dir . '/.webp.lock', 'c');
        if (!$lock) {
            return esc_html__('Unable to lock the gallery.', 'sakurairo');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return esc_html__('Another gallery conversion is running.', 'sakurairo');
        }
        try {
            $readers = ['jpg' => 'imagecreatefromjpeg', 'jpeg' => 'imagecreatefromjpeg', 'png' => 'imagecreatefrompng'];
            foreach ($this->get_all_files($this->image_folder) as $source) {
                $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
                // Keep GIF animation and existing WebP files intact.
                if (!isset($readers[$extension])) {
                    continue;
                }
                $destination = $source . '.webp';
                if (file_exists($destination) || is_link($destination)) {
                    $this->log .= esc_html(sprintf(__('Skipped existing file: %s', 'sakurairo'), basename($destination))) . '<br>';
                    continue;
                }
                $converted = $this->convert_to_webp($source, $destination, $readers[$extension]);
                $this->log .= esc_html(sprintf($converted
                    ? __('Created WebP copy: %s', 'sakurairo')
                    : __('Conversion failed; original preserved: %s', 'sakurairo'), basename($source))) . '<br>';
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $this->log . esc_html__('Originals and the current index are unchanged. Rebuild the index to use valid WebP copies.', 'sakurairo');
    }

    private function convert_to_webp($source, $destination, $reader) {
        if (!function_exists($reader)) {
            return false;
        }
        $image = @$reader($source);
        if (!$image) {
            return false;
        }
        // Exclusive creation prevents overwriting files, including a concurrent writer's output.
        $stream = @fopen($destination, 'xb');
        if (!$stream) {
            imagedestroy($image);
            return false;
        }
        $success = false;
        try {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            $success = imagewebp($image, $stream, 80);
            fflush($stream);
            $info = @getimagesize($destination);
            $success = $success && $info && $info[2] === IMAGETYPE_WEBP;
        } catch (\Throwable $error) {
            $success = false;
        } finally {
            fclose($stream);
            imagedestroy($image);
            if (!$success) {
                // Only remove the new file opened exclusively by this invocation.
                unlink($destination);
            }
        }
        return $success;
    }

    //获取图片
    public function get_image(\WP_REST_Request $request) {
        $imgParam = sanitize_text_field($request->get_param('img')) ?: '';
        $imageList = json_decode(file_get_contents($this->image_list), true);

        if (empty($imageList)) {
            $this->init(true);
        }

        $error_info = array(
            'status' => 500,
            "success" => false,
            'message' => __("No images found. Please contact the administrator to check if images exist in the 'iro_gallary' directory and ensure the directory is readable and writable.", "sakurairo") . '<br>',
        );
        $error = new \WP_REST_Response($error_info, 500);
        $error->set_status(500);

        if (!empty($imageList)) {
            //img参数优先获取long或wide
            if ($imgParam == 'l' && !empty($imageList['long'])) {
                $random_image = $imageList['long'][array_rand($imageList['long'])];
            } else {
                if ($imgParam == 'w' && !empty($imageList['wide'])) {
                    $random_image = $imageList['wide'][array_rand($imageList['wide'])];
                } else {
                    $all_images = array_merge($imageList['long'] ?? [], $imageList['wide'] ?? []);
                    if (!empty($all_images)) {
                        $random_image = $all_images[array_rand($all_images)];
                    } else {
                        return $error;
                    }
                }
            }

            $random_image = wp_get_upload_dir()['baseurl'] . $random_image;

            wp_safe_redirect(esc_url_raw($random_image), 302);
            exit;
        } else {
            return $error;
        }
    }
}
?>