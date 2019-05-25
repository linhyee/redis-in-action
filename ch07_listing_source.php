<?php 

$average_per_1k = [];

// We pre-declare our known stop words, these were fetched from http://www.textfixer.com/resources/
$stop_words = <<<EOF
able about across after all almost also am among an and any are as at be because been but by can cannot could dear did do does either else ever every for from get got had has have he her hers him his how however if in into is it its just least let like likely may me might most must my neither no nor not of off often on only or other our own rather said say says she should since so some than that the their them then there these they this tis to too twas us wants was we were what when where which while who whom why will with would yet you your
EOF;
$stop_words = explode(" ", $stop_words);

// A regular expression that extracts words as we defined them
$words_re = "/[a-z']{2,}/";

function tokenize($content) {
  global $words_re, $stop_words;
  // Our set of words that we have found in the document content
  $words = [];
  preg_match_all($words_re, strtolower($content), $out);
  // Iterate over all of the words in the content
  foreach ($out[0] as $match) {
    // Strip any leading or trailing single-quote characters
    $word = trim($match, "'");
    // Keep any words that are still at least 2 characters long
    if (strlen($word) >= 2) {
      if (!in_array($word, $words)) {
        $words[] = $word;
      }
    }
  }
  // Return the set of words that remain that are also not stop words
  return array_values(array_diff($words, $stop_words));
}

function index_document($con, $docid, $content) {
  // Get the tokenized words for the content
  $words = tokenize($content);
  $con->multi();
  // Add the documents to the appropriate inverted index entries
  foreach ($words as $word) {
    $con->sAdd("idx:".$word, $docid);
  }
  // Return the number of unique non-stop words that were added for the document
  return count($con->exec());
}

function _set_common($con, $method, $names, $ttl=30, $execute=true) {
  // Create a new temporary identifier
  $id = uuid4();
  // Set up a transactional pipeline so that we have 
  // consistent results for each individual call
  if ($execute) {
    $con->multi();
  }
  // Add the 'idx:' prefix to our terms
  $names = array_map(function($var) {return "idx:".$var;}, $names);
  // Set up the call for one of the operations
  // $con->$method("idx:".$id, ...$names);
  array_unshift($names, 'idx:'.$id);
  call_user_func_array([$con, $method], $names);
  // Instruct Redis to expire the SET in the future
  $con->expire("idx:".$id, $ttl);
  if ($execute) {
    // Actually execute the operation
    $con->exec();
  }
  // Return the id for the caller to process the results
  return $id;
}

// Helper function to perform SET intersections
function intersect($con, $items, $ttl =30, $_execute=true) {
  return _set_common($con, 'sInterStore', $items, $ttl, $_execute);
}

// Helper function to perform SET unions
function union($con, $items, $ttl=30, $_execute=true) {
  return _set_common($con, 'sUnionStore', $items, $ttl, $_execute);
}

// Helper function to perform SET differences
function difference($con, $items, $ttl=30, $_execute=true) {
  return _set_common($con, 'sDiffStore', $items, $ttl, $_execute);
}

// Our regular expression for finding wanted, unwanted, and synonym words
$query_re = "/[+-]?[a-z']{2,}/";

function parse($query) {
  global $query_re, $stop_words;
  // unwanted: A unique set of unwanted words
  // all: Our final result of words that we are looking to intersect
  // current: The current unique set of words to consider as synonyms
  $unwanted = $all = $current = [];
  preg_match_all($query_re, strtolower($query), $matches);
  // Iterate over all words in the search query
  foreach ($matches[0] as $word) {
    // Discover +/- prefixes, if any
    $prefix = substr($word, 0, 1);
    if (in_array($prefix, ['+', '-'])) {
      $word = substr($word, 1);
    } else {
      $prefix = null;
    }

    // Strip any leading or trailing single quotes, 
    // and skip anything that is a stop word
    $word = trim($word, "'");
    if (strlen($word) <2 || in_array($word, $stop_words)) {
      continue;
    }

    // If the word is unwanted, add it to the unwanted set
    if ($prefix == '-') {
      if (!in_array($word, $unwanted)) {
        $unwanted[] = $word; // simulate the set operation
      }
      continue;
    }

    // Set up a new synonym set if we have no synonym prefix and we already have words
    if ($current && !$prefix) {
      $all[] = $current;
      $current = [];
    }
    // Add the current word to the current set
    if (!in_array($word, $current)) {
      $current[] = $word;
    }
  }
  // Add any remaining words to the final intersection
  if ($current) {
    $all[] = $current;
  }
  return [$all, $unwanted];
}

