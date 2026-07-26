<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GoogleDriveAuth extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'google-drive:auth {--client-id= : Google OAuth Client ID} {--client-secret= : Google OAuth Client Secret}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Authorize Google Drive API with your personal Google account to obtain a Refresh Token';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('====================================================');
        $this->info('  RMS-CSPC — Google Drive Personal OAuth Setup');
        $this->info('====================================================');

        $clientId = $this->option('client-id') 
            ?: env('GOOGLE_DRIVE_CLIENT_ID') 
            ?: $this->ask('Enter your Google OAuth Client ID');

        $clientSecret = $this->option('client-secret') 
            ?: env('GOOGLE_DRIVE_CLIENT_SECRET') 
            ?: $this->ask('Enter your Google OAuth Client Secret');

        if (empty($clientId) || empty($clientSecret)) {
            $this->error('Client ID and Client Secret are required.');
            return Command::FAILURE;
        }

        $redirectUri = 'https://developers.google.com/oauthplayground';

        $client = new \Google\Client();
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setRedirectUri($redirectUri);
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->addScope(\Google\Service\Drive::DRIVE);

        if (env('GOOGLE_DRIVE_VERIFY_SSL') === false || env('APP_ENV') === 'local') {
            $client->setHttpClient(new \GuzzleHttp\Client(['verify' => false]));
        }

        $authUrl = $client->createAuthUrl();

        $this->info("\nStep 1: Open the following URL in your web browser:\n");
        $this->line("<fg=cyan>{$authUrl}</>");
        $this->info("\nStep 2: Sign in with your personal Google account and allow access.");
        $this->info("Step 3: Copy the Authorization Code given by Google and paste it below.\n");

        $authCode = $this->ask('Enter the Authorization Code from Google');

        if (empty($authCode)) {
            $this->error('Authorization code cannot be empty.');
            return Command::FAILURE;
        }

        try {
            $this->info('Exchanging code for Refresh Token...');
            $accessToken = $client->fetchAccessTokenWithAuthCode(trim($authCode));

            if (isset($accessToken['error'])) {
                $this->error('Failed to obtain token: ' . ($accessToken['error_description'] ?? $accessToken['error']));
                return Command::FAILURE;
            }

            $refreshToken = $accessToken['refresh_token'] ?? null;

            if (!$refreshToken) {
                $this->warn('Warning: No refresh token returned. Make sure to remove app access in Google Security settings and try again.');
                return Command::FAILURE;
            }

            $this->info("\nSUCCESS! Refresh Token obtained!");
            $this->line("Refresh Token: {$refreshToken}\n");

            // Update .env file
            $this->updateEnvFile([
                'GOOGLE_DRIVE_CLIENT_ID' => $clientId,
                'GOOGLE_DRIVE_CLIENT_SECRET' => $clientSecret,
                'GOOGLE_DRIVE_REFRESH_TOKEN' => $refreshToken,
                'FILESYSTEM_DISK' => 'google',
            ]);

            $this->info('Updated .env file with Google Drive credentials.');
            $this->info('You can now run: php artisan google-drive:test');

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $this->error('OAuth Error: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Update environment variables in .env file
     */
    protected function updateEnvFile(array $data)
    {
        $envPath = base_path('.env');
        if (!file_exists($envPath)) {
            if (file_exists(base_path('.env.example'))) {
                copy(base_path('.env.example'), $envPath);
            } else {
                touch($envPath);
            }
        }

        $envContent = file_get_contents($envPath);

        foreach ($data as $key => $value) {
            $keyPattern = "/^{$key}=.*/m";
            if (preg_match($keyPattern, $envContent)) {
                $envContent = preg_replace($keyPattern, "{$key}={$value}", $envContent);
            } else {
                $envContent .= "\n{$key}={$value}";
            }
        }

        file_put_contents($envPath, $envContent);
    }
}
