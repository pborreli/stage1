<?php

namespace App\CoreBundle\Message;

use App\CoreBundle\Entity\Build;

use Exception;

abstract class AbstractMessage implements MessageInterface
{
    private $object;

    public function __construct($object)
    {
        $this->object = $object;
    }

    public function getObject()
    {
        return $this->object;
    }

    public function toArray()
    {
        return [
            'event' => $this->getEvent(),
            'channel' => $this->getChannel(),
            'timestamp' => microtime(true),
            'data' => $this->getData(),
        ];
    }

    public function __toString()
    {
        try {
            return json_encode($this->toArray());
        } catch (Exception $e) {
            echo $e->getMessage();
            return get_class($e).': '.$e->getMessage();
        }
    }

    public function getEvent()
    {
        $className = get_class($this);
        $className = substr($className, strrpos($className, '\\') + 1, -7);
        $className = strtolower(preg_replace('~(?<=\\w)([A-Z])~', '.$1', $className));

        return $className;
    }

    public function getData()
    {
        $className = get_class($this->object);
        $className = substr($className, strrpos($className, '\\') + 1);
        $className = strtolower(preg_replace('~(?<=\\w)([A-Z])~', '_$1', $className));

        return [$className => $this->object->asMessage()];
    }

    public function getChannel()
    {
        return $this->object->getChannel();
    }
}