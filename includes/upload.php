<?php
/**
 * Secure Image Upload Validator
 * Validates uploaded vehicle images by:
 * 1. PHP upload error code
 * 2. File size limit (max 3 MB)
 * 3. Actual file content MIME type via finfo_file() and getimagesize()
 * 4. Safe random generated filename (never trusts user-supplied filename)
 */

class UploadValidator
{
    private const MAX_BYTES = 3145728; // 3 MB

    private const ALLOWED_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    public static function handleVehicleImageUpload(array $fileInput): array
    {
        if (empty($fileInput['name']) || ($fileInput['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['uploaded' => false, 'error' => null, 'path' => null];
        }

        if (($fileInput['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return ['uploaded' => false, 'error' => 'Image upload failed with error code ' . (int)$fileInput['error'] . '.', 'path' => null];
        }

        if (($fileInput['size'] ?? 0) > self::MAX_BYTES || ($fileInput['size'] ?? 0) <= 0) {
            return ['uploaded' => false, 'error' => 'Uploaded image must be between 1 byte and 3 MB.', 'path' => null];
        }

        $tmpPath = $fileInput['tmp_name'] ?? '';
        if ($tmpPath === '' || !is_file($tmpPath)) {
            return ['uploaded' => false, 'error' => 'Temporary upload file could not be verified.', 'path' => null];
        }

        // Verify actual file content MIME type
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($tmpPath);

        if (!isset(self::ALLOWED_MIMES[$detectedMime])) {
            return [
                'uploaded' => false,
                'error'    => 'Unsupported image format (' . htmlspecialchars((string)$detectedMime) . '). Only JPG, PNG, and WEBP images are allowed.',
                'path'     => null,
            ];
        }

        // Verify valid raster image dimensions via getimagesize
        $imgInfo = @getimagesize($tmpPath);
        if ($imgInfo === false || $imgInfo[0] < 50 || $imgInfo[1] < 50) {
            return [
                'uploaded' => false,
                'error'    => 'File content is not a valid image (minimum 50x50 pixels required).',
                'path'     => null,
            ];
        }

        $ext = self::ALLOWED_MIMES[$detectedMime];
        $safeFilename = 'vehicle_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;

        $uploadDir = __DIR__ . '/../assets/images/uploads';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0775, true);
        }

        $destination = $uploadDir . '/' . $safeFilename;
        if (!move_uploaded_file($tmpPath, $destination) && !@rename($tmpPath, $destination)) {
            return ['uploaded' => false, 'error' => 'Failed to save uploaded image to server storage.', 'path' => null];
        }

        return [
            'uploaded' => true,
            'error'    => null,
            'path'     => 'assets/images/uploads/' . $safeFilename,
        ];
    }
}

