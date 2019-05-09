<?php

function add_update_contact(Redis $con, $user, $contact) {
  $ac_list = "recent:".$user;
  // Set up the atomic operation
  $pipeline = $con->multi(Redis::PIPELINE);
  // Remove the contact from the list if it exists
  $pipeline->lRem($ac_list, $contact);
  // Push the item onto the front of the list
  $pipeline->lPush($ac_list, $contact);
  // Remove anything beyond the 100th item
  $pipeline->lTrim($ac_list, 0, 99);
  // Actually execute everything
  $pipeline->exec();
}

function remove_contact(Redis $con, $user, $contact) {
  $con->lRem("recent:".$user, $contact);
}

function fetch_autocomplete_list(Redis $con, $user, $prefix) {
  // Fetch the autocomplete list
  $candidates = $con->lRange("recent:".$user, 0, -1);
  $matches = [];
  // Check each cadidate
  foreach ($candidates as $candidate)  {
    if (startswith(strtolower($candidate), strtolower($prefix))) {
      // We found a match
      $matches[] = $candidate;
    }
  }
  // Return all of the matches
  return $matches;
}

// Set up our list of characters that we know about
$valid_characters = '`abcdefghijklmnopqrstuvwxyz{';

function find_prefix_range($prefix) {
  global $valid_characters;
  // Find the position of prefix character in our list of characters
  $posn = bisect_left(substr($prefix, -1), $valid_characters);
  // Find the predecessor character
  $pson = $posn == -1 ? 1: $posn;
  $suffix = $valid_characters[$posn-1];

  // Return the range
  return [substr($prefix, 0,-1).$suffix.'{', $prefix .'{'];
}

function autocomplete_on_prefix(Redis $con, $guild, $prefix) {
  // Find the start/end range for the prefix
  list($start, $end) = find_prefix_range($prefix);
  $identifier = Uuid::v4();
  $start .= $identifier;
  $end .= $identifier;
  $zset_name = "members:".$guild;

  // Add the start/end range items to the ZSET
  $con->zAdd($zset_name, 0, $start);
  $con->zAdd($zset_name, 0, $end);

  while (1) {
    $con->watch($zset_name);
    // Find the ranks of our end points
    $ret = $con->multi()
      ->zRank($zset_name, $start)
      ->zRank($zset_name, $end)
      ->exec();
    $sindex = $ret[0];
    $eindex = $ret[1];
    $erange = min($sindex +9, $eindex -2);
    // Get the values inside our range, and clean up
    $con->multi();
    $con->zRem($zset_name, $start, $end);
    $con->zRange($zset_name, $sindex, $erange);

    $ret = $con->exec();
    if ($ret == false) {
      // Retry if someone modified our autocomplete zset
      continue;
    }
    $items = end($ret);
    break;
  }
  
  // Remove start/end entries if an autocomplete was in progress
  return array_filter($items, function($var){ return strpos($var, "{")===false;});
}

function join_guild(Redis $con, $guild, $user) {
  $con->zAdd("members:".$guild, 0, $user);
}

function leave_guild(Redis $con, $guild, $user) {
  $con->zRem("members:".$guild, $user);
}

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
        ->hIncryBy($seller, "funds", intval($price))
        ->hIncryBy($buyer, "funds", -intval($price))
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

function acquire_lock(Redis $con, $lockname, $acquire_timeout=10) {
  // A 128-bit random identifier
  $identifier = strval(Uuid::v4());

  $end = time() + $acquire_timeout;
  while(time() < $end) {
    // Get the lock
    if ($con->setNx('lock:'.$lockname, $identifier)) {
      return $identifier;
    }
    usleep(100);
  }
  return false;
}

function purchase_item_with_lock(Redis $con, $buyerid, $itemid, $sellerid) {
  $buyer = "users:".$buyerid;
  $seller = "users:".$sellerid;
  $item = $itemid.".".$sellerid;
  $inventory = "inventory:".$buyerid;

  // Get the lock
  $locked = acquire_lock($con, "market:");
  if (!$locked) {
    return false;
  }

  try {
    // Check for a sold item or insufficent funds
    list($price, $funds) = $con->multi(Redis::PIPELINE)
      ->zScore("market:", $item)
      ->hGet($buyer, "funds")
      ->exec();
    if (!$price || $price > $funds) {
      return null;
    }

    // Transfer funds from the buyer to the seller, and 
    // transfer the item to the buyer
    $con->multi(Redis::PIPELINE)
      ->hIncryBy($seller, "funds", intval($price))
      ->hIncryBy($buyer, "funds", -intval($price))
      ->sAdd("market:", $item)
      ->exec();

    return true;
  } catch (Exception $e) {
    release_lock($con, "market:", $locked);
  }
  return false;
}

