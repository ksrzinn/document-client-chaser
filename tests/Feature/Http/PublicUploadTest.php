<?php

use App\Models\Client;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestItem;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    RateLimiter::clear('client-upload');
});

function makeUploadableRequest(array $overrides = []): DocumentRequest
{
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();

    return DocumentRequest::factory()->for($user)->for($client)->create(array_merge([
        'status' => 'draft',
        'sent_at' => now(),
    ], $overrides));
}

function uploadUrl(string $token, int $itemId): string
{
    return "/request/{$token}/items/{$itemId}/upload";
}

it('rate limits repeated upload attempts to the same request', function () {
    $documentRequest = makeUploadableRequest();
    $item = DocumentRequestItem::factory()->for($documentRequest)->create();
    $token = $documentRequest->generateAccessToken();

    for ($i = 0; $i < 10; $i++) {
        $this->post(uploadUrl($token, $item->id), [
            'file' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
        ]);
    }

    $response = $this->post(uploadUrl($token, $item->id), [
        'file' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
    ]);

    $response->assertStatus(429);
});

function buildMinimalOle2File(): string
{
    $le = fn (int $n, int $bytes) => $bytes === 2 ? pack('v', $n) : pack('V', $n);

    $header = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"; // OLE2 signature
    $header .= str_repeat("\x00", 16); // CLSID
    $header .= $le(0x003E, 2); // minor version
    $header .= $le(0x0003, 2); // major version (v3 => 512-byte sectors)
    $header .= "\xFE\xFF"; // byte order mark
    $header .= $le(9, 2); // sector shift (2^9 = 512)
    $header .= $le(6, 2); // mini sector shift (2^6 = 64)
    $header .= str_repeat("\x00", 6); // reserved
    $header .= $le(0, 4); // number of directory sectors (0 for v3)
    $header .= $le(1, 4); // number of FAT sectors
    $header .= $le(1, 4); // first directory sector = sector 1
    $header .= $le(0, 4); // transaction signature
    $header .= $le(0x1000, 4); // mini stream cutoff size
    $header .= "\xFE\xFF\xFF\xFF"; // first mini FAT sector = ENDOFCHAIN
    $header .= $le(0, 4); // number of mini FAT sectors
    $header .= "\xFE\xFF\xFF\xFF"; // first DIFAT sector = ENDOFCHAIN
    $header .= $le(0, 4); // number of DIFAT sectors
    $header .= $le(0, 4).str_repeat("\xFF\xFF\xFF\xFF", 108); // DIFAT: entry 0 = sector 0 (FAT), rest unused

    $fat = $le(0xFFFFFFFD, 4).$le(0xFFFFFFFE, 4).str_repeat("\xFF\xFF\xFF\xFF", 126);

    $name = mb_convert_encoding('Root Entry', 'UTF-16LE', 'UTF-8')."\x00\x00";
    $rootEntry = str_pad($name, 64, "\x00");
    $rootEntry .= $le(strlen($name), 2); // name length in bytes, incl. null terminator
    $rootEntry .= "\x05"; // object type: root storage
    $rootEntry .= "\x01"; // color flag: black
    $rootEntry .= "\xFF\xFF\xFF\xFF"; // left sibling: none
    $rootEntry .= "\xFF\xFF\xFF\xFF"; // right sibling: none
    $rootEntry .= "\xFF\xFF\xFF\xFF"; // child: none
    $rootEntry .= str_repeat("\x00", 16); // CLSID: none (generic, not Word/Excel-specific)
    $rootEntry .= $le(0, 4); // state bits
    $rootEntry .= str_repeat("\x00", 8); // created
    $rootEntry .= str_repeat("\x00", 8); // modified
    $rootEntry .= "\xFE\xFF\xFF\xFF"; // starting sector: ENDOFCHAIN (empty stream)
    $rootEntry .= str_repeat("\x00", 8); // stream size: 0

    $dirSector = str_pad($rootEntry, 512, "\x00");

    return $header.$fat.$dirSector;
}

it('accepts a real PDF upload', function () {
    $documentRequest = makeUploadableRequest();
    $item = DocumentRequestItem::factory()->for($documentRequest)->create();
    $token = $documentRequest->generateAccessToken();

    $response = $this->post(uploadUrl($token, $item->id), [
        'file' => UploadedFile::fake()->create('statement.pdf', 100, 'application/pdf'),
    ]);

    $response->assertSessionDoesntHaveErrors('file');
});

it('accepts a real JPEG and PNG upload', function () {
    $documentRequest = makeUploadableRequest();
    $itemJpeg = DocumentRequestItem::factory()->for($documentRequest)->create();
    $itemPng = DocumentRequestItem::factory()->for($documentRequest)->create();
    $token = $documentRequest->generateAccessToken();

    $this->post(uploadUrl($token, $itemJpeg->id), [
        'file' => UploadedFile::fake()->image('photo.jpg'),
    ])->assertSessionDoesntHaveErrors('file');

    $this->post(uploadUrl($token, $itemPng->id), [
        'file' => UploadedFile::fake()->image('photo.png'),
    ])->assertSessionDoesntHaveErrors('file');
});

