<?php
// Set up a mapping that should help turn most logging severity levels into something consistent
$severities = [
  Logging::DEBUG => 'debug',
  Logging::INFO => 'info',
  Logging::WARNING => 'warnig',
  Logging::ERROR => 'error',
  Logging::CRITICAL => 'critical',
];
foreach (array_values($severities) as $val) {
  $severities[$val] = $val;
}

function log_recent($con, $name, $message, $severity=Logging::INFO, $pipe=null) {
  global $severities;
  // Actually try to turn a logging level into a simple string
  if (isset($severities[$severity])) {
    $severity = strtolower($severities[$severity]);
  }
  // Create the key that messages will be written to
  $destination = sprintf("recent:%s:%s", $name, $severity);
  $asctime = gmdate('D M j H:i:s Y', time());
  // Add the current time so that we know when the message was sent
  $message = $asctime . ' '.$message;
  // Set up a pipeline so we only need 1 round trip
  $pipe = $pipe ? $pipe : $con->multi();
  // Add the message to the beginning of the log list
  $pipe->lPush($destination, $message);
  // Trim the log list to only include the most recent 100 messages
  $pipe->lTrim($destination, 0, 99);
  // Execute the two commands
  $pipe->exec();
}

function log_common($con, $name, $message, $severity=Logging::INFO, $timeout = 5) {
  global $severities;
  // Handle the loggin level
  if (isset($severities[$severity])) {
    $severity = strtolower($severities[$severity]);
  }
  // Set up the destination key for keeping recent logs
  $destination = sprintf("common:%s:%s", $name, $severity);
  // Keep a record of the start of the hour for this set of message
  $start_key = $destination.":start";
  $end = time() + $timeout;
  while (time() < $end) {
    // We are going to watch the start of the hour key for changes
    // that only happen at the beginning of the hour
    $con->watch($start_key);
    // Get the current time and find the current start hour
    $hour_start = gmdate('Y-m-d\TH:00:00');

    $existing = $con->get($start_key);
    $con->multi();
    // If the current list of common logs is for a previous hour
    if ($existing && $existing < $hour_start) {
      // Move the old common log information to the archive
      $con->reName($destination, $destination.':last');
      // Update the start of the current hour for the common logs
      $con->reName($start_key, $destination.':pstart');
      // Update the start of the current hour for the common logs
      $con->set($start_key, $hour_start);
    } elseif (!$existing) {
      $con->set($start_key, $hour_start);
    }

    // Actually increment our common counter
    $con->zIncrBy($destination, 1, $message);
    // Call the log_recent() function to record these there,
    // and rely on its call to execute()
    log_recent($con, $name, $message, $severity, $con);
    return;
  }
}

// The precision of the counters in seconds: 1 second, 5 seconds, 1 minute, 5 minutes, 1 hour, 5 hours, 1 day
// - adjust as necessary
$precision = [1, 5, 60, 300, 3600, 18000, 86400];

function update_counter($con, $name, $count = 1, $now = null) {
  // Get the current time to know when is the proper time to add to
  $now = $now?$now:time();
  // Create a transactional pipeline so that later cleanup can work correctly
  $pipe = $con->multi();

  global $precision;
  // Add entries for all precisions that we record
  foreach ($precision as $prec) {
    // Get the start of the current time slice
    $pnow = intval($now / $prec) * $prec;
    // Create the named hash where this data will be stored
    $hash = sprintf("%s:%s", $prec, $name);
    // Record a reference to the counters into a ZSET with the 
    // score 0 so we can clean up after ourselves
    $pipe->zAdd("known:", 0, $hash);
    // Update the counter for the given name and time precision
    $pipe->hIncrBy("count:".$hash, $pnow, $count);
  }
  $pipe->exec();
}

