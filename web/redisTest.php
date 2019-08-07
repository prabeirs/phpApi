<?php
$redis = new Redis();
$redisUrl = getenv("REDIS_URL");
if ($redisUrl == FALSE) {
    // ENv var REDIS_URL does not exist
    $redisUrl = "redis://localhost:6379";
} else {
    $toks = explode("//", $redisUrl);
    //var_dump($toks);
    $redisUrl = explode(":", $toks[1]);
    $redisUrl = $redisUrl[0];
    if (empty($redisUrl)) {
        $redisUrl = "redis://localhost:6379";
    }
}
    
$redis->connect($redisUrl, 6379);
//echo $redis->ping();

$jsonResult = $redis->get("http://localhost:80/index.php");

if ($jsonResult) {
    print "got from cache<br>\n";
    print($jsonResult);
} else {
    print("no cached result found!<b>\n");
    //var_dump($jsonResult);

    $curl = curl_init();
    curl_setopt ($curl, CURLOPT_URL, "http://localhost:80/index.php");
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    $result = curl_exec ($curl);
    curl_close ($curl);
    print $result;
    
    $redis->set("http://localhost:80/index.php", $result);
}
print("<br>\n");
/*$x = array(1,2,3);
foreach($x as $i) {
    print "$i<br>\n";
    foreach ($x as $j) {
        if ($j == 3) {
            break 2;
        }
        print("$i $j<br>\n");
    }
}*/


?>