function parse_and_search($con, $query, $ttl=30) {
  // Parse the query
  list($all, $unwanted) = parse($query);
  // If there are no words in the query that are not stop words, 
  // we don't have a result
  if (!$all) {
    return null;
  }

  $to_intersect=[];
  // Iterate over each list of synonyms
  foreach ($all as $syn) {
    // If the synonym list is more than one word long, 
    // then perform the union operation
    if (count($syn) > 1) {
      $to_intersect[] = union($con, $syn, $ttl);
    } else {
      // Otherwise use the individual word directly
      $to_intersect[] = $syn[0];
    }
  }
  // If we have more than one word/result to intersect, intersect them
  if (count($to_intersect) > 1) {
    $intersect_result = intersect($con, $to_intersect, $ttl);
  } else {
    // Otherwise use the individual word/result directly
    $intersect_result = $to_intersect[0];
  }
  // If we have any unwanted words, remove them from our earlier result and return it
  if ($unwanted) {
    array_unshift($unwanted, $intersect_result);
    return difference($con, $unwanted, $ttl);
  }
  // Otherwise return the intersection result
  return $intersect_result;
}

// We will optionally take an previous result id, a way to sort the results, 
// and options for paginating over the results
function search_and_sort($con, $query, $id=null, $ttl=300, $sort='-updated',
  $start=0, $num=20) {
  // Determine which attribute to sort by, and whether to sort ascending or descending
  $desc = startswith($sort, '-');
  $sort = ltrim($sort, '-');
  $by = 'kb:doc:*->'.$sort;
  // We need to tell Redis whether we are sorting by a number or alphabetically
  $alpha = in_array($sort, ['updated', 'id', 'created']);

  // If there was a previous result, try to update its expiration time if it still exists
  if ($id && !$con->expire($id, $ttl)) {
    $id = null;
  }
  // Perform the search if we didn't have a past search id, or if our results expired
  if (!$id) {
    $id = parse_and_search($con, $query, $ttl);
  }
  $results = $con->multi()
    // Fetch the total number of results
    ->sCard('idx:'.$id)
    // Sort the result list by the proper column and fetch only those results we want
    ->sort('idx:'.$id, ['alpha'=>$alpha, 'by'=>$by, 'sort'=>$desc?'desc':'asc', 'limit'=>[$start, $num]])
    ->exec();

  // Return the number of items in the results, the results we wanted, 
  // and the id of the results so that we can fetch them again later
  return [$results[0], $results[1], $id];
}

// Like before, we'll optionally take a previous result id for pagination if the result is still available
function search_and_zsort($con, $query, $id=null, $ttl=300, $update=1, $vote=0,
  $start=0, $num=20, $desc=true) {
  // We will refresh the search result's TTL if possible
  if ($id && !$con->expire($id, $ttl)) {
    $id = null;
  }

  // If our search result expired, or if this is the first time we've searched, 
  // perform the standard SET search
  if (!$id) {
    $id = parse_and_search($con, $query, $ttl);
    $scored_search = [
      // We use the 'id' key for the intersection, 
      // but we don't want it to count towards weights
      $id => 0,
      // Set up the scoring adjustments for balancing update time and votes. 
      // Remember: votes can be adjusted to 1, 10, 100, or higher depending 
      // on the sorting result desired.
      'sort:update'=> $update,
      'sort:votes' => $vote
    ];
    // Intersect using our helper function that we define in listing 7.7
    $id = zintersect($con, $scored_search, $ttl);
  }

  $con->multi();
  // Fetch the size of the result ZSET
  $con->zCard('idx:'.$id);
  // Handle fetching a "page" of results
  if ($desc) {
    $con->zRevRange('idx:'.$id, $start, $start+$num-1);
  } else {
    $con->zRange('idx:'.$id, $start, $start+$num-1);
  }
  $results = $con->exec();

  // Return the results and the id for pagination
  return [$results[0], $results[1], $id];
}

