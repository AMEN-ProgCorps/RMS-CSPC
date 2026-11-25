{{--
  DCS PAGE: DCS Settings catalogs
  FILE: resources/views/pages/dcs/settings/index.blade.php
  DEBUG: SettingsRecycleHelper; DocumentNumberSeriesHelper
--}}
<?php

/**
 * DCS file: DCS settings catalogs: document types, originators, offices, and related lists. Deleted catalog rows are hidden by SettingsRecycleHelper.
 */

use App\Helpers\OfficeIntakeHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.dcs')] #[Title('CSPC - Document Control System')] class extends Component {
    use WithFileUploads;

    public string $modalKind = '';
    public ?int $editingId = null;
    public ?int $parentId = null;

    public string $docTypeName = '';
    /** @var list<array{name: string}> Optional sub-types when creating a top-level document type */
    public array $docTypeSubRows = [
        ['name' => ''],
    ];
    public string $originatorName = '';
    public string $facultyName = '';
    /** @var list<string> Multi-college membership for faculty modal */
    public array $facultyCollegeIds = [];
    public string $collegeId = '';
    public string $collegeName = '';
    public string $officeId = '';
    public string $programName = '';
    public string $programCode = '';
    /** @var list<array{code: string, name: string}> Optional programs when creating a college */
    public array $collegeProgramRows = [
        ['code' => '', 'name' => ''],
    ];
    public string $semesterName = '';
    public string $schoolYear = '';
    /** When saving a school year, mark it as the sole active S/Y for office intake. */
    public bool $schoolYearIsActive = false;
    public string $programId = '';
    public string $semesterId = '';
    public string $courseName = '';
    public string $courseCode = '';
    public string $courseYearLevel = '';
    public string $courseType = '';
    /** School year for the program-course form (distinct from catalog `$schoolYear` text field). */
    public string $courseSchoolYearId = '';
    /** @var list<int|string> Multi-select on the Course Names table */
    public array $selectedCourseIds = [];
    /** @var list<array{type: string, year_level: string, code: string, name: string}> */
    public array $courseRows = [
        ['type' => '', 'year_level' => '', 'code' => '', 'name' => ''],
    ];
    public string $bulkCollegeId = '';
    public string $bulkProgramId = '';
    public string $bulkFillSemester = '';
    public string $bulkFillYear = '';
    public string $bulkFillType = '';
    /** @var list<array{id: int, semester_id: string, semester_name: string, year_level: string, course_type: string, school_year_id?: string, code: string, name: string}> */
    public array $bulkRows = [];
    public string $courseListCollegeId = '';
    public string $courseListProgramId = '';
    public int $courseListEpoch = 0;
    /** When set, Colleges section shows this college's programs (programs live under college). */
    public ?int $collegeDetailId = null;
    public $csvFile = null;

    public string $deleteTitle = '';
    public string $deleteMessage = '';

    public string $sigFormKind = 'drf';
    public string $sigDocTypeKind = 'internal';
    public string $sigParentTypeId = '';
    public string $sigSubTypeId = '';
    /** College scope for Syllabi / TOS signatories (empty = All campuses default). */
    public string $sigCollegeId = '';
    public string $sigEffectiveFrom = '';
    public string $sigEffectiveUntil = '';
    public string $sigPreparedByName = '';
    public string $sigPreparedByDesignation = '';
    public string $sigReviewedByName = '';
    public string $sigReviewedByDesignation = '';
    public string $sigApprovedByName = '';
    public string $sigApprovedByDesignation = '';
    /** Recent list filter: all | parent:{id} | kind */
    public string $sigHistoryTab = 'all';
    /** @var list<array{name: string}> */
    public array $sigReviewers = [['name' => '']];
    /** @var list<array{position: string, name: string}> */
    public array $sigApprovals = [['position' => '', 'name' => '']];

    /**
     * Sets the active sig history tab.
     *
     * @param string $tab
     *
     * @return void
     */
    public function setSigHistoryTab(string $tab): void
    {
        $tab = trim($tab);
        $this->sigHistoryTab = $tab !== '' ? $tab : 'all';
    }

    /**
     * Supplies view data, query collections, and metrics to the Blade template.
     * @return array
     */
    public function with(): array
    {
        return $this->catalog();
    }

    /**
     * Lifecycle hook executed after updating the sig form kind property.
     * @return void
     */
    public function updatedSigFormKind(): void
    {
        $this->sigHistoryTab = 'all';
        $this->loadSignatoryTemplateFields();
    }

    /**
     * Lifecycle hook executed after updating the sig doc type kind property.
     * @return void
     */
    public function updatedSigDocTypeKind(): void
    {
        $this->loadSignatoryTemplateFields();
    }

    /**
     * Lifecycle hook executed after updating the sig parent type id property.
     * @return void
     */
    public function updatedSigParentTypeId(): void
    {
        $this->sigSubTypeId = '';
        $this->sigCollegeId = '';
        $this->syncSigDocTypeKindFromCatalog();
        $this->loadSignatoryTemplateFields();
    }

    /**
     * Lifecycle hook executed after updating the sig sub type id property.
     * @return void
     */
    public function updatedSigSubTypeId(): void
    {
        if (! $this->sigScopeNeedsCollege()) {
            $this->sigCollegeId = '';
        }
        $this->syncSigDocTypeKindFromCatalog();
        $this->loadSignatoryTemplateFields();
    }

    /**
     * Lifecycle hook executed after updating the sig college id property.
     * @return void
     */
    public function updatedSigCollegeId(): void
    {
        $this->loadSignatoryTemplateFields();
    }

    /** Syllabi / TOS sub-types require a college (or All campuses) scope. */
    private function sigScopeNeedsCollege(): bool
    {
        $sub = (int) $this->sigSubTypeId;
        if ($sub > 0) {
            return OfficeIntakeHelper::isOfficeDrfSyllabiLike($sub);
        }

        return false;
    }

    /**
     * Resolved sig college id.
     * @return ?int
     */
    private function resolvedSigCollegeId(): ?int
    {
        if (! $this->sigScopeNeedsCollege()) {
            return null;
        }
        $id = (int) $this->sigCollegeId;

        return $id > 0 ? $id : null;
    }

    /** Align kind with catalog names so Internal/External types don't fight the Kind field. */
    private function syncSigDocTypeKindFromCatalog(): void
    {
        $id = $this->resolvedSigDocTypeId();
        if (! $id || ! Schema::hasTable('dcs_doc_types')) {
            return;
        }
        $row = DB::table('dcs_doc_types')->where('id', $id)->first(['doc_type_name', 'parent_id']);
        if (! $row) {
            return;
        }
        $label = trim((string) ($row->doc_type_name ?? ''));
        if (! empty($row->parent_id)) {
            $parent = DB::table('dcs_doc_types')->where('id', $row->parent_id)->value('doc_type_name');
            $label = trim((string) $parent.' '.$label);
        }
        $this->sigDocTypeKind = str_contains(mb_strtolower($label), 'external') ? 'external' : 'internal';
    }

    /**
     * Retrieves and populates signatory template fields data.
     * @return void
     */
    public function loadSignatoryTemplateFields(): void
    {
        if ($this->sigEffectiveFrom === '') {
            $this->sigEffectiveFrom = now()->toDateString();
        }

        // Editor shows only this exact scope — not parent/kind inheritance
        // (inheritance still applies in office intake when this scope is empty).
        $docTypeId = $this->resolvedSigDocTypeId();
        $collegeId = $this->resolvedSigCollegeId();
        $payload = OfficeIntakeHelper::signatoryTemplate(
            $this->sigFormKind,
            $this->sigDocTypeKind,
            $docTypeId,
            null,
            false,
            false,
            $collegeId
        ) ?? [];
        // Exact college scope: ignore All-campuses fallback while editing a specific college.
        if ($collegeId && $collegeId > 0) {
            $hitCollege = (int) data_get($payload, '_meta.college_id', 0);
            if ($hitCollege !== $collegeId) {
                $payload = [];
            }
        } elseif ($this->sigScopeNeedsCollege()) {
            // All campuses editor: only show NULL-college rows.
            $hitCollege = (int) data_get($payload, '_meta.college_id', 0);
            if ($hitCollege > 0) {
                $payload = [];
            }
        }

        $meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
        $this->sigEffectiveUntil = ! empty($meta['effective_until']) ? (string) $meta['effective_until'] : '';
        if (! empty($meta['effective_from'])) {
            $this->sigEffectiveFrom = (string) $meta['effective_from'];
        }

        $this->sigPreparedByName = (string) ($payload['prepared_by_name'] ?? '');
        $this->sigPreparedByDesignation = (string) ($payload['prepared_by_designation'] ?? '');
        $this->sigReviewedByName = (string) ($payload['reviewed_by_name'] ?? '');
        $this->sigReviewedByDesignation = (string) ($payload['reviewed_by_designation'] ?? '');
        $this->sigApprovedByName = (string) ($payload['approved_by_name'] ?? '');
        $this->sigApprovedByDesignation = (string) ($payload['approved_by_designation'] ?? '');

        $reviewers = [];
        foreach ((array) ($payload['reviewers'] ?? []) as $row) {
            $name = trim((string) (is_array($row) ? ($row['name'] ?? '') : $row));
            if ($name !== '') {
                $reviewers[] = ['name' => $name];
            }
        }
        $this->sigReviewers = $reviewers !== [] ? $reviewers : [['name' => '']];

        $approvals = [];
        foreach ((array) ($payload['approvals'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $approvals[] = [
                'position' => trim((string) ($row['position'] ?? '')),
                'name' => trim((string) ($row['name'] ?? '')),
            ];
        }
        $this->sigApprovals = $approvals !== [] ? $approvals : [['position' => '', 'name' => '']];
    }

    /**
     * Creates a new sig reviewer row record.
     * @return void
     */
    public function addSigReviewerRow(): void
    {
        $this->sigReviewers[] = ['name' => ''];
    }

    /**
     * Deletes or deactivates the specified sig reviewer row.
     *
     * @param int $index
     *
     * @return void
     */
    public function removeSigReviewerRow(int $index): void
    {
        unset($this->sigReviewers[$index]);
        $this->sigReviewers = array_values($this->sigReviewers) ?: [['name' => '']];
    }

    /**
     * Creates a new sig approval row record.
     * @return void
     */
    public function addSigApprovalRow(): void
    {
        $this->sigApprovals[] = ['position' => '', 'name' => ''];
    }

    /**
     * Deletes or deactivates the specified sig approval row.
     *
     * @param int $index
     *
     * @return void
     */
    public function removeSigApprovalRow(int $index): void
    {
        unset($this->sigApprovals[$index]);
        $this->sigApprovals = array_values($this->sigApprovals) ?: [['position' => '', 'name' => '']];
    }

    /**
     * Begins new signatory assignment.
     * @return void
     */
    public function beginNewSignatoryAssignment(): void
    {
        $this->sigEffectiveFrom = now()->toDateString();
        $this->sigEffectiveUntil = '';
        $this->sigPreparedByName = '';
        $this->sigPreparedByDesignation = '';
        $this->sigReviewedByName = '';
        $this->sigReviewedByDesignation = '';
        $this->sigApprovedByName = '';
        $this->sigApprovedByDesignation = '';
        $this->sigReviewers = [['name' => '']];
        $this->sigApprovals = [['position' => '', 'name' => '']];
        $this->flashToast('Fields cleared for a new assignment. Save when ready — the current active set ends automatically.', 'info');
    }

    /**
     * Submits and processes replace signatory assignment.
     * @return void
     */
    public function confirmReplaceSignatoryAssignment(): void
    {
        \App\Helpers\RegisterQueryHelper::assertFullDcsUser('settings');
        $this->resetValidation();
        $this->editingId = null;
        $this->parentId = null;
        $this->deleteTitle = 'Replace active assignment';
        $this->deleteMessage = 'Save this as the new active set? The current open assignment for this type will end.';
        $this->modalKind = 'signatory:replace';
        $this->dispatch('settings-open-modal');
    }

    /**
     * Persists signatory template changes to the database.
     * @return void
     */
    public function saveSignatoryTemplate(): void
    {
        \App\Helpers\RegisterQueryHelper::assertFullDcsUser('settings');

        if ($this->sigFormKind === 'drf') {
            $payload = [
                'prepared_by_name' => trim($this->sigPreparedByName),
                'prepared_by_designation' => trim($this->sigPreparedByDesignation),
                'reviewed_by_name' => trim($this->sigReviewedByName),
                'reviewed_by_designation' => trim($this->sigReviewedByDesignation),
                'approved_by_name' => trim($this->sigApprovedByName),
                'approved_by_designation' => trim($this->sigApprovedByDesignation),
            ];
        } else {
            $payload = [
                'reviewers' => collect($this->sigReviewers)
                    ->map(fn ($r) => ['name' => trim((string) ($r['name'] ?? ''))])
                    ->filter(fn ($r) => $r['name'] !== '')
                    ->values()
                    ->all(),
                'approvals' => collect($this->sigApprovals)
                    ->map(fn ($r) => [
                        'position' => trim((string) ($r['position'] ?? '')),
                        'name' => trim((string) ($r['name'] ?? '')),
                    ])
                    ->filter(fn ($r) => $r['position'] !== '' || $r['name'] !== '')
                    ->values()
                    ->all(),
            ];
        }

        try {
            $docTypeId = $this->resolvedSigDocTypeId();
            if (! $docTypeId) {
                $this->fail('Select a Document type from the catalog.');

                return;
            }
            if ($this->sigScopeNeedsCollege() && (int) $this->sigCollegeId < 1) {
                $this->fail('Select a college for Syllabi / TOS signatories.');

                return;
            }
            $this->syncSigDocTypeKindFromCatalog();
            $from = trim($this->sigEffectiveFrom) !== '' ? trim($this->sigEffectiveFrom) : now()->toDateString();
            $until = trim($this->sigEffectiveUntil) !== '' ? trim($this->sigEffectiveUntil) : null;
            OfficeIntakeHelper::saveSignatoryTemplate(
                $this->sigFormKind,
                $this->sigDocTypeKind,
                $payload,
                auth()->id(),
                $docTypeId,
                $from,
                $until,
                $this->resolvedSigCollegeId()
            );
            $this->flashToast('Signatory assignment saved for this scope and date window.', 'success');
            $this->loadSignatoryTemplateFields();
            if ($this->modalKind === 'signatory:replace') {
                $this->closeModal();
            }
        } catch (\Throwable $e) {
            $this->fail($e->getMessage() ?: 'Could not save signatory assignment.');
        }
    }

    /**
     * Resolved sig doc type id.
     * @return ?int
     */
    private function resolvedSigDocTypeId(): ?int
    {
        $sub = (int) $this->sigSubTypeId;
        if ($sub > 0) {
            return $sub;
        }
        $parent = (int) $this->sigParentTypeId;

        return $parent > 0 ? $parent : null;
    }

    /**
     * Closes the modal.
     * @return void
     */
    public function closeModal(): void
    {
        $this->editingId = null;
        $this->parentId = null;
        $this->modalKind = '';
        $this->reset([
            'docTypeName', 'originatorName',
            'facultyName', 'facultyCollegeIds', 'collegeId', 'collegeName', 'officeId', 'programName', 'programCode',
            'semesterName', 'schoolYear', 'schoolYearIsActive', 'programId', 'semesterId', 'courseName', 'courseCode', 'courseYearLevel', 'courseType', 'courseSchoolYearId',
            'bulkCollegeId', 'bulkProgramId', 'bulkFillSemester', 'bulkFillYear', 'bulkFillType',
            'deleteTitle', 'deleteMessage',
        ]);
        $this->facultyCollegeIds = [];
        $this->schoolYearIsActive = false;
        $this->collegeProgramRows = [['code' => '', 'name' => '']];
        $this->docTypeSubRows = [['name' => '']];
        $this->courseRows = [['type' => '', 'year_level' => '', 'code' => '', 'name' => '']];
        $this->bulkRows = [];
        $this->csvFile = null;
        $this->resetValidation();
        $this->dispatch('settings-close-modal');
    }

    /**
     * Displays the doc type dialog or panel.
     *
     * @param ?int $id
     * @param ?int $parentId
     *
     * @return void
     */
    public function openDocType(?int $id = null, ?int $parentId = null): void
    {
        $this->resetFormFor('docType', $id);
        $this->parentId = $parentId;
        $this->docTypeSubRows = [['name' => '']];
        if ($id) {
            $row = DB::table('dcs_doc_types')->where('id', $id)->first();
            abort_unless($row, 404);
            $this->docTypeName = $row->doc_type_name;
            $this->parentId = $row->parent_id ? (int) $row->parent_id : null;
        }
    }

    /**
     * Displays the sub type dialog or panel.
     *
     * @param int $parentId
     *
     * @return void
     */
    public function openSubType(int $parentId): void
    {
        $this->openDocType(null, $parentId);
    }

    /**
     * Adds another blank sub-type row in the Add Document Type modal.
     */
    public function addDocTypeSubRow(): void
    {
        $this->docTypeSubRows[] = ['name' => ''];
    }

    /**
     * Removes a sub-type row from the Add Document Type modal.
     */
    public function removeDocTypeSubRow(int $index): void
    {
        if (count($this->docTypeSubRows) <= 1) {
            $this->docTypeSubRows = [['name' => '']];

            return;
        }
        unset($this->docTypeSubRows[$index]);
        $this->docTypeSubRows = array_values($this->docTypeSubRows);
    }

    /**
     * Displays the originator dialog or panel.
     *
     * @param ?int $id
     *
     * @return void
     */
    public function openOriginator(?int $id = null): void
    {
        abort_unless(Schema::hasTable('dcs_originators'), 404);
        $this->resetFormFor('originator', $id);
        if ($id) {
            $row = DB::table('dcs_originators')->where('id', $id)->first();
            abort_unless($row, 404);
            $this->originatorName = $row->originator_name;
        }
    }

    /**
     * Displays the faculty dialog or panel.
     *
     * @param ?int $id
     *
     * @return void
     */
    public function openFaculty(?int $id = null): void
    {
        $this->resetFormFor('faculty', $id);
        $this->facultyCollegeIds = [];
        if ($id) {
            $row = DB::table('dcs_faculties')->where('id', $id)->first();
            abort_unless($row, 404);
            $this->facultyName = $row->faculty_name;
            $this->facultyCollegeIds = array_map(
                'strval',
                \App\Helpers\FacultyCollegeHelper::collegeIdsFor((int) $id)
            );
        }
    }

    /**
     * Displays the college dialog or panel.
     *
     * @param ?int $id
     *
     * @return void
     */
    public function openCollege(?int $id = null): void
    {
        $this->resetFormFor('college', $id);
        $this->officeId = '';
        $this->collegeName = '';
        $this->programName = '';
        $this->programCode = '';
        $this->collegeProgramRows = [['code' => '', 'name' => '']];
        if ($id) {
            $row = DB::table('dcs_colleges')->where('id', $id)->first();
            abort_unless($row, 404);
            $this->collegeName = $row->college_name;
            $this->officeId = $row->office_id !== null ? (string) $row->office_id : '';
        }
    }

    /**
     * Adds another optional program row in the Add College modal.
     */
    public function addCollegeProgramRow(): void
    {
        $this->collegeProgramRows[] = ['code' => '', 'name' => ''];
    }

    /**
     * Removes an optional program row in the Add College modal.
     */
    public function removeCollegeProgramRow(int $index): void
    {
        if (count($this->collegeProgramRows) <= 1) {
            $this->collegeProgramRows = [['code' => '', 'name' => '']];
            return;
        }
        unset($this->collegeProgramRows[$index]);
        $this->collegeProgramRows = array_values($this->collegeProgramRows);
    }

    /**
     * Drill into a college to manage its programs.
     */
    public function openCollegePrograms(int $id): void
    {
        abort_unless(DB::table('dcs_colleges')->where('id', $id)->exists(), 404);
        $this->collegeDetailId = $id;
    }

    /**
     * Back to the colleges list.
     */
    public function closeCollegePrograms(): void
    {
        $this->collegeDetailId = null;
    }

    /**
     * Lifecycle hook executed after updating the office id property.
     *
     * @param ?string $value
     *
     * @return void
     */
    public function updatedOfficeId(?string $value): void
    {
        if ($this->modalKind !== 'college' || ! $value) {
            return;
        }
        $office = $value ? DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office')->where('id', (int) $value)->first() : null;
        $this->collegeName = $office->office_name ?? '';
    }

    /**
     * Displays the program dialog or panel.
     *
     * @param ?int $id
     *
     * @return void
     */
    /**
     * Edit an existing program, or open Add Program (optionally locked to a college).
     */
    public function openProgram(?int $id = null): void
    {
        $this->resetFormFor('program', $id);
        $this->collegeId = '';
        $this->programName = '';
        $this->programCode = '';
        if ($id) {
            $row = DB::table('dcs_programs')->where('id', $id)->first();
            abort_unless($row, 404);
            $this->collegeId = (string) $row->college_id;
            $this->programName = $row->program_name;
            $this->programCode = $row->program_code ?? '';
            $this->collegeDetailId = (int) $row->college_id;
            return;
        }

        if ($this->collegeDetailId) {
            $this->collegeId = (string) $this->collegeDetailId;
        }
    }

    /**
     * Add a program under a specific college (from the college detail page).
     */
    public function openProgramForCollege(int $collegeId): void
    {
        abort_unless(DB::table('dcs_colleges')->where('id', $collegeId)->exists(), 404);
        $this->collegeDetailId = $collegeId;
        $this->openProgram(null);
        $this->collegeId = (string) $collegeId;
    }

    /**
     * Displays the semester dialog or panel.
     *
     * @param ?int $id
     *
     * @return void
     */
    public function openSemester(?int $id = null): void
    {
        $this->resetFormFor('semester', $id);
        if ($id) {
            $row = DB::table('dcs_semesters')->where('id', $id)->first();
            abort_unless($row, 404);
            $this->semesterName = $row->semester_name;
        }
    }

    /**
     * Displays the school year dialog or panel.
     *
     * @param ?int $id
     *
     * @return void
     */
    public function openSchoolYear(?int $id = null): void
    {
        $this->resetFormFor('schoolYear', $id);
        $this->schoolYearIsActive = false;
        if ($id) {
            $row = DB::table('dcs_school_years')->where('id', $id)->first();
            abort_unless($row, 404);
            $this->schoolYear = $row->school_year;
            $this->schoolYearIsActive = Schema::hasColumn('dcs_school_years', 'is_active')
                && ! empty($row->is_active);
        }
    }

    /**
     * Make this the only active school year (used by office intake / requestors).
     */
    public function activateSchoolYear(int $id): void
    {
        if (! Schema::hasTable('dcs_school_years') || ! Schema::hasColumn('dcs_school_years', 'is_active')) {
            $this->fail('School year activation is not available yet. Run migrations first.');
            return;
        }
        $row = DB::table('dcs_school_years')->where('id', $id)->first();
        abort_unless($row, 404);
        if (Schema::hasColumn('dcs_school_years', 'deleted_at') && ! empty($row->deleted_at)) {
            $this->fail('Restore this school year from the Recycle Bin before activating it.');
            return;
        }

        DB::table('dcs_school_years')->update(['is_active' => false]);
        DB::table('dcs_school_years')->where('id', $id)->update(['is_active' => true]);
        $this->done('Active school year set to ' . $row->school_year . '. Office intake will only offer this year.');
    }

    /**
     * Displays the program course dialog or panel.
     *
     * @param ?int $id
     *
     * @return void
     */
    public function openProgramCourse(?int $id = null): void
    {
        $this->resetFormFor('programCourse', $id);
        $this->courseRows = [['type' => '', 'year_level' => '', 'code' => '', 'name' => '']];
        $this->courseName = '';
        $this->courseCode = '';
        $this->programId = '';
        $this->courseSchoolYearId = '';
        $this->courseType = '';
        $this->courseYearLevel = '';
        $hasSchoolYear = Schema::hasColumn('dcs_program_courses', 'school_year_id');
        if ($id) {
            $rowQ = DB::table('dcs_program_courses')->where('id', $id);
            \App\Helpers\SettingsRecycleHelper::applyNotDeleted($rowQ, 'dcs_program_courses');
            $row = $rowQ->first();
            abort_unless($row, 404);
            $this->programId = $row->program_id !== null ? (string) $row->program_id : '';
            $this->semesterId = (string) $row->semester_id;
            $this->courseYearLevel = $row->year_level ?? '';
            $this->courseType = $row->course_type ?? '';
            $this->courseName = $row->course_name;
            $this->courseCode = $row->course_code ?? '';
            if ($hasSchoolYear && ! empty($row->school_year_id)) {
                $this->courseSchoolYearId = (string) $row->school_year_id;
            }
        } else {
            if ($this->courseListProgramId !== '') {
                // Prefill program from the Course Names list filter; leave type open.
                $this->programId = (string) $this->courseListProgramId;
            }
            if ($hasSchoolYear && Schema::hasTable('dcs_school_years') && Schema::hasColumn('dcs_school_years', 'is_active')) {
                $activeQ = DB::table('dcs_school_years')->where('is_active', true);
                \App\Helpers\SettingsRecycleHelper::applyNotDeleted($activeQ, 'dcs_school_years');
                $activeId = $activeQ->value('id');
                if ($activeId) {
                    $this->courseSchoolYearId = (string) $activeId;
                }
            }
        }
    }

    public function updatedCourseType(?string $value): void
    {
        if ($this->modalKind !== 'programCourse' || ! $this->editingId) {
            return;
        }
        $allowed = \App\Helpers\CourseTypeRules::allowedYearLevels($value);
        if ($this->courseYearLevel !== '' && ! in_array($this->courseYearLevel, $allowed, true)) {
            $this->courseYearLevel = '';
        }
    }

    /**
     * When a bulk-add row's course type changes, clear an invalid year level.
     */
    public function updatedCourseRows(mixed $value, ?string $key = null): void
    {
        if ($this->modalKind !== 'programCourse' || $this->editingId) {
            return;
        }
        $key = (string) $key;
        if ($key === '' || ! str_ends_with($key, '.type')) {
            return;
        }
        $index = (int) explode('.', $key)[0];
        if (! isset($this->courseRows[$index])) {
            return;
        }
        $type = trim((string) ($this->courseRows[$index]['type'] ?? ''));
        $year = trim((string) ($this->courseRows[$index]['year_level'] ?? ''));
        $allowed = \App\Helpers\CourseTypeRules::allowedYearLevels($type !== '' ? $type : null);
        if ($year !== '' && ! in_array($year, $allowed, true)) {
            $this->courseRows[$index]['year_level'] = '';
        }
    }

    /**
     * Sets the active course list college.
     *
     * @param string $collegeId
     *
     * @return void
     */
    public function setCourseListCollege(string $collegeId = ''): void
    {
        $this->courseListCollegeId = $collegeId === 'all' ? '' : $collegeId;
        $this->courseListProgramId = '';
        $this->clearCourseSelection();
    }

    /**
     * Sets the active course list program.
     *
     * @param string $programId
     *
     * @return void
     */
    public function setCourseListProgram(string $programId = ''): void
    {
        $this->courseListProgramId = $programId === 'all' ? '' : $programId;
        $this->clearCourseSelection();
    }

    /**
     * Displays the program courses bulk dialog or panel.
     *
     * @param ?string $collegeId
     * @param ?string $programId
     *
     * @return void
     */
    public function openProgramCoursesBulk(?string $collegeId = null, ?string $programId = null): void
    {
        $this->resetFormFor('programCoursesBulk', null);
        $this->bulkCollegeId = $collegeId && $collegeId !== 'all' ? (string) $collegeId : '';
        $this->bulkProgramId = $programId && $programId !== 'all' ? (string) $programId : '';
        $this->bulkFillSemester = '';
        $this->bulkFillYear = '';
        $this->bulkFillType = '';
        $this->bulkRows = [];
        if ($this->bulkProgramId !== '') {
            $this->ensureBulkCollegeFromProgram();
            $this->loadBulkProgramCourses();
        }
    }

    /**
     * Lifecycle hook executed after updating the bulk college id property.
     *
     * @param ?string $value
     *
     * @return void
     */
    public function updatedBulkCollegeId(?string $value): void
    {
        if ($this->modalKind !== 'programCoursesBulk') {
            return;
        }
        $this->bulkProgramId = '';
        $this->bulkRows = [];
        $this->resetValidation();
    }

    /**
     * Lifecycle hook executed after updating the bulk program id property.
     *
     * @param ?string $value
     *
     * @return void
     */
    public function updatedBulkProgramId(?string $value): void
    {
        if ($this->modalKind !== 'programCoursesBulk') {
            return;
        }
        $this->loadBulkProgramCourses();
    }

    /**
     * Updated action.
     *
     * @param string $name
     * @param mixed $value
     *
     * @return void
     */
    public function updated(string $name, mixed $value = null): void
    {
        if (! str_starts_with($name, 'bulkRows.')) {
            return;
        }

        $this->resetErrorBag($name);
        $this->resetErrorBag('bulkRows');
    }

    /**
     * Filters and queries bulk fill based on criteria.
     *
     * @param bool $blanksOnly
     *
     * @return void
     */
    public function applyBulkFill(bool $blanksOnly = true): void
    {
        if ($this->modalKind !== 'programCoursesBulk' || $this->bulkRows === []) {
            return;
        }
        $semester = trim($this->bulkFillSemester);
        $year = trim($this->bulkFillYear);
        $type = trim($this->bulkFillType);
        if ($semester === '' && $year === '' && $type === '') {
            $this->fail('Pick a semester, year level, and/or course type to apply.');
            return;
        }
        foreach ($this->bulkRows as $i => $row) {
            if ($semester !== '' && (! $blanksOnly || trim((string) ($row['semester_id'] ?? '')) === '')) {
                $this->bulkRows[$i]['semester_id'] = $semester;
                $this->resetErrorBag("bulkRows.{$i}.semester_id");
            }
            if ($year !== '' && (! $blanksOnly || trim((string) ($row['year_level'] ?? '')) === '')) {
                $this->bulkRows[$i]['year_level'] = $year;
                $this->resetErrorBag("bulkRows.{$i}.year_level");
            }
            if ($type !== '' && (! $blanksOnly || trim((string) ($row['course_type'] ?? '')) === '')) {
                $this->bulkRows[$i]['course_type'] = $type;
                $this->resetErrorBag("bulkRows.{$i}.course_type");
            }
        }
        $this->resetErrorBag('bulkRows');
    }

    /**
     * Creates a new course row record.
     * @return void
     */
    public function addCourseRow(): void
    {
        $this->courseRows[] = ['type' => '', 'year_level' => '', 'code' => '', 'name' => ''];
        $this->dispatch('settings-focus-last-course-row');
    }

    /**
     * Deletes or deactivates the specified course row.
     *
     * @param int $index
     *
     * @return void
     */
    public function removeCourseRow(int $index): void
    {
        if (count($this->courseRows) <= 1) {
            $this->courseRows = [['type' => '', 'year_level' => '', 'code' => '', 'name' => '']];
            return;
        }
        unset($this->courseRows[$index]);
        $this->courseRows = array_values($this->courseRows);
    }

    /**
     * Displays the import originators dialog or panel.
     * @return void
     */
    public function openImportOriginators(): void
    {
        abort_unless(Schema::hasTable('dcs_originators'), 404);
        $this->csvFile = null;
        $this->resetFormFor('importOriginators', null);
    }

    /**
     * Displays the import faculties dialog or panel.
     * @return void
     */
    public function openImportFaculties(): void
    {
        $this->csvFile = null;
        $this->resetFormFor('importFaculties', null);
    }

    /**
     * Runs csv import.
     * @return void
     */
    public function runCsvImport(): void
    {
        \App\Helpers\RegisterQueryHelper::assertFullDcsUser('settings');
        match ($this->modalKind) {
            'importOriginators' => $this->importOriginatorsFromCsv(),
            'importFaculties' => $this->importFacultiesFromCsv(),
            default => null,
        };
    }

    /**
     * Submits and processes delete.
     *
     * @param string $kind
     * @param int $id
     * @param string $title
     * @param string $message
     *
     * @return void
     */
    public function confirmDelete(string $kind, int $id, string $title, string $message): void
    {
        if (! \App\Helpers\RegisterQueryHelper::isDcsSoftDeleteEnabled()) {
            $this->fail('Delete is disabled. Ask an administrator to enable it in Admin Console → System Settings → Document Control (DCS).');

            return;
        }

        $this->resetFormFor('delete', $id);
        $this->deleteTitle = $title;
        $this->deleteMessage = $message;
        $this->modalKind = $kind . ':delete';
    }

    /**
     * Saves changes to the database.
     * @return void
     */
    public function save(): void
    {
        \App\Helpers\RegisterQueryHelper::assertFullDcsUser('settings');
        match ($this->modalKind) {
            'docType' => $this->saveDocType(),
            'originator' => $this->saveOriginator(),
            'faculty' => $this->saveFaculty(),
            'college' => $this->saveCollege(),
            'program' => $this->saveProgram(),
            'semester' => $this->saveSemester(),
            'schoolYear' => $this->saveSchoolYear(),
            'programCourse' => $this->saveProgramCourse(),
            'programCoursesBulk' => $this->saveProgramCoursesBulk(),
            default => null,
        };
    }

    /**
     * Deletes or deactivates the record.
     * @return void
     */
    public function destroy(): void
    {
        \App\Helpers\RegisterQueryHelper::assertFullDcsUser('settings');
        if (! \App\Helpers\RegisterQueryHelper::isDcsSoftDeleteEnabled()) {
            $this->closeModal();
            $this->fail('Delete is disabled by the administrator.');

            return;
        }

        $kind = str_replace(':delete', '', $this->modalKind);
        $id = (int) $this->editingId;

        match ($kind) {
            'docType' => $this->destroyDocType($id),
            'originator' => $this->destroyOriginator($id),
            'faculty' => $this->destroyFaculty($id),
            'college' => $this->destroyCollege($id),
            'program' => $this->destroyProgram($id),
            'semester' => $this->destroySemester($id),
            'schoolYear' => $this->destroySchoolYear($id),
            'programCourse' => $this->destroyProgramCourse($id),
            'programCoursesSelected' => $this->destroySelectedProgramCourses(),
            default => null,
        };
    }

    /**
     * Persists doc type changes to the database.
     * @return void
     */
    private function saveDocType(): void
    {
        $this->validate(['docTypeName' => 'required|string|max:255']);
        $parentId = $this->editingId
            ? DB::table('dcs_doc_types')->where('id', $this->editingId)->value('parent_id')
            : $this->parentId;

        $exists = DB::table('dcs_doc_types')
            ->where('doc_type_name', $this->docTypeName)
            ->where('parent_id', $parentId)
            ->when($this->editingId, fn ($q) => $q->where('id', '!=', $this->editingId));
        \App\Helpers\SettingsRecycleHelper::applyNotDeleted($exists, 'dcs_doc_types');
        if ($exists->exists()) {
            $this->fail('Another entry with this name already exists at the same level.');
            return;
        }

        $payload = ['doc_type_name' => $this->docTypeName];

        if ($this->editingId) {
            DB::table('dcs_doc_types')->where('id', $this->editingId)->update($payload);

            $this->done('Updated successfully.');
            return;
        }

        // Creating a top-level type: optional sub-types in the same modal (like college + programs).
        $subRows = [];
        if ($parentId === null) {
            foreach ($this->docTypeSubRows as $i => $row) {
                $name = trim((string) ($row['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                if (mb_strlen($name) > 255) {
                    $this->addError("docTypeSubRows.{$i}.name", 'Sub-type name may not be greater than 255 characters.');
                    continue;
                }
                $subRows[] = ['index' => $i, 'name' => $name];
            }
            if ($this->getErrorBag()->isNotEmpty()) {
                return;
            }

            $namesSeen = [];
            foreach ($subRows as $entry) {
                $key = mb_strtolower($entry['name']);
                if (isset($namesSeen[$key])) {
                    $this->addError("docTypeSubRows.{$entry['index']}.name", 'Duplicate sub-type name in this list.');
                    continue;
                }
                $namesSeen[$key] = true;
            }
            if ($this->getErrorBag()->isNotEmpty()) {
                return;
            }
        }

        $newId = (int) DB::table('dcs_doc_types')->insertGetId(array_merge($payload, [
            'parent_id' => $parentId,
        ]));

        if ($parentId === null && $newId > 0 && $subRows !== []) {
            DB::table('dcs_doc_types')->insert(array_map(
                fn (array $entry) => [
                    'doc_type_name' => $entry['name'],
                    'parent_id' => $newId,
                ],
                $subRows
            ));
            $count = count($subRows);
            $this->done($count === 1
                ? 'Document type and sub-type added.'
                : "Document type and {$count} sub-types added.");

            return;
        }

        $this->done($parentId ? 'Sub-type added.' : 'Document type added.');
    }

    /**
     * Persists originator changes to the database.
     * @return void
     */
    private function saveOriginator(): void
    {
        if (! Schema::hasTable('dcs_originators')) {
            $this->fail('Originators table is not available. Run pending migrations.');
            return;
        }

        $this->validate([
            'originatorName' => [
                'required', 'string', 'max:255',
                \App\Helpers\SettingsRecycleHelper::uniqueRule('dcs_originators', 'originator_name', $this->editingId),
            ],
        ]);

        if ($this->editingId) {
            DB::table('dcs_originators')->where('id', $this->editingId)->update(['originator_name' => $this->originatorName]);
            $this->done('Originator updated.');
            return;
        }

        DB::table('dcs_originators')->insert(['originator_name' => $this->originatorName]);
        $this->done('Originator added.');
    }

    /**
     * Persists faculty changes to the database.
     * @return void
     */
    private function saveFaculty(): void
    {
        $this->validate([
            'facultyName' => 'required|string|max:255',
            'facultyCollegeIds' => 'required|array|min:1',
            'facultyCollegeIds.*' => 'integer|exists:dcs_colleges,id',
        ], [
            'facultyCollegeIds.required' => 'Select at least one college for this faculty.',
            'facultyCollegeIds.min' => 'Select at least one college for this faculty.',
        ]);

        $collegeIds = array_values(array_unique(array_map('intval', $this->facultyCollegeIds)));
        $name = trim($this->facultyName);

        $existsQ = DB::table('dcs_faculties')
            ->whereRaw('LOWER(TRIM(faculty_name)) = ?', [mb_strtolower($name)])
            ->when($this->editingId, fn ($q) => $q->where('id', '!=', $this->editingId));
        \App\Helpers\SettingsRecycleHelper::applyNotDeleted($existsQ, 'dcs_faculties');
        if ($existsQ->exists()) {
            $this->fail('A faculty with this name already exists. Edit that record to add colleges.');
            return;
        }

        if ($this->editingId) {
            DB::table('dcs_faculties')->where('id', $this->editingId)->update([
                'faculty_name' => $name,
                'updated_at' => now(),
            ]);
            \App\Helpers\FacultyCollegeHelper::sync((int) $this->editingId, $collegeIds);
            $this->done('Faculty updated.');
            return;
        }

        $facultyId = (int) DB::table('dcs_faculties')->insertGetId([
            'faculty_name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \App\Helpers\FacultyCollegeHelper::sync($facultyId, $collegeIds);
        $this->done('Faculty added.');
    }

    /**
     * Processes and imports originators from csv from uploaded file.
     * @return void
     */
    private function importOriginatorsFromCsv(): void
    {
        if (! Schema::hasTable('dcs_originators')) {
            $this->fail('Originators table is not available. Run pending migrations.');
            return;
        }

        $this->validate([
            'csvFile' => 'required|file|mimes:csv,txt|max:2048',
        ], [
            'csvFile.required' => 'Please choose a CSV file.',
            'csvFile.mimes' => 'Upload a .csv file.',
        ]);

        $rows = $this->readCsvRows($this->csvFile->getRealPath());
        if ($rows === []) {
            $this->fail('The CSV file is empty.');
            return;
        }

        $added = 0;
        $skipped = 0;
        foreach ($rows as $cols) {
            $name = trim((string) ($cols[0] ?? ''));
            if ($name === '' || $this->isCsvHeaderValue($name, ['originator', 'originator_name', 'name'])) {
                continue;
            }
            if (mb_strlen($name) > 255) {
                $skipped++;
                continue;
            }
            $existsQ = DB::table('dcs_originators')->where('originator_name', $name);
            \App\Helpers\SettingsRecycleHelper::applyNotDeleted($existsQ, 'dcs_originators');
            if ($existsQ->exists()) {
                $skipped++;
                continue;
            }
            DB::table('dcs_originators')->insert([
                'originator_name' => $name,
            ]);
            $added++;
        }

        if ($added === 0 && $skipped === 0) {
            $this->fail('No originator names found in the CSV.');
            return;
        }

        $this->csvFile = null;
        $this->done($this->csvImportSummary('originator', $added, $skipped));
    }

    /**
     * Processes and imports faculties from csv from uploaded file.
     * @return void
     */
    private function importFacultiesFromCsv(): void
    {
        $this->validate([
            'csvFile' => 'required|file|mimes:csv,txt|max:2048',
        ], [
            'csvFile.required' => 'Please choose a CSV file.',
            'csvFile.mimes' => 'Upload a .csv file.',
        ]);

        $rows = $this->readCsvRows($this->csvFile->getRealPath());
        if ($rows === []) {
            $this->fail('The CSV file is empty.');
            return;
        }

        $collegeMap = [];
        $collegesQ = DB::table('dcs_colleges')->select('id', 'college_name');
        \App\Helpers\SettingsRecycleHelper::applyNotDeleted($collegesQ, 'dcs_colleges');
        foreach ($collegesQ->get() as $college) {
            $collegeMap[mb_strtolower(trim($college->college_name))] = (int) $college->id;
        }

        $added = 0;
        $skipped = 0;
        foreach ($rows as $cols) {
            $name = trim((string) ($cols[0] ?? ''));
            $collegeName = trim((string) ($cols[1] ?? ''));
            if ($name === '' || $this->isCsvHeaderValue($name, ['faculty', 'faculty_name', 'name'])) {
                continue;
            }
            if (mb_strlen($name) > 255) {
                $skipped++;
                continue;
            }

            if ($collegeName === '' || $this->isCsvHeaderValue($collegeName, ['college', 'college_name'])) {
                $skipped++;
                continue;
            }
            $collegeId = $collegeMap[mb_strtolower($collegeName)] ?? null;
            if ($collegeId === null) {
                $skipped++;
                continue;
            }

            $existsQ = DB::table('dcs_faculties')
                ->whereRaw('LOWER(TRIM(faculty_name)) = ?', [mb_strtolower($name)]);
            \App\Helpers\SettingsRecycleHelper::applyNotDeleted($existsQ, 'dcs_faculties');
            $existingId = (int) ($existsQ->value('id') ?: 0);

            try {
                if ($existingId > 0) {
                    if (\App\Helpers\FacultyCollegeHelper::attach($existingId, (int) $collegeId)) {
                        $added++;
                    } else {
                        $skipped++;
                    }
                    continue;
                }

                $facultyId = (int) DB::table('dcs_faculties')->insertGetId([
                    'faculty_name' => $name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                \App\Helpers\FacultyCollegeHelper::sync($facultyId, [(int) $collegeId]);
                $added++;
            } catch (\Throwable) {
                $skipped++;
            }
        }

        if ($added === 0 && $skipped === 0) {
            $this->fail('No faculty names found in the CSV.');
            return;
        }

        $this->csvFile = null;
        $this->done($this->csvImportSummary('faculty', $added, $skipped));
    }

    /** @return list<list<string>> */
    private function readCsvRows(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return [];
        }

        $rows = [];
        while (($data = fgetcsv($handle)) !== false) {
            if (! is_array($data)) {
                continue;
            }
            $cols = array_map(fn ($v) => trim((string) $v), $data);
            if (count(array_filter($cols, fn ($v) => $v !== '')) === 0) {
                continue;
            }
            $rows[] = $cols;
        }
        fclose($handle);

        return $rows;
    }

    /** @param list<string> $headers */
    private function isCsvHeaderValue(string $value, array $headers): bool
    {
        $normalized = mb_strtolower(str_replace([' ', '-'], '_', $value));

        return in_array($normalized, $headers, true);
    }

    /**
     * Csvs import summary.
     *
     * @param string $singular
     * @param int $added
     * @param int $skipped
     *
     * @return string
     */
    private function csvImportSummary(string $singular, int $added, int $skipped): string
    {
        $plural = $singular === 'faculty' ? 'faculties' : ($singular . 's');
        $msg = $added === 1 ? "1 {$singular} imported." : "{$added} {$plural} imported.";
        if ($skipped > 0) {
            $msg .= " {$skipped} skipped (duplicate, missing/unknown college, or invalid).";
        }

        return $msg;
    }

    /**
     * Persists college changes to the database.
     * @return void
     */
    private function saveCollege(): void
    {
        $officeTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office';

        $this->validate([
            'officeId' => "nullable|integer|exists:{$officeTbl},id",
            'collegeName' => [
                'required', 'string', 'max:255',
                \App\Helpers\SettingsRecycleHelper::uniqueRule('dcs_colleges', 'college_name', $this->editingId),
            ],
        ]);

        $officeId = $this->officeId !== '' ? (int) $this->officeId : null;
        $office = $officeId ? DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office')->where('id', $officeId)->first() : null;

        if ($officeId && ! $office) {
            $this->fail('That office no longer exists.');
            return;
        }

        if ($office && ! $this->officeIsActive($office) && (int) ($this->editingId ? DB::table('dcs_colleges')->where('id', $this->editingId)->value('office_id') : 0) !== (int) $office->id) {
            $this->fail('That office is inactive and cannot be used.');
            return;
        }

        if ($officeId) {
            $officeTakenQ = DB::table('dcs_colleges')
                ->where('office_id', $officeId)
                ->when($this->editingId, fn ($q) => $q->where('id', '!=', $this->editingId));
            \App\Helpers\SettingsRecycleHelper::applyNotDeleted($officeTakenQ, 'dcs_colleges');
            if ($officeTakenQ->exists()) {
                $this->fail('That office is already linked to another college.');
                return;
            }
        }

        if ($office) {
            $code = $this->collegeCodeFromOffice($office, $this->collegeName, $this->editingId);
        } elseif ($this->editingId) {
            $code = (string) (DB::table('dcs_colleges')->where('id', $this->editingId)->value('college_code') ?: $this->uniqueCollegeCode($this->collegeName, $this->editingId));
        } else {
            $code = $this->uniqueCollegeCode($this->collegeName, $this->editingId);
        }

        $payload = [
            'office_id' => $officeId,
            'college_code' => $code,
            'college_name' => $this->collegeName,
        ];

        if ($this->editingId) {
            DB::table('dcs_colleges')->where('id', $this->editingId)->update($payload);
            $this->done('College updated.');
            return;
        }

        $programRows = [];
        foreach ($this->collegeProgramRows as $i => $row) {
            $name = trim((string) ($row['name'] ?? ''));
            $code = trim((string) ($row['code'] ?? ''));
            if ($name === '' && $code === '') {
                continue;
            }
            if ($name === '') {
                $this->addError("collegeProgramRows.{$i}.name", 'Program name is required.');
            }
            if ($code === '') {
                $this->addError("collegeProgramRows.{$i}.code", 'Program code is required.');
            }
            if (mb_strlen($name) > 255) {
                $this->addError("collegeProgramRows.{$i}.name", 'Program name may not be greater than 255 characters.');
            }
            if (mb_strlen($code) > 50) {
                $this->addError("collegeProgramRows.{$i}.code", 'Program code may not be greater than 50 characters.');
            }
            $programRows[] = ['index' => $i, 'name' => $name, 'code' => $code];
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $codesSeen = [];
        foreach ($programRows as $entry) {
            $key = mb_strtolower($entry['code']);
            if (isset($codesSeen[$key])) {
                $this->addError("collegeProgramRows.{$entry['index']}.code", 'Duplicate program code in this list.');
                continue;
            }
            $codesSeen[$key] = true;
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $newId = (int) DB::table('dcs_colleges')->insertGetId($payload);
        $this->collegeDetailId = $newId > 0 ? $newId : null;

        if ($newId > 0 && $programRows !== []) {
            DB::table('dcs_programs')->insert(array_map(
                fn (array $entry) => [
                    'college_id' => $newId,
                    'program_name' => $entry['name'],
                    'program_code' => $entry['code'],
                ],
                $programRows
            ));
            $count = count($programRows);
            $this->done($count === 1
                ? 'College and program added.'
                : "College and {$count} programs added.");
            return;
        }

        $this->done('College added. Click the college name to add programs.');
    }

    /**
     * Persists program changes to the database.
     * @return void
     */
    private function saveProgram(): void
    {
        $this->validate([
            'collegeId' => 'required|integer|exists:dcs_colleges,id',
            'programName' => 'required|string|max:255',
            'programCode' => [
                'required', 'string', 'max:50',
                \App\Helpers\SettingsRecycleHelper::uniqueRule('dcs_programs', 'program_code', $this->editingId)
                    ->where(fn ($q) => $q->where('college_id', (int) $this->collegeId)),
            ],
        ]);

        $payload = [
            'college_id' => (int) $this->collegeId,
            'program_name' => $this->programName,
            'program_code' => $this->programCode,
        ];

        if ($this->editingId) {
            DB::table('dcs_programs')->where('id', $this->editingId)->update($payload);
            $this->done('Program updated.');
            return;
        }

        DB::table('dcs_programs')->insert($payload);
        $this->done('Program added.');
    }

    /**
     * Persists semester changes to the database.
     * @return void
     */
    private function saveSemester(): void
    {
        $this->validate([
            'semesterName' => [
                'required', 'string', 'max:50',
                \App\Helpers\SettingsRecycleHelper::uniqueRule('dcs_semesters', 'semester_name', $this->editingId),
            ],
        ]);

        if ($this->editingId) {
            DB::table('dcs_semesters')->where('id', $this->editingId)->update(['semester_name' => $this->semesterName]);
            $this->done('Semester updated.');
            return;
        }

        DB::table('dcs_semesters')->insert(['semester_name' => $this->semesterName]);
        $this->done('Semester added.');
    }

    /**
     * Persists school year changes to the database.
     * @return void
     */
    private function saveSchoolYear(): void
    {
        $this->validate([
            'schoolYear' => [
                'required', 'string', 'max:50',
                \App\Helpers\SettingsRecycleHelper::uniqueRule('dcs_school_years', 'school_year', $this->editingId),
            ],
        ]);

        $hasActive = Schema::hasColumn('dcs_school_years', 'is_active');
        $payload = ['school_year' => $this->schoolYear];

        if ($this->editingId) {
            DB::table('dcs_school_years')->where('id', $this->editingId)->update($payload);
            $id = (int) $this->editingId;
        } else {
            $id = (int) DB::table('dcs_school_years')->insertGetId($payload);
        }

        if ($hasActive && $this->schoolYearIsActive && $id > 0) {
            DB::table('dcs_school_years')->update(['is_active' => false]);
            DB::table('dcs_school_years')->where('id', $id)->update(['is_active' => true]);
        }

        $this->done($this->editingId ? 'School year updated.' : 'School year added.');
    }

    /**
     * Persists program course changes to the database.
     * @return void
     */
    private function saveProgramCourse(): void
    {
        $hasCourseCode = Schema::hasColumn('dcs_program_courses', 'course_code');
        $hasYearLevel = Schema::hasColumn('dcs_program_courses', 'year_level');
        $hasCourseType = Schema::hasColumn('dcs_program_courses', 'course_type');

        if ($this->editingId) {
            $this->saveSingleProgramCourse($hasCourseCode, $hasYearLevel, $hasCourseType);
            return;
        }

        $this->saveBulkProgramCourses($hasCourseCode, $hasYearLevel, $hasCourseType);
    }

    /**
     * Persists single program course changes to the database.
     *
     * @param bool $hasCourseCode
     * @param bool $hasYearLevel
     * @param bool $hasCourseType
     *
     * @return void
     */
    private function saveSingleProgramCourse(bool $hasCourseCode, bool $hasYearLevel, bool $hasCourseType): void
    {
        $courseType = $hasCourseType ? trim($this->courseType) : null;
        $hasSchoolYear = Schema::hasColumn('dcs_program_courses', 'school_year_id');

        $rules = [
            'programId' => 'required|integer|exists:dcs_programs,id',
            'semesterId' => 'required|integer|exists:dcs_semesters,id',
            'courseName' => 'required|string|max:255',
        ];
        if ($hasSchoolYear) {
            $rules['courseSchoolYearId'] = 'required|integer|exists:dcs_school_years,id';
        }
        if ($hasYearLevel) {
            $allowedYears = implode(',', \App\Helpers\CourseTypeRules::allowedYearLevels($courseType));
            $rules['courseYearLevel'] = 'required|string|max:50|in:'.$allowedYears;
        }
        if ($hasCourseType) {
            $rules['courseType'] = 'required|string|max:50|in:GE Courses,PE Courses,NSTP,Major';
        }
        if ($hasCourseCode) {
            $rules['courseCode'] = 'required|string|max:50';
        }
        $this->validate($rules);

        if (! $this->assertCourseTypeCaps($courseType, $hasYearLevel ? $this->courseYearLevel : null, (int) $this->semesterId)) {
            return;
        }

        $editingId = (int) $this->editingId;
        if ($editingId < 1) {
            $this->fail('Could not update course. Reload and try again.');
            return;
        }

        $existing = DB::table('dcs_program_courses')->where('id', $editingId);
        \App\Helpers\SettingsRecycleHelper::applyNotDeleted($existing, 'dcs_program_courses');
        if (! $existing->exists()) {
            $this->fail('That course is no longer available. It may have been moved to the Admin Console Recycle Bin.');
            $this->bumpCourseList();
            $this->closeModal();

            return;
        }

        $yearLevel = $hasYearLevel ? $this->courseYearLevel : null;
        $programId = (int) $this->programId;
        $semesterId = (int) $this->semesterId;
        $schoolYearId = $hasSchoolYear ? (int) $this->courseSchoolYearId : null;
        $courseName = trim($this->courseName);
        $courseCode = $hasCourseCode ? trim($this->courseCode) : null;

        if ($this->programCourseNameTaken($programId, $semesterId, $courseName, $yearLevel, $hasYearLevel, $courseType, $hasCourseType, $editingId, false, $schoolYearId, $hasSchoolYear)) {
            $this->fail('This course is already listed for the selected program, school year, semester, year level, and course type.');
            return;
        }

        if ($hasCourseCode && $this->programCourseCodeTaken($programId, $semesterId, (string) $courseCode, $yearLevel, $hasYearLevel, $courseType, $hasCourseType, $editingId, false, $schoolYearId, $hasSchoolYear)) {
            $this->fail('This course code is already used for the selected program, school year, semester, year level, and course type.');
            return;
        }

        $payload = [
            'program_id' => $programId,
            'semester_id' => $semesterId,
            'course_name' => $courseName,
            'updated_at' => now(),
        ];
        if ($hasYearLevel) {
            $payload['year_level'] = $yearLevel;
        }
        if ($hasCourseType) {
            $payload['course_type'] = $courseType;
        }
        if ($hasCourseCode) {
            $payload['course_code'] = $courseCode;
        }
        if ($hasSchoolYear) {
            $payload['school_year_id'] = $schoolYearId;
        }

        try {
            DB::table('dcs_program_courses')->where('id', $editingId)->update($payload);
        } catch (\Throwable $e) {
            report($e);
            $this->fail('Could not update course. Check for a duplicate name/code (including items in the Admin Console Recycle Bin).');

            return;
        }

        // Force a clean table remount so year-group morphing does not blank the list.
        $this->bumpCourseList();
        $this->done('Course updated.');
    }

    /**
     * Persists bulk program courses changes to the database.
     *
     * @param bool $hasCourseCode
     * @param bool $hasYearLevel
     * @param bool $hasCourseType
     *
     * @return void
     */
    private function saveBulkProgramCourses(bool $hasCourseCode, bool $hasYearLevel, bool $hasCourseType): void
    {
        $hasSchoolYear = Schema::hasColumn('dcs_program_courses', 'school_year_id');

        $rules = [
            'programId' => 'required|integer|exists:dcs_programs,id',
            'semesterId' => 'required|integer|exists:dcs_semesters,id',
            'courseRows' => 'required|array|min:1',
            'courseRows.*.name' => 'nullable|string|max:255',
            'courseRows.*.code' => 'nullable|string|max:50',
            'courseRows.*.type' => 'nullable|string|max:50',
            'courseRows.*.year_level' => 'nullable|string|max:50',
        ];
        if ($hasSchoolYear) {
            $rules['courseSchoolYearId'] = 'required|integer|exists:dcs_school_years,id';
        }
        $this->validate($rules);

        $programId = (int) $this->programId;
        $semesterId = (int) $this->semesterId;
        $schoolYearId = $hasSchoolYear ? (int) $this->courseSchoolYearId : null;

        $entries = [];
        foreach ($this->courseRows as $i => $row) {
            $name = trim((string) ($row['name'] ?? ''));
            $code = trim((string) ($row['code'] ?? ''));
            $type = trim((string) ($row['type'] ?? ''));
            $year = trim((string) ($row['year_level'] ?? ''));
            if ($name === '' && $code === '' && $type === '' && $year === '') {
                continue;
            }
            if ($hasCourseType && $type === '') {
                $this->addError("courseRows.{$i}.type", 'Course type is required.');
            } elseif ($hasCourseType && ! in_array($type, \App\Helpers\CourseTypeRules::ALL_TYPES, true)) {
                $this->addError("courseRows.{$i}.type", 'Select a valid course type.');
            }
            if ($hasYearLevel && $year === '') {
                $this->addError("courseRows.{$i}.year_level", 'Year level is required.');
            }
            if ($name === '') {
                $this->addError("courseRows.{$i}.name", 'Course name is required.');
            }
            if ($hasCourseCode && $code === '') {
                $this->addError("courseRows.{$i}.code", 'Course code is required.');
            }
            if ($this->getErrorBag()->has("courseRows.{$i}.type")
                || $this->getErrorBag()->has("courseRows.{$i}.year_level")
                || $this->getErrorBag()->has("courseRows.{$i}.name")
                || $this->getErrorBag()->has("courseRows.{$i}.code")) {
                continue;
            }
            if ($hasCourseType && $hasYearLevel && $type !== '' && $year !== '') {
                if (! \App\Helpers\CourseTypeRules::isYearLevelAllowed($type, $year)) {
                    $this->addError("courseRows.{$i}.year_level", 'Year level is not allowed for '.$type.'.');
                    continue;
                }
                $semName = (string) (DB::table('dcs_semesters')->where('id', $semesterId)->value('semester_name') ?? '');
                if ($semName !== '' && ! \App\Helpers\CourseTypeRules::isYearSemesterAllowed($type, $year, $semName)) {
                    $this->addError("courseRows.{$i}.year_level", $type.' cannot use '.$year.' / '.$semName.'.');
                    continue;
                }
            }
            $entries[] = [
                'index' => $i,
                'name' => $name,
                'code' => $code,
                'type' => $hasCourseType ? $type : null,
                'year_level' => $hasYearLevel ? $year : null,
            ];
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        if ($entries === []) {
            $this->addError('courseRows.0.name', 'Add at least one course.');
            return;
        }

        $seenNames = [];
        $seenCodes = [];
        foreach ($entries as $entry) {
            $scope = mb_strtolower(($entry['type'] ?? '').'|'.($entry['year_level'] ?? ''));
            $nameKey = $scope.'|'.mb_strtolower($entry['name']);
            if (isset($seenNames[$nameKey])) {
                $this->addError("courseRows.{$entry['index']}.name", 'Duplicate course name for this type and year level in this list.');
                continue;
            }
            $seenNames[$nameKey] = true;

            if ($hasCourseCode) {
                $codeKey = $scope.'|'.mb_strtolower($entry['code']);
                if (isset($seenCodes[$codeKey])) {
                    $this->addError("courseRows.{$entry['index']}.code", 'Duplicate course code for this type and year level in this list.');
                    continue;
                }
                $seenCodes[$codeKey] = true;
            }
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        foreach ($entries as $entry) {
            $rowType = $entry['type'];
            $rowYear = $entry['year_level'];
            if ($this->programCourseNameTaken($programId, $semesterId, $entry['name'], $rowYear, $hasYearLevel, $rowType, $hasCourseType, null, false, $schoolYearId, $hasSchoolYear)) {
                $this->addError(
                    "courseRows.{$entry['index']}.name",
                    'Already listed for this program, school year, semester, year level, and course type.'
                );
            }
            if ($hasCourseCode && $this->programCourseCodeTaken($programId, $semesterId, $entry['code'], $rowYear, $hasYearLevel, $rowType, $hasCourseType, null, false, $schoolYearId, $hasSchoolYear)) {
                $this->addError(
                    "courseRows.{$entry['index']}.code",
                    'Code already used for this program, school year, semester, year level, and course type.'
                );
            }
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $now = now();
        foreach ($entries as $entry) {
            $payload = [
                'program_id' => $programId,
                'semester_id' => $semesterId,
                'course_name' => $entry['name'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if ($hasYearLevel) {
                $payload['year_level'] = $entry['year_level'];
            }
            if ($hasCourseType) {
                $payload['course_type'] = $entry['type'];
            }
            if ($hasCourseCode) {
                $payload['course_code'] = $entry['code'];
            }
            if ($hasSchoolYear) {
                $payload['school_year_id'] = $schoolYearId;
            }
            DB::table('dcs_program_courses')->insert($payload);
        }

        $count = count($entries);
        $this->courseRows = [['type' => '', 'year_level' => '', 'code' => '', 'name' => '']];
        $this->resetValidation();
        \App\Helpers\RegisterPersistHelper::logAdminChange(
            'DCS settings: ' . ($count === 1 ? 'Course added.' : "{$count} courses added.")
        );
        $this->flashToast(
            $count === 1
                ? 'Course added. Keep going or close when finished.'
                : "{$count} courses added. Keep going or close when finished.",
            'success'
        );
        $this->bumpCourseList();
        $this->dispatch('settings-focus-course-row');
    }

    /**
     * Ensures bulk college from program.
     * @return void
     */
    private function ensureBulkCollegeFromProgram(): void
    {
        if ($this->bulkProgramId === '') {
            return;
        }
        $collegeId = DB::table('dcs_programs')->where('id', (int) $this->bulkProgramId)->value('college_id');
        if ($collegeId) {
            $this->bulkCollegeId = (string) $collegeId;
        }
    }

    /**
     * Retrieves and populates bulk program courses data.
     * @return void
     */
    private function loadBulkProgramCourses(): void
    {
        $this->resetValidation();
        $programId = (int) $this->bulkProgramId;
        if ($programId < 1) {
            $this->bulkRows = [];
            return;
        }

        if ($this->bulkCollegeId !== '') {
            $belongs = DB::table('dcs_programs')
                ->where('id', $programId)
                ->where('college_id', (int) $this->bulkCollegeId)
                ->exists();
            if (! $belongs) {
                $this->bulkRows = [];
                return;
            }
        } else {
            $this->ensureBulkCollegeFromProgram();
        }

        $hasCourseCode = Schema::hasColumn('dcs_program_courses', 'course_code');
        $hasYearLevel = Schema::hasColumn('dcs_program_courses', 'year_level');
        $hasCourseType = Schema::hasColumn('dcs_program_courses', 'course_type');
        $hasSchoolYear = Schema::hasColumn('dcs_program_courses', 'school_year_id');
        $cols = ['pc.id', 'pc.semester_id', 'pc.course_name', 's.semester_name'];
        if ($hasYearLevel) {
            $cols[] = 'pc.year_level';
        }
        if ($hasCourseType) {
            $cols[] = 'pc.course_type';
        }
        if ($hasCourseCode) {
            $cols[] = 'pc.course_code';
        }
        if ($hasSchoolYear) {
            $cols[] = 'pc.school_year_id';
        }

        $query = DB::table('dcs_program_courses as pc')
            ->leftJoin('dcs_semesters as s', 's.id', '=', 'pc.semester_id')
            ->where('pc.program_id', $programId)
            ->orderBy('pc.semester_id');
        if ($hasYearLevel) {
            $query->orderByRaw("CASE pc.year_level
                WHEN '1st Year' THEN 1
                WHEN '2nd Year' THEN 2
                WHEN '3rd Year' THEN 3
                WHEN '4th Year' THEN 4
                WHEN '5th Year' THEN 5
                ELSE 99 END");
        }
        if ($hasCourseCode) {
            $query->orderByRaw("CASE WHEN pc.course_code IS NULL OR pc.course_code = '' THEN 1 ELSE 0 END")
                ->orderBy('pc.course_code');
        }
        $query->orderBy('pc.course_name');
        \App\Helpers\SettingsRecycleHelper::applyNotDeleted($query, 'dcs_program_courses', 'pc');

        $this->bulkRows = $query->get($cols)->map(function ($row) use ($hasSchoolYear) {
            $mapped = [
                'id' => (int) $row->id,
                'semester_id' => (string) $row->semester_id,
                'semester_name' => (string) ($row->semester_name ?? '—'),
                'year_level' => (string) ($row->year_level ?? ''),
                'course_type' => (string) ($row->course_type ?? ''),
                'code' => (string) ($row->course_code ?? ''),
                'name' => (string) ($row->course_name ?? ''),
            ];
            if ($hasSchoolYear) {
                $mapped['school_year_id'] = $row->school_year_id !== null ? (string) $row->school_year_id : '';
            }

            return $mapped;
        })->values()->all();
    }

    /**
     * Persists program courses bulk changes to the database.
     * @return void
     */
    private function saveProgramCoursesBulk(): void
    {
        $hasCourseCode = Schema::hasColumn('dcs_program_courses', 'course_code');
        $hasYearLevel = Schema::hasColumn('dcs_program_courses', 'year_level');
        $hasCourseType = Schema::hasColumn('dcs_program_courses', 'course_type');
        $hasSchoolYear = Schema::hasColumn('dcs_program_courses', 'school_year_id');
        $years = \App\Helpers\SyllabiMonitoringHelper::YEAR_LEVELS;
        $types = \App\Helpers\SyllabiMonitoringHelper::COURSE_TYPES;

        // Keep a dense 0..n index list so Livewire nested models stay stable.
        $this->bulkRows = collect($this->bulkRows)
            ->filter(fn ($row) => is_array($row) && (int) ($row['id'] ?? 0) > 0)
            ->values()
            ->map(function (array $row) use ($hasSchoolYear) {
                $row['id'] = (int) ($row['id'] ?? 0);
                $row['semester_id'] = (string) ($row['semester_id'] ?? '');
                $row['year_level'] = trim((string) ($row['year_level'] ?? ''));
                $row['course_type'] = trim((string) ($row['course_type'] ?? ''));
                $row['code'] = trim((string) ($row['code'] ?? ''));
                $row['name'] = trim((string) ($row['name'] ?? ''));
                if ($hasSchoolYear) {
                    $row['school_year_id'] = (string) ($row['school_year_id'] ?? '');
                }

                return $row;
            })
            ->all();

        if ($this->bulkRows === []) {
            $this->fail('No courses loaded for this program. Pick the program again and retry.');

            return;
        }

        $rules = [
            'bulkCollegeId' => 'required|integer|exists:dcs_colleges,id',
            'bulkProgramId' => 'required|integer|exists:dcs_programs,id',
            'bulkRows' => 'required|array|min:1',
            'bulkRows.*.id' => 'required|integer',
            'bulkRows.*.semester_id' => 'required|integer|exists:dcs_semesters,id',
            'bulkRows.*.name' => 'required|string|max:255',
            'bulkRows.*.code' => $hasCourseCode ? 'required|string|max:50' : 'nullable|string|max:50',
            'bulkRows.*.year_level' => $hasYearLevel ? 'required|string|in:' . implode(',', $years) : 'nullable|string',
            'bulkRows.*.course_type' => $hasCourseType ? 'required|string|in:' . implode(',', $types) : 'nullable|string',
        ];
        if ($hasSchoolYear) {
            $rules['bulkRows.*.school_year_id'] = 'required|integer|exists:dcs_school_years,id';
        }

        try {
            $this->validate($rules);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->fail('Could not save — fill School Year, Year Level, Course Type, Code, and Name on every row.');
            throw $e;
        }

        $programId = (int) $this->bulkProgramId;
        $belongs = DB::table('dcs_programs')
            ->where('id', $programId)
            ->where('college_id', (int) $this->bulkCollegeId)
            ->exists();
        if (! $belongs) {
            $this->fail('That program does not belong to the selected college.');
            return;
        }

        $ids = collect($this->bulkRows)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $ownedQuery = DB::table('dcs_program_courses')
            ->where('program_id', $programId)
            ->whereIn('id', $ids);
        \App\Helpers\SettingsRecycleHelper::applyNotDeleted($ownedQuery, 'dcs_program_courses');
        $owned = $ownedQuery
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        if (count($owned) !== count(array_unique($ids))) {
            $this->fail('One or more courses are not part of this program. Reload and try again.');
            $this->loadBulkProgramCourses();

            return;
        }

        $seenNames = [];
        $seenCodes = [];
        foreach ($this->bulkRows as $i => $row) {
            $nameKey = mb_strtolower(trim((string) ($row['name'] ?? '')))
                . '|' . (string) ($row['semester_id'] ?? '')
                . '|' . (string) ($row['year_level'] ?? '')
                . '|' . (string) ($row['course_type'] ?? '')
                . '|' . (string) ($row['school_year_id'] ?? '');
            if (isset($seenNames[$nameKey])) {
                $this->addError("bulkRows.{$i}.name", 'Duplicate course name for this school year, semester, year, and type.');
            }
            $seenNames[$nameKey] = true;

            if ($hasCourseCode) {
                $codeKey = mb_strtolower(trim((string) ($row['code'] ?? '')))
                    . '|' . (string) ($row['semester_id'] ?? '')
                    . '|' . (string) ($row['year_level'] ?? '')
                    . '|' . (string) ($row['course_type'] ?? '')
                    . '|' . (string) ($row['school_year_id'] ?? '');
                if (isset($seenCodes[$codeKey])) {
                    $this->addError("bulkRows.{$i}.code", 'Duplicate course code for this school year, semester, year, and type.');
                }
                $seenCodes[$codeKey] = true;
            }

            $rowType = $hasCourseType ? trim((string) ($row['course_type'] ?? '')) : null;
            $rowSchoolYearId = $hasSchoolYear ? (int) ($row['school_year_id'] ?? 0) : null;
            if ($this->programCourseNameTaken(
                $programId,
                (int) $row['semester_id'],
                trim((string) $row['name']),
                $hasYearLevel ? ($row['year_level'] ?: null) : null,
                $hasYearLevel,
                $rowType,
                $hasCourseType,
                (int) $row['id'],
                false,
                $rowSchoolYearId,
                $hasSchoolYear
            )) {
                $this->addError("bulkRows.{$i}.name", 'Already used by another course in this program for the same school year, semester, year level, and type.');
            }
            if ($hasCourseCode && $this->programCourseCodeTaken(
                $programId,
                (int) $row['semester_id'],
                trim((string) $row['code']),
                $hasYearLevel ? ($row['year_level'] ?: null) : null,
                $hasYearLevel,
                $rowType,
                $hasCourseType,
                (int) $row['id'],
                false,
                $rowSchoolYearId,
                $hasSchoolYear
            )) {
                $this->addError("bulkRows.{$i}.code", 'Already used by another course in this program for the same school year, semester, year level, and type.');
            }
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            $this->fail('Could not save — fix the highlighted duplicate or missing fields.');

            return;
        }

        $now = now();
        DB::transaction(function () use ($hasCourseCode, $hasYearLevel, $hasCourseType, $hasSchoolYear, $now) {
            foreach ($this->bulkRows as $row) {
                $payload = [
                    'semester_id' => (int) $row['semester_id'],
                    'course_name' => trim((string) $row['name']),
                    'updated_at' => $now,
                ];
                if ($hasYearLevel) {
                    $payload['year_level'] = $row['year_level'];
                }
                if ($hasCourseType) {
                    $payload['course_type'] = $row['course_type'];
                }
                if ($hasCourseCode) {
                    $payload['course_code'] = trim((string) $row['code']);
                }
                if ($hasSchoolYear) {
                    $payload['school_year_id'] = (int) $row['school_year_id'];
                }
                DB::table('dcs_program_courses')->where('id', (int) $row['id'])->update($payload);
            }
        });

        $count = count($this->bulkRows);
        $this->bumpCourseList();
        $this->done($count === 1 ? 'Course updated.' : "{$count} courses updated.");
    }

    /**
     * Programs course name taken.
     *
     * @param int $programId
     * @param int $semesterId
     * @param string $courseName
     * @param ?string $yearLevel
     * @param bool $hasYearLevel
     * @param ?string $courseType
     * @param bool $hasCourseType
     * @param ?int $exceptId
     *
     * @return bool
     */
    private function assertCourseTypeCaps(?string $courseType, ?string $yearLevel, int $semesterId): bool
    {
        if ($courseType === null || $courseType === '') {
            return true;
        }
        if ($yearLevel !== null && $yearLevel !== '' && ! \App\Helpers\CourseTypeRules::isYearLevelAllowed($courseType, $yearLevel)) {
            $this->fail('Year level is not allowed for '.$courseType.'.');

            return false;
        }
        if ($semesterId > 0 && $yearLevel !== null && $yearLevel !== '') {
            $semName = (string) (DB::table('dcs_semesters')->where('id', $semesterId)->value('semester_name') ?? '');
            if ($semName !== '' && ! \App\Helpers\CourseTypeRules::isYearSemesterAllowed($courseType, $yearLevel, $semName)) {
                $this->fail($courseType.' cannot use '.$yearLevel.' / '.$semName.'.');

                return false;
            }
        }

        return true;
    }

    private function programCourseNameTaken(
        ?int $programId,
        int $semesterId,
        string $courseName,
        ?string $yearLevel,
        bool $hasYearLevel,
        ?string $courseType = null,
        bool $hasCourseType = false,
        ?int $exceptId = null,
        bool $sharedCatalog = false,
        ?int $schoolYearId = null,
        bool $hasSchoolYear = false
    ): bool {
        $q = DB::table('dcs_program_courses')
            ->where('semester_id', $semesterId)
            ->where('course_name', $courseName)
            ->when($hasYearLevel, function ($query) use ($yearLevel) {
                return $yearLevel === null || $yearLevel === ''
                    ? $query->where(fn ($inner) => $inner->whereNull('year_level')->orWhere('year_level', ''))
                    : $query->where('year_level', $yearLevel);
            })
            ->when($hasCourseType, function ($query) use ($courseType) {
                return $courseType === null || $courseType === ''
                    ? $query->where(fn ($inner) => $inner->whereNull('course_type')->orWhere('course_type', ''))
                    : $query->where('course_type', $courseType);
            })
            ->when($hasSchoolYear, function ($query) use ($schoolYearId) {
                return $schoolYearId === null || $schoolYearId < 1
                    ? $query->whereNull('school_year_id')
                    : $query->where('school_year_id', $schoolYearId);
            })
            ->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId));
        // sharedCatalog path retained for compatibility; callers pass false.
        if (! $sharedCatalog) {
            $q->when(
                $programId === null || $programId < 1,
                fn ($query) => $query->whereNull('program_id'),
                fn ($query) => $query->where('program_id', $programId)
            );
        }
        \App\Helpers\SettingsRecycleHelper::applyNotDeleted($q, 'dcs_program_courses');

        return $q->exists();
    }

    /**
     * Programs course code taken.
     *
     * @param int $programId
     * @param int $semesterId
     * @param string $courseCode
     * @param ?string $yearLevel
     * @param bool $hasYearLevel
     * @param ?string $courseType
     * @param bool $hasCourseType
     * @param ?int $exceptId
     *
     * @return bool
     */
    private function programCourseCodeTaken(
        ?int $programId,
        int $semesterId,
        string $courseCode,
        ?string $yearLevel,
        bool $hasYearLevel,
        ?string $courseType = null,
        bool $hasCourseType = false,
        ?int $exceptId = null,
        bool $sharedCatalog = false,
        ?int $schoolYearId = null,
        bool $hasSchoolYear = false
    ): bool {
        $q = DB::table('dcs_program_courses')
            ->where('semester_id', $semesterId)
            ->where('course_code', $courseCode)
            ->when($hasYearLevel, function ($query) use ($yearLevel) {
                return $yearLevel === null || $yearLevel === ''
                    ? $query->where(fn ($inner) => $inner->whereNull('year_level')->orWhere('year_level', ''))
                    : $query->where('year_level', $yearLevel);
            })
            ->when($hasCourseType, function ($query) use ($courseType) {
                return $courseType === null || $courseType === ''
                    ? $query->where(fn ($inner) => $inner->whereNull('course_type')->orWhere('course_type', ''))
                    : $query->where('course_type', $courseType);
            })
            ->when($hasSchoolYear, function ($query) use ($schoolYearId) {
                return $schoolYearId === null || $schoolYearId < 1
                    ? $query->whereNull('school_year_id')
                    : $query->where('school_year_id', $schoolYearId);
            })
            ->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId));
        // sharedCatalog path retained for compatibility; callers pass false.
        if (! $sharedCatalog) {
            $q->when(
                $programId === null || $programId < 1,
                fn ($query) => $query->whereNull('program_id'),
                fn ($query) => $query->where('program_id', $programId)
            );
        }
        \App\Helpers\SettingsRecycleHelper::applyNotDeleted($q, 'dcs_program_courses');

        return $q->exists();
    }

    /**
     * Fold legacy / program-scoped / recycle-bin GE/PE/NSTP copies into the row being edited.
     * Soft-deleted rows are included and hard-removed — they still occupy unique indexes.
     */
    private function absorbSharedCourseDuplicates(
        int $keeperId,
        string $courseType,
        int $semesterId,
        ?string $yearLevel,
        bool $hasYearLevel,
        ?string $courseCode,
        string $courseName
    ): void {
        if ($keeperId < 1 || $courseType === '') {
            return;
        }

        // Include soft-deleted rows: deleted_at does not free PostgreSQL unique indexes.
        $q = DB::table('dcs_program_courses')
            ->where('course_type', $courseType)
            ->where('semester_id', $semesterId)
            ->where('id', '!=', $keeperId);

        if ($hasYearLevel) {
            if ($yearLevel === null || $yearLevel === '') {
                $q->where(fn ($inner) => $inner->whereNull('year_level')->orWhere('year_level', ''));
            } else {
                $q->where('year_level', $yearLevel);
            }
        }

        $code = trim((string) $courseCode);
        $name = trim($courseName);
        if ($code === '' && $name === '') {
            return;
        }
        $q->where(function ($inner) use ($code, $name) {
            if ($code !== '') {
                $inner->where('course_code', $code);
            }
            if ($name !== '') {
                if ($code !== '') {
                    $inner->orWhere('course_name', $name);
                } else {
                    $inner->where('course_name', $name);
                }
            }
        });

        $dupIds = $q->pluck('id')->map(fn ($id) => (int) $id)->filter(fn ($id) => $id > 0)->values()->all();
        if ($dupIds === []) {
            return;
        }

        if (Schema::hasTable('dcs_syllabi')) {
            DB::table('dcs_syllabi')->whereIn('course_id', $dupIds)->update(['course_id' => $keeperId]);
        }
        if (Schema::hasTable('dcs_office_intake_drf') && Schema::hasColumn('dcs_office_intake_drf', 'course_id')) {
            DB::table('dcs_office_intake_drf')->whereIn('course_id', $dupIds)->update(['course_id' => $keeperId]);
        }
        if (Schema::hasTable('dcs_program_course_faculties')) {
            DB::table('dcs_program_course_faculties')->whereIn('program_course_id', $dupIds)->delete();
        }

        // Hard delete so unique (type+sem+year+code/name) is freed for the keeper update.
        DB::table('dcs_program_courses')->whereIn('id', $dupIds)->delete();
    }

    /**
     * Deletes or deactivates the specified doc type.
     *
     * @param int $id
     *
     * @return void
     */
    private function destroyDocType(int $id): void
    {
        $activeChildren = DB::table('dcs_doc_types')->where('parent_id', $id);
        \App\Helpers\SettingsRecycleHelper::applyNotDeleted($activeChildren, 'dcs_doc_types');
        if ($activeChildren->exists()) {
            $this->fail('This document type still has sub-types under it. Remove or reassign those first.');
            return;
        }
        if ($this->docTypeIsInUse($id)) {
            $this->fail('This type is already used by one or more registered documents and cannot be deleted.');
            return;
        }
        if (\App\Helpers\SettingsRecycleHelper::supports('dcs_doc_types')) {
            \App\Helpers\SettingsRecycleHelper::softDelete('docType', $id);
            $this->done('Document type moved to the Admin Console Recycle Bin.');
            return;
        }
        DB::table('dcs_doc_types')->where('id', $id)->delete();
        $this->done('Deleted successfully.');
    }

    /**
     * Deletes or deactivates the specified originator.
     *
     * @param int $id
     *
     * @return void
     */
    private function destroyOriginator(int $id): void
    {
        if (! Schema::hasTable('dcs_originators')) {
            $this->fail('Originators table is not available. Run pending migrations.');
            return;
        }
        if (\App\Helpers\SettingsRecycleHelper::supports('dcs_originators')) {
            \App\Helpers\SettingsRecycleHelper::softDelete('originator', $id);
            $this->done('Originator moved to the Admin Console Recycle Bin.');
            return;
        }
        DB::table('dcs_originators')->where('id', $id)->delete();
        $this->done('Originator deleted.');
    }

    /**
     * Deletes or deactivates the specified faculty.
     *
     * @param int $id
     *
     * @return void
     */
    private function destroyFaculty(int $id): void
    {
        if (DB::table('dcs_syllabi_drf')->where('faculty_id', $id)->exists()) {
            $this->fail('This faculty is referenced by syllabi DRF records and cannot be deleted.');
            return;
        }
        if (\App\Helpers\SettingsRecycleHelper::supports('dcs_faculties')) {
            \App\Helpers\SettingsRecycleHelper::softDelete('faculty', $id);
            $this->done('Faculty moved to the Admin Console Recycle Bin.');
            return;
        }
        DB::table('dcs_faculties')->where('id', $id)->delete();
        $this->done('Faculty deleted.');
    }

    /**
     * Deletes or deactivates the specified college.
     *
     * @param int $id
     *
     * @return void
     */
    private function destroyCollege(int $id): void
    {
        $programs = DB::table('dcs_programs')->where('college_id', $id);
        \App\Helpers\SettingsRecycleHelper::applyNotDeleted($programs, 'dcs_programs');
        if ($programs->exists()) {
            $this->fail('This college has programs. Remove or reassign them first.');
            return;
        }
        if (\App\Helpers\SettingsRecycleHelper::supports('dcs_colleges')) {
            \App\Helpers\SettingsRecycleHelper::softDelete('college', $id);
            if ($this->collegeDetailId === $id) {
                $this->collegeDetailId = null;
            }
            $this->done('College moved to the Admin Console Recycle Bin.');
            return;
        }
        DB::table('dcs_colleges')->where('id', $id)->delete();
        if ($this->collegeDetailId === $id) {
            $this->collegeDetailId = null;
        }
        $this->done('College deleted.');
    }

    /**
     * Deletes or deactivates the specified program.
     *
     * @param int $id
     *
     * @return void
     */
    private function destroyProgram(int $id): void
    {
        $courses = DB::table('dcs_program_courses')->where('program_id', $id);
        \App\Helpers\SettingsRecycleHelper::applyNotDeleted($courses, 'dcs_program_courses');
        $inUse = $courses->exists()
            || DB::table('dcs_syllabi')->where('program_id', $id)->exists();
        if ($inUse) {
            $this->fail('This program is referenced by courses or syllabi and cannot be deleted.');
            return;
        }
        if (\App\Helpers\SettingsRecycleHelper::supports('dcs_programs')) {
            \App\Helpers\SettingsRecycleHelper::softDelete('program', $id);
            $this->done('Program moved to the Admin Console Recycle Bin.');
            return;
        }
        DB::table('dcs_programs')->where('id', $id)->delete();
        $this->done('Program deleted.');
    }

    /**
     * Deletes or deactivates the specified semester.
     *
     * @param int $id
     *
     * @return void
     */
    private function destroySemester(int $id): void
    {
        $courses = DB::table('dcs_program_courses')->where('semester_id', $id);
        \App\Helpers\SettingsRecycleHelper::applyNotDeleted($courses, 'dcs_program_courses');
        $inUse = $courses->exists()
            || DB::table('dcs_syllabi')->where('semester_id', $id)->exists();
        if ($inUse) {
            $this->fail('This semester is referenced by courses or syllabi and cannot be deleted.');
            return;
        }
        if (\App\Helpers\SettingsRecycleHelper::supports('dcs_semesters')) {
            \App\Helpers\SettingsRecycleHelper::softDelete('semester', $id);
            $this->done('Semester moved to the Admin Console Recycle Bin.');
            return;
        }
        DB::table('dcs_semesters')->where('id', $id)->delete();
        $this->done('Semester deleted.');
    }

    /**
     * Deletes or deactivates the specified school year.
     *
     * @param int $id
     *
     * @return void
     */
    private function destroySchoolYear(int $id): void
    {
        if (DB::table('dcs_syllabi')->where('school_year_id', $id)->exists()) {
            $this->fail('This school year is referenced by syllabi and cannot be deleted.');
            return;
        }
        if (Schema::hasColumn('dcs_school_years', 'is_active')) {
            DB::table('dcs_school_years')->where('id', $id)->update(['is_active' => false]);
        }
        if (\App\Helpers\SettingsRecycleHelper::supports('dcs_school_years')) {
            \App\Helpers\SettingsRecycleHelper::softDelete('schoolYear', $id);
            $this->done('School year moved to the Admin Console Recycle Bin.');
            return;
        }
        DB::table('dcs_school_years')->where('id', $id)->delete();
        $this->done('School year deleted.');
    }

    /**
     * Deletes or deactivates the specified program course.
     *
     * @param int $id
     *
     * @return void
     */
    private function destroyProgramCourse(int $id): void
    {
        if (DB::table('dcs_syllabi')->where('course_id', $id)->exists()) {
            $this->fail('This course is referenced by syllabi and cannot be deleted.');
            return;
        }
        if (\App\Helpers\SettingsRecycleHelper::supports('dcs_program_courses')) {
            \App\Helpers\SettingsRecycleHelper::softDelete('programCourse', $id);
            $this->bumpCourseList();
            $this->done('Course moved to the Admin Console Recycle Bin.');
            return;
        }
        DB::table('dcs_program_courses')->where('id', $id)->delete();
        $this->bumpCourseList();
        $this->done('Course deleted.');
    }

    public function clearCourseSelection(): void
    {
        $this->selectedCourseIds = [];
    }

    public function toggleSelectAllCourses(): void
    {
        $ids = $this->filteredProgramCourseIds();
        if ($ids === []) {
            $this->selectedCourseIds = [];

            return;
        }
        $selected = array_values(array_unique(array_map('intval', $this->selectedCourseIds)));
        $missing = array_diff($ids, $selected);
        $this->selectedCourseIds = $missing === []
            ? []
            : array_values(array_unique(array_merge($selected, $ids)));
    }

    public function openSelectedCoursesBulk(): void
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $this->selectedCourseIds),
            fn ($id) => $id > 0
        )));
        if ($ids === []) {
            $this->fail('Select at least one course to edit.');

            return;
        }

        $rowsQ = DB::table('dcs_program_courses')->whereIn('id', $ids);
        \App\Helpers\SettingsRecycleHelper::applyNotDeleted($rowsQ, 'dcs_program_courses');
        $rows = $rowsQ->get(['id', 'program_id']);
        if ($rows->count() !== count($ids)) {
            $this->fail('One or more selected courses are no longer available. Refresh and try again.');
            $this->clearCourseSelection();

            return;
        }

        $programIds = $rows->pluck('program_id')->map(fn ($id) => $id !== null ? (int) $id : null)->unique()->values();
        if ($programIds->count() !== 1 || $programIds->first() === null || (int) $programIds->first() < 1) {
            $this->fail('Select courses from one program only.');

            return;
        }

        $programId = (int) $programIds->first();
        $this->resetFormFor('programCoursesBulk', null);
        $this->bulkProgramId = (string) $programId;
        $this->bulkFillSemester = '';
        $this->bulkFillYear = '';
        $this->bulkFillType = '';
        $this->ensureBulkCollegeFromProgram();
        $this->loadBulkProgramCoursesByIds($ids);
    }

    public function confirmDestroySelectedProgramCourses(): void
    {
        if (! \App\Helpers\RegisterQueryHelper::isDcsSoftDeleteEnabled()) {
            $this->fail('Delete is disabled. Ask an administrator to enable it in Admin Console → System Settings → Document Control (DCS).');

            return;
        }

        $ids = array_values(array_unique(array_filter(
            array_map('intval', $this->selectedCourseIds),
            fn ($id) => $id > 0
        )));
        if ($ids === []) {
            $this->fail('Select at least one course to delete.');

            return;
        }

        $count = count($ids);
        $this->resetFormFor('delete', null);
        $this->deleteTitle = $count === 1 ? 'Delete selected course' : "Delete {$count} selected courses";
        $this->deleteMessage = $count === 1
            ? 'This course will be moved to the Admin Console Recycle Bin. Courses referenced by syllabi will be skipped.'
            : 'Selected courses will be moved to the Admin Console Recycle Bin. Courses referenced by syllabi will be skipped.';
        $this->modalKind = 'programCoursesSelected:delete';
    }

    private function destroySelectedProgramCourses(): void
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $this->selectedCourseIds),
            fn ($id) => $id > 0
        )));
        if ($ids === []) {
            $this->closeModal();
            $this->fail('No courses selected.');

            return;
        }

        $deleted = 0;
        $skipped = 0;
        $supportsSoft = \App\Helpers\SettingsRecycleHelper::supports('dcs_program_courses');
        foreach ($ids as $id) {
            if (DB::table('dcs_syllabi')->where('course_id', $id)->exists()) {
                $skipped++;
                continue;
            }
            if ($supportsSoft) {
                \App\Helpers\SettingsRecycleHelper::softDelete('programCourse', $id);
            } else {
                DB::table('dcs_program_courses')->where('id', $id)->delete();
            }
            $deleted++;
        }

        $this->clearCourseSelection();
        $this->bumpCourseList();

        if ($deleted === 0) {
            $this->closeModal();
            $this->fail($skipped > 0
                ? 'None of the selected courses could be deleted — they are referenced by syllabi.'
                : 'No courses were deleted.');

            return;
        }

        $msg = $supportsSoft
            ? ($deleted === 1 ? 'Course moved to the Admin Console Recycle Bin.' : "{$deleted} courses moved to the Admin Console Recycle Bin.")
            : ($deleted === 1 ? 'Course deleted.' : "{$deleted} courses deleted.");
        if ($skipped > 0) {
            $msg .= " {$skipped} skipped (referenced by syllabi).";
        }
        $this->done($msg);
    }

    /**
     * @return list<int>
     */
    private function filteredProgramCourseIds(): array
    {
        $query = DB::table('dcs_program_courses as pc')
            ->leftJoin('dcs_programs as p', 'p.id', '=', 'pc.program_id')
            ->leftJoin('dcs_colleges as c', 'c.id', '=', 'p.college_id');
        if ($this->courseListCollegeId !== '') {
            $query->where('c.id', (int) $this->courseListCollegeId);
        }
        if ($this->courseListProgramId !== '') {
            $query->where('pc.program_id', (int) $this->courseListProgramId);
        }
        \App\Helpers\SettingsRecycleHelper::applyNotDeleted($query, 'dcs_program_courses', 'pc');

        return $query->pluck('pc.id')->map(fn ($id) => (int) $id)->filter(fn ($id) => $id > 0)->values()->all();
    }

    /**
     * @param  list<int>  $ids
     */
    private function loadBulkProgramCoursesByIds(array $ids): void
    {
        $this->resetValidation();
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($id) => $id > 0)));
        if ($ids === []) {
            $this->bulkRows = [];

            return;
        }

        $hasCourseCode = Schema::hasColumn('dcs_program_courses', 'course_code');
        $hasYearLevel = Schema::hasColumn('dcs_program_courses', 'year_level');
        $hasCourseType = Schema::hasColumn('dcs_program_courses', 'course_type');
        $hasSchoolYear = Schema::hasColumn('dcs_program_courses', 'school_year_id');
        $cols = ['pc.id', 'pc.semester_id', 'pc.course_name', 's.semester_name'];
        if ($hasYearLevel) {
            $cols[] = 'pc.year_level';
        }
        if ($hasCourseType) {
            $cols[] = 'pc.course_type';
        }
        if ($hasCourseCode) {
            $cols[] = 'pc.course_code';
        }
        if ($hasSchoolYear) {
            $cols[] = 'pc.school_year_id';
        }

        $query = DB::table('dcs_program_courses as pc')
            ->leftJoin('dcs_semesters as s', 's.id', '=', 'pc.semester_id')
            ->whereIn('pc.id', $ids)
            ->orderBy('pc.semester_id');
        if ($hasYearLevel) {
            $query->orderByRaw("CASE pc.year_level
                WHEN '1st Year' THEN 1
                WHEN '2nd Year' THEN 2
                WHEN '3rd Year' THEN 3
                WHEN '4th Year' THEN 4
                WHEN '5th Year' THEN 5
                ELSE 99 END");
        }
        if ($hasCourseCode) {
            $query->orderByRaw("CASE WHEN pc.course_code IS NULL OR pc.course_code = '' THEN 1 ELSE 0 END")
                ->orderBy('pc.course_code');
        }
        $query->orderBy('pc.course_name');
        \App\Helpers\SettingsRecycleHelper::applyNotDeleted($query, 'dcs_program_courses', 'pc');

        $this->bulkRows = $query->get($cols)->map(function ($row) use ($hasSchoolYear) {
            $mapped = [
                'id' => (int) $row->id,
                'semester_id' => (string) $row->semester_id,
                'semester_name' => (string) ($row->semester_name ?? '—'),
                'year_level' => (string) ($row->year_level ?? ''),
                'course_type' => (string) ($row->course_type ?? ''),
                'code' => (string) ($row->course_code ?? ''),
                'name' => (string) ($row->course_name ?? ''),
            ];
            if ($hasSchoolYear) {
                $mapped['school_year_id'] = $row->school_year_id !== null ? (string) $row->school_year_id : '';
            }

            return $mapped;
        })->values()->all();
    }

    /**
     * Resets form for and clears active selections.
     *
     * @param string $kind
     * @param ?int $id
     *
     * @return void
     */
    private function resetFormFor(string $kind, ?int $id): void
    {
        $this->resetValidation();
        $this->editingId = $id;
        $this->parentId = null;
        $this->modalKind = $kind;
        $this->dispatch('settings-open-modal');
    }

    /**
     * Bumps course list.
     * @return void
     */
    private function bumpCourseList(): void
    {
        $this->courseListEpoch++;
        $this->selectedCourseIds = [];
    }

    /**
     * Dones action.
     *
     * @param string $message
     *
     * @return void
     */
    private function done(string $message): void
    {
        \App\Helpers\RegisterPersistHelper::logAdminChange('DCS settings: ' . $message);
        $this->closeModal();
        $this->flashToast($message, 'success');
    }

    /**
     * Fails action.
     *
     * @param string $message
     *
     * @return void
     */
    private function fail(string $message): void
    {
        $this->flashToast($message, 'error');
    }

    /**
     * Flashs toast.
     *
     * @param string $message
     * @param string $type
     *
     * @return void
     */
    private function flashToast(string $message, string $type = 'success'): void
    {
        $this->dispatch('dcs-toast', message: $message, type: $type);
    }

    /**
     * Catalogs action.
     * @return array
     */
    private function catalog(): array
    {
        // Opening/editing a modal should not reload faculties/originators/signatories.
        // Courses always load so the Course Names table stays populated without wire:ignore
        // (needed for Livewire checkbox selection).
        $modalLite = $this->modalKind !== '';
        $needsFaculties = ! $modalLite || in_array($this->modalKind, ['faculty', 'importFaculties'], true);
        $needsOriginators = ! $modalLite || in_array($this->modalKind, ['originator', 'importOriginators'], true);
        $needsSignatories = ! $modalLite;
        $needsCourses = true;

        $docTypeParentsQ = DB::table('dcs_doc_types')->whereNull('parent_id')->orderBy('doc_type_name');
        \App\Helpers\SettingsRecycleHelper::applyNotDeleted($docTypeParentsQ, 'dcs_doc_types');
        $docTypeParentCols = ['id', 'doc_type_name', 'parent_id'];
        $docTypeParents = $docTypeParentsQ->get($docTypeParentCols);

        $docTypeSubsQ = DB::table('dcs_doc_types')->whereNotNull('parent_id')->orderBy('doc_type_name');
        \App\Helpers\SettingsRecycleHelper::applyNotDeleted($docTypeSubsQ, 'dcs_doc_types');
        $docTypeSubs = $docTypeSubsQ->get($docTypeParentCols)
            ->groupBy(fn ($row) => (string) $row->parent_id);

        $officeTable = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $collegesQ = DB::table('dcs_colleges as c')
            ->leftJoin($officeTable . ' as o', 'o.id', '=', 'c.office_id')
            ->orderBy('c.college_code');
        \App\Helpers\SettingsRecycleHelper::applyNotDeleted($collegesQ, 'dcs_colleges', 'c');
        $colleges = $collegesQ->get(['c.id', 'c.college_code', 'c.college_name', 'c.office_id', 'o.office_name as office_name', 'o.office_code as office_code']);
        $linkedOfficeIds = $colleges->pluck('office_id')->filter()->map(fn ($id) => (int) $id);
        if ($this->modalKind === 'college' && $this->editingId) {
            $currentOfficeId = (int) (optional($colleges->firstWhere('id', $this->editingId))->office_id ?? 0);
            $linkedOfficeIds = $linkedOfficeIds->reject(fn ($id) => $id === $currentOfficeId);
        }
        $collegeOfficesQuery = DB::table($officeTable)->where('is_active', true);
        \App\Helpers\RegisterQueryHelper::applySelectableOfficesFilter($collegeOfficesQuery);
        if ($linkedOfficeIds->isNotEmpty()) {
            $collegeOfficesQuery->whereNotIn('id', $linkedOfficeIds->values()->all());
        }
        $collegeOffices = $collegeOfficesQuery
            ->orderBy('office_name')
            ->get(['id', 'office_name', 'office_code']);
        if ($this->modalKind === 'college' && $this->officeId !== '') {
            $selectedOffice = DB::table($officeTable)->where('id', (int) $this->officeId)->first(['id', 'office_name', 'office_code']);
            if ($selectedOffice && ! $collegeOffices->contains('id', $selectedOffice->id)) {
                $collegeOffices = $collegeOffices->prepend($selectedOffice);
            }
        }
        $programCountsQ = DB::table('dcs_programs')->select('college_id', DB::raw('count(*) as programs_count'));
        \App\Helpers\SettingsRecycleHelper::applyNotDeleted($programCountsQ, 'dcs_programs');
        $programCounts = $programCountsQ->groupBy('college_id')->pluck('programs_count', 'college_id');

        $programsQ = DB::table('dcs_programs as p')
            ->leftJoin('dcs_colleges as c', 'c.id', '=', 'p.college_id')
            ->orderBy('c.college_name')->orderBy('p.program_name');
        \App\Helpers\SettingsRecycleHelper::applyNotDeleted($programsQ, 'dcs_programs', 'p');
        \App\Helpers\SettingsRecycleHelper::applyNotDeleted($programsQ, 'dcs_colleges', 'c');
        $programs = $programsQ->get(['p.id', 'p.college_id', 'p.program_name', 'p.program_code', 'c.college_name']);

        $programCourses = collect();
        if ($needsCourses) {
            $hasCourseCode = Schema::hasColumn('dcs_program_courses', 'course_code');
            $hasYearLevel = Schema::hasColumn('dcs_program_courses', 'year_level');
            $hasCourseType = Schema::hasColumn('dcs_program_courses', 'course_type');
            $hasSchoolYear = Schema::hasColumn('dcs_program_courses', 'school_year_id');
            $courseCols = ['pc.id', 'pc.program_id', 'pc.semester_id', 'pc.course_name', 'p.program_name', 'c.id as college_id', 'c.college_name', 'c.college_code', 's.semester_name'];
            if ($hasYearLevel) {
                $courseCols[] = 'pc.year_level';
            }
            if ($hasCourseType) {
                $courseCols[] = 'pc.course_type';
            }
            if ($hasCourseCode) {
                $courseCols[] = 'pc.course_code';
            }
            if ($hasSchoolYear) {
                $courseCols[] = 'pc.school_year_id';
                $courseCols[] = 'sy.school_year';
            }

            $programCoursesQ = DB::table('dcs_program_courses as pc')
                ->leftJoin('dcs_programs as p', 'p.id', '=', 'pc.program_id')
                ->leftJoin('dcs_colleges as c', 'c.id', '=', 'p.college_id')
                ->leftJoin('dcs_semesters as s', 's.id', '=', 'pc.semester_id');
            if ($hasSchoolYear) {
                $programCoursesQ->leftJoin('dcs_school_years as sy', 'sy.id', '=', 'pc.school_year_id');
            }
            if ($this->courseListCollegeId !== '') {
                $programCoursesQ->where('c.id', (int) $this->courseListCollegeId);
            }
            if ($this->courseListProgramId !== '') {
                $programCoursesQ->where('pc.program_id', (int) $this->courseListProgramId);
            }
            if ($hasYearLevel) {
                $programCoursesQ->orderByRaw("CASE pc.year_level
                    WHEN '1st Year' THEN 1
                    WHEN '2nd Year' THEN 2
                    WHEN '3rd Year' THEN 3
                    WHEN '4th Year' THEN 4
                    WHEN '5th Year' THEN 5
                    ELSE 99 END");
            }
            $programCoursesQ->orderBy('c.college_name')->orderBy('p.program_name')->orderBy('pc.semester_id');
            if ($hasCourseType) {
                $programCoursesQ->orderBy('pc.course_type');
            }
            if ($hasCourseCode) {
                $programCoursesQ->orderByRaw("CASE WHEN pc.course_code IS NULL OR pc.course_code = '' THEN 1 ELSE 0 END")
                    ->orderBy('pc.course_code');
            }
            $programCoursesQ->orderBy('pc.course_name');
            \App\Helpers\SettingsRecycleHelper::applyNotDeleted($programCoursesQ, 'dcs_program_courses', 'pc');
            $programCourses = $programCoursesQ->get($courseCols);
        }

        $originators = collect();
        if ($needsOriginators && Schema::hasTable('dcs_originators')) {
            $originatorsQ = DB::table('dcs_originators')->orderBy('originator_name');
            \App\Helpers\SettingsRecycleHelper::applyNotDeleted($originatorsQ, 'dcs_originators');
            $originators = $originatorsQ->get(['id', 'originator_name']);
        }

        $faculties = collect();
        if ($needsFaculties) {
            $facultiesQ = DB::table('dcs_faculties as f')
                ->orderBy('f.faculty_name');
            \App\Helpers\SettingsRecycleHelper::applyNotDeleted($facultiesQ, 'dcs_faculties', 'f');
            $facultyRows = $facultiesQ->get(['f.id', 'f.faculty_name']);
            $collegeIdsByFaculty = \App\Helpers\FacultyCollegeHelper::collegeIdsByFaculty(
                $facultyRows->pluck('id')->map(fn ($id) => (int) $id)->all()
            );
            $collegeNameById = $colleges->keyBy('id');
            $faculties = $facultyRows->map(function ($fac) use ($collegeIdsByFaculty, $collegeNameById) {
                $ids = $collegeIdsByFaculty[(int) $fac->id] ?? [];
                $names = [];
                foreach ($ids as $cid) {
                    $college = $collegeNameById->get($cid);
                    if ($college) {
                        $names[] = $college->college_name;
                    }
                }

                return (object) [
                    'id' => $fac->id,
                    'faculty_name' => $fac->faculty_name,
                    'college_ids' => $ids,
                    'college_names' => $names,
                    'college_label' => $names !== [] ? implode(', ', $names) : '—',
                ];
            });
        }

        $semestersQ = DB::table('dcs_semesters')->orderBy('id');
        \App\Helpers\SettingsRecycleHelper::applyNotDeleted($semestersQ, 'dcs_semesters');

        $schoolYearsQ = DB::table('dcs_school_years')->orderBy('school_year');
        \App\Helpers\SettingsRecycleHelper::applyNotDeleted($schoolYearsQ, 'dcs_school_years');

        $sigHistoryTabs = [];
        $signatoryAssignmentGroups = [];
        $sigHasActiveAssignment = false;
        $sigInheritanceHint = '';
        if ($needsSignatories) {
            $sigHistoryTabs = OfficeIntakeHelper::signatoryHistoryDocTypeTabs($this->sigFormKind, $docTypeParents);
            $signatoryAssignmentGroups = OfficeIntakeHelper::groupedSignatoryAssignments(
                $this->sigFormKind,
                $this->sigHistoryTab !== '' ? $this->sigHistoryTab : 'all'
            );
            $sigHasActiveAssignment = OfficeIntakeHelper::hasActiveSignatoryAssignment(
                $this->sigFormKind,
                $this->sigDocTypeKind,
                $this->resolvedSigDocTypeId(),
                $this->resolvedSigCollegeId()
            );
            $sigInheritanceHint = OfficeIntakeHelper::signatoryInheritanceHint(
                $this->sigFormKind,
                $this->sigDocTypeKind,
                $this->resolvedSigDocTypeId(),
                $this->resolvedSigCollegeId()
            );
        }

        return [
            'canSoftDelete' => \App\Helpers\RegisterQueryHelper::isDcsSoftDeleteEnabled(),
            'docTypeParents' => $docTypeParents,
            'docTypeSubs' => $docTypeSubs,
            'originators' => $originators,
            'faculties' => $faculties,
            'colleges' => $colleges,
            'collegeOffices' => $collegeOffices,
            'programCounts' => $programCounts,
            'programs' => $programs,
            'programsByCollege' => $programs->groupBy(fn ($row) => (string) $row->college_id),
            'semesters' => $semestersQ->get(['id', 'semester_name']),
            'schoolYears' => $schoolYearsQ->get(
                Schema::hasColumn('dcs_school_years', 'is_active')
                    ? ['id', 'school_year', 'is_active']
                    : ['id', 'school_year']
            ),
            'programCourses' => $programCourses,
            'signatoryTemplatesReady' => Schema::hasTable('dcs_signatory_templates'),
            'sigHistoryTabs' => $sigHistoryTabs,
            'signatoryAssignmentGroups' => $signatoryAssignmentGroups,
            // Catalog parents only (Forms/Logbooks stay out of this dropdown until Admin enables them).
            'sigParentOptions' => $docTypeParents->filter(function ($type) {
                $name = (string) ($type->doc_type_name ?? '');
                if (! OfficeIntakeHelper::isDefaultExcludedDrfParent($name)) {
                    return true;
                }

                return OfficeIntakeHelper::isOfficeDrfParentEnabled($name);
            })->values(),
            'sigSubOptions' => ($this->sigParentTypeId !== '' && isset($docTypeSubs[(string) $this->sigParentTypeId]))
                ? $docTypeSubs[(string) $this->sigParentTypeId]
                : collect(),
            'sigNeedsCollege' => $this->sigScopeNeedsCollege(),
            'sigCollegeOptions' => $colleges,
            'sigHasActiveAssignment' => $sigHasActiveAssignment,
            'sigInheritanceHint' => $sigInheritanceHint,
        ];
    }

    /**
     * Offices is active.
     *
     * @param object $office
     *
     * @return bool
     */
    private function officeIsActive(object $office): bool
    {
        $value = $office->is_active ?? false;
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 't', 'true', 'yes', 'on'], true);
    }

    /**
     * Colleges code from office.
     *
     * @param object $office
     * @param string $name
     * @param ?int $exceptId
     *
     * @return string
     */
    private function collegeCodeFromOffice(object $office, string $name, ?int $exceptId = null): string
    {
        $code = strtoupper(trim((string) ($office->office_code ?? '')));
        if ($code === '') {
            return $this->uniqueCollegeCode($name, $exceptId);
        }
        $takenQ = DB::table('dcs_colleges')
            ->where('college_code', $code)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId));
        \App\Helpers\SettingsRecycleHelper::applyNotDeleted($takenQ, 'dcs_colleges');
        $taken = $takenQ->exists();

        return $taken ? $this->uniqueCollegeCode($name, $exceptId) : $code;
    }

    /**
     * Uniques college code.
     *
     * @param string $name
     * @param ?int $exceptId
     *
     * @return string
     */
    private function uniqueCollegeCode(string $name, ?int $exceptId = null): string
    {
        $code = collect(explode(' ', $name))->filter(fn ($w) => strlen($w) > 0)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 2)))->join('');
        if ($code === '') {
            $code = 'CL';
        }
        $base = $code;
        $counter = 1;
        while (true) {
            $q = DB::table('dcs_colleges')->where('college_code', $code)->when($exceptId, fn ($qq) => $qq->where('id', '!=', $exceptId));
            \App\Helpers\SettingsRecycleHelper::applyNotDeleted($q, 'dcs_colleges');
            if (! $q->exists()) {
                break;
            }
            $code = $base . $counter;
            $counter++;
        }

        return $code;
    }

    /**
     * Docs type is in use.
     *
     * @param int $id
     *
     * @return bool
     */
    private function docTypeIsInUse(int $id): bool
    {
        return DB::table('dcs_document_requests')->where(fn ($q) => $q->where('doc_type_id', $id)->orWhere('sub_type_id', $id))->exists();
    }
}; ?>

