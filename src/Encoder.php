<?php

declare(strict_types=1);
namespace Adawolfa\ISDOC;
use Symfony\Component\Serializer\Encoder\XmlEncoder;

final class Encoder
{

	private XmlEncoder $encoder;
	private Serializer $serializer;

	public function __construct(XmlEncoder $encoder, Serializer $serializer)
	{
		$this->encoder    = $encoder;
		$this->serializer = $serializer;
	}

	/** @throws EncoderException */
	public function encode(Schema\Invoice $invoice): string
	{
		try {

			return $this->encoder->encode($this->serializer->serialize($invoice), $this->encoder::FORMAT, [
				$this->encoder::ROOT_NODE_NAME => 'Invoice',
				$this->encoder::FORMAT_OUTPUT  => true,
			]);

		} catch (SerializerException $exception) {
			throw new EncoderException('An error has been encountered during XML encoding.', 0, $exception);
		}
	}

}