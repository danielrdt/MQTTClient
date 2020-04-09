<?php

declare(strict_types=1);

namespace PTLS\Extensions;

use PTLS\Core;

abstract class ExtensionAbstract
{
    protected $extType;
    protected $legnth;
    private $core;

    public function __construct(Core $core)
    {
        $this->core = $core;
    }

    abstract public function onEncodeClientHello($type, $data);

    abstract public function onDecodeClientHello();

    abstract public function onDecodeServerHello();

    protected function decodeHeader()
    {
        // MsgType
        $header = Core::_pack('C', 0)
            . Core::_pack('C', $this->extType)
            // Length
            . Core::_pack('n', $this->length);

        return $header;
    }
}
