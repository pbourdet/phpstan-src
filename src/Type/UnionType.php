<?php declare(strict_types = 1);

namespace PHPStan\Type;

use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ConstantReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\PropertyReflection;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantBooleanType;

class UnionType implements CompoundType, StaticResolvableType
{

	/** @var \PHPStan\Type\Type[] */
	private $types;

	/**
	 * @param Type[] $types
	 */
	public function __construct(array $types)
	{
		$throwException = function () use ($types): void {
			throw new \PHPStan\ShouldNotHappenException(sprintf(
				'Cannot create %s with: %s',
				self::class,
				implode(', ', array_map(function (Type $type): string {
					return $type->describe(VerbosityLevel::value());
				}, $types))
			));
		};
		if (count($types) < 2) {
			$throwException();
		}
		foreach ($types as $type) {
			if (!($type instanceof UnionType)) {
				continue;
			}

			$throwException();
		}
		$this->types = UnionTypeHelper::sortTypes($types);
	}

	/**
	 * @return \PHPStan\Type\Type[]
	 */
	public function getTypes(): array
	{
		return $this->types;
	}

	/**
	 * @return string[]
	 */
	public function getReferencedClasses(): array
	{
		return UnionTypeHelper::getReferencedClasses($this->getTypes());
	}

	public function accepts(Type $type): bool
	{
		if ($type instanceof CompoundType) {
			return CompoundTypeHelper::accepts($type, $this);
		}

		foreach ($this->getTypes() as $otherType) {
			if ($otherType->accepts($type)) {
				return true;
			}
		}

		return false;
	}

	public function isSuperTypeOf(Type $otherType): TrinaryLogic
	{
		if ($otherType instanceof self || $otherType instanceof IterableType) {
			return $otherType->isSubTypeOf($this);
		}

		$results = [];
		foreach ($this->getTypes() as $innerType) {
			$results[] = $innerType->isSuperTypeOf($otherType);
		}

		return TrinaryLogic::createNo()->or(...$results);
	}

	public function isSubTypeOf(Type $otherType): TrinaryLogic
	{
		$results = [];
		foreach ($this->getTypes() as $innerType) {
			$results[] = $otherType->isSuperTypeOf($innerType);
		}

		return TrinaryLogic::extremeIdentity(...$results);
	}

	public function describe(VerbosityLevel $level): string
	{
		$joinTypes = function (array $types) use ($level): string {
			$typeNames = [];
			foreach ($types as $type) {
				if ($type instanceof IntersectionType) {
					$typeNames[] = sprintf('(%s)', $type->describe($level));
				} else {
					$typeNames[] = $type->describe($level);
				}
			}

			return implode('|', array_unique($typeNames));
		};

		return $level->handle(
			function () use ($joinTypes): string {
				$types = TypeCombinator::union(...array_map(function (Type $type): Type {
					if (
						$type instanceof ConstantType
						&& !$type instanceof ConstantBooleanType
					) {
						return $type->generalize();
					}

					return $type;
				}, $this->types));

				if ($types instanceof UnionType) {
					return $joinTypes($types->getTypes());
				}

				return $joinTypes([$types]);
			},
			function () use ($joinTypes): string {
				$arrayDescription = [];
				$constantArrays = [];
				$commonTypes = [];

				foreach ($this->types as $type) {
					if (!$type instanceof ConstantArrayType) {
						$commonTypes[] = $type;
						continue;
					}

					$constantArrays[] = $type;
					foreach ($type->getKeyTypes() as $i => $keyType) {
						if (!isset($arrayDescription[$keyType->getValue()])) {
							$arrayDescription[$keyType->getValue()] = [
								'key' => $keyType,
								'value' => $type->getValueTypes()[$i],
								'count' => 1,
							];
							continue;
						}

						$arrayDescription[$keyType->getValue()] = [
							'key' => $keyType,
							'value' => TypeCombinator::union(
								$arrayDescription[$keyType->getValue()]['value'],
								$type->getValueTypes()[$i]
							),
							'count' => $arrayDescription[$keyType->getValue()]['count'] + 1,
						];
					}
				}

				$someKeyCountIsHigherThanOne = false;
				foreach ($arrayDescription as $value) {
					if ($value['count'] > 1) {
						$someKeyCountIsHigherThanOne = true;
						break;
					}
				}

				if (!$someKeyCountIsHigherThanOne) {
					return $joinTypes(UnionTypeHelper::sortTypes(array_merge(
						$commonTypes,
						$constantArrays
					)));
				}

				$constantArraysCount = count($constantArrays);
				$constantArraysDescriptions = [];
				foreach ($arrayDescription as $keyTypeValue => $value) {
					$constantArraysDescriptions[] = sprintf(
						'%s%s => %s',
						$value['count'] < $constantArraysCount ? '?' : '',
						$value['key']->describe(VerbosityLevel::value()),
						$value['value']->describe(VerbosityLevel::value())
					);
				}

				$description = '';
				if (count($commonTypes) > 0) {
					$description = $joinTypes($commonTypes);
					if (count($constantArraysDescriptions) > 0) {
						$description .= '|';
					}
				}

				if (count($constantArraysDescriptions) > 0) {
					$description .= 'array(' . implode(', ', $constantArraysDescriptions) . ')';
				}

				return $description;
			}
		);
	}

