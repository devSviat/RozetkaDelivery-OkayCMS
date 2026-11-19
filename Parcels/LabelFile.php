<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Parcels;

/**
 * Файл для завантаження: один PDF як є, кілька — одним ZIP. Браузеру віддається лише те, що пройшло pdf():
 * SVG чи HTML з API на домені адмінки виконали б свій скрипт.
 */
final readonly class LabelFile
{
    public const int MAX_BYTES = 20 * 1024 * 1024;

    public function __construct(
        public string $bytes,
        public string $mime,
        public string $filename,
    ) {
    }

    /** Байти PDF із base64 відповіді або null: не base64, не PDF чи понад ліміт. */
    public static function pdf(mixed $base64): ?string
    {
        if (!is_string($base64) || $base64 === '' || strlen($base64) > intdiv(self::MAX_BYTES + 2, 3) * 4 + 4) {
            return null;
        }
        $bytes = base64_decode($base64, true);

        return is_string($bytes) && strlen($bytes) <= self::MAX_BYTES && str_starts_with($bytes, '%PDF-') ? $bytes : null;
    }

    /** Що прийшло замість PDF, для логу: лише вид і розмір, без вмісту. */
    public static function describe(mixed $base64): string
    {
        $bytes = is_string($base64) ? base64_decode(substr($base64, 0, 400), true) : false;
        $head = is_string($bytes) ? strtolower(ltrim($bytes)) : '';
        $kind = match (true) {
            !is_string($base64) => get_debug_type($base64),
            $bytes === false => 'not-base64',
            str_starts_with($head, '<svg') || (str_starts_with($head, '<?xml') && str_contains($head, '<svg')) => 'svg',
            str_starts_with($head, '<') => 'html',
            default => 'other',
        };

        return "kind={$kind} bytes=" . (is_string($base64) ? strlen($base64) : 0);
    }

    /**
     * Браузер бере з відповіді форми один файл, а злити PDF без бібліотеки не можна, тож кілька PDF ідуть одним ZIP
     * (ext-zip вимагає composer.json).
     *
     * @param list<string> $pdfs
     * @param string $name ім'я файлу без розширення
     */
    public static function fromPdfs(array $pdfs, string $name): self
    {
        $name = (string)preg_replace('/[^\w-]/', '', $name);
        if (count($pdfs) === 1) {
            return new self($pdfs[0], 'application/pdf', "{$name}.pdf");
        }
        if ($pdfs === []) {
            throw new \InvalidArgumentException('No PDF to pack');
        }
        $path = tempnam(sys_get_temp_dir(), 'rozetka-labels');
        if ($path === false) {
            throw new \RuntimeException('Cannot create a temporary file for labels');
        }
        try {
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Cannot create a ZIP of labels');
            }
            foreach ($pdfs as $index => $pdf) {
                if (!$zip->addFromString("{$name}-" . ($index + 1) . '.pdf', $pdf)) {
                    throw new \RuntimeException('Cannot add a label to the ZIP');
                }
            }
            // close() записує архів на диск: без перевірки порожній чи обірваний ZIP пішов би менеджеру.
            if (!$zip->close()) {
                throw new \RuntimeException('Cannot write the ZIP of labels');
            }
            $bytes = file_get_contents($path);
            if ($bytes === false || $bytes === '') {
                throw new \RuntimeException('Cannot read the ZIP of labels');
            }
        } finally {
            @unlink($path);
        }

        return new self($bytes, 'application/zip', "{$name}.zip");
    }
}
