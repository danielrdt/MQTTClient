<?php

declare(strict_types=1);

namespace PTLS;

/**
 * Simple buffering
 */
class Buffer
{
    private $buffer;

    public function set($data)
    {
        $this->buffer = $data;
        return $this;
    }

    public function append($data)
    {
        $this->buffer .= $data;
        return $this;
    }

    public function flush()
    {
        $data = $this->buffer;
        $this->buffer = null;
        return $data;
    }

    public function get()
    {
        return $this->buffer;
    }

    public function length()
    {
        if (!$this->buffer) {
            return 0;
        }
        return strlen($this->buffer);
    }
}
