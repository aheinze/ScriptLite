<?php

declare(strict_types=1);

namespace ScriptLite\Lexer;

use Generator;

/**
 * Zero-copy generator-based lexer.
 *
 * Architecture notes:
 * - We iterate by raw byte offset ($pos) into the source string.
 *   PHP strings are byte buffers; substr() with COW means we never allocate
 *   unless the returned slice is mutated (it won't be — Token is readonly).
 * - Yields tokens lazily via Generator so the parser never builds a full token array.
 * - Keyword lookup uses a static match-table — O(1) hash lookup at the Zend level.
 */
final class Lexer
{
    /** Pre-computed keyword map. Populated once per process via static init. */
    private const array KEYWORDS = [
        'var'       => TokenType::Var,
        'let'       => TokenType::Let,
        'const'     => TokenType::Const,
        'function'  => TokenType::Function,
        'return'    => TokenType::Return,
        'if'        => TokenType::If,
        'else'      => TokenType::Else,
        'while'     => TokenType::While,
        'for'       => TokenType::For,
        'break'     => TokenType::Break,
        'continue'  => TokenType::Continue,
        'true'      => TokenType::True,
        'false'     => TokenType::False,
        'null'      => TokenType::Null,
        'undefined' => TokenType::Undefined,
        'typeof'    => TokenType::Typeof,
        'this'      => TokenType::This,
        'new'       => TokenType::New,
        'switch'    => TokenType::Switch,
        'case'      => TokenType::Case,
        'default'   => TokenType::Default,
        'do'        => TokenType::Do,
        'try'       => TokenType::Try,
        'catch'     => TokenType::Catch,
        'throw'     => TokenType::Throw,
        'void'      => TokenType::Void,
        'delete'    => TokenType::Delete,
        'in'        => TokenType::In,
        'instanceof' => TokenType::Instanceof,
    ];

    private readonly string $src;
    private readonly int $len;
    private int $pos;
    private int $line;
    private int $col;
    private ?TokenType $lastTokenType = null;

    /** @var int[] Stack of brace depths for template literal interpolation */
    private array $templateBraceStack = [];

    public function __construct(string $source)
    {
        $this->src  = $source;
        $this->len  = strlen($source);
        $this->pos  = 0;
        $this->line = 1;
        $this->col  = 1;
    }

    /**
     * @return Generator<int, Token>
     */
    public function tokenize(): Generator
    {
        while ($this->pos < $this->len) {
            $ch = $this->src[$this->pos];

            // Skip whitespace (manually, no regex)
            if ($ch === ' ' || $ch === "\t" || $ch === "\r") {
                $this->advance();
                continue;
            }

            if ($ch === "\n") {
                $this->line++;
                $this->col = 0; // advance() will set it to 1
                $this->advance();
                continue;
            }

            // Single-line comments
            if ($ch === '/' && $this->pos + 1 < $this->len && $this->src[$this->pos + 1] === '/') {
                $this->skipLineComment();
                continue;
            }

            // Multi-line comments
            if ($ch === '/' && $this->pos + 1 < $this->len && $this->src[$this->pos + 1] === '*') {
                $this->skipBlockComment();
                continue;
            }

            // Regex literals — must be checked before operator handling
            if ($ch === '/' && $this->canBeRegex()) {
                $tok = $this->readRegex();
                $this->lastTokenType = $tok->type;
                yield $tok;
                continue;
            }

            // Numbers
            if ($ch >= '0' && $ch <= '9') {
                $tok = $this->readNumber();
                $this->lastTokenType = $tok->type;
                yield $tok;
                continue;
            }

            // Strings
            if ($ch === '"' || $ch === "'") {
                $tok = $this->readString($ch);
                $this->lastTokenType = $tok->type;
                yield $tok;
                continue;
            }

            // Template literals
            if ($ch === '`') {
                $tok = $this->readTemplateStart();
                $this->lastTokenType = $tok->type;
                yield $tok;
                continue;
            }

            // Template interpolation: } at depth 0 resumes template scanning
            if ($ch === '}' && !empty($this->templateBraceStack)) {
                $top = count($this->templateBraceStack) - 1;
                if ($this->templateBraceStack[$top] === 0) {
                    array_pop($this->templateBraceStack);
                    $tok = $this->resumeTemplate();
                    $this->lastTokenType = $tok->type;
                    yield $tok;
                    continue;
                }
                $this->templateBraceStack[$top]--;
            }

            // Track { depth for template interpolation
            if ($ch === '{' && !empty($this->templateBraceStack)) {
                $this->templateBraceStack[count($this->templateBraceStack) - 1]++;
            }

            // Identifiers / Keywords
            if ($this->isIdentStart($ch)) {
                $tok = $this->readIdentifier();
                $this->lastTokenType = $tok->type;
                yield $tok;
                continue;
            }

            // Multi-char operators & delimiters
            $tok = $this->readOperatorOrDelimiter();
            $this->lastTokenType = $tok->type;
            yield $tok;
        }

        yield new Token(TokenType::Eof, '', $this->line, $this->col);
    }

