Psy Shell v0.9.9 (PHP 7.2.3 - cli) by Justin Hileman
>>> $con = new Redis();       
=> Redis {#2290
     isConnected: false,
   }
>>> $con->connect("127.0.0.1", 6379);
=> true
>>> $con->get("key");                  #A
=> false
>>> $con->incr("key");                 #B
=> 1                                   #B
>>> $con->incr("key", 15);             #B
=> 16                                  #B
>>> $con->decr("key", 5);              #C
=> 11                                  #C
>>> $con->get("key");                  #D
=> "11"                                #D
>>> $con->set("key", "13");            #E
=> true                                #E
>>> $con->incr("key");                 #E
=> 14                                  #E
>>> 
#A When we fetch a key that does not exist, we get the False value, which is dsplayed in false in the interactive console.
#B We can increment keys that don't exist, and we can pass an optional value to increment by more than 1
#C Like incrementing, descremneting takeks an optional argument for the amount to decrement by
#D When we fetch the key it acts like a string
#E And when we se the key, we can set it as a string, but still manipulate it like an integer

>>> $con->append("new-string-key", "hello");     #A
=> 5                                             #B
>>> $con->append("new-string-key", " world!");
=> 12                                            #B
>>> $con->substr("new-string-key", 3, 7);        #C
=> "lo wo"                                       #D
>>> $con->setRange("new-string-key", 0, "H");    #E
=> 12                                            #F
>>> $con->setRange("new-string-key", 6, "W");
=> 12
>>> $con->get("new-string-key");                 #G
=> "Hello World!"                                #H
>>> $con->setRange("new-string-key", 11, ", how are you?");  #I
=> 25
>>> $con->get("new-string-key");
=> "Hello World, how are you?"                   #J
>>> $con->setBit("another-key", 2, 1);           #K
=> 0                                             #L
>>> $con->setBit("another-key", 7, 1);           #M
=> 0                                             #M
>>> $con->get("another-key");                    #M
=> "!"                                           #N
>>> 
#A Let's append the string 'hello' to the previously non-existent key 'new-string-key'
#B When appending a value, Redis return the length of the string so far
#C Reids use 0-indexing, and when accessing ranges, is inclusive of the endpoints by default
#D The string 'lo wo' is from the middle of 'hello world!'
#E Let's set a couple string ranges
#F When setting a range inside a string, Redis also returns the total length of the string
#G Let's see what we have now!
#H Yep, we capitalized our 'H' and 'W'
#I With setrange we can replace anywhere inside the string,and we can make the string longer
#J We replaced the exclamation point and added more to the end of the string
#K If you write to a bit beyond the size of the string, it is filled with nulls
#L Setting bits also returns the value of the bit before it was set
#M If you are going to try to interpret the bits stored in Redis, remember that offsets into bits are from the highest-order to the lowest-order
#N We set bits 2 and 7 to 1, which gave us '!', or characer 33

>>> $con->rPush("list-key", "last");
=> 1
>>> $con->rPush("list-key", "first");
=> 2
>>> $con->rPush("list-key", "new last");
=> 3
>>> $con->lRange("list-key", 0, -1);
=> [
     "last",
     "first",
     "new last",
   ]
>>> clear                 

>>> $con->rPush("list-key", "last");          #A
=> 1                                          #A
>>> $con->lPush("list-key", "first");         #B
=> 2
>>> $con->rPush("list-key", "new last");
=> 3
>>> $con->lRange("list-key", 0, -1);          #C
=> [                                          #C
     "first",
     "last",
     "new last",
   ]
>>> $con->lPop("list-key");                   #D
=> "first"                                    #D
>>> $con->lPop("list-key");                   #D
=> "last"                                     #D
>>> $con->lRange("list-key", 0, -1);
=> [
     "new last",
   ]
>>> $con->rPush("list-key", "a", "b", "c");   #E
=> 4
>>> $con->lRange("list-key", 0, -1);
=> [
     "new last",
     "a",
     "b",
     "c",
   ]
>>> $con->lTrim("list-key", 2, -1);           #F
=> true                                       #F
>>> $con->lRange("list-key", 0, -1);          #F
=> [                                          #F
     "b",
     "c",
   ]
