<?php

declare(strict_types=1);

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

    private function parseVarDeclaration(): VarDeclaration|DestructuringDeclaration
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
            return $this->parseArrayDestructuring($kind);
        }

        // Object destructuring: let {a, b} = expr
        if ($this->current->type === TokenType::LeftBrace) {
            return $this->parseObjectDestructuring($kind);
        }

        $name = $this->expect(TokenType::Identifier, 'Expected variable name')->value;
        $init = null;
        if ($this->match(TokenType::Equal)) {
            $init = $this->parseExpression();
        }
        $this->consumeSemicolon();

        return new VarDeclaration($kind, $name, $init);
    }

    private function parseArrayDestructuring(VarKind $kind): DestructuringDeclaration
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
        $this->consumeSemicolon();

        return new DestructuringDeclaration($kind, $bindings, $restName, $initializer, true);
    }

    private function parseObjectDestructuring(VarKind $kind): DestructuringDeclaration
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

            // { key: localName } or { key } (shorthand)
            $localName = $key;
            if ($this->match(TokenType::Colon)) {
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
        $this->consumeSemicolon();

        return new DestructuringDeclaration($kind, $bindings, $restName, $initializer, false);
    }

    private function parseFunctionDeclaration(): FunctionDeclaration
    {
        $this->expect(TokenType::Function);
        $name   = $this->expect(TokenType::Identifier, 'Expected function name')->value;
        [$params, $restParam, $defaults] = $this->parseParamList();
        $body   = $this->parseBlockBody();

        return new FunctionDeclaration($name, $params, $body, $restParam, $defaults);
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
                $this->consumeSemicolon();
                $init = new VarDeclaration($kind, $name, $init_expr);

                // Continue parsing condition and update
                return $this->parseForRest($init);
            }

            // Identifier not found after var/let/const — shouldn't happen in valid JS
            throw new ParserException('Expected variable name', $this->current);
        }

        // Regular for loop without var/let/const init
        $init = null;
        if (!$this->match(TokenType::Semicolon)) {
            $init = new ExpressionStmt($this->parseExpression());
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

        // Update
        $update = null;
        if ($this->current->type !== TokenType::RightParen) {
            $update = $this->parseExpression();
        }
        $this->expect(TokenType::RightParen);

        $body = $this->parseStatement();

        return new ForStmt($init, $condition, $update, $body);
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
            $this->expect(TokenType::LeftParen);
            $param = $this->expect(TokenType::Identifier, 'Expected catch parameter name')->value;
            $this->expect(TokenType::RightParen);
            $body = $this->parseBlockStatement();
            $handler = new CatchClause($param, $body);
        }

        return new TryCatchStmt($block, $handler);
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
        while (true) {
            $bp = $this->infixBindingPower($this->current->type);
            if ($bp === null || $bp[0] < $minBp) {
                break;
            }
            [$leftBp, $rightBp] = $bp;

            // Special handling for logical operators (short-circuit semantics in VM)
            if ($this->current->type === TokenType::And || $this->current->type === TokenType::Or
                || $this->current->type === TokenType::NullishCoalesce) {
                $op = $this->advance()->value;
                $right = $this->parseExpression($rightBp);
                $left = new LogicalExpr($left, $op, $right);
                continue;
            }

            // Member access — ., ?. and [ as infix
            if ($this->current->type === TokenType::Dot) {
                $this->advance();
                $prop = $this->expect(TokenType::Identifier, 'Expected property name after "."');
                $left = new MemberExpr($left, new Identifier($prop->value), false);
                continue;
            }

            if ($this->current->type === TokenType::OptionalChain) {
                $this->advance();
                $prop = $this->expect(TokenType::Identifier, 'Expected property name after "?."');
                $left = new MemberExpr($left, new Identifier($prop->value), false, true);
                continue;
            }

            if ($this->current->type === TokenType::LeftBracket) {
                $this->advance();
                $prop = $this->parseExpression();
                $this->expect(TokenType::RightBracket);
                $left = new MemberExpr($left, $prop, true);
                continue;
            }

            // Function call — ( is an infix operator with high binding power
            if ($this->current->type === TokenType::LeftParen) {
                $left = $this->parseCallExpr($left);
                continue;
            }

            // Ternary conditional: cond ? then : else
            if ($this->current->type === TokenType::Question) {
                $this->advance(); // consume ?
                $consequent = $this->parseExpression(); // full expression for the "then" branch
                $this->expect(TokenType::Colon, "Expected ':' in ternary expression");
                // Both branches accept full AssignmentExpression in JS (arrows, assignments, nested ternaries)
                $alternate = $this->parseExpression();
                $left = new ConditionalExpr($left, $consequent, $alternate);
                continue;
            }

            // Arrow function: x => body (single param, no parens)
            if ($this->current->type === TokenType::Arrow) {
                if (!$left instanceof Identifier) {
                    throw new ParserException('Arrow function parameter must be an identifier', $this->current);
                }
                $this->advance();
                $left = new FunctionExpr(null, [$left->name], $this->parseArrowBody(), isArrow: true);
                continue;
            }

            // Postfix ++/-- (no right operand)
            if ($this->current->type === TokenType::PlusPlus || $this->current->type === TokenType::MinusMinus) {
                $op = $this->advance()->value;
                if (!($left instanceof Identifier) && !($left instanceof MemberExpr)) {
                    throw new ParserException('Invalid left-hand side in postfix operation', $this->current);
                }
                $left = new UpdateExpr($op, $left, false);
                continue;
            }

            // Assignment operators
            if ($this->isAssignOp($this->current->type)) {
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

        // Parse comma-separated expressions (could be params or a single grouped expr)
        $exprs = [$this->parseExpression()];
        $restParam = null;
        while ($this->match(TokenType::Comma)) {
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
                } else {
                    throw new ParserException('Arrow function parameters must be identifiers', $this->current);
                }
            }
            $hasDefaults = array_filter($defaults, fn($d) => $d !== null);
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
        [$params, $restParam, $defaults] = $this->parseParamList();
        $body   = $this->parseBlockBody();
        return new FunctionExpr($name, $params, $body, restParam: $restParam, defaults: $defaults);
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

    private function parseCallExpr(Expr $callee): CallExpr
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
        return new CallExpr($callee, $args);
    }

    private function parseCallArgument(): Expr
    {
        if ($this->current->type === TokenType::Spread) {
            $this->advance();
            return new SpreadElement($this->parseExpression());
        }
        return $this->parseExpression();
    }

    /** @return array{string[], ?string} [params, restParam] */
    private function parseParamList(): array
    {
        $this->expect(TokenType::LeftParen);
        $params = [];
        $defaults = [];
        $restParam = null;
        if ($this->current->type !== TokenType::RightParen) {
            if ($this->current->type === TokenType::Spread) {
                $this->advance();
                $restParam = $this->expect(TokenType::Identifier, 'Expected rest parameter name')->value;
            } else {
                $params[] = $this->expect(TokenType::Identifier, 'Expected parameter name')->value;
                $defaults[] = $this->match(TokenType::Equal) ? $this->parseExpression() : null;
                while ($this->match(TokenType::Comma)) {
                    if ($this->current->type === TokenType::Spread) {
                        $this->advance();
                        $restParam = $this->expect(TokenType::Identifier, 'Expected rest parameter name')->value;
                        break;
                    }
                    $params[] = $this->expect(TokenType::Identifier, 'Expected parameter name')->value;
                    $defaults[] = $this->match(TokenType::Equal) ? $this->parseExpression() : null;
                }
            }
        }
        $this->expect(TokenType::RightParen);
        // Only include defaults array if any defaults were provided
        $hasDefaults = array_filter($defaults, fn($d) => $d !== null);
        return [$params, $restParam, $hasDefaults ? $defaults : []];
    }

    // ──────────────────── Binding Powers ────────────────────

    /**
     * Returns [leftBp, rightBp] for infix operators, or null if not an infix op.
     * Left-associative: rightBp = leftBp + 1
     * Right-associative: rightBp = leftBp (assignment)
     */
    private function infixBindingPower(TokenType $type): ?array
    {
        return match ($type) {
            // Arrow function: x => body (right-assoc, same level as assignment)
            TokenType::Arrow => [2, 1],

            // Assignment (right-assoc)
            TokenType::Equal, TokenType::PlusEqual, TokenType::MinusEqual,
            TokenType::StarEqual, TokenType::SlashEqual,
            TokenType::PercentEqual, TokenType::StarStarEqual,
            TokenType::AmpersandEqual, TokenType::PipeEqual, TokenType::CaretEqual,
            TokenType::LeftShiftEqual, TokenType::RightShiftEqual,
            TokenType::UnsignedRightShiftEqual,
            TokenType::NullishCoalesceEqual => [2, 1],

            // Ternary (right-assoc, just above assignment)
            TokenType::Question => [4, 3],

            // Nullish coalescing
            TokenType::NullishCoalesce => [6, 7],

            // Logical OR
            TokenType::Or => [8, 9],

            // Logical AND
            TokenType::And => [10, 11],

            // Bitwise OR
            TokenType::Pipe => [12, 13],

            // Bitwise XOR
            TokenType::Caret => [14, 15],

            // Bitwise AND
            TokenType::Ampersand => [16, 17],

            // Equality
            TokenType::EqualEqual, TokenType::NotEqual,
            TokenType::StrictEqual, TokenType::StrictNotEqual => [18, 19],

            // Relational (comparison + in + instanceof)
            TokenType::Less, TokenType::LessEqual,
            TokenType::Greater, TokenType::GreaterEqual,
            TokenType::In, TokenType::Instanceof => [20, 21],

            // Shift
            TokenType::LeftShift, TokenType::RightShift,
            TokenType::UnsignedRightShift => [22, 23],

            // Additive
            TokenType::Plus, TokenType::Minus => [24, 25],

            // Multiplicative
            TokenType::Star, TokenType::Slash, TokenType::Percent => [26, 27],

            // Exponentiation (right-assoc)
            TokenType::StarStar => [29, 28],

            // Postfix ++/--
            TokenType::PlusPlus, TokenType::MinusMinus => [31, 32],

            // Member access & Call — same precedence, left-to-right
            TokenType::Dot, TokenType::OptionalChain, TokenType::LeftBracket,
            TokenType::LeftParen => [33, 34],

            default => null,
        };
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
        return match ($type) {
            TokenType::Equal, TokenType::PlusEqual, TokenType::MinusEqual,
            TokenType::StarEqual, TokenType::SlashEqual,
            TokenType::PercentEqual, TokenType::StarStarEqual,
            TokenType::AmpersandEqual, TokenType::PipeEqual, TokenType::CaretEqual,
            TokenType::LeftShiftEqual, TokenType::RightShiftEqual,
            TokenType::UnsignedRightShiftEqual,
            TokenType::NullishCoalesceEqual => true,
            default => false,
        };
    }
}
