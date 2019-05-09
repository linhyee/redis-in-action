<?php 

function check_token(Redis $con, $token) {
  // Fetch and return the given user, if available
  return $con->hGet("login:", $token);
}

function update_token(Redis $con, $token, $user, $item = null) {
  // Get the timestamp
  $timestamp = time();
  // Keep a mapping fromthe token to the logged-in user
  $con->hSet("login:",$token, $user);
  // Record when the token was last seen
  $con->zAdd("recent:", $timestamp, $token);
  if ($item) {
    // Record that the user viewed the item
    $con->zAdd("viewed:".$token, $timestamp, $item);
    // Remove old items, keeping the most recent 25
    $con->zRemRangeByRank("viewed:".$token, 0, -26);
  }
}

$QUIT = false;
const LIMIT = 10000000;

function clean_sessions(Redis $con) {
  global $QUIT;

  while (!$QUIT) {
    // Find out how many tokens are known
    $size = $con->zSize("recent:");
    // We are still under our limit, sleep an try again
    if ($size <= LIMIT) {
      sleep(1);
      continue;
    }

    // Fetch the token ids that should be removed
    $end_index = min($size - LIMIT, 100);
    $tokens = $con->zRange("recent:", 0, $end_index -1);

    // Prepare the key names for the tokens to delete
    $session_keys = [];
    foreach ($tokens as $token) {
      $session_keys[] = "viewed:".$token;
    }

    // Remove
    $con->delete($session_keys);
    $con->hDel("login:", $tokens);
    $con->zRem("recent:", $tokens);
  }
}

function add_to_cart(Redis $con, $session, $item, $count) {
  if ($count <= 0) {
    // Remove the item from the cart
    $con->hRem("cart:".$session, $item);
  } else {
    //Add the item to the cart
    $con->hSet("cart:".$session, $item, $count);
  }
}

function clean_full_sessions(Redis $con) {
  global $QUIT;

  while (!$QUIT) {
    $size = $con->zSize("recent:");
    if ($size <= LIMIT) {
      sleep(1);
      continue;
    }

    $end_index = min($size - LIMIT, 100);
    $sessions = $con->zRange("recent:", 0, $end_index -1);

    $session_keys = [];
    foreach ($sessions as $sess) {
      $session_keys[] = "viewed:".$sess;
      //The required added line to delete the shopping cart for old sessions
      $session_keys[] = "cart:".$sess;
    }

    $con->delete($session_keys);
    $con->hDel("login:", $sessions);
    $con->zRem("recent:", $sessions);
  }
}


function cache_request(Redis $con, $request, $callback) {
  // If we cannot cache the request, immediately call the callback
  if (!can_cache($con, $request)) {
    return callback($request);
  }

  // Convert the request into a simple string key for later lookups
  $page_key = "cache:".hash_request($request);
  // Fetch the cached content if we can, and it is available
  $content = $con->get($page_key);

  if (!$content) {
    // Generate the content if we can't cache the page, or if it wasn't cached
    $content = callback($request);
    //Cache the newly generated content if we can cache it
    $con.setEx($page_key, 300, $content);
  }

  // Return the content
  return $content;
}

function schedule_row_cache(Redis $con, $row_id, $delay) {
  // Set the delay for the item first
  $con->zAdd("delay:", $delay, $row_id);
  // Schedule the item to be cached now
  $con->zAdd("scehdule:", time(), $row_id);
}

function cache_rows(Redis $con) {
  while (!$QUIT) {
    // Find the next row that should be cached (if any), including the timestamp,
    // as a list of tuples with zero or one items on Python, but array in PHP.
    $next = $con->zRangeByScore("schedule:", 0, 0, ["withscores" => true]);
    $now = time();
    if ($next || array_values($next)[0] > $now) {
      // No rows can be cached now, so wait 50 milliseconds and try again
      usleep(0.05 *1000);
      continue;
    }

    $row_id = key($next);
    // Get the delay before the next schedule
    $delay = $con->zScore("delay:", $row_id);
    if ($delay <= 0) {
      // The item shouldn't be cached anymore, remove it from the cache
      $con->zRem("delay:", $row_id);
      $con->zRem("schedule:", $row_id);
      $con->delete("inv:".$row_id);
      continue;
    }

    // Get the database row
    $row = Inventory::get($row_id);
    // Update the schedule and set cache value
    $con->zAdd("schedule:", $now+$delay, $row_id);
    $con->set("inv:"+$row_id, $row->to_json());
  }
}

function update_token_02(Redis $con, $token, $user, $item = null) {
  $timestamp = time();
  $con->hSet("login:", $token, $user);
  $con->zAdd("recent:", $timestamp, $token);
  if ($item) {
    $con->zAdd("viewed:".$token, $timestamp, $item);
    $con->zRemRangeByRank("viewed:".$token, 0, -26);
    // The line we need to add to update_token()
    $con->zIncryBy("viewed:", -1, $item);
  }
}