function get_counter($con, $name, $precision) {
  // Get the name of the key where we will be storing counter data
  $hash = sprintf("%s:%s", $precision, $name);
  // Fetch the counter data from redis
  $data = $con->hGetAll("count:".$hash);
  // Convert the counter data into something more expected
  $to_return = [];
  foreach ($data as $key => $value) {
    $to_return[] = [intval($key), intval($value)];
  }
  // Sort our data so that older samples are first
  sort($to_return);
  return $to_return;
}

function clean_counters(MyThread $thr, $con) {
  if (!$con) {
    $con = TestCh05::new_reids(); //php pthread do not surpport share global var
  }
  $passes = 0;
  while (!$thr->quit) {
    $start = time();
    $index = 0;
    while ($index < intval($con->zCard("known:"))) {
      $hash = $con->zRange("known:", $index, $index);
      $index +=1;
      if (!$hash) {
        break;
      }
      $hash = $hash[0];
      $prec = intval(explode(':', $hash)[0]);
      $bprec = int ($prec / 60);
      $bprec = $prec ? $prec :1;
      if ($passes % $bprec) {
        continue;
      }

      $hkey = "count:".$hash;
      $cutoff = time() - SAMPLE_COUNT * $prec;
      $samples = array_map("intval", $con->hKeys($hkey));
      sort($samples);
      $remove = bisect_right($samples, $cutoff);

      if ($remove) {
        $con->hDel($hkey, array_slice($samples, 0, $remove));
        if ($remove == count($samples)) {
          $con->watch($hkey);
          if (!$con->hLen($hkey)) {
            $con->multi()
              ->zRem("known:", $hash)
              ->exec();
              $index -= 1;
          } else {
            $con->unwatch();
          }
        }
      }
    }
    $passes += 1;
    $duration = min(time() - $start +1, 60);
    sleep(max(60 - $duration, 1));
  }
}

function update_stats($con, $context, $type, $value, $timeout=5) {
  // Set up the destination statistics key
  $destination = sprintf("stats:%s:%s", $context, $type);
  // Handle the current hour/last hour like in common_log()
  $start_key = $destination.":start";
  $end = time() + $timeout;
  while (time() < $end) {
    try {
      $con->watch($start_key);
      $hour_start = gmdate('Y-m-d\TH:00:00');

      $existing = $con->get($start_key);
      $con->multi();
      if ($existing && $existing < $hour_start) {
        $con->reName($destination, $destination.':last');
        $con->reName($start_key, $destination.':pstart');
        $con->set($start_key, $hour_start);
      }

      $tkey1 = uuid4();
      $tkey2 = uuid4();
      // Add the value to the temporary keys
      $con->zAdd($tkey1, $value, 'min');
      $con->zAdd($tkey2, $value, 'max');
      // Union the temporary keys with the destination stats key with
      // the appropriate min/max aggregate
      $con->zUnion($destination, [$destination, $tkey1],[1,1], 'min');
      $con->zUnion($destination, [$destination, $tkey2],[1,1], 'max');

      // Clean up the temporary keys
      $con->delete($tkey1, $tkey2);
      // Update the count, sum, and sum of squares members of the zset
      $con->zIncrBy($destination, 1, "count");
      $con->zIncrBy($destination, $value, "sum");
      $con->zIncrBy($destination, $value*$value,"sumsq");

      // Return the base counter info so that the caller can do something
      // interesting if necessary
      $r = $con->exec();
      return $r;
    } catch(Exception $e){
      // If the hour just turned over and the stats have already been shuffled over,
      // try again.
      continue;
    }
  }
}

function get_stats($con, $context, $type) {
  $key = sprintf("stats:%s:%s", $context, $type);
  $data = $con->zRange($key, 0, -1, true);
  $data['average'] = $data['sum'] / $data['count'];
  $numberator = $data['sumsq'] - pow($data['sum'], 2) / $data['count'];
  $data['stddev'] = pow(($numberator / (($data['count'] -1) ? $data['count'] -1 :1)), 0.5);
  return $data;
}

