'use strict';

const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..');
const adminScriptPath = path.join(root, 'JsScrip', 'adminpanel.js');
const adminHtmlPath = path.join(root, 'html', 'adminpanel.html');
const sheetJsPath = path.join(root, 'JsScrip', 'vendor', 'xlsx.full.min.js');
const adminSource = fs.readFileSync(adminScriptPath, 'utf8');
const adminHtml = fs.readFileSync(adminHtmlPath, 'utf8');
const sheetJsBytes = fs.readFileSync(sheetJsPath);
const XLSX = require(sheetJsPath);

function sourceBetween(startMarker, endMarker) {
    const start = adminSource.indexOf(startMarker);
    assert.notEqual(start, -1, `Missing source marker: ${startMarker}`);
    const end = adminSource.indexOf(endMarker, start + startMarker.length);
    assert.notEqual(end, -1, `Missing source marker: ${endMarker}`);
    return adminSource.slice(start, end);
}

const securityContext = {};
vm.createContext(securityContext);
vm.runInContext([
    sourceBetween('const BULK_HEADER_ALIASES = {', '\nfunction sectionTokenToNumber('),
    sourceBetween('function mapBulkRowToCanonical(', '\nfunction normalizeBulkDepartment('),
    sourceBetween('function getExcelParser(', '\nfunction hasRecognizedBulkColumn('),
    sourceBetween('function hasRecognizedBulkColumn(', '\nfunction mapCredentialDistributorRow('),
    sourceBetween('function mapCredentialDistributorRow(', '\nfunction extractCredentialDistributorRows('),
    sourceBetween('const SUBJECT_BULK_HEADER_ALIASES = {', '\nfunction setupSubjectManagement('),
    sourceBetween('function mapSubjectBulkRow(', '\nasync function handleBulkSubjectImport('),
    sourceBetween('function mapOfferingBulkRow(', '\nfunction buildOfferingBulkPayloadRows('),
    `this.sheetJsSecurityHooks = {
        SHEETJS_REQUIRED_VERSION,
        EXCEL_IMPORT_MAX_FILE_BYTES,
        EXCEL_IMPORT_MAX_DATA_ROWS,
        EXCEL_IMPORT_MAX_COLUMNS,
        EXCEL_IMPORT_TIMEOUT_MS,
        CREDENTIAL_DISTRIBUTOR_MAX_ROWS,
        BULK_HEADER_ALIASES,
        CREDENTIAL_DISTRIBUTOR_HEADER_ALIASES,
        SUBJECT_BULK_HEADER_ALIASES,
        OFFERING_BULK_HEADER_ALIASES,
        getExcelParser,
        createExcelImportError,
        resolveExcelImportMaxDataRows,
        validateExcelImportFileMetadata,
        validateExcelImportSignature,
        validateExcelWorksheetDimensions,
        getValidatedExcelWorksheet,
        parseExcelWorksheetMatrix,
        parseExcelRowsInWorker,
        readExcelRows,
        buildSafeExcelRows,
        resolveExcelHeaderAlias,
        hasRecognizedBulkColumn,
        mapBulkRowToCanonical,
        mapCredentialDistributorRow,
        mapSubjectBulkRow,
        mapOfferingBulkRow
    };`
].join('\n'), securityContext);

const hooks = securityContext.sheetJsSecurityHooks;
const expectedVersion = '0.20.3';
const expectedSha256 = 'cc015130aa8521e7f088f88898eba949ccdcbfb38df0bd129b44b7273c3a6f41';

assert.equal(XLSX.version, expectedVersion, 'Vendored SheetJS must be the approved release.');
assert.equal(hooks.SHEETJS_REQUIRED_VERSION, expectedVersion, 'Runtime version guard must match the vendored release.');
assert.equal(
    crypto.createHash('sha256').update(sheetJsBytes).digest('hex'),
    expectedSha256,
    'Vendored SheetJS bytes must match the official distribution.'
);
assert(adminHtml.includes('xlsx.full.min.js?v=0.20.3'), 'Admin HTML must cache-bust the approved SheetJS release.');
assert(adminHtml.includes('adminpanel.js?v=20260928b'), 'Admin HTML must load the hardened import code.');
assert(adminSource.includes('xlsx.full.min.js?v=${SHEETJS_REQUIRED_VERSION}'), 'Worker must load the versioned local asset.');
assert(adminSource.includes("kind: 'infrastructure'") && adminSource.includes("kind: 'parse'"), 'Worker failures must distinguish infrastructure from parsing errors.');
assert(!adminSource.includes('parseExcelRowsOnMainThread'), 'Untrusted workbooks must never be parsed on the main UI thread.');
assert(!adminSource.includes('falling back to main thread'), 'The insecure main-thread fallback must be removed.');
assert.equal(fs.existsSync(path.join(root, 'package.json')), false, 'SheetJS must not be shadowed by an npm package.');
assert.equal(fs.existsSync(path.join(root, 'package-lock.json')), false, 'SheetJS must not be shadowed by an npm lockfile.');

