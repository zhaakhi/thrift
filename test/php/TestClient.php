<?php

namespace test\php;

require_once __DIR__.'/../../lib/php/lib/Thrift/ClassLoader/ThriftClassLoader.php';

use Thrift\ClassLoader\ThriftClassLoader;

if (!isset($GEN_DIR)) {
  $GEN_DIR = 'gen-php';
}
if (!isset($MODE)) {
  $MODE = 'normal';
}

$loader = new ThriftClassLoader();
$loader->registerNamespace('Thrift', __DIR__ . '/../../lib/php/lib');
if ($GEN_DIR === 'gen-php-psr4') {
  $loader->registerNamespace('ThriftTest', $GEN_DIR);
} else {
  $loader->registerDefinition('ThriftTest', $GEN_DIR);
}
$loader->register();

/*
 * Licensed to the Apache Software Foundation (ASF) under one
 * or more contributor license agreements. See the NOTICE file
 * distributed with this work for additional information
 * regarding copyright ownership. The ASF licenses this file
 * to you under the Apache License, Version 2.0 (the
 * "License"); you may not use this file except in compliance
 * with the License. You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing,
 * software distributed under the License is distributed on an
 * "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY
 * KIND, either express or implied. See the License for the
 * specific language governing permissions and limitations
 * under the License.
 */

/** Include the Thrift base */
/** Include the protocols */
use Thrift\Protocol\TBinaryProtocol;
use Thrift\Protocol\TBinaryProtocolAccelerated;
use Thrift\Protocol\TCompactProtocol;
use Thrift\Protocol\TJSONProtocol;

/** Include the socket layer */
use Thrift\Transport\TSocket;
use Thrift\Transport\TSocketPool;

/** Include the socket layer */
use Thrift\Transport\TFramedTransport;
use Thrift\Transport\TBufferedTransport;

function makeProtocol($transport, $PROTO)
{
  if ($PROTO == 'binary') {
    return new TBinaryProtocol($transport);
  } else if ($PROTO == 'compact') {
    return new TCompactProtocol($transport);
  } else if ($PROTO == 'json') {
    return new TJSONProtocol($transport);
  } else if ($PROTO == 'accel') {
    if (!function_exists('thrift_protocol_write_binary')) {
      echo "Acceleration extension is not loaded\n";
      exit(1);
    }
    return new TBinaryProtocolAccelerated($transport);
  }

  echo "--protocol must be one of {binary|compact|json|accel}\n";
  exit(1);
}

$host = 'localhost';
$port = 9090;

if ($argc > 1) {
  $host = $argv[0];
}

if ($argc > 2) {
  $host = $argv[1];
}

foreach ($argv as $arg) {
  if (substr($arg, 0, 7) == '--port=') {
    $port = substr($arg, 7);
  } else if (substr($arg, 0, 12) == '--transport=') {
    $MODE = substr($arg, 12);
  } else if (substr($arg, 0, 11) == '--protocol=') {
    $PROTO = substr($arg, 11);
  } 
}

$hosts = array('localhost');

$socket = new TSocket($host, $port);
$socket = new TSocketPool($hosts, $port);
$socket->setDebug(TRUE);

if ($MODE == 'inline') {
  $transport = $socket;
  $testClient = new \ThriftTest\ThriftTestClient($transport);
} else if ($MODE == 'framed') {
  $framedSocket = new TFramedTransport($socket);
  $transport = $framedSocket;
  $protocol = makeProtocol($transport, $PROTO);
  $testClient = new \ThriftTest\ThriftTestClient($protocol);
} else {
  $bufferedSocket = new TBufferedTransport($socket, 1024, 1024);
  $transport = $bufferedSocket;
  $protocol = makeProtocol($transport, $PROTO);
  $testClient = new \ThriftTest\ThriftTestClient($protocol);
}

$transport->open();

$start = microtime(true);

$testRunner = new TestRunner($testClient);

// Return codes defined in test/README.md, the return code will an OR of these
class ErrVal {
    const BASETYPES = 1;
    const STRUCTS = 2;
    const CONTAINERS = 4;
    const EXCEPTIONS = 8;
    const UNKNOWN = 64;
}

// VOID TEST
$testRunner->run('testVoid()', ErrVal::BASETYPES, function($testClient) {
    $testClient->testVoid();
});

// STRING TEST
$testRunner->roundtrip('testString', "");
$testRunner->roundtrip('testString', "Test");

// BOOL TEST
$testRunner->roundtrip('testBool', true);
$testRunner->roundtrip('testBool', false);

