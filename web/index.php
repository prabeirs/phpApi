<?php

//ini_set('display_errors', 1);
//ini_set('display_startup_errors', 1);
//error_reporting(E_ALL);

// Returns the db config parameters in a dict
function _getDbConfig() {
    $dbName = "hellofresh";
    $userName = "hellofresh";
    $password = "hellofresh";
    $port = 5432;
    $host = "postgres";

    $config = array("host" => $host, "dbname" => $dbName, "user" => $userName, "password" => $password, "port" => $port);

    return($config);
}

// Gather the Redis config details and return the connection handle
// Return an array of two elems 1. redis conn obj, 2. messages json encoded dict
function getRedisDb() {
    $redis = NULL;
    try {
        $redis = new Redis();
    } catch (Exception $e) {
        $data["message"] = "Caught exception, unable to instantiate Redis object. " . $e->getMessage();
        $data["status"] = "failure";
        return array($redis, json_encode($data));
    }

    $redisUrl = getenv("REDIS_URL");
    if ($redisUrl == FALSE) {
        // ENv var REDIS_URL does not exist
        $redisUrl = "http://localhost:6379";
    } else {
        $toks = explode("//", $redisUrl);
	//var_dump($toks);
	$redisUrl = explode(":", $toks[1]);
	$redisUrl = $redisUrl[0];
	if (empty($redisUrl)) {
	    $redisUrl = "http://localhost:6379";
	}
    }
    $retVal = $redis->connect($redisUrl, 6379, 1, NULL, 100); // 1 sec timeout, 100ms delay between reconnection attempts.
    if (!$retVal) {
        $data["message"] = "UNable to connect to Redis";
        $data["status"] = "failure";
    } else {
        $data["message"] = "Redis connection successful";
        $data["status"] = "Ok";
    }
    //echo $redis->ping();

    return array($redis, json_encode($data));
}

// Connects to a pgsql db as per _getConfigDb() and returns the db connection handle as first param and message inside a dict in second param
// Returns an array of 1 item is db handle, 2nd is dict of messages.
function getdb() {
    error_reporting(E_ALL);
    $config = _getDbConfig();
    $configStr = "";
    foreach ($config as $key => $value) {
        $configStr .= "$key=$value ";
    }
    $configStr = rtrim($configStr);
    $connection = pg_connect($configStr);
    if($connection) {
        $data["message"] =  "sucessfully connected";
        $data["status"] = "Ok";
        $data["result"] = $config;
    } else {
        $data["message"] = "there has been an error connecting to db!";
        $data["status"] = "error";
    }
    error_reporting(0);
    return array($connection, json_encode($data));
}

// For an Update query using PATCH method, once after the Db is updated successfully then come over here and
// query Redis for the Individual and All query keys. Update all query/key's value and then store the same json obj.
// Now query the individual query/key from Redis and update/overwrite each of them with the passed in parameter's values.
function checkUpdateRedis($updateData) {
    global $requestUrl;
    global $redisHandle;
    $keyIndividual = $requestUrl;
    $keyAll = "http://localhost:80/index.php";
    $tmpJson = array();

    if (!isset($redisHandle)) {
        return;
    }

    $valueIndividual = $redisHandle->get($keyIndividual);
    $valueAll        = $redisHandle->get($keyAll);
    $newIndividualValue = Null;

    $jsonArr = json_decode($valueAll, True);

    foreach ($jsonArr as $key => $item) {
        //print("$key  -> " . $item . "<br>\n");
        if ($key == "result") {
            $valueOfResult = $jsonArr[$key];
            foreach ($valueOfResult as $jsonObj) {
                //print("--> " . $_GET["id"] . "  " . $jsonObj["name"] . " " . $jsonObj["id"] . "<br>\n");
                if ($jsonObj["id"] == $_GET["id"]) {
                    // Manipulate the json overwriting old present values with new PATCH passed in values. PATCH passed in ones are in the
                    // passed in parameter to this function.
                    foreach ($updateData as $key => $value) {
                        //print("Manipulating old key $key value " . $jsonObj[$key] . " with new " . "$value<br>\n");
                        $jsonObj[$key] = $updateData[$key];
                    }
                    array_push($tmpJson, $jsonObj); // store modified object in the temp json collector
                    $newIndividualValue = $jsonObj;
                    break 2;
                } else {
                    array_push($tmpJson, $jsonObj);  // non modified object gathering
                }
            }
        }
    }

    $x = $redisHandle->set($keyIndividual, json_decode($newIndividualValue));
    $y = $redisHandle->set($keyAll, json_decode($tmpJson));
    return array($x, $y);
}

