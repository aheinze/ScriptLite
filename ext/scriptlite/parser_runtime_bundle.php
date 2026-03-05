declare(strict_types=1);

namespace ScriptLite\Lexer;

/**
 * Backed enum for token types. Integer-backed for fast switch/match in the VM hot loop.
 * Grouped by category for cache-line locality when the Zend Engine handles match arms.
 */
enum TokenType: int
{
    // Literals (0-9)
    case Number = 0;
    case String = 1;
    case True = 2;
    case False = 3;
    case Null = 4;
    case Undefined = 5;
    case Regex = 6;
    case TemplateHead = 7;     // `text${
    case TemplateMiddle = 8;   // }text${
    case TemplateTail = 9;     // }text`

    // Identifiers & Keywords (10-29)
    case Identifier = 10;
    case Var = 11;
    case Let = 12;
    case Const = 13;
    case Function = 14;
    case Return = 15;
    case If = 16;
    case Else = 17;
    case While = 18;
    case For = 19;
    case Break = 20;
    case Continue = 21;
    case Typeof = 22;
    case This = 23;
    case New = 24;
    case Switch = 25;
    case Case = 26;
    case Default = 27;
    case Do = 28;
    case Try = 29;
    case Catch = 84;    // keyword but placed in delimiter range (keyword range full)
    case Throw = 85;
    case Spread = 86;   // ...

    // Operators — Arithmetic (30-39)
    case Plus = 30;
    case Minus = 31;
    case Star = 32;
    case Slash = 33;
    case Percent = 34;
    case PlusPlus = 35;          // ++
    case MinusMinus = 36;        // --
    case StarStar = 37;          // **

    // Operators — Comparison (40-49)
    case EqualEqual = 40;
    case NotEqual = 41;
    case StrictEqual = 42;
    case StrictNotEqual = 43;
    case Less = 44;
    case LessEqual = 45;
    case Greater = 46;
    case GreaterEqual = 47;

    // Operators — Logical & Bitwise (50-59)
    case And = 50;
    case Or = 51;
    case Not = 52;
    case Ampersand = 53;         // &
    case Pipe = 54;              // |
    case Caret = 55;             // ^
    case Tilde = 56;             // ~
    case LeftShift = 57;         // <<
    case RightShift = 58;        // >>
    case UnsignedRightShift = 59; // >>>

    // Operators — Assignment (60-69, 87-92)
    case Equal = 60;
    case PlusEqual = 61;
    case MinusEqual = 62;
    case StarEqual = 63;
    case SlashEqual = 64;
    case StarStarEqual = 65;     // **=
    case PercentEqual = 66;      // %=
    case NullishCoalesceEqual = 67; // ??=
    case AmpersandEqual = 68;    // &=
    case PipeEqual = 87;         // |=
    case CaretEqual = 88;        // ^=
    case LeftShiftEqual = 89;    // <<=
    case RightShiftEqual = 91;   // >>=
    case UnsignedRightShiftEqual = 92; // >>>=

    // Delimiters (70-89)
    case LeftParen = 70;
    case RightParen = 71;
    case LeftBrace = 72;
    case RightBrace = 73;
    case LeftBracket = 74;
    case RightBracket = 75;
    case Semicolon = 76;
    case Comma = 77;
    case Dot = 78;
    case Arrow = 79;   // =>
    case Colon = 80;
    case Question = 81;  // ?
    case OptionalChain = 82;  // ?.
    case NullishCoalesce = 83;  // ??

    // Keywords — operators (93-96)
    case Void = 93;
    case Delete = 94;
    case In = 95;
    case Instanceof = 96;

    // Special
    case Eof = 90;
}

namespace ScriptLite\Lexer;

/**
 * Immutable token. Uses readonly to avoid mutation bugs and lets PHP intern the class layout.
 * The $value is a string slice reference — we never copy substrings during lexing if the source
 * string stays alive (PHP's copy-on-write handles this at the zval level).
 */
final readonly class Token
{
    public function __construct(
        public TokenType $type,
        public string    $value,
        public int       $line,
        public int       $col,
    ) {}
}

namespace ScriptLite\Lexer;

use RuntimeException;

final class LexerException extends RuntimeException
{
    public function __construct(string $message, public readonly int $sourceLine, public readonly int $sourceCol)
    {
        parent::__construct("{$message} at line {$sourceLine}, col {$sourceCol}");
    }
}

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

namespace ScriptLite\Ast;

/**
 * Marker interface for all AST nodes.
 * We use an interface (not abstract class) to keep the node hierarchy flat —
 * PHP's instanceof checks on interfaces are very fast in the Zend Engine.
 */
interface Node {}

namespace ScriptLite\Ast;

/** Marker for expression nodes */
interface Expr extends Node {}

namespace ScriptLite\Ast;

/** Marker for statement nodes */
interface Stmt extends Node {}

namespace ScriptLite\Ast;

use ScriptLite\Lexer\Token;
use RuntimeException;

final class ParserException extends RuntimeException
{
    public function __construct(string $message, ?Token $token = null)
    {
        $loc = $token ? " at line {$token->line}, col {$token->col}" : '';
        parent::__construct($message . $loc);
    }
}

namespace ScriptLite\Ast;

final readonly class Program implements Node
{
    /** @param Stmt[] $body */
    public function __construct(public array $body) {}
}

namespace ScriptLite\Ast;

enum VarKind: string
{
    case Var = 'var';
    case Let = 'let';
    case Const = 'const';
}

namespace ScriptLite\Ast;

final readonly class Identifier implements Expr
{
    public function __construct(public string $name) {}
}

namespace ScriptLite\Ast;

final readonly class NumberLiteral implements Expr
{
    public function __construct(public float $value) {}
}

namespace ScriptLite\Ast;

final readonly class StringLiteral implements Expr
{
    public function __construct(public string $value) {}
}

namespace ScriptLite\Ast;

final readonly class BooleanLiteral implements Expr
{
    public function __construct(public bool $value) {}
}

namespace ScriptLite\Ast;

final readonly class NullLiteral implements Expr {}

namespace ScriptLite\Ast;

final readonly class UndefinedLiteral implements Expr {}

namespace ScriptLite\Ast;

final readonly class ObjectProperty
{
    public function __construct(
        public ?string $key,
        public Expr $value,
        public bool $computed = false,
        public ?Expr $computedKey = null,
    ) {}
}

namespace ScriptLite\Ast;

/**
 * Represents a spread element: `...expr`
 * Used in array literals, call arguments, and new arguments.
 */
final readonly class SpreadElement implements Expr
{
    public function __construct(
        public Expr $argument,
    ) {}
}

namespace ScriptLite\Ast;

final readonly class BinaryExpr implements Expr
{
    public function __construct(
        public Expr   $left,
        public string $operator,
        public Expr   $right,
    ) {}
}

namespace ScriptLite\Ast;

final readonly class UnaryExpr implements Expr
{
    public function __construct(
        public string $operator,
        public Expr   $operand,
    ) {}
}

namespace ScriptLite\Ast;

final readonly class UpdateExpr implements Expr
{
    public function __construct(
        public string $operator,  // '++' or '--'
        public Expr   $argument,  // Identifier or MemberExpr
        public bool   $prefix,    // true = ++x, false = x++
    ) {}
}

namespace ScriptLite\Ast;

final readonly class AssignExpr implements Expr
{
    public function __construct(
        public string $name,
        public string $operator, // '=', '+=', '-=', '*=', '/='
        public Expr   $value,
    ) {}
}

namespace ScriptLite\Ast;

final readonly class LogicalExpr implements Expr
{
    public function __construct(
        public Expr   $left,
        public string $operator, // '&&' or '||'
        public Expr   $right,
    ) {}
}

namespace ScriptLite\Ast;

final readonly class ConditionalExpr implements Expr
{
    public function __construct(
        public Expr $condition,
        public Expr $consequent,
        public Expr $alternate,
    ) {}
}

namespace ScriptLite\Ast;

final readonly class CallExpr implements Expr
{
    /**
     * @param Expr   $callee
     * @param Expr[] $arguments
     */
    public function __construct(
        public Expr  $callee,
        public array $arguments,
        public bool  $optional = false,
        public bool  $optionalChain = false,
    ) {}
}