function _zset_common($con, $method, $scores, $ttl=30, 
  $aggregate = 'sum', $execute = true) {
  // Create a new temporary identifier
  $id = uuid4();
  // Set up a transactional pipeline so that we have 
  // consistent results for each individual call
  if ($execute) {
    $con->multi();
  }
  // Add the 'idx:' prefix to our inputs
  foreach (array_keys($scores) as $key) {
    $scores['idx:'.$key] = $scores[$key];
    unset($scores[$key]);
  }
  // Set up the call for one of the operations
  call_user_func_array([$con, $method], ['idx:'.$id, array_keys($scores), array_values($scores), $aggregate]);
  // Instruct Redis to expire the ZSET in the future
  $con->expire('idx:'.$id, $ttl);
  // Actually execute the operation, unless explicitly instructed not to by the caller
  if ($execute) {
    $con->exec();
  }
  // Return the id for the caller to process the results
  return $id;
}

// Helper function to perform ZSET intersections
function zintersect($con, $items, $ttl=30, $aggregate='sum', $_execute=true) {
  return _zset_common($con, 'zInter', (array)$items, $ttl, $aggregate, $_execute);
}

// Helper function to perform ZSET unions
function zunion($con, $items, $ttl=30, $aggregate='sum', $_execute=true) {
  return _zset_common($con, 'zUnion', (array)$items, $ttl, $aggregate, $_execute);
}

function string_to_score($string, $ignore_case=false) {
  // We can handle optional case-insensitive indexes easily, so we will
  if (!$ignore_case) {
    $string = strtolower($string);
  }
  // Convert the first 6 characters of the string into their numeric values, 
  // null being 0, tab being 9, capital A being 65, etc.
  $pieces = array_map('ord', str_split(substr($string, 0,6)));
  // For strings that aren't at least 6 characters long, 
  // we will add place-holder values to represent that the string was short
  while (count($pieces) < 6) {
    $pieces[] = -1;
  }

  $score = 0;
  // For each value in the converted string values, we add it to the score, 
  // taking into consideration that a null is different from a place holder
  foreach ($pieces as $piece) {
    $score = $score * 257 + $piece + 1;
  }
  // Because we have an extra bit, we can also signify whether the string is exactly 6 
  // characters or more, allowing us to differentiate 'robber' and 'robbers', though 
  // not 'robbers' and 'robbery'
  return $score *2 + (strlen($string) > 6);
}

function to_char_map($set) {
  $out = [];
  foreach ($set as $pos => $val) {
    $out[$val] = $pos-1;
  }
  return $out;
}

$lower = to_char_map(array_merge([-1], range(ord('a'), ord('z'))));
//$alpha
//$lower_numeric
//$alpha_numeric

function string_to_score_generic($string, $mapping) {
  $length = intval(52/log(count($mapping), 2));

  $pieces = array_map('ord', str_split(substr($string, 0, $length)));
  while (count($pieces) < $length) {
    $pieces[] = -1;
  }
  $score = 0;
  foreach ($pieces as $piece) {
    $value = $mapping[$piece];
    $score = $score * count($mapping) + $value + 1;
  }
  return $score * 2 + (count($string) > $length);
}

function zadd_string($con, $name) {
  $args = array_slice(func_get_args(), 2);
  $pieces = [];
  $kwargs = [];
  // Combine both types of arguments passed for later modification
  foreach ($args as $item) {
    if (is_array($item)) {
      if (is_assoc_array($item)) {
        $kwargs[] = $item;
      }
    } else {
      $pieces[] = $item;
    }
  }
  foreach ($kwargs as $array) {
    foreach ($array as $key => $val) {
      $pieces[] = $key;
      $pieces[] = $val;
    }
  }

  // Convert string scores to integer scores
  foreach ($pieces as $k => &$v) {
    if ($k & 1) {
      $v = string_to_score($v);
    }
  }
  $params = array_reverse($pieces);
  array_unshift($params, $name);

  // Call the existing ZADD method
  // return $con->zAdd($name, ...$params);
  return call_user_func_array([$con, 'zAdd'], $params);
}

function cpc_to_ecpm($views, $clicks, $cpc) {
  return 1000.0 * $cpc * $clicks / $views;
}

function cpa_to_ecpm($views, $actions, $cpa) {
  // Because click through rate is (clicks/views), and action rate is (actions/clicks),
  //  when we multiply them together we get (actions/views)
  return 1000.0 * $cpa * $actions / $views;
}

$to_ecpm = [
  'cpc' => 'cpc_to_ecpm',
  'cpa' => 'cpa_to_ecpm',
  'cpm' => function () {
    $args = func_get_args();
    return end($args);
  }
];

