<?php

declare(strict_types=1);

namespace LINGNER\LinTypo3Health\Log;

/**
 * Entries of level error and above from the TYPO3 log files
 * (typo3_*.log of the default FileWriter, without deprecations).
 *
 * Each file is read backwards from its end, at most MAX_BYTES, so large logs
 * stay cheap. Reading does not stop at the first entry before the requested
 * start: messages are written with their line breaks, so a message carrying
 * visitor input can contain lines that look like entries, and a forged old
 * date would otherwise hide every real entry before it. Such lines can still
 * add entries, the counts are an indicator, not proof.
 *
 * Error pages: "page" is an uncaught exception of a web request, the core
 * exception handler renders an error page instead; "content" is a content
 * element replaced by "Oops, an error occurred!", the rest of the page
 * renders. TYPO3 does not log 404 and 403.
 */
class LogFileReader
{
    public const LEVELS = ['error', 'critical', 'alert', 'emergency'];

    private const MAX_BYTES = 8 * 1024 * 1024;
    private const CHUNK_BYTES = 64 * 1024;
    // Lines can carry whole stack traces, only their beginning is parsed
    private const HEAD_BYTES = 4096;
    // FileWriter line: date('r') [LEVEL] request="..." component="...": message - {data}
    private const LINE_PATTERN = '/^(\w{3}, \d{2} \w{3} \d{4} \d{2}:\d{2}:\d{2} [+-]\d{4}) \[([A-Z]+)\] request="([^"]*)" component="([^"]*)": /';

    public function __construct(
        private readonly string $logDirectory,
    ) {}

    /**
     * Names of the readable log files, "typo3", "typo3_solr", ...
     *
     * @return list<string>
     */
    public function logs(): array
    {
        return array_values(array_map($this->logName(...), array_filter($this->files(), is_readable(...))));
    }

    /**
     * Entries since $since, newest first per file, file by file. The message
     * is raw, see LogMessageFormatter. Returns whether all files were read
     * back to $since, false when the read limit came first.
     *
     * @return \Generator<int, array{time: int, log: string, level: string, component: string, requestId: string, exception: string|null, errorPage: string|null, message: string}, mixed, bool>
     */
    public function entries(int $since): \Generator
    {
        $complete = true;
        foreach ($this->files() as $file) {
            $complete = (yield from $this->readFile($file, $since)) && $complete;
        }
        return $complete;
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $files = glob($this->logDirectory . '/typo3_*.log') ?: [];
        return array_values(array_filter(
            $files,
            static fn(string $file): bool => !str_starts_with(basename($file), 'typo3_deprecations_')
        ));
    }

    /**
     * typo3_<hash>.log is "typo3", typo3_solr_<hash>.log is "typo3_solr".
     */
    private function logName(string $file): string
    {
        return (string) preg_replace('/(_[0-9a-f]{10})?\.log$/', '', basename($file));
    }

    /**
     * Reads backwards in chunks up to the start of the file or MAX_BYTES.
     * Complete when the start of the file or an entry before $since was reached.
     *
     * @return \Generator<int, array<string, mixed>, mixed, bool>
     */
    private function readFile(string $file, int $since): \Generator
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return true;
        }

        try {
            $log = $this->logName($file);
            $size = (int) (fstat($handle)['size'] ?? 0);
            $position = $size;
            $buffer = '';
            $reachedSince = false;
            while ($position > 0 && $size - $position < self::MAX_BYTES) {
                $length = min(self::CHUNK_BYTES, $position);
                $position -= $length;
                fseek($handle, $position);
                $lines = explode("\n", (string) fread($handle, $length) . $buffer);
                // The first line may start in the previous chunk
                $buffer = $position > 0 ? (string) array_shift($lines) : '';

                foreach (array_reverse($lines) as $line) {
                    $entry = $this->parseLine($line);
                    if ($entry === null) {
                        continue;
                    }
                    if ($entry['time'] < $since) {
                        $reachedSince = true;
                        continue;
                    }
                    if (in_array($entry['level'], self::LEVELS, true)) {
                        yield ['log' => $log] + $entry;
                    }
                }
            }
            return $position === 0 || $reachedSince;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Null for lines that do not start an entry (continuations of messages
     * with line breaks).
     *
     * @return array<string, mixed>|null
     */
    private function parseLine(string $line): ?array
    {
        $head = substr($line, 0, self::HEAD_BYTES);
        if (!preg_match(self::LINE_PATTERN, $head, $matches)) {
            return null;
        }
        $time = strtotime($matches[1]);
        if ($time === false) {
            return null;
        }

        $component = $matches[4];
        $message = substr($head, strlen($matches[0]));
        // Core exception handler: "Core: Exception handler (WEB: FE): <class>, code #...",
        // other entries with an exception contain "- <short class>: <message>, in file ..."
        $isCoreHandler = preg_match('/^Core: Exception handler \((\w+): \w+\): ([\w\\\\]+),/', $message, $core) === 1;
        $exception = match (true) {
            $isCoreHandler => $core[2],
            preg_match('/- (\w+): .*, in file /', $message, $folded) === 1 => $folded[1],
            default => null,
        };

        $errorPage = match (true) {
            $isCoreHandler && $core[1] === 'WEB' => 'page',
            $component === 'TYPO3.CMS.Frontend.ContentObject.Exception.ProductionExceptionHandler' => 'content',
            default => null,
        };

        return [
            'time' => $time,
            'level' => strtolower($matches[2]),
            'component' => $component,
            'requestId' => $matches[3],
            'exception' => $exception !== null ? substr((string) strrchr('\\' . $exception, '\\'), 1) : null,
            'errorPage' => $errorPage,
            'message' => $message,
        ];
    }
}