	private function hasInternal(
		callable $canCallback,
		callable $hasCallback
	): bool
	{
		$typesWithCan = 0;
		$typesWithHas = 0;
		foreach ($this->types as $type) {
			if ($canCallback($type)->no()) {
				continue;
			}
			$typesWithCan++;
			if (!$hasCallback($type)) {
				continue;
			}
			$typesWithHas++;
		}

		return $typesWithCan > 0 && $typesWithHas === $typesWithCan;
	}

	public function canAccessProperties(): TrinaryLogic
	{
		return $this->unionResults(function (Type $type): TrinaryLogic {
			return $type->canAccessProperties();
		});
	}

	public function hasProperty(string $propertyName): bool
	{
		return $this->hasInternal(
			function (Type $type): TrinaryLogic {
				return $type->canAccessProperties();
			},
			function (Type $type) use ($propertyName): bool {
				return $type->hasProperty($propertyName);
			}
		);
	}

	public function getProperty(string $propertyName, Scope $scope): PropertyReflection
	{
		foreach ($this->types as $type) {
			if ($type->canAccessProperties()->no()) {
				continue;
			}
			return $type->getProperty($propertyName, $scope);
		}

		throw new \PHPStan\ShouldNotHappenException();
	}

	public function canCallMethods(): TrinaryLogic
	{
		return $this->unionResults(function (Type $type): TrinaryLogic {
			return $type->canCallMethods();
		});
	}

	public function hasMethod(string $methodName): bool
	{
		return $this->hasInternal(
			function (Type $type): TrinaryLogic {
				return $type->canCallMethods();
			},
			function (Type $type) use ($methodName): bool {
				return $type->hasMethod($methodName);
			}
		);
	}

	public function getMethod(string $methodName, Scope $scope): MethodReflection
	{
		foreach ($this->types as $type) {
			if ($type->canCallMethods()->no()) {
				continue;
			}
			return $type->getMethod($methodName, $scope);
		}

		throw new \PHPStan\ShouldNotHappenException();
	}

	public function canAccessConstants(): TrinaryLogic
	{
		return $this->unionResults(function (Type $type): TrinaryLogic {
			return $type->canAccessConstants();
		});
	}

	public function hasConstant(string $constantName): bool
	{
		return $this->hasInternal(
			function (Type $type): TrinaryLogic {
				return $type->canAccessConstants();
			},
			function (Type $type) use ($constantName): bool {
				return $type->hasConstant($constantName);
			}
		);
	}

