{{--
  ADMIN PAGE: DCS Form Templates (print identity + layout + columns)
  FILE: resources/views/pages/admin/dcs/form-templates.blade.php
--}}
<?php

use App\Helpers\DcsPrintTemplateHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.admin')] #[Title('Admin Console - DCS Form Templates')] class extends Component {
    use WithFileUploads;

    public string $successMessage = '';
    public string $errorMessage = '';

    public string $selectedKey = 'office_drf';
    public string $editorTab = 'identity'; // identity | layout | columns | preview

    public string $label = '';
    public string $formCode = '';
    public string $formTitle = '';
    public string $republic = '';
    public string $institutionName = '';
    public string $institutionAddress = '';
    public string $footerLeft = '';
    public string $footerCenter = '';
    public string $defaultEffectivity = '';
    public string $defaultRev = '';
    public ?string $logoPath = null;
    public $logoUpload = null;

    /** @var array<int, array<string, mixed>> */
    public array $layoutBlocks = [];

    /** @var array<int, array<string, mixed>> */
    public array $columns = [];

    /** @var array<int, array<string, mixed>> */
    public array $groupHeaders = [];

    public string $pageSize = 'short_bond';
    public float $pageWidth = 8.5;
    public float $pageHeight = 11.0;
    public int $previewBump = 0;

    public ?string $selectedBlockId = null;

    /** @var list<string> */
    public array $selectedBlockIds = [];

    public string $layoutSection = 'header'; // header | body | footer when adding blocks

    /** @var list<string> JSON snapshots of layoutBlocks for undo */
    public array $undoStack = [];

    /** @var list<string> JSON snapshots of layoutBlocks for redo */
    public array $redoStack = [];

    /** @var list<string> JSON snapshots of columns editor (Distribution / Generate Report) */
    public array $columnsUndoStack = [];

    /** @var list<string> JSON snapshots of columns editor redo */
    public array $columnsRedoStack = [];

    /** Optional name for the History snapshot created on Save */
    public string $revisionName = '';

    /** Saved History revision selected for Enable live (null = button hidden) */
    public ?int $selectedLiveRevisionId = null;

    /** Table first-row (thead) style for report/distribution templates */
    public string $tableHeaderBg = '#8db3e2';
    public string $tableHeaderColor = '#000000';
    public string $tableHeaderFontFamily = 'Arial';
    public float $tableHeaderFontSize = 10;
    public int $tableHeaderFontWeight = 700;
    public string $tableHeaderFontStyle = 'normal';

    public function mount(): void
    {
        $perms = auth()->user()?->permissions;
        if (! $perms || (! $perms->is_sadm && ! ($perms->can_access_dcs_admin ?? false))) {
            $this->redirect(route('portal'));

            return;
        }

        $this->loadTemplate($this->selectedKey);
        $this->syncEditorFromLiveIfPublished();
    }

    public function clearMessages(): void
    {
        $this->successMessage = '';
        $this->errorMessage = '';
    }

    public function selectTemplate(string $key): void
    {
        $this->clearMessages();
        $this->selectedKey = $key;
        $this->clearBlockSelection();
        $this->editorTab = 'identity';
        $this->loadTemplate($key);
        $this->syncEditorFromLiveIfPublished();
        // Force iframe remount so Distribution / reports never show a previous template's HTML.
        $this->touchLivePreview();
    }

    public function setEditorTab(string $tab): void
    {
        if (! in_array($tab, ['identity', 'layout', 'columns', 'preview'], true)) {
            return;
        }
        $this->editorTab = $tab;
        // Don't keep stale success banners when switching Identity / Layout / Preview.
        $this->successMessage = '';
        // Layout/Columns hydrate from live when opened (Identity keeps current editor draft
        // so branding edits there still preview against the same canvas state).
        if (in_array($tab, ['layout', 'columns'], true)) {
            $this->syncEditorFromLiveIfPublished();
        }
        $this->touchLivePreview();
    }

    /**
     * Load the active live_json into the editor (same source as office intake)
     * and select that version in History.
     */
    private function syncEditorFromLiveIfPublished(): void
    {
        $status = DcsPrintTemplateHelper::liveStatus($this->selectedKey);
        if (empty($status['has_live'])) {
            return;
        }

        // Always hydrate from live_json — History revision can be stale vs the published snapshot.
        $live = DcsPrintTemplateHelper::getLive($this->selectedKey);
        $this->label = (string) ($live->label ?? $this->label);
        $this->formCode = (string) ($live->form_code ?? $this->formCode);
        $this->formTitle = (string) ($live->form_title ?? $this->formTitle);
        $this->republic = (string) ($live->republic ?? $this->republic);
        $this->institutionName = (string) ($live->institution_name ?? $this->institutionName);
        $this->institutionAddress = (string) ($live->institution_address ?? $this->institutionAddress);
        $this->footerLeft = (string) ($live->footer_left ?? $this->footerLeft);
        $this->footerCenter = (string) ($live->footer_center ?? $this->footerCenter);
        $this->defaultEffectivity = (string) ($live->default_effectivity ?? $this->defaultEffectivity);
        $this->defaultRev = (string) ($live->default_rev ?? $this->defaultRev);
        $this->logoPath = $live->logo_path ?? $this->logoPath;

        if (in_array($this->selectedKey, ['office_drf', 'office_dcn'], true)) {
            $this->layoutBlocks = DcsPrintTemplateHelper::layoutBlocks($live);
            $this->clearBlockSelection();
            $this->undoStack = [];
            $this->redoStack = [];
        } else {
            $payload = DcsPrintTemplateHelper::columnsPayload($live);
            $this->columns = $payload['columns'];
            $this->groupHeaders = $payload['group_headers'];
            $tStyle = $payload['style'] ?? DcsPrintTemplateHelper::normalizeTableStyle([]);
            $this->tableHeaderBg = (string) ($tStyle['header_row_bg'] ?? $this->tableHeaderBg);
            $this->tableHeaderColor = (string) ($tStyle['header_row_color'] ?? $this->tableHeaderColor);
            $this->tableHeaderFontFamily = (string) ($tStyle['header_font_family'] ?? $this->tableHeaderFontFamily);
            $this->tableHeaderFontSize = (float) ($tStyle['header_font_size_pt'] ?? $this->tableHeaderFontSize);
            $this->tableHeaderFontWeight = (int) ($tStyle['header_font_weight'] ?? $this->tableHeaderFontWeight);
            $this->tableHeaderFontStyle = (string) ($tStyle['header_font_style'] ?? $this->tableHeaderFontStyle);
            $this->columnsUndoStack = [];
            $this->columnsRedoStack = [];
        }

        $page = DcsPrintTemplateHelper::pageSpec($live);
        $this->pageSize = $page['size'];
        $this->pageWidth = $page['width_in'];
        $this->pageHeight = $page['height_in'];

        $liveRevId = DcsPrintTemplateHelper::ensureLiveRevisionTracked($this->selectedKey)
            ?: (int) ($status['live_revision_id'] ?? 0);
        $this->selectedLiveRevisionId = $liveRevId > 0 ? $liveRevId : null;
    }

    public function refreshPreview(): void
    {
        // Stay on the current editor tab when it already has a side-by-side preview.
        if (! in_array($this->editorTab, ['identity', 'layout', 'columns', 'preview'], true)) {
            $this->editorTab = 'preview';
        }
        $this->previewBump++;
        $this->pushLivePreviewToSession();
    }

    public function updatedPageSize(string $value): void
    {
        $resolved = DcsPrintTemplateHelper::resolvePageSize($value);
        $this->pageSize = $resolved['size'];
        $this->pageWidth = $resolved['width_in'];
        $this->pageHeight = $resolved['height_in'];
        $this->touchLivePreview();
    }

    /**
     * Any branding / column edit should refresh the official live preview iframe.
     */
    public function updating(string $name, mixed $value): void
    {
        if ($name === 'logoUpload') {
            if ($this->editorTab === 'columns') {
                $this->pushColumnsHistory();
            } elseif ($this->editorTab === 'layout') {
                $this->pushLayoutHistory();
            }

            return;
        }
        if ($this->editorTab !== 'columns') {
            return;
        }
        $track = [
            'formCode', 'formTitle', 'republic', 'institutionName', 'institutionAddress',
            'footerLeft', 'footerCenter', 'defaultEffectivity', 'defaultRev',
            'tableHeaderBg', 'tableHeaderColor', 'tableHeaderFontFamily', 'tableHeaderFontSize',
            'tableHeaderFontWeight', 'tableHeaderFontStyle', 'columns', 'groupHeaders', 'logoPath',
        ];
        foreach ($track as $key) {
            if ($name === $key || str_starts_with($name, $key . '.')) {
                $this->pushColumnsHistory();

                return;
            }
        }
    }

    public function updated(string $name, mixed $value): void
    {
        $identityProps = [
            'formCode', 'formTitle', 'republic', 'institutionName', 'institutionAddress',
            'footerLeft', 'footerCenter', 'defaultEffectivity', 'defaultRev', 'label',
        ];
        $watch = array_merge($identityProps, [
            'tableHeaderBg', 'tableHeaderColor', 'tableHeaderFontFamily', 'tableHeaderFontSize',
            'tableHeaderFontWeight', 'tableHeaderFontStyle', 'columns', 'groupHeaders', 'logoUpload',
        ]);
        foreach ($watch as $key) {
            if ($name === $key || str_starts_with($name, $key . '.')) {
                if (in_array($name, $identityProps, true)) {
                    $this->syncLayoutLabelsFromIdentity($name, (string) $value);
                }
                $this->touchLivePreview();

                return;
            }
        }
    }

    /**
     * Keep layout canvas branding text in sync when Identity fields change.
     */
    private function syncLayoutLabelsFromIdentity(string $prop, string $value): void
    {
        $bindMap = [
            'formCode' => 'form_code',
            'formTitle' => 'form_title',
            'republic' => 'republic',
            'institutionName' => 'institution_name',
            'institutionAddress' => 'institution_address',
        ];
        if ($this->layoutBlocks === []) {
            return;
        }

        // Footer identity fields rebuild the bound footer line labels.
        if (in_array($prop, ['defaultRev', 'footerCenter', 'defaultEffectivity', 'footerLeft'], true)) {
            $revLine = trim(($this->footerCenter !== '' ? $this->footerCenter : 'Rev.') . ' ' . $this->defaultRev);
            $effLine = trim(($this->footerLeft !== '' ? $this->footerLeft : 'Effectivity Date:') . ' ' . $this->defaultEffectivity);
            foreach ($this->layoutBlocks as $i => $block) {
                $bind = (string) ($block['bind'] ?? '');
                if ($bind === 'footer_rev_line') {
                    $this->layoutBlocks[$i]['label'] = $revLine;
                } elseif ($bind === 'footer_effectivity_line') {
                    $this->layoutBlocks[$i]['label'] = $effLine;
                }
            }

            return;
        }

        $bind = $bindMap[$prop] ?? null;
        if ($bind === null) {
            return;
        }
        foreach ($this->layoutBlocks as $i => $block) {
            if ((string) ($block['bind'] ?? '') === $bind) {
                $this->layoutBlocks[$i]['label'] = $value;
            }
        }
    }

    /**
     * Layout canvas label edits that map to branding should update Identity (official preview source).
     */
    private function syncIdentityFromLayoutEdit(string $bind, string $oldLabel, string $newLabel): void
    {
        $bindMap = [
            'form_code' => 'formCode',
            'form_title' => 'formTitle',
            'republic' => 'republic',
            'institution_name' => 'institutionName',
            'institution_address' => 'institutionAddress',
        ];
        if (isset($bindMap[$bind])) {
            $this->{$bindMap[$bind]} = $newLabel;

            return;
        }

        // Footer binds: canvas label is the source of truth for preview + identity.
        if ($bind === 'footer_rev_line') {
            $this->applyFooterRevLabelToIdentity($newLabel);

            return;
        }
        if ($bind === 'footer_effectivity_line') {
            $this->applyFooterEffectivityLabelToIdentity($newLabel);

            return;
        }

        // Unbound legacy blocks: match previous label to an identity field.
        $old = trim($oldLabel);
        $new = trim($newLabel);
        if ($old === '') {
            return;
        }
        $pairs = [
            'republic' => $this->republic,
            'institutionName' => $this->institutionName,
            'institutionAddress' => $this->institutionAddress,
            'formTitle' => $this->formTitle,
            'formCode' => $this->formCode,
        ];
        foreach ($pairs as $prop => $current) {
            if (strcasecmp(trim((string) $current), $old) === 0) {
                $this->{$prop} = $newLabel;

                return;
            }
        }

        // Unbound "Rev. 0" → "Rev. 1" footer text.
        $oldRevDisplay = trim(($this->footerCenter !== '' ? $this->footerCenter : 'Rev.') . ' ' . $this->defaultRev);
        if (strcasecmp($old, $oldRevDisplay) === 0 || preg_match('/^Rev\.?\s*\S+/iu', $old)) {
            if (preg_match('/^Rev\.?\s*/iu', $new) || strcasecmp($old, $oldRevDisplay) === 0) {
                $this->applyFooterRevLabelToIdentity($newLabel);
            }
        }
    }

    private function applyFooterRevLabelToIdentity(string $label): void
    {
        $label = trim($label);
        if ($label === '') {
            return;
        }
        if (preg_match('/^(Rev\.?)\s*(.*)$/iu', $label, $m)) {
            $this->footerCenter = 'Rev.';
            $this->defaultRev = trim((string) ($m[2] ?? ''));

            return;
        }
        $this->footerCenter = 'Rev.';
        $this->defaultRev = $label;
    }

    private function applyFooterEffectivityLabelToIdentity(string $label): void
    {
        $label = trim($label);
        if ($label === '') {
            return;
        }
        if (preg_match('/^(Effectivity Date:?)\s*(.*)$/iu', $label, $m)) {
            $this->footerLeft = 'Effectivity Date:';
            $this->defaultEffectivity = trim((string) ($m[2] ?? ''));

            return;
        }
        $this->defaultEffectivity = $label;
    }

    private function touchLivePreview(): void
    {
        $this->previewBump++;
        if (in_array($this->editorTab, ['identity', 'layout', 'columns', 'preview'], true)) {
            $this->pushLivePreviewToSession();
        }
    }

    /**
     * @param  bool  $preferLive  When true (opening a template), hydrate from published live_json.
     *                            When false (after Save / reset), reload the draft row.
     */
    public function loadTemplate(string $key, bool $preferLive = true): void
    {
        $status = DcsPrintTemplateHelper::liveStatus($key);
        $useLive = $preferLive && ! empty($status['has_live']);
        $tpl = $useLive
            ? DcsPrintTemplateHelper::getLive($key)
            : DcsPrintTemplateHelper::getDraft($key);
        $this->label = (string) ($tpl->label ?? $key);
        $this->formCode = (string) ($tpl->form_code ?? '');
        $this->formTitle = (string) ($tpl->form_title ?? '');
        $this->republic = (string) ($tpl->republic ?? '');
        $this->institutionName = (string) ($tpl->institution_name ?? '');
        $this->institutionAddress = (string) ($tpl->institution_address ?? '');
        $this->footerLeft = (string) ($tpl->footer_left ?? '');
        $this->footerCenter = (string) ($tpl->footer_center ?? '');
        $this->defaultEffectivity = (string) ($tpl->default_effectivity ?? '');
        $this->defaultRev = (string) ($tpl->default_rev ?? '');
        $this->logoPath = $tpl->logo_path ?? null;

        $page = DcsPrintTemplateHelper::pageSpec($tpl);
        $this->pageSize = $page['size'];
        $this->pageWidth = $page['width_in'];
        $this->pageHeight = $page['height_in'];

        $this->layoutBlocks = DcsPrintTemplateHelper::layoutBlocks($tpl);
        // DCN: replace legacy simplified canvas with official dcn-print geometry.
        if ($key === 'office_dcn' && DcsPrintTemplateHelper::isLegacySimplifiedDcnLayout(['blocks' => $this->layoutBlocks])) {
            $official = DcsPrintTemplateHelper::officialDcnLayoutBlocks();
            if ($official !== []) {
                $this->layoutBlocks = $official;
            }
        }
        $this->undoStack = [];
        $this->redoStack = [];
        $this->columnsUndoStack = [];
        $this->columnsRedoStack = [];
        $this->selectedLiveRevisionId = null;
        $this->clearBlockSelection();
        $payload = DcsPrintTemplateHelper::columnsPayload($tpl);
        $this->columns = $payload['columns'];
        $this->groupHeaders = $payload['group_headers'];
        $tStyle = $payload['style'] ?? DcsPrintTemplateHelper::normalizeTableStyle([]);
        $this->tableHeaderBg = (string) ($tStyle['header_row_bg'] ?? '#8db3e2');
        $this->tableHeaderColor = (string) ($tStyle['header_row_color'] ?? '#000000');
        $this->tableHeaderFontFamily = (string) ($tStyle['header_font_family'] ?? 'Arial');
        $this->tableHeaderFontSize = (float) ($tStyle['header_font_size_pt'] ?? 10);
        $this->tableHeaderFontWeight = (int) ($tStyle['header_font_weight'] ?? 700);
        // Distribution live form uses white headers; upgrade old blue default.
        if ($key === 'distribution') {
            $seedCols = is_array((DcsPrintTemplateHelper::defaultSeeds()['distribution']['columns_json'] ?? null))
                ? DcsPrintTemplateHelper::defaultSeeds()['distribution']['columns_json']
                : [];
            if (strtolower($this->tableHeaderBg) === '#8db3e2') {
                $this->tableHeaderBg = '#ffffff';
                $this->tableHeaderFontSize = 11.0;
            }
            if (count($this->groupHeaders) < 2 && is_array($seedCols['group_headers'] ?? null)) {
                $this->groupHeaders = array_values($seedCols['group_headers']);
            }
            if (count($this->columns) < 6 && is_array($seedCols['columns'] ?? null)) {
                $this->columns = array_values($seedCols['columns']);
            }
        }
        $this->tableHeaderFontStyle = (string) ($tStyle['header_font_style'] ?? 'normal');

        if ($useLive) {
            $liveRevId = DcsPrintTemplateHelper::ensureLiveRevisionTracked($key)
                ?: (int) ($status['live_revision_id'] ?? 0);
            $this->selectedLiveRevisionId = $liveRevId > 0 ? $liveRevId : null;
        }
    }

    public function saveIdentity(): void
    {
        $this->clearMessages();
        $this->persistCurrent(includeLayout: false, includeColumns: false);
    }

    public function saveLayout(): void
    {
        $this->clearMessages();
        $this->persistCurrent(includeLayout: true, includeColumns: false);
    }

    /**
     * Copy the current canvas into a new Saved versions draft (does not change live).
     */
    public function saveLayoutAsNew(): void
    {
        $this->clearMessages();
        $this->persistCurrent(includeLayout: true, includeColumns: false, recordRevision: true, forceNewRevision: true);
    }

    /**
     * Prefer restoring the previous published live template (before last Update live).
     * If no previous snapshot exists, discard unsaved edits and reload the selected / live version.
     */
    public function resetSelectedVersion(): void
    {
        $this->clearMessages();

        // Primary: undo the last Update live / Enable live publish.
        if (DcsPrintTemplateHelper::hasPreviousLive($this->selectedKey)) {
            $result = DcsPrintTemplateHelper::restorePreviousLive($this->selectedKey);
            if (! $result['ok']) {
                $this->errorMessage = $result['message'];

                return;
            }
            $revId = (int) ($result['revision_id'] ?? 0);
            $this->loadTemplate($this->selectedKey, preferLive: true);
            if ($revId > 0) {
                $this->selectedLiveRevisionId = $revId;
            }
            $this->undoStack = [];
            $this->redoStack = [];
            $this->columnsUndoStack = [];
            $this->columnsRedoStack = [];
            $this->clearBlockSelection();
            $this->successMessage = $result['message'];
            $this->touchLivePreview();

            return;
        }

        // Fallback: discard unsaved canvas edits only.
        $status = DcsPrintTemplateHelper::liveStatus($this->selectedKey);
        $liveRevId = (int) ($status['live_revision_id'] ?? 0);
        $selectedId = (int) ($this->selectedLiveRevisionId ?? 0);
        $isLiveSelected = $selectedId > 0 && $liveRevId > 0 && $selectedId === $liveRevId;

        if ($isLiveSelected || ($selectedId < 1 && ! empty($status['has_live']))) {
            $this->syncEditorFromLiveIfPublished();
            if ($selectedId < 1 && $liveRevId > 0) {
                $this->selectedLiveRevisionId = $liveRevId;
            }
            $this->undoStack = [];
            $this->redoStack = [];
            $this->columnsUndoStack = [];
            $this->columnsRedoStack = [];
            $this->clearBlockSelection();
            $this->successMessage = 'No earlier live publish to restore. Reloaded the current live template (unsaved edits discarded).';
            $this->touchLivePreview();

            return;
        }

        if ($selectedId > 0) {
            if (! $this->applyRevisionToEditor($selectedId, announce: false, recordHistory: false)) {
                return;
            }
            $this->undoStack = [];
            $this->redoStack = [];
            $this->columnsUndoStack = [];
            $this->columnsRedoStack = [];
            $this->selectedLiveRevisionId = $selectedId;
            $this->successMessage = 'No earlier live publish to restore. Reloaded the selected saved version (unsaved edits discarded).';
            $this->touchLivePreview();

            return;
        }

        $this->errorMessage = 'Select a saved version first, then Reset.';
    }

    public function saveColumns(): void
    {
        $this->clearMessages();
        $this->persistCurrent(includeLayout: false, includeColumns: true);
    }

    /**
     * Copy the current columns into a new Saved versions draft (does not change live).
     */
    public function saveColumnsAsNew(): void
    {
        $this->clearMessages();
        $this->persistCurrent(includeLayout: false, includeColumns: true, recordRevision: true, forceNewRevision: true);
    }

    public function saveAll(): void
    {
        $this->clearMessages();
        $this->persistCurrent(includeLayout: true, includeColumns: true);
    }

    /**
     * Pick a History save — loads it into the editor and reveals Enable live.
     */
    public function selectSavedRevision(mixed $revisionId): void
    {
        $id = (int) $revisionId;
        if ($id < 1) {
            $this->selectedLiveRevisionId = null;
            $this->clearMessages();

            return;
        }
        $this->restoreRevision($id);
    }

    public function restoreRevision(int $revisionId): void
    {
        $this->clearMessages();
        if (! $this->applyRevisionToEditor($revisionId, announce: true)) {
            return;
        }
        $this->editorTab = in_array($this->selectedKey, ['office_drf', 'office_dcn'], true) ? 'layout' : 'columns';
    }

    /**
     * Load a History revision into the editor and mark it as the Enable-live candidate.
     */
    private function applyRevisionToEditor(int $revisionId, bool $announce = true, bool $recordHistory = true): bool
    {
        $rev = DcsPrintTemplateHelper::findRevision($revisionId);
        if (! $rev || (string) ($rev->template_key ?? '') !== $this->selectedKey) {
            $this->errorMessage = 'Revision not found.';
            $this->selectedLiveRevisionId = null;

            return false;
        }

        // Block reverting to Official stock after a customized version is already live.
        if ((string) ($rev->source ?? '') === 'official') {
            $status = DcsPrintTemplateHelper::liveStatus($this->selectedKey);
            $liveRevId = (int) ($status['live_revision_id'] ?? 0);
            if ($liveRevId > 0 && $liveRevId !== $revisionId) {
                $this->errorMessage = 'Official (stock) is locked — a customized version is already live. Use Save as new or edit the live version.';

                return false;
            }
        }

        $layout = is_array($rev->layout_json ?? null) ? $rev->layout_json : [];
        $columns = is_array($rev->columns_json ?? null) ? $rev->columns_json : null;
        $kind = (string) ($rev->kind ?? 'layout');

        if (in_array($kind, ['layout', 'full'], true) || ($columns === null && is_array($layout['blocks'] ?? null))) {
            if ($recordHistory) {
                $this->pushLayoutHistory();
            }
            $this->layoutBlocks = array_values(is_array($layout['blocks'] ?? null) ? $layout['blocks'] : []);
            $this->clearBlockSelection();
        }

        if ($columns !== null && in_array($kind, ['columns', 'full'], true)) {
            if ($recordHistory) {
                $this->pushColumnsHistory();
            }
            $this->columns = array_values(is_array($columns['columns'] ?? null) ? $columns['columns'] : []);
            $this->groupHeaders = array_values(is_array($columns['group_headers'] ?? null) ? $columns['group_headers'] : []);
            $style = DcsPrintTemplateHelper::normalizeTableStyle(is_array($columns['style'] ?? null) ? $columns['style'] : []);
            $this->tableHeaderBg = (string) ($style['header_row_bg'] ?? '#8db3e2');
            $this->tableHeaderColor = (string) ($style['header_row_color'] ?? '#000000');
            $this->tableHeaderFontFamily = (string) ($style['header_font_family'] ?? 'Arial');
            $this->tableHeaderFontSize = (float) ($style['header_font_size_pt'] ?? 10);
            $this->tableHeaderFontWeight = (int) ($style['header_font_weight'] ?? 700);
            $this->tableHeaderFontStyle = (string) ($style['header_font_style'] ?? 'normal');
            if (! $recordHistory) {
                $this->columnsUndoStack = [];
                $this->columnsRedoStack = [];
            }
        }

        if (is_array($rev->page_json ?? null)) {
            $page = DcsPrintTemplateHelper::resolvePageSize(
                (string) ($rev->page_json['size'] ?? $this->pageSize),
                isset($rev->page_json['width_in']) ? (float) $rev->page_json['width_in'] : null,
                isset($rev->page_json['height_in']) ? (float) $rev->page_json['height_in'] : null
            );
            $this->pageSize = $page['size'];
            $this->pageWidth = $page['width_in'];
            $this->pageHeight = $page['height_in'];
        }

        $this->selectedLiveRevisionId = $revisionId;
        $this->touchLivePreview();
        // Selection feedback stays beside Enable live — no top success banner.
        if ($announce) {
            $this->successMessage = '';
        }

        return true;
    }

    /**
     * @param  bool  $recordRevision     When false (Enable live), update draft only — do not add a Saved versions row.
     * @param  bool  $forceNewRevision   When true (Save as new), always create a Saved versions row even if live is selected.
     */
    private function persistCurrent(
        bool $includeLayout,
        bool $includeColumns,
        bool $recordRevision = true,
        bool $forceNewRevision = false
    ): void
    {
        if (! Schema::hasTable(DcsPrintTemplateHelper::TABLE)) {
            $this->errorMessage = 'Run migrations to create dcs_print_form_templates.';

            return;
        }

        try {
            if ($this->logoUpload) {
                $stored = $this->logoUpload->store('dcs-print-logos', 'public');
                $this->logoPath = $stored;
            }

            $row = DB::table(DcsPrintTemplateHelper::TABLE)->where('key', $this->selectedKey)->first();
            $pagePayload = [
                'size' => $this->pageSize,
                'width_in' => $this->pageWidth,
                'height_in' => $this->pageHeight,
                'margin_in' => 0,
            ];
            $payload = [
                'label' => trim($this->label) !== '' ? trim($this->label) : $this->selectedKey,
                'form_code' => trim($this->formCode),
                'form_title' => trim($this->formTitle),
                'republic' => trim($this->republic),
                'institution_name' => trim($this->institutionName),
                'institution_address' => trim($this->institutionAddress),
                'footer_left' => trim($this->footerLeft),
                'footer_center' => trim($this->footerCenter),
                'default_effectivity' => trim($this->defaultEffectivity),
                'default_rev' => trim($this->defaultRev),
                'logo_path' => $this->logoPath,
                'page_json' => json_encode($pagePayload),
                'updated_by' => auth()->id(),
                'updated_at' => now(),
            ];

            $newLayout = ['blocks' => array_values($this->layoutBlocks)];
            $newColumns = [
                'style' => DcsPrintTemplateHelper::normalizeTableStyle([
                    'header_row_bg' => $this->tableHeaderBg,
                    'header_row_color' => $this->tableHeaderColor,
                    'header_font_family' => $this->tableHeaderFontFamily,
                    'header_font_size_pt' => $this->tableHeaderFontSize,
                    'header_font_weight' => $this->tableHeaderFontWeight,
                    'header_font_style' => $this->tableHeaderFontStyle,
                ]),
                'group_headers' => array_values($this->groupHeaders),
                'columns' => array_values($this->columns),
            ];

            if ($includeLayout) {
                $payload['layout_json'] = json_encode($newLayout);
            }
            if ($includeColumns) {
                $payload['columns_json'] = json_encode($newColumns);
            }

            if ($row) {
                DB::table(DcsPrintTemplateHelper::TABLE)->where('id', $row->id)->update($payload);
            } else {
                $payload['key'] = $this->selectedKey;
                $payload['created_at'] = now();
                if (! isset($payload['layout_json'])) {
                    $payload['layout_json'] = json_encode(['blocks' => []]);
                }
                if (! isset($payload['columns_json'])) {
                    $payload['columns_json'] = null;
                }
                DB::table(DcsPrintTemplateHelper::TABLE)->insert($payload);
            }

            // Selected Saved versions row: update in place (unless Save as new / Official).
            $liveStatusBefore = DcsPrintTemplateHelper::liveStatus($this->selectedKey);
            $liveRevId = (int) ($liveStatusBefore['live_revision_id'] ?? 0);
            $selectedRevId = (int) ($this->selectedLiveRevisionId ?? 0);
            $selectedRev = ($recordRevision && ! $forceNewRevision && $selectedRevId > 0)
                ? DcsPrintTemplateHelper::findRevision($selectedRevId)
                : null;
            $selectedIsOfficial = $selectedRev && (string) ($selectedRev->source ?? '') === 'official';
            $editingLiveVersion = $selectedRev
                && $liveRevId > 0
                && $selectedRevId === $liveRevId;
            // Update the selected save in place when it is a normal (non-official) version.
            $updateSelectedInPlace = $selectedRev && ! $selectedIsOfficial;

            $publishedLive = false;
            $updatedExisting = false;
            if ($recordRevision && ($includeLayout || $includeColumns)) {
                $kind = $includeLayout && $includeColumns ? 'full' : ($includeLayout ? 'layout' : 'columns');

                if ($updateSelectedInPlace) {
                    DcsPrintTemplateHelper::updateRevision(
                        $selectedRevId,
                        $includeLayout ? $newLayout : ['blocks' => []],
                        $includeColumns ? $newColumns : null,
                        $pagePayload,
                        $kind
                    );
                    $this->selectedLiveRevisionId = $selectedRevId;
                    $updatedExisting = true;
                    // Live selected → also publish to office intake.
                    if ($editingLiveVersion && Schema::hasColumn(DcsPrintTemplateHelper::TABLE, 'live_json')) {
                        $publishedLive = DcsPrintTemplateHelper::publishLive($this->selectedKey, $selectedRevId);
                    }
                } elseif ($editingLiveVersion && $selectedIsOfficial) {
                    // Official is live: publish live_json only — never mutate the Official stock row.
                    $this->selectedLiveRevisionId = $selectedRevId;
                    if (Schema::hasColumn(DcsPrintTemplateHelper::TABLE, 'live_json')) {
                        $publishedLive = DcsPrintTemplateHelper::publishLive($this->selectedKey, $selectedRevId);
                    }
                } else {
                    // No selection, Official selected, or Save as new → create a new Saved versions row.
                    $revLabel = trim($this->revisionName);
                    if ($revLabel === '') {
                        $revLabel = 'Saved ' . now()->format('M j, g:i A');
                    }
                    $newRevId = DcsPrintTemplateHelper::pushRevision(
                        $this->selectedKey,
                        $includeLayout ? $newLayout : ['blocks' => []],
                        $includeColumns ? $newColumns : null,
                        $pagePayload,
                        'save',
                        $kind,
                        $revLabel,
                        $forceNewRevision
                    );
                    $this->revisionName = '';
                    if ($newRevId) {
                        $this->selectedLiveRevisionId = $newRevId;
                    }
                }
            }

            DcsPrintTemplateHelper::flushCache();
            $this->logoUpload = null;
            $keepLiveRev = $this->selectedLiveRevisionId;
            if (! $recordRevision) {
                // Enable live path: quiet persist; caller sets the success message.
                $this->successMessage = '';
            } elseif ($publishedLive) {
                $this->successMessage = 'Live template updated (no new Saved versions row).';
            } elseif ($updatedExisting) {
                $this->successMessage = 'Saved version updated. Click Enable live to publish it to office intake.';
            } elseif ($forceNewRevision) {
                $this->successMessage = $keepLiveRev
                    ? 'New version created from the canvas. Edit it, then Enable live when ready.'
                    : 'New version created. Select it under Saved versions to Enable live.';
            } elseif ($includeLayout && in_array($this->selectedKey, ['office_drf', 'office_dcn'], true)) {
                $this->successMessage = $keepLiveRev
                    ? 'Layout saved and selected. Click Enable live to publish this version.'
                    : 'Layout saved. Select that version under Saved versions to reveal Enable live.';
            } elseif ($includeColumns) {
                $this->successMessage = $keepLiveRev
                    ? 'Columns saved and selected. Click Enable live to publish this version.'
                    : 'Columns saved. Select that version under Saved versions to reveal Enable live.';
            } elseif (! $includeLayout && ! $includeColumns) {
                $this->successMessage = 'Identity saved as draft. Save layout/columns, then Enable live on that saved version.';
            } else {
                $this->successMessage = $keepLiveRev
                    ? 'Template saved and selected. Click Enable live to publish.'
                    : 'Template saved. Select that version under Saved versions to reveal Enable live.';
            }
            // Reload draft (just-saved), not live — keep the selected Saved versions row.
            $this->loadTemplate($this->selectedKey, preferLive: false);
            if ($keepLiveRev) {
                $this->selectedLiveRevisionId = $keepLiveRev;
            }
            if ($publishedLive) {
                $this->syncEditorFromLiveIfPublished();
                $this->touchLivePreview();
            }
        } catch (\Throwable $e) {
            $this->errorMessage = 'Save failed: ' . $e->getMessage();
        }
    }

    public function enableLive(): void
    {
        $this->clearMessages();
        if (! $this->selectedLiveRevisionId) {
            $this->errorMessage = 'Select a saved version first, then Enable live.';

            return;
        }
        if (! Schema::hasTable(DcsPrintTemplateHelper::TABLE)) {
            $this->errorMessage = 'Run migrations to create dcs_print_form_templates.';

            return;
        }
        if (! Schema::hasColumn(DcsPrintTemplateHelper::TABLE, 'live_json')) {
            $this->errorMessage = 'Run migrations to add live_json (Enable live support).';

            return;
        }

        $status = DcsPrintTemplateHelper::liveStatus($this->selectedKey);
        $revId = (int) $this->selectedLiveRevisionId;
        if (! empty($status['live_revision_id']) && (int) $status['live_revision_id'] === $revId) {
            $this->successMessage = '';
            $this->editorTab = in_array($this->selectedKey, ['office_drf', 'office_dcn'], true) ? 'layout' : 'columns';
            $this->syncEditorFromLiveIfPublished();
            $this->touchLivePreview();

            return;
        }

        // Re-load the selected save so live matches History, not unsaved editor tweaks.
        if (! $this->applyRevisionToEditor($revId, announce: false)) {
            return;
        }

        $isForm = in_array($this->selectedKey, ['office_drf', 'office_dcn'], true);
        // Update draft from the selected version, but do not create another Saved versions row.
        $this->persistCurrent(includeLayout: $isForm, includeColumns: ! $isForm, recordRevision: false);
        if ($this->errorMessage !== '') {
            return;
        }
        if (! DcsPrintTemplateHelper::publishLive($this->selectedKey, $revId)) {
            $this->errorMessage = 'Could not publish live template. Save a draft first, then try again.';

            return;
        }

        // Keep selection — button becomes "Already live"; editor shows the new live version.
        $this->selectedLiveRevisionId = $revId;
        $this->editorTab = $isForm ? 'layout' : 'columns';
        $this->syncEditorFromLiveIfPublished();
        $this->successMessage = 'Live template replaced — office intake / exports now use this saved version. Layout shows the live template.';
        $this->touchLivePreview();
    }

    public function resetToDefault(): void
    {
        $this->clearMessages();
        $seeds = DcsPrintTemplateHelper::defaultSeeds();
        if (! isset($seeds[$this->selectedKey])) {
            $this->errorMessage = 'No default seed for this template.';

            return;
        }
        if (! Schema::hasTable(DcsPrintTemplateHelper::TABLE)) {
            $this->errorMessage = 'Run migrations first.';

            return;
        }

        $seed = $seeds[$this->selectedKey];
        $payload = [
            'label' => $seed['label'],
            'form_code' => $seed['form_code'] ?? null,
            'form_title' => $seed['form_title'] ?? null,
            'republic' => $seed['republic'] ?? null,
            'institution_name' => $seed['institution_name'] ?? null,
            'institution_address' => $seed['institution_address'] ?? null,
            'footer_left' => $seed['footer_left'] ?? null,
            'footer_center' => $seed['footer_center'] ?? null,
            'default_effectivity' => $seed['default_effectivity'] ?? null,
            'default_rev' => $seed['default_rev'] ?? null,
            'logo_path' => null,
            'layout_json' => isset($seed['layout_json']) ? json_encode($seed['layout_json']) : null,
            'columns_json' => isset($seed['columns_json']) ? json_encode($seed['columns_json']) : null,
            'page_json' => isset($seed['page_json']) ? json_encode($seed['page_json']) : null,
            'updated_by' => auth()->id(),
            'updated_at' => now(),
        ];

        $row = DB::table(DcsPrintTemplateHelper::TABLE)->where('key', $this->selectedKey)->first();
        if ($row) {
            $oldLayout = is_string($row->layout_json ?? null) ? json_decode((string) $row->layout_json, true) : ($row->layout_json ?? null);
            if (Schema::hasColumn(DcsPrintTemplateHelper::TABLE, 'layout_json_previous') && is_array($oldLayout)) {
                $payload['layout_json_previous'] = json_encode($oldLayout);
                if (Schema::hasColumn(DcsPrintTemplateHelper::TABLE, 'layout_previous_at')) {
                    $payload['layout_previous_at'] = now();
                }
            }
            DB::table(DcsPrintTemplateHelper::TABLE)->where('id', $row->id)->update($payload);
        } else {
            $payload['key'] = $this->selectedKey;
            $payload['created_at'] = now();
            DB::table(DcsPrintTemplateHelper::TABLE)->insert($payload);
        }

        DcsPrintTemplateHelper::flushCache();
        $this->loadTemplate($this->selectedKey, preferLive: false);
        $this->editorTab = in_array($this->selectedKey, ['office_drf', 'office_dcn'], true) ? 'layout' : 'identity';
        $this->touchLivePreview();
        $this->successMessage = 'Official system design applied as draft. Save, select that version under Saved versions, then Enable live.';
    }

    public function setLayoutSection(string $section): void
    {
        if (in_array($section, ['header', 'body', 'footer'], true)) {
            $this->layoutSection = $section;
        }
    }

    /**
     * Snapshot layout + identity so Undo restores labels and branding together.
     */
    private function layoutHistorySnapshot(): string
    {
        $encoded = json_encode([
            'v' => 2,
            'blocks' => array_values($this->layoutBlocks),
            'identity' => [
                'republic' => $this->republic,
                'institutionName' => $this->institutionName,
                'institutionAddress' => $this->institutionAddress,
                'formCode' => $this->formCode,
                'formTitle' => $this->formTitle,
                'footerLeft' => $this->footerLeft,
                'footerCenter' => $this->footerCenter,
                'defaultEffectivity' => $this->defaultEffectivity,
                'defaultRev' => $this->defaultRev,
            ],
        ]);

        return $encoded !== false ? $encoded : '{"v":2,"blocks":[],"identity":{}}';
    }

    private function applyLayoutHistorySnapshot(string $json): void
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            $this->layoutBlocks = [];

            return;
        }

        // Legacy snapshots were a bare blocks array.
        if (! isset($decoded['v']) && array_is_list($decoded)) {
            $this->layoutBlocks = array_values($decoded);
            $this->resyncIdentityFromLayoutBlocks();

            return;
        }

        $blocks = $decoded['blocks'] ?? [];
        $this->layoutBlocks = is_array($blocks) ? array_values($blocks) : [];
        $identity = is_array($decoded['identity'] ?? null) ? $decoded['identity'] : [];
        foreach ([
            'republic' => 'republic',
            'institutionName' => 'institutionName',
            'institutionAddress' => 'institutionAddress',
            'formCode' => 'formCode',
            'formTitle' => 'formTitle',
            'footerLeft' => 'footerLeft',
            'footerCenter' => 'footerCenter',
            'defaultEffectivity' => 'defaultEffectivity',
            'defaultRev' => 'defaultRev',
        ] as $key => $prop) {
            if (array_key_exists($key, $identity)) {
                $this->{$prop} = (string) $identity[$key];
            }
        }
    }

    /** Re-bind Identity fields from canvas labels after a legacy undo snapshot. */
    private function resyncIdentityFromLayoutBlocks(): void
    {
        $map = [
            'form_code' => 'formCode',
            'form_title' => 'formTitle',
            'republic' => 'republic',
            'institution_name' => 'institutionName',
            'institution_address' => 'institutionAddress',
        ];
        foreach ($this->layoutBlocks as $block) {
            if (! is_array($block)) {
                continue;
            }
            $bind = (string) ($block['bind'] ?? '');
            if (! isset($map[$bind])) {
                continue;
            }
            $this->{$map[$bind]} = (string) ($block['label'] ?? '');
        }
    }

    private function pushLayoutHistory(): void
    {
        $snap = $this->layoutHistorySnapshot();
        $last = $this->undoStack !== [] ? $this->undoStack[count($this->undoStack) - 1] : null;
        if ($last === $snap) {
            return;
        }
        $this->undoStack[] = $snap;
        if (count($this->undoStack) > 50) {
            array_shift($this->undoStack);
        }
        $this->redoStack = [];
    }

    public function undoLayout(): void
    {
        if ($this->undoStack === []) {
            return;
        }
        $keepId = $this->selectedBlockId;
        $this->redoStack[] = $this->layoutHistorySnapshot();
        $prev = array_pop($this->undoStack);
        $this->applyLayoutHistorySnapshot((string) $prev);
        if ($keepId && $this->blockExists((string) $keepId)) {
            $this->setBlockSelection((string) $keepId, [(string) $keepId]);
        } else {
            $this->clearBlockSelection();
        }
        $this->editorTab = 'layout';
        $this->touchLivePreview();
    }

    public function redoLayout(): void
    {
        if ($this->redoStack === []) {
            return;
        }
        $keepId = $this->selectedBlockId;
        $this->undoStack[] = $this->layoutHistorySnapshot();
        $next = array_pop($this->redoStack);
        $this->applyLayoutHistorySnapshot((string) $next);
        if ($keepId && $this->blockExists((string) $keepId)) {
            $this->setBlockSelection((string) $keepId, [(string) $keepId]);
        } else {
            $this->clearBlockSelection();
        }
        $this->editorTab = 'layout';
        $this->touchLivePreview();
    }

    /**
     * Snapshot columns + branding for Distribution / Generate Report undo.
     */
    private function columnsHistorySnapshot(): string
    {
        $encoded = json_encode([
            'v' => 1,
            'columns' => array_values($this->columns),
            'groupHeaders' => array_values($this->groupHeaders),
            'style' => [
                'tableHeaderBg' => $this->tableHeaderBg,
                'tableHeaderColor' => $this->tableHeaderColor,
                'tableHeaderFontFamily' => $this->tableHeaderFontFamily,
                'tableHeaderFontSize' => $this->tableHeaderFontSize,
                'tableHeaderFontWeight' => $this->tableHeaderFontWeight,
                'tableHeaderFontStyle' => $this->tableHeaderFontStyle,
            ],
            'identity' => [
                'republic' => $this->republic,
                'institutionName' => $this->institutionName,
                'institutionAddress' => $this->institutionAddress,
                'formCode' => $this->formCode,
                'formTitle' => $this->formTitle,
                'footerLeft' => $this->footerLeft,
                'footerCenter' => $this->footerCenter,
                'defaultEffectivity' => $this->defaultEffectivity,
                'defaultRev' => $this->defaultRev,
                'logoPath' => $this->logoPath,
            ],
        ]);

        return $encoded !== false ? $encoded : '{"v":1,"columns":[],"groupHeaders":[],"style":{},"identity":{}}';
    }

    private function applyColumnsHistorySnapshot(string $json): void
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return;
        }
        $this->columns = array_values(is_array($decoded['columns'] ?? null) ? $decoded['columns'] : []);
        $this->groupHeaders = array_values(is_array($decoded['groupHeaders'] ?? null) ? $decoded['groupHeaders'] : []);
        $style = is_array($decoded['style'] ?? null) ? $decoded['style'] : [];
        if (array_key_exists('tableHeaderBg', $style)) {
            $this->tableHeaderBg = (string) $style['tableHeaderBg'];
        }
        if (array_key_exists('tableHeaderColor', $style)) {
            $this->tableHeaderColor = (string) $style['tableHeaderColor'];
        }
        if (array_key_exists('tableHeaderFontFamily', $style)) {
            $this->tableHeaderFontFamily = (string) $style['tableHeaderFontFamily'];
        }
        if (array_key_exists('tableHeaderFontSize', $style)) {
            $this->tableHeaderFontSize = (float) $style['tableHeaderFontSize'];
        }
        if (array_key_exists('tableHeaderFontWeight', $style)) {
            $this->tableHeaderFontWeight = (int) $style['tableHeaderFontWeight'];
        }
        if (array_key_exists('tableHeaderFontStyle', $style)) {
            $this->tableHeaderFontStyle = (string) $style['tableHeaderFontStyle'];
        }
        $identity = is_array($decoded['identity'] ?? null) ? $decoded['identity'] : [];
        foreach ([
            'republic' => 'republic',
            'institutionName' => 'institutionName',
            'institutionAddress' => 'institutionAddress',
            'formCode' => 'formCode',
            'formTitle' => 'formTitle',
            'footerLeft' => 'footerLeft',
            'footerCenter' => 'footerCenter',
            'defaultEffectivity' => 'defaultEffectivity',
            'defaultRev' => 'defaultRev',
        ] as $key => $prop) {
            if (array_key_exists($key, $identity)) {
                $this->{$prop} = (string) $identity[$key];
            }
        }
        if (array_key_exists('logoPath', $identity)) {
            $this->logoPath = $identity['logoPath'] !== null && $identity['logoPath'] !== ''
                ? (string) $identity['logoPath']
                : null;
            $this->logoUpload = null;
        }
    }

    private function pushColumnsHistory(): void
    {
        $snap = $this->columnsHistorySnapshot();
        $last = $this->columnsUndoStack !== [] ? $this->columnsUndoStack[count($this->columnsUndoStack) - 1] : null;
        if ($last === $snap) {
            return;
        }
        $this->columnsUndoStack[] = $snap;
        if (count($this->columnsUndoStack) > 50) {
            array_shift($this->columnsUndoStack);
        }
        $this->columnsRedoStack = [];
    }

    public function undoColumns(): void
    {
        if ($this->columnsUndoStack === []) {
            return;
        }
        $this->columnsRedoStack[] = $this->columnsHistorySnapshot();
        $prev = array_pop($this->columnsUndoStack);
        $this->applyColumnsHistorySnapshot((string) $prev);
        $this->editorTab = 'columns';
        $this->touchLivePreview();
    }

    public function redoColumns(): void
    {
        if ($this->columnsRedoStack === []) {
            return;
        }
        $this->columnsUndoStack[] = $this->columnsHistorySnapshot();
        $next = array_pop($this->columnsRedoStack);
        $this->applyColumnsHistorySnapshot((string) $next);
        $this->editorTab = 'columns';
        $this->touchLivePreview();
    }

    public function clearCustomLogo(): void
    {
        if ($this->editorTab === 'columns') {
            $this->pushColumnsHistory();
        } elseif ($this->editorTab === 'layout') {
            $this->pushLayoutHistory();
        }
        $this->logoUpload = null;
        $this->logoPath = null;
        $this->touchLivePreview();
    }

    public function addLogoBlock(): void
    {
        $this->layoutSection = 'header';
        $this->addBlock('logo');
    }

    public function clearBlockSelection(): void
    {
        $this->selectedBlockId = null;
        $this->selectedBlockIds = [];
    }

    /**
     * @param  list<string>|null  $ids
     */
    private function setBlockSelection(?string $primaryId, ?array $ids = null): void
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids ?? array_filter([$primaryId])))));
        $this->selectedBlockIds = $ids;
        $this->selectedBlockId = $primaryId !== null && in_array($primaryId, $ids, true)
            ? $primaryId
            : ($ids[count($ids) - 1] ?? null);
    }

    public function addBlock(string $type = 'field'): void
    {
        $this->pushLayoutHistory();
        $catalog = DcsPrintTemplateHelper::fieldCatalog($this->selectedKey);
        $bind = $catalog[0]['key'] ?? '';
        $label = $catalog[0]['label'] ?? 'New field';
        $section = $this->layoutSection;
        $yDefault = match ($section) {
            'header' => 0.2,
            'footer' => 6.8,
            default => 1.2,
        };
        $countInSection = count(array_filter($this->layoutBlocks, fn ($b) => ($b['section'] ?? '') === $section));

        $w = 3.5;
        $h = 0.35;
        $blockLabel = $label;
        $blockBind = in_array($type, ['field', 'signature'], true) ? $bind : '';
        $extra = [];

        if ($type === 'logo') {
            $w = 0.64;
            $h = 0.66;
            $blockLabel = 'Logo';
        } elseif ($type === 'line') {
            $w = 7.5;
            $h = 0.04;
            $blockLabel = 'Line';
            $blockBind = '';
        } elseif ($type === 'table') {
            $w = 7.5;
            $h = 1.6;
            $blockBind = '';
            // DCN (and new tables): Word-like freeform grid. DRF keeps signature defaults.
            if ($this->selectedKey === 'office_dcn') {
                $blockLabel = 'Table';
                $extra = [
                    'table_cols' => 3,
                    'table_rows' => 3,
                    'table_borders' => true,
                    'table_cells' => DcsPrintTemplateHelper::padTableCells([], 3, 3),
                    'table_headers' => [],
                    'table_row_labels' => [],
                    'table_cell_binds' => [],
                ];
            } else {
                $blockLabel = 'Signature table';
                $extra = [
                    'table_cols' => 4,
                    'table_rows' => 4,
                    'table_borders' => true,
                    'table_headers' => ['', 'Prepared by:', 'Reviewed by:', 'Approved by:'],
                    'table_row_labels' => ['Signature', 'Name', 'Designation', 'Date'],
                    'table_cell_binds' => [
                        '1' => ['1' => 'prepared_by_name', '2' => 'reviewed_by_name', '3' => 'approved_by_name'],
                        '2' => ['1' => 'prepared_by_designation', '2' => 'reviewed_by_designation', '3' => 'approved_by_designation'],
                    ],
                ];
            }
        } elseif ($type === 'checkboxes') {
            $w = 7.5;
            $h = 0.32;
            $blockLabel = 'Type of document:';
            $blockBind = 'doc_type';
            $extra = ['options' => ['Internal', 'External']];
        } elseif ($type === 'ruled_lines') {
            $w = 7.5;
            $h = 1.1;
            $blockLabel = 'Description/reason for request (define in detail):';
            $blockBind = 'description';
            $extra = ['line_count' => 5];
        } elseif ($type === 'dist_grid') {
            $w = 7.5;
            $h = 1.5;
            $blockLabel = 'Distribute document to (department/position):';
            $blockBind = 'distribute_to';
            $extra = ['grid_cols' => 4, 'grid_rows' => 5];
        } elseif ($type === 'text') {
            $blockLabel = 'Static text';
        } elseif ($type === 'signature') {
            $blockLabel = 'Signature';
            $w = 2.4;
            $h = 0.9;
        }

        $block = array_merge([
            'id' => (string) Str::uuid(),
            'type' => in_array($type, ['logo', 'text', 'field', 'line', 'signature', 'table', 'spacer', 'checkboxes', 'ruled_lines', 'dist_grid'], true) ? $type : 'field',
            'section' => $section,
            'x' => 0.5,
            'y' => $yDefault + ($countInSection * 0.08),
            'w' => $w,
            'h' => $h,
            'unit' => 'in',
            'label' => $blockLabel,
            'bind' => $blockBind,
            'style' => DcsPrintTemplateHelper::normalizeBlockStyle([
                'underline' => $type === 'field',
                'lineColor' => '#0d2a7a',
            ]),
        ], $extra);

        $this->layoutBlocks[] = $block;
        $this->setBlockSelection($block['id'], [$block['id']]);
        $this->editorTab = 'layout';
        $this->touchLivePreview();
    }

    public function selectBlock(string $id, bool $toggle = false): void
    {
        $this->editorTab = 'layout';
        $id = (string) $id;
        if ($id === '') {
            return;
        }

        if ($toggle) {
            $ids = $this->selectedBlockIds;
            if (in_array($id, $ids, true)) {
                $ids = array_values(array_filter($ids, fn ($x) => $x !== $id));
                $this->setBlockSelection($ids[count($ids) - 1] ?? null, $ids);
            } else {
                $ids[] = $id;
                $this->setBlockSelection($id, $ids);
            }

            return;
        }

        $this->setBlockSelection($id, [$id]);
    }

    /**
     * @param  list<array{id?: string, x?: float|int|string, y?: float|int|string}>  $moves
     */
    public function moveBlocks(array $moves): void
    {
        if ($moves === []) {
            return;
        }

        $byId = [];
        foreach ($moves as $move) {
            if (! is_array($move)) {
                continue;
            }
            $id = (string) ($move['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $byId[$id] = [
                'x' => (float) ($move['x'] ?? 0),
                'y' => (float) ($move['y'] ?? 0),
            ];
        }
        if ($byId === []) {
            return;
        }

        $this->pushLayoutHistory();
        $movedIds = [];
        foreach ($this->layoutBlocks as $i => $block) {
            $id = (string) ($block['id'] ?? '');
            if (! isset($byId[$id])) {
                continue;
            }
            $w = max(0.05, (float) ($block['w'] ?? 1));
            $h = max(0.01, (float) ($block['h'] ?? 0.3));
            $x = max(0.0, min(round($byId[$id]['x'], 3), max(0.0, round($this->pageWidth - $w, 3))));
            $y = max(0.0, min(round($byId[$id]['y'], 3), max(0.0, round($this->pageHeight - $h, 3))));
            $this->layoutBlocks[$i]['x'] = $x;
            $this->layoutBlocks[$i]['y'] = $y;
            $this->layoutBlocks[$i]['section'] = DcsPrintTemplateHelper::normalizeSection(null, $y);
            $movedIds[] = $id;
        }

        if ($movedIds !== []) {
            $keep = array_values(array_unique(array_merge(
                $this->selectedBlockIds !== [] ? $this->selectedBlockIds : [],
                $movedIds
            )));
            $keep = array_values(array_filter($keep, fn ($id) => $this->blockExists($id)));
            $primary = $this->selectedBlockId && in_array($this->selectedBlockId, $keep, true)
                ? $this->selectedBlockId
                : ($movedIds[count($movedIds) - 1] ?? null);
            $this->setBlockSelection($primary, $keep !== [] ? $keep : $movedIds);
            $this->touchLivePreview();
        }
    }

    public function nudgeSelected(float $dx, float $dy): void
    {
        $ids = $this->targetBlockIds();
        if ($ids === []) {
            return;
        }
        $moves = [];
        foreach ($this->layoutBlocks as $block) {
            $id = (string) ($block['id'] ?? '');
            if (! in_array($id, $ids, true)) {
                continue;
            }
            $moves[] = [
                'id' => $id,
                'x' => (float) ($block['x'] ?? 0) + $dx,
                'y' => (float) ($block['y'] ?? 0) + $dy,
            ];
        }
        $this->moveBlocks($moves);
    }

    private function blockExists(string $id): bool
    {
        foreach ($this->layoutBlocks as $block) {
            if ((string) ($block['id'] ?? '') === $id) {
                return true;
            }
        }

        return false;
    }

    public function deleteSelectedBlock(): void
    {
        $ids = $this->selectedBlockIds !== []
            ? $this->selectedBlockIds
            : array_values(array_filter([(string) $this->selectedBlockId]));
        if ($ids === []) {
            return;
        }
        $this->pushLayoutHistory();
        $this->layoutBlocks = array_values(array_filter(
            $this->layoutBlocks,
            fn ($b) => ! in_array((string) ($b['id'] ?? ''), $ids, true)
        ));
        $this->clearBlockSelection();
        $this->touchLivePreview();
    }

    /**
     * @return list<string>
     */
    private function targetBlockIds(): array
    {
        if ($this->selectedBlockIds !== []) {
            return array_values(array_unique(array_map('strval', $this->selectedBlockIds)));
        }
        if ($this->selectedBlockId) {
            return [(string) $this->selectedBlockId];
        }

        return [];
    }

    /**
     * Fields that should apply to every selected block (not only primary).
     *
     * @return list<string>
     */
    private function multiEditableFields(): array
    {
        return [
            'fontSizePt', 'fontWeight', 'fontFamily', 'fontStyle', 'align', 'verticalAlign',
            'color', 'backgroundColor', 'lineColor', 'objectFit', 'underline', 'table_borders',
            'w', 'h', 'section', 'label',
        ];
    }

    public function updateTableCell(int $row, int $col, $value): void
    {
        $id = (string) ($this->selectedBlockId ?? '');
        if ($id === '') {
            return;
        }
        foreach ($this->layoutBlocks as $i => $block) {
            if ((string) ($block['id'] ?? '') !== $id) {
                continue;
            }
            if ((string) ($block['type'] ?? '') !== 'table') {
                return;
            }
            $cols = max(1, min(12, (int) ($block['table_cols'] ?? 3)));
            $rows = max(1, min(12, (int) ($block['table_rows'] ?? 3)));
            $cells = DcsPrintTemplateHelper::padTableCells($block['table_cells'] ?? [], $rows, $cols);
            $r = max(0, min($rows - 1, $row));
            $c = max(0, min($cols - 1, $col));
            $next = trim((string) $value);
            if (($cells[$r][$c] ?? '') === $next) {
                return;
            }
            $this->pushLayoutHistory();
            $cells[$r][$c] = $next;
            $this->layoutBlocks[$i]['table_cells'] = $cells;
            $this->touchLivePreview();

            return;
        }
    }

    public function updateTableHeader(int $col, $value): void
    {
        $id = (string) ($this->selectedBlockId ?? '');
        if ($id === '') {
            return;
        }
        foreach ($this->layoutBlocks as $i => $block) {
            if ((string) ($block['id'] ?? '') !== $id) {
                continue;
            }
            if ((string) ($block['type'] ?? '') !== 'table') {
                return;
            }
            $cols = max(1, min(12, (int) ($block['table_cols'] ?? 3)));
            $headers = is_array($block['table_headers'] ?? null) ? array_values($block['table_headers']) : [];
            while (count($headers) < $cols) {
                $headers[] = '';
            }
            $headers = array_slice($headers, 0, $cols);
            $c = max(0, min($cols - 1, $col));
            $next = trim((string) $value);
            if ((string) ($headers[$c] ?? '') === $next) {
                return;
            }
            $this->pushLayoutHistory();
            $headers[$c] = $next;
            $this->layoutBlocks[$i]['table_headers'] = $headers;
            $this->touchLivePreview();

            return;
        }
    }

    public function updateBlockField(string $field, $value): void
    {
        $ids = $this->targetBlockIds();
        if ($ids === []) {
            return;
        }

        // Absolute X/Y / type / bind / table structure stay primary-only (avoid stacking blocks).
        $primaryOnly = in_array($field, ['x', 'y', 'type', 'bind', 'table_cols', 'table_rows'], true);
        if ($primaryOnly) {
            $ids = $this->selectedBlockId ? [(string) $this->selectedBlockId] : array_slice($ids, -1);
        } elseif (count($ids) > 1 && ! in_array($field, $this->multiEditableFields(), true)) {
            $ids = $this->selectedBlockId ? [(string) $this->selectedBlockId] : array_slice($ids, -1);
        }

        // Skip no-ops (e.g. label keyup.debounce + change firing the same value) so Undo is not a dead step.
        if (! $this->blockFieldWouldChange($ids, $field, $value)) {
            return;
        }

        $this->pushLayoutHistory();

        // Multi-select Align / Vertical also lines up block positions (not only text style).
        if (count($ids) > 1) {
            if ($field === 'align') {
                $this->alignSelectedBlocksSpatially((string) $value);
            } elseif ($field === 'verticalAlign') {
                $this->alignSelectedBlocksVertically((string) $value);
            }
        }

        foreach ($this->layoutBlocks as $i => $block) {
            $id = (string) ($block['id'] ?? '');
            if (! in_array($id, $ids, true)) {
                continue;
            }
            $oldLabel = (string) ($this->layoutBlocks[$i]['label'] ?? '');
            $bind = (string) ($this->layoutBlocks[$i]['bind'] ?? '');
            $this->applyFieldToBlockIndex($i, $field, $value);
            if ($field === 'label') {
                $this->syncIdentityFromLayoutEdit($bind, $oldLabel, (string) $value);
            }
        }
        // Any block property change should refresh the Layout canvas preview.
        $this->touchLivePreview();
    }

    /**
     * @param  list<string>  $ids
     */
    private function blockFieldWouldChange(array $ids, string $field, mixed $value): bool
    {
        foreach ($this->layoutBlocks as $block) {
            if (! is_array($block) || ! in_array((string) ($block['id'] ?? ''), $ids, true)) {
                continue;
            }
            $style = is_array($block['style'] ?? null) ? $block['style'] : [];
            $current = match (true) {
                in_array($field, ['x', 'y', 'w', 'h'], true) => (float) ($block[$field] ?? 0),
                in_array($field, ['table_cols', 'table_rows'], true) => (int) ($block[$field] ?? 0),
                $field === 'underline' => ! empty($style['underline']),
                $field === 'table_borders' => DcsPrintTemplateHelper::tableBordersVisible($block),
                in_array($field, ['fontSizePt', 'fontWeight'], true) => $style[$field] ?? null,
                in_array($field, ['fontFamily', 'fontStyle', 'align', 'verticalAlign', 'color', 'backgroundColor', 'lineColor', 'objectFit'], true) => (string) ($style[$field] ?? ''),
                default => $block[$field] ?? null,
            };

            if (in_array($field, ['x', 'y', 'w', 'h', 'fontSizePt'], true)) {
                if (abs((float) $current - (float) $value) > 0.0001) {
                    return true;
                }
                continue;
            }
            if ($field === 'underline' || $field === 'table_borders') {
                if ((bool) $current !== filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
                    return true;
                }
                continue;
            }
            if ($field === 'fontWeight') {
                if ((int) $current !== (int) $value) {
                    return true;
                }
                continue;
            }
            if ((string) $current !== (string) $value) {
                return true;
            }
        }

        // Align / verticalAlign with multi-select can still move geometry even if style matches.
        if (count($ids) > 1 && in_array($field, ['align', 'verticalAlign'], true)) {
            return true;
        }

        return false;
    }

    private function applyFieldToBlockIndex(int $i, string $field, mixed $value): void
    {
        $block = $this->layoutBlocks[$i];
        if (! isset($this->layoutBlocks[$i]['style']) || ! is_array($this->layoutBlocks[$i]['style'])) {
            $this->layoutBlocks[$i]['style'] = DcsPrintTemplateHelper::normalizeBlockStyle([]);
        }
        if (in_array($field, ['x', 'y', 'w', 'h'], true)) {
            $this->layoutBlocks[$i][$field] = (float) $value;
        } elseif (in_array($field, ['table_cols', 'table_rows'], true)) {
            $this->layoutBlocks[$i][$field] = max(1, min(12, (int) $value));
            $cols = max(1, min(12, (int) ($this->layoutBlocks[$i]['table_cols'] ?? 3)));
            $rows = max(1, min(12, (int) ($this->layoutBlocks[$i]['table_rows'] ?? 3)));
            // Keep freeform cell matrix in sync only when already freeform.
            if (array_key_exists('table_cells', $this->layoutBlocks[$i])) {
                $this->layoutBlocks[$i]['table_cells'] = DcsPrintTemplateHelper::padTableCells(
                    $this->layoutBlocks[$i]['table_cells'] ?? [],
                    $rows,
                    $cols
                );
            }
            // Always keep column header labels sized to column count (DCN Approvals, etc.).
            $headers = is_array($this->layoutBlocks[$i]['table_headers'] ?? null)
                ? array_values($this->layoutBlocks[$i]['table_headers'])
                : [];
            while (count($headers) < $cols) {
                $headers[] = '';
            }
            $this->layoutBlocks[$i]['table_headers'] = array_slice($headers, 0, $cols);
        } elseif ($field === 'table_borders') {
            $this->layoutBlocks[$i]['table_borders'] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        } elseif ($field === 'section') {
            $this->layoutBlocks[$i]['section'] = DcsPrintTemplateHelper::normalizeSection($value, (float) ($block['y'] ?? 0));
        } elseif (in_array($field, ['fontSizePt', 'fontWeight'], true)) {
            $this->layoutBlocks[$i]['style'][$field] = $field === 'fontWeight' ? (int) $value : (float) $value;
        } elseif (in_array($field, ['fontFamily', 'fontStyle', 'align', 'verticalAlign', 'color', 'backgroundColor', 'lineColor', 'objectFit'], true)) {
            $this->layoutBlocks[$i]['style'][$field] = (string) $value;
        } elseif ($field === 'underline') {
            $this->layoutBlocks[$i]['style']['underline'] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        } elseif ($field === 'type') {
            $newType = in_array((string) $value, ['logo', 'text', 'field', 'line', 'signature', 'table', 'spacer'], true)
                ? (string) $value
                : 'field';
            $this->layoutBlocks[$i]['type'] = $newType;
            if ($newType === 'table') {
                $this->layoutBlocks[$i]['w'] = max((float) ($this->layoutBlocks[$i]['w'] ?? 1), 7.0);
                $this->layoutBlocks[$i]['h'] = max((float) ($this->layoutBlocks[$i]['h'] ?? 0.3), 1.4);
                $isDcn = $this->selectedKey === 'office_dcn';
                $this->layoutBlocks[$i]['label'] = ($this->layoutBlocks[$i]['label'] ?? '') !== ''
                    ? $this->layoutBlocks[$i]['label']
                    : ($isDcn ? 'Table' : 'Signature table');
                $cols = (int) ($this->layoutBlocks[$i]['table_cols'] ?? ($isDcn ? 3 : 4));
                $rows = (int) ($this->layoutBlocks[$i]['table_rows'] ?? ($isDcn ? 3 : 4));
                $this->layoutBlocks[$i]['table_cols'] = max(1, min(12, $cols));
                $this->layoutBlocks[$i]['table_rows'] = max(1, min(12, $rows));
                if (! array_key_exists('table_borders', $this->layoutBlocks[$i])) {
                    $this->layoutBlocks[$i]['table_borders'] = true;
                }
                if ($isDcn) {
                    $this->layoutBlocks[$i]['table_cells'] = DcsPrintTemplateHelper::padTableCells(
                        $this->layoutBlocks[$i]['table_cells'] ?? [],
                        (int) $this->layoutBlocks[$i]['table_rows'],
                        (int) $this->layoutBlocks[$i]['table_cols']
                    );
                    if (! is_array($this->layoutBlocks[$i]['table_headers'] ?? null)) {
                        $this->layoutBlocks[$i]['table_headers'] = [];
                    }
                    if (! is_array($this->layoutBlocks[$i]['table_row_labels'] ?? null)) {
                        $this->layoutBlocks[$i]['table_row_labels'] = [];
                    }
                } else {
                    if (! is_array($this->layoutBlocks[$i]['table_headers'] ?? null)) {
                        $this->layoutBlocks[$i]['table_headers'] = ['', 'Prepared by:', 'Reviewed by:', 'Approved by:'];
                    }
                    if (! is_array($this->layoutBlocks[$i]['table_row_labels'] ?? null)) {
                        $this->layoutBlocks[$i]['table_row_labels'] = ['Signature', 'Name', 'Designation', 'Date'];
                    }
                }
            } elseif ($newType === 'line') {
                $this->layoutBlocks[$i]['h'] = min((float) ($this->layoutBlocks[$i]['h'] ?? 0.3), 0.06);
                $this->layoutBlocks[$i]['w'] = max((float) ($this->layoutBlocks[$i]['w'] ?? 1), 3.0);
                $this->layoutBlocks[$i]['label'] = ($this->layoutBlocks[$i]['label'] ?? '') !== '' ? $this->layoutBlocks[$i]['label'] : 'Line';
                $this->layoutBlocks[$i]['style']['lineColor'] = $this->layoutBlocks[$i]['style']['lineColor'] ?? '#0d2a7a';
            }
        } else {
            $this->layoutBlocks[$i][$field] = $value;
        }
        $this->layoutBlocks[$i]['style'] = DcsPrintTemplateHelper::normalizeBlockStyle($this->layoutBlocks[$i]['style']);
    }

    /**
     * Line up selected blocks horizontally when using Align with multi-select.
     */
    private function alignSelectedBlocksSpatially(string $align): void
    {
        $ids = $this->targetBlockIds();
        if (count($ids) < 2) {
            return;
        }

        $indexes = [];
        foreach ($this->layoutBlocks as $i => $block) {
            if (in_array((string) ($block['id'] ?? ''), $ids, true)) {
                $indexes[] = $i;
            }
        }
        if (count($indexes) < 2) {
            return;
        }

        $lefts = [];
        $rights = [];
        $centers = [];
        foreach ($indexes as $i) {
            $x = (float) ($this->layoutBlocks[$i]['x'] ?? 0);
            $w = max(0.05, (float) ($this->layoutBlocks[$i]['w'] ?? 1));
            $lefts[] = $x;
            $rights[] = $x + $w;
            $centers[] = $x + ($w / 2);
        }

        $align = strtolower(trim($align));
        foreach ($indexes as $i) {
            $w = max(0.05, (float) ($this->layoutBlocks[$i]['w'] ?? 1));
            if ($align === 'left') {
                $x = min($lefts);
            } elseif ($align === 'right') {
                $x = max($rights) - $w;
            } elseif ($align === 'center') {
                $x = (array_sum($centers) / count($centers)) - ($w / 2);
            } else {
                // justify: keep positions, text-align only
                continue;
            }
            $x = max(0.0, min(round($x, 3), max(0.0, round($this->pageWidth - $w, 3))));
            $this->layoutBlocks[$i]['x'] = $x;
            $y = (float) ($this->layoutBlocks[$i]['y'] ?? 0);
            $this->layoutBlocks[$i]['section'] = DcsPrintTemplateHelper::normalizeSection(null, $y);
        }
    }

    private function alignSelectedBlocksVertically(string $verticalAlign): void
    {
        $ids = $this->targetBlockIds();
        if (count($ids) < 2) {
            return;
        }

        $indexes = [];
        foreach ($this->layoutBlocks as $i => $block) {
            if (in_array((string) ($block['id'] ?? ''), $ids, true)) {
                $indexes[] = $i;
            }
        }
        if (count($indexes) < 2) {
            return;
        }

        $tops = [];
        $bottoms = [];
        $mids = [];
        foreach ($indexes as $i) {
            $y = (float) ($this->layoutBlocks[$i]['y'] ?? 0);
            $h = max(0.01, (float) ($this->layoutBlocks[$i]['h'] ?? 0.3));
            $tops[] = $y;
            $bottoms[] = $y + $h;
            $mids[] = $y + ($h / 2);
        }

        $verticalAlign = strtolower(trim($verticalAlign));
        foreach ($indexes as $i) {
            $h = max(0.01, (float) ($this->layoutBlocks[$i]['h'] ?? 0.3));
            if ($verticalAlign === 'top') {
                $y = min($tops);
            } elseif ($verticalAlign === 'bottom') {
                $y = max($bottoms) - $h;
            } elseif (in_array($verticalAlign, ['middle', 'mid', 'center'], true)) {
                $y = (array_sum($mids) / count($mids)) - ($h / 2);
            } else {
                continue;
            }
            $y = max(0.0, min(round($y, 3), max(0.0, round($this->pageHeight - $h, 3))));
            $this->layoutBlocks[$i]['y'] = $y;
            $this->layoutBlocks[$i]['section'] = DcsPrintTemplateHelper::normalizeSection(null, $y);
        }
    }

    public function moveColumn(int $index, string $dir): void
    {
        $swap = $dir === 'up' ? $index - 1 : $index + 1;
        if (! isset($this->columns[$index], $this->columns[$swap])) {
            return;
        }
        $this->pushColumnsHistory();
        $tmp = $this->columns[$index];
        $this->columns[$index] = $this->columns[$swap];
        $this->columns[$swap] = $tmp;
        $this->columns = array_values($this->columns);
        $this->touchLivePreview();
    }

    public function toggleColumnVisible(int $index): void
    {
        if (! isset($this->columns[$index])) {
            return;
        }
        $this->pushColumnsHistory();
        $this->columns[$index]['visible'] = empty($this->columns[$index]['visible']);
        $this->touchLivePreview();
    }

    public function addColumn(): void
    {
        $catalog = DcsPrintTemplateHelper::fieldCatalog($this->selectedKey);
        $used = collect($this->columns)->pluck('key')->all();
        $pick = null;
        foreach ($catalog as $c) {
            if (! in_array($c['key'], $used, true)) {
                $pick = $c;
                break;
            }
        }
        if (! $pick) {
            $pick = $catalog[0] ?? ['key' => 'custom', 'label' => 'Custom'];
        }
        $this->pushColumnsHistory();
        $this->columns[] = [
            'key' => $pick['key'],
            'label' => $pick['label'],
            'width' => '12%',
            'visible' => true,
            'group' => null,
        ];
        $this->touchLivePreview();
    }

    public function removeColumn(int $index): void
    {
        if (! isset($this->columns[$index])) {
            return;
        }
        $this->pushColumnsHistory();
        unset($this->columns[$index]);
        $this->columns = array_values($this->columns);
        $this->touchLivePreview();
    }

    public function addGroupHeader(): void
    {
        $this->pushColumnsHistory();
        $this->groupHeaders[] = ['label' => 'Group', 'span' => 1];
        $this->touchLivePreview();
    }

    public function removeGroupHeader(int $index): void
    {
        if (! isset($this->groupHeaders[$index])) {
            return;
        }
        $this->pushColumnsHistory();
        unset($this->groupHeaders[$index]);
        $this->groupHeaders = array_values($this->groupHeaders);
        $this->touchLivePreview();
    }

    public function with(): array
    {
        $list = [];
        foreach (DcsPrintTemplateHelper::defaultSeeds() as $key => $seed) {
            $tpl = DcsPrintTemplateHelper::getDraft($key);
            $list[] = [
                'key' => $key,
                'label' => (string) ($tpl->label ?? $seed['label']),
                'form_code' => (string) ($tpl->form_code ?? ''),
                'has_layout' => DcsPrintTemplateHelper::layoutBlocks($tpl) !== [],
                'has_columns' => DcsPrintTemplateHelper::columnsPayload($tpl)['columns'] !== [],
            ];
        }

        $liveStatus = DcsPrintTemplateHelper::liveStatus($this->selectedKey);

        $selectedBlock = null;
        foreach ($this->layoutBlocks as $block) {
            if ((string) ($block['id'] ?? '') === (string) $this->selectedBlockId) {
                $selectedBlock = $block;
                break;
            }
        }

        $blocksBySection = ['header' => [], 'body' => [], 'footer' => []];
        foreach ($this->layoutBlocks as $block) {
            $sec = DcsPrintTemplateHelper::normalizeSection($block['section'] ?? null, (float) ($block['y'] ?? 0));
            $blocksBySection[$sec][] = $block;
        }

        $isFormLayout = in_array($this->selectedKey, ['office_drf', 'office_dcn'], true);
        $previewKey = '';
        // Keep live preview HTML fresh on every editor tab that shows the official form.
        if (in_array($this->editorTab, ['identity', 'layout', 'columns', 'preview'], true)) {
            $previewKey = $this->pushLivePreviewToSession();
        }

        $liveRevisionId = isset($liveStatus['live_revision_id']) ? (int) $liveStatus['live_revision_id'] : 0;
        $layoutRevisions = DcsPrintTemplateHelper::listRevisions(
            $this->selectedKey,
            15,
            $this->editorTab === 'columns' ? 'columns' : 'layout'
        );
        $liveIsOfficial = false;
        foreach ($layoutRevisions as &$revRow) {
            $revRow['is_live'] = $liveRevisionId > 0 && (int) ($revRow['id'] ?? 0) === $liveRevisionId;
            if (! empty($revRow['is_live']) && ! empty($revRow['is_official'])) {
                $liveIsOfficial = true;
            }
        }
        unset($revRow);

        // Once a customized (non-Official) version is live, hide Official so users
        // cannot revert office intake back to stock from Saved versions.
        if ($liveRevisionId > 0 && ! $liveIsOfficial) {
            $layoutRevisions = array_values(array_filter(
                $layoutRevisions,
                static fn ($rev) => empty($rev['is_official'])
            ));
        }

        $selectedIsAlreadyLive = $this->selectedLiveRevisionId
            && $liveRevisionId > 0
            && (int) $this->selectedLiveRevisionId === $liveRevisionId;

        return [
            'templates' => $list,
            'fieldCatalog' => DcsPrintTemplateHelper::fieldCatalog($this->selectedKey),
            'selectedBlock' => $selectedBlock,
            'blocksBySection' => $blocksBySection,
            'isFormLayout' => $isFormLayout,
            'isColumnTemplate' => ! $isFormLayout,
            'liveStatus' => $liveStatus,
            'previewKey' => $previewKey !== '' ? $previewKey : (string) session('dcs_print_tpl_live_preview_key', ''),
            'livePreviewUrl' => route('admin.dcs.form-templates.live-preview'),
            'tableReady' => $this->tableIsReady(),
            'fontFamilies' => ['Arial', 'Times New Roman', 'Calibri', 'Georgia', 'Helvetica', 'Courier New', 'Verdana'],
            'pageSizes' => DcsPrintTemplateHelper::pageSizes(),
            'canvasScale' => 72,
            'layoutRevisions' => $layoutRevisions,
            'selectedIsAlreadyLive' => $selectedIsAlreadyLive,
            'hasPreviousLive' => DcsPrintTemplateHelper::hasPreviousLive($this->selectedKey),
            'identityLogoSrc' => $this->liveLogoSrc(),
            'selectedTemplateMeta' => collect($list)->firstWhere('key', $this->selectedKey) ?? [
                'key' => $this->selectedKey,
                'label' => $this->label,
                'form_code' => $this->formCode,
                'has_layout' => false,
                'has_columns' => false,
            ],
        ];
    }

    /**
     * Persist current editor HTML for the iframe preview route.
     */
    private function pushLivePreviewToSession(): string
    {
        $isFormLayout = in_array($this->selectedKey, ['office_drf', 'office_dcn'], true);
        $html = $this->renderLivePreviewHtml($isFormLayout);
        // Strip Livewire morph markers that can appear when rendering Blade during a Livewire request.
        $html = preg_replace('/<!--\[if\s+(?:END)?BLOCK\]>.*?<!\[endif\]-->/s', '', $html) ?? $html;
        // Include template key + bump so switching templates never reuses another form's preview.
        $key = md5($this->selectedKey . '|' . $html . '|' . $this->previewBump);
        session([
            'dcs_print_tpl_live_preview_html' => $html,
            'dcs_print_tpl_live_preview_key' => $key,
        ]);

        return $key;
    }

    private function tableIsReady(): bool
    {
        try {
            return Schema::hasTable(DcsPrintTemplateHelper::TABLE);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Build a template object from the current editor state (including unsaved edits).
     */
    private function buildLiveTpl(): object
    {
        return (object) [
            'key' => $this->selectedKey,
            'label' => $this->label,
            'form_code' => $this->formCode,
            'form_title' => $this->formTitle,
            'republic' => $this->republic,
            'institution_name' => $this->institutionName,
            'institution_address' => $this->institutionAddress,
            'footer_left' => $this->footerLeft,
            'footer_center' => $this->footerCenter,
            'default_effectivity' => $this->defaultEffectivity,
            'default_rev' => $this->defaultRev,
            'logo_path' => $this->logoPath,
            'layout_json' => ['blocks' => array_values($this->layoutBlocks)],
            'columns_json' => [
                'style' => DcsPrintTemplateHelper::normalizeTableStyle([
                    'header_row_bg' => $this->tableHeaderBg,
                    'header_row_color' => $this->tableHeaderColor,
                    'header_font_family' => $this->tableHeaderFontFamily,
                    'header_font_size_pt' => $this->tableHeaderFontSize,
                    'header_font_weight' => $this->tableHeaderFontWeight,
                    'header_font_style' => $this->tableHeaderFontStyle,
                ]),
                'group_headers' => array_values($this->groupHeaders),
                'columns' => array_values($this->columns),
            ],
            'page_json' => [
                'size' => $this->pageSize,
                'width_in' => $this->pageWidth,
                'height_in' => $this->pageHeight,
                'margin_in' => 0,
            ],
        ];
    }

    private function liveLogoSrc(): string
    {
        if ($this->logoUpload) {
            try {
                return $this->logoUpload->temporaryUrl();
            } catch (\Throwable $e) {
                // fall through
            }
        }

        // Distribution live print crops the seal; DRF/DCN keep the original logo.
        if ($this->selectedKey === 'distribution' && ($this->logoPath === null || trim((string) $this->logoPath) === '')) {
            $cropped = DcsPrintTemplateHelper::croppedLogoDataUrl(public_path('images/logo.png'));
            if ($cropped !== '') {
                return $cropped;
            }
        }

        return DcsPrintTemplateHelper::identityVars($this->buildLiveTpl())['logoSrc'] ?? '';
    }

    /**
     * @return array<string, string>
     */
    private function sampleBindValues(): array
    {
        // Blank preview only — layout/grid still render empty lines/cells.
        return [
            'request_no' => '',
            'drf_date' => '',
            'originator' => '',
            'doc_title' => '',
            'previous_document_no' => '',
            'doc_type' => '',
            'description' => '',
            'distribute_to' => '',
            'prepared_by_name' => '',
            'prepared_by_designation' => '',
            'reviewed_by_name' => '',
            'reviewed_by_designation' => '',
            'approved_by_name' => '',
            'approved_by_designation' => '',
            'dcn_no' => '',
            'document_no' => '',
            'document_title' => '',
            'change_from' => '',
            'change_to' => '',
            'justification' => '',
            'department' => '',
            'reviewed_by' => '',
            'approved_by' => '',
            'form_code' => $this->formCode,
            'form_title' => $this->formTitle,
        ];
    }

    /**
     * Empty masterlist rows for Form Templates preview only (fills one short-bond page).
     *
     * @return list<array<string, string>>
     */
    private function sampleReportRows(): array
    {
        $blank = [
            'item_no' => '',
            'doc_no' => '',
            'rev_no' => '',
            'doc_title' => '',
            'effectivity_date' => '',
            'originator' => '',
            'no_pages' => '',
            'pdf_path' => '',
            'department' => '',
            'signature' => '',
            'dist_date' => '',
            'copies' => '',
            'retrieved_by' => '',
            'ret_date' => '',
            'document_title' => '',
        ];

        // Seed a few empties; masterlist JS (previewPadRows) pads to the footer.
        return array_fill(0, 12, $blank);
    }

    private function renderLivePreviewHtml(bool $isFormLayout): string
    {
        $logoSrc = $this->liveLogoSrc();

        try {
            // Identity / Layout / Preview share one editable canvas preview for DRF + DCN
            // (hydrated from live on open) so the live template look is editable everywhere.
            if ($isFormLayout && in_array($this->editorTab, ['identity', 'layout', 'preview'], true)) {
                return $this->renderLayoutCanvasPreviewHtml($logoSrc);
            }

            return match ($this->selectedKey) {
                'office_drf' => $this->renderOfficialDrfPreviewHtml($logoSrc),
                'office_dcn' => $this->renderOfficialDcnPreviewHtml($logoSrc, false),
                'distribution' => $this->renderOfficialDistributionPreviewHtml($logoSrc),
                default => $this->renderOfficialMasterlistPreviewHtml($logoSrc),
            };
        } catch (\Throwable $e) {
            return '<!DOCTYPE html><html><body style="font-family:Arial;padding:24px;color:#991b1b">Preview error: '
                . e($e->getMessage()) . '</body></html>';
        }
    }

    /**
     * Exact published live_json preview — same source as office intake DRF/DCN print.
     */
    private function renderPublishedLivePreviewHtml(string $logoSrc): string
    {
        $tpl = DcsPrintTemplateHelper::getLive($this->selectedKey);
        $blocks = DcsPrintTemplateHelper::layoutBlocks($tpl);
        $identity = DcsPrintTemplateHelper::identityVars($tpl);
        if (($identity['logoSrc'] ?? '') !== '') {
            $logoSrc = $identity['logoSrc'];
        }

        // DRF / DCN: same canvas renderer as Layout so Identity live preview matches Layout.
        if ($blocks === []) {
            return match ($this->selectedKey) {
                'office_dcn' => $this->renderOfficialDcnPreviewHtml($logoSrc, false),
                default => $this->renderOfficialDrfPreviewHtml($logoSrc),
            };
        }

        $page = DcsPrintTemplateHelper::pageSpec($tpl);
        $values = array_merge($this->sampleBindValues(), [
            'republic' => $identity['republic'] ?? '',
            'institution_name' => $identity['institutionName'] ?? '',
            'institution_address' => $identity['institutionAddress'] ?? '',
            'form_code' => $identity['letterNumber'] ?? '',
            'form_title' => $identity['formTitle'] ?? '',
            'default_effectivity' => $identity['footerEffectivity'] ?? '',
            'default_rev' => $identity['footerRev'] ?? '',
        ]);

        return view('pages.dcs.reports.print-layout-renderer', [
            'tpl' => $tpl,
            'values' => $values,
            'blocksOverride' => $blocks,
            'logoSrcOverride' => $logoSrc,
            'identityOverride' => [
                'republic' => $identity['republic'] ?? 'Republic of the Philippines',
                'institutionName' => $identity['institutionName'] ?? 'Camarines Sur Polytechnic Colleges',
                'institutionAddress' => $identity['institutionAddress'] ?? 'Nabua, Camarines Sur',
                'letterNumber' => $identity['letterNumber'] ?? '',
                'formTitle' => $identity['formTitle'] ?? '',
                'footerLeft' => $identity['footerLeft'] ?? '',
                'footerCenter' => $identity['footerCenter'] ?? '',
                'footerEffectivity' => $identity['footerEffectivity'] ?? '',
                'footerRev' => $identity['footerRev'] ?? '',
                'logoSrc' => $logoSrc,
            ],
            'pageOverride' => [
                'size' => $page['size'],
                'width_in' => $page['width_in'],
                'height_in' => $page['height_in'],
                'margin_in' => 0,
            ],
            'preview' => true,
            'hideToolbar' => true,
            'embed' => true,
        ])->render();
    }

    /**
     * Editable form preview driven by current canvas blocks + identity.
     * DCN uses the official CSPC-F-DCC-01 blade (same look as Identity) with live canvas overrides.
     * DRF uses the canvas layout renderer.
     */
    private function renderLayoutCanvasPreviewHtml(string $logoSrc): string
    {
        // DCN: always the real Document Change Notice form — canvas edits flow in via overrides.
        if ($this->selectedKey === 'office_dcn') {
            return $this->renderOfficialDcnPreviewHtml($logoSrc, true);
        }

        if ($this->layoutBlocks === []) {
            return $this->renderOfficialDrfPreviewHtml($logoSrc);
        }

        $values = array_merge($this->sampleBindValues(), [
            'republic' => $this->republic,
            'institution_name' => $this->institutionName,
            'institution_address' => $this->institutionAddress,
            'form_code' => $this->formCode,
            'form_title' => $this->formTitle,
            'default_effectivity' => $this->defaultEffectivity,
            'default_rev' => $this->defaultRev,
        ]);

        return view('pages.dcs.reports.print-layout-renderer', [
            'tpl' => $this->buildLiveTpl(),
            'values' => $values,
            'blocksOverride' => array_values($this->layoutBlocks),
            'logoSrcOverride' => $logoSrc,
            'identityOverride' => [
                'republic' => trim($this->republic) !== '' ? trim($this->republic) : 'Republic of the Philippines',
                'institutionName' => trim($this->institutionName) !== '' ? trim($this->institutionName) : 'Camarines Sur Polytechnic Colleges',
                'institutionAddress' => trim($this->institutionAddress) !== '' ? trim($this->institutionAddress) : 'Nabua, Camarines Sur',
                'letterNumber' => trim($this->formCode) !== '' ? trim($this->formCode) : '',
                'formTitle' => trim($this->formTitle) !== '' ? trim($this->formTitle) : '',
                'footerLeft' => $this->footerLeft,
                'footerCenter' => $this->footerCenter,
                'footerEffectivity' => $this->defaultEffectivity,
                'footerRev' => $this->defaultRev,
                'logoSrc' => $logoSrc,
            ],
            'pageOverride' => [
                'size' => $this->pageSize,
                'width_in' => $this->pageWidth,
                'height_in' => $this->pageHeight,
                'margin_in' => 0,
            ],
            'preview' => true,
            'hideToolbar' => true,
            'embed' => true,
        ])->render();
    }

    private function liveColumnsPayload(): array
    {
        return [
            'style' => DcsPrintTemplateHelper::normalizeTableStyle([
                'header_row_bg' => $this->tableHeaderBg,
                'header_row_color' => $this->tableHeaderColor,
                'header_font_family' => $this->tableHeaderFontFamily,
                'header_font_size_pt' => $this->tableHeaderFontSize,
                'header_font_weight' => $this->tableHeaderFontWeight,
                'header_font_style' => $this->tableHeaderFontStyle,
            ]),
            'group_headers' => array_values($this->groupHeaders),
            'columns' => array_values($this->columns),
        ];
    }

    private function renderOfficialDistributionPreviewHtml(string $logoSrc): string
    {
        $seed = DcsPrintTemplateHelper::defaultSeeds()['distribution'] ?? [];
        $seedCols = is_array($seed['columns_json'] ?? null) ? $seed['columns_json'] : [];
        $officialStyle = DcsPrintTemplateHelper::normalizeTableStyle(
            is_array($seedCols['style'] ?? null) ? $seedCols['style'] : ['header_row_bg' => '#ffffff']
        );

        // Identity + Columns + Preview share one draft: header/footer + table stay in sync.
        $payload = $this->liveColumnsPayload();
        if (strtolower((string) ($payload['style']['header_row_bg'] ?? '')) === '#8db3e2') {
            $payload['style'] = $officialStyle;
        }
        if (($payload['group_headers'] ?? []) === [] && is_array($seedCols['group_headers'] ?? null)) {
            $payload['group_headers'] = $seedCols['group_headers'];
        }
        if (count($payload['columns'] ?? []) < 6 && is_array($seedCols['columns'] ?? null)) {
            $payload['columns'] = $seedCols['columns'];
        }

        // Seed a few empties; blade JS (previewPadRows) pads to ~1 row above the footer.
        $offices = array_fill(0, 12, ['name' => '', 'copies' => '']);

        return view('pages.dcs.reports.distribution-template', [
            'offices' => $offices,
            'date' => now('Asia/Manila')->toDateString(),
            'documentTitle' => '',
            'logoSrc' => $logoSrc,
            'republic' => trim($this->republic) !== '' ? trim($this->republic) : 'Republic of the Philippines',
            'institutionName' => trim($this->institutionName) !== '' ? trim($this->institutionName) : 'Camarines Sur Polytechnic Colleges',
            'institutionAddress' => trim($this->institutionAddress) !== '' ? trim($this->institutionAddress) : 'Nabua, Camarines Sur',
            'letterNumber' => trim($this->formCode) !== '' ? trim($this->formCode) : 'CSPC-F-DCC-05',
            'footerLeft' => trim($this->footerLeft) !== '' ? trim($this->footerLeft) : 'Effectivity Date:',
            'footerCenter' => trim($this->footerCenter) !== '' ? trim($this->footerCenter) : 'Rev.',
            'footerEffectivity' => trim($this->defaultEffectivity) !== '' ? trim($this->defaultEffectivity) : 'August 2019',
            'footerRev' => trim($this->defaultRev) !== '' ? trim($this->defaultRev) : '2',
            'title' => trim($this->formTitle) !== '' ? trim($this->formTitle) : 'DISTRIBUTION AND RETRIEVAL',
            'templateColumns' => $payload,
            'tableHeaderStyle' => $payload['style'],
            'embed' => true,
            'previewPadRows' => true,
            'autoprint' => false,
        ])->render();
    }

    private function renderOfficialMasterlistPreviewHtml(string $logoSrc): string
    {
        $payload = $this->liveColumnsPayload();
        $visibleCols = array_values(array_filter(
            $payload['columns'],
            fn ($c) => is_array($c) && ! empty($c['visible'])
        ));
        $sample = $this->sampleReportRows();
        $rows = collect($sample)->map(function (array $row) use ($visibleCols) {
            $out = [];
            foreach ($visibleCols as $col) {
                $key = (string) ($col['key'] ?? '');
                $out[$key] = $row[$key] ?? '';
            }

            return (object) array_merge($row, $out);
        });

        return view('pages.dcs.reports.export-masterlist-internal', [
            'title' => trim($this->formTitle) !== '' ? trim($this->formTitle) : 'Document Masterlist',
            'columns' => $visibleCols !== [] ? $visibleCols : $payload['columns'],
            'groupHeaders' => $payload['group_headers'],
            'rows' => $rows,
            'isFiltered' => false,
            'selectedCount' => $rows->count(),
            'totalCount' => $rows->count(),
            'dateFrom' => null,
            'dateTo' => null,
            'asOf' => null,
            'period' => 'all',
            'periodLabel' => 'All records',
            'embed' => true,
            'previewPadRows' => true,
            'selectedSubTypeNames' => [],
            'activeSub' => str_replace('masterlist_', '', $this->selectedKey),
            'activeCategory' => 'masterlist',
            'autoPrint' => false,
            'saveAsPdf' => false,
            'plainTable' => false,
            'isMlInternal' => true,
            // Empty string (not null) — null was coalescing to "internal" and drawing a check.
            'checkedType' => '',
            'republic' => trim($this->republic) !== '' ? trim($this->republic) : 'Republic of the Philippines',
            'institutionName' => trim($this->institutionName) !== '' ? trim($this->institutionName) : 'Camarines Sur Polytechnic Colleges',
            'institutionAddress' => trim($this->institutionAddress) !== '' ? trim($this->institutionAddress) : 'Nabua, Camarines Sur',
            'letterNumber' => trim($this->formCode) !== '' ? trim($this->formCode) : 'CSPC-F-DCC-03',
            'footerLeft' => trim($this->footerLeft) !== '' ? trim($this->footerLeft) : 'Effectivity Date:',
            'footerCenter' => trim($this->footerCenter) !== '' ? trim($this->footerCenter) : 'Rev.',
            'footerRight' => '',
            'footerEffectivity' => trim($this->defaultEffectivity) !== '' ? trim($this->defaultEffectivity) : 'January 2025',
            'footerRev' => trim($this->defaultRev) !== '' ? trim($this->defaultRev) : '4',
            'logoSrc' => $logoSrc !== '' ? $logoSrc : null,
            'tableHeaderStyle' => $payload['style'],
            'forcePrintLayout' => true,
        ])->render();
    }

    /**
     * Exact office DRF print blade (CSPC-F-DCC-06) for Form Templates live preview.
     */
    private function renderOfficialDrfPreviewHtml(string $logoSrc): string
    {
        $drf = (object) [
            'id' => 0,
            'doc_title' => '',
            'drf_date' => null,
            'originator_name' => '',
            'doc_type_kind' => '',
            'description_reason' => '',
            'prepared_by_name' => '',
            'prepared_by_designation' => '',
            'reviewed_by_name' => '',
            'reviewed_by_designation' => '',
            'approved_by_name' => '',
            'approved_by_designation' => '',
            'previous_document_no' => '',
            'previous_document_title' => '',
            'previous_originator' => '',
            // Empty list keeps the distribute-to grid lines; no sample offices.
            'preview_distribute_offices' => [],
        ];

        return view('pages.dcs.office.drf-print', [
            'drf' => $drf,
            'logoSrc' => $logoSrc,
            'sourceOffices' => collect(),
            'viewerMode' => false,
            'allowPrint' => true,
            'printLockMessage' => (trim($this->formTitle) !== '' ? trim($this->formTitle) : 'Document Request Form') . ' Preview',
            'hideToolbar' => true,
            'embed' => true,
            'printFormCode' => trim($this->formCode) !== '' ? trim($this->formCode) : 'CSPC-F-DCC-06',
            'printFormTitle' => trim($this->formTitle) !== '' ? trim($this->formTitle) : 'Document Request Form',
            'printRepublic' => trim($this->republic) !== '' ? trim($this->republic) : 'Republic of the Philippines',
            'printInstitution' => trim($this->institutionName) !== '' ? trim($this->institutionName) : 'Camarines Sur Polytechnic Colleges',
            'printAddress' => trim($this->institutionAddress) !== '' ? trim($this->institutionAddress) : 'Nabua, Camarines Sur',
            'printFooterLeft' => trim($this->footerLeft) !== '' ? trim($this->footerLeft) : 'Effectivity Date:',
            'printFooterCenter' => trim($this->footerCenter) !== '' ? trim($this->footerCenter) : 'Rev.',
            'printFooterEffectivity' => trim($this->defaultEffectivity) !== '' ? trim($this->defaultEffectivity) : 'January 2018',
            'printFooterRev' => trim($this->defaultRev) !== '' ? trim($this->defaultRev) : '0',
        ])->render();
    }

    /**
     * Exact office DCN print blade (CSPC-F-DCC-01) for Form Templates live preview.
     * When $fromLayoutEditor, apply canvas header geometry so Layout moves update the preview.
     */
    private function renderOfficialDcnPreviewHtml(string $logoSrc, bool $fromLayoutEditor = false): string
    {
        $dcn = (object) [
            'id' => 0,
            'dcn_no' => '',
            'document_no' => '',
            'document_title' => '',
            'change_from' => '',
            'change_to' => '',
            'brief_purpose' => '',
            'originator_name' => '',
            'department_on' => null,
            'preview_department_code' => '',
            'preview_department' => '',
            'preview_reviewers' => [],
            'approved_by_name' => '',
        ];

        $layoutOverrides = $fromLayoutEditor
            ? DcsPrintTemplateHelper::dcnLayoutOverridesFromBlocks($this->layoutBlocks)
            : [];

        return view('pages.dcs.office.dcn-print', [
            'dcn' => $dcn,
            'logoSrc' => $logoSrc,
            'revisions' => collect(),
            'sourceOffices' => collect(),
            'viewerMode' => false,
            'allowPrint' => true,
            'printLockMessage' => (trim($this->formTitle) !== '' ? trim($this->formTitle) : 'Document Change Notice') . ' Preview',
            'hideToolbar' => true,
            'embed' => true,
            'printFormCode' => trim($this->formCode) !== '' ? trim($this->formCode) : 'CSPC-F-DCC-01',
            'printFormTitle' => trim($this->formTitle) !== '' ? trim($this->formTitle) : 'Document Change Notice',
            'printRepublic' => trim($this->republic) !== '' ? trim($this->republic) : 'Republic of the Philippines',
            'printInstitution' => trim($this->institutionName) !== '' ? trim($this->institutionName) : 'Camarines Sur Polytechnic Colleges',
            'printAddress' => trim($this->institutionAddress) !== '' ? trim($this->institutionAddress) : 'Nabua, Camarines Sur',
            'printFooterLeft' => trim($this->footerLeft) !== '' ? trim($this->footerLeft) : 'Effectivity Date:',
            'printFooterCenter' => trim($this->footerCenter) !== '' ? trim($this->footerCenter) : 'Rev.',
            'printFooterEffectivity' => trim($this->defaultEffectivity) !== '' ? trim($this->defaultEffectivity) : 'January 2018',
            'printFooterRev' => trim($this->defaultRev) !== '' ? trim($this->defaultRev) : '1',
            'layoutOverrides' => $layoutOverrides,
        ])->render();
    }

}; ?>


<div class="dcs-tpl-page">
    <div class="dcs-tpl-head">
        <div>
            <p class="eyebrow">Document Control System</p>
            <h1>Form Templates</h1>
            <p>Design printable DRF/DCN layouts and Generate Report column styles.</p>
        </div>
    </div>

    @if(! $tableReady)
        <div class="alert error"><i class="fa-solid fa-triangle-exclamation"></i> Run migrations to enable <code>dcs_print_form_templates</code>.</div>
    @endif
    @if($successMessage)
        <div class="alert ok"><i class="fa-solid fa-circle-check"></i> {{ $successMessage }}</div>
    @endif
    @if($errorMessage)
        <div class="alert error"><i class="fa-solid fa-circle-xmark"></i> {{ $errorMessage }}</div>
    @endif

    <div class="dcs-tpl-toolbar">
        <label class="tpl-picker">
            <span class="tpl-picker-label">Template</span>
            <select wire:change="selectTemplate($event.target.value)" class="tpl-picker-select">
                @foreach($templates as $t)
                    <option value="{{ $t['key'] }}" @selected($selectedKey === $t['key'])>
                        {{ $t['label'] }}{{ $t['form_code'] ? ' — '.$t['form_code'] : '' }}
                    </option>
                @endforeach
            </select>
        </label>
        <div class="tpl-picker-meta">
            <span class="tpl-code">{{ $selectedTemplateMeta['form_code'] ?: '—' }}</span>
            @if(! empty($selectedTemplateMeta['has_layout']))<span class="tag">Layout</span>@endif
            @if(! empty($selectedTemplateMeta['has_columns']))<span class="tag">Columns</span>@endif
            @if(! empty($liveStatus['has_live']))
                <span class="tag tag-live" title="Published to office intake / exports">Live{{ ! empty($liveStatus['live_at']) ? ' · '.\Illuminate\Support\Carbon::parse($liveStatus['live_at'])->timezone('Asia/Manila')->format('M j, g:i A') : '' }}</span>
            @else
                <span class="tag tag-draft" title="Exports use official seed until you Enable live">Not live</span>
            @endif
            @if(! empty($liveStatus['draft_differs']))
                <span class="tag tag-diff">Draft differs</span>
            @endif
            <span class="list-count">{{ count($templates) }} templates</span>
        </div>
    </div>

    <section class="dcs-tpl-editor">
            <div class="editor-tabs">
                <button type="button" class="{{ $editorTab === 'identity' ? 'is-active' : '' }}" wire:click="setEditorTab('identity')">
                    <i class="fa-solid fa-id-card"></i> Identity
                </button>
                @if($isFormLayout)
                    <button type="button" class="{{ $editorTab === 'layout' ? 'is-active' : '' }}" wire:click="setEditorTab('layout')">
                        <i class="fa-solid fa-layer-group"></i> Layout
                    </button>
                @endif
                @if($isColumnTemplate)
                    <button type="button" class="{{ $editorTab === 'columns' ? 'is-active' : '' }}" wire:click="setEditorTab('columns')">
                        <i class="fa-solid fa-table-columns"></i> Columns
                    </button>
                @endif
                <button type="button" class="{{ $editorTab === 'preview' ? 'is-active' : '' }}" wire:click="setEditorTab('preview')">
                    <i class="fa-solid fa-eye"></i> Preview
                </button>
            </div>

            @if($editorTab === 'identity')
                <div class="panel layout-panel">
                    <div class="help-callout">
                        @if($isFormLayout)
                            <strong>Identity</strong>
                            <span>Edit branding here — the live preview on the right matches <em>Layout</em>. Open <em>Layout</em> to move blocks, edit labels, and add tables. Save / Update live when ready.</span>
                        @else
                            <strong>Identity</strong>
                            <span>Edit header, form code, footer, and logo here. Table structure is on <em>Columns</em>. After <em>Save columns</em>, select that version under <em>Saved versions</em> to reveal <em>Enable live</em>.</span>
                        @endif
                    </div>
                    <div class="layout-workspace">
                        <div class="layout-edit columns-edit">
                            <div class="sticky-actions" style="margin-bottom:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                                <button type="button" class="btn-primary" wire:click="saveIdentity">
                                    <i class="fa-solid fa-floppy-disk"></i> Save identity
                                </button>
                                @if($isFormLayout)
                                    <button type="button" class="btn-secondary" wire:click="setEditorTab('layout')">
                                        <i class="fa-solid fa-layer-group"></i> Edit on Layout
                                    </button>
                                @else
                                    <button type="button" class="btn-secondary" wire:click="setEditorTab('columns')">
                                        <i class="fa-solid fa-table-columns"></i> Edit on Columns
                                    </button>
                                @endif
                            </div>
                            <div class="card-grid identity-card-grid">
                                <div class="prop-card identity-card">
                                    <div class="prop-card-h"><i class="fa-solid fa-file-signature"></i> Form identity</div>
                                    <div class="field"><label>Label</label><input type="text" wire:model.live="label"></div>
                                    <div class="field"><label>Form code</label><input type="text" wire:model.live="formCode" placeholder="CSPC-F-DCC-03"></div>
                                    <div class="field span-2"><label>Form title</label><input type="text" wire:model.live="formTitle"></div>
                                </div>
                                <div class="prop-card identity-card">
                                    <div class="prop-card-h"><i class="fa-solid fa-building-columns"></i> Institution header</div>
                                    <div class="field span-2"><label>Republic</label><input type="text" wire:model.live="republic"></div>
                                    <div class="field span-2"><label>Institution</label><input type="text" wire:model.live="institutionName"></div>
                                    <div class="field span-2"><label>Address</label><input type="text" wire:model.live="institutionAddress"></div>
                                </div>
                                <div class="prop-card identity-card">
                                    <div class="prop-card-h"><i class="fa-solid fa-shoe-prints"></i> Footer</div>
                                    <div class="field"><label>Footer left label</label><input type="text" wire:model.live="footerLeft" placeholder="Effectivity Date:"></div>
                                    <div class="field"><label>Effectivity</label><input type="text" wire:model.live="defaultEffectivity"></div>
                                    <div class="field"><label>Footer center label</label><input type="text" wire:model.live="footerCenter" placeholder="Rev."></div>
                                    <div class="field"><label>Rev.</label><input type="text" wire:model.live="defaultRev"></div>
                                </div>
                                <div class="prop-card identity-card">
                                    <div class="prop-card-h"><i class="fa-solid fa-file-lines"></i> Page</div>
                                    <div class="field span-2">
                                        <label>Page size</label>
                                        <select wire:model.live="pageSize">
                                            @foreach($pageSizes as $sizeKey => $sizeMeta)
                                                <option value="{{ $sizeKey }}">{{ $sizeMeta['label'] }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <p class="hint span-2" style="margin:0">
                                        @if($isFormLayout)
                                            To change the logo, open <em>Layout</em>, select the logo block, and upload there.
                                        @else
                                            To change the logo, use <em>Columns → Branding</em>.
                                        @endif
                                    </p>
                                </div>
                            </div>
                        </div>
                        <aside class="layout-live">
                            <div class="layout-live-head">
                                <div>
                                    <p class="eyebrow" style="margin:0">{{ trim($formTitle) !== '' ? trim($formTitle) . ' Preview' : 'Live template preview' }}</p>
                                    <p class="hint" style="margin:2px 0 0">{{ $pageWidth }} × {{ $pageHeight }} in · {{ ! empty($liveStatus['has_live']) ? 'live template (editable)' : 'draft' }} · same as Layout</p>
                                </div>
                                <button type="button" class="tool-btn" wire:click="refreshPreview" title="Refresh preview">
                                    <i class="fa-solid fa-rotate"></i><span>Refresh</span>
                                </button>
                            </div>
                            <div class="layout-live-stage" wire:key="identity-side-preview-{{ $previewKey }}-{{ $previewBump }}" x-data x-init="$el.scrollTop = 0">
                                <iframe
                                    class="layout-live-frame"
                                    title="Identity preview"
                                    src="{{ $livePreviewUrl }}?t={{ $previewKey }}&b={{ $previewBump }}&k={{ urlencode($selectedKey) }}"
                                    style="width: {{ $pageWidth * 96 }}px; height: {{ $pageHeight * 96 }}px;"
                                    onload="this.parentElement.scrollTop = 0"
                                ></iframe>
                            </div>
                        </aside>
                    </div>
                </div>
            @endif

            @if($editorTab === 'layout' && $isFormLayout)
                <div class="panel layout-panel">
                    <div class="layout-chrome">
                        <div class="section-tabs" role="tablist">
                            @foreach(['header' => ['1. Header', 'fa-heading'], 'body' => ['2. Body', 'fa-align-left'], 'footer' => ['3. Footer', 'fa-grip-lines']] as $sec => [$secLabel, $secIcon])
                                <button type="button" class="{{ $layoutSection === $sec ? 'is-active' : '' }}" wire:click="setLayoutSection('{{ $sec }}')">
                                    <i class="fa-solid {{ $secIcon }}"></i> {{ $secLabel }}
                                </button>
                            @endforeach
                        </div>
                        <div class="layout-toolbar">
                            <div class="tool-group">
                                @foreach([
                                    'logo' => ['fa-image', 'Logo'],
                                    'text' => ['fa-font', 'Text'],
                                    'field' => ['fa-pen-to-square', 'Field'],
                                    'checkboxes' => ['fa-square-check', 'Checks'],
                                    'ruled_lines' => ['fa-align-justify', 'Lines'],
                                    'dist_grid' => ['fa-table-cells', 'Dist grid'],
                                    'signature' => ['fa-signature', 'Signature'],
                                    'line' => ['fa-minus', 'Line'],
                                    'table' => ['fa-table', 'Table'],
                                ] as $type => [$icon, $label])
                                    <button type="button" class="tool-btn" wire:click="addBlock('{{ $type }}')" title="Add {{ $label }} to {{ $layoutSection }}">
                                        <i class="fa-solid {{ $icon }}"></i><span>{{ $label }}</span>
                                    </button>
                                @endforeach
                            </div>
                            <div class="tool-group">
                                <button type="button" class="tool-btn" wire:click="undoLayout" @disabled(count($undoStack) === 0) title="Undo">
                                    <i class="fa-solid fa-rotate-left"></i><span>Undo</span>
                                </button>
                                <button type="button" class="tool-btn" wire:click="redoLayout" @disabled(count($redoStack) === 0) title="Redo">
                                    <i class="fa-solid fa-rotate-right"></i><span>Redo</span>
                                </button>
                                <button type="button" class="tool-btn danger" wire:click="deleteSelectedBlock" @disabled(count($selectedBlockIds) === 0 && ! $selectedBlockId) title="Delete selected">
                                    <i class="fa-solid fa-trash"></i><span>Delete{{ count($selectedBlockIds) > 1 ? ' ('.count($selectedBlockIds).')' : '' }}</span>
                                </button>
                                @php
                                    $selectedRevMeta = collect($layoutRevisions)->firstWhere('id', (int) $selectedLiveRevisionId);
                                    $selectedIsNamedSave = $selectedLiveRevisionId
                                        && empty($selectedIsAlreadyLive)
                                        && empty($selectedRevMeta['is_official']);
                                    $showSaveAsNew = ! empty($selectedIsAlreadyLive) || $selectedIsNamedSave;
                                    $primarySaveLabel = ! empty($selectedIsAlreadyLive)
                                        ? 'Update live'
                                        : ($selectedIsNamedSave ? 'Save version' : 'Save layout');
                                @endphp
                                <div x-data="{ confirmSave: false, confirmNew: false, confirmReset: false }" style="display:inline-flex;gap:8px;position:relative;align-items:center">
                                    <button type="button" class="tool-btn" @click="confirmReset = true" title="Discard unsaved edits and reload the selected / live version">
                                        <i class="fa-solid fa-clock-rotate-left"></i><span>Reset</span>
                                    </button>
                                    @if($showSaveAsNew)
                                        <button type="button" class="tool-btn" @click="confirmNew = true" title="Copy canvas into a new Saved versions draft">
                                            <i class="fa-solid fa-copy"></i><span>Save as new</span>
                                        </button>
                                    @endif
                                    <button type="button" class="btn-primary" @click="confirmSave = true">
                                        <i class="fa-solid fa-floppy-disk"></i> {{ $primarySaveLabel }}
                                    </button>
                                    <template x-teleport="body">
                                        <div class="tpl-confirm-backdrop" x-show="confirmSave" x-cloak x-transition.opacity @keydown.escape.window="confirmSave = false">
                                            <div class="tpl-confirm-card" role="dialog" aria-modal="true" @click.outside="confirmSave = false">
                                                @if(! empty($selectedIsAlreadyLive))
                                                    <p class="tpl-confirm-title">Update live layout?</p>
                                                    <p class="tpl-confirm-body">Publishes your canvas edits to office intake. Does <strong>not</strong> create a new Saved versions row. Use <em>Save as new</em> if you want a draft first.</p>
                                                    <div class="tpl-confirm-actions">
                                                        <button type="button" class="btn-ghost" @click="confirmSave = false">Cancel</button>
                                                        <button type="button" class="btn-primary" @click="confirmSave = false; $wire.saveLayout()">Update live</button>
                                                    </div>
                                                @elseif($selectedIsNamedSave)
                                                    <p class="tpl-confirm-title">Save this version?</p>
                                                    <p class="tpl-confirm-body">Updates the <strong>selected</strong> Saved versions entry (no new row). Then click <em>Enable live</em> to publish it.</p>
                                                    <div class="tpl-confirm-actions">
                                                        <button type="button" class="btn-ghost" @click="confirmSave = false">Cancel</button>
                                                        <button type="button" class="btn-primary" @click="confirmSave = false; $wire.saveLayout()">Save version</button>
                                                    </div>
                                                @else
                                                    <p class="tpl-confirm-title">Save layout draft?</p>
                                                    <p class="tpl-confirm-body">Creates a <strong>new</strong> Saved versions draft. Select it to reveal Enable live. Optional name labels the entry.</p>
                                                    <label class="field" style="display:block;margin:0 0 14px">
                                                        <span style="display:block;font-size:12px;font-weight:650;color:#475569;margin-bottom:4px">Version name</span>
                                                        <input type="text" wire:model="revisionName" placeholder="e.g. My DRF layout v1" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px">
                                                    </label>
                                                    <div class="tpl-confirm-actions">
                                                        <button type="button" class="btn-ghost" @click="confirmSave = false">Cancel</button>
                                                        <button type="button" class="btn-primary" @click="confirmSave = false; $wire.saveLayout()">OK</button>
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                    </template>
                                    @if($showSaveAsNew)
                                        <template x-teleport="body">
                                            <div class="tpl-confirm-backdrop" x-show="confirmNew" x-cloak x-transition.opacity @keydown.escape.window="confirmNew = false">
                                                <div class="tpl-confirm-card" role="dialog" aria-modal="true" @click.outside="confirmNew = false">
                                                    <p class="tpl-confirm-title">Save as new version?</p>
                                                    <p class="tpl-confirm-body">Copies the current canvas into a <strong>new Saved versions</strong> draft. The previous version and live stay unchanged until you Enable live.</p>
                                                    <label class="field" style="display:block;margin:0 0 14px">
                                                        <span style="display:block;font-size:12px;font-weight:650;color:#475569;margin-bottom:4px">Version name</span>
                                                        <input type="text" wire:model="revisionName" placeholder="e.g. DRF layout v2" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px">
                                                    </label>
                                                    <div class="tpl-confirm-actions">
                                                        <button type="button" class="btn-ghost" @click="confirmNew = false">Cancel</button>
                                                        <button type="button" class="btn-primary" @click="confirmNew = false; $wire.saveLayoutAsNew()">Save as new</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </template>
                                    @endif
                                    <template x-teleport="body">
                                        <div class="tpl-confirm-backdrop" x-show="confirmReset" x-cloak x-transition.opacity @keydown.escape.window="confirmReset = false">
                                            <div class="tpl-confirm-card" role="dialog" aria-modal="true" @click.outside="confirmReset = false">
                                                <p class="tpl-confirm-title">Reset to previous live?</p>
                                                <p class="tpl-confirm-body">
                                                    @if(! empty($hasPreviousLive))
                                                        Restores the <strong>live template from before your last Update live / Enable live</strong>
                                                        (block positions, labels, and branding). This also updates office intake.
                                                    @else
                                                        No earlier live publish is stored yet. This will only discard <strong>unsaved</strong> canvas edits
                                                        and reload the current selected / live version.
                                                    @endif
                                                </p>
                                                <div class="tpl-confirm-actions">
                                                    <button type="button" class="btn-ghost" @click="confirmReset = false">Cancel</button>
                                                    <button type="button" class="btn-primary" @click="confirmReset = false; $wire.resetSelectedVersion()">Reset</button>
                                                </div>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>
                        <div class="layout-restore-bar">
                            @if(count($layoutRevisions) > 0)
                                <label class="revision-pick">
                                    <span>Saved versions</span>
                                    <select wire:change="selectSavedRevision($event.target.value)">
                                        <option value="0" disabled @selected(! $selectedLiveRevisionId)>Select a saved layout…</option>
                                        @foreach($layoutRevisions as $rev)
                                            @php
                                                $revName = trim((string) ($rev['label'] ?? ''));
                                                if ($revName === '') {
                                                    $revName = ! empty($rev['is_official']) ? 'Official (stock)' : 'Saved layout';
                                                }
                                                if (! empty($rev['is_live'])) {
                                                    $revName .= ' · Live';
                                                } elseif (! empty($rev['is_official'])) {
                                                    $revName .= ' · Original';
                                                }
                                            @endphp
                                            <option value="{{ $rev['id'] }}" @selected($selectedLiveRevisionId === (int) $rev['id'])>
                                                {{ $revName }}@if(empty($rev['is_official']) && ! empty($rev['created_at'])) · {{ $rev['created_at'] }}@endif
                                            </option>
                                        @endforeach
                                    </select>
                                </label>
                            @endif
                            @if($selectedLiveRevisionId && $selectedIsAlreadyLive)
                                <span class="tag tag-live" title="This saved version is already published">Already live</span>
                                <span class="enable-live-hint"><em>Update live</em> publishes edits · <em>Save as new</em> creates a draft first</span>
                            @elseif($selectedLiveRevisionId)
                                <div class="enable-live-actions" x-data="{ confirmLive: false }">
                                    <button type="button" class="btn-primary enable-live-btn" @click="confirmLive = true">
                                        <i class="fa-solid fa-bolt"></i> Enable live
                                    </button>
                                    <span class="enable-live-hint">Publish this version (replaces current live)</span>
                                    <template x-teleport="body">
                                        <div class="tpl-confirm-backdrop" x-show="confirmLive" x-cloak x-transition.opacity @keydown.escape.window="confirmLive = false">
                                            <div class="tpl-confirm-card" role="dialog" aria-modal="true" @click.outside="confirmLive = false">
                                                <p class="tpl-confirm-title">Enable live?</p>
                                                <p class="tpl-confirm-body">Publish the <strong>selected saved version</strong> to office intake. The previous live version is replaced.</p>
                                                <div class="tpl-confirm-actions">
                                                    <button type="button" class="btn-ghost" @click="confirmLive = false">Cancel</button>
                                                    <button type="button" class="btn-primary" @click="confirmLive = false; $wire.enableLive()">
                                                        <i class="fa-solid fa-bolt"></i> Enable live
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            @endif
                        </div>
                        <p class="hint bar-hint warn-hint"><i class="fa-solid fa-circle-info"></i> Previous save: <em>Save version</em> updates it, then <em>Enable live</em>. On live: <em>Update live</em> or <em>Save as new</em>. @if($selectedKey === 'office_dcn')Right preview is the official CSPC-F-DCC-01 form — edit blocks here and it updates live.@endif</p>
                        <p class="hint bar-hint">Adding to <strong>{{ strtoupper($layoutSection) }}</strong> · click = select · <kbd>Ctrl</kbd>/<kbd>⌘</kbd>+click = multi · drag to move · {{ $pageWidth }}×{{ $pageHeight }} in</p>
                    </div>

                    <div class="layout-workspace">
                    <div class="layout-edit">
                    <div class="layout-body">
                        <div class="canvas-wrap"
                             data-dcs-canvas
                             data-scale="{{ (float) $canvasScale }}"
                             data-page-w="{{ (float) $pageWidth }}"
                             data-page-h="{{ (float) $pageHeight }}">
                            <div class="canvas true-page"
                                 wire:click.self="clearBlockSelection"
                                 style="width: {{ $pageWidth * $canvasScale }}px; height: {{ $pageHeight * $canvasScale }}px;">
                                <div class="guide guide-header" style="top: 0; height: {{ 0.95 * $canvasScale }}px;">HEADER</div>
                                <div class="guide guide-body" style="top: {{ 0.95 * $canvasScale }}px; height: {{ max(40, ($pageHeight - 0.95 - 1.2) * $canvasScale) }}px;">BODY</div>
                                <div class="guide guide-footer" style="top: {{ max(0, ($pageHeight - 1.2) * $canvasScale) }}px; height: {{ 1.2 * $canvasScale }}px;">FOOTER</div>
                                @foreach($layoutBlocks as $block)
                                    @php
                                        $scale = $canvasScale;
                                        $bType = (string) ($block['type'] ?? 'block');
                                        $left = ((float) ($block['x'] ?? 0)) * $scale;
                                        $top = ((float) ($block['y'] ?? 0)) * $scale;
                                        $wPx = max(18, ((float) ($block['w'] ?? 1)) * $scale);
                                        $hPx = max($bType === 'line' ? 4 : 12, ((float) ($block['h'] ?? 0.3)) * $scale);
                                        $id = (string) ($block['id'] ?? '');
                                        $sec = \App\Helpers\DcsPrintTemplateHelper::normalizeSection($block['section'] ?? null, (float) ($block['y'] ?? 0));
                                        $st = \App\Helpers\DcsPrintTemplateHelper::normalizeBlockStyle(is_array($block['style'] ?? null) ? $block['style'] : []);
                                        $lineColor = $st['lineColor'] ?? '#0d2a7a';
                                        $isSelected = in_array($id, $selectedBlockIds, true) || $selectedBlockId === $id;
                                    @endphp
                                    <button type="button"
                                        wire:key="canvas-block-{{ $id }}"
                                        data-block-id="{{ $id }}"
                                        class="canvas-block type-{{ $bType }} section-{{ $sec }} {{ $isSelected ? 'is-selected' : '' }}"
                                        style="left: {{ $left }}px; top: {{ $top }}px; width: {{ $wPx }}px; height: {{ $hPx }}px;
                                               font-family: {{ $st['fontFamily'] }}, sans-serif; font-size: {{ max(7, $st['fontSizePt'] * 0.75) }}pt;
                                               font-weight: {{ $st['fontWeight'] }}; font-style: {{ $st['fontStyle'] }};
                                               text-align: {{ $st['align'] }}; color: {{ $st['color'] }};
                                               {{ $st['backgroundColor'] !== '' ? 'background:'.$st['backgroundColor'].';' : '' }}"
                                        wire:click.stop="selectBlock('{{ $id }}')"
                                        title="Click to select · drag to move · Ctrl/⌘+click multi · {{ $sec }} · {{ $bType }}">
                                        @if($bType === 'line')
                                            <span class="canvas-line-bar" style="background: {{ $lineColor }};"></span>
                                            <span class="type">line</span>
                                        @elseif($bType === 'table')
                                            @php
                                                $cCols = max(1, min(12, (int) ($block['table_cols'] ?? 3)));
                                                $cRows = max(1, min(12, (int) ($block['table_rows'] ?? 3)));
                                                $cBorders = \App\Helpers\DcsPrintTemplateHelper::tableBordersVisible($block);
                                                $cCells = \App\Helpers\DcsPrintTemplateHelper::padTableCells($block['table_cells'] ?? [], $cRows, $cCols);
                                                $legacyHeaders = is_array($block['table_headers'] ?? null) ? $block['table_headers'] : [];
                                                $legacyLabels = is_array($block['table_row_labels'] ?? null) ? $block['table_row_labels'] : [];
                                            @endphp
                                            <span class="type">table</span>
                                            <table class="canvas-table-grid {{ $cBorders ? '' : 'no-borders' }}" aria-hidden="true">
                                                @for($cr = 0; $cr < $cRows; $cr++)
                                                    <tr>
                                                        @for($cc = 0; $cc < $cCols; $cc++)
                                                            @php
                                                                $cellTxt = (string) ($cCells[$cr][$cc] ?? '');
                                                                if ($cellTxt === '' && $cr === 0 && isset($legacyHeaders[$cc])) {
                                                                    $cellTxt = (string) $legacyHeaders[$cc];
                                                                }
                                                                if ($cellTxt === '' && $cc === 0 && isset($legacyLabels[$cr])) {
                                                                    $cellTxt = (string) $legacyLabels[$cr];
                                                                }
                                                            @endphp
                                                            <td>{{ $cellTxt !== '' ? $cellTxt : '·' }}</td>
                                                        @endfor
                                                    </tr>
                                                @endfor
                                            </table>
                                        @elseif($bType === 'checkboxes')
                                            <span class="type">checks</span>
                                            <span class="lbl">{{ $block['label'] ?: 'Checkboxes' }}</span>
                                            <span class="canvas-checks-preview" aria-hidden="true">☐ Internal &nbsp; ☐ External</span>
                                        @elseif($bType === 'ruled_lines')
                                            <span class="type">lines</span>
                                            <span class="lbl">{{ $block['label'] ?: 'Ruled lines' }}</span>
                                            <span class="canvas-ruled-preview" aria-hidden="true"></span>
                                        @elseif($bType === 'dist_grid')
                                            <span class="type">dist</span>
                                            <span class="lbl">{{ $block['label'] ?: 'Distribution grid' }}</span>
                                            <span class="canvas-dist-preview" aria-hidden="true"></span>
                                        @else
                                            <span class="type">{{ $bType }}</span>
                                            <span class="lbl">{{ $block['label'] ?: ($block['bind'] ?? '') }}</span>
                                        @endif
                                    </button>
                                @endforeach
                            </div>
                        </div>

                        <aside class="block-props">
                            @if(count($layoutBlocks) > 0)
                                <div class="prop-card block-picker">
                                    <div class="prop-card-h" title="Number of blocks on this template canvas">Canvas blocks ({{ count($layoutBlocks) }})</div>
                                    <div class="block-picker-list">
                                        @foreach($layoutBlocks as $pick)
                                            @php
                                                $pid = (string) ($pick['id'] ?? '');
                                                $pType = (string) ($pick['type'] ?? 'block');
                                                $pLabel = (string) ($pick['label'] ?: ($pick['bind'] ?? $pType));
                                                $pOn = in_array($pid, $selectedBlockIds, true) || $selectedBlockId === $pid;
                                            @endphp
                                            <button type="button"
                                                class="block-picker-item {{ $pOn ? 'is-on' : '' }}"
                                                wire:click="selectBlock('{{ $pid }}')"
                                                title="{{ $pType }}">
                                                <span class="bp-type">{{ $pType }}</span>
                                                <span class="bp-label">{{ $pLabel }}</span>
                                            </button>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                            @if($selectedBlock)
                                @php
                                    $st = \App\Helpers\DcsPrintTemplateHelper::normalizeBlockStyle(is_array($selectedBlock['style'] ?? null) ? $selectedBlock['style'] : []);
                                    $blockType = (string) ($selectedBlock['type'] ?? 'text');
                                    $tableCols = max(1, (int) ($selectedBlock['table_cols'] ?? 4));
                                    $tableRows = max(1, (int) ($selectedBlock['table_rows'] ?? 4));
                                    $multiCount = count($selectedBlockIds);
                                @endphp
                                <div class="props-stack" wire:key="block-props-{{ $selectedBlockId }}-{{ $multiCount }}-{{ md5((string) ($selectedBlock['label'] ?? '').'|'.(string) ($selectedBlock['x'] ?? '').'|'.(string) ($selectedBlock['y'] ?? '')) }}">
                                @if($multiCount > 1)
                                    <div class="multi-banner">
                                        <strong>{{ $multiCount }} blocks selected</strong>
                                        <span>Drag to move together. Align / fonts / colors apply to all. X·Y edit the primary only.</span>
                                    </div>
                                @endif
                                <div class="props-head">
                                    <div>
                                        <p class="eyebrow">{{ $multiCount > 1 ? 'Primary block' : 'Selected block' }}</p>
                                        <h3>{{ ucfirst($blockType) }}</h3>
                                    </div>
                                    <span class="chip chip-{{ $selectedBlock['section'] ?? 'body' }}">{{ ucfirst($selectedBlock['section'] ?? 'body') }}</span>
                                </div>

                                <div class="prop-card">
                                    <div class="prop-card-h">Basics</div>
                                    <div class="form-grid tight">
                                        <label class="field">Section
                                            <select wire:change="updateBlockField('section', $event.target.value)">
                                                @foreach(['header','body','footer'] as $s)
                                                    <option value="{{ $s }}" @selected(($selectedBlock['section'] ?? 'body') === $s)>{{ ucfirst($s) }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="field">Type
                                            <select wire:change="updateBlockField('type', $event.target.value)">
                                                @foreach(['logo','text','field','line','signature','table','spacer'] as $t)
                                                    <option value="{{ $t }}" @selected($blockType === $t)>{{ $t }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="field span-2">Label
                                            <input type="text"
                                                wire:key="block-label-{{ $selectedBlockId }}-{{ md5((string) ($selectedBlock['label'] ?? '')) }}"
                                                value="{{ $selectedBlock['label'] ?? '' }}"
                                                wire:keyup.debounce.400ms="updateBlockField('label', $event.target.value)">
                                        </label>
                                        @if(! in_array($blockType, ['line', 'table', 'logo', 'spacer'], true))
                                        <label class="field span-2">Bind field
                                            <select wire:change="updateBlockField('bind', $event.target.value)">
                                                <option value="">— none —</option>
                                                @foreach($fieldCatalog as $f)
                                                    <option value="{{ $f['key'] }}" @selected(($selectedBlock['bind'] ?? '') === $f['key'])>{{ $f['label'] }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        @endif
                                    </div>
                                </div>

                                <div class="prop-card">
                                    <div class="prop-card-h"><i class="fa-solid fa-up-down-left-right"></i> Position &amp; size</div>
                                    <div class="metric-grid">
                                        <label class="field metric">
                                            <span class="metric-label">X</span>
                                            <span class="metric-field">
                                                <input type="number" step="0.01" value="{{ $selectedBlock['x'] ?? 0 }}" wire:change="updateBlockField('x', $event.target.value)">
                                                <span class="unit">in</span>
                                            </span>
                                        </label>
                                        <label class="field metric">
                                            <span class="metric-label">Y</span>
                                            <span class="metric-field">
                                                <input type="number" step="0.01" value="{{ $selectedBlock['y'] ?? 0 }}" wire:change="updateBlockField('y', $event.target.value)">
                                                <span class="unit">in</span>
                                            </span>
                                        </label>
                                        <label class="field metric">
                                            <span class="metric-label">W</span>
                                            <span class="metric-field">
                                                <input type="number" step="0.01" min="0.05" value="{{ $selectedBlock['w'] ?? 1 }}" wire:change="updateBlockField('w', $event.target.value)">
                                                <span class="unit">in</span>
                                            </span>
                                        </label>
                                        <label class="field metric">
                                            <span class="metric-label">H</span>
                                            <span class="metric-field">
                                                <input type="number" step="0.01" min="{{ $blockType === 'line' ? '0.01' : '0.05' }}" value="{{ $selectedBlock['h'] ?? 0.3 }}" wire:change="updateBlockField('h', $event.target.value)">
                                                <span class="unit">in</span>
                                            </span>
                                        </label>
                                    </div>
                                    <div class="nudge-row mt-8">
                                        <span class="seg-label">Nudge</span>
                                        <div class="seg">
                                            <button type="button" wire:click="nudgeSelected(-0.05, 0)" title="Left">←</button>
                                            <button type="button" wire:click="nudgeSelected(0, -0.05)" title="Up">↑</button>
                                            <button type="button" wire:click="nudgeSelected(0, 0.05)" title="Down">↓</button>
                                            <button type="button" wire:click="nudgeSelected(0.05, 0)" title="Right">→</button>
                                        </div>
                                    </div>
                                    <p class="hint mt-8">Or drag on the canvas · arrow keys nudge (Shift = larger)</p>
                                    @if($blockType === 'logo')
                                        <div class="logo-actions mt-8">
                                            <label class="field">
                                                <span>Replace logo</span>
                                                <input type="file" wire:model="logoUpload" accept="image/*">
                                            </label>
                                            <div class="logo-actions-row">
                                                <button type="button" class="tool-btn" wire:click="addLogoBlock" title="Add another logo block on the canvas">
                                                    <i class="fa-solid fa-plus"></i><span>Add new logo</span>
                                                </button>
                                                <button type="button" class="tool-btn" wire:click="clearCustomLogo" title="Use the default system seal">
                                                    <i class="fa-solid fa-rotate-left"></i><span>Use default</span>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="identity-logo-dd mt-8">
                                            @if($identityLogoSrc !== '')
                                                <img src="{{ $identityLogoSrc }}" alt="Form logo" class="identity-logo-thumb">
                                                <span class="mono">{{ $logoPath !== '' && $logoPath !== null ? $logoPath : 'Default system logo' }}</span>
                                            @else
                                                <span class="mono">No logo</span>
                                            @endif
                                        </div>
                                        <p class="hint mt-8">Replace updates this seal. Add new logo places another logo block. Saves with Save / Update live.</p>
                                        <label class="field mt-8">Logo fit
                                            <select wire:change="updateBlockField('objectFit', $event.target.value)">
                                                @foreach(['contain','cover','fill'] as $fit)
                                                    <option value="{{ $fit }}" @selected(($st['objectFit'] ?? 'contain') === $fit)>{{ $fit }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                    @endif
                                    @if($blockType === 'line')
                                        <label class="field color-row mt-8">Line color
                                            <input type="color" value="{{ $st['lineColor'] ?? '#0d2a7a' }}" wire:change="updateBlockField('lineColor', $event.target.value)">
                                        </label>
                                    @endif
                                    @if($blockType === 'table')
                                        @php
                                            $tableBordersOn = \App\Helpers\DcsPrintTemplateHelper::tableBordersVisible($selectedBlock);
                                            $tableLabelLower = mb_strtolower((string) ($selectedBlock['label'] ?? ''));
                                            $isApprovalsTable = str_contains($tableLabelLower, 'approval');
                                            $isFreeformTable = array_key_exists('table_cells', $selectedBlock) && ! $isApprovalsTable;
                                            $tableHeaders = is_array($selectedBlock['table_headers'] ?? null)
                                                ? array_values($selectedBlock['table_headers'])
                                                : [];
                                            while (count($tableHeaders) < $tableCols) {
                                                $tableHeaders[] = '';
                                            }
                                            $tableHeaders = array_slice($tableHeaders, 0, $tableCols);
                                            $tableCells = $isFreeformTable
                                                ? \App\Helpers\DcsPrintTemplateHelper::padTableCells(
                                                    $selectedBlock['table_cells'] ?? [],
                                                    $tableRows,
                                                    $tableCols
                                                )
                                                : [];
                                            $headerPlaceholders = ['Position', 'Names', 'Signature', 'Date'];
                                        @endphp
                                        <div class="metric-grid mt-8">
                                            <label class="field">Columns
                                                <input type="number" min="1" max="12" value="{{ $tableCols }}" wire:change="updateBlockField('table_cols', $event.target.value)">
                                            </label>
                                            <label class="field">Rows
                                                <input type="number" min="1" max="12" value="{{ $tableRows }}" wire:change="updateBlockField('table_rows', $event.target.value)">
                                            </label>
                                        </div>
                                        <div class="seg-row mt-8">
                                            <span class="seg-label">Borders</span>
                                            <div class="seg">
                                                <button type="button" class="{{ $tableBordersOn ? 'is-on' : '' }}" wire:click="updateBlockField('table_borders', true)">Show</button>
                                                <button type="button" class="{{ ! $tableBordersOn ? 'is-on' : '' }}" wire:click="updateBlockField('table_borders', false)">Hide</button>
                                            </div>
                                        </div>
                                        <div class="table-cell-editor mt-8">
                                            <div class="prop-card-h" style="margin-bottom:6px">Column names</div>
                                            <div class="table-cell-grid table-header-names" style="grid-template-columns: repeat(2, minmax(0, 1fr));">
                                                @for($tc = 0; $tc < $tableCols; $tc++)
                                                    <input type="text"
                                                        value="{{ $tableHeaders[$tc] ?? '' }}"
                                                        placeholder="{{ $headerPlaceholders[$tc] ?? ('Col '.($tc + 1)) }}"
                                                        wire:change="updateTableHeader({{ $tc }}, $event.target.value)"
                                                        title="Column {{ $tc + 1 }} name">
                                                @endfor
                                            </div>
                                        </div>
                                        @if($isFreeformTable)
                                            <div class="table-cell-editor mt-8">
                                                <div class="prop-card-h" style="margin-bottom:6px">Cell text</div>
                                                <div class="table-cell-grid" style="grid-template-columns: repeat({{ $tableCols }}, minmax(0, 1fr));">
                                                    @for($tr = 0; $tr < $tableRows; $tr++)
                                                        @for($tc = 0; $tc < $tableCols; $tc++)
                                                            <input type="text"
                                                                value="{{ $tableCells[$tr][$tc] ?? '' }}"
                                                                placeholder="R{{ $tr + 1 }}C{{ $tc + 1 }}"
                                                                wire:change="updateTableCell({{ $tr }}, {{ $tc }}, $event.target.value)"
                                                                title="Row {{ $tr + 1 }}, column {{ $tc + 1 }}">
                                                        @endfor
                                                    @endfor
                                                </div>
                                            </div>
                                        @endif
                                        <p class="hint mt-8">Edit column names above — they appear on the DCN Approvals header. Add a new Table for freeform cells.</p>
                                    @endif
                                </div>

                                @if(! in_array($blockType, ['line', 'logo', 'spacer'], true))
                                <div class="prop-card">
                                    <div class="prop-card-h"><i class="fa-solid fa-font"></i> Typography</div>
                                    <label class="field">Font family
                                        <select wire:change="updateBlockField('fontFamily', $event.target.value)">
                                            @foreach($fontFamilies as $ff)
                                                <option value="{{ $ff }}" @selected(($st['fontFamily'] ?? 'Arial') === $ff)>{{ $ff }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <div class="metric-grid mt-8">
                                        <label class="field metric">
                                            <span class="metric-label">Size</span>
                                            <span class="metric-field">
                                                <input type="number" step="0.5" min="6" max="72" value="{{ $st['fontSizePt'] ?? 11 }}" wire:change="updateBlockField('fontSizePt', $event.target.value)">
                                                <span class="unit">pt</span>
                                            </span>
                                        </label>
                                        <label class="field">Weight
                                            <select wire:change="updateBlockField('fontWeight', $event.target.value)">
                                                @foreach([400 => 'Regular', 600 => 'Semibold', 700 => 'Bold', 800 => 'Extra bold'] as $fw => $fwLabel)
                                                    <option value="{{ $fw }}" @selected((int) ($st['fontWeight'] ?? 400) === $fw)>{{ $fwLabel }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                    </div>
                                    <div class="seg-row mt-8">
                                        <span class="seg-label">Style</span>
                                        <div class="seg">
                                            <button type="button" class="{{ ($st['fontStyle'] ?? 'normal') === 'normal' ? 'is-on' : '' }}" wire:click="updateBlockField('fontStyle', 'normal')">Aa</button>
                                            <button type="button" class="{{ ($st['fontStyle'] ?? '') === 'italic' ? 'is-on' : '' }}" wire:click="updateBlockField('fontStyle', 'italic')"><em>Aa</em></button>
                                            <button type="button" class="{{ ! empty($st['underline']) ? 'is-on' : '' }}" wire:click="updateBlockField('underline', {{ empty($st['underline']) ? 'true' : 'false' }})"><span class="u">U</span></button>
                                        </div>
                                    </div>
                                    <div class="seg-row mt-8">
                                        <span class="seg-label">Align</span>
                                        <div class="seg">
                                            @foreach(['left'=>'fa-align-left','center'=>'fa-align-center','right'=>'fa-align-right','justify'=>'fa-align-justify'] as $aVal => $aIcon)
                                                <button type="button" class="{{ ($st['align'] ?? 'left') === $aVal ? 'is-on' : '' }}" wire:click="updateBlockField('align', '{{ $aVal }}')" title="{{ ucfirst($aVal) }}">
                                                    <i class="fa-solid {{ $aIcon }}"></i>
                                                </button>
                                            @endforeach
                                        </div>
                                    </div>
                                    <div class="seg-row mt-8">
                                        <span class="seg-label">Vertical</span>
                                        <div class="seg">
                                            @foreach(['top'=>'Top','middle'=>'Mid','bottom'=>'Bot'] as $vVal => $vLabel)
                                                <button type="button" class="{{ ($st['verticalAlign'] ?? 'top') === $vVal ? 'is-on' : '' }}" wire:click="updateBlockField('verticalAlign', '{{ $vVal }}')">{{ $vLabel }}</button>
                                            @endforeach
                                        </div>
                                    </div>
                                    <div class="color-pair mt-8">
                                        <label class="field color-row">Text
                                            <input type="color" value="{{ $st['color'] ?? '#000000' }}" wire:change="updateBlockField('color', $event.target.value)">
                                        </label>
                                        <label class="field color-row">Fill
                                            <input type="color" value="{{ ($st['backgroundColor'] ?? '') !== '' ? $st['backgroundColor'] : '#ffffff' }}" wire:change="updateBlockField('backgroundColor', $event.target.value)">
                                        </label>
                                    </div>
                                </div>
                                @endif
                                </div>
                            @else
                                <div class="props-empty">
                                    <i class="fa-solid fa-hand-pointer"></i>
                                    <p>Select a block on the canvas to edit its properties.</p>
                                    <p class="hint" style="margin-top:8px">Click a block to select. <kbd>Ctrl</kbd>/<kbd>⌘</kbd>+click for multiple, then drag to move them together.</p>
                                </div>
                            @endif
                        </aside>
                    </div>
                    </div>{{-- /.layout-edit --}}

                    <aside class="layout-live">
                        <div class="layout-live-head">
                            <div>
                                <p class="eyebrow" style="margin:0">{{ trim($formTitle) !== '' ? trim($formTitle) . ' Preview' : 'Document Change Notice Preview' }}</p>
                                <p class="hint" style="margin:2px 0 0">
                                    {{ $pageWidth }} × {{ $pageHeight }} in
                                    · official form
                                    · edits on canvas update this preview
                                </p>
                            </div>
                            <button type="button" class="tool-btn" wire:click="refreshPreview" title="Refresh preview">
                                <i class="fa-solid fa-rotate"></i><span>Refresh</span>
                            </button>
                        </div>
                        <div class="layout-live-stage" wire:key="layout-side-preview-{{ $previewKey }}-{{ $previewBump }}" x-data x-init="$el.scrollTop = 0">
                            <iframe
                                class="layout-live-frame"
                                title="Layout canvas live preview"
                                src="{{ $livePreviewUrl }}?t={{ $previewKey }}&b={{ $previewBump }}&k={{ urlencode($selectedKey) }}"
                                style="width: {{ $pageWidth * 96 }}px; height: {{ $pageHeight * 96 }}px;"
                                onload="this.parentElement.scrollTop = 0"
                            ></iframe>
                        </div>
                    </aside>
                    </div>{{-- /.layout-workspace --}}
                </div>
            @endif

            @if($editorTab === 'columns' && $isColumnTemplate)
                <div class="panel layout-panel" x-data="{ confirmReset: false, confirmSave: false, confirmNew: false }">
                    <div class="layout-chrome">
                        <div class="layout-toolbar">
                            <div class="tool-group">
                                <button type="button" class="tool-btn" wire:click="addColumn"><i class="fa-solid fa-plus"></i><span>Column</span></button>
                                <button type="button" class="tool-btn" wire:click="addGroupHeader"><i class="fa-solid fa-object-group"></i><span>Group</span></button>
                            </div>
                            <div class="tool-group">
                                <button type="button" class="tool-btn" wire:click="undoColumns" @disabled(count($columnsUndoStack) === 0) title="Undo">
                                    <i class="fa-solid fa-rotate-left"></i><span>Undo</span>
                                </button>
                                <button type="button" class="tool-btn" wire:click="redoColumns" @disabled(count($columnsRedoStack) === 0) title="Redo">
                                    <i class="fa-solid fa-rotate-right"></i><span>Redo</span>
                                </button>
                                <button type="button"
                                    class="tool-btn"
                                    @click="confirmReset = true"
                                    title="Restore previous live (before last Update live)">
                                    <i class="fa-solid fa-clock-rotate-left"></i><span>Reset</span>
                                </button>
                                @if(! empty($selectedIsAlreadyLive))
                                    <button type="button"
                                        class="tool-btn"
                                        @click="confirmNew = true"
                                        title="Copy into a new draft (live unchanged)">
                                        <i class="fa-solid fa-copy"></i><span>Save as new</span>
                                    </button>
                                    <button type="button"
                                        class="btn-primary"
                                        @click="confirmSave = true">
                                        <i class="fa-solid fa-floppy-disk"></i> Update live
                                    </button>
                                @else
                                    <button type="button"
                                        class="btn-primary"
                                        @click="confirmSave = true">
                                        <i class="fa-solid fa-floppy-disk"></i> Save columns
                                    </button>
                                @endif
                            </div>
                        </div>
                        <template x-teleport="body">
                            <div class="tpl-confirm-backdrop" x-show="confirmReset" x-cloak x-transition.opacity @keydown.escape.window="confirmReset = false">
                                <div class="tpl-confirm-card" role="dialog" aria-modal="true" @click.outside="confirmReset = false">
                                    <p class="tpl-confirm-title">Reset to previous live?</p>
                                    <p class="tpl-confirm-body">
                                        @if(! empty($hasPreviousLive))
                                            Restores the <strong>live columns template from before your last Update live / Enable live</strong>
                                            (header, footer, and columns). This also updates report exports.
                                        @else
                                            No earlier live publish is stored yet. This will only discard <strong>unsaved</strong> edits
                                            and reload the current selected / live columns version.
                                        @endif
                                    </p>
                                    <div class="tpl-confirm-actions">
                                        <button type="button" class="btn-ghost" @click="confirmReset = false">Cancel</button>
                                        <button type="button" class="btn-primary" @click="confirmReset = false; $wire.resetSelectedVersion()">Reset</button>
                                    </div>
                                </div>
                            </div>
                        </template>
                        <template x-teleport="body">
                            <div class="tpl-confirm-backdrop" x-show="confirmSave" x-cloak x-transition.opacity @keydown.escape.window="confirmSave = false">
                                <div class="tpl-confirm-card" role="dialog" aria-modal="true" @click.outside="confirmSave = false">
                                    @if(! empty($selectedIsAlreadyLive))
                                        <p class="tpl-confirm-title">Update live columns?</p>
                                        <p class="tpl-confirm-body">Publishes branding and column edits to report exports. Does <strong>not</strong> create a new Saved versions row. Use <em>Save as new</em> if you want a draft first.</p>
                                    @else
                                        <p class="tpl-confirm-title">Save columns draft?</p>
                                        <p class="tpl-confirm-body">Creates or updates a Saved versions draft. Select it to reveal <em>Enable live</em>.</p>
                                    @endif
                                    <div class="tpl-confirm-actions">
                                        <button type="button" class="btn-ghost" @click="confirmSave = false">Cancel</button>
                                        <button type="button" class="btn-primary" @click="confirmSave = false; $wire.saveColumns()">
                                            @if(! empty($selectedIsAlreadyLive)) Update live @else Save columns @endif
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </template>
                        @if(! empty($selectedIsAlreadyLive))
                            <template x-teleport="body">
                                <div class="tpl-confirm-backdrop" x-show="confirmNew" x-cloak x-transition.opacity @keydown.escape.window="confirmNew = false">
                                    <div class="tpl-confirm-card" role="dialog" aria-modal="true" @click.outside="confirmNew = false">
                                        <p class="tpl-confirm-title">Save as new version?</p>
                                        <p class="tpl-confirm-body">Copies the current columns into a <strong>new Saved versions</strong> draft. Live stays unchanged until you Enable live.</p>
                                        <div class="tpl-confirm-actions">
                                            <button type="button" class="btn-ghost" @click="confirmNew = false">Cancel</button>
                                            <button type="button" class="btn-primary" @click="confirmNew = false; $wire.saveColumnsAsNew()">Save as new</button>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        @endif
                        <div class="layout-restore-bar">
                            @if(count($layoutRevisions) > 0)
                                <label class="revision-pick">
                                    <span>Saved versions</span>
                                    <select wire:change="selectSavedRevision($event.target.value)">
                                        <option value="0" disabled @selected(! $selectedLiveRevisionId)>Select a saved columns version…</option>
                                        @foreach($layoutRevisions as $rev)
                                            @php
                                                $revName = trim((string) ($rev['label'] ?? ''));
                                                if ($revName === '') {
                                                    $revName = ! empty($rev['is_official']) ? 'Official (stock)' : 'Saved columns';
                                                }
                                                if (! empty($rev['is_live'])) {
                                                    $revName .= ' · Live';
                                                } elseif (! empty($rev['is_official'])) {
                                                    $revName .= ' · Original';
                                                }
                                            @endphp
                                            <option value="{{ $rev['id'] }}" @selected($selectedLiveRevisionId === (int) $rev['id'])>
                                                {{ $revName }}@if(empty($rev['is_official']) && ! empty($rev['created_at'])) · {{ $rev['created_at'] }}@endif
                                            </option>
                                        @endforeach
                                    </select>
                                </label>
                            @endif
                            @if($selectedLiveRevisionId && $selectedIsAlreadyLive)
                                <span class="tag tag-live" title="This saved version is already published">Already live</span>
                                <span class="enable-live-hint"><em>Update live</em> publishes edits · <em>Save as new</em> creates a draft first</span>
                            @elseif($selectedLiveRevisionId)
                                <div class="enable-live-actions" x-data="{ confirmLive: false }">
                                    <button type="button" class="btn-primary enable-live-btn" @click="confirmLive = true">
                                        <i class="fa-solid fa-bolt"></i> Enable live
                                    </button>
                                    <span class="enable-live-hint">Publish this version (replaces current live)</span>
                                    <template x-teleport="body">
                                        <div class="tpl-confirm-backdrop" x-show="confirmLive" x-cloak x-transition.opacity @keydown.escape.window="confirmLive = false">
                                            <div class="tpl-confirm-card" role="dialog" aria-modal="true" @click.outside="confirmLive = false">
                                                <p class="tpl-confirm-title">Enable live?</p>
                                                <p class="tpl-confirm-body">Publish the <strong>selected saved version</strong> to report exports. The previous live version is replaced.</p>
                                                <div class="tpl-confirm-actions">
                                                    <button type="button" class="btn-ghost" @click="confirmLive = false">Cancel</button>
                                                    <button type="button" class="btn-primary" @click="confirmLive = false; $wire.enableLive()">
                                                        <i class="fa-solid fa-bolt"></i> Enable live
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            @endif
                        </div>
                        <p class="hint bar-hint"><i class="fa-solid fa-circle-info"></i>
                            @if($selectedKey === 'distribution')
                                Edit branding and the DISTRIBUTION / RETRIEVAL table here.
                            @else
                                Edit branding and table columns here.
                            @endif
                            <em>Update live</em> publishes; <em>Save as new</em> creates a draft.
                        </p>
                    </div>

                    <div class="layout-workspace" x-data="{ colsPanel: @js($selectedKey === 'distribution' ? 'table' : 'branding') }">
                        <div class="layout-edit columns-edit">
                            <div class="cols-subnav" role="tablist" aria-label="Columns editor sections">
                                <button type="button" role="tab" class="cols-subnav-btn" :class="colsPanel === 'branding' && 'is-active'" @click="colsPanel = 'branding'" :aria-selected="colsPanel === 'branding'">
                                    <i class="fa-solid fa-heading"></i> Branding
                                </button>
                                <button type="button" role="tab" class="cols-subnav-btn" :class="colsPanel === 'table' && 'is-active'" @click="colsPanel = 'table'" :aria-selected="colsPanel === 'table'">
                                    <i class="fa-solid fa-table"></i> Table
                                </button>
                            </div>

                            <div class="cols-panel" x-show="colsPanel === 'branding'" x-cloak>
                                <section class="cols-section">
                                    <div class="cols-section-h">
                                        <div>
                                            <h3>Form identity</h3>
                                            <p>Code and title printed on the sheet.</p>
                                        </div>
                                    </div>
                                    <div class="cols-fields cols-fields-2">
                                        <label class="field"><span>Form code</span><input type="text" wire:model.live="formCode" placeholder="CSPC-F-DCC-05"></label>
                                        <label class="field"><span>Form title</span><input type="text" wire:model.live="formTitle"></label>
                                    </div>
                                </section>

                                <section class="cols-section">
                                    <div class="cols-section-h">
                                        <div>
                                            <h3>Institution header</h3>
                                            <p>Logo band text above the blue rule.</p>
                                        </div>
                                    </div>
                                    <div class="cols-fields">
                                        <label class="field"><span>Republic</span><input type="text" wire:model.live="republic"></label>
                                        <label class="field"><span>Institution</span><input type="text" wire:model.live="institutionName"></label>
                                        <label class="field"><span>Address</span><input type="text" wire:model.live="institutionAddress"></label>
                                    </div>
                                </section>

                                <section class="cols-section">
                                    <div class="cols-section-h">
                                        <div>
                                            <h3>Footer</h3>
                                            <p>Effectivity and revision line under the table.</p>
                                        </div>
                                    </div>
                                    <div class="cols-fields cols-fields-2">
                                        <label class="field"><span>Left label</span><input type="text" wire:model.live="footerLeft" placeholder="Effectivity Date:"></label>
                                        <label class="field"><span>Effectivity</span><input type="text" wire:model.live="defaultEffectivity"></label>
                                        <label class="field"><span>Center label</span><input type="text" wire:model.live="footerCenter" placeholder="Rev."></label>
                                        <label class="field"><span>Rev.</span><input type="text" wire:model.live="defaultRev"></label>
                                    </div>
                                </section>

                                <section class="cols-section">
                                    <div class="cols-section-h">
                                        <div>
                                            <h3>Logo</h3>
                                            <p>Replace the header seal, or upload a new custom logo.</p>
                                        </div>
                                    </div>
                                    <label class="field">
                                        <span>Replace / upload new logo</span>
                                        <input type="file" wire:model="logoUpload" accept="image/*">
                                    </label>
                                    <div class="logo-actions-row mt-8">
                                        <button type="button" class="tool-btn" wire:click="clearCustomLogo" title="Use the default system seal">
                                            <i class="fa-solid fa-rotate-left"></i><span>Use default logo</span>
                                        </button>
                                    </div>
                                    <div class="identity-logo-dd mt-8">
                                        @if($identityLogoSrc !== '')
                                            <img src="{{ $identityLogoSrc }}" alt="Form logo" class="identity-logo-thumb">
                                            <span class="mono">{{ $logoPath !== '' && $logoPath !== null ? $logoPath : 'Default system logo' }}</span>
                                        @else
                                            <span class="mono">No logo</span>
                                        @endif
                                    </div>
                                    <p class="hint mt-8">New uploads preview immediately and save with Save / Update live.</p>
                                </section>
                            </div>

                            <div class="cols-panel" x-show="colsPanel === 'table'" x-cloak>
                                <section class="cols-section">
                                    <div class="cols-section-h">
                                        <div>
                                            <h3>Group header style</h3>
                                            <p>Colors and type for the top table band{{ $selectedKey === 'distribution' ? ' (DISTRIBUTION / RETRIEVAL)' : '' }}.</p>
                                        </div>
                                        <div class="header-swatch header-swatch-inline" style="background: {{ $tableHeaderBg }}; color: {{ $tableHeaderColor }}; font-family: {{ $tableHeaderFontFamily }}; font-size: {{ max(9, $tableHeaderFontSize) }}pt; font-weight: {{ $tableHeaderFontWeight }}; font-style: {{ $tableHeaderFontStyle }};">
                                            Aa
                                        </div>
                                    </div>
                                    <div class="cols-style-bar">
                                        <label class="field color-row"><span>Fill</span><input type="color" wire:model.live="tableHeaderBg"></label>
                                        <label class="field color-row"><span>Text</span><input type="color" wire:model.live="tableHeaderColor"></label>
                                        <label class="field"><span>Font</span>
                                            <select wire:model.live="tableHeaderFontFamily">
                                                @foreach($fontFamilies as $ff)
                                                    <option value="{{ $ff }}">{{ $ff }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="field"><span>Size</span><input type="number" step="0.5" min="6" max="36" wire:model.live="tableHeaderFontSize"></label>
                                        <label class="field"><span>Weight</span>
                                            <select wire:model.live="tableHeaderFontWeight">
                                                <option value="400">Regular</option>
                                                <option value="600">Semibold</option>
                                                <option value="700">Bold</option>
                                            </select>
                                        </label>
                                        <label class="field"><span>Style</span>
                                            <select wire:model.live="tableHeaderFontStyle">
                                                <option value="normal">Normal</option>
                                                <option value="italic">Italic</option>
                                            </select>
                                        </label>
                                    </div>
                                </section>

                                @if(count($groupHeaders) > 0)
                                    <section class="cols-section">
                                        <div class="cols-section-h">
                                            <div>
                                                <h3>Group headers</h3>
                                                <p>Merged top labels and how many columns each spans.</p>
                                            </div>
                                            <button type="button" class="tool-btn" wire:click="addGroupHeader" title="Add group"><i class="fa-solid fa-plus"></i><span>Group</span></button>
                                        </div>
                                        <div class="group-chip-list">
                                            @foreach($groupHeaders as $gi => $gh)
                                                <div class="group-chip">
                                                    <span class="group-chip-idx">{{ $gi + 1 }}</span>
                                                    <input type="text" wire:model.live="groupHeaders.{{ $gi }}.label" placeholder="Label" aria-label="Group label">
                                                    <label class="group-chip-span">
                                                        <span>Span</span>
                                                        <input type="number" min="1" wire:model.live="groupHeaders.{{ $gi }}.span" title="Column span">
                                                    </label>
                                                    <button type="button" class="icon-btn danger" wire:click="removeGroupHeader({{ $gi }})" title="Remove group"><i class="fa-solid fa-trash"></i></button>
                                                </div>
                                            @endforeach
                                        </div>
                                    </section>
                                @endif

                                <section class="cols-section">
                                    <div class="cols-section-h">
                                        <div>
                                            <h3>Columns <span class="cols-count">{{ count($columns) }}</span></h3>
                                            <p>Order, labels, widths, and group assignment.</p>
                                        </div>
                                        <button type="button" class="tool-btn" wire:click="addColumn" title="Add column"><i class="fa-solid fa-plus"></i><span>Column</span></button>
                                    </div>
                                    <div class="col-card-list">
                                        @foreach($columns as $ci => $col)
                                            <article class="col-card {{ empty($col['visible']) ? 'is-hidden-col' : '' }}">
                                                <div class="col-card-top">
                                                    <button type="button" class="vis-btn {{ ! empty($col['visible']) ? 'is-on' : '' }}" wire:click="toggleColumnVisible({{ $ci }})" title="Toggle visible">
                                                        <i class="fa-solid {{ ! empty($col['visible']) ? 'fa-eye' : 'fa-eye-slash' }}"></i>
                                                    </button>
                                                    <span class="col-card-order">{{ $ci + 1 }}</span>
                                                    <strong class="col-card-title">{{ trim((string) ($col['label'] ?? '')) !== '' ? strip_tags((string) $col['label']) : ((string) ($col['key'] ?? 'Column')) }}</strong>
                                                    @if(trim((string) ($col['group'] ?? '')) !== '')
                                                        <span class="col-card-group">{{ $col['group'] }}</span>
                                                    @endif
                                                    <div class="col-card-actions">
                                                        <button type="button" class="icon-btn" wire:click="moveColumn({{ $ci }}, 'up')" title="Move up" @disabled($ci === 0)><i class="fa-solid fa-arrow-up"></i></button>
                                                        <button type="button" class="icon-btn" wire:click="moveColumn({{ $ci }}, 'down')" title="Move down" @disabled($ci === count($columns) - 1)><i class="fa-solid fa-arrow-down"></i></button>
                                                        <button type="button" class="icon-btn danger" wire:click="removeColumn({{ $ci }})" title="Remove"><i class="fa-solid fa-trash"></i></button>
                                                    </div>
                                                </div>
                                                <div class="col-card-grid">
                                                    <label class="field"><span>Field</span>
                                                        <select wire:model.live="columns.{{ $ci }}.key">
                                                            @foreach($fieldCatalog as $f)
                                                                <option value="{{ $f['key'] }}">{{ $f['key'] }}</option>
                                                            @endforeach
                                                        </select>
                                                    </label>
                                                    <label class="field"><span>Display name</span>
                                                        <input type="text" wire:model.live="columns.{{ $ci }}.label" placeholder="Label">
                                                    </label>
                                                    <label class="field"><span>Width</span>
                                                        <input type="text" wire:model.live="columns.{{ $ci }}.width" placeholder="e.g. 34%">
                                                    </label>
                                                    <label class="field"><span>Group</span>
                                                        <input type="text" wire:model.live="columns.{{ $ci }}.group" placeholder="{{ $selectedKey === 'distribution' ? 'DISTRIBUTION / RETRIEVAL' : 'Group' }}">
                                                    </label>
                                                </div>
                                            </article>
                                        @endforeach
                                        @if(count($columns) === 0)
                                            <p class="hint empty-cols">No columns yet. Add a column to build the table.</p>
                                        @endif
                                    </div>
                                </section>
                            </div>
                        </div>

                        <aside class="layout-live">
                            <div class="layout-live-head">
                                <div>
                                    <p class="eyebrow" style="margin:0">{{ trim($formTitle) !== '' ? trim($formTitle) : 'Live preview' }}</p>
                                    <p class="hint" style="margin:2px 0 0">{{ $pageWidth }} × {{ $pageHeight }} in · branding + table update live</p>
                                </div>
                                <button type="button" class="tool-btn" wire:click="refreshPreview" title="Refresh preview">
                                    <i class="fa-solid fa-rotate"></i><span>Refresh</span>
                                </button>
                            </div>
                            <div class="layout-live-stage" wire:key="columns-side-preview-{{ $previewKey }}-{{ $previewBump }}" x-data x-init="$el.scrollTop = 0">
                                <iframe
                                    class="layout-live-frame"
                                    title="Columns live preview"
                                    src="{{ $livePreviewUrl }}?t={{ $previewKey }}&b={{ $previewBump }}&k={{ urlencode($selectedKey) }}"
                                    style="width: {{ $pageWidth * 96 }}px; height: {{ $pageHeight * 96 }}px;"
                                    onload="this.parentElement.scrollTop = 0"
                                ></iframe>
                            </div>
                        </aside>
                    </div>
                </div>
            @endif

            @if($editorTab === 'preview')
                <div class="panel">
                    <div class="preview-toolbar">
                        <div>
                            <p class="eyebrow" style="margin:0">Live preview</p>
                            <p class="hint" style="margin:2px 0 0">Edited draft · {{ $pageWidth }} × {{ $pageHeight }} in (same as Layout side preview)</p>
                        </div>
                        <button type="button" class="btn-secondary" wire:click="refreshPreview"><i class="fa-solid fa-rotate"></i> Refresh</button>
                    </div>
                    <div class="preview-stage" wire:key="tpl-preview-wrap-{{ $previewKey }}-{{ $previewBump }}">
                        <iframe
                            class="preview-frame true-page-frame"
                            title="{{ trim($formTitle) !== '' ? trim($formTitle) . ' Preview' : 'Document Request Form Preview' }}"
                            src="{{ $livePreviewUrl }}?t={{ $previewKey }}&b={{ $previewBump }}&k={{ urlencode($selectedKey) }}"
                            style="width: {{ $pageWidth }}in; height: {{ $pageHeight }}in;"
                        ></iframe>
                    </div>
                </div>
            @endif
    </section>
</div>

<style>
.dcs-tpl-page {
    --ink: #0f172a;
    --muted: #64748b;
    --line: #e2e8f0;
    --soft: #f8fafc;
    --brand: #0d2a7a;
    --brand-soft: #eff6ff;
    padding: 20px 24px 48px;
    color: var(--ink);
    background: linear-gradient(180deg, #f1f5f9 0%, #eef2f7 100%);
    min-height: 100%;
}
.dcs-tpl-page *,
.dcs-tpl-page *::before,
.dcs-tpl-page *::after { box-sizing: border-box; }
.dcs-tpl-page .eyebrow { margin: 0; font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); }
.dcs-tpl-page .dcs-tpl-head { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; margin-bottom: 14px; }
.dcs-tpl-page .dcs-tpl-head h1 { font-size: 24px; font-weight: 750; margin: 4px 0; letter-spacing: -.02em; }
.dcs-tpl-page .dcs-tpl-head > div > p:last-child { margin: 0; color: var(--muted); font-size: 13px; }
.dcs-tpl-page .dcs-tpl-head-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.dcs-tpl-page .alert { display: flex; align-items: center; gap: 8px; padding: 10px 12px; border-radius: 10px; margin-bottom: 12px; font-size: 13px; }
.dcs-tpl-page .alert.ok { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
.dcs-tpl-page .alert.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.dcs-tpl-page .dcs-tpl-toolbar {
    display: flex; flex-wrap: wrap; align-items: center; gap: 12px;
    background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 10px 14px;
    margin-bottom: 12px; box-shadow: 0 1px 2px rgba(15,23,42,.04);
}
.dcs-tpl-page .tpl-picker {
    display: flex; align-items: center; gap: 10px; min-width: min(100%, 420px); flex: 1 1 320px;
}
.dcs-tpl-page .tpl-picker-label {
    font-size: 11px; font-weight: 750; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); flex-shrink: 0;
}
.dcs-tpl-page .tpl-picker-select {
    width: 100%; max-width: 520px; min-width: 0;
    border: 1px solid #cbd5e1; border-radius: 9px; padding: 8px 12px;
    font-size: 13px; font-weight: 650; color: var(--ink); background: #fff;
}
.dcs-tpl-page .tpl-picker-select:focus {
    outline: none; border-color: #93c5fd; box-shadow: 0 0 0 3px rgba(59,130,246,.15);
}
.dcs-tpl-page .tpl-picker-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
.dcs-tpl-page .list-count { font-size: 11px; font-weight: 700; color: var(--brand); background: var(--brand-soft); border-radius: 999px; padding: 2px 8px; }
.dcs-tpl-page .tpl-code { font-size: 11px; color: #475569; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
.dcs-tpl-page .tag { font-size: 10px; font-weight: 700; color: #334155; background: #e2e8f0; border-radius: 999px; padding: 1px 7px; }
.dcs-tpl-page .tag-live { color: #065f46; background: #d1fae5; }
.dcs-tpl-page .tag-draft { color: #92400e; background: #fef3c7; }
.dcs-tpl-page .tag-diff { color: #1e40af; background: #dbeafe; }
.dcs-tpl-page .prop-card.identity-card:has(> .field) {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px 12px;
    align-items: start;
}
.dcs-tpl-page .prop-card.identity-card:has(> .field) .prop-card-h { grid-column: 1 / -1; }
.dcs-tpl-page .identity-card .field { display: flex; flex-direction: column; gap: 4px; margin: 0; min-width: 0; }
.dcs-tpl-page .identity-card .field.span-2 { grid-column: 1 / -1; }
.dcs-tpl-page .identity-card .field label { font-size: 11px; font-weight: 650; color: #64748b; }
.dcs-tpl-page .identity-card .field input,
.dcs-tpl-page .identity-card .field select {
    border: 1px solid #cbd5e1; border-radius: 8px; padding: 8px 10px; font-size: 13px; background: #fff; width: 100%;
}
.dcs-tpl-page .tpl-picker-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
.dcs-tpl-page .dcs-tpl-editor {
    background: #fff; border: 1px solid var(--line); border-radius: 14px; overflow: hidden;
    display: flex; flex-direction: column; box-shadow: 0 1px 2px rgba(15,23,42,.04); min-width: 0;
}
.dcs-tpl-page .editor-tabs { display: flex; gap: 2px; border-bottom: 1px solid var(--line); background: var(--soft); padding: 0 8px; position: relative; z-index: 2; }
.dcs-tpl-page .editor-tabs button {
    border: 0; background: transparent; padding: 12px 14px; font-size: 13px; font-weight: 650; color: var(--muted);
    cursor: pointer; display: inline-flex; align-items: center; gap: 7px; border-bottom: 2px solid transparent;
    pointer-events: auto;
}
.dcs-tpl-page .editor-tabs button:hover { color: var(--brand); }
.dcs-tpl-page .editor-tabs button.is-active { color: var(--brand); border-bottom-color: var(--brand); background: #fff; }
.dcs-tpl-page .help-callout {
    display: flex; flex-direction: column; gap: 4px; margin-bottom: 14px; padding: 10px 12px;
    background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 10px; color: #1e3a8a; font-size: 13px;
}
.dcs-tpl-page .help-callout strong { font-size: 12px; letter-spacing: .02em; }
.dcs-tpl-page .panel { padding: 16px; min-width: 0; }
.dcs-tpl-page .card-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 12px; }
.dcs-tpl-page .prop-card { border: 1px solid var(--line); border-radius: 12px; padding: 12px; background: #fff; min-width: 0; overflow: hidden; }
.dcs-tpl-page .prop-card-h { font-size: 12px; font-weight: 750; color: #334155; margin-bottom: 10px; display: flex; align-items: center; gap: 7px; }
.dcs-tpl-page .form-grid,
.dcs-tpl-page .metric-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 10px;
    width: 100%;
}
.dcs-tpl-page .form-grid.tight,
.dcs-tpl-page .metric-grid { gap: 8px; }
.dcs-tpl-page .form-grid > *,
.dcs-tpl-page .metric-grid > *,
.dcs-tpl-page .field { min-width: 0; max-width: 100%; }
.dcs-tpl-page .field {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 11px;
    font-weight: 700;
    color: #64748b;
}
.dcs-tpl-page .form-grid .span-2,
.dcs-tpl-page .metric-grid .span-fill,
.dcs-tpl-page .span-2 { grid-column: 1 / -1; }
.dcs-tpl-page .field > input,
.dcs-tpl-page .field > select,
.dcs-tpl-page .field > textarea,
.dcs-tpl-page .col-row input,
.dcs-tpl-page .col-row select {
    display: block;
    width: 100% !important;
    max-width: 100% !important;
    min-width: 0 !important;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 8px 10px;
    font-size: 13px;
    font-weight: 500;
    color: var(--ink);
    background: #fff;
}
.dcs-tpl-page .field > input[type="file"] {
    padding: 6px;
    font-size: 12px;
}
.dcs-tpl-page .field > input:focus,
.dcs-tpl-page .field > select:focus {
    outline: none; border-color: #93c5fd; box-shadow: 0 0 0 3px rgba(59,130,246,.15);
}
.dcs-tpl-page .metric { gap: 4px; }
.dcs-tpl-page .metric-label { display: block; line-height: 1.2; }
.dcs-tpl-page .metric-field {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: stretch;
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    background: #fff;
    overflow: hidden;
}
.dcs-tpl-page .metric-field:focus-within {
    border-color: #93c5fd;
    box-shadow: 0 0 0 3px rgba(59,130,246,.15);
}
.dcs-tpl-page .metric-field > input {
    width: 100% !important;
    max-width: 100% !important;
    min-width: 0 !important;
    border: 0 !important;
    border-radius: 0 !important;
    box-shadow: none !important;
    padding: 8px 8px 8px 10px;
    background: transparent;
}
.dcs-tpl-page .metric-field > .unit {
    display: inline-flex;
    align-items: center;
    padding: 0 8px;
    font-size: 11px;
    font-weight: 650;
    color: #94a3b8;
    background: #f8fafc;
    border-left: 1px solid #e2e8f0;
    flex-shrink: 0;
}
.dcs-tpl-page .panel-actions { display: flex; gap: 8px; margin-top: 14px; flex-wrap: wrap; }
.dcs-tpl-page .sticky-actions { position: sticky; bottom: 0; background: linear-gradient(180deg, transparent, #fff 30%); padding-top: 12px; }
.dcs-tpl-page .btn-primary,
.dcs-tpl-page .btn-secondary,
.dcs-tpl-page .btn-ghost {
    border-radius: 9px; padding: 8px 14px; font-size: 13px; font-weight: 650; cursor: pointer;
    border: 1px solid transparent; display: inline-flex; align-items: center; gap: 7px;
}
.dcs-tpl-page .btn-primary { background: var(--brand); color: #fff; }
.dcs-tpl-page .btn-primary:hover { filter: brightness(1.05); }
.dcs-tpl-page .btn-secondary { background: #fff; color: #334155; border-color: #cbd5e1; }
.dcs-tpl-page .btn-ghost { background: transparent; color: #475569; border-color: #cbd5e1; }
.dcs-tpl-page .identity-card-grid {
    gap: 12px;
}
.dcs-tpl-page .identity-card {
    background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
}
.dcs-tpl-page .identity-card .prop-card-h {
    color: #0d2a7a;
}
.dcs-tpl-page .identity-readonly {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px 16px;
    margin: 0;
}
.dcs-tpl-page .identity-readonly > div { min-width: 0; }
.dcs-tpl-page .identity-readonly > .span-2 { grid-column: 1 / -1; }
.dcs-tpl-page .identity-readonly dt {
    margin: 0 0 3px;
    font-size: 10px;
    font-weight: 750;
    letter-spacing: .06em;
    text-transform: uppercase;
    color: #64748b;
}
.dcs-tpl-page .identity-readonly dd {
    margin: 0;
    font-size: 13.5px;
    font-weight: 650;
    color: #0f172a;
    line-height: 1.35;
    word-break: break-word;
}
.dcs-tpl-page .identity-readonly dd.mono,
.dcs-tpl-page .identity-readonly .mono {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 11px;
    font-weight: 500;
    color: #475569;
}
.dcs-tpl-page .identity-readonly dd.nowrap {
    white-space: nowrap;
}
.dcs-tpl-page .identity-readonly--page {
    grid-template-columns: 1fr;
}
.dcs-tpl-page .identity-logo-dd {
    display: flex;
    align-items: center;
    gap: 10px;
}
.dcs-tpl-page .logo-actions { display: flex; flex-direction: column; gap: 8px; }
.dcs-tpl-page .logo-actions-row { display: flex; flex-wrap: wrap; gap: 6px; }
.dcs-tpl-page .identity-logo-thumb {
    width: 44px;
    height: 44px;
    object-fit: contain;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    background: #fff;
    padding: 3px;
    flex-shrink: 0;
}
.dcs-tpl-page .hint { font-size: 12px; color: var(--muted); }
.dcs-tpl-page .hint.mono,
.dcs-tpl-page .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 11px; }
.dcs-tpl-page .bar-hint { margin: 8px 0 0; }
.dcs-tpl-page .warn-hint { color: #92400e; background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 8px 10px; }
/* Teleported to body so blur covers RMS header + sidebar (same as DCS Settings .overlay) */
.tpl-confirm-backdrop {
    position: fixed;
    inset: 0;
    z-index: 20000;
    background: rgba(15, 23, 42, 0.55);
    -webkit-backdrop-filter: blur(2px);
    backdrop-filter: blur(2px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
}
.tpl-confirm-card {
    width: min(420px, 100%);
    background: #fff;
    border-radius: 16px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 24px 48px rgba(15, 23, 42, 0.18);
    padding: 18px 18px 14px;
}
.tpl-confirm-title {
    margin: 0 0 6px;
    font-size: 16px;
    font-weight: 750;
    color: #0f172a;
}
.tpl-confirm-body {
    margin: 0 0 16px;
    font-size: 13px;
    line-height: 1.45;
    color: #475569;
}
.tpl-confirm-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
}
.tpl-confirm-actions .btn-primary {
    min-width: 72px;
    background: #0d2a7a;
    color: #fff;
    border: 0;
    border-radius: 8px;
    padding: 8px 14px;
    font-weight: 650;
    cursor: pointer;
}
.tpl-confirm-actions .btn-ghost {
    background: #fff;
    color: #475569;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 8px 14px;
    font-weight: 650;
    cursor: pointer;
}
[x-cloak] { display: none !important; }
.dcs-tpl-page .layout-restore-bar {
    display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-top: 10px;
    padding: 8px 10px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px;
}
.dcs-tpl-page .revision-pick {
    display: inline-flex; align-items: center; gap: 8px; font-size: 12px; font-weight: 650; color: #475569;
}
.dcs-tpl-page .revision-pick select {
    border: 1px solid #cbd5e1; border-radius: 8px; padding: 7px 10px; background: #fff;
    min-width: 220px; max-width: min(360px, 70vw); font-weight: 500; color: #0f172a;
}
.dcs-tpl-page .enable-live-actions {
    display: inline-flex; align-items: center; gap: 8px; flex-wrap: wrap;
}
.dcs-tpl-page .enable-live-btn {
    padding: 7px 12px; font-size: 12px; display: inline-flex; align-items: center; gap: 6px;
    white-space: nowrap;
}
.dcs-tpl-page .enable-live-hint {
    font-size: 12px; color: #64748b; font-weight: 500; line-height: 1.3;
}
.dcs-tpl-page .columns-edit { min-width: 0; overflow: auto; max-height: calc(100vh - 220px); padding-right: 4px; display: flex; flex-direction: column; gap: 12px; }
.dcs-tpl-page .cols-subnav {
    display: inline-flex; gap: 4px; padding: 4px; border-radius: 10px;
    background: #f1f5f9; border: 1px solid #e2e8f0; align-self: flex-start; position: sticky; top: 0; z-index: 3;
}
.dcs-tpl-page .cols-subnav-btn {
    border: 0; background: transparent; border-radius: 8px; padding: 7px 12px;
    font-size: 12px; font-weight: 700; color: #64748b; cursor: pointer;
    display: inline-flex; align-items: center; gap: 6px;
}
.dcs-tpl-page .cols-subnav-btn:hover { color: var(--brand); background: #fff; }
.dcs-tpl-page .cols-subnav-btn.is-active { color: #0f172a; background: #fff; box-shadow: 0 1px 2px rgba(15,23,42,.08); }
.dcs-tpl-page .cols-panel { display: flex; flex-direction: column; gap: 12px; min-width: 0; }
.dcs-tpl-page .cols-section {
    border: 1px solid var(--line); border-radius: 12px; padding: 12px 14px; background: #fff; min-width: 0;
}
.dcs-tpl-page .cols-section-h {
    display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; margin: 0 0 12px;
    padding: 0; background: transparent !important; color: inherit; border: 0; min-height: 0; height: auto;
}
.dcs-tpl-page .cols-section-h h3 {
    margin: 0; font-size: 13px; font-weight: 750; color: #0f172a !important; background: transparent !important;
    display: inline-flex; align-items: center; gap: 8px;
}
.dcs-tpl-page .cols-section-h p {
    margin: 3px 0 0; font-size: 12px; color: #64748b !important; font-weight: 500; line-height: 1.35;
    background: transparent !important;
}
.dcs-tpl-page .cols-count {
    display: inline-flex; align-items: center; justify-content: center; min-width: 20px; height: 18px;
    padding: 0 6px; border-radius: 999px; background: var(--brand-soft); color: var(--brand); font-size: 11px;
}
.dcs-tpl-page .cols-fields { display: grid; grid-template-columns: 1fr; gap: 10px; }
.dcs-tpl-page .cols-fields-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
.dcs-tpl-page .cols-fields .field span,
.dcs-tpl-page .cols-style-bar .field span,
.dcs-tpl-page .col-card-grid .field span {
    display: block; margin-bottom: 4px; font-size: 11px; font-weight: 650; color: #64748b;
}
.dcs-tpl-page .cols-style-bar {
    display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px;
}
.dcs-tpl-page .header-swatch-inline {
    margin: 0; min-width: 44px; padding: 8px 10px; flex-shrink: 0; line-height: 1;
}
.dcs-tpl-page .group-chip-list { display: flex; flex-direction: column; gap: 8px; }
.dcs-tpl-page .group-chip {
    display: grid; grid-template-columns: auto minmax(0, 1fr) auto auto; gap: 8px; align-items: center;
    padding: 8px 10px; border: 1px solid #e2e8f0; border-radius: 10px; background: #f8fafc;
}
.dcs-tpl-page .group-chip-idx {
    width: 22px; height: 22px; border-radius: 999px; display: inline-flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: 750; color: #334155; background: #e2e8f0;
}
.dcs-tpl-page .group-chip-span {
    display: inline-flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 650; color: #64748b;
}
.dcs-tpl-page .group-chip input[type="text"],
.dcs-tpl-page .group-chip input[type="number"] {
    width: 100%; min-width: 0; border: 1px solid #cbd5e1; border-radius: 8px; padding: 7px 9px;
    font-size: 13px; font-weight: 500; color: var(--ink); background: #fff;
}
.dcs-tpl-page .group-chip-span input { width: 64px !important; }
.dcs-tpl-page .cols-style-bar .color-row span { display: inline; margin-bottom: 0; }
.dcs-tpl-page .col-card-list { display: flex; flex-direction: column; gap: 10px; }
.dcs-tpl-page .col-card {
    border: 1px solid var(--line); border-radius: 12px; padding: 10px 12px; background: #fff;
    box-shadow: 0 1px 0 rgba(15,23,42,.03);
}
.dcs-tpl-page .col-card.is-hidden-col { opacity: .62; background: #f8fafc; }
.dcs-tpl-page .col-card-top {
    display: flex; align-items: center; gap: 8px; margin-bottom: 10px; min-width: 0;
}
.dcs-tpl-page .col-card-order {
    width: 22px; height: 22px; border-radius: 999px; flex-shrink: 0;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: 750; color: #475569; background: #e2e8f0;
}
.dcs-tpl-page .col-card-title {
    flex: 1 1 auto; min-width: 0; font-size: 13px; font-weight: 700; color: #0f172a;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.dcs-tpl-page .col-card-group {
    flex-shrink: 0; font-size: 10px; font-weight: 750; letter-spacing: .04em; text-transform: uppercase;
    color: #1e40af; background: #dbeafe; border-radius: 999px; padding: 2px 8px;
}
.dcs-tpl-page .col-card-actions { display: inline-flex; gap: 4px; margin-left: auto; flex-shrink: 0; }
.dcs-tpl-page .col-card-grid {
    display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px 10px;
}
.dcs-tpl-page .icon-btn.danger { color: #b91c1c; }
.dcs-tpl-page .icon-btn:disabled { opacity: .35; cursor: not-allowed; }
.dcs-tpl-page .empty-cols { margin: 0; padding: 16px; text-align: center; border: 1px dashed #cbd5e1; border-radius: 10px; }
@media (max-width: 720px) {
    .dcs-tpl-page .cols-fields-2,
    .dcs-tpl-page .cols-style-bar,
    .dcs-tpl-page .col-card-grid { grid-template-columns: 1fr; }
    .dcs-tpl-page .group-chip { grid-template-columns: auto minmax(0, 1fr); }
    .dcs-tpl-page .group-chip-span { grid-column: 2; }
}
.dcs-tpl-page .layout-chrome { margin-bottom: 12px; }
.dcs-tpl-page .section-tabs { display: flex; gap: 6px; margin-bottom: 10px; flex-wrap: wrap; }
.dcs-tpl-page .section-tabs button {
    border: 1px solid #cbd5e1; background: #fff; border-radius: 999px; padding: 7px 12px; font-size: 12px;
    font-weight: 650; color: #475569; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
}
.dcs-tpl-page .section-tabs button.is-active { background: var(--brand); color: #fff; border-color: var(--brand); }
.dcs-tpl-page .layout-toolbar { display: flex; flex-wrap: wrap; gap: 10px; justify-content: space-between; align-items: center; }
.dcs-tpl-page .tool-group { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
.dcs-tpl-page .tool-btn {
    border: 1px solid #cbd5e1; background: #fff; color: #334155; border-radius: 9px; padding: 7px 10px;
    font-size: 12px; font-weight: 650; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
}
.dcs-tpl-page .tool-btn:hover { background: var(--soft); }
.dcs-tpl-page .tool-btn.danger { color: #b91c1c; border-color: #fecaca; }
.dcs-tpl-page .tool-btn:disabled { opacity: .45; cursor: not-allowed; }
.dcs-tpl-page .layout-workspace {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 12px;
    align-items: start;
}
.dcs-tpl-page .layout-edit { min-width: 0; display: flex; flex-direction: column; gap: 12px; }
.dcs-tpl-page .layout-body {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(220px, 260px);
    gap: 12px;
    align-items: start;
}
.dcs-tpl-page .layout-live {
    border: 1px solid var(--line);
    border-radius: 12px;
    background: var(--soft);
    padding: 10px;
    position: sticky;
    top: 12px;
    max-height: calc(100vh - 140px);
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.dcs-tpl-page .layout-live-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}
.dcs-tpl-page .layout-live-stage {
    background: #64748b;
    border-radius: 10px;
    padding: 10px;
    border: 1px solid var(--line);
    overflow: auto;
    min-height: 0;
    flex: 1;
}
.dcs-tpl-page .layout-live-frame {
    border: 0;
    border-radius: 8px;
    background: #e2e8f0;
    display: block;
    margin: 0 auto;
    max-width: none;
}
.dcs-tpl-page .canvas-wrap { overflow: auto; border: 1px solid var(--line); border-radius: 12px; background: #64748b; padding: 14px; max-height: calc(100vh - 220px); min-width: 0; }
.dcs-tpl-page .canvas { position: relative; background: #fff; box-shadow: 0 8px 24px rgba(15,23,42,.18); margin: 0 auto; overflow: hidden; }
.dcs-tpl-page .guide { position: absolute; left: 0; right: 0; z-index: 0; pointer-events: none; border-bottom: 1px dashed rgba(37,99,235,.35); color: rgba(100,116,139,.7); font-size: 10px; font-weight: 750; letter-spacing: .06em; padding: 4px 8px; }
.dcs-tpl-page .guide-header { background: rgba(59,130,246,.05); }
.dcs-tpl-page .guide-footer { background: rgba(148,163,184,.1); border-bottom: 0; }
.dcs-tpl-page .canvas-block {
    position: absolute; z-index: 1; border: 1px dashed #94a3b8; background: rgba(59,130,246,.05);
    padding: 2px 4px; overflow: hidden; cursor: grab; touch-action: none; user-select: none;
    box-sizing: border-box; margin: 0; text-align: left;
    appearance: none; -webkit-appearance: none; font: inherit; line-height: 1.15;
    display: block; color: inherit;
}
.dcs-tpl-page .canvas-block:active,
.dcs-tpl-page .canvas-block.is-dragging { cursor: grabbing; }
.dcs-tpl-page .canvas-block.is-selected { border: 2px solid #2563eb; background: rgba(37,99,235,.12); box-shadow: 0 0 0 3px rgba(37,99,235,.15); z-index: 2; }
.dcs-tpl-page .canvas-block.is-dragging { z-index: 8; box-shadow: 0 8px 20px rgba(15,23,42,.2); opacity: .95; }
.dcs-tpl-page .canvas-wrap.is-dragging { cursor: grabbing; }
.dcs-tpl-page .multi-banner {
    background: #eff6ff; border: 1px solid #bfdbfe; color: #1e3a8a;
    border-radius: 10px; padding: 8px 10px; font-size: 12px;
    display: flex; flex-direction: column; gap: 2px;
}
.dcs-tpl-page .nudge-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.dcs-tpl-page .multi-banner strong { font-size: 12.5px; }
.dcs-tpl-page kbd {
    display: inline-block; font-size: 10px; font-weight: 700; font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    background: #f1f5f9; border: 1px solid #cbd5e1; border-bottom-width: 2px; border-radius: 4px; padding: 0 4px;
}
.dcs-tpl-page .canvas-block.type-line {
    padding: 0;
    background: transparent;
    border-style: solid;
    border-color: transparent;
    display: flex;
    flex-direction: column;
    justify-content: center;
}
.dcs-tpl-page .canvas-block.type-line.is-selected { border-color: #2563eb; background: rgba(37,99,235,.08); }
.dcs-tpl-page .canvas-line-bar { display: block; width: 100%; height: 3px; border-radius: 2px; flex-shrink: 0; }
.dcs-tpl-page .canvas-block.type-line .type { position: absolute; left: 4px; top: -12px; background: #fff; padding: 0 3px; }
.dcs-tpl-page .canvas-block.type-table { background: rgba(15,23,42,.03); padding: 2px 3px; overflow: hidden; }
.dcs-tpl-page .canvas-table-grid {
    width: 100%; height: calc(100% - 14px); min-height: 16px; margin-top: 1px;
    border-collapse: collapse; table-layout: fixed; font-size: 8px; line-height: 1.1;
}
.dcs-tpl-page .canvas-table-grid td {
    border: 1px solid #94a3b8; padding: 1px 2px; overflow: hidden; white-space: nowrap;
    text-overflow: ellipsis; color: #334155; vertical-align: middle;
}
.dcs-tpl-page .canvas-table-grid.no-borders td { border-color: transparent; }
.dcs-tpl-page .table-cell-editor { max-height: 220px; overflow: auto; }
.dcs-tpl-page .table-cell-grid {
    display: grid; gap: 4px; min-width: 0;
}
.dcs-tpl-page .table-cell-grid.table-header-names {
    gap: 6px;
}
.dcs-tpl-page .table-cell-grid input {
    width: 100%; min-width: 0; padding: 5px 6px; font-size: 11px;
    border: 1px solid #cbd5e1; border-radius: 6px; background: #fff;
}
.dcs-tpl-page .table-cell-grid.table-header-names input {
    padding: 7px 8px; font-size: 12px;
}
.dcs-tpl-page .canvas-checks-preview { display: block; margin-top: 2px; font-size: 10px; color: #475569; }
.dcs-tpl-page .canvas-ruled-preview {
    display: block; margin-top: 4px; height: calc(100% - 24px); min-height: 20px;
    background:
        repeating-linear-gradient(to bottom, transparent 0, transparent 13px, #94a3b8 13px, #94a3b8 14px);
    opacity: .55;
}
.dcs-tpl-page .canvas-dist-preview {
    display: block; margin-top: 4px; height: calc(100% - 24px); min-height: 28px;
    background:
        repeating-linear-gradient(to bottom, transparent 0, transparent 10px, #94a3b8 10px, #94a3b8 11px),
        repeating-linear-gradient(to right, transparent 0, transparent calc(25% - 1px), #cbd5e1 calc(25% - 1px), #cbd5e1 25%);
    opacity: .5;
}
.dcs-tpl-page .canvas-block .type { display: block; font-size: 8px; color: #64748b; text-transform: uppercase; letter-spacing: .04em; }
.dcs-tpl-page .canvas-block .lbl { display: block; font-size: 11px; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.dcs-tpl-page .block-props {
    border: 1px solid var(--line); border-radius: 12px; padding: 12px; background: var(--soft);
    position: sticky; top: 12px; max-height: 780px; overflow-x: hidden; overflow-y: auto;
    display: flex; flex-direction: column; gap: 10px; min-width: 0;
}
.dcs-tpl-page .props-stack { display: flex; flex-direction: column; gap: 10px; min-width: 0; }
.dcs-tpl-page .props-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; }
.dcs-tpl-page .props-head h3 { margin: 2px 0 0; font-size: 16px; }
.dcs-tpl-page .chip { font-size: 10px; font-weight: 750; border-radius: 999px; padding: 3px 8px; text-transform: uppercase; letter-spacing: .04em; flex-shrink: 0; }
.dcs-tpl-page .chip-header { background: #dbeafe; color: #1d4ed8; }
.dcs-tpl-page .chip-body { background: #e2e8f0; color: #334155; }
.dcs-tpl-page .chip-footer { background: #fef3c7; color: #92400e; }
.dcs-tpl-page .props-empty { text-align: center; color: var(--muted); padding: 36px 12px; }
.dcs-tpl-page .props-empty i { font-size: 28px; margin-bottom: 10px; display: block; }
.dcs-tpl-page .block-picker { max-height: 180px; display: flex; flex-direction: column; }
.dcs-tpl-page .block-picker-list { overflow: auto; display: flex; flex-direction: column; gap: 4px; min-height: 0; }
.dcs-tpl-page .block-picker-item {
    display: flex; align-items: center; gap: 8px; width: 100%; text-align: left;
    border: 1px solid #e2e8f0; background: #fff; border-radius: 8px; padding: 6px 8px;
    cursor: pointer; font-size: 12px; color: #334155;
}
.dcs-tpl-page .block-picker-item:hover { background: #f8fafc; border-color: #cbd5e1; }
.dcs-tpl-page .block-picker-item.is-on { border-color: #2563eb; background: #eff6ff; color: #1e3a8a; }
.dcs-tpl-page .block-picker-item .bp-type {
    font-size: 9px; font-weight: 750; letter-spacing: .04em; text-transform: uppercase;
    color: #64748b; background: #f1f5f9; border-radius: 4px; padding: 2px 5px; flex-shrink: 0;
}
.dcs-tpl-page .block-picker-item.is-on .bp-type { background: #dbeafe; color: #1d4ed8; }
.dcs-tpl-page .block-picker-item .bp-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.dcs-tpl-page .seg-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; min-width: 0; }
.dcs-tpl-page .seg-label { font-size: 11px; font-weight: 700; color: #64748b; flex-shrink: 0; }
.dcs-tpl-page .seg { display: inline-flex; background: #fff; border: 1px solid #cbd5e1; border-radius: 9px; overflow: hidden; max-width: 100%; }
.dcs-tpl-page .seg button {
    border: 0; background: transparent; padding: 7px 8px; cursor: pointer; color: #475569; font-size: 12px; font-weight: 650;
    border-right: 1px solid #e2e8f0; min-width: 32px;
}
.dcs-tpl-page .seg button:last-child { border-right: 0; }
.dcs-tpl-page .seg button.is-on { background: var(--brand); color: #fff; }
.dcs-tpl-page .seg .u { text-decoration: underline; }
.dcs-tpl-page .color-pair { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 8px; }
.dcs-tpl-page .color-row { display: flex !important; flex-direction: row !important; align-items: center; justify-content: space-between; gap: 8px; }
.dcs-tpl-page .color-row input[type="color"] {
    width: 42px !important; max-width: 42px !important; min-width: 42px !important;
    height: 32px; padding: 2px; border-radius: 8px; cursor: pointer; flex-shrink: 0;
}
.dcs-tpl-page .mt-8 { margin-top: 8px; }
.dcs-tpl-page .mt-12 { margin-top: 12px; }
.dcs-tpl-page .header-swatch { border-radius: 8px; padding: 12px; margin-top: 12px; border: 1px solid #cbd5e1; text-align: center; font-weight: 700; }
.dcs-tpl-page .header-swatch.header-swatch-inline { margin-top: 0; }
.dcs-tpl-page .col-table { display: flex; flex-direction: column; gap: 8px; }
.dcs-tpl-page .col-row { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
.dcs-tpl-page .col-row.cardish { background: #fff; border: 1px solid var(--line); border-radius: 10px; padding: 8px; }
.dcs-tpl-page .icon-btn,
.dcs-tpl-page .vis-btn {
    border: 1px solid #cbd5e1; background: #fff; border-radius: 8px; width: 34px; height: 34px; cursor: pointer; color: #475569;
}
.dcs-tpl-page .vis-btn.is-on { background: #ecfdf5; border-color: #a7f3d0; color: #047857; }
.dcs-tpl-page .preview-toolbar { display: flex; justify-content: space-between; gap: 12px; align-items: center; margin-bottom: 10px; flex-wrap: wrap; }
.dcs-tpl-page .preview-stage {
    background: #64748b;
    border-radius: 12px;
    padding: 16px;
    border: 1px solid var(--line);
    overflow: auto;
    max-height: calc(100vh - 180px);
}
@media screen and (min-width: 769px) {
    /* Undo admin :root zoom so 1in in the preview matches browser CSS inches. */
    .dcs-tpl-page .preview-stage {
        zoom: calc(1 / clamp(0.72, 100vw / 1920, 1));
    }
}
.dcs-tpl-page .preview-frame,
.dcs-tpl-page .true-page-frame {
    border: 0;
    border-radius: 4px;
    background: #fff;
    display: block;
    margin: 0 auto;
    box-shadow: 0 8px 24px rgba(15, 23, 42, .22);
    flex-shrink: 0;
}
.dcs-tpl-page .sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0,0,0,0); }
@media (max-width: 1280px) {
    .dcs-tpl-page .layout-workspace { grid-template-columns: 1fr; }
    .dcs-tpl-page .layout-live { position: static; max-height: none; }
}
@media (max-width: 1100px) {
    .dcs-tpl-page .layout-body { grid-template-columns: 1fr; }
    .dcs-tpl-page .block-props { position: static; max-height: none; }
}
</style>

@script
<script>
    // Canvas select uses Livewire wire:click. Drag uses document-level vanilla JS
    // so it does not depend on Alpine x-data registration timing.
    if (! window.__dcsTplCanvasDragBound) {
        window.__dcsTplCanvasDragBound = true;

        let drag = null;

        const wireFrom = (el) => {
            const root = el?.closest?.('[wire\\:id]');
            if (! root || ! window.Livewire) return null;
            return window.Livewire.find(root.getAttribute('wire:id'));
        };

        const pageZoom = (canvas) => {
            if (! canvas) return 1;
            const rect = canvas.getBoundingClientRect();
            const ratio = rect.width / Math.max(1, canvas.offsetWidth);
            return Number.isFinite(ratio) && ratio > 0 ? ratio : 1;
        };

        const selectedIdsIn = (wrap) =>
            Array.from(wrap.querySelectorAll('.canvas-block.is-selected'))
                .map((el) => el.getAttribute('data-block-id'))
                .filter(Boolean);

        // Ctrl/⌘+click multi-select (capture so it wins over plain wire:click).
        document.addEventListener('click', (e) => {
            if (! (e.ctrlKey || e.metaKey)) return;
            const block = e.target.closest?.('.canvas-block');
            if (! block || ! block.closest('[data-dcs-canvas]')) return;
            const id = block.getAttribute('data-block-id');
            if (! id) return;
            e.preventDefault();
            e.stopPropagation();
            const api = wireFrom(block);
            if (api) api.call('selectBlock', id, true);
        }, true);

        document.addEventListener('pointerdown', (e) => {
            if (e.button !== 0) return;
            if (e.ctrlKey || e.metaKey) return; // multi-select handled above

            const block = e.target.closest?.('.canvas-block');
            if (! block) return;
            const wrap = block.closest('[data-dcs-canvas]');
            if (! wrap) return;

            const canvas = wrap.querySelector('.canvas');
            const scale = Number(wrap.getAttribute('data-scale')) || 72;
            const pageW = Number(wrap.getAttribute('data-page-w')) || 8.5;
            const pageH = Number(wrap.getAttribute('data-page-h')) || 11;
            const primary = block.getAttribute('data-block-id');
            if (! primary) return;

            const before = selectedIdsIn(wrap);
            const ids = (before.includes(primary) && before.length > 1) ? before.slice() : [primary];
            const items = [];
            ids.forEach((sid) => {
                const node = wrap.querySelector('.canvas-block[data-block-id="' + sid + '"]');
                if (! node) return;
                const left = parseFloat(node.style.left) || 0;
                const top = parseFloat(node.style.top) || 0;
                items.push({
                    id: sid,
                    el: node,
                    origLeft: left,
                    origTop: top,
                    curLeft: left,
                    curTop: top,
                    w: node.offsetWidth || 18,
                    h: node.offsetHeight || 12,
                });
            });
            if (! items.length) return;

            drag = {
                wrap,
                canvas,
                scale,
                pageW,
                pageH,
                zoom: pageZoom(canvas),
                startCX: e.clientX,
                startCY: e.clientY,
                items,
                moved: false,
                pointerId: e.pointerId,
            };
        }, true);

        document.addEventListener('pointermove', (e) => {
            if (! drag) return;
            let dx = (e.clientX - drag.startCX) / drag.zoom;
            let dy = (e.clientY - drag.startCY) / drag.zoom;
            if (! drag.moved && Math.abs(dx) <= 3 && Math.abs(dy) <= 3) return;

            if (! drag.moved) {
                drag.moved = true;
                drag.wrap.classList.add('is-dragging');
                drag.items.forEach((item) => item.el.classList.add('is-dragging'));
            }

            e.preventDefault();
            drag.items.forEach((item) => {
                const maxL = Math.max(0, drag.pageW * drag.scale - item.w);
                const maxT = Math.max(0, drag.pageH * drag.scale - item.h);
                dx = Math.min(Math.max(dx, -item.origLeft), maxL - item.origLeft);
                dy = Math.min(Math.max(dy, -item.origTop), maxT - item.origTop);
            });
            drag.items.forEach((item) => {
                item.curLeft = item.origLeft + dx;
                item.curTop = item.origTop + dy;
                item.el.style.left = item.curLeft + 'px';
                item.el.style.top = item.curTop + 'px';
            });
        }, true);

        document.addEventListener('pointerup', (e) => {
            if (! drag) return;
            const current = drag;
            drag = null;
            current.wrap.classList.remove('is-dragging');
            current.items.forEach((item) => item.el.classList.remove('is-dragging'));

            if (! current.moved) return; // let wire:click handle selection

            // Suppress the click that follows a drag so selection isn't collapsed.
            const suppress = (ev) => {
                ev.preventDefault();
                ev.stopPropagation();
                document.removeEventListener('click', suppress, true);
            };
            document.addEventListener('click', suppress, true);
            setTimeout(() => document.removeEventListener('click', suppress, true), 0);

            const api = wireFrom(current.wrap);
            if (! api) return;
            api.call('moveBlocks', current.items.map((item) => ({
                id: item.id,
                x: Number((item.curLeft / current.scale).toFixed(3)),
                y: Number((item.curTop / current.scale).toFixed(3)),
            })));
        }, true);

        document.addEventListener('pointercancel', () => {
            if (! drag) return;
            drag.wrap.classList.remove('is-dragging');
            drag.items.forEach((item) => item.el.classList.remove('is-dragging'));
            drag = null;
        }, true);

        document.addEventListener('keydown', (e) => {
            const wrap = document.querySelector('.dcs-tpl-page [data-dcs-canvas]');
            if (! wrap) return;
            const tag = ((e.target && e.target.tagName) || '').toLowerCase();
            if (tag === 'input' || tag === 'textarea' || tag === 'select') return;
            const ids = selectedIdsIn(wrap);
            if (! ids.length) return;
            const step = e.shiftKey ? 0.1 : 0.02;
            let dx = 0;
            let dy = 0;
            if (e.key === 'ArrowLeft') dx = -step;
            else if (e.key === 'ArrowRight') dx = step;
            else if (e.key === 'ArrowUp') dy = -step;
            else if (e.key === 'ArrowDown') dy = step;
            else return;
            e.preventDefault();
            const scale = Number(wrap.getAttribute('data-scale')) || 72;
            const moves = [];
            ids.forEach((sid) => {
                const node = wrap.querySelector('.canvas-block[data-block-id="' + sid + '"]');
                if (! node) return;
                moves.push({
                    id: sid,
                    x: Number((((parseFloat(node.style.left) || 0) / scale) + dx).toFixed(3)),
                    y: Number((((parseFloat(node.style.top) || 0) / scale) + dy).toFixed(3)),
                });
            });
            const api = wireFrom(wrap);
            if (api && moves.length) api.call('moveBlocks', moves);
        });
    }
</script>
@endscript
