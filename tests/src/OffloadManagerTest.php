<?php

namespace Aol\Offload\Tests;

use Aol\Offload\Deferred\OffloadDeferred;
use Aol\Offload\OffloadInterface;
use Aol\Offload\OffloadManager;
use Aol\Offload\OffloadRun;

abstract class OffloadManagerTest extends \PHPUnit_Framework_TestCase
{
	/** @var OffloadManager */
	protected $manager;

	public function testFetch()
	{
		$data = __METHOD__ . time() . rand(0, 100);
		$result = $this->manager->fetch(__METHOD__, function () use ($data) { return $data; });
		$this->assertEquals($data, $result->getData());
		$this->assertFalse($result->isFromCache());
	}

	public function testFetchCachedStale()
	{
		$data = __METHOD__ . time() . rand(0, 100);
		$result = $this->manager->fetch(__METHOD__, function () use ($data) { return $data; });
		$this->assertEquals($data, $result->getData());
		$this->assertFalse($result->isFromCache());
		$result = $this->manager->fetch(__METHOD__, function () use ($data) { return $data; });
		$this->assertEquals($data, $result->getData());
		$this->assertTrue($result->isFromCache());
		$this->assertTrue($result->isStale());
	}

	public function testFetchCachedFresh()
	{
		$data = __METHOD__ . time() . rand(0, 100);
		$result = $this->manager->fetchCached(__METHOD__, 5, function () use ($data) { return $data; });
		$this->assertEquals($data, $result->getData());
		$this->assertFalse($result->isFromCache());
		$result = $this->manager->fetch(__METHOD__, function () use ($data) { return $data; });
		$this->assertEquals($data, $result->getData());
		$this->assertTrue($result->isFromCache());
		$this->assertTrue($result->getStaleTime() < 0);
		$this->assertFalse($result->isStale());
		$this->assertGreaterThan(time(), $result->getExpireTime());
	}

	public function testFetchBad()
	{
		$data = __METHOD__ . time() . rand(0, 100);
		$result = $this->manager->fetchCached(__METHOD__, 5, function (OffloadRun $run) use ($data) {
			$run->setBad();
			return $data;
		});
		$this->assertEquals($data, $result->getData());
		$this->assertFalse($result->isFromCache());
		$result = $this->manager->fetch(__METHOD__, function () use ($data) { return $data; });
		$this->assertEquals($data, $result->getData());
		$this->assertFalse($result->isFromCache());
	}

	public function testDrain()
	{
		$data = __METHOD__ . time() . rand(0, 100);
		$result = $this->manager->fetch(__METHOD__, function () use ($data) { return $data; });
		$this->assertEquals($data, $result->getData());
		$this->assertFalse($result->isFromCache());
		$result = $this->manager->fetch(__METHOD__, function () use ($data) { return $data; });
		$this->assertEquals($data, $result->getData());
		$this->assertTrue($result->isFromCache());
		$this->assertTrue($result->isStale());
		$this->assertTrue($this->manager->hasWork());
		$drained = $this->manager->drain();
		$this->assertTrue(is_array($drained));
		$this->assertNotEmpty($drained);
		$this->assertEquals([__METHOD__ => $data], $drained);
	}

	public function testQueueNonExclusive()
	{
		$invoked = 0;
		$increment_invoked = function () use (&$invoked) { return $invoked++; };
		$this->manager->queue(__METHOD__, $increment_invoked, [OffloadInterface::OPTION_EXCLUSIVE => false]);
		$this->manager->queue(__METHOD__, $increment_invoked, [OffloadInterface::OPTION_EXCLUSIVE => false]);
		$this->manager->drain();
		$this->assertEquals(2, $invoked);
	}

	public function testQueueCached()
	{
		$invoked = 0;
		$increment_invoked = function () use (&$invoked) { return $invoked++; };
		$this->manager->queueCached(__METHOD__, 1, $increment_invoked);
		$this->manager->queueCached(__METHOD__, 1, $increment_invoked);
		$this->manager->drain();
		$this->assertEquals(1, $invoked);
	}

	public function testQueueCachedNonExclusive()
	{
		$invoked = 0;
		$increment_invoked = function () use (&$invoked) { return $invoked++; };
		$this->manager->queueCached(__METHOD__, 1, $increment_invoked, [OffloadInterface::OPTION_EXCLUSIVE => false]);
		$this->manager->queueCached(__METHOD__, 1, $increment_invoked, [OffloadInterface::OPTION_EXCLUSIVE => false]);
		$this->manager->drain();
		$this->assertEquals(2, $invoked);
	}

	public function testGetCacheHit()
	{
		$data = __METHOD__ . time() . rand(0, 100);
		$task = function () use ($data) { return $data; };
		$this->manager->fetchCached(__METHOD__, 5, $task);
		$result = $this->manager->get(__METHOD__);
		$this->assertEquals($data, $result->getData());
	}

	public function testGetManyCacheHit()
	{
		$data = __METHOD__ . time() . rand(0, 100);
		$task = function () use ($data) { return $data; };
		$this->manager->fetchCached(__METHOD__ . '1', 5, $task);
		$this->manager->fetchCached(__METHOD__ . '2', 5, $task);
		$result = $this->manager->getMany([__METHOD__ . '1', __METHOD__ . 'X', __METHOD__ . '2']);
		$this->assertTrue(is_array($result));
		$this->assertEquals(3, count($result));
		$this->assertEquals($data, $result[0]->getData());
		$this->assertNull($result[1]->getData());
		$this->assertFalse($result[1]->isFromCache());
		$this->assertEquals($data, $result[2]->getData());
	}

	public function testDeleteCache()
	{
		$data = __METHOD__ . time() . rand(0, 100);
		$task = function () use ($data) { return $data; };
		$this->manager->fetchCached(__METHOD__ . '1', 5, $task);
		$this->manager->fetchCached(__METHOD__ . '2', 5, $task);
		$this->assertTrue($this->manager->get(__METHOD__ . '1')->isFromCache());
		$this->assertTrue($this->manager->get(__METHOD__ . '2')->isFromCache());
		$this->assertEquals(2, $this->manager->delete([__METHOD__ . '1', __METHOD__ . '2']));
		$this->assertFalse($this->manager->get(__METHOD__ . '1')->isFromCache());
		$this->assertFalse($this->manager->get(__METHOD__ . '2')->isFromCache());
	}

	public function testGetCacheMiss()
	{
		$result = $this->manager->get(__METHOD__);
		$this->assertNull($result->getData());
		$this->assertFalse($result->isFromCache());
	}

	public function testRealDeferred()
	{
		$data = __METHOD__ . time() . rand(0, 100);
		$result = $this->manager->fetchCached(__METHOD__, 5, function () use ($data) {
			return new OffloadDeferred(function () use ($data) {
				usleep(1000 * 100);
				return $data;
			});
		});
		$this->assertEquals($data, $result->getData());
	}

	public function testRealDeferredAlreadyWaited()
	{
		$data = __METHOD__ . time() . rand(0, 100);
		$result = $this->manager->fetchCached(__METHOD__, 5, function () use ($data) {
			$defer = new OffloadDeferred(function () use ($data) {
				usleep(1000 * 100);
				return $data;
			});
			$defer->wait();
			return $defer;
		});
		$this->assertEquals($data, $result->getData());
	}
}