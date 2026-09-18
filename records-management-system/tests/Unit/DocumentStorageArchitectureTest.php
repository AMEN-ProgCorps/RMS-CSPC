<?php

namespace Tests\Unit;

use App\Services\DocumentStorageService;
use PHPUnit\Framework\TestCase;

class DocumentStorageArchitectureTest extends TestCase
{
    public function test_resolve_office_from_path(): void
    {
        // New subsystem-first layout
        $this->assertEquals('ICTO', DocumentStorageService::resolveOfficeFromPath('dts/ICTO/DOC-123_sample.pdf'));
        $this->assertEquals('REGISTRAR', DocumentStorageService::resolveOfficeFromPath('rdp/REGISTRAR/DOC-456_sample.pdf'));
        $this->assertEquals('HR', DocumentStorageService::resolveOfficeFromPath('dcs/HR/masterlist/DCS-789_sample.pdf'));
        $this->assertEquals('GENERAL', DocumentStorageService::resolveOfficeFromPath('dts/GENERAL/DOC-000_sample.pdf'));

        // Legacy office-first layout
        $this->assertEquals('ICTO', DocumentStorageService::resolveOfficeFromPath('ICTO/DTS/DOC-123_sample.pdf'));
        $this->assertEquals('REGISTRAR', DocumentStorageService::resolveOfficeFromPath('REGISTRAR/RDP/DOC-456_sample.pdf'));
        $this->assertEquals('HR', DocumentStorageService::resolveOfficeFromPath('HR/DCS/masterlist/DCS-789_sample.pdf'));
    }

    public function test_resolve_subsystem_from_path(): void
    {
        // New subsystem-first layout
        $this->assertEquals('DTS', DocumentStorageService::resolveSubsystemFromPath('dts/ICTO/DOC-123_sample.pdf'));
        $this->assertEquals('RDP', DocumentStorageService::resolveSubsystemFromPath('rdp/REGISTRAR/DOC-456_sample.pdf'));
        $this->assertEquals('DCS', DocumentStorageService::resolveSubsystemFromPath('dcs/HR/masterlist/DCS-789_sample.pdf'));

        // Legacy office-first layout
        $this->assertEquals('DTS', DocumentStorageService::resolveSubsystemFromPath('ICTO/DTS/DOC-123_sample.pdf'));
        $this->assertEquals('RDP', DocumentStorageService::resolveSubsystemFromPath('REGISTRAR/RDP/DOC-456_sample.pdf'));
        $this->assertEquals('DCS', DocumentStorageService::resolveSubsystemFromPath('HR/DCS/masterlist/DCS-789_sample.pdf'));
    }

    public function test_to_subsystem_first_path(): void
    {
        // Legacy conversion
        $this->assertEquals(
            'dts/ICTO/DOC-123_sample.pdf',
            DocumentStorageService::toSubsystemFirstPath('ICTO/DTS/DOC-123_sample.pdf')
        );
        $this->assertEquals(
            'rdp/REGISTRAR/DOC-456_sample.pdf',
            DocumentStorageService::toSubsystemFirstPath('REGISTRAR/RDP/DOC-456_sample.pdf')
        );
        $this->assertEquals(
            'dcs/HR/masterlist/DCS-789_sample.pdf',
            DocumentStorageService::toSubsystemFirstPath('HR/DCS/masterlist/DCS-789_sample.pdf')
        );

        // Already subsystem-first (idempotent)
        $this->assertEquals(
            'dts/ICTO/DOC-123_sample.pdf',
            DocumentStorageService::toSubsystemFirstPath('dts/ICTO/DOC-123_sample.pdf')
        );
        $this->assertEquals(
            'dcs/HR/masterlist/DCS-789_sample.pdf',
            DocumentStorageService::toSubsystemFirstPath('dcs/HR/masterlist/DCS-789_sample.pdf')
        );
    }

    public function test_invert_path_architecture(): void
    {
        // Subsystem-first -> Legacy
        $this->assertEquals(
            'ICTO/DTS/DOC-123_sample.pdf',
            DocumentStorageService::invertPathArchitecture('dts/ICTO/DOC-123_sample.pdf')
        );
        $this->assertEquals(
            'HR/DCS/masterlist/DCS-789_sample.pdf',
            DocumentStorageService::invertPathArchitecture('dcs/HR/masterlist/DCS-789_sample.pdf')
        );

        // Legacy -> Subsystem-first
        $this->assertEquals(
            'dts/ICTO/DOC-123_sample.pdf',
            DocumentStorageService::invertPathArchitecture('ICTO/DTS/DOC-123_sample.pdf')
        );
        $this->assertEquals(
            'dcs/HR/masterlist/DCS-789_sample.pdf',
            DocumentStorageService::invertPathArchitecture('HR/DCS/masterlist/DCS-789_sample.pdf')
        );
    }

    public function test_resolve_dcs_category_from_path(): void
    {
        // Subsystem-first
        $this->assertEquals('masterlist', DocumentStorageService::resolveDcsCategoryFromPath('dcs/ICTO/masterlist/DCS-01.pdf'));
        $this->assertEquals('drf', DocumentStorageService::resolveDcsCategoryFromPath('dcs/ICTO/drf/DCS-02.pdf'));
        $this->assertEquals('revisions', DocumentStorageService::resolveDcsCategoryFromPath('dcs/REGISTRAR/revisions/DCS-03.pdf'));

        // Legacy
        $this->assertEquals('dcn', DocumentStorageService::resolveDcsCategoryFromPath('ICTO/DCS/dcn/DCS-04.pdf'));
    }

    public function test_is_dcs_storage_path(): void
    {
        $this->assertTrue(DocumentStorageService::isDcsStoragePath('dcs/ICTO/masterlist/DCS-01.pdf'));
        $this->assertTrue(DocumentStorageService::isDcsStoragePath('ICTO/DCS/masterlist/DCS-01.pdf'));
        $this->assertFalse(DocumentStorageService::isDcsStoragePath('dts/ICTO/DOC-01.pdf'));
        $this->assertFalse(DocumentStorageService::isDcsStoragePath('rdp/REGISTRAR/DOC-02.pdf'));
    }
}
