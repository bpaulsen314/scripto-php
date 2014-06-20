<?php
namespace W3glue\Scripto;

use \PHPUnit_Framework_TestCase as TestCase;

class ScriptStub extends Script
{
    protected function _run($arguments = array())
    {
        $this->log("Something");
    }
}

class ScriptTest extends TestCase
{
    public function testArgumentValidation()
    {
        $script = new ScriptStub();
        $script->start([]);
    }
}
