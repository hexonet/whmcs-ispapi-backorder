<?php
date_default_timezone_set('UTC');
require_once dirname(__FILE__).'/../../../../init.php';
require_once dirname(__FILE__).'/../vendor/autoload.php';
require_once dirname(__FILE__)."/helper.php"; //HELPER WHICH CONTAINS HELPER FUNCTIONS
use WHMCS\Database\Capsule;
use Mso\IdnaConvert\IdnaConvert;

//############################
//HELPER FUNCTIONS
//############################
function logmessage($cronname, $status, $message, $query = null)
{
    try {
        $pdo = Capsule::connection()->getPdo();
        $insert_stmt = $pdo->prepare("INSERT INTO backorder_logs(cron, date, status, message, query) VALUES(:cron, :date, :status, :message, :query)");
        $insert_stmt->execute(array(':cron' => $cronname, ':date' => date("Y-m-d H:i:s") , ':status' => $status, ':message' => $message, ':query' => $query));
    } catch (\Exception $e) {
        die($e->getMessage());
    }
}

//THIS FUNCTION CALLS OUR HEXONET API AND IS USED FOR CRONS AND IN THE WHMCS ADMIN AREA
function ispapi_api_call($command)
{
    require_once(dirname(__FILE__)."/../../../../includes/registrarfunctions.php");
    $higher_version_required_message = "The ISPAPI DomainCheck Module requires ISPAPI Registrar Module v1.0.15 or higher!";

    //CHECK IF THE ISPAPI REGISTRAR MODULE IS INSTALLED
    $error = false;
    $message = "";
    if (file_exists(dirname(__FILE__)."/../../../../modules/registrars/ispapi/ispapi.php")) {
        $file = "ispapi";
        require_once(dirname(__FILE__)."/../../../../modules/registrars/".$file."/".$file.".php");
        $funcname = $file.'_GetISPAPIModuleVersion';
        if (function_exists($file.'_GetISPAPIModuleVersion')) {
            $version = call_user_func($file.'_GetISPAPIModuleVersion');
            //check if version = 1.0.15 or higher
            if (version_compare($version, '1.0.15') >= 0) {
                //check authentication
                $registrarconfigoptions = getregistrarconfigoptions($file);
                $ispapi_config = ispapi_config($registrarconfigoptions);
                $checkAuthenticationCommand = array(
                        "command" => "CheckAuthentication",
                );
                $checkAuthentication = ispapi_call($checkAuthenticationCommand, $ispapi_config);
                if ($checkAuthentication["CODE"] != "200") {
                    $error = true;
                    $message = "The \"".$file."\" registrar authentication failed! Please verify your registrar credentials and try again.";
                }
            } else {
                $error = true;
                $message = $higher_version_required_message;
            }
        } else {
            $error = true;
            $message = $higher_version_required_message;
        }
    } else {
        $error = true;
        $message = $higher_version_required_message;
    }

    $response = array();
    if ($error) {
        $response["CODE"] = 549;
        $response["DESCRIPTION"] = $message;
    } else {
        $response = ispapi_call($command, $ispapi_config);
    }
    return $response;
}

//THIS FUNCTION CALLS OUR LOCAL API AND IS USED FOR CUSTOMER AND ADMIN
function backorder_api_call($command)
{
    $time = microtime(true);
    $ca = new WHMCS_ClientArea();

    $userid = $ca->getUserID();

    //CHECK IF ADMIN LOGGED IN AND AUTHORIZE PASSING USERID TO COMMAND
    if (isset($_SESSION['adminid']) && $_SESSION['adminid'] > 0 && isset($command["USERID"])) {
        $userid = $command["USERID"];
    }

    $dir = opendir(dirname(__FILE__)."/../api");
    $files = array();
    while (($file = readdir($dir)) !== false) {
        if (preg_match('/^command\.(.*)\.php$/', $file, $m)) {
            $files[strtoupper($m[1])] = $file;
        }
    }
    $c = strtoupper($command["COMMAND"]);
    if (isset($files[$c])) {
        $response = include dirname(__FILE__)."/../api/".$files[$c];
    }

    if (empty($response)) {
        $response["CODE"] = 500;
        $response["DESCRIPTION"] = "Command invalid";
    } else {
        if (!isset($response["QUEUETIME"])) {
            $response["QUEUETIME"] = "0.000";
        }
        if (!isset($response["RUNTIME"])) {
            $response["RUNTIME"] = sprintf("%0.3f", microtime(true) - $time);
        }
    }

    return $response;
}

//THIS FUNCTION CALLS OUR LOCAL API AND IS USED FOR CRONS AND SOME OTHER COMMANDS
//$userid WILL TAKE THE VALUE OF $command["USER"]
//THIS COMMAND IS NOT AVAILABLE FROM OUTSIDE
function backorder_backend_api_call($command)
{
    $time = microtime(true);
    $ca = new WHMCS_ClientArea();

    $userid = $command["USER"];
    $dir = opendir(dirname(__FILE__)."/../api");
    $files = array();
    while (($file = readdir($dir)) !== false) {
        if (preg_match('/^command\.(.*)\.php$/', $file, $m)) {
            $files[strtoupper($m[1])] = $file;
        }
    }
    $c = strtoupper($command["COMMAND"]);
    if (isset($files[$c])) {
        $response = include dirname(__FILE__)."/../api/".$files[$c];
    }

    if (empty($response)) {
        $response["CODE"] = 500;
        $response["DESCRIPTION"] = "Command invalid";
    } else {
        if (!isset($response["QUEUETIME"])) {
            $response["QUEUETIME"] = "0.000";
        }
        if (!isset($response["RUNTIME"])) {
            $response["RUNTIME"] = sprintf("%0.3f", microtime(true) - $time);
        }
    }
    return $response;
}