// Remove a key from Redis after checking it's existence once
function removeFromRedis($key) {
    global $redisHandle;

    if (!isset($redisHandle)) {
        return;
    }

    $x = $redisHandle->exists($key);
    if ($x) {
        //print("$x key exists in cache\n");
        $x = $redisHandle->del($key);  // Redis version < 4.0.0 . FOr > 4.0.0 use unlink
        if ($x == 1) {
            //print("Successfully removed $x record from cache\n");
            return True;
        } else {
            //print("Failure in removing $key from cache\n");
            return False;
        }
    } else {
        //print("key does not exist in cache\n");
        return True;
    }
}

// Return from Redis a sought key's value.
// Return the existing key's string value from Redis
// Return NUll if Redis is not set up or unsed.
// Param key is the request url 
function checkRedisFor($key) {
    $retVal = "";
    global $redisHandle;

    if (!isset($redisHandle)) {
        return;
    }
    //print("Checking for $key in cache...");
    $retVal = $redisHandle->get($key);
    //print(" got : ");
    //print($retVal);
    //print("<br>\n");

    if ($retVal) {
        return array(True, $retVal);
    } else {
        return array(False, $retVal);
    }
}

// Inserts a new key for the Redis.
function newRedisFor($key, $value) {
    global $redisHandle;
    
    if (!isset($redisHandle)) {
        return;
    }

    //var_dump($value);

    if (!$redisHandle->set($key, $value)) {
        //$str = "cannot insert $value for $key<br>\n";
        //print($str . "Redis ping check : ". $redisHandle->ping());
        return False;
    }
    else {
        //print("inserted new Redis Ok $value for $key<br>\n");
        return True;
    }

}

// Readies a pgsql query based on the POST'ed field parameters (curl -d ....) and returns it along with json encoded message.
// On failure returns a json encoded message comprising of necessary but missing POST parameters.
function createRecipe() {

    $table = "recipe"; // If not straight forward it can be gathered from other place and need change here then.
    $titleMessage = "";
    $sql = "";
    $errCounter = 0;
    $newJsonData = array();
    // RIght now there is a missing thing that when we pass a GET param to a POST request it should normally abort but it do not.
    // The check below desired to check that aspect & bail out is erroneous at the moment.
    /*foreach ($_GET as $key => $val) {
        $data["message"] = "unexpected GET parameter passed on";
        $data["status"] = "error";
        // set response code - 400 bad request
        http_response_code(400);
        return($sql, $data["message"]);
    }*/
    if (!empty($_POST["recipe_name"])) {
        $recipeName = $_POST["recipe_name"];
        $newJsonData["name"] = $recipeName;
    } else {
        $titleMessage .= "recipe_name ";
        $errCounter++;
    }
    if (!empty($_POST["prep_time"])) {
        $prepTime = $_POST["prep_time"];
        $newJsonData["prep_time"] = $prepTime;
    } else {
        $titleMessage .= "prep_time ";
        $errCounter++;
    }
    if (!empty($_POST["difficulty"])) {
        $difficulty = $_POST["difficulty"];
        $newJsonData["difficulty"] = $difficulty;
    } else {
        $titleMessage .= "difficulty ";
        $errCounter++;
    }
    if (!empty($_POST["vegetarian"])) {
        $veg = $_POST["vegetarian"];
        $newJsonData["vegetarian"] = $veg;
    } else {
        $titleMessage .= "vegetarian ";
        $errCounter++;
    }
    if (!empty($titleMessage)) {
        if ($errCounter == 1) {
            $titleMessage .= "POST parameter is missing!";
        } else {
            $titleMessage .= "POST parameters are missing!";
        }
    }

    if (!isset($recipeName) || !isset($prepTime) || !isset($difficulty) || !isset($veg)) {
        $data["message"] = $titleMessage;
        $data["status"] = "error";
    } else {
        $sql = "INSERT INTO $table(name, prep_time, difficulty, vegetarian) VALUES ('$recipeName' , '$prepTime', $difficulty, $veg) RETURNING id";
        $data["message"] = "successfull sql creation for input data";
        $data["status"] = "Ok";
    }

    return array($sql, $data["message"], $newJsonData);
}