namespace ScriptLite\Ast;

/**
 * Property access: obj.prop (computed=false) or obj[expr] (computed=true).
 */
final readonly class MemberExpr implements Expr
{
    public function __construct(
        public Expr $object,
        public Expr $property,
        public bool $computed,
        public bool $optional = false,
        public bool $optionalChain = false,
    ) {}
}

namespace ScriptLite\Ast;

/**
 * Assignment to a member expression: obj[key] = value or obj.prop = value.
 */
final readonly class MemberAssignExpr implements Expr
{
    public function __construct(
        public Expr $object,
        public Expr $property,
        public bool $computed,
        public string $operator,
        public Expr $value,
    ) {}
}

namespace ScriptLite\Ast;

/**
 * Covers both function expressions and arrow functions.
 */
final readonly class FunctionExpr implements Expr
{
    /**
     * @param string[]  $params
     * @param Stmt[]    $body
     * @param array<int, ?Expr> $defaults  Default value expressions, indexed same as $params
     * @param array<int, array{isArray: bool, bindings: array, restName: ?string}> $paramDestructures
     */
    public function __construct(
        public ?string $name,
        public array   $params,
        public array   $body,
        public bool    $isArrow = false,
        public ?string $restParam = null,
        public array   $defaults = [],
        public array   $paramDestructures = [],
    ) {}
}

namespace ScriptLite\Ast;

final readonly class FunctionDeclaration implements Stmt
{
    /**
     * @param string   $name
     * @param string[] $params
     * @param Stmt[]   $body
     * @param array<int, ?Expr> $defaults  Default value expressions, indexed same as $params
     * @param array<int, array{isArray: bool, bindings: array, restName: ?string}> $paramDestructures
     */
    public function __construct(
        public string  $name,
        public array   $params,
        public array   $body,
        public ?string $restParam = null,
        public array   $defaults = [],
        public array   $paramDestructures = [],
    ) {}
}

namespace ScriptLite\Ast;

final readonly class VarDeclaration implements Stmt
{
    public function __construct(
        public VarKind $kind,
        public string  $name,
        public ?Expr   $initializer,
    ) {}
}

namespace ScriptLite\Ast;

/**
 * Multiple variable declarations sharing the same kind.
 * e.g. let a = 1, b = 2;
 */
final readonly class VarDeclarationList implements Stmt
{
    /** @param VarDeclaration[] $declarations */
    public function __construct(
        public array $declarations,
    ) {}
}

namespace ScriptLite\Ast;

/**
 * Destructuring variable declaration.
 *
 * Handles both array patterns: let [a, b] = expr
 * and object patterns: let {x, y} = expr
 */
final readonly class DestructuringDeclaration implements Stmt
{
    /**
     * @param VarKind $kind var/let/const
     * @param array<array{name: string, source: string|int, default: ?Expr}> $bindings
     * @param ?string $restName rest element name (e.g. ...rest)
     * @param Expr $initializer The right-hand side expression
     * @param bool $isArray True for array destructuring, false for object
     */
    public function __construct(
        public VarKind $kind,
        public array   $bindings,
        public ?string $restName,
        public Expr    $initializer,
        public bool    $isArray,
    ) {}
}

namespace ScriptLite\Ast;

final readonly class BlockStmt implements Stmt
{
    /** @param Stmt[] $statements */
    public function __construct(public array $statements) {}
}

namespace ScriptLite\Ast;

final readonly class ExpressionStmt implements Stmt
{
    public function __construct(public Expr $expression) {}
}

namespace ScriptLite\Ast;

final readonly class ReturnStmt implements Stmt
{
    public function __construct(public ?Expr $value) {}
}

namespace ScriptLite\Ast;

final readonly class IfStmt implements Stmt
{
    public function __construct(
        public Expr  $condition,
        public Stmt  $consequent,
        public ?Stmt $alternate,
    ) {}
}

namespace ScriptLite\Ast;

final readonly class WhileStmt implements Stmt
{
    public function __construct(
        public Expr $condition,
        public Stmt $body,
    ) {}
}

namespace ScriptLite\Ast;

final readonly class DoWhileStmt implements Stmt
{
    public function __construct(
        public Expr $condition,
        public Stmt $body,
    ) {}
}

namespace ScriptLite\Ast;

final readonly class ForStmt implements Stmt
{
    public function __construct(
        public ?Node $init,       // VarDeclaration | ExpressionStmt | null
        public ?Expr $condition,
        public ?Expr $update,
        public Stmt  $body,
    ) {}
}

namespace ScriptLite\Ast;

final readonly class ForOfStmt implements Stmt
{
    public function __construct(
        public VarKind $kind,
        public string  $name,
        public Expr    $iterable,
        public Stmt    $body,
    ) {}
}

namespace ScriptLite\Ast;

final readonly class ForInStmt implements Stmt
{
    public function __construct(
        public VarKind $kind,
        public string  $name,
        public Expr    $object,
        public Stmt    $body,
    ) {}
}

namespace ScriptLite\Ast;

final readonly class SwitchStmt implements Stmt
{
    /** @param SwitchCase[] $cases */
    public function __construct(
        public Expr $discriminant,
        public array $cases,
    ) {}
}

namespace ScriptLite\Ast;

final readonly class SwitchCase implements Node
{
    /** @param Stmt[] $consequent */
    public function __construct(
        public ?Expr $test,
        public array $consequent,
    ) {}
}

namespace ScriptLite\Ast;

final readonly class TryCatchStmt implements Stmt
{
    public function __construct(
        public BlockStmt  $block,
        public ?CatchClause $handler,
        public ?BlockStmt $finalizer = null,
    ) {}
}

namespace ScriptLite\Ast;

final readonly class CatchClause implements Node
{
    public function __construct(
        public ?string $param,
        public BlockStmt $body,
    ) {}
}

namespace ScriptLite\Ast;

final readonly class BreakStmt implements Stmt {}

namespace ScriptLite\Ast;

final readonly class ContinueStmt implements Stmt {}

namespace ScriptLite\Ast;

final readonly class ThrowStmt implements Stmt
{
    public function __construct(
        public Expr $argument,
    ) {}
}

namespace ScriptLite\Ast;

final readonly class ArrayLiteral implements Expr
{
    /** @param Expr[] $elements */
    public function __construct(
        public array $elements,
    ) {}
}

namespace ScriptLite\Ast;

final readonly class ObjectLiteral implements Expr
{
    /** @param ObjectProperty[] $properties */
    public function __construct(
        public array $properties,
    ) {}
}

namespace ScriptLite\Ast;

final readonly class NewExpr implements Expr
{
    /** @param Expr[] $arguments */
    public function __construct(
        public Expr $callee,
        public array $arguments,
    ) {}
}

namespace ScriptLite\Ast;

/**
 * Template literal: `text ${expr} text`
 *
 * @property string[] $quasis      String parts (always count(expressions) + 1)
 * @property Expr[]   $expressions Interpolated expressions
 */
final readonly class TemplateLiteral implements Expr
{
    /** @param string[] $quasis @param Expr[] $expressions */
    public function __construct(
        public array $quasis,
        public array $expressions,
    ) {}
}

namespace ScriptLite\Ast;

final readonly class RegexLiteral implements Expr
{
    public function __construct(
        public string $pattern,
        public string $flags,
    ) {}
}

namespace ScriptLite\Ast;

final readonly class TypeofExpr implements Expr
{
    public function __construct(public Expr $operand) {}
}

namespace ScriptLite\Ast;

final readonly class ThisExpr implements Expr
{
}

namespace ScriptLite\Ast;

final readonly class VoidExpr implements Expr
{
    public function __construct(
        public Expr $operand,
    ) {}
}

namespace ScriptLite\Ast;

final readonly class DeleteExpr implements Expr
{
    public function __construct(
        public Expr $operand,
    ) {}
}

namespace ScriptLite\Ast;

/**
 * Comma-separated expression sequence (comma operator).
 * Evaluates all expressions left-to-right, returns the last value.
 * e.g. i++, j-- in for-loop updates
 */
