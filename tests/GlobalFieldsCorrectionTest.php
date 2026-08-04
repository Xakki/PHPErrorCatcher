<?php

namespace Xakki\PhpErrorCatcher\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Xakki\PhpErrorCatcher\PhpErrorCatcher;
use Xakki\PhpErrorCatcher\dto\LogData;

/**
 * Captures LogData instead of dispatching them to storages/viewers.
 */
class CapturingCatcher extends PhpErrorCatcher
{
    /** @var LogData[] */
    public $captured = array();

    protected function add(LogData $logData)
    {
        $this->captured[] = $logData;
    }
}

/**
 * End-to-end regression: values written through addGlobalField() are merged into the
 * context AFTER the corrector call at the top of log(), so they used to bypass it.
 * The motivating production defect: addGlobalField('smq_id', $row['smq_id']) hands over
 * a PHP 5.6 DB string while direct log() call sites pass int / null — three json types
 * for one OpenSearch field, and the whole document gets rejected.
 */
class GlobalFieldsCorrectionTest extends TestCase
{
    /** @var CapturingCatcher */
    private $catcher;

    public function setUp()
    {
        $ref = new ReflectionClass('Xakki\\PhpErrorCatcher\\Tests\\CapturingCatcher');
        $this->catcher = $ref->newInstanceWithoutConstructor();
        $this->setOwner($this->catcher);
    }

    public function tearDown()
    {
        $this->setOwner(null);
        $this->catcher = null;
    }

    /**
     * @param PhpErrorCatcher|null $obj
     */
    private function setOwner($obj)
    {
        $prop = new ReflectionProperty('Xakki\\PhpErrorCatcher\\PhpErrorCatcher', '_obj');
        $prop->setAccessible(true);
        $prop->setValue(null, $obj);
    }

    /**
     * @return array
     */
    private function lastFields()
    {
        $this->assertCount(1, $this->catcher->captured);
        return $this->catcher->captured[0]->fields;
    }

    /**
     * @return array
     */
    private function readLogFields()
    {
        $prop = new ReflectionProperty('Xakki\\PhpErrorCatcher\\PhpErrorCatcher', 'logFields');
        $prop->setAccessible(true);
        return $prop->getValue($this->catcher);
    }

    public function testGlobalIdIsCorrectedToString()
    {
        PhpErrorCatcher::addGlobalField('smq_id', 2921172);
        $this->catcher->error('boom');

        $fields = $this->lastFields();
        $this->assertSame('2921172', $fields['smq_id']);
    }

    /**
     * All three real call sites must converge on the same json type.
     */
    public function testGlobalIdConvergesAcrossCallSites()
    {
        $inputs = array('2921172', 2921172, 0, null);
        $expected = array('2921172', '2921172', '0', null);

        foreach ($inputs as $i => $input) {
            $ref = new ReflectionClass('Xakki\\PhpErrorCatcher\\Tests\\CapturingCatcher');
            $this->catcher = $ref->newInstanceWithoutConstructor();
            $this->setOwner($this->catcher);

            PhpErrorCatcher::addGlobalField('smq_id', $input);
            $this->catcher->error('boom');

            $fields = $this->lastFields();
            $this->assertSame($expected[$i], $fields['smq_id']);
        }
    }

    public function testGlobalLegacyBooleanString()
    {
        PhpErrorCatcher::addGlobalField('is_bot', 'F');
        $this->catcher->error('boom');

        $fields = $this->lastFields();
        $this->assertSame(false, $fields['is_bot']);
    }

    public function testGlobalArrayIsEncodedNotCast()
    {
        PhpErrorCatcher::addGlobalField('retry_count', array(1, 2));
        $this->catcher->error('boom');

        $fields = $this->lastFields();
        $this->assertSame('[1,2]', $fields['retry_count']);
    }

    /**
     * Precedence must be unchanged: a context key still beats the global of the same name.
     */
    public function testContextStillWinsOverGlobal()
    {
        PhpErrorCatcher::addGlobalField('smq_id', '111');
        $this->catcher->error('boom', array('smq_id' => '222'));

        $fields = $this->lastFields();
        $this->assertSame('222', $fields['smq_id']);
    }

    /**
     * The corrector runs on a COPY: the stored globals keep what the app put there,
     * and repeated log() calls in one request stay stable.
     */
    public function testStoredGlobalsAreNotMutatedInPlace()
    {
        PhpErrorCatcher::addGlobalField('smq_id', '2921172');
        PhpErrorCatcher::addGlobalField('payload', array('a' => 1));

        $this->catcher->error('first');
        $stored = $this->readLogFields();
        $this->assertSame('2921172', $stored['smq_id']);
        $this->assertInternalType('string', $stored['smq_id']);
        $this->assertSame(array('a' => 1), $stored['payload']);

        $this->catcher->error('second');
        $this->assertCount(2, $this->catcher->captured);
        $this->assertSame('2921172', $this->catcher->captured[0]->fields['smq_id']);
        $this->assertSame('2921172', $this->catcher->captured[1]->fields['smq_id']);
        // 'payload' has no rule, so its structure is left alone.
        $this->assertSame(array('a' => 1), $this->catcher->captured[1]->fields['payload']);
    }

    public function testGlobalTagStaysString()
    {
        PhpErrorCatcher::addGlobalTag(123);
        $this->catcher->error('boom');

        $fields = $this->lastFields();
        $this->assertSame('123', $fields[PhpErrorCatcher::FIELD_TAG]);
    }

    /**
     * The globals pass must not reach the fields log() types deliberately between the
     * two correction points: it corrects $globals only, never the merged $context.
     */
    public function testExceptionFieldsAreNotReCoerced()
    {
        PhpErrorCatcher::addGlobalField('smq_id', '2921172');
        $this->catcher->error(new \RuntimeException('boom', 7));

        $fields = $this->lastFields();
        $this->assertSame('2921172', $fields['smq_id']);
        $this->assertSame(7, $fields['exception_code']);
        $this->assertSame('RuntimeException', $fields['exception']);
        // Consumed by log() itself, never leaks into fields.
        $this->assertArrayNotHasKey(PhpErrorCatcher::FIELD_TRACE, $fields);
        $this->assertNotEmpty($this->catcher->captured[0]->trace);
    }
}