// Executes the supplied second parameter query to db to populate it with the recipe.
// Returns just a success or failure json encoded message.
function insertTo($dbHandle, $query, $redisData) {

    $result = pg_query($dbHandle, $query);

    if (!$result) {
        $data["message"] = "data not saved successfully";
        $data["status"] = "error";
        // set response code - 503 service unavailable
        http_response_code(503);
    } else {
        $data["message"] = "data saved successfully";
        $data["status"] = "Ok";
        // Use the passed parameter json object as a new record and then insert it to Redis mapped it with two possible GET queries :-
        // One individual get query with "id=X" and another without id i.e; all's query.
        // set response code - 201 created
        // But we DO NOT know the ID which got dynamically created & inserted in the DB for this new record!!
        $row = pg_fetch_row($result);
        $newId = $row[0];
        print("new ID = $newId<br>\n");
        $redisData["id"] = $newId;
        $tmpJson["result"] = array($redisData);
        $tmpJson["message"] = "data saved successfully";
        $tmpJson["status"] = "Ok";
        $value = json_encode($tmpJson);
        $key = "http://localhost:80/index.php?id=$newId";
        newRedisFor($key, $value);
        appendRedisFor(json_encode($redisData));
        
        http_response_code(201);
    }

    return(json_encode($data));

}

// Append to Redis a new value. Obviously as name suggests it appends it to the "all" GET query key which already exists and has a value.
function appendRedisFor($newValue) {
    global $redisHandle;

    if (!isset($redisHandle)) {
        return;
    } else {
        //print("new value param decoded :");
        //var_dump($newValue);
        //print("end new value<br>\n");

        $keyAll = "http://localhost:80/index.php";
        $presentAllValue = $redisHandle->get($keyAll);
        if ($presentAllValue == False) {
            //print("inserting fresh first $newValue for key $keyAll returned :");
            $x = $redisHandle->set($keyAll, json_decode($newValue), True);
            //print(" $x<br>\n");
            return $x;
        }
        //print("present value = $presentAllValue<br>\n");
        $presentAllValueArr = json_decode($presentAllValue, True);
        //print("present all value :");
        //var_dump($presentAllValueArr);
        //print("end present all<br>\n");
        //print("present all value only result :");
        array_push($presentAllValueArr["result"], json_decode($newValue));
        //var_dump($presentAllValueArr);
        //print("end present all value result<br>\n");
        $retVal = $redisHandle->set($keyAll, json_encode($presentAllValueArr));
        //print("redis insert returned : " . $reVal . "<br>\n");
        return $retVal;
    }

}


// The "result" key of the json encoded values of the return value holds an array whose single element is the recipe.
function getSelectFrom($db, $thing) {

    // Check in Redis and if found then return
    global $requestUrl;
    list($x, $jsonData) = checkRedisFor($requestUrl);
    if ($x) {
        // set response code - 200 OK
        http_response_code(200);
        return($jsonData);
    }
 
    // If not found in Redis then queury the DB and also immediate later insert it to Redis as a new key/value 
    $location = "recipe";
    $selectFields = array("id" => $thing);
    $rec = pg_select( $db, $location, $selectFields );
    if ($rec) {
        global $requestUrl;
        $data["message"] = "data selected successfully";
        $data["status"] = "Ok";
        $data["result"] = $rec;
        newRedisFor($requestUrl, json_encode($data));  // INsert it in Redis
        // set response code - 200 OK
        http_response_code(200);
    } else {
        global $requestUrl;
        removeFromRedis($requestUrl); // As the DB select query failed so just check Redis if it exists in case then remove it.
                                      // But this is not needed I beieve as we reach till here iff it was not found  in Redis only
        $data["message"] = "no data or wrong input received";
        $data["status"] = "error";
        // set response code - 404 Not found
        http_response_code(404);
    }

    return(json_encode($data));

}