final readonly class SequenceExpr implements Expr
{
    /** @param Expr[] $expressions */
    public function __construct(
        public array $expressions,
    ) {}
}

namespace ScriptLite\Ast;

use ScriptLite\Lexer\Lexer;
use ScriptLite\Lexer\Token;
use ScriptLite\Lexer\TokenType;
use Generator;

/**
 * Pratt Parser (Top-Down Operator Precedence).
 *
 * Why Pratt? It handles arbitrary precedence levels with no grammar tables,
 * and the "binding power" model maps perfectly to JS operator semantics.
 * The entire expression parser lives in parseExpression() + two lookup tables (prefix/infix).
 *
 * The parser eagerly consumes from the lexer's generator — no lookahead buffer needed
 * because we cache exactly one token ($current).
 */
final class Parser
{
    private Token $current;
    /** @var Generator<int, Token> */
    private Generator $tokens;

    public function __construct(private readonly string $source) {}

    public function parse(): Program
    {
        $lexer        = new Lexer($this->source);
        $this->tokens = $lexer->tokenize();
        $this->current = $this->tokens->current();

        $body = [];
        while ($this->current->type !== TokenType::Eof) {
            $body[] = $this->parseStatement();
        }

        return new Program($body);
    }

    // ──────────────────────── Token helpers ────────────────────────

    private function advance(): Token
    {
        $prev = $this->current;
        $this->tokens->next();
        $this->current = $this->tokens->valid() ? $this->tokens->current() : new Token(TokenType::Eof, '', $prev->line, $prev->col);
        return $prev;
    }

    private function expect(TokenType $type, string $message = ''): Token
    {
        if ($this->current->type !== $type) {
            $msg = $message ?: "Expected {$type->name}, got {$this->current->type->name} ('{$this->current->value}')";
            throw new ParserException($msg, $this->current);
        }
        return $this->advance();
    }

    private function match(TokenType $type): bool
    {
        if ($this->current->type === $type) {
            $this->advance();
            return true;
        }
        return false;
    }

    // ──────────────────────── Statements ────────────────────────

    private function parseStatement(): Stmt
    {
        return match ($this->current->type) {
            TokenType::Var, TokenType::Let, TokenType::Const => $this->parseVarDeclaration(),
            TokenType::Function => $this->parseFunctionDeclaration(),
            TokenType::Return => $this->parseReturnStatement(),
            TokenType::LeftBrace => $this->parseBlockStatement(),
            TokenType::If => $this->parseIfStatement(),
            TokenType::While => $this->parseWhileStatement(),
            TokenType::For => $this->parseForStatement(),
            TokenType::Break => $this->parseBreakStatement(),
            TokenType::Continue => $this->parseContinueStatement(),
            TokenType::Do => $this->parseDoWhileStatement(),
            TokenType::Switch => $this->parseSwitchStatement(),
            TokenType::Try => $this->parseTryStatement(),
            TokenType::Throw => $this->parseThrowStatement(),
            default => $this->parseExpressionStatement(),
        };
    }

    private function parseVarDeclaration(): VarDeclaration|DestructuringDeclaration|VarDeclarationList
    {
        $result = $this->parseVarDeclarationInner();
        $this->consumeSemicolon();
        return $result;
    }

    /**
     * Parse var/let/const declaration(s) without consuming the trailing semicolon.
     * Returns a single VarDeclaration, DestructuringDeclaration, or VarDeclarationList.
     */
    private function parseVarDeclarationInner(): VarDeclaration|DestructuringDeclaration|VarDeclarationList
    {
        $kind = match ($this->current->type) {
            TokenType::Var => VarKind::Var,
            TokenType::Let => VarKind::Let,
            TokenType::Const => VarKind::Const,
            default => throw new ParserException('Expected var/let/const', $this->current),
        };
        $this->advance();

        // Array destructuring: let [a, b] = expr
        if ($this->current->type === TokenType::LeftBracket) {
            return $this->parseArrayDestructuring($kind, false);
        }

        // Object destructuring: let {a, b} = expr
        if ($this->current->type === TokenType::LeftBrace) {
            return $this->parseObjectDestructuring($kind, false);
        }

        $name = $this->expect(TokenType::Identifier, 'Expected variable name')->value;
        $init = null;
        if ($this->match(TokenType::Equal)) {
            $init = $this->parseExpression();
        }
        $first = new VarDeclaration($kind, $name, $init);

        // Check for comma-separated additional declarations: let a = 1, b = 2
        if ($this->current->type !== TokenType::Comma) {
            return $first;
        }

        $declarations = [$first];
        while ($this->match(TokenType::Comma)) {
            $name = $this->expect(TokenType::Identifier, 'Expected variable name')->value;
            $init = null;
            if ($this->match(TokenType::Equal)) {
                $init = $this->parseExpression();
            }
            $declarations[] = new VarDeclaration($kind, $name, $init);
        }

        return new VarDeclarationList($declarations);
    }

    private function parseArrayDestructuring(VarKind $kind, bool $consumeSemi = true): DestructuringDeclaration
    {
        $this->expect(TokenType::LeftBracket);
        $bindings = [];
        $restName = null;
        $index = 0;

        while ($this->current->type !== TokenType::RightBracket && $this->current->type !== TokenType::Eof) {
            // Handle holes: [, , x]
            if ($this->current->type === TokenType::Comma) {
                $index++;
                $this->advance();
                continue;
            }

            // Rest element: [...rest]
            if ($this->current->type === TokenType::Spread) {
                $this->advance();
                $restName = $this->expect(TokenType::Identifier, 'Expected rest element name')->value;
                break;
            }

            // Nested array destructuring: [a, [b, c]]
            if ($this->current->type === TokenType::LeftBracket) {
                $nested = $this->parseNestedPattern(true);
                $default = null;
                if ($this->match(TokenType::Equal)) {
                    $default = $this->parseExpression();
                }
                $bindings[] = ['name' => null, 'source' => $index, 'default' => $default, 'nested' => $nested];
                $index++;
                if (!$this->match(TokenType::Comma)) {
                    break;
                }
                continue;
            }

            // Nested object destructuring: [a, {b, c}]
            if ($this->current->type === TokenType::LeftBrace) {
                $nested = $this->parseNestedPattern(false);
                $default = null;
                if ($this->match(TokenType::Equal)) {
                    $default = $this->parseExpression();
                }
                $bindings[] = ['name' => null, 'source' => $index, 'default' => $default, 'nested' => $nested];
                $index++;
                if (!$this->match(TokenType::Comma)) {
                    break;
                }
                continue;
            }

            $name = $this->expect(TokenType::Identifier, 'Expected variable name in destructuring')->value;
            $default = null;
            if ($this->match(TokenType::Equal)) {
                $default = $this->parseExpression();
            }
            $bindings[] = ['name' => $name, 'source' => $index, 'default' => $default];
            $index++;

            if (!$this->match(TokenType::Comma)) {
                break;
            }
        }

        $this->expect(TokenType::RightBracket);
        $this->expect(TokenType::Equal, 'Expected = after destructuring pattern');
        $initializer = $this->parseExpression();
        if ($consumeSemi) {
            $this->consumeSemicolon();
        }

        return new DestructuringDeclaration($kind, $bindings, $restName, $initializer, true);
    }