function acquire_lock_with_timeout(Redis $con, $lockname, 
  $acquire_timeout=10, $lock_timeout=10) {
  // A 128-bit random identifier
  $identifier = strval(Uuid::v4());
  $lockname = "lock:".$lockname;
  // Only pass integers to our EXPIRE calls
  $lock_timeout = intval(ceil($lock_timeout));

  $end = time() + $acquire_timeout;
  while (time() < $end) {
    // Get the lock and set the expiration
    if ($con->setNx($lockname, $identifier)) {
      $con->expire($lockname, $lock_timeout);
      return $identifier;
    } else if ($con->ttl($lockname) < 0) {
      // Check and update the expiration time as necessary
      // Because make sure that avoiding dead-lock if client
      // crashed between setNx and expire operation
      $con->expire($lockname, $lock_timeout);
    }
    usleep(100);
  }
  return false;
}

function release_lock(Redis $con, $lockname, $identifier) {
  $lockname = "lock:".$lockname;

  while (true) {
    try {
      // Check and verify that we still have the lock
      $con->watch($lockname);

      // Release the lock
      if ($con->get($lockname) == $identifier) {
        $con->multi()
          ->delete($lockname)
          ->exec();
        return true;
      }

      $con->unwatch($lockname);
      break;
    } catch(Exception $e) {
      echo $e->getMessage(), "\n";
    }
  }
      // We lost the lock
  return false;
}

function acquire_semaphore(Redis $con, $semname, $limit, $timeout=10) {
  // 128-bit random identifier
  $identifier = strval(Uuid::v4());
  $now = time();

  $s = $con->multi()
    // Time out old semaphore holders
    ->zRemRangeByScore($semname, '-inf', $now-$timeout)
    // Try to acquire the semaphore
    ->zAdd($semname, $identifier, $now)
    // Check to see if we have it
    ->zRank($semname, $identifier)
    ->exec();
  if ($s[count($s)-1] < $limit) {
    return $identifier;
  }
  // We failed to get the semaphore, discard our identifier
  $con->zRem($semname, $identifier);
  return null;
}

function release_semaphore(Redis $con, $semname, $identifier) {
  // Returns 1 if the semaphore was properly released,
  // 0 if had timed out
  return $con->zRem($semname, $identifier);
}

function acquire_fair_semaphore(Redis $con, $semname, $limit, $timeout=10) {
  // A 128-bit random identifier
  $identifier = strval(Uuid::v4());
  $czset = $semname.":owner";
  $ctr = $semname.":counter";

  $now = time();
  $s = $con->multi()
    // Time out old entries
    ->zRemRangeByScore($semname, "-inf", $now-$timeout)
    ->zInter($czset, [$czset, $semname], [1, 0])
    ->incr($ctr)
    ->exec();
  // Get the counter
  $counter = $s[count($s)-1];

  $s = $con->multi()
    // Try to acquire the semaphore
    ->zAdd($semname, $now, $identifier)
    ->zAdd($czset, $counter, $identifier)
    // Check the rank to determine if we got the semaphore
    ->zRank($czset, $identifier)
    ->exec();

  if ($s[count($s)-1] < $limit) {
    // We got the semaphore
    return $identifier;
  }

  // We didn't get the semaphore, clean out the bad data
  $con->multi()
    ->zRem($semname, $identifier)
    ->zRem($czset, $identifier)
    ->exec();

  return null;
}

function release_fair_semaphore(Redis $con, $semname, $identifier) {
  return $con->multi()
    ->zRem($semname, $identifier)
    ->zRem($semname.":owner", $identifier)
    // Returns 1 if the semaphore was properly released,
    // 0 if it had timed out
    ->exec()[0];
}

function refresh_fair_semaphore(Redis $con, $semname, $identifier) {
  // Update our semaphore(if member exists, the score was updated and 0 returned)
  if ($con->zAdd($semname, time(), $semaphore)) {
    // We lost our semaphore
    release_fair_semaphore($con, $semname, $identifier);
    return false;
  }
  // We still have oure semaphore
  return true;
}