assert.equal(hooks.EXCEL_IMPORT_MAX_FILE_BYTES, 10 * 1024 * 1024);
assert.equal(hooks.EXCEL_IMPORT_MAX_DATA_ROWS, 25000);
assert.equal(hooks.EXCEL_IMPORT_MAX_COLUMNS, 100);
assert.equal(hooks.EXCEL_IMPORT_TIMEOUT_MS, 30000);
assert.equal(hooks.CREDENTIAL_DISTRIBUTOR_MAX_ROWS, 500);

securityContext.XLSX = { version: '0.19.2' };
assert.equal(hooks.getExcelParser(), null, 'A non-approved SheetJS version must be refused.');
securityContext.XLSX = XLSX;
assert.equal(hooks.getExcelParser(), XLSX, 'The approved SheetJS release must be accepted.');

function parseSafeWorkbook(buffer) {
    const workbook = XLSX.read(buffer, { type: 'buffer' });
    const worksheet = hooks.getValidatedExcelWorksheet(workbook);
    const matrix = hooks.parseExcelWorksheetMatrix(XLSX, worksheet);
    return hooks.buildSafeExcelRows(matrix);
}

function readFixture(name) {
    return fs.readFileSync(path.join(root, 'files', name));
}

const fixtureExpectations = [
    ['bulk.xlsx', 200],
    ['subject.xlsx', 18],
    ['subjectassign.xlsx', 54],
    ['excess sample.xlsx', 8]
];

const parsedFixtures = new Map();
fixtureExpectations.forEach(([name, expectedRows]) => {
    const buffer = readFixture(name);
    const workbook = XLSX.read(buffer, { type: 'buffer' });
    const ordinaryRows = XLSX.utils.sheet_to_json(workbook.Sheets[workbook.SheetNames[0]], { defval: '' });
    const safeRows = parseSafeWorkbook(buffer);
    parsedFixtures.set(name, safeRows);
    assert.equal(safeRows.length, expectedRows, `${name} row count changed.`);
    assert.equal(JSON.stringify(safeRows), JSON.stringify(ordinaryRows), `${name} parsed values changed.`);
    safeRows.forEach(row => assert.equal(Object.getPrototypeOf(row), null, `${name} rows must not inherit object properties.`));
});

const bulkFixtureBuffer = readFixture('bulk.xlsx');
const bulkFixtureArrayBuffer = bulkFixtureBuffer.buffer.slice(
    bulkFixtureBuffer.byteOffset,
    bulkFixtureBuffer.byteOffset + bulkFixtureBuffer.byteLength
);

const workerSourceMatch = adminSource.match(/const workerSource = `([\s\S]*?)`;\s*let blobUrl/);
assert(workerSourceMatch, 'Unable to locate the inline Excel worker source.');
const workerSource = workerSourceMatch[1];
assert.doesNotThrow(() => new vm.Script(workerSource), 'Inline Excel worker source must be valid JavaScript.');

function runInlineWorker(arrayBuffer, options = {}) {
    const messages = [];
    const context = {
        self: {
            postMessage(message) {
                messages.push(message);
            }
        },
        importScripts() {
            if (options.importError) throw new Error(options.importError);
            context.self.XLSX = options.parser || XLSX;
        }
    };
    vm.createContext(context);
    vm.runInContext(workerSource, context);
    context.self.onmessage({
        data: {
            parserUrl: 'http://127.0.0.1/system/JsScrip/vendor/xlsx.full.min.js?v=0.20.3',
            expectedVersion,
            maxDataRows: options.maxDataRows || hooks.EXCEL_IMPORT_MAX_DATA_ROWS,
            maxColumns: options.maxColumns || hooks.EXCEL_IMPORT_MAX_COLUMNS,
            arrayBuffer
        }
    });
    assert.equal(messages.length, 1, 'Excel worker must return exactly one result.');
    return messages[0];
}