    private function parseObjectDestructuring(VarKind $kind, bool $consumeSemi = true): DestructuringDeclaration
    {
        $this->expect(TokenType::LeftBrace);
        $bindings = [];
        $restName = null;

        while ($this->current->type !== TokenType::RightBrace && $this->current->type !== TokenType::Eof) {
            // Rest element: {...rest}
            if ($this->current->type === TokenType::Spread) {
                $this->advance();
                $restName = $this->expect(TokenType::Identifier, 'Expected rest element name')->value;
                break;
            }

            $key = $this->expect(TokenType::Identifier, 'Expected property name in destructuring')->value;

            // { key: localName } or { key: { nested } } or { key: [ nested ] } or { key } (shorthand)
            $localName = $key;
            if ($this->match(TokenType::Colon)) {
                // Nested object destructuring: { key: { ... } }
                if ($this->current->type === TokenType::LeftBrace) {
                    $nested = $this->parseNestedPattern(false);
                    $default = null;
                    if ($this->match(TokenType::Equal)) {
                        $default = $this->parseExpression();
                    }
                    $bindings[] = ['name' => null, 'source' => $key, 'default' => $default, 'nested' => $nested];
                    if (!$this->match(TokenType::Comma)) {
                        break;
                    }
                    continue;
                }
                // Nested array destructuring: { key: [ ... ] }
                if ($this->current->type === TokenType::LeftBracket) {
                    $nested = $this->parseNestedPattern(true);
                    $default = null;
                    if ($this->match(TokenType::Equal)) {
                        $default = $this->parseExpression();
                    }
                    $bindings[] = ['name' => null, 'source' => $key, 'default' => $default, 'nested' => $nested];
                    if (!$this->match(TokenType::Comma)) {
                        break;
                    }
                    continue;
                }
                $localName = $this->expect(TokenType::Identifier, 'Expected variable name')->value;
            }

            $default = null;
            if ($this->match(TokenType::Equal)) {
                $default = $this->parseExpression();
            }

            $bindings[] = ['name' => $localName, 'source' => $key, 'default' => $default];

            if (!$this->match(TokenType::Comma)) {
                break;
            }
        }

        $this->expect(TokenType::RightBrace);
        $this->expect(TokenType::Equal, 'Expected = after destructuring pattern');
        $initializer = $this->parseExpression();
        if ($consumeSemi) {
            $this->consumeSemicolon();
        }

        return new DestructuringDeclaration($kind, $bindings, $restName, $initializer, false);
    }

    /**
     * Parse a nested destructuring pattern (consumed as part of a parent pattern).
     * Returns ['isArray' => bool, 'bindings' => [...], 'restName' => ?string]
     */
    private function parseNestedPattern(bool $isArray): array
    {
        $bindings = [];
        $restName = null;

        if ($isArray) {
            $this->expect(TokenType::LeftBracket);
            $index = 0;
            while ($this->current->type !== TokenType::RightBracket && $this->current->type !== TokenType::Eof) {
                if ($this->current->type === TokenType::Comma) {
                    $index++;
                    $this->advance();
                    continue;
                }
                if ($this->current->type === TokenType::Spread) {
                    $this->advance();
                    $restName = $this->expect(TokenType::Identifier, 'Expected rest element name')->value;
                    break;
                }
                // Recursively nested array
                if ($this->current->type === TokenType::LeftBracket) {
                    $nested = $this->parseNestedPattern(true);
                    $default = null;
                    if ($this->match(TokenType::Equal)) {
                        $default = $this->parseExpression();
                    }
                    $bindings[] = ['name' => null, 'source' => $index, 'default' => $default, 'nested' => $nested];
                    $index++;
                    if (!$this->match(TokenType::Comma)) { break; }
                    continue;
                }
                // Recursively nested object
                if ($this->current->type === TokenType::LeftBrace) {
                    $nested = $this->parseNestedPattern(false);
                    $default = null;
                    if ($this->match(TokenType::Equal)) {
                        $default = $this->parseExpression();
                    }
                    $bindings[] = ['name' => null, 'source' => $index, 'default' => $default, 'nested' => $nested];
                    $index++;
                    if (!$this->match(TokenType::Comma)) { break; }
                    continue;
                }
                $name = $this->expect(TokenType::Identifier, 'Expected variable name in destructuring')->value;
                $default = null;
                if ($this->match(TokenType::Equal)) {
                    $default = $this->parseExpression();
                }
                $bindings[] = ['name' => $name, 'source' => $index, 'default' => $default];
                $index++;
                if (!$this->match(TokenType::Comma)) { break; }
            }
            $this->expect(TokenType::RightBracket);
        } else {
            $this->expect(TokenType::LeftBrace);
            while ($this->current->type !== TokenType::RightBrace && $this->current->type !== TokenType::Eof) {
                if ($this->current->type === TokenType::Spread) {
                    $this->advance();
                    $restName = $this->expect(TokenType::Identifier, 'Expected rest element name')->value;
                    break;
                }
                $key = $this->expect(TokenType::Identifier, 'Expected property name in destructuring')->value;
                $localName = $key;
                if ($this->match(TokenType::Colon)) {
                    // Nested object: { key: { ... } }
                    if ($this->current->type === TokenType::LeftBrace) {
                        $nested = $this->parseNestedPattern(false);
                        $default = null;
                        if ($this->match(TokenType::Equal)) {
                            $default = $this->parseExpression();
                        }
                        $bindings[] = ['name' => null, 'source' => $key, 'default' => $default, 'nested' => $nested];
                        if (!$this->match(TokenType::Comma)) { break; }
                        continue;
                    }
                    // Nested array: { key: [ ... ] }
                    if ($this->current->type === TokenType::LeftBracket) {
                        $nested = $this->parseNestedPattern(true);
                        $default = null;
                        if ($this->match(TokenType::Equal)) {
                            $default = $this->parseExpression();
                        }
                        $bindings[] = ['name' => null, 'source' => $key, 'default' => $default, 'nested' => $nested];
                        if (!$this->match(TokenType::Comma)) { break; }
                        continue;
                    }
                    $localName = $this->expect(TokenType::Identifier, 'Expected variable name')->value;
                }
                $default = null;
                if ($this->match(TokenType::Equal)) {
                    $default = $this->parseExpression();
                }
                $bindings[] = ['name' => $localName, 'source' => $key, 'default' => $default];
                if (!$this->match(TokenType::Comma)) { break; }
            }
            $this->expect(TokenType::RightBrace);
        }

        return ['isArray' => $isArray, 'bindings' => $bindings, 'restName' => $restName];
    }

    private function parseFunctionDeclaration(): FunctionDeclaration
    {
        $this->expect(TokenType::Function);
        $name   = $this->expect(TokenType::Identifier, 'Expected function name')->value;
        [$params, $restParam, $defaults, $paramDestructures] = $this->parseParamList();
        $body   = $this->parseBlockBody();

        return new FunctionDeclaration($name, $params, $body, $restParam, $defaults, $paramDestructures);
    }

    private function parseReturnStatement(): ReturnStmt
    {
        $this->expect(TokenType::Return);
        $value = null;
        if ($this->current->type !== TokenType::Semicolon && $this->current->type !== TokenType::RightBrace && $this->current->type !== TokenType::Eof) {
            $value = $this->parseExpression();
        }
        $this->consumeSemicolon();
        return new ReturnStmt($value);
    }

    private function parseBlockStatement(): BlockStmt
    {
        return new BlockStmt($this->parseBlockBody());
    }

    /** @return Stmt[] */
    private function parseBlockBody(): array
    {
        $this->expect(TokenType::LeftBrace);
        $stmts = [];
        while ($this->current->type !== TokenType::RightBrace && $this->current->type !== TokenType::Eof) {
            $stmts[] = $this->parseStatement();
        }
        $this->expect(TokenType::RightBrace);
        return $stmts;
    }

    private function parseIfStatement(): IfStmt
    {
        $this->expect(TokenType::If);
        $this->expect(TokenType::LeftParen);
        $condition = $this->parseExpression();
        $this->expect(TokenType::RightParen);
        $consequent = $this->parseStatement();
        $alternate = null;
        if ($this->match(TokenType::Else)) {
            $alternate = $this->parseStatement();
        }
        return new IfStmt($condition, $consequent, $alternate);
    }

    private function parseWhileStatement(): WhileStmt
    {
        $this->expect(TokenType::While);
        $this->expect(TokenType::LeftParen);
        $condition = $this->parseExpression();
        $this->expect(TokenType::RightParen);
        $body = $this->parseStatement();
        return new WhileStmt($condition, $body);
    }

