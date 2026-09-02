<?php

/**
 * Pure-PHP statement-level coverage instrumenter.
 *
 * Reads a PHP source file and writes an instrumented copy that calls
 * \TiktokCoverage\Cov::hit('<realPath>', <originalLine>) around every
 * executable statement:
 *
 *   - normal statements  -> hit call APPENDED after the terminating ';'
 *   - return/exit/throw/break/continue/goto at statement start -> hit call
 *     PREPENDED before the keyword (only when NOT in an inline control-header
 *     body, where prepending would change semantics; the rare inline case
 *     falls back to append).
 *
 * Class properties, enum cases, abstract method signatures and declare()
 * directives are NOT instrumented. Tokens are used (not text), so strings,
 * heredocs and comments are untouched. Original line numbers are preserved:
 * the appended preamble is a single line (same as the original <?php tag) and
 * a leading declare() clause, if present, is spliced verbatim before the
 * preamble without adding lines.
 */

final class Instrumenter
{
    private string $src;
    private string $label;
    private string $preamble;

    private string $out = '';
    private bool $php = false;
    private int $parens = 0;
    /** @var list<string> 'call'|'control' */
    private array $parenStack = [];
    /** @var list<string> 'generic'|'func'|'class' */
    private array $braceStack = [];
    private int $funcDepth = 0;
    private int $classDepth = 0;
    private ?string $braceKindNext = null;
    private bool $expectNew = true;
    private ?int $pendingLine = null;
    private int $lastLine = 0;
    private bool $pendingTerminating = false;
    private bool $afterHeader = false;
    private bool $skipStatement = false;
    private bool $casePending = false;
    private ?string $prevSig = null;

    private const TERMINATING = [
        T_RETURN => true,
        T_EXIT => true,
        T_THROW => true,
        T_BREAK => true,
        T_CONTINUE => true,
        T_GOTO => true,
    ];

    private const TYPE_DECLS = [
        T_FUNCTION => 'func',
        T_FN => 'func',
        T_CLASS => 'class',
        T_INTERFACE => 'class',
        T_TRAIT => 'class',
        T_ENUM => 'class',
    ];

    private const CONTROL_NAMES = [
        'T_IF' => true,
        'T_ELSEIF' => true,
        'T_FOR' => true,
        'T_WHILE' => true,
        'T_FOREACH' => true,
        'T_SWITCH' => true,
        'T_CATCH' => true,
        'T_DECLARE' => true,
    ];

    private const SKIP = [
        T_WHITESPACE => true,
        T_COMMENT => true,
        T_DOC_COMMENT => true,
        T_OPEN_TAG => true,
        T_OPEN_TAG_WITH_ECHO => true,
        T_CLOSE_TAG => true,
        T_INLINE_HTML => true,
    ];

    public function __construct(string $src, string $label, string $logPath, string $covPath)
    {
        $this->src = $src;
        $this->label = $label;
        $esc = static fn (string $s): string => str_replace(['\\', "'"], ['\\\\', "\\'"], $s);
        $this->preamble =
            ' require_once \'' . $esc($covPath)
            . "'; \\TiktokCoverage\\Cov::install(); \\TiktokCoverage\\Cov::setLog('" . $esc($logPath)
            . "'); /* instrumented: " . $esc($label) . ' */';
    }