// BYTE TEST
$testRunner->roundtrip('testByte', 0);
$testRunner->roundtrip('testByte', 1);
$testRunner->roundtrip('testByte', -1);
$testRunner->roundtrip('testByte', 127);
$testRunner->roundtrip('testByte', -128);

// I32 TEST
$testRunner->roundtrip('testI32', 0);
$testRunner->roundtrip('testI32', 1);
$testRunner->roundtrip('testI32', -1);
$testRunner->roundtrip('testI32', 2147483647);
$testRunner->roundtrip('testI32', -2147483648);

// I64 TEST
$testRunner->roundtrip('testI64', 0);
$testRunner->roundtrip('testI64', 1);
$testRunner->roundtrip('testI64', -1);
$testRunner->roundtrip('testI64', -34359738368);

// DOUBLE TEST
$testRunner->roundtrip('testDouble', -852.234234234);

// STRUCT TEST
$xtruct = new \ThriftTest\Xtruct();
$xtruct->string_thing = "Zero";
$xtruct->byte_thing = 1;
$xtruct->i32_thing = -3;
$xtruct->i64_thing = -5;
$testRunner->roundtripNonStrict('testStruct', $xtruct, ErrVal::STRUCTS);

// NESTED STRUCT TEST
$xtruct2 = new \ThriftTest\Xtruct2();
$xtruct2->byte_thing = 1;
$xtruct2->struct_thing = $xtruct;
$xtruct2->i32_thing = 5;
$testRunner->roundtripNonStrict('testNest', $xtruct2, ErrVal::STRUCTS);

// MAP TEST
$mapout = array();
for ($i = 0; $i < 5; ++$i) {
  $mapout[$i] = $i-10;
}
$testRunner->roundtripIgnoreKeyOrder('testMap', $mapout, ErrVal::CONTAINERS);

$mapout = array();
for ($i = 0; $i < 11; $i++) {
    $mapout["key$i"] = "val$i";
}
$testRunner->roundtripIgnoreKeyOrder('testStringMap', $mapout, ErrVal::CONTAINERS);

// SET TEST
$testRunner->run('testSet', ErrVal::CONTAINERS, function($testClient) {
    $setout = array();
    for ($i = -2; $i < 3; ++$i) {
        $setout[$i]= true;
    }

    echo 'testSet(' . prettyFormat($setout) . ')';
    $result = $testClient->testSet($setout);
    echo ' = ' . prettyFormat($result) . "\n";
    assertSame($setout, $result);

    // Regression test for corrupted arrays from C extension (THRIFT-3977)
    if ($result[2] !== $setout[2] || is_int($result[2])) {
        throw new \Exception('Invalid set array');
    }
});

// LIST TEST
$listout = array();
for ($i = -2; $i < 3; ++$i) {
  $listout[]= $i;
}
$testRunner->roundtrip('testList', $listout, ErrVal::CONTAINERS);

// ENUM TEST
$testRunner->roundtrip('testEnum', \ThriftTest\Numberz::ONE, ErrVal::STRUCTS);
$testRunner->roundtrip('testEnum', \ThriftTest\Numberz::TWO, ErrVal::STRUCTS);
$testRunner->roundtrip('testEnum', \ThriftTest\Numberz::THREE, ErrVal::STRUCTS);
$testRunner->roundtrip('testEnum', \ThriftTest\Numberz::FIVE, ErrVal::STRUCTS);
$testRunner->roundtrip('testEnum', \ThriftTest\Numberz::EIGHT, ErrVal::STRUCTS);

// TYPEDEF TEST
$testRunner->roundtrip('testTypedef', 309858235082523, ErrVal::STRUCTS);

// NESTED MAP TEST
$testRunner->run('testMapMap(1)', ErrVal::CONTAINERS, function($testClient) {
    $mm = $testClient->testMapMap(1);
    echo " = " . prettyFormat($mm) . "\n";
    $expected_mm = [
      -4 => [-4 => -4, -3 => -3, -2 => -2, -1 => -1],
      4 => [4 => 4, 3 => 3, 2 => 2, 1 => 1],
    ];
    // Key order should not matter
    ksort($mm);
    ksort($mm[-4]);
    ksort($mm[4]);
    assertEqual($expected_mm, $mm);
});

// INSANITY TEST
$testRunner->run('testInsanity()', ErrVal::STRUCTS, function($testClient) {
    $insane = new \ThriftTest\Insanity();
    $insane->userMap[\ThriftTest\Numberz::FIVE] = 5000;
    $truck = new \ThriftTest\Xtruct();
    $truck->string_thing = "Truck";
    $truck->byte_thing = 8;
    $truck->i32_thing = 8;
    $truck->i64_thing = 8;
    $insane->xtructs[] = $truck;
    $whoa = $testClient->testInsanity($insane);
    echo ' = ' . prettyFormat($whoa) . "\n";

    assertEqual($insane, $whoa[1][2]);
});