function rescale_viewed(Redis $con) {
  while (!$QUIT) {
    // Remove any item not in the top 20000 viewed items
    $con->zRemRangeByRank("viewed:", 20000, -1);
    // Rescale all counts to be 1/2 of what they were before
    // $con->zInter("viewed:", ["viewed:"], [0.5]);
    // Do ti again in 5 minutes
    sleep(300);
  }
}

function can_cache(Redis $con, $request) {
  // Get the item id for the page, if any
  $item_id = extract_item_id($request);
  // Check whether the page can be statically cached,
  // and whether this is an item page
  if (!$item_id || is_dynamic($request)) {
    return false;
  }
  // Get the rank of the item
  $rank = $con->zRank("viewed:", $item_id);
  // Return whether the item has a high enough view count to be cached
  return $rank != null && $rank < 10000;
}

#---------------- Below this line  are helpers to test the code -------------#

/**
 * short string unique id, Just test for this file
 */
function uuid($entropy = false) {
  $s = uniqid("", $entropy);
  $n = hexdec(str_replace(".", "", strval($s)));
  $i = "1234567890QWERTYUIOPASDFGHJKLZXCVBNM";
  $base = strlen($i);
  $out = "";
  for ($t = floor(log10($n) / log10($base)); $t >= 0; $t--) {
    $a = floor($n / pow($base, $t));
    $out = $out.substr($i, $a, 1);
    $n = $n - ($a * pow($base, $t));
  }

  return $out;
}

function extract_item_id($request) {
  $parsed = parse_url($request);
  $query = [];
  parse_str($parsed["query"], $query);

  return isset($query["item"]) ? $query["item"] : "";
}

function is_dynamic($request) {
  $parsed = parse_url($request);
  $query = [];
  parse_str($parsed["query"], $query);

  return array_key_exists("_", $query);
}

function hash_request($request) {
  return hash("sha1", $request);
}

class Inventory {
  private $id;

  public function __construct($id) {
    $this->id = $id;
  }

  public static function get($id) {
    return new self($id);
  }

  public function to_json() {
    return json_encode(["id"=>$this->id, "data"=>"data to cache...", "cached"=> time()]);
  }
}

// test routine function
function routine(Redis $con) {
  $s = $con->hGetAll("hash-key");
  // print_r($s);
  echo current($s), "\n";
  sleep(1);
}

declare(ticks = 1);
function sig_handler($signo) {
  global $QUIT;
  if ($signo == SIGUSR1) {
    echo "got signal!!\n";
    $QUIT = true;
  }
  var_dump($signo);
}

/**
 * registe signal handlers
 */
function init_signals() {
  if (!function_exists("pcntl_signal")) {
    echo "PCNTL functions not available on this PHP installation!\n";
    return;
  }
  if (!pcntl_signal(SIGUSR1, "sig_handler")) {
    echo "register singal error!\n";
    exit(-1);
  }
}

function tell_quit($pid) {
  if (!function_exists("posix_kill")) {
    echo "PCNTL functions not available on this PHP installation!\n";
    return;
  }
  if (!posix_kill($pid, SIGUSR1)) {
    echo "kill error!\n";
  }
}

function fork($routine, array $args) {
  if (!function_exists("pcntl_fork")) {
    echo "PCNTL functions not available on this PHP installation!\n";
    return false;
  }
  global $QUIT;
  $pid = pcntl_fork();
  if ($pid == -1) {
    echo "could not fork";
    exit(-1);
  } else if ($pid == 0) {
    // Would be block signal when routine function is unlimited loop !!!
    // if (function_exists($routine)) {
    //   call_user_func_array($routine, $args);
    // }

    while (!$QUIT) {
      call_user_func_array($routine, $args);
    }
    echo "\nEND\n";
    // Let it terminated!
    exit(0);
  } else {
    return $pid;
  }
}

function wait(&$status) {
  if (!function_exists("pcntl_wait")) {
    echo "PCNTL functions not available on this PHP installation!\n";
    return;
  }
  pcntl_wait($status);
  $exit_status = pcntl_wexitstatus($status);
  if ($exit_status !== 0) {
    echo "exited with exit code ", $exit_status, "\n";
    exit(-1);
  }
}

class TestCh02 {
  public $con = null;

  public function __construct() {
    init_signals();
    $this->con = $this->new_redis();
  }

