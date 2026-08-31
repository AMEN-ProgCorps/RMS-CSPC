<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentStorageService
{
    /** Local disk ({@see config/filesystems.php}) is rooted at storage/app/private — cache lives under uploads/. */
    public static function localUploadsPath(string $relativePath): string
    {
        return 'uploads/' . ltrim(str_replace(['\\'], '/', $relativePath), '/');
    }

    public const DCS_CATEGORIES = [
        'masterlist',
        'drf',
        'dcn',
        'distribution',
        'retrieval',
        'revisions',
        'syllabi',
        'report_templates',
        'generated_reports',
    ];

    /**
     * @var list<array{
     *     table: string,
     *     column: string,
     *     category: string,
     *     request_column?: string,
     *     join?: array{0: string, 1: string, 2: string, 3: string}
     * }>
     */
    public const DCS_SCAN_SOURCES = [
        ['table' => 'dcs_document_request_form', 'column' => 'scanned_drf', 'category' => 'drf'],
        ['table' => 'dcs_document_change_notice', 'column' => 'scanned_dcn', 'category' => 'dcn'],
        ['table' => 'dcs_masterlist_registration', 'column' => 'scanned_masterlist', 'category' => 'masterlist'],
        ['table' => 'dcs_document_retrieval', 'column' => 'scanned_retrieval', 'category' => 'retrieval'],
        ['table' => 'dcs_document_distribution', 'column' => 'scanned_distribution', 'category' => 'distribution'],
        [
            'table' => 'dcs_doc_revision',
            'column' => 'scanned_copy',
            'category' => 'revisions',
            'request_column' => 'dcn.request_id',
            'join' => ['dcs_document_change_notice as dcn', 'src.dcn_id', '=', 'dcn.id'],
        ],
        [
            'table' => 'dcs_syllabi_drf',
            'column' => 'scanned_drf',
            'category' => 'syllabi',
            'request_column' => 'sy.request_id',
            'join' => ['dcs_syllabi as sy', 'src.syllabi_id', '=', 'sy.id'],
        ],
    ];

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
        if (!in_array($subsystem, ['DTS', 'RDP', 'DCS'])) {
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
        Storage::disk('local')->put(self::localUploadsPath($relativePath), $fileContent);

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
            'local_path'    => self::localUploadsPath($relativePath),
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
            $localPath = self::localUploadsPath($relativePath);
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
        $isDcs = ($subsystem === 'DCS');

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

                if ($isDcs && Schema::hasColumn('folder_data', 'is_dcs_available')) {
                    DB::table('folder_data')->where('office_name', $officeName)->update([
                        'is_dcs_available' => true,
                        'current_dcs_size' => $fileSize,
                    ]);
                }
            } else {
                $needsDtsSubsystem = $isDts && !$folderRecord->is_dts_available;
                $needsRdpSubsystem = $isRdp && !$folderRecord->is_rdp_available;
                $needsDcsSubsystem = $isDcs && !($folderRecord->is_dcs_available ?? false);

                if ($needsDtsSubsystem || $needsRdpSubsystem || $needsDcsSubsystem) {
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

                if ($isDcs && Schema::hasColumn('folder_data', 'is_dcs_available')) {
                    DB::table('folder_data')->where('office_name', $officeName)->update([
                        'is_dcs_available' => true,
                        'current_dcs_size' => DB::raw('current_dcs_size + ' . $fileSize),
                    ]);
                }
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
        $localPath = self::localUploadsPath($relativePath);

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

    public static function isLegacyPublicScanPath(?string $path): bool
    {
        if (! is_string($path) || trim($path) === '') {
            return false;
        }

        $path = ltrim(str_replace(['\\'], '/', $path), '/');

        return str_starts_with($path, 'scans/');
    }

    /**
     * Store a DCS scanned PDF under {OFFICE}/DCS/{category}/ on Google Drive (+ local cache).
     */
    public static function storeDcsScan(
        $file,
        ?User $user = null,
        ?string $originalFilename = null,
        string $category = 'masterlist'
    ): string {
        $user = $user ?: auth()->user();
        $officeFolderName = strtoupper(Str::slug(self::resolveOfficeCode($user), '_'));
        if ($officeFolderName === '') {
            $officeFolderName = 'GENERAL';
        }

        $category = self::normalizeDcsCategory($category);

        if ($file instanceof UploadedFile) {
            $originalName = $originalFilename ?: $file->getClientOriginalName();
            $fileContent = file_get_contents($file->getRealPath());
            $fileSize = $file->getSize() ?: strlen($fileContent);
            $extension = $file->getClientOriginalExtension() ?: 'pdf';
        } else {
            $fileContent = is_resource($file) ? stream_get_contents($file) : (string) $file;
            $originalName = $originalFilename ?: ('document_' . time() . '.pdf');
            $fileSize = strlen($fileContent);
            $extension = pathinfo($originalName, PATHINFO_EXTENSION) ?: 'pdf';
        }

        $safeBaseName = Str::slug(pathinfo($originalName, PATHINFO_FILENAME), '_');
        if ($safeBaseName === '') {
            $safeBaseName = 'scan';
        }
        $storedFileName = 'DCS-' . strtoupper(Str::random(8)) . "_{$safeBaseName}.{$extension}";
        $relativePath = "{$officeFolderName}/DCS/{$category}/{$storedFileName}";

        self::ensureDriveFolderStructure($officeFolderName, 'DCS', $fileSize);
        self::ensureDcsCategoryFolder($officeFolderName, $category);

        $localPath = self::localUploadsPath($relativePath);
        Storage::disk('local')->put($localPath, $fileContent);

        try {
            Storage::disk('google')->put($relativePath, $fileContent);
        } catch (\Throwable $e) {
            logger()->error("Google Drive DCS upload failed for {$relativePath}: " . $e->getMessage());
        }

        return $relativePath;
    }

    /**
     * Write a file to {OFFICE}/DCS/... on local cache + Google Drive (no document_data row).
     */
    public static function storeDcsFileAtPath(
        string $relativePath,
        string $fileContent,
        ?User $user = null,
        ?string $originalFilename = null,
        ?string $mimeType = null
    ): string {
        $relativePath = ltrim(str_replace(['\\'], '/', $relativePath), '/');
        if ($relativePath === '' || str_contains($relativePath, '..')) {
            throw new \InvalidArgumentException('Invalid DCS storage path.');
        }

        $user = $user ?: auth()->user();
        $fileSize = strlen($fileContent);
        $originalName = $originalFilename ?: basename($relativePath);
        $mimeType = $mimeType ?: self::dcsFileMimeType($relativePath);

        $officeFolderName = strtoupper(explode('/', $relativePath)[0] ?: 'GENERAL');
        $category = self::resolveDcsCategoryFromPath($relativePath);

        self::ensureDriveFolderStructure($officeFolderName, 'DCS', $fileSize);
        self::ensureDcsCategoryFolder($officeFolderName, $category);
        self::ensureDcsPathDirectory($relativePath);

        try {
            Storage::disk('local')->put(self::localUploadsPath($relativePath), $fileContent);
        } catch (\Throwable $e) {
            logger()->error("Local DCS cache write failed for {$relativePath}: " . $e->getMessage());
        }

        try {
            Storage::disk('google')->put($relativePath, $fileContent);
        } catch (\Throwable $e) {
            logger()->error("Google Drive DCS file upload failed for {$relativePath}: " . $e->getMessage());
        }

        return $relativePath;
    }

    /**
     * Move a legacy public/scans/... file to Google Drive and return the new path.
     */
    public static function migrateLegacyScanToDrive(
        string $legacyPath,
        string $category = 'masterlist',
        ?User $user = null,
        ?string $officeFolderName = null
    ): ?string {
        if (! self::isLegacyPublicScanPath($legacyPath)) {
            return null;
        }

        if (! Storage::disk('public')->exists($legacyPath)) {
            return null;
        }

        $absolutePath = Storage::disk('public')->path($legacyPath);
        $fileSize = is_file($absolutePath) ? (int) filesize($absolutePath) : 0;
        if ($fileSize <= 0) {
            return null;
        }

        $user = $user ?: auth()->user();
        $officeFolderName = strtoupper($officeFolderName ?: Str::slug(self::resolveOfficeCode($user), '_'));
        if ($officeFolderName === '') {
            $officeFolderName = 'GENERAL';
        }

        $category = self::normalizeDcsCategory($category);
        $originalName = basename($legacyPath);
        $extension = pathinfo($originalName, PATHINFO_EXTENSION) ?: 'pdf';
        $safeBaseName = Str::slug(pathinfo($originalName, PATHINFO_FILENAME), '_') ?: 'scan';
        $storedFileName = 'DCS-' . strtoupper(Str::random(8)) . "_{$safeBaseName}.{$extension}";
        $relativePath = "{$officeFolderName}/DCS/{$category}/{$storedFileName}";

        self::ensureDriveFolderStructure($officeFolderName, 'DCS', $fileSize);
        self::ensureDcsCategoryFolder($officeFolderName, $category);
        self::ensureDcsPathDirectory($relativePath);

        try {
            self::writeStreamFromAbsolutePath('local', self::localUploadsPath($relativePath), $absolutePath);
            self::writeStreamFromAbsolutePath('google', $relativePath, $absolutePath);
        } catch (\Throwable $e) {
            logger()->error("Google Drive legacy DCS migration failed for {$legacyPath}: " . $e->getMessage());

            return null;
        }

        return $relativePath;
    }

    public static function dcsFileMimeType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }

    public static function dcsDocumentIdFromPath(?string $relativePath): ?string
    {
        if (! is_string($relativePath) || trim($relativePath) === '') {
            return null;
        }

        return self::extractDcsDocumentId($relativePath);
    }

    /**
     * Path + optional scan document id (from filename; not a document_data FK).
     *
     * @return array<string, mixed>
     */
    public static function dcsScanFields(string $table, string $pathColumn, ?string $path): array
    {
        $fields = [$pathColumn => $path];
        $docIdColumn = $pathColumn . '_document_id';

        if ($path && Schema::hasTable($table) && Schema::hasColumn($table, $docIdColumn)) {
            $fields[$docIdColumn] = self::dcsDocumentIdFromPath($path);
        }

        return $fields;
    }

    protected static function extractDcsDocumentId(string $relativePath): string
    {
        $base = basename($relativePath);
        if (preg_match('/^(DCS-[A-Z0-9]+(?:-TPL)?)/i', $base, $matches)) {
            return strtoupper($matches[1]);
        }

        return 'DCS-' . strtoupper(Str::random(10));
    }

    /**
     * @return \Illuminate\Support\Collection<int, object>
     */
    public static function collectDcsScanEntries(): \Illuminate\Support\Collection
    {
        $officeNames = DB::table('office')
            ->pluck('office_name', 'office_code')
            ->all();

        $byPath = [];

        foreach (self::collectDcsScanRows() as $row) {
            $path = ltrim(str_replace('\\', '/', (string) $row->scan_path), '/');
            if ($path === '' || isset($byPath[$path])) {
                continue;
            }

            $pathOfficeCode = strtoupper(explode('/', $path)[0] ?? '');
            $requestId = isset($row->request_id) && $row->request_id !== null
                ? (int) $row->request_id
                : null;

            $byPath[$path] = (object) [
                'document_id'   => self::extractDcsDocumentId($path),
                'document_name' => basename($path),
                'document_path' => $path,
                'request_id'    => $requestId,
                'user_office'   => $pathOfficeCode,
                'office_name'   => $officeNames[$pathOfficeCode] ?? null,
                'category'      => $row->category,
                'date_added'    => $row->updated_at ?? $row->created_at ?? null,
                'first_name'    => $row->first_name ?? null,
                'last_name'     => $row->last_name ?? null,
            ];
        }

        return collect(array_values($byPath));
    }

    /**
     * Scan files grouped by document request (registration bundle).
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public static function collectDcsRequestFileGroups(): \Illuminate\Support\Collection
    {
        $files = self::collectDcsScanEntries();
        if ($files->isEmpty()) {
            return collect();
        }

        $grouped = [];
        foreach ($files as $file) {
            $key = $file->request_id ? 'req-' . $file->request_id : 'unlinked';
            if (! isset($grouped[$key])) {
                $grouped[$key] = (object) [
                    'group_key'            => $key,
                    'request_id'           => $file->request_id,
                    'doc_no'               => null,
                    'doc_title'            => null,
                    'rev_no'               => null,
                    'doc_type_name'        => null,
                    'revision_status'      => null,
                    'primary_office_code'  => null,
                    'office_names'         => [],
                    'file_count'           => 0,
                    'date_updated'         => null,
                    'files'                => [],
                    'register_url'         => null,
                ];
            }

            $grouped[$key]->files[] = $file;
            $grouped[$key]->file_count++;

            if ($file->date_added && (
                $grouped[$key]->date_updated === null
                || $file->date_added > $grouped[$key]->date_updated
            )) {
                $grouped[$key]->date_updated = $file->date_added;
            }
        }

        $requestIds = collect($grouped)
            ->pluck('request_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $masterlists = $requestIds === []
            ? collect()
            : DB::table('dcs_masterlist_registration as ml')
                ->leftJoin('dcs_doc_types as dt', 'ml.doc_type_id', '=', 'dt.id')
                ->whereIn('ml.request_id', $requestIds)
                ->select(
                    'ml.request_id',
                    'ml.doc_no',
                    'ml.doc_title',
                    'ml.revise_no',
                    'ml.revision_status',
                    'dt.doc_type_name'
                )
                ->get()
                ->keyBy('request_id');

        $drfTitles = $requestIds === []
            ? collect()
            : DB::table('dcs_document_request_form')
                ->whereIn('request_id', $requestIds)
                ->pluck('doc_title', 'request_id');

        $sourceOffices = $requestIds === [] || ! Schema::hasTable('dcs_masterlist_source_offices')
            ? collect()
            : DB::table('dcs_masterlist_source_offices as so')
                ->join('office as o', 'so.office_id', '=', 'o.id')
                ->join('dcs_masterlist_registration as ml', 'so.masterlist_id', '=', 'ml.id')
                ->whereIn('ml.request_id', $requestIds)
                ->select('ml.request_id', 'o.office_code', 'o.office_name')
                ->orderBy('o.office_name')
                ->get()
                ->groupBy('request_id');

        foreach ($grouped as $group) {
            if (! $group->request_id) {
                $group->doc_no = 'Unlinked files';
                $group->doc_title = 'Scans not tied to a document request';
                continue;
            }

            $ml = $masterlists->get($group->request_id);
            if ($ml) {
                $group->doc_no = $ml->doc_no;
                $group->doc_title = $ml->doc_title;
                $group->rev_no = (int) $ml->revise_no;
                $group->doc_type_name = $ml->doc_type_name;
                $group->revision_status = $ml->revision_status;
            } else {
                $group->doc_title = $drfTitles->get($group->request_id) ?: ('Request #' . $group->request_id);
                $group->doc_no = 'REQ-' . $group->request_id;
            }

            $offices = $sourceOffices->get($group->request_id, collect());
            if ($offices->isNotEmpty()) {
                $group->office_names = $offices->pluck('office_name')->unique()->values()->all();
                $group->primary_office_code = strtoupper((string) $offices->first()->office_code);
            } elseif ($group->files[0]->user_office ?? null) {
                $group->primary_office_code = $group->files[0]->user_office;
                $group->office_names = $group->files[0]->office_name
                    ? [$group->files[0]->office_name]
                    : [];
            }

            $group->register_url = route('dcs.register.edit', ['id' => $group->request_id], false);
        }

        return collect(array_values($grouped));
    }

    /**
     * Saved generated reports (PDF/CSV exports).
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public static function collectDcsGeneratedReportEntries(): \Illuminate\Support\Collection
    {
        if (! Schema::hasTable('dcs_generated_reports')) {
            return collect();
        }

        $officeNames = DB::table('office')
            ->pluck('office_name', 'office_code')
            ->all();

        return DB::table('dcs_generated_reports as r')
            ->leftJoin('account_details as ad', 'r.generated_by', '=', 'ad.account_id')
            ->orderByDesc('r.created_at')
            ->get([
                'r.id',
                'r.report_token',
                'r.category',
                'r.sub_category',
                'r.title',
                'r.file_path',
                'r.file_name',
                'r.format',
                'r.row_count',
                'r.office_code',
                'r.created_at',
                'ad.first_name',
                'ad.last_name',
            ])
            ->map(function ($row) use ($officeNames) {
                $officeCode = strtoupper((string) ($row->office_code ?: explode('/', (string) $row->file_path)[0] ?? ''));

                return (object) [
                    'id'            => (int) $row->id,
                    'report_token'  => $row->report_token,
                    'category'      => $row->category,
                    'sub_category'  => $row->sub_category,
                    'title'         => $row->title,
                    'file_path'     => $row->file_path,
                    'file_name'     => $row->file_name,
                    'format'        => $row->format,
                    'row_count'     => (int) $row->row_count,
                    'user_office'   => $officeCode,
                    'office_name'   => $officeNames[$officeCode] ?? null,
                    'date_added'    => $row->created_at,
                    'first_name'    => $row->first_name,
                    'last_name'     => $row->last_name,
                ];
            });
    }

    /**
     * Archive a generated DCS report to Google Drive + history table.
     *
     * @param  array<string, mixed>  $meta
     * @return array{id: int, report_token: string, file_path: string, file_name: string}|null
     */
    public static function storeGeneratedReport(
        string $fileContent,
        string $format,
        array $meta,
        ?User $user = null
    ): ?array {
        if (! Schema::hasTable('dcs_generated_reports') || trim($fileContent) === '') {
            return null;
        }

        $user = $user ?: auth()->user();
        if (! $user) {
            return null;
        }

        $format = strtolower($format) === 'csv' ? 'csv' : 'pdf';
        $officeFolderName = strtoupper(Str::slug(self::resolveOfficeCode($user), '_'));
        if ($officeFolderName === '') {
            $officeFolderName = 'GENERAL';
        }

        $token = 'DCS-RPT-' . strtoupper(Str::random(8));
        $title = trim((string) ($meta['title'] ?? 'Report')) ?: 'Report';
        $safeBase = Str::slug($title, '_') ?: 'report';
        $extension = $format === 'csv' ? 'csv' : 'pdf';
        $storedFileName = "{$token}_{$safeBase}.{$extension}";
        $relativePath = "{$officeFolderName}/DCS/generated_reports/{$storedFileName}";
        $mimeType = $format === 'csv' ? 'text/csv' : 'application/pdf';

        self::ensureDriveFolderStructure($officeFolderName, 'DCS', strlen($fileContent));
        self::ensureDcsCategoryFolder($officeFolderName, 'generated_reports');
        self::storeDcsFileAtPath($relativePath, $fileContent, $user, $storedFileName, $mimeType);

        $filters = $meta['filters'] ?? null;
        if (is_string($filters)) {
            $filters = json_decode($filters, true);
        }

        $id = (int) DB::table('dcs_generated_reports')->insertGetId([
            'report_token'  => $token,
            'category'      => (string) ($meta['category'] ?? 'general'),
            'sub_category'  => $meta['sub_category'] ?? null,
            'title'         => $title,
            'file_path'     => $relativePath,
            'file_name'     => $storedFileName,
            'format'        => $format,
            'row_count'     => (int) ($meta['row_count'] ?? 0),
            'office_code'   => $officeFolderName,
            'filters'       => $filters ? json_encode($filters) : null,
            'date_from'     => $meta['date_from'] ?? null,
            'date_to'       => $meta['date_to'] ?? null,
            'period'        => $meta['period'] ?? null,
            'generated_by'  => $user->id,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        return [
            'id'           => $id,
            'report_token' => $token,
            'file_path'    => $relativePath,
            'file_name'    => $storedFileName,
        ];
    }

    /**
     * @return \Illuminate\Support\LazyCollection<int, object>
     */
    protected static function collectDcsScanRows(): \Illuminate\Support\LazyCollection
    {
        return \Illuminate\Support\LazyCollection::make(function () {
            foreach (self::DCS_SCAN_SOURCES as $source) {
                if (! Schema::hasTable($source['table']) || ! Schema::hasColumn($source['table'], $source['column'])) {
                    continue;
                }

                $query = DB::table($source['table'] . ' as src')
                    ->whereNotNull('src.' . $source['column'])
                    ->where('src.' . $source['column'], '!=', '');

                if (Schema::hasColumn($source['table'], 'is_office_intake')) {
                    $query->where(function ($q) {
                        $q->whereNull('src.is_office_intake')
                            ->orWhere('src.is_office_intake', false);
                    });
                }

                if (! empty($source['join'])) {
                    [$joinTable, $left, $operator, $right] = $source['join'];
                    $query->leftJoin($joinTable, $left, $operator, $right);
                }

                $requestColumn = $source['request_column']
                    ?? (Schema::hasColumn($source['table'], 'request_id') ? 'src.request_id' : null);

                if (Schema::hasColumn($source['table'], 'created_by')) {
                    $query->leftJoin('account_details as ad', 'src.created_by', '=', 'ad.account_id');
                }

                $select = [
                    'src.' . $source['column'] . ' as scan_path',
                    DB::raw('\'' . str_replace('\'', '\\\'', $source['category']) . '\' as category'),
                ];

                if ($requestColumn) {
                    $select[] = DB::raw($requestColumn . ' as request_id');
                }

                if (Schema::hasColumn($source['table'], 'updated_at')) {
                    $select[] = 'src.updated_at';
                } elseif (Schema::hasColumn($source['table'], 'created_at')) {
                    $select[] = 'src.created_at';
                }

                if (Schema::hasColumn($source['table'], 'created_by')) {
                    $select[] = 'ad.first_name';
                    $select[] = 'ad.last_name';
                }

                foreach ($query->get($select) as $row) {
                    yield $row;
                }
            }
        });
    }

    public static function dcsPathFileSize(string $path): int
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if ($path === '') {
            return 0;
        }

        try {
            if (self::isLegacyPublicScanPath($path) && Storage::disk('public')->exists($path)) {
                return (int) Storage::disk('public')->size($path);
            }

            $localPath = self::localUploadsPath($path);
            if (Storage::disk('local')->exists($localPath)) {
                return (int) Storage::disk('local')->size($localPath);
            }
        } catch (\Throwable $e) {
            logger()->warning("DCS path size lookup failed for {$path}: " . $e->getMessage());
        }

        return 0;
    }

    public static function sumDcsBytesForOffice(string $officeCode): int
    {
        $officeCode = strtoupper(trim($officeCode));
        if ($officeCode === '') {
            return 0;
        }

        $total = 0;
        foreach (self::collectDcsScanEntries() as $entry) {
            if ($entry->user_office !== $officeCode) {
                continue;
            }
            $total += self::dcsPathFileSize($entry->document_path);
        }

        return $total;
    }

    public static function normalizeDcsCategory(string $category): string
    {
        $category = strtolower(trim(str_replace('\\', '/', $category)));
        if (str_contains($category, '/')) {
            $parts = array_values(array_filter(explode('/', $category), fn ($p) => $p !== '' && $p !== 'scans'));
            $category = $parts !== [] ? end($parts) : 'masterlist';
        }
        $category = Str::slug($category, '_');
        $aliases = [
            'masterlist' => 'masterlist',
            'drf' => 'drf',
            'syllabi_drf' => 'syllabi',
            'syllabi-drf' => 'syllabi',
            'syllabi' => 'syllabi',
            'dcn' => 'dcn',
            'distribution' => 'distribution',
            'dist' => 'distribution',
            'retrieval' => 'retrieval',
            'ret' => 'retrieval',
            'revisions' => 'revisions',
            'revision' => 'revisions',
            'report_templates' => 'report_templates',
            'report-template' => 'report_templates',
            'generated_reports' => 'generated_reports',
            'generated-report' => 'generated_reports',
        ];

        if (isset($aliases[$category])) {
            return $aliases[$category];
        }

        if (str_starts_with($category, 'scans_')) {
            return self::normalizeDcsCategory(substr($category, 6));
        }

        if (str_contains($category, 'masterlist')) {
            return 'masterlist';
        }
        if (str_contains($category, 'syllabi')) {
            return 'syllabi';
        }
        if (str_contains($category, 'revision')) {
            return 'revisions';
        }
        if (str_contains($category, 'distribution')) {
            return 'distribution';
        }
        if (str_contains($category, 'retrieval')) {
            return 'retrieval';
        }

        return in_array($category, self::DCS_CATEGORIES, true) ? $category : 'masterlist';
    }

    public static function resolveDcsCategoryFromPath(?string $path): string
    {
        if (! is_string($path) || trim($path) === '') {
            return 'masterlist';
        }

        $path = ltrim(str_replace(['\\'], '/', $path), '/');

        if (self::isLegacyPublicScanPath($path)) {
            $legacy = trim(substr($path, strlen('scans/')), '/');
            $segment = explode('/', $legacy)[0] ?? 'masterlist';

            return self::normalizeDcsCategory($segment);
        }

        if (preg_match('#/DCS/([^/]+)/#i', '/' . $path, $matches)) {
            return self::normalizeDcsCategory($matches[1]);
        }

        return 'masterlist';
    }

    /**
     * Stream a file from disk into a storage driver (avoids loading large files into memory).
     */
    protected static function writeStreamFromAbsolutePath(string $disk, string $targetPath, string $absoluteSourcePath): void
    {
        if (! is_file($absoluteSourcePath) || ! is_readable($absoluteSourcePath)) {
            throw new \RuntimeException("Cannot read file: {$absoluteSourcePath}");
        }

        $stream = fopen($absoluteSourcePath, 'rb');
        if ($stream === false) {
            throw new \RuntimeException("Cannot open file: {$absoluteSourcePath}");
        }

        Storage::disk($disk)->writeStream($targetPath, $stream);
    }

    protected static function ensureDcsCategoryFolder(string $officeName, string $category): void
    {
        $category = self::normalizeDcsCategory($category);
        $folderPath = "{$officeName}/DCS/{$category}";

        try {
            Storage::disk('local')->makeDirectory(self::localUploadsPath($folderPath));
        } catch (\Throwable $e) {
            logger()->warning("Local DCS category directory notice ({$folderPath}): " . $e->getMessage());
        }

        try {
            Storage::disk('google')->makeDirectory($folderPath);
        } catch (\Throwable $e) {
            logger()->warning("Drive DCS category directory notice ({$folderPath}): " . $e->getMessage());
        }
    }

    protected static function ensureDcsPathDirectory(string $relativePath): void
    {
        $dir = dirname(str_replace('\\', '/', $relativePath));
        if ($dir === '.' || $dir === '') {
            return;
        }

        try {
            Storage::disk('local')->makeDirectory(self::localUploadsPath($dir));
        } catch (\Throwable $e) {
            logger()->warning("Local DCS path directory notice ({$dir}): " . $e->getMessage());
        }

        try {
            Storage::disk('google')->makeDirectory($dir);
        } catch (\Throwable $e) {
            logger()->warning("Drive DCS path directory notice ({$dir}): " . $e->getMessage());
        }
    }

    public static function normalizeDcsScanPath(?string $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $path = ltrim(str_replace(['../', '..\\', '\\'], ['', '', '/'], $path), '/');
        if ($path === '' || str_contains($path, '..')) {
            return null;
        }

        return $path;
    }

    /** Resolve a stored scan path to its DCS document request id, if known. */
    public static function resolveRequestIdForScanPath(string $path): ?int
    {
        $path = self::normalizeDcsScanPath($path);
        if ($path === null) {
            return null;
        }

        foreach (self::DCS_SCAN_SOURCES as $source) {
            if (! Schema::hasTable($source['table']) || ! Schema::hasColumn($source['table'], $source['column'])) {
                continue;
            }

            $query = DB::table($source['table'] . ' as src')
                ->where('src.' . $source['column'], $path);

            if (! empty($source['join'])) {
                [$joinTable, $left, $operator, $right] = $source['join'];
                $query->leftJoin($joinTable, $left, $operator, $right);
            }

            $requestColumn = $source['request_column']
                ?? (Schema::hasColumn($source['table'], 'request_id') ? 'src.request_id' : null);

            if (! $requestColumn) {
                continue;
            }

            $requestId = $query->value(DB::raw($requestColumn));
            if ($requestId) {
                return (int) $requestId;
            }
        }

        if (Schema::hasTable('dcs_document_stamps') && Schema::hasColumn('dcs_document_stamps', 'file_path')) {
            $requestId = DB::table('dcs_document_stamps')
                ->where('file_path', $path)
                ->value('document_request_id');
            if ($requestId) {
                return (int) $requestId;
            }
        }

        return null;
    }

    public static function getDcsScanContent(?string $path): ?string
    {
        $path = self::normalizeDcsScanPath($path);
        if ($path === null) {
            return null;
        }

        if (self::isLegacyPublicScanPath($path)) {
            return Storage::disk('public')->exists($path)
                ? Storage::disk('public')->get($path)
                : null;
        }

        return self::getFileContent($path);
    }

    public static function dcsScanExists(?string $path): bool
    {
        $path = self::normalizeDcsScanPath($path);
        if ($path === null) {
            return false;
        }

        if (self::isLegacyPublicScanPath($path)) {
            return Storage::disk('public')->exists($path);
        }

        $localPath = self::localUploadsPath($path);

        return Storage::disk('local')->exists($localPath)
            || Storage::disk('google')->exists($path);
    }

    public static function dcsScanUrl(?string $path): ?string
    {
        if (! self::dcsScanExists($path)) {
            return null;
        }

        return route('dcs.view-document', ['path' => $path]);
    }

    /** Legacy public/scans/ or {OFFICE}/DCS/{category}/... paths belong to DCS. */
    public static function isDcsStoragePath(?string $path): bool
    {
        $path = self::normalizeDcsScanPath($path);
        if ($path === null) {
            return false;
        }

        if (self::isLegacyPublicScanPath($path)) {
            return true;
        }

        return (bool) preg_match('#(^|/)DCS/#', $path);
    }

    public static function duplicateDcsScan(string $sourcePath, ?User $user = null): ?string
    {
        $content = self::getDcsScanContent($sourcePath);
        if ($content === null) {
            return null;
        }

        $extension = pathinfo($sourcePath, PATHINFO_EXTENSION) ?: 'pdf';
        $originalName = basename($sourcePath);
        if (! str_contains($originalName, '.')) {
            $originalName .= '.' . $extension;
        }

        return self::storeDcsScan(
            $content,
            $user,
            $originalName,
            self::resolveDcsCategoryFromPath($sourcePath)
        );
    }

    public static function deleteDcsScan(?string $path): void
    {
        if (! is_string($path) || trim($path) === '') {
            return;
        }

        $path = ltrim(str_replace(['../', '..\\'], '', $path), '/');
        if ($path === '' || str_contains($path, '..')) {
            return;
        }

        if (self::isLegacyPublicScanPath($path)) {
            try {
                if (Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->delete($path);
                }
            } catch (\Throwable $e) {
                logger()->error("Public scan delete failed for {$path}: " . $e->getMessage());
            }

            return;
        }

        try {
            if (Storage::disk('google')->exists($path)) {
                Storage::disk('google')->delete($path);
            }
        } catch (\Throwable $e) {
            logger()->error("Google Drive DCS delete failed for {$path}: " . $e->getMessage());
        }

        try {
            $localPath = self::localUploadsPath($path);
            if (Storage::disk('local')->exists($localPath)) {
                Storage::disk('local')->delete($localPath);
            }
        } catch (\Throwable $e) {
            logger()->error("Local DCS cache delete failed for {$path}: " . $e->getMessage());
        }
    }
}