	public function getConstant(string $constantName): ConstantReflection
	{
		foreach ($this->types as $type) {
			if ($type->canAccessConstants()->no()) {
				continue;
			}
			return $type->getConstant($constantName);
		}

		throw new \PHPStan\ShouldNotHappenException();
	}

	public function resolveStatic(string $className): Type
	{
		return new self(UnionTypeHelper::resolveStatic($className, $this->getTypes()));
	}

	public function changeBaseClass(string $className): StaticResolvableType
	{
		return new self(UnionTypeHelper::changeBaseClass($className, $this->getTypes()));
	}

	public function isIterable(): TrinaryLogic
	{
		return $this->unionResults(function (Type $type): TrinaryLogic {
			return $type->isIterable();
		});
	}

	public function getIterableKeyType(): Type
	{
		return $this->unionTypes(function (Type $type): Type {
			return $type->getIterableKeyType();
		});
	}

	public function getIterableValueType(): Type
	{
		return $this->unionTypes(function (Type $type): Type {
			return $type->getIterableValueType();
		});
	}

	public function isOffsetAccessible(): TrinaryLogic
	{
		return $this->unionResults(function (Type $type): TrinaryLogic {
			return $type->isOffsetAccessible();
		});
	}

	public function getOffsetValueType(Type $offsetType): Type
	{
		return $this->unionTypes(function (Type $type) use ($offsetType): Type {
			return $type->getOffsetValueType($offsetType);
		});
	}

	public function setOffsetValueType(?Type $offsetType, Type $valueType): Type
	{
		return $this->unionTypes(function (Type $type) use ($offsetType, $valueType): Type {
			return $type->setOffsetValueType($offsetType, $valueType);
		});
	}

	public function isCallable(): TrinaryLogic
	{
		return $this->unionResults(function (Type $type): TrinaryLogic {
			return $type->isCallable();
		});
	}

	/**
	 * @param \PHPStan\Analyser\Scope $scope
	 * @return \PHPStan\Reflection\ParametersAcceptor[]
	 */
	public function getCallableParametersAcceptors(Scope $scope): array
	{
		foreach ($this->types as $type) {
			if ($type->isCallable()->no()) {
				continue;
			}

			return $type->getCallableParametersAcceptors($scope);
		}

		throw new \PHPStan\ShouldNotHappenException();
	}

	public function isCloneable(): TrinaryLogic
	{
		return $this->unionResults(function (Type $type): TrinaryLogic {
			return $type->isCloneable();
		});
	}

	public function toBoolean(): BooleanType
	{
		/** @var BooleanType $type */
		$type = $this->unionTypes(function (Type $type): BooleanType {
			return $type->toBoolean();
		});

		return $type;
	}

	public function toNumber(): Type
	{
		$type = $this->unionTypes(function (Type $type): Type {
			return $type->toNumber();
		});

		return $type;
	}

	public function toString(): Type
	{
		$type = $this->unionTypes(function (Type $type): Type {
			return $type->toString();
		});

		return $type;
	}

	public function toInteger(): Type
	{
		$type = $this->unionTypes(function (Type $type): Type {
			return $type->toInteger();
		});

		return $type;
	}

	public function toFloat(): Type
	{
		$type = $this->unionTypes(function (Type $type): Type {
			return $type->toFloat();
		});

		return $type;
	}

	public function toArray(): Type
	{
		$type = $this->unionTypes(function (Type $type): Type {
			return $type->toArray();
		});

		return $type;
	}

	/**
	 * @param mixed[] $properties
	 * @return Type
	 */
	public static function __set_state(array $properties): Type
	{
		return new self($properties['types']);
	}

	private function unionResults(callable $getResult): TrinaryLogic
	{
		return TrinaryLogic::extremeIdentity(...array_map($getResult, $this->types));
	}

	protected function unionTypes(callable $getType): Type
	{
		return TypeCombinator::union(...array_map($getType, $this->types));
	}

}
