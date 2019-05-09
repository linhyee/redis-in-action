<?php 

/**

save 60 1000                        #A
stop-writes-on-bgsave-error no      #A
rdbcompression yes                  #A
dbfilename dump.rdb                 #A
appendonly no                       #B
appendfsync everysec                #B
no-appendfsync-on-rewrite no        #B
auto-aof-rewrite-percentage 100     #B
auto-aof-rewrite-min-size 64mb      #B
dir ./                              #C
# <end id="persistence-options"/>
#A Snapshotting persistence options
#B Append-only file persistence options
#C Shared option, where to store the snapshot or append-only file

 */

// Our function will be provided with a callback that will take a connection and a log line, calling methods on the pipeline as necessary
function process_logs($con, $path, $callback) {
  // Get the current progress
  list($current_file, $offset) = $con->mGet(["progress:file", "progress:position"]);

  // This closure is meant primarily to reducce the number of duplicated lines later
  $update_progress = function($con, $fname, $offset) {
    $con->multi()
    // We want to update our file an line number offsets into the logfile
      ->mSet([
        "progress:file" => $fname,
        "progress:position" => $offset,
      ])
      // This will execute any outstanding log updates, as well as to 
      // actually write our file and line number updates to Redis
      ->exec();
  };

  $files = listdir($path);
  sort($files);
  // Iterate over the logfiles in sorted order
  foreach ($files as $fname) {
    // Skip over files that are before the current file
    if ($fname < $current_file) {
      continue;
    }
    try {
      $inp = new SplFileObject(path_join($path, $fname), "rb");
    } catch(Exception $e) {
      echo $e->getMessage(), "\n";
      continue;
    }
    // If we are continuing a file, skip over the parts that we've already processed
    if ($fname == $current_file) {
      $inp->fseek($offset, 10);
    } else {
      $offset = 0;
    }

    $current_file = null;
    // Produces pairs consisting of a numeric sequence starting from 0, and the original data
    foreach ($inp as $lno => $line) {
      // Handle the log line
      $callback($con, $line);
      // Update our information about the offset into the file
      $offset = intval($offset) + strlen($line);

      // Write our progress back to Redis every 1000 lines, or when we are done with a file
      if (($lno +1) % 1000) {
        $update_progress($con, $fname, $offset);
      }
    }
    $update_progress($con, $fname, $offset);
    unset($inp);
  }
}

function wait_for_sync($mcon, $scon) {
  $identifier = uuid4();
  // Add the token to the master
  $mcon->zAdd("sync:wait", $identifier, time());

  // Wait for the slave to sync (if necessary)
  while (!(@$scon->info()['master_link_status'] != 'up')) {
    usleep(1);
  }
  // Wait for the slave to recceive the data change
  while (!@$scon->zScore("sync:wait", $identifier)) {
    usleep(1);
  }
  // Wait up to 1 second
  $deadline = time() + 1.01;
  while (time() < $deadline) {
    // Check to see if the data is known to be on disk
    if (@$scon->info()['aof_pending_bio_fsync'] == 0) {
      break;
    }
    usleep(1);
  }

  // Clean up our status and clean out older entries that have been left the there
  $mcon->zRem("sync:wait", $identifier);
  $mcon->zRemRangeByScore("sync:wait", 0, time() - 900);
}

/**

user@vpn-master ~:$ ssh root@machine-b.vpn                          #A
Last login: Wed Mar 28 15:21:06 2012 from ...                       #A
root@machine-b ~:$ redis-cli                                        #B
redis 127.0.0.1:6379> SAVE                                          #C
OK                                                                  #C
redis 127.0.0.1:6379> QUIT                                          #C
root@machine-b ~:$ scp \\                                           #D
> /var/local/redis/dump.rdb machine-c.vpn:/var/local/redis/         #D
dump.rdb                      100%   525MB  8.1MB/s   01:05         #D
root@machine-b ~:$ ssh machine-c.vpn                                #E
Last login: Tue Mar 27 12:42:31 2012 from ...                       #E
root@machine-c ~:$ sudo /etc/init.d/redis-server start              #E
Starting Redis server...                                            #E
root@machine-c ~:$ exit
root@machine-b ~:$ redis-cli                                        #F
redis 127.0.0.1:6379> SLAVEOF machine-c.vpn 6379                    #F
OK                                                                  #F
redis 127.0.0.1:6379> QUIT
root@machine-b ~:$ exit
user@vpn-master ~:$
# <end id="master-failover"/>
#A Connect to machine B on our vpn network
#B Start up the command line redis client to do a few simple operations
#C Start a SAVE, and when it is done, QUIT so that we can continue
#D Copy the snapshot over to the new master, machine C
#E Connect to the new master and start Redis
#F Tell machine B's Redis that it should use C as the new master
#END

 */

