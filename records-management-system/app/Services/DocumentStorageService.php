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
        [
            'table' => 'dcs_office_intake_drf',
            'column' => 'scanned_drf',
            'category' => 'drf',
            'request_column' => 'src.registered_request_id',
        ],
        [
            'table' => 'dcs_office_intake_dcn',
            'column' => 'scanned_dcn',
            'category' => 'dcn',
            'request_column' => 'src.registered_request_id',
        ],
        ['table' => 'dcs_masterlist_registration', 'column' => 'scanned_masterlist', 'category' => 'masterlist'],
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

    public const DCC_ROOT = 'DCS';

    /** @var array<string, string> */
    public const DCC_DOCINFO_GROUPS = [
        'internal_docs' => 'INTERNAL',
        'internal_forms' => 'INTERNALFORMS',
        'external_docs' => 'EXTERNAL',
        'forms' => 'FORMS',
        'logbooks' => 'LOGBOOKS',
    ];

    /** Static DCC folders created on preload / first write (no year or office nesting). */
    public static function dccStaticFolders(): array
    {
        $folders = [
            'DCS',
            'DCS/DCC_ECOPY',
            'DCS/DCC_ECOPY/DCC_DRF_ECOPY',
            'DCS/DCC_ECOPY/DCC_DCN_ECOPY',
            'DCS/DCC_ECOPY/DCC_D&R_ECOPY',
            'DCS/DCC_ECOPY/DCC_DOCINFO_ECOPY',
            'DCS/DCC_MASTERLIST',
            'DCS/DCC_RANDOM_CHECKING',
            'DCS/DCC_RANDOM_CHECKING/INVENTORY_RANDOM_CHECKING',
            'DCS/DCC_RANDOM_CHECKING/RESULT_RANDOM_CHECKING',
            'DCS/DCC_GENERATED_REPORTS',
            'DCS/DCC_STAMPED_DOCUMENTS',
        ];

        foreach (self::DCC_DOCINFO_GROUPS as $prefix) {
            $folders[] = "DCS/DCC_ECOPY/DCC_DOCINFO_ECOPY/{$prefix}_DOCINFO_ECOPY";
            if (! in_array($prefix, ['FORMS', 'LOGBOOKS'], true)) {
                $folders[] = "DCS/DCC_ECOPY/DCC_DOCINFO_ECOPY/{$prefix}_DOCINFO_ECOPY/LATEST_{$prefix}_DOCINFO_ECOPY";
                $folders[] = "DCS/DCC_ECOPY/DCC_DOCINFO_ECOPY/{$prefix}_DOCINFO_ECOPY/OBSELETE_{$prefix}_DOCINFO_ECOPY";
            }
            $folders[] = "DCS/DCC_MASTERLIST/{$prefix}_MASTERLIST";
        }

        return $folders;
    }

    public static function isDccStoragePath(?string $path): bool
    {
        $path = ltrim(str_replace(['\\'], '/', (string) $path), '/');

        return (bool) preg_match('#^DCS/DCC_#i', $path);
    }

    public static function dccGroupKeyFromDocTypeId(mixed $docTypeId): string
    {
        $id = (int) $docTypeId;
        if ($id < 1 || ! Schema::hasTable('dcs_doc_types')) {
            return 'internal_docs';
        }

        $type = DB::table('dcs_doc_types')->where('id', $id)->first();
        if ($type && ! empty($type->parent_id)) {
            $type = DB::table('dcs_doc_types')->where('id', $type->parent_id)->first() ?: $type;
        }
        $name = mb_strtolower(trim((string) ($type->doc_type_name ?? '')));

        if (str_contains($name, 'internal form')) {
            return 'internal_forms';
        }
        if ($name === 'forms' || str_starts_with($name, 'form')) {
            return 'forms';
        }
        if (str_contains($name, 'logbook')) {
            return 'logbooks';
        }
        if (str_contains($name, 'external')) {
            return 'external_docs';
        }

        return 'internal_docs';
    }

    public static function normalizeDccGroupKey(?string $group): string
    {
        $group = strtolower(trim((string) $group));
        $aliases = [
            'internal' => 'internal_docs',
            'internal_docs' => 'internal_docs',
            'internal_forms' => 'internal_forms',
            'internalforms' => 'internal_forms',
            'external' => 'external_docs',
            'external_docs' => 'external_docs',
            'forms' => 'forms',
            'form' => 'forms',
            'logbooks' => 'logbooks',
            'logbook' => 'logbooks',
        ];

        return $aliases[$group] ?? (isset(self::DCC_DOCINFO_GROUPS[$group]) ? $group : 'internal_docs');
    }

    public static function dccFolderLabel(?string $name, string $fallback): string
    {
        $name = trim(preg_replace('/[\/\\\\]+/', ' ', (string) $name) ?? '');
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        return $name !== '' ? $name : $fallback;
    }

    public static function dccYearFromValue(mixed $value): string
    {
        $raw = trim((string) $value);
        if (preg_match('/^(\d{4})/', $raw, $matches)) {
            $year = (int) $matches[1];
            if ($year >= 1900 && $year <= 2100) {
                return (string) $year;
            }
        }

        return now()->format('Y');
    }

    /**
     * @param  array{
     *     group?: string,
     *     latest?: bool,
     *     year?: int|string,
     *     date?: string,
     *     cluster?: string,
     *     office_name?: string,
     *     kind?: string
     * }  $context
     */
    public static function buildDccRelativePath(string $category, string $filename, array $context = []): string
    {
        $filename = ltrim(str_replace(['\\', '/'], '', $filename), '.');
        if ($filename === '') {
            $filename = 'scan.pdf';
        }

        if (($context['kind'] ?? '') === 'stamped') {
            return 'DCS/DCC_STAMPED_DOCUMENTS/' . $filename;
        }

        $category = self::normalizeDcsCategory($category);
        $year = self::dccYearFromValue($context['year'] ?? $context['date'] ?? $filename);

        if (in_array($category, ['drf', 'syllabi'], true)) {
            return "DCS/DCC_ECOPY/DCC_DRF_ECOPY/{$year}_DRF_ECOPY/{$filename}";
        }
        if (in_array($category, ['dcn', 'revisions'], true)) {
            return "DCS/DCC_ECOPY/DCC_DCN_ECOPY/{$year}_DCN_ECOPY/{$filename}";
        }
        if (in_array($category, ['distribution', 'retrieval'], true)) {
            return "DCS/DCC_ECOPY/DCC_D&R_ECOPY/{$year}_D&R_ECOPY/{$filename}";
        }
        if ($category === 'generated_reports') {
            return "DCS/DCC_GENERATED_REPORTS/{$filename}";
        }

        $group = self::normalizeDccGroupKey((string) ($context['group'] ?? 'internal_docs'));
        $prefix = self::DCC_DOCINFO_GROUPS[$group] ?? 'INTERNAL';
        $latest = array_key_exists('latest', $context) ? (bool) $context['latest'] : true;
        $leaf = ($latest ? 'LATEST' : 'OBSELETE') . "_{$prefix}_DOCINFO_ECOPY";

        if (in_array($group, ['forms', 'logbooks'], true)) {
            $cluster = self::dccFolderLabel($context['cluster'] ?? null, 'UNASSIGNED CLUSTER');
            $office = self::dccFolderLabel($context['office_name'] ?? null, 'UNASSIGNED OFFICE');

            return "DCS/DCC_ECOPY/DCC_DOCINFO_ECOPY/{$prefix}_DOCINFO_ECOPY/{$cluster}/{$office}/{$leaf}/{$filename}";
        }

        return "DCS/DCC_ECOPY/DCC_DOCINFO_ECOPY/{$prefix}_DOCINFO_ECOPY/{$leaf}/{$filename}";
    }

    /** @return array{group: string, latest: bool, cluster: string, office_name: string} */
    public static function inferDccContextFromPath(?string $path): array
    {
        $defaults = [
            'group' => 'internal_docs',
            'latest' => true,
            'cluster' => '',
            'office_name' => '',
        ];
        $path = ltrim(str_replace(['\\'], '/', (string) $path), '/');
        if ($path === '') {
            return $defaults;
        }

        foreach (self::DCC_DOCINFO_GROUPS as $group => $prefix) {
            if (! str_contains($path, "{$prefix}_DOCINFO_ECOPY")) {
                continue;
            }
            $defaults['group'] = $group;
            $defaults['latest'] = ! str_contains($path, "OBSELETE_{$prefix}_DOCINFO_ECOPY");
            if (in_array($group, ['forms', 'logbooks'], true)
                && preg_match('#/DCC_DOCINFO_ECOPY/' . preg_quote($prefix, '#') . '_DOCINFO_ECOPY/([^/]+)/([^/]+)/#', $path, $m)
            ) {
                $defaults['cluster'] = $m[1];
                $defaults['office_name'] = $m[2];
            }
            break;
        }

        return $defaults;
    }

    public static function ensureDccSkeleton(): void
    {
        foreach (self::dccStaticFolders() as $folder) {
            self::ensureDcsDirectory($folder);
        }
    }

    public static function ensureDcsDirectory(string $relativeDir): void
    {
        $relativeDir = trim(str_replace('\\', '/', $relativeDir), '/');
        if ($relativeDir === '' || str_contains($relativeDir, '..')) {
            return;
        }

        try {
            Storage::disk('local')->makeDirectory(self::localUploadsPath($relativeDir));
        } catch (\Throwable $e) {
            logger()->warning("Local DCC directory notice ({$relativeDir}): " . $e->getMessage());
        }

        try {
            Storage::disk('google')->makeDirectory($relativeDir);
        } catch (\Throwable $e) {
            logger()->warning("Drive DCC directory notice ({$relativeDir}): " . $e->getMessage());
        }
    }

    public static function ensureRandomCheckInventoryFolders(?string $cluster, ?string $officeName): void
    {
        $cluster = self::dccFolderLabel($cluster, 'UNASSIGNED CLUSTER');
        $officeName = self::dccFolderLabel($officeName, 'UNASSIGNED OFFICE');
        self::ensureDcsDirectory("DCS/DCC_RANDOM_CHECKING/INVENTORY_RANDOM_CHECKING/{$cluster}/{$officeName}");
    }

    public static function ensureRandomCheckResultYear(mixed $year): void
    {
        $year = self::dccYearFromValue($year);
        self::ensureDcsDirectory("DCS/DCC_RANDOM_CHECKING/RESULT_RANDOM_CHECKING/{$year}_RESULT_RANDOM_CHECKING");
    }

    public static function moveDcsFile(string $from, string $to): string
    {
        $from = ltrim(str_replace(['\\'], '/', $from), '/');
        $to = ltrim(str_replace(['\\'], '/', $to), '/');
        if ($from === '' || $to === '' || $from === $to || str_contains($from, '..') || str_contains($to, '..')) {
            return $from;
        }

        self::ensureDcsDirectory(trim(dirname($to), '.'));

        $moved = false;
        try {
            $localFrom = self::localUploadsPath($from);
            $localTo = self::localUploadsPath($to);
            if (Storage::disk('local')->exists($localFrom)) {
                Storage::disk('local')->move($localFrom, $localTo);
                $moved = true;
            }
        } catch (\Throwable $e) {
            logger()->error("Local DCS move failed {$from} → {$to}: " . $e->getMessage());
        }

        try {
            if (self::googleExistsSafe($from)) {
                $content = Storage::disk('google')->get($from);
                if ($content !== null && $content !== '') {
                    Storage::disk('google')->put($to, $content);
                    Storage::disk('google')->delete($from);
                    $moved = true;
                }
            }
        } catch (\Throwable $e) {
            logger()->error("Google Drive DCS move failed {$from} → {$to}: " . $e->getMessage());
        }

        return $moved ? $to : $from;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function relocateDocinfoToObsolete(?string $relativePath, array $context = []): ?string
    {
        if (! is_string($relativePath) || trim($relativePath) === '') {
            return $relativePath;
        }

        $relativePath = ltrim(str_replace(['\\'], '/', $relativePath), '/');
        if (str_contains($relativePath, 'OBSELETE_')) {
            return $relativePath;
        }

        $inferred = self::inferDccContextFromPath($relativePath);
        $context = array_merge($inferred, $context, ['latest' => false]);
        $newPath = self::buildDccRelativePath('masterlist', basename($relativePath), $context);

        return self::moveDcsFile($relativePath, $newPath);
    }

    /**
     * @param  list<string>  $familyNos
     * @param  list<int>  $requestIds
     */
    public static function moveObsoleteDocinfoFilesForFamily(array $familyNos, array $requestIds, int $tipId): void
    {
        if ($familyNos === [] || $requestIds === [] || ! Schema::hasTable('dcs_masterlist_registration')) {
            return;
        }

        $rows = DB::table('dcs_masterlist_registration')
            ->whereIn('doc_no', $familyNos)
            ->whereIn('request_id', $requestIds)
            ->where('id', '!=', $tipId)
            ->whereNotNull('scanned_masterlist')
            ->where('scanned_masterlist', '!=', '')
            ->get(['id', 'scanned_masterlist', 'doc_type_id', 'request_id']);

        foreach ($rows as $row) {
            $path = ltrim(str_replace(['\\'], '/', (string) $row->scanned_masterlist), '/');
            if ($path === '' || str_contains($path, 'OBSELETE_')) {
                continue;
            }

            $context = self::inferDccContextFromPath($path);
            $source = self::sourceClusterOfficeForMasterlist((int) $row->id);
            if ($source['cluster'] !== '') {
                $context['cluster'] = $source['cluster'];
            }
            if ($source['office_name'] !== '') {
                $context['office_name'] = $source['office_name'];
            }
            $context['group'] = self::dccGroupKeyFromDocTypeId($row->doc_type_id);

            $newPath = self::relocateDocinfoToObsolete($path, $context);
            if (is_string($newPath) && $newPath !== '' && $newPath !== $path) {
                DB::table('dcs_masterlist_registration')
                    ->where('id', $row->id)
                    ->update(['scanned_masterlist' => $newPath, 'updated_at' => now()]);
            }
        }
    }

    /** @return array{cluster: string, office_name: string} */
    public static function sourceClusterOfficeForMasterlist(int $masterlistId): array
    {
        $empty = ['cluster' => '', 'office_name' => ''];
        if ($masterlistId < 1 || ! Schema::hasTable('dcs_masterlist_source_offices')) {
            return $empty;
        }

        $officeTbl = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $clusterTbl = Schema::hasTable('sys_cluster') ? 'sys_cluster' : (Schema::hasTable('cluster') ? 'cluster' : null);

        $query = DB::table('dcs_masterlist_source_offices as so')
            ->join($officeTbl . ' as o', 'o.id', '=', 'so.office_id')
            ->where('so.masterlist_id', $masterlistId)
            ->select('o.office_name', 'o.cluster');

        $row = $query->orderBy('so.id')->first();
        if (! $row) {
            return $empty;
        }

        $clusterName = '';
        $clusterRef = trim((string) ($row->cluster ?? ''));
        if ($clusterTbl && $clusterRef !== '') {
            $clusterName = (string) (DB::table($clusterTbl)
                ->where(function ($q) use ($clusterRef) {
                    $q->where('cluster_code', $clusterRef);
                    if (ctype_digit($clusterRef)) {
                        $q->orWhere('id', (int) $clusterRef);
                    }
                })
                ->value('cluster_name') ?? '');
        }

        return [
            'cluster' => $clusterName,
            'office_name' => trim((string) ($row->office_name ?? '')),
        ];
    }

    /** @return array{cluster: string, office_name: string} */
    public static function sourceClusterOfficeForOfficeId(int $officeId): array
    {
        $empty = ['cluster' => '', 'office_name' => ''];
        if ($officeId < 1) {
            return $empty;
        }

        $officeTbl = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $clusterTbl = Schema::hasTable('sys_cluster') ? 'sys_cluster' : (Schema::hasTable('cluster') ? 'cluster' : null);
        $row = DB::table($officeTbl)->where('id', $officeId)->first();
        if (! $row) {
            return $empty;
        }

        $clusterName = '';
        $clusterRef = trim((string) ($row->cluster ?? ''));
        if ($clusterTbl && $clusterRef !== '') {
            $clusterName = (string) (DB::table($clusterTbl)
                ->where(function ($q) use ($clusterRef) {
                    $q->where('cluster_code', $clusterRef);
                    if (ctype_digit($clusterRef)) {
                        $q->orWhere('id', (int) $clusterRef);
                    }
                })
                ->value('cluster_name') ?? '');
        }

        return [
            'cluster' => $clusterName,
            'office_name' => trim((string) ($row->office_name ?? '')),
        ];
    }

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
        $subsystemFolder = strtolower($subsystem);
        $relativePath = "{$subsystemFolder}/{$officeFolderName}/{$storedFileName}";

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

        // 6. Insert / Update sys_document_data Record
        // Ensure document_name is unique if another record already has it
        $nameConflict = DB::table('sys_document_data')
            ->where('document_name', $originalName)
            ->where('document_id', '!=', $documentId)
            ->first();

        if ($nameConflict) {
            $ext = pathinfo($originalName, PATHINFO_EXTENSION);
            $base = pathinfo($originalName, PATHINFO_FILENAME);
            $originalName = "{$base}_" . strtoupper(Str::random(4)) . ($ext ? ".{$ext}" : "");
        }

        $existingDoc = DB::table('sys_document_data')->where('document_id', $documentId)->first();

        if ($existingDoc) {
            DB::table('sys_document_data')->where('document_id', $documentId)->update([
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
            DB::table('sys_document_data')->insert([
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
     * Delete/Purge a document from Google Drive, Local Storage cache, and sys_document_data database.
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
            // 3. Delete / Purge record from sys_document_data database table
            DB::table('sys_document_data')->where('document_path', $relativePath)->delete();
        } catch (\Throwable $e) {
            logger()->error("Database delete failed for sys_document_data ({$relativePath}): " . $e->getMessage());
        }
    }

    /**
     * Ensure folder structure exists on Google Drive using sys_folder_data database cache.
     */
    protected static function ensureDriveFolderStructure(string $officeName, string $subsystem, int $fileSize): void
    {
        $subsystem = strtoupper($subsystem);
        $subsystemFolder = strtolower($subsystem);
        $isDts = ($subsystem === 'DTS');
        $isRdp = ($subsystem === 'RDP');
        $isDcs = ($subsystem === 'DCS');

        try {
            $folderRecord = DB::table('sys_folder_data')->where('office_name', $officeName)->first();

            if (!$folderRecord) {
                // First time uploading for this office: Create Subsystem & Office folders on Drive
                try {
                    Storage::disk('google')->makeDirectory($subsystemFolder);
                    Storage::disk('google')->makeDirectory("{$subsystemFolder}/{$officeName}");
                } catch (\Throwable $e) {
                    logger()->warning("Drive directory creation notice ({$subsystemFolder}/{$officeName}): " . $e->getMessage());
                }

                DB::table('sys_folder_data')->insert([
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

                if ($isDcs && Schema::hasColumn('sys_folder_data', 'is_dcs_available')) {
                    DB::table('sys_folder_data')->where('office_name', $officeName)->update([
                        'is_dcs_available' => true,
                        'current_dcs_size' => $fileSize,
                    ]);
                }
            } else {
                $needsDtsSubsystem = $isDts && !$folderRecord->is_dts_available;
                $needsRdpSubsystem = $isRdp && !$folderRecord->is_rdp_available;
                $needsDcsSubsystem = $isDcs && !($folderRecord->is_dcs_available ?? false);

                if ($needsDtsSubsystem || $needsRdpSubsystem || $needsDcsSubsystem) {
                    // Create subsystem/office folder on Drive
                    try {
                        Storage::disk('google')->makeDirectory($subsystemFolder);
                        Storage::disk('google')->makeDirectory("{$subsystemFolder}/{$officeName}");
                    } catch (\Throwable $e) {
                        logger()->warning("Drive subsystem directory creation notice ({$subsystemFolder}/{$officeName}): " . $e->getMessage());
                    }
                }

                // Zero API calls made if subsystem is already available!
                DB::table('sys_folder_data')->where('office_name', $officeName)->update([
                    'total_folder_size' => DB::raw("total_folder_size + {$fileSize}"),
                    'is_dts_available'  => $isDts ? true : $folderRecord->is_dts_available,
                    'current_dts_size'  => $isDts ? DB::raw("current_dts_size + {$fileSize}") : $folderRecord->current_dts_size,
                    'is_rdp_available'  => $isRdp ? true : $folderRecord->is_rdp_available,
                    'current_rdp_size'  => $isRdp ? DB::raw("current_rdp_size + {$fileSize}") : $folderRecord->current_rdp_size,
                    'updated_at'        => now(),
                ]);

                if ($isDcs && Schema::hasColumn('sys_folder_data', 'is_dcs_available')) {
                    DB::table('sys_folder_data')->where('office_name', $officeName)->update([
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
                $office = DB::table('sys_office')->where('id', $user->details->office_id)->first();
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
     * Resolve the office code from a relative storage path (supports both new subsystem-first and legacy office-first paths).
     */
    public static function resolveOfficeFromPath(?string $path): string
    {
        if (! is_string($path) || trim($path) === '') {
            return 'GENERAL';
        }

        $clean = ltrim(str_replace(['\\'], '/', $path), '/');
        $segments = array_values(array_filter(explode('/', $clean), fn ($s) => $s !== ''));
        if (empty($segments)) {
            return 'GENERAL';
        }

        $knownSubsystems = ['dts', 'rdp', 'dcs', 'chat', 'chatify', 'backup', 'admin'];

        // DCC tree: DCS/DCC_*/... has no uploader office in the path.
        if (self::isDccStoragePath($clean)) {
            return 'GENERAL';
        }

        // Subsystem-first layout: dts/{OFFICE}/... or dcs/{OFFICE}/category/...
        if (in_array(strtolower($segments[0]), $knownSubsystems, true)) {
            return isset($segments[1]) ? strtoupper($segments[1]) : 'GENERAL';
        }

        // Office-first layout (legacy): {OFFICE}/DTS/... or {OFFICE}/DCS/...
        if (isset($segments[1]) && in_array(strtolower($segments[1]), $knownSubsystems, true)) {
            return strtoupper($segments[0]);
        }

        return strtoupper($segments[0]);
    }

    /**
     * Resolve the subsystem code (DTS, RDP, DCS, etc.) from a relative storage path.
     */
    public static function resolveSubsystemFromPath(?string $path): string
    {
        if (! is_string($path) || trim($path) === '') {
            return 'DTS';
        }

        $clean = ltrim(str_replace(['\\'], '/', $path), '/');
        $segments = array_values(array_filter(explode('/', $clean), fn ($s) => $s !== ''));
        $knownSubsystems = ['dts', 'rdp', 'dcs', 'chat', 'chatify', 'backup', 'admin'];

        // Subsystem-first: segments[0] is subsystem
        if (isset($segments[0]) && in_array(strtolower($segments[0]), $knownSubsystems, true)) {
            return strtoupper($segments[0]);
        }

        // Office-first: segments[1] is subsystem
        if (isset($segments[1]) && in_array(strtolower($segments[1]), $knownSubsystems, true)) {
            return strtoupper($segments[1]);
        }

        if (str_contains(strtolower($clean), 'dts')) {
            return 'DTS';
        }
        if (str_contains(strtolower($clean), 'rdp')) {
            return 'RDP';
        }
        if (str_contains(strtolower($clean), 'dcs')) {
            return 'DCS';
        }

        return 'DTS';
    }

    /**
     * Convert any relative path (legacy or new) to the canonical subsystem-first path.
     */
    public static function toSubsystemFirstPath(?string $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $clean = ltrim(str_replace(['\\'], '/', $path), '/');
        if (self::isLegacyPublicScanPath($clean)) {
            return $clean;
        }

        $segments = array_values(array_filter(explode('/', $clean), fn ($s) => $s !== ''));
        if (count($segments) < 2) {
            return $clean;
        }

        $knownSubsystems = ['dts', 'rdp', 'dcs', 'chat', 'chatify', 'backup', 'admin'];

        if (self::isDccStoragePath($clean)) {
            return $clean;
        }

        // Already subsystem-first: dts/{OFFICE}/...
        if (in_array(strtolower($segments[0]), $knownSubsystems, true)) {
            $segments[0] = strtolower($segments[0]);
            $segments[1] = strtoupper($segments[1]);
            return implode('/', $segments);
        }

        // Legacy: {OFFICE}/{SUBSYSTEM}/...
        if (in_array(strtolower($segments[1]), $knownSubsystems, true)) {
            $office = strtoupper($segments[0]);
            $subsystem = strtolower($segments[1]);
            $rest = array_slice($segments, 2);
            return implode('/', array_merge([$subsystem, $office], $rest));
        }

        return $clean;
    }

    /**
     * Invert path between new (subsystem/office/...) and legacy (office/subsystem/...) for fallback lookups.
     */
    public static function invertPathArchitecture(?string $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $clean = ltrim(str_replace(['\\'], '/', $path), '/');
        $segments = array_values(array_filter(explode('/', $clean), fn ($s) => $s !== ''));
        if (count($segments) < 2) {
            return null;
        }

        $knownSubsystems = ['dts', 'rdp', 'dcs', 'chat', 'chatify', 'backup', 'admin'];

        if (self::isDccStoragePath($clean)) {
            return null;
        }

        // Subsystem-first -> Legacy (subsystem/office/... -> office/subsystem/...)
        if (in_array(strtolower($segments[0]), $knownSubsystems, true)) {
            $subsystem = strtoupper($segments[0]);
            $office = strtoupper($segments[1]);
            $rest = array_slice($segments, 2);
            return implode('/', array_merge([$office, $subsystem], $rest));
        }

        // Legacy -> Subsystem-first (office/subsystem/... -> subsystem/office/...)
        if (in_array(strtolower($segments[1]), $knownSubsystems, true)) {
            $office = strtoupper($segments[0]);
            $subsystem = strtolower($segments[1]);
            $rest = array_slice($segments, 2);
            return implode('/', array_merge([$subsystem, $office], $rest));
        }

        return null;
    }

    /**
     * Retrieve file contents (checks local cache first, falls back to Google Drive and caches locally).
     * DCS paths are refused unless $allowDcsPaths is true — DTS/RDP must not read DCS files this way.
     */
    public static function getFileContent(string $relativePath, bool $allowDcsPaths = false): ?string
    {
        if (! $allowDcsPaths && self::isDcsStoragePath($relativePath)) {
            return null;
        }

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

        // Fallback: check inverted architecture path (legacy <-> new)
        $inverted = self::invertPathArchitecture($relativePath);
        if ($inverted && $inverted !== $relativePath) {
            $invertedLocal = self::localUploadsPath($inverted);
            if (Storage::disk('local')->exists($invertedLocal)) {
                return Storage::disk('local')->get($invertedLocal);
            }
            if (Storage::disk('google')->exists($inverted)) {
                $content = Storage::disk('google')->get($inverted);
                if ($content) {
                    Storage::disk('local')->put($localPath, $content);
                    return $content;
                }
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
     * Store a DCS scanned PDF under the DCC_* Drive tree (+ local cache).
     *
     * @param  array<string, mixed>  $dccContext
     */
    public static function storeDcsScan(
        $file,
        ?User $user = null,
        ?string $originalFilename = null,
        string $category = 'masterlist',
        bool $useProvidedBasename = false,
        array $dccContext = []
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

        if ($useProvidedBasename && $originalFilename) {
            $safeBaseName = self::sanitizeDcsScanBasename(pathinfo($originalFilename, PATHINFO_FILENAME));
            if ($safeBaseName === '') {
                $safeBaseName = 'scan';
            }
            $storedFileName = "{$safeBaseName}.{$extension}";
        } else {
            $safeBaseName = Str::slug(pathinfo($originalName, PATHINFO_FILENAME), '_');
            if ($safeBaseName === '') {
                $safeBaseName = 'scan';
            }
            $storedFileName = 'DCS-' . strtoupper(Str::random(8)) . "_{$safeBaseName}.{$extension}";
        }

        $relativePath = self::buildDccRelativePath($category, $storedFileName, $dccContext);
        if (Storage::disk('local')->exists(self::localUploadsPath($relativePath)) || self::googleExistsSafe($relativePath)) {
            $storedFileName = pathinfo($storedFileName, PATHINFO_FILENAME)
                . '_' . strtoupper(Str::random(4))
                . '.' . $extension;
            $relativePath = self::buildDccRelativePath($category, $storedFileName, $dccContext);
        }

        self::ensureDriveFolderStructure($officeFolderName, 'DCS', $fileSize);
        self::ensureDcsDirectory(trim(dirname($relativePath), '.'));

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
     * Keep Y-m-d, form tokens (incl. D&R), underscores; strip path separators / unsafe chars.
     */
    public static function sanitizeDcsScanBasename(string $basename): string
    {
        $basename = str_replace(['/', '\\', "\0"], '', $basename);
        $basename = preg_replace('/\s+/u', '_', trim($basename)) ?? '';
        $basename = preg_replace('/[^\p{L}\p{N}_.&-]+/u', '', $basename) ?? '';
        $basename = trim($basename, '._');

        return $basename !== '' ? $basename : 'scan';
    }

    /**
     * Rename an existing DCS scan file to a new convention basename (same folder + extension).
     * Returns the new relative path, or the original path if rename is skipped/fails.
     */
    public static function renameDcsScanToBasename(?string $relativePath, string $conventionBasename): ?string
    {
        if (! is_string($relativePath) || trim($relativePath) === '') {
            return $relativePath;
        }

        $relativePath = ltrim(str_replace(['\\'], '/', $relativePath), '/');
        if ($relativePath === '' || str_contains($relativePath, '..')) {
            return $relativePath;
        }

        $ext = pathinfo($relativePath, PATHINFO_EXTENSION) ?: 'pdf';
        $safeBase = self::sanitizeDcsScanBasename($conventionBasename);
        if ($safeBase === '') {
            $safeBase = 'scan';
        }

        $dir = trim(str_replace('\\', '/', dirname($relativePath)), '.');
        $newName = "{$safeBase}.{$ext}";
        if (self::isDccStoragePath($relativePath)) {
            $context = self::inferDccContextFromPath($relativePath);
            $context['date'] = $safeBase;
            $newRelative = self::buildDccRelativePath(self::resolveDcsCategoryFromPath($relativePath), $newName, $context);
        } else {
            $newRelative = ($dir === '' || $dir === '.') ? $newName : "{$dir}/{$newName}";
        }

        if ($newRelative === $relativePath) {
            return $relativePath;
        }

        $localOld = self::localUploadsPath($relativePath);
        $localNew = self::localUploadsPath($newRelative);

        if (Storage::disk('local')->exists($localNew) || self::googleExistsSafe($newRelative)) {
            $newName = "{$safeBase}_" . strtoupper(Str::random(4)) . ".{$ext}";
            if (self::isDccStoragePath($relativePath)) {
                $context = self::inferDccContextFromPath($relativePath);
                $context['date'] = $safeBase;
                $newRelative = self::buildDccRelativePath(self::resolveDcsCategoryFromPath($relativePath), $newName, $context);
            } else {
                $newRelative = ($dir === '' || $dir === '.') ? $newName : "{$dir}/{$newName}";
            }
            $localNew = self::localUploadsPath($newRelative);
        }

        self::ensureDcsDirectory(trim(dirname($newRelative), '.'));

        $moved = false;
        try {
            if (Storage::disk('local')->exists($localOld)) {
                Storage::disk('local')->move($localOld, $localNew);
                $moved = true;
            }
        } catch (\Throwable $e) {
            logger()->error("Local DCS rename failed {$relativePath} → {$newRelative}: " . $e->getMessage());
        }

        try {
            if (self::googleExistsSafe($relativePath)) {
                $content = Storage::disk('google')->get($relativePath);
                Storage::disk('google')->put($newRelative, $content);
                Storage::disk('google')->delete($relativePath);
                $moved = true;
            }
        } catch (\Throwable $e) {
            logger()->error("Google Drive DCS rename failed {$relativePath} → {$newRelative}: " . $e->getMessage());
        }

        if (! $moved) {
            return $relativePath;
        }

        $hasSavepoint = false;
        try {
            if (DB::transactionLevel() > 0) {
                DB::statement('SAVEPOINT dcs_scan_rename_meta');
                $hasSavepoint = true;
            }

            $payload = [
                'document_path' => $newRelative,
                'document_name' => basename($newRelative),
            ];
            // sys_document_data uses date_modified (not Laravel updated_at)
            if (\Illuminate\Support\Facades\Schema::hasColumn('sys_document_data', 'date_modified')) {
                $payload['date_modified'] = now();
            }

            DB::table('sys_document_data')
                ->where('document_path', $relativePath)
                ->update($payload);

            if ($hasSavepoint) {
                DB::statement('RELEASE SAVEPOINT dcs_scan_rename_meta');
            }
        } catch (\Throwable $e) {
            // Do not let a metadata miss abort the outer registration transaction (pgsql 25P02).
            logger()->warning("sys_document_data rename skipped {$relativePath}: " . $e->getMessage());
            if ($hasSavepoint) {
                try {
                    DB::statement('ROLLBACK TO SAVEPOINT dcs_scan_rename_meta');
                } catch (\Throwable) {
                    // ignore
                }
            }
        }

        return $newRelative;
    }

    protected static function googleExistsSafe(string $relativePath): bool
    {
        try {
            return Storage::disk('google')->exists($relativePath);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Write a file to dcs/{OFFICE}/... on local cache + Google Drive (no document_data row).
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

        $officeFolderName = strtoupper(self::resolveOfficeFromPath($relativePath));
        $category = self::resolveDcsCategoryFromPath($relativePath);

        self::ensureDriveFolderStructure($officeFolderName, 'DCS', $fileSize);
        if (! self::isDccStoragePath($relativePath)) {
            self::ensureDcsCategoryFolder($officeFolderName, $category);
        }
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
        $relativePath = "dcs/{$officeFolderName}/{$category}/{$storedFileName}";

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
     * Scan path fields for DCS registration inserts/updates.
     *
     * @return array<string, mixed>
     */
    public static function dcsScanFields(string $table, string $pathColumn, ?string $path): array
    {
        return [$pathColumn => $path];
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
        $officeNames = DB::table('sys_office')
            ->pluck('office_name', 'office_code')
            ->all();

        $byPath = [];

        foreach (self::collectDcsScanRows() as $row) {
            $path = ltrim(str_replace('\\', '/', (string) $row->scan_path), '/');
            if ($path === '' || isset($byPath[$path])) {
                continue;
            }

            $pathOfficeCode = self::resolveOfficeFromPath($path);
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
                ->join('sys_office as o', 'so.office_id', '=', 'o.id')
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

        $officeNames = DB::table('sys_office')
            ->pluck('office_name', 'office_code')
            ->all();

        return DB::table('dcs_generated_reports as r')
            ->leftJoin('sys_account_details as ad', 'r.generated_by', '=', 'ad.account_id')
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
                $officeCode = strtoupper((string) ($row->office_code ?: self::resolveOfficeFromPath((string) $row->file_path)));

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
     * Identical exports (same office + fingerprint) reuse the existing Manage Files entry.
     *
     * @param  array<string, mixed>  $meta
     * @return array{id: int, report_token: string, file_path: string, file_name: string, reused?: bool}|null
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

        $fingerprint = self::generatedReportFingerprint($fileContent, $format, $meta);

        if (
            $fingerprint !== ''
            && Schema::hasColumn('dcs_generated_reports', 'content_fingerprint')
        ) {
            $existing = DB::table('dcs_generated_reports')
                ->where('office_code', $officeFolderName)
                ->where('content_fingerprint', $fingerprint)
                ->orderByDesc('id')
                ->first();

            if ($existing && self::dcsScanExists((string) $existing->file_path)) {
                DB::table('dcs_generated_reports')
                    ->where('id', $existing->id)
                    ->update(['updated_at' => now()]);

                return [
                    'id'           => (int) $existing->id,
                    'report_token' => (string) $existing->report_token,
                    'file_path'    => (string) $existing->file_path,
                    'file_name'    => (string) $existing->file_name,
                    'reused'       => true,
                ];
            }
        }

        $token = 'DCS-RPT-' . strtoupper(Str::random(8));
        $title = trim((string) ($meta['title'] ?? 'Report')) ?: 'Report';
        $safeBase = Str::slug($title, '_') ?: 'report';
        $extension = $format === 'csv' ? 'csv' : 'pdf';
        $storedFileName = "{$token}_{$safeBase}.{$extension}";
        $relativePath = self::buildDccRelativePath('generated_reports', $storedFileName);
        $mimeType = $format === 'csv' ? 'text/csv' : 'application/pdf';

        self::ensureDriveFolderStructure($officeFolderName, 'DCS', strlen($fileContent));
        self::ensureDcsDirectory('DCS/DCC_GENERATED_REPORTS');
        self::storeDcsFileAtPath($relativePath, $fileContent, $user, $storedFileName, $mimeType);

        $filters = $meta['filters'] ?? null;
        if (is_string($filters)) {
            $filters = json_decode($filters, true);
        }

        $insert = [
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
        ];

        if (Schema::hasColumn('dcs_generated_reports', 'content_fingerprint')) {
            $insert['content_fingerprint'] = $fingerprint !== '' ? $fingerprint : null;
        }

        $id = (int) DB::table('dcs_generated_reports')->insertGetId($insert);

        return [
            'id'           => $id,
            'report_token' => $token,
            'file_path'    => $relativePath,
            'file_name'    => $storedFileName,
            'reused'       => false,
        ];
    }

    /**
     * Stable identity for an export so re-generates can reuse Manage Files storage.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function generatedReportFingerprint(string $fileContent, string $format, array $meta): string
    {
        $filters = $meta['filters'] ?? null;
        if (is_string($filters)) {
            $decoded = json_decode($filters, true);
            $filters = is_array($decoded) ? $decoded : [];
        }
        if (! is_array($filters)) {
            $filters = [];
        }
        $filters = self::normalizeFingerprintValue($filters);

        return hash('sha256', implode("\n", [
            strtolower($format) === 'csv' ? 'csv' : 'pdf',
            (string) ($meta['category'] ?? ''),
            (string) ($meta['sub_category'] ?? ''),
            (string) ($meta['date_from'] ?? ''),
            (string) ($meta['date_to'] ?? ''),
            (string) ($meta['period'] ?? ''),
            (string) ((int) ($meta['row_count'] ?? 0)),
            json_encode($filters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]));
    }

    private static function normalizeFingerprintValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_keys($value) === range(0, count($value) - 1);
        if (! $isList) {
            ksort($value);
        }

        foreach ($value as $key => $child) {
            $value[$key] = self::normalizeFingerprintValue($child);
        }

        return $value;
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
                    $query->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details') . ' as ad', 'src.created_by', '=', 'ad.account_id');
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

        if (self::isDccStoragePath($path)) {
            if (str_contains($path, 'DCC_DRF_ECOPY')) {
                return 'drf';
            }
            if (str_contains($path, 'DCC_DCN_ECOPY')) {
                return 'dcn';
            }
            if (str_contains($path, 'DCC_D&R_ECOPY')) {
                return 'distribution';
            }
            if (str_contains($path, 'DCC_GENERATED_REPORTS')) {
                return 'generated_reports';
            }
            if (str_contains($path, 'DCC_STAMPED_DOCUMENTS') || str_contains($path, 'DOCINFO_ECOPY')) {
                return 'masterlist';
            }

            return 'masterlist';
        }

        // Subsystem-first layout: dcs/{office}/{category}/...
        if (preg_match('#^dcs/[^/]+/([^/]+)/#i', $path, $matches) && ! str_starts_with(strtoupper($matches[1] ?? ''), 'DCC_')) {
            return self::normalizeDcsCategory($matches[1]);
        }

        // Legacy layout: {office}/DCS/{category}/...
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
        $folderPath = "dcs/{$officeName}/{$category}";

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

        return self::getFileContent($path, true);
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

    public static function dcsDownloadFilename(?string $requested, string $path): string
    {
        $candidate = basename(str_replace('\\', '/', (string) $requested));
        if ($candidate === '' || $candidate === '.' || $candidate === '..') {
            $candidate = basename(str_replace('\\', '/', $path)) ?: 'document.pdf';
        }

        $candidate = str_replace(["\r", "\n", '"'], '', $candidate);

        return $candidate !== '' ? $candidate : 'document.pdf';
    }

    public static function dcsInlineDisposition(string $filename): string
    {
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $filename) ?: 'document.pdf';

        return 'inline; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($filename);
    }

    public static function dcsScanUrl(?string $path, int $ttlMinutes = 60): ?string
    {
        if (! self::dcsScanExists($path)) {
            return null;
        }

        $normalized = self::normalizeDcsScanPath($path);
        if ($normalized === null) {
            return null;
        }

        $downloadAs = self::dcsDownloadFilename(null, $normalized);

        return \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'dcs.view-document',
            now()->addMinutes(max(1, $ttlMinutes)),
            [
                'downloadAs' => $downloadAs,
                'path' => $normalized,
            ]
        );
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

        if (self::isDccStoragePath($path)) {
            return true;
        }

        return (bool) preg_match('#(^|/)dcs/#i', $path);
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
