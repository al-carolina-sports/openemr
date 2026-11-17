<?php
declare(strict_types=1);

namespace ZeroDoc\LibraryTools\Validation;

/**
 * One validator finding. Every field exists so the message can be acted on by
 * the person transcribing, without reading any code:
 *
 *   code    - stable identifier, e.g. SYMMETRY, DUP_CODE
 *   entry   - the offending entry, named as it appears in the file
 *   locus   - where inside the entry (element, option, item)
 *   message - what is wrong
 *   fix     - what to do about it
 */
final class Issue
{
    public const ERROR   = 'error';
    public const WARNING = 'warning';

    private function __construct(
        public readonly string $severity,
        public readonly string $code,
        public readonly string $entry,
        public readonly string $locus,
        public readonly string $message,
        public readonly string $fix = '',
        public readonly string $file = '',
    ) {
    }

    public static function error(string $code, string $entry, string $locus, string $message, string $fix = '', string $file = ''): self
    {
        return new self(self::ERROR, $code, $entry, $locus, $message, $fix, $file);
    }

    public static function warning(string $code, string $entry, string $locus, string $message, string $fix = '', string $file = ''): self
    {
        return new self(self::WARNING, $code, $entry, $locus, $message, $fix, $file);
    }

    public function isError(): bool
    {
        return $this->severity === self::ERROR;
    }

    public function format(bool $colour = false): string
    {
        $tag = $this->isError() ? 'ERROR  ' : 'WARNING';
        if ($colour) {
            $tag = $this->isError() ? "\033[31mERROR  \033[0m" : "\033[33mWARNING\033[0m";
        }
        $head = sprintf('%s [%s] %s', $tag, $this->code, $this->entry);
        if ($this->locus !== '' && $this->locus !== $this->entry) {
            $head .= ' -> ' . $this->locus;
        }
        $out = $head . "\n        " . $this->message;
        if ($this->fix !== '') {
            $out .= "\n        Fix: " . $this->fix;
        }
        if ($this->file !== '') {
            $out .= "\n        In: " . $this->file;
        }
        return $out;
    }

    public function toArray(): array
    {
        return [
            'severity' => $this->severity,
            'code'     => $this->code,
            'entry'    => $this->entry,
            'locus'    => $this->locus,
            'message'  => $this->message,
            'fix'      => $this->fix,
            'file'     => $this->file,
        ];
    }
}