    // ──────────── Internal scanning methods ────────────

    private function advance(): void
    {
        $this->pos++;
        $this->col++;
    }

    private function peek(int $offset = 0): string
    {
        $idx = $this->pos + $offset;
        return $idx < $this->len ? $this->src[$idx] : "\0";
    }

    private function skipLineComment(): void
    {
        while ($this->pos < $this->len && $this->src[$this->pos] !== "\n") {
            $this->advance();
        }
    }

    private function skipBlockComment(): void
    {
        $this->advance(); // skip /
        $this->advance(); // skip *
        while ($this->pos < $this->len) {
            if ($this->src[$this->pos] === '*' && $this->peek(1) === '/') {
                $this->advance();
                $this->advance();
                return;
            }
            if ($this->src[$this->pos] === "\n") {
                $this->line++;
                $this->col = 0;
            }
            $this->advance();
        }
    }

    private function readNumber(): Token
    {
        $startCol = $this->col;
        $start    = $this->pos;
        $hasDot   = false;

        while ($this->pos < $this->len) {
            $c = $this->src[$this->pos];
            if ($c === '.' && !$hasDot) {
                $hasDot = true;
                $this->advance();
            } elseif ($c >= '0' && $c <= '9') {
                $this->advance();
            } else {
                break;
            }
        }

        return new Token(
            TokenType::Number,
            substr($this->src, $start, $this->pos - $start),
            $this->line,
            $startCol,
        );
    }

    private function readString(string $quote): Token
    {
        $startCol = $this->col;
        $this->advance(); // skip opening quote

        $buf = '';
        while ($this->pos < $this->len && $this->src[$this->pos] !== $quote) {
            if ($this->src[$this->pos] === '\\' && $this->pos + 1 < $this->len) {
                $this->advance(); // skip backslash
                $ch = $this->src[$this->pos];
                $buf .= match ($ch) {
                    'n' => "\n",
                    't' => "\t",
                    'r' => "\r",
                    '\\' => '\\',
                    '\'' => '\'',
                    '"' => '"',
                    '`' => '`',
                    '0' => "\0",
                    'b' => "\x08",
                    'f' => "\f",
                    'v' => "\x0B",
                    'u' => $this->readUnicodeEscape(),
                    'x' => $this->readHexEscape(),
                    default => '\\' . $ch,  // unknown escape → keep literal
                };
            } else {
                $buf .= $this->src[$this->pos];
            }
            $this->advance();
        }

        if ($this->pos < $this->len) {
            $this->advance(); // skip closing quote
        }

        return new Token(TokenType::String, $buf, $this->line, $startCol);
    }

    /**
     * Read \uXXXX or \u{XXXXX} Unicode escape sequence.
     * Cursor is on 'u', returns the decoded character, leaves cursor on last consumed char.
     */
    private function readUnicodeEscape(): string
    {
        if ($this->pos + 1 < $this->len && $this->src[$this->pos + 1] === '{') {
            // \u{XXXXX} — variable-length
            $this->advance(); // skip '{'
            $hex = '';
            while ($this->pos + 1 < $this->len && $this->src[$this->pos + 1] !== '}') {
                $this->advance();
                $hex .= $this->src[$this->pos];
            }
            if ($this->pos + 1 < $this->len) {
                $this->advance(); // skip '}'
            }
            return mb_chr((int) hexdec($hex), 'UTF-8');
        }
        // \uXXXX — exactly 4 hex digits
        $hex = '';
        for ($i = 0; $i < 4 && $this->pos + 1 < $this->len; $i++) {
            $this->advance();
            $hex .= $this->src[$this->pos];
        }
        return mb_chr((int) hexdec($hex), 'UTF-8');
    }

    /**
     * Read \xXX hex escape sequence.
     * Cursor is on 'x', returns the decoded character, leaves cursor on last consumed char.
     */
    private function readHexEscape(): string
    {
        $hex = '';
        for ($i = 0; $i < 2 && $this->pos + 1 < $this->len; $i++) {
            $this->advance();
            $hex .= $this->src[$this->pos];
        }
        return chr((int) hexdec($hex));
    }