const workerSuccess = runInlineWorker(bulkFixtureArrayBuffer);
assert.equal(workerSuccess.success, true, 'Worker must parse a valid XLSX workbook.');
assert.equal(workerSuccess.matrix.length, 201, 'Worker matrix must include one header and 200 bulk-user rows.');

const malformedBytes = Buffer.from([0x50, 0x4b, 0x03, 0x04]);
const malformedArrayBuffer = malformedBytes.buffer.slice(
    malformedBytes.byteOffset,
    malformedBytes.byteOffset + malformedBytes.byteLength
);
const workerParseFailure = runInlineWorker(malformedArrayBuffer);
assert.equal(workerParseFailure.success, false);
assert.equal(workerParseFailure.kind, 'parse', 'Malformed workbooks must not trigger a main-thread retry.');
assert.equal(workerParseFailure.code, 'invalid_workbook');

const workerLoadFailure = runInlineWorker(bulkFixtureArrayBuffer, { importError: 'blocked' });
assert.equal(workerLoadFailure.success, false);
assert.equal(workerLoadFailure.kind, 'infrastructure', 'Worker loading failures must fail safely.');
assert.equal(workerLoadFailure.code, 'worker_unavailable');

const workerVersionFailure = runInlineWorker(bulkFixtureArrayBuffer, {
    parser: Object.assign({}, XLSX, { version: '0.19.2' })
});
assert.equal(workerVersionFailure.success, false);
assert.equal(workerVersionFailure.kind, 'parse', 'An unexpected worker parser version must be refused without retrying it as infrastructure.');
assert.equal(workerVersionFailure.code, 'parser_unavailable');

function toArrayBuffer(buffer) {
    return buffer.buffer.slice(buffer.byteOffset, buffer.byteOffset + buffer.byteLength);
}

function assertExcelImportError(callback, expectedCode, message) {
    assert.throws(callback, error => error && error.excelImportCode === expectedCode, message);
}

assert.equal(
    hooks.validateExcelImportFileMetadata({ name: 'boundary.xlsx', size: hooks.EXCEL_IMPORT_MAX_FILE_BYTES }),
    '.xlsx'
);
assertExcelImportError(
    () => hooks.validateExcelImportFileMetadata({ name: 'empty.xlsx', size: 0 }),
    'empty_file',
    'Empty files must be rejected before parsing.'
);
assertExcelImportError(
    () => hooks.validateExcelImportFileMetadata({ name: 'large.xlsx', size: hooks.EXCEL_IMPORT_MAX_FILE_BYTES + 1 }),
    'file_too_large',
    'Files above 10 MiB must be rejected before reading their bytes.'
);
assertExcelImportError(
    () => hooks.validateExcelImportFileMetadata({ name: 'renamed.csv', size: 20 }),
    'unsupported_type',
    'Non-XLS/XLSX extensions must be rejected.'
);
hooks.validateExcelImportSignature(bulkFixtureArrayBuffer, '.xlsx');

const signatureWorkbook = XLSX.utils.book_new();
XLSX.utils.book_append_sheet(signatureWorkbook, XLSX.utils.aoa_to_sheet([['name'], ['Alice']]), 'Sheet1');
const xlsSignatureBuffer = XLSX.write(signatureWorkbook, { type: 'buffer', bookType: 'xls' });
hooks.validateExcelImportSignature(toArrayBuffer(xlsSignatureBuffer), '.xls');
const biff5SignatureBuffer = XLSX.write(signatureWorkbook, { type: 'buffer', bookType: 'biff5' });
hooks.validateExcelImportSignature(toArrayBuffer(biff5SignatureBuffer), '.xls');
assertExcelImportError(
    () => hooks.validateExcelImportSignature(toArrayBuffer(Buffer.from('name,email\nAlice,a@example.test')), '.xlsx'),
    'invalid_signature',
    'A CSV renamed to XLSX must be rejected.'
);
assertExcelImportError(
    () => hooks.validateExcelImportSignature(toArrayBuffer(Buffer.from('<html><table></table></html>')), '.xls'),
    'invalid_signature',
    'HTML renamed to XLS must be rejected.'
);

