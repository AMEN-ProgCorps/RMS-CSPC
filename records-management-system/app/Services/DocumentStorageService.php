<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentStorageService
{
    /**
     * Store an uploaded file organized by Office and Subsystem (DTS or RDP).
     *
     * @param UploadedFile|string|resource $file
     * @param string $subsystem 'DTS' or 'RDP'
     * @param User|null $user
     * @param string|null $customDocumentId
     * @param string|null $originalFilename
     * @return array Contains document_id, document_name, document_path, file_size, user_office
     */
    public static function storeUpload(
        $file,
        string $subsystem = 'DTS',
        ?User $user = null,
        ?string $customDocumentId = null,
        ?string $originalFilename = null
    ): array {
        $subsystem = strtoupper(trim($subsystem));
        if (!in_array($subsystem, ['DTS', 'RDP'])) {
            $subsystem = 'DTS';
        }

        // 1. Resolve Uploader & Office
        $user = $user ?: auth()->user();
        $officeCode = self::resolveOfficeCode($user);
        $officeFolderName = Str::slug($officeCode, '_');
        if (empty($officeFolderName)) {
            $officeFolderName = 'GENERAL';
        }
        $officeFolderName = strtoupper($officeFolderName);

        // 2. Prepare File Metadata & Content
        if ($file instanceof UploadedFile) {
            $originalName = $originalFilename ?: $file->getClientOriginalName();
            $mimeType = $file->getClientMimeType() ?: $file->getMimeType();
            $fileContent = file_get_contents($file->getRealPath());
            $fileSize = $file->getSize() ?: strlen($fileContent);
            $extension = $file->getClientOriginalExtension() ?: 'pdf';
        } else {
            $fileContent = is_resource($file) ? stream_get_contents($file) : (string) $file;
            $originalName = $originalFilename ?: 'document_' . time() . '.pdf';
            $fileSize = strlen($fileContent);
            $extension = pathinfo($originalName, PATHINFO_EXTENSION) ?: 'pdf';
            $mimeType = 'application/' . $extension;
        }

        $documentId = $customDocumentId ?: 'DOC-' . strtoupper(Str::random(10));
        $safeBaseName = Str::slug(pathinfo($originalName, PATHINFO_FILENAME), '_');
        $storedFileName = "{$documentId}_{$safeBaseName}.{$extension}";
        $relativePath = "{$officeFolderName}/{$subsystem}/{$storedFileName}";

        // 3. Optimize Folder Creation using folder_data Database Caching
        self::ensureDriveFolderStructure($officeFolderName, $subsystem, $fileSize);

        // 4. Save Local Copy (for fast preview & optimized serving)
        $localPath = "uploads/{$relativePath}";
        Storage::disk('local')->put("private/{$localPath}", $fileContent);

        // 5. Upload to Google Drive Cloud Storage
        try {
            Storage::disk('google')->put($relativePath, $fileContent);
        } catch (\Throwable $e) {
            logger()->error("Google Drive upload failed for {$relativePath}: " . $e->getMessage());
        }

        // 6. Insert / Update document_data Record
        // Ensure document_name is unique if another record already has it
        $nameConflict = DB::table('document_data')
            ->where('document_name', $originalName)
            ->where('document_id', '!=', $documentId)
            ->first();

        if ($nameConflict) {
            $ext = pathinfo($originalName, PATHINFO_EXTENSION);
            $base = pathinfo($originalName, PATHINFO_FILENAME);
            $originalName = "{$base}_" . strtoupper(Str::random(4)) . ($ext ? ".{$ext}" : "");
        }

        $existingDoc = DB::table('document_data')->where('document_id', $documentId)->first();

        if ($existingDoc) {
            DB::table('document_data')->where('document_id', $documentId)->update([
                'document_name' => $originalName,
                'document_path' => $relativePath,
                'uploaded_by'   => $user?->id ?: $existingDoc->uploaded_by,
                'user_office'   => $officeCode ?: $existingDoc->user_office,
                'file_size'     => (string) $fileSize,
                'file_type'     => $mimeType,
                'is_active'     => true,
                'date_modified' => now(),
                'date_deleted'  => now(),
            ]);
        } else {
            DB::table('document_data')->insert([
                'document_id'   => $documentId,
                'document_name' => $originalName,
                'document_path' => $relativePath,
                'uploaded_by'   => $user?->id,
                'user_office'   => $officeCode,
                'file_size'     => (string) $fileSize,
                'file_type'     => $mimeType,
                'is_active'     => true,
                'date_added'    => now(),
                'date_modified' => now(),
                'date_deleted'  => now(),
            ]);
        }

        return [
            'document_id'   => $documentId,
            'document_name' => $originalName,
            'document_path' => $relativePath,
            'local_path'    => "private/{$localPath}",
            'user_office'   => $officeCode,
            'file_size'     => $fileSize,
            'file_type'     => $mimeType,
        ];
    }

    /**
     * Delete/Purge a document from Google Drive, Local Storage cache, and document_data database.
     *
     * @param string|null $relativePath Relative storage path e.g. "DEV/DTS/DOC-1234_filename.pdf"
     */
    public static function deleteDocument(?string $relativePath): void
    {
        if (empty($relativePath)) {
            return;
        }

        try {
            // 1. Delete from Google Drive Cloud Storage
            if (Storage::disk('google')->exists($relativePath)) {
                Storage::disk('google')->delete($relativePath);
            }
        } catch (\Throwable $e) {
            logger()->error("Google Drive delete failed for {$relativePath}: " . $e->getMessage());
        }

        try {
            // 2. Delete from Local Storage Cache
            $localPath = "private/uploads/{$relativePath}";
            if (Storage::disk('local')->exists($localPath)) {
                Storage::disk('local')->delete($localPath);
            }
        } catch (\Throwable $e) {
            logger()->error("Local storage delete failed for {$relativePath}: " . $e->getMessage());
        }

        try {
            // 3. Delete / Purge record from document_data database table
            DB::table('document_data')->where('document_path', $relativePath)->delete();
        } catch (\Throwable $e) {
            logger()->error("Database delete failed for document_data ({$relativePath}): " . $e->getMessage());
        }
    }

    /**
     * Ensure folder structure exists on Google Drive using folder_data database cache.
     */
    protected static function ensureDriveFolderStructure(string $officeName, string $subsystem, int $fileSize): void
    {
        $subsystem = strtoupper($subsystem);
        $isDts = ($subsystem === 'DTS');
        $isRdp = ($subsystem === 'RDP');

        try {
            $folderRecord = DB::table('folder_data')->where('office_name', $officeName)->first();

            if (!$folderRecord) {
                // First time uploading for this office: Create Office & Subsystem folders on Drive
                try {
                    Storage::disk('google')->makeDirectory($officeName);
                    Storage::disk('google')->makeDirectory("{$officeName}/{$subsystem}");
                } catch (\Throwable $e) {
                    logger()->warning("Drive directory creation notice ({$officeName}/{$subsystem}): " . $e->getMessage());
                }

                DB::table('folder_data')->insert([
                    'office_name'       => $officeName,
                    'total_folder_size' => $fileSize,
                    'is_dts_available'  => $isDts,
                    'current_dts_size'  => $isDts ? $fileSize : 0,
                    'is_rdp_available'  => $isRdp,
                    'current_rdp_size'  => $isRdp ? $fileSize : 0,
                    'is_active'         => true,
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]);
            } else {
                $needsDtsSubsystem = $isDts && !$folderRecord->is_dts_available;
                $needsRdpSubsystem = $isRdp && !$folderRecord->is_rdp_available;

                if ($needsDtsSubsystem || $needsRdpSubsystem) {
                    // Create subsystem folder on Drive
                    try {
                        Storage::disk('google')->makeDirectory("{$officeName}/{$subsystem}");
                    } catch (\Throwable $e) {
                        logger()->warning("Drive subsystem directory creation notice ({$officeName}/{$subsystem}): " . $e->getMessage());
                    }
                }

                // Zero API calls made if subsystem is already available!
                DB::table('folder_data')->where('office_name', $officeName)->update([
                    'total_folder_size' => DB::raw("total_folder_size + {$fileSize}"),
                    'is_dts_available'  => $isDts ? true : $folderRecord->is_dts_available,
                    'current_dts_size'  => $isDts ? DB::raw("current_dts_size + {$fileSize}") : $folderRecord->current_dts_size,
                    'is_rdp_available'  => $isRdp ? true : $folderRecord->is_rdp_available,
                    'current_rdp_size'  => $isRdp ? DB::raw("current_rdp_size + {$fileSize}") : $folderRecord->current_rdp_size,
                    'updated_at'        => now(),
                ]);
            }
        } catch (\Throwable $e) {
            logger()->error("ensureDriveFolderStructure error for {$officeName}: " . $e->getMessage());
        }
    }

    /**
     * Resolve the office code for a user.
     */
    public static function resolveOfficeCode(?User $user = null): string
    {
        if (!$user) {
            return 'GENERAL';
        }

        try {
            $user->loadMissing('details.office');
            if ($user->details && $user->details->office) {
                return $user->details->office->office_code ?: $user->details->office->office_name ?: 'GENERAL';
            }

            if ($user->details && !empty($user->details->office_id)) {
                $office = DB::table('office')->where('id', $user->details->office_id)->first();
                if ($office) {
                    return $office->office_code ?: $office->office_name ?: 'GENERAL';
                }
            }
        } catch (\Throwable $e) {
            logger()->warning("Could not resolve user office code: " . $e->getMessage());
        }

        return 'GENERAL';
    }

    /**
     * Retrieve file contents (checks local cache first, falls back to Google Drive and caches locally).
     */
    public static function getFileContent(string $relativePath): ?string
    {
        $localPath = "private/uploads/{$relativePath}";

        if (Storage::disk('local')->exists($localPath)) {
            return Storage::disk('local')->get($localPath);
        }

        if (Storage::disk('google')->exists($relativePath)) {
            $content = Storage::disk('google')->get($relativePath);
            if ($content) {
                // Cache locally for fast future reads
                Storage::disk('local')->put($localPath, $content);
                return $content;
            }
        }

        return null;
    }
}
