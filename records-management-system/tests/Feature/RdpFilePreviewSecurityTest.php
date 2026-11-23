<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DocumentStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RdpFilePreviewSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('google');
    }

    public function test_view_document_serves_pdf_with_proper_mime_and_security_headers_even_if_it_contains_html_script_tags(): void
    {
        $user = User::first() ?? User::factory()->create();

        // Create a polyglot-style file content with PDF signature and embedded HTML/script
        $maliciousPdfContent = "%PDF-1.4\n<?php echo 'malicious'; ?>\n<!DOCTYPE html><html><body><script>alert(document.cookie);</script></body></html>";

        // Store the file locally
        $storedPath = 'rdp/GENERAL/test_malicious.pdf';
        Storage::disk('local')->put('uploads/' . $storedPath, $maliciousPdfContent);

        // Request document preview via /rdp/view-document
        $response = $this->actingAs($user)->get('/rdp/view-document?path=' . urlencode($storedPath));

        $response->assertStatus(200);

        // Must ALWAYS be application/pdf, NEVER text/html
        $contentType = $response->headers->get('Content-Type');
        $this->assertStringContainsString('application/pdf', $contentType);
        $this->assertStringNotContainsString('text/html', $contentType);

        // Must have nosniff header to prevent MIME sniffing
        $this->assertEquals('nosniff', $response->headers->get('X-Content-Type-Options'));

        // Must have strict Content-Security-Policy blocking script execution
        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertNotEmpty($csp);
        $this->assertStringContainsString("script-src 'none'", $csp);
    }

    public function test_view_document_sandboxes_html_files(): void
    {
        $user = User::first() ?? User::factory()->create();

        $htmlContent = "<!DOCTYPE html><html><body><h1>Safe Report</h1><script>alert('xss');</script></body></html>";
        $storedPath = 'rdp/GENERAL/report.html';
        Storage::disk('local')->put('uploads/' . $storedPath, $htmlContent);

        $response = $this->actingAs($user)->get('/rdp/view-document?path=' . urlencode($storedPath));

        $response->assertStatus(200);
        $this->assertEquals('nosniff', $response->headers->get('X-Content-Type-Options'));

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('sandbox', $csp);
    }

    public function test_store_upload_rejects_dangerous_double_extension(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('prohibited executable extensions or double extension');

        $file = UploadedFile::fake()->create('finaltest.php.pdf', 100, 'application/pdf');
        DocumentStorageService::storeUpload($file, 'RDP');
    }

    public function test_store_upload_rejects_embedded_php_tags(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('server-side executable code tags');

        $maliciousPdf = "%PDF-1.4\n<?php phpinfo(); ?>\n%%EOF";
        $file = UploadedFile::fake()->createWithContent('document.pdf', $maliciousPdf);
        DocumentStorageService::storeUpload($file, 'RDP');
    }

    public function test_store_upload_rejects_pdf_without_valid_header(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('valid PDF header');

        $fakePdf = "This is not a real PDF file";
        $file = UploadedFile::fake()->createWithContent('document.pdf', $fakePdf);
        DocumentStorageService::storeUpload($file, 'RDP');
    }

    public function test_store_upload_accepts_valid_pdf(): void
    {
        $user = User::first() ?? User::factory()->create();

        $validPdf = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF";
        $file = UploadedFile::fake()->createWithContent('valid_doc.pdf', $validPdf);
        $result = DocumentStorageService::storeUpload($file, 'RDP', $user);

        $this->assertNotEmpty($result['document_id']);
        $this->assertNotEmpty($result['document_path']);
        $this->assertStringEndsWith('.pdf', $result['document_path']);
    }
}