// The "result" key of the json encoded values of the return value holds an array whose each element is an recipe.
function getAllFrom($db) {

    // Check it first in Redis if found then return
    global $requestUrl;
    list($x, $jsonData) = checkRedisFor($requestUrl);
    if ($x) {
        // set response code - 200 OK
        http_response_code(200);
        return($jsonData);
    }

    // Iff not found in Redis then query DB
    $result = pg_query($db, "SELECT * from Recipe");
    if (!$result) {
       $data["message"] = "An error occurred while gathering data from DB";
       $data["status"] = "error";
        // set response code - 404 Not found
        http_response_code(404);
    } else { // Db query OK
        $arr = pg_fetch_all($result);
        $data["result"] = $arr;
        if (!$arr) {
            $data["message"] = "no data retrieved";
        } else {
            $data["message"] = "data retrieved successfully";
        }
        $data["status"] = "Ok";
        if ($arr == False) { // But no results (0 results) from DB
            global $requestUrl;
            removeFromRedis($requestUrl);  // then remove this key from Redis in case it exists there
        } else {
            newRedisFor($requestUrl, json_encode($data)); // Insert this key to Redis as we reached here if it was not found in Redis only
        }
        // set response code - 200 OK
        http_response_code(200);
    }

    return (json_encode($data));
}

// This usage is necessarily to remove a single record only at a time (call).
function removeFrom($db, $thing) {
    $location = "recipe";
    $selectFields = array("id" => $thing);
    $rec = pg_select( $db, $location, $selectFields );
    $res = Null;
   
    // If the record is in DB  then try delete it from DB
    if ($rec) {
        $res = pg_delete($db, $location, $selectFields);
    } else { // If the record is not in DB then check and remove from Redis if IN CASE it exists there just to keep insync
        global $requestUrl;
        //print("req url = $requestUrl\n");
        //removeFromRedis($requestUrl); // Remove the individual query key from Redis. NOTE: Probably this code is not needed here as below
                                      // function call does this task along with an another
        syncRedisWithDb(); // Remove from the all query and individual query of redis for this particular record "id" & then update redis
        
        $data["message"] = "data does not exist in DB!";
        $data["status"] = "fail";
        // set response code - 400 bad request
        http_response_code(400);
        return (json_encode($data));
    }

    if ($res) { // If record was in DB and also the deletion from DB returned TRUE/Success
        $data["message"] = "data $thing is deleted successfully";
        $data["status"] = "Ok";
        $data["result"] = $res;
        // Update redis GET calls for this "id" and also for all id's GET call to have both of them remove this record
	// First get the present old value for this key. Store it temporarily
        // Second get the present old all keys values from Redis. Store it temporarily
        // Remove this old key & value from Redis second step var and update Redis with modified var
        // Remove this old key & value from the Redis using the first step var's key
        syncRedisWithDb();

        // set response code - 200 ok
        http_response_code(200);
    } else { // If the record deletion from DB returned FALSE/failure
        global $requestUrl;
        assertRedisInsert($requestUrl, $db, $thing); // Check whether the key is present in Redis. If not present then insert in Redis as it is in DB or it remained in DB as we see just now above
        $data["message"] = "sent wrong inputs!";
        $data["status"] = "error";
        // set response code - 503 service unavailable
        http_response_code(503);
    }

    return (json_encode($data));

}

