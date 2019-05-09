[root@VM_0_8_centos ~]# /usr/local/redis/bin/redis-cli #A
127.0.0.1:6379> set hello world                        #B
OK                                                     #C
127.0.0.1:6379> get hello                              #D
"world"                                                #E
127.0.0.1:6379> del hello                              #F
(integer) 1                                            #G
127.0.0.1:6379> get hello                              #H
(nil)
127.0.0.1:6379>
#A Start the redis-cli client up.
#B Set the key hello to the value world.
#C If a SET command succeeds, it returns OK.
#D Now get the value stored at the key hello.
#E It's still world, like we just set it.
#F Let's delete the key-value pair.
#G If there was a value to delete, DEL returns the number of items that were delete.
#H There's no more value, so trying to fetch the value returns nil.


127.0.0.1:6379> rpush list-key item   #A
(integer) 1
127.0.0.1:6379> rpush list-key item2  #A
(integer) 2
127.0.0.1:6379> rpush list-key item   #A
(integer) 3
127.0.0.1:6379> lrange list-key 0 -1  #B
1) "item"
2) "item2"
3) "item"
127.0.0.1:6379> lindex list-key 1     #C
"item2"
127.0.0.1:6379> lpop list-key         #D
"item"
127.0.0.1:6379> lrange list-key 0 -1
1) "item2"
2) "item"
127.0.0.1:6379>
#A When we push items onto a LIST, the command returns the current length of the list.
#B We can fetch the entire list by passing a range of 0 for the start index and -1 for the last index.
#C We can fetch individual items from the list with LINDEX by passing index number.
#D Popping an item from the list makes it no longer available.

127.0.0.1:6379> sadd set-key item        #A
(integer) 1
127.0.0.1:6379> sadd set-key item2       #A
(integer) 0
127.0.0.1:6379> sadd set-key item3       #A
(integer) 0
127.0.0.1:6379> sadd set-key item4       #A
(integer) 1
127.0.0.1:6379> sadd set-key item        #A
(integer) 0
127.0.0.1:6379> smembers set-key         #B
1) "item"
2) "item4"
3) "item1"
4) "item2"
5) "item3"
127.0.0.1:6379> sismember set-key item5  #C
(integer) 0
127.0.0.1:6379> sismember set-key item   #C
(integer) 1
127.0.0.1:6379> srem set-key item2       #D
(integer) 1
127.0.0.1:6379> srem set-key item2       #D
(integer) 0
127.0.0.1:6379> smembers set-key
1) "item"
2) "item4"
3) "item1"
4) "item3"
127.0.0.1:6379>
#A When adding an item to s SET, Redis will return a 1 if the item is new to the set and 0 if it was already in the SET.
#B We can fetch all of the items in the SET.
#C We can also ask Redis whether an item is in the SET.
#D When we attempt to remove items, our commands return the number of items that were removed.

127.0.0.1:6379> hset hash-key sub-key1 value1  #A
(integer) 1
127.0.0.1:6379> hset hash-key sub-key2 value2  #A
(integer) 1
127.0.0.1:6379> hset hash-key sub-key1 value1  #A
(integer) 0
127.0.0.1:6379> hgetall hash-key               #B
1) "sub-key1"
2) "value1"
3) "sub-key2"
4) "value2"
127.0.0.1:6379> hdel hash-key sub-key2         #C
(integer) 1
127.0.0.1:6379> hdel hash-key sub-key2         #C
(integer) 0
127.0.0.1:6379> hget hash-key sub-key1         #D
"value1"
127.0.0.1:6379> hgetall hash-key
1) "sub-key1"
2) "value1"
127.0.0.1:6379>
#A When we add items to a hash, again we get a return value that tells whether the item is new in the hash.
#B We can fetch all of the items in the HASH.
#C When we delete items from the hash, the command returns whether the item was there before we tried to remove it.
#D We can also fetch individual fields from hashes.

127.0.0.1:6379> zadd zset-key 728 member1                  #A
(integer) 1
127.0.0.1:6379> zadd zset-key 982 member0                  #A
(integer) 1
127.0.0.1:6379> zadd zset-key 982 member0                  #A
(integer) 0
127.0.0.1:6379> zadd zset-key 982 member2                  #A
(integer) 1
127.0.0.1:6379> zrange zset-key 0 -1 withscores            #B
1) "member1"
2) "728"
3) "member0"
4) "982"
5) "member2"
6) "982"
127.0.0.1:6379> zrangebyscore zset-key 0 800 withscores    #C
1) "member1"
2) "728"
127.0.0.1:6379> zrem zset-key member1                      #D
(integer) 1
127.0.0.1:6379> zrem zset-key member1                      #D
(integer) 0
127.0.0.1:6379> zrange zset-key 0 -1 withscores
1) "member0"
2) "982"
3) "member2"
4) "982"
127.0.0.1:6379>
#A When we add items to a ZSET, the command returns the number of new items.
#B We can fetch all of the items in teh ZSET, which are ordered by the scores.
#C We can also fetch a subsequence of items based on their scores.
#D When we remove items, we again find the number of items that were removed.

<?php

/**
 * constants
 */
const ONE_WEEK_IN_SECONDS = 604800; // 7 * 86400;
const VOTE_SCORE = 432;