<div
    x-data="{
        tab: new URLSearchParams(window.location.search).get('tab') || sessionStorage.getItem('settingsActiveTab') || 'doctypes',
        syllabiSection: new URLSearchParams(window.location.search).get('section') || sessionStorage.getItem('settingsSyllabiSection') || 'colleges',
        isModalOpen: false,
        isModalLoading: false,
        init() {
            const legacySyllabi = {
                faculties: 'faculties',
                colleges: 'colleges',
                programs: 'colleges', // Programs now live under Colleges
                semesters: 'semesters',
                schoolyears: 'schoolyears',
                coursenames: 'coursenames',
            };
            const allowed = ['doctypes', 'signatories', 'originators', 'syllabi'];
            const allowedSections = Object.values(legacySyllabi);
            if (legacySyllabi[this.tab]) {
                this.syllabiSection = legacySyllabi[this.tab];
                this.tab = 'syllabi';
            }
            if (!allowed.includes(this.tab)) this.tab = 'doctypes';
            if (!allowedSections.includes(this.syllabiSection)) this.syllabiSection = 'colleges';
            sessionStorage.setItem('settingsActiveTab', this.tab);
            sessionStorage.setItem('settingsSyllabiSection', this.syllabiSection);
            if (this.tab === 'signatories') $wire.loadSignatoryTemplateFields();
        },
        setTab(name) {
            this.tab = name;
            sessionStorage.setItem('settingsActiveTab', name);
            if (name === 'signatories') $wire.loadSignatoryTemplateFields();
        },
        setSyllabiSection(name) {
            this.syllabiSection = name === 'programs' ? 'colleges' : name;
            sessionStorage.setItem('settingsSyllabiSection', this.syllabiSection);
            if (this.syllabiSection !== 'colleges') {
                $wire.closeCollegePrograms();
            }
        },
        openUi({ loading = false } = {}) {
            this.isModalOpen = true;
            this.isModalLoading = !!loading;
            document.body.style.overflow = 'hidden';
            const overlay = document.getElementById('settingsOverlay');
            if (overlay) {
                overlay.style.display = '';
                overlay.style.pointerEvents = '';
            }
        },
        closeUi() {
            this.isModalOpen = false;
            this.isModalLoading = false;
            document.body.style.overflow = '';
            const overlay = document.getElementById('settingsOverlay');
            if (overlay) {
                overlay.style.display = 'none';
                overlay.style.pointerEvents = 'none';
            }
        },
        dismiss() {
            this.closeUi();
            $wire.closeModal();
        },
        openCollegeModal(id = null) {
            // Instant shell only — hide it as soon as Livewire mounts the real overlay.
            this.openUi({ loading: true });
            id == null ? $wire.openCollege() : $wire.openCollege(id);
        }
    }"
    x-on:settings-open-modal.window="openUi({ loading: false })"
    x-on:settings-close-modal.window="closeUi()"
    x-on:settings-focus-course-row.window="$nextTick(() => document.querySelector('[data-course-first-input]')?.focus())"
    x-on:settings-focus-last-course-row.window="$nextTick(() => {
        const rows = document.querySelectorAll('.st-course-row:not(.st-course-row-head) .st-input');
        rows[rows.length - 2]?.focus();
    })"
    x-on:keydown.escape.window="isModalOpen && dismiss()"
