<?php

namespace Aol\Offload\Tests\Cache;

use Aol\Offload\Cache\OffloadCacheRedis;

class OffloadLockRedisTest extends OffloadCacheTest
{
	protected function setUp()
	{
		$client = new \Predis\Client();
		$client->flushdb();
		$this->cache = new OffloadCacheRedis($client);
	}
}