>>> 
#A When we push items onto the list, it returns the length of the list after the push has completed
#B We can easily push on both ends of the list
#C Semantically, the left end of the list is the beginning, and the right end of the list is the end
#D Popping off the left items repeatedly will return items from left to right
#E We can push multiple items at the same time
#F We can trim any number of items from the start, end, or both

>>> $con->rPush("list", "item1");            #A
=> 1                                         #A
>>> $con->rPush("list", "item2");            #A
=> 2                                         #A
>>> $con->rPush("list2", "item3");           #A
=> 1                                         #A
>>> $con->bRPopLPush("list2", "list", 1);    #B
=> "item3"                                   #B
>>> $con->brpoplpush("list2", "list", 1);    #C
=> false
>>> $con->lRange("list", 0, -1);             #D
=> [                                         #D
     "item3",
     "item1",
     "item2",
   ]
>>> $con->bRPopLPush("list", "list2", 1);
=> "item2"
>>> $con->blPop(["list", "list2"], 1);       #E
=> [
     "list",
     "item3",
   ]
>>> $con->blPop(["list", "list2"], 1);       #E
=> [
     "list",
     "item1",
   ]
>>> $con->blPop(["list", "list2"], 1);       #E
=> [
     "list2",
     "item2",
   ]
>>> $con->blPop(["list", "list2"], 1);       #E
=> []
>>> 
#A Let's add some items to a couple lists to start
#B Let's move an item from one list to the other, leaving it
#C When a list is empty, the blocking pop will stall for the timeout, and return False(which is displayed in the interactive console)
#D We popped the rightmost item from 'list2' and pushed it to the left of 'list'
#E Blocking left-popping items from these will check lists for items in the order that they are passed, until they are empty

<?php
function update_token_02(Redis $con, $token, $user, $item = null) {
  $timestamp = time();
  $con->hset("login:", $user, $token);
  $con->zAdd("recent:", $timestamp, $token);

  if ($item) {
    $key = "viewed:".$token;
    // Remove the item from the list if it was there
    $con->lRem($key, $item);
    // Push the item to the right side of the LIST so that ZRANGE 
    // and LRANGE have the same result
    $con->rPush($key, $item);
    // Trim the LIST to only include the most recent 25 items
    $con->lTrim($key, -25, -1);
    $con->zIncrBy("viewed:", -1, $item);
  }
}

?>

>>> $con->sAdd("set-key", "a", "b", "c");    #A
=> 3                                         #A
>>> $con->sRem("set-key", "c", "d");         #B
=> 1                                         #B
>>> $con->sRem("set-key", "c", "d");         #B
=> 0                                         #B
>>> $con->sCard("set-key");                  #C
=> 2                                         #C
>>> $con->sMembers("set-key");               #D
=> [
     "a",
     "b",
   ]
>>> $con->sMove("set-key", "set-key2", "a"); #E
=> true                                      #E
>>> $con->sMove("set-key", "set-key2", "c"); #F
=> false                                     #F
>>> $con->sMembers("set-key2");              #F
=> [
     "a",
   ]
>>> 
#A Adding items to the SET returns the number of items that weren't already in the SET
#B Removing items from the SET returns whether an item was removed - note that the client is buggy in that respect[just for python], [But in php]as Redis itself returns the total number of items removed
#C We can get the number of items in the SET
#D We can also fetch the whole SET
#E We can easily move items from on SET to another SET
#F When an item doesn't exist in the first set during a SMOVE, it isn't added to the destination SET

>>> $con->sAdd("skey1", "a", "b", "c", "d"); #A
=> 4
>>> $con->sAdd("skey2", "c", "d", "e", "f"); #A
=> 4
>>> $con->sDiff("skey1", "skey2");           #B
=> [                                         #B
     "a",
     "b",
   ]
>>> $con->sInter("skey1", "skey2");          #C
=> [
     "c",
     "d",
   ]
>>> $con->sUnion("skey1", "skey2");          #D
=> [
     "c",
     "e",
     "d",
     "a",
     "f",
     "b",
   ]
>>> 
#A First we'll add a few items to a couple SETs
#B We can calculate the result of removing all of the items in the second set from the first SET
#C We can also find out which items exist in both SETs
#D And we can find out all of the items that are in either of the SETs

