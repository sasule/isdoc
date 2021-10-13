<?php

declare(strict_types=1);
namespace Adawolfa\ISDOC;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;

/**
 * Automatic object to array conversion.
 */
trait ToArray
{

	public function toArray(): array
	{
		try {
			$reflection = new ReflectionClass($this);
		} catch (ReflectionException $exception) {
			throw new RuntimeException('Failed to create reflection.', 0, $exception);
		}

		$data       = [];
		$properties = [];

		do {

			foreach ($reflection->getProperties(~ReflectionProperty::IS_STATIC) as $property) {

				if (isset($properties[$property->getName()])) {
					continue;
				}

				$properties[$property->getName()] = $property;

			}

			$reflection = $reflection->getParentClass();

		} while($reflection !== false);

		foreach ($properties as $property) {

			$value = $this->{$property->getName()};

			if ($value instanceof Arrayable) {
				$value = $value->toArray();
			}

			$data[$property->getName()] = $value;

		}

		return $data;
	}

}