function worksheetWithFullRange(fullRange) {
    const worksheet = XLSX.utils.aoa_to_sheet([['name'], ['Alice']]);
    worksheet['!fullref'] = fullRange;
    return worksheet;
}

assert.doesNotThrow(() => hooks.parseExcelWorksheetMatrix(
    XLSX,
    worksheetWithFullRange('A1:A25001'),
    hooks.EXCEL_IMPORT_MAX_DATA_ROWS
));
assertExcelImportError(
    () => hooks.parseExcelWorksheetMatrix(XLSX, worksheetWithFullRange('A1:A25002'), hooks.EXCEL_IMPORT_MAX_DATA_ROWS),
    'row_limit',
    '25,001 data rows must be rejected.'
);
assert.doesNotThrow(() => hooks.parseExcelWorksheetMatrix(
    XLSX,
    worksheetWithFullRange('A1:CV2'),
    hooks.EXCEL_IMPORT_MAX_DATA_ROWS
));
assertExcelImportError(
    () => hooks.parseExcelWorksheetMatrix(XLSX, worksheetWithFullRange('A1:CW2'), hooks.EXCEL_IMPORT_MAX_DATA_ROWS),
    'column_limit',
    '101 columns must be rejected.'
);
assert.doesNotThrow(() => hooks.parseExcelWorksheetMatrix(
    XLSX,
    worksheetWithFullRange('A1:A501'),
    hooks.CREDENTIAL_DISTRIBUTOR_MAX_ROWS
));
assertExcelImportError(
    () => hooks.parseExcelWorksheetMatrix(XLSX, worksheetWithFullRange('A1:A502'), hooks.CREDENTIAL_DISTRIBUTOR_MAX_ROWS),
    'row_limit',
    'Credential distribution must reject more than 500 data rows.'
);

const firstSheetWorkbook = XLSX.utils.book_new();
XLSX.utils.book_append_sheet(firstSheetWorkbook, XLSX.utils.aoa_to_sheet([['name'], ['First']]), 'First');
XLSX.utils.book_append_sheet(firstSheetWorkbook, XLSX.utils.aoa_to_sheet([['name'], ['Second']]), 'Second');
const firstSheetBuffer = XLSX.write(firstSheetWorkbook, { type: 'buffer', bookType: 'xlsx' });
const firstSheetResult = runInlineWorker(toArrayBuffer(firstSheetBuffer));
assert.equal(firstSheetResult.success, true);
assert.deepEqual(JSON.parse(JSON.stringify(firstSheetResult.matrix)), [['name'], ['First']], 'Only the first worksheet may be processed.');

const largeMatrix = [Array.from({ length: 12 }, (_value, index) => 'column_' + (index + 1))];
for (let rowIndex = 1; rowIndex <= 10000; rowIndex += 1) {
    largeMatrix.push(Array.from({ length: 12 }, (_value, columnIndex) => 'R' + rowIndex + 'C' + (columnIndex + 1)));
}
const largeWorkbook = XLSX.utils.book_new();
XLSX.utils.book_append_sheet(largeWorkbook, XLSX.utils.aoa_to_sheet(largeMatrix), 'Large Import');
const largeWorkbookBuffer = XLSX.write(largeWorkbook, { type: 'buffer', bookType: 'xlsx', compression: true });
assert(largeWorkbookBuffer.length <= hooks.EXCEL_IMPORT_MAX_FILE_BYTES, 'Large legitimate test workbook must remain under 10 MiB.');
const largeWorkerResult = runInlineWorker(toArrayBuffer(largeWorkbookBuffer));
assert.equal(largeWorkerResult.success, true, 'A legitimate 10,000-row workbook must parse successfully.');
assert.equal(largeWorkerResult.matrix.length, 10001);