>>> $con->hMset("hash-key", ["k1"=>"v1", "k2"=>"v2","k3"=>"v3"]); #A
=> true
>>> $con->hMget("hash-key", ["k2", "k3"]);                        #B
=> [
     "k2" => "v2",
     "k3" => "v3",
   ]
>>> $con->hLen("hash-key");                                       #C
=> 3
>>> $con->hDel("hash-key", "k1", "k3");                           #D
=> 2
>>> 
#A We can add multiple items to the hash in one call
#B We can fetch a subset of the values in a single call
#C The HLEN command is typically used for debugging very large HASHes
#D The HDEL command handles multiple arguments without needing an HMDEL counterpart and returns number of items if any fields were removed

>>> $con->hMset("hash-key2", ["short"=>"hello", "long"=>1000*"1"]); #A
=> true
>>> $con->hKeys("hash-key2");                                       #A
=> [
     "short",
     "long",
   ]
>>> $con->hExists("hash-key2", "num");                              #B
=> false
>>> $con->hIncrBy("hash-key2", "num", 1);                           #C
=> 1
>>> $con->hExists("hash-key2", "num");                              #C
=> true
>>> 
#A Fetching keys can be useful to keep from needing to transfer large values when you are looking into HASHes
#B We can also check the existence of specific keys
#C Incremneting a previously non-existent key in a hash behaves just like on strings, Redis operates as though the value had been 0

>>> $con->zAdd("zset-key", 3, "a", 2, "b", 1, "c");                 #A
=> 3
>>> $con->zCard("zset-key");                                        #B
=> 3
>>> $con->zIncrBy("zset-key", 3, "c");                              #C
=> 4.0
>>> $con->zScore("zset-key", "b");                                  #D
=> 2.0
>>> $con->zRank("zset-key", "c");                                   #E
=> 2
>>> $con->zCount("zset-key", 0, 3);                                 #F
=> 2
>>> $con->zRem("zset-key", "b");                                    #G
=> 1
>>> $con->zRange("zset-key", 0, -1, true);                          #H
=> [
     "a" => 3.0,
     "c" => 4.0,
   ]
>>> 
#A Knowing members to ZSETs in PHP has the arguments  same compared to standard Redis, so as to confuse users compared to HASHes
#B Knowing how large a ZSET is can tell you in some cases if it is necessary to trim your ZSET
#C We cna also increment members like we can with STRING and HASH values
#D Fetching scores of individual members can be userful if you have been keeping counters or toplists
#E By fetching the 0-indexed position of a member, we can then later use ZRANGE to fetch a range of the values easily
#F Counting the number of items with a given range of scores can be quite useful for some tasks
#G Removing members is as easy as adding them
#H For debugging, we usually fetch the entire ZSET with this ZRANGE call, but real use-cases will usually fetch items a relatively small group at a time

>>> $con->zAdd("zset-1", 1, "a", 2, "b", 3, "c");                 #A
=> 3
>>> $con->zAdd("zset-2", 4, "b", 1, "c", 0, "d");                 #A
=> 3
>>> $con->zInter("zset-i", ["zset-1", "zset-2"]);                 #B
=> 2
>>> $con->zRange("zset-i", 0, -1, true);                          #B
=> [
     "c" => 4.0,
     "b" => 6.0,
   ]
>>> $con->zUnion("zset-u", ["zset-1", "zset-2"], [1,1], "min");   #C
=> 4
>>> $con->zRange("zset-u", 0, -1, true);                          #C
=> [
     "d" => 0.0,
     "a" => 1.0,
     "c" => 1.0,
     "b" => 2.0,
   ]
>>> $con->sAdd("set-1", "a", "d");                                #D
=> 2
>>> $con->zUnion("zset-u2", ["zset-1", "zset-2", "set-1"]);       #D
=> 4
>>> $con->zRange("zset-u2", 0, -1, true);                         #D
=> [
     "d" => 1.0,
     "a" => 2.0,
     "c" => 4.0,
     "b" => 6.0,
   ]
>>> 
#A We'll start out by creating a couple ZSETs
#B When performing ZINTERSTORE or ZUNIONSTORE, out default aggregate is sum, so scores of items that are in multiple ZSETs are added
#C It's easy to provide different aggregates, though we are limited to sum, min, and max
#D You can also pass SETs as inputs to ZINTERSTORE and ZUNIONSTORE, they behave as though they were ZSETs with all scores equal to 1

