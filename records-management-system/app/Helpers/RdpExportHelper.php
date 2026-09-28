<?php

namespace App\Helpers;

use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RdpSimpleZip
{
    private string $data = '';
    private string $dir = '';
    private int $offset = 0;
    private int $entries = 0;

    public function addFile(string $name, string $content): void
    {
        $name = str_replace('\\', '/', $name);
        $time = time();
        $dtime = dechex(
            ((int)date('Y', $time) - 1980) << 25 |
            ((int)date('m', $time)) << 21 |
            ((int)date('d', $time)) << 16 |
            ((int)date('H', $time)) << 11 |
            ((int)date('i', $time)) << 5 |
            ((int)date('s', $time)) >> 1
        );
        $hexdtime = pack('V', hexdec($dtime));
        $unc_len = strlen($content);
        $crc = crc32($content);
        $zdata = gzdeflate($content);
        $c_len = strlen($zdata);

        $fr = "\x50\x4b\x03\x04\x14\x00\x00\x00\x08\x00" . $hexdtime
            . pack('VVVvv', $crc, $c_len, $unc_len, strlen($name), 0)
            . $name . $zdata;
        $this->data .= $fr;

        $cd = "\x50\x4b\x01\x02\x00\x00\x14\x00\x00\x00\x08\x00" . $hexdtime
            . pack('VVVvvvvvVV', $crc, $c_len, $unc_len, strlen($name), 0, 0, 0, 0, 32, $this->offset)
            . $name;
        $this->dir .= $cd;
        $this->offset = strlen($this->data);
        $this->entries++;
    }

    public function getZip(): string
    {
        return $this->data . $this->dir . "\x50\x4b\x05\x06\x00\x00\x00\x00"
            . pack('vvVVv', $this->entries, $this->entries, strlen($this->dir), $this->offset, 0);
    }
}

class RdpExportHelper
{
    public static function colIndexToLetter(int $colIndex): string
    {
        $letter = '';
        $colIndex++;
        while ($colIndex > 0) {
            $rem = ($colIndex - 1) % 26;
            $letter = chr(65 + $rem) . $letter;
            $colIndex = intdiv($colIndex - $rem, 26);
        }
        return $letter;
    }

    /**
     * Generate pure OpenXML Spreadsheet (.xlsx) bytes for general tables.
     */
    public static function generateXlsxBinary(string $title, array $meta, array $headers, array $rows): string
    {
        $colCount = max(count($headers), 5);
        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<cols>';
        for ($i = 1; $i <= $colCount; $i++) {
            $w = ($i === 2) ? 38 : (($i === 3) ? 28 : 18);
            $sheetXml .= '<col min="' . $i . '" max="' . $i . '" width="' . $w . '" customWidth="1"/>';
        }
        $sheetXml .= '</cols><sheetData>';

        $r = 1;
        // Title row
        $sheetXml .= '<row r="' . $r . '"><c r="A' . $r . '" t="inlineStr" s="1"><is><t>' . htmlspecialchars($title, ENT_XML1, 'UTF-8') . '</t></is></c></row>';
        $r++;

        // Metadata block
        foreach ($meta as $k => $v) {
            $sheetXml .= '<row r="' . $r . '">'
                . '<c r="A' . $r . '" t="inlineStr" s="1"><is><t>' . htmlspecialchars((string)$k, ENT_XML1, 'UTF-8') . ':</t></is></c>'
                . '<c r="B' . $r . '" t="inlineStr"><is><t>' . htmlspecialchars((string)$v, ENT_XML1, 'UTF-8') . '</t></is></c>'
                . '</row>';
            $r++;
        }
        $r++; // Empty spacing row

        // Table Header
        $sheetXml .= '<row r="' . $r . '">';
        foreach ($headers as $ci => $h) {
            $colLetter = self::colIndexToLetter($ci);
            $sheetXml .= '<c r="' . $colLetter . $r . '" t="inlineStr" s="2"><is><t>' . htmlspecialchars((string)$h, ENT_XML1, 'UTF-8') . '</t></is></c>';
        }
        $sheetXml .= '</row>';
        $r++;

        // Table Rows
        foreach ($rows as $row) {
            $sheetXml .= '<row r="' . $r . '">';
            foreach ($headers as $ci => $h) {
                $colLetter = self::colIndexToLetter($ci);
                $val = $row[$ci] ?? '';
                $valStr = (string)$val;
                $isNum = is_numeric($val) && !preg_match('/^0\d+/', $valStr);
                if ($isNum && strlen($valStr) <= 12) {
                    $sheetXml .= '<c r="' . $colLetter . $r . '" s="3"><v>' . $valStr . '</v></c>';
                } else {
                    $sheetXml .= '<c r="' . $colLetter . $r . '" t="inlineStr" s="3"><is><t>' . htmlspecialchars($valStr, ENT_XML1, 'UTF-8') . '</t></is></c>';
                }
            }
            $sheetXml .= '</row>';
            $r++;
        }

        $sheetXml .= '</sheetData></worksheet>';

        $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="3">'
            . '<font><sz val="10"/><name val="Arial"/></font>'
            . '<font><b/><sz val="11"/><name val="Arial"/></font>'
            . '<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Arial"/></font>'
            . '</fonts>'
            . '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF1E293B"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="2">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left style="thin"><color rgb="FFCBD5E1"/></left>'
            . '<right style="thin"><color rgb="FFCBD5E1"/></right>'
            . '<top style="thin"><color rgb="FFCBD5E1"/></top>'
            . '<bottom style="thin"><color rgb="FFCBD5E1"/></bottom>'
            . '<diagonal/></border>'
            . '</borders>'
            . '<cellXfs count="4">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0"/>'
            . '<xf numFmtId="0" fontId="2" fillId="2" borderId="1" applyFont="1" applyFill="1" applyBorder="1"/>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" applyBorder="1"/>'
            . '</cellXfs>'
            . '</styleSheet>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';

        $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';

        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';

        $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Records" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';

        $zip = new RdpSimpleZip();
        $zip->addFile('[Content_Types].xml', $contentTypes);
        $zip->addFile('_rels/.rels', $rootRels);
        $zip->addFile('xl/_rels/workbook.xml.rels', $wbRels);
        $zip->addFile('xl/workbook.xml', $workbookXml);
        $zip->addFile('xl/styles.xml', $stylesXml);
        $zip->addFile('xl/worksheets/sheet1.xml', $sheetXml);

        return $zip->getZip();
    }

