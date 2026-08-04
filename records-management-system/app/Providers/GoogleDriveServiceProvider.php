<?php

namespace App\Providers;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;
use Masbug\Flysystem\GoogleDriveAdapter;

class GoogleDriveServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        try {
            Storage::extend('google', function ($app, $config) {
                $client = new \Google\Client();

                // DB Overrides Check — read credentials from system_settings (set via Admin Console)
                $dbClientId     = null;
                $dbClientSecret = null;
                $dbRefreshToken = null;
                $dbFolderId     = null;
                $dbVerifySsl    = null;

                try {
                    $dbClientId     = \Illuminate\Support\Facades\DB::table('system_settings')->where('key', 'google_drive_client_id')->value('value');
                    $dbClientSecret = \Illuminate\Support\Facades\DB::table('system_settings')->where('key', 'google_drive_client_secret')->value('value');
                    $dbRefreshToken = \Illuminate\Support\Facades\DB::table('system_settings')->where('key', 'google_drive_refresh_token')->value('value');
                    $dbFolderId     = \Illuminate\Support\Facades\DB::table('system_settings')->where('key', 'google_drive_folder_id')->value('value');
                    $dbVerifySsl    = \Illuminate\Support\Facades\DB::table('system_settings')->where('key', 'google_drive_verify_ssl')->value('value');

                    if (!empty($dbClientId))     $config['clientId']     = $dbClientId;
                    if (!empty($dbClientSecret)) $config['clientSecret'] = $dbClientSecret;
                    if (!empty($dbRefreshToken)) $config['refreshToken'] = $dbRefreshToken;
                    if (!empty($dbFolderId))     $config['folder']       = $dbFolderId;
                } catch (\Throwable $e) {}

                $verifySsl = ($dbVerifySsl !== null)
                    ? filter_var($dbVerifySsl, FILTER_VALIDATE_BOOLEAN)
                    : filter_var(env('GOOGLE_DRIVE_VERIFY_SSL', false), FILTER_VALIDATE_BOOLEAN);
                $guzzleClient = new \GuzzleHttp\Client([
                    'verify' => $verifySsl,
                ]);
                $client->setHttpClient($guzzleClient);

                // Set scopes BEFORE any token fetch so the client knows what permissions to request
                $client->addScope(\Google\Service\Drive::DRIVE);

                // 1. OAuth 2.0 Client credentials auth (User Account - Recommended for Personal Drive)
                if (!empty($config['clientId']) && !empty($config['clientSecret']) && !empty($config['refreshToken'])) {
                    $client->setClientId($config['clientId']);
                    $client->setClientSecret($config['clientSecret']);
                    try {
                        $token = $client->fetchAccessTokenWithRefreshToken($config['refreshToken']);

                        // Validate that we actually got a valid access token
                        if (!empty($token['error'])) {
                            logger()->error('Google Drive OAuth token fetch returned error: ' . ($token['error_description'] ?? $token['error']));
                            throw new \RuntimeException('Google Drive OAuth token error: ' . ($token['error_description'] ?? $token['error']));
                        }

                        $accessToken = $client->getAccessToken();
                        if (empty($accessToken) || empty($accessToken['access_token'])) {
                            logger()->error('Google Drive OAuth token fetch completed but no access token was returned. Check Client ID, Client Secret, and Refresh Token.');
                            throw new \RuntimeException('Google Drive authentication failed: no access token obtained. Verify your Client ID, Client Secret, and Refresh Token are correct and not expired.');
                        }
                    } catch (\RuntimeException $e) {
                        // Re-throw our own validation exceptions so the disk creation fails loudly
                        throw $e;
                    } catch (\Throwable $e) {
                        logger()->error('Google Drive refresh token authentication failed: ' . $e->getMessage());
                        throw new \RuntimeException('Google Drive authentication failed: ' . $e->getMessage(), 0, $e);
                    }
                }
                // 2. Service Account JSON file auth fallback
                else {
                    $jsonPath = !empty($config['serviceAccountJson']) ? base_path($config['serviceAccountJson']) : null;

                    if (empty($jsonPath) || !file_exists($jsonPath)) {
                        if (!empty($config['serviceAccountJson']) && file_exists($config['serviceAccountJson'])) {
                            $jsonPath = $config['serviceAccountJson'];
                        } else {
                            // Auto-detect any service account .json file in storage/app/
                            $jsonFiles = glob(storage_path('app/*.json')) ?: [];
                            foreach ($jsonFiles as $file) {
                                if (!str_ends_with($file, '.example')) {
                                    $jsonPath = $file;
                                    break;
                                }
                            }
                        }
                    }

                    if (!empty($jsonPath) && file_exists($jsonPath)) {
                        $client->setAuthConfig($jsonPath);
                        try {
                            $client->fetchAccessTokenWithAssertion($guzzleClient);

                            $accessToken = $client->getAccessToken();
                            if (empty($accessToken) || empty($accessToken['access_token'])) {
                                logger()->error('Google Drive Service Account auth completed but no access token was returned. Check your JSON key file.');
                                throw new \RuntimeException('Google Drive Service Account authentication failed: no access token obtained.');
                            }
                        } catch (\RuntimeException $e) {
                            throw $e;
                        } catch (\Throwable $e) {
                            logger()->error('Google Drive Service Account authentication failed: ' . $e->getMessage());
                            throw new \RuntimeException('Google Drive Service Account authentication failed: ' . $e->getMessage(), 0, $e);
                        }
                    } else {
                        logger()->error('Google Drive: No OAuth credentials and no valid Service Account JSON file found. Drive storage will not work.');
                    }
                }

                $folderId = $config['folder'] ?? 'root';
                $options = [];

                if (!empty($config['teamDriveId'])) {
                    $options['teamDriveId'] = $config['teamDriveId'];
                }

                $service = new \Google\Service\Drive($client);
                $adapter = new GoogleDriveAdapter($service, $folderId, $options);
                $flysystem = new Filesystem($adapter);

                return new FilesystemAdapter($flysystem, $adapter, $config);
            });
        } catch (\Throwable $e) {
            logger()->error('Google Drive Storage Driver initialization failed: ' . $e->getMessage());
        }
    }
}