function index_ad($con, $id, $locations, $content, $type, $value) {
  // Set up the pipeline so that we only need a single round-trip to 
  // perform the full index operation
  $con->multi();

  foreach ($locations as $location) {
    // Add the ad id to all of the relevant location SETs for targeting
    $con->sAdd('idx:req:'.$location, $id);
  }

  $words = tokenize($content);
  foreach ($words as $word) {
    // Index the words for the ad
    $con->zAdd('idx:'.$word, 0, $id);
  }
  global $to_ecpm, $average_per_1k;
  // We will keep a dictionary that stores the average number of clicks or 
  // actions per 1000 views on our network, for estimating the performance 
  // of new ads
  $rvalue = $to_ecpm[$type] (
    1000, isset($average_per_1k[$type]) ? $average_per_1k[$type]: 1, $value
  );
  // Record what type of ad this is
  $con->hSet('type:', $id, $type);
  // Add the ad's eCPM to a ZSET of all ads
  $con->zAdd('idx:ad:value:', $rvalue, $id);
  // Add the ad's base value to a ZST of all ads
  $con->zAdd('ad:base_value:', $value, $id);
  // Keep a record of the words that could be targeted for the ad
  array_unshift($words, 'terms:'.$id);
  call_user_func_array([$con, 'sAdd'], $words);
  // $con->sAdd('terms:'.$id, ...$words);
  $con->exec();
}

function target_ads($con, $locations, $content) {
  $con->multi();
  // Find all ads that fit the location targeting parameter, and their eCPMs
  list($matched_ads, $base_ecpm) = match_location($con, $locations);
  // Finish any bonus scoring based on matching the content
  list($words, $targeted_ads) = finish_scoring($con, $matched_ads, $base_ecpm, $content);

  // Get an id that can be used for reporting and recording of this particular ad target
  $con->incr('ads:served:');
  // Fetch the top-eCPM ad id
  $con->zRevRange('idx:'.$targeted_ads, 0,0);
  list($target_id, $targeted_ad) = array_slice($con->exec(), -2);

  // If there were no ads that matched the location targeting, return nothing
  if (!$targeted_ad) {
    return [null, null];
  }

  $ad_id = $targeted_ad[0];
  // Record the results of our targeting efforts as part of our learning process 
  record_targeting_result($con, $target_id, $ad_id, $words);

  // Return the target id and the ad id to the caller
  return [$target_id, $ad_id];
}

function match_location($con_multi, $locations) {
  // Calculate the SET key names for all of the provided locations
  $required = array_map(function($var) {return 'req:'.$var;}, $locations);
  // Calculate the SET of matched ads that are valid for this location
  $matched_ads = union($con_multi, $required, $ttl=300, $_execute=false);
  //Return the matched ads SET id, as well as the id of the ZSET that 
  //includes the base eCPM of all of the matched ads
  return [
    $matched_ads,
    zintersect(
      $con_multi, 
      [$matched_ads=>0, 'ad:value:'=>1], 
      $aggregate='sum', 
      $_execute=false
    )
  ];
}

function finish_scoring($con_multi, $matched, $base, $content) {
  $bonus_ecpm = [];
  // Tokenize the content for matching against ads
  $words = tokenize($content);
  foreach ($words as $word) {
    // Find the ads that are location-targeted, which also 
    // have one of the words in the content
    $word_bonus = zintersect(
      $con_multi, 
      [$matched=>0, $word=>1],
      'sum',
      false
    );
    $bonus_ecpm[$word_bonus] = 1;
  }

  if ($bonus_ecpm) {
    // Find the minimum and maximum eCPM bonuses for each ad
    $minimum = zunion($con_multi, $bonus_ecpm, 'min', false);
    $maximum = zunion($con_multi, $bonus_ecpm, 'max', false);

    // Compute the total of the base + half of the minimum 
    // eCPM bonus + half of the maximum eCPM bonus
    return [
      $words,
      zunion(
        $con_multi,
        [$base => 1, $minimum => 0.5, $maximum => 0.5],
        'sum',
        false
      )
    ];
  }
  // If there were no words in the content to match against, 
  // return just the known eCPM
  return [$words, $base];
}