// EXCEPTION TEST
$testRunner->run("testException('Xception')", ErrVal::EXCEPTIONS, function($testClient) {
    try {
        $testClient->testException('Xception');
        throw new \RuntimeException("Should have thrown exception");
    } catch (\ThriftTest\Xception $x) {
        echo ' caught xception '.$x->errorCode.': '.$x->message."\n";
    }
});


// INTEGER LIMIT TESTS
// Max I32
$num = pow(2, 30) + (pow(2, 30) - 1);
$testRunner->roundtrip('testI32', $num);

// Min I32
$num = 0 - pow(2, 31);
$testRunner->roundtrip('testI32', $num);

// Max I64
$num = pow(2, 62) + (pow(2, 62) - 1);
$testRunner->roundtrip('testI64', $num);

// Min I64
$num = 0 - pow(2, 62) - pow(2, 62);
$testRunner->roundtrip('testI64', $num);


/**
 * Normal tests done.
 */
$stop = microtime(true);
$elp = round(1000*($stop - $start), 0);
echo "Total time: $elp ms\n";

// REGRESSION TESTS
if ($protocol instanceof TBinaryProtocolAccelerated) {
    $testRunner->run('THRIFT-3984 do not double free strings', ErrVal::UNKNOWN, function($testClient) use ($protocol) {
        // Regression check: check that method name is not double-freed
        // Method name should not be an interned string.
        $method_name = "Void";
        $method_name = "test$method_name";

        $seqid = 0;
        $args = new \ThriftTest\ThriftTest_testVoid_args();
        thrift_protocol_write_binary($protocol, $method_name, \Thrift\Type\TMessageType::CALL, $args, $seqid, $protocol->isStrictWrite());
        $testClient->recv_testVoid();
    });
}


// DONE
$transport->close();
exit($testRunner->getErrorCode());




// TEST HELPERS

class TestRunner {
    private $testClient;
    private $errorCode;

    public function __construct($testClient){
        $this->testClient = $testClient;
        $this->errorCode = 0;
    }

    /**
     * Runs a test, catching and reporting exceptions
     */
    public function run($testName, $errorCode, $testCode) {
        if (!$errorCode) {
            $errorCode = ErrVal::UNKNOWN;
        }
        try {
            echo "#$testName\n";
            $testCode($this->testClient);
        } catch (\Exception $e) {
            echo "** FAILED **\n";
            echo "$e\n";
            $this->errorCode |= $errorCode;
        }
    }

    /**
     * Convenience method for checking roundtrip of a single value
     */
    private function roundtripWithCheck($method, $value, $errorCode=ErrVal::BASETYPES, $checkFun) {
        $value_str = prettyFormat($value);
        $this->run("$method($value_str)", $errorCode, function($testClient) use ($method, $value, $checkFun) {
            $result = $testClient->$method($value);
            echo ' = ' . prettyFormat($result) . "\n";
            $checkFun($value, $result);
        });
    }

    /**
     * Convenience method for checking roundtrip of a single value
     */
    public function roundtrip($method, $value, $errorCode=ErrVal::BASETYPES) {
        $this->roundtripWithCheck($method, $value, $errorCode, function($a, $b) {
            assertSame($a, $b);
        });
    }

    /**
     * Convenience method for checking roundtrip of a single value, with non-strict comparison
     */
    public function roundtripNonStrict($method, $value, $errorCode=ErrVal::BASETYPES) {
        $this->roundtripWithCheck($method, $value, $errorCode, function($a, $b) {
            assertEqual($a, $b);
        });
    }

    /**
     * Convenience method for checking roundtrip of a single value, sorting on key before comparison
     */
    public function roundtripIgnoreKeyOrder($method, $value, $errorCode=ErrVal::BASETYPES) {
        $this->roundtripWithCheck($method, $value, $errorCode, function($a, $b) {
            ksort($a);
            ksort($b);
            assertSame($a, $b);
        });
    }

    public function getErrorCode() {
        return $this->errorCode;
    }
}


function prettyFormat($val) {
    if (is_object($val)) {
        return get_class($val) . json_encode($val);
    } else {
        return json_encode($val);
    }
}

// Compare with ===
function assertSame($a, $b) {
    if ($a !== $b) {
        throw new \RuntimeException("Failed to assert that " . prettyFormat($a). " === " . prettyFormat($b));
    }
}

// Compare with ==
function assertEqual($a, $b) {
    if ($a != $b) {
        throw new \RuntimeException("Failed to assert that " . prettyFormat($a). " == " . prettyFormat($b));
    }
}