    private function readIdentifier(): Token
    {
        $startCol = $this->col;
        $start    = $this->pos;

        while ($this->pos < $this->len && $this->isIdentPart($this->src[$this->pos])) {
            $this->advance();
        }

        $word = substr($this->src, $start, $this->pos - $start);
        $type = self::KEYWORDS[$word] ?? TokenType::Identifier;

        return new Token($type, $word, $this->line, $startCol);
    }

    private function readOperatorOrDelimiter(): Token
    {
        $line = $this->line;
        $col  = $this->col;
        $ch   = $this->src[$this->pos];
        $next = $this->peek(1);

        // Two-character operators
        $twoChar = $ch . $next;
        $token = match ($twoChar) {
            '==' => $this->twoCharThen($next, '=', TokenType::StrictEqual, '===', TokenType::EqualEqual, '=='),
            '!=' => $this->twoCharThen($next, '=', TokenType::StrictNotEqual, '!==', TokenType::NotEqual, '!='),
            default => null,
        };
        if ($token !== null) {
            return $token;
        }

        // Strict equality / inequality special handling
        if ($ch === '=' && $next === '=') {
            $this->advance();
            $this->advance();
            if ($this->pos < $this->len && $this->src[$this->pos] === '=') {
                $this->advance();
                return new Token(TokenType::StrictEqual, '===', $line, $col);
            }
            return new Token(TokenType::EqualEqual, '==', $line, $col);
        }

        if ($ch === '!' && $next === '=') {
            $this->advance();
            $this->advance();
            if ($this->pos < $this->len && $this->src[$this->pos] === '=') {
                $this->advance();
                return new Token(TokenType::StrictNotEqual, '!==', $line, $col);
            }
            return new Token(TokenType::NotEqual, '!=', $line, $col);
        }

        // ── Multi-char operators (longest match first) ──

        // >>> / >>>= / >> / >>= / >=  (must check before >= )
        if ($ch === '>' && $next === '>') {
            $this->advance();
            $this->advance();
            if ($this->pos < $this->len && $this->src[$this->pos] === '>') {
                $this->advance();
                if ($this->pos < $this->len && $this->src[$this->pos] === '=') {
                    $this->advance();
                    return new Token(TokenType::UnsignedRightShiftEqual, '>>>=', $line, $col);
                }
                return new Token(TokenType::UnsignedRightShift, '>>>', $line, $col);
            }
            if ($this->pos < $this->len && $this->src[$this->pos] === '=') {
                $this->advance();
                return new Token(TokenType::RightShiftEqual, '>>=', $line, $col);
            }
            return new Token(TokenType::RightShift, '>>', $line, $col);
        }

        if ($ch === '>' && $next === '=') {
            $this->advance();
            $this->advance();
            return new Token(TokenType::GreaterEqual, '>=', $line, $col);
        }

        // << / <<= / <=  (must check before <=)
        if ($ch === '<' && $next === '<') {
            $this->advance();
            $this->advance();
            if ($this->pos < $this->len && $this->src[$this->pos] === '=') {
                $this->advance();
                return new Token(TokenType::LeftShiftEqual, '<<=', $line, $col);
            }
            return new Token(TokenType::LeftShift, '<<', $line, $col);
        }

        if ($ch === '<' && $next === '=') {
            $this->advance();
            $this->advance();
            return new Token(TokenType::LessEqual, '<=', $line, $col);
        }

        // ** / **= / *=  (must check ** before *=)
        if ($ch === '*' && $next === '*') {
            $this->advance();
            $this->advance();
            if ($this->pos < $this->len && $this->src[$this->pos] === '=') {
                $this->advance();
                return new Token(TokenType::StarStarEqual, '**=', $line, $col);
            }
            return new Token(TokenType::StarStar, '**', $line, $col);
        }

        if ($ch === '*' && $next === '=') {
            $this->advance();
            $this->advance();
            return new Token(TokenType::StarEqual, '*=', $line, $col);
        }

        // ++ / +=  (must check ++ before +=)
        if ($ch === '+' && $next === '+') {
            $this->advance();
            $this->advance();
            return new Token(TokenType::PlusPlus, '++', $line, $col);
        }

        if ($ch === '+' && $next === '=') {
            $this->advance();
            $this->advance();
            return new Token(TokenType::PlusEqual, '+=', $line, $col);
        }

        // -- / -=  (must check -- before -=)
        if ($ch === '-' && $next === '-') {
            $this->advance();
            $this->advance();
            return new Token(TokenType::MinusMinus, '--', $line, $col);
        }

        if ($ch === '-' && $next === '=') {
            $this->advance();
            $this->advance();
            return new Token(TokenType::MinusEqual, '-=', $line, $col);
        }

        // &= / &&  (must check &= before &&)
        if ($ch === '&' && $next === '=') {
            $this->advance();
            $this->advance();
            return new Token(TokenType::AmpersandEqual, '&=', $line, $col);
        }

        if ($ch === '&' && $next === '&') {
            $this->advance();
            $this->advance();
            return new Token(TokenType::And, '&&', $line, $col);
        }

        // |= / ||  (must check |= before ||)
        if ($ch === '|' && $next === '=') {
            $this->advance();
            $this->advance();
            return new Token(TokenType::PipeEqual, '|=', $line, $col);
        }

        if ($ch === '|' && $next === '|') {
            $this->advance();
            $this->advance();
            return new Token(TokenType::Or, '||', $line, $col);
        }

        // ^=
        if ($ch === '^' && $next === '=') {
            $this->advance();
            $this->advance();
            return new Token(TokenType::CaretEqual, '^=', $line, $col);
        }

        // /=
        if ($ch === '/' && $next === '=') {
            $this->advance();
            $this->advance();
            return new Token(TokenType::SlashEqual, '/=', $line, $col);
        }

        // %=
        if ($ch === '%' && $next === '=') {
            $this->advance();
            $this->advance();
            return new Token(TokenType::PercentEqual, '%=', $line, $col);
        }

        if ($ch === '=' && $next === '>') {
            $this->advance();
            $this->advance();
            return new Token(TokenType::Arrow, '=>', $line, $col);
        }

        // ??= / ??
        if ($ch === '?' && $next === '?') {
            $this->advance();
            $this->advance();
            if ($this->pos < $this->len && $this->src[$this->pos] === '=') {
                $this->advance();
                return new Token(TokenType::NullishCoalesceEqual, '??=', $line, $col);
            }
            return new Token(TokenType::NullishCoalesce, '??', $line, $col);
        }

        // Optional chaining: ?. (but not ?.digit, which is ternary + number)
        if ($ch === '?' && $next === '.' && !($this->peek(2) >= '0' && $this->peek(2) <= '9')) {
            $this->advance();
            $this->advance();
            return new Token(TokenType::OptionalChain, '?.', $line, $col);
        }

        // Spread operator: ...
        if ($ch === '.' && $next === '.' && $this->peek(2) === '.') {
            $this->advance();
            $this->advance();
            $this->advance();
            return new Token(TokenType::Spread, '...', $line, $col);
        }

        // Single-character tokens
        $this->advance();
        $type = match ($ch) {
            '+' => TokenType::Plus,
            '-' => TokenType::Minus,
            '*' => TokenType::Star,
            '/' => TokenType::Slash,
            '%' => TokenType::Percent,
            '=' => TokenType::Equal,
            '<' => TokenType::Less,
            '>' => TokenType::Greater,
            '!' => TokenType::Not,
            '&' => TokenType::Ampersand,
            '|' => TokenType::Pipe,
            '^' => TokenType::Caret,
            '~' => TokenType::Tilde,
            '(' => TokenType::LeftParen,
            ')' => TokenType::RightParen,
            '{' => TokenType::LeftBrace,
            '}' => TokenType::RightBrace,
            '[' => TokenType::LeftBracket,
            ']' => TokenType::RightBracket,
            ';' => TokenType::Semicolon,
            ',' => TokenType::Comma,
            '.' => TokenType::Dot,
            ':' => TokenType::Colon,
            '?' => TokenType::Question,
            default => throw new LexerException("Unexpected character '{$ch}'", $line, $col),
        };

        return new Token($type, $ch, $line, $col);
    }