>
<main class="settings-main">
    <div class="settings-header">
        <div class="settings-header-left">
            <div class="settings-breadcrumb">Document Control System / <span>Settings</span></div>
            <h1>Settings</h1>
        </div>
    </div>

    <div class="settings-tabs">
        @foreach ([
            'doctypes' => ['fa-tags', 'Document Types'],
            'signatories' => ['fa-signature', 'Signatories'],
            'originators' => ['fa-user-pen', 'Originators'],
            'syllabi' => ['fa-book-open', 'Syllabi'],
        ] as $key => $meta)
            <button type="button" class="tab-btn" :class="tab === '{{ $key }}' && 'active'" @click="setTab('{{ $key }}')">
                <i class="fa-solid {{ $meta[0] }}"></i> {{ $meta[1] }}
            </button>
        @endforeach
    </div>

    <section class="tab-panel" wire:key="settings-tab-doctypes" x-show="tab === 'doctypes'" x-cloak>
        <div class="panel-toolbar">
            <span class="panel-subtitle">Top-level types and their sub-types</span>
            <button type="button" class="btn-primary" wire:click="openDocType()"><i class="fa-solid fa-plus"></i> Add Document Type</button>
        </div>
        <div class="doctype-list">
            @forelse($docTypeParents as $type)
                <div class="doctype-group" wire:key="dt-{{ $type->id }}" data-id="{{ $type->id }}">
                    <div class="doctype-parent-row">
                        <div class="doctype-name"><i class="fa-solid fa-folder"></i><span>{{ $type->doc_type_name }}</span></div>
                        <div class="row-actions">
                            <button type="button" class="icon-btn" title="Add sub-type" wire:click="openSubType({{ $type->id }})"><i class="fa-solid fa-plus"></i></button>
                            <button type="button" class="icon-btn" title="Edit" wire:click="openDocType({{ $type->id }})"><i class="fa-solid fa-pen"></i></button>
                            @if(!empty($canSoftDelete))
                            <button type="button" class="icon-btn icon-btn-danger" title="Delete" wire:click="confirmDelete('docType', {{ $type->id }}, 'Delete Document Type', 'This document type will be moved to the Admin Console Recycle Bin.')"><i class="fa-solid fa-trash"></i></button>
                            @endif
                        </div>
                    </div>
                    @if(($docTypeSubs[(string) $type->id] ?? collect())->count())
                        <div class="doctype-subtypes">
                            @foreach($docTypeSubs[(string) $type->id] as $sub)
                                <div class="doctype-sub-row" wire:key="dts-{{ $sub->id }}" data-id="{{ $sub->id }}">
                                    <div class="doctype-name"><i class="fa-solid fa-turn-up fa-rotate-90"></i><span>{{ $sub->doc_type_name }}</span></div>
                                    <div class="row-actions">
                                        <button type="button" class="icon-btn" title="Edit" wire:click="openDocType({{ $sub->id }})"><i class="fa-solid fa-pen"></i></button>
                                        @if(!empty($canSoftDelete))
                                        <button type="button" class="icon-btn icon-btn-danger" title="Delete" wire:click="confirmDelete('docType', {{ $sub->id }}, 'Delete Document Type', 'This document type will be moved to the Admin Console Recycle Bin.')"><i class="fa-solid fa-trash"></i></button>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @empty
                <div class="empty-state"><i class="fa-solid fa-tags"></i><span>No document types yet. Add one to get started.</span></div>
            @endforelse
        </div>
    </section>

    <section class="tab-panel sig-panel" wire:key="settings-tab-signatories" x-show="tab === 'signatories'" x-cloak>
        <div class="panel-toolbar">
            <div>
                <h2 class="sig-panel-title">Signatory assignments</h2>
                <p class="panel-subtitle">Assign prepared / reviewed / approved names per Document Type (and sub-type). Syllabi and TOS/Rubrics are separated by college. Saving a new assignment for the same scope ends the previous one and applies the new names immediately.</p>
            </div>
        </div>
        @if(!($signatoryTemplatesReady ?? false))
            <div class="empty-state"><i class="fa-solid fa-database"></i><span>Run migrations to enable signatory assignments (<code>dcs_signatory_templates</code>).</span></div>
        @else
            @php
                $today = now()->toDateString();
            @endphp
            <div class="sig-layout">
                <div class="sig-editor">
                    <div class="sig-section">
                        <div class="sig-section-head">
                            <span class="sig-section-num">1</span>
                            <div>
                                <h3 class="sig-section-title">Scope</h3>
                                <p class="sig-section-hint"><strong>Document type</strong> is the catalog entry (Internal, External…). Assign per type or sub-type — whole type covers sub-types that have no assignment of their own.</p>
                            </div>
                        </div>
                        <div class="sig-scope-grid">
                            <div class="st-field sig-field">
                                <label class="st-label">Form</label>
                                <select class="st-input" wire:model.live="sigFormKind">
                                    <option value="drf">DRF</option>
                                    <option value="dcn">DCN</option>
                                </select>
                            </div>
                            <div class="st-field sig-field">
                                <label class="st-label">Document type <span class="st-optional">catalog</span></label>
                                <select class="st-input" wire:model.live="sigParentTypeId">
                                    <option value="" disabled {{ $sigParentTypeId === '' ? 'selected' : '' }}>Select document type…</option>
                                    @foreach($sigParentOptions as $type)
                                        <option value="{{ $type->id }}">{{ $type->doc_type_name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="st-field sig-field">
                                <label class="st-label">Sub-type @if(($sigSubOptions ?? collect())->count())<span class="st-optional">optional</span>@else<span class="st-optional">none</span>@endif</label>
                                <select class="st-input" wire:model.live="sigSubTypeId" @disabled($sigParentTypeId === '' || ($sigSubOptions ?? collect())->count() < 1)>
                                    <option value="">{{ ($sigSubOptions ?? collect())->count() ? 'Whole type' : 'Whole type (no sub-types)' }}</option>
                                    @foreach($sigSubOptions as $sub)
                                        <option value="{{ $sub->id }}">{{ $sub->doc_type_name }}</option>
                                    @endforeach
                                </select>
                                @if($sigParentTypeId !== '' && ($sigSubOptions ?? collect())->count() < 1)
                                    <span class="sig-field-note">No sub-types — save against this whole type.</span>
                                @elseif(($sigSubOptions ?? collect())->count() && $sigSubTypeId === '')
                                    <span class="sig-field-note">Office intake can pick a sub-type — assign here when you can, or leave Whole type for the rest.</span>
                                @endif
                            </div>
                            @if($sigNeedsCollege ?? false)
                                <div class="st-field sig-field">
                                    <label class="st-label">College <span class="st-optional">required</span></label>
                                    <select class="st-input" wire:model.live="sigCollegeId">
                                        <option value="" disabled {{ $sigCollegeId === '' ? 'selected' : '' }}>Select college…</option>
                                        @foreach(($sigCollegeOptions ?? collect()) as $college)
                                            <option value="{{ $college->id }}">{{ $college->college_name }}</option>
                                        @endforeach
                                    </select>
                                    <span class="sig-field-note">Syllabi and TOS/Rubrics signatories are per college (e.g. Buhi Campus vs CCS).</span>
                                </div>
                            @endif
                            <div class="st-field sig-field">
                                <label class="st-label">From</label>
                                <input class="st-input" type="date" wire:model="sigEffectiveFrom">
                            </div>
                            <div class="st-field sig-field">
                                <label class="st-label">Until <span class="st-optional">blank = open</span></label>
                                <input class="st-input" type="date" wire:model="sigEffectiveUntil">
                                <span class="sig-field-note">Leave blank; save again anytime to replace the active set for this type.</span>
                            </div>
                        </div>
                    </div>

                    <div class="sig-section" wire:key="sig-people-{{ $sigFormKind }}-{{ $sigParentTypeId }}-{{ $sigSubTypeId }}-{{ $sigCollegeId }}">
                        <div class="sig-section-head">
                            <span class="sig-section-num">2</span>
                            <div>
                                <h3 class="sig-section-title">Signatories</h3>
                                <p class="sig-section-hint">{{ $sigFormKind === 'drf' ? 'Prepared, reviewed, and approved by' : 'Reviewers and approvals for DCN' }}</p>
                            </div>
                        </div>

                        @if($sigFormKind === 'drf')
                            <div class="sig-people-table" role="group" aria-label="DRF signatories">
                                <div class="sig-people-head" aria-hidden="true">
                                    <span>Role</span>
                                    <span>Name</span>
                                    <span>Designation</span>
                                </div>
                                <div class="sig-people-row">
                                    <span class="sig-role">Prepared by</span>
                                    <input class="st-input" type="text" wire:model="sigPreparedByName" placeholder="Full name" maxlength="255" aria-label="Prepared by name">
                                    <input class="st-input" type="text" wire:model="sigPreparedByDesignation" placeholder="Position / title" maxlength="255" aria-label="Prepared by designation">
                                </div>
                                <div class="sig-people-row">
                                    <span class="sig-role">Reviewed by</span>
                                    <input class="st-input" type="text" wire:model="sigReviewedByName" placeholder="Full name" maxlength="255" aria-label="Reviewed by name">
                                    <input class="st-input" type="text" wire:model="sigReviewedByDesignation" placeholder="Position / title" maxlength="255" aria-label="Reviewed by designation">
                                </div>
                                <div class="sig-people-row">
                                    <span class="sig-role">Approved by</span>
                                    <input class="st-input" type="text" wire:model="sigApprovedByName" placeholder="Full name" maxlength="255" aria-label="Approved by name">
                                    <input class="st-input" type="text" wire:model="sigApprovedByDesignation" placeholder="Position / title" maxlength="255" aria-label="Approved by designation">
                                </div>
                            </div>
                        @else
                            <div class="sig-dcn-blocks">
                                <div class="sig-dcn-block">
                                    <div class="sig-dcn-block-head">
                                        <span>Reviewers</span>
                                        <button type="button" class="sig-link-btn" wire:click="addSigReviewerRow"><i class="fa-solid fa-plus"></i> Add</button>
                                    </div>
                                    <div class="sig-dcn-colhead" aria-hidden="true">
                                        <span></span>
                                        <span>Name</span>
                                        <span></span>
                                    </div>
                                    <div class="sig-dcn-rows">
                                        @foreach($sigReviewers as $i => $row)
                                            <div class="sig-dcn-row" wire:key="sig-rev-{{ $i }}">
                                                <span class="sig-dcn-index">{{ $i + 1 }}</span>
                                                <input class="st-input" type="text" wire:model="sigReviewers.{{ $i }}.name" placeholder="e.g. Juan Dela Cruz" maxlength="255" aria-label="Reviewer {{ $i + 1 }} name">
                                                <button type="button" class="icon-btn icon-btn-danger" wire:click="removeSigReviewerRow({{ $i }})" title="Remove" @disabled(count($sigReviewers) < 2)><i class="fa-solid fa-trash"></i></button>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                                <div class="sig-dcn-block">
                                    <div class="sig-dcn-block-head">
                                        <span>Approvals</span>
                                        <button type="button" class="sig-link-btn" wire:click="addSigApprovalRow"><i class="fa-solid fa-plus"></i> Add</button>
                                    </div>
                                    <div class="sig-dcn-colhead sig-dcn-colhead-approval" aria-hidden="true">
                                        <span></span>
                                        <span>Position</span>
                                        <span>Name</span>
                                        <span></span>
                                    </div>
                                    <div class="sig-dcn-rows">
                                        @foreach($sigApprovals as $i => $row)
                                            <div class="sig-dcn-row sig-dcn-row-approval" wire:key="sig-apr-{{ $i }}">
                                                <span class="sig-dcn-index">{{ $i + 1 }}</span>
                                                <input class="st-input" type="text" wire:model="sigApprovals.{{ $i }}.position" placeholder="e.g. College Dean" maxlength="255" aria-label="Approval {{ $i + 1 }} position">
                                                <input class="st-input" type="text" wire:model="sigApprovals.{{ $i }}.name" placeholder="e.g. Juan Dela Cruz" maxlength="255" aria-label="Approval {{ $i + 1 }} name">
                                                <button type="button" class="icon-btn icon-btn-danger" wire:click="removeSigApprovalRow({{ $i }})" title="Remove" @disabled(count($sigApprovals) < 2)><i class="fa-solid fa-trash"></i></button>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>

                    <div class="sig-actions">
                        @if($sigParentTypeId === '')
                            <p class="sig-actions-hint">Select a Document type from the catalog to assign signatories.</p>
                        @elseif(($sigNeedsCollege ?? false) && $sigCollegeId === '')
                            <p class="sig-actions-hint">Select a college to assign Syllabi / TOS signatories for that campus.</p>
                        @elseif($sigHasActiveAssignment ?? false)
                            <div class="sig-replace-banner" role="status">
                                <strong>Active assignment exists for this exact scope</strong>
                                <span>Office intake still uses that active set (even if the fields below look empty after <em>Start new assignment</em>). Save a replacement to end it, or check Recent for this document type.</span>
                            </div>
                            <div class="sig-actions-row">
                                <button type="button" class="btn-secondary" wire:click="beginNewSignatoryAssignment">
                                    <i class="fa-solid fa-plus"></i> Start new assignment
                                </button>
                                <button type="button" class="btn-primary" wire:click="confirmReplaceSignatoryAssignment" wire:loading.attr="disabled" wire:target="confirmReplaceSignatoryAssignment, saveSignatoryTemplate">
                                    <span wire:loading.remove wire:target="confirmReplaceSignatoryAssignment, saveSignatoryTemplate"><i class="fa-solid fa-floppy-disk"></i> Replace active &amp; save</span>
                                    <span wire:loading wire:target="confirmReplaceSignatoryAssignment, saveSignatoryTemplate">Saving…</span>
                                </button>
                            </div>
                        @else
                            @if(! empty($sigInheritanceHint))
                                <div class="sig-replace-banner sig-inherit-banner" role="status">
                                    <strong>Inherited in office intake</strong>
                                    <span>{{ $sigInheritanceHint }}</span>
                                </div>
                            @else
                                <p class="sig-actions-hint">No active assignment for this scope yet. Fill the names and save.</p>
                            @endif
                            <button type="button" class="btn-primary" wire:click="saveSignatoryTemplate" wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="saveSignatoryTemplate"><i class="fa-solid fa-floppy-disk"></i> Save assignment</span>
                                <span wire:loading wire:target="saveSignatoryTemplate">Saving…</span>
                            </button>
                        @endif
                    </div>
                </div>

                <aside class="sig-history" aria-label="Recent signatories">
                    <div class="sig-history-head">
                        <h3 class="sig-section-title">Recent signatories</h3>
                        @php
                            $activeHistTab = collect($sigHistoryTabs ?? [])->firstWhere('key', $sigHistoryTab)
                                ?? collect($sigHistoryTabs ?? [])->first();
                        @endphp
                        <span class="sig-history-count">{{ (int) ($activeHistTab['count'] ?? 0) }}</span>
                    </div>
                    <div class="sig-history-tabs" role="tablist" aria-label="Document types">
                        @foreach(($sigHistoryTabs ?? []) as $tab)
                            <button
                                type="button"
                                role="tab"
                                class="sig-history-tab {{ $sigHistoryTab === $tab['key'] ? 'is-active' : '' }}"
                                aria-selected="{{ $sigHistoryTab === $tab['key'] ? 'true' : 'false' }}"
                                wire:click="setSigHistoryTab('{{ $tab['key'] }}')"
                            >
                                {{ $tab['label'] }} <span>{{ (int) $tab['count'] }}</span>
                            </button>
                        @endforeach
                    </div>
                    <p class="sig-history-sub">Showing {{ strtoupper($sigFormKind) }} · {{ $activeHistTab['label'] ?? 'All' }}. Active sets are expanded; ended sets stay compact — click to expand.</p>
                    <div class="sig-history-list">
                        @forelse($signatoryAssignmentGroups as $parentGroup)
                            <div class="sig-history-parent" wire:key="sig-p-{{ $parentGroup['key'] }}">
                                <div class="sig-history-group-parent">
                                    {{ $parentGroup['label'] }}
                                </div>
                                @foreach($parentGroup['children'] as $child)
                                    @php
                                        $scopeLabel = $child['label'];
                                        if (($child['key'] ?? '') === 'whole') {
                                            $scopeLabel = 'Whole type (all sub-types without their own set)';
                                        }
                                        $sortedItems = collect($child['items'])->sortBy(function ($row) use ($today) {
                                            $status = OfficeIntakeHelper::signatoryAssignmentStatus($row, $today);
                                            return match ($status) {
                                                'active' => 0,
                                                'scheduled' => 1,
                                                default => 2,
                                            };
                                        })->values();
                                    @endphp
                                    <div class="sig-history-group" wire:key="sig-g-{{ $parentGroup['key'] }}-{{ $child['key'] }}">
                                        <div class="sig-history-group-label">{{ $scopeLabel }}</div>
                                        @foreach($sortedItems as $row)
                                            @php
                                                $fromRaw = data_get($row, 'effective_from');
                                                $untilRaw = data_get($row, 'effective_until');
                                                $dateRange = OfficeIntakeHelper::formatSignatoryDateRange($fromRaw, $untilRaw);
                                                $status = OfficeIntakeHelper::signatoryAssignmentStatus($row, $today);
                                                $active = $status === 'active';
                                                $scheduled = $status === 'scheduled';
                                                $kindLabel = ucfirst((string) ($row->doc_type_kind ?? '—'));
                                                $payloadRaw = data_get($row, 'payload');
                                                $payload = is_array($payloadRaw)
                                                    ? $payloadRaw
                                                    : (is_string($payloadRaw) ? (json_decode($payloadRaw, true) ?: []) : []);
                                                $detailRows = [];
                                                if (($row->form_kind ?? $sigFormKind) === 'dcn') {
                                                    foreach ((array) ($payload['reviewers'] ?? []) as $i => $rev) {
                                                        $n = trim((string) (is_array($rev) ? ($rev['name'] ?? '') : $rev));
                                                        if ($n !== '') {
                                                            $detailRows[] = ['role' => 'Reviewer '.($i + 1), 'name' => $n, 'designation' => ''];
                                                        }
                                                    }
                                                    foreach ((array) ($payload['approvals'] ?? []) as $i => $apr) {
                                                        if (! is_array($apr)) {
                                                            continue;
                                                        }
                                                        $n = trim((string) ($apr['name'] ?? ''));
                                                        $p = trim((string) ($apr['position'] ?? ''));
                                                        if ($n !== '' || $p !== '') {
                                                            $detailRows[] = ['role' => 'Approval '.($i + 1), 'name' => $n, 'designation' => $p];
                                                        }
                                                    }
                                                } else {
                                                    $detailRows = [
                                                        ['role' => 'Prepared by', 'name' => trim((string) ($payload['prepared_by_name'] ?? '')), 'designation' => trim((string) ($payload['prepared_by_designation'] ?? ''))],
                                                        ['role' => 'Reviewed by', 'name' => trim((string) ($payload['reviewed_by_name'] ?? '')), 'designation' => trim((string) ($payload['reviewed_by_designation'] ?? ''))],
                                                        ['role' => 'Approved by', 'name' => trim((string) ($payload['approved_by_name'] ?? '')), 'designation' => trim((string) ($payload['approved_by_designation'] ?? ''))],
                                                    ];
                                                    $detailRows = array_values(array_filter($detailRows, fn ($r) => $r['name'] !== '' || $r['designation'] !== ''));
                                                }
                                                $peoplePreview = collect($detailRows)->map(function ($d) {
                                                    $name = trim((string) ($d['name'] ?? ''));
                                                    $desig = trim((string) ($d['designation'] ?? ''));
                                                    if ($name === '') {
                                                        return null;
                                                    }

                                                    return $desig !== '' ? $name.' ('.$desig.')' : $name;
                                                })->filter()->take(3)->implode(' · ');
                                                if ($peoplePreview === '') {
                                                    $peoplePreview = 'No names saved';
                                                } elseif (count($detailRows) > 3) {
                                                    $peoplePreview .= '…';
                                                }
                                                $formLabel = strtoupper((string) ($row->form_kind ?? $sigFormKind));
                                            @endphp
                                            @if($active || $scheduled)
                                                <article class="sig-history-item {{ $active ? 'is-active' : 'is-scheduled' }}" wire:key="sig-hist-{{ $row->id }}">
                                                    <div class="sig-history-top">
                                                        <div class="sig-history-title-wrap">
                                                            <span class="sig-history-k">Effective</span>
                                                            <strong class="sig-history-type">{{ $dateRange }}</strong>
                                                        </div>
                                                        @if($active)
                                                            <span class="sig-pill is-active">Active</span>
                                                        @else
                                                            <span class="sig-pill is-scheduled">Scheduled</span>
                                                        @endif
                                                    </div>
                                                    <div class="sig-history-meta">
                                                        <span>{{ $formLabel }}</span>
                                                        <span aria-hidden="true">·</span>
                                                        <span>{{ $kindLabel }} documents</span>
                                                    </div>
                                                    <ul class="sig-history-detail">
                                                        @forelse($detailRows as $d)
                                                            <li>
                                                                <span class="sig-history-detail-role">{{ $d['role'] }}</span>
                                                                <span class="sig-history-detail-name">{{ $d['name'] !== '' ? $d['name'] : '—' }}</span>
                                                                @if($d['designation'] !== '')
                                                                    <span class="sig-history-detail-desig">{{ $d['designation'] }}</span>
                                                                @endif
                                                            </li>
                                                        @empty
                                                            <li><span class="sig-history-detail-name">{{ $peoplePreview }}</span></li>
                                                        @endforelse
                                                    </ul>
                                                </article>
                                            @else
                                                <details class="sig-history-item is-ended is-compact" wire:key="sig-hist-{{ $row->id }}">
                                                    <summary class="sig-history-summary">
                                                        <span class="sig-history-summary-main">
                                                            <span class="sig-pill is-ended">Ended</span>
                                                            <span class="sig-history-summary-dates">{{ $dateRange }}</span>
                                                        </span>
                                                        <span class="sig-history-summary-people">{{ $peoplePreview }}</span>
                                                    </summary>
                                                    <div class="sig-history-compact-body">
                                                        <div class="sig-history-meta">
                                                            <span>{{ $formLabel }}</span>
                                                            <span aria-hidden="true">·</span>
                                                            <span>{{ $kindLabel }} documents</span>
                                                        </div>
                                                        <ul class="sig-history-detail">
                                                            @forelse($detailRows as $d)
                                                                <li>
                                                                    <span class="sig-history-detail-role">{{ $d['role'] }}</span>
                                                                    <span class="sig-history-detail-name">{{ $d['name'] !== '' ? $d['name'] : '—' }}</span>
                                                                    @if($d['designation'] !== '')
                                                                        <span class="sig-history-detail-desig">{{ $d['designation'] }}</span>
                                                                    @endif
                                                                </li>
                                                            @empty
                                                                <li><span class="sig-history-detail-name">No names saved</span></li>
                                                            @endforelse
                                                        </ul>
                                                    </div>
                                                </details>
                                            @endif
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>
                        @empty
                            <div class="sig-history-empty">
                                <i class="fa-regular fa-clock"></i>
                                <span>No {{ strtoupper($sigFormKind) }} signatories for {{ $activeHistTab['label'] ?? 'this type' }}.</span>
                            </div>
                        @endforelse
                    </div>
                </aside>
            </div>
        @endif
    </section>

    <section class="tab-panel" wire:key="settings-tab-originators" x-show="tab === 'originators'" x-cloak>
        <div class="panel-toolbar">
            <span class="panel-subtitle">Manage document originators (authors/creators)</span>
            <div class="panel-actions">
                <button type="button" class="btn-secondary" wire:click="openImportOriginators()"><i class="fa-solid fa-file-csv"></i> Import CSV</button>
                <button type="button" class="btn-primary" wire:click="openOriginator()"><i class="fa-solid fa-plus"></i> Add Originator</button>
            </div>
        </div>
        <div class="table-wrap">
            <table class="settings-table">
                <thead><tr><th>Originator Name</th><th style="width:140px;">Actions</th></tr></thead>
                <tbody>
                    @forelse($originators as $orig)
                        <tr wire:key="og-{{ $orig->id }}" data-id="{{ $orig->id }}">
                            <td data-label="Originator">{{ $orig->originator_name }}</td>
                            <td>
                                <div class="row-actions">
                                    <button type="button" class="icon-btn" title="Edit" wire:click="openOriginator({{ $orig->id }})"><i class="fa-solid fa-pen"></i></button>
                                    @if(!empty($canSoftDelete))
                                    <button type="button" class="icon-btn icon-btn-danger" title="Delete" wire:click="confirmDelete('originator', {{ $orig->id }}, 'Delete Originator', 'This originator will be moved to the Admin Console Recycle Bin.')"><i class="fa-solid fa-trash"></i></button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="2" class="empty-cell">No originators yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="tab-panel" wire:key="settings-tab-syllabi" x-show="tab === 'syllabi'" x-cloak>
        <div class="panel-toolbar">
            <div>
                <h2 class="sig-panel-title">Syllabi catalog</h2>
                <p class="panel-subtitle">Everything used by Syllabi / TOS-Rubrics registration — colleges (with their programs), terms, courses, and faculty — in one place.</p>
            </div>
        </div>
        <div class="settings-subtabs" role="tablist" aria-label="Syllabi settings sections">
            @foreach ([
                'colleges' => ['fa-graduation-cap', 'Colleges'],
                'semesters' => ['fa-calendar-week', 'Semesters'],
                'schoolyears' => ['fa-calendar-days', 'School Years'],
                'coursenames' => ['fa-list-check', 'Course Names'],
                'faculties' => ['fa-chalkboard-user', 'Faculty'],
            ] as $key => $meta)
                <button
                    type="button"
                    class="settings-subtab"
                    role="tab"
                    :aria-selected="syllabiSection === '{{ $key }}'"
                    :class="syllabiSection === '{{ $key }}' && 'active'"
                    @click="setSyllabiSection('{{ $key }}')"
                >
                    <i class="fa-solid {{ $meta[0] }}"></i> {{ $meta[1] }}
                </button>
            @endforeach
        </div>

        <div class="syllabi-section" wire:key="settings-syllabi-faculties" x-show="syllabiSection === 'faculties'" x-cloak>
        <div x-data="{ collegeFilter: 'all' }">
        <div class="panel-toolbar">
            <span class="panel-subtitle">One faculty record can belong to several colleges (part-timers)</span>
            <div class="panel-actions">
                <button type="button" class="btn-secondary" wire:click="openImportFaculties()"><i class="fa-solid fa-file-csv"></i> Import CSV</button>
                <button type="button" class="btn-primary" wire:click="openFaculty()"><i class="fa-solid fa-plus"></i> Add Faculty</button>
            </div>
        </div>
        @if($colleges->isNotEmpty())
            <div class="st-college-filters" role="group" aria-label="Filter faculties by college">
                <button type="button" class="st-college-chip" :class="{ 'is-active': collegeFilter === 'all' }" @click="collegeFilter = 'all'">All</button>
                @foreach($colleges as $college)
                    @php
                        $chipLabel = trim((string) ($college->college_code ?? '')) ?: trim((string) ($college->college_name ?? ''));
                    @endphp
                    <button
                        type="button"
                        class="st-college-chip"
                        title="{{ $college->college_name }}"
                        :class="{ 'is-active': collegeFilter === '{{ $college->id }}' }"
                        @click="collegeFilter = '{{ $college->id }}'"
                    >{{ $chipLabel }}</button>
                @endforeach
            </div>
        @endif
        <div class="table-wrap">
            <table class="settings-table">
                <thead><tr><th>Colleges</th><th>Faculty Name</th><th style="width:140px;">Actions</th></tr></thead>
                <tbody>
                    @forelse($faculties as $fac)
                        @php
                            $facCollegeKeys = array_map('strval', $fac->college_ids ?? []);
                        @endphp
                        <tr
                            wire:key="fc-{{ $fac->id }}"
                            data-id="{{ $fac->id }}"
                            x-show="collegeFilter === 'all' || {{ json_encode(array_values($facCollegeKeys)) }}.includes(collegeFilter)"
                        >
                            <td data-label="Colleges">{{ $fac->college_label }}</td>
                            <td data-label="Faculty">{{ $fac->faculty_name }}</td>
                            <td>
                                <div class="row-actions">
                                    <button type="button" class="icon-btn" title="Edit" wire:click="openFaculty({{ $fac->id }})"><i class="fa-solid fa-pen"></i></button>
                                    @if(!empty($canSoftDelete))
                                    <button type="button" class="icon-btn icon-btn-danger" title="Delete" wire:click="confirmDelete('faculty', {{ $fac->id }}, 'Delete Faculty', 'This faculty will be moved to the Admin Console Recycle Bin.')"><i class="fa-solid fa-trash"></i></button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="empty-cell">No faculties yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        </div>
        </div>

        <div
            class="syllabi-section"
            wire:key="settings-syllabi-colleges-{{ $collegeDetailId ?? 0 }}"
            x-show="syllabiSection === 'colleges'"
            x-data="{
                openId: {{ $collegeDetailId ? (int) $collegeDetailId : 'null' }},
                isOpen(id) { return Number(this.openId) === Number(id); },
                toggleCollege(id) {
                    id = Number(id);
                    // Local-only toggle — avoid Livewire remorph fighting the dropdown.
                    this.openId = this.isOpen(id) ? null : id;
                }
            }"
        >
            <div class="panel-toolbar">
                <span class="panel-subtitle">Add a college (office or name), then click the college name to show its programs.</span>
                <button type="button" class="btn-primary" @click="openCollegeModal()"><i class="fa-solid fa-plus"></i> Add College</button>
            </div>
            <div class="table-wrap">
                <table class="settings-table st-college-accordion">
                    <thead><tr><th>College Name</th><th>Office</th><th style="width:100px;">Programs</th><th style="width:140px;">Actions</th></tr></thead>
                    <tbody>
                        @forelse($colleges as $college)
                            @php
                                $collegePrograms = $programsByCollege[(string) $college->id] ?? collect();
                                $progCount = $collegePrograms->count();
                                $cid = (int) $college->id;
                            @endphp
                            <tr
                                wire:key="cl-{{ $cid }}"
                                data-id="{{ $cid }}"
                                class="st-college-row"
                                :class="{ 'is-open': isOpen({{ $cid }}) }"
                            >
                                <td data-label="College">
                                    <button
                                        type="button"
                                        class="st-college-link"
                                        :aria-expanded="isOpen({{ $cid }}) ? 'true' : 'false'"
                                        @click="toggleCollege({{ $cid }})"
                                    >
                                        <i
                                            class="fa-solid st-college-caret"
                                            :class="isOpen({{ $cid }}) ? 'fa-chevron-down' : 'fa-chevron-right'"
                                            aria-hidden="true"
                                        ></i>
                                        <span>{{ $college->college_name }}</span>
                                    </button>
                                </td>
                                <td data-label="Office">
                                    @if($college->office_code || $college->office_name)
                                        {{ $college->office_code ? $college->office_code : '' }}{{ $college->office_code && $college->office_name ? ' — ' : '' }}{{ $college->office_name }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td data-label="Programs">{{ $progCount }}</td>
                                <td>
                                    <div class="row-actions">
                                        <button type="button" class="icon-btn" title="Edit college" @click="openCollegeModal({{ $cid }})"><i class="fa-solid fa-pen"></i></button>
                                        @if(!empty($canSoftDelete))
                                        <button type="button" class="icon-btn icon-btn-danger" title="Delete" wire:click="confirmDelete('college', {{ $cid }}, 'Delete College', 'This college will be moved to the Admin Console Recycle Bin.')"><i class="fa-solid fa-trash"></i></button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                            <tr
                                wire:key="cl-programs-{{ $cid }}"
                                class="st-college-programs-row"
                                :class="{ 'is-visible': isOpen({{ $cid }}) }"
                            >
                                <td colspan="4">
                                    <div class="st-college-programs-panel">
                                        <div class="st-college-programs-head">
                                            <span class="st-college-programs-label">
                                                <i class="fa-solid fa-book-open" aria-hidden="true"></i>
                                                Programs
                                                <span class="program-count">({{ $progCount }})</span>
                                            </span>
                                            <button
                                                type="button"
                                                class="btn-primary st-add-program-btn"
                                                @click="openId = {{ $cid }}"
                                                wire:click="openProgramForCollege({{ $cid }})"
                                            ><i class="fa-solid fa-plus"></i> Add Program</button>
                                        </div>
                                        <table class="settings-table st-nested-programs">
                                            <thead><tr><th>Program Name</th><th style="width:120px;">Code</th><th style="width:120px;">Actions</th></tr></thead>
                                            <tbody>
                                                @forelse($collegePrograms as $prog)
                                                    <tr wire:key="pg-{{ $prog->id }}" data-id="{{ $prog->id }}">
                                                        <td data-label="Program" class="st-program-name-cell">{{ $prog->program_name }}</td>
                                                        <td data-label="Code" class="st-program-code-cell">{{ $prog->program_code ?? '—' }}</td>
                                                        <td class="st-program-actions-cell">
                                                            <div class="row-actions">
                                                                <button type="button" class="icon-btn" title="Edit" wire:click="openProgram({{ $prog->id }})"><i class="fa-solid fa-pen"></i></button>
                                                                @if(!empty($canSoftDelete))
                                                                <button type="button" class="icon-btn icon-btn-danger" title="Delete" wire:click="confirmDelete('program', {{ $prog->id }}, 'Delete Program', 'This program will be moved to the Admin Console Recycle Bin.')"><i class="fa-solid fa-trash"></i></button>
                                                                @endif
                                                            </div>
                                                        </td>
                                                    </tr>
                                                @empty
                                                    <tr><td colspan="3" class="empty-cell">No programs yet. Click Add Program.</td></tr>
                                                @endforelse
                                            </tbody>
                                        </table>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="empty-cell">No colleges yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="syllabi-section" wire:key="settings-syllabi-semesters" x-show="syllabiSection === 'semesters'" x-cloak>
        <div class="panel-toolbar">
            <span class="panel-subtitle">Academic semesters</span>
            <button type="button" class="btn-primary" wire:click="openSemester()"><i class="fa-solid fa-plus"></i> Add Semester</button>
        </div>
        <div class="table-wrap">
            <table class="settings-table">
                <thead><tr><th>Semester Name</th><th style="width:140px;">Actions</th></tr></thead>
                <tbody>
                    @forelse($semesters as $sem)
                        <tr wire:key="sm-{{ $sem->id }}" data-id="{{ $sem->id }}">
                            <td data-label="Semester">{{ $sem->semester_name }}</td>
                            <td>
                                <div class="row-actions">
                                    <button type="button" class="icon-btn" title="Edit" wire:click="openSemester({{ $sem->id }})"><i class="fa-solid fa-pen"></i></button>
                                    @if(!empty($canSoftDelete))
                                    <button type="button" class="icon-btn icon-btn-danger" title="Delete" wire:click="confirmDelete('semester', {{ $sem->id }}, 'Delete Semester', 'This semester will be moved to the Admin Console Recycle Bin.')"><i class="fa-solid fa-trash"></i></button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="2" class="empty-cell">No semesters yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        </div>

        <div class="syllabi-section" wire:key="settings-syllabi-schoolyears" x-show="syllabiSection === 'schoolyears'" x-cloak>
        <div class="panel-toolbar">
            <span class="panel-subtitle">Academic school years — only the <strong>Active</strong> year is offered on office Syllabi/TOS DRF requests</span>
            <button type="button" class="btn-primary" wire:click="openSchoolYear()"><i class="fa-solid fa-plus"></i> Add School Year</button>
        </div>
        <div class="table-wrap">
            <table class="settings-table">
                <thead><tr><th>School Year</th><th style="width:110px;">Status</th><th style="width:160px;">Actions</th></tr></thead>
                <tbody>
                    @forelse($schoolYears as $sy)
                        @php $syActive = ! empty($sy->is_active); @endphp
                        <tr wire:key="sy-{{ $sy->id }}" data-id="{{ $sy->id }}">
                            <td data-label="School Year">{{ $sy->school_year }}</td>
                            <td data-label="Status">
                                @if($syActive)
                                    <span class="st-status-pill is-on">Active</span>
                                @else
                                    <span class="st-status-pill">Inactive</span>
                                @endif
                            </td>
                            <td>
                                <div class="row-actions">
                                    <button
                                        type="button"
                                        class="st-sy-switch{{ $syActive ? ' is-on' : '' }}"
                                        role="switch"
                                        aria-checked="{{ $syActive ? 'true' : 'false' }}"
                                        title="{{ $syActive ? 'Active school year' : 'Set as active school year' }}"
                                        @if($syActive) disabled @else wire:click="activateSchoolYear({{ $sy->id }})" @endif
                                    >
                                        <span class="st-sy-switch-thumb" aria-hidden="true"></span>
                                    </button>
                                    <button type="button" class="icon-btn" title="Edit" wire:click="openSchoolYear({{ $sy->id }})"><i class="fa-solid fa-pen"></i></button>
                                    @if(!empty($canSoftDelete))
                                    <button type="button" class="icon-btn icon-btn-danger" title="Delete" wire:click="confirmDelete('schoolYear', {{ $sy->id }}, 'Delete School Year', 'This school year will be moved to the Admin Console Recycle Bin.')"><i class="fa-solid fa-trash"></i></button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="empty-cell">No school years yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        </div>

        <div class="syllabi-section" wire:key="settings-syllabi-coursenames" x-show="syllabiSection === 'coursenames'" x-cloak>
        <div>
        <div class="panel-toolbar">
            <span class="panel-subtitle">GE, PE, NSTP, and Major are per program, school year, and semester. Add all types together for one program/semester, then use them to auto-fill Syllabi/TOS registration.</span>
            <div class="panel-actions">
                @if(count($selectedCourseIds) > 0)
                    <button type="button" class="btn-secondary" wire:click="openSelectedCoursesBulk"><i class="fa-solid fa-pen-to-square"></i> Edit selected</button>
                    @if(!empty($canSoftDelete))
                    <button type="button" class="btn-secondary" wire:click="confirmDestroySelectedProgramCourses"><i class="fa-solid fa-trash"></i> Delete selected</button>
                    @endif
                @endif
                <button
                    type="button"
                    class="btn-secondary"
                    wire:click="openProgramCoursesBulk('{{ $courseListCollegeId }}', '{{ $courseListProgramId }}')"
                ><i class="fa-solid fa-table-list"></i> Edit by program</button>
                <button type="button" class="btn-primary" wire:click="openProgramCourse()"><i class="fa-solid fa-plus"></i> Add Course</button>
            </div>
        </div>
        @if($colleges->isNotEmpty())
            <div class="st-college-filters" role="group" aria-label="Filter courses by college">
                <button type="button" class="st-college-chip {{ $courseListCollegeId === '' ? 'is-active' : '' }}" wire:click="setCourseListCollege('')">All</button>
                @foreach($colleges as $college)
                    @php
                        $chipLabel = trim((string) ($college->college_code ?? '')) ?: trim((string) ($college->college_name ?? ''));
                    @endphp
                    <button
                        type="button"
                        class="st-college-chip {{ (string) $courseListCollegeId === (string) $college->id ? 'is-active' : '' }}"
                        title="{{ $college->college_name }}"
                        wire:click="setCourseListCollege('{{ $college->id }}')"
                    >{{ $chipLabel }}</button>
                @endforeach
            </div>
            @if($courseListCollegeId !== '')
                <div class="st-college-filters" role="group" aria-label="Filter courses by program">
                    <button type="button" class="st-college-chip {{ $courseListProgramId === '' ? 'is-active' : '' }}" wire:click="setCourseListProgram('')">All programs</button>
                    @foreach($programs as $prog)
                        @if((string) $prog->college_id === (string) $courseListCollegeId)
                            <button
                                type="button"
                                class="st-college-chip {{ (string) $courseListProgramId === (string) $prog->id ? 'is-active' : '' }}"
                                title="{{ $prog->program_name }}"
                                wire:click="setCourseListProgram('{{ $prog->id }}')"
                            >{{ $prog->program_code ?: $prog->program_name }}</button>
                        @endif
                    @endforeach
                </div>
            @endif
        @endif
        {{-- Epoch remounts the table after save so year-group row morphing cannot blank the list.
             No wire:ignore so Livewire can bind row checkboxes for multi-select. --}}
        @php
            $showSchoolYearCol = \Illuminate\Support\Facades\Schema::hasColumn('dcs_program_courses', 'school_year_id')
                && $programCourses->contains(fn ($c) => ! empty($c->school_year_id));
            $courseTableColspan = 7 + ($showSchoolYearCol ? 1 : 0);
            $visibleCourseIds = $programCourses->pluck('id')->map(fn ($id) => (int) $id)->all();
            $selectedInts = array_map('intval', $selectedCourseIds);
            $allVisibleSelected = $visibleCourseIds !== []
                && count(array_diff($visibleCourseIds, $selectedInts)) === 0;
        @endphp
        <div class="table-wrap" wire:key="course-names-{{ $courseListEpoch }}-{{ $courseListCollegeId }}-{{ $courseListProgramId }}">
            <table class="settings-table">
                <thead>
                    <tr>
                        <th style="width:40px;">
                            <input
                                type="checkbox"
                                title="Select all"
                                wire:click.prevent="toggleSelectAllCourses"
                                @checked($allVisibleSelected)
                                @disabled($programCourses->isEmpty())
                            >
                        </th>
                        <th>College</th>
                        <th>Program</th>
                        @if($showSchoolYearCol)<th>School Year</th>@endif
                        <th>Year Level</th>
                        <th>Course Code</th>
                        <th>Course Name</th>
                        <th style="width:140px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @if($programCourses->isEmpty())
                        <tr wire:key="course-names-empty"><td colspan="{{ $courseTableColspan }}" class="empty-cell">No courses yet.</td></tr>
                    @else
                    @php
                        $knownYears = \App\Helpers\SyllabiMonitoringHelper::YEAR_LEVELS;
                        $knownTypes = \App\Helpers\SyllabiMonitoringHelper::COURSE_TYPES;
                        $typeOrder = array_flip($knownTypes);
                        $coursesByYear = [];
                        foreach ($knownYears as $yearKey) {
                            $coursesByYear[$yearKey] = collect();
                        }
                        $coursesByYear[''] = collect();
                        foreach ($programCourses as $course) {
                            $yearKey = trim((string) ($course->year_level ?? ''));
                            if (! array_key_exists($yearKey, $coursesByYear)) {
                                $yearKey = '';
                            }
                            $coursesByYear[$yearKey]->push($course);
                        }
                    @endphp
                    @foreach($coursesByYear as $yearKey => $yearCourses)
                        @if($yearCourses->isEmpty())
                            @continue
                        @endif
                        @php
                            $yearLabel = $yearKey !== '' ? $yearKey : 'No year level';
                            $yearCount = $yearCourses->count();
                            $yearKeySlug = $yearKey !== '' ? \Illuminate\Support\Str::slug($yearKey) : 'none';
                        @endphp
                        <tr class="st-year-header" wire:key="year-header-{{ $yearKeySlug }}">
                            <td colspan="{{ $courseTableColspan }}">
                                <span>Year Level · {{ $yearLabel }}</span>
                                <span class="st-year-count">{{ $yearCount }} {{ $yearCount === 1 ? 'course' : 'courses' }}</span>
                            </td>
                        </tr>
                        @php
                            // Preserve query order (semester_id) via first-seen group keys.
                            $coursesBySem = $yearCourses->groupBy(fn ($c) => trim((string) ($c->semester_name ?? '')));
                        @endphp
                        @foreach($coursesBySem as $semKey => $semCourses)
                            @php
                                $semLabel = $semKey !== '' ? $semKey : 'No semester';
                                $semCount = $semCourses->count();
                                $semKeySlug = $semKey !== '' ? \Illuminate\Support\Str::slug($semKey) : 'none';
                            @endphp
                            <tr class="st-sem-header" wire:key="sem-header-{{ $yearKeySlug }}-{{ $semKeySlug }}">
                                <td colspan="{{ $courseTableColspan }}">
                                    <span>Semester · {{ $semLabel }}</span>
                                    <span class="st-year-count">{{ $semCount }} {{ $semCount === 1 ? 'course' : 'courses' }}</span>
                                </td>
                            </tr>
                            @php
                                $coursesByType = $semCourses->groupBy(fn ($c) => trim((string) ($c->course_type ?? '')));
                                $coursesByType = $coursesByType->sortBy(fn ($rows, $typeKey) => $typeOrder[$typeKey] ?? 99);
                            @endphp
                            @foreach($coursesByType as $typeKey => $typeCourses)
                                @php
                                    $typeLabel = match ($typeKey) {
                                        'Major' => 'Major Courses',
                                        'NSTP' => 'NSTP Courses',
                                        '' => 'No course type',
                                        default => $typeKey,
                                    };
                                    $typeCourses = $typeCourses->sortBy([
                                        fn ($c) => trim((string) ($c->course_code ?? '')) === '' ? 1 : 0,
                                        fn ($c) => (string) ($c->course_code ?? ''),
                                        fn ($c) => (string) ($c->course_name ?? ''),
                                    ])->values();
                                    $typeCount = $typeCourses->count();
                                    $typeKeySlug = $typeKey !== '' ? \Illuminate\Support\Str::slug($typeKey) : 'none';
                                @endphp
                                <tr class="st-type-header" wire:key="type-header-{{ $yearKeySlug }}-{{ $semKeySlug }}-{{ $typeKeySlug }}">
                                    <td colspan="{{ $courseTableColspan }}">
                                        <span>{{ $typeLabel }}</span>
                                        <span class="st-year-count">{{ $typeCount }} {{ $typeCount === 1 ? 'course' : 'courses' }}</span>
                                    </td>
                                </tr>
                                @foreach($typeCourses as $course)
                                    <tr wire:key="pc-{{ $course->id }}" class="st-course-data" data-id="{{ $course->id }}">
                                        <td data-label="Select">
                                            <input type="checkbox" value="{{ $course->id }}" wire:model.live="selectedCourseIds">
                                        </td>
                                        <td data-label="College">{{ $course->college_name ?? '—' }}</td>
                                        <td data-label="Program">{{ $course->program_name ?? '—' }}</td>
                                        @if($showSchoolYearCol)
                                            <td data-label="School Year">{{ $course->school_year ?? '—' }}</td>
                                        @endif
                                        <td data-label="Year Level">{{ $course->year_level ?? '—' }}</td>
                                        <td data-label="Code">{{ $course->course_code ?: '—' }}</td>
                                        <td data-label="Course Name">{{ $course->course_name }}</td>
                                        <td>
                                            <div class="row-actions">
                                                <button type="button" class="icon-btn" title="Edit" wire:click="openProgramCourse({{ $course->id }})"><i class="fa-solid fa-pen"></i></button>
                                                @if(!empty($canSoftDelete))
                                                <button type="button" class="icon-btn icon-btn-danger" title="Delete" wire:click="confirmDelete('programCourse', {{ $course->id }}, 'Delete Course', 'This course will be moved to the Admin Console Recycle Bin.')"><i class="fa-solid fa-trash"></i></button>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            @endforeach
                        @endforeach
                    @endforeach
                    @endif
                </tbody>
            </table>
        </div>
        </div>
        </div>
    </section>
</main>

{{-- Instant Alpine shell while Livewire loads the real modal (one overlay at a time). --}}
<div
    class="overlay"
    id="settingsOverlayLoading"
    x-show="isModalOpen && isModalLoading"
    x-cloak
    x-on:click.self="dismiss()"
    style="display: none;"
>
    <div class="modal modal--college" x-on:click.stop>
        <div class="st-modal st-modal-loading">
            <div class="st-modal-top">
                <div class="st-modal-icon"><i class="fa-solid fa-pen-to-square"></i></div>
                <button type="button" class="st-modal-close" x-on:click="dismiss()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="st-modal-title">Please wait</div>
            <p class="st-faculty-hint" style="margin:0;">Loading form…</p>
        </div>
    </div>
</div>

@if($modalKind !== '')
<div
    class="overlay"
    id="settingsOverlay"
    x-show="isModalOpen"
    x-cloak
    x-on:click.self="dismiss()"
    style="display: none;"
>
    <div class="modal{{ $modalKind === 'college' ? ' modal--college' : '' }}{{ $modalKind === 'faculty' ? ' modal--faculty' : '' }}{{ ($modalKind === 'docType' && ! $editingId && ! $parentId) ? ' modal--doc-type' : '' }}{{ in_array($modalKind, ['programCourse', 'programCoursesBulk'], true) ? ' modal--courses' : '' }}{{ $modalKind === 'programCoursesBulk' ? ' modal--courses-bulk' : '' }}" id="settingsModalBox" x-on:click.stop>
        @if(str_ends_with($modalKind, ':delete'))
            <div class="st-modal delete-modal">
                <div class="st-modal-top">
                    <div class="st-modal-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
                    <button type="button" class="st-modal-close" x-on:click="dismiss()"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <div class="delete-title">{{ $deleteTitle }}</div>
                <div class="delete-message">{{ $deleteMessage }}</div>
                <div class="st-actions-row">
                    <button type="button" class="st-btn st-btn-ghost" x-on:click="dismiss()">Cancel</button>
                    <button type="button" class="st-btn st-btn-danger" wire:click="destroy" wire:loading.attr="disabled">
                        <i class="fa-solid fa-trash"></i> Delete
                    </button>
                </div>
            </div>
        @elseif($modalKind === 'signatory:replace')
            <div class="st-modal confirm-modal">
                <div class="st-modal-top">
                    <div class="st-modal-icon"><i class="fa-solid fa-floppy-disk"></i></div>
                    <button type="button" class="st-modal-close" x-on:click="dismiss()"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <div class="confirm-title">{{ $deleteTitle }}</div>
                <div class="confirm-message">{{ $deleteMessage }}</div>
                <div class="st-actions-row">
                    <button type="button" class="st-btn st-btn-ghost" x-on:click="dismiss()">Cancel</button>
                    <button type="button" class="st-btn st-btn-primary" wire:click="saveSignatoryTemplate" wire:loading.attr="disabled" wire:target="saveSignatoryTemplate">
                        <span wire:loading.remove wire:target="saveSignatoryTemplate"><i class="fa-solid fa-check"></i> OK</span>
                        <span wire:loading wire:target="saveSignatoryTemplate">Saving…</span>
                    </button>
                </div>
            </div>
        @else
            <form class="st-modal{{ $modalKind === 'faculty' ? ' st-modal--faculty' : '' }}" wire:submit.prevent="{{ str_starts_with($modalKind, 'import') ? 'runCsvImport' : 'save' }}">
                <div class="st-modal-top">
                    <div class="st-modal-heading">
                        <div class="st-modal-icon"><i class="fa-solid {{ $modalKind === 'faculty' ? 'fa-chalkboard-user' : (str_starts_with($modalKind, 'import') ? 'fa-file-csv' : 'fa-pen-to-square') }}"></i></div>
                        <div class="st-modal-title">
                            @if($modalKind === 'docType') {{ $editingId ? 'Edit Document Type' : ($parentId ? 'Add Sub-type' : 'Add Document Type') }}
                            @elseif($modalKind === 'originator') {{ $editingId ? 'Edit Originator' : 'Add Originator' }}
                            @elseif($modalKind === 'faculty') {{ $editingId ? 'Edit Faculty' : 'Add Faculty' }}
                            @elseif($modalKind === 'importOriginators') Import Originators (CSV)
                            @elseif($modalKind === 'importFaculties') Import Faculties (CSV)
                            @elseif($modalKind === 'college') {{ $editingId ? 'Edit College' : 'Add College' }}
                            @elseif($modalKind === 'program') {{ $editingId ? 'Edit Program' : 'Add Program' }}
                            @elseif($modalKind === 'semester') {{ $editingId ? 'Edit Semester' : 'Add Semester' }}
                            @elseif($modalKind === 'schoolYear') {{ $editingId ? 'Edit School Year' : 'Add School Year' }}
                            @elseif($modalKind === 'programCourse') {{ $editingId ? 'Edit Course' : 'Add Courses' }}
                            @elseif($modalKind === 'programCoursesBulk') Edit courses by program
                            @endif
                        </div>
                    </div>
                    <button type="button" class="st-modal-close" x-on:click="dismiss()" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
                </div>

                @if($modalKind === 'importOriginators' || $modalKind === 'importFaculties')
                    <p class="csv-help">
                        @if($modalKind === 'importOriginators')
                            One originator name per line. Optional header: <code>originator_name</code>
                        @else
                            Columns: <code>faculty_name,college_name</code>. College must match an existing college. Repeat the same faculty name on multiple rows to link them to several colleges. Optional header row is fine.
                        @endif
                    </p>
                    <div class="st-field">
                        <label class="st-label">CSV File</label>
                        <input type="file" class="st-input @error('csvFile') error @enderror" wire:model="csvFile" accept=".csv,text/csv">
                        @error('csvFile') <div class="field-error csv-error">{{ $message }}</div> @enderror
                        <div wire:loading wire:target="csvFile" class="st-faculty-hint">Uploading…</div>
                    </div>
                @elseif($modalKind === 'docType')
                    <div class="st-field">
                        <label class="st-label" for="stDocTypeName">{{ $parentId ? 'Sub-type name' : 'Document type name' }}</label>
                        <input
                            id="stDocTypeName"
                            type="text"
                            class="st-input @error('docTypeName') error @enderror"
                            wire:model="docTypeName"
                            placeholder="{{ $parentId ? 'e.g. Memo, Circular' : 'e.g. Internal, Syllabi' }}"
                        >
                        @error('docTypeName') <div class="field-error">{{ $message }}</div> @enderror
                    </div>
                    @if(! $editingId && ! $parentId)
                        <div class="st-college-program-block">
                            <div class="st-college-program-title">Sub-types <span class="st-optional">optional</span></div>
                            <p class="st-faculty-hint" style="margin-top:0;">Add one or more sub-types for this document type now, or leave blank and add them later from the list.</p>
                            <div class="st-course-rows st-doc-type-sub-rows">
                                <div class="st-course-row st-course-row-head st-doc-type-sub-head">
                                    <span>Sub-type name</span>
                                    <span></span>
                                </div>
                                @foreach($docTypeSubRows as $i => $row)
                                    <div class="st-course-row st-doc-type-sub-row" wire:key="doc-type-sub-row-{{ $i }}">
                                        <div>
                                            <input
                                                type="text"
                                                class="st-input @error('docTypeSubRows.'.$i.'.name') error @enderror"
                                                wire:model="docTypeSubRows.{{ $i }}.name"
                                                placeholder="e.g. Memo, Circular"
                                                wire:keydown.enter.prevent="addDocTypeSubRow"
                                            >
                                            @error('docTypeSubRows.'.$i.'.name') <div class="field-error">{{ $message }}</div> @enderror
                                        </div>
                                        <button
                                            type="button"
                                            class="icon-btn icon-btn-danger"
                                            title="Remove row"
                                            wire:click="removeDocTypeSubRow({{ $i }})"
                                            @disabled(count($docTypeSubRows) <= 1)
                                        >
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </div>
                                @endforeach
                            </div>
                            <button type="button" class="st-add-row-btn" wire:click="addDocTypeSubRow">
                                <i class="fa-solid fa-plus"></i> Add another sub-type
                            </button>
                            @error('docTypeSubRows') <div class="field-error">{{ $message }}</div> @enderror
                        </div>
                    @endif
                @elseif($modalKind === 'originator')
                    <div class="st-field">
                        <label class="st-label">Originator Name</label>
                        <input type="text" class="st-input @error('originatorName') error @enderror" wire:model="originatorName">
                        @error('originatorName') <div class="field-error">{{ $message }}</div> @enderror
                    </div>
                @elseif($modalKind === 'faculty')
                    <div class="st-field">
                        <label class="st-label" for="stFacultyName">Faculty name</label>
                        <input
                            id="stFacultyName"
                            type="text"
                            class="st-input @error('facultyName') error @enderror"
                            wire:model="facultyName"
                            placeholder="e.g. Juan Dela Cruz"
                            autocomplete="name"
                        >
                        @error('facultyName') <div class="field-error">{{ $message }}</div> @enderror
                    </div>
                    <div class="st-field st-field-colleges" x-data="{ q: '' }">
                        <div class="st-label-row">
                            <label class="st-label" style="margin-bottom:0;">Colleges</label>
                            @if($colleges->isNotEmpty())
                                <span class="st-faculty-count">{{ count($facultyCollegeIds) }} selected</span>
                            @endif
                        </div>
                        @if($colleges->isNotEmpty())
                            <div class="st-faculty-search">
                                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                                <input
                                    type="search"
                                    class="st-input"
                                    placeholder="Filter colleges…"
                                    x-model="q"
                                    @keydown.enter.prevent
                                    aria-label="Filter colleges"
                                >
                            </div>
                        @endif
                        <div class="st-faculty-picks @error('facultyCollegeIds') error @enderror" role="group" aria-label="Colleges">
                            @forelse($colleges as $college)
                                @php
                                    $collegeLabel = trim((string) $college->college_name);
                                    $collegeCode = trim((string) ($college->college_code ?? ''));
                                    $filterKey = mb_strtolower(trim($collegeLabel.' '.$collegeCode));
                                @endphp
                                <label
                                    class="st-faculty-pick"
                                    data-label="{{ $filterKey }}"
                                    x-show="!q.trim() || ($el.dataset.label || '').includes(q.trim().toLowerCase())"
                                >
                                    <input
                                        type="checkbox"
                                        value="{{ $college->id }}"
                                        wire:model.live="facultyCollegeIds"
                                    >
                                    <span class="st-faculty-pick-body">
                                        <span class="st-faculty-pick-name">{{ $collegeLabel !== '' ? $collegeLabel : 'Untitled college' }}</span>
                                        @if($collegeCode !== '')
                                            <span class="st-faculty-pick-code">{{ $collegeCode }}</span>
                                        @endif
                                    </span>
                                </label>
                            @empty
                                <p class="st-faculty-empty">Add colleges first, then assign this instructor.</p>
                            @endforelse
                        </div>
                        @error('facultyCollegeIds') <div class="field-error">{{ $message }}</div> @enderror
                        @error('facultyCollegeIds.*') <div class="field-error">{{ $message }}</div> @enderror
                        <p class="st-faculty-hint">Select every college where this instructor teaches (for example CAS for GE and CCS for a major course).</p>
                    </div>
                @elseif($modalKind === 'college')
                    <div class="st-field">
                        <label class="st-label">Office <span class="st-optional">optional</span></label>
                        <select class="st-input @error('officeId') error @enderror" wire:model.live="officeId">
                            <option value="">— None — not a college office</option>
                            @foreach($collegeOffices as $office)
                                <option value="{{ $office->id }}">{{ $office->office_code ? $office->office_code . ' — ' : '' }}{{ $office->office_name }}</option>
                            @endforeach
                        </select>
                        @error('officeId') <div class="field-error">{{ $message }}</div> @enderror
                        <p class="st-faculty-hint">Leave this blank for offices that are not colleges (Cashier, ICTU, RFIU, and similar). Pick an office only for college units such as CCS or CEA.</p>
                    </div>
                    <div class="st-field">
                        <label class="st-label">College Name</label>
                        <input type="text" class="st-input @error('collegeName') error @enderror" wire:model="collegeName">
                        @error('collegeName') <div class="field-error">{{ $message }}</div> @enderror
                    </div>
                    @if(! $editingId)
                        <div class="st-college-program-block">
                            <div class="st-college-program-title">Programs <span class="st-optional">optional</span></div>
                            <p class="st-faculty-hint" style="margin-top:0;">Add one or more programs for this college now, or leave blank and add them later from the list.</p>
                            <div class="st-course-rows st-college-program-rows">
                                <div class="st-course-row st-course-row-head">
                                    <span>Code</span>
                                    <span>Program name</span>
                                    <span></span>
                                </div>
                                @foreach($collegeProgramRows as $i => $row)
                                    <div class="st-course-row" wire:key="college-program-row-{{ $i }}">
                                        <div>
                                            <input
                                                type="text"
                                                class="st-input @error('collegeProgramRows.'.$i.'.code') error @enderror"
                                                wire:model="collegeProgramRows.{{ $i }}.code"
                                                placeholder="e.g. BSCS"
                                            >
                                            @error('collegeProgramRows.'.$i.'.code') <div class="field-error">{{ $message }}</div> @enderror
                                        </div>
                                        <div>
                                            <input
                                                type="text"
                                                class="st-input @error('collegeProgramRows.'.$i.'.name') error @enderror"
                                                wire:model="collegeProgramRows.{{ $i }}.name"
                                                placeholder="e.g. Bachelor of Science in Computer Science"
                                                wire:keydown.enter.prevent="addCollegeProgramRow"
                                            >
                                            @error('collegeProgramRows.'.$i.'.name') <div class="field-error">{{ $message }}</div> @enderror
                                        </div>
                                        <button
                                            type="button"
                                            class="icon-btn icon-btn-danger"
                                            title="Remove row"
                                            wire:click="removeCollegeProgramRow({{ $i }})"
                                            @disabled(count($collegeProgramRows) <= 1)
                                        >
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </div>
                                @endforeach
                            </div>
                            <button type="button" class="st-add-row-btn" wire:click="addCollegeProgramRow">
                                <i class="fa-solid fa-plus"></i> Add another program
                            </button>
                            @error('collegeProgramRows') <div class="field-error">{{ $message }}</div> @enderror
                        </div>
                    @endif
                @elseif($modalKind === 'program')
                    @php
                        $programCollegeLocked = $collegeId !== '' && $collegeDetailId && (int) $collegeId === (int) $collegeDetailId;
                        $programCollegeName = $programCollegeLocked
                            ? (string) optional($colleges->firstWhere('id', (int) $collegeId))->college_name
                            : '';
                    @endphp
                    <div class="st-field">
                        <label class="st-label">College</label>
                        @if($programCollegeLocked && $programCollegeName !== '')
                            <div class="st-input st-input-readonly">{{ $programCollegeName }}</div>
                        @else
                            <select class="st-input @error('collegeId') error @enderror" wire:model="collegeId">
                                <option value="">Select college</option>
                                @foreach($colleges as $college)
                                    <option value="{{ $college->id }}">{{ $college->college_name }}</option>
                                @endforeach
                            </select>
                        @endif
                        @error('collegeId') <div class="field-error">{{ $message }}</div> @enderror
                    </div>
                    <div class="st-field">
                        <label class="st-label">Program Name</label>
                        <input type="text" class="st-input @error('programName') error @enderror" wire:model="programName">
                        @error('programName') <div class="field-error">{{ $message }}</div> @enderror
                    </div>
                    <div class="st-field">
                        <label class="st-label">Code</label>
                        <input type="text" class="st-input @error('programCode') error @enderror" wire:model="programCode">
                        @error('programCode') <div class="field-error">{{ $message }}</div> @enderror
                    </div>
                @elseif($modalKind === 'semester')
                    <div class="st-field">
                        <label class="st-label">Semester Name</label>
                        <input type="text" class="st-input @error('semesterName') error @enderror" wire:model="semesterName">
                        @error('semesterName') <div class="field-error">{{ $message }}</div> @enderror
                    </div>
                @elseif($modalKind === 'schoolYear')
                    <div class="st-field">
                        <label class="st-label">School Year</label>
                        <input type="text" class="st-input @error('schoolYear') error @enderror" wire:model="schoolYear" placeholder="e.g. 2025-2026">
                        @error('schoolYear') <div class="field-error">{{ $message }}</div> @enderror
                    </div>
                    @if(\Illuminate\Support\Facades\Schema::hasColumn('dcs_school_years', 'is_active'))
                    <div class="st-field" style="margin-top:12px;">
                        <label class="st-check">
                            <input type="checkbox" wire:model="schoolYearIsActive">
                            <span>Set as the active school year (office intake will only offer this year)</span>
                        </label>
                    </div>
                    @endif
                @elseif($modalKind === 'programCourse')
                    @php
                        $programOptions = $programs;
                        $semesterOptions = $semesters;
                        $yearLevelOptions = \App\Helpers\CourseTypeRules::allowedYearLevels($courseType !== '' ? $courseType : 'Major');
                        $hasCourseSchoolYear = \Illuminate\Support\Facades\Schema::hasColumn('dcs_program_courses', 'school_year_id');
                        // Keep the current program/semester selectable even if it was soft-filtered out of the catalog.
                        if ($editingId && $programId !== '' && ! $programOptions->contains(fn ($p) => (string) $p->id === (string) $programId)) {
                            $orphanProgram = \Illuminate\Support\Facades\DB::table('dcs_programs as p')
                                ->leftJoin('dcs_colleges as c', 'c.id', '=', 'p.college_id')
                                ->where('p.id', (int) $programId)
                                ->first(['p.id', 'p.program_name', 'p.program_code', 'p.college_id', 'c.college_name']);
                            if ($orphanProgram) {
                                $programOptions = $programOptions->prepend($orphanProgram)->values();
                            }
                        }
                        if ($editingId && $semesterId !== '' && ! $semesterOptions->contains(fn ($s) => (string) $s->id === (string) $semesterId)) {
                            $orphanSemester = \Illuminate\Support\Facades\DB::table('dcs_semesters')
                                ->where('id', (int) $semesterId)
                                ->first(['id', 'semester_name']);
                            if ($orphanSemester) {
                                $semesterOptions = $semesterOptions->prepend($orphanSemester)->values();
                            }
                        }
                    @endphp
                    <div class="st-field">
                        <label class="st-label">College / Program</label>
                        <select class="st-input @error('programId') error @enderror" wire:model.live="programId">
                            <option value="" disabled @selected($programId === '')>Select college / program</option>
                            @foreach($colleges as $college)
                                @php
                                    $collegePrograms = $programOptions
                                        ->filter(fn ($p) => (string) ($p->college_id ?? '') === (string) $college->id)
                                        ->values();
                                @endphp
                                @if($collegePrograms->isNotEmpty())
                                    <optgroup label="{{ $college->college_name }}">
                                        @foreach($collegePrograms as $prog)
                                            <option value="{{ $prog->id }}">{{ $prog->program_name }}{{ $prog->program_code ? ' (' . $prog->program_code . ')' : '' }}</option>
                                        @endforeach
                                    </optgroup>
                                @endif
                            @endforeach
                            @php
                                $collegeIds = $colleges->map(fn ($c) => (string) $c->id)->all();
                                $orphanPrograms = $programOptions->filter(
                                    fn ($p) => ! in_array((string) ($p->college_id ?? ''), $collegeIds, true)
                                );
                            @endphp
                            @if($orphanPrograms->isNotEmpty())
                                <optgroup label="Other">
                                    @foreach($orphanPrograms as $prog)
                                        <option value="{{ $prog->id }}">{{ $prog->college_name ?? '—' }} — {{ $prog->program_name }}</option>
                                    @endforeach
                                </optgroup>
                            @endif
                        </select>
                        @error('programId') <div class="field-error">{{ $message }}</div> @enderror
                    </div>
                    @if($hasCourseSchoolYear)
                    <div class="st-field">
                        <label class="st-label">School Year</label>
                        <select class="st-input @error('courseSchoolYearId') error @enderror" wire:model="courseSchoolYearId">
                            <option value="" disabled @selected($courseSchoolYearId === '')>Select school year</option>
                            @foreach($schoolYears as $sy)
                                <option value="{{ $sy->id }}">{{ $sy->school_year }}@if(!empty($sy->is_active)) (active)@endif</option>
                            @endforeach
                        </select>
                        @error('courseSchoolYearId') <div class="field-error">{{ $message }}</div> @enderror
                    </div>
                    @endif
                    <div class="st-field">
                        <label class="st-label">Semester</label>
                        <select class="st-input @error('semesterId') error @enderror" wire:model="semesterId">
                            <option value="" disabled @selected($semesterId === '')>Select semester</option>
                            @foreach($semesterOptions as $sem)
                                <option value="{{ $sem->id }}">{{ $sem->semester_name }}</option>
                            @endforeach
                        </select>
                        @error('semesterId') <div class="field-error">{{ $message }}</div> @enderror
                    </div>

                    @if($editingId)
                        <div class="st-field">
                            <label class="st-label">Course Type</label>
                            <select class="st-input @error('courseType') error @enderror" wire:model.live="courseType">
                                <option value="" disabled @selected($courseType === '')>Select course type</option>
                                <option value="GE Courses">GE Courses</option>
                                <option value="PE Courses">PE Courses</option>
                                <option value="NSTP">NSTP</option>
                                <option value="Major">Major</option>
                            </select>
                            @error('courseType') <div class="field-error">{{ $message }}</div> @enderror
                        </div>
                        <div class="st-field">
                            <label class="st-label">Year Level</label>
                            <select class="st-input @error('courseYearLevel') error @enderror" wire:model="courseYearLevel">
                                <option value="" disabled @selected($courseYearLevel === '')>Select year level</option>
                                @foreach($yearLevelOptions as $yearOpt)
                                    <option value="{{ $yearOpt }}">{{ $yearOpt }}</option>
                                @endforeach
                            </select>
                            @error('courseYearLevel') <div class="field-error">{{ $message }}</div> @enderror
                        </div>
                        <div class="st-field">
                            <label class="st-label">Course Code</label>
                            <input type="text" class="st-input @error('courseCode') error @enderror" wire:model="courseCode" placeholder="e.g. CS 101">
                            @error('courseCode') <div class="field-error">{{ $message }}</div> @enderror
                        </div>
                        <div class="st-field">
                            <label class="st-label">Course Name</label>
                            <input type="text" class="st-input @error('courseName') error @enderror" wire:model="courseName">
                            @error('courseName') <div class="field-error">{{ $message }}</div> @enderror
                        </div>
                    @else
                        <div class="st-field">
                            <label class="st-label">Courses</label>
                            <p class="st-faculty-hint" style="margin-top:0;">Pick college/program{{ $hasCourseSchoolYear ? ', school year' : '' }}, and semester once. Mix GE, PE, NSTP, and Major in the same list — set type and year level on each row.</p>
                            <div class="st-course-rows st-course-rows--mixed">
                                <div class="st-course-row st-course-row--mixed st-course-row-head">
                                    <span>Type</span>
                                    <span>Year</span>
                                    <span>Code</span>
                                    <span>Course name</span>
                                    <span></span>
                                </div>
                                @foreach($courseRows as $i => $row)
                                    @php
                                        $rowType = trim((string) ($row['type'] ?? ''));
                                        $rowYearOptions = \App\Helpers\CourseTypeRules::allowedYearLevels($rowType !== '' ? $rowType : null);
                                    @endphp
                                    <div class="st-course-row st-course-row--mixed" wire:key="course-row-{{ $i }}">
                                        <div>
                                            <select
                                                class="st-input @error('courseRows.'.$i.'.type') error @enderror"
                                                wire:model.live="courseRows.{{ $i }}.type"
                                                @if($i === 0) data-course-first-input @endif
                                            >
                                                <option value="" disabled @selected($rowType === '')>Type</option>
                                                <option value="GE Courses">GE</option>
                                                <option value="PE Courses">PE</option>
                                                <option value="NSTP">NSTP</option>
                                                <option value="Major">Major</option>
                                            </select>
                                            @error('courseRows.'.$i.'.type') <div class="field-error">{{ $message }}</div> @enderror
                                        </div>
                                        <div>
                                            <select
                                                class="st-input @error('courseRows.'.$i.'.year_level') error @enderror"
                                                wire:model="courseRows.{{ $i }}.year_level"
                                                @disabled($rowType === '')
                                            >
                                                <option value="" disabled @selected(trim((string) ($row['year_level'] ?? '')) === '')>Year</option>
                                                @foreach($rowYearOptions as $yearOpt)
                                                    <option value="{{ $yearOpt }}">{{ $yearOpt }}</option>
                                                @endforeach
                                            </select>
                                            @error('courseRows.'.$i.'.year_level') <div class="field-error">{{ $message }}</div> @enderror
                                        </div>
                                        <div>
                                            <input
                                                type="text"
                                                class="st-input @error('courseRows.'.$i.'.code') error @enderror"
                                                wire:model="courseRows.{{ $i }}.code"
                                                placeholder="e.g. CS 101"
                                            >
                                            @error('courseRows.'.$i.'.code') <div class="field-error">{{ $message }}</div> @enderror
                                        </div>
                                        <div>
                                            <input
                                                type="text"
                                                class="st-input @error('courseRows.'.$i.'.name') error @enderror"
                                                wire:model="courseRows.{{ $i }}.name"
                                                placeholder="Course name"
                                                wire:keydown.enter.prevent="addCourseRow"
                                            >
                                            @error('courseRows.'.$i.'.name') <div class="field-error">{{ $message }}</div> @enderror
                                        </div>
                                        <button
                                            type="button"
                                            class="icon-btn icon-btn-danger"
                                            title="Remove row"
                                            wire:click="removeCourseRow({{ $i }})"
                                            @disabled(count($courseRows) <= 1)
                                        >
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </div>
                                @endforeach
                            </div>
                            <button type="button" class="st-add-row-btn" wire:click="addCourseRow">
                                <i class="fa-solid fa-plus"></i> Add another course
                            </button>
                            @error('courseRows') <div class="field-error">{{ $message }}</div> @enderror
                        </div>
                    @endif
                    <p class="st-faculty-hint">Add GE, PE, NSTP, and Major for this program and semester in one save. Faculty is chosen during Syllabi / TOS registration.</p>
                @elseif($modalKind === 'programCoursesBulk')
                    <p class="st-faculty-hint" style="margin-top:0;">Pick a college and program, then set year level and course type for every course in one save. Use fill blanks if most rows are still empty.</p>
                    <div class="st-bulk-filters">
                        <div class="st-field">
                            <label class="st-label">College</label>
                            <select class="st-input @error('bulkCollegeId') error @enderror" wire:model.live="bulkCollegeId">
                                <option value="">Select college</option>
                                @foreach($colleges as $college)
                                    <option value="{{ $college->id }}">{{ $college->college_name }}</option>
                                @endforeach
                            </select>
                            @error('bulkCollegeId') <div class="field-error">{{ $message }}</div> @enderror
                        </div>
                        <div class="st-field">
                            <label class="st-label">Program</label>
                            <select class="st-input @error('bulkProgramId') error @enderror" wire:model.live="bulkProgramId" @disabled($bulkCollegeId === '')>
                                <option value="">Select program</option>
                                @foreach($programs as $prog)
                                    @if((string) $prog->college_id === (string) $bulkCollegeId)
                                        <option value="{{ $prog->id }}">{{ $prog->program_name }}{{ $prog->program_code ? ' (' . $prog->program_code . ')' : '' }}</option>
                                    @endif
                                @endforeach
                            </select>
                            @error('bulkProgramId') <div class="field-error">{{ $message }}</div> @enderror
                        </div>
                    </div>
                    @if($bulkRows !== [])
                        <div class="st-bulk-apply">
                            <select class="st-input" wire:model="bulkFillSemester">
                                <option value="">Semester…</option>
                                @foreach($semesters as $sem)
                                    <option value="{{ $sem->id }}">{{ $sem->semester_name }}</option>
                                @endforeach
                            </select>
                            <select class="st-input" wire:model="bulkFillYear">
                                <option value="">Year level…</option>
                                <option value="1st Year">1st Year</option>
                                <option value="2nd Year">2nd Year</option>
                                <option value="3rd Year">3rd Year</option>
                                <option value="4th Year">4th Year</option>
                                <option value="5th Year">5th Year</option>
                            </select>
                            <select class="st-input" wire:model="bulkFillType">
                                <option value="">Course type…</option>
                                <option value="GE Courses">GE Courses</option>
                                <option value="PE Courses">PE Courses</option>
                                <option value="NSTP">NSTP</option>
                                <option value="Major">Major</option>
                            </select>
                            <button type="button" class="st-btn st-btn-ghost" wire:click="applyBulkFill(true)">Fill blanks</button>
                            <button type="button" class="st-btn st-btn-ghost" wire:click="applyBulkFill(false)">Apply to all</button>
                        </div>
                        <div class="st-bulk-table-wrap">
                            @if($errors->has('bulkRows') || $errors->has('bulkCollegeId') || $errors->has('bulkProgramId') || collect($errors->getMessages())->keys()->contains(fn ($k) => str_starts_with((string) $k, 'bulkRows.')))
                                <div class="ofi-alert err" style="margin:0 0 12px;">
                                    Could not save. Fix the highlighted fields below
                                    (year level, course type, code, and name are required on every row).
                                </div>
                            @endif
                            @php
                                $bulkHasSchoolYear = \Illuminate\Support\Facades\Schema::hasColumn('dcs_program_courses', 'school_year_id');
                            @endphp
                            <table class="st-bulk-table">
                                <thead>
                                    <tr>
                                        @if($bulkHasSchoolYear)<th>School Year</th>@endif
                                        <th>Semester</th>
                                        <th>Year Level</th>
                                        <th>Course Type</th>
                                        <th>Code</th>
                                        <th>Course Name</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($bulkRows as $i => $row)
                                        <tr wire:key="bulk-course-{{ $row['id'] }}-{{ $i }}">
                                            @if($bulkHasSchoolYear)
                                            <td data-label="School Year">
                                                <select class="st-input @error('bulkRows.'.$i.'.school_year_id') error @enderror" wire:model.live="bulkRows.{{ $i }}.school_year_id">
                                                    <option value="">School year</option>
                                                    @foreach($schoolYears as $sy)
                                                        <option value="{{ $sy->id }}">{{ $sy->school_year }}</option>
                                                    @endforeach
                                                </select>
                                                @error('bulkRows.'.$i.'.school_year_id') <div class="field-error">{{ $message }}</div> @enderror
                                            </td>
                                            @endif
                                            <td data-label="Semester">
                                                <input type="hidden" wire:model="bulkRows.{{ $i }}.id">
                                                <select class="st-input @error('bulkRows.'.$i.'.semester_id') error @enderror" wire:model.live="bulkRows.{{ $i }}.semester_id">
                                                    <option value="">Semester</option>
                                                    @foreach($semesters as $sem)
                                                        <option value="{{ $sem->id }}">{{ $sem->semester_name }}</option>
                                                    @endforeach
                                                </select>
                                                @error('bulkRows.'.$i.'.semester_id') <div class="field-error">{{ $message }}</div> @enderror
                                            </td>
                                            <td data-label="Year Level">
                                                <select class="st-input @error('bulkRows.'.$i.'.year_level') error @enderror" wire:model.live="bulkRows.{{ $i }}.year_level">
                                                    <option value="">Year</option>
                                                    <option value="1st Year">1st Year</option>
                                                    <option value="2nd Year">2nd Year</option>
                                                    <option value="3rd Year">3rd Year</option>
                                                    <option value="4th Year">4th Year</option>
                                                    <option value="5th Year">5th Year</option>
                                                </select>
                                                @error('bulkRows.'.$i.'.year_level') <div class="field-error">{{ $message }}</div> @enderror
                                            </td>
                                            <td data-label="Course Type">
                                                <select class="st-input @error('bulkRows.'.$i.'.course_type') error @enderror" wire:model.live="bulkRows.{{ $i }}.course_type">
                                                    <option value="">Type</option>
                                                    <option value="GE Courses">GE Courses</option>
                                                    <option value="PE Courses">PE Courses</option>
                                                    <option value="NSTP">NSTP</option>
                                                    <option value="Major">Major</option>
                                                </select>
                                                @error('bulkRows.'.$i.'.course_type') <div class="field-error">{{ $message }}</div> @enderror
                                            </td>
                                            <td data-label="Code">
                                                <input type="text" class="st-input @error('bulkRows.'.$i.'.code') error @enderror" wire:model.blur="bulkRows.{{ $i }}.code">
                                                @error('bulkRows.'.$i.'.code') <div class="field-error">{{ $message }}</div> @enderror
                                            </td>
                                            <td data-label="Course Name">
                                                <input type="text" class="st-input @error('bulkRows.'.$i.'.name') error @enderror" wire:model.blur="bulkRows.{{ $i }}.name">
                                                @error('bulkRows.'.$i.'.name') <div class="field-error">{{ $message }}</div> @enderror
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @elseif($bulkProgramId !== '')
                        <p class="st-faculty-hint">No courses for this program yet. Use Add Courses to enter GE, PE, NSTP, and Major for a semester.</p>
                    @endif
                    @error('bulkRows') <div class="field-error">{{ $message }}</div> @enderror
                @endif

                <div class="st-actions-row">
                    <button type="button" class="st-btn st-btn-ghost" x-on:click="dismiss()">{{ $modalKind === 'programCourse' && ! $editingId ? 'Done' : 'Cancel' }}</button>
                    <button type="submit" class="st-btn st-btn-primary" wire:loading.attr="disabled" @disabled($modalKind === 'programCoursesBulk' && $bulkRows === [])>
                        <i class="fa-solid fa-check"></i>
                        @if(str_starts_with($modalKind, 'import'))
                            Import
                        @elseif($modalKind === 'programCourse' && ! $editingId)
                            Save courses
                        @elseif($modalKind === 'programCoursesBulk')
                            Save all
                        @else
                            Save
                        @endif
                    </button>
                </div>
            </form>
        @endif
    </div>
</div>
@endif

</div>

