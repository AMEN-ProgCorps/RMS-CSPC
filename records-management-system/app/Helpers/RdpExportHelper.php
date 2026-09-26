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
     * Generate pure OpenXML Spreadsheet (.xlsx) bytes.
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
            $ref = self::colIndexToLetter($ci) . $r;
            $sheetXml .= '<c r="' . $ref . '" t="inlineStr" s="1"><is><t>' . htmlspecialchars((string)$h, ENT_XML1, 'UTF-8') . '</t></is></c>';
        }
        $sheetXml .= '</row>';
        $r++;

        // Table Data Rows
        foreach ($rows as $row) {
            $sheetXml .= '<row r="' . $r . '">';
            foreach (array_values($row) as $ci => $val) {
                $ref = self::colIndexToLetter($ci) . $r;
                $sheetXml .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . htmlspecialchars((string)($val ?? ''), ENT_XML1, 'UTF-8') . '</t></is></c>';
            }
            $sheetXml .= '</row>';
            $r++;
        }

        $sheetXml .= '</sheetData></worksheet>';

        $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2">'
            . '<font><name val="Calibri"/><sz val="11"/><color theme="1"/></font>'
            . '<font><b/><name val="Calibri"/><sz val="11"/><color theme="1"/></font>'
            . '</fonts>'
            . '<fills count="2">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '</fills>'
            . '<borders count="1">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '</borders>'
            . '<cellStyleXfs count="1">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>'
            . '</cellStyleXfs>'
            . '<cellXfs count="2">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '</cellXfs>'
            . '</styleSheet>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
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

        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Cluster Records" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';

        $zip = new RdpSimpleZip();
        $zip->addFile('[Content_Types].xml', $contentTypes);
        $zip->addFile('_rels/.rels', $rootRels);
        $zip->addFile('xl/_rels/workbook.xml.rels', $wbRels);
        $zip->addFile('xl/styles.xml', $stylesXml);
        $zip->addFile('xl/workbook.xml', $workbook);
        $zip->addFile('xl/worksheets/sheet1.xml', $sheetXml);

        return $zip->getZip();
    }

    /**
     * Return a streamed download response for .xlsx
     */
    public static function streamXlsx(string $filename, string $title, array $meta, array $headers, array $rows): StreamedResponse
    {
        $xlsxData = self::generateXlsxBinary($title, $meta, $headers, $rows);

        return new StreamedResponse(function () use ($xlsxData) {
            echo $xlsxData;
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    /**
     * Return a streamed download response for .pdf
     */
    public static function streamPdf(
        string $filename,
        object $cluster,
        array $items,
        string $formCode,
        array $signatures = []
    ): StreamedResponse {
        $isNap1 = ($formCode === 'nap1');
        $isNap2 = ($formCode === 'nap2');
        $isNap3 = ($formCode === 'nap3');

        if ($isNap2) {
            $html = self::buildNap2Html($cluster, $items, $signatures);
            $paperSize = 'legal';
            $paperOrientation = 'portrait';
        } elseif ($isNap3) {
            $html = self::buildNap3Html($cluster, $items, $signatures);
            $paperSize = 'legal';
            $paperOrientation = 'portrait';
        } else {
            $html = self::buildNap1Html($cluster, $items, $signatures);
            $paperSize = 'legal';
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

        $pdfOutput = $dompdf->output();

        return new StreamedResponse(function () use ($pdfOutput) {
            echo $pdfOutput;
        }, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    private static function escape(mixed $val): string
    {
        if ($val === null) return '';
        $str = trim((string)$val);
        if ($str === '—' || $str === '-' || $str === 'N/A' || $str === 'None' || $str === 'null') {
            return '';
        }
        return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
    }

    public static function buildNap1Html(object $cluster, array $items, array $sig = []): string
    {
        $agency = self::escape($sig['agencyName'] ?? $cluster->office_name ?? $cluster->office ?? 'Camarines Sur Polytechnic Colleges');
        $dept = self::escape($sig['departmentDivision'] ?? 'Office of the President');
        $section = self::escape($sig['sectionUnit'] ?? 'Records Management Unit');
        $tel = self::escape($sig['telephoneNumber'] ?? '(054) 288-1534 loc. 113');
        $email = self::escape($sig['emailAddress'] ?? 'records@cspc.edu.ph');
        $address = self::escape($sig['agencyAddress'] ?? 'San Miguel, Nabua, Camarines Sur');
        $personInCharge = self::escape($sig['personInCharge'] ?? $cluster->submitter_name ?? 'Records Officer');
        $datePrepared = self::escape($sig['datePrepared'] ?? date('m/d/Y', strtotime($cluster->created_at ?? 'now')));

        $preparedBy = self::escape($sig['preparedBy'] ?? $cluster->submitter_name ?? 'Records Officer');
        $preparedPos = self::escape($sig['preparedPosition'] ?? 'Administrative Officer V / Records Officer');
        $assistedBy = self::escape($sig['assistedBy'] ?? '');
        $assistedPos = self::escape($sig['assistedPosition'] ?? 'NAP Records Management Analyst');
        $recommendingBy = self::escape($sig['recommendingBy'] ?? '');
        $recommendingPos = self::escape($sig['recommendingPosition'] ?? 'Vice President for Administration');
        $approvedBy = self::escape($sig['approvedBy'] ?? '');
        $approvedPos = self::escape($sig['approvedPosition'] ?? 'Chief of Division / Department Head');

        $rowsHtml = '';
        foreach ($items as $it) {
            $title = self::escape($it->series_title ?? $it->doc_name ?? 'Untitled Series');
            $desc = self::escape($it->description ?? '');
            $period = self::escape($it->period_covered ?? '—');
            $volume = self::escape($it->volume ?? '—');
            $medium = self::escape($it->medium_name ?? 'Paper');
            $restriction = self::escape($it->access_restriction ?? 'Restricted');
            $loc = self::escape($it->records_location ?? '—');
            $freq = self::escape($it->frequency_of_use ?? '—');
            $dup = self::escape($it->duplication ?? '—');
            $time = self::escape($it->time_value ?? 'T');
            $util = self::escape($it->utility_name_display ?? 'Adm');
            $active = self::escape($it->active_period ?? '—');
            $storage = self::escape($it->storage_period ?? '—');
            $total = self::escape($it->total_period ?? '—');
            $remarks = self::escape($it->remarks ?? '');

            $rowsHtml .= '<tr style="vertical-align: top;">'
                . '<td style="border: 1px solid #000; padding: 4px; text-align: left; font-weight: bold; font-size: 7.5pt;">'
                . $title . ($desc !== '' ? '<div style="font-weight: normal; font-size: 7pt; margin-top: 2px;">' . $desc . '</div>' : '')
                . '</td>'
                . '<td style="border: 1px solid #000; padding: 4px; text-align: center; font-size: 7.5pt;">' . ($period ?: '—') . '</td>'
                . '<td style="border: 1px solid #000; padding: 4px; text-align: center; font-size: 7.5pt;">' . ($volume ?: '—') . '</td>'
                . '<td style="border: 1px solid #000; padding: 4px; text-align: center; font-size: 7.5pt;">' . ($medium ?: 'Paper') . '</td>'
                . '<td style="border: 1px solid #000; padding: 4px; text-align: center; font-size: 7.5pt;">' . ($restriction ?: 'Restricted') . '</td>'
                . '<td style="border: 1px solid #000; padding: 4px; text-align: center; font-size: 7.5pt;">' . ($loc ?: '—') . '</td>'
                . '<td style="border: 1px solid #000; padding: 4px; text-align: center; font-size: 7.5pt;">' . ($freq ?: '—') . '</td>'
                . '<td style="border: 1px solid #000; padding: 4px; text-align: center; font-size: 7.5pt;">' . ($dup ?: '—') . '</td>'
                . '<td style="border: 1px solid #000; padding: 4px; text-align: center; font-weight: bold; font-size: 7.5pt;">' . ($time ?: 'T') . '</td>'
                . '<td style="border: 1px solid #000; padding: 4px; text-align: center; font-weight: bold; font-size: 7.5pt;">' . ($util ?: 'Adm') . '</td>'
                . '<td style="border: 1px solid #000; padding: 4px; text-align: center; font-size: 7.5pt;">' . ($active ?: '—') . '</td>'
                . '<td style="border: 1px solid #000; padding: 4px; text-align: center; font-size: 7.5pt;">' . ($storage ?: '—') . '</td>'
                . '<td style="border: 1px solid #000; padding: 4px; text-align: center; font-weight: bold; font-size: 7.5pt;">' . ($total ?: '—') . '</td>'
                . '<td style="border: 1px solid #000; padding: 4px; text-align: left; font-size: 7.5pt;">' . $remarks . '</td>'
                . '</tr>';
        }

        return '<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  @page { margin: 8mm 8mm 10mm 8mm; }
  body { font-family: Helvetica, Arial, sans-serif; font-size: 7.5pt; color: #000; margin: 0; padding: 0; }
  table { width: 100%; border-collapse: collapse; table-layout: fixed; }
  th, td { border: 1px solid #000; padding: 3px 4px; font-size: 7.5pt; }
  .header-table td { font-size: 7.5pt; }
  .data-table th { background-color: #f1f5f9; font-size: 7pt; font-weight: bold; text-align: center; }
</style>
</head>
<body>
  <div style="font-size: 7pt; margin-bottom: 2px;">NAP Records Inventory and Appraisal Form 2024</div>
  <table class="header-table" style="border: 2px solid #000;">
    <tr>
      <td rowspan="3" style="width: 27%; text-align: center; vertical-align: middle; padding: 4px; border: 1px solid #000;">
        <div style="border: 1.5px solid #000; padding: 6px 4px;">
          <div style="font-weight: bold; font-size: 8.5pt;">NATIONAL ARCHIVES OF THE PHILIPPINES</div>
          <div style="font-style: italic; font-size: 7pt; margin: 2px 0 4px 0;">Pambansang Sinupan ng Pilipinas</div>
          <div style="font-weight: bold; font-size: 8.5pt;">RECORDS INVENTORY AND APPRAISAL</div>
        </div>
      </td>
      <td rowspan="2" style="width: 27%; border: 1px solid #000; vertical-align: top;">
        <strong>1. NAME OF OFFICE:</strong>
        <div style="font-weight: bold; font-size: 8pt; margin-top: 2px;">' . $agency . '</div>
      </td>
      <td style="width: 20%; border: 1px solid #000; vertical-align: top;">
        <strong>2. DEPARTMENT/DIVISION:</strong>
        <div style="font-size: 7.5pt; margin-top: 2px;">' . $dept . '</div>
      </td>
      <td style="width: 26%; border: 1px solid #000; vertical-align: top;">
        <strong>4. TELEPHONE NO.:</strong>
        <div style="font-size: 7.5pt; margin-top: 2px;">' . $tel . '</div>
      </td>
    </tr>
    <tr>
      <td style="border: 1px solid #000; vertical-align: top;">
        <strong>3. SECTION/UNIT:</strong>
        <div style="font-size: 7.5pt; margin-top: 2px;">' . $section . '</div>
      </td>
      <td style="border: 1px solid #000; vertical-align: top;">
        <strong>5. EMAIL ADDRESS:</strong>
        <div style="font-size: 7.5pt; margin-top: 2px;">' . $email . '</div>
      </td>
    </tr>
    <tr>
      <td style="border: 1px solid #000; vertical-align: top;">
        <strong>6. ADDRESS:</strong>
        <div style="font-size: 7.5pt; margin-top: 2px;">' . $address . '</div>
      </td>
      <td style="border: 1px solid #000; vertical-align: top;">
        <strong>7. PERSON-IN-CHARGE OF FILES:</strong>
        <div style="font-size: 7.5pt; margin-top: 2px;">' . $personInCharge . '</div>
      </td>
      <td style="border: 1px solid #000; vertical-align: top;">
        <strong>8. DATE PREPARED:</strong>
        <div style="font-size: 7.5pt; margin-top: 2px;">' . $datePrepared . '</div>
      </td>
    </tr>
  </table>

  <table class="data-table" style="border: 2px solid #000; border-top: none; margin-top: 0;">
    <thead>
      <tr>
        <th rowspan="2" style="width: 22%; border: 1px solid #000;">9. RECORDS SERIES TITLE AND DESCRIPTION</th>
        <th rowspan="2" style="width: 9%; border: 1px solid #000;">10. PERIOD COVERED</th>
        <th rowspan="2" style="width: 5%; border: 1px solid #000;">11. VOL.</th>
        <th rowspan="2" style="width: 6%; border: 1px solid #000;">12. MEDIUM</th>
        <th rowspan="2" style="width: 7%; border: 1px solid #000;">13. RESTRICTION</th>
        <th rowspan="2" style="width: 7%; border: 1px solid #000;">14. LOCATION</th>
        <th rowspan="2" style="width: 6%; border: 1px solid #000;">15. FREQ.</th>
        <th rowspan="2" style="width: 6%; border: 1px solid #000;">16. DUP.</th>
        <th rowspan="2" style="width: 4%; border: 1px solid #000;">17. TIME</th>
        <th rowspan="2" style="width: 5%; border: 1px solid #000;">18. UTIL.</th>
        <th colspan="3" style="width: 12%; border: 1px solid #000;">19. RETENTION PERIOD</th>
        <th rowspan="2" style="width: 11%; border: 1px solid #000;">20. DISPOSITION PROVISION</th>
      </tr>
      <tr>
        <th style="width: 4%; border: 1px solid #000;">Active</th>
        <th style="width: 4%; border: 1px solid #000;">Storage</th>
        <th style="width: 4%; border: 1px solid #000;">Total</th>
      </tr>
    </thead>
    <tbody>
      ' . $rowsHtml . '
    </tbody>
  </table>

  <table style="border: 2px solid #000; border-top: none; margin-top: 0; page-break-inside: avoid;">
    <tr>
      <td style="width: 25%; padding: 6px; vertical-align: top; border: 1px solid #000;">
        <strong>9. PREPARED BY:</strong><br><br><br>
        <div style="border-bottom: 1px solid #000; font-weight: bold; text-align: center; min-height: 12px;">' . $preparedBy . '</div>
        <div style="font-size: 7pt; text-align: center; margin-top: 2px;">' . $preparedPos . '</div>
      </td>
      <td style="width: 25%; padding: 6px; vertical-align: top; border: 1px solid #000;">
        <strong>10. ASSISTED BY:</strong><br><br><br>
        <div style="border-bottom: 1px solid #000; font-weight: bold; text-align: center; min-height: 12px;">' . $assistedBy . '</div>
        <div style="font-size: 7pt; text-align: center; margin-top: 2px;">' . $assistedPos . '</div>
      </td>
      <td style="width: 25%; padding: 6px; vertical-align: top; border: 1px solid #000;">
        <strong>11. RECOMMENDING APPROVAL:</strong><br><br><br>
        <div style="border-bottom: 1px solid #000; font-weight: bold; text-align: center; min-height: 12px;">' . $recommendingBy . '</div>
        <div style="font-size: 7pt; text-align: center; margin-top: 2px;">' . $recommendingPos . '</div>
      </td>
      <td style="width: 25%; padding: 6px; vertical-align: top; border: 1px solid #000;">
        <strong>12. APPROVED:</strong><br><br><br>
        <div style="border-bottom: 1px solid #000; font-weight: bold; text-align: center; min-height: 12px;">' . $approvedBy . '</div>
        <div style="font-size: 7pt; text-align: center; margin-top: 2px;">' . $approvedPos . '</div>
      </td>
    </tr>
  </table>
</body>
</html>';
    }

    public static function buildNap2Html(object $cluster, array $items, array $sig = []): string
    {
        $agency = self::escape($sig['agencyName'] ?? $cluster->office_name ?? $cluster->office ?? 'Camarines Sur Polytechnic Colleges');
        $address = self::escape($sig['agencyAddress'] ?? 'San Miguel, Nabua, Camarines Sur');
        $tel = self::escape($sig['telephoneNumber'] ?? '(054) 288-1534 loc. 113');
        $datePrepared = self::escape($sig['datePrepared'] ?? date('F d, Y', strtotime($cluster->created_at ?? 'now')));

        $preparedBy = self::escape($sig['preparedBy'] ?? $cluster->submitter_name ?? 'Records Officer');
        $preparedPos = self::escape($sig['preparedPosition'] ?? 'Administrative Officer V / Records Officer');
        $assistedBy = self::escape($sig['assistedBy'] ?? '');
        $assistedPos = self::escape($sig['assistedPosition'] ?? 'NAP Records Management Analyst');
        $approvedBy = self::escape($sig['approvedBy'] ?? '');
        $approvedPos = self::escape($sig['approvedPosition'] ?? 'Chief of Division / Department Head');
        $execDirector = self::escape($sig['executiveDirectorName'] ?? '');
        $execDirectorTitle = self::escape($sig['executiveDirectorTitle'] ?? 'Executive Director, National Archives of the Philippines');

        $rowsHtml = '';
        foreach ($items as $it) {
            $itemNo = self::escape($it->display_item_no ?? $it->item_number ?? '');
            $title = self::escape($it->series_title ?? 'Untitled Series');
            $desc = self::escape($it->description ?? '');
            $active = self::escape($it->effective_active ?? $it->active_period ?? '—');
            $storage = self::escape($it->effective_storage ?? $it->storage_period ?? '—');
            $total = !empty($it->effective_is_permanent) ? 'Permanent' : self::escape($it->effective_total ?? $it->total_period ?? '—');
            $remarks = self::escape($it->remarks ?? '');
            $indent = isset($it->depth) && $it->depth > 0 ? ($it->depth * 14) : 0;

            $rowsHtml .= '<tr style="vertical-align: top;">'
                . '<td style="border: 1px solid #000; padding: 4px; text-align: center; font-size: 8pt;">' . $itemNo . '</td>'
                . '<td style="border: 1px solid #000; padding: 4px 4px 4px ' . ($indent + 4) . 'px; text-align: left; font-size: 8pt;">'
                . ($indent > 0 ? '└ ' : '') . '<strong>' . $title . '</strong>'
                . ($desc !== '' ? '<div style="font-weight: normal; font-size: 7.5pt; margin-top: 2px;">' . $desc . '</div>' : '')
                . '</td>'
                . '<td style="border: 1px solid #000; padding: 4px; text-align: center; font-size: 8pt;">' . ($active ?: '—') . '</td>'
                . '<td style="border: 1px solid #000; padding: 4px; text-align: center; font-size: 8pt;">' . ($storage ?: '—') . '</td>'
                . '<td style="border: 1px solid #000; padding: 4px; text-align: center; font-weight: bold; font-size: 8pt;">' . ($total ?: '—') . '</td>'
                . '<td style="border: 1px solid #000; padding: 4px; text-align: left; font-size: 8pt;">' . $remarks . '</td>'
                . '</tr>';
        }

        return '<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  @page { margin: 10mm 10mm 12mm 10mm; }
  body { font-family: Helvetica, Arial, sans-serif; font-size: 8pt; color: #000; margin: 0; padding: 0; }
  table { width: 100%; border-collapse: collapse; table-layout: fixed; }
  th, td { border: 1px solid #000; padding: 4px; font-size: 8pt; }
  .data-table th { background-color: #f1f5f9; font-size: 8pt; font-weight: bold; text-align: center; }
</style>
</head>
<body>
  <div style="font-size: 7.5pt; margin-bottom: 2px;">NAP Form 2 (Revised 2012)</div>
  <table style="border: 2px solid #000;">
    <tr>
      <td rowspan="2" style="width: 50%; text-align: center; vertical-align: middle; padding: 6px; border: 1px solid #000;">
        <div style="border: 1.5px solid #000; padding: 6px 4px;">
          <div style="font-weight: bold; font-size: 9.5pt;">NATIONAL ARCHIVES OF THE PHILIPPINES</div>
          <div style="font-style: italic; font-size: 7.5pt; margin: 2px 0 4px 0;">Pambansang Sinupan ng Pilipinas</div>
          <div style="font-weight: bold; font-size: 9.5pt;">RECORDS DISPOSITION SCHEDULE</div>
        </div>
      </td>
      <td style="width: 50%; border: 1px solid #000; vertical-align: top;">
        <strong>1. AGENCY NAME:</strong>
        <div style="font-weight: bold; font-size: 8.5pt; margin-top: 2px;">' . $agency . '</div>
      </td>
    </tr>
    <tr>
      <td style="border: 1px solid #000; vertical-align: top;">
        <strong>2. ADDRESS:</strong>
        <div style="font-size: 8pt; margin-top: 2px;">' . $address . '</div>
      </td>
    </tr>
    <tr>
      <td style="border: 1px solid #000;">
        <strong>3. TELEPHONE NO.:</strong> ' . $tel . '
      </td>
      <td style="border: 1px solid #000;">
        <strong>4. DATE PREPARED:</strong> ' . $datePrepared . '
      </td>
    </tr>
  </table>

  <table class="data-table" style="border: 2px solid #000; border-top: none; margin-top: 0;">
    <thead>
      <tr>
        <th rowspan="2" style="width: 10%; border: 1px solid #000;">ITEM NO.</th>
        <th rowspan="2" style="width: 44%; border: 1px solid #000;">RECORDS SERIES TITLE AND DESCRIPTION</th>
        <th colspan="3" style="width: 26%; border: 1px solid #000;">RETENTION PERIOD</th>
        <th rowspan="2" style="width: 20%; border: 1px solid #000;">REMARKS</th>
      </tr>
      <tr>
        <th style="width: 8%; border: 1px solid #000;">Active</th>
        <th style="width: 8%; border: 1px solid #000;">Storage</th>
        <th style="width: 10%; border: 1px solid #000;">Total</th>
      </tr>
    </thead>
    <tbody>
      ' . $rowsHtml . '
    </tbody>
  </table>

  <table style="border: 2px solid #000; border-top: none; margin-top: 0; page-break-inside: avoid;">
    <tr>
      <td style="width: 50%; padding: 8px; vertical-align: top; border: 1px solid #000;">
        <strong>PREPARED BY:</strong><br><br><br>
        <div style="border-bottom: 1px solid #000; font-weight: bold; text-align: center; min-height: 14px;">' . $preparedBy . '</div>
        <div style="font-size: 7.5pt; text-align: center; margin-top: 2px;">' . $preparedPos . '</div>
      </td>
      <td style="width: 50%; padding: 8px; vertical-align: top; border: 1px solid #000;">
        <strong>ASSISTED BY:</strong><br><br><br>
        <div style="border-bottom: 1px solid #000; font-weight: bold; text-align: center; min-height: 14px;">' . $assistedBy . '</div>
        <div style="font-size: 7.5pt; text-align: center; margin-top: 2px;">' . $assistedPos . '</div>
      </td>
    </tr>
    <tr>
      <td style="width: 50%; padding: 8px; vertical-align: top; border: 1px solid #000;">
        <strong>APPROVED BY:</strong><br><br><br>
        <div style="border-bottom: 1px solid #000; font-weight: bold; text-align: center; min-height: 14px;">' . $approvedBy . '</div>
        <div style="font-size: 7.5pt; text-align: center; margin-top: 2px;">' . $approvedPos . '</div>
      </td>
      <td style="width: 50%; padding: 8px; vertical-align: top; border: 1px solid #000;">
        <strong>APPROVED (NAP):</strong><br><br><br>
        <div style="border-bottom: 1px solid #000; font-weight: bold; text-align: center; min-height: 14px;">' . $execDirector . '</div>
        <div style="font-size: 7.5pt; text-align: center; margin-top: 2px;">' . $execDirectorTitle . '</div>
      </td>
    </tr>
  </table>
</body>
</html>';
    }

    public static function buildNap3Html(object $cluster, array $items, array $sig = []): string
    {
        $agency = self::escape($sig['agencyName'] ?? $cluster->office_name ?? $cluster->office ?? 'Camarines Sur Polytechnic Colleges');
        $address = self::escape($sig['agencyAddress'] ?? 'San Miguel, Nabua, Camarines Sur');
        $tel = self::escape($sig['telephoneNumber'] ?? '(054) 288-1534 loc. 113');
        $datePrepared = self::escape($sig['datePrepared'] ?? date('F d, Y', strtotime($cluster->created_at ?? 'now')));

        $location = self::escape($sig['effectivePrintLocation'] ?? 'Records Management Unit Storage');
        $volume = self::escape($sig['effectivePrintVolume'] ?? '0.5 cu. m.');
        $preparedBy = self::escape($sig['preparedBy'] ?? $cluster->submitter_name ?? 'Records Officer');
        $preparedPos = self::escape($sig['preparedPosition'] ?? 'Administrative Officer V / Records Officer');
        $approvedBy = self::escape($sig['approvedBy'] ?? '');

        $rowsHtml = '';
        foreach ($items as $it) {
            $itemNo = self::escape($it->item_number ?? '');
            $title = self::escape($it->series_title ?? $it->parent_title ?? 'Untitled Item');
            $desc = self::escape($it->description ?? '');
            $period = self::escape($it->period_covered ?? '—');
            $retention = self::escape(trim(($it->total_period ?? '') . ($it->remarks ? ' / ' . $it->remarks : '')));

            $rowsHtml .= '<tr style="vertical-align: top;">'
                . '<td style="border: 1px solid #000; padding: 4px; text-align: center; font-size: 8pt;">' . $itemNo . '</td>'
                . '<td style="border: 1px solid #000; padding: 4px; text-align: left; font-size: 8pt;">'
                . '<strong>' . $title . '</strong>'
                . ($desc !== '' ? '<div style="font-size: 7.5pt; margin-top: 2px;">' . $desc . '</div>' : '')
                . '</td>'
                . '<td style="border: 1px solid #000; padding: 4px; text-align: center; font-size: 8pt;">' . ($period ?: '—') . '</td>'
                . '<td style="border: 1px solid #000; padding: 4px; text-align: center; font-size: 8pt;">' . ($retention ?: '—') . '</td>'
                . '</tr>';
        }

        return '<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  @page { margin: 10mm 10mm 12mm 10mm; }
  body { font-family: Helvetica, Arial, sans-serif; font-size: 8pt; color: #000; margin: 0; padding: 0; }
  table { width: 100%; border-collapse: collapse; table-layout: fixed; }
  th, td { border: 1px solid #000; padding: 4px; font-size: 8pt; }
  .data-table th { background-color: #f1f5f9; font-size: 8pt; font-weight: bold; text-align: center; }
</style>
</head>
<body>
  <div style="font-size: 7.5pt; margin-bottom: 2px;">NAP Form 3 (Revised 2012)</div>
  <table style="border: 2px solid #000;">
    <tr>
      <td rowspan="2" style="width: 50%; text-align: center; vertical-align: middle; padding: 6px; border: 1px solid #000;">
        <div style="border: 1.5px solid #000; padding: 6px 4px;">
          <div style="font-weight: bold; font-size: 9.5pt;">NATIONAL ARCHIVES OF THE PHILIPPINES</div>
          <div style="font-style: italic; font-size: 7.5pt; margin: 2px 0 4px 0;">Pambansang Sinupan ng Pilipinas</div>
          <div style="font-weight: bold; font-size: 9.5pt;">REQUEST FOR AUTHORITY TO DISPOSE<br>OF RECORDS</div>
        </div>
      </td>
      <td style="width: 50%; border: 1px solid #000; vertical-align: top;">
        <strong>AGENCY NAME:</strong>
        <div style="font-weight: bold; font-size: 8.5pt; margin-top: 2px;">' . $agency . '</div>
      </td>
    </tr>
    <tr>
      <td style="border: 1px solid #000; vertical-align: top;">
        <strong>ADDRESS:</strong>
        <div style="font-size: 8pt; margin-top: 2px;">' . $address . '</div>
      </td>
    </tr>
    <tr>
      <td style="border: 1px solid #000;">
        <strong>DATE:</strong> ' . $datePrepared . '
      </td>
      <td style="border: 1px solid #000;">
        <strong>TELEPHONE NUMBER:</strong> ' . $tel . '
      </td>
    </tr>
  </table>

  <table class="data-table" style="border: 2px solid #000; border-top: none; margin-top: 0;">
    <thead>
      <tr>
        <th style="width: 14%; border: 1px solid #000;">GRDS/RDS ITEM NO.</th>
        <th style="width: 46%; border: 1px solid #000;">RECORD SERIES TITLE AND DESCRIPTION</th>
        <th style="width: 20%; border: 1px solid #000;">PERIOD COVERED</th>
        <th style="width: 20%; border: 1px solid #000;">RETENTION PERIOD AND PROVISION/S COMPLIED</th>
      </tr>
    </thead>
    <tbody>
      ' . $rowsHtml . '
    </tbody>
  </table>

  <table style="border: 2px solid #000; border-top: none; margin-top: 0; page-break-inside: avoid;">
    <tr>
      <td style="width: 50%; padding: 6px; border: 1px solid #000;">
        <strong>LOCATION OF RECORDS:</strong> ' . $location . '
      </td>
      <td style="width: 50%; padding: 6px; border: 1px solid #000;">
        <strong>VOLUME IN CUBIC METER:</strong> ' . $volume . '
      </td>
    </tr>
    <tr>
      <td style="width: 50%; padding: 6px; border: 1px solid #000;">
        <strong>PREPARED BY:</strong> ' . $preparedBy . '
      </td>
      <td style="width: 50%; padding: 6px; border: 1px solid #000;">
        <strong>POSITION:</strong> ' . $preparedPos . '
      </td>
    </tr>
    <tr>
      <td colspan="2" style="padding: 10px; border: 1px solid #000; vertical-align: top;">
        <strong>CERTIFIED AND APPROVED BY:</strong>
        <div style="font-size: 7.5pt; margin-top: 4px; text-align: center; line-height: 1.3;">
          This is to certify that the above mentioned records are no longer needed and not involved nor connected in any administrative or judicial cases.
        </div>
        <div style="margin-top: 24px; text-align: center; border-bottom: 1px solid #000; width: 45%; margin-left: auto; margin-right: 30px; font-weight: bold; min-height: 14px;">
          ' . $approvedBy . '
        </div>
        <div style="text-align: center; font-size: 7.5pt; margin-top: 2px; width: 45%; margin-left: auto; margin-right: 30px;">
          Name and Signature of Agency Head or Duly Authorized Representative
        </div>
      </td>
    </tr>
  </table>
</body>
</html>';
    }
}
