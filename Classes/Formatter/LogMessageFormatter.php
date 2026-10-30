<?php

declare(strict_types=1);

namespace LINGNER\LinTypo3Health\Formatter;

/**
 * Cleans a log message for the health output: no appended data, stack
 * traces or personal data, paths relative to the project, limited length.
 * The full entry stays in the log file.
 */
class LogMessageFormatter
{
    private const MAX_LENGTH = 500;

    // Query strings carry tokens, signatures and API keys: "?name=" up to a space,
    // quote or fragment, in absolute and relative URLs alike
    private const QUERY_PATTERN = '~\?[\w.%\[\]-]+=[^\s#"\'<>`]*~';
    // user:password@ in URLs
    private const URL_CREDENTIALS_PATTERN = '~(\b[a-z][a-z0-9+.-]*://)[^\s/@"\'<>`]+@~i';
    private const EMAIL_PATTERN = '/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/';
    // Only server roots, so URL paths like /de/news stay
    private const SERVER_PATH_PATTERN = '#(?<![\w.:/])/(?:var|home|tmp|srv|opt|usr|etc|data|mnt|run|root|kunden|www)/[\w.@+/-]*#';
    // Candidates only, see replaceIps()
    private const IPV6_PATTERN = '/(?<![\w:])[0-9a-f]*:[0-9a-f:]*:[0-9a-f]*(?:\.\d+){0,3}(?![\w:])/i';
    private const IPV4_PATTERN = '/\b\d{1,3}(?:\.\d{1,3}){3}\b/';

    public function __construct(
        private readonly string $projectPath,
    ) {}

    public function format(string $message): string
    {
        // In this order: the exception text is compared before paths change
        $steps = [
            $this->removeData(...),
            $this->removeStackTrace(...),
            $this->removeRepeatedException(...),
            $this->shortenProjectPaths(...),
            $this->replaceServerPaths(...),
            $this->stripQueries(...),
            $this->replaceUrlCredentials(...),
            $this->replaceEmails(...),
            $this->replaceIps(...),
            trim(...),
            $this->limitLength(...),
        ];
        foreach ($steps as $step) {
            $message = $step($message);
        }
        return $message;
    }

    /**
     * The FileWriter appends the log data as " - {json}", it holds stack
     * traces and request details.
     */
    private function removeData(string $message): string
    {
        return $this->cutAt($message, ' - {"');
    }

    private function removeStackTrace(string $message): string
    {
        return $this->cutAt($message, 'Stack trace:');
    }

    /**
     * The FileWriter appends "- <class>: <exception message>, in file <file>:<line>"
     * without a space before the dash. Most messages already contain that
     * text, then only the location is kept.
     */
    private function removeRepeatedException(string $message): string
    {
        if (!preg_match('/^(.*?)(- \w+: (.+), in file (\S+))\s*$/s', $message, $matches)) {
            return $message;
        }
        [, $text, $appended, $exceptionMessage, $location] = $matches;
        $text = rtrim($text);

        if (!str_contains($text, $exceptionMessage)) {
            return $text . ' ' . $appended;
        }
        $file = explode(':', $location)[0];
        return str_contains($text, $file) ? $text : $text . ', in file ' . $location;
    }

    private function shortenProjectPaths(string $message): string
    {
        return str_replace($this->projectPath . '/', '', $message);
    }

    /**
     * Paths left after shortenProjectPaths() are outside the project.
     */
    private function replaceServerPaths(string $message): string
    {
        return (string) preg_replace(self::SERVER_PATH_PATTERN, '[path]', $message);
    }

    private function stripQueries(string $message): string
    {
        return (string) preg_replace(self::QUERY_PATTERN, '<query_stripped>', $message);
    }

    /**
     * Before replaceEmails(), which would otherwise leave "user:[email]".
     */
    private function replaceUrlCredentials(string $message): string
    {
        return (string) preg_replace(self::URL_CREDENTIALS_PATTERN, '$1[credentials]@', $message);
    }

    private function replaceEmails(string $message): string
    {
        return (string) preg_replace(self::EMAIL_PATTERN, '[email]', $message);
    }

    /**
     * The patterns find candidates, filter_var() decides, so times (10:50:48)
     * and Class::method stay. IPv6 first, it may end in an IPv4 address.
     */
    private function replaceIps(string $message): string
    {
        foreach ([self::IPV6_PATTERN, self::IPV4_PATTERN] as $pattern) {
            $message = (string) preg_replace_callback(
                $pattern,
                static fn(array $match): string => filter_var($match[0], FILTER_VALIDATE_IP) !== false ? '[ip]' : $match[0],
                $message
            );
        }
        return $message;
    }

    private function limitLength(string $message): string
    {
        if (mb_strlen($message) <= self::MAX_LENGTH) {
            return $message;
        }
        return rtrim(mb_substr($message, 0, self::MAX_LENGTH)) . ' […]';
    }

    private function cutAt(string $message, string $marker): string
    {
        $position = strpos($message, $marker);
        return $position === false ? $message : substr($message, 0, $position);
    }
}