function access_time($con, $context) {
  // Record the start time
  $start = time();
  // Let the block of code that we are wrapping run
  yield;

  $delda = time() - $start;
  $stats = update_stats($con, $context, "AccessTime", $delda);
  $average = $stats[1] / $stats[0];

  $con->multi()
    ->zAdd("slowest:AccessTime", $average, $context)
    ->zRemRangeByRank("slowest:AccessTime", 0, -101)
    ->exec();
}

function process_view($con, $cb) {
  with_ctx(access_time($con, Request::$path), $cb);
}

function ip_to_score($ip_address) {
  $score = 0;
  foreach (explode('.', $ip_address) as $v) {
    $score = $score * 256 + intval($v, 10);
  }
  return $score;
}

// Should be run with the location of the GeoLiteCity-Blocks.csv flle
function import_ips_to_redis($con, $filename) {
  $csv_file = fopen($filename, "rb");
  if (!$csv_file) {
    return;
  }
  $i = 0;
  while (($row =fgetcsv($csv_file))!== false) {
    // C .
    $start_ip = $row ? $row[0] : '';
    if (strpos(strtolower($start_ip), 'i')) {
      continue;
    }
    if (strpos($start_ip, '.')) {
      $start_ip = ip_to_score($start_ip);
    } elseif (is_numeric($start_ip)) {
      $start_ip = intval($start_ip, 10);
    } else {
      continue;
    }

    $city_id = $row[2] .'_'. $i;
    $con->zAdd("ip2cityid:", $start_ip, $city_id);
    $i++;
  }
}

// Should be run with the location of the GeoLiteCity-Location.csv file
function import_cities_to_redis($con, $filename) {
  $csv_file = fopen($filename, "rb");
  if (!$csv_file) {
    return;
  }
  while (($row=fgetcsv($csv_file)) !== false) {
    if (count($row) < 4 || !is_numeric($row[0])) {
      continue;
    }
    $row = array_map(function($var){
      return mb_convert_encoding($var, 'UTF-8');
    }, $row);
    // Prepare the information for adding to the hash
    $city_id = $row[0];
    $country = $row[1];
    $region = $row[2];
    $city = $row[3];
    // Actually add the city information to Redis
    $con->hSet("cityid2city:", $city_id, json_encode(
      [
        $city,
        $region,
        $country,
      ]
    ));
  }
}

function find_city_by_ip($con, $ip_address) {
  // Convert the ip address to a score for zrevrangebyscore
  if (is_string($ip_address)) {
    $ip_address = ip_to_score($ip_address);
  }

  // Find the uique city ID
  $city_id = $con->zRevRangeByScore("ip2cityid:", $ip_address, 0, ['limit'=>[0,1]]);

  if (!$city_id) {
    return null;
  }

  // Convert the unique city ID o t common city ID
  $city_id = explode('_', $city_id[0])[0];
  // Fetch the city information from the hash
  return json_decode($con->hGet("cityid2city:", $city_id));
}

$last_checked = null;
$is_under_maintenance = false;

function is_under_maintenance($con) {
  global $last_checked, $is_under_maintenance;

  if ($last_checked < time() -1) {
    $last_checked = time();
    $is_under_maintenance = (boolean)$con->get('is-under-maintenance');
  }
  return $is_under_maintenance;
}

function set_config($con, $type, $component, $config) {
  $con->set(sprintf("config:%s:%s", $type, $component), json_encode($config));
}

$configs = [];
$checked = [];

function get_config($con, $type, $component, $wait=1) {
  $key = sprintf("config:%s:%s", $type, $component);
  global $configs, $checked;

  if ($checked[$key] < time() - $wait) {
    $checked[$key] = time();
    $config = json_decode($con->get($key), true);
    $config = $config ? $config : [];
    $old_config = $configs[$key];

    if ($config[$key] != $old_config) {
      $configs[$key] = $config;
    }
  }
  return $configs[$key];
}