assert.deepEqual(
    { ...hooks.mapBulkRowToCanonical(parsedFixtures.get('bulk.xlsx')[0]) },
    {
        name: 'admin.alexis.',
        email: 'admin.alexis.navarro@naap.edu.ph',
        role: 'admin',
        campus: 'villamor',
        password: '123',
        department: 'ICS',
        employeeId: 'admin',
        employmentType: 'Regular',
        position: 'System Administrator',
        studentNumber: '',
        yearSection: '',
        programCode: ''
    },
    'Bulk-user fields must retain their canonical mappings.'
);
assert.deepEqual(
    { ...hooks.mapCredentialDistributorRow(parsedFixtures.get('bulk.xlsx')[0], 2) },
    {
        rowNumber: 2,
        name: 'admin.alexis.',
        email: 'admin.alexis.navarro@naap.edu.ph',
        role: 'admin',
        campus: 'villamor',
        employee: '',
        password: '123'
    },
    'Credential-distributor fields must retain their canonical mappings.'
);
assert.equal(
    hooks.mapCredentialDistributorRow({ employeeId: 'EMP-001' }, 2).employee,
    'EMP-001',
    'Credential employee-id headers must map to the employee field.'
);
assert.deepEqual(
    { ...hooks.mapSubjectBulkRow(parsedFixtures.get('subject.xlsx')[0]) },
    { campusSlug: 'villamor', departmentCode: 'ics', subjectCode: 'AIS101', subjectName: 'Introduction to Accounting Information Systems' },
    'Subject fields must retain their canonical mappings.'
);
assert.deepEqual(
    { ...hooks.mapOfferingBulkRow(parsedFixtures.get('subjectassign.xlsx')[0]) },
    {
        semesterSlug: '',
        campusSlug: 'mactan',
        departmentCode: 'inet',
        programCode: 'BSAET',
        subjectCode: 'AET101',
        sectionName: '1/1',
        professorEmployeeId: 'PRF-2026-004'
    },
    'Offering fields must retain their canonical mappings.'
);
assert.deepEqual(
    { ...hooks.mapOfferingBulkRow(parsedFixtures.get('excess sample.xlsx')[0]) },
    {
        semesterSlug: '',
        campusSlug: 'villamor',
        departmentCode: 'ilas',
        programCode: 'BSAVCOMM',
        subjectCode: 'THC102',
        sectionName: '1/1',
        professorEmployeeId: 'PRF-2026-003'
    },
    'Excess-load fields must retain their canonical mappings.'
);

const unknownRows = hooks.buildSafeExcelRows([['name', 'unexpected', 'toString'], ['Alice', 'ignored', 'safe value']]);
assert.equal(Object.getPrototypeOf(unknownRows[0]), null);
assert.equal(unknownRows[0].toString, 'safe value', 'Unknown property names must be inert data on a null-prototype row.');
assert.equal(hooks.hasRecognizedBulkColumn(unknownRows, hooks.BULK_HEADER_ALIASES), true);
assert.equal(hooks.resolveExcelHeaderAlias(hooks.BULK_HEADER_ALIASES, 'unexpected'), '');
assert.equal(hooks.resolveExcelHeaderAlias(hooks.BULK_HEADER_ALIASES, 'constructor'), '');
assert.equal(
    hooks.hasRecognizedBulkColumn([hooks.buildSafeExcelRows([['toString'], ['value']])[0]], hooks.BULK_HEADER_ALIASES),
    false,
    'Inherited alias-table properties must not be treated as recognized columns.'
);

const duplicateRows = hooks.buildSafeExcelRows([['name', 'name', ''], ['First', 'Second', 'blank']]);
assert.deepEqual(Object.keys(duplicateRows[0]), ['name', 'name_1', '__EMPTY']);
assert.equal(duplicateRows[0].name, 'First');
assert.equal(duplicateRows[0].name_1, 'Second');

for (const dangerousName of ['__proto__', ' constructor ', 'PROTOTYPE']) {
    assertExcelImportError(
        () => hooks.buildSafeExcelRows([[dangerousName], ['polluted']]),
        'invalid_workbook',
        `${dangerousName} must be rejected as a column name.`
    );
}
assert.equal(vm.runInContext('Object.prototype.polluted', securityContext), undefined, 'Column parsing must not pollute Object.prototype.');

const ordinaryWorkbook = XLSX.utils.book_new();
XLSX.utils.book_append_sheet(ordinaryWorkbook, XLSX.utils.aoa_to_sheet([['name'], ['Alice']]), 'Unexpected but safe');
assert.equal(hooks.getValidatedExcelWorksheet(ordinaryWorkbook), ordinaryWorkbook.Sheets['Unexpected but safe']);

