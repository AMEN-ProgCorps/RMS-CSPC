# Random Check (Admin)
Schedule and conduct compliance sampling visits, then export finalized PDF/Excel reports. Requires the Random Check module permission.

## Screens
1. **Home** — pick a year; June / December cycle summary stats.
2. **Year** — cycle cards and office grid by cluster. Card badges show Finalized / Draft / Scheduled. Office list filters are **All / Finalized / Draft** (Scheduled is a badge, not a filter). Use **Schedule Random Check** to pick office, visit date, and notify when offered.
3. **Office work** — visit date, conducted/tested by; document table (Availability Yes/No, remarks, recommended actions, compliance).
   - Row filters: All / With recommended actions / Not complied
   - **Save draft**, **Finalize visit**, **View previous year**
   - **Export PDF** / **Export Excel** only after the visit is finalized

## Schedule modal
- Opens from Schedule Random Check on the year screen.
- Fields: office, visit date, notify option when available.
- After scheduling, open the office work screen to record findings.

> [!NOTE]
> Office Intake has **no Random Check page**. Old `/dcs/office/random-checks` URLs redirect to the DCS home. Controllers run visits here and send the exported PDF/Excel to the office if they asked to see the result.