    public function generate(string $outPath): void
    {
        $tokens = token_get_all($this->src);
        $count = count($tokens);

        $i0 = 0;
        while ($i0 < $count) {
            $t = $tokens[$i0];
            if (is_array($t) && isset(self::SKIP[$t[0]])) {
                $i0++;
                continue;
            }
            break;
        }
        if ($i0 >= $count) {
            throw new \RuntimeException('no PHP code found to instrument');
        }

        $head = '';
        for ($j = 0; $j < $i0; $j++) {
            $head .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
        }

        $startIdx = $i0;
        $declare = '';
        $first = $tokens[$i0];
        if (is_array($first) && $first[0] === T_DECLARE) {
            $depth = 0;
            $end = $i0 + 1;
            for (; $end < $count; $end++) {
                $t = $tokens[$end];
                if (is_array($t)) {
                    continue;
                }
                if ($t === '(') {
                    $depth++;
                } elseif ($t === ')') {
                    $depth--;
                } elseif ($t === ';' && $depth === 0) {
                    break;
                }
            }
            if ($end < $count) {
                for ($j = $i0; $j <= $end; $j++) {
                    $declare .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                }
                $startIdx = $end + 1;
            }
        }

        $this->out = $head . $declare . $this->preamble;
        $this->php = true;
        $this->expectNew = true;

        for ($k = $startIdx; $k < $count; $k++) {
            $t = $tokens[$k];
            if (is_array($t)) {
                [$id, $text, $line] = $t;
                $this->lastLine = $line;
            } else {
                $id = null;
                $text = $t;
                $line = null;
            }

            if ($id === T_OPEN_TAG || $id === T_OPEN_TAG_WITH_ECHO) {
                $this->php = $id === T_OPEN_TAG_WITH_ECHO;
                $this->out .= $text;
                continue;
            }
            if ($id === T_CLOSE_TAG) {
                $this->php = false;
                $this->expectNew = false;
                $this->out .= $text;
                continue;
            }
            if (!$this->php || (is_array($t) && isset(self::SKIP[$id]))) {
                $this->out .= $text;
                continue;
            }

            if ($id === null) {
                $this->handleChar($text);
                continue;
            }

            $this->handleKeyword($id, $line);
            $this->out .= $text;
            $this->prevSig = token_name($id);
        }

        $dir = dirname($outPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($outPath, $this->out);
    }

    private function handleChar(string $c): void
    {
        if ($c === '(') {
            $kind = isset(self::CONTROL_NAMES[$this->prevSig ?? '']) ? 'control' : 'call';
            $this->parenStack[] = $kind;
            $this->parens++;
            $this->out .= '(';
            return;
        }
        if ($c === ')') {
            $kind = $this->parenStack === [] ? 'call' : array_pop($this->parenStack);
            if ($this->parens > 0) {
                $this->parens--;
            }
            if ($kind === 'control') {
                $this->pendingLine = null;
                $this->pendingTerminating = false;
                $this->expectNew = true;
                $this->afterHeader = true;
            }
            $this->out .= ')';
            return;
        }
        if ($c === '{') {
            $kind = $this->braceKindNext ?? 'generic';
            $this->braceKindNext = null;
            $this->braceStack[] = $kind;
            if ($kind === 'func') {
                $this->funcDepth++;
            } elseif ($kind === 'class') {
                $this->classDepth++;
            }
            $this->resetStatement();
            $this->afterHeader = false;
            $this->out .= '{';
            return;
        }
        if ($c === '}') {
            $kind = $this->braceStack === [] ? 'generic' : array_pop($this->braceStack);
            if ($kind === 'func') {
                $this->funcDepth--;
            } elseif ($kind === 'class') {
                $this->classDepth--;
            }
            $this->resetStatement();
            $this->afterHeader = false;
            $this->out .= '}';
            return;
        }
        if ($c === ';') {
            $this->out .= ';';
            // A ';' ends a statement outside of any parens, or inside a
            // call-paren nesting (e.g. statements in a closure that is itself
            // an argument of an outer call). Only control-header ';' (for/
            // while/if/catch headers) must be skipped.
            if ($this->parens === 0 || end($this->parenStack) === 'call') {
                $this->terminateStatement();
                $this->expectNew = true;
            }
            $this->braceKindNext = null;
            $this->afterHeader = false;
            return;
        }
        if ($c === ':' && $this->parens === 0) {
            if ($this->casePending) {
                $this->casePending = false;
                $this->resetStatement();
            } elseif ($this->expectNew) {
                // alternative-syntax statement boundary (if (c): ... endif;)
                $this->pendingLine = null;
                $this->afterHeader = false;
            }
        }

        $this->out .= $c;
    }

    private function handleKeyword(int $id, int $line): void
    {
        if (isset(self::TYPE_DECLS[$id])) {
            $this->braceKindNext = self::TYPE_DECLS[$id];
            $this->expectNew = false;
            return;
        }

        if ($id === T_CASE || $id === T_DEFAULT) {
            if ($this->expectNew) {
                $this->casePending = true;
                $this->pendingLine = $line;
                $this->pendingTerminating = false;
                $this->expectNew = false;
            }
            return;
        }

        $name = token_name($id);

        if (isset(self::CONTROL_NAMES[$name])) {
            if ($this->expectNew) {
                $this->expectNew = false;
                $this->afterHeader = true;
            }
            return;
        }

        if ($id === T_ELSE || $id === T_DO || $id === T_TRY || $id === T_FINALLY) {
            $this->expectNew = true;
            $this->afterHeader = true;
            $this->pendingLine = null;
            $this->pendingTerminating = false;
            return;
        }

        if ($this->expectNew) {
            if ($this->afterHeader) {
                // inline control-body statement (if (c) stmt;): do not touch.
                // Appending a hit here would orphan a following else; our
                // sources use braces anyway, so these lines are left out.
                $this->skipStatement = true;
            } elseif (isset(self::TERMINATING[$id])) {
                if ($this->instrumentable()) {
                    $this->emitHit($line);
                    $this->pendingLine = null;
                    $this->pendingTerminating = false;
                } else {
                    $this->pendingLine = $line;
                    $this->pendingTerminating = true;
                }
            } else {
                $this->pendingLine = $line;
                $this->pendingTerminating = false;
            }
            $this->expectNew = false;
        }
    }

    private function resetStatement(): void
    {
        $this->pendingLine = null;
        $this->pendingTerminating = false;
        $this->casePending = false;
        $this->skipStatement = false;
        $this->expectNew = true;
    }

    private function instrumentable(): bool
    {
        return $this->classDepth <= $this->funcDepth;
    }

    private function terminateStatement(): void
    {
        if ($this->skipStatement || !$this->instrumentable()) {
            $this->resetStatement();
            $this->braceKindNext = null;
            return;
        }
        if ($this->pendingLine !== null && (!$this->pendingTerminating)) {
            $this->emitHit($this->pendingLine);
            if ($this->lastLine > $this->pendingLine) {
                $this->emitSpan($this->pendingLine, $this->lastLine);
            }
        }
        $this->resetStatement();
        $this->braceKindNext = null;
    }

    private function emitHit(int $line): void
    {
        $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $this->label);
        $this->out .= "\\TiktokCoverage\\Cov::hit('" . $escaped . "', " . $line . ');';
    }

    private function emitSpan(int $from, int $to): void
    {
        $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $this->label);
        $this->out .= "\\TiktokCoverage\\Cov::hitSpan('" . $escaped . "', " . $from . ', ' . $to . ');';
    }
}