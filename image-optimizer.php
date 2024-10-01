<?php
/**
 * Plugin Name: Image Optimizer
 * Description: Optimizes images using lossy compression, generates WebP versions on upload, and serves WebP images when supported
 * Version: 0.3.1
 * Author: Transparent Web Solutions
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class ImageOptimizer {
    private $backup_dir;
    private $quality;
    private $max_width;

    public function __construct() {
        $this->backup_dir = wp_upload_dir()['basedir'] . '/image-optimizer-backup';
        $this->quality = 78; // Adjust this value as needed (75-85 is a good range)
        $this->max_width = 2000; // Maximum width for resizing

        add_action('init', array($this, 'create_backup_directory'));
        add_filter('wp_handle_upload', array($this, 'optimize_uploaded_image'), 10, 2);
        add_filter('wp_get_attachment_image_src', array($this, 'serve_webp_image'), 10, 4);
        add_filter('wp_calculate_image_srcset', array($this, 'serve_webp_srcset'), 10, 5);
        add_filter('wp_get_attachment_url', array($this, 'serve_webp_url'), 10, 2);
        add_filter('style_loader_tag', array($this, 'webp_background_images'), 10, 4);
    }

    public function create_backup_directory() {
        if (!file_exists($this->backup_dir)) {
            wp_mkdir_p($this->backup_dir);
        }
    }

    public function optimize_uploaded_image($file, $context) {
        if ($context !== 'upload' || !in_array($file['type'], array('image/jpeg', 'image/png'))) {
            return $file;
        }

        $image_path = $file['file'];
        $image_backup_path = $this->backup_dir . '/' . basename($image_path);

        // Backup original image
        copy($image_path, $image_backup_path);

        // Optimize image
        $this->optimize_image($image_path);

        // Generate WebP version
        $this->generate_webp($image_path);

        return $file;
    }

    private function optimize_image($image_path) {
        if (!extension_loaded('imagick')) {
            error_log('Imagick extension is not available. Image optimization skipped.');
            return false;
        }

        try {
            $image = new Imagick($image_path);

            // Resize if too large
            $current_width = $image->getImageWidth();
            if ($current_width > $this->max_width) {
                $image->resizeImage($this->max_width, 0, Imagick::FILTER_LANCZOS, 1);
            }

            // Strip metadata
            $image->stripImage();

            // Set compression quality
            $image->setImageCompressionQuality($this->quality);

            // For PNG, convert to JPEG if it doesn't have transparency
            if ($image->getImageFormat() == 'PNG' && !$this->has_transparency($image)) {
                $image->setImageFormat('JPEG');
                $image_path = preg_replace('/\.png$/i', '.jpg', $image_path);
            }

            // Save the optimized image
            $image->writeImage($image_path);
            $image->destroy();

            return true;
        } catch (Exception $e) {
            error_log('Image optimization failed: ' . $e->getMessage());
            return false;
        }
    }

    private function generate_webp($image_path) {
        if (!extension_loaded('imagick')) {
            error_log('Imagick extension is not available. WebP conversion skipped.');
            return false;
        }

        try {
            $image = new Imagick($image_path);
            $webp_path = preg_replace('/\.(jpg|jpeg|png)$/i', '.webp', $image_path);
            $image->setImageFormat('WEBP');
            $image->setImageCompressionQuality($this->quality);
            $image->writeImage($webp_path);
            $image->destroy();
            return true;
        } catch (Exception $e) {
            error_log('WebP conversion failed: ' . $e->getMessage());
            return false;
        }
    }

    private function has_transparency($image) {
        return $image->getImageAlphaChannel() == Imagick::ALPHACHANNEL_ACTIVATE;
    }

    public function serve_webp_image($image, $attachment_id, $size, $icon) {
        if (!$this->browser_supports_webp()) {
            return $image;
        }

        $webp_url = $this->get_webp_url($image[0]);
        if ($webp_url) {
            $image[0] = $webp_url;
        }

        return $image;
    }

    public function serve_webp_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id) {
        if (!$this->browser_supports_webp()) {
            return $sources;
        }

        foreach ($sources as &$source) {
            $webp_url = $this->get_webp_url($source['url']);
            if ($webp_url) {
                $source['url'] = $webp_url;
            }
        }

        return $sources;
    }

    public function serve_webp_url($url, $attachment_id) {
        if (!$this->browser_supports_webp()) {
            return $url;
        }

        $webp_url = $this->get_webp_url($url);
        return $webp_url ? $webp_url : $url;
    }

    public function webp_background_images($tag, $handle, $href, $media) {
        if (!$this->browser_supports_webp()) {
            return $tag;
        }

        $css = file_get_contents($href);
        $css = preg_replace_callback('/url\([\'"]?([^\'")]+\.(?:png|jpg|jpeg))[\'"]?\)/i', function($matches) {
            $webp_url = $this->get_webp_url($matches[1]);
            return $webp_url ? "url('" . $webp_url . "')" : $matches[0];
        }, $css);
        
        $css_dir = wp_upload_dir()['basedir'] . '/webp-css';
        if (!file_exists($css_dir)) {
            wp_mkdir_p($css_dir);
        }
        
        $css_file = $css_dir . '/' . md5($handle) . '.css';
        file_put_contents($css_file, $css);
        
        return str_replace($href, wp_upload_dir()['baseurl'] . '/webp-css/' . md5($handle) . '.css', $tag);
    }

    private function browser_supports_webp() {
        return strpos($_SERVER['HTTP_ACCEPT'], 'image/webp') !== false;
    }

    private function get_webp_url($url) {
        $webp_url = preg_replace('/\.(jpe?g|png)$/i', '.webp', $url);
        $webp_path = str_replace(wp_get_upload_dir()['baseurl'], wp_get_upload_dir()['basedir'], $webp_url);

        return file_exists($webp_path) ? $webp_url : false;
    }
}

new ImageOptimizer();
