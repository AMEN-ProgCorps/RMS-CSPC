<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class TestGoogleDrive extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'google-drive:test';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test Google Drive API connection and file operations';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Testing Google Drive API Connection...');

        $jsonFile = config('filesystems.disks.google.serviceAccountJson');
        if (empty($jsonFile) || !file_exists(base_path($jsonFile))) {
            $jsonFiles = glob(storage_path('app/*.json')) ?: [];
            foreach ($jsonFiles as $file) {
                if (!str_ends_with($file, '.example')) {
                    $jsonFile = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file);
                    break;
                }
            }
        }
        $folderId = config('filesystems.disks.google.folder');

        $refreshToken = config('filesystems.disks.google.refreshToken');
        $clientId = config('filesystems.disks.google.clientId');

        if (!empty($refreshToken)) {
            $this->info("Auth Mode: OAuth 2.0 User Credentials [Active]");
            $this->line("Client ID: " . substr($clientId, 0, 15) . "...");
        } else {
            $this->info("Auth Mode: Service Account");
            $this->line("Service Account JSON: " . ($jsonFile ? $jsonFile . ' [Found]' : 'Not Set'));

            if ($jsonFile && file_exists(base_path($jsonFile))) {
                $jsonData = json_decode(file_get_contents(base_path($jsonFile)), true);
                if (!empty($jsonData['client_email'])) {
                    $this->line("Service Account Email: " . $jsonData['client_email']);
                }
            }
        }

        $this->line("Folder ID: " . ($folderId ?: 'Not Set (Root folder will be used)'));

        try {
            $testFileName = 'test_connection_' . time() . '.txt';
            $testContent = 'RMS-CSPC Google Drive Connection Test - ' . date('Y-m-d H:i:s');

            $this->info("Attempting to write test file: {$testFileName}");
            $written = Storage::disk('google')->put($testFileName, $testContent);

            if ($written) {
                $this->info("Successfully wrote test file to Google Drive!");
                
                if (Storage::disk('google')->exists($testFileName)) {
                    $this->info("Verified file existence on Google Drive.");
                    
                    $content = Storage::disk('google')->get($testFileName);
                    $this->line("Read Content: " . $content);

                    $this->info("Cleaning up test file...");
                    Storage::disk('google')->delete($testFileName);
                    $this->info("Test completed successfully!");
                    return Command::SUCCESS;
                }
            }

            $this->error("Failed to write test file to Google Drive.");
            return Command::FAILURE;
        } catch (\Throwable $e) {
            $this->error("Google Drive API Test Failed!");
            $this->error($e->getMessage());
            return Command::FAILURE;
        }
    }
}
