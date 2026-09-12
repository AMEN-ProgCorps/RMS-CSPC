<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.dcs')] class extends Component {
    use WithFileUploads;

    public string $modalKind = '';
    public ?int $editingId = null;
    public ?int $parentId = null;

    public string $docTypeName = '';
    public string $originatorName = '';
    public string $facultyName = '';
    public string $collegeId = '';
    public string $collegeName = '';
    public string $officeId = '';
    public string $programName = '';
    public string $programCode = '';
    public string $semesterName = '';
    public string $schoolYear = '';
    public string $programId = '';
    public string $semesterId = '';
    public string $courseName = '';
    public string $courseCode = '';
    public string $courseYearLevel = '';
    /** @var list<array{code: string, name: string}> */
    public array $courseRows = [
        ['code' => '', 'name' => ''],
    ];
    public $csvFile = null;

    public string $deleteTitle = '';
    public string $deleteMessage = '';

    public function with(): array
    {
        return $this->catalog();
    }

    public function closeModal(): void
    {
        $this->editingId = null;
        $this->parentId = null;
        $this->modalKind = '';
        $this->reset([
            'docTypeName', 'originatorName',
            'facultyName', 'collegeId', 'collegeName', 'officeId', 'programName', 'programCode',
            'semesterName', 'schoolYear', 'programId', 'semesterId', 'courseName', 'courseCode', 'courseYearLevel',
            'deleteTitle', 'deleteMessage',
        ]);
        $this->courseRows = [['code' => '', 'name' => '']];
        $this->csvFile = null;
        $this->resetValidation();
        $this->dispatch('settings-close-modal');
    }

    public function openDocType(?int $id = null, ?int $parentId = null): void
    {
        $this->resetFormFor('docType', $id);
        $this->parentId = $parentId;
        if ($id) {
            $row = DB::table('dcs_doc_types')->where('id', $id)->first();
            abort_unless($row, 404);
            $this->docTypeName = $row->doc_type_name;
            $this->parentId = $row->parent_id ? (int) $row->parent_id : null;
        }
    }

    public function openSubType(int $parentId): void
    {
        $this->openDocType(null, $parentId);
    }

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

    public function openFaculty(?int $id = null): void
    {
        $this->resetFormFor('faculty', $id);
        if ($id) {
            $row = DB::table('dcs_faculties')->where('id', $id)->first();
            abort_unless($row, 404);
            $this->facultyName = $row->faculty_name;
            $this->collegeId = $row->college_id !== null ? (string) $row->college_id : '';
        }
    }

    public function openCollege(?int $id = null): void
    {
        $this->resetFormFor('college', $id);
        $this->officeId = '';
        $this->collegeName = '';
        if ($id) {
            $row = DB::table('dcs_colleges')->where('id', $id)->first();
            abort_unless($row, 404);
            $this->collegeName = $row->college_name;
            $this->officeId = $row->office_id !== null ? (string) $row->office_id : '';
        }
    }

    public function updatedOfficeId(?string $value): void
    {
        if ($this->modalKind !== 'college' || ! $value) {
            return;
        }
        $office = $value ? DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office')->where('id', (int) $value)->first() : null;
        $this->collegeName = $office->office_name ?? '';
    }

    public function openProgram(?int $id = null): void
    {
        $this->resetFormFor('program', $id);
        if ($id) {
            $row = DB::table('dcs_programs')->where('id', $id)->first();
            abort_unless($row, 404);
            $this->collegeId = (string) $row->college_id;
            $this->programName = $row->program_name;
            $this->programCode = $row->program_code ?? '';
        }
    }

    public function openSemester(?int $id = null): void
    {
        $this->resetFormFor('semester', $id);
        if ($id) {
            $row = DB::table('dcs_semesters')->where('id', $id)->first();
            abort_unless($row, 404);
            $this->semesterName = $row->semester_name;
        }
    }

    public function openSchoolYear(?int $id = null): void
    {
        $this->resetFormFor('schoolYear', $id);
        if ($id) {
            $row = DB::table('dcs_school_years')->where('id', $id)->first();
            abort_unless($row, 404);
            $this->schoolYear = $row->school_year;
        }
    }

    public function openProgramCourse(?int $id = null): void
    {
        $this->resetFormFor('programCourse', $id);
        $this->courseRows = [['code' => '', 'name' => '']];
        $this->courseName = '';
        $this->courseCode = '';
        if ($id) {
            $row = DB::table('dcs_program_courses')->where('id', $id)->first();
            abort_unless($row, 404);
            $this->programId = (string) $row->program_id;
            $this->semesterId = (string) $row->semester_id;
            $this->courseYearLevel = $row->year_level ?? '';
            $this->courseName = $row->course_name;
            $this->courseCode = $row->course_code ?? '';
        }
    }

    public function addCourseRow(): void
    {
        $this->courseRows[] = ['code' => '', 'name' => ''];
        $this->dispatch('settings-focus-last-course-row');
    }

    public function removeCourseRow(int $index): void
    {
        if (count($this->courseRows) <= 1) {
            $this->courseRows = [['code' => '', 'name' => '']];
            return;
        }
        unset($this->courseRows[$index]);
        $this->courseRows = array_values($this->courseRows);
    }

    public function openImportOriginators(): void
    {
        abort_unless(Schema::hasTable('dcs_originators'), 404);
        $this->csvFile = null;
        $this->resetFormFor('importOriginators', null);
    }

    public function openImportFaculties(): void
    {
        $this->csvFile = null;
        $this->resetFormFor('importFaculties', null);
    }

    public function runCsvImport(): void
    {
        \App\Helpers\RegisterQueryHelper::assertFullDcsUser('settings');
        match ($this->modalKind) {
            'importOriginators' => $this->importOriginatorsFromCsv(),
            'importFaculties' => $this->importFacultiesFromCsv(),
            default => null,
        };
    }

    public function confirmDelete(string $kind, int $id, string $title, string $message): void
    {
        $this->resetFormFor('delete', $id);
        $this->deleteTitle = $title;
        $this->deleteMessage = $message;
        $this->modalKind = $kind . ':delete';
    }

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
            default => null,
        };
    }

    public function destroy(): void
    {
        \App\Helpers\RegisterQueryHelper::assertFullDcsUser('settings');
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
            default => null,
        };
    }

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

        if ($this->editingId) {
            DB::table('dcs_doc_types')->where('id', $this->editingId)->update(['doc_type_name' => $this->docTypeName]);
            $this->done('Updated successfully.');
            return;
        }

        DB::table('dcs_doc_types')->insert([
            'doc_type_name' => $this->docTypeName,
            'parent_id' => $parentId,
        ]);
        $this->done($parentId ? 'Sub-type added.' : 'Document type added.');
    }

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
            if (Schema::hasColumn('dcs_masterlist_registration', 'originator_id')) {
                DB::table('dcs_masterlist_registration')
                    ->where('originator_id', $this->editingId)
                    ->update(['originator_name' => $this->originatorName]);
            }
            $this->done('Originator updated.');
            return;
        }

        DB::table('dcs_originators')->insert(['originator_name' => $this->originatorName]);
        $this->done('Originator added.');
    }

    private function saveFaculty(): void
    {
        $collegeId = $this->collegeId !== '' ? (int) $this->collegeId : null;
        $this->validate([
            'facultyName' => 'required|string|max:255',
            'collegeId' => 'nullable|integer|exists:dcs_colleges,id',
        ]);

        $existsQ = DB::table('dcs_faculties')
            ->where('faculty_name', $this->facultyName)
            ->where('college_id', $collegeId)
            ->when($this->editingId, fn ($q) => $q->where('id', '!=', $this->editingId));
        \App\Helpers\SettingsRecycleHelper::applyNotDeleted($existsQ, 'dcs_faculties');
        if ($existsQ->exists()) {
            $this->fail('A faculty with this name already exists in the selected college.');
            return;
        }

        $payload = ['faculty_name' => $this->facultyName, 'college_id' => $collegeId];
        if ($this->editingId) {
            DB::table('dcs_faculties')->where('id', $this->editingId)->update($payload);
            if (Schema::hasTable('dcs_syllabi_drf') && Schema::hasColumn('dcs_syllabi_drf', 'faculty_id')) {
                DB::table('dcs_syllabi_drf')
                    ->where('faculty_id', $this->editingId)
                    ->update(['faculty_name' => $this->facultyName]);
            }
            $this->done('Faculty updated.');
            return;
        }

        DB::table('dcs_faculties')->insert($payload);
        $this->done('Faculty added.');
    }

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

            $collegeId = null;
            if ($collegeName !== '' && ! $this->isCsvHeaderValue($collegeName, ['college', 'college_name'])) {
                $collegeId = $collegeMap[mb_strtolower($collegeName)] ?? null;
                if ($collegeId === null) {
                    $skipped++;
                    continue;
                }
            }

            $existsQ = DB::table('dcs_faculties')
                ->where('faculty_name', $name)
                ->where('college_id', $collegeId);
            \App\Helpers\SettingsRecycleHelper::applyNotDeleted($existsQ, 'dcs_faculties');
            if ($existsQ->exists()) {
                $skipped++;
                continue;
            }

            try {
                DB::table('dcs_faculties')->insert([
                    'faculty_name' => $name,
                    'college_id' => $collegeId,
                ]);
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

    private function csvImportSummary(string $singular, int $added, int $skipped): string
    {
        $plural = $singular === 'faculty' ? 'faculties' : ($singular . 's');
        $msg = $added === 1 ? "1 {$singular} imported." : "{$added} {$plural} imported.";
        if ($skipped > 0) {
            $msg .= " {$skipped} skipped (duplicate, unknown college, or invalid).";
        }

        return $msg;
    }

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

        DB::table('dcs_colleges')->insert($payload);
        $this->done('College added. You can add programs under it in the Programs tab.');
    }

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

    private function saveSchoolYear(): void
    {
        $this->validate([
            'schoolYear' => [
                'required', 'string', 'max:50',
                \App\Helpers\SettingsRecycleHelper::uniqueRule('dcs_school_years', 'school_year', $this->editingId),
            ],
        ]);

        if ($this->editingId) {
            DB::table('dcs_school_years')->where('id', $this->editingId)->update(['school_year' => $this->schoolYear]);
            $this->done('School year updated.');
            return;
        }

        DB::table('dcs_school_years')->insert(['school_year' => $this->schoolYear]);
        $this->done('School year added.');
    }

    private function saveProgramCourse(): void
    {
        $hasCourseCode = Schema::hasColumn('dcs_program_courses', 'course_code');
        $hasYearLevel = Schema::hasColumn('dcs_program_courses', 'year_level');

        if ($this->editingId) {
            $this->saveSingleProgramCourse($hasCourseCode, $hasYearLevel);
            return;
        }

        $this->saveBulkProgramCourses($hasCourseCode, $hasYearLevel);
    }

    private function saveSingleProgramCourse(bool $hasCourseCode, bool $hasYearLevel): void
    {
        $rules = [
            'programId' => 'required|integer|exists:dcs_programs,id',
            'semesterId' => 'required|integer|exists:dcs_semesters,id',
            'courseName' => 'required|string|max:255',
        ];
        if ($hasYearLevel) {
            $rules['courseYearLevel'] = 'required|string|max:50|in:1st Year,2nd Year,3rd Year,4th Year,5th Year';
        }
        if ($hasCourseCode) {
            $rules['courseCode'] = 'required|string|max:50';
        }
        $this->validate($rules);

        $yearLevel = $hasYearLevel ? $this->courseYearLevel : null;

        if ($this->programCourseNameTaken((int) $this->programId, (int) $this->semesterId, $this->courseName, $yearLevel, $hasYearLevel, (int) $this->editingId)) {
            $this->fail('This course is already listed for the selected program, semester, and year level.');
            return;
        }

        if ($hasCourseCode && $this->programCourseCodeTaken((int) $this->programId, (int) $this->semesterId, $this->courseCode, $yearLevel, $hasYearLevel, (int) $this->editingId)) {
            $this->fail('This course code is already used for the selected program, semester, and year level.');
            return;
        }

        $payload = [
            'program_id' => (int) $this->programId,
            'semester_id' => (int) $this->semesterId,
            'course_name' => $this->courseName,
        ];
        if ($hasYearLevel) {
            $payload['year_level'] = $yearLevel;
        }
        if ($hasCourseCode) {
            $payload['course_code'] = $this->courseCode;
        }

        DB::table('dcs_program_courses')->where('id', (int) $this->editingId)->update($payload);
        $this->done('Course updated.');
    }

    private function saveBulkProgramCourses(bool $hasCourseCode, bool $hasYearLevel): void
    {
        $rules = [
            'programId' => 'required|integer|exists:dcs_programs,id',
            'semesterId' => 'required|integer|exists:dcs_semesters,id',
            'courseRows' => 'required|array|min:1',
            'courseRows.*.name' => 'nullable|string|max:255',
            'courseRows.*.code' => 'nullable|string|max:50',
        ];
        if ($hasYearLevel) {
            $rules['courseYearLevel'] = 'required|string|max:50|in:1st Year,2nd Year,3rd Year,4th Year,5th Year';
        }
        $this->validate($rules);

        $yearLevel = $hasYearLevel ? $this->courseYearLevel : null;
        $programId = (int) $this->programId;
        $semesterId = (int) $this->semesterId;

        $entries = [];
        foreach ($this->courseRows as $i => $row) {
            $name = trim((string) ($row['name'] ?? ''));
            $code = trim((string) ($row['code'] ?? ''));
            if ($name === '' && $code === '') {
                continue;
            }
            if ($name === '') {
                $this->addError("courseRows.{$i}.name", 'Course name is required.');
                continue;
            }
            if ($hasCourseCode && $code === '') {
                $this->addError("courseRows.{$i}.code", 'Course code is required.');
                continue;
            }
            $entries[] = ['index' => $i, 'name' => $name, 'code' => $code];
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
            $nameKey = mb_strtolower($entry['name']);
            if (isset($seenNames[$nameKey])) {
                $this->addError("courseRows.{$entry['index']}.name", 'Duplicate course name in this list.');
                continue;
            }
            $seenNames[$nameKey] = true;

            if ($hasCourseCode) {
                $codeKey = mb_strtolower($entry['code']);
                if (isset($seenCodes[$codeKey])) {
                    $this->addError("courseRows.{$entry['index']}.code", 'Duplicate course code in this list.');
                    continue;
                }
                $seenCodes[$codeKey] = true;
            }
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        foreach ($entries as $entry) {
            if ($this->programCourseNameTaken($programId, $semesterId, $entry['name'], $yearLevel, $hasYearLevel)) {
                $this->addError("courseRows.{$entry['index']}.name", 'Already listed for this program, semester, and year level.');
            }
            if ($hasCourseCode && $this->programCourseCodeTaken($programId, $semesterId, $entry['code'], $yearLevel, $hasYearLevel)) {
                $this->addError("courseRows.{$entry['index']}.code", 'Code already used for this program, semester, and year level.');
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
                $payload['year_level'] = $yearLevel;
            }
            if ($hasCourseCode) {
                $payload['course_code'] = $entry['code'];
            }
            DB::table('dcs_program_courses')->insert($payload);
        }

        $count = count($entries);
        $this->courseRows = [['code' => '', 'name' => '']];
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
        $this->dispatch('settings-focus-course-row');
    }

    private function programCourseNameTaken(
        int $programId,
        int $semesterId,
        string $courseName,
        ?string $yearLevel,
        bool $hasYearLevel,
        ?int $exceptId = null
    ): bool {
        $q = DB::table('dcs_program_courses')
            ->where('program_id', $programId)
            ->where('semester_id', $semesterId)
            ->where('course_name', $courseName)
            ->when($hasYearLevel, fn ($query) => $query->where('year_level', $yearLevel))
            ->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId));
        \App\Helpers\SettingsRecycleHelper::applyNotDeleted($q, 'dcs_program_courses');

        return $q->exists();
    }

    private function programCourseCodeTaken(
        int $programId,
        int $semesterId,
        string $courseCode,
        ?string $yearLevel,
        bool $hasYearLevel,
        ?int $exceptId = null
    ): bool {
        $q = DB::table('dcs_program_courses')
            ->where('program_id', $programId)
            ->where('semester_id', $semesterId)
            ->where('course_code', $courseCode)
            ->when($hasYearLevel, fn ($query) => $query->where('year_level', $yearLevel))
            ->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId));
        \App\Helpers\SettingsRecycleHelper::applyNotDeleted($q, 'dcs_program_courses');

        return $q->exists();
    }

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
            $this->done('Document type moved to Recycle Bin.');
            return;
        }
        DB::table('dcs_doc_types')->where('id', $id)->delete();
        $this->done('Deleted successfully.');
    }

    private function destroyOriginator(int $id): void
    {
        if (! Schema::hasTable('dcs_originators')) {
            $this->fail('Originators table is not available. Run pending migrations.');
            return;
        }
        if (\App\Helpers\SettingsRecycleHelper::supports('dcs_originators')) {
            \App\Helpers\SettingsRecycleHelper::softDelete('originator', $id);
            $this->done('Originator moved to Recycle Bin.');
            return;
        }
        DB::table('dcs_originators')->where('id', $id)->delete();
        $this->done('Originator deleted.');
    }

    private function destroyFaculty(int $id): void
    {
        if (DB::table('dcs_syllabi_drf')->where('faculty_id', $id)->exists()) {
            $this->fail('This faculty is referenced by syllabi DRF records and cannot be deleted.');
            return;
        }
        if (\App\Helpers\SettingsRecycleHelper::supports('dcs_faculties')) {
            \App\Helpers\SettingsRecycleHelper::softDelete('faculty', $id);
            $this->done('Faculty moved to Recycle Bin.');
            return;
        }
        DB::table('dcs_faculties')->where('id', $id)->delete();
        $this->done('Faculty deleted.');
    }

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
            $this->done('College moved to Recycle Bin.');
            return;
        }
        DB::table('dcs_colleges')->where('id', $id)->delete();
        $this->done('College deleted.');
    }

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
            $this->done('Program moved to Recycle Bin.');
            return;
        }
        DB::table('dcs_programs')->where('id', $id)->delete();
        $this->done('Program deleted.');
    }

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
            $this->done('Semester moved to Recycle Bin.');
            return;
        }
        DB::table('dcs_semesters')->where('id', $id)->delete();
        $this->done('Semester deleted.');
    }

    private function destroySchoolYear(int $id): void
    {
        if (DB::table('dcs_syllabi')->where('school_year_id', $id)->exists()) {
            $this->fail('This school year is referenced by syllabi and cannot be deleted.');
            return;
        }
        if (\App\Helpers\SettingsRecycleHelper::supports('dcs_school_years')) {
            \App\Helpers\SettingsRecycleHelper::softDelete('schoolYear', $id);
            $this->done('School year moved to Recycle Bin.');
            return;
        }
        DB::table('dcs_school_years')->where('id', $id)->delete();
        $this->done('School year deleted.');
    }

    private function destroyProgramCourse(int $id): void
    {
        if (DB::table('dcs_syllabi')->where('course_id', $id)->exists()) {
            $this->fail('This course is referenced by syllabi and cannot be deleted.');
            return;
        }
        if (\App\Helpers\SettingsRecycleHelper::supports('dcs_program_courses')) {
            \App\Helpers\SettingsRecycleHelper::softDelete('programCourse', $id);
            $this->done('Course moved to Recycle Bin.');
            return;
        }
        DB::table('dcs_program_courses')->where('id', $id)->delete();
        $this->done('Course deleted.');
    }

    private function resetFormFor(string $kind, ?int $id): void
    {
        $this->resetValidation();
        $this->editingId = $id;
        $this->parentId = null;
        $this->modalKind = $kind;
        $this->dispatch('settings-open-modal');
    }

    private function done(string $message): void
    {
        \App\Helpers\RegisterPersistHelper::logAdminChange('DCS settings: ' . $message);
        $this->closeModal();
        $this->flashToast($message, 'success');
    }

    private function fail(string $message): void
    {
        $this->flashToast($message, 'error');
    }

    private function flashToast(string $message, string $type = 'success'): void
    {
        $this->dispatch('dcs-toast', message: $message, type: $type);
    }

    private function catalog(): array
    {
        $docTypeParentsQ = DB::table('dcs_doc_types')->whereNull('parent_id')->orderBy('doc_type_name');
        \App\Helpers\SettingsRecycleHelper::applyNotDeleted($docTypeParentsQ, 'dcs_doc_types');
        $docTypeParents = $docTypeParentsQ->get(['id', 'doc_type_name', 'parent_id']);

        $docTypeSubsQ = DB::table('dcs_doc_types')->whereNotNull('parent_id')->orderBy('doc_type_name');
        \App\Helpers\SettingsRecycleHelper::applyNotDeleted($docTypeSubsQ, 'dcs_doc_types');
        $docTypeSubs = $docTypeSubsQ->get(['id', 'doc_type_name', 'parent_id'])
            ->groupBy(fn ($row) => (string) $row->parent_id);

        $collegesQ = DB::table('dcs_colleges as c')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as o', 'o.id', '=', 'c.office_id')
            ->orderBy('c.college_code');
        \App\Helpers\SettingsRecycleHelper::applyNotDeleted($collegesQ, 'dcs_colleges', 'c');
        $colleges = $collegesQ->get(['c.id', 'c.college_code', 'c.college_name', 'c.office_id', 'o.office_name as office_name', 'o.office_code as office_code']);
        $linkedOfficeIds = $colleges->pluck('office_id')->filter()->map(fn ($id) => (int) $id);
        if ($this->modalKind === 'college' && $this->editingId) {
            $currentOfficeId = (int) (optional($colleges->firstWhere('id', $this->editingId))->office_id ?? 0);
            $linkedOfficeIds = $linkedOfficeIds->reject(fn ($id) => $id === $currentOfficeId);
        }
        $collegeOfficesQuery = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office')->where('is_active', true);
        \App\Helpers\RegisterQueryHelper::applySelectableOfficesFilter($collegeOfficesQuery);
        if ($linkedOfficeIds->isNotEmpty()) {
            $collegeOfficesQuery->whereNotIn('id', $linkedOfficeIds->values()->all());
        }
        $collegeOffices = $collegeOfficesQuery
            ->orderBy('office_name')
            ->get(['id', 'office_name', 'office_code']);
        if ($this->modalKind === 'college' && $this->officeId !== '') {
            $selectedOffice = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office')->where('id', (int) $this->officeId)->first(['id', 'office_name', 'office_code']);
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

        $hasCourseCode = Schema::hasColumn('dcs_program_courses', 'course_code');
        $hasYearLevel = Schema::hasColumn('dcs_program_courses', 'year_level');
        $courseCols = ['pc.id', 'pc.program_id', 'pc.semester_id', 'pc.course_name', 'p.program_name', 'c.college_name', 's.semester_name'];
        if ($hasYearLevel) {
            $courseCols[] = 'pc.year_level';
        }
        if ($hasCourseCode) {
            $courseCols[] = 'pc.course_code';
        }

        $programCoursesQ = DB::table('dcs_program_courses as pc')
            ->leftJoin('dcs_programs as p', 'p.id', '=', 'pc.program_id')
            ->leftJoin('dcs_colleges as c', 'c.id', '=', 'p.college_id')
            ->leftJoin('dcs_semesters as s', 's.id', '=', 'pc.semester_id')
            ->orderBy('pc.program_id')->orderBy('pc.semester_id');
        if ($hasYearLevel) {
            $programCoursesQ->orderBy('pc.year_level');
        }
        $programCoursesQ->orderBy('pc.course_name');
        \App\Helpers\SettingsRecycleHelper::applyNotDeleted($programCoursesQ, 'dcs_program_courses', 'pc');
        $programCourses = $programCoursesQ->get($courseCols);

        $originatorsQ = Schema::hasTable('dcs_originators')
            ? DB::table('dcs_originators')->orderBy('originator_name')
            : null;
        if ($originatorsQ) {
            \App\Helpers\SettingsRecycleHelper::applyNotDeleted($originatorsQ, 'dcs_originators');
        }

        $facultiesQ = DB::table('dcs_faculties as f')->leftJoin('dcs_colleges as c', 'c.id', '=', 'f.college_id')->orderBy('f.faculty_name');
        \App\Helpers\SettingsRecycleHelper::applyNotDeleted($facultiesQ, 'dcs_faculties', 'f');

        $semestersQ = DB::table('dcs_semesters')->orderBy('id');
        \App\Helpers\SettingsRecycleHelper::applyNotDeleted($semestersQ, 'dcs_semesters');

        $schoolYearsQ = DB::table('dcs_school_years')->orderBy('school_year');
        \App\Helpers\SettingsRecycleHelper::applyNotDeleted($schoolYearsQ, 'dcs_school_years');

        return [
            'docTypeParents' => $docTypeParents,
            'docTypeSubs' => $docTypeSubs,
            'originators' => $originatorsQ
                ? $originatorsQ->get(['id', 'originator_name'])
                : collect(),
            'faculties' => $facultiesQ->get(['f.id', 'f.faculty_name', 'f.college_id', 'c.college_name']),
            'colleges' => $colleges,
            'collegeOffices' => $collegeOffices,
            'programCounts' => $programCounts,
            'programs' => $programs,
            'programsByCollege' => $programs->groupBy(fn ($row) => (string) $row->college_id),
            'semesters' => $semestersQ->get(['id', 'semester_name']),
            'schoolYears' => $schoolYearsQ->get(['id', 'school_year']),
            'programCourses' => $programCourses,
        ];
    }

    private function officeIsActive(object $office): bool
    {
        $value = $office->is_active ?? false;
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 't', 'true', 'yes', 'on'], true);
    }

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

    private function docTypeIsInUse(int $id): bool
    {
        return DB::table('dcs_document_requests')->where(fn ($q) => $q->where('doc_type_id', $id)->orWhere('sub_type_id', $id))->exists();
    }
}; ?>