    /**
     * Generate official NAP Form 1 OpenXML Spreadsheet (.xlsx) matching client's template exactly.
     */
    public static function generateNap1TemplateXlsxBinary(object $cluster, array $items, array $sig = []): string
    {
        $agency = $sig['agencyName'] ?? $cluster->office_name ?? $cluster->office ?? '';
        $dept = $sig['departmentDivision'] ?? '';
        $section = $sig['sectionUnit'] ?? '';
        $tel = $sig['telephoneNumber'] ?? '';
        $email = $sig['emailAddress'] ?? '';
        $address = $sig['agencyAddress'] ?? '';
        $personInCharge = $sig['personInCharge'] ?? $cluster->submitter_name ?? '';
        $datePrepared = $sig['datePrepared'] ?? date('m/d/Y');

        $preparedBy = $sig['preparedBy'] ?? $cluster->submitter_name ?? '';
        $preparedPos = $sig['preparedPosition'] ?? 'Name and Position';
        $assistedBy = $sig['assistedBy'] ?? '';
        $assistedPos = $sig['assistedPosition'] ?? 'NAP Records Management Analyst';
        $approvedBy = $sig['approvedBy'] ?? '';
        $approvedPos = $sig['approvedPosition'] ?? 'Chief of the Division/Department';

        $includeDesc = (bool)($sig['includeDescription'] ?? false);

        if (!empty($items) && isset($items[0]->series_title) && (isset($items[0]->sub_series) || isset($items[0]->direct_records) || isset($items[0]->has_children))) {
            $flattenedItems = self::flattenHierarchy($items, $includeDesc);
        } elseif (!empty($items) && isset($items[0]['type'])) {
            $flattenedItems = $items;
        } else {
            $flattenedItems = [];
            foreach ($items as $it) {
                $flattenedItems[] = [
                    'type' => 'root_standalone',
                    'root' => (object)[
                        'series_title'        => $it->series_title ?? $it->doc_name ?? 'Untitled Series',
                        'compiled_period'     => $it->period_covered ?? '',
                        'compiled_volume'     => $it->volume ?? '',
                        'compiled_medium'     => $it->medium_name ?? $it->records_medium ?? 'Paper',
                        'compiled_restriction'=> $it->access_restriction ?? $it->restriction ?? 'Restricted',
                        'compiled_location'   => $it->records_location ?? '',
                        'compiled_freq'       => $it->frequency_of_use ?? $it->frequence_use ?? '',
                        'compiled_duplication'=> $it->duplication ?? '',
                        'compiled_time'       => $it->time_value ?? 'T',
                        'compiled_util'       => $it->utility_name_display ?? 'Adm',
                        'is_permanent'        => (bool)($it->is_retention_period_permanent ?? false) || strtolower(trim($it->total_period ?? '')) === 'permanent',
                        'active_period'       => $it->active_period ?? '',
                        'storage_period'      => $it->storage_period ?? '',
                        'total_period'        => $it->total_period ?? '',
                        'remarks'             => $it->remarks ?? '',
                    ]
                ];
            }
        }

        // Optimized column widths so headers and data values have comfortable margins
        $colWidths = [
            14.14, 14.14, 16.50, 11.50, 13.00, 
            13.50, 15.50, 13.50, 14.00, 9.50, 
            13.00, 10.50, 10.50, 12.50, 26.00
        ];

        $mergeCells = [];
        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0" showGridLines="1"/></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="16"/>'
            . '<cols>';

        foreach ($colWidths as $idx => $w) {
            $c = $idx + 1;
            $sheetXml .= '<col min="' . $c . '" max="' . $c . '" width="' . $w . '" customWidth="1"/>';
        }
        $sheetXml .= '</cols><sheetData>';

        // Row 1: Top labels
        $sheetXml .= '<row r="1" ht="14" customHeight="1">'
            . '<c r="A1" t="inlineStr" s="4"><is><t>NATIONAL ARCHIVES OF THE PHILIPPINES&#10;Pambansang Sinupan ng Pilipinas&#10;&#10;RECORDS INVENTORY AND APPRAISAL</t></is></c>'
            . '<c r="B1" s="4"/><c r="C1" s="4"/><c r="D1" s="4"/>'
            . '<c r="E1" t="inlineStr" s="2"><is><t>1. NAME OF OFFICE:</t></is></c>'
            . '<c r="F1" s="2"/><c r="G1" s="2"/><c r="H1" s="2"/>'
            . '<c r="I1" t="inlineStr" s="2"><is><t>2. DEPARTMENT/DIVISION:</t></is></c>'
            . '<c r="J1" s="2"/><c r="K1" s="2"/><c r="L1" s="2"/>'
            . '<c r="M1" t="inlineStr" s="2"><is><t>4. TELEPHONE NO.:</t></is></c>'
            . '<c r="N1" s="2"/><c r="O1" s="2"/>'
            . '</row>';
        $mergeCells[] = 'A1:D6';
        $mergeCells[] = 'E1:H1';
        $mergeCells[] = 'I1:L1';
        $mergeCells[] = 'M1:O1';

        // Row 2: Values 1, 2, 4
        $sheetXml .= '<row r="2" ht="20" customHeight="1">'
            . '<c r="A2" s="4"/><c r="B2" s="4"/><c r="C2" s="4"/><c r="D2" s="4"/>'
            . '<c r="E2" t="inlineStr" s="3"><is><t>' . htmlspecialchars($agency, ENT_XML1, 'UTF-8') . '</t></is></c>'
            . '<c r="F2" s="3"/><c r="G2" s="3"/><c r="H2" s="3"/>'
            . '<c r="I2" t="inlineStr" s="3"><is><t>' . htmlspecialchars($dept, ENT_XML1, 'UTF-8') . '</t></is></c>'
            . '<c r="J2" s="3"/><c r="K2" s="3"/><c r="L2" s="3"/>'
            . '<c r="M2" t="inlineStr" s="3"><is><t>' . htmlspecialchars($tel, ENT_XML1, 'UTF-8') . '</t></is></c>'
            . '<c r="N2" s="3"/><c r="O2" s="3"/>'
            . '</row>';
        $mergeCells[] = 'E2:H4';
        $mergeCells[] = 'I2:L2';
        $mergeCells[] = 'M2:O2';

        // Row 3: Labels 3, 5
        $sheetXml .= '<row r="3" ht="14" customHeight="1">'
            . '<c r="A3" s="4"/><c r="B3" s="4"/><c r="C3" s="4"/><c r="D3" s="4"/>'
            . '<c r="E3" s="3"/><c r="F3" s="3"/><c r="G3" s="3"/><c r="H3" s="3"/>'
            . '<c r="I3" t="inlineStr" s="2"><is><t>3. SECTION/UNIT:</t></is></c>'
            . '<c r="J3" s="2"/><c r="K3" s="2"/><c r="L3" s="2"/>'
            . '<c r="M3" t="inlineStr" s="2"><is><t>5. EMAIL ADDRESS.:</t></is></c>'
            . '<c r="N3" s="2"/><c r="O3" s="2"/>'
            . '</row>';
        $mergeCells[] = 'I3:L3';
        $mergeCells[] = 'M3:O3';

        // Row 4: Values 3, 5
        $sheetXml .= '<row r="4" ht="20" customHeight="1">'
            . '<c r="A4" s="4"/><c r="B4" s="4"/><c r="C4" s="4"/><c r="D4" s="4"/>'
            . '<c r="E4" s="3"/><c r="F4" s="3"/><c r="G4" s="3"/><c r="H4" s="3"/>'
            . '<c r="I4" t="inlineStr" s="3"><is><t>' . htmlspecialchars($section, ENT_XML1, 'UTF-8') . '</t></is></c>'
            . '<c r="J4" s="3"/><c r="K4" s="3"/><c r="L4" s="3"/>'
            . '<c r="M4" t="inlineStr" s="3"><is><t>' . htmlspecialchars($email, ENT_XML1, 'UTF-8') . '</t></is></c>'
            . '<c r="N4" s="3"/><c r="O4" s="3"/>'
            . '</row>';
        $mergeCells[] = 'I4:L4';
        $mergeCells[] = 'M4:O4';

        // Row 5: Labels 6, 7, 8
        $sheetXml .= '<row r="5" ht="14" customHeight="1">'
            . '<c r="A5" s="4"/><c r="B5" s="4"/><c r="C5" s="4"/><c r="D5" s="4"/>'
            . '<c r="E5" t="inlineStr" s="2"><is><t>6. ADDRESS:</t></is></c>'
            . '<c r="F5" s="2"/><c r="G5" s="2"/><c r="H5" s="2"/>'
            . '<c r="I5" t="inlineStr" s="2"><is><t>7. PERSON-IN-CHARGE OF FILES:</t></is></c>'
            . '<c r="J5" s="2"/><c r="K5" s="2"/><c r="L5" s="2"/>'
            . '<c r="M5" t="inlineStr" s="2"><is><t>8. DATE PREPARED:</t></is></c>'
            . '<c r="N5" s="2"/><c r="O5" s="2"/>'
            . '</row>';
        $mergeCells[] = 'E5:H5';
        $mergeCells[] = 'I5:L5';
        $mergeCells[] = 'M5:O5';

        // Row 6: Values 6, 7, 8
        $sheetXml .= '<row r="6" ht="28" customHeight="1">'
            . '<c r="A6" s="4"/><c r="B6" s="4"/><c r="C6" s="4"/><c r="D6" s="4"/>'
            . '<c r="E6" t="inlineStr" s="3"><is><t>' . htmlspecialchars($address, ENT_XML1, 'UTF-8') . '</t></is></c>'
            . '<c r="F6" s="3"/><c r="G6" s="3"/><c r="H6" s="3"/>'
            . '<c r="I6" t="inlineStr" s="3"><is><t>' . htmlspecialchars($personInCharge, ENT_XML1, 'UTF-8') . '</t></is></c>'
            . '<c r="J6" s="3"/><c r="K6" s="3"/><c r="L6" s="3"/>'
            . '<c r="M6" t="inlineStr" s="3"><is><t>' . htmlspecialchars($datePrepared, ENT_XML1, 'UTF-8') . '</t></is></c>'
            . '<c r="N6" s="3"/><c r="O6" s="3"/>'
            . '</row>';
        $mergeCells[] = 'E6:H6';
        $mergeCells[] = 'I6:L6';
        $mergeCells[] = 'M6:O6';

        // Row 7 (Main Table Headers - row 1)
        $sheetXml .= '<row r="7" ht="26" customHeight="1">'
            . '<c r="A7" t="inlineStr" s="6"><is><t>9. RECORDS SERIES TITLE AND DESCRIPTION</t></is></c>'
            . '<c r="B7" s="6"/>'
            . '<c r="C7" t="inlineStr" s="6"><is><t>10. PERIOD COVERED / INCLUSIVE DATES</t></is></c>'
            . '<c r="D7" t="inlineStr" s="6"><is><t>11. VOLUME</t></is></c>'
            . '<c r="E7" t="inlineStr" s="6"><is><t>12. RECORDS MEDIUM</t></is></c>'
            . '<c r="F7" t="inlineStr" s="6"><is><t>13. RESTRICTION/S</t></is></c>'
            . '<c r="G7" t="inlineStr" s="6"><is><t>14. LOCATION OF RECORDS</t></is></c>'
            . '<c r="H7" t="inlineStr" s="6"><is><t>15. FREQUENCY OF USE</t></is></c>'
            . '<c r="I7" t="inlineStr" s="6"><is><t>16. DUPLICATION</t></is></c>'
            . '<c r="J7" t="inlineStr" s="6"><is><t>17. TIME VALUE (T/P)</t></is></c>'
            . '<c r="K7" t="inlineStr" s="6"><is><t>18. UTILITY VALUE Adm/F/L/Arc</t></is></c>'
            . '<c r="L7" t="inlineStr" s="6"><is><t>19. RETENTION PERIOD</t></is></c>'
            . '<c r="M7" s="6"/><c r="N7" s="6"/>'
            . '<c r="O7" t="inlineStr" s="6"><is><t>20. DISPOSITION PROVISION</t></is></c>'
            . '</row>';
        $mergeCells[] = 'A7:B8';
        $mergeCells[] = 'C7:C8';
        $mergeCells[] = 'D7:D8';
        $mergeCells[] = 'E7:E8';
        $mergeCells[] = 'F7:F8';
        $mergeCells[] = 'G7:G8';
        $mergeCells[] = 'H7:H8';
        $mergeCells[] = 'I7:I8';
        $mergeCells[] = 'J7:J8';
        $mergeCells[] = 'K7:K8';
        $mergeCells[] = 'L7:N7';
        $mergeCells[] = 'O7:O8';

        // Row 8 (Retention Period Subheaders - row 2)
        $sheetXml .= '<row r="8" ht="18" customHeight="1">'
            . '<c r="A8" s="6"/><c r="B8" s="6"/><c r="C8" s="6"/><c r="D8" s="6"/><c r="E8" s="6"/><c r="F8" s="6"/><c r="G8" s="6"/><c r="H8" s="6"/><c r="I8" s="6"/><c r="J8" s="6"/><c r="K8" s="6"/>'
            . '<c r="L8" t="inlineStr" s="6"><is><t>Active</t></is></c>'
            . '<c r="M8" t="inlineStr" s="6"><is><t>Storage</t></is></c>'
            . '<c r="N8" t="inlineStr" s="6"><is><t>Total</t></is></c>'
            . '<c r="O8" s="6"/>'
            . '</row>';

        $dash = function($val) {
            $s = trim((string)$val);
            return ($s === '' || $s === 'null' || $s === 'None' || $s === 'N/A') ? '—' : $s;
        };

        // Render Data Rows
        $r = 9;
        $cols15 = ['A','B','C','D','E','F','G','H','I','J','K','L','M','N','O'];
        $renderedCount = 0;

        foreach ($flattenedItems as $item) {
            $mergeCells[] = 'A' . $r . ':B' . $r;

            if ($item['type'] === 'root_standalone') {
                $root = $item['root'];
                $isPerm = (bool)($root->is_permanent ?? false) || strtolower(trim($root->total_period ?? '')) === 'permanent';

                $sheetXml .= '<row r="' . $r . '" ht="19" customHeight="1">'
                    . '<c r="A' . $r . '" t="inlineStr" s="8"><is><t>' . htmlspecialchars(strtoupper($root->series_title ?? ''), ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '<c r="B' . $r . '" s="8"/>'
                    . '<c r="C' . $r . '" t="inlineStr" s="9"><is><t>' . htmlspecialchars($dash($root->compiled_period ?? ''), ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '<c r="D' . $r . '" t="inlineStr" s="9"><is><t>' . htmlspecialchars($dash($root->compiled_volume ?? ''), ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '<c r="E' . $r . '" t="inlineStr" s="9"><is><t>' . htmlspecialchars($dash($root->compiled_medium ?? ''), ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '<c r="F' . $r . '" t="inlineStr" s="9"><is><t>' . htmlspecialchars($dash($root->compiled_restriction ?? ''), ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '<c r="G' . $r . '" t="inlineStr" s="9"><is><t>' . htmlspecialchars($dash($root->compiled_location ?? ''), ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '<c r="H' . $r . '" t="inlineStr" s="9"><is><t>' . htmlspecialchars($dash($root->compiled_freq ?? ''), ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '<c r="I' . $r . '" t="inlineStr" s="9"><is><t>' . htmlspecialchars($dash($root->compiled_duplication ?? ''), ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '<c r="J' . $r . '" t="inlineStr" s="10"><is><t>' . htmlspecialchars($dash($root->compiled_time ?? ''), ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '<c r="K' . $r . '" t="inlineStr" s="10"><is><t>' . htmlspecialchars($dash($root->compiled_util ?? ''), ENT_XML1, 'UTF-8') . '</t></is></c>';

                if ($isPerm) {
                    $sheetXml .= '<c r="L' . $r . '" t="inlineStr" s="10"><is><t>PERMANENT</t></is></c>'
                        . '<c r="M' . $r . '" t="inlineStr" s="9"><is><t>—</t></is></c>'
                        . '<c r="N' . $r . '" t="inlineStr" s="10"><is><t>PERMANENT</t></is></c>';
                } else {
                    $sheetXml .= '<c r="L' . $r . '" t="inlineStr" s="9"><is><t>' . htmlspecialchars($dash($root->active_period ?? ''), ENT_XML1, 'UTF-8') . '</t></is></c>'
                        . '<c r="M' . $r . '" t="inlineStr" s="9"><is><t>' . htmlspecialchars($dash($root->storage_period ?? ''), ENT_XML1, 'UTF-8') . '</t></is></c>'
                        . '<c r="N' . $r . '" t="inlineStr" s="10"><is><t>' . htmlspecialchars($dash($root->total_period ?? ''), ENT_XML1, 'UTF-8') . '</t></is></c>';
                }

                $sheetXml .= '<c r="O' . $r . '" t="inlineStr" s="7"><is><t>' . htmlspecialchars($root->remarks ?? '', ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '</row>';
                $r++;
                $renderedCount++;
            } elseif ($item['type'] === 'root_header') {
                $root = $item['root'];
                $sheetXml .= '<row r="' . $r . '" ht="19" customHeight="1">'
                    . '<c r="A' . $r . '" t="inlineStr" s="8"><is><t>' . htmlspecialchars(strtoupper($root->series_title ?? ''), ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '<c r="B' . $r . '" s="8"/>';
                for ($ci = 2; $ci < 15; $ci++) {
                    $sheetXml .= '<c r="' . $cols15[$ci] . $r . '" s="11"/>';
                }
                $sheetXml .= '</row>';
                $r++;
                $renderedCount++;
            } elseif ($item['type'] === 'sub_series') {
                $sub = $item['sub'];
                $root = $item['root'] ?? null;
                $isPerm = (bool)($sub->is_permanent ?? false) || strtolower(trim($sub->total_period ?? '')) === 'permanent';

                $sheetXml .= '<row r="' . $r . '" ht="19" customHeight="1">'
                    . '<c r="A' . $r . '" t="inlineStr" s="7"><is><t>   └ ' . htmlspecialchars($sub->series_title ?? '', ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '<c r="B' . $r . '" s="7"/>'
                    . '<c r="C' . $r . '" t="inlineStr" s="9"><is><t>' . htmlspecialchars($dash($sub->compiled_period ?? ''), ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '<c r="D' . $r . '" t="inlineStr" s="9"><is><t>' . htmlspecialchars($dash($sub->compiled_volume ?? ''), ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '<c r="E' . $r . '" t="inlineStr" s="9"><is><t>' . htmlspecialchars($dash($sub->compiled_medium ?? ''), ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '<c r="F' . $r . '" t="inlineStr" s="9"><is><t>' . htmlspecialchars($dash($sub->compiled_restriction ?? ''), ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '<c r="G' . $r . '" t="inlineStr" s="9"><is><t>' . htmlspecialchars($dash($sub->compiled_location ?? ''), ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '<c r="H' . $r . '" t="inlineStr" s="9"><is><t>' . htmlspecialchars($dash($sub->compiled_freq ?? ''), ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '<c r="I' . $r . '" t="inlineStr" s="9"><is><t>' . htmlspecialchars($dash($sub->compiled_duplication ?? ''), ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '<c r="J' . $r . '" t="inlineStr" s="10"><is><t>' . htmlspecialchars($dash($sub->compiled_time ?? ''), ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '<c r="K' . $r . '" t="inlineStr" s="10"><is><t>' . htmlspecialchars($dash($sub->compiled_util ?? ''), ENT_XML1, 'UTF-8') . '</t></is></c>';

                if ($isPerm) {
                    $sheetXml .= '<c r="L' . $r . '" t="inlineStr" s="10"><is><t>PERMANENT</t></is></c>'
                        . '<c r="M' . $r . '" t="inlineStr" s="9"><is><t>—</t></is></c>'
                        . '<c r="N' . $r . '" t="inlineStr" s="10"><is><t>PERMANENT</t></is></c>';
                } else {
                    $sheetXml .= '<c r="L' . $r . '" t="inlineStr" s="9"><is><t>' . htmlspecialchars($dash($sub->active_period ?? ''), ENT_XML1, 'UTF-8') . '</t></is></c>'
                        . '<c r="M' . $r . '" t="inlineStr" s="9"><is><t>' . htmlspecialchars($dash($sub->storage_period ?? ''), ENT_XML1, 'UTF-8') . '</t></is></c>'
                        . '<c r="N' . $r . '" t="inlineStr" s="10"><is><t>' . htmlspecialchars($dash($sub->total_period ?? ''), ENT_XML1, 'UTF-8') . '</t></is></c>';
                }

                $rem = ($sub->remarks ?? '') ?: ($root ? ($root->remarks ?? '') : '');
                $sheetXml .= '<c r="O' . $r . '" t="inlineStr" s="7"><is><t>' . htmlspecialchars($rem, ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '</row>';
                $r++;
                $renderedCount++;
            } elseif ($item['type'] === 'record') {
                $rec = $item['rec'];
                $sheetXml .= '<row r="' . $r . '" ht="19" customHeight="1">'
                    . '<c r="A' . $r . '" t="inlineStr" s="7"><is><t>      ' . htmlspecialchars($rec->description ?? '', ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '<c r="B' . $r . '" s="7"/>'
                    . '<c r="C' . $r . '" t="inlineStr" s="9"><is><t>' . htmlspecialchars($dash($rec->date_covered ?? ''), ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '<c r="D' . $r . '" t="inlineStr" s="9"><is><t>' . htmlspecialchars($dash($rec->volume ?? ''), ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '<c r="E' . $r . '" t="inlineStr" s="9"><is><t>' . htmlspecialchars($dash($rec->medium ?? ''), ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '<c r="F' . $r . '" t="inlineStr" s="9"><is><t>' . htmlspecialchars($dash($rec->restriction ?? ''), ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '<c r="G' . $r . '" t="inlineStr" s="9"><is><t>' . htmlspecialchars($dash($rec->location ?? ''), ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '<c r="H' . $r . '" t="inlineStr" s="9"><is><t>' . htmlspecialchars($dash($rec->frequence_use ?? ''), ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '<c r="I' . $r . '" t="inlineStr" s="9"><is><t>' . htmlspecialchars($dash($rec->duplication ?? ''), ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '<c r="J' . $r . '" t="inlineStr" s="10"><is><t>' . htmlspecialchars($dash($rec->time_value ?? ''), ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '<c r="K' . $r . '" t="inlineStr" s="10"><is><t>' . htmlspecialchars($dash($rec->utility ?? ''), ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '<c r="L' . $r . '" t="inlineStr" s="9"><is><t>—</t></is></c>'
                    . '<c r="M' . $r . '" t="inlineStr" s="9"><is><t>—</t></is></c>'
                    . '<c r="N' . $r . '" t="inlineStr" s="9"><is><t>—</t></is></c>'
                    . '<c r="O' . $r . '" s="11"/>'
                    . '</row>';
                $r++;
                $renderedCount++;
            }
        }

        // Pad empty filler rows up to Row 32 (total 24 rows minimum)
        for (; $r <= 32; $r++) {
            $mergeCells[] = 'A' . $r . ':B' . $r;
            $sheetXml .= '<row r="' . $r . '" ht="18" customHeight="1">';
            foreach ($cols15 as $col) {
                $sheetXml .= '<c r="' . $col . $r . '" s="11"/>';
            }
            $sheetXml .= '</row>';
        }

        // Legend Section (Row 33 to 35)
        $sheetXml .= '<row r="33" ht="18" customHeight="1">'
            . '<c r="A33" t="inlineStr" s="1"><is><t>LEGEND:</t></is></c>'
            . '</row>';

        $sheetXml .= '<row r="34" ht="16" customHeight="1">'
            . '<c r="B34" t="inlineStr" s="14"><is><t>TIME VALUE:</t></is></c>'
            . '<c r="C34" t="inlineStr" s="15"><is><t>T  -  Temporary</t></is></c>'
            . '<c r="E34" t="inlineStr" s="15"><is><t>P  -  Permanent</t></is></c>'
            . '</row>';
        $mergeCells[] = 'C34:D34';
        $mergeCells[] = 'E34:F34';

        $sheetXml .= '<row r="35" ht="16" customHeight="1">'
            . '<c r="B35" t="inlineStr" s="14"><is><t>UTILITY VALUE:</t></is></c>'
            . '<c r="C35" t="inlineStr" s="15"><is><t>Adm  -  Administrative</t></is></c>'
            . '<c r="E35" t="inlineStr" s="15"><is><t>F  -  Fiscal</t></is></c>'
            . '<c r="F35" t="inlineStr" s="15"><is><t>L  -  Legal</t></is></c>'
            . '<c r="G35" t="inlineStr" s="15"><is><t>Arc  -  Archival</t></is></c>'
            . '</row>';
        $mergeCells[] = 'C35:D35';
        $mergeCells[] = 'G35:H35';

        // Spacer Row 36
        $sheetXml .= '<row r="36" ht="12" customHeight="1"/>';

        // Signatures Block (Row 37 to 41)
        $sheetXml .= '<row r="37" ht="16" customHeight="1">'
            . '<c r="B37" t="inlineStr" s="1"><is><t>PREPARED BY:</t></is></c>'
            . '<c r="G37" t="inlineStr" s="1"><is><t>ASSISTED BY:</t></is></c>'
            . '<c r="L37" t="inlineStr" s="1"><is><t>APPROVED BY:</t></is></c>'
            . '</row>';
        $mergeCells[] = 'B37:D37';
        $mergeCells[] = 'G37:I37';
        $mergeCells[] = 'L37:N37';

        // Signature Space (Rows 38 and 39)
        $sheetXml .= '<row r="38" ht="16" customHeight="1"/>';
        $sheetXml .= '<row r="39" ht="16" customHeight="1"/>';

        // Signature Line & Name (Row 40)
        $sheetXml .= '<row r="40" ht="20" customHeight="1">'
            . '<c r="B40" t="inlineStr" s="12"><is><t>' . htmlspecialchars($preparedBy, ENT_XML1, 'UTF-8') . '</t></is></c>'
            . '<c r="C40" s="12"/><c r="D40" s="12"/>'
            . '<c r="G40" t="inlineStr" s="12"><is><t>' . htmlspecialchars($assistedBy, ENT_XML1, 'UTF-8') . '</t></is></c>'
            . '<c r="H40" s="12"/><c r="I40" s="12"/>'
            . '<c r="L40" t="inlineStr" s="12"><is><t>' . htmlspecialchars($approvedBy, ENT_XML1, 'UTF-8') . '</t></is></c>'
            . '<c r="M40" s="12"/><c r="N40" s="12"/>'
            . '</row>';
        $mergeCells[] = 'B40:D40';
        $mergeCells[] = 'G40:I40';
        $mergeCells[] = 'L40:N40';

        // Positions (Row 41)
        $sheetXml .= '<row r="41" ht="16" customHeight="1">'
            . '<c r="B41" t="inlineStr" s="13"><is><t>' . htmlspecialchars($preparedPos, ENT_XML1, 'UTF-8') . '</t></is></c>'
            . '<c r="G41" t="inlineStr" s="13"><is><t>' . htmlspecialchars($assistedPos, ENT_XML1, 'UTF-8') . '</t></is></c>'
            . '<c r="L41" t="inlineStr" s="13"><is><t>' . htmlspecialchars($approvedPos, ENT_XML1, 'UTF-8') . '</t></is></c>'
            . '</row>';
        $mergeCells[] = 'B41:D41';
        $mergeCells[] = 'G41:I41';
        $mergeCells[] = 'L41:N41';

        $sheetXml .= '</sheetData>';

        // Merged Cells
        $sheetXml .= '<mergeCells count="' . count($mergeCells) . '">';
        foreach ($mergeCells as $range) {
            $sheetXml .= '<mergeCell ref="' . $range . '"/>';
        }
        $sheetXml .= '</mergeCells>';

        // Page Margins and Print Setup
        $sheetXml .= '<pageMargins left="0.4" right="0.4" top="0.4" bottom="0.4" header="0.2" footer="0.2"/>'
            . '<pageSetup orientation="landscape" paperSize="5" fitToWidth="1" fitToHeight="0"/>'
            . '<headerFooter><oddHeader>&amp;L&amp;7NAP Records Inventory and Appraisal Form&#10;2024</oddHeader></headerFooter>'
            . '</worksheet>';

        // Stylesheet definition
        $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="6">'
            . '<font><sz val="8"/><name val="Arial"/></font>'
            . '<font><b/><sz val="8"/><name val="Arial"/></font>'
            . '<font><b/><sz val="8.5"/><name val="Arial"/></font>'
            . '<font><b/><sz val="9.5"/><name val="Arial"/></font>'
            . '<font><i/><sz val="8"/><name val="Arial"/></font>'
            . '<font><sz val="7"/><color rgb="FF555555"/><name val="Arial"/></font>'
            . '</fonts>'
            . '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFF8FAFC"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="3">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left style="thin"><color rgb="FF000000"/></left>'
            . '<right style="thin"><color rgb="FF000000"/></right>'
            . '<top style="thin"><color rgb="FF000000"/></top>'
            . '<bottom style="thin"><color rgb="FF000000"/></bottom>'
            . '<diagonal/></border>'
            . '<border><left/><right/><top/>'
            . '<bottom style="thin"><color rgb="FF000000"/></bottom>'
            . '<diagonal/></border>'
            . '</borders>'
            . '<cellXfs count="16">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0"><alignment horizontal="left" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="1" applyFont="1" applyBorder="1"><alignment horizontal="left" vertical="top"/></xf>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="1" applyFont="1" applyBorder="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="3" fillId="0" borderId="1" applyFont="1" applyBorder="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="4" fillId="0" borderId="1" applyFont="1" applyBorder="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="1" applyFont="1" applyBorder="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" applyFont="1" applyBorder="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="1" applyFont="1" applyBorder="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" applyFont="1" applyBorder="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="1" applyFont="1" applyBorder="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" applyBorder="1"/>'
            . '<xf numFmtId="0" fontId="3" fillId="0" borderId="2" applyFont="1" applyBorder="1"><alignment horizontal="center" vertical="bottom"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" applyFont="1"><alignment horizontal="center" vertical="top"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0"><alignment horizontal="left" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0"><alignment horizontal="left" vertical="center"/></xf>'
            . '</cellXfs>'
            . '</styleSheet>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';

        $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';

        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';

        $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Records" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';

        $zip = new RdpSimpleZip();
        $zip->addFile('[Content_Types].xml', $contentTypes);
        $zip->addFile('_rels/.rels', $rootRels);
        $zip->addFile('xl/_rels/workbook.xml.rels', $wbRels);
        $zip->addFile('xl/workbook.xml', $workbookXml);
        $zip->addFile('xl/styles.xml', $stylesXml);
        $zip->addFile('xl/worksheets/sheet1.xml', $sheetXml);

        return $zip->getZip();
    }

    /**
     * Stream official NAP Form 1 Excel spreadsheet.
     */
    public static function streamNap1Xlsx(
        string $filename,
        object $cluster,
        array $items,
        array $signatures = []
    ): StreamedResponse {
        $xlsxContent = self::generateNap1TemplateXlsxBinary($cluster, $items, $signatures);

        return new StreamedResponse(function () use ($xlsxContent) {
            echo $xlsxContent;
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length' => strlen($xlsxContent),
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    /**
     * Generate high-fidelity OpenXML (.xlsx) binary matching official NAP Form 2 (2008).
     */
    public static function generateNap2TemplateXlsxBinary(
        object $cluster,
        array $items,
        array $signatures = []
    ): string {
        $agency = $signatures['agencyName'] ?? (!empty($cluster->office_name) ? $cluster->office_name : 'Camarines Sur Polytechnic Colleges');
        $address = $signatures['agencyAddress'] ?? 'San Miguel, Nabua, Camarines Sur';
        $scheduleNo = $signatures['scheduleNo'] ?? (!empty($cluster->cluster_id) ? (string)$cluster->cluster_id : 'RDS-' . date('Y') . '-001');
        $datePrepared = $signatures['datePrepared'] ?? (!empty($cluster->created_at) ? \Carbon\Carbon::parse($cluster->created_at)->format('m/d/Y') : date('m/d/Y'));

        $preparedBy = $signatures['preparedBy'] ?? ($cluster->submitter_name ?? '');
        $preparedPos = $signatures['preparedPosition'] ?? 'Administrative Officer V';
        $assistedBy = $signatures['assistedBy'] ?? '';
        $assistedPos = $signatures['assistedPosition'] ?? 'NAP Records Management Analyst';
        $recommendingBy = $signatures['recommendingBy'] ?? '';
        $recommendingPos = $signatures['recommendingPosition'] ?? 'Vice President for Administration';
        $approvedBy = $signatures['approvedBy'] ?? '';
        $approvedPos = $signatures['approvedPosition'] ?? 'College President';
        $committeeChairman = $signatures['committeeChairmanName'] ?? '';
        $committeeChairmanTitle = $signatures['committeeChairmanTitle'] ?? 'Chairman, Records Management Evaluation Committee';
        $execDirector = $signatures['executiveDirectorName'] ?? '';
        $execDirectorTitle = $signatures['executiveDirectorTitle'] ?? 'Executive Director';

        // 7 columns: A (Item No: 12), B (Title 1: 20), C (Title 2: 24), D (Active: 11), E (Storage: 11), F (Total: 13), G (Remarks: 21)
        // Left 50% = A+B+C = 56. Right 50% = D+E+F+G = 56. Total = 112.
        $colWidths = [12.00, 20.00, 24.00, 11.00, 11.00, 13.00, 21.00];

        $mergeCells = [];
        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0" showGridLines="1"/></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="16"/>'
            . '<cols>';

        foreach ($colWidths as $idx => $w) {
            $c = $idx + 1;
            $sheetXml .= '<col min="' . $c . '" max="' . $c . '" width="' . $w . '" customWidth="1"/>';
        }
        $sheetXml .= '</cols><sheetData>';

        // Row 1: Top Tag
        $sheetXml .= '<row r="1" ht="14" customHeight="1">'
            . '<c r="A1" t="inlineStr" s="0"><is><t>NAP Form 2&#10;2008</t></is></c>'
            . '</row>';

        // Row 2: Top Header Box (Left: National Archives, Right: Agency Name)
        $sheetXml .= '<row r="2" ht="14" customHeight="1">'
            . '<c r="A2" t="inlineStr" s="4"><is><t>NATIONAL ARCHIVES OF THE PHILIPPINES&#10;Pambansang Sinupan ng Pilipinas&#10;&#10;RECORDS DISPOSITION SCHEDULE</t></is></c>'
            . '<c r="B2" s="4"/><c r="C2" s="4"/>'
            . '<c r="D2" t="inlineStr" s="2"><is><t>1. AGENCY NAME:</t></is></c>'
            . '<c r="E2" s="2"/><c r="F2" s="2"/><c r="G2" s="2"/>'
            . '</row>';
        $mergeCells[] = 'A2:C5';
        $mergeCells[] = 'D2:G2';

        // Row 3: Agency Name Value
        $sheetXml .= '<row r="3" ht="20" customHeight="1">'
            . '<c r="A3" s="4"/><c r="B3" s="4"/><c r="C3" s="4"/>'
            . '<c r="D3" t="inlineStr" s="3"><is><t>' . htmlspecialchars($agency, ENT_XML1, 'UTF-8') . '</t></is></c>'
            . '<c r="E3" s="3"/><c r="F3" s="3"/><c r="G3" s="3"/>'
            . '</row>';
        $mergeCells[] = 'D3:G3';

        // Row 4: Address Label
        $sheetXml .= '<row r="4" ht="14" customHeight="1">'
            . '<c r="A4" s="4"/><c r="B4" s="4"/><c r="C4" s="4"/>'
            . '<c r="D4" t="inlineStr" s="2"><is><t>2. ADDRESS:</t></is></c>'
            . '<c r="E4" s="2"/><c r="F4" s="2"/><c r="G4" s="2"/>'
            . '</row>';
        $mergeCells[] = 'D4:G4';

        // Row 5: Address Value
        $sheetXml .= '<row r="5" ht="22" customHeight="1">'
            . '<c r="A5" s="4"/><c r="B5" s="4"/><c r="C5" s="4"/>'
            . '<c r="D5" t="inlineStr" s="3"><is><t>' . htmlspecialchars($address, ENT_XML1, 'UTF-8') . '</t></is></c>'
            . '<c r="E5" s="3"/><c r="F5" s="3"/><c r="G5" s="3"/>'
            . '</row>';
        $mergeCells[] = 'D5:G5';

        // Row 6: Schedule No (Left) & Date Prepared (Right)
        $sheetXml .= '<row r="6" ht="18" customHeight="1">'
            . '<c r="A6" t="inlineStr" s="2"><is><t>3. SCHEDULE NO.: ' . htmlspecialchars($scheduleNo, ENT_XML1, 'UTF-8') . '</t></is></c>'
            . '<c r="B6" s="2"/><c r="C6" s="2"/>'
            . '<c r="D6" t="inlineStr" s="2"><is><t>4. DATE PREPARED: ' . htmlspecialchars($datePrepared, ENT_XML1, 'UTF-8') . '</t></is></c>'
            . '<c r="E6" s="2"/><c r="F6" s="2"/><c r="G6" s="2"/>'
            . '</row>';
        $mergeCells[] = 'A6:C6';
        $mergeCells[] = 'D6:G6';

        // Row 7: Main Table Headers Row 1
        $sheetXml .= '<row r="7" ht="24" customHeight="1">'
            . '<c r="A7" t="inlineStr" s="6"><is><t>5. ITEM NO.:</t></is></c>'
            . '<c r="B7" t="inlineStr" s="6"><is><t>6. RECORD SERIES TITLE AND DESCRIPTION</t></is></c>'
            . '<c r="C7" s="6"/>'
            . '<c r="D7" t="inlineStr" s="6"><is><t>7. RETENTION PERIOD</t></is></c>'
            . '<c r="E7" s="6"/><c r="F7" s="6"/>'
            . '<c r="G7" t="inlineStr" s="6"><is><t>8. REMARKS</t></is></c>'
            . '</row>';
        $mergeCells[] = 'A7:A8';
        $mergeCells[] = 'B7:C8';
        $mergeCells[] = 'D7:F7';
        $mergeCells[] = 'G7:G8';

        // Row 8: Main Table Headers Row 2 (Subheaders)
        $sheetXml .= '<row r="8" ht="16" customHeight="1">'
            . '<c r="A8" s="6"/><c r="B8" s="6"/><c r="C8" s="6"/>'
            . '<c r="D8" t="inlineStr" s="6"><is><t>Active</t></is></c>'
            . '<c r="E8" t="inlineStr" s="6"><is><t>Storage</t></is></c>'
            . '<c r="F8" t="inlineStr" s="6"><is><t>Total</t></is></c>'
            . '<c r="G8" s="6"/>'
            . '</row>';

        $dash = function($val) {
            $s = trim((string)$val);
            return ($s === '' || $s === 'null' || $s === 'None' || $s === 'N/A') ? '—' : $s;
        };

        $r = 9;
        $cols7 = ['A','B','C','D','E','F','G'];

        foreach ($items as $item) {
            if (is_array($item)) {
                $item = (object)$item;
            }
            $mergeCells[] = 'B' . $r . ':C' . $r;

            $itemNo = (string)($item->display_item_no ?? $item->item_no ?? $item->item_number ?? '');
            $title = (string)($item->series_title ?? $item->title ?? '');
            $depth = (int)($item->depth ?? 0);
            $active = $item->effective_active ?? $item->active_period ?? $item->active ?? '';
            $storage = $item->effective_storage ?? $item->storage_period ?? $item->storage ?? '';
            $total = $item->effective_total ?? $item->total_period ?? $item->total ?? '';
            $remarks = (string)($item->remarks ?? $item->rem ?? '');

            $isH = !empty($item->is_header) || (!empty($item->is_root_parent) && !empty($item->has_children));
            $isPerm = !empty($item->effective_is_permanent) || !empty($item->is_perm) || (strtolower(trim((string)$total)) === 'permanent');

            $displayTitle = ($depth > 0) ? (str_repeat('   ', $depth) . '└ ' . $title) : ($isH ? strtoupper($title) : $title);

            $sheetXml .= '<row r="' . $r . '" ht="19" customHeight="1">'
                . '<c r="A' . $r . '" t="inlineStr" s="' . ($isH ? '10' : '9') . '"><is><t>' . htmlspecialchars($itemNo, ENT_XML1, 'UTF-8') . '</t></is></c>'
                . '<c r="B' . $r . '" t="inlineStr" s="' . ($isH ? '8' : '7') . '"><is><t>' . htmlspecialchars($displayTitle, ENT_XML1, 'UTF-8') . '</t></is></c>'
                . '<c r="C' . $r . '" s="' . ($isH ? '8' : '7') . '"/>';

            if ($isH) {
                $sheetXml .= '<c r="D' . $r . '" s="11"/><c r="E' . $r . '" s="11"/><c r="F' . $r . '" s="11"/><c r="G' . $r . '" s="11"/>';
            } elseif ($isPerm) {
                $mergeCells[] = 'D' . $r . ':F' . $r;
                $sheetXml .= '<c r="D' . $r . '" t="inlineStr" s="10"><is><t>PERMANENT</t></is></c>'
                    . '<c r="E' . $r . '" s="10"/><c r="F' . $r . '" s="10"/>'
                    . '<c r="G' . $r . '" t="inlineStr" s="7"><is><t>' . htmlspecialchars($remarks, ENT_XML1, 'UTF-8') . '</t></is></c>';
            } else {
                $sheetXml .= '<c r="D' . $r . '" t="inlineStr" s="9"><is><t>' . htmlspecialchars($dash($active), ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '<c r="E' . $r . '" t="inlineStr" s="9"><is><t>' . htmlspecialchars($dash($storage), ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '<c r="F' . $r . '" t="inlineStr" s="10"><is><t>' . htmlspecialchars($dash($total), ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '<c r="G' . $r . '" t="inlineStr" s="7"><is><t>' . htmlspecialchars($remarks, ENT_XML1, 'UTF-8') . '</t></is></c>';
            }

            $sheetXml .= '</row>';
            $r++;
        }

        // Pad empty rows up to row 36 (minimum 28 table rows to fill full paper size)
        for (; $r <= 36; $r++) {
            $mergeCells[] = 'B' . $r . ':C' . $r;
            $sheetXml .= '<row r="' . $r . '" ht="18" customHeight="1">';
            foreach ($cols7 as $col) {
                $sheetXml .= '<c r="' . $col . $r . '" s="11"/>';
            }
            $sheetXml .= '</row>';
        }

        // Statutory Notice
        $sheetXml .= '<row r="' . $r . '" ht="28" customHeight="1">'
            . '<c r="A' . $r . '" t="inlineStr" s="14"><is><t>IMPORTANT: Pursuant to Section 18, Article III, RA 9470 s. 2007, "No government department, bureau, agency and instrumentality shall dispose of, destroy or authorize the disposal or destruction of any public records, which are in the custody or under its control except with the prior written authority of the executive director."</t></is></c>'
            . '<c r="B' . $r . '" s="14"/><c r="C' . $r . '" s="14"/><c r="D' . $r . '" s="14"/><c r="E' . $r . '" s="14"/><c r="F' . $r . '" s="14"/><c r="G' . $r . '" s="14"/>'
            . '</row>';
        $mergeCells[] = 'A' . $r . ':G' . $r;
        $r += 2; // Spacer

        // Signatures Section
        // Row r: 9. Prepared by (A:C) | 11. Recommending Approval (D:G)
        $sheetXml .= '<row r="' . $r . '" ht="16" customHeight="1">'
            . '<c r="A' . $r . '" t="inlineStr" s="2"><is><t>9. Prepared by:</t></is></c>'
            . '<c r="B' . $r . '" s="2"/><c r="C' . $r . '" s="2"/>'
            . '<c r="D' . $r . '" t="inlineStr" s="2"><is><t>11. Recommending Approval:</t></is></c>'
            . '<c r="E' . $r . '" s="2"/><c r="F' . $r . '" s="2"/><c r="G' . $r . '" s="2"/>'
            . '</row>';
        $mergeCells[] = 'A' . $r . ':C' . $r;
        $mergeCells[] = 'D' . $r . ':G' . $r;
        $r++;

        // Blank row for signature
        $sheetXml .= '<row r="' . $r . '" ht="18" customHeight="1"/>';
        $r++;

        // Underline + Name
        $sheetXml .= '<row r="' . $r . '" ht="20" customHeight="1">'
            . '<c r="A' . $r . '" s="0"/>'
            . '<c r="B' . $r . '" t="inlineStr" s="12"><is><t>' . htmlspecialchars($preparedBy, ENT_XML1, 'UTF-8') . '</t></is></c>'
            . '<c r="C' . $r . '" s="12"/>'
            . '<c r="D' . $r . '" s="0"/>'
            . '<c r="E' . $r . '" t="inlineStr" s="12"><is><t>' . htmlspecialchars($recommendingBy, ENT_XML1, 'UTF-8') . '</t></is></c>'
            . '<c r="F' . $r . '" s="12"/><c r="G' . $r . '" s="0"/>'
            . '</row>';
        $mergeCells[] = 'B' . $r . ':C' . $r;
        $mergeCells[] = 'E' . $r . ':F' . $r;
        $r++;

        // Position
        $sheetXml .= '<row r="' . $r . '" ht="16" customHeight="1">'
            . '<c r="A' . $r . '" s="0"/>'
            . '<c r="B' . $r . '" t="inlineStr" s="13"><is><t>' . htmlspecialchars($preparedPos, ENT_XML1, 'UTF-8') . '</t></is></c>'
            . '<c r="C' . $r . '" s="13"/>'
            . '<c r="D' . $r . '" s="0"/>'
            . '<c r="E' . $r . '" t="inlineStr" s="13"><is><t>' . htmlspecialchars($recommendingPos, ENT_XML1, 'UTF-8') . '</t></is></c>'
            . '<c r="F' . $r . '" s="13"/><c r="G' . $r . '" s="0"/>'
            . '</row>';
        $mergeCells[] = 'B' . $r . ':C' . $r;
        $mergeCells[] = 'E' . $r . ':F' . $r;
        $r += 2;

        // Row: 10. Assisted by (A:C) | 12. Approved (D:G)
        $sheetXml .= '<row r="' . $r . '" ht="16" customHeight="1">'
            . '<c r="A' . $r . '" t="inlineStr" s="2"><is><t>10. Assisted by:</t></is></c>'
            . '<c r="B' . $r . '" s="2"/><c r="C' . $r . '" s="2"/>'
            . '<c r="D' . $r . '" t="inlineStr" s="2"><is><t>12. Approved</t></is></c>'
            . '<c r="E' . $r . '" s="2"/><c r="F' . $r . '" s="2"/><c r="G' . $r . '" s="2"/>'
            . '</row>';
        $mergeCells[] = 'A' . $r . ':C' . $r;
        $mergeCells[] = 'D' . $r . ':G' . $r;
        $r++;

        $sheetXml .= '<row r="' . $r . '" ht="18" customHeight="1"/>';
        $r++;

        $sheetXml .= '<row r="' . $r . '" ht="20" customHeight="1">'
            . '<c r="A' . $r . '" s="0"/>'
            . '<c r="B' . $r . '" t="inlineStr" s="12"><is><t>' . htmlspecialchars($assistedBy, ENT_XML1, 'UTF-8') . '</t></is></c>'
            . '<c r="C' . $r . '" s="12"/>'
            . '<c r="D' . $r . '" s="0"/>'
            . '<c r="E' . $r . '" t="inlineStr" s="12"><is><t>' . htmlspecialchars($approvedBy, ENT_XML1, 'UTF-8') . '</t></is></c>'
            . '<c r="F' . $r . '" s="12"/><c r="G' . $r . '" s="0"/>'
            . '</row>';
        $mergeCells[] = 'B' . $r . ':C' . $r;
        $mergeCells[] = 'E' . $r . ':F' . $r;
        $r++;

        $sheetXml .= '<row r="' . $r . '" ht="16" customHeight="1">'
            . '<c r="A' . $r . '" s="0"/>'
            . '<c r="B' . $r . '" t="inlineStr" s="13"><is><t>' . htmlspecialchars($assistedPos, ENT_XML1, 'UTF-8') . '</t></is></c>'
            . '<c r="C' . $r . '" s="13"/>'
            . '<c r="D' . $r . '" s="0"/>'
            . '<c r="E' . $r . '" t="inlineStr" s="13"><is><t>' . htmlspecialchars($approvedPos, ENT_XML1, 'UTF-8') . '</t></is></c>'
            . '<c r="F' . $r . '" s="13"/><c r="G' . $r . '" s="0"/>'
            . '</row>';
        $mergeCells[] = 'B' . $r . ':C' . $r;
        $mergeCells[] = 'E' . $r . ':F' . $r;
        $r += 2;

        // NAP Official Evaluation Box
        $sheetXml .= '<row r="' . $r . '" ht="20" customHeight="1">'
            . '<c r="A' . $r . '" t="inlineStr" s="2"><is><t>TO BE ACCOMPLISHED BY THE NATIONAL ARCHIVES OF THE PHILIPPINES</t></is></c>'
            . '<c r="B' . $r . '" s="2"/><c r="C' . $r . '" s="2"/><c r="D' . $r . '" s="2"/><c r="E' . $r . '" s="2"/><c r="F' . $r . '" s="2"/><c r="G' . $r . '" s="2"/>'
            . '</row>';
        $mergeCells[] = 'A' . $r . ':G' . $r;
        $r++;

        $sheetXml .= '<row r="' . $r . '" ht="16" customHeight="1">'
            . '<c r="A' . $r . '" t="inlineStr" s="0"><is><t>This Records Disposition Schedule</t></is></c>'
            . '</row>';
        $r++;

        $sheetXml .= '<row r="' . $r . '" ht="16" customHeight="1">'
            . '<c r="B' . $r . '" t="inlineStr" s="0"><is><t>[   ]  is being returned for improvement / correction</t></is></c>'
            . '</row>';
        $r++;

        $sheetXml .= '<row r="' . $r . '" ht="16" customHeight="1">'
            . '<c r="B' . $r . '" t="inlineStr" s="0"><is><t>[   ]  is being recommended for approval</t></is></c>'
            . '</row>';
        $r += 2;

        // Chairman & Executive Director
        $sheetXml .= '<row r="' . $r . '" ht="20" customHeight="1">'
            . '<c r="A' . $r . '" s="0"/>'
            . '<c r="B' . $r . '" t="inlineStr" s="12"><is><t>' . htmlspecialchars($committeeChairman, ENT_XML1, 'UTF-8') . '</t></is></c>'
            . '<c r="C' . $r . '" s="12"/><c r="D' . $r . '" s="0"/>'
            . '<c r="E' . $r . '" t="inlineStr" s="12"><is><t>' . htmlspecialchars($execDirector, ENT_XML1, 'UTF-8') . '</t></is></c>'
            . '<c r="F' . $r . '" s="12"/><c r="G' . $r . '" s="0"/>'
            . '</row>';
        $mergeCells[] = 'B' . $r . ':C' . $r;
        $mergeCells[] = 'E' . $r . ':F' . $r;
        $r++;

        $sheetXml .= '<row r="' . $r . '" ht="16" customHeight="1">'
            . '<c r="A' . $r . '" s="0"/>'
            . '<c r="B' . $r . '" t="inlineStr" s="13"><is><t>' . htmlspecialchars($committeeChairmanTitle, ENT_XML1, 'UTF-8') . '</t></is></c>'
            . '<c r="C' . $r . '" s="13"/><c r="D' . $r . '" s="0"/>'
            . '<c r="E' . $r . '" t="inlineStr" s="13"><is><t>' . htmlspecialchars($execDirectorTitle, ENT_XML1, 'UTF-8') . '</t></is></c>'
            . '<c r="F' . $r . '" s="13"/><c r="G' . $r . '" s="0"/>'
            . '</row>';
        $mergeCells[] = 'B' . $r . ':C' . $r;
        $mergeCells[] = 'E' . $r . ':F' . $r;

        $sheetXml .= '</sheetData>';

        // Merged Cells
        $sheetXml .= '<mergeCells count="' . count($mergeCells) . '">';
        foreach ($mergeCells as $range) {
            $sheetXml .= '<mergeCell ref="' . $range . '"/>';
        }
        $sheetXml .= '</mergeCells>';

        // Page Margins and Print Setup (Legal Portrait)
        $sheetXml .= '<pageMargins left="0.4" right="0.4" top="0.4" bottom="0.4" header="0.2" footer="0.2"/>'
            . '<pageSetup orientation="portrait" paperSize="5" fitToWidth="1" fitToHeight="0"/>'
            . '<headerFooter><oddHeader>&amp;L&amp;7NAP Form 2&#10;2008</oddHeader></headerFooter>'
            . '</worksheet>';

        // Stylesheet definition
        $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="6">'
            . '<font><sz val="8.5"/><name val="Arial"/></font>'
            . '<font><b/><sz val="8.5"/><name val="Arial"/></font>'
            . '<font><b/><sz val="9"/><name val="Arial"/></font>'
            . '<font><b/><sz val="10"/><name val="Arial"/></font>'
            . '<font><i/><sz val="8"/><name val="Arial"/></font>'
            . '<font><sz val="7.5"/><color rgb="FF555555"/><name val="Arial"/></font>'
            . '</fonts>'
            . '<fills count="2">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '</fills>'
            . '<borders count="3">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left style="thin"><color rgb="FF000000"/></left>'
            . '<right style="thin"><color rgb="FF000000"/></right>'
            . '<top style="thin"><color rgb="FF000000"/></top>'
            . '<bottom style="thin"><color rgb="FF000000"/></bottom>'
            . '<diagonal/></border>'
            . '<border><left/><right/><top/>'
            . '<bottom style="thin"><color rgb="FF000000"/></bottom>'
            . '<diagonal/></border>'
            . '</borders>'
            . '<cellXfs count="15">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0"><alignment horizontal="left" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="1" applyFont="1" applyBorder="1"><alignment horizontal="left" vertical="top"/></xf>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="1" applyFont="1" applyBorder="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="3" fillId="0" borderId="1" applyFont="1" applyBorder="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="4" fillId="0" borderId="1" applyFont="1" applyBorder="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="1" applyFont="1" applyBorder="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" applyFont="1" applyBorder="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="1" applyFont="1" applyBorder="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" applyFont="1" applyBorder="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="1" applyFont="1" applyBorder="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" applyBorder="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="2" applyFont="1" applyBorder="1"><alignment horizontal="center" vertical="bottom"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" applyFont="1"><alignment horizontal="center" vertical="top"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0"><alignment horizontal="left" vertical="top" wrapText="1"/></xf>'
            . '</cellXfs>'
            . '</styleSheet>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';

        $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';

        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';

        $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="NAP Form 2" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';

        $zip = new RdpSimpleZip();
        $zip->addFile('[Content_Types].xml', $contentTypes);
        $zip->addFile('_rels/.rels', $rootRels);
        $zip->addFile('xl/_rels/workbook.xml.rels', $wbRels);
        $zip->addFile('xl/workbook.xml', $workbookXml);
        $zip->addFile('xl/styles.xml', $stylesXml);
        $zip->addFile('xl/worksheets/sheet1.xml', $sheetXml);

        return $zip->getZip();
    }

    /**
     * Stream official NAP Form 2 Excel spreadsheet.
     */
    public static function streamNap2Xlsx(
        string $filename,
        object $cluster,
        array $items,
        array $signatures = []
    ): StreamedResponse {
        $xlsxContent = self::generateNap2TemplateXlsxBinary($cluster, $items, $signatures);

        return new StreamedResponse(function () use ($xlsxContent) {
            echo $xlsxContent;
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length' => strlen($xlsxContent),
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    /**
     * Generate high-fidelity OpenXML (.xlsx) binary matching official NAP Form 3 (Revised 2012).
     */
    public static function generateNap3TemplateXlsxBinary(
        object $cluster,
        array $items,
        array $signatures = []
    ): string {
        $agency = $signatures['agencyName'] ?? (!empty($cluster->office_name) ? $cluster->office_name : 'Camarines Sur Polytechnic Colleges');
        $address = $signatures['agencyAddress'] ?? 'San Miguel, Nabua, Camarines Sur';
        $datePrepared = $signatures['datePrepared'] ?? (!empty($cluster->created_at) ? \Carbon\Carbon::parse($cluster->created_at)->format('F d, Y') : date('F d, Y'));
        $tel = $signatures['telephoneNumber'] ?? ($signatures['tel'] ?? '(054) 288-1534 loc. 113');
        $location = $signatures['effectiveLocation'] ?? ($signatures['location'] ?? 'Records Management Office');
        $volume = $signatures['effectiveVolume'] ?? ($signatures['volume'] ?? '');

        $preparedBy = $signatures['preparedBy'] ?? ($cluster->submitter_name ?? '');
        $preparedPos = $signatures['preparedPosition'] ?? 'Administrative Officer V / Records Officer';
        $approvedBy = $signatures['approvedBy'] ?? '';

        // Flatten items if passed as hierarchy tree
        $includeDesc = !empty($signatures['includeDescription']);

        if (!empty($items) && is_array($items[0]) && isset($items[0]['type'])) {
            $flattenedItems = $items;
        } elseif (!empty($items) && is_object($items[0]) && isset($items[0]->type)) {
            $flattenedItems = array_map(function($it) { return (array)$it; }, $items);
        } elseif (!empty($items) && (isset($items[0]->series_title) || isset($items[0]->sub_series) || isset($items[0]->has_children))) {
            $flattenedItems = self::flattenHierarchy($items, $includeDesc);
        } else {
            $flattenedItems = [];
            foreach ($items as $it) {
                if (is_array($it)) {
                    $it = (object)$it;
                }
                $flattenedItems[] = [
                    'type' => 'root_standalone',
                    'root' => $it,
                ];
            }
        }

        // 5 columns: A (Item No: 14), B (Title 1: 22), C (Title 2: 20), D (Period: 26), E (Retention: 30)
        // Left = A+B+C = 56. Right = D+E = 56. Total = 112.
        $colWidths = [14.00, 22.00, 20.00, 26.00, 30.00];

        $mergeCells = [];
        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0" showGridLines="1"/></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="16"/>'
            . '<cols>';

        foreach ($colWidths as $idx => $w) {
            $c = $idx + 1;
            $sheetXml .= '<col min="' . $c . '" max="' . $c . '" width="' . $w . '" customWidth="1"/>';
        }
        $sheetXml .= '</cols><sheetData>';

        // Row 1: Top Line
        $sheetXml .= '<row r="1" ht="14" customHeight="1">'
            . '<c r="A1" t="inlineStr" s="0"><is><t>NAP Form No. 3&#10;Revised 2012</t></is></c>'
            . '<c r="B1" s="0"/><c r="C1" s="0"/><c r="D1" s="0"/>'
            . '<c r="E1" t="inlineStr" s="5"><is><t>Accomplish in 3 copies</t></is></c>'
            . '</row>';
        $mergeCells[] = 'A1:C1';

        // Row 2: Top Header Box
        $sheetXml .= '<row r="2" ht="14" customHeight="1">'
            . '<c r="A2" t="inlineStr" s="4"><is><t>NATIONAL ARCHIVES OF THE PHILIPPINES&#10;Pambansang Sinupan ng Pilipinas&#10;&#10;REQUEST FOR AUTHORITY TO DISPOSE&#10;OF RECORDS</t></is></c>'
            . '<c r="B2" s="4"/><c r="C2" s="4"/>'
            . '<c r="D2" t="inlineStr" s="2"><is><t>AGENCY NAME:</t></is></c>'
            . '<c r="E2" s="2"/>'
            . '</row>';
        $mergeCells[] = 'A2:C5';
        $mergeCells[] = 'D2:E2';

        // Row 3: Agency Name Value
        $sheetXml .= '<row r="3" ht="20" customHeight="1">'
            . '<c r="A3" s="4"/><c r="B3" s="4"/><c r="C3" s="4"/>'
            . '<c r="D3" t="inlineStr" s="3"><is><t>' . htmlspecialchars($agency, ENT_XML1, 'UTF-8') . '</t></is></c>'
            . '<c r="E3" s="3"/>'
            . '</row>';
        $mergeCells[] = 'D3:E3';

        // Row 4: Address Label
        $sheetXml .= '<row r="4" ht="14" customHeight="1">'
            . '<c r="A4" s="4"/><c r="B4" s="4"/><c r="C4" s="4"/>'
            . '<c r="D4" t="inlineStr" s="2"><is><t>ADDRESS:</t></is></c>'
            . '<c r="E4" s="2"/>'
            . '</row>';
        $mergeCells[] = 'D4:E4';

        // Row 5: Address Value
        $sheetXml .= '<row r="5" ht="22" customHeight="1">'
            . '<c r="A5" s="4"/><c r="B5" s="4"/><c r="C5" s="4"/>'
            . '<c r="D5" t="inlineStr" s="3"><is><t>' . htmlspecialchars($address, ENT_XML1, 'UTF-8') . '</t></is></c>'
            . '<c r="E5" s="3"/>'
            . '</row>';
        $mergeCells[] = 'D5:E5';

        // Row 6: Date (Left) & Telephone Number (Right)
        $sheetXml .= '<row r="6" ht="18" customHeight="1">'
            . '<c r="A6" t="inlineStr" s="2"><is><t>DATE: ' . htmlspecialchars($datePrepared, ENT_XML1, 'UTF-8') . '</t></is></c>'
            . '<c r="B6" s="2"/><c r="C6" s="2"/>'
            . '<c r="D6" t="inlineStr" s="2"><is><t>TELEPHONE NUMBER: ' . htmlspecialchars($tel, ENT_XML1, 'UTF-8') . '</t></is></c>'
            . '<c r="E6" s="2"/>'
            . '</row>';
        $mergeCells[] = 'A6:C6';
        $mergeCells[] = 'D6:E6';

        // Row 7: Main Table Headers
        $sheetXml .= '<row r="7" ht="26" customHeight="1">'
            . '<c r="A7" t="inlineStr" s="6"><is><t>GRDS/ RDS ITEM NO.</t></is></c>'
            . '<c r="B7" t="inlineStr" s="6"><is><t>RECORD SERIES TITLE AND DESCRIPTION</t></is></c>'
            . '<c r="C7" s="6"/>'
            . '<c r="D7" t="inlineStr" s="6"><is><t>PERIOD COVERED</t></is></c>'
            . '<c r="E7" t="inlineStr" s="6"><is><t>RETENTION PERIOD AND PROVISION/S COMPLIED (If Any)</t></is></c>'
            . '</row>';
        $mergeCells[] = 'B7:C7';

        $dash = function($val) {
            $s = trim((string)$val);
            return ($s === '' || $s === 'null' || $s === 'None' || $s === 'N/A') ? '—' : $s;
        };

        $r = 8;
        $cols5 = ['A','B','C','D','E'];

        foreach ($flattenedItems as $item) {
            $mergeCells[] = 'B' . $r . ':C' . $r;

            if ($item['type'] === 'root_standalone') {
                $root = $item['root'];
                $itemNo = (string)($root->item_number ?? $root->item_no ?? '');
                $title = (string)($root->series_title ?? $root->title ?? '');
                $period = $root->compiled_period ?? $root->period_covered ?? '';
                $retention = trim(($root->total_period ?? '') . (!empty($root->remarks) ? ' / ' . $root->remarks : ''));

                $sheetXml .= '<row r="' . $r . '" ht="19" customHeight="1">'
                    . '<c r="A' . $r . '" t="inlineStr" s="9"><is><t>' . htmlspecialchars($itemNo, ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '<c r="B' . $r . '" t="inlineStr" s="8"><is><t>' . htmlspecialchars(strtoupper($title), ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '<c r="C' . $r . '" s="8"/>'
                    . '<c r="D' . $r . '" t="inlineStr" s="9"><is><t>' . htmlspecialchars($dash($period), ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '<c r="E' . $r . '" t="inlineStr" s="9"><is><t>' . htmlspecialchars($dash($retention), ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '</row>';
                $r++;
            } elseif ($item['type'] === 'root_header') {
                $root = $item['root'];
                $itemNo = (string)($root->item_number ?? $root->item_no ?? '');
                $title = (string)($root->series_title ?? $root->title ?? '');

                $sheetXml .= '<row r="' . $r . '" ht="19" customHeight="1">'
                    . '<c r="A' . $r . '" t="inlineStr" s="10"><is><t>' . htmlspecialchars($itemNo, ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '<c r="B' . $r . '" t="inlineStr" s="8"><is><t>' . htmlspecialchars(strtoupper($title), ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '<c r="C' . $r . '" s="8"/>'
                    . '<c r="D' . $r . '" s="11"/><c r="E' . $r . '" s="11"/>'
                    . '</row>';
                $r++;
            } elseif ($item['type'] === 'sub_series') {
                $sub = $item['sub'];
                $root = $item['root'] ?? null;
                $title = (string)($sub->series_title ?? $sub->title ?? '');
                $period = $sub->compiled_period ?? $sub->period_covered ?? '';
                $rem = ($sub->remarks ?? '') ?: ($root ? ($root->remarks ?? '') : '');
                $retention = trim(($sub->total_period ?? '') . (!empty($rem) ? ' / ' . $rem : ''));

                $sheetXml .= '<row r="' . $r . '" ht="19" customHeight="1">'
                    . '<c r="A' . $r . '" s="11"/>'
                    . '<c r="B' . $r . '" t="inlineStr" s="7"><is><t>   └ ' . htmlspecialchars($title, ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '<c r="C' . $r . '" s="7"/>'
                    . '<c r="D' . $r . '" t="inlineStr" s="9"><is><t>' . htmlspecialchars($dash($period), ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '<c r="E' . $r . '" t="inlineStr" s="9"><is><t>' . htmlspecialchars($dash($retention), ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '</row>';
                $r++;
            } elseif ($item['type'] === 'record') {
                $rec = $item['rec'];
                $desc = (string)($rec->description ?? '');
                $dateCov = $rec->date_covered ?? '';

                $sheetXml .= '<row r="' . $r . '" ht="19" customHeight="1">'
                    . '<c r="A' . $r . '" s="11"/>'
                    . '<c r="B' . $r . '" t="inlineStr" s="7"><is><t>      ' . htmlspecialchars($desc, ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '<c r="C' . $r . '" s="7"/>'
                    . '<c r="D' . $r . '" t="inlineStr" s="9"><is><t>' . htmlspecialchars($dash($dateCov), ENT_XML1, 'UTF-8') . '</t></is></c>'
                    . '<c r="E' . $r . '" s="11"/>'
                    . '</row>';
                $r++;
            }
        }

        // Pad empty rows up to row 30 (minimum 23 table rows to fill full paper size)
        for (; $r <= 30; $r++) {
            $mergeCells[] = 'B' . $r . ':C' . $r;
            $sheetXml .= '<row r="' . $r . '" ht="18" customHeight="1">';
            foreach ($cols5 as $col) {
                $sheetXml .= '<c r="' . $col . $r . '" s="11"/>';
            }
            $sheetXml .= '</row>';
        }

        // Footer Box 1: Location (Left) & Volume (Right)
        $sheetXml .= '<row r="' . $r . '" ht="22" customHeight="1">'
            . '<c r="A' . $r . '" t="inlineStr" s="2"><is><t>LOCATION OF RECORDS: ' . htmlspecialchars($location, ENT_XML1, 'UTF-8') . '</t></is></c>'
            . '<c r="B' . $r . '" s="2"/><c r="C' . $r . '" s="2"/>'
            . '<c r="D' . $r . '" t="inlineStr" s="2"><is><t>VOLUME IN CUBIC METER: ' . htmlspecialchars($volume, ENT_XML1, 'UTF-8') . '</t></is></c>'
            . '<c r="E' . $r . '" s="2"/>'
            . '</row>';
        $mergeCells[] = 'A' . $r . ':C' . $r;
        $mergeCells[] = 'D' . $r . ':E' . $r;
        $r++;

        // Footer Box 2: Prepared By (Left) & Position (Right)
        $sheetXml .= '<row r="' . $r . '" ht="22" customHeight="1">'
            . '<c r="A' . $r . '" t="inlineStr" s="2"><is><t>PREPARED BY: ' . htmlspecialchars($preparedBy, ENT_XML1, 'UTF-8') . '</t></is></c>'
            . '<c r="B' . $r . '" s="2"/><c r="C' . $r . '" s="2"/>'
            . '<c r="D' . $r . '" t="inlineStr" s="2"><is><t>POSITION: ' . htmlspecialchars($preparedPos, ENT_XML1, 'UTF-8') . '</t></is></c>'
            . '<c r="E' . $r . '" s="2"/>'
            . '</row>';
        $mergeCells[] = 'A' . $r . ':C' . $r;
        $mergeCells[] = 'D' . $r . ':E' . $r;
        $r++;

        // Footer Box 3: Certified and Approved By
        $sheetXml .= '<row r="' . $r . '" ht="16" customHeight="1">'
            . '<c r="A' . $r . '" t="inlineStr" s="2"><is><t>CERTIFIED AND APPROVED BY:</t></is></c>'
            . '<c r="B' . $r . '" s="2"/><c r="C' . $r . '" s="2"/><c r="D' . $r . '" s="2"/><c r="E' . $r . '" s="2"/>'
            . '</row>';
        $mergeCells[] = 'A' . $r . ':E' . $r;
        $r++;

        $sheetXml .= '<row r="' . $r . '" ht="26" customHeight="1">'
            . '<c r="A' . $r . '" t="inlineStr" s="14"><is><t>This is to certify that the above mentioned records are no longer needed and not involved nor connected in any administrative or judicial cases.</t></is></c>'
            . '<c r="B' . $r . '" s="14"/><c r="C' . $r . '" s="14"/><c r="D' . $r . '" s="14"/><c r="E' . $r . '" s="14"/>'
            . '</row>';
        $mergeCells[] = 'A' . $r . ':E' . $r;
        $r += 2; // Signature space

        // Signature Underline + Approved Name
        $sheetXml .= '<row r="' . $r . '" ht="20" customHeight="1">'
            . '<c r="A' . $r . '" s="0"/><c r="B' . $r . '" s="0"/>'
            . '<c r="C' . $r . '" t="inlineStr" s="12"><is><t>' . htmlspecialchars($approvedBy, ENT_XML1, 'UTF-8') . '</t></is></c>'
            . '<c r="D' . $r . '" s="12"/><c r="E' . $r . '" s="12"/>'
            . '</row>';
        $mergeCells[] = 'C' . $r . ':E' . $r;
        $r++;

        // Label below signature line
        $sheetXml .= '<row r="' . $r . '" ht="16" customHeight="1">'
            . '<c r="A' . $r . '" s="0"/><c r="B' . $r . '" s="0"/>'
            . '<c r="C' . $r . '" t="inlineStr" s="13"><is><t>Name and Signature of Agency Head or Duly Authorized Representative</t></is></c>'
            . '<c r="D' . $r . '" s="13"/><c r="E' . $r . '" s="13"/>'
            . '</row>';
        $mergeCells[] = 'C' . $r . ':E' . $r;

        $sheetXml .= '</sheetData>';

        // Merged Cells
        $sheetXml .= '<mergeCells count="' . count($mergeCells) . '">';
        foreach ($mergeCells as $range) {
            $sheetXml .= '<mergeCell ref="' . $range . '"/>';
        }
        $sheetXml .= '</mergeCells>';

        // Page Margins and Print Setup
        $sheetXml .= '<pageMargins left="0.4" right="0.4" top="0.4" bottom="0.4" header="0.2" footer="0.2"/>'
            . '<pageSetup orientation="portrait" paperSize="5" fitToWidth="1" fitToHeight="0"/>'
            . '<headerFooter><oddHeader>&amp;LNAP Form No. 3&#10;Revised 2012&amp;RAccomplish in 3 copies</oddHeader></headerFooter>'
            . '</worksheet>';

        // Stylesheet definition
        $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="6">'
            . '<font><sz val="8.5"/><name val="Arial"/></font>'
            . '<font><b/><sz val="8.5"/><name val="Arial"/></font>'
            . '<font><b/><sz val="9"/><name val="Arial"/></font>'
            . '<font><b/><sz val="10"/><name val="Arial"/></font>'
            . '<font><i/><sz val="8"/><name val="Arial"/></font>'
            . '<font><i/><sz val="8.5"/><color rgb="FF555555"/><name val="Arial"/></font>'
            . '</fonts>'
            . '<fills count="2">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '</fills>'
            . '<borders count="3">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left style="thin"><color rgb="FF000000"/></left>'
            . '<right style="thin"><color rgb="FF000000"/></right>'
            . '<top style="thin"><color rgb="FF000000"/></top>'
            . '<bottom style="thin"><color rgb="FF000000"/></bottom>'
            . '<diagonal/></border>'
            . '<border><left/><right/><top/>'
            . '<bottom style="thin"><color rgb="FF000000"/></bottom>'
            . '<diagonal/></border>'
            . '</borders>'
            . '<cellXfs count="15">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0"><alignment horizontal="left" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="1" applyFont="1" applyBorder="1"><alignment horizontal="left" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="1" applyFont="1" applyBorder="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="3" fillId="0" borderId="1" applyFont="1" applyBorder="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="5" fillId="0" borderId="0" applyFont="1"><alignment horizontal="right" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="1" applyFont="1" applyBorder="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" applyFont="1" applyBorder="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="1" applyFont="1" applyBorder="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" applyFont="1" applyBorder="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="1" applyFont="1" applyBorder="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" applyBorder="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="2" applyFont="1" applyBorder="1"><alignment horizontal="center" vertical="bottom"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" applyFont="1"><alignment horizontal="center" vertical="top"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '</cellXfs>'
            . '</styleSheet>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';

        $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';

        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';

        $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="NAP Form 3" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';

        $zip = new RdpSimpleZip();
        $zip->addFile('[Content_Types].xml', $contentTypes);
        $zip->addFile('_rels/.rels', $rootRels);
        $zip->addFile('xl/_rels/workbook.xml.rels', $wbRels);
        $zip->addFile('xl/workbook.xml', $workbookXml);
        $zip->addFile('xl/styles.xml', $stylesXml);
        $zip->addFile('xl/worksheets/sheet1.xml', $sheetXml);

        return $zip->getZip();
    }

    /**
     * Stream official NAP Form 3 Excel spreadsheet.
     */
    public static function streamNap3Xlsx(
        string $filename,
        object $cluster,
        array $items,
        array $signatures = []
    ): StreamedResponse {
        $xlsxContent = self::generateNap3TemplateXlsxBinary($cluster, $items, $signatures);

        return new StreamedResponse(function () use ($xlsxContent) {
            echo $xlsxContent;
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length' => strlen($xlsxContent),
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    /**
     * Stream a general OpenXML (.xlsx) file to the browser.
     */
    public static function streamXlsx(
        string $filename,
        string $title,
        array $meta,
        array $headers,
        array $rows
    ): StreamedResponse {
        $xlsxContent = self::generateXlsxBinary($title, $meta, $headers, $rows);

        return new StreamedResponse(function () use ($xlsxContent) {
            echo $xlsxContent;
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length' => strlen($xlsxContent),
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    /**
     * Sanitize string values, matching Blade cleanVal helper.
     */
    public static function cleanVal(mixed $val, bool $allowDash = false): string
    {
        if ($val === null) return $allowDash ? '—' : '';
        $str = trim((string)$val);
        if ($str === '' || $str === 'null' || $str === 'None' || $str === 'N/A') {
            return $allowDash ? '—' : '';
        }
        if ($str === '—' || $str === '-') {
            return $allowDash ? '—' : '';
        }
        return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Flattens a record tree into page-renderable rows matching the print preview hierarchy.
     */
    public static function flattenHierarchy(array $tree, bool $includeDescription = false): array
    {
        $flattened = [];
        foreach ($tree as $root) {
            $hasChildren = !empty($root->has_children) || !empty($root->sub_series);
            if (!$hasChildren) {
                $flattened[] = [
                    'type' => 'root_standalone',
                    'root' => $root,
                ];
                if ($includeDescription && !empty($root->direct_records)) {
                    foreach ($root->direct_records as $rec) {
                        $flattened[] = [
                            'type'   => 'record',
                            'rec'    => $rec,
                            'indent' => 20,
                        ];
                    }
                }
            } else {
                $flattened[] = [
                    'type' => 'root_header',
                    'root' => $root,
                ];
                if (!empty($root->sub_series)) {
                    foreach ($root->sub_series as $sub) {
                        $flattened[] = [
                            'type'   => 'sub_series',
                            'sub'    => $sub,
                            'root'   => $root,
                            'indent' => 16,
                        ];
                        if ($includeDescription && !empty($sub->records)) {
                            foreach ($sub->records as $rec) {
                                $flattened[] = [
                                    'type'   => 'record',
                                    'rec'    => $rec,
                                    'indent' => 26,
                                ];
                            }
                        }
                    }
                }
            }
        }
        return $flattened;
    }

    /**
     * Generates NAP Form 1 HTML layout matching print preview & client template exactly.
     * Features:
     * - Page 1: Full-height inventory table with borders running to the bottom (no legend, no signatures).
     * - Page 2: Dedicated appraisal page with continuation grid, Legend, and 3-signatory block with signature spacing.
     * - Displays '—' for empty data cells.
     */
    public static function buildNap1Html(object $cluster, array $items, array $sig = []): string
    {
        $agency = self::cleanVal($sig['agencyName'] ?? $cluster->office_name ?? $cluster->office ?? 'Camarines Sur Polytechnic Colleges');
        $dept = self::cleanVal($sig['departmentDivision'] ?? 'Office of the President');
        $section = self::cleanVal($sig['sectionUnit'] ?? 'Records Management Unit');
        $tel = self::cleanVal($sig['telephoneNumber'] ?? '(054) 288-1534 loc. 113');
        $email = self::cleanVal($sig['emailAddress'] ?? 'records@cspc.edu.ph');
        $address = self::cleanVal($sig['agencyAddress'] ?? 'San Miguel, Nabua, Camarines Sur');
        $personInCharge = self::cleanVal($sig['personInCharge'] ?? $cluster->submitter_name ?? 'Records Officer');
        $datePrepared = self::cleanVal($sig['datePrepared'] ?? date('m/d/Y', strtotime($cluster->created_at ?? 'now')));

        $preparedBy = self::cleanVal($sig['preparedBy'] ?? $cluster->submitter_name ?? '');
        $preparedPos = self::cleanVal($sig['preparedPosition'] ?? '');
        $assistedBy = self::cleanVal($sig['assistedBy'] ?? '');
        $assistedPos = self::cleanVal($sig['assistedPosition'] ?? '');
        $approvedBy = self::cleanVal($sig['approvedBy'] ?? '');
        $approvedPos = self::cleanVal($sig['approvedPosition'] ?? '');

        $includeDesc = (bool)($sig['includeDescription'] ?? false);

        if (!empty($items) && isset($items[0]->series_title) && (isset($items[0]->sub_series) || isset($items[0]->direct_records) || isset($items[0]->has_children))) {
            $flattenedItems = self::flattenHierarchy($items, $includeDesc);
        } elseif (!empty($items) && isset($items[0]['type'])) {
            $flattenedItems = $items;
        } else {
            $flattenedItems = [];
            foreach ($items as $it) {
                $flattenedItems[] = [
                    'type' => 'root_standalone',
                    'root' => (object)[
                        'series_title'        => $it->series_title ?? $it->doc_name ?? 'Untitled Series',
                        'compiled_period'     => $it->period_covered ?? '',
                        'compiled_volume'     => $it->volume ?? '',
                        'compiled_medium'     => $it->medium_name ?? $it->records_medium ?? '—',
                        'compiled_restriction'=> $it->access_restriction ?? $it->restriction ?? '—',
                        'compiled_location'   => $it->records_location ?? '',
                        'compiled_freq'       => $it->frequency_of_use ?? $it->frequence_use ?? '—',
                        'compiled_duplication'=> $it->duplication ?? '—',
                        'compiled_time'       => $it->time_value ?? 'T',
                        'compiled_util'       => $it->utility_name_display ?? 'Adm',
                        'is_permanent'        => (bool)($it->is_retention_period_permanent ?? false) || strtolower(trim($it->total_period ?? '')) === 'permanent',
                        'active_period'       => $it->active_period ?? '',
                        'storage_period'      => $it->storage_period ?? '—',
                        'total_period'        => $it->total_period ?? '',
                        'remarks'             => $it->remarks ?? '',
                    ]
                ];
            }
        }

        $totalItems = count($flattenedItems);
        $maxRowsFinalPage = 10;
        $maxRowsOtherPages = 15;

        if ($totalItems === 0) {
            $pages = [ [] ];
        } elseif ($totalItems <= $maxRowsFinalPage) {
            $pages = [ $flattenedItems ];
        } else {
            $remaining = $flattenedItems;
            $pages = [];
            while (!empty($remaining)) {
                if (count($remaining) <= $maxRowsFinalPage) {
                    $pages[] = $remaining;
                    break;
                }
                $chunkSize = min($maxRowsOtherPages, max(1, count($remaining) - 1));
                $chunk = array_splice($remaining, 0, $chunkSize);
                $pages[] = $chunk;
            }
        }
        $totalPages = count($pages);

        $cellBorder = "border-left: 1px solid #000; border-right: 1px solid #000; border-top: none; border-bottom: none;";

        $html = '<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page {
        size: 13in 8.5in;
        margin: 8mm 8mm 8mm 8mm;
    }
    body {
        font-family: Arial, Helvetica, sans-serif;
        font-size: 8px;
        color: #000000;
        margin: 0;
        padding: 0;
    }
    .page-container {
        width: 100%;
        page-break-after: always;
    }
    .page-container:last-child {
        page-break-after: avoid;
    }
    table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
    }
    .print-table th {
        border: 1px solid #000000;
        padding: 4px 2px;
        background: #ffffff;
        text-align: center;
        font-weight: bold;
        font-size: 8px;
        vertical-align: middle;
        color: #000000;
    }
    .print-table td {
        padding: 3px 4px;
        vertical-align: top;
        font-size: 8px;
        color: #000000;
        background: #ffffff;
    }
</style>
</head>
<body>';

        foreach ($pages as $pageIndex => $pageItems) {
            $isLastPage = ($pageIndex + 1) === $totalPages;

            // When last page has signatures, filler height stretches down to bottom border above Legend.
            $computedFiller = $isLastPage 
                ? max(40, 290 - (count($pageItems) * 20))
                : max(60, 480 - (count($pageItems) * 20));

            $html .= '<div class="page-container">';

            // Top Form Identifier
            $html .= '<div style="font-size: 8px; font-weight: normal; margin-bottom: 2px; line-height: 1.25;">
                NAP Records Inventory and Appraisal Form<br>2024
            </div>
            <br>';

            // Top Header Grid Box (Fields 1 to 8)
            $html .= '<table style="border: 2px solid #000; border-bottom: none; font-size: 8px; text-align: left;">
                <tr>
                    <td rowspan="3" style="width: 27.5%; border: 1px solid #000; text-align: center; vertical-align: middle; padding: 4px;">
                        <div style="border: 1.5px solid #000; padding: 8px 6px; margin: 2px;">
                            <div style="font-weight: bold; font-size: 10px; text-align: center;">NATIONAL ARCHIVES OF THE PHILIPPINES</div>
                            <div style="font-style: italic; font-size: 8.5px; margin: 2px 0 8px 0; text-align: center;">Pambansang Sinupan ng Pilipinas</div>
                            <div style="font-weight: bold; font-size: 10px; text-align: center;">RECORDS INVENTORY AND APPRAISAL</div>
                        </div>
                    </td>
                    <td rowspan="2" style="width: 27%; border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                        <strong>1. NAME OF OFFICE:</strong>
                        <div style="font-size: 9px; font-weight: bold; margin-top: 3px;">' . $agency . '</div>
                    </td>
                    <td style="width: 17.5%; border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                        <strong>2. DEPARTMENT/DIVISION:</strong>
                        <div style="font-size: 9px; font-weight: bold; margin-top: 3px;">' . $dept . '</div>
                    </td>
                    <td style="width: 28%; border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                        <strong>4. TELEPHONE NO.:</strong>
                        <div style="font-size: 9px; font-weight: bold; margin-top: 3px;">' . $tel . '</div>
                    </td>
                </tr>
                <tr>
                    <td style="border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                        <strong>3. SECTION/UNIT:</strong>
                        <div style="font-size: 9px; font-weight: bold; margin-top: 3px;">' . $section . '</div>
                    </td>
                    <td style="border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                        <strong>5. EMAIL ADDRESS.:</strong>
                        <div style="font-size: 9px; font-weight: bold; margin-top: 3px;">' . $email . '</div>
                    </td>
                </tr>
                <tr>
                    <td style="border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                        <strong>6. ADDRESS:</strong>
                        <div style="font-size: 9px; font-weight: bold; margin-top: 3px;">' . $address . '</div>
                    </td>
                    <td style="border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                        <strong>7. PERSON-IN-CHARGE OF FILES:</strong>
                        <div style="font-size: 9px; font-weight: bold; margin-top: 3px;">' . $personInCharge . '</div>
                    </td>
                    <td style="border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                        <strong>8. DATE PREPARED:</strong>
                        <div style="font-size: 9px; font-weight: bold; margin-top: 3px;">' . $datePrepared . '</div>
                    </td>
                </tr>
            </table>';

            // Main Data Table (Columns 9 to 20)
            $html .= '<table class="print-table" style="border: 2px solid #000; text-align: center;">
                <thead>
                    <tr style="font-weight: bold;">
                        <th rowspan="2" style="border: 1px solid #000; width: 17%;">9. RECORDS SERIES TITLE AND DESCRIPTION</th>
                        <th rowspan="2" style="border: 1px solid #000; width: 8%;">10. PERIOD COVERED / INCLUSIVE DATES</th>
                        <th rowspan="2" style="border: 1px solid #000; width: 5%;">11. VOLUME</th>
                        <th rowspan="2" style="border: 1px solid #000; width: 6.5%;">12. RECORDS MEDIUM</th>
                        <th rowspan="2" style="border: 1px solid #000; width: 6.5%;">13. RESTRICTION/S</th>
                        <th rowspan="2" style="border: 1px solid #000; width: 7.5%;">14. LOCATION OF RECORDS</th>
                        <th rowspan="2" style="border: 1px solid #000; width: 6.5%;">15. FREQUENCY OF USE</th>
                        <th rowspan="2" style="border: 1px solid #000; width: 5.5%;">16. DUPLICATION</th>
                        <th rowspan="2" style="border: 1px solid #000; width: 5%;">17. TIME VALUE (T/P)</th>
                        <th rowspan="2" style="border: 1px solid #000; width: 6.5%;">18. UTILITY VALUE Adm/F/L/Arc</th>
                        <th colspan="3" style="border: 1px solid #000; width: 11%;">19. RETENTION PERIOD</th>
                        <th rowspan="2" style="border: 1px solid #000; width: 15%;">20. DISPOSITION PROVISION</th>
                    </tr>
                    <tr style="font-weight: bold;">
                        <th style="border: 1px solid #000; width: 3.6%; padding: 3px 2px;">Active</th>
                        <th style="border: 1px solid #000; width: 3.6%; padding: 3px 2px;">Storage</th>
                        <th style="border: 1px solid #000; width: 3.8%; padding: 3px 2px;">Total</th>
                    </tr>
                </thead>
                <tbody>';

            foreach ($pageItems as $item) {
                if ($item['type'] === 'root_standalone') {
                    $root = $item['root'];
                    $isPerm = (bool)($root->is_permanent ?? false) || strtolower(trim($root->total_period ?? '')) === 'permanent';

                    $html .= '<tr style="vertical-align: top;">
                        <td style="' . $cellBorder . ' text-align: left; padding: 3px 6px; font-weight: bold; font-size: 8.5px;">
                            ' . strtoupper(self::cleanVal($root->series_title)) . '
                        </td>
                        <td style="' . $cellBorder . ' padding: 3px 2px; text-align: center;">' . self::cleanVal($root->compiled_period, true) . '</td>
                        <td style="' . $cellBorder . ' padding: 3px 2px; text-align: center;">' . self::cleanVal($root->compiled_volume, true) . '</td>
                        <td style="' . $cellBorder . ' padding: 3px 2px; text-align: center;">' . self::cleanVal($root->compiled_medium, true) . '</td>
                        <td style="' . $cellBorder . ' padding: 3px 2px; text-align: center;">' . self::cleanVal($root->compiled_restriction, true) . '</td>
                        <td style="' . $cellBorder . ' padding: 3px 2px; text-align: center;">' . self::cleanVal($root->compiled_location, true) . '</td>
                        <td style="' . $cellBorder . ' padding: 3px 2px; text-align: center;">' . self::cleanVal($root->compiled_freq, true) . '</td>
                        <td style="' . $cellBorder . ' padding: 3px 2px; text-align: center;">' . self::cleanVal($root->compiled_duplication, true) . '</td>
                        <td style="' . $cellBorder . ' padding: 3px 2px; text-align: center; font-weight: bold;">' . self::cleanVal($root->compiled_time ?: 'T') . '</td>
                        <td style="' . $cellBorder . ' padding: 3px 2px; text-align: center; font-weight: bold;">' . self::cleanVal($root->compiled_util ?: 'A') . '</td>';

                    if ($isPerm) {
                        $html .= '<td colspan="3" style="' . $cellBorder . ' padding: 3px 2px; text-align: center; font-weight: bold;">PERMANENT</td>';
                    } else {
                        $html .= '<td style="' . $cellBorder . ' padding: 3px 2px; text-align: center;">' . self::cleanVal($root->active_period, true) . '</td>
                            <td style="' . $cellBorder . ' padding: 3px 2px; text-align: center;">' . self::cleanVal($root->storage_period, true) . '</td>
                            <td style="' . $cellBorder . ' padding: 3px 2px; text-align: center; font-weight: bold;">' . self::cleanVal($root->total_period, true) . '</td>';
                    }

                    $html .= '<td style="' . $cellBorder . ' padding: 3px 4px; text-align: left;">' . self::cleanVal($root->remarks ?? '') . '</td>
                    </tr>';
                } elseif ($item['type'] === 'root_header') {
                    $root = $item['root'];
                    $html .= '<tr style="vertical-align: top;">
                        <td style="' . $cellBorder . ' text-align: left; padding: 4px 6px 2px 6px; font-weight: bold; font-size: 8.5px;">
                            ' . strtoupper(self::cleanVal($root->series_title)) . '
                        </td>
                        <td style="' . $cellBorder . '"></td>
                        <td style="' . $cellBorder . '"></td>
                        <td style="' . $cellBorder . '"></td>
                        <td style="' . $cellBorder . '"></td>
                        <td style="' . $cellBorder . '"></td>
                        <td style="' . $cellBorder . '"></td>
                        <td style="' . $cellBorder . '"></td>
                        <td style="' . $cellBorder . '"></td>
                        <td style="' . $cellBorder . '"></td>
                        <td style="' . $cellBorder . '"></td>
                        <td style="' . $cellBorder . '"></td>
                        <td style="' . $cellBorder . '"></td>
                        <td style="' . $cellBorder . '"></td>
                    </tr>';
                } elseif ($item['type'] === 'sub_series') {
                    $sub = $item['sub'];
                    $root = $item['root'] ?? null;
                    $isPerm = (bool)($sub->is_permanent ?? false) || strtolower(trim($sub->total_period ?? '')) === 'permanent';
                    $indent = $item['indent'] ?? 16;

                    $html .= '<tr style="vertical-align: top;">
                        <td style="' . $cellBorder . ' text-align: left; padding: 2px 6px 3px ' . $indent . 'px; font-weight: normal; font-size: 8.5px;">
                            ' . self::cleanVal($sub->series_title) . '
                        </td>
                        <td style="' . $cellBorder . ' padding: 2px; text-align: center;">' . self::cleanVal($sub->compiled_period, true) . '</td>
                        <td style="' . $cellBorder . ' padding: 2px; text-align: center;">' . self::cleanVal($sub->compiled_volume, true) . '</td>
                        <td style="' . $cellBorder . ' padding: 2px; text-align: center;">' . self::cleanVal($sub->compiled_medium, true) . '</td>
                        <td style="' . $cellBorder . ' padding: 2px; text-align: center;">' . self::cleanVal($sub->compiled_restriction, true) . '</td>
                        <td style="' . $cellBorder . ' padding: 2px; text-align: center;">' . self::cleanVal($sub->compiled_location, true) . '</td>
                        <td style="' . $cellBorder . ' padding: 2px; text-align: center;">' . self::cleanVal($sub->compiled_freq, true) . '</td>
                        <td style="' . $cellBorder . ' padding: 2px; text-align: center;">' . self::cleanVal($sub->compiled_duplication, true) . '</td>
                        <td style="' . $cellBorder . ' padding: 2px; text-align: center; font-weight: bold;">' . self::cleanVal($sub->compiled_time ?: 'T') . '</td>
                        <td style="' . $cellBorder . ' padding: 2px; text-align: center; font-weight: bold;">' . self::cleanVal($sub->compiled_util ?: 'A') . '</td>';

                    if ($isPerm) {
                        $html .= '<td colspan="3" style="' . $cellBorder . ' padding: 2px; text-align: center; font-weight: bold;">PERMANENT</td>';
                    } else {
                        $html .= '<td style="' . $cellBorder . ' padding: 2px; text-align: center;">' . self::cleanVal($sub->active_period, true) . '</td>
                            <td style="' . $cellBorder . ' padding: 2px; text-align: center;">' . self::cleanVal($sub->storage_period, true) . '</td>
                            <td style="' . $cellBorder . ' padding: 2px; text-align: center; font-weight: bold;">' . self::cleanVal($sub->total_period, true) . '</td>';
                    }

                    $rem = self::cleanVal(($sub->remarks ?? '') ?: ($root ? ($root->remarks ?? '') : ''));
                    $html .= '<td style="' . $cellBorder . ' padding: 2px 4px; text-align: left;">' . $rem . '</td>
                    </tr>';
                } elseif ($item['type'] === 'record') {
                    $rec = $item['rec'];
                    $indent = $item['indent'] ?? 20;

                    $html .= '<tr style="vertical-align: top;">
                        <td style="' . $cellBorder . ' text-align: left; padding: 2px 6px 2px ' . $indent . 'px; font-size: 8px;">
                            ' . self::cleanVal($rec->description) . '
                        </td>
                        <td style="' . $cellBorder . ' padding: 2px; text-align: center;">' . self::cleanVal($rec->date_covered, true) . '</td>
                        <td style="' . $cellBorder . ' padding: 2px; text-align: center;">' . self::cleanVal($rec->volume, true) . '</td>
                        <td style="' . $cellBorder . ' padding: 2px; text-align: center;">' . self::cleanVal($rec->medium, true) . '</td>
                        <td style="' . $cellBorder . ' padding: 2px; text-align: center;">' . self::cleanVal($rec->restriction, true) . '</td>
                        <td style="' . $cellBorder . ' padding: 2px; text-align: center;">' . self::cleanVal($rec->location, true) . '</td>
                        <td style="' . $cellBorder . ' padding: 2px; text-align: center;">' . self::cleanVal($rec->frequence_use, true) . '</td>
                        <td style="' . $cellBorder . ' padding: 2px; text-align: center;">' . self::cleanVal($rec->duplication, true) . '</td>
                        <td style="' . $cellBorder . ' padding: 2px; text-align: center;">' . self::cleanVal($rec->time_value, true) . '</td>
                        <td style="' . $cellBorder . ' padding: 2px; text-align: center;">' . self::cleanVal($rec->utility, true) . '</td>
                        <td colspan="3" style="' . $cellBorder . ' padding: 2px;"></td>
                        <td style="' . $cellBorder . ' padding: 2px;"></td>
                    </tr>';
                }
            }

            // Computed filler row extending borders to bottom
            $html .= '<tr style="height: ' . $computedFiller . 'px;">
                <td style="' . $cellBorder . '">&nbsp;</td>
                <td style="' . $cellBorder . '">&nbsp;</td>
                <td style="' . $cellBorder . '">&nbsp;</td>
                <td style="' . $cellBorder . '">&nbsp;</td>
                <td style="' . $cellBorder . '">&nbsp;</td>
                <td style="' . $cellBorder . '">&nbsp;</td>
                <td style="' . $cellBorder . '">&nbsp;</td>
                <td style="' . $cellBorder . '">&nbsp;</td>
                <td style="' . $cellBorder . '">&nbsp;</td>
                <td style="' . $cellBorder . '">&nbsp;</td>
                <td style="' . $cellBorder . '">&nbsp;</td>
                <td style="' . $cellBorder . '">&nbsp;</td>
                <td style="' . $cellBorder . '">&nbsp;</td>
                <td style="' . $cellBorder . '">&nbsp;</td>
            </tr>
            </tbody>
            </table>';

            // Legend and Signatures (Only on the last page)
            if ($isLastPage) {
                // Legend Section
                $html .= '<table style="width: 100%; border: none; font-size: 8px; margin-top: 6px; line-height: 1.35;">
                    <tr>
                        <td colspan="2" style="font-weight: bold; padding: 1px 0; border: none;">LEGEND:</td>
                    </tr>
                    <tr>
                        <td style="font-weight: normal; padding: 1px 0 1px 50px; width: 140px; border: none; white-space: nowrap;">TIME VALUE:</td>
                        <td style="border: none; padding: 1px 0;"><strong>T</strong> - Temporary &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; <strong>P</strong> - Permanent</td>
                    </tr>
                    <tr>
                        <td style="font-weight: normal; padding: 1px 0 1px 50px; width: 140px; border: none; white-space: nowrap;">UTILITY VALUE:</td>
                        <td style="border: none; padding: 1px 0;"><strong>Adm</strong> - Administrative &nbsp;&nbsp;&nbsp;&nbsp; <strong>F</strong> - Fiscal &nbsp;&nbsp;&nbsp;&nbsp; <strong>L</strong> - Legal &nbsp;&nbsp;&nbsp;&nbsp; <strong>Arc</strong> - Archival</td>
                    </tr>
                </table>';

                // Signatures Section with 25px space
                $html .= '<table style="width: 100%; border: none; margin-top: 15px; font-size: 8.5px;">
                    <tr>
                        <td style="width: 33.33%; text-align: center; border: none; padding: 0 15px; vertical-align: top;">
                            <div style="font-weight: bold; text-align: left; margin-bottom: 25px;">PREPARED BY:</div>
                            <div style="border-bottom: 1.5px solid #000; width: 90%; margin: 0 auto; font-weight: bold; font-size: 9px; min-height: 14px;">
                                ' . $preparedBy . '
                            </div>
                            <div style="font-size: 8px; margin-top: 3px;">
                                ' . ($preparedPos ?: 'Name and Position') . '
                            </div>
                        </td>
                        <td style="width: 33.33%; text-align: center; border: none; padding: 0 15px; vertical-align: top;">
                            <div style="font-weight: bold; text-align: left; margin-bottom: 25px;">ASSISTED BY:</div>
                            <div style="border-bottom: 1.5px solid #000; width: 90%; margin: 0 auto; font-weight: bold; font-size: 9px; min-height: 14px;">
                                ' . $assistedBy . '
                            </div>
                            <div style="font-size: 8px; margin-top: 3px;">
                                ' . ($assistedPos ?: 'NAP Records Management Analyst') . '
                            </div>
                        </td>
                        <td style="width: 33.33%; text-align: center; border: none; padding: 0 15px; vertical-align: top;">
                            <div style="font-weight: bold; text-align: left; margin-bottom: 25px;">APPROVED BY:</div>
                            <div style="border-bottom: 1.5px solid #000; width: 90%; margin: 0 auto; font-weight: bold; font-size: 9px; min-height: 14px;">
                                ' . $approvedBy . '
                            </div>
                            <div style="font-size: 8px; margin-top: 3px;">
                                ' . ($approvedPos ?: 'Chief of the Division/Department') . '
                            </div>
                        </td>
                    </tr>
                </table>';
            }

            $html .= '</div>';
        }

        $html .= '</body></html>';
        return $html;
    }

    /**
     * Generates NAP Form 2 HTML layout matching print preview exactly.
     */
    public static function buildNap2Html(object $cluster, array $items, array $sig = []): string
    {
        $agency = self::cleanVal($sig['agencyName'] ?? $cluster->office_name ?? $cluster->office ?? 'Camarines Sur Polytechnic Colleges');
        $address = self::cleanVal($sig['agencyAddress'] ?? 'San Miguel, Nabua, Camarines Sur');
        $datePrepared = self::cleanVal($sig['datePrepared'] ?? date('F d, Y', strtotime($cluster->created_at ?? 'now')));

        $preparedBy = self::cleanVal($sig['preparedBy'] ?? $cluster->submitter_name ?? '');
        $preparedPos = self::cleanVal($sig['preparedPosition'] ?? '');
        $assistedBy = self::cleanVal($sig['assistedBy'] ?? '');
        $assistedPos = self::cleanVal($sig['assistedPosition'] ?? '');
        $recommendingBy = self::cleanVal($sig['recommendingBy'] ?? '');
        $recommendingPos = self::cleanVal($sig['recommendingPosition'] ?? '');
        $approvedBy = self::cleanVal($sig['approvedBy'] ?? '');
        $approvedPos = self::cleanVal($sig['approvedPosition'] ?? '');
        $committeeChairman = self::cleanVal($sig['committeeChairmanName'] ?? '');
        $execDirector = self::cleanVal($sig['executiveDirectorName'] ?? '');

        $scheduleNo = self::cleanVal($cluster->cluster_id ?? '');

        $totalPrintItems = count($items);
        $rowsPerPage = 16;
        $dataPages = ($totalPrintItems === 0) ? [ [] ] : array_chunk($items, $rowsPerPage);
        $totalPages = count($dataPages) + 1; // Dedicated final Signatures & NAP Approval Sheet

        $html = '<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page {
        size: 8.5in 13in;
        margin: 10mm 10mm 10mm 10mm;
    }
    body {
        font-family: Arial, Helvetica, sans-serif;
        font-size: 9px;
        color: #000000;
        margin: 0;
        padding: 0;
    }
    .page-container {
        width: 100%;
        page-break-after: always;
    }
    .page-container:last-child {
        page-break-after: avoid;
    }
    table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
    }
    .print-table th {
        border: 1px solid #000;
        padding: 4px;
        text-align: center;
        font-weight: bold;
        font-size: 9px;
        background: #ffffff;
    }
    .print-table td {
        padding: 4px 5px;
        font-size: 8.5px;
        vertical-align: top;
    }
</style>
</head>
<body>';

        // DATA PAGES
        foreach ($dataPages as $pageIndex => $pageItems) {
            $pageNumber = $pageIndex + 1;
            $fillerHeight = empty($pageItems) ? 880 : max(40, 880 - (count($pageItems) * 26));

            $html .= '<div class="page-container">';

            // Top Form Identifier
            $html .= '<div style="font-size: 10px; font-weight: normal; line-height: 1.25; margin-bottom: 6px;">
                NAP Form 2<br>2008
            </div>';

            // Header Box (Outer Border)
            $html .= '<table style="border: 2px solid #000; margin-bottom: 0;">
                <tr>
                    <td style="width: 50%; border-right: 2px solid #000; padding: 10px 12px; text-align: center; vertical-align: middle;">
                        <div style="font-size: 11px; font-weight: bold; letter-spacing: 0.3px;">NATIONAL ARCHIVES OF THE PHILIPPINES</div>
                        <div style="font-size: 9.5px; font-style: italic; margin-top: 2px; margin-bottom: 8px;">Pambansang Sinupan ng Pilipinas</div>
                        <div style="font-size: 12px; font-weight: bold; letter-spacing: 0.5px;">RECORDS DISPOSITION SCHEDULE</div>
                    </td>
                    <td style="width: 50%; padding: 0; vertical-align: top;">
                        <div style="padding: 7px 10px; border-bottom: 1px solid #000; font-size: 9px; line-height: 1.4;">
                            <strong>1. AGENCY NAME:</strong>
                            <div style="font-size: 10px; font-weight: bold; margin-top: 2px;">' . $agency . '</div>
                        </div>
                        <div style="padding: 7px 10px; font-size: 9px; line-height: 1.4;">
                            <strong>2. ADDRESS:</strong>
                            <div style="font-size: 10px; font-weight: bold; margin-top: 2px;">' . $address . '</div>
                        </div>
                    </td>
                </tr>
                <tr>
                    <td style="width: 50%; border-right: 2px solid #000; border-top: 2px solid #000; padding: 6px 10px; font-size: 9px;">
                        <strong>3. SCHEDULE NO.:</strong> <span style="font-weight: bold; font-size: 10px; margin-left: 4px;">' . $scheduleNo . '</span>
                    </td>
                    <td style="width: 50%; border-top: 2px solid #000; padding: 6px 10px; font-size: 9px;">
                        <strong>4. DATE PREPARED:</strong> <span style="font-weight: bold; font-size: 10px; margin-left: 4px;">' . $datePrepared . '</span>
                    </td>
                </tr>
            </table>';

            // Data Table (Boxes 5 - 8)
            $html .= '<table class="print-table" style="border: 2px solid #000; border-top: none; font-size: 9px;">
                <thead>
                    <tr>
                        <th rowspan="2" style="width: 11%; border-top: 2px solid #000;">5. ITEM NO.:</th>
                        <th rowspan="2" style="width: 47%; border-top: 2px solid #000;">6. RECORD SERIES TITLE AND DESCRIPTION</th>
                        <th colspan="3" style="width: 24%; border-top: 2px solid #000;">7. RETENTION PERIOD</th>
                        <th rowspan="2" style="width: 18%; border-top: 2px solid #000;">8. REMARKS</th>
                    </tr>
                    <tr>
                        <th style="width: 8%;">Active</th>
                        <th style="width: 8%;">Storage</th>
                        <th style="width: 8%;">Total</th>
                    </tr>
                </thead>
                <tbody>';

            foreach ($pageItems as $item) {
                $isPerm = (bool)($item->effective_is_permanent ?? false) ||
                          (strtolower(trim($item->effective_total ?? '')) === 'permanent') ||
                          (strtolower(trim($item->effective_active ?? '')) === 'permanent' && strtolower(trim($item->effective_storage ?? '')) === 'permanent');

                $depth = $item->depth ?? 0;
                $indent = ($depth * 14) + 6;
                $prefix = ($depth > 0) ? '└ ' : '';
                $itemNo = self::cleanVal($item->display_item_no ?? $item->item_number ?? '');
                $title = self::cleanVal($item->series_title ?? '');
                $remarks = self::cleanVal($item->remarks ?? '');

                $html .= '<tr>
                    <td style="border-left: 1px solid #000; border-right: 1px solid #000; text-align: center; font-weight: bold;">
                        ' . $itemNo . '
                    </td>
                    <td style="border-left: 1px solid #000; border-right: 1px solid #000; padding-left: ' . $indent . 'px;">
                        <span style="' . ($depth === 0 ? 'font-weight: bold;' : 'font-weight: normal;') . '">
                            ' . $prefix . $title . '
                        </span>
                    </td>';

                if ($isPerm) {
                    $html .= '<td colspan="3" style="border-left: 1px solid #000; border-right: 1px solid #000; text-align: center; font-weight: bold;">PERMANENT</td>';
                } else {
                    $html .= '<td style="border-left: 1px solid #000; border-right: 1px solid #000; text-align: center;">' . self::cleanVal($item->effective_active ?? '', true) . '</td>
                        <td style="border-left: 1px solid #000; border-right: 1px solid #000; text-align: center;">' . self::cleanVal($item->effective_storage ?? '', true) . '</td>
                        <td style="border-left: 1px solid #000; border-right: 1px solid #000; text-align: center; font-weight: bold;">' . self::cleanVal($item->effective_total ?? '', true) . '</td>';
                }

                $html .= '<td style="border-left: 1px solid #000; border-right: 1px solid #000; font-size: 8.5px;">' . $remarks . '</td>
                </tr>';
            }

            // Filler row
            $html .= '<tr>
                <td style="border-left: 1px solid #000; border-right: 1px solid #000; height: ' . $fillerHeight . 'px;"></td>
                <td style="border-left: 1px solid #000; border-right: 1px solid #000;"></td>
                <td style="border-left: 1px solid #000; border-right: 1px solid #000;"></td>
                <td style="border-left: 1px solid #000; border-right: 1px solid #000;"></td>
                <td style="border-left: 1px solid #000; border-right: 1px solid #000;"></td>
                <td style="border-left: 1px solid #000; border-right: 1px solid #000;"></td>
            </tr>
            </tbody>
            </table>';

            // Statutory Notice
            $html .= '<div style="border-top: 2px solid #000; padding-top: 6px; margin-top: 0; font-size: 8px; line-height: 1.35; text-align: justify;">
                <strong>IMPORTANT:</strong> Pursuant to Section 18, Article III, RA 9470 s. 2007, "No government department, bureau, agency and instrumentality shall dispose of, destroy or authorize the disposal or destruction of any public records, which are in the custody or under its control except with the prior written authority of the executive director."
            </div>';

            // Page Number
            $html .= '<div style="text-align: right; font-size: 9px; margin-top: 8px;">
                Page ' . $pageNumber . ' of ' . $totalPages . ' Pages
            </div>';

            $html .= '</div>';
        }

        // SIGNATURES & NAP APPROVAL PAGE (FINAL PAGE)
        $html .= '<div class="page-container">';
        $html .= '<div style="font-size: 10px; font-weight: normal; line-height: 1.25; margin-bottom: 8px;">
            NAP Form 2<br>2008
        </div>';

        // Signatures Table (Box 9, 11, 10, 12)
        $html .= '<table style="border: 2px solid #000; font-size: 9px;">
            <tr>
                <td style="width: 50%; border: 1px solid #000; padding: 12px 16px; vertical-align: top; height: 140px;">
                    <div style="font-weight: bold; font-size: 9.5px; margin-bottom: 25px;">9. Prepared by:</div>
                    <div style="text-align: center; width: 85%; margin: 0 auto;">
                        <div style="border-bottom: 1px solid #000; font-weight: bold; font-size: 10px; min-height: 16px; padding-bottom: 2px;">
                            ' . $preparedBy . '
                        </div>
                        <div style="font-size: 8.5px; margin-top: 2px; margin-bottom: 15px;">Name</div>
                        <div style="border-bottom: 1px solid #000; font-weight: bold; font-size: 10px; min-height: 16px; padding-bottom: 2px;">
                            ' . $preparedPos . '
                        </div>
                        <div style="font-size: 8.5px; margin-top: 2px;">Position</div>
                    </div>
                </td>
                <td style="width: 50%; border: 1px solid #000; padding: 12px 16px; vertical-align: top; height: 140px;">
                    <div style="font-weight: bold; font-size: 9.5px; margin-bottom: 25px;">11. Recommending Approval:</div>
                    <div style="text-align: center; width: 85%; margin: 0 auto;">
                        <div style="border-bottom: 1px solid #000; font-weight: bold; font-size: 10px; min-height: 16px; padding-bottom: 2px;">
                            ' . $recommendingBy . '
                        </div>
                        <div style="font-size: 8.5px; margin-top: 2px; margin-bottom: 15px;">Name</div>
                        <div style="border-bottom: 1px solid #000; font-weight: bold; font-size: 10px; min-height: 16px; padding-bottom: 2px;">
                            ' . $recommendingPos . '
                        </div>
                        <div style="font-size: 8.5px; margin-top: 2px;">Position</div>
                    </div>
                </td>
            </tr>
            <tr>
                <td style="width: 50%; border: 1px solid #000; padding: 12px 16px; vertical-align: top; height: 140px;">
                    <div style="font-weight: bold; font-size: 9.5px; margin-bottom: 25px;">10. Assisted by:</div>
                    <div style="text-align: center; width: 85%; margin: 0 auto;">
                        <div style="border-bottom: 1px solid #000; font-weight: bold; font-size: 10px; min-height: 16px; padding-bottom: 2px;">
                            ' . $assistedBy . '
                        </div>
                        <div style="font-size: 8.5px; margin-top: 2px; margin-bottom: 15px;">Name</div>
                        <div style="border-bottom: 1px solid #000; font-weight: bold; font-size: 10px; min-height: 16px; padding-bottom: 2px;">
                            ' . $assistedPos . '
                        </div>
                        <div style="font-size: 8.5px; margin-top: 2px;">Position</div>
                    </div>
                </td>
                <td style="width: 50%; border: 1px solid #000; padding: 12px 16px; vertical-align: top; height: 140px;">
                    <div style="font-weight: bold; font-size: 9.5px; margin-bottom: 25px;">12. Approved</div>
                    <div style="text-align: center; width: 85%; margin: 0 auto;">
                        <div style="border-bottom: 1px solid #000; font-weight: bold; font-size: 10px; min-height: 16px; padding-bottom: 2px;">
                            ' . $approvedBy . '
                        </div>
                        <div style="font-size: 8.5px; margin-top: 2px; margin-bottom: 15px;">Name</div>
                        <div style="border-bottom: 1px solid #000; font-weight: bold; font-size: 10px; min-height: 16px; padding-bottom: 2px;">
                            ' . $approvedPos . '
                        </div>
                        <div style="font-size: 8.5px; margin-top: 2px;">Position</div>
                    </div>
                </td>
            </tr>
        </table>';

        // NAP Accomplishment Section
        $html .= '<div style="border: 2px solid #000; margin-top: 14px; font-size: 9.5px;">
            <div style="border-bottom: 2px solid #000; padding: 6px; text-align: center; font-weight: bold; font-size: 10px; letter-spacing: 0.5px; text-transform: uppercase;">
                TO BE ACCOMPLISHED BY THE NATIONAL ARCHIVES OF THE PHILIPPINES
            </div>
            <div style="padding: 16px 20px;">
                <div style="margin-bottom: 12px; font-size: 9.5px;">This Records Disposition Schedule</div>
                <div style="margin-bottom: 8px; padding-left: 20px;">
                    <span style="display: inline-block; width: 13px; height: 13px; border: 1.5px solid #000; vertical-align: middle; margin-right: 8px;"></span>
                    <span>is being returned for improvement / correction</span>
                </div>
                <div style="margin-bottom: 25px; padding-left: 20px;">
                    <span style="display: inline-block; width: 13px; height: 13px; border: 1.5px solid #000; vertical-align: middle; margin-right: 8px;"></span>
                    <span>is being recommended for approval</span>
                </div>

                <table style="width: 100%; border: none; margin-top: 40px;">
                    <tr>
                        <td style="width: 45%; text-align: center; border: none; vertical-align: top;">
                            <div style="border-bottom: 1px solid #000; width: 100%; min-height: 20px; margin-bottom: 4px; font-weight: bold; font-size: 10px;">
                                ' . $committeeChairman . '
                            </div>
                            <div style="font-weight: bold; font-size: 9.5px;">Chairman</div>
                            <div style="font-size: 8.5px; margin-top: 1px;">Records Management Evaluation Committee</div>
                            <div style="margin-top: 16px; text-align: left; font-size: 9px;">
                                Date: <span style="display: inline-block; border-bottom: 1px solid #000; width: 130px;"></span>
                            </div>
                        </td>
                        <td style="width: 10%; border: none;"></td>
                        <td style="width: 45%; text-align: center; border: none; vertical-align: top;">
                            <div style="font-weight: bold; font-size: 9.5px; margin-bottom: 6px;">APPROVED:</div>
                            <div style="border-bottom: 1px solid #000; width: 100%; min-height: 20px; margin-bottom: 4px; font-weight: bold; font-size: 10px;">
                                ' . $execDirector . '
                            </div>
                            <div style="font-weight: bold; font-size: 9.5px;">Executive Director</div>
                            <div style="margin-top: 16px; text-align: left; font-size: 9px;">
                                Date: <span style="display: inline-block; border-bottom: 1px solid #000; width: 130px;"></span>
                            </div>
                        </td>
                    </tr>
                </table>
            </div>
        </div>';

        // Page Number
        $html .= '<div style="text-align: right; font-size: 9px; margin-top: 8px;">
            Page ' . $totalPages . ' of ' . $totalPages . ' Pages
        </div>';

        $html .= '</div>';
        $html .= '</body></html>';
        return $html;
    }

    /**
     * Generates NAP Form 3 HTML layout matching print preview exactly.
     */
    public static function buildNap3Html(object $cluster, array $items, array $sig = []): string
    {
        $agency = self::cleanVal($sig['agencyName'] ?? $cluster->office_name ?? $cluster->office ?? 'Camarines Sur Polytechnic Colleges');
        $address = self::cleanVal($sig['agencyAddress'] ?? 'San Miguel, Nabua, Camarines Sur');
        $tel = self::cleanVal($sig['telephoneNumber'] ?? '(054) 288-1534 loc. 113');
        $datePrepared = self::cleanVal($sig['datePrepared'] ?? date('F d, Y', strtotime($cluster->created_at ?? 'now')));

        $location = self::cleanVal($sig['effectiveLocation'] ?? 'Records Management Unit Storage');
        $volume = self::cleanVal($sig['effectiveVolume'] ?? '0.5 cu. m.');
        $preparedBy = self::cleanVal($sig['preparedBy'] ?? $cluster->submitter_name ?? '');
        $preparedPos = self::cleanVal($sig['preparedPosition'] ?? '');
        $approvedBy = self::cleanVal($sig['approvedBy'] ?? '');

        $includeDesc = (bool)($sig['includeDescription'] ?? false);

        if (!empty($items) && isset($items[0]->series_title) && (isset($items[0]->sub_series) || isset($items[0]->direct_records) || isset($items[0]->has_children))) {
            $flattenedItems = self::flattenHierarchy($items, $includeDesc);
        } elseif (!empty($items) && isset($items[0]['type'])) {
            $flattenedItems = $items;
        } else {
            $flattenedItems = [];
            foreach ($items as $it) {
                $flattenedItems[] = [
                    'type' => 'root_standalone',
                    'root' => (object)[
                        'item_number'     => $it->item_number ?? '',
                        'series_title'    => $it->series_title ?? $it->doc_name ?? 'Untitled Series',
                        'compiled_period' => $it->period_covered ?? '',
                        'total_period'    => $it->total_period ?? '',
                        'remarks'         => $it->remarks ?? '',
                    ]
                ];
            }
        }

        $totalItems = count($flattenedItems);
        $pages = [];
        $maxRowsFinalPage = 10;
        $maxRowsOtherPages = 15;

        if ($totalItems === 0) {
            $pages = [ [] ];
        } elseif ($totalItems <= $maxRowsFinalPage) {
            $pages = [ $flattenedItems ];
        } else {
            $remaining = $flattenedItems;
            while (!empty($remaining)) {
                if (count($remaining) <= $maxRowsFinalPage) {
                    $pages[] = $remaining;
                    break;
                }
                $chunkSize = min($maxRowsOtherPages, max(1, count($remaining) - 1));
                $chunk = array_splice($remaining, 0, $chunkSize);
                $pages[] = $chunk;
            }
        }
        $totalPages = count($pages);

        $cellBorder = "border-left: 1px solid #000; border-right: 1px solid #000; border-top: none; border-bottom: none;";

        $html = '<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page {
        size: 8.5in 13in;
        margin: 10mm 10mm 10mm 10mm;
    }
    body {
        font-family: Arial, Helvetica, sans-serif;
        font-size: 8.5px;
        color: #000000;
        margin: 0;
        padding: 0;
    }
    .page-container {
        width: 100%;
        page-break-after: always;
    }
    .page-container:last-child {
        page-break-after: avoid;
    }
    table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
    }
    .print-table th {
        border: 1px solid #000;
        padding: 6px 5px;
        text-align: center;
        font-weight: bold;
        font-size: 8.5px;
        background: #ffffff;
    }
    .print-table td {
        padding: 4px 6px;
        font-size: 8.5px;
        vertical-align: top;
    }
</style>
</head>
<body>';

        foreach ($pages as $pageIndex => $pageItems) {
            $isLastPage = ($pageIndex + 1) === $totalPages;
            $computedFiller = $isLastPage
                ? max(40, 750 - (count($pageItems) * 22))
                : max(40, 900 - (count($pageItems) * 22));

            $html .= '<div class="page-container">';

            // Top Form ID Line
            $html .= '<table style="width: 100%; border: none; margin-bottom: 4px; font-size: 8.5px;">
                <tr>
                    <td style="border: none; padding: 0;">
                        <div style="font-weight: bold;">NAP Form No. 3</div>
                        <div style="font-style: italic;">Revised 2012</div>
                    </td>
                    <td style="border: none; padding: 0; text-align: right; font-style: italic;">
                        Accomplish in 3 copies
                    </td>
                </tr>
            </table>';

            // Header Box
            $html .= '<table style="border: 2px solid #000; font-size: 9px;">
                <tr>
                    <td rowspan="2" style="width: 50%; border: 1px solid #000; text-align: center; padding: 6px; vertical-align: middle;">
                        <div style="border: 1.5px solid #000; padding: 8px 6px; margin: 2px;">
                            <div style="font-weight: bold; font-size: 10px; text-transform: uppercase;">NATIONAL ARCHIVES OF THE PHILIPPINES</div>
                            <div style="font-size: 8.5px; font-style: italic; margin: 2px 0 6px 0;">Pambansang Sinupan ng Pilipinas</div>
                            <div style="font-weight: 800; font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.5px;">
                                REQUEST FOR AUTHORITY TO DISPOSE<br>OF RECORDS
                            </div>
                        </div>
                    </td>
                    <td style="width: 50%; border: 1px solid #000; padding: 6px 8px; vertical-align: top;">
                        <div><strong>AGENCY NAME:</strong> <span style="text-transform: uppercase;">' . $agency . '</span></div>
                    </td>
                </tr>
                <tr>
                    <td style="border: 1px solid #000; padding: 6px 8px; vertical-align: top;">
                        <div><strong>ADDRESS:</strong> ' . $address . '</div>
                    </td>
                </tr>
                <tr>
                    <td style="border: 1px solid #000; padding: 6px 8px;">
                        <strong>DATE:</strong> ' . $datePrepared . '
                    </td>
                    <td style="border: 1px solid #000; padding: 6px 8px;">
                        <strong>TELEPHONE NUMBER:</strong> ' . $tel . '
                    </td>
                </tr>
            </table>';

            // Official Table (Revised 2012)
            $html .= '<table class="print-table" style="border: 2px solid #000; border-top: none;">
                <thead>
                    <tr>
                        <th style="width: 12%;">GRDS/ RDS ITEM NO.</th>
                        <th style="width: 48%;">RECORD SERIES TITLE AND DESCRIPTION</th>
                        <th style="width: 20%;">PERIOD COVERED</th>
                        <th style="width: 20%;">RETENTION PERIOD AND PROVISION/S COMPLIED (If Any)</th>
                    </tr>
                </thead>
                <tbody>';

            foreach ($pageItems as $item) {
                if ($item['type'] === 'root_standalone') {
                    $root = $item['root'];
                    $itemNo = self::cleanVal($root->item_number ?? '');
                    $title = strtoupper(self::cleanVal($root->series_title ?? ''));
                    $period = self::cleanVal($root->compiled_period ?? '', true);
                    $ret = self::cleanVal(trim(($root->total_period ?? '') . ($root->remarks ? ' / ' . $root->remarks : '')), true);

                    $html .= '<tr style="vertical-align: top;">
                        <td style="' . $cellBorder . ' text-align: center;">' . $itemNo . '</td>
                        <td style="' . $cellBorder . ' text-align: left; font-weight: bold;">' . $title . '</td>
                        <td style="' . $cellBorder . ' text-align: center;">' . $period . '</td>
                        <td style="' . $cellBorder . ' text-align: center;">' . $ret . '</td>
                    </tr>';
                } elseif ($item['type'] === 'root_header') {
                    $root = $item['root'];
                    $itemNo = self::cleanVal($root->item_number ?? '');
                    $title = strtoupper(self::cleanVal($root->series_title ?? ''));

                    $html .= '<tr style="vertical-align: top;">
                        <td style="' . $cellBorder . ' text-align: center;">' . $itemNo . '</td>
                        <td style="' . $cellBorder . ' text-align: left; font-weight: bold;">' . $title . '</td>
                        <td style="' . $cellBorder . '"></td>
                        <td style="' . $cellBorder . '"></td>
                    </tr>';
                } elseif ($item['type'] === 'sub_series') {
                    $sub = $item['sub'];
                    $root = $item['root'] ?? null;
                    $title = self::cleanVal($sub->series_title ?? '');
                    $period = self::cleanVal($sub->compiled_period ?? '', true);
                    $remarks = $sub->remarks ?: ($root ? $root->remarks : '');
                    $ret = self::cleanVal(trim(($sub->total_period ?? '') . ($remarks ? ' / ' . $remarks : '')), true);
                    $indent = $item['indent'] ?? 14;

                    $html .= '<tr style="vertical-align: top;">
                        <td style="' . $cellBorder . ' text-align: center;"></td>
                        <td style="' . $cellBorder . ' text-align: left; padding-left: ' . $indent . 'px;">
                            └ ' . $title . '
                        </td>
                        <td style="' . $cellBorder . ' text-align: center;">' . $period . '</td>
                        <td style="' . $cellBorder . ' text-align: center;">' . $ret . '</td>
                    </tr>';
                } elseif ($item['type'] === 'record') {
                    $rec = $item['rec'];
                    $desc = self::cleanVal($rec->description ?? '');
                    $date = self::cleanVal($rec->date_covered ?? '', true);
                    $indent = $item['indent'] ?? 20;

                    $html .= '<tr style="vertical-align: top;">
                        <td style="' . $cellBorder . ' text-align: center;"></td>
                        <td style="' . $cellBorder . ' text-align: left; padding-left: ' . $indent . 'px; font-size: 8px;">
                            ' . $desc . '
                        </td>
                        <td style="' . $cellBorder . ' text-align: center;">' . $date . '</td>
                        <td style="' . $cellBorder . '"></td>
                    </tr>';
                }
            }

            // Filler row
            $html .= '<tr style="height: ' . $computedFiller . 'px;">
                <td style="' . $cellBorder . '">&nbsp;</td>
                <td style="' . $cellBorder . '">&nbsp;</td>
                <td style="' . $cellBorder . '">&nbsp;</td>
                <td style="' . $cellBorder . '">&nbsp;</td>
            </tr>
            </tbody>
            </table>';

            // Official Footer Blocks on last page
            if ($isLastPage) {
                $html .= '<table style="border: 2px solid #000; border-top: none; font-size: 8.5px; page-break-inside: avoid;">
                    <tr>
                        <td style="width: 50%; border: 1px solid #000; padding: 8px;">
                            <strong>LOCATION OF RECORDS:</strong> ' . $location . '
                        </td>
                        <td style="width: 50%; border: 1px solid #000; padding: 8px;">
                            <strong>VOLUME IN CUBIC METER:</strong> ' . $volume . '
                        </td>
                    </tr>
                    <tr>
                        <td style="width: 50%; border: 1px solid #000; padding: 8px;">
                            <strong>PREPARED BY:</strong> ' . $preparedBy . '
                        </td>
                        <td style="width: 50%; border: 1px solid #000; padding: 8px;">
                            <strong>POSITION:</strong> ' . $preparedPos . '
                        </td>
                    </tr>
                    <tr>
                        <td colspan="2" style="border: 1px solid #000; padding: 14px 10px; vertical-align: top;">
                            <strong>CERTIFIED AND APPROVED BY:</strong>
                            <div style="font-size: 8px; margin-top: 6px; text-align: center; line-height: 1.4;">
                                This is to certify that the above mentioned records are no longer needed and<br>not involved nor connected in any administrative or judicial cases.
                            </div>
                            <div style="margin-top: 36px; text-align: center; border-bottom: 1px solid #000; width: 45%; margin-left: auto; margin-right: 40px; font-weight: bold; font-size: 9px; min-height: 13px;">
                                ' . $approvedBy . '
                            </div>
                            <div style="text-align: center; font-size: 8px; margin-top: 3px; width: 45%; margin-left: auto; margin-right: 40px; line-height: 1.3;">
                                Name and Signature of Agency Head<br>or Duly Authorized Representative
                            </div>
                        </td>
                    </tr>
                </table>';
            }

            // Page Number
            $pageWord = ($totalPages === 1) ? 'Page' : 'Pages';
            $html .= '<div style="text-align: right; font-size: 8px; margin-top: 8px;">
                Page ' . ($pageIndex + 1) . ' of ' . $totalPages . ' ' . $pageWord . '
            </div>';

            $html .= '</div>';
        }

        $html .= '</body></html>';
        return $html;
    }

    /**
     * Stream a PDF file generated via DomPDF with official National Archives formatting.
     */
    public static function streamPdf(
        string $filename,
        object $cluster,
        array $items,
        string $formCode,
        array $signatures = []
    ): StreamedResponse {
        $formCode = strtolower($formCode);
        $isNap2 = ($formCode === 'nap2');
        $isNap3 = ($formCode === 'nap3');

        if ($isNap2) {
            $html = self::buildNap2Html($cluster, $items, $signatures);
            $paperSize = [0, 0, 612.00, 936.00];
            $paperOrientation = 'portrait';
        } elseif ($isNap3) {
            $html = self::buildNap3Html($cluster, $items, $signatures);
            $paperSize = [0, 0, 612.00, 936.00];
            $paperOrientation = 'portrait';
        } else {
            $html = self::buildNap1Html($cluster, $items, $signatures);
            $paperSize = [0, 0, 612.00, 936.00];
            $paperOrientation = 'landscape';
        }

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'Helvetica');
        $options->set('dpi', 96);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper($paperSize, $paperOrientation);
        $dompdf->render();

        if (!$isNap2 && !$isNap3) {
            $canvas = $dompdf->getCanvas();
            $fontMetrics = $dompdf->getFontMetrics();
            $font = $fontMetrics->getFont('Helvetica');
            $w = $canvas->get_width();
            $h = $canvas->get_height();
            $totalPages = $canvas->get_page_count();
            $pageWord = ($totalPages === 1) ? 'Page' : 'Pages';
            $footerText = "Page {PAGE_NUM} of {PAGE_COUNT} {$pageWord}";
            $textWidth = $fontMetrics->getTextWidth("Page {$totalPages} of {$totalPages} {$pageWord}", $font, 8);
            $marginRight = 23; // ~8mm
            $marginBottom = 18; // ~6.5mm
            $x = $w - $marginRight - $textWidth - 5;
            $y = $h - $marginBottom;
            $canvas->page_text($x, $y, $footerText, $font, 8, [0, 0, 0]);
        }

        $pdfOutput = $dompdf->output();

        return new StreamedResponse(function () use ($pdfOutput) {
            echo $pdfOutput;
        }, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }
}