// This function remove from the "all" GET query and "individual/id" GET query of redis the particular record "id" which is under an attempt 
// to removal from backend & then update redis
function syncRedisWithDb() {
    global $redisHandle;
    global $requestUrl;
    $requestAll = "http://localhost:80/index.php";
    $requestId = $_GET["id"];
    $tmpJson = array();
    if (!isset($redisHandle)) {
        return;
    }

    $presentIndividualValue = $redisHandle->get($requestUrl); // NOTE ###
    $presentAllValue = $redisHandle->get($requestAll);
    //print("Individual query $requestUrl value = $presentIndividualValue<br>\n");
    //print("All query $requestAll value = $presentAllValue<br>\n");
    $jsonArr = json_decode($presentAllValue, True);
    foreach ($jsonArr as $key => $item) {
        //print("$key  -> " . $item . "<br>\n");
        if ($key == "result") {
            $valueOfResult = $jsonArr[$key];
            foreach ($valueOfResult as $jsonObj) {
                //print("--> " . $jsonObj["name"] . " " . $jsonObj["id"] . "<br>\n");
                if ($jsonObj["id"] == $_GET["id"]) {
                    continue;
                } else {
                    array_push($tmpJson, $jsonObj);
                }
            }
        }
    }
    //var_dump($tmpJson);
    $redisHandle->set($requestAll, json_decode($tmpJson));
    removeFromRedis($requestUrl);
    return True;
}

// Check a key existence in Redis and if not present then query the DB backend and insert it in redis
function assertRedisInsert($key, $db, $thing) {
    global $redisHandle;

    if (!isset($redisHandle)) {
        return;
    }

    if (!$redisHandle->exists($key)) {
        
        $location = "recipe";
        $selectFields = array("id" => $thing);
        $rec = pg_select( $db, $location, $selectFields );
        if ($rec) {
            global $requestUrl;
            $data["message"] = "data selected successfully";
            $data["status"] = "Ok";
            $data["result"] = $rec;
            return newRedisFor($requestUrl, json_encode($data));
        } else {
            return False;
        }
    }
    return True;
}

