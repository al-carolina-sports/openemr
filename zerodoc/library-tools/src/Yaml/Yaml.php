<?php
declare(strict_types=1);

namespace ZeroDoc\LibraryTools\Yaml;

/**
 * Dependency-free YAML reader/writer for the subset documented in FORMAT.md.
 *
 * Why not a library: the validators must be runnable by the content track on a
 * bare PHP install, with no composer step. The supported subset is deliberately
 * narrow and is exactly what FORMAT.md tells transcribers to write:
 *
 *   - block mappings and block sequences, indentation-nested
 *   - "- key: value" sequence items that open a mapping
 *   - plain, 'single' and "double" quoted scalars
 *   - flow sequences [a, b] and flow mappings {a: b}
 *   - block scalars  |  |-  >  >-
 *   - comments, leading "---", blank lines
 *   - true/false/null/~/numbers coerced; everything else stays a string
 *
 * Not supported (and rejected with a line number rather than mis-parsed):
 *   anchors/aliases, tags, multiple documents, complex keys, flow nesting
 *   deeper than one level.
 */
final class Yaml
{
    public static function parseFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new YamlException("Cannot read file: {$path}");
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new YamlException("Cannot read file: {$path}");
        }
        // A .json file is accepted too - same object model.
        if (preg_match('/\.json$/i', $path)) {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw new YamlException("Invalid JSON in {$path}: " . json_last_error_msg());
            }
            return $decoded;
        }
        try {
            return self::parse($raw);
        } catch (YamlException $e) {
            throw new YamlException(basename($path) . ': ' . $e->getMessage());
        }
    }

    public static function parse(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        if (str_contains($text, "\t")) {
            $lines = explode("\n", $text);
            foreach ($lines as $i => $l) {
                if (preg_match('/^\s*\t/', $l)) {
                    throw new YamlException('line ' . ($i + 1) . ': tab used for indentation; YAML requires spaces');
                }
            }
        }
        $lines = self::tokenise($text);
        if ($lines === []) {
            return [];
        }
        $pos = 0;
        $result = self::parseBlock($lines, $pos, $lines[0]['indent']);
        if (!is_array($result)) {
            throw new YamlException('top level of the file must be a mapping (key: value), not a bare scalar');
        }
        return $result;
    }

    /** @return list<array{indent:int,text:string,line:int}> */
    private static function tokenise(string $text): array
    {
        $out = [];
        foreach (explode("\n", $text) as $i => $line) {
            $lineNo = $i + 1;
            if (trim($line) === '' || trim($line) === '---') {
                continue;
            }
            $trimmedStart = ltrim($line, ' ');
            if (str_starts_with($trimmedStart, '#')) {
                continue;
            }
            if (preg_match('/^(\s*)(.*)$/', $line, $m) !== 1) {
                continue;
            }
            $indent = strlen($m[1]);
            $text   = rtrim(self::stripComment($m[2]));
            if ($text === '') {
                continue;
            }
            $out[] = ['indent' => $indent, 'text' => $text, 'line' => $lineNo];
        }
        return $out;
    }

    /**
     * Removes a trailing comment: a "#" that is unquoted and either opens the
     * text or follows whitespace. A "#" inside a quoted string, or inside a word
     * such as "level #3", is left alone.
     */
    private static function stripComment(string $text): string
    {
        $quote = null;
        $len   = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $c = $text[$i];
            if ($quote !== null) {
                if ($c === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($c === '"' || $c === "'") {
                $quote = $c;
                continue;
            }
            if ($c === '#' && ($i === 0 || $text[$i - 1] === ' ' || $text[$i - 1] === "\t")) {
                return substr($text, 0, $i);
            }
        }
        return $text;
    }

    /**
     * @param list<array{indent:int,text:string,line:int}> $lines
     * @return array<string,mixed>|list<mixed>
     */
    private static function parseBlock(array $lines, int &$pos, int $indent): array
    {
        $count = count($lines);
        if ($pos >= $count) {
            return [];
        }
        $isSeq = str_starts_with($lines[$pos]['text'], '- ') || $lines[$pos]['text'] === '-';
        return $isSeq
            ? self::parseSequence($lines, $pos, $indent)
            : self::parseMapping($lines, $pos, $indent);
    }

    /** @return list<mixed> */
    private static function parseSequence(array $lines, int &$pos, int $indent): array
    {
        $out = [];
        $count = count($lines);
        while ($pos < $count) {
            $cur = $lines[$pos];
            if ($cur['indent'] < $indent) {
                break;
            }
            if ($cur['indent'] > $indent) {
                throw new YamlException("line {$cur['line']}: unexpected indentation inside a list");
            }
            if (!str_starts_with($cur['text'], '- ') && $cur['text'] !== '-') {
                break;
            }
            $inline = $cur['text'] === '-' ? '' : trim(substr($cur['text'], 2));
            $pos++;
            if ($inline === '') {
                // Nested block belongs to this item.
                if ($pos < $count && $lines[$pos]['indent'] > $indent) {
                    $out[] = self::parseBlock($lines, $pos, $lines[$pos]['indent']);
                } else {
                    $out[] = null;
                }
                continue;
            }
            if (self::looksLikeKey($inline)) {
                // "- key: value" opens a mapping whose further keys are indented
                // to the column of the key itself.
                $childIndent = $indent + 2;
                $synthetic = [['indent' => $childIndent, 'text' => $inline, 'line' => $cur['line']]];
                $j = $pos;
                while ($j < $count && $lines[$j]['indent'] >= $childIndent) {
                    $synthetic[] = $lines[$j];
                    $j++;
                }
                $sPos = 0;
                $out[] = self::parseMapping($synthetic, $sPos, $childIndent);
                $pos = $j;
                continue;
            }
            $out[] = self::scalar($inline, $cur['line']);
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function parseMapping(array $lines, int &$pos, int $indent): array
    {
        $out = [];
        $count = count($lines);
        while ($pos < $count) {
            $cur = $lines[$pos];
            if ($cur['indent'] < $indent) {
                break;
            }
            if ($cur['indent'] > $indent) {
                throw new YamlException("line {$cur['line']}: unexpected indentation; expected a key at column " . ($indent + 1));
            }
            if (str_starts_with($cur['text'], '- ')) {
                break;
            }
            [$key, $rest] = self::splitKey($cur['text'], $cur['line']);
            if (array_key_exists($key, $out)) {
                throw new YamlException("line {$cur['line']}: duplicate key \"{$key}\" in the same mapping");
            }
            $pos++;
            if ($rest === '' ) {
                if ($pos < $count && $lines[$pos]['indent'] > $indent) {
                    $out[$key] = self::parseBlock($lines, $pos, $lines[$pos]['indent']);
                } elseif ($pos < $count && $lines[$pos]['indent'] === $indent
                          && (str_starts_with($lines[$pos]['text'], '- ') || $lines[$pos]['text'] === '-')) {
                    // list at the same indentation as its key
                    $out[$key] = self::parseSequence($lines, $pos, $indent);
                } else {
                    $out[$key] = null;
                }
                continue;
            }
            if ($rest === '|' || $rest === '|-' || $rest === '>' || $rest === '>-'
                || $rest === '|+' || $rest === '>+') {
                $out[$key] = self::blockScalar($lines, $pos, $indent, $rest);
                continue;
            }
            $out[$key] = self::scalar($rest, $cur['line']);
        }
        return $out;
    }

    private static function blockScalar(array $lines, int &$pos, int $indent, string $style): string
    {
        $count = count($lines);
        $parts = [];
        $base = null;
        while ($pos < $count && $lines[$pos]['indent'] > $indent) {
            $base ??= $lines[$pos]['indent'];
            $pad = max(0, $lines[$pos]['indent'] - $base);
            $parts[] = str_repeat(' ', $pad) . $lines[$pos]['text'];
            $pos++;
        }
        $fold = str_starts_with($style, '>');
        $text = $fold ? implode(' ', $parts) : implode("\n", $parts);
        if (str_ends_with($style, '-')) {
            return $text;
        }
        return $text;
    }

    private static function looksLikeKey(string $s): bool
    {
        if (str_starts_with($s, '[') || str_starts_with($s, '{')) {
            return false;
        }
        return preg_match('/^(?:"[^"]*"|\'[^\']*\'|[^:#\[\]{}]+):(\s|$)/', $s) === 1;
    }

    /** @return array{0:string,1:string} */
    private static function splitKey(string $text, int $line): array
    {
        if (preg_match('/^"([^"]*)"\s*:\s*(.*)$/', $text, $m) === 1) {
            return [$m[1], trim($m[2])];
        }
        if (preg_match("/^'([^']*)'\s*:\s*(.*)$/", $text, $m) === 1) {
            return [$m[1], trim($m[2])];
        }
        if (preg_match('/^([^:#]+?)\s*:\s*(.*)$/', $text, $m) !== 1) {
            throw new YamlException("line {$line}: expected \"key: value\", found: " . self::clip($text));
        }
        return [trim($m[1]), trim($m[2])];
    }

    private static function scalar(string $raw, int $line): mixed
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if ($raw[0] === '"' || $raw[0] === "'") {
            return self::quoted($raw, $line);
        }
        // strip a trailing comment from a plain scalar
        if (preg_match('/^(.*?)\s+#.*$/', $raw, $m) === 1) {
            $raw = rtrim($m[1]);
        }
        if ($raw === '') {
            return null;
        }
        if ($raw[0] === '[') {
            return self::flowSeq($raw, $line);
        }
        if ($raw[0] === '{') {
            return self::flowMap($raw, $line);
        }
        if (str_starts_with($raw, '&') || str_starts_with($raw, '*') || str_starts_with($raw, '!')) {
            throw new YamlException("line {$line}: anchors, aliases and tags are not supported in this format");
        }
        $lower = strtolower($raw);
        return match (true) {
            $lower === 'true'  || $lower === 'yes' => true,
            $lower === 'false' || $lower === 'no'  => false,
            $lower === 'null'  || $raw === '~'     => null,
            preg_match('/^-?\d+$/', $raw) === 1    => (int) $raw,
            preg_match('/^-?\d*\.\d+$/', $raw) === 1 => (float) $raw,
            default => $raw,
        };
    }

    private static function quoted(string $raw, int $line): string
    {
        $q = $raw[0];
        if (!str_ends_with($raw, $q) || strlen($raw) < 2) {
            throw new YamlException("line {$line}: unterminated {$q}-quoted string: " . self::clip($raw));
        }
        $inner = substr($raw, 1, -1);
        if ($q === "'") {
            return str_replace("''", "'", $inner);
        }
        return str_replace(
            ['\\n', '\\t', '\\"', '\\\\'],
            ["\n", "\t", '"', '\\'],
            $inner
        );
    }

    /** @return list<mixed> */
    private static function flowSeq(string $raw, int $line): array
    {
        if (!str_ends_with($raw, ']')) {
            throw new YamlException("line {$line}: unterminated [ ... ] list");
        }
        $inner = trim(substr($raw, 1, -1));
        if ($inner === '') {
            return [];
        }
        $out = [];
        foreach (self::splitFlow($inner, $line) as $piece) {
            $out[] = self::scalar($piece, $line);
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function flowMap(string $raw, int $line): array
    {
        if (!str_ends_with($raw, '}')) {
            throw new YamlException("line {$line}: unterminated { ... } mapping");
        }
        $inner = trim(substr($raw, 1, -1));
        if ($inner === '') {
            return [];
        }
        $out = [];
        foreach (self::splitFlow($inner, $line) as $piece) {
            [$k, $v] = self::splitKey($piece, $line);
            $out[$k] = self::scalar($v, $line);
        }
        return $out;
    }

    /** @return list<string> */
    private static function splitFlow(string $inner, int $line): array
    {
        $out = [];
        $buf = '';
        $depth = 0;
        $quote = null;
        $len = strlen($inner);
        for ($i = 0; $i < $len; $i++) {
            $c = $inner[$i];
            if ($quote !== null) {
                $buf .= $c;
                if ($c === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($c === '"' || $c === "'") {
                $quote = $c;
                $buf .= $c;
                continue;
            }
            if ($c === '[' || $c === '{') {
                $depth++;
                $buf .= $c;
                continue;
            }
            if ($c === ']' || $c === '}') {
                $depth--;
                $buf .= $c;
                continue;
            }
            if ($c === ',' && $depth === 0) {
                $out[] = trim($buf);
                $buf = '';
                continue;
            }
            $buf .= $c;
        }
        if ($quote !== null) {
            throw new YamlException("line {$line}: unterminated quoted string inside a flow collection");
        }
        if (trim($buf) !== '') {
            $out[] = trim($buf);
        }
        return $out;
    }

    private static function clip(string $s): string
    {
        return strlen($s) > 60 ? substr($s, 0, 57) . '...' : $s;
    }

    // ---------------------------------------------------------------- writing

    public static function dump(mixed $value, int $indent = 0): string
    {
        if (is_array($value) && $value !== [] && array_is_list($value)) {
            $out = '';
            foreach ($value as $item) {
                $pad = str_repeat(' ', $indent);
                if (is_array($item) && $item !== []) {
                    $body = self::dump($item, $indent + 2);
                    $body = preg_replace('/^' . str_repeat(' ', $indent + 2) . '/', '', $body, 1);
                    $out .= $pad . '- ' . ltrim($body);
                } else {
                    $out .= $pad . '- ' . self::scalarOut($item) . "\n";
                }
            }
            return $out;
        }
        if (is_array($value)) {
            $out = '';
            foreach ($value as $k => $v) {
                $pad = str_repeat(' ', $indent);
                $key = self::keyOut((string) $k);
                if (is_array($v) && $v !== []) {
                    $out .= $pad . $key . ":\n" . self::dump($v, $indent + 2);
                } elseif (is_array($v)) {
                    $out .= $pad . $key . ': []' . "\n";
                } elseif (is_string($v) && str_contains($v, "\n")) {
                    $out .= $pad . $key . ": |-\n";
                    foreach (explode("\n", $v) as $l) {
                        $out .= str_repeat(' ', $indent + 2) . $l . "\n";
                    }
                } else {
                    $out .= $pad . $key . ': ' . self::scalarOut($v) . "\n";
                }
            }
            return $out;
        }
        return str_repeat(' ', $indent) . self::scalarOut($value) . "\n";
    }

    private static function keyOut(string $k): string
    {
        return preg_match('/^[A-Za-z0-9_][A-Za-z0-9_\- ]*$/', $k) === 1 ? $k : '"' . $k . '"';
    }

    private static function scalarOut(mixed $v): string
    {
        if ($v === null) {
            return 'null';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }
        $s = (string) $v;
        if ($s === '') {
            return '""';
        }
        $needsQuote = preg_match('/^[\s]|[\s]$|^[-?:,\[\]{}#&*!|>\'"%@`]|: |:$| #/', $s) === 1
            || in_array(strtolower($s), ['true', 'false', 'null', 'yes', 'no', '~'], true)
            || preg_match('/^-?\d+(\.\d+)?$/', $s) === 1;
        if ($needsQuote) {
            return '"' . str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $s) . '"';
        }
        return $s;
    }
}
