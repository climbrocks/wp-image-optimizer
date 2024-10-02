<?php
/**
 * Plugin Name: Image Optimizer
 * Description: Optimizes images using lossy compression, generates WebP versions on upload, and serves WebP images when supported
 * Version: 1.0.0
 * Author: Transparent Web Solutions
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class ImageOptimizer {
    private $backup_dir;
    private $quality;
    private $max_width;
    private $optimized_marker_file;
    private $batch_size;
    private $log_file;

    public function __construct() {
        $this->backup_dir = wp_upload_dir()['basedir'] . '/image-optimizer-backup';
        $this->quality = 78; // Adjust this value as needed (75-85 is a good range)
        $this->max_width = 2000; // Maximum width for resizing
        $this->optimized_marker_file = '.optimized';
        $this->batch_size = 20; // Number of images to process per batch
        $this->log_file = WP_CONTENT_DIR . '/image-optimizer-log.txt';

        add_action('init', array($this, 'create_backup_directory'));
        add_filter('wp_handle_upload', array($this, 'optimize_uploaded_image'), 10, 2);
        add_filter('wp_get_attachment_image_src', array($this, 'serve_webp_image'), 10, 4);
        add_filter('wp_calculate_image_srcset', array($this, 'serve_webp_srcset'), 10, 5);
        add_filter('wp_get_attachment_url', array($this, 'serve_webp_url'), 10, 2);
        add_filter('style_loader_tag', array($this, 'webp_background_images'), 10, 4);
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('wp_ajax_run_bulk_optimization', array($this, 'run_bulk_optimization'));
        add_action('wp_ajax_get_optimization_progress', array($this, 'get_optimization_progress'));
        add_action('wp_ajax_restore_original_images', array($this, 'restore_original_images'));
    }

    public function create_backup_directory() {
        if (!file_exists($this->backup_dir)) {
            wp_mkdir_p($this->backup_dir);
        }
    }

    public function optimize_uploaded_image($file, $context) {
        try {
            $file_path = '';
            $file_type = '';

            if ($context === 'upload' && isset($file['file']) && isset($file['type'])) {
                $file_path = $file['file'];
                $file_type = $file['type'];
            } elseif (is_string($file)) {
                $file_path = $file;
                $file_type = wp_check_filetype($file_path)['type'];
            } else {
                throw new Exception('Unable to determine file path and type');
            }

            if (!in_array($file_type, array('image/jpeg', 'image/png'))) {
                return $file;
            }

            $image_backup_path = $this->backup_dir . '/' . basename($file_path);

            // Backup original image if not already backed up
            if (!file_exists($image_backup_path)) {
                if (!copy($file_path, $image_backup_path)) {
                    throw new Exception("Failed to create backup: $file_path");
                }
            }

            // Optimize image
            if (!$this->optimize_image($file_path)) {
                throw new Exception("Failed to optimize image: $file_path");
            }

            // Generate WebP version
            if (!$this->generate_webp($file_path)) {
                throw new Exception("Failed to generate WebP: $file_path");
            }

            // Mark as optimized
            $this->mark_as_optimized($file_path);

            return $file;
        } catch (Exception $e) {
            $this->log_error($file_path, $e->getMessage());
            throw $e; // Re-throw the exception to be caught in run_bulk_optimization
        }
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

        $css_file_path = $this->get_local_css_path($href);
        if (!$css_file_path || !file_exists($css_file_path)) {
            return $tag; // Return original tag if we can't find the CSS file
        }

        $css = file_get_contents($css_file_path);
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

    public function add_admin_menu() {
        add_management_page('Image Optimizer', 'Image Optimizer', 'manage_options', 'image-optimizer', array($this, 'admin_page'));
    }

    public function admin_page() {
        ?>
        <div class="wrap">
            <h1>Image Optimizer</h1>
            <button id="run-optimization" class="button button-primary">Run Bulk Optimization</button>
            <button id="restore-originals" class="button">Restore Original Images</button>
            <div id="optimization-summary" style="margin-top: 20px;"></div>
            <div id="progress-bar" style="display: none; margin-top: 20px;">
                <div id="progress" style="width: 0%; height: 20px; background-color: #0073aa;"></div>
            </div>
            <div id="optimization-details" style="margin-top: 20px;"></div>
        </div>
        <script>
        jQuery(document).ready(function($) {
            var totalImages = 0;
            var processedImages = 0;

            $('#run-optimization').click(function() {
                $(this).prop('disabled', true);
                $('#optimization-summary').html('');
                $('#optimization-details').html('');
                $('#progress-bar').show();
                runOptimization(0);
            });

            $('#restore-originals').click(function() {
                if (confirm('Are you sure you want to restore all original images? This action cannot be undone.')) {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'restore_original_images'
                        },
                        success: function(response) {
                            $('#optimization-summary').html(response);
                        },
                        error: function(jqXHR, textStatus, errorThrown) {
                            $('#optimization-summary').html('Error restoring images: ' + textStatus + ' - ' + errorThrown);
                        }
                    });
                }
            });

            function runOptimization(offset) {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'run_bulk_optimization',
                        offset: offset
                    },
                    success: function(response) {
                        if (response.success === false) {
                            $('#optimization-summary').html('Error: ' + response.data.message);
                            $('#run-optimization').prop('disabled', false);
                            return;
                        }

                        totalImages = response.data.total_images;
                        processedImages += response.data.processed_images.length;

                        updateProgressBar(response.data.progress);
                        updateSummary(response.data.message);
                        updateDetails(response.data.processed_images);

                        if (response.data.continue) {
                            setTimeout(function() {
                                runOptimization(response.data.offset);
                            }, 1000);
                        } else {
                            $('#run-optimization').prop('disabled', false);
                            $('#optimization-summary').append('<br><strong>Optimization complete!</strong>');
                        }
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        $('#optimization-summary').html('AJAX error: ' + textStatus + ' - ' + errorThrown + '<br>Response: ' + jqXHR.responseText);
                        $('#run-optimization').prop('disabled', false);
                    }
                });
            }

            function updateProgressBar(progress) {
                $('#progress').css('width', progress + '%');
            }

            function updateSummary(message) {
                $('#optimization-summary').html(message);
            }

            function updateDetails(processedImages) {
                var detailsHtml = '<h3>Processed Images (' + processedImages.length + '/' + totalImages + ')</h3>';
                detailsHtml += '<ul>';
                processedImages.forEach(function(image) {
                    var statusColor = image.status === 'optimized' ? 'green' : (image.status === 'skipped' ? 'orange' : 'red');
                    detailsHtml += '<li style="color: ' + statusColor + '">' + image.name + ' - ' + image.status;
                    if (image.message) {
                        detailsHtml += ' (' + image.message + ')';
                    }
                    detailsHtml += '</li>';
                });
                detailsHtml += '</ul>';
                $('#optimization-details').prepend(detailsHtml);
            }
        });
        </script>
        <?php
    }

    public function run_bulk_optimization() {
        try {
            $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;

            $query_images = new WP_Query(array(
                'post_type' => 'attachment',
                'post_mime_type' => array('image/jpeg', 'image/png'),
                'post_status' => 'inherit',
                'posts_per_page' => $this->batch_size,
                'offset' => $offset,
            ));

            $total_images = wp_count_posts('attachment')->inherit;
            $optimized_count = 0;
            $skipped_count = 0;
            $error_count = 0;

            $processed_images = array();

            foreach ($query_images->posts as $image) {
                $file_path = get_attached_file($image->ID);
                
                if ($this->is_already_optimized($file_path)) {
                    $skipped_count++;
                    $processed_images[] = array(
                        'id' => $image->ID,
                        'name' => basename($file_path),
                        'status' => 'skipped'
                    );
                    continue;
                }

                try {
                    $this->optimize_uploaded_image($file_path, 'bulk');
                    $optimized_count++;
                    $processed_images[] = array(
                        'id' => $image->ID,
                        'name' => basename($file_path),
                        'status' => 'optimized'
                    );
                } catch (Exception $e) {
                    $error_count++;
                    $processed_images[] = array(
                        'id' => $image->ID,
                        'name' => basename($file_path),
                        'status' => 'error',
                        'message' => $e->getMessage()
                    );
                    $this->log_error($file_path, $e->getMessage());
                }
            }

            $processed = $offset + $optimized_count + $skipped_count + $error_count;
            $progress = round(($processed / $total_images) * 100, 2);

            $result = array(
                'message' => "Optimization in progress. Processed: $processed/$total_images, Optimized: $optimized_count, Skipped: $skipped_count, Errors: $error_count",
                'progress' => $progress,
                'continue' => $processed < $total_images,
                'offset' => $offset + $this->batch_size,
                'processed_images' => $processed_images,
                'total_images' => $total_images
            );

            wp_send_json_success($result);
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => 'Error in run_bulk_optimization: ' . $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));
        }
    }

    public function restore_original_images() {
        $restored_count = 0;
        $error_count = 0;

        $backup_files = glob($this->backup_dir . '/*');
        foreach ($backup_files as $backup_file) {
            $original_file = str_replace($this->backup_dir, wp_upload_dir()['basedir'], $backup_file);
            if (copy($backup_file, $original_file)) {
                $restored_count++;
                $this->remove_optimized_marker($original_file);
            } else {
                $error_count++;
                $this->log_error($original_file, 'Failed to restore original image');
            }
        }

        echo "Restoration complete. Restored: $restored_count, Errors: $error_count";
        wp_die();
    }

    private function remove_optimized_marker($file_path) {
        $marker_file = dirname($file_path) . '/' . basename($file_path, '.' . pathinfo($file_path, PATHINFO_EXTENSION)) . $this->optimized_marker_file;
        if (file_exists($marker_file)) {
            unlink($marker_file);
        }
    }

    private function log_error($file_path, $error_message) {
        $log_message = date('Y-m-d H:i:s') . " - Error optimizing $file_path: $error_message\n";
        error_log($log_message, 3, $this->log_file);
    }

    private function is_already_optimized($file_path) {
        $marker_file = dirname($file_path) . '/' . basename($file_path, '.' . pathinfo($file_path, PATHINFO_EXTENSION)) . $this->optimized_marker_file;
        return file_exists($marker_file);
    }

    private function mark_as_optimized($file_path) {
        $marker_file = dirname($file_path) . '/' . basename($file_path, '.' . pathinfo($file_path, PATHINFO_EXTENSION)) . $this->optimized_marker_file;
        touch($marker_file);
    }

    private function get_local_css_path($url) {
        // Remove protocol and domain from URL
        $parsed_url = parse_url($url);
        $relative_path = isset($parsed_url['path']) ? $parsed_url['path'] : '';

        // Try to find the file in the WordPress directory
        $possible_paths = [
            ABSPATH . ltrim($relative_path, '/'),
            WP_CONTENT_DIR . ltrim($relative_path, '/wp-content'),
        ];

        foreach ($possible_paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return false;
    }
}

new ImageOptimizer();