// Function to update a recipe by reading it's supplied "id". Reads the records wanted to be updated from PATCH HTTP method values,
// read the "id" (required indeed) from GET HTTP method value and updates Db after few validation checks. Check for the affected rows
// updated in Db and then construct proper client returnable messages and status code.
function updateRecipe($dbHandle) {

    $table = "recipe"; // If not straight forward it can be gathered from other place and need change here then.
    $titleMessage = "";
    $errCounter = 0;
    $updateData = array();
    $updateCondition = array();

    parse_str(file_get_contents('php://input'), $_PATCH);
    if (!empty($_PATCH["prep_time"])) {
        $newPrepTime = $_PATCH["prep_time"];
        $updateData["prep_time"] = strip_tags($newPrepTime);
    }
    if (!empty($_PATCH["recipe_name"])) {
        $newRecipeName = $_PATCH["recipe_name"];
        $updateData["recipe_name"] = "$newRecipeName";
    }
    if (!empty($_PATCH["difficulty"])) {
        $newDifficulty = $_PATCH["difficulty"];
        $updateData["difficulty"] = $newDifficulty;
    }
    if (!empty($_PATCH["vegetarian"])) {
        $newVegetarian = $_PATCH["vegetarian"];
        $updateData["vegetarian"] = $newVegetarian;
    }
    if (!empty($_GET["id"])) {
        $id = $_GET["id"];
        $updateCondition["id"] = strip_tags($id);
    } else {
        $titleMessage .= "id ";
        $errCounter++;
    }
    if ($errCounter == 1) {
        $titleMessage .= "GET parameter is missing";
    } elseif ($errCounter > 1) {
        $titleMessage .= "GET parameters are missing";
    }
    if (!empty($titleMessage)) {
        $data["message"] = $titleMessage;
        $data["status"] = "error";
        // set response code - 400 bad request
        http_response_code(400);
        return(json_encode($data));
    }

    if (!isset($newPrepTime) && !isset($newRecipeName) && !isset($newDifficulty) && !isset($newVegetarian)) {
        $data["message"] = "not a single update PATCH parameter supplied";
        $data["status"] = "error";
        // set response code - 400 bad request
        http_response_code(400);
    } else {
        $setStr = "";
        $whereStr = "";
        $returnStr = "";
        foreach ($updateData as $key => $val) {
            if ($key == "prep_time") {
                $setStr .= "$key = '$val', ";
            }
            elseif ($key == "recipe_name") {
                $key = "name"; // this is what is the field's name in db but for an user recipe_name is specific & clear.
                $setStr .= "$key = '$val', ";
            }
            else {
                $setStr .= "$key = $val, ";
            }

            $returnStr .= "$key, ";            
        }
        $setStr = rtrim($setStr);
        $setStr = rtrim($setStr, ",");
        $returnStr = rtrim($returnStr);
        $returnStr = rtrim($returnStr, ",");

       $whereStr = "id = " . $updateCondition["id"];

       $sql = "UPDATE $table SET $setStr WHERE $whereStr RETURNING $returnStr";
       //print("sql = $sql");
       $result = pg_query($dbHandle, $sql);
       $rowsAffected = pg_num_rows($result); // is returned -1 on error

        if (!$result) {
            $data["message"] = "data not saved successfully. rows from resultset is $rowsAffected";
            $data["status"] = "error";
            // set response code - 503 service unavailable
            http_response_code(503);
        } else {
            if ($rowsAffected > 0) {
                // As the DB deletion is OK so go to redis and query the present old key/value individual and all and then overwrite them
                // accordingly finding the PATCH passed in parameters in the redis's jsons of both.
                checkUpdateRedis($updateData);
                $data["message"] = "data saved successfully. rows from resultset is $rowsAffected";
            } elseif ($rowsAffected == -1) {
                $data["message"] = "data saved successfully. Though rows updated in resultset is $rowsAffected unexpected";
            } else {
                $data["message"] = "no effective data saved. rows from resultset is $rowsAffected";
            }
            $data["status"] = "Ok";
            $data["result"] = "$rowsAffected row(s) returned";
            // set response code - 200 ok
            http_response_code(200);
        }
    }
        
    return(json_encode($data));
}

function close($db) {
    if(!pg_close($db)) {
        $data["message"] = "Failed to close connection to " . pg_host($db) . ": " .
        pg_last_error($db);
        $data["status"] = "error";
        print json_encode($data);
    }
}

// These below functions sets headers accordingly as relevant
function singleGetHeader() {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Headers: access");
    header("Access-Control-Allow-Methods: GET");
    header("Access-Control-Allow-Credentials: true");
    header("Content-Type: application/json");    
}

function allGetHeader() {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Headers: access");
    header("Access-Control-Allow-Methods: GET");
    header("Access-Control-Allow-Credentials: true");
    header("Content-Type: application/json");    
}

function deleteSingleHeader() {
    header("Access-Control-Allow-Origin: *");
    header("Content-Type: application/json; charset=UTF-8");
    header("Access-Control-Allow-Methods: DELETE");
    header("Access-Control-Max-Age: 3600");
    header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
}

function postSingleHeader() {
    header("Access-Control-Allow-Origin: *");
    header("Content-Type: application/json; charset=UTF-8");
    header("Access-Control-Allow-Methods: POST");
    header("Access-Control-Max-Age: 3600");
    header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
}

function patchSingleHeader() {
    header("Access-Control-Allow-Origin: *");
    header("Content-Type: application/json; charset=UTF-8");
    header("Access-Control-Allow-Methods: PATCH");
    header("Access-Control-Max-Age: 3600");
    header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
}

function usageErrorOf($for, $message = NULL) {
    if ($message === NULL) {
        $errMsg = "";
    } else {
        $errMsg = $message;
    }
    if ($for == "DELETE") {
        $data["message"] = "usage error of $for method. $errMsg. Expects a GET parameter \"id\" of the recipe name";
        $data["status"] = "error";
    } elseif ($for == "POST") {
        $data["message"] = "usage error of $for method. $errMsg";
        $data["status"] = "error";
    } elseif ($for == "Unknown") {
        $data["message"] = "$for HTTP method";
        $data["status"] = "error";
    } elseif ($for == "GET") {
        $data["message"] = "usage error of $for HTTP method. $errMsg.";
        $data["status"] = "error";
    } elseif ($for == "PATCH") {
        $data["message"] = "usage error of $for HTTP method. $errMsg.";
        $data["status"] = "error";
    }

    return(json_encode($data));
}

