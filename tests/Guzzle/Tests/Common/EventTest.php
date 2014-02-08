<?php

namespace Guzzle\Tests\Common;

use Guzzle\Common\Event;

class EventTest extends \PHPUnit_Framework_TestCase
{
    public function testStopsPropagation()
    {
        $e = new Event();
        $this->assertFalse($e->isPropagationStopped());
        $e->stopPropagation();
        $this->assertTrue($e->isPropagationStopped());
    }
}