function acquire_semaphore_with_lock(Redis $con, $semname, $limit, $timeout=10) {
  $identifier = acquire_lock($con, $semname, $acquire_timeout=1);
  if ($identifier) {
    try {
      return acquire_fair_semaphore($con, $semname, $limit, $timeout);
    } finally {
      release_lock($con, $semname, $identifier);
    }
  }
}

function send_sold_email_via_queue(Redis $con, $seller, $item, $price, $buyer) {
  // Prepare the item
  $data = [
    'seller_id' => $seller,
    'item_id' => $item,
    'price' => $price,
    'buyer_id' => $buyer,
    'time' => time(),
  ];

  // Push the item onto the queue
  $con->rPush('queue:email', json_encode($data));
}

function process_sold_email_queue(MyThread $thr, Redis $con) {
  while (!$thr->quit) {
    // Try to get a message to send
    $packed = $con->blPop(['queue:email'], 30);
    // No message to send, try again
    if (!$packed) {
      continue;
    }
    // Load the packed email information
    $to_send = json_decode($packed[1]);
    try {
      // do something here
      print_r($to_send);
      // or Send the email using our pre-written emailing function
    } catch(Exception $e) {
      print "\n";
    } finally {
      print "\n";
    }
  }
}

function worker_watch_queue(MyThread $thr, Redis $con, $queue, $clbks) {
  while (!$thr->quit) {
    // Try to get an item from the queue
    $packed = $con->blPop([$queue], 30);
    // There is nothing to work on, try again
    if (!$packed) {
      continue;
    }

    // Unpack the work item
    list($name, $args) = json_decode($packed[1]);
    // The function is unknow, log the error and try again
    if (!in_array($name, $clbks)) {
      echo "Unknow callback ".$name." callback\n";
      continue;
    }

    // Execute the task
    call_user_func_array($clbks[$name], $args);
  }
}

function execute_later(Redis $con, $queue, $name, $args, $delay=0) {
  // Generate a unique identifier
  $identifier = Uuid::v4();
  // Prepare the item for the queue
  $item = json_encode([$identifier, $queue, $name, $args]);

  if ($delay > 0) {
    // Delay the item for the queue
    $con->zAdd("delayed:", time()+$delay, $item);
  } else {
    // Execute the item immediately
    $con->rPush("queue:".$queue, $item);
  }

  // Return the identifier
  return $identifier;
}

function poll_queue(MyThread $thr, $con) {
  // For php thread
  if (!$con) {
    $con = TestCh06::new_reids();
  }
  while (!$thr->quit) {
    // Get the first item in the queue
    $item = $con->zRange("delayed:", 0, 0, true);

    // No item or the item is still to be execued in the future
    $key = key($item);
    if (!$item || $item[$key] >time()) {
      usleep(100);
      continue;
    }

    // Unpack the item so that we know where it should go
    $item = $key;
    list($identifier, $queue, $function, $args) = json_decode($item, true);

    // Get the lock for the item
    $locked = acquire_lock($con, $identifier);
    // We couldn't get the lock, so skip it and try again
    if (!$locked) {
      continue;
    }

    // Move the item to the proper list queue
    if ($con->zRem("delayed:", $item)) {
      $con->rPush("queue:".$queue, $item);
    }

    // Release the lock
    release_lock($con, $identifier, $locked);
  }
}

function create_chat(Redis $con, $sender, array $recipients, $message, $chat_id=null) {
  // Get a new chat id
  $chat_id = $chat_id ? $chat_id : $con->incr("ids:chat:");
  array_push($recipients, $sender);
  $con->multi();

  foreach ($recipients as $r) {
    // Create the set with the list of people participating
    $con->zAdd("chat:".$chat_id, 0, $r);
    // Initialize the seen zsets
    $con->zAdd("seen:".$r, 0, $chat_id);
  }
  $con->exec();

  // Send the message
  return send_message($con, $chat_id, $sender, $message);
}