function record_targeting_result($con, $target_id, $ad_id, $words) {
  $con->multi();

  // Find the words in the content that matched with the words in the ad
  $terms = $con->sMembers('terms:'.$ad_id);
  $matched = array_intersect($words, $terms);
  if ($matched) {
    $matched_key = 'terms:matched:'.$target_id;
    // If any words in the ad matched the content, record that 
    // information and keep it for 15 minutes
    array_unshift($matched, $match_key);
    call_user_func_array([$con, 'sAdd'], $matched);
    // $con->sAdd($matched_key, ...$matched);
    $con->expire($matched_key, 900);
  }

  // Keep a per-type count of the number of views that each ad received
  $type = $con->hGet('type:', $ad_id);
  $con->incr('type:'.$type.':views');
  // Record view information for each word in the ad, as well as the ad itself
  foreach ($matched as $word) {
    $con->zIncrBy('views:'.$ad_id, 1, $word);
  }
  $con->zIncrBy('views:'.$ad_id, 1, '');

  $r = $con->exec();
  if (!($r[count($r)] % 100)) {
    // Every 100th time that the ad was shown, update the ad's eCPM
    update_cpms($con, $ad_id);
  }
}

function record_click($con, $target_id, $ad_id, $action = false) {
  $con->multi();
  $click_key = 'clicks:'.$ad_id;

  $match_key = 'terms:matched:'.$target_id;

  $type = $con->hGet('type:', $ad_id);
  // If the ad was a CPA ad, refresh the expiration time of 
  // the matched terms if it is still available
  if ($type == 'cpa') {
    $con->expire($match_key, 900);
    if ($action) {
      // Record actions instead of clicks
      $click_key = 'actions:'.$ad_id;
    }
  }

  // Keep a global count of clicks/actions for ads based on the ad type
  if ($action && $type == 'cpa') {
    $con->incr('type:'.$type.':actions');
  } else {
    $con->incr('type:'.$clicks.':type');
  }

  // Record clicks (or actions) for the ad and for all words 
  // that had been targeted in the ad
  $matched = $con->sMembers($match_key);
  $matched[] = '';
  foreach ($matched as $word) {
    $con->zIncrBy($click_key, $word);
  }

  $con->exec();
  // Update the eCPM for all words that were seen in the ad
  update_cpms($con, $ad_id);
}

function update_cpms($con, $ad_id) {
  // Fetch the type and value of the ad, as well as all of the words in the ad
  list($type, $base_value, $words) = $con->multi()
    ->hGet('type:', $ad_id)
    ->zScore('ad:base_value:', $ad_id)
    ->sMembers('terms:'.$ad_id)
    ->exec();

  // Determine whether the eCPM of the ad should be based on clicks or actions
  $which = 'clicks';
  if ($type == 'cpa') {
    $which = 'actions';
  }

  // Fetch the current number of views and clicks/actions for the given ad type
  list($type_views, $type_clicks) = $con->multi() 
    ->get('type:'.$type.':views')
    ->get('type:'.$type.':'.$which)
    ->exec();

  global $to_ecpm, $average_per_1k;
  // Write back to our global dictionary the click-through rate or action rate for the ad
  $average_per_1k[$type] = (
    1000.0 * intval($type_clicks ? $type_clicks:1) /
    intval($type_views ? $type_views:1)
  );

  // If we are processing a CPM ad, then we don't update any of the eCPMs, as they are already updated
  if ($type == 'cpm') {
    return;
  }

  $view_key = 'views:'.$ad_id;
  $click_key = $which.':'.$ad_id;

  $my_to_ecpm = $to_ecpm[$type];

  // Fetch the per-ad view and click/action scores and
  list($ad_views, $ad_clicks) = $con->multi()
    ->zScore($view_key, '')
    ->zScore($click_key, '')
    ->exec();

  if ($ad_clicks < 1) {
    // Use the existing eCPM if the ad hasn't received any clicks yet
    $ad_ecpm = $con->zScore('idx:ad:value:', $ad_id);
  } else {
    // Calculate the ad's eCPM and update the ad's value
    $ad_ecpm = $my_to_ecpm(
      $ad_views ? $ad_views : 1,
      $ad_clicks ? $ad_clicks :0,
      $base_value
    );
  }

  foreach ($words as $word) {
    // Fetch the view and click/action scores for the word
    list($views, $clicks) = $con->multi()
      ->zScore($view_key, $word)
      ->zScore($click_key, $word)
      ->exec();

    // Don't update eCPMs when the ad has not received any clicks
    if ($clicks < 1) {
      continue;
    }

    // Calculate the word's eCPM
    $word_ecpm = $my_to_ecpm(
      $views ? $views : 1,
      $clicks ? $clicks : 0,
      $base_value
    );
    // Calculate the word's bonus
    $bonus = $word_ecpm - $ad_ecpm;
    // Write the word's bonus back to the per-word per-ad ZSET
    $con->zAdd('idx:'.$word, $ad_id, $bonus);
  }
}

