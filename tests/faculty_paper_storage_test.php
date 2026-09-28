<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

require_once __DIR__ . '/../api/state_helpers.php';
require_once __DIR__ . '/../api/faculty_pdf_helper.php';

$facultyPaperStorageAssertions = 0;

function facultyPaperStorageAssert(bool $condition, string $message): void
{
    global $facultyPaperStorageAssertions;
    $facultyPaperStorageAssertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function facultyPaperStorageExpectFailure(callable $callback, string $label): void
{
    try {
        $callback();
    } catch (NaapFacultyPaperStorageException $error) {
        facultyPaperStorageAssert(
            $error->getMessage() === 'Private faculty paper storage is unavailable.',
            $label . ' exposed an unsafe error message.'
        );
        return;
    }
    throw new RuntimeException($label . ' was accepted unexpectedly.');
}

function facultyPaperStorageRemoveTree(string $root): void
{
    if (!is_dir($root)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isLink() || $item->isFile()) {
            @unlink($item->getPathname());
        } elseif ($item->isDir()) {
            @rmdir($item->getPathname());
        }
    }
    @rmdir($root);
}

function facultyPaperStorageCreateDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec(
        'CREATE TABLE faculty_acknowledgement_papers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            latest_file_path TEXT NOT NULL DEFAULT \'\',
            pdf_versions_json TEXT NULL
        )'
    );
    return $pdo;
}

$temporaryBase = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'naap-faculty-paper-test-' . bin2hex(random_bytes(8));
if (!mkdir($temporaryBase, 0700, true) && !is_dir($temporaryBase)) {
    throw new RuntimeException('Unable to create faculty paper test directory.');
}

$previousStorage = getenv('NAAP_FACULTY_PAPER_STORAGE_DIR');
$previousDocumentRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;