    private function parseForStatement(): ForStmt|ForOfStmt|ForInStmt
    {
        $this->expect(TokenType::For);
        $this->expect(TokenType::LeftParen);

        // Check for for...of and for...in: var/let/const <name> of/in <expr>
        if ($this->current->type === TokenType::Var || $this->current->type === TokenType::Let || $this->current->type === TokenType::Const) {
            $kind = match ($this->current->type) {
                TokenType::Var => VarKind::Var,
                TokenType::Let => VarKind::Let,
                TokenType::Const => VarKind::Const,
            };
            $saved = $this->current;
            $this->advance(); // consume var/let/const

            // Destructuring in for-init: for (let [a, b] = ...; ...)
            if ($this->current->type === TokenType::LeftBracket) {
                $init = $this->parseArrayDestructuring($kind, false);
                $this->consumeSemicolon();
                return $this->parseForRest($init);
            }
            if ($this->current->type === TokenType::LeftBrace) {
                $init = $this->parseObjectDestructuring($kind, false);
                $this->consumeSemicolon();
                return $this->parseForRest($init);
            }

            if ($this->current->type === TokenType::Identifier) {
                $name = $this->current->value;
                $this->advance(); // consume identifier

                // for (var x of iterable)
                if ($this->current->type === TokenType::Identifier && $this->current->value === 'of') {
                    $this->advance(); // consume 'of'
                    $iterable = $this->parseExpression();
                    $this->expect(TokenType::RightParen);
                    $body = $this->parseStatement();
                    return new ForOfStmt($kind, $name, $iterable, $body);
                }

                // for (var x in object)
                if ($this->current->type === TokenType::In) {
                    $this->advance(); // consume 'in'
                    $object = $this->parseExpression();
                    $this->expect(TokenType::RightParen);
                    $body = $this->parseStatement();
                    return new ForInStmt($kind, $name, $object, $body);
                }

                // Not for...of or for...in — parse as normal for loop
                // We already consumed "var/let/const name", now expect = or ;
                $init_expr = null;
                if ($this->match(TokenType::Equal)) {
                    $init_expr = $this->parseExpression();
                }
                $first = new VarDeclaration($kind, $name, $init_expr);

                // Multi-var: for (let i = 0, j = 10; ...)
                if ($this->current->type === TokenType::Comma) {
                    $declarations = [$first];
                    while ($this->match(TokenType::Comma)) {
                        $n = $this->expect(TokenType::Identifier, 'Expected variable name')->value;
                        $e = null;
                        if ($this->match(TokenType::Equal)) {
                            $e = $this->parseExpression();
                        }
                        $declarations[] = new VarDeclaration($kind, $n, $e);
                    }
                    $this->consumeSemicolon();
                    return $this->parseForRest(new VarDeclarationList($declarations));
                }

                $this->consumeSemicolon();
                return $this->parseForRest($first);
            }

            // Identifier not found after var/let/const — shouldn't happen in valid JS
            throw new ParserException('Expected variable name', $this->current);
        }

        // Regular for loop without var/let/const init
        $init = null;
        if (!$this->match(TokenType::Semicolon)) {
            $init = new ExpressionStmt($this->parseCommaExpression());
            $this->expect(TokenType::Semicolon);
        }

        return $this->parseForRest($init);
    }

    private function parseForRest(?Node $init): ForStmt
    {
        // Condition
        $condition = null;
        if ($this->current->type !== TokenType::Semicolon) {
            $condition = $this->parseExpression();
        }
        $this->expect(TokenType::Semicolon);

        // Update — supports comma operator: i++, j--
        $update = null;
        if ($this->current->type !== TokenType::RightParen) {
            $update = $this->parseCommaExpression();
        }
        $this->expect(TokenType::RightParen);

        $body = $this->parseStatement();

        return new ForStmt($init, $condition, $update, $body);
    }

    /**
     * Parse an expression that may contain the comma operator.
     * Returns a SequenceExpr if commas are found, otherwise a single Expr.
     */
    private function parseCommaExpression(): Expr
    {
        $first = $this->parseExpression();
        if ($this->current->type !== TokenType::Comma) {
            return $first;
        }
        $expressions = [$first];
        while ($this->match(TokenType::Comma)) {
            $expressions[] = $this->parseExpression();
        }
        return new SequenceExpr($expressions);
    }

    private function parseBreakStatement(): BreakStmt
    {
        $this->expect(TokenType::Break);
        $this->consumeSemicolon();
        return new BreakStmt();
    }

    private function parseContinueStatement(): ContinueStmt
    {
        $this->expect(TokenType::Continue);
        $this->consumeSemicolon();
        return new ContinueStmt();
    }

    private function parseDoWhileStatement(): DoWhileStmt
    {
        $this->expect(TokenType::Do);
        $body = $this->parseStatement();
        $this->expect(TokenType::While);
        $this->expect(TokenType::LeftParen);
        $condition = $this->parseExpression();
        $this->expect(TokenType::RightParen);
        $this->consumeSemicolon();
        return new DoWhileStmt($condition, $body);
    }

    private function parseSwitchStatement(): SwitchStmt
    {
        $this->expect(TokenType::Switch);
        $this->expect(TokenType::LeftParen);
        $discriminant = $this->parseExpression();
        $this->expect(TokenType::RightParen);
        $this->expect(TokenType::LeftBrace);

        $cases = [];
        while ($this->current->type !== TokenType::RightBrace && $this->current->type !== TokenType::Eof) {
            $test = null;
            if ($this->match(TokenType::Case)) {
                $test = $this->parseExpression();
            } else {
                $this->expect(TokenType::Default);
            }
            $this->expect(TokenType::Colon);

            $consequent = [];
            while (
                $this->current->type !== TokenType::Case
                && $this->current->type !== TokenType::Default
                && $this->current->type !== TokenType::RightBrace
                && $this->current->type !== TokenType::Eof
            ) {
                $consequent[] = $this->parseStatement();
            }
            $cases[] = new SwitchCase($test, $consequent);
        }

        $this->expect(TokenType::RightBrace);
        return new SwitchStmt($discriminant, $cases);
    }

    private function parseTryStatement(): TryCatchStmt
    {
        $this->expect(TokenType::Try);
        $block = $this->parseBlockStatement();

        $handler = null;
        if ($this->match(TokenType::Catch)) {
            // Optional catch binding: catch { ... } or catch (e) { ... }
            $param = null;
            if ($this->current->type === TokenType::LeftParen) {
                $this->advance();
                $param = $this->expect(TokenType::Identifier, 'Expected catch parameter name')->value;
                $this->expect(TokenType::RightParen);
            }
            $body = $this->parseBlockStatement();
            $handler = new CatchClause($param, $body);
        }

        // finally block
        $finalizer = null;
        if ($this->current->type === TokenType::Identifier && $this->current->value === 'finally') {
            $this->advance();
            $finalizer = $this->parseBlockStatement();
        }

        return new TryCatchStmt($block, $handler, $finalizer);
    }

    private function parseThrowStatement(): ThrowStmt
    {
        $this->expect(TokenType::Throw);
        $argument = $this->parseExpression();
        $this->consumeSemicolon();
        return new ThrowStmt($argument);
    }

    private function parseExpressionStatement(): ExpressionStmt
    {
        $expr = $this->parseExpression();
        $this->consumeSemicolon();
        return new ExpressionStmt($expr);
    }

    private function consumeSemicolon(): void
    {
        // Lenient: consume semicolon if present, but don't require it (ASI-like behavior)
        $this->match(TokenType::Semicolon);
    }

    // ──────────────────── Pratt Expression Parser ────────────────────

