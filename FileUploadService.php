<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Exceptions\HttpException;
use App\Core\Logger;

/**
 * The only place in the system that accepts a file from a client.
 *
 * File upload is the highest-risk endpoint in any commerce platform, so nothing
 * here trusts the client. In order:
 *
 *   1. PHP's own upload error code is checked first.
 *   2. Size is capped before any parsing.
 *   3. The MIME type is read from the file's *contents* with finfo, never from
 *      the browser-supplied $_FILES['type'], which is attacker-controlled.
 *   4. getimagesize() must agree that this is a real, decodable raster image
 *      with sane dimensions — this is what stops a PHP payload wearing a .jpg
 *      extension.
 *   5. SVG is rejected outright: it is XML, and XML means script and XXE.
 *   6. The stored filename is generated, never derived from user input, so
 *      path traversal and double-extension tricks have nothing to work with.
 *   7. The file is written with 0644 and the directory carries an .htaccess
 *      that refuses to execute anything.
 */
final class FileUploadService
{
    /** Extension is chosen by us from the detected MIME type, not from the upload. */
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $file A single entry from $_FILES
     *
     * @return array{file_path:string, mime_type:string, file_size_bytes:int, width_px:int, height_px:int}
     */
    public function storeImage(array $file, string $collection): array
    {
        $this->assertUploadSucceeded($file);

        $maxBytes = (int) $this->config->get('uploads.max_image_bytes', 5_242_880);
        $size = (int) ($file['size'] ?? 0);

        if ($size <= 0 || $size > $maxBytes) {
            throw new HttpException(
                sprintf('Image must be between 1 byte and %d KB.', (int) ($maxBytes / 1024)),
                422,
                ['file' => ['File size is outside the allowed range.']]
            );
        }

        $temporaryPath = (string) ($file['tmp_name'] ?? '');

        if (!is_uploaded_file($temporaryPath)) {
            // Guards against a caller fabricating $_FILES to read an arbitrary
            // path off the server.
            throw new HttpException('Upload could not be verified.', 422);
        }

        $mimeType = $this->detectMimeType($temporaryPath);

        if (!array_key_exists($mimeType, self::MIME_EXTENSIONS)) {
            throw new HttpException(
                'Only JPEG, PNG, WebP and GIF images are accepted.',
                422,
                ['file' => ['Detected file type: ' . $mimeType]]
            );
        }

        $dimensions = $this->assertDecodableImage($temporaryPath, $mimeType);

        $extension = self::MIME_EXTENSIONS[$mimeType];
        $relativeDirectory = sprintf('%s/%s', trim($collection, '/'), date('Y/m'));
        $absoluteDirectory = $this->uploadRoot() . '/' . $relativeDirectory;

        if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0o755, true) && !is_dir($absoluteDirectory)) {
            throw new HttpException('Upload directory could not be created.', 500);
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        $absolutePath = $absoluteDirectory . '/' . $filename;

        if (!move_uploaded_file($temporaryPath, $absolutePath)) {
            throw new HttpException('Uploaded file could not be stored.', 500);
        }

        chmod($absolutePath, 0o644);

        $relativePath = $relativeDirectory . '/' . $filename;

        $this->logger->info('Image stored', [
            'path' => $relativePath,
            'mime_type' => $mimeType,
            'bytes' => $size,
            'dimensions' => $dimensions['width'] . 'x' . $dimensions['height'],
        ], 'uploads');

        return [
            'file_path' => $relativePath,
            'mime_type' => $mimeType,
            'file_size_bytes' => $size,
            'width_px' => $dimensions['width'],
            'height_px' => $dimensions['height'],
        ];
    }

    /**
     * Deletes a stored file. The path is confined to the upload root, so a
     * crafted value cannot reach outside it.
     */
    public function delete(?string $relativePath): bool
    {
        if ($relativePath === null || $relativePath === '') {
            return false;
        }

        $root = realpath($this->uploadRoot());

        if ($root === false) {
            return false;
        }

        $target = realpath($root . '/' . ltrim($relativePath, '/'));

        if ($target === false || !str_starts_with($target, $root . DIRECTORY_SEPARATOR)) {
            $this->logger->warning('Refused to delete a path outside the upload root', [
                'requested' => $relativePath,
            ], 'uploads');

            return false;
        }

        return is_file($target) && unlink($target);
    }

    public function publicUrl(?string $relativePath): ?string
    {
        if ($relativePath === null || $relativePath === '') {
            return null;
        }

        return rtrim((string) $this->config->get('app.url'), '/')
            . '/' . trim((string) $this->config->get('uploads.public_prefix', 'uploads'), '/')
            . '/' . ltrim($relativePath, '/');
    }

    /** @param array<string, mixed> $file */
    private function assertUploadSucceeded(array $file): void
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error === UPLOAD_ERR_OK) {
            return;
        }

        $message = match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The image is larger than the server allows.',
            UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE => 'No file was received.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not write the upload.',
            UPLOAD_ERR_EXTENSION => 'The upload was blocked by a server extension.',
            default => 'The upload failed.',
        };

        throw new HttpException($message, $error === UPLOAD_ERR_NO_FILE ? 422 : 400);
    }

    private function detectMimeType(string $path): string
    {
        if (!function_exists('finfo_open')) {
            throw new HttpException('Server is missing the fileinfo extension; uploads are disabled.', 500);
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            throw new HttpException('File type could not be determined.', 500);
        }

        $mimeType = finfo_file($finfo, $path);
        finfo_close($finfo);

        return $mimeType === false ? 'application/octet-stream' : strtolower($mimeType);
    }

    /**
     * @return array{width:int, height:int}
     */
    private function assertDecodableImage(string $path, string $mimeType): array
    {
        $info = @getimagesize($path);

        if ($info === false || !isset($info[0], $info[1])) {
            throw new HttpException(
                'The file is not a readable image.',
                422,
                ['file' => ['The image could not be decoded.']]
            );
        }

        // getimagesize must independently agree with finfo. A mismatch means
        // the file is lying about what it is.
        if (isset($info['mime']) && strtolower((string) $info['mime']) !== $mimeType) {
            throw new HttpException('The file type is inconsistent and was rejected.', 422);
        }

        $width = (int) $info[0];
        $height = (int) $info[1];

        $minEdge = (int) $this->config->get('uploads.min_image_edge_px', 200);
        $maxEdge = (int) $this->config->get('uploads.max_image_edge_px', 6000);

        if ($width < $minEdge || $height < $minEdge) {
            throw new HttpException(
                sprintf('Image must be at least %d x %d pixels.', $minEdge, $minEdge),
                422
            );
        }

        // Caps decompression-bomb exposure before any resize work happens.
        if ($width > $maxEdge || $height > $maxEdge) {
            throw new HttpException(
                sprintf('Image edges must not exceed %d pixels.', $maxEdge),
                422
            );
        }

        return ['width' => $width, 'height' => $height];
    }

    private function uploadRoot(): string
    {
        return rtrim((string) $this->config->get('uploads.root_path', APP_ROOT . '/public/uploads'), '/');
    }
}
