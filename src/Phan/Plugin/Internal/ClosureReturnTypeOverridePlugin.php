<?php declare(strict_types=1);
namespace Phan\Plugin\Internal;

use Phan\CodeBase;
use Phan\Analysis\ArgumentType;
use Phan\Analysis\PostOrderAnalysisVisitor;
use Phan\AST\UnionTypeVisitor;
use Phan\Config;
use Phan\Issue;
use Phan\Language\Context;
use Phan\Language\Element\Func;
use Phan\Language\Element\FunctionInterface;
use Phan\Language\Element\Method;
use Phan\Language\Type\ClosureType;
use Phan\Language\UnionType;
use Phan\PluginV2\ReturnTypeOverrideCapability;
use Phan\PluginV2;
use ast\Node;

/**
 * NOTE: This is automatically loaded by phan. Do not include it in a config.
 *
 * TODO: Refactor this.
 */
final class ClosureReturnTypeOverridePlugin extends PluginV2 implements ReturnTypeOverrideCapability {
    /**
     * @return \Closure[]
     */
    private static function getReturnTypeOverridesStatic(CodeBase $code_base) : array
    {
        $call_user_func_callback = static function(
            CodeBase $code_base,
            Context $context,
            Func $function,
            array $args
        ) : UnionType {
            $element_types = new UnionType();
            if (\count($args) < 1) {
                return $element_types;
            }
            $function_like_list = UnionTypeVisitor::functionLikeListFromNodeAndContext($code_base, $context, $args[0], true);
            if (\count($function_like_list) === 0) {
                return $element_types;
            }
            $arguments = \array_slice($args, 1);
            foreach ($function_like_list as $function_like) {
                if ($function_like->hasDependentReturnType()) {
                    $element_types->addUnionType($function_like->getDependentReturnType($code_base, $context, $arguments));
                } else {
                    $element_types->addUnionType($function_like->getUnionType());
                }
            }
            self::analyzeFunctionAndNormalArgumentList($code_base, $context, $function_like_list, $arguments);
            return $element_types;
        };
        $call_user_func_array_callback = static function(
            CodeBase $code_base,
            Context $context,
            Func $function,
            array $args
        ) : UnionType {
            $element_types = new UnionType();
            if (\count($args) < 2) {
                return $element_types;
            }
            // Currently, only analyze calls of the form call_user_func_array(callable expression, [$arg1, $arg2...])
            $function_like_list = UnionTypeVisitor::functionLikeListFromNodeAndContext($code_base, $context, $args[0], true);
            if (\count($function_like_list) === 0) {
                return $element_types;
            }
            $arg_array_node = $args[1];
            if (($arg_array_node instanceof Node) && $arg_array_node->kind === \ast\AST_ARRAY) {
                $arguments = [];
                // TODO: Sanity check keys.
                foreach ($arg_array_node->children as $child) {
                    $arguments[] = $child->children['value'];
                }
            } else {
                $arguments = null;
            }
            $element_types = new UnionType();

            foreach ($function_like_list as $function_like) {
                if ($arguments !== null && $function_like->hasDependentReturnType()) {
                    $element_types->addUnionType($function_like->getDependentReturnType($code_base, $context, $arguments));
                } else {
                    $element_types->addUnionType($function_like->getUnionType());
                }
            }
            if ($arguments !== null) {
                self::analyzeFunctionAndNormalArgumentList($code_base, $context, $function_like_list, $arguments);
            }
            return $element_types;
        };
        $from_callable_callback = static function(
            CodeBase $code_base,
            Context $context,
            Method $unused_method,
            array $args
        ) : UnionType {
            if (\count($args) < 1) {
                return ClosureType::instance(false)->asUnionType();
            }
            $function_like_list = UnionTypeVisitor::functionLikeListFromNodeAndContext($code_base, $context, $args[0], true);
            if (\count($function_like_list) === 0) {
                return ClosureType::instance(false)->asUnionType();
            }
            $closure_types = new UnionType();
            foreach ($function_like_list as $function_like) {
                $closure_types->addType(ClosureType::instanceWithClosureFQSEN($function_like->getFQSEN()));
            }
            return $closure_types;
        };
        return [
            // call
            'call_user_func'            => $call_user_func_callback,
            'forward_static_call'       => $call_user_func_callback,
            'call_user_func_array'      => $call_user_func_array_callback,
            'forward_static_call_array' => $call_user_func_array_callback,
            'Closure::fromCallable'     => $from_callable_callback,
        ];
    }

    /**
     * @return \Closure[]
     */
    public function getReturnTypeOverrides(CodeBase $code_base) : array
    {
        return self::getReturnTypeOverridesStatic($code_base);
    }

    public static function createNormalArgumentCache(CodeBase $code_base, Context $context) : \Closure
    {
        $cache = [];
        return function($argument, int $i) use($code_base, $context, &$cache) : UnionType {
            $argument_type = $cache[$i] ?? null;
            if (isset($argument_type)) {
                return $argument_type;
            }
            $argument_type = UnionTypeVisitor::unionTypeFromNode(
                $code_base,
                $context,
                $argument,
                true
            );
            $cache[$i] = $argument_type;
            return $argument_type;
        };
    }

    /**
     * Analyze a function which is called with the un-transformed types from $arguments.
     *
     * @param CodeBase $code_base
     * @param Context $context
     * @param FunctionInterface[] $function_like_list
     * @param Node[]|string[]|int[] $arguments
     *
     * @return void
     */
    private static function analyzeFunctionAndNormalArgumentList(CodeBase $code_base, Context $context, array $function_like_list, array $arguments) {
        $get_argument_type = self::createNormalArgumentCache($code_base, $context);
        foreach ($function_like_list as $function_like) {
            ArgumentType::analyzeForCallback($function_like, $arguments, $context, $code_base, $get_argument_type);
        }
        if (Config::get_quick_mode()) {
            // Keep it fast, don't recurse.
            return;
        }

        $argument_types = [];
        foreach ($arguments as $i => $argument) {
            $argument_types[] = $get_argument_type($argument, $i);
        }
        $analyzer = new PostOrderAnalysisVisitor($code_base, $context, null);
        foreach ($function_like_list as $function_like) {
            $analyzer->analyzeCallableWithArgumentTypes($argument_types, $function_like);
        }
    }
}