function list_item(Redis $con, $itemid, $sellerid, $price) {
  $inventory = sprintf("inventory:%s", $sellerid);
  $item = sprintf("%s.%s", $itemid, $sellerid);
  $end = time() + 5;

  while (time() < $end) {
    try {
      // Watch for changes to the users's inventory
      $con->watch($inventory);
      // Verify that the user still has the item 
      if (!$con->sIsMember($inventory, $itemid)) {
        // If the item is not in the user's inventory, 
        // stop watching the inventory key and return
        $con->unwatch($inventory);
        return null;
      }

      // Actually list the item
      $con->multi()
        ->zAdd("market:", $price, $item)
        ->sRem($inventory, $itemid)
        // If execute returns without a WatchError being, 
        // then the transaction is complete and the inventory
        // key is no longer watched
        ->exec();

      return true;
    } catch(Exception $e) {
      // The user's inventory was changed, retry
      echo $e->getMessage(), "\n";
    }
  }
  return false;
}

function purchase_item(Redis $con, $buyerid, $itemid, $sellerid, $lprice) {
  $buyer = "users:".$buyerid;
  $seller = "users:".$sellerid;
  $item = $itemid.".".$sellerid;
  $inventory = "inventory:".$buyerid;
  $end = time() + 10;

  while (time() < $end) {
    try {
      // Watch for changes to the market an to the buyer's account information
      $con->watch(["market:", $buyer]);
      // Check for a sold/repriced item or insufficient funds
      $price = $con->zScore("market:", $item);
      $funds = intval($con->hGet($buyer, "funds"));
      if ($price != $lprice || $price > $funds) {
        $con->unwatch(["market:", $buyer]);
        return null;
      }

      // Transfer funds from the buyer to the seller,
      // and transfer the item to the buyer 
      $con->multi()
        ->hIncrBy($seller, "funds", intval($price))
        ->hIncrBy($buyer, "funds", -intval($price))
        ->sAdd($inventory, $itemid)
        ->zRem("market:", $item)
        ->exec();

      return true;
    } catch(Exception $e) {
      // Retry if the buyer's account or the market changed
      echo $e->getMessage(), "\n";
    }
  }
  return false;
}

function update_token($con, $token, $user, $item = null) {
  // Get the timestamp
  $timestamp = time();
  // Keep a mapping from the token to the logged-in user
  $con->hSet("login:", $token, $user);
  // Record when the token was last seen
  $con->zAdd("recent:", $timestamp, $token);
  if ($item) {
    // Record that the user viewed the item
    $con->zAdd("viewed:".$token, $timestamp, $item);
    // Remove old itmes, keeping the most recent 25
    $con->zRemRangeByRank("viewed:".$token, 0, -26);
    // Update the number of times the given item had been viewed
    $con->zIncrBy("viewed:", -1, $item);
  }
}

function update_token_pipeline($con, $token, $user, $item = null) {
  $timestamp = time();
  // Set up the pipeline
  $pipe = $con->multi();
  $pipe->hSet("login:", $token, $user);
  $pipe->zAdd("recent:", $timestamp, $token);
  if ($item) {
    $pipe->zAdd("viewed:".$token, $timestamp, $item);
    $pipe->zRemRangeByRank("viewed:".$token, 0, -26);
    $pipe->zIncrBy("viewed:", -1, $item);
  }
  // Execute the commands in the pipeline
  $pipe->exec();
}

function benchmark_update_token($con, $duration) {
  // Execute both the update_token() and the update_token_pipeline() functions
  foreach (['update_token', 'update_token_pipeline'] as $function) {
    // Set up our counters and our ending conditions
    $count = 0;
    $start = time();
    $end = $start + $duration;
    while (time() < $end) {
      $count += 1;
      // Call one of the two functions
      $function($con, 'token', 'user', 'item');
    }
    // Calculate the duration
    $delta = time()-$start;
    // Print information about the results
    echo $function, " ", __FUNCTION__, " ", $count, " ", $delta, " ", $count / $delta;
    echo "\n";
  }
}

