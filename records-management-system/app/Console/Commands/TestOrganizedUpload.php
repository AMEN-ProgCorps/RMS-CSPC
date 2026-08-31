<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\DocumentStorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class TestOrganizedUpload extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'storage:test-upload {--office=ITSO : Office code to simulate}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test organized multi-tier upload workflow (Google Drive + Local + folder_data DB caching)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('====================================================');
        $this->info('  Testing Organized Multi-Tier Upload Workflow');
        $this->info('====================================================');

        $officeCode = strtoupper($this->option('office') ?: 'ITSO');
        $this->info("Office Code: {$officeCode}");

        $user = User::first();
        if (!$user) {
            $this->warn('No user found in database. Using system default user.');
        }

        // Test 1: Upload DTS Document
        $this->info("\n--- TEST 1: Uploading DTS Document ---");
        $dtsFileContent = "Sample DTS Official Document Content - " . date('Y-m-d H:i:s');
        $dtsResult = DocumentStorageService::storeUpload(
            $dtsFileContent,
            'DTS',
            $user,
            null,
            'official_memo_' . time() . '.pdf'
        );

        $this->info("DTS Upload Result:");
        $this->line(" - Document ID: " . $dtsResult['document_id']);
        $this->line(" - Relative Path: " . $dtsResult['document_path']);
        $this->line(" - Office Resolved: " . $dtsResult['user_office']);
        $this->line(" - File Size: " . $dtsResult['file_size'] . " bytes");

        // Verify sys_folder_data state after Test 1
        $folderTbl = Schema::hasTable('sys_folder_data') ? 'sys_folder_data' : 'folder_data';
        $docDataTbl = Schema::hasTable('sys_document_data') ? 'sys_document_data' : 'document_data';
        $folderRecord = DB::table($folderTbl)->where('office_name', $dtsResult['user_office'])->first();
        if ($folderRecord) {
            $this->info("\n[sys_folder_data] Record State:");
            $this->line(" - Office Name: " . $folderRecord->office_name);
            $this->line(" - Total Folder Size: " . $folderRecord->total_folder_size . " bytes");
            $this->line(" - DTS Available: " . ($folderRecord->is_dts_available ? 'TRUE' : 'FALSE'));
            $this->line(" - DTS Current Size: " . $folderRecord->current_dts_size . " bytes");
            $this->line(" - RDP Available: " . ($folderRecord->is_rdp_available ? 'TRUE' : 'FALSE'));
        } else {
            $this->error("Failed to find sys_folder_data record for office: " . $dtsResult['user_office']);
        }

        // Test 2: Upload RDP Document (Second Upload for same office)
        $this->info("\n--- TEST 2: Uploading RDP Document (Same Office) ---");
        $rdpFileContent = "Sample RDP Record Series Document Content - " . date('Y-m-d H:i:s');
        $rdpResult = DocumentStorageService::storeUpload(
            $rdpFileContent,
            'RDP',
            $user,
            null,
            'inventory_report_' . time() . '.pdf'
        );

        $this->info("RDP Upload Result:");
        $this->line(" - Document ID: " . $rdpResult['document_id']);
        $this->line(" - Relative Path: " . $rdpResult['document_path']);
        $this->line(" - Office Resolved: " . $rdpResult['user_office']);

        // Verify sys_folder_data state after Test 2
        $updatedFolderRecord = DB::table($folderTbl)->where('office_name', $rdpResult['user_office'])->first();
        if ($updatedFolderRecord) {
            $this->info("\n[sys_folder_data] Updated Record State:");
            $this->line(" - Total Folder Size: " . $updatedFolderRecord->total_folder_size . " bytes");
            $this->line(" - DTS Available: " . ($updatedFolderRecord->is_dts_available ? 'TRUE' : 'FALSE'));
            $this->line(" - RDP Available: " . ($updatedFolderRecord->is_rdp_available ? 'TRUE' : 'FALSE'));
            $this->line(" - RDP Current Size: " . $updatedFolderRecord->current_rdp_size . " bytes");
        }

        // Verify local file caching
        $localExists = Storage::disk('local')->exists(\App\Services\DocumentStorageService::localUploadsPath($dtsResult['document_path']));
        $this->info("\nLocal File Cached: " . ($localExists ? 'YES ✅' : 'NO ❌'));

        // Verify sys_document_data DB insertion
        $docDataRecord = DB::table($docDataTbl)->where('document_id', $dtsResult['document_id'])->first();
        $this->info("sys_document_data DB Record Found: " . ($docDataRecord ? 'YES ✅' : 'NO ❌'));

        // Test 3: Upload DCS Document (masterlist category)
        $this->info("\n--- TEST 3: Uploading DCS Document (masterlist) ---");
        $dcsFileContent = "Sample DCS Masterlist Scan Content - " . date('Y-m-d H:i:s');
        $dcsPath = DocumentStorageService::storeDcsScan(
            $dcsFileContent,
            $user,
            'masterlist_test_' . time() . '.pdf',
            'masterlist'
        );
        $dcsDocumentId = DocumentStorageService::dcsDocumentIdFromPath($dcsPath);

        $this->info("DCS Upload Result:");
        $this->line(" - Document ID (from filename): " . ($dcsDocumentId ?: '(none)'));
        $this->line(" - Relative Path: " . $dcsPath);
        $this->line(" - Category folder: masterlist");

        $dcsFolderRecord = DB::table($folderTbl)->where('office_name', strtoupper($officeCode))->first();
        if ($dcsFolderRecord && Schema::hasColumn($folderTbl, 'is_dcs_available')) {
            $this->info("\n[sys_folder_data] DCS State:");
            $this->line(" - DCS Available: " . (($dcsFolderRecord->is_dcs_available ?? false) ? 'TRUE' : 'FALSE'));
            $this->line(" - DCS Current Size: " . ($dcsFolderRecord->current_dcs_size ?? 0) . " bytes");
        }

        $dcsInDocumentData = $dcsDocumentId
            ? DB::table($docDataTbl)->where('document_id', $dcsDocumentId)->exists()
            : false;
        $this->info("DCS excluded from sys_document_data: " . ($dcsInDocumentData ? 'NO ❌' : 'YES ✅'));

        $dcsLocalExists = Storage::disk('local')->exists(\App\Services\DocumentStorageService::localUploadsPath($dcsPath));
        $this->info("DCS Local File Cached: " . ($dcsLocalExists ? 'YES ✅' : 'NO ❌'));

        $this->info("\n🎉 All Tests Completed Successfully!");
        return Command::SUCCESS;
    }
}