<div
    x-data="{
        tab: new URLSearchParams(window.location.search).get('tab') || sessionStorage.getItem('settingsActiveTab') || 'doctypes',
        modalOpen: false,
        init() {
            const allowed = ['doctypes','originators','faculties','colleges','programs','semesters','schoolyears','coursenames'];
            if (!allowed.includes(this.tab)) this.tab = 'doctypes';
            sessionStorage.setItem('settingsActiveTab', this.tab);
        },
        setTab(name) {
            this.tab = name;
            sessionStorage.setItem('settingsActiveTab', name);
        },
        openUi() {
            this.modalOpen = true;
            document.body.style.overflow = 'hidden';
        },
        closeUi() {
            this.modalOpen = false;
            document.body.style.overflow = '';
        },
        dismiss() {
            this.closeUi();
            $wire.closeModal();
        }
    }"
    @settings-open-modal.window="openUi()"
    @settings-close-modal.window="closeUi()"
    @settings-focus-course-row.window="$nextTick(() => document.querySelector('[data-course-first-input]')?.focus())"
    @settings-focus-last-course-row.window="$nextTick(() => {
        const rows = document.querySelectorAll('.st-course-row:not(.st-course-row-head) .st-input');
        rows[rows.length - 2]?.focus();
    })"
    @keydown.escape.window="if (modalOpen) dismiss()"
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
            'originators' => ['fa-user-pen', 'Originators'],
            'faculties' => ['fa-chalkboard-user', 'Faculty'],
            'colleges' => ['fa-graduation-cap', 'Colleges'],
            'programs' => ['fa-book-open', 'Programs'],
            'semesters' => ['fa-calendar-week', 'Semesters'],
            'schoolyears' => ['fa-calendar-days', 'School Years'],
            'coursenames' => ['fa-list-check', 'Course Names'],
        ] as $key => $meta)
            <button type="button" class="tab-btn" :class="tab === '{{ $key }}' && 'active'" @click="setTab('{{ $key }}')">
                <i class="fa-solid {{ $meta[0] }}"></i> {{ $meta[1] }}
            </button>
        @endforeach
    </div>

    <section class="tab-panel" x-show="tab === 'doctypes'" x-cloak>
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
                            <button type="button" class="icon-btn icon-btn-danger" title="Delete" wire:click="confirmDelete('docType', {{ $type->id }}, 'Delete Document Type', 'This document type will be moved to the DCS Recycle Bin.')"><i class="fa-solid fa-trash"></i></button>
                        </div>
                    </div>
                    @if(($docTypeSubs[(string) $type->id] ?? collect())->count())
                        <div class="doctype-subtypes">
                            @foreach($docTypeSubs[(string) $type->id] as $sub)
                                <div class="doctype-sub-row" wire:key="dts-{{ $sub->id }}" data-id="{{ $sub->id }}">
                                    <div class="doctype-name"><i class="fa-solid fa-turn-up fa-rotate-90"></i><span>{{ $sub->doc_type_name }}</span></div>
                                    <div class="row-actions">
                                        <button type="button" class="icon-btn" title="Edit" wire:click="openDocType({{ $sub->id }})"><i class="fa-solid fa-pen"></i></button>
                                        <button type="button" class="icon-btn icon-btn-danger" title="Delete" wire:click="confirmDelete('docType', {{ $sub->id }}, 'Delete Document Type', 'This document type will be moved to the DCS Recycle Bin.')"><i class="fa-solid fa-trash"></i></button>
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

    <section class="tab-panel" x-show="tab === 'originators'" x-cloak>
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
                                    <button type="button" class="icon-btn icon-btn-danger" title="Delete" wire:click="confirmDelete('originator', {{ $orig->id }}, 'Delete Originator', 'This originator will be moved to the DCS Recycle Bin.')"><i class="fa-solid fa-trash"></i></button>
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

    <section class="tab-panel" x-show="tab === 'faculties'" x-cloak>
        <div class="panel-toolbar">
            <span class="panel-subtitle">Manage faculty members per college</span>
            <div class="panel-actions">
                <button type="button" class="btn-secondary" wire:click="openImportFaculties()"><i class="fa-solid fa-file-csv"></i> Import CSV</button>
                <button type="button" class="btn-primary" wire:click="openFaculty()"><i class="fa-solid fa-plus"></i> Add Faculty</button>
            </div>
        </div>
        <div class="table-wrap">
            <table class="settings-table">
                <thead><tr><th>College</th><th>Faculty Name</th><th style="width:140px;">Actions</th></tr></thead>
                <tbody>
                    @forelse($faculties as $fac)
                        <tr wire:key="fc-{{ $fac->id }}" data-id="{{ $fac->id }}">
                            <td data-label="College">{{ $fac->college_name ?? '—' }}</td>
                            <td data-label="Faculty">{{ $fac->faculty_name }}</td>
                            <td>
                                <div class="row-actions">
                                    <button type="button" class="icon-btn" title="Edit" wire:click="openFaculty({{ $fac->id }})"><i class="fa-solid fa-pen"></i></button>
                                    <button type="button" class="icon-btn icon-btn-danger" title="Delete" wire:click="confirmDelete('faculty', {{ $fac->id }}, 'Delete Faculty', 'This faculty will be moved to the DCS Recycle Bin.')"><i class="fa-solid fa-trash"></i></button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="empty-cell">No faculties yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="tab-panel" x-show="tab === 'colleges'" x-cloak>
        <div class="panel-toolbar">
            <span class="panel-subtitle">Academic colleges for programs and syllabi. Link an office only when that office is actually a college (CCS, CEA, etc.).</span>
            <button type="button" class="btn-primary" wire:click="openCollege()"><i class="fa-solid fa-plus"></i> Add College</button>
        </div>
        <div class="table-wrap">
            <table class="settings-table">
                <thead><tr><th>College Name</th><th>Office</th><th style="width:100px;">Programs</th><th style="width:140px;">Actions</th></tr></thead>
                <tbody>
                    @forelse($colleges as $college)
                        <tr wire:key="cl-{{ $college->id }}" data-id="{{ $college->id }}">
                            <td data-label="College">{{ $college->college_name }}</td>
                            <td data-label="Office">
                                @if($college->office_code || $college->office_name)
                                    {{ $college->office_code ? $college->office_code : '' }}{{ $college->office_code && $college->office_name ? ' — ' : '' }}{{ $college->office_name }}
                                @else
                                    —
                                @endif
                            </td>
                            <td data-label="Programs">{{ $programCounts[$college->id] ?? 0 }}</td>
                            <td>
                                <div class="row-actions">
                                    <button type="button" class="icon-btn" title="Edit" wire:click="openCollege({{ $college->id }})"><i class="fa-solid fa-pen"></i></button>
                                    <button type="button" class="icon-btn icon-btn-danger" title="Delete" wire:click="confirmDelete('college', {{ $college->id }}, 'Delete College', 'This college will be moved to the DCS Recycle Bin.')"><i class="fa-solid fa-trash"></i></button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="empty-cell">No colleges yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="tab-panel" x-show="tab === 'programs'" x-cloak>
        <div class="panel-toolbar">
            <span class="panel-subtitle">Add programs under each academic college.</span>
            <button type="button" class="btn-primary" wire:click="openProgram()"><i class="fa-solid fa-plus"></i> Add Program</button>
        </div>
        <div class="table-wrap">
            <table class="settings-table">
                <thead><tr><th>College</th><th>Program Name</th><th style="width:100px;">Code</th><th style="width:140px;">Actions</th></tr></thead>
                <tbody>
                    @forelse($colleges as $college)
                        <tr class="college-group-header" wire:key="pgh-{{ $college->id }}">
                            <td colspan="4">
                                @php $progCount = ($programsByCollege[(string) $college->id] ?? collect())->count(); @endphp
                                <i class="fa-solid fa-graduation-cap"></i>
                                {{ $college->college_name }}
                                <span class="program-count">({{ $progCount }} {{ \Illuminate\Support\Str::plural('program', $progCount) }})</span>
                            </td>
                        </tr>
                        @forelse($programsByCollege[(string) $college->id] ?? [] as $prog)
                            <tr wire:key="pg-{{ $prog->id }}" data-id="{{ $prog->id }}">
                                <td data-label="College">{{ $college->college_name }}</td>
                                <td data-label="Program">{{ $prog->program_name }}</td>
                                <td data-label="Code">{{ $prog->program_code ?? '—' }}</td>
                                <td>
                                    <div class="row-actions">
                                        <button type="button" class="icon-btn" title="Edit" wire:click="openProgram({{ $prog->id }})"><i class="fa-solid fa-pen"></i></button>
                                        <button type="button" class="icon-btn icon-btn-danger" title="Delete" wire:click="confirmDelete('program', {{ $prog->id }}, 'Delete Program', 'This program will be moved to the DCS Recycle Bin.')"><i class="fa-solid fa-trash"></i></button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr class="college-group-empty"><td colspan="4" class="empty-cell">No programs under this college yet.</td></tr>
                        @endforelse
                    @empty
                        <tr><td colspan="4" class="empty-cell">No colleges or programs yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="tab-panel" x-show="tab === 'semesters'" x-cloak>
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
                                    <button type="button" class="icon-btn icon-btn-danger" title="Delete" wire:click="confirmDelete('semester', {{ $sem->id }}, 'Delete Semester', 'This semester will be moved to the DCS Recycle Bin.')"><i class="fa-solid fa-trash"></i></button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="2" class="empty-cell">No semesters yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="tab-panel" x-show="tab === 'schoolyears'" x-cloak>
        <div class="panel-toolbar">
            <span class="panel-subtitle">Academic school years</span>
            <button type="button" class="btn-primary" wire:click="openSchoolYear()"><i class="fa-solid fa-plus"></i> Add School Year</button>
        </div>
        <div class="table-wrap">
            <table class="settings-table">
                <thead><tr><th>School Year</th><th style="width:140px;">Actions</th></tr></thead>
                <tbody>
                    @forelse($schoolYears as $sy)
                        <tr wire:key="sy-{{ $sy->id }}" data-id="{{ $sy->id }}">
                            <td data-label="School Year">{{ $sy->school_year }}</td>
                            <td>
                                <div class="row-actions">
                                    <button type="button" class="icon-btn" title="Edit" wire:click="openSchoolYear({{ $sy->id }})"><i class="fa-solid fa-pen"></i></button>
                                    <button type="button" class="icon-btn icon-btn-danger" title="Delete" wire:click="confirmDelete('schoolYear', {{ $sy->id }}, 'Delete School Year', 'This school year will be moved to the DCS Recycle Bin.')"><i class="fa-solid fa-trash"></i></button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="2" class="empty-cell">No school years yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="tab-panel" x-show="tab === 'coursenames'" x-cloak>
        <div class="panel-toolbar">
            <span class="panel-subtitle">Curriculum course list per program, semester, and year level — used to auto-fill Syllabi/TOS-Rubrics registration. Faculty is assigned during registration.</span>
            <button type="button" class="btn-primary" wire:click="openProgramCourse()"><i class="fa-solid fa-plus"></i> Add Course</button>
        </div>
        <div class="table-wrap">
            <table class="settings-table">
                <thead><tr><th>College</th><th>Program</th><th>Semester</th><th>Year Level</th><th>Course Code</th><th>Course Name</th><th style="width:140px;">Actions</th></tr></thead>
                <tbody>
                    @forelse($programCourses as $course)
                        <tr wire:key="pc-{{ $course->id }}" data-id="{{ $course->id }}">
                            <td data-label="College">{{ $course->college_name ?? '—' }}</td>
                            <td data-label="Program">{{ $course->program_name ?? '—' }}</td>
                            <td data-label="Semester">{{ $course->semester_name ?? '—' }}</td>
                            <td data-label="Year Level">{{ $course->year_level ?? '—' }}</td>
                            <td data-label="Code">{{ $course->course_code ?: '—' }}</td>
                            <td data-label="Course Name">{{ $course->course_name }}</td>
                            <td>
                                <div class="row-actions">
                                    <button type="button" class="icon-btn" title="Edit" wire:click="openProgramCourse({{ $course->id }})"><i class="fa-solid fa-pen"></i></button>
                                    <button type="button" class="icon-btn icon-btn-danger" title="Delete" wire:click="confirmDelete('programCourse', {{ $course->id }}, 'Delete Course', 'This course will be moved to the DCS Recycle Bin.')"><i class="fa-solid fa-trash"></i></button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="empty-cell">No courses yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</main>