<?php 
class MyThread extends Thread {
  // The routine function
  public $routine = null;
  // The routine function params
  public $args = null;

  public function __construct($routine, array $args) {
    if (!is_callable($routine)) {
      throw new Exception("function `$routine` unable callabled", 1);
    }
    $this->routine = $routine;
    $this->args = (array)$args;
  }

  public function run() {
    call_user_func_array($this->routine, $this->args);
  }
}

function new_redis() {
  $con = new Redis();
  $con->connect("127.0.0.1", 6379);

  return $con;
}

function publisher($n) {
  // Because of php's thread extension didn't support global 
  // context sharing
  $con = new_redis();
  // We sleep initially in the function to let the SUBSCRIBEr 
  // connect and start receiving messages
  sleep(1);
  foreach (range($n,10) as $i) {
    // After publishing, we will pause for a moment so that 
    // we can see this happen over time
    $b = $con->publish("channel", $i);
    if ($b == 0) {
      break;
    }
    sleep(1);
  }
  echo "published completed!\n";
}

function run_pubsub() {
  $con = new_redis();
  // Let's start the publisher thread to send 3 message
  $thread = new MyThread("publisher", [3]);
  $thread->start();
  $count = 0;
  // Subscribe to a channel
  $pubsub = $con->subscribe(["channel"], function(Redis $con, $channel, $msg) use(&$count){
    // Counting for belowing handles
    $count++;
    // We'll print every message that we receive
    echo "counted:", $count, " msg:", $msg, "\n";
    if ($count == 4) {
      // I think it's bugs off phpredis ext When we unsubscribed 
      // but subscribe function did't stop!!!
      $con->rawCommand("unsubscribe", "channel");
      return;
    }

    if ($count == 5) {
      // When we recive the unsubscribe message, 
      // we need to stop receiving message

      // throw new Exception("closuer quit!", 1);
      // return;
      // or this
      // exit;

      // Forgot it, seems to be no fucking ways ending subscribe call 
      // because redis read channel blocked, and call callback after
    }
  });
  $thread->join();
  echo "thread joined!";
}

// run_pubsub();
 ?>

>>> $con->rPush("sort-input", 23, 15, 110, 7);                             #A
=> 4
>>> $con->sort("sort-input");                                              #B
=> [
     "7",
     "15",
     "23",
     "110",
   ]
>>> $con->sort("sort-input", ["alpha"=>true]);                             #C
=> [
     "110",
     "15",
     "23",
     "7",
   ]
>>> $con->sort("sort-input", ["sort"=>"desc"]);
=> [
     "110",
     "23",
     "15",
     "7",
   ]
>>> $con->hSet("d-7", "field", 5);                                         #D
=> 1
>>> $con->hSet("d-15", "field", 1);                                        #D
=> 1
>>> $con->hSet("d-23", "field", 9);                                        #D
=> 1
>>> $con->hSet("d-110", "field", 3);                                       #D
=> 1
>>> $con->sort("sort-input", ["by"=> "d-*->field"]);                       #E
=> [
     "15",
     "110",
     "7",
     "23",
   ]
>>> $con->sort("sort-input", ["by"=>"d-*->field", "get"=>"d-*->field"]);   #F
=> [
     "1",
     "3",
     "5",
     "9",
   ]
>>> 
#A Start by adding some items to a LIST
#B We can sort the items numerically
#C And we can sort the items alphabetically
#D We are just adding some additional data for SORTing and fetching
#E We can sort our data by fields of HASHes
#F And we can even fetch that data and return it instead of or in addition to our input data

<?php 

function notrans() {
  $con = new_redis();
  // Increment the 'notrans:' counter an print the result
  echo $con->incr("notrans:", 1), "\n";
  // Wait for 100 milliseconds
  usleep(100);
  // Decrement the 'notrans:' counter
  $con->incr("notrans:", -1);
}

function trans() {
  $con = new_redis();
  // Create a transactional pipeline
  $pipeline = $con->multi(Redis::PIPELINE);
  // Queue up the 'trans:' counter increment
  $pipeline->incr("trans:", 1);
  // Wait for 100 milliseconds
  usleep(100);
  // Queue up the 'trans:' counter decrement
  $pipeline->incr("trans:", -1);
  // Execute both commands and print the result of the increment operation
  print_r($pipeline->exec());
}

