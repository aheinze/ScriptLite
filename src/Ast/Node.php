<?php

declare(strict_types=1);

namespace ScriptLite\Ast;

/**
 * Marker interface for all AST nodes.
 * We use an interface (not abstract class) to keep the node hierarchy flat —
 * PHP's instanceof checks on interfaces are very fast in the Zend Engine.
 */
interface Node {}
