<?php

/*
 +----------------------------------------------------------------------+
 | Zephir Language                                                      |
 +----------------------------------------------------------------------+
 | Copyright (c) 2013 Zephir Team                                       |
 +----------------------------------------------------------------------+
 | This source file is subject to version 1.0 of the Zephir license,    |
 | that is bundled with this package in the file LICENSE, and is        |
 | available through the world-wide-web at the following url:           |
 | http://www.zephir-lang.com/license                                   |
 |                                                                      |
 | If you did not receive a copy of the Zephir license and are unable   |
 | to obtain it through the world-wide-web, please send a note to       |
 | license@zephir-lang.com so we can mail you a copy immediately.       |
 +----------------------------------------------------------------------+
*/

/**
 * FetchOperator
 *
 * Fetch is a special operator that checks if an expression 'isset' and then obtain the value
 * without calculating the hash key twice
 */
class FetchOperator extends BaseOperator
{

	/**
	 *
	 * @param array $expression
	 * @param \CompilationContext $compilationContext
	 * @return \CompiledExpression
	 */
	public function compile($expression, CompilationContext $compilationContext)
	{

		$compilationContext->headersManager->add('kernel/array');

		$variable = $compilationContext->symbolTable->getVariableForWrite($expression['left']['value'], $compilationContext, $expression['left']);
		if ($variable->getType() != 'variable') {
			throw new CompilerException('Cannot use variable type: ' . $variable->gettype() . ' in "fetch" operator', $expression);
		}

		/**
		 * return_value must not be observed
		 */
		if ($variable->getName() != 'return_value') {

			/*
			 * @todo use a read detector here
			 */

			$readOnly = false;
			$numberMutations = $compilationContext->symbolTable->getExpectedMutations($variable->getName());
			if ($numberMutations == 1) {
				if ($variable->getNumberMutations() == 1) {
					$variable->setIsInitialized(true);
					$variable->setMemoryTracked(false);
					$variable->setDynamicType('undefined');
					$readOnly = true;
				}
			}

			if (!$readOnly) {
				$variable->setIsInitialized(true);
				$variable->observeVariant($compilationContext);
				$variable->setDynamicType('undefined');
			}

		} else {
			$variable = $compilationContext->symbolTable->getTempVariableForObserve('variable', $compilationContext, $expression);
		}

		$evalVariable = $compilationContext->symbolTable->getVariableForRead($expression['right']['left']['value'], $compilationContext, $expression['right']['left']);
		if ($evalVariable->getType() != 'variable') {
			throw new CompiledException("Variable type: " . $variable->getType() . " cannot be used as array/object", $expression['right']);
		}

		$dynamicType = $evalVariable->getDynamicType();
		if ($dynamicType != 'undefined' && $dynamicType != 'array' && $dynamicType != 'object') {
			$compilationContext->logger->warning('Possible attempt to use non array/object in fetch operator', 'non-valid-fetch', $expression['right']);
		}

		if ($readOnly) {
			$flags = '1';
		} else {
			$flags = '0';
		}

		switch ($expression['right']['type']) {
			case 'array-access':
				$expr = new Expression($expression['right']['right']);
				$expr->setReadOnly(true);
				$resolvedExpr = $expr->compile($compilationContext);
				switch ($resolvedExpr->getType()) {
					case 'int':
					case 'long':
					case 'uint':
						return new CompiledExpression('bool', 'zephir_array_isset_long_fetch(&' . $variable->getName() . ', ' . $evalVariable->getName() . ', ' . $resolvedExpr->getCode() . ', ' . $flags . ' TSRMLS_CC)', $expression);
					case 'string':
						return new CompiledExpression('bool', 'zephir_array_isset_string_fetch(&' . $variable->getName() . ', ' . $evalVariable->getName() . ', SS("' . $resolvedExpr->getCode() . '"), ' . $flags . ' TSRMLS_CC)', $expression);
					case 'variable':
						$indexVariable = $compilationContext->symbolTable->getVariableForRead($resolvedExpr->getCode(), $compilationContext, $expression['right']['left']);
						switch ($indexVariable->getType()) {
							case 'int':
							case 'long':
							case 'uint':
								return new CompiledExpression('bool', 'zephir_array_isset_long_fetch(&' . $variable->getName() . ', ' . $evalVariable->getName() . ', ' . $indexVariable->getName() . ', ' . $flags . ' TSRMLS_CC)', $expression);
							case 'string':
							case 'variable':
								return new CompiledExpression('bool', 'zephir_array_isset_fetch(&' . $variable->getName() . ', ' . $evalVariable->getName() . ', ' . $indexVariable->getName() . ', ' . $flags . ' TSRMLS_CC)', $expression);
							default:
								throw new CompilerException('[' . $indexVariable->getType() . ']', $expression);
						}
						break;
					default:
						throw new CompilerException('[' . $expression['right']['right']['type'] . ']', $expression);
				}
				break;
			case 'property-access':
			case 'property-dynamic-access':
				/* @todo, implement this */
				return new CompiledExpression('bool', 'false', $expression);
			default:
				throw new CompilerException('[' . $expression['right']['type'] . ']', $expression);
		}

	}

}