    /**
     * Core Pratt loop. $minBp is the minimum binding power we'll accept on the left.
     */
    private function parseExpression(int $minBp = 0): Expr
    {
        // Prefix / NUD (null denotation)
        $left = $this->parsePrefixExpr();

        // Infix / LED (left denotation)
        $inOptionalChain = false;
        while (true) {
            $currentType = $this->current->type;
            $bp = $this->infixBindingPower($currentType);
            if ($bp === null || ($bp >> 8) < $minBp) {
                break;
            }
            $rightBp = $bp & 0xFF;

            // Reset chain flag on non-chain tokens
            $isChainToken = $currentType === TokenType::Dot
                || $currentType === TokenType::OptionalChain
                || $currentType === TokenType::LeftBracket
                || $currentType === TokenType::LeftParen;
            if (!$isChainToken) {
                $inOptionalChain = false;
            }

            // Special handling for logical operators (short-circuit semantics in VM)
            if ($currentType === TokenType::And || $currentType === TokenType::Or
                || $currentType === TokenType::NullishCoalesce) {
                $op = $this->advance()->value;
                $right = $this->parseExpression($rightBp);
                $left = new LogicalExpr($left, $op, $right);
                continue;
            }

            // Member access — ., ?. and [ as infix
            if ($currentType === TokenType::Dot) {
                $this->advance();
                $prop = $this->expect(TokenType::Identifier, 'Expected property name after "."');
                $left = new MemberExpr($left, new Identifier($prop->value), false, optionalChain: $inOptionalChain);
                continue;
            }

            if ($currentType === TokenType::OptionalChain) {
                $this->advance();
                $inOptionalChain = true;
                if ($this->current->type === TokenType::LeftBracket) {
                    // ?.["key"] — optional computed access
                    $this->advance();
                    $prop = $this->parseExpression();
                    $this->expect(TokenType::RightBracket);
                    $left = new MemberExpr($left, $prop, true, optional: true);
                } elseif ($this->current->type === TokenType::LeftParen) {
                    // ?.() — optional call
                    $left = $this->parseCallExpr($left, optional: true);
                } else {
                    $prop = $this->expect(TokenType::Identifier, 'Expected property name after "?."');
                    $left = new MemberExpr($left, new Identifier($prop->value), false, optional: true);
                }
                continue;
            }

            if ($currentType === TokenType::LeftBracket) {
                $this->advance();
                $prop = $this->parseExpression();
                $this->expect(TokenType::RightBracket);
                $left = new MemberExpr($left, $prop, true, optionalChain: $inOptionalChain);
                continue;
            }

            // Function call — ( is an infix operator with high binding power
            if ($currentType === TokenType::LeftParen) {
                $left = $this->parseCallExpr($left, optionalChain: $inOptionalChain);
                continue;
            }

            // Ternary conditional: cond ? then : else
            if ($currentType === TokenType::Question) {
                $this->advance(); // consume ?
                $consequent = $this->parseExpression(); // full expression for the "then" branch
                $this->expect(TokenType::Colon, "Expected ':' in ternary expression");
                // Both branches accept full AssignmentExpression in JS (arrows, assignments, nested ternaries)
                $alternate = $this->parseExpression();
                $left = new ConditionalExpr($left, $consequent, $alternate);
                continue;
            }

            // Arrow function: x => body (single param, no parens)
            if ($currentType === TokenType::Arrow) {
                if (!$left instanceof Identifier) {
                    throw new ParserException('Arrow function parameter must be an identifier', $this->current);
                }
                $this->advance();
                $left = new FunctionExpr(null, [$left->name], $this->parseArrowBody(), isArrow: true);
                continue;
            }

            // Postfix ++/-- (no right operand)
            if ($currentType === TokenType::PlusPlus || $currentType === TokenType::MinusMinus) {
                $op = $this->advance()->value;
                if (!($left instanceof Identifier) && !($left instanceof MemberExpr)) {
                    throw new ParserException('Invalid left-hand side in postfix operation', $this->current);
                }
                $left = new UpdateExpr($op, $left, false);
                continue;
            }

            // Assignment operators
            if ($this->isAssignOp($currentType)) {
                $op = $this->advance()->value;
                $right = $this->parseExpression($rightBp);
                if ($left instanceof Identifier) {
                    $left = new AssignExpr($left->name, $op, $right);
                } elseif ($left instanceof MemberExpr) {
                    $left = new MemberAssignExpr($left->object, $left->property, $left->computed, $op, $right);
                } else {
                    throw new ParserException('Invalid assignment target', $this->current);
                }
                continue;
            }

            // Standard binary
            $op    = $this->advance()->value;
            $right = $this->parseExpression($rightBp);
            $left  = new BinaryExpr($left, $op, $right);
        }

        return $left;
    }

    private function parsePrefixExpr(): Expr
    {
        $token = $this->current;

        return match ($token->type) {
            TokenType::Number => $this->parseNumber(),
            TokenType::String => $this->parseString(),
            TokenType::True => $this->parseBool(true),
            TokenType::False => $this->parseBool(false),
            TokenType::Null => $this->parseNull(),
            TokenType::Undefined => $this->parseUndefined(),
            TokenType::Identifier => $this->parseIdentifier(),
            TokenType::LeftParen => $this->parseGroupOrArrow(),
            TokenType::LeftBracket => $this->parseArrayLiteral(),
            TokenType::LeftBrace => $this->parseObjectLiteral(),
            TokenType::Minus, TokenType::Not, TokenType::Tilde => $this->parseUnary(),
            TokenType::Typeof => $this->parseTypeof(),
            TokenType::Void => $this->parseVoidExpr(),
            TokenType::Delete => $this->parseDeleteExpr(),
            TokenType::PlusPlus, TokenType::MinusMinus => $this->parsePrefixUpdate(),
            TokenType::Function => $this->parseFunctionExpression(),
            TokenType::This => $this->parseThis(),
            TokenType::New => $this->parseNewExpr(),
            TokenType::Regex => $this->parseRegexLiteral(),
            TokenType::TemplateHead => $this->parseTemplateLiteral(),
            default => throw new ParserException("Unexpected token '{$token->value}'", $token),
        };
    }

    private function parseNumber(): NumberLiteral
    {
        $t = $this->advance();
        return new NumberLiteral((float) $t->value);
    }

    private function parseString(): StringLiteral
    {
        $t = $this->advance();
        return new StringLiteral($t->value);
    }

    private function parseBool(bool $val): BooleanLiteral
    {
        $this->advance();
        return new BooleanLiteral($val);
    }

    private function parseNull(): NullLiteral
    {
        $this->advance();
        return new NullLiteral();
    }

    private function parseUndefined(): UndefinedLiteral
    {
        $this->advance();
        return new UndefinedLiteral();
    }

    private function parseIdentifier(): Identifier
    {
        $t = $this->advance();
        return new Identifier($t->value);
    }

    private function parseGroupOrArrow(): Expr
    {
        $this->expect(TokenType::LeftParen);

        // () => ... — no params
        if ($this->current->type === TokenType::RightParen) {
            $this->advance();
            $this->expect(TokenType::Arrow, 'Expected "=>" after empty parameter list');
            return new FunctionExpr(null, [], $this->parseArrowBody(), isArrow: true);
        }

        // (...rest) => ... — rest-only arrow
        if ($this->current->type === TokenType::Spread) {
            $this->advance();
            $restName = $this->expect(TokenType::Identifier, 'Expected rest parameter name')->value;
            $this->expect(TokenType::RightParen);
            $this->expect(TokenType::Arrow, 'Expected "=>" after rest parameter');
            return new FunctionExpr(null, [], $this->parseArrowBody(), isArrow: true, restParam: $restName);
        }

        // Parse first expression (may be single param or grouped expression)
        $expr = $this->parseExpression();
        if ($this->current->type !== TokenType::Comma) {
            $this->expect(TokenType::RightParen);

            // No comma means either a single grouped expression or a single-param arrow
            if ($this->current->type !== TokenType::Arrow) {
                return $expr;
            }

            $this->advance();
            $params = [];
            $defaults = [];
            $hasDefaults = false;
            if ($expr instanceof Identifier) {
                $params[] = $expr->name;
                $defaults[] = null;
            } elseif ($expr instanceof AssignExpr && $expr->operator === '=') {
                $params[] = $expr->name;
                $defaults[] = $expr->value;
                $hasDefaults = true;
            } else {
                throw new ParserException('Arrow function parameters must be identifiers', $this->current);
            }
            return new FunctionExpr(
                null,
                $params,
                $this->parseArrowBody(),
                isArrow: true,
                defaults: $hasDefaults ? $defaults : [],
            );
        }

        $exprs = [$expr];
        $hasDefaults = false;
        $restParam = null;
        while ($this->match(TokenType::Comma)) {
            if ($this->current->type === TokenType::RightParen) {
                break; // trailing comma
            }
            if ($this->current->type === TokenType::Spread) {
                $this->advance();
                $restParam = $this->expect(TokenType::Identifier, 'Expected rest parameter name')->value;
                break; // rest must be last
            }
            $exprs[] = $this->parseExpression();
        }
        $this->expect(TokenType::RightParen);

        // If => follows, this was an arrow parameter list
        if ($this->current->type === TokenType::Arrow) {
            $this->advance();
            $params = [];
            $defaults = [];
            foreach ($exprs as $expr) {
                if ($expr instanceof Identifier) {
                    $params[] = $expr->name;
                    $defaults[] = null;
                } elseif ($expr instanceof AssignExpr && $expr->operator === '=') {
                    $params[] = $expr->name;
                    $defaults[] = $expr->value;
                    $hasDefaults = true;
                } else {
                    throw new ParserException('Arrow function parameters must be identifiers', $this->current);
                }
            }
            return new FunctionExpr(null, $params, $this->parseArrowBody(), isArrow: true, restParam: $restParam, defaults: $hasDefaults ? $defaults : []);
        }

        // No arrow — plain grouping (must be single expression)
        if ($restParam !== null) {
            throw new ParserException('Rest parameter outside arrow function', $this->current);
        }
        if (count($exprs) !== 1) {
            throw new ParserException('Unexpected comma in grouped expression', $this->current);
        }
        return $exprs[0];
    }