  public function new_redis() {
    $redis = new Redis();
    // $redis->connect("127.0.0.1", 6379);
    $this->con->connect("192.168.71.210", 7001);
    $this->con->auth("yis@2019._");
    return $redis;
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
        call_user_func(array($tester, $test));
      }
    }
  }

  public function test_login_cookies() {
    $con = $this->con;
    $token = strval(uuid());

    update_token($con, $token, "username", "itemX");
    echo "We just logged-in/updated token:", $token, "\n";
    echo "For user:", "username";
    echo "\n";

    echo "What username do we get when we look-up that token?";
    echo "\n";
    $r = check_token($con, $token);
    var_dump($r);
    echo "\n";
    $this->assertTrue($r);

    echo "Let's drop the maxinum number of cookies to 0 to clean them out\n";
    echo "We will start a thread to do the cleaning, while we stop it later\n";

    $limit = 0;
    $pid = fork("routine", [$this->new_redis()]);
    echo "fork child:", $pid, "\n";
    sleep(1);
    tell_quit($pid);
    sleep(2);

    echo "\n";
    $status = "";
    wait($status);

    $s = $con->hLen("login:");
    echo "The current number of sessions still available is :", $s;
    echo "\n";
    $this->assertTrue(!$s);
  }

  public function test_shopping_cart_cookies() {
    $con = $this->con;
    $token = strval(uuid());

    echo "We'll refresh our session...\n";
    update_token($con, $token, "username", "itemX");
    echo "And add an item to the shopping cart\n";
    add_to_cart($con, $token, "itemY", 3);
    $r = $con->hGetAll("cart:".$token);
    echo "Our shopping cart currently has:", json_encode($r);
    echo "\n";

    $this->assertTrue(count($r) >= 1);

    echo "Let's clean out our sessions and carts\n";
    $limt = 0;
    $pid = fork("clean_full_sessions",[$this->new_redis()]);
    echo "fork child:", $pid, "\n";
    sleep(1);
    tell_quit($pid);
    sleep(2);

    echo "\n";
    $status = "";
    wait($status);

    $r = $con->hGetAll("cart:".$token);
    echo "Our shopping cart now contains:", json_encode($r);
    echo "\n";
    $this->assertTrue(!$r);
  }

  public function test_cache_request() {
    $con = $this->con;
    $token = strval(uuid());

    $callback = function ($request) {
      return "content for" . $request;
    };

    update_token($con, $token, "username", "itemX");
    $url = "http://test.com/?item=itemX";
    echo "We are going to cache a simple request against ", $url;
    echo "\n";
    $result = cache_request($con, $url, $callback);
    echo "\n";

    $this->assertTrue($result);

    echo "To test that we've cached the request, we'll pass a bad callback\n";
    $result2 = cache_request($con, $url, null);

    // For assertEquals
    $this->assertTrue($result === $result2);

    $this->assertTrue(can_cache($con, "http://test.com/"));
    $this->assertTrue(can_cache($con, "http://test.com/?item=itemX&_=123456"));
  }

  // We aren't going to bother with the top 10k requests are cached, as
  // we already tested it as part of the cached requests test.
  public function test_cache_rows() {
    $con = $this->con;

    echo "First, let's schedule caching of itemX every 5 seconds\n";
    schedule_row_cache($con, "itemX", 5);
    echo "Our scehdule looks like:";
    $s = $con->zRange("schedule:", 0, -1, $withscores=true);
    echo json_encode($s), "\n";
    $this->assertTrue($s);

    echo "We'll start a caching thread that will cache the data...\n";
    $pid = fork("cache_rows", [$this->new_redis()]);
    sleep(1);
    echo "Our cached data looks like:";
    $r = $con->get("inv:itemX");
    echo json_encode($r);
    echo "\n";
    $this->assertTrue($r);
    echo "\n";
    echo "We'll check again in 5 seconds...\n";
    sleep(5);
    echo "Notice that the data has change...\n";
    $r2 = $con->get("inv:itemX");
    echo json_encode($r2);
    echo "\n";
    $this->assertTrue($r2);
    $this->assertTrue($r != $r2);

    echo "Let's force un-caching\n";
    schedule_row_cache($con, "itemX", -1);
    sleep(1);
    $r = $con->get("inv:itemX");
    echo "The cache was cleared?",var_dump(!$r);
    echo "\n";
    $this->assertTrue(!$r);

    tell_quit($pid);
    sleep(2);
    $status = "";
    wait($status);
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

    echo "assert fail:", basename(__FILE__), ":", $line, ",", $message;
    echo "\n";
    exit(-1);
  }

  public function __destruct() {
    $con = $this->con;
    $to_del = array_merge($con->keys("login:*"),
      $con->keys("recent:*"),
      $con->keys("viewed:*"),
      $con->keys("cart:*"),
      $con->keys("cache:*"),
      $con->keys("delay:*"),
      $con->keys("schedule:*"),
      $con->keys("inv:*")
    );
    if (!empty($to_del)) {
      $con->delete($to_del);
    }

    $this->con->close();
    $this->con = null;
    $con = null;
  }
}

TestCh02::run();

 ?>