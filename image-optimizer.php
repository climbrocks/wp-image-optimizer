<?php
/**
 * Plugin Name: Image Optimizer
 * Description: Optimizes images using lossy compression and generates WebP versions on upload
 * Version: 0.1
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
        $this->quality = 82; // Adjust this value as needed (75-85 is a good range)
        $this->max_width = 2000; // Maximum width for resizing

        add_action('init', array($this, 'create_backup_directory'));
        add_filter('wp_handle_upload', array($this, 'optimize_uploaded_image'), 10, 2);
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
}

new ImageOptimizer();