//THIS FUNCTION IS USED FOR LISTINGS
function backorder_api_query_list($command, $config = "")
{
    $response = backorder_api_call($command, $config);

    $list = array(
            "CODE" => $response["CODE"],
            "DESCRIPTION" => $response["DESCRIPTION"],
            "RUNTIME" => $response["RUNTIME"],
            "QUEUETIME" => $response["QUEUETIME"],
            "ITEMS" => array()
    );
    foreach ($response["PROPERTY"] as $property => $values) {
        if (preg_match('/^(FIRST|LAST|COUNT|LIMIT|TOTAL|ITEMS|COLUMN)$/', $property)) {
            $list[$property] = $response["PROPERTY"][$property][0];
        } else {
            foreach ($values as $index => $value) {
                $list["ITEMS"][$index][$property] = $value;
            }
        }
    }

    if (isset($command["FIRST"]) && !isset($list["FIRST"])) {
        $list["FIRST"] = $command["FIRST"];
    }

    if (isset($command["LIMIT"]) && !isset($list["LIMIT"])) {
        $list["LIMIT"] = $command["LIMIT"];
    }

    if (isset($list["FIRST"]) && isset($list["LIMIT"])) {
        $list["PAGE"] = floor($list["FIRST"] / $list["LIMIT"]) + 1;
        if ($list["PAGE"] > 1) {
            $list["PREVPAGE"] = $list["PAGE"] - 1;
            $list["PREVPAGEFIRST"] = ($list["PREVPAGE"]-1) * $list["LIMIT"];
        }
        $list["NEXTPAGE"] = $list["PAGE"] + 1;
        $list["NEXTPAGEFIRST"] = ($list["NEXTPAGE"]-1) * $list["LIMIT"];
    }
    if (isset($list["TOTAL"]) && isset($list["LIMIT"])) {
        $list["PAGES"] = floor(($list["TOTAL"] + $list["LIMIT"] - 1) / $list["LIMIT"]);
        $list["LASTPAGEFIRST"] = ($list["PAGES"] - 1) * $list["LIMIT"];
        if (isset($list["NEXTPAGE"]) && ($list["NEXTPAGE"] > $list["PAGES"])) {
            unset($list["NEXTPAGE"]);
            unset($list["NEXTPAGEFIRST"]);
        }
    }

    if (!isset($list["COUNT"])) {
        $list["COUNT"] = count($list["ITEMS"]);
    }

    if (isset($list["FIRST"]) && !isset($list["LAST"])) {
        $list["LAST"] = $list["FIRST"] + count($list["ITEMS"]) - 1;
    }

    return $list;
}

// CREATE AN API RESPONSE
function backorder_api_response($code, $info = "")
{
    $r = array("CODE" => $code, "DESCRIPTION" => "Error", "PROPERTY" => array());
    $codes = array(
            "200" => "Command completed successfully",
            "504" => "Missing required attribute",
            "505" => "Invalid attribute value syntax",
            "540" => "Attribute value is not unique",
            "541" => "Invalid attribute value",
            "545" => "Entity reference not found",
            "549" => "Command failed",
    );
    if (isset($codes[$code])) {
        $r["DESCRIPTION"] = $codes[$code];
    }
    if (strlen($info)) {
        $r["DESCRIPTION"] .= "; ".$info;
    }
    return $r;
}

//CHECK THE DOMAIN SYNTAX
function backorder_api_check_syntax_domain($domain)
{
    $IDN = new IdnaConvert();
    if (strlen($domain) > 223) {
        return false;
    }
    if (!preg_match('/^([a-z0-9](\-*[a-z0-9])*)(\.([a-z0-9](\-*[a-z0-9]+)*))+$/i', $IDN->encode($domain))) {
        return false;
    }
    return true;
}

//CHECK IF TLD IN THE PRICELIST
function backorder_api_check_valid_tld($domain, $userid)
{
    try {
        $pdo = Capsule::connection()->getPdo();
        $IDN = new IdnaConvert();
        $currencyid = null;

        $stmt = $pdo->prepare("SELECT currency FROM tblclients WHERE id=?");
        $stmt->execute(array($userid));
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($data) {
            $currencyid = $data["currency"];
        }
        $tlds = "";
        $stmt = $pdo->prepare("SELECT extension FROM backorder_pricing WHERE currency_id=?");
        $stmt->execute(array($currencyid));
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($data as $value) {
            $tlds .= "|.".$value["extension"];
        }
        $tld_list = substr($tlds, 1);
        if (!preg_match('/^([a-z0-9](\-*[a-z0-9])*)\\'.$tld_list.'$/i', $IDN->encode($domain))) {
            return false;
        }
        return true;
    } catch (\Exception $e) {
        die($e->getMessage());
    }
}
