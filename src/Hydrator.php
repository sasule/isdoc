<?php

declare(strict_types=1);
namespace Adawolfa\ISDOC;
use Adawolfa\ISDOC\Data\Value;
use Adawolfa\ISDOC\Data\ValueException;
use Adawolfa\ISDOC\Reflection\Instance;
use Adawolfa\ISDOC\Reflection\MappedProperty;
use Adawolfa\ISDOC\Reflection\Property;
use Adawolfa\ISDOC\Reflection\ReferenceProperty;
use Adawolfa\ISDOC\Reflection\Reflector;

final class Hydrator
{

	private Reflector $reflector;

	/** @var callable[] */
	private array $finishers  = [];
	private int   $depth      = 0;

	/** @var Instance[] */
	private array $references = [];

	public function __construct(Reflector $reflector)
	{
		$this->reflector = $reflector;
	}

	/**
	 * @template T
	 * @param Data            $data
	 * @param class-string<T> $class
	 * @return T
	 * @throws Data\Exception
	 */
	public function hydrate(Data $data, string $class): object
	{
		$this->depth++;

		try {

			$instance = $this->reflector->class($class);

			if ($data->hasValue('@id')) {
				$this->registerReference($data->getValue('@id'), $instance);
			}

			foreach ($instance->getProperties() as $property) {
				$this->hydrateProperty($data, $property);
			}

			return $instance->getInstance();

		} finally {

			if (--$this->depth === 0) {
				$this->finish();
			}

		}
	}

	private function finish(): void
	{
		try {

			foreach ($this->finishers as $finisher) {
				$finisher();
			}

		} finally {
			$this->references = $this->finishers = [];
		}
	}

	/** @throws Data\Exception */
	private function registerReference(Value $value, object $instance): void
	{
		$id = $value->toString();

		if (isset($this->references[$id])) {
			throw Data\Exception::duplicateReferenceId($id);
		}

		$this->references[$id] = $instance;
	}

	/** @throws Data\Exception */
	private function hydrateProperty(Data $data, Property $property): void
	{
		switch (true) {

			case $property instanceof MappedProperty:
				$this->hydrateMappedProperty($data, $property);
				break;

			case $property instanceof ReferenceProperty:
				$this->hydrateReferenceProperty($data, $property);
				break;

		}
	}

	/** @throws Data\Exception */
	private function hydrateReferenceProperty(Data $data, ReferenceProperty $property): void
	{
		if (!$data->hasValue('@ref')) {
			throw Data\Exception::missingReferenceId($data->getPath());
		}

		$this->finishers[] = function() use($data, $property): void {

			$id = $data->getValue('@ref');

			if (!isset($this->references[$id->toString()])) {
				throw Data\Exception::referencedElementNotFound($id->toString(), $id->getPath());
			}

			// TODO: Validation.
			$property->setValue($this->references[$id->toString()]->getInstance());

		};
	}

	/** @throws Data\Exception */
	private function hydrateMappedProperty(Data $data, MappedProperty $property): void
	{
		switch (true) {

			case $property->isPrimitive():
				$this->hydratePrimitiveProperty($data, $property);
				break;

			case $property->isDate():
				$this->hydrateDateProperty($data, $property);
				break;

			case $property->isSimpleContentElement():
				$this->hydrateSimpleContentElementProperty($data, $property);
				break;

			default:
				$this->hydrateComplexProperty($data, $property);
				break;

		}
	}

	/** @throws ValueException */
	private function hydratePrimitiveProperty(Data $data, MappedProperty $property): void
	{
		$property->setValue($data->getValue($property->getMap())->cast($property->getType()));
	}

	/** @throws Data\Exception */
	private function hydrateDateProperty(Data $data, MappedProperty $property): void
	{
		$value = $data->getValue($property->getMap());
		$date  = $value->toDate();

		if ($date === null && !$property->isNullable()) {
			throw Data\Exception::missingRequiredChild($property->getMap(), $value->getPath());
		}

		$property->setValue($date);
	}

	/** @throws Data\Exception */
	private function hydrateSimpleContentElementProperty(Data $data, MappedProperty $property): void
	{
		if (!$data->hasChild($property->getMap()) && !$data->hasValue($property->getMap())) {

			if ($property->isNullable()) {
				$property->setValue(null);
				return;
			}

			throw Data\Exception::missingRequiredChild($property->getMap(), $data->getPath());

		}

		if ($data->hasChild($property->getMap())) {
			$child = $data->getChild($property->getMap());
		} else {
			$child = Data::createEmpty($data, $property->getMap());
		}

		$value = $this->hydrate($child, $property->getType()->getName());

		if (!$value instanceof SimpleContentElement) {
			throw new RuntimeException('Value was expected to be an instance of ' . SimpleContentElement::class . '.');
		}

		if ($data->hasChild($property->getMap())) {
			$value->setContent($child->getValue('#')->toString());
		} else {
			$value->setContent($data->getValue($property->getMap())->toString());
		}

		$property->setValue($value);
	}

	/** @throws Data\Exception */
	private function hydrateComplexProperty(Data $data, MappedProperty $property): void
	{
		if (!$data->hasChild($property->getMap())) {

			if (!$property->isNullable()) {
				throw Data\Exception::missingRequiredChild($property->getMap(), $data->getPath());
			}

			$property->setValue(null);
			return;

		}

		$child = $data->getChild($property->getMap());
		$value = $this->hydrate($child, $property->getType()->getName());

		if ($value instanceof Collection) {

			$collection = $this->reflector->instance($value);

			if (!$collection instanceof Reflection\Collection) {
				throw new RuntimeException('Collection reflection was expected to be instance of ' . Reflection\Collection::class . '.');
			}

			// TODO: Check add() method.
			foreach ($child->getChildList($collection->getMap()) as $itemData) {
				$value->add($this->hydrate($itemData, $collection->getItemClassName()));
			}

		}

		$property->setValue($value);
	}

}