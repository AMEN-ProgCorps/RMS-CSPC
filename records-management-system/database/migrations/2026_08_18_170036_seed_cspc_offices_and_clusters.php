<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('office')) {
            return;
        }

        $hasClusterColumn = Schema::hasColumn('office', 'cluster');
        $hasClusterTable = Schema::hasTable('cluster');

        $offices = [
            ['office_name' => 'President', 'office_code' => 'PRES', 'is_active' => true],
            ['office_name' => 'VP for Academic Affairs', 'office_code' => 'VPAA', 'is_active' => true],
            ['office_name' => 'VP for Administration & Finance', 'office_code' => 'VPAF', 'is_active' => true],
            ['office_name' => 'VP for Research, Innovation & Collaboration', 'office_code' => 'VIRC', 'is_active' => true],
            ['office_name' => 'Chief Administrative Officer', 'office_code' => 'CAO', 'is_active' => true],
            ['office_name' => 'Center for Quality Assurance', 'office_code' => 'CQA', 'is_active' => true],
            ['office_name' => 'Supervising Administrative Officer', 'office_code' => 'SAO', 'is_active' => true],
            ['office_name' => 'Board Secretary', 'office_code' => 'BSEC', 'is_active' => true],
            ['office_name' => 'Internal Audit Unit', 'office_code' => 'IAU', 'is_active' => true],
            ['office_name' => 'Legal Affairs Unit', 'office_code' => 'LAU', 'is_active' => true],
            ['office_name' => 'Institutional Planning and Development Unit', 'office_code' => 'IPDU', 'is_active' => true],
            ['office_name' => 'Center for International Relations and Linkages', 'office_code' => 'CIRL', 'is_active' => true],
            ['office_name' => 'Buhi Campus', 'office_code' => 'BUHI', 'is_active' => true],
            ['office_name' => 'Human Resource Management and Development Unit', 'office_code' => 'HRMDU', 'is_active' => true],
            ['office_name' => 'Health Services Unit', 'office_code' => 'HSU', 'is_active' => true],
            ['office_name' => 'General Services Unit', 'office_code' => 'GSU', 'is_active' => true],
            ['office_name' => 'Supply and Property Management Unit', 'office_code' => 'SPMU', 'is_active' => true],
            ['office_name' => 'Accounting Unit', 'office_code' => 'ACCT', 'is_active' => true],
            ['office_name' => 'Budget Unit', 'office_code' => 'BUDG', 'is_active' => true],
            ['office_name' => 'Cash Unit', 'office_code' => 'CASH', 'is_active' => true],
            ['office_name' => 'Procurement Unit', 'office_code' => 'PROC', 'is_active' => true],
            ['office_name' => 'Project Management Unit', 'office_code' => 'PMU', 'is_active' => true],
            ['office_name' => 'Information and Communications Technology Unit', 'office_code' => 'ICTU', 'is_active' => true],
            ['office_name' => 'Records and Freedom of Information Unit', 'office_code' => 'RFIO', 'is_active' => true],
            ['office_name' => 'Graduate School', 'office_code' => 'GS', 'is_active' => true],
            ['office_name' => 'College of Arts & Sciences', 'office_code' => 'CAS', 'is_active' => true],
            ['office_name' => 'College of Technological and Developmental Education', 'office_code' => 'CTDE', 'is_active' => true],
            ['office_name' => 'College of Computer Studies', 'office_code' => 'CCS', 'is_active' => true],
            ['office_name' => 'College of Engineering & Architecture', 'office_code' => 'CEA', 'is_active' => true],
            ['office_name' => 'College of Health Sciences', 'office_code' => 'CHS', 'is_active' => true],
            ['office_name' => 'College of Tourism, Hospitality & Business Management', 'office_code' => 'CTHBM', 'is_active' => true],
            ['office_name' => 'Information and Alumni Affairs Unit', 'office_code' => 'IAAU', 'is_active' => true],
            ['office_name' => 'Center for Human Rights Education', 'office_code' => 'CHRE', 'is_active' => true],
            ['office_name' => 'Center for Gender and Development', 'office_code' => 'CGAD', 'is_active' => true],
            ['office_name' => 'Student Registration and Records', 'office_code' => 'SRR', 'is_active' => true],
            ['office_name' => 'Student Testing and Admission', 'office_code' => 'STA', 'is_active' => true],
            ['office_name' => 'Learning Resources and Development', 'office_code' => 'LRD', 'is_active' => true],
            ['office_name' => 'Academic Center for Continuing Enhancement Services for Students', 'office_code' => 'ACCESS', 'is_active' => false],
            ['office_name' => 'National Service Training Program', 'office_code' => 'NSTP', 'is_active' => true],
            ['office_name' => 'Student Affairs Services', 'office_code' => 'SAS', 'is_active' => true],
            ['office_name' => 'Guidance and Counseling', 'office_code' => 'GC', 'is_active' => true],
            ['office_name' => 'Competency and Assessment Center-TESDA', 'office_code' => 'CAC', 'is_active' => true],
            ['office_name' => 'Research and Development Services Office', 'office_code' => 'RDSO', 'is_active' => true],
            ['office_name' => 'Extension and Community Services Office', 'office_code' => 'ECSO', 'is_active' => true],
            ['office_name' => 'Research Ethics Services', 'office_code' => 'RES', 'is_active' => true],
            ['office_name' => 'Center for Rinconada Culture and Arts', 'office_code' => 'CRCA', 'is_active' => true],
            ['office_name' => 'Center for Intellectual Property Management', 'office_code' => 'CIPM', 'is_active' => true],
            ['office_name' => 'Production and Auxiliary Services', 'office_code' => 'PAS', 'is_active' => true],
            ['office_name' => 'AI Research Center for Community Development', 'office_code' => 'AIRCD', 'is_active' => true],
            ['office_name' => 'Technology Transfer Office', 'office_code' => 'TTO', 'is_active' => true],
            ['office_name' => 'Center for Futures Thinking and Strategic Foresight', 'office_code' => 'CFTSF', 'is_active' => true],
            ['office_name' => 'Center for Future Energy and Sustainable Technology', 'office_code' => 'CFEST', 'is_active' => true],
            ['office_name' => 'Center for Research in Integrative, Social and Special Sciences and Policy', 'office_code' => 'CRISSSP', 'is_active' => true],
            ['office_name' => 'Rinconada Center for Environmental Sustainability', 'office_code' => 'RCES', 'is_active' => true],
            ['office_name' => 'Broadcast Center', 'office_code' => 'BC', 'is_active' => true],
            ['office_name' => 'Document Control', 'office_code' => 'DC', 'is_active' => true],
            ['office_name' => 'Top Management', 'office_code' => 'TM', 'is_active' => true],
            ['office_name' => 'Faculty Association Inc', 'office_code' => 'FAI', 'is_active' => true],
            ['office_name' => 'Student Publication', 'office_code' => 'SPUB', 'is_active' => true],
        ];

        foreach ($offices as $office) {
            DB::table('office')->updateOrInsert(
                ['office_code' => $office['office_code']],
                ['office_name' => $office['office_name'], 'is_active' => $office['is_active']]
            );
        }

        if ($hasClusterTable) {
            $clusters = [
                ['cluster_name' => 'Executive', 'cluster_code' => 'EXEC', 'cluster_head' => 'PRES', 'is_active' => true],
                ['cluster_name' => 'Administration', 'cluster_code' => 'ADMIN', 'cluster_head' => 'CAO', 'is_active' => true],
                ['cluster_name' => 'Academic', 'cluster_code' => 'ACADEMIC', 'cluster_head' => 'VPAA', 'is_active' => true],
                ['cluster_name' => 'Research', 'cluster_code' => 'RESEARCH', 'cluster_head' => 'VIRC', 'is_active' => true],
                ['cluster_name' => 'Student Services', 'cluster_code' => 'STUDENT', 'cluster_head' => 'SAS', 'is_active' => true],
            ];

            foreach ($clusters as $cluster) {
                DB::table('cluster')->updateOrInsert(
                    ['cluster_code' => $cluster['cluster_code']],
                    $cluster
                );
            }
        }

        if ($hasClusterColumn) {
            $clusterByCode = [
                'EXEC' => ['PRES', 'VPAA', 'VPAF', 'VIRC', 'BSEC', 'TM'],
                'ADMIN' => ['CAO', 'SAO', 'IAU', 'LAU', 'IPDU', 'HRMDU', 'HSU', 'GSU', 'SPMU', 'ACCT', 'BUDG', 'CASH', 'PROC', 'PMU', 'ICTU', 'RFIO', 'PAS', 'DC'],
                'ACADEMIC' => ['CQA', 'CIRL', 'BUHI', 'GS', 'CAS', 'CTDE', 'CCS', 'CEA', 'CHS', 'CTHBM', 'LRD', 'ACCESS', 'NSTP', 'FAI'],
                'RESEARCH' => ['RDSO', 'ECSO', 'RES', 'CRCA', 'CIPM', 'AIRCD', 'TTO', 'CFTSF', 'CFEST', 'CRISSSP', 'RCES', 'BC'],
                'STUDENT' => ['IAAU', 'CHRE', 'CGAD', 'SRR', 'STA', 'SAS', 'GC', 'CAC', 'SPUB'],
            ];

            foreach ($clusterByCode as $clusterCode => $officeCodes) {
                DB::table('office')->whereIn('office_code', $officeCodes)->update(['cluster' => $clusterCode]);
            }
        }

        if (Schema::hasTable('dcs_colleges')) {
            $collegeOffices = [
                'CCS' => 'CCS',
                'CEA' => 'CEA',
                'CHS' => 'CHS',
                'CTDE' => 'CTDE',
                'CTHBM' => 'CTHBM',
                'CAS' => 'CAS',
            ];

            foreach ($collegeOffices as $collegeCode => $officeCode) {
                $officeId = DB::table('office')->where('office_code', $officeCode)->value('id');
                if ($officeId) {
                    DB::table('dcs_colleges')->where('college_code', $collegeCode)->update(['office_id' => $officeId]);
                }
            }
        }

        $dcOfficeId = DB::table('office')->where('office_code', 'DC')->value('id')
            ?: DB::table('office')->where('office_code', 'RFIO')->value('id');

        if ($dcOfficeId && Schema::hasTable('account_details')) {
            $adminId = DB::table('account')->where('username', 'admin')->value('id');
            if ($adminId) {
                DB::table('account_details')
                    ->where('account_id', $adminId)
                    ->whereNull('office_id')
                    ->update(['office_id' => $dcOfficeId]);
            }
        }
    }

    public function down(): void
    {
        $codes = [
            'PRES', 'VPAA', 'VPAF', 'VIRC', 'CAO', 'CQA', 'SAO', 'BSEC', 'IAU', 'LAU',
            'IPDU', 'CIRL', 'BUHI', 'HRMDU', 'HSU', 'GSU', 'SPMU', 'ACCT', 'BUDG', 'CASH',
            'PROC', 'PMU', 'ICTU', 'RFIO', 'GS', 'CAS', 'CTDE', 'CCS', 'CEA', 'CHS',
            'CTHBM', 'IAAU', 'CHRE', 'CGAD', 'SRR', 'STA', 'LRD', 'ACCESS', 'NSTP', 'SAS',
            'GC', 'CAC', 'RDSO', 'ECSO', 'RES', 'CRCA', 'CIPM', 'PAS', 'AIRCD', 'TTO',
            'CFTSF', 'CFEST', 'CRISSSP', 'RCES', 'BC', 'DC', 'TM', 'FAI', 'SPUB',
        ];

        if (Schema::hasTable('dcs_colleges')) {
            DB::table('dcs_colleges')->whereIn('college_code', ['CCS', 'CEA', 'CHS', 'CTDE', 'CTHBM', 'CAS'])->update(['office_id' => null]);
        }

        DB::table('office')->whereIn('office_code', $codes)->delete();

        if (Schema::hasTable('cluster')) {
            DB::table('cluster')->whereIn('cluster_code', ['EXEC', 'ADMIN', 'ACADEMIC', 'RESEARCH', 'STUDENT'])->delete();
        }
    }
};
