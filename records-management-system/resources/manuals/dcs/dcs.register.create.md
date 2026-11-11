# Register Document
Encode a new, revised, or recurring controlled document into the DCS masterlist (RFIO / Document Controllers).

## Top of form
- **Version Type**, **Document Type**, **Sub-Type** (when the type has children)
- Dynamic **checklist** checkboxes from the catalog for that type

## Sections that appear by rules
Not every card is always visible. Depending on type/version you may see:
- **Document to be Superseded**
- **Document Change Notice** (revision table, justification, DCN fields)
- **Syllabi** wizard (college/program/semester/course → DRF steps)
- **Document Request Form** (when coming from office intake)
- **Approval** (Applicable / Not Applicable) + approval details
- **Masterlist Registration** — doc no., dates, time spent, scan PDF, keywords, related docs, **Compare Revisions (DRR)**
- **Document Retrieval**
- **Document Distribution** — offices, copies, saved office groups

## Footer buttons
- **Cancel**
- **Generate** — distribution template (enabled after the document is saved)
- **Save Draft**
- **Save and Appraise** — opens **Review & Confirm** → **Confirm Save**, then continues to **RDP Inventory & Appraisal** when appraisal is requested

## Intake handoff
- From Request review, **Proceed to Registration** lands here with DRF/DCN context already attached.

> [!NOTE]
> There is no appraisal form on this page itself. Appraisal starts after a successful Save and Appraise / Update and Appraise.
