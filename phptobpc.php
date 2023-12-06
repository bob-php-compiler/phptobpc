<?php

use PhpParser\Error;
use PhpParser\ParserFactory;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\Node\Expr;
use PhpParser\PrettyPrinter;

if ($argc != 2) {
    echo "Usage: php phptobpc.php file.php\n";
    exit(1);
}

$code = file_get_contents($argv[1]);

spl_autoload_register(function ($class) {
    require str_replace('\\', '/', $class) . '.php';
});

class PhpToBpcConverter extends \PhpParser\NodeVisitorAbstract
{
    protected $namespacedFuncs = array();

    public function enterNode(Node $node)
    {
        if ($node instanceof Stmt\Function_) {
            if (count($node->namespacedName->parts) > 1) {
                $this->namespacedFuncs[$node->namespacedName->toString()] = true;
            }
        }
    }

    public function leaveNode(Node $node) {
        if ($node instanceof Expr\Array_) {
             $node->setAttribute('kind', Expr\Array_::KIND_LONG);
        } elseif ($node instanceof Node\Name) {
            // leaveNode是从内到外的,所以先遇到Node\Name,再遇到Expr\FuncCall
            // 有attribute namespacedName,说明这个name不确定,unresolved
            // function call会这样
            if (!$node->getAttribute('namespacedName')) {
                return new Node\Name($node->toString());
            }
        } elseif ($node instanceof Expr\FuncCall) {
            $namespacedName = $node->name->getAttribute('namespacedName');
            if ($namespacedName) {
                $func = $namespacedName->toString();
                if (isset($this->namespacedFuncs[$func])) {
                    $node->name = new Node\Name($func);
                }
            }
        } elseif (   $node instanceof Stmt\Class_
                  || $node instanceof Stmt\Interface_
                  || $node instanceof Stmt\Trait_
        ) {
            $node->name = $node->namespacedName->toString();
        } elseif ($node instanceof Stmt\Function_) {
            $node->name = $node->namespacedName->toString();
            $node->returnType = null;
        } elseif (   $node instanceof Stmt\ClassMethod
                  || $node instanceof Expr\Closure
        ) {
            $node->returnType = null;
        } elseif ($node instanceof Stmt\Const_) {
            $defines = array();
            foreach ($node->consts as $const) {
                $defines[] = new Stmt\Expression(
                     new Expr\FuncCall(
                        new Node\Name('define'),
                        array(
                            new Node\Arg(new Node\Scalar\String_(
                                str_replace('\\', '_', $const->namespacedName->toString())
                            )),
                            new Node\Arg($const->value)
                        )
                    )
                );
            }
            return $defines;
        } elseif ($node instanceof Expr\ConstFetch) {
            $node->name = new Node\Name(
                str_replace('\\', '_', $node->name->toString())
            );
        } elseif ($node instanceof Expr\Ternary) {
            if ($node->if === null) {
                $node->if = $node->cond;
            }
        } elseif ($node instanceof Expr\BinaryOp\Coalesce) {
            return new Expr\Ternary(
                new Expr\Isset_(array($node->left)),    // cond
                $node->left,                            // if
                $node->right                            // else
            );
        } elseif (   $node instanceof Stmt\If_
                  && $node->cond instanceof Expr\FuncCall
                  && $node->cond->name instanceof Node\Name
                  && $node->cond->name->toString() == 'defined'
                  && count($node->cond->args) == 1
                  && $node->cond->args[0] instanceof Node\Arg
                  && $node->cond->args[0]->value instanceof Node\Scalar\String_
                  && $node->cond->args[0]->value->value == '__BPC__'
        ) {
            return $node->stmts;
        } elseif ($node instanceof Stmt\Namespace_) {
            // returning an array merges is into the parent array
            return $node->stmts;
        } elseif (   $node instanceof Stmt\Use_
                  || $node instanceof Stmt\GroupUse
        ) {
            // remove use nodes altogether
            return NodeTraverser::REMOVE_NODE;
        } elseif ($node instanceof Stmt\Declare_) {
            // remove declare(xxx)
            return NodeTraverser::REMOVE_NODE;
        } elseif ($node instanceof Stmt\ClassConst) {
            // remove class const visibility
            $node->flags = 0;
        }
    }
}

$parser        = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
$traverser     = new NodeTraverser;
$prettyPrinter = new PrettyPrinter\Standard;

$traverser->addVisitor(new NameResolver);
$traverser->addVisitor(new PhpToBpcConverter);

try {
    $stmts = $parser->parse($code);
    //echo (new \PhpParser\NodeDumper)->dump($stmts), "\n";
    $stmts = $traverser->traverse($stmts);
    echo $prettyPrinter->prettyPrintFile($stmts);
} catch (Error $e) {
    fwrite(STDERR, 'Parse Error: ' . $e->getMessage() . "\n");
    exit(1);
}