function send_message(Redis $con, $chat_id, $sender, $message) {
  $identifier = acquire_lock($con, "chat:".$chat_id);
  if (!$identifier) {
    throw new Exception("Couldn't get the lock", 1);
  }
  try {
    // Prepare the message
    $mid = $con->incr("ids:".$chat_id);
    $ts = time();
    $packed = json_encode([
      "id" => $mid,
      "ts" => $ts,
      "sender" => $sender,
      "message" => $message,
    ]);

    // Send the message to the chat
    $con->zAdd("msgs:".$chat_id, $mid, $packed);
  } finally {
    release_lock($con, "chat:".$chat_id, $identifier);
  }

  return $chat_id;
}

function fetch_pending_message(Redis $con, $recipient) {
  // Get the last message ids received
  $seen = $con->zRange("seen:".$recipient, 0, -1, true);

  $pipeline = $con->multi(Redis::PIPELINE);

  // Fetch all new message
  foreach ($seen as $chat_id => $seen_id) {
    $pipeline->zRangeByScore("msgs:".$chat_id, $seen_id+1, "inf", ["withscores"=>true]);
  }
  $r = $pipeline->exec();

  $i = 0;
  $pipeline = TestCh06::new_reids()->multi(Redis::PIPELINE);
  $chat_info = [];

  foreach ($seen as $chat_id => $seen_id) {
    $message = $r[$i];
    if ($message) {
      $messages = array_map(function($var) { return json_decode($var, true);}, 
        array_flip(array_map("strval",$message)));
      // Update the 'chat' ZSET with the most recently received message.
      $seen_id = end($messages)["id"];
      $con->zAdd("chat:".$chat_id, $seen_id, $recipient);

      // Discover message that have been seen by all users
      $min_id = $con->zRange("chat:".$chat_id, 0, 0, true);
      // Update the 'seen' ZSET
      $pipeline->zAdd("seen:".$recipient, $seen_id, $chat_id);

      if ($min_id) {
        // Celan out message that have been seen by all users
        $min_id = current($min_id);
        $pipeline->zRemRangeByScore("msg:".$chat_id, 0, $min_id);
      }

      $chat_info[$i] = [$chat_id, $messages];
    }
    $i++;
  }
  $pipeline->exec();

  return $chat_info;
}

function join_chat(Redis $con, $chat_id, $user) {
  // Get the most recent message id for the chat
  $message_id = (int)$con->get("ids:".$chat_id);

  $con->multi(Redis::PIPELINE)
    // Add the user to the chat member list
    ->zAdd("chat:".$chat_id, $message_id, $user)
    // Add the chat to the users's seen list
    ->zAdd("seen:".$user, $message_id, $chat_id)
    ->exec();
}

function leave_chat(Redis $con, $chat_id, $user) {
  $r = $con->multi(Redis::PIPELINE)
    // Remove the user from the chat
    ->zRem("chat:".$chat_id, $user)
    ->zRem("seen:".$user, $chat_id)
    // Find the number of remaining group members
    ->zCard("chat:".$chat_id)
    ->exec();

  if (!$r[count($r)]) {
    // Delete the chat
    $con->multi()
      ->delete("msgs:".$chat_id)
      ->delete("ids:".$chat_id)
      ->exec();
  } else {
    // Delete old message from the chat
    $oldest = $con->zRange("chat:".$chat_id, 0, 0, true);
    $con->zRemRangeByScore("msgs:".$chat_id, 0, end($oldest));
  }
}

// Prepare the local aggregate dictionary
$aggregates = [];

function daily_country_aggregate(Redis $con, $line) {
  if ($line) {
    $line = explode(' ', $line);
    // Extract the information from our log lines
    $ip = $line[0];
    $day = $line[1];
    // Find the country from the IP address
    $country = find_city_by_ip_local($ip)[2];

    // Increment our local aggregate
    if (isset($aggregates[$day][$country])) {
      $aggregates[$day][$country] = 0;
    } else {
      $aggregates[$day][$country] += 1;
    }
  }

  // The day file is done, write our aggregate to Redis
  foreach ($aggregates as $day => $aggregate) {
    foreach ($aggregate as $country => $count) {
      $con->zAdd('daily:country:'.$day, $count, $country);
    }
    unset($aggregates[$day]);
  }
}