    /** @return Stmt[] */
    private function parseArrowBody(): array
    {
        if ($this->current->type === TokenType::LeftBrace) {
            return $this->parseBlockBody();
        }
        // Expression body → implicit return
        return [new ReturnStmt($this->parseExpression(1))];
    }

    private function parseArrayLiteral(): ArrayLiteral
    {
        $this->expect(TokenType::LeftBracket);
        $elements = [];
        if ($this->current->type !== TokenType::RightBracket) {
            $elements[] = $this->parseArrayElement();
            while ($this->match(TokenType::Comma)) {
                if ($this->current->type === TokenType::RightBracket) {
                    break; // trailing comma
                }
                $elements[] = $this->parseArrayElement();
            }
        }
        $this->expect(TokenType::RightBracket);
        return new ArrayLiteral($elements);
    }

    private function parseArrayElement(): Expr
    {
        if ($this->current->type === TokenType::Spread) {
            $this->advance();
            return new SpreadElement($this->parseExpression());
        }
        return $this->parseExpression();
    }

    private function parseObjectLiteral(): ObjectLiteral
    {
        $this->expect(TokenType::LeftBrace);
        $properties = [];
        if ($this->current->type !== TokenType::RightBrace) {
            $properties[] = $this->parseObjectProperty();
            while ($this->match(TokenType::Comma)) {
                if ($this->current->type === TokenType::RightBrace) {
                    break; // trailing comma
                }
                $properties[] = $this->parseObjectProperty();
            }
        }
        $this->expect(TokenType::RightBrace);
        return new ObjectLiteral($properties);
    }

    private function parseObjectProperty(): ObjectProperty
    {
        // Computed property name: { [expr]: value }
        if ($this->current->type === TokenType::LeftBracket) {
            $this->advance(); // consume [
            $keyExpr = $this->parseExpression();
            $this->expect(TokenType::RightBracket);
            $this->expect(TokenType::Colon);
            $value = $this->parseExpression();
            return new ObjectProperty(null, $value, computed: true, computedKey: $keyExpr);
        }

        // Key can be an identifier, string, or number
        $key = match ($this->current->type) {
            TokenType::Identifier => $this->advance()->value,
            TokenType::String => $this->advance()->value,
            TokenType::Number => $this->advance()->value,
            default => throw new ParserException('Expected property name', $this->current),
        };

        // Shorthand property: { x } means { x: x }
        if ($this->current->type !== TokenType::Colon) {
            return new ObjectProperty($key, new Identifier($key));
        }

        $this->expect(TokenType::Colon);
        $value = $this->parseExpression();
        return new ObjectProperty($key, $value);
    }

    private function parseUnary(): UnaryExpr
    {
        $op = $this->advance()->value;
        $operand = $this->parseExpression($this->prefixBindingPower($op));
        return new UnaryExpr($op, $operand);
    }

    private function parseTypeof(): TypeofExpr
    {
        $this->advance(); // consume 'typeof'
        $operand = $this->parseExpression($this->prefixBindingPower('typeof'));
        return new TypeofExpr($operand);
    }

    private function parsePrefixUpdate(): UpdateExpr
    {
        $op = $this->advance()->value; // '++' or '--'
        $operand = $this->parseExpression(30); // prefix binding power
        if (!($operand instanceof Identifier) && !($operand instanceof MemberExpr)) {
            throw new ParserException('Invalid left-hand side in prefix operation', $this->current);
        }
        return new UpdateExpr($op, $operand, true);
    }

    private function parseVoidExpr(): VoidExpr
    {
        $this->advance(); // consume 'void'
        $operand = $this->parseExpression($this->prefixBindingPower('void'));
        return new VoidExpr($operand);
    }

    private function parseDeleteExpr(): DeleteExpr
    {
        $this->advance(); // consume 'delete'
        $operand = $this->parseExpression($this->prefixBindingPower('delete'));
        return new DeleteExpr($operand);
    }

    private function parseFunctionExpression(): FunctionExpr
    {
        $this->expect(TokenType::Function);
        $name = null;
        if ($this->current->type === TokenType::Identifier) {
            $name = $this->advance()->value;
        }
        [$params, $restParam, $defaults, $paramDestructures] = $this->parseParamList();
        $body   = $this->parseBlockBody();
        return new FunctionExpr($name, $params, $body, restParam: $restParam, defaults: $defaults, paramDestructures: $paramDestructures);
    }

    private function parseThis(): ThisExpr
    {
        $this->advance();
        return new ThisExpr();
    }

    private function parseNewExpr(): NewExpr
    {
        $this->advance(); // consume 'new'

        // Parse callee (identifier + member access chains)
        $callee = $this->parsePrefixExpr();
        while ($this->current->type === TokenType::Dot || $this->current->type === TokenType::LeftBracket) {
            if ($this->current->type === TokenType::Dot) {
                $this->advance();
                $prop = $this->expect(TokenType::Identifier, 'Expected property name after "."');
                $callee = new MemberExpr($callee, new Identifier($prop->value), false);
            } else {
                $this->advance();
                $prop = $this->parseExpression();
                $this->expect(TokenType::RightBracket);
                $callee = new MemberExpr($callee, $prop, true);
            }
        }

        // Parse optional argument list
        $args = [];
        if ($this->current->type === TokenType::LeftParen) {
            $this->expect(TokenType::LeftParen);
            if ($this->current->type !== TokenType::RightParen) {
                $args[] = $this->parseCallArgument();
                while ($this->match(TokenType::Comma)) {
                    $args[] = $this->parseCallArgument();
                }
            }
            $this->expect(TokenType::RightParen);
        }

        return new NewExpr($callee, $args);
    }

    private function parseRegexLiteral(): RegexLiteral
    {
        $t = $this->advance();
        [$pattern, $flags] = explode('|||', $t->value, 2);
        return new RegexLiteral($pattern, $flags);
    }

    private function parseTemplateLiteral(): TemplateLiteral
    {
        $quasis = [];
        $expressions = [];

        // First part (TemplateHead)
        $head = $this->advance();
        $quasis[] = $head->value;

        // Parse expression after ${
        $expressions[] = $this->parseExpression();

        // Continue with middle parts
        while ($this->current->type === TokenType::TemplateMiddle) {
            $middle = $this->advance();
            $quasis[] = $middle->value;
            $expressions[] = $this->parseExpression();
        }

        // Final part (TemplateTail)
        $tail = $this->expect(TokenType::TemplateTail, 'Expected template literal tail');
        $quasis[] = $tail->value;

        return new TemplateLiteral($quasis, $expressions);
    }