function article_vote(Redis $con, $user, $article) {
  //Calculate the cutoff time for voting
  $cutoff = time() - ONE_WEEK_IN_SECONDS;
  // Check to see if the article can still be voted on
  // (we could use the article HASH here, but scores
  // are returned as floats so we don't have to cast
  // it.)
  if ($con->zScore("time:", $article) < $cutoff) {
    return;
  }

  // Get the id portion from the article:id identifier
  $article_id = explode(":", $article)[1];
  // If the user hasn't voted for this article before,
  // increment the article score and voite count (note
  // that our HINCREBY and ZINCREBY calls should be in
  // a Redis transaction, but we don't introduce them
  // until chapter 3 and 4, so ignore that for now)
  if ($con->sAdd("voted:".$article_id, $user)) {
    $con->zIncrBy("score:", VOTE_SCORE, $article);
    $con->hIncrBy($article, "votes" ,1);
  }
}

function post_article(Redis $con, $user, $title, $link) {
  // Generate a new article id
  $article_id = strval($con->incr("article:"));

  $voted = "voted:".$article_id;
  // Start with the posting user having voted for the
  // article, and set the article voting information
  // to automatically expire in a week (we discuss 
  // expiration in chapter 3)
  $con->sAdd($voted, $user);
  $con->setTimeout($voted, ONE_WEEK_IN_SECONDS);

  $now = time();
  $article = "article:".$article_id;
  // Create the article hash
  $con->hMSet($article, [
    "title" => $title,
    "link" => $link,
    "poster" => $user,
    "time" => $now,
    "votes" => 1,
  ]);

  // Add the article to the time and score ordered zsets
  $con->zAdd("score:", $now + VOTE_SCORE, $article);
  $con->zAdd("time:", $now, $article);

  return $article_id;
}

const ARITCLES_PER_PAGE = 25;

function get_articles(Redis $con, $page, $order = "score:") {
  // Set up the start and end indexes for fetching the articles
  $start = ($page - 1) * ARITCLES_PER_PAGE;
  $end = $start + ARITCLES_PER_PAGE -1;

  // Fetch the article ids
  $ids = $con->zRevRange($order, $start, $end);
  $articles = [];
  // Get the article information from the list of article ids
  foreach ($ids as $id) {
    $article_data = $con->hGetAll($id);
    $article_data["id"] = $id;
    $articles[] = $article_data;
  }
  return $articles;
}

function add_remove_groups(Redis $con, $article_id, array $to_add = [], array $to_remove = []) {
  // Construct the article information like we did in post_article
  $article = "article:".$article_id;
  foreach ($to_add as $group) {
    // Add the article to groups that it should be a part of
    $con->sAdd("group:".$group, $article);
  }
  foreach ($to_remove as $group) {
    // Remove the article from groups that it should be removed from
    $con->sAdd("group:".$group, $article);
  }
}

function get_group_articles(Redis $con, $group, $page, $order = "score:") {
  // Create a key for each group and each sort order
  $key = $order . $group;
  // If we haven't sorted these articles recently, we should sort them
  if (!$con->exists($key)) {
    // Actually sort the articles in the group based on score or recency
    $con->zInter($key, ["group:".$group, $order], [1,1], "max");
    // Tell Redis to automaticlly expire the ZSET in 60 seconds
    $con->setTimeout($key, 600);
  }
  return get_articles($con, $page, $key);
}

class TestCh01 {
  public $con = null;

  public function __construct() {
    $this->con = new Redis();
    // $this->con->connect("127.0.0.1", 6379);
    $this->con->connect("192.168.71.210", 7001);
    $this->con->auth("yis@2019._");
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

  public function test_article_functionality() {
    $con = $this->con;

    $article_id = strval(post_article($con, "username", "A title", "http://www.google.com"));
    echo "We posted a new article with id:", $article_id;
    echo "\n";

    $this->assertTrue($article_id);

    echo "Its HASH looks like:";
    $r = $con->hGetAll("article:".$article_id);
    // var_dump($r);
    echo json_encode($r);
    echo "\n";
    $this->assertTrue($r);

    article_vote($con, "other_user", "article:".$article_id);
    echo "We voted for the article, it now has votes:", 
    $v = intval($con->hGet("article:".$article_id, "votes"));
    echo "\n";
    var_dump($v);
    echo "\n";
    $this->assertTrue($v);

    echo "The currently highest-scoring articles are:";
    $articles = get_articles($con, 1);
    // var_dump($articles);
    echo json_encode($articles);
    echo "\n";
    $this->assertTrue(count($articles) >= 1);

    add_remove_groups($con, $article_id, ["new-group"]);
    echo "We added the article to a new group, other articles include:";
    $articles = get_group_articles($con, "new-group", 1);
    // var_dump($articles);
    echo json_encode($articles);
    echo "\n";
    $this->assertTrue(count($articles) >= 1);

    $to_del = array_merge($con->keys("time:*"),
      $con->keys("voted:*"),
      $con->keys("score:*"),
      $con->keys("article:*"),
      $con->keys("group:*")
    );
    if (!empty($to_del)) {
      $con->delete($to_del);
    }
    echo "\n";
    echo "TEST OK!!!\n";
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
    $this->con->close();
    $this->con = null;
  }
}

TestCh01::run();

?>