function add_job($con, $job_id, $required_skills) {
  // Add all required job skills to the job's SET
  $con->sAdd('job:'.$job_id, ...$required_skills);
}

function is_qualified($con, $job_id, $candidate_skills) {
  $temp = uuid4();
  $r = $con->multi()
    // Add the candidate's skills to a temporary SET with an expiration time
    ->sAdd($temp, ...$candidate_skills)
    // Add the candidate's skills to a temporary SET with an expiration time
    ->expire($temp, 20)
    // Calculate the SET of skills that the job requires that the user doesn't have
    ->sDiff('job:'.$job_id, $temp)
    ->exec();
  // Return True if there are no skills that the candidate does not have
  return !end($r);
}

function index_job($con, $job_id, $skills) {
  $con->multi();
  foreach ($skills as $skill) {
    // Add the job id to all appropriate skill SETs
    $con->sAdd('idx:skill:'.$skill, $job_id);
  }
  // Add the total required skill count to the required skills ZSET
  $con->zAdd('idx:jobs:req', count($skills), $job_id);
  $con->execute();
}

function find_jobs($con, $candidate_skills) {
  // Set up the dictionary for scoring the jobs
  $skills = [];
  foreach ($candidate_skills as $skill) {
    $skills['skill:'.$skill] = 1;
  }
  // Calculate the scores for each of the jobs
  $job_score = zunion($con, $skills);
  // Calculate how many more skills the job requires than the candidate has
  $final_result = zintersect($con, [$job_score => -1, 'jobs:req' => 1]);

  // Return the jobs that the candidate has the skills for
  return $con->zRangeByScore('idx:'.$final_result, 0, 0);
}

// 0 is beginner, 1 is intermediate, 2 is expert
define('SKILL_LEVEL_LIMIT ', 2);

function index_job_levels($con, $job_id, $skill_levels) {
  $total_skills = count(array_unique(array_map(
    function($var){return $var[0];}, $skill_levels)));
  $con->multi();
  foreach ($skill_levels as $val) {
    list($skill, $level) = $val;
    $level = min($level, SKILL_LEVEL_LIMIT);
    foreach(range($level, SKILL_LEVEL_LIMIT) as $wlevel) {
      $con->sAdd('idx:skill:'.$skill.':'.$wlevel, $job_id);
    }
  }
  $con->zAdd('idx:jobs:req', $total_skills, $job_id);
  $con->exec();
}

function search_job_levels($con, $skill_levels) {
  $skills = [];
  foreach ($skill_levels as $val) {
    list($skill, $level) = $val;
    $level = min($level, SKILL_LEVEL_LIMIT);
    $skills['skill:'.$skill.':'.$level] = 1;
  }
  $job_scores = zunion($con, $skills);
  $final_result = zintersect($con, [$job_scores=>-1, 'jobs:req'=>1]);

  return $con->zRangeByScore('idx:'.$final_result, '-inf',0);
}

function index_job_years($con, $job_id, $skill_years) {
  $total_skills = count(array_unique(array_map(
    function($var) {return $var[0];}, $skill_years)));
  $con->multi();
  foreach ($skill_years as $val) {
    list($skill, $years) = $val;
    $con->zAdd('idx:skill:'.$skill.':years', max($years, 0), $job_id);
  }
  $con->sAdd('idx:jobs:all', $job_id);
  $con->zAdd('idx:jobs:req', $total_skills, $job_id);
  $con->exec();
}

