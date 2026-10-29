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
        $this->assertTrue(DocumentStorageService::isDcsStoragePath('DCS/DCC_ECOPY/DCC_DRF_ECOPY/2026_DRF_ECOPY/scan.pdf'));
        $this->assertFalse(DocumentStorageService::isDcsStoragePath('dts/ICTO/DOC-01.pdf'));
        $this->assertFalse(DocumentStorageService::isDcsStoragePath('rdp/REGISTRAR/DOC-02.pdf'));
    }

    public function test_dcc_year_folder_paths(): void
    {
        $this->assertSame(
            'DCS/DCC_ECOPY/DCC_DRF_ECOPY/2026_DRF_ECOPY/2026-12-28_DRF_INT_Policy_Rev0.pdf',
            DocumentStorageService::buildDccRelativePath('drf', '2026-12-28_DRF_INT_Policy_Rev0.pdf', ['date' => '2026-12-28'])
        );
        $this->assertSame(
            'DCS/DCC_ECOPY/DCC_DCN_ECOPY/2018_DCN_ECOPY/2018-03-15_DCN_INT_Policy_Rev1.pdf',
            DocumentStorageService::buildDccRelativePath('dcn', '2018-03-15_DCN_INT_Policy_Rev1.pdf', ['date' => '2018-03-15'])
        );
        $this->assertSame(
            'DCS/DCC_ECOPY/DCC_D&R_ECOPY/2026_D&R_ECOPY/2026-12-28_D&R_Policy_Rev1.pdf',
            DocumentStorageService::buildDccRelativePath('distribution', '2026-12-28_D&R_Policy_Rev1.pdf', ['date' => '2026-12-28'])
        );
        $this->assertSame(
            'DCS/DCC_ECOPY/DCC_DRF_ECOPY/2026_DRF_ECOPY/SUPERSEDED/scan.pdf',
            DocumentStorageService::buildDccRelativePath('drf', 'scan.pdf', [
                'date' => '2026-01-01',
                'lifecycle' => 'superseded',
            ])
        );
        $this->assertSame(
            'DCS/DCC_ECOPY/DCC_D&R_ECOPY/2026_D&R_ECOPY/OBSOLETE/scan.pdf',
            DocumentStorageService::buildDccRelativePath('distribution', 'scan.pdf', [
                'date' => '2026-01-01',
                'lifecycle' => 'obsolete',
            ])
        );
    }

    public function test_dcc_docinfo_lifecycle_paths(): void
    {
        $this->assertSame(
            'DCS/DCC_ECOPY/DCC_DOCINFO_ECOPY/INTERNAL_DOCINFO_ECOPY/LATEST_INTERNAL_DOCINFO_ECOPY/doc.pdf',
            DocumentStorageService::buildDccRelativePath('masterlist', 'doc.pdf', [
                'group' => 'internal_docs',
                'latest' => true,
            ])
        );
        $this->assertSame(
            'DCS/DCC_ECOPY/DCC_DOCINFO_ECOPY/INTERNAL_DOCINFO_ECOPY/SUPERSEDED_INTERNAL_DOCINFO_ECOPY/doc.pdf',
            DocumentStorageService::buildDccRelativePath('masterlist', 'doc.pdf', [
                'group' => 'internal_docs',
                'lifecycle' => 'superseded',
            ])
        );
        $this->assertSame(
            'DCS/DCC_ECOPY/DCC_DOCINFO_ECOPY/INTERNAL_DOCINFO_ECOPY/OBSOLETE_INTERNAL_DOCINFO_ECOPY/doc.pdf',
            DocumentStorageService::buildDccRelativePath('masterlist', 'doc.pdf', [
                'group' => 'internal_docs',
                'latest' => false,
            ])
        );
        $this->assertSame(
            'DCS/DCC_ECOPY/DCC_DOCINFO_ECOPY/INTERNAL_DOCINFO_ECOPY/OBSOLETE_INTERNAL_DOCINFO_ECOPY/doc.pdf',
            DocumentStorageService::buildDccRelativePath('masterlist', 'doc.pdf', [
                'group' => 'internal_docs',
                'lifecycle' => 'obsolete',
            ])
        );
        $this->assertSame(
            'DCS/DCC_ECOPY/DCC_DOCINFO_ECOPY/FORMS_DOCINFO_ECOPY/ADMINISTRATIVE CLUSTER/ACCOUNTING UNIT/LATEST_FORMS_DOCINFO_ECOPY/form.pdf',
            DocumentStorageService::buildDccRelativePath('masterlist', 'form.pdf', [
                'group' => 'forms',
                'latest' => true,
                'cluster' => 'ADMINISTRATIVE CLUSTER',
                'office_name' => 'ACCOUNTING UNIT',
            ])
        );
    }

    public function test_dcc_lifecycle_path_rules_never_cross_wrong_leaf(): void
    {
        $superseded = DocumentStorageService::buildDccRelativePath('masterlist', 'doc.pdf', [
            'group' => 'internal_docs',
            'lifecycle' => 'superseded',
        ]);
        $obsolete = DocumentStorageService::buildDccRelativePath('masterlist', 'doc.pdf', [
            'group' => 'internal_docs',
            'lifecycle' => 'obsolete',
        ]);
        $latest = DocumentStorageService::buildDccRelativePath('masterlist', 'doc.pdf', [
            'group' => 'internal_docs',
            'lifecycle' => 'latest',
        ]);

        $this->assertStringContainsString('SUPERSEDED_', $superseded);
        $this->assertStringNotContainsString('LATEST_', $superseded);
        $this->assertStringContainsString('OBSOLETE_', $obsolete);
        $this->assertStringNotContainsString('SUPERSEDED_', $obsolete);
        $this->assertStringNotContainsString('OBSELETE_', $obsolete);
        $this->assertStringContainsString('LATEST_', $latest);
        $this->assertTrue(DocumentStorageService::pathMatchesLifecycle($latest, 'latest'));
        $this->assertTrue(DocumentStorageService::pathMatchesLifecycle($superseded, 'superseded'));
        $this->assertTrue(DocumentStorageService::pathMatchesLifecycle($obsolete, 'obsolete'));
        $this->assertFalse(DocumentStorageService::pathMatchesLifecycle($latest, 'superseded'));
        $this->assertFalse(DocumentStorageService::pathMatchesLifecycle($superseded, 'obsolete'));
    }

    public function test_infer_dcc_context_maps_legacy_obselete_to_obsolete(): void
    {
        $ctx = DocumentStorageService::inferDccContextFromPath(
            'DCS/DCC_ECOPY/DCC_DOCINFO_ECOPY/INTERNAL_DOCINFO_ECOPY/OBSELETE_INTERNAL_DOCINFO_ECOPY/doc.pdf'
        );
        $this->assertSame('obsolete', $ctx['lifecycle']);
        $this->assertFalse($ctx['latest']);
        $this->assertSame('internal_docs', $ctx['group']);
        $this->assertTrue(DocumentStorageService::isLegacyObsoleteLeaf(
            'DCS/DCC_ECOPY/DCC_DOCINFO_ECOPY/INTERNAL_DOCINFO_ECOPY/OBSELETE_INTERNAL_DOCINFO_ECOPY/doc.pdf'
        ));

        $sup = DocumentStorageService::inferDccContextFromPath(
            'DCS/DCC_ECOPY/DCC_DOCINFO_ECOPY/INTERNAL_DOCINFO_ECOPY/SUPERSEDED_INTERNAL_DOCINFO_ECOPY/doc.pdf'
        );
        $this->assertSame('superseded', $sup['lifecycle']);

        $this->assertSame(
            'obsolete',
            DocumentStorageService::inferFormScanLifecycleFromPath(
                'DCS/DCC_ECOPY/DCC_DRF_ECOPY/2026_DRF_ECOPY/OBSELETE/scan.pdf'
            )
        );
        $this->assertSame(
            'superseded',
            DocumentStorageService::inferFormScanLifecycleFromPath(
                'DCS/DCC_ECOPY/DCC_DRF_ECOPY/2026_DRF_ECOPY/SUPERSEDED/scan.pdf'
            )
        );
    }

    public function test_dcc_static_folders_include_superseded_and_obsolete(): void
    {
        $folders = DocumentStorageService::dccStaticFolders();
        $this->assertContains(
            'DCS/DCC_ECOPY/DCC_DOCINFO_ECOPY/INTERNAL_DOCINFO_ECOPY/SUPERSEDED_INTERNAL_DOCINFO_ECOPY',
            $folders
        );
        $this->assertContains(
            'DCS/DCC_ECOPY/DCC_DOCINFO_ECOPY/INTERNAL_DOCINFO_ECOPY/OBSOLETE_INTERNAL_DOCINFO_ECOPY',
            $folders
        );
        $this->assertNotContains(
            'DCS/DCC_ECOPY/DCC_DOCINFO_ECOPY/INTERNAL_DOCINFO_ECOPY/OBSELETE_INTERNAL_DOCINFO_ECOPY',
            $folders
        );
    }

    public function test_dcc_generated_and_stamped_paths(): void
    {
        $this->assertSame(
            'DCS/DCC_GENERATED_REPORTS/report.pdf',
            DocumentStorageService::buildDccRelativePath('generated_reports', 'report.pdf')
        );
        $this->assertSame(
            'DCS/DCC_STAMPED_DOCUMENTS/doc.pdf',
            DocumentStorageService::buildDccRelativePath('masterlist', 'doc.pdf', ['kind' => 'stamped'])
        );
    }

    public function test_dcc_paths_keep_canonical_case(): void
    {
        $path = 'DCS/DCC_ECOPY/DCC_DRF_ECOPY/2026_DRF_ECOPY/scan.pdf';
        $this->assertTrue(DocumentStorageService::isDccStoragePath($path));
        $this->assertSame('DCS', DocumentStorageService::resolveSubsystemFromPath($path));
        $this->assertSame('GENERAL', DocumentStorageService::resolveOfficeFromPath($path));
        $this->assertSame('drf', DocumentStorageService::resolveDcsCategoryFromPath($path));
        $this->assertSame($path, DocumentStorageService::toSubsystemFirstPath($path));
        $this->assertNull(DocumentStorageService::invertPathArchitecture($path));
    }

    public function test_resolve_dcs_category_from_dcc_paths(): void
    {
        $this->assertEquals('dcn', DocumentStorageService::resolveDcsCategoryFromPath('DCS/DCC_ECOPY/DCC_DCN_ECOPY/2018_DCN_ECOPY/a.pdf'));
        $this->assertEquals('distribution', DocumentStorageService::resolveDcsCategoryFromPath('DCS/DCC_ECOPY/DCC_D&R_ECOPY/2026_D&R_ECOPY/a.pdf'));
        $this->assertEquals('masterlist', DocumentStorageService::resolveDcsCategoryFromPath('DCS/DCC_ECOPY/DCC_DOCINFO_ECOPY/INTERNAL_DOCINFO_ECOPY/LATEST_INTERNAL_DOCINFO_ECOPY/a.pdf'));
        $this->assertEquals('generated_reports', DocumentStorageService::resolveDcsCategoryFromPath('DCS/DCC_GENERATED_REPORTS/a.pdf'));
    }
}
