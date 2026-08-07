<?php

namespace App\Services\ReportBot;

class ReportBotRouter
{
    /**
     * Detect which flow a Telegram file belongs to based on filename and MIME type.
     *
     * Detection order:
     * 1. If filename contains "leads" → 'leads'
     * 2. Else if filename contains "ad" → 'ads'
     * 3. Else if extension is .csv or .xlsx (or MIME indicates csv/spreadsheet) → 'tiktok_income'
     * 4. Else → null
     *
     * @param  string  $fileName  The uploaded file name
     * @param  string  $mime  The MIME type
     * @return string|null One of: 'leads', 'ads', 'tiktok_income', or null
     */
    public static function detect(string $fileName, string $mime): ?string
    {
        $lowerName = strtolower($fileName);

        // Rule 1: Check if filename contains "leads"
        if (strpos($lowerName, 'leads') !== false) {
            return 'leads';
        }

        // Rule 2: Check if filename contains "ad"
        if (strpos($lowerName, 'ad') !== false) {
            return 'ads';
        }

        // Rule 3: Check if file is CSV or XLSX
        // Get extension from filename
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        // Check by extension or MIME type
        if ($extension === 'csv' || $extension === 'xlsx' ||
            strpos($mime, 'csv') !== false ||
            strpos($mime, 'spreadsheet') !== false) {
            return 'tiktok_income';
        }

        // Rule 4: No match
        return null;
    }
}