function redirectAdjust() {
    $request = $_SERVER["REQUEST_URI"];
    $toks = explode("/", $request);
    $lastTok = end($toks);
    $reqStr = "request = $request, last tok = " . end($toks);
    $expectedMatch1 = "/recipes";
    $expectedMatch2 = "/recipes/";
    $expectedMatch3 = "/recipes/$lastTok";
    $isMatch = False;
    
    switch ($request) {
        case $expectedMatch1:
            $isMatch = True;
            $data["message"] = "Ok query";
            $data["status"] = "Ok";
            break;
        case $expectedMatch2:
            $isMatch = True;
            $data["message"] = "Ok query";
            $data["status"] = "Ok";
            break;
        case $expectedMatch3:
            $isMatch = True;
            $_GET['id'] = $lastTok;
            $data["message"] = "Ok query";
            $data["status"] = "Ok";
            break;
        default:
            $isMatch = False;
            $data["message"] = "improper URI. $reqStr";
            $data["status"] = "error";
            break;
    }

    return array($isMatch, json_encode($data));
}

// ### main ###
$requestMethod = $_SERVER["REQUEST_METHOD"];
$requestUrl = "http://localhost:80" . $_SERVER["REQUEST_URI"];

list($db, $config) = getdb();
list($redisHandle, $jsonMsgEncoded) = getRedisDb();
$jsonMsgDecoded = json_decode($jsonMsgEncoded, TRUE);
if ($jsonMsgDecoded["status"] != "Ok") {
    unset($redisHandle);
}

/*list($isUrlOk, $data) = redirectAdjust();
if (!$isUrlOk) {
    http_response_code(404);  // 404 BAD request recieved.
    $jsonMsgDecoded = json_decode($data, TRUE);
    print usageErrorOf($requestMethod, $jsonMsgDecoded["message"]);
    close($db);
    exit(1);
}*/

if($requestMethod == "GET") {
    if ($_GET["id"] == "0") { // iff 0 then say do not exist as 0 seems like a valid "id" though it is not considered by the pgsql. However iff X where X does not look like a number then show all records
       // set response code - 400 bad request
       http_response_code(400);
       print usageErrorOf($requestMethod, $_GET["id"] . " id do not exist");
       close($db);
       exit(1);
    } elseif (!empty($_GET['id'])) {
        $id = intval($_GET["id"]);
        singleGetHeader();
        print getSelectFrom($db, $id);
        close($db);
        exit(0);   
    } else {
        allGetHeader();
        print getAllFrom($db);
        close($db);
        exit(0);
    }
} elseif ($requestMethod == "DELETE") {
    if (!empty($_GET["id"])) {
        $id = intval($_GET["id"]);
        deleteSingleHeader();
        print removeFrom($db, $id);
        close($db);
        exit(0);
    } else {
        print usageErrorOf($requestMethod);
        close($db);
        exit(1);
    }
} elseif ($requestMethod == "POST") {
    postSingleHeader();
    list($recipeQuery, $message, $newJsonForRedis) = createRecipe();
    if (empty($recipeQuery)) {
        // set response code - 400 bad request
        http_response_code(400);
        print usageErrorOf($requestMethod, $message);
    	close($db);
        exit(1);
    } else {
        print insertTo($db, $recipeQuery, $newJsonForRedis);
    	close($db);
        exit(0);
    }
} elseif ($requestMethod == "PATCH") {
    patchSingleHeader();
    print updateRecipe($db);
    close($db);
    exit(0);
} else {
    usageErrorOf("Unknown");
    close($db);
    exit(0);
}
// end main






?>