    private function parseCallExpr(Expr $callee, bool $optional = false, bool $optionalChain = false): CallExpr
    {
        $this->expect(TokenType::LeftParen);
        $args = [];
        if ($this->current->type !== TokenType::RightParen) {
            $args[] = $this->parseCallArgument();
            while ($this->match(TokenType::Comma)) {
                $args[] = $this->parseCallArgument();
            }
        }
        $this->expect(TokenType::RightParen);
        return new CallExpr($callee, $args, $optional, $optionalChain);
    }

    private function parseCallArgument(): Expr
    {
        if ($this->current->type === TokenType::Spread) {
            $this->advance();
            return new SpreadElement($this->parseExpression());
        }
        return $this->parseExpression();
    }

    /** @return array{string[], ?string, array, array} [params, restParam, defaults, paramDestructures] */
    private function parseParamList(): array
    {
        $this->expect(TokenType::LeftParen);
        $params = [];
        $defaults = [];
        $restParam = null;
        $paramDestructures = [];
        $syntheticIdx = 0;
        $paramIndex = 0;
        $hasDefaults = false;
        if ($this->current->type !== TokenType::RightParen) {
            if ($this->current->type === TokenType::Spread) {
                $this->advance();
                $restParam = $this->expect(TokenType::Identifier, 'Expected rest parameter name')->value;
            } else {
                $this->parseOneParam($params, $defaults, $paramDestructures, $paramIndex, $syntheticIdx, $hasDefaults);
                while ($this->match(TokenType::Comma)) {
                    if ($this->current->type === TokenType::Spread) {
                        $this->advance();
                        $restParam = $this->expect(TokenType::Identifier, 'Expected rest parameter name')->value;
                        break;
                    }
                    $this->parseOneParam($params, $defaults, $paramDestructures, $paramIndex, $syntheticIdx, $hasDefaults);
                }
            }
        }
        $this->expect(TokenType::RightParen);
        // Only include defaults array if any defaults were provided
        return [$params, $restParam, $hasDefaults ? $defaults : [], $paramDestructures];
    }

    private function parseOneParam(
        array &$params,
        array &$defaults,
        array &$paramDestructures,
        int &$paramIndex,
        int &$syntheticIdx,
        bool &$hasDefaults,
    ): void {
        $default = null;

        if ($this->current->type === TokenType::LeftBrace || $this->current->type === TokenType::LeftBracket) {
            $isArray = $this->current->type === TokenType::LeftBracket;
            $pattern = $isArray ? $this->parseDestructuringPattern(true) : $this->parseDestructuringPattern(false);
            $synthetic = '__p' . $syntheticIdx++;
            $idx = $paramIndex++;
            $params[] = $synthetic;
            if ($this->match(TokenType::Equal)) {
                $default = $this->parseExpression();
                $hasDefaults = true;
            }
            $defaults[] = $default;
            $paramDestructures[$idx] = $pattern;
            return;
        }

        $paramIndex++;
        $params[] = $this->expect(TokenType::Identifier, 'Expected parameter name')->value;
        if ($this->match(TokenType::Equal)) {
            $default = $this->parseExpression();
            $hasDefaults = true;
        }
        $defaults[] = $default;
    }

    /**
     * Parse a destructuring pattern (without = initializer) for function params.
     * @return array{isArray: bool, bindings: array, restName: ?string}
     */
    private function parseDestructuringPattern(bool $isArray): array
    {
        return $this->parseNestedPattern($isArray);
    }

    // ──────────────────── Binding Powers ────────────────────

    /**
     * Packed binding power table for infix operators.
     * Value format: (leftBp << 8) | rightBp.
     */
    private const array INFIX_BINDING_POWER = [
        TokenType::Arrow->value                => (2 << 8) | 1,
        TokenType::Equal->value                => (2 << 8) | 1,
        TokenType::PlusEqual->value            => (2 << 8) | 1,
        TokenType::MinusEqual->value           => (2 << 8) | 1,
        TokenType::StarEqual->value            => (2 << 8) | 1,
        TokenType::SlashEqual->value           => (2 << 8) | 1,
        TokenType::PercentEqual->value         => (2 << 8) | 1,
        TokenType::StarStarEqual->value        => (2 << 8) | 1,
        TokenType::AmpersandEqual->value       => (2 << 8) | 1,
        TokenType::PipeEqual->value            => (2 << 8) | 1,
        TokenType::CaretEqual->value           => (2 << 8) | 1,
        TokenType::LeftShiftEqual->value       => (2 << 8) | 1,
        TokenType::RightShiftEqual->value      => (2 << 8) | 1,
        TokenType::UnsignedRightShiftEqual->value => (2 << 8) | 1,
        TokenType::NullishCoalesceEqual->value => (2 << 8) | 1,
        TokenType::Question->value              => (4 << 8) | 3,
        TokenType::NullishCoalesce->value       => (6 << 8) | 7,
        TokenType::Or->value                    => (8 << 8) | 9,
        TokenType::And->value                   => (10 << 8) | 11,
        TokenType::Pipe->value                  => (12 << 8) | 13,
        TokenType::Caret->value                 => (14 << 8) | 15,
        TokenType::Ampersand->value             => (16 << 8) | 17,
        TokenType::EqualEqual->value            => (18 << 8) | 19,
        TokenType::NotEqual->value              => (18 << 8) | 19,
        TokenType::StrictEqual->value           => (18 << 8) | 19,
        TokenType::StrictNotEqual->value        => (18 << 8) | 19,
        TokenType::Less->value                  => (20 << 8) | 21,
        TokenType::LessEqual->value             => (20 << 8) | 21,
        TokenType::Greater->value               => (20 << 8) | 21,
        TokenType::GreaterEqual->value          => (20 << 8) | 21,
        TokenType::In->value                    => (20 << 8) | 21,
        TokenType::Instanceof->value            => (20 << 8) | 21,
        TokenType::LeftShift->value             => (22 << 8) | 23,
        TokenType::RightShift->value            => (22 << 8) | 23,
        TokenType::UnsignedRightShift->value    => (22 << 8) | 23,
        TokenType::Plus->value                  => (24 << 8) | 25,
        TokenType::Minus->value                 => (24 << 8) | 25,
        TokenType::Star->value                  => (26 << 8) | 27,
        TokenType::Slash->value                 => (26 << 8) | 27,
        TokenType::Percent->value               => (26 << 8) | 27,
        TokenType::StarStar->value              => (29 << 8) | 28,
        TokenType::PlusPlus->value              => (31 << 8) | 32,
        TokenType::MinusMinus->value            => (31 << 8) | 32,
        TokenType::Dot->value                   => (33 << 8) | 34,
        TokenType::OptionalChain->value         => (33 << 8) | 34,
        TokenType::LeftBracket->value           => (33 << 8) | 34,
        TokenType::LeftParen->value             => (33 << 8) | 34,
    ];

    private const array ASSIGN_OPS = [
        TokenType::Equal->value => true,
        TokenType::PlusEqual->value => true,
        TokenType::MinusEqual->value => true,
        TokenType::StarEqual->value => true,
        TokenType::SlashEqual->value => true,
        TokenType::PercentEqual->value => true,
        TokenType::StarStarEqual->value => true,
        TokenType::AmpersandEqual->value => true,
        TokenType::PipeEqual->value => true,
        TokenType::CaretEqual->value => true,
        TokenType::LeftShiftEqual->value => true,
        TokenType::RightShiftEqual->value => true,
        TokenType::UnsignedRightShiftEqual->value => true,
        TokenType::NullishCoalesceEqual->value => true,
    ];

    /**
     * Returns packed binding power for infix operators, or null if not infix.
     * Value format: (leftBp << 8) | rightBp.
     * Left-associative: rightBp = leftBp + 1
     * Right-associative: rightBp = leftBp (assignment)
     */
    private function infixBindingPower(TokenType $type): ?int
    {
        return self::INFIX_BINDING_POWER[$type->value] ?? null;
    }

    private function prefixBindingPower(string $op): int
    {
        return match ($op) {
            '-', '!', '~', 'typeof', 'void', 'delete' => 30,
            default  => 0,
        };
    }

    private function isAssignOp(TokenType $type): bool
    {
        return isset(self::ASSIGN_OPS[$type->value]);
    }
}