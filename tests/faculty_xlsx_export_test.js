'use strict';

const assert = require('node:assert/strict');
const childProcess = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const sheetJsPath = path.join(root, 'JsScrip', 'vendor', 'xlsx.full.min.js');
const XLSX = require(sheetJsPath);
const bundledPhp = 'C:\\xampp\\php\\php.exe';
const phpBinary = process.env.PHP_BINARY || (fs.existsSync(bundledPhp) ? bundledPhp : 'php');

const phpScript = String.raw`
require 'api/faculty_xlsx_helper.php';
$sasr = facultyXlsxGenerateSasrBinary([
    'faculty_name' => 'Test Faculty',
    'department' => 'ICS',
    'rank' => 'Instructor',
    'semester_label' => 'First Semester 2026-2027',
    'set_summary' => [
        'rows' => [[
            'seq' => 1,
            'course_code' => 'TEST101',
            'year_section' => '1-1',
            'student_count' => 20,
            'average_set_rating' => 4.5,
            'weighted_set_score' => 90,
        ]],
        'total_students' => 20,
        'total_weighted_score' => 90,
    ],
    'section_c_summary' => ['set_rating' => 4.5, 'sef_rating' => 4.25],
]);
$overall = facultyXlsxGenerateOverallSasrBinary([
    'campus_label' => 'Villamor Campus',
    'scope_label' => 'All Programs',
    'semester_label' => 'First Semester 2026-2027',
    'load_label' => 'Main Load',
    'generated_date' => 'September 28, 2026',
    'rows' => [[
        'seq' => 1,
        'employee_id' => 'EMP-001',
        'faculty_name' => 'Test Faculty',
        'department_program' => 'ICS',
        'set_rating' => 4.5,
        'sef_rating' => 4.25,
    ]],
]);
echo json_encode([
    'sasr' => base64_encode($sasr),
    'overall' => base64_encode($overall),
], JSON_THROW_ON_ERROR);
`;

const result = childProcess.spawnSync(phpBinary, ['-r', phpScript], {
    cwd: root,
    encoding: 'utf8',
    maxBuffer: 10 * 1024 * 1024
});
assert.equal(result.status, 0, result.stderr || 'PHP XLSX export generation failed.');
const payload = JSON.parse(result.stdout);

function parseExport(base64, expectedSheetName) {
    const bytes = Buffer.from(base64, 'base64');
    assert(bytes.length > 0, expectedSheetName + ' export must not be empty.');
    const workbook = XLSX.read(bytes, { type: 'buffer' });
    assert.deepEqual(workbook.SheetNames, [expectedSheetName]);
    return XLSX.utils.sheet_to_json(workbook.Sheets[expectedSheetName], {
        header: 1,
        defval: '',
        blankrows: false
    });
}

const sasrRows = parseExport(payload.sasr, 'SASR');
assert(sasrRows.some(row => row.includes('SASR')));
assert(sasrRows.some(row => row.includes('TEST FACULTY')));
assert(sasrRows.some(row => row.includes('TEST101')));

const overallRows = parseExport(payload.overall, 'Overall SASR');
assert(overallRows.some(row => row.includes('OVERALL SASR')));
assert(overallRows.some(row => row.includes('EMP-001')));
assert(overallRows.some(row => row.includes('TEST FACULTY')));

console.log('Faculty XLSX export compatibility tests passed.');