function search_job_years($con, $skill_years) {
  $skill_years = [];
  array_map(function($var)use(&$skill_years){ $skill_years[$var[0]] = $var[1];}, $skill_years);
  $union = [];
  foreach ($skill_years as $skill => $years) {
    $sub_result = zintersect(
      $con,
      ['jobs:all' => -$years, 'skill:'.$skill.':years' => 1],
      'sum',
      false
    );
    $con->zRemRangeByScore('idx:'.$sub_result, '(0', 'inf');
    $union[] = zintersect(
      $con, ['jobs:all' => 1, $sub_result => 0], 'sum', false
    );
  }
  $job_scores = zunion($con, array_combine($union, array_pad([], count($union), 1)), 'sum', false);
  $final_result = zintersect($con, [$job_scores => -1, 'jobs:req' => 1], 'sum', false);

  $con->zRangeByScore('idx:'.$final_result, '-inf', 0);
  return end($con->exec());
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

// startswith function like python
function startswith($haystack, $needle) {
  return $needle === "" || strpos($haystack, $needle) === 0;
}

// whether passed variable is an associative array
function is_assoc_array($var) {
  return is_array($var) && array_keys($var) !== range(0, sizeof($var) -1);
}

// whether passed variable is numberic array
function is_numeric_array($var) {
  if (!is_array($var)) {
    return false;
  }
  return (sizeof($var) ===0 || array_keys($var) === range(0, sizeof($var) -1));
}

class TestCh07 {
  public $con = null;
  public $content = 'this is some random content, look at how it is indexed.';

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

  public function _test_index_document() {
    echo "We're tokenizing some content...\n";
    $tokens = tokenize($this->content);
    echo "Those tokens are:", self::pprint($tokens);
    $this->assertTrue($tokens);

    echo "And now we are indexing that content...\n";
    $r = index_document($this->con, 'test', $this->content);
    $this->assertEquals($r, count($tokens));
    foreach ($tokens as $t) {
      $this->assertEquals($this->con->sMembers('idx:'.$t), ['test']);
    }
  }

  public function _test_set_operations() {
    index_document($this->con, 'test', $this->content);

    $r = intersect($this->con, ['content', 'indexed']);
    $this->assertEquals($this->con->sMembers('idx:'.$r), ['test']);

    $r = intersect($this->con, ['content', 'ignored']);
    $this->assertEquals($this->con->sMembers('idx:'.$r), []);

    $r = union($this->con, ['content', 'ignored']);
    $this->assertEquals($this->con->sMembers('idx:'.$r), ['test']);

    $r = difference($this->con, ['content', 'ignored']);
    $this->assertEquals($this->con->sMembers('idx:'.$r), ['test']);

    $r = difference($this->con, ['content', 'indexed']);
    $this->assertEquals($this->con->sMembers('idx:'.$r), []);

    echo "set operations test done!\n";
  }

  public function _test_parse_query() {
    $query = 'test query without stopwords';
    $this->assertEquals(parse($query), [array_map(function ($var) {return [$var];}, explode(' ', $query)), []]);

    $query = 'test +query without -stopwords';
    $this->assertEquals(parse($query), [[['test', 'query'], ['without']], ['stopwords']]);

    echo "parse query test done!\n";
  }

  public function _test_parse_and_search() {
    echo "And now we are testing search...\n";
    index_document($this->con, 'test', $this->content);

    $r = parse_and_search($this->con, 'content');
    $this->assertEquals($this->con->sMembers('idx:'.$r), ['test']);

    $r = parse_and_search($this->con, 'content indexed random');
    $this->assertEquals($this->con->sMembers('idx:'.$r), ['test']);

    $r = parse_and_search($this->con, 'content +indexed random');
    $this->assertEquals($this->con->sMembers('idx:'.$r), ['test']);

    $r = parse_and_search($this->con, 'content indexed +random');
    $this->assertEquals($this->con->sMembers('idx:'.$r), ['test']);

    $r = parse_and_search($this->con, 'content indexed -random');
    $this->assertEquals($this->con->sMembers('idx:'.$r), []);

    echo "Which passed!\n";
  }

  public function _test_search_with_sort() {
    echo "And now let's test searching with sorting...\n";
    index_document($this->con, 'test', $this->content);
    index_document($this->con, 'test2', $this->content);
    $this->con->hMset('kb:doc:test', ['updated' => 12345, 'id' => 10]);
    $this->con->hMset('kb:doc:test2', ['updated' => 54321, 'id' => 1]);

    $r = search_and_sort($this->con, 'content');
    $this->assertEquals($r[1], ['test2', 'test']);

    $r = search_and_sort($this->con, 'content', $id=null, $ttl=300, $sort='-id', $start=0, $num=20);
    $this->assertEquals($r[1], ['test', 'test2']);

    echo "Which passed!\n";
  }

  public function _test_search_with_zsort() {
    echo "And now let's test searching with sorting...\n";

    index_document($this->con, 'test', $this->content);
    index_document($this->con, 'test2', $this->content);
    $this->con->zAdd('idx:sort:update', 12345, 'test', 54321, 'test2');
    $this->con->zAdd('idx:sort:votes', 10, 'test', 1, 'test2');

    $r = search_and_zsort($this->con, 'content', $id=null, $ttl=300, $update=1, $vote=0, $start=0, $num=20, $desc=false);
    $this->assertEquals($r[1], ['test', 'test2']);

    $r = search_and_zsort($this->con, 'content', $id=null, $ttl=300, $update=0, $vote=1, $start=0, $num=20, $desc=false);
    $this->assertEquals($r[1], ['test2', 'test']);

    echo "Which passed!\n";
  }

  public function _test_string_to_score() {
    $words = explode(' ', 'these are some words that will be sorted');
    $pairs = array_map(function($var){return [$var, string_to_score($var)];}, $words);
    $pairs2 = $pairs;
    sort($pairs);
    usort($pairs2, function($a, $b) {
      if ($a[1] == $b[1]) {
        return 0;
      }
      return $a[1] < $b[1] ? -1 : 1;
    });
    $this->assertEquals($pairs, $pairs2);

    global $lower;
    $words = explode(' ', 'these are some words that will be sorted');
    $pairs = array_map(function($var)use($lower){ return [$var, string_to_score_generic($var, $lower)];}, $words);
    $pairs2 =$pairs;
    sort($pairs);
    usort($pairs2, function($a, $b) {
      if ($a[1] == $b[1]) {
        return 0;
      }
      return $a[1] < $b[1] ? -1 : 1;
    });
    $this->assertEquals($pairs, $pairs2);

    zadd_string($this->con, 'key', 'test', 'value',['test2' => 'other']);
    $this->assertEquals($this->con->zScore('key', 'test'), string_to_score('value'));
    $this->assertEquals($this->con->zScore('key', 'test2'), string_to_score('other'));
  }

  public function _test_index_and_target_ads() {
    index_ad($this->con, '1', ['USA', 'CA'], $this->content, 'cpc', 0.25);
    index_ad($this->con, '2', ['USA', 'VA'], $this->content . ' wooooo', 'cpc', 0.125);
  }

  public function test_is_qualified_for_job() {
    add_job($this->con, 'test', ['q1', 'q2', 'q3']);
    $this->assertTrue(is_qualified($this->con, 'test', ['q1', 'q3', 'q2']));
    $this->assertTrue(!is_qualified($this->con, 'test', ['q1', 'q2']));
  }

  public function test_index_and_find_jobs() {
    index_job($this->con, 'test1', ['q1', 'q2', 'q3']);
    index_job($this->con, 'test2', ['q1', 'q2', 'q4']);
    index_job($this->con, 'test3', ['q1', 'q3', 'q5']);

    $this->assertEquals(find_jobs($this->con, ['q1']), []);
    $this->assertEquals(find_jobs($this->con, ['q1', 'q3', 'q4'], ['test3']));
    $this->assertEquals(find_jobs($this->con, ['q1', 'q3', 'q5'], ['test3']));
    $this->assertEquals(find_jobs($this->con, ['q1', 'q2', 'q3', 'q4', 'q5']), ['test1','test2', 'test3']);
  }

  public function test_index_and_find_jobs_levels() {
    echo "Now testing find jobs with levels...\n";
    index_job_levels($this->con, 'job1', [['q1', 1]]);
    index_job_levels($this->con, 'job2', [['q1', 0], ['q2', 2]]);

    $this->assertEquals(search_job_levels($this->con, [['q1', 0]]), []);
    $this->assertEquals();
  }

  public static function pprint($var) {
    echo json_encode($var, true);
    echo "\n";
  }

  public function assertTrue($bool, $message = 'not TRUE') {
    if ($bool) {
      return;
    }
    $this->print_assert_msg(__METHOD__, __FILE__, $message);
  }

  public function assertEquals($exp1, $exp2, $message = 'not EQUALS') {
    if ($exp1 === $exp2) {
      return;
    }
    $this->print_assert_msg(__METHOD__, __FILE__, $message);
  }

  public function print_assert_msg($fn, $file, $message) {
    $fn = explode('::', $fn)[1];

    $traces = debug_backtrace();
    foreach ($traces as $trace) {
      if ($trace['function'] == $fn) {
        $line = $trace['line'];
        break;
      }
    }
    echo 'assert fail:', basename($file), ':', $line, ', ', $message;
    echo "\n";
    exit(-1);
  }

  public function __destruct() {
    $this->con->close();
    $this->con = null;
  }
}

TestCh07::run();
 ?>