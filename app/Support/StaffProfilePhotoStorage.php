<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Public-disk profile photos for staff (user_management.photo_path / users.photo_path).
 */
final class StaffProfilePhotoStorage
{
    public const DISK = 'public';

    public const DIRECTORY = 'health-workers/profile-photos';

    public static function store(UploadedFile $file): string
    {
        $extension = strtolower((string) $file->guessExtension());
        if ($extension === '' || ! in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
            $extension = strtolower((string) $file->getClientOriginalExtension());
        }
        if (! in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
            $extension = 'jpg';
        }

        $storedName = Str::uuid()->toString().'.'.$extension;
        $path = $file->storeAs(self::DIRECTORY, $storedName, self::DISK);

        if (! is_string($path) || $path === '') {
            throw new \RuntimeException('Health worker profile photo could not be stored.');
        }

        return str_replace('\\', '/', $path);
    }

    public static function deleteManaged(?string $path): void
    {
        if (! self::isManagedPath($path)) {
            return;
        }

        Storage::disk(self::DISK)->delete($path);
    }

    public static function url(?string $path): ?string
    {
        $normalized = str_replace('\\', '/', trim((string) $path));

        if (! self::isManagedPath($normalized)) {
            return null;
        }

        if (! Storage::disk(self::DISK)->exists($normalized)) {
            return null;
        }

        return '/storage/'.$normalized;
    }

    public static function isManagedPath(?string $path): bool
    {
        $normalized = str_replace('\\', '/', trim((string) $path));
        if ($normalized === '' || str_contains($normalized, '..')) {
            return false;
        }

        return str_starts_with($normalized, self::DIRECTORY.'/');
    }
}