const dangerousWorkbook = XLSX.utils.book_new();
XLSX.utils.book_append_sheet(dangerousWorkbook, XLSX.utils.aoa_to_sheet([['name'], ['Alice']]), '__proto__');
assertExcelImportError(() => hooks.getValidatedExcelWorksheet(dangerousWorkbook), 'invalid_workbook');
assertExcelImportError(() => hooks.getValidatedExcelWorksheet({}), 'invalid_workbook');
assertExcelImportError(
    () => hooks.getValidatedExcelWorksheet(Object.create({ SheetNames: ['Inherited'], Sheets: { Inherited: {} } })),
    'invalid_workbook',
    'Workbook structure must not be accepted through inherited properties.'
);
assertExcelImportError(
    () => hooks.getValidatedExcelWorksheet({ SheetNames: ['Missing'], Sheets: {} }),
    'invalid_workbook'
);
assert.throws(
    () => XLSX.read(malformedBytes, { type: 'buffer' }),
    /ZIP|Unsupported|file/i,
    'A truncated XLSX ZIP must fail safely.'
);

function roundTrip(bookType) {
    const rows = [{ No: 1, Email: 'user@example.test', Reason: 'SMTP failure' }];
    const workbook = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(workbook, XLSX.utils.json_to_sheet(rows), 'Failed Emails');
    const output = XLSX.write(workbook, { type: 'buffer', bookType });
    return parseSafeWorkbook(output);
}

assert.deepEqual(roundTrip('xlsx').map(row => ({ ...row })), [{ No: 1, Email: 'user@example.test', Reason: 'SMTP failure' }]);
assert.deepEqual(roundTrip('xls').map(row => ({ ...row })), [{ No: 1, Email: 'user@example.test', Reason: 'SMTP failure' }]);

async function runAsyncWorkerSafetyTests() {
    let arrayBufferRead = false;
    await assert.rejects(
        hooks.readExcelRows({
            name: 'too-large.xlsx',
            size: hooks.EXCEL_IMPORT_MAX_FILE_BYTES + 1,
            async arrayBuffer() {
                arrayBufferRead = true;
                return bulkFixtureArrayBuffer;
            }
        }),
        error => error && error.excelImportCode === 'file_too_large'
    );
    assert.equal(arrayBufferRead, false, 'Oversized files must be rejected before reading their contents.');

    await assert.rejects(
        hooks.readExcelRows({
            name: 'renamed.xlsx',
            size: 16,
            async arrayBuffer() {
                return toArrayBuffer(Buffer.from('not a workbook'));
            }
        }),
        error => error && error.excelImportCode === 'invalid_signature'
    );

    securityContext.Worker = undefined;
    securityContext.Blob = function BlobStub() {};
    await assert.rejects(
        hooks.parseExcelRowsInWorker(bulkFixtureArrayBuffer),
        error => error && error.excelImportCode === 'worker_unavailable',
        'Worker-blocked browsers must fail safely without main-thread parsing.'
    );

    let terminated = 0;
    let revoked = 0;
    class HangingWorker {
        postMessage() {}
        terminate() {
            terminated += 1;
        }
    }
    class TestURL {
        constructor(relative, base) {
            this.href = new URL(relative, base).href;
        }
        static createObjectURL() {
            return 'blob:test-excel-worker';
        }
        static revokeObjectURL(value) {
            assert.equal(value, 'blob:test-excel-worker');
            revoked += 1;
        }
    }
    securityContext.Worker = HangingWorker;
    securityContext.Blob = function BlobStub(parts, options) {
        this.parts = parts;
        this.options = options;
    };
    securityContext.URL = TestURL;
    securityContext.window = { location: { href: 'http://127.0.0.1/system/html/adminpanel.html' } };
    securityContext.setTimeout = setTimeout;
    securityContext.clearTimeout = clearTimeout;

    await assert.rejects(
        hooks.parseExcelRowsInWorker(bulkFixtureArrayBuffer, { timeoutMs: 5 }),
        error => error && error.excelImportCode === 'timeout',
        'A stalled parser worker must time out with a controlled error.'
    );
    assert.equal(terminated, 1, 'Timed-out workers must be terminated.');
    assert.equal(revoked, 1, 'Timed-out worker blob URLs must be revoked.');
}

runAsyncWorkerSafetyTests().then(() => {
    console.log('SheetJS security and compatibility tests passed.');
}).catch(error => {
    console.error(error);
    process.exitCode = 1;
});
