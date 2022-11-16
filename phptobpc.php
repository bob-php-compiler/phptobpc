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
    public function leaveNode(Node $node) {
        if ($node instanceof Expr\Array_) {
             $node->setAttribute('kind', Expr\Array_::KIND_LONG);
        } elseif ($node instanceof Node\Name) {
            return new Node\Name($node->toString());
        } elseif (   $node instanceof Stmt\Class_
                  || $node instanceof Stmt\Interface_
                  || $node instanceof Stmt\Function_
        ) {
            $node->name = $node->namespacedName->toString();
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
        } elseif ($node instanceof Stmt\Namespace_) {
            // returning an array merges is into the parent array
            return $node->stmts;
        } elseif (   $node instanceof Stmt\Use_
                  || $node instanceof Stmt\GroupUse
        ) {
            // remove use nodes altogether
            return NodeTraverser::REMOVE_NODE;
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
    echo 'Parse Error: ', $e->getMessage();
    exit(1);
}
