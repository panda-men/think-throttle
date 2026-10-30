<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';
$path = $argv[1] ?? '';
if (!preg_match('/^panda-throttle-test-[a-f0-9]{12}$/D',basename(dirname($path)))) throw new RuntimeException('Invalid test directory');
$context = json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
if (($context['marker'] ?? '')!=='throttle-concurrency-v1' || !preg_match('/^throttle:test:[a-f0-9]{16}$/D',$context['prefix'] ?? '')) throw new RuntimeException('Invalid test context');
touch(dirname($path).'/ready-'.($argv[2] ?? ''));
$deadline = microtime(true)+20;
while (!is_file(dirname($path).'/go')) { if (microtime(true)>$deadline) throw new RuntimeException('Test barrier timed out'); usleep(1000); }
$redis = Panda\ThinkThrottle\RedisConnection::connect(['host'=>$context['host'],'port'=>$context['port'],'connect_timeout'=>1,'read_timeout'=>2]);
$limiter = new Panda\ThinkThrottle\RateLimiter(new Panda\ThinkThrottle\RedisStore($redis,$context['prefix'],str_repeat('test-hmac-',4)));
$allowed = 0;
for ($i=0;$i<4;$i++) if ($limiter->consume(new Panda\ThinkThrottle\Limit('shared','all',10,1,3600000),
    new Panda\ThinkThrottle\Limit('worker',(string)$argv[2],4,1,3600000))->allowed) $allowed++;
echo json_encode(['allowed'=>$allowed]);