    /**
     * Helper for three-char operator lookahead pattern.
     * Not actually used in the main flow (replaced by inline checks), but kept for reference.
     */
    private function twoCharThen(string $next, string $third, TokenType $threeType, string $threeVal, TokenType $twoType, string $twoVal): ?Token
    {
        // This method is not called in the current flow — the inline checks above are faster.
        return null;
    }

    /**
     * Context-aware: `/` is a regex when the previous token cannot end an expression.
     */
    private function canBeRegex(): bool
    {
        if ($this->lastTokenType === null) {
            return true; // start of input
        }
        // After these tokens, `/` starts a regex:
        return match ($this->lastTokenType) {
            // Operators and keywords that precede expressions
            TokenType::Plus, TokenType::Minus, TokenType::Star, TokenType::Slash,
            TokenType::Percent, TokenType::Equal, TokenType::PlusEqual,
            TokenType::MinusEqual, TokenType::StarEqual, TokenType::SlashEqual,
            TokenType::EqualEqual, TokenType::NotEqual, TokenType::StrictEqual,
            TokenType::StrictNotEqual, TokenType::Less, TokenType::LessEqual,
            TokenType::Greater, TokenType::GreaterEqual, TokenType::And,
            TokenType::Or, TokenType::Not,
            // Delimiters that precede expressions
            TokenType::LeftParen, TokenType::LeftBracket, TokenType::LeftBrace,
            TokenType::Comma, TokenType::Colon, TokenType::Semicolon,
            TokenType::Arrow, TokenType::Question,
            // Keywords that precede expressions
            TokenType::Return, TokenType::Typeof, TokenType::New,
            TokenType::Var, TokenType::Let, TokenType::Const,
            // Template literal interpolation boundaries
            TokenType::TemplateHead, TokenType::TemplateMiddle => true,
            // After `)`, `]`, `}`, identifier, number, string, true, false, regex → division
            default => false,
        };
    }

