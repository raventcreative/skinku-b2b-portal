<?php

namespace App\Services;

use App\Models\File;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Resizes & compresses uploaded images using PHP's built-in GD (no external
 * package). Large phone photos are shrunk to a max dimension and re-encoded as
 * JPEG to keep server storage small. Falls back to a plain store if GD cannot
 * decode the file.
 */
class ImageService
{
    /**
     * Store an uploaded file against a model's polymorphic file collection.
     * Images are resized; other file types are stored as-is. Returns the File row.
     */
    public function attach(Model $model, UploadedFile $file, string $collection, int $maxDim = 1280): File
    {
        $isImage = str_starts_with((string) $file->getClientMimeType(), 'image/')
            && @getimagesize($file->getRealPath()) !== false;

        $path = $isImage
            ? $this->storeResized($file, $collection, $maxDim)
            : $file->store($collection, 'public');

        $nextSort = (int) ($model->files()->where('collection', $collection)->max('sort_order'));
        if ($model->files()->where('collection', $collection)->exists()) {
            $nextSort += 1;
        }

        return $model->files()->create([
            'collection' => $collection,
            'disk' => 'public',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            // MIME hasil deteksi isi file (server), bukan klaim browser; gambar yang di-resize selalu JPEG.
            'mime_type' => $isImage && str_ends_with($path, '.jpg') ? 'image/jpeg' : ($file->getMimeType() ?: $file->getClientMimeType()),
            'size' => $file->getSize(),
            'sort_order' => $nextSort,
        ]);
    }

    /**
     * Store an uploaded image resized to fit within $maxDim (px) on the
     * public disk under $dir. Returns the stored relative path.
     */
    public function storeResized(UploadedFile $file, string $dir, int $maxDim = 1280, int $quality = 80): string
    {
        $tmp = $file->getRealPath();
        $info = @getimagesize($tmp);

        if ($info === false) {
            return $file->store($dir, 'public'); // not an image we can read — store as-is
        }

        [$width, $height, $type] = $info;

        $src = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($tmp),
            IMAGETYPE_PNG => @imagecreatefrompng($tmp),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : null,
            IMAGETYPE_GIF => @imagecreatefromgif($tmp),
            default => null,
        };

        if (! $src) {
            return $file->store($dir, 'public');
        }

        // Respect EXIF orientation from phone cameras (JPEG only).
        if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($tmp);
            if (! empty($exif['Orientation'])) {
                $src = $this->applyOrientation($src, (int) $exif['Orientation']);
                $width = imagesx($src);
                $height = imagesy($src);
            }
        }

        $scale = min(1, $maxDim / max($width, $height));
        $newW = max(1, (int) round($width * $scale));
        $newH = max(1, (int) round($height * $scale));

        $dst = imagecreatetruecolor($newW, $newH);
        // Flatten any transparency onto white (JPEG has no alpha).
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $newW, $newH, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $width, $height);

        $relative = trim($dir, '/').'/'.Str::uuid()->toString().'.jpg';
        Storage::disk('public')->makeDirectory($dir);
        imagejpeg($dst, Storage::disk('public')->path($relative), $quality);

        imagedestroy($src);
        imagedestroy($dst);

        return $relative;
    }

    /** Rotate/flip a GD image according to an EXIF orientation flag. */
    private function applyOrientation($img, int $orientation)
    {
        switch ($orientation) {
            case 3:
                return imagerotate($img, 180, 0);
            case 6:
                return imagerotate($img, -90, 0);
            case 8:
                return imagerotate($img, 90, 0);
            default:
                return $img;
        }
    }
}
