<?php

namespace App\Support;

use App\Models\DeathRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Private-disk persistence for death certificates.
 * Files are never written to the public disk and are not enumerable via URL.
 */
final class DeathCertificateStorage
{
    public const DISK = 'death_certificates';

    public const MAX_KILOBYTES = 5120;

    /** @var list<string> */
    public const ACCEPTED_EXTENSIONS = ['png', 'jpg', 'jpeg', 'pdf'];

    /** @var list<string> */
    public const ACCEPTED_MIMES = [
        'image/png',
        'image/jpeg',
        'application/pdf',
    ];

    /**
     * @return array{
     *     certificate_disk: string,
     *     certificate_path: string,
     *     certificate_original_name: string,
     *     certificate_mime: string,
     *     certificate_size: int,
     *     certificate_extension: string
     * }
     */
    public static function store(UploadedFile $file, DeathRequest $request): array
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if ($extension === '' || ! in_array($extension, self::ACCEPTED_EXTENSIONS, true)) {
            $extension = strtolower((string) $file->guessExtension());
        }

        $storedName = Str::uuid()->toString().'.'.$extension;
        $directory = $request->household_no.'/'.$request->member_id.'/'.$request->id;
        $path = $file->storeAs($directory, $storedName, self::DISK);

        if (! is_string($path) || $path === '') {
            throw new \RuntimeException('Death certificate could not be stored.');
        }

        $mime = (string) ($file->getMimeType() ?: $file->getClientMimeType() ?: '');
        if ($mime === '') {
            $mime = DemoDeath::mimeForPublicExtension($extension) ?? 'application/octet-stream';
        }

        return [
            'certificate_disk' => self::DISK,
            'certificate_path' => $path,
            'certificate_original_name' => DemoDeath::safeFilename((string) $file->getClientOriginalName())
                ?: ('death-certificate.'.$extension),
            'certificate_mime' => $mime,
            'certificate_size' => (int) $file->getSize(),
            'certificate_extension' => $extension,
        ];
    }

    public static function deleteStored(?DeathRequest $request): void
    {
        if ($request === null) {
            return;
        }

        $disk = (string) $request->certificate_disk;
        $path = (string) $request->certificate_path;
        if ($disk === '' || $path === '' || $path === 'pending') {
            return;
        }

        Storage::disk($disk)->delete($path);
    }

    public static function exists(DeathRequest $request): bool
    {
        $disk = (string) $request->certificate_disk;
        $path = (string) $request->certificate_path;

        if ($disk === '' || $path === '' || $path === 'pending') {
            return false;
        }

        return Storage::disk($disk)->exists($path);
    }

    public static function download(DeathRequest $request): StreamedResponse
    {
        abort_unless(self::exists($request), 404, 'Death certificate was not found.');

        $downloadName = DemoDeath::safeFilename((string) $request->certificate_original_name)
            ?: ('death-certificate.'.$request->certificate_extension);

        return Storage::disk((string) $request->certificate_disk)->response(
            (string) $request->certificate_path,
            $downloadName,
            [
                'Content-Type' => (string) ($request->certificate_mime ?: 'application/octet-stream'),
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ]
        );
    }
}
