<?php
/**
 * WebP Image Converter
 * 
 * A high-performance PHP command-line tool for batch converting JPEG images to WebP format.
 * This script provides enterprise-grade image conversion with comprehensive features for
 * managing large-scale image processing tasks.
 *
 * Key Features:
 * - Recursive directory traversal with symbolic link support
 * - EXIF data preservation in JSON format
 * - Automatic image rotation based on EXIF orientation
 * - Configurable WebP quality settings (0-100)
 * - Real-time progress tracking with detailed statistics
 * - Storage space optimization tracking
 * - Memory-efficient processing with proper resource cleanup
 * - Comprehensive error handling and reporting
 * - Secure file permissions management
 *
 * Usage Example:
 * php webp-conv.php -rvcd /path/to/images -q 85
 *
 * @author Benjamin Lewis <benjamin[at]astions[dot]com>
 * @copyright 2025 Benjamin Lewis
 * @license MIT
 * @version 1.1.0
 * @link https://github.com/agtlewis/php-webp-conv
 * @see README.md for detailed documentation
 */

try {
    ini_set('display_errors', 1);

    $options = getopt('d:hq:xrcfv', [
        'directory:',
        'help',
        'quality:',
        'noexif',
        'rotate',
        'cleanup',
        'verbose',
        'follow-symlinks'
    ]);

    # Check if the PHP environment is compatible.
    WebpHelpers::checkCompatibility();

    # Need help?
    if (empty($options) || WebpHelpers::hasArg($options, ['help', 'h'])) {
        WebpHelpers::printHelp();
    }

    # Get the directory from the command line arguments.
    if (false === $directory = WebpHelpers::getArgValue($options, ['directory', 'd'], false)) {
        WebpHelpers::printHelp();
    }

    WebpHelpers::checkDirectoryPermissions($directory);

    # Recursively iterate the given directory
    try {
        $directoryIterator = new RecursiveDirectoryIterator(
            $directory,
            (WebpHelpers::hasArg($options, ['follow-symlinks', 'f']) ? RecursiveDirectoryIterator::FOLLOW_SYMLINKS : 0) | RecursiveDirectoryIterator::SKIP_DOTS
        );
    } catch (UnexpectedValueException $e) {
        throw new Exception("Failed to open directory: {$directory}");
    }

    # Filter only files then use Regex to match only `.jpg` or `.jpeg`
    $iterator       = new RecursiveIteratorIterator($directoryIterator, RecursiveIteratorIterator::LEAVES_ONLY);
    $regexIterator  = new RegexIterator($iterator, '/\.(jpe?g)$/i', RecursiveRegexIterator::MATCH);

    # Initialize counters
    $stats = [
        'total'     => iterator_count($regexIterator),
        'processed' => 0,
        'success'   => 0,
        'failed'    => 0,
        'bytes_o'   => 0,
        'bytes_n'   => 0
    ];
    
    # Reset iterator after counting
    $regexIterator->rewind();

    # Initialize the EXIF directory variable
    $lastExifParentDir = null;

    foreach ($regexIterator as $file) {
        $stats['processed']++;
        
        try {
            $originalPath   = $file->getRealPath();
            $currentDir     = $file->getPath();
            $fileName       = $file->getFilename();
            $webpPath       = $currentDir . DIRECTORY_SEPARATOR . preg_replace('/\.(jpe?g)$/i', '.webp', $fileName);
            
            if (!WebpHelpers::hasArg($options, ['noexif', 'x'])) {
                # Don't attempt to create the EXIF directory for every file
                if ($currentDir !== $lastExifParentDir) {
                    if (false === $exifDir = WebpHelpers::createExifDirectory($currentDir)) {
                        throw new RuntimeException("Critical Error: Cannot create or access EXIF directory at {$currentDir}. Please check permissions.");
                    }

                    $lastExifParentDir = $currentDir;
                }

                $exifPath = $exifDir . DIRECTORY_SEPARATOR . preg_replace('/\.(jpe?g)$/i', '.exif.json', $file->getFilename());
                $exifData = @exif_read_data($originalPath);
            }

            if (false === $image = $image_bak = imagecreatefromjpeg($originalPath)) {
                fwrite(STDOUT, "Error: Failed to create image from {$originalPath}\n");
                $stats['failed']++;
                continue;
            }

            if (WebpHelpers::hasArg($options, ['rotate', 'r'])) {
                if (false === $image = WebpHelpers::rotateFromExif($image, $exifData ?? null)) {
                    fwrite(STDOUT, "Error: Rotation failed for {$originalPath}\n");

                    # Restore the non rotated image
                    $image = $image_bak;
                }
            }

            if (false === $imageWebResult = imagewebp(
                $image, 
                $webpPath, 
                WebpHelpers::validateQuality(WebpHelpers::getArgValue($options, ['quality', 'q'], 90))
            )) {
                fwrite(STDOUT, "Error: Failed to create WebP image {$webpPath}\n");
                WebpHelpers::maybeDestroyImage($image);
                continue;
            }

            # State is used to track successful operations
            $state = [
                'webp' => false,
                'exif' => false,
            ];

            /**
             * A more in depth check to ensure the WebP file was created successfully.  Documentation
             * states "Caution However, if libgd fails to output the image, this function returns true."
             * 
             * @see https://www.php.net/manual/en/function.imagewebp.php
             */
            if ($imageWebResult && file_exists($webpPath) && filesize($webpPath) > 0) {
                $state['webp'] = true;
                
                if (WebpHelpers::hasArg($options, ['cleanup', 'c'])) {
                    $stats['bytes_o'] += filesize($originalPath);
                    $stats['bytes_n'] += filesize($webpPath);
                }
            } else {
                fwrite(STDOUT, "Error: WebP conversion failed for {$originalPath} - image file is empty or missing\n");

                if (file_exists($webpPath)) {
                    unlink($webpPath);
                }

                WebpHelpers::maybeDestroyImage($image);
                continue;
            }

            if (WebpHelpers::hasArg($options, ['verbose', 'v'])) {
                WebpHelpers::printProgress($stats);
            }

            if (WebpHelpers::hasArg($options, ['noexif', 'x'])) {
                $state['exif'] = true;
            } else {
                # No EXIF data to write
                if (empty($exifData)) {
                    $state['exif'] = true;
                } else {
                    $data = [
                        'exif' => $exifData
                    ];

                    if (false !== file_put_contents($exifPath, json_encode($data))) {
                        if (!@chmod($exifPath, 0660)) {
                            fwrite(STDOUT, "Warning: Failed to set permissions on EXIF file {$exifPath}\n");
                        }

                        $state['exif'] = true;
                    } else {
                        fwrite(STDOUT, "Warning: Failed to save EXIF data for {$originalPath}\n");
                    }
                }
            }

            # Delete the original JPEG file if the --cleanup flag is set and both the WebP and EXIF states are true
            if (WebpHelpers::hasArg($options, ['cleanup', 'c'])) {
                if ($state['webp'] && $state['exif']) {
                    unlink($originalPath);
                } else {
                    fwrite(STDOUT, "Warning: Cleanup failed for {$originalPath} - WebP or EXIF data is missing\n");
                }
            }

            WebpHelpers::maybeDestroyImage($image);

            if ($state['webp'] && $state['exif']) {
                $stats['success']++;
            } else {
                $stats['failed']++;
            }

        } catch (UnexpectedValueException $e) {
            $stats['failed']++;
            fwrite(STDERR, "Error processing file {$originalPath}: {$e->getMessage()}\n");
            continue;
        }
    }
    
    # Print final statistics
    if (WebpHelpers::hasArg($options, ['verbose', 'v'])) {
        WebpHelpers::printFinalStats($stats);
    }

} catch (Exception $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

class WebpHelpers
{
    /**
     * Checks if the server environment is compatible.
     *
     * @throws RuntimeException If incompatibilities are found.
     */
    public static function checkCompatibility() {
        if (!extension_loaded('gd')) {
            throw new RuntimeException("GD extension is required!");
        }

        if (!extension_loaded('json')) {
            throw new RuntimeException("JSON extension is required!");
        }

        if (version_compare(PHP_VERSION, '7.1', '<')) {
            throw new RuntimeException("PHP version 7.1 or higher is required!");
        }
    }

    /**
     * Get an argument value, with a default value if not present
     *
     * @param array $array The argument array to check
     * @param array $keys The keys to check for
     * @param mixed $default The default value to return if the key is not set.
     * @return mixed The value from the array or the default value if the key is not set.
     */
    public static function getArgValue(array $array, array $keys, $default = null) {
        foreach ($keys as $key) {
            if (array_key_exists($key, $array)) {
                return $array[$key];
            }
        }

        return $default;
    }

    /**
     * Check if an argument is present
     *
     * @param array $array The argument array
     * @param array $keys The keys to check for
     * @return bool True if the argument is present, false otherwise
     */
    public static function hasArg(array $array, array $keys) {
        foreach ($keys as $key) {
            if (array_key_exists($key, $array)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create a directory for storing EXIF information.
     *
     * @param string $path The base path where the EXIF directory will be created.
     * @return string The path to the created EXIF directory.
     */
    public static function createExifDirectory(string $path) {
        $res = false;
        $dir = $path . DIRECTORY_SEPARATOR . '.exif';

        if (!file_exists($dir)) {
            $res = mkdir($dir, 0770, true);
        }

        if (!$res && !is_dir($dir) || !is_writable($dir)) {
            return false;
        }

        return $dir;
    }

    /**
     * Rotate an image based on the EXIF data.
     * 
     * @param mixed $img An imagecreatefromjpeg resource or GDImage instance
     * @param mixed $exif An exif data array
     * @return mixed The (possibly) rotated image resource or GDImage instance
     */
    public static function rotateFromExif($img, $exif) {
        $deg = 0;

        if (!empty($exif) && isset($exif['Orientation'])) {
            switch ($exif['Orientation']) {
                case 3:
                    $deg = 180;
                    break;
                case 6:
                    $deg = -90;
                    break;
                case 8:
                    $deg = 90;
                break;
            }
        }

        if ($deg !== 0) {
            $img = imagerotate($img, $deg, 0);
        }

        return $img;
    }

    /**
     * Destroy an image resource if the PHP version is less than 8.0.0.
     * 
     * Prior to PHP 8.0.0, imagedestroy() freed any memory associated with 
     * the image resource. As of 8.0.0, the GD extension uses objects instead 
     * of resources, and objects cannot be explicitly closed.
     * 
     * @see https://www.php.net/manual/en/function.imagedestroy.php
     *
     * @param GdImage|resource $image The image to destroy.
     */
    public static function maybeDestroyImage($image) {
        if ($image && is_resource($image) && version_compare(PHP_VERSION, '8.0.0', '<')) {
            /** @var GdImage|resource $image */
            imagedestroy($image);
        }
    }

    /**
     * Output the help text to the console
     * @return never
     */
    public static function printHelp() {
        $help = implode("\n", [
            'WebP Image Converter - Usage Guide                                                 ',
            '                                                                                   ',
            'Required Arguments:                                                                ',
            '    --directory, -d <path>    Directory containing images to convert               ',
            '                                                                                   ',
            'Optional Arguments:                                                                ',
            '    --help, -h                Show this help message                               ',
            '    --quality, -q <0-100>     WebP output quality (default: 90)                    ',
            '    --verbose, -v             Show progress messages                               ',
            '    --noexif, -x              Do not save EXIF data                                ',
            '    --rotate, -r              Auto-rotate images based on EXIF orientation         ',
            '    --cleanup, -c             Delete .JPEG versions upon successful conversion     ',
            '    --follow-symlinks, -f     Follow symbolic links when traversing directories    ',
            '                                                                                   ',
            'Examples:                                                                          ',
            '    php webp-conv.php --dir /path/to/images                                        ',
            '    php webp-conv.php -rd /path/to/images -q 70                                    ',
            '    php webp-conv.php -xfd /path/to/images                                         ',
            '    php webp-conv.php -rvcd /path/to/images -q 85                                  ',
            '                                                                                   ',
            'Notes:                                                                             ',
            '    - Original JPEG files will be replaced with WebP versions                      ',
            '    - EXIF data is preserved in .exif/filename.exif.json                           ',
            '    - Requires PHP 7.1+ with GD and JSON extensions                                ',
            '                                                                                   '
        ]);

        fwrite(STDOUT, "\n{$help}\n");
        exit(0);
    }

    /**
     * Format bytes into raw number with specific unit
     *
     * @param int $bytes Number of bytes
     * @return float Raw number in appropriate unit
     */
    private static function formatBytesRaw($bytes) {
        $neg    = $bytes < 0;
        $bytes  = abs($bytes);
        $pow    = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow    = min($pow, 4);
        
        $value  = round($bytes / pow(1024, $pow), 2);

        return $neg ? -$value : $value;
    }

    /**
     * Print progress information to console
     * 
     * @param array $stats Statistics array containing progress information
     */
    public static function printProgress($stats) {
        $percent = sprintf("%.1f", ($stats['processed'] / $stats['total']) * 100);
        $message = sprintf("\rProgress: %d/%d (%s%%) - Success: %d, Failed: %d", 
            $stats['processed'],
            $stats['total'],
            $percent,
            $stats['success'],
            $stats['failed']
        );

        if (isset($stats['bytes_o']) && $stats['bytes_o'] > 0) {
            $saved = $stats['bytes_o'] - $stats['bytes_n'];
            $percent_saved = sprintf("%.1f", ($saved / $stats['bytes_o']) * 100);
            $prefix = $saved >= 0 ? "saved" : "increased";
            $message .= sprintf(" - Storage %s: %.2f %s (%s%%)", 
                $prefix,
                abs(self::formatBytesRaw($saved)),
                self::getByteUnit(abs($saved)),
                rtrim($percent_saved, '%')
            );
        }

        fwrite(STDOUT, $message);
    }

    /**
     * Get the appropriate byte unit
     *
     * @param int $bytes Number of bytes
     * @return string Unit (B, KB, MB, GB, TB)
     */
    private static function getByteUnit($bytes) {
        $units  = ['B', 'KB', 'MB', 'GB', 'TB'];
        $pow    = floor(($bytes ? log($bytes) : 0) / log(1024));

        return $units[min($pow, 4)];
    }

    /**
     * Print final statistics in a formatted table
     * 
     * @param array $stats Statistics array containing final counts
     */
    public static function printFinalStats($stats) {
        $rows = [
            ['Category', 'Value', 'Percentage'],
            ['─', '─', '─'],
            ['Total Files', $stats['total'], '100.0%'],
            ['Successful', $stats['success'], sprintf('%.1f%%', ($stats['success'] / $stats['total']) * 100)],
            ['Failed', $stats['failed'], sprintf('%.1f%%', ($stats['failed'] / $stats['total']) * 100)],
        ];

        if (isset($stats['bytes_o']) && $stats['bytes_o'] > 0) {
            $saved = $stats['bytes_o'] - $stats['bytes_n'];
            $percent_saved = sprintf('%.1f%%', ($saved / $stats['bytes_o']) * 100);
            $storage_word = $saved >= 0 ? 'Saved' : 'Increased';
            
            $rows[] = ['─', '─', '─'];
            $rows[] = ['Original Size', self::formatBytes($stats['bytes_o']), '100.0%'];
            $rows[] = ['Converted Size', self::formatBytes($stats['bytes_n']), sprintf('%.1f%%', ($stats['bytes_n'] / $stats['bytes_o']) * 100)];
            $rows[] = ["Storage $storage_word", self::formatBytes(abs($saved)), $percent_saved];
        }

        # Calculate column widths
        $widths = [];
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, mb_strlen($cell));
            }
        }

        # Print the table
        fwrite(STDOUT, "\n\nConversion Results:\n");
        
        foreach ($rows as $i => $row) {
            if ($row[0] === '─') {
                # Separator row
                fwrite(STDOUT, '+' . str_repeat('─', $widths[0] + 2) . '+' . str_repeat('─', $widths[1] + 2) . '+' . str_repeat('─', $widths[2] + 2) . "+\n");
                continue;
            }

            # Data row
            $formatted_row = array_map(function($cell, $i) use ($widths) {
                return str_pad($cell, $widths[$i], ' ', STR_PAD_RIGHT);
            }, $row, array_keys($row));

            fwrite(STDOUT, '| ' . implode(' | ', $formatted_row) . " |\n");

            # Header separator
            if ($i === 0) {
                fwrite(STDOUT, '+' . str_repeat('═', $widths[0] + 2) . '+' . str_repeat('═', $widths[1] + 2) . '+' . str_repeat('═', $widths[2] + 2) . "+\n");
            }
        }

        # Bottom border
        fwrite(STDOUT, '+' . str_repeat('─', $widths[0] + 2) . '+' . str_repeat('─', $widths[1] + 2) . '+' . str_repeat('─', $widths[2] + 2) . "+\n\n");
    }

    /**
     * Validate quality parameter
     * 
     * @param mixed $quality The quality value to validate
     * @return int Validated quality between 0 and 100
     */
    public static function validateQuality($quality) {
        return max(0, min(100, (int) $quality));
    }

    /**
     * Check if directory is writable and accessible
     * 
     * @param string $directory Directory to check
     * @throws RuntimeException if directory is not writable
     */
    public static function checkDirectoryPermissions($directory) {
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException("Directory {$directory} is not writable");
        }
    }

    /**
     * Format bytes into human readable format
     *
     * @param int $bytes Number of bytes
     * @param int $precision Decimal precision
     * @return string Formatted string
     */
    public static function formatBytes($bytes, $precision = 2) {
        $units  = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes  = max($bytes, 0);
        $pow    = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow    = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Validate that a file is actually a JPEG image
     *
     * @param string $filepath Path to the file to check
     * @return bool True if file is a valid JPEG
     */
    public static function isValidJpeg(string $filepath) {
        $finfo      = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType   = finfo_file($finfo, $filepath);

        finfo_close($finfo);
        
        return in_array($mimeType, ['image/jpeg', 'image/jpg']);
    }
}