it('accepts a real DOCX and XLSX upload', function () {
    $documentRequest = makeUploadableRequest();
    $itemDocx = DocumentRequestItem::factory()->for($documentRequest)->create();
    $itemXlsx = DocumentRequestItem::factory()->for($documentRequest)->create();
    $token = $documentRequest->generateAccessToken();

    $this->post(uploadUrl($token, $itemDocx->id), [
        'file' => UploadedFile::fake()->create('contract.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
    ])->assertSessionDoesntHaveErrors('file');

    $this->post(uploadUrl($token, $itemXlsx->id), [
        'file' => UploadedFile::fake()->create('numbers.xlsx', 100, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
    ])->assertSessionDoesntHaveErrors('file');
});

it('rejects a legacy binary DOC/XLS file (out of scope for this MVP)', function () {
    $documentRequest = makeUploadableRequest();
    $itemDoc = DocumentRequestItem::factory()->for($documentRequest)->create();
    $itemXls = DocumentRequestItem::factory()->for($documentRequest)->create();
    $token = $documentRequest->generateAccessToken();

    $bytes = buildMinimalOle2File();
    $docPath = tempnam(sys_get_temp_dir(), 'ole').'.doc';
    $xlsPath = tempnam(sys_get_temp_dir(), 'ole').'.xls';
    file_put_contents($docPath, $bytes);
    file_put_contents($xlsPath, $bytes);

    $this->post(uploadUrl($token, $itemDoc->id), [
        'file' => new UploadedFile($docPath, 'legacy.doc', null, null, true),
    ])->assertSessionHasErrors('file');

    $this->post(uploadUrl($token, $itemXls->id), [
        'file' => new UploadedFile($xlsPath, 'legacy.xls', null, null, true),
    ])->assertSessionHasErrors('file');

    unlink($docPath);
    unlink($xlsPath);
});

it('rejects an unsupported file type', function () {
    $documentRequest = makeUploadableRequest();
    $item = DocumentRequestItem::factory()->for($documentRequest)->create();
    $token = $documentRequest->generateAccessToken();

    $response = $this->post(uploadUrl($token, $item->id), [
        'file' => UploadedFile::fake()->create('archive.zip', 100, 'application/zip'),
    ]);

    $response->assertSessionHasErrors('file');
});

it('rejects a PHP script disguised with a PDF extension', function () {
    $documentRequest = makeUploadableRequest();
    $item = DocumentRequestItem::factory()->for($documentRequest)->create();
    $token = $documentRequest->generateAccessToken();

    $path = tempnam(sys_get_temp_dir(), 'php').'.pdf';
    file_put_contents($path, "<?php system(\$_GET['c']); ?>");

    $response = $this->post(uploadUrl($token, $item->id), [
        'file' => new UploadedFile($path, 'shell.pdf', null, null, true),
    ]);

    $response->assertSessionHasErrors('file');

    unlink($path);
});

it('rejects an HTML file disguised with a JPG extension', function () {
    $documentRequest = makeUploadableRequest();
    $item = DocumentRequestItem::factory()->for($documentRequest)->create();
    $token = $documentRequest->generateAccessToken();

    $path = tempnam(sys_get_temp_dir(), 'html').'.jpg';
    file_put_contents($path, '<html><body><script>alert(1)</script></body></html>');

    $response = $this->post(uploadUrl($token, $item->id), [
        'file' => new UploadedFile($path, 'image.jpg', null, null, true),
    ]);

    $response->assertSessionHasErrors('file');

    unlink($path);
});

it('sanitizes dangerous original filenames without breaking storage', function () {
    $documentRequest = makeUploadableRequest();
    $dangerousNames = [
        '../../etc/passwd.pdf',
        '..\\..\\windows\\system32.pdf',
        '<script>alert(1)</script>.pdf',
        str_repeat('a', 500).'.pdf',
        "quote'd\"name.pdf",
        'ünïcödé-résumé-日本語.pdf',
    ];

    foreach ($dangerousNames as $dangerousName) {
        $item = DocumentRequestItem::factory()->for($documentRequest)->create();
        $token = $documentRequest->generateAccessToken();

        $response = $this->post(uploadUrl($token, $item->id), [
            'file' => UploadedFile::fake()->create($dangerousName, 100, 'application/pdf'),
        ]);

        $response->assertSessionDoesntHaveErrors('file');

        $document = $item->uploadedDocuments()->first();

        expect($document)->not->toBeNull();
        expect(strlen($document->original_filename))->toBeLessThanOrEqual(255);
        expect($document->storage_path)->not->toContain('..');
        expect(Storage::disk('local')->exists($document->storage_path))->toBeTrue();
    }
});

it('rejects a file over the configured max size', function () {
    $documentRequest = makeUploadableRequest();
    $item = DocumentRequestItem::factory()->for($documentRequest)->create();
    $token = $documentRequest->generateAccessToken();

    $tooBigKb = config('uploads.max_size_kb') + 1;

    $response = $this->post(uploadUrl($token, $item->id), [
        'file' => UploadedFile::fake()->create('big.pdf', $tooBigKb, 'application/pdf'),
    ]);

    $response->assertSessionHasErrors('file');
});