function run_test($callback) {
  $thrs = [];
  foreach (range(1, 3) as $i) {
    // Start three of the non-transactional/transactional increment/sleep/decrement calls
    $thread = new MyThread($callback, null);
    $thread->start();
    $thrs[] = $thread;
  }
  // Wait half a second for everything to be done
  usleep(500);
  foreach ($thrs as $thr) {
    $thr->join();
  }
}
run_test("notrans");
run_test("trans");
 ?>


<?php 
const ONE_WEEK_IN_SECONDS = 604800;
const VOTE_SCORE = 432;

function article_vote(Redis $con, $user, $article) {
  $cutoff = time() - ONE_WEEK_IN_SECONDS;
  // If the article should expire between  our ZSCORE and our SADD,
  // we need to use the posted time to properly expire it
  $posted = $con->zScore("time:", $article);
  if ($posted < $cutoff) {
    return;
  }
  $article_id = explode(":", $article)[1];
  $pipeline = $con->multi(Redis::PIPELINE);
  $pipeline->sAdd("voted:".$article_id, $user);
  // Set the expiration time if we shouldn't have actually added the vote to SET
  $pipeline->setTimeout("voted:".$article_id, intval($posted-$cutoff));

  if ($pipeline->exec()[0]) {
    // We could lose our connection between the SADD/EXPIRE and ZINCRBY/HINCRBY, 
    // so the vote may not count, but that is better than it partially counting
    // by failing between ZINCRBY/HiNCRBY calls
    $pipeline->zIncrBy("score:", VOTE_SCORE, $article);
    $pipeline->hIncrBy($article, "votes", 1);
    $pipeline->exec();
  }
}

const ARITCLES_PER_PAGE = 25;
function get_articles(Redis $con, $page, $order="score:") {
  $start = max($page -1, 0) * ARITCLES_PER_PAGE;
  $end = $start + ARITCLES_PER_PAGE -1;

  $ids = $con->zRevRangeByScore($order, $start, $end);

  $pipeline = $con->multi(Redis::PIPELINE);
  // Prepare the HGETALL calls on the pipeline
  array_map(function($id) use($pipeline) { return $pipeline->hGetAll($id);}, $ids);

  $articles = [];
  // Excecute the pipeline and add ids to the article
  foreach (array_combine($ids, $pipeline->exec()) as $id => $article_data) {
    $article_data["id"] = $id;
    $articles[] = $article_data;
  }

  return $articles;
}

 ?>

>>> $con->set("key", "value");                   #A
=> true
>>> $con->get("key");                            #A
=> "value"
>>> $con->expire("key",2);                       #B
=> true
>>> sleep(2);                                    #B
=> 0
>>> $con->get("key");                            #B
=> false
>>> $con->set("key", "value2");
=> true
>>> $con->expire("key", 100); $con->ttl("key");  #C
=> 100
>>> 
#A We are starting with a very simple STRING value
#B If we set a key to expire in the future, and we wait long enough for to expire, when we try to fetch the key, it has already been deleted
#C We can also easily find out how long it will be before a key will expire

<?php 
const THIRTY_DAYS = 2592000; // 30 * 86400

function check_token_02(Redis $con, $token) {
  // We are going to store the login token as a string value so we can EXPIRE it
  return $con->get("login:".$token);
}

function update_token_03(Redis $con, $token, $item = null) {
  // Set the value of the login token and the token's expiration time with one call
  $con->setEx("login:".$token, $user, THIRTY_DAYS);
  if ($item) {
    $con->lRem($key, $item);
    $con->rPush($key, $item);
    $con->lTrim($key, -25, -1);
    $con->zIncrBy("viewed:", $item, -1);
  }

  // We can't manipulate LISTs and set theire expiration 
  // at the same time, so we must do it later
  $con->expire($key, THIRTY_DAYS);
}

function add_to_cart_02(Redis $con, $session, $item, $count) {
  $key = "cart:".$session;
  if ($count <= 0) {
    $con->hRem($key, $item);
  } else {
    $con->hSet($key, $item, $count);
  }
  // We also can't manipulate HASHes and set their expiration times, 
  // so we again do it later
  $con->expire($key, THIRTY_DAYS);
}

 ?>
