# WebP Image Converter

A high-performance PHP command-line tool for batch converting JPEG images to WebP format while preserving EXIF data. This enterprise-grade converter is designed for handling large-scale image processing tasks with robust error handling and detailed progress tracking.

## Features

- 🚀 Recursive directory processing with symbolic link support
- 📊 EXIF data preservation in JSON format
- 🔄 Automatic image rotation based on EXIF orientation
- ⚙️ Configurable WebP quality settings (0-100)
- 🔗 Optional symbolic link following
- 🧹 Optional cleanup of original files
- 📈 Real-time progress tracking with:
  - Conversion progress percentage
  - Success/failure counts
  - Storage space savings/increases
  - Detailed final statistics
- 🔒 Security features:
  - Secure file permissions handling
  - Input validation
  - Protected EXIF storage
- 💾 Performance optimizations:
  - Memory-efficient processing
  - Resource cleanup
  - Filtered directory traversal

## Requirements

- PHP 7.1 or higher
- GD extension
- JSON extension

## Installation

1. Clone the repository:
```bash
git clone https://github.com/agtlewis/php-webp-conv.git
cd php-webp-conv
```

2. Make the script executable (optional):
```bash
chmod +x webp-conv.php
```

## Usage

### Basic Usage
```bash
php webp-conv.php --directory /path/to/images
```

### Full Options
```bash
Required Arguments:
    --directory, -d <path>    Directory containing images to convert

Optional Arguments:
    --help, -h                Show this help message
    --quality, -q <0-100>     WebP output quality (default: 90)
    --verbose, -v             Show progress messages
    --noexif, -x              Do not save EXIF data
    --rotate, -r              Auto-rotate images based on EXIF orientation
    --cleanup, -c             Delete JPEG versions upon successful conversion
    --follow-symlinks, -f     Follow symbolic links when traversing directories
```

### Example Commands

Convert images with default settings:
```bash
php webp-conv.php --directory /path/to/images
```

Convert with rotation and 70% quality:
```bash
php webp-conv.php -rd /path/to/images -q 70
```

Convert, cleanup originals, and show progress:
```bash
php webp-conv.php -vcd /path/to/images -q 75
```

## Progress Tracking

When using the verbose flag (-v), you'll see real-time progress including:
- Files processed/total
- Success/failure count
- Storage space saved (when using cleanup flag)

Example output:
```
Progress: 14535/14535 (100.0%) - Success: 14534, Failed: 0 - Storage saved: 484.96 MB (52.7%)
```

## Final Statistics

After completion, the script optionally displays comprehensive statistics:
```
Conversion Results:
| Category       | Value     | Percentage |
+════════════════+═══════════+════════════+
+────────────────+───────────+────────────+
| Total Files    | 14535     | 100.0%     |
| Successful     | 14535     | 100.0%     |
| Failed         | 0         | 0.0%       |
+────────────────+───────────+────────────+
| Original Size  | 920.52 MB | 100.0%     |
| Converted Size | 435.56 MB | 47.3%      |
| Storage Saved  | 484.96 MB | 52.7%      |
+────────────────+───────────+────────────+
```

## EXIF Data Preservation

EXIF data is stored in a hidden `.exif` directory alongside the original images. Each JPEG's EXIF data is saved as a JSON file with the naming pattern `original_name.exif.json`.

## Security

- The script enforces secure file permissions (0770) for the EXIF directory
- Input validation is performed on all command-line arguments
- Error handling prevents script termination on individual file failures

## Performance

- Memory-efficient processing with proper resource cleanup
- Processes files one at a time to maintain low memory footprint
- Optimized directory traversal with filtered iterators
- Storage space optimization tracking

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

MIT License - See LICENSE file for details

## Author

Benjamin Lewis  
Email: benjamin[at]astions[dot]com