    private function readRegex(): Token
    {
        $startCol = $this->col;
        $this->advance(); // skip opening /
        $pattern = '';

        while ($this->pos < $this->len && $this->src[$this->pos] !== '/') {
            if ($this->src[$this->pos] === '\\') {
                $pattern .= $this->src[$this->pos];
                $this->advance();
                if ($this->pos < $this->len) {
                    $pattern .= $this->src[$this->pos];
                    $this->advance();
                }
            } else {
                $pattern .= $this->src[$this->pos];
                $this->advance();
            }
        }

        if ($this->pos < $this->len) {
            $this->advance(); // skip closing /
        }

        // Read flags
        $flags = '';
        while ($this->pos < $this->len && strpos('gimsuy', $this->src[$this->pos]) !== false) {
            $flags .= $this->src[$this->pos];
            $this->advance();
        }

        return new Token(TokenType::Regex, $pattern . '|||' . $flags, $this->line, $startCol);
    }

    private function readTemplateStart(): Token
    {
        $startCol = $this->col;
        $this->advance(); // skip opening backtick

        [$text, $hasInterpolation] = $this->scanTemplateText();

        if ($hasInterpolation) {
            $this->templateBraceStack[] = 0;
            return new Token(TokenType::TemplateHead, $text, $this->line, $startCol);
        }

        // No interpolation — emit as regular string
        return new Token(TokenType::String, $text, $this->line, $startCol);
    }

    private function resumeTemplate(): Token
    {
        $startCol = $this->col;
        $this->advance(); // skip closing }

        [$text, $hasInterpolation] = $this->scanTemplateText();

        if ($hasInterpolation) {
            $this->templateBraceStack[] = 0;
            return new Token(TokenType::TemplateMiddle, $text, $this->line, $startCol);
        }

        return new Token(TokenType::TemplateTail, $text, $this->line, $startCol);
    }

    /** @return array{string, bool} [text, hasInterpolation] */
    private function scanTemplateText(): array
    {
        $text = '';
        while ($this->pos < $this->len) {
            $ch = $this->src[$this->pos];

            if ($ch === '`') {
                $this->advance(); // skip closing backtick
                return [$text, false];
            }

            if ($ch === '$' && $this->pos + 1 < $this->len && $this->src[$this->pos + 1] === '{') {
                $this->advance(); // skip $
                $this->advance(); // skip {
                return [$text, true];
            }

            if ($ch === '\\' && $this->pos + 1 < $this->len) {
                $this->advance(); // skip backslash
                $esc = $this->src[$this->pos];
                $text .= match ($esc) {
                    'n' => "\n",
                    't' => "\t",
                    'r' => "\r",
                    '\\' => '\\',
                    '\'' => '\'',
                    '"' => '"',
                    '`' => '`',
                    '$' => '$',
                    '0' => "\0",
                    'b' => "\x08",
                    'f' => "\f",
                    'v' => "\x0B",
                    'u' => $this->readUnicodeEscape(),
                    'x' => $this->readHexEscape(),
                    default => '\\' . $esc,
                };
                $this->advance();
                continue;
            }

            if ($ch === "\n") {
                $this->line++;
                $this->col = 0;
            }

            $text .= $ch;
            $this->advance();
        }

        return [$text, false];
    }

    private function isIdentStart(string $ch): bool
    {
        return ($ch >= 'a' && $ch <= 'z')
            || ($ch >= 'A' && $ch <= 'Z')
            || $ch === '_'
            || $ch === '$';
    }

    private function isIdentPart(string $ch): bool
    {
        return $this->isIdentStart($ch) || ($ch >= '0' && $ch <= '9');
    }
}