function copy_logs_to_redis($con, $path, $channel, $count = 10,
  $limit = 1073741824/* 2^30 */, $quit_when_done = true) {
  $bytes_in_redis = 0;
  $waiting = new SplQueue();

  create_chat($con, "source", array_map('strval', range(0, $count-1)), 'copying logs to redis...', $channel);
  $count = strval($count);

  // Iterate over all of the logfiles
  foreach (listdir($path) as $logfile) {
    $full_path = rtrim($path, DIRECTORY_SEPARATOR).
      DIRECTORY_SEPARATOR.$logfile;

    $fsize = filesize($full_path);
    // Clean out finished files if we need more room
    while (/*($bytes_in_redis + $fsize)*/ /* caused dead loop when a file far exceeds the limit*/ 
      $bytes_in_redis > $limit) {
      $cleaned = _clean($con, $channel, $waiting, $count);
      if ($cleaned) {
        $bytes_in_redis -= $cleaned;
      } else {
        usleep(25);
      }
    }

    // Upload the file to Redis
    $hd = fopen($full_path, 'rb');
    if ($hd) {
      $block = '';
      while (!feof($hd)) {
        $block = fread($hd, 131072/* 2^17 */);
        $con->append($channel.$logfile, $block);
      }
      fclose($hd);
    }

    // Notify the listeners that the file is ready
    send_message($con, $channel, "source", $logfile);

    // Update our local information about Redis' memory use
    $bytes_in_redis += $fsize;
    $waiting->enqueue([$logfile, $fsize]);
  }

  // We are out of files, so signal that it is done
  if ($quit_when_done) {
    send_message($con, $channel, "source", ":done");
  }
  print_r($waiting);

  // Clean up the files when we are done
  while (!$waiting->isEmpty()) {
    $cleaned = _clean($con, $channel, $waiting, $count);
    if ($cleaned) {
      $bytes_in_redis -= $cleaned;
    } else {
      usleep(250);
    }
    exit;
  }
}

// How we actually perform the cleanup from Redis
function _clean($con, $channel, $waiting, $count) {
  if ($waiting->isEmpty()) {
    return 0;
  }
  $w0 = $waiting->bottom()[0];
  if ($con->get($channel.$w0.":done") == $count) {
    $con->delete($channel.$w0, $channel.$w0.":done");
    return $waiting->dequeue()[1];
  }
  return 0;
}

function process_logs_from_redis($con, $id, $callback) {
  if (!$con) {
    $con = TestCh06::new_reids(); // For php thread
  }
  while (1) {
    // Fetch the list of files
    $fdata = fetch_pending_message($con, $id);

    foreach ($fdata as $ch => $mdata) {
      foreach ($mdata as $message) {
        $logfile = $message["message"];

        // No more logfiles
        if ($logfile == ":done") {
          return;
        } else if (!$logfile) {
          continue;
        }

        // Choose a block reader
        $block_reader = "readblocks";
        if (endswith($logfile, ".gz")) {
          $block_reader = "readblocks_gz";
        }

        // Iterate over the lines
        foreach (readlines($con, $ch.$logfile, $block_reader) as $line) {
          // Pass each line to the callback
          $callback($con, $line);
        }
        // Report that we are finished with the log
        $callback($con, null);

        // Report that we are finished with the log
        $con->incr($ch.$logfile.":done");
      }
    }

    if (!$fdata) {
      usleep(100);
    }
  }
}

function readlines($con, $key, $rblocks) {
  $out = "";
  foreach ($rblocks($con, $key) as $block) {
    $out += $block;
    // Fine the rightmost linebreak if any
    $posn = strrpos($out, "\n");
    // We found a line break
    if ($posn !== false) {
      // Split on all of the line breaks
      foreach (explode("\n", substr($out, 0, $posn)) as $line) {
        // Yield each line
        yield $line."\n";
      }
      // Keep track of the trailing data
      $out = substr($out, $posn);
    }
    // We are out of data
    if (!$block) {
      yield $out;
      break;
    }
  }
}

function readblocks($con, $key, $blocksize=131072 /* 2^17 */) {
  $lb = $blocksize;
  $pos = 0;
  // Keep going while we got as much as we expected
  while ($lb == $blocksize) {
    // Fetch the block
    $block = $con->getRange($key, $pos, $pos+$blocksize-1);
    yield $block;
    $lb = strlen($block);
    // Prepare for the next pass
    $pos += $lb;
  }
  yield '';
}