function test(MyThread $thr, $msg) {
  while (!$thr->quit) {
    echo "$msg\n";
    sleep(1);
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

function with_ctx(Iterator $it, Closure $cb, array $params =[]) {
  foreach ($it as $key => $value) {
    call_user_func_array($cb, $params);
  }
}

function random_float($min = 0, $max = 1) {
 return $min + mt_rand() / mt_getrandmax() * ($max - $min);
}

class Request {
  static $path;
}

if (!class_exists('Thread')) {
  class Thread {
    public function __construct($routine, array $args) {}
    public function start() {}
    public function run() {}
    public function join() {}
  }
}

class Logging {
  const DEBUG = "10";
  const INFO = "20";
  const WARNING = "30";
  const ERROR = "40";
  const CRITICAL = "50";
}

class MyThread extends Thread {
  public $quit = false;
  public $sample_count = 100;
  // The routine function
  public $routine = null;
  // The routine function params
  public $args = null;

  public function __construct($routine, array $args) {
    if (!is_callable($routine)) {
      throw new Exception("function `$routine` unable callabled", 1);
    }
    $this->routine = $routine;
    if ($args) {
      array_unshift($args, $this);
    }
    $this->args = $args;
  }

  public function run() {
    call_user_func_array($this->routine, (array)$this->args);
  }
}

class TestCh05 {
  public $con = null;

  public static function new_reids() {
    $con = new Redis();
    // $con->connect("127.0.0.1", 6379);
    $con->connect("192.168.71.210", 7001);
    $con->auth("yis@2019._");
    return $con;
  }

  public function __construct() {
    $this->con = self::new_reids();
  }

  public function _test_thread() {
    $thread = new MyThread("test", ["hello from main!"]);
    $thread->start();
    sleep(5);
    $thread->quit = true;
    $thread->join();
    echo "done!\n";
  }

  public function test_log_recent() {
    $this->con->delete("recent:test:info");
    echo "Let's write a few logs to the recent log.\n";
    for ($i=0; $i<5; $i++) {
      log_recent($this->con, "test", "this is message ".$i);
    }
    $recent = $this->con->lRange("recent:test:info", 0, -1);
    echo "The current recent message log has this many messages:", count($recent), "\n";
    echo "Those messages include:";
    self::pprint($recent);
    $this->assertTrue(count($recent) >= 5);
    $this->con->delete("recent:test:info");
  }

  public function test_log_common() {
    $this->con->delete("common:test:info", "common:test:info:start", "common:test:info:last", "common:test:info:pstart", "recent:test:info");
    echo "Let's write some items to the common log.\n";
    for ($i = 0; $i < 6; $i++) {
      for ($j = 0; $j < $i; $j++) {
        log_common($this->con, "test", "message-".$j);
      }
    }
    $common = $this->con->zRevRange("common:test:info", 0, -1, true);
    echo "The current number of common message is:", count($common), "\n";
    echo "Those common message are:";
    self::pprint($common);
    $this->assertTrue(count($common) >= 5);
    $this->con->delete("common:test:info", "common:test:info:start", "common:test:info:last", "common:test:info:pstart", "recent:test:info");
  }

  public function test_counters() {
    echo "Let's update some counters for now and a little in the future.\n";
    $now = time();
    for ($i = 0; $i < 10; $i++) {
      update_counter($this->con, "test", rand(1,5), $now + $i);
    }
    $counter = get_counter($this->con, "test", 1);
    echo "We have some per-second couters:", count($counter), "\n";
    $this->assertTrue(count($counter) >= 10);
    $counter = get_counter($this->con, "test", 5);
    echo "We have some per-5-second counters:", count($counter), "\n";
    echo "These counters include:";
    self::pprint($counter);
    $this->assertTrue(count($counter) >=2);
    echo "\n";

  }

  public function test_stats() {
    echo "Let's add some data for our statistics\n";
    for ($i = 0; $i < 5; $i++) {
      $r = update_stats($this->con, "temp", "example", rand(5, 15));
    }
    echo "We have some aggregate statistics:"; 
    self::pprint($r); 
    echo "\n";

    $rr = get_stats($this->con, "temp", "example");
    echo "Which we can also fetch manually:";
    self::pprint($rr);
    $this->assertTrue($rr["count"] >= 5);
  }

  public function test_access_time() {
    echo "Let's calculate some accees time...\n";
    for ($i = 0; $i < 10; $i++) {
      with_ctx(access_time($this->con, "req-".$i), function()use($i) {
        // usleep((0.5+random_float()) * 1000);
        sleep(mt_rand(1,5));
        echo "woke up ", $i, " times\n";
      });
    }
    echo "The slowest access times are:";
    $atimes = $this->con->zRevRange("slowest:AccessTime", 0, -1, true);
    self::pprint($atimes);
    $this->assertTrue(count($atimes)>=10);
    echo "\n";

    echo "Let's use the callback version...\n";
    for ($i = 0; $i < 5; $i++) {
      Request::$path = "cbreq-".$i;
      process_view($this->con, function()use($i) {
        // usleep((0.5+random_float()) * 1000);
        sleep(mt_rand(1,5));
        echo "woke up ", $i, " times\n";
      });
    }
    echo "The slowest access times are:";
    $atimes = $this->con->zRevRange("slowest:AccessTime", 0, -1, true);
    self::pprint($atimes);
    $this->assertTrue(count($atimes) > 10);
  }

  public function test_ip_lookup() {
    $con = $this->con;
    $blocks_file = "GeoLite2-City-Blocks-IPv4.csv";
    $locations_file = "GeoLite2-City-Locations-zh-CN.csv";
    if (!is_file($blocks_file) || !is_file($locations_file)) {
      echo "********\n";
      echo "You do not have the GeoLiteCity database available, aborting test\n";
      echo "Please have the floowing tow files in the current path:\n";
      echo $blocks_file,"\n";
      echo $locations_file, "\n";
      echo "********\n";
      return;
    }

    echo "Importing IP addresses to Redis...(this may take a while)\n";
    import_ips_to_redis($con, $blocks_file);
    $ranges = $con->zCard("ip2cityid:");
    echo "Loaded ranges into Redis:", $ranges, "\n";
    $this->assertTrue($ranges > 0);
    echo "\n";

    echo "Importing Location lookups to Redis... (this may take a while)\n";
    import_cities_to_redis($con, $locations_file);
    $cities = $con->hLen("cityid2city:");
    echo "Loaded city lookups into Redis:", $cities, "\n";
    $this->assertTrue($cities > 0);
    echo "\n";

    echo "Let's lookup one location(1.0.106.0) specified!\n";
    self::pprint(find_city_by_ip($con, "1.0.106.0"));

    echo "Let's lookup some location!\n";
    for ($i = 0; $i < 5; $i++) {
      self::pprint(find_city_by_ip($con, sprintf("%s.%s.%s.%s", rand(1,255),rand(1,255), rand(1,255), rand(1,255))));
    }
  }

  public function test_is_under_maintenance() {
    echo "Are we under maintenance (we shouldn't be)?",is_under_maintenance($this->con), "\n";
    $this->con->set("is-under-maintenance", 'yes');
    echo "we cached this, so it should be the same:",is_under_maintenance($this->con), "\n";
    sleep(1);
    echo "But after a sleep, it should change:", is_under_maintenance($this->con), "\n";
    echo "Cleaning up...\n";
    $this->con->delete("is-under-maintenance");
    sleep(1);
    echo "Should be False again:", is_under_maintenance($this->con);
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
    echo json_encode($var);
    echo "\n";
  }

  public function assertFalse($bool, $message = "") {
    if (!$bool) {
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

TestCh05::run();

?>