function uuid4() {
  return sprintf("%04x%04x-%04x-%04x-%04x-%04x%04x%04x",
    mt_rand(0, 0xffff), mt_rand(0, 0xffff),
    mt_rand(0, 0xffff),
    mt_rand(0, 0x0fff) | 0x4000,
    mt_rand(0, 0x3fff) | 0x8000,
    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
  );
}

function path_join($path, $file) {
  $ds = DIRECTORY_SEPARATOR;
  return rtrim($path, $ds) . $ds . $file;
}

// listdir function like python
function listdir($path) {
  if (!is_dir($path)) {
    return [];
  }
  $dirs = [];
  $hd = opendir($path);
  while (($file = readdir($hd)) !== false) {
    if ($file == '.' || $file == '..') {
      continue;
    }
    $dirs[] = $file;
  }
  closedir($hd);
  return $dirs;
}

/**
#redis-benchmark

[root@VM_0_8_centos bin]# ./redis-benchmark -c 1 -q #A
PING_INLINE: 61124.69 requests per second
PING_BULK: 58479.53 requests per second
SET: 56947.61 requests per second
GET: 58858.15 requests per second
INCR: 58962.27 requests per second
LPUSH: 51150.89 requests per second
RPUSH: 58788.95 requests per second
LPOP: 56818.18 requests per second
RPOP: 57937.43 requests per second
SADD: 59880.24 requests per second
HSET: 60240.96 requests per second
SPOP: 66181.34 requests per second
LPUSH (needed to benchmark LRANGE): 59772.86 requests per second
LRANGE_100 (first 100 elements): 31525.85 requests per second
LRANGE_300 (first 300 elements): 13787.40 requests per second
LRANGE_500 (first 450 elements): 9910.80 requests per second
LRANGE_600 (first 600 elements): 7635.92 requests per second
MSET (10 keys): 49067.71 requests per second

#A We run with the '-q' option to get simple output, and '-c 1' to use a single client

 */

class TestCh04 {
  public $con = null;

  public static function new_redis() {
    $con = new Redis();
    // $con->connect("127.0.0.1", 6379);
    $con->connect("192.168.71.210", 7001);
    $con->auth("yis@2019._");
    return $con;
  }

  public function __construct() {
    $this->con = self::new_redis();
  }

  public function test_process_logs() {
    $con = $this->con;
    $con->delete("progress:file", "progress:position", "progress:done");

    $dire = "./tmp";
    echo "Creating some temporary 'log' files...\n";
    for ($i=0; $i<5; $i++) {
      $hd = fopen($dire."/log-".$i.".txt", "wb");
      if ($hd) {
        for ($j=0; $j<1000; $j++) {
          fwrite($hd, "one line ".$j."\n");
        }
      }
      fclose($hd);
    }
    echo "Done!\n";
    echo "\n";

    echo "Let's process 'log' files...\n";
    process_logs($con, $dire, function($con, $line) {
      $con->append("progress:done", $line);
    });
    echo "Finished!\n";

    $file = $con->get("progress:file");
    $pos = $con->get("progress:position");
    echo "Now the progress current file is ", $file, "\n";
    echo "And the progress current position is ", $pos, "\n";

    $lines = $con->get("progress:done");
    $r = strlen($lines);
    echo "Total bytes readed: ", $r, "\n";

    $this->assertTrue($r == $pos *5);
    $con->delete("progress:file", "progress:position", "progress:done");
  }

  # We also can't test wait_for_sync, as we can't guarantee that there are
  # multiple Redis servers running with the proper configuration

  public function test_update_token() {
    $con = $this->con;
    $con->delete("login:", "recent:", "viewed:");

    $token = uuid4();
    update_token($con, $token, "username", "itemX");
    echo "We just logged-in/update token:", $token, "\n";
    echo "For user: username\n";

    echo "What username do we get when we lookup the token? ";
    $r = $con->hGet("login:", $token);
    echo $r, "\n";
    $this->assertTrue($r);

    echo "Listing what does username hold?";
    $items = $con->zRange("viewed:".$token, 0, -1, true);
    self::pprint($items);
    $this->assertTrue(array_key_exists("itemX", $items));

    echo "Now let's add some items use update_token_pipeline() function";
    update_token_pipeline($con, $token, "username", "itemY");
    $items = $con->zRange("viewed:".$token, 0, -1, true);
    echo "Okay, our user's numbers of items should be:", count($items), "\n";
    self::pprint($items);
    $this->assertTrue(array_key_exists("itemY", $items));

    $con->delete("login:", "recent:", "viewed:", "viewed:".$token);
  }