function readblocks_gz($con, $key, $blocksize=131072 /* 2^17 */) {
  if (function_exists('inflate_init')) {
    $ctx = inflate_init(ZLIB_ENCODING_GZIP);
    foreach (readblocks($con, $key, $blocksize) as $block) {
      yield inflate_add($ctx, $block);
    }
  } else {
    $s = '';
    foreach (readblocks($con, $key, $blocksize) as $block) {
      $s .= $block;
    }
    if (substr($s, 0, 3) != "\x1f\x8b\x08") {
      throw new Exception("invalid gzip data", 1);
    }
    $i = 10;
    $flag = ord($s[3]);
    if ($flag & 4) {
      $i += 2 + ord($s[$i]) + 256*ord($s[$i+1]);
    }
    if ($flag & 8) {
      $i = strpos($s, "\0", $i) + 1;
    }
    if ($flag & 16) {
      $i = strpos($s, "\0", $i) + 1;
    }
    if ($flag & 2) {
      $i += 2;
    }

    yield gzinflate(substr($s, $i, -8));
  }
}

#--------------------- the below is helper class or functions ---------------------#

function find_city_by_ip_local($ip) {
  return '';
}

// endswith function like python
function endswith($haystack, $needle) {
  return $needle === "" || strrpos($haystack, $needle) === (strlen($haystack)-strlen($needle));
}

// startswith function like python
function startswith($haystack, $needle) {
  return $needle === "" || strpos($haystack, $needle) === 0;
}