try {
    $privateRoot = $temporaryBase . DIRECTORY_SEPARATOR . 'private';
    putenv('NAAP_FACULTY_PAPER_STORAGE_DIR=' . $privateRoot);
    $_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);

    $resolvedRoot = naapFacultyPaperGetStorageRoot(true);
    facultyPaperStorageAssert(is_dir($resolvedRoot), 'Configured private storage was not created.');
    facultyPaperStorageAssert(
        !naapFacultyPaperPathIsWithin($resolvedRoot, dirname(__DIR__)),
        'Configured private storage resolved inside the application.'
    );

    facultyPaperStorageExpectFailure(
        fn () => naapValidateFacultyPaperStoragePath('relative/faculty_papers'),
        'A relative storage path'
    );
    facultyPaperStorageExpectFailure(
        fn () => naapValidateFacultyPaperStoragePath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . 'private-papers'),
        'An application-local storage path'
    );
    $webRoot = $temporaryBase . DIRECTORY_SEPARATOR . 'web';
    mkdir($webRoot, 0700, true);
    $_SERVER['DOCUMENT_ROOT'] = $webRoot;
    facultyPaperStorageExpectFailure(
        fn () => naapValidateFacultyPaperStoragePath($webRoot . DIRECTORY_SEPARATOR . 'faculty_papers'),
        'A document-root storage path'
    );
    $_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);

    $logicalPath = 'files/faculty_papers/FP-TEST/FP-TEST_main_v1_sent_20260921_120000.pdf';
    $binary = "%PDF-1.4\n" . random_bytes(256) . "\n%%EOF\n";
    $writtenPath = naapFacultyPaperAtomicWrite($logicalPath, $binary);
    facultyPaperStorageAssert(is_file($writtenPath), 'Atomic PDF write did not create the target file.');
    facultyPaperStorageAssert(
        hash_equals(hash('sha256', $binary), (string) hash_file('sha256', $writtenPath)),
        'Atomic PDF write changed the file content.'
    );
    facultyPaperStorageAssert(
        naapFacultyPaperResolvePrivateFile($logicalPath) === str_replace('\\', '/', realpath($writtenPath) ?: ''),
        'Private logical-path resolution returned the wrong file.'
    );
    facultyPaperStorageAssert(
        naapFacultyPaperAtomicWrite($logicalPath, $binary) === str_replace('\\', '/', realpath($writtenPath) ?: ''),
        'An identical atomic write was not idempotent.'
    );
    facultyPaperStorageExpectFailure(
        fn () => naapFacultyPaperAtomicWrite($logicalPath, $binary . 'changed'),
        'A conflicting atomic write'
    );

    foreach ([
        '../outside.pdf',
        'files/faculty_papers/../outside.pdf',
        'files/faculty_papers/FP-TEST/not-a-pdf.txt',
        'files/faculty_papers/FP TEST/file.pdf',
        '/absolute/file.pdf',
    ] as $invalidLogicalPath) {
        facultyPaperStorageExpectFailure(
            fn () => naapFacultyPaperLogicalPathToRelative($invalidLogicalPath),
            'Invalid logical path'
        );
    }

    $symlinkTarget = $temporaryBase . DIRECTORY_SEPARATOR . 'symlink-target';
    $symlinkParent = $privateRoot . DIRECTORY_SEPARATOR . 'LINK-TEST';
    mkdir($symlinkTarget, 0700, true);
    if (@symlink($symlinkTarget, $symlinkParent)) {
        facultyPaperStorageExpectFailure(
            fn () => naapFacultyPaperPrivatePathForLogicalPath(
                'files/faculty_papers/LINK-TEST/escape.pdf',
                $privateRoot
            ),
            'A symlink escape path'
        );
    }

    $pdo = facultyPaperStorageCreateDatabase();
    $legacyRoot = $temporaryBase . DIRECTORY_SEPARATOR . 'legacy';
    $migrationRoot = $temporaryBase . DIRECTORY_SEPARATOR . 'migrated-private';
    $referencedRelative = 'FP-REF/referenced.pdf';
    $orphanRelative = 'FP-ORPHAN/orphan.pdf';
    mkdir($legacyRoot . DIRECTORY_SEPARATOR . 'FP-REF', 0700, true);
    mkdir($legacyRoot . DIRECTORY_SEPARATOR . 'FP-ORPHAN', 0700, true);
    file_put_contents($legacyRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $referencedRelative), "%PDF referenced\n");
    file_put_contents($legacyRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $orphanRelative), "%PDF orphan\n");
    $referencedLogical = naapFacultyPaperLogicalPrefix() . $referencedRelative;
    $insert = $pdo->prepare(
        'INSERT INTO faculty_acknowledgement_papers (latest_file_path, pdf_versions_json)
         VALUES (:latest, :versions)'
    );
    $insert->execute([
        ':latest' => $referencedLogical,
        ':versions' => json_encode([['version_no' => 1, 'file_path' => $referencedLogical]]),
    ]);

    facultyPaperStorageAssert(
        naapFacultyPaperStorageMigrationPending($pdo, $legacyRoot, $migrationRoot),
        'Legacy PDFs did not mark the private-storage migration pending.'
    );
    migrateNaapFacultyPaperStorage($pdo, $legacyRoot, $migrationRoot);
    facultyPaperStorageAssert(
        count(naapFacultyPaperEnumerateLegacyPdfs($legacyRoot)) === 0,
        'The migration left PDFs in the public legacy directory.'
    );
    facultyPaperStorageAssert(
        is_file(naapFacultyPaperResolvePrivateFile($referencedLogical, $migrationRoot)),
        'The referenced PDF was not migrated.'
    );
    facultyPaperStorageAssert(
        is_file(naapFacultyPaperResolvePrivateFile(naapFacultyPaperLogicalPrefix() . $orphanRelative, $migrationRoot)),
        'The unreferenced PDF was not preserved.'
    );
    facultyPaperStorageAssert(
        !naapFacultyPaperStorageMigrationPending($pdo, $legacyRoot, $migrationRoot),
        'The completed migration still reports pending.'
    );
    migrateNaapFacultyPaperStorage($pdo, $legacyRoot, $migrationRoot);
    facultyPaperStorageAssert(
        !naapFacultyPaperStorageMigrationPending($pdo, $legacyRoot, $migrationRoot),
        'Repeated migration execution was not idempotent.'
    );

    mkdir($legacyRoot . DIRECTORY_SEPARATOR . 'FP-REF', 0700, true);
    $conflictingSource = $legacyRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $referencedRelative);
    file_put_contents($conflictingSource, "%PDF conflicting\n");
    facultyPaperStorageExpectFailure(
        fn () => migrateNaapFacultyPaperStorage($pdo, $legacyRoot, $migrationRoot),
        'A conflicting migration destination'
    );
    facultyPaperStorageAssert(is_file($conflictingSource), 'A migration conflict deleted the source file.');
    facultyPaperStorageAssert(
        file_get_contents(naapFacultyPaperResolvePrivateFile($referencedLogical, $migrationRoot)) === "%PDF referenced\n",
        'A migration conflict overwrote the verified private file.'
    );
    file_put_contents($conflictingSource, "%PDF referenced\n");
    migrateNaapFacultyPaperStorage($pdo, $legacyRoot, $migrationRoot);
    facultyPaperStorageAssert(!is_file($conflictingSource), 'An identical migrated source was not cleaned up.');

    $pdo->exec("UPDATE faculty_acknowledgement_papers SET latest_file_path = 'files/faculty_papers/FP-MISSING/missing.pdf', pdf_versions_json = '[]'");
    facultyPaperStorageAssert(
        naapFacultyPaperStorageMigrationPending($pdo, $legacyRoot, $migrationRoot),
        'A missing referenced private PDF did not mark the migration pending.'
    );
    facultyPaperStorageExpectFailure(
        fn () => migrateNaapFacultyPaperStorage($pdo, $legacyRoot, $migrationRoot),
        'A migration with a missing referenced PDF'
    );

    $paper = [
        'professor_user_id' => 'u10',
        'recipient_role' => 'procoor',
        'recipient_user_id' => 'u20',
        'department' => 'ICS',
        'status' => 'sent',
    ];
    facultyPaperStorageAssert(facultyPdfCanAccessStoredFile($paper, 'professor', 'u10'), 'The owning professor was denied.');
    facultyPaperStorageAssert(!facultyPdfCanAccessStoredFile($paper, 'professor', 'u11'), 'A different professor was allowed.');
    facultyPaperStorageAssert(facultyPdfCanAccessStoredFile($paper, 'procoor', 'u20'), 'The assigned coordinator was denied.');
    facultyPaperStorageAssert(!facultyPdfCanAccessStoredFile($paper, 'procoor', 'u21'), 'A different coordinator was allowed.');
    facultyPaperStorageAssert(
        facultyPdfCanAccessStoredFile($paper, 'dean', 'u30', ['department' => 'ICS']),
        'The matching dean was denied.'
    );
    facultyPaperStorageAssert(
        !facultyPdfCanAccessStoredFile($paper, 'dean', 'u31', ['department' => 'ILAS']),
        'A dean from another department was allowed.'
    );
    facultyPaperStorageAssert(facultyPdfCanAccessStoredFile($paper, 'hr', 'u40'), 'HR was denied.');
    facultyPaperStorageAssert(!facultyPdfCanAccessStoredFile($paper, 'student', 'u50'), 'A student was allowed.');

    putenv('NAAP_FACULTY_PAPER_STORAGE_DIR');
    $defaultPath = naapDefaultFacultyPaperStoragePath();
    facultyPaperStorageAssert($defaultPath !== '', 'A conventional deployment path was not detected.');
    facultyPaperStorageAssert(
        !naapFacultyPaperPathIsWithin($defaultPath, dirname(__DIR__)),
        'The conventional deployment path is inside the application.'
    );
} finally {
    if ($previousStorage === false) {
        putenv('NAAP_FACULTY_PAPER_STORAGE_DIR');
    } else {
        putenv('NAAP_FACULTY_PAPER_STORAGE_DIR=' . $previousStorage);
    }
    if ($previousDocumentRoot === null) {
        unset($_SERVER['DOCUMENT_ROOT']);
    } else {
        $_SERVER['DOCUMENT_ROOT'] = $previousDocumentRoot;
    }
    facultyPaperStorageRemoveTree($temporaryBase);
}

echo 'Faculty paper storage tests passed (' . $facultyPaperStorageAssertions . " assertions).\n";