  public function test_benchmark_update_token() {
    $this->con->delete("login:", "recent:", "viewed:", "viewed:token");
    $n = 10;
    echo "begin {$n} times benchmark test";
    echo "\n\n";
    for ($i= 0; $i<$n; $i++) {
      benchmark_update_token($this->con, 10);
    }
    echo "\n";
    echo "done!\n";
    $this->con->delete("login:", "recent:", "viewed:", "viewed:token");
  }

  public function test_list_item() {
    $con = $this->con;
    $con->delete("inventory:userX", "market:");
    echo "We need to set up just enough state so that a user can list an item\n";
    $seller = "userX";
    $item = "itemX";
    $con->sAdd("inventory:".$seller, $item);
    $i = $con->sMembers("inventory:".$seller);
    echo "The user's inventory has:", self::pprint($i);
    $this->assertTrue($i);
    echo "\n";

    echo "Listing the item...\n";
    $l = list_item($con, $item, $seller, 10);
    echo "Listing the item succeeded?", $l, "\n";
    $this->assertTrue($l);
    $r = $con->zRange("market:", 0, -1,true);
    echo "The market contains:";
    self::pprint($r);

    $any = function($it, $var) {
      foreach ($it as $key => $val) {
        if ($key == $var) {
          return true;
        }
      }
      return false;
    };

    $this->assertTrue($r);
    $this->assertTrue($any($r, "itemX.userX"));
  }

  public function test_purchase_item() {
    $this->con->delete("inventory:userY", "users:userX", "users:userY");
    $this->test_list_item();
    $con = $this->con;

    echo "We need to set up just enough state so a user can buy an item\n";
    $buyer = "userY";
    $con->hSet("users:userY", "funds", 125);
    $r = $con->hGetAll("users:userY");
    echo "The user has some money:", self::pprint($r);
    $this->assertTrue($r);
    $this->assertTrue($r["funds"]);
    echo "\n";

    echo "Let's purchase an item\n";
    $p = purchase_item($con, "userY", "itemX", "userX", 10);
    echo "Purchasing an item succeeded?", self::pprint($p);
    $this->assertTrue($p);
    $r = $con->hGetAll("users:userY");
    echo "Their money is now:", self::pprint($r);
    $this->assertTrue($r);
    $i = $con->sMembers("inventory:".$buyer);
    echo "Their inventory is now:", self::pprint($i);
    $this->assertTrue($i);
    $this->assertTrue(in_array("itemX", $i));
    $this->assertTrue($con->zScore("market:", "itemX.userX")==null);

    $this->con->delete("inventory:userY", "users:userX", "users:userY");
  }

  public static function run() {
    $mthds = get_class_methods(get_called_class());
    $tests = [];
    foreach ($mthds as $m) {
      if (preg_match("/^test_.+/", $m)) {
        $tests[] = $m;
      }
    }
    if (!empty($tests)) {
      $tester = new self();
      foreach ($tests as $test) {
        $dash = str_repeat('-', (100 - strlen($test)+2) /2);
        echo "\n\n", $dash, " ", $test, " ", $dash, "\n\n";
        call_user_func(array($tester, $test));
      }
    }
  }

  public static function pprint($var) {
    echo json_encode($var, true);
    echo "\n";
  }

  public function assertTrue($bool, $message = "") {
    if ($bool) {
      return;
    }
    $traces = debug_backtrace();
    foreach ($traces as $trace) {
      if ($trace["function"] == explode("::", __METHOD__)[1]) {
        $line = $trace["line"];
        break;
      }
    }

    echo "assert fail:",basename(__FILE__), ":", $line, ",",  $message;
    echo "\n";
    exit(-1);
  }

  public function __destruct() {
    if ($this->con && $this->con->isConnected()) {
      $this->con->close();
      $this->con = null;
    }
  }
}

TestCh04::run();

?>