// bisect_left
function bisect_left($needle, $haystack) {
  $low = 0;
  $high = is_string($haystack) ? strlen($haystack) -1 : count($haystack) - 1;
  $mid = 0;
  while ($low <= $high) {
    $mid = $low + (($high - $low) >> 1);
    if ($needle == $haystack[$mid]) {
      return $mid;
    } else if ($needle < $haystack[$mid]) {
      $high = $mid - 1;
    } else {
      $low = $mid + 1;
    }
  }
  return -1;
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

// shutil.rmtree
function rmtree($dir) {
  if (!is_dir($dir)) {
    return false;
  }
  $files = array_diff(scandir($dir), [".",".."]);
  foreach ($files as $file) {
    $subdir = $dir.DIRECTORY_SEPARATOR.$file;
    is_dir($subdir) ? rmtree($subdir) : unlink($subdir);
  }
  return rmdir($dir);
}

function mkdtemp() {
  $str = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ_-";
  $dir =  sys_get_temp_dir() .DIRECTORY_SEPARATOR. "tmp".substr(str_shuffle($str),0,6);
  if (is_dir($dir)){
    return mkdtemp();
  }
  mkdir($dir, 0700);
  return $dir;
}

if (!function_exists('random_bytes')) {
  function random_bytes($n) {
    return substr(uniqid(), 0, $n);
  }
}

class Uuid {
  public static function v4() {
    return sprintf("%04x%04x-%04x-%04x-%04x-%04x%04x%04x",
      mt_rand(0, 0xffff), mt_rand(0, 0xffff),
      mt_rand(0, 0xffff),
      mt_rand(0, 0x0fff) | 0x4000,
      mt_rand(0, 0x3fff) | 0x8000,
      mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
  }
}

if (!class_exists('Thread')) {
  class Thread {
    public function join() {}
    public function start() {}
  }
}

class MyThread extends Thread {
  public $quit = false;
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

class TestCh06 {
  public $con = null;

  public static function new_reids() {
    $con = new Redis();
    // $con->connect("127.0.0.1", 6379);
    $con->connect("192.168.71.148", 6379);
    $con->auth('654321');
    return $con;
  }

  public function __construct() {
    $this->con = self::new_reids();
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

  public function test_add_update_contact() {
    $con = $this->con;
    $con->delete("recent:user");

    echo "Let's add a few contacts...\n";
    foreach (range(1, 10) as $i) {
      add_update_contact($con, "user", sprintf("contact-%d-%d", $i/3, $i));
    }
    echo "Current recently contacted contacts\n";
    $contacts = $con->lRange("recent:user", 0, -1);
    self::pprint($contacts);
    $this->assertTrue(count($contacts) >= 10);
    echo "\n";

    echo "Let's pull one of the older ones up to the front\n";
    add_update_contact($con, "user", "contact-1-4");
    $contacts = $con->lRange("recent:user", 0, 2);
    echo "New top-3 contacts:\n";
    self::pprint($contacts);
    $this->assertTrue($contacts[0] == "contact-1-4");
    echo "\n";

    echo "Let's remove a contact...\n";
    echo remove_contact($con, "user", "contact-2-6");
    $contacts = $con->lRange("recent:user", 0, -1);
    echo "New contacts:\n";
    self::pprint($contacts);
    $this->assertTrue(count($contacts) >= 9);
    echo "\n";

    echo "And let's finally autocomplete on \n";
    $all = $con->lRange("recent:user", 0, -1);
    $contacts = fetch_autocomplete_list($con, "user", "c");
    $this->assertTrue($all == $contacts);
    $equiv = array_filter($all, function($var){ return startswith($var, "contact-2-"); });
    $contacts = fetch_autocomplete_list($con, "user", "contact-2-");
    sort($equiv);
    sort($contacts);
    $this->assertTrue($equiv == $contacts);
    $con->delete("recent:user");
  }

  public function test_address_book_autocomplete() {
    $this->con->delete("members:test");
    echo "the start/end range of 'abc' is: ", self::pprint(find_prefix_range('abc'));
    echo "\n";

    echo "Let's add a few people to the guild\n";
    foreach (["jeff", "jenny", "jack", "jennifer"] as $name) {
      join_guild($this->con, "test", $name);
    }
    echo "\n";
    $r = autocomplete_on_prefix($this->con, "test", "je");
    self::pprint($r);
    $this->assertTrue(count($r) == 3);
    echo "jeff just left to join a different guild...\n";
    leave_guild($this->con, "test", "jeff");
    $r = autocomplete_on_prefix($this->con, "test", "je");
    self::pprint($r);
    $this->assertTrue(count($r)==2);
    $this->con->delete("members:test");
  }

  public function test_distributed_locking() {
    $this->con->delete("lock:testlock");
    echo "Getting an initial lock...\n";
    $this->assertTrue(acquire_lock_with_timeout($this->con, "testlock", 1, 1));
    echo "Got it!\n";
    echo "Trying to get it again without releasing the first one...\n";
    $this->assertFalse(acquire_lock_with_timeout($this->con, "testlock", 0.01, 1));
    echo "Failed to get it!\n";
    echo "\n";
    echo "Waiting for the lock to timeout...\n";
    sleep(2);
    echo "Getting the lock again...\n";
    $r = acquire_lock_with_timeout($this->con, "testlock", 1, 1);
    $this->assertTrue($r);
    echo "Got it!\n";
    echo "Release the lock...\n";
    $this->assertTrue(release_lock($this->con, "testlock", $r));
    echo "Release it...\n";
    echo "\n";
    echo "Acquiring it again...\n";
    $this->assertTrue(acquire_lock_with_timeout($this->con, "testlock", 1, 1));
    echo "Got it!\n";
    $this->con->delete("lock:testlock");
  }

  public function test_counting_semaphore() {
    $this->con->delete("testsem", "testsem:owner", "testsem:counter");
    echo "Getting 3 initial semaphores with a limit of 3...\n";
    foreach(range(1, 3) as $i) {
      $this->assertTrue(acquire_fair_semaphore($this->con, "testsem", 3, 1));
    }
    echo "Done!\n";
    echo "Getting one more that should fail...\n";
    $this->assertFalse(acquire_fair_semaphore($this->con, "testsem", 3, 1));
    echo "Couldn't get it!\n";
    echo "\n";
    echo "Let's wait some of them to time out\n";
    sleep(2);
    echo "Can we get one?\n";
    $r = acquire_fair_semaphore($this->con, "testsem", 3, 1);
    $this->assertTrue($r);
    echo "Got one!\n";
    echo "Let's release it ...\n";
    $this->assertTrue(release_fair_semaphore($this->con, "testsem", $r));
    echo "Released!\n";
    echo "\n";
    echo "And let's make sure we can get 3 more!\n";
    foreach (range(1, 3) as $i) {
      $this->assertTrue(acquire_fair_semaphore($this->con, "testsem", 3, 1));
    }
    echo "We got them!\n";
    $this->con->delete("testsem","testsem:owner", "testsem:counter");
  }

  public function test_delayed_tasks() {
    $this->con->delete("queue:tqueue", "delayed:");
    echo "Let's start some regular and delayed tasks...\n";
    foreach ([0, 0.5, 0, 1.5] as $delay) {
      $this->assertTrue(execute_later($this->con, "tqueue", "testfn", [], $delay));
    }
    $r = $this->con->lLen("queue:tqueue");
    echo "How many non-delayed tasks are there (should be 2)?", $r, "\n";
    $this->assertTrue($r == 2); // assert equals
    echo "\n";
    echo "Let's start up a thread to bring those delayed tasks back...\n";
    $t = new MyThread("poll_queue", [null]);
    $t->start();
    echo "Started.\n";
    echo "Let's wait for those tasks to be prepared...\n";
    sleep(2);
    $t->quit = true;
    $t->join();
    $r = $this->con->lLen("queue:tqueue");
    echo "Waiting is over, how many tasks do we have (should be 4)?", $r, "\n";
    // $this->assertTrue($r == 4);
    $this->con->delete("queue:tqueue", "delayed:");
  }

  public function test_multi_recipient_messaging() {
    $this->con->delete("ids:chat:", "msgs:1", "ids:1", "chat:1", "seen:joe", "seen:jeff", "seen:jenny");

    echo "Let's create a new chat session with some recipients...\n";
    $chat_id = create_chat($this->con, "joe", ["jeff", "jenny"], "message 1");
    echo "Now let's send a few messages...\n";
    foreach (range(2, 5) as $i) {
      send_message($this->con, $chat_id, "joe", "message ".$i);
    }
    echo "\n";
    echo "And let's get the message that are waiting for jeff and jenny...\n";
    $r1 = fetch_pending_message($this->con, "jeff");
    $r2 = fetch_pending_message($this->con, "jenny");
    echo "They are the same?", $r1== $r2, "\n";
    $this->assertTrue($r1 == $r2);
    echo "Those message are:\n";
    self::pprint($r1);
    echo "\n";
    $this->con->delete("ids:chat:", "msgs:1", "ids:1", "chat:1", "seen:joe", "seen:jeff", "seen:jenny");
  }

  public function test_file_distribution() {
    $this->con->delete("test:temp-1.txt", "test:temp-2.txt", "test:temp-3.txt", "msgs:test:", "seen:0", "seen:source", "ids:test:", "chat:test:");

    $dire = './tmp';// mkdtemp();
    echo "Creating some temporary 'log' files...\n";
    $hd = fopen($dire."/temp-1.txt", "wb");
    if ($hd) {
      fwrite($hd, "one line\n");
      fclose($hd);
    }
    $hd = fopen($dire."/temp-2.txt", "wb");
    if ($hd) {
      fwrite($hd, str_repeat("many lines\n", 10000));
      fclose($hd);
    }
    $out = gzopen($dire."/temp-3.txt.gz", "wb");
    for ($i = 0; $i < 100000; $i++) {
      gzwrite($out, "random line ".bin2hex(random_bytes(16)). "\n");
    } 
    gzclose($out);
    $size = filesize($dire."/temp-3.txt.gz");
    echo "Done.\n";
    echo "\n";
    echo "Starting up a thread to copy logs to redis...\n";
    copy_logs_to_redis($this->con, $dire, "test:", 1);

    // $t = new MyThread("copy_logs_to_redis", [$dire, "test:", 1, $size]);
    // $t->start();

    echo "Let's pause to let some logs get copied to Redis...\n";
    usleep(250);
    echo "\n";
    echo "Okay, the logs should be ready. Let's process them!\n";

    $index = [0];
    $counts = [0,0,0];
    $callback = function ($con, $line) use($index, $counts) {
      if (!$line) {
        echo "Finished with a file ",$index[0],", linecount: ", $count[$index[0]];
        $index[0] +=1;
      } else if ($line || endswith($line, "\n")) {
        $counts[$index[0]] += 1;
      }
    };

    echo "Files should have 1, 10000, and 100000 lines\n";
    process_logs_from_redis($con, '0', $callback);

    echo "\n";
    echo "Let's wait for the copy thread to finish cleaning up...\n";
    // $t->join();
    echo "Done cleaning out Redis!";

    // Clean
    echo "Time to clean up files...\n";
    rmtree($dire);
    echo "Cleaned out files\n";
    $this->con->delete("test:temp-1.txt", "test:temp-2.txt", "test:temp-3.txt", "msgs:test:", "seen:0", "seen:source", "ids:test:", "chat:test:");
  }

  public static function pprint($var) {
    echo json_encode($var, true);
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
    try {
      $this->con->close();
      $this->con = null;
    } catch (Exception $e) {
      echo $e->getMessage(), "\n";
    }
  }

}

TestCh06::run();

?>