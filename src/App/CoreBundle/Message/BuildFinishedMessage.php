<?php

namespace App\CoreBundle\Message;

use App\CoreBundle\Entity\Build;

class BuildFinishedMessage extends AbstractMessage
{
    public function __construct(Build $build)
    {
        parent::__construct($build);
    }
}