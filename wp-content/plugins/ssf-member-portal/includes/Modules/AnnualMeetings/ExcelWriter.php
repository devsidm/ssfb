<?php

namespace SSF\MemberPortal\Modules\AnnualMeetings;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Small dependency-free XLSX writer for the registration projection.
 */
final class ExcelWriter
{
    public function create(array $headers, array $rows, string $filename)
    {
        if (! class_exists('ZipArchive')) {
            return new \WP_Error('annual_meeting_zip_missing', __('Servern saknar ZipArchive och kan inte skapa Excel-filen.', 'ssf-member-portal'));
        }

        $path = trailingslashit(get_temp_dir()) . 'ssf-annual-meeting-' . wp_generate_password(16, false, false) . '.xlsx';
        $zip = new \ZipArchive();
        if (true !== $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            return new \WP_Error('annual_meeting_xlsx_create', __('Excel-filen kunde inte skapas på servern.', 'ssf-member-portal'));
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Anmälningar" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->worksheet($headers, $rows));
        $zip->close();

        return is_readable($path) ? $path : new \WP_Error('annual_meeting_xlsx_read', __('Excel-filen kunde inte läsas efter skapandet.', 'ssf-member-portal'));
    }

    private function worksheet(array $headers, array $rows): string
    {
        $all_rows = array_merge(array($headers), $rows);
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetData>';
        foreach ($all_rows as $row_index => $row) {
            $number = $row_index + 1;
            $xml .= '<row r="' . $number . '">';
            foreach (array_values($row) as $column_index => $value) {
                $reference = $this->column($column_index + 1) . $number;
                $text = htmlspecialchars((string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
                $xml .= '<c r="' . $reference . '" t="inlineStr"><is><t xml:space="preserve">' . $text . '</t></is></c>';
            }
            $xml .= '</row>';
        }
        $last_column = $this->column(max(1, count($headers)));
        $last_row = max(1, count($all_rows));
        return $xml . '</sheetData><autoFilter ref="A1:' . $last_column . $last_row . '"/></worksheet>';
    }

    private function column(int $number): string
    {
        $value = '';
        while ($number > 0) {
            $number--;
            $value = chr(65 + ($number % 26)) . $value;
            $number = intdiv($number, 26);
        }
        return $value;
    }
}
