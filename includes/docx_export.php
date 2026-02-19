<?php
/**
 * docx_export.php - Generate issue Word document from template (PHPWord).
 * Mirrors VBA logic: load template, replace placeholders, save to folder with VBA-style filename.
 *
 * Template placeholders: {IssueNum}, {ShortDesc}, {Priority}, {System}, {Client},
 * {Reporter}, {ObjectType}, {FixDev}
 */

// PHPWord optional: functions are defined but docx_generate_issue() returns null if class not loaded.

/**
 * Sanitize string for use in filenames (VBA SafePart: replace invalid chars with "-").
 *
 * @param string $s
 * @return string
 */
function docx_safe_filename_part($s) {
    $s = trim((string) $s);
    $bad = [':', '/', '\\', '*', '?', '"', '<', '>', '|'];
    foreach ($bad as $c) {
        $s = str_replace($c, '-', $s);
    }
    return $s;
}

/**
 * Generate issue document from template and save to output path.
 *
 * @param string $templatePath Full path to template.docx
 * @param string $outputPath Full path for the output .docx file
 * @param array $replacements Keys: IssueNum, ShortDesc, Priority, System, Client, Reporter, ObjectType, FixDev (values as string)
 * @return string|null Saved file path on success, null on failure
 */
function docx_generate_issue($templatePath, $outputPath, array $replacements) {
    if (!class_exists(\PhpOffice\PhpWord\TemplateProcessor::class, true)) {
        return null;
    }
    if (!is_readable($templatePath)) {
        return null;
    }

    try {
        $tp = new \PhpOffice\PhpWord\TemplateProcessor($templatePath);
        $tp->setMacroChars('{', '}');

        $keys = ['IssueNum', 'ShortDesc', 'Priority', 'System', 'Client', 'Reporter', 'ObjectType', 'FixDev'];
        foreach ($keys as $key) {
            $v = isset($replacements[$key]) ? (string) $replacements[$key] : '';
            $tp->setValue($key, $v);
        }

        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true)) {
                return null;
            }
        }

        $tp->saveAs($outputPath);
        return $outputPath;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Build output folder and filename for an issue (VBA-style).
 * Folder: baseDir / zero-padded ticket_no (e.g. 1239 -> 1239, 42 -> 0042 if 4 digits used in VBA it was "0000" format).
 * Filename: "{ticket_no} - {System} - {Client} - {ObjectType} - {ShortDesc first 80}.docx"
 *
 * @param string $baseDir Base directory (e.g. out/)
 * @param int $ticketNo Issue number
 * @param string $systemName
 * @param string $clientName
 * @param string $objectTypeName
 * @param string $shortDesc
 * @return array ['dir' => full folder path, 'filename' => filename, 'path' => full output path]
 */
function docx_issue_paths($baseDir, $ticketNo, $systemName, $clientName, $objectTypeName, $shortDesc) {
    $folderName = str_pad((string) $ticketNo, 4, '0', STR_PAD_LEFT);
    $dir = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $folderName;

    $safeSystem = docx_safe_filename_part($systemName);
    $safeClient = docx_safe_filename_part($clientName);
    $safeObject = docx_safe_filename_part($objectTypeName);
    $safeDesc = docx_safe_filename_part($shortDesc);
    $safeDesc = mb_substr($safeDesc, 0, 80);

    $filename = $ticketNo . ' - ' . $safeSystem . ' - ' . $safeClient . ' - ' . $safeObject . ' - ' . $safeDesc . '.docx';
    $path = $dir . DIRECTORY_SEPARATOR . $filename;

    return ['dir' => $dir, 'filename' => $filename, 'path' => $path];
}
