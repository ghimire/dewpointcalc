#!/usr/bin/php
<?php
//******* You may modify these values ********//

// DATABASE DETAILS //
define("HOST","localhost");
define("DB_NAME","temperature");
define("DB_USER","sms-user");
define("DB_PW","s3cr37passw0rd");

// REGULAR EXPRESSION TO MATCH PHONE NUMBERS //
define("PHONE_REGEX",'/^[0-9]{7}/');

// INTERNATIONAL COUNTRY CALLING CODE
define("COUNTRYCODE","220");

// ***************************************//
// WARNING: Do not modify below this line //
// ***************************************//

define("SMSLIMIT",160);
define("SMSPROCESS_LIMIT", 10);
define("DATA_REGEX",'/^([0-9\-\.]+)\ ([0-9\-\.]+)\ ([0-9\-\.]+)$/');
define("DATA_REGEX2",'/^([0-9\-\.]+)\ ([0-9\-\.]+)$/');

$db = mysql_connect(HOST,DB_USER,DB_PW) or die("Connect Error");
mysql_select_db(DB_NAME);

// Calculate Vapor Pressure, Relative Humidity and Dew Point
function calculate($text){
    $Tw = 0.0;    //* initialize //
    $Td = 0.0;    //  variables *//
    $h = 0.0;       // Height in feet
    if (preg_match(DATA_REGEX, $text)) {
        preg_match(DATA_REGEX, $text,$matches);
        $Tw  = (float)$matches[1]; // Wet bulb temperature in Celcius
        $Td  = (float)$matches[2]; // Dry bulb temperature in Celcius
        $h   = (float)$matches[3]; // Height in feet
    } elseif(preg_match(DATA_REGEX2, $text)) {
        preg_match(DATA_REGEX2, $text,$matches);
        $Tw  = (float)$matches[1]; // Wet bulb temperature in Celcius
        $Td  = (float)$matches[2]; // Dry bulb temperature in Celcius
    } else return "";

    $p = 1013.25 * pow((1.00 - ($h/145366.45)),5.2553); // Atmospheric pressure in millibars
    
    $Es = 6.112 * (exp((17.67 * $Td) / (243.5 + $Td)));
    $Ew = 6.112 * (exp((17.67 * $Tw) / (243.5 + $Tw)));

    $E  = $Ew - (0.00066 * (1.00 + 0.00115 * $Tw) * ($Td - $Tw) * $p);
    $RH = 100.00 * ($E / $Es);
    $B = log($E / 6.108) / 17.27;
    $Tp = (237.3 * $B) / (1.00 - $B);
    return sprintf("Dew point: %1\$.2f degreesC\n",$Tp).sprintf("Relative Humidity: %1\$.2f percent\n",$RH).sprintf("Water Vapor Pressure: %1\$.2f millibars\n",$E);
}

// Log Messages
function logmsg($msg) {
    $message = mysql_real_escape_string($msg);
    mysql_query('insert into log (message) values("'.$message.'")') or die(mysql_error());
}

// Temperature Log Insertion
function insert_to_temperaturelog($number,$text){
    $number = mysql_real_escape_string(intval($number));
    $text = mysql_real_escape_string($text);
    $station = $number;
    $text=preg_replace("/(\t|\r|\n)/","",$text);  // remove new lines \n, tabs and \r

    if (preg_match(DATA_REGEX, $text) || preg_match(DATA_REGEX2, $text)) {
        logmsg("Request from ".$number." Data: $text");
        // Send calculations
        $calculated_values = calculate($text);
        if(!empty($calculated_values))
        insert_to_smsqueue($number,$calculated_values);
    }
}

// SMS Queue
function insert_to_smsqueue($number,$text){
    $number = mysql_real_escape_string(intval($number));
    mysql_query("INSERT INTO outbox (number,text) VALUES('".$number."', '".$text."')");
}

// Process sms inbox and collect temperature data
function process_sms_inbox(){
    $result = mysql_query("select * from inbox WHERE processed = 0 limit ".SMSPROCESS_LIMIT) or die (mysql_error());
    while ($row = mysql_fetch_assoc($result)){
        $number = preg_replace('/^\+'.COUNTRYCODE.'/','',$row['number']);
        $text = trim(preg_replace('/[^(\x20-\x7F)]*/','', $row['text']));
        if(preg_match(DATA_REGEX, $text) || preg_match(DATA_REGEX2, $text)) {
            insert_to_temperaturelog($number,$text);
            logmsg("Added ".$number." to queue");
        } else {
            logmsg("Invalid request from ".$number);
        }

        $rowid=intval($row['id']);
        mysql_query('UPDATE inbox set processed = 1 WHERE id = '.$rowid);
    }
    mysql_free_result($result);
}

// Call the processing function
process_sms_inbox();
?>