<div class="overlay" id="settingsOverlay" x-show="modalOpen" x-cloak x-transition.opacity @click.self="dismiss()">
    <div class="modal{{ $modalKind === 'programCourse' ? ' modal--courses' : '' }}" id="settingsModalBox" @click.stop>
        @if(str_ends_with($modalKind, ':delete'))
            <div class="st-modal delete-modal">
                <div class="st-modal-top">
                    <div class="st-modal-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
                    <button type="button" class="st-modal-close" @click="dismiss()"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <div class="delete-title">{{ $deleteTitle }}</div>
                <div class="delete-message">{{ $deleteMessage }}</div>
                <div class="st-actions-row">
                    <button type="button" class="st-btn st-btn-ghost" @click="dismiss()">Cancel</button>
                    <button type="button" class="st-btn st-btn-danger" wire:click="destroy" wire:loading.attr="disabled">
                        <i class="fa-solid fa-trash"></i> Delete
                    </button>
                </div>
            </div>
        @else
            <form class="st-modal" wire:submit.prevent="{{ str_starts_with($modalKind, 'import') ? 'runCsvImport' : 'save' }}">
                <div class="st-modal-top">
                    <div class="st-modal-icon"><i class="fa-solid {{ str_starts_with($modalKind, 'import') ? 'fa-file-csv' : 'fa-pen-to-square' }}"></i></div>
                    <button type="button" class="st-modal-close" @click="dismiss()"><i class="fa-solid fa-xmark"></i></button>
                </div>
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
                    @endif
                </div>

                @if($modalKind === 'importOriginators' || $modalKind === 'importFaculties')
                    <p class="csv-help">
                        @if($modalKind === 'importOriginators')
                            One originator name per line. Optional header: <code>originator_name</code>
                        @else
                            Columns: <code>faculty_name,college_name</code>. College must match an existing college name. Optional header row is fine.
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
                        <label class="st-label">Name</label>
                        <input type="text" class="st-input @error('docTypeName') error @enderror" wire:model="docTypeName" placeholder="e.g. Internal, Syllabi">
                        @error('docTypeName') <div class="field-error">{{ $message }}</div> @enderror
                    </div>
                @elseif($modalKind === 'originator')
                    <div class="st-field">
                        <label class="st-label">Originator Name</label>
                        <input type="text" class="st-input @error('originatorName') error @enderror" wire:model="originatorName">
                        @error('originatorName') <div class="field-error">{{ $message }}</div> @enderror
                    </div>
                @elseif($modalKind === 'faculty')
                    <div class="st-field">
                        <label class="st-label">College</label>
                        <select class="st-input @error('collegeId') error @enderror" wire:model="collegeId">
                            <option value="">— None —</option>
                            @foreach($colleges as $college)
                                <option value="{{ $college->id }}">{{ $college->college_name }}</option>
                            @endforeach
                        </select>
                        @error('collegeId') <div class="field-error">{{ $message }}</div> @enderror
                    </div>
                    <div class="st-field">
                        <label class="st-label">Faculty Name</label>
                        <input type="text" class="st-input @error('facultyName') error @enderror" wire:model="facultyName">
                        @error('facultyName') <div class="field-error">{{ $message }}</div> @enderror
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
                        <p class="st-faculty-hint">Leave this blank for offices that are not colleges (Cashier, ICTU, RFIO, and similar). Pick an office only for college units such as CCS or CEA.</p>
                    </div>
                    <div class="st-field">
                        <label class="st-label">College Name</label>
                        <input type="text" class="st-input @error('collegeName') error @enderror" wire:model="collegeName">
                        @error('collegeName') <div class="field-error">{{ $message }}</div> @enderror
                    </div>
                @elseif($modalKind === 'program')
                    <div class="st-field">
                        <label class="st-label">College</label>
                        <select class="st-input @error('collegeId') error @enderror" wire:model="collegeId">
                            <option value="">Select college</option>
                            @foreach($colleges as $college)
                                <option value="{{ $college->id }}">{{ $college->college_name }}</option>
                            @endforeach
                        </select>
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
                @elseif($modalKind === 'programCourse')
                    <div class="st-field">
                        <label class="st-label">Program</label>
                        <select class="st-input @error('programId') error @enderror" wire:model="programId">
                            <option value="">Select program</option>
                            @foreach($programs as $prog)
                                <option value="{{ $prog->id }}">{{ $prog->college_name }} — {{ $prog->program_name }}</option>
                            @endforeach
                        </select>
                        @error('programId') <div class="field-error">{{ $message }}</div> @enderror
                    </div>
                    <div class="st-field">
                        <label class="st-label">Semester</label>
                        <select class="st-input @error('semesterId') error @enderror" wire:model="semesterId">
                            <option value="">Select semester</option>
                            @foreach($semesters as $sem)
                                <option value="{{ $sem->id }}">{{ $sem->semester_name }}</option>
                            @endforeach
                        </select>
                        @error('semesterId') <div class="field-error">{{ $message }}</div> @enderror
                    </div>
                    <div class="st-field">
                        <label class="st-label">Year Level</label>
                        <select class="st-input @error('courseYearLevel') error @enderror" wire:model="courseYearLevel">
                            <option value="">Select year level</option>
                            <option value="1st Year">1st Year</option>
                            <option value="2nd Year">2nd Year</option>
                            <option value="3rd Year">3rd Year</option>
                            <option value="4th Year">4th Year</option>
                            <option value="5th Year">5th Year</option>
                        </select>
                        @error('courseYearLevel') <div class="field-error">{{ $message }}</div> @enderror
                    </div>

                    @if($editingId)
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
                            <p class="st-faculty-hint" style="margin-top:0;">Set program, semester, and year level once, then add as many courses as you need.</p>
                            <div class="st-course-rows">
                                <div class="st-course-row st-course-row-head">
                                    <span>Code</span>
                                    <span>Course name</span>
                                    <span></span>
                                </div>
                                @foreach($courseRows as $i => $row)
                                    <div class="st-course-row" wire:key="course-row-{{ $i }}">
                                        <div>
                                            <input
                                                type="text"
                                                class="st-input @error('courseRows.'.$i.'.code') error @enderror"
                                                wire:model="courseRows.{{ $i }}.code"
                                                placeholder="e.g. CS 101"
                                                @if($i === 0) data-course-first-input @endif
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
                    <p class="st-faculty-hint">Faculty is selected when registering Syllabi or TOS/Rubrics for a college.</p>
                @endif

                <div class="st-actions-row">
                    <button type="button" class="st-btn st-btn-ghost" @click="dismiss()">{{ $modalKind === 'programCourse' && ! $editingId ? 'Done' : 'Cancel' }}</button>
                    <button type="submit" class="st-btn st-btn-primary" wire:loading.attr="disabled">
                        <i class="fa-solid fa-check"></i>
                        @if(str_starts_with($modalKind, 'import'))
                            Import
                        @elseif($modalKind === 'programCourse' && ! $editingId)
                            Save courses
                        @else
                            Save
                        @endif
                    </button>
                </div>
            </form>
        @endif
    </div>
